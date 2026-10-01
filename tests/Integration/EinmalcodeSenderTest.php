<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Comms\EinmalcodeSender;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Support\CodeDrossel;
use Platform\Recruiting\Support\Einmalcode;
use Platform\Recruiting\Support\EinmalcodeVorlagen;

/**
 * Der Versand des Einmalcodes — die Nachricht, mit der jemand sein Passwort
 * zuruecksetzt oder seine Nummer wechselt.
 *
 * DIE VIER ZUSAGEN, DIE HIER FALSIFIZIERT WERDEN, und warum jede von ihnen
 * ohne Test still bliebe:
 *
 *  1. RULING GD-1 IST VERDRAHTET. CodeDrossel stand seit Aufgabe 3 fertig da
 *     und wurde nirgends gerufen; ein vergessener Aufruf faellt durch nichts
 *     auf, weil alle Tests gruen bleiben und eine Nachricht bloss sieben Cent
 *     kostet. Geprueft wird deshalb NICHT, dass die Klasse existiert, sondern
 *     dass der VIERTE Versand in derselben Stunde und der SECHSTE am selben
 *     Tag wirklich nichts mehr verschickt — beide Zahlen getrennt, damit
 *     nicht eine Grenze fuer die andere einsteht.
 *
 *  2. DIE REIHENFOLGE: erst fragen, dann erzeugen.
 *     KontoWriter::erzeugeCode() ueberschreibt den Code, der noch unterwegs
 *     ist. Wer die Drossel danach fragt, hat dem Menschen den Code entwertet,
 *     den er gerade im Daumen hat, und verschickt keinen neuen. Der Beleg ist
 *     nicht die Reihenfolge im Quelltext, sondern der UNVERAENDERTE code_hash
 *     nach einem gedrosselten Versuch.
 *
 *  3. EIN VON META ABGELEHNTER VERSAND IST KEIN ERFOLG.
 *     RecEmployee::sendPortalNotification() meldet ok:true direkt nach
 *     sendTemplate(), ohne $message->status zu pruefen. Die Meta-Attrappe hier
 *     ist deshalb bewusst KEINE grosszuegige: sie kennt eine Liste genehmigter
 *     Vorlagen samt erwarteter Parameternamen und antwortet sonst mit
 *     status=failed, so wie Meta es tut. Eine Attrappe, die jeden
 *     Vorlagennamen und jeden Platzhalter annimmt, prueft nichts.
 *
 *  4. DER CODE STEHT IN DER NACHRICHT UND SONST NIRGENDS. Versand-Code
 *     protokolliert traditionell viel, und eine HTTP-Ausnahme traegt den
 *     gesendeten Rumpf im Text. Geprueft wird deshalb der Inhalt der
 *     Log-Attrappe, einschliesslich des Falls, in dem die Ausnahme den Code
 *     woertlich enthaelt.
 *
 * AUFBAU: Container + Capsule + SQLite von Hand, Vorbild PersonLinkerTest,
 * einschliesslich Log-Attrappe VOR Facade::clearResolvedInstances()
 * (reference_log_facade_test_stub.md). rec_persons und rec_employees werden
 * von Hand gebaut (deckungsgleich mit den echten Migrationen, wie in
 * KontoWriterTest); fuer comms_channels, integrations_whatsapp_accounts und
 * rec_applicant_settings laufen dagegen die ECHTEN Migrationen — Vorbild
 * RecruitingChannelSetTest. Der Unterschied ist Absicht: deren Form gehoert
 * nicht diesem Modul, und die Kanal-Aufloesung liest mit dem meta-JSON und
 * sender_identifier genau die Spalten, die ein handgebautes Schema still
 * falsch haette.
 *
 * DIE UHR wird mit Carbon::setTestNow() festgehalten. Ohne sie koennte diese
 * Testklasse den Unterschied zwischen "drei in der Stunde" und "fuenf am Tag"
 * gar nicht herstellen — sie wuerde dann eine Zusage pruefen, die die
 * Testumgebung nicht abbilden kann.
 */
final class EinmalcodeSenderTest extends TestCase
{
    private const TEAM = 3;

    private const NUMMER = '+4915111111111';

    private const NEUE_NUMMER = '+4915122222222';

    /** Eine dritte, fremde Nummer — Ziel der Nummernwechsel-Tests zu Fund F4. */
    private const ZIEL_NUMMER = '+4915133333333';

    private const PFEFFER = 'pfeffer-fuer-den-test';

    /** Der bei Meta genehmigte Vorlagenname — in der Attrappe und in der Konfiguration. */
    private const VORLAGE = 'konto_einmalcode';

    private const ANGEFASST = '2026-09-28 09:00:00';

    /** Mitten am Tag, damit die Stunden-Rechnung nicht an Mitternacht stoesst. */
    private const JETZT = '2026-09-29 10:00:00';

    private Capsule $capsule;

    /** @var object{zeilen: list<array{stufe: string, nachricht: string, daten: array}>} */
    private object $log;

    /** @var object{calls: list<array<string, mixed>>, genehmigt: array<string, list<string>>, wirft: ?\Throwable} */
    private object $meta;

    /** @var object{schluessel: list<string>} */
    private object $cache;

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden —
        // sonst fliegt eine ReflectionException, sobald der erste
        // Log::info(...) laeuft (reference_log_facade_test_stub.md).
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $this->log = new class {
            /** @var list<array{stufe: string, nachricht: string, daten: array}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = [
                    'stufe'     => $m,
                    'nachricht' => (string) ($a[0] ?? ''),
                    'daten'     => (array) ($a[1] ?? []),
                ];
            }
        };
        $container->instance('log', $this->log);

        $container->instance('config', new ConfigRepository([
            'recruiting' => [
                'konto' => [
                    'pepper'        => self::PFEFFER,
                    'code_vorlagen' => [
                        KontoWriter::ZWECK_ANMELDUNG => [
                            'name' => self::VORLAGE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                        KontoWriter::ZWECK_PASSWORT => [
                            'name' => self::VORLAGE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                        KontoWriter::ZWECK_NUMMERNWECHSEL => [
                            'name' => self::VORLAGE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                    ],
                ],
            ],
        ]));

        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);

        $this->cache = $this->cacheAttrappe();

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Carbon::setTestNow(Carbon::parse(self::JETZT));

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();

            // Deckungsgleich mit 2026_09_29_000001_add_konto_felder_to_rec_persons.php.
            $t->string('invite_token_hash', 64)->nullable();
            $t->timestamp('invite_expires_at')->nullable();
            $t->timestamp('invite_used_at')->nullable();
            $t->string('code_hash', 64)->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->unsignedTinyInteger('code_versuche')->default(0);
            $t->string('code_zweck', 20)->nullable();
            $t->string('code_neue_nummer', 32)->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();

            // Deckungsgleich mit 2026_09_29_000003_add_nummernwechsel_zu_rec_persons.php
            // und 2026_09_29_000004_add_notfall_sperre_zu_rec_persons.php.
            // Nicht nur der Vollstaendigkeit halber: KontoWriter::
            // offeneNummernwechsel() steht auf zwei whereNotNull dieser
            // Spalten, und SQLite faellt bei einer fehlenden Spalte in
            // doppelten Anfuehrungszeichen auf ein String-Literal zurueck —
            // whereNotNull traefe dann JEDE Person. Das ist die stillste
            // Richtung des Fehlers, weil ploetzlich alles passt.
            $t->string('wechsel_neue_nummer', 32)->nullable();
            $t->timestamp('wechsel_beantragt_at')->nullable();
            $t->timestamp('wechsel_wirksam_ab')->nullable();
            $t->string('wechsel_quelle', 20)->nullable();
            $t->timestamp('notfall_gesperrt_bis')->nullable();

            $t->timestamps();

            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        $this->echteMigrationen();

        // Der ECHTE Beobachter, nicht seine Abwesenheit, ist die Zusicherung
        // des Observer-Tests (Muster PersonLinkerTest, Fixrunde 1 Befund I4).
        RecEmployeeExportObserver::register();

        $this->kanalAufsetzen();

        $this->personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-einmalcode-test',
            'team_id'    => self::TEAM,
            'phone'      => self::NUMMER,
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);

        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
             'rec_person_id' => $this->personId, 'company' => 'RG', 'phone' => self::NUMMER, 'is_active' => 1,
             'zas_changed_at' => self::ANGEFASST, 'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ------------------------------------------------------- Zusage 3: Meta

    /**
     * Ein von Meta ABGELEHNTER Versand ergibt `failed`, nicht `sent`.
     *
     * Die Attrappe kennt die Vorlage `gibt_es_bei_meta_nicht` nicht und
     * antwortet wie Meta mit status=failed. Wer $message->status nicht
     * anschaut (der Fehler aus RecEmployee::sendPortalNotification), meldet
     * hier `sent` — und jemand wartet auf einen Code, der nie ankam, waehrend
     * das Protokoll "verschickt" sagt.
     */
    public function testVonMetaAbgelehnterVersandErgibtFailed(): void
    {
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['name' => 'gibt_es_bei_meta_nicht']);

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertCount(1, $this->meta->calls, 'Der Versuch lief — abgelehnt hat Meta, nicht wir.');
    }

    // ------------------------------------- Zusage: Platzhalter, kein Versand

    /**
     * Ein Platzhalter, den wir nicht befuellen koennen, fuehrt zu KEINEM
     * Versand — und zu keinem neuen Code.
     *
     * HoldingTemplateComponents::build() setzt bei einem unbekannten
     * Platzhalter still den Vornamen ein; Meta naehme so eine Nachricht an.
     * Jemand bekaeme dann seinen Vornamen statt des Codes zugeschickt, der
     * Versand gaelte als Erfolg — und der Code, den er vorher hatte, waere
     * ueberschrieben. Deshalb beide Zusicherungen in einem Test.
     */
    public function testUnbekannterPlatzhalterWirdNichtVerschicktUndErzeugtKeinenCode(): void
    {
        $vorher = $this->laufenderCode();
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['platzhalter' => ['code', 'lieblingsfarbe']]);

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls, 'Kein Versand.');
        $this->assertSame($vorher, $this->laufenderCode(), 'Der laufende Code bleibt unangetastet.');
        $this->assertStringContainsString('lieblingsfarbe', $this->logText(), 'Das Log nennt den Platzhalter.');
    }

    /**
     * Eine Vorlage OHNE {{code}} geht gar nicht erst raus.
     *
     * Ohne diese Wache waere die Nachricht formal in Ordnung, Meta naehme sie
     * an, der Versand gaelte als `sent` — und der Mensch bekaeme eine
     * Nachricht ohne die einzige Zahl, wegen der sie verschickt wurde.
     */
    public function testVorlageOhneCodePlatzhalterWirdNichtVerschickt(): void
    {
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['platzhalter' => ['name']]);

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
    }

    /**
     * Fehlt die Einstellung, wird NICHT verschickt — und es steht eine klare
     * Meldung im Log. Kein stiller Fehlschlag: die Meta-Vorlagen sind am
     * 29.09.2026 noch nicht genehmigt, dieser Zustand ist der Normalfall bis
     * zur Freigabe.
     */
    public function testFehlendeVorlageVerschicktNichtsUndSagtEsDeutlich(): void
    {
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['name' => '']);

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertStringContainsString('code_vorlagen', $this->logText());
    }

    // --------------------------------------------- Zusage 1 + 2: die Drossel

    /**
     * Ruling GD-1, erste Zahl: hoechstens DREI Einmalcodes je Nummer und
     * Stunde — und der vierte Versuch laesst den laufenden Code stehen.
     *
     * Der unveraenderte code_hash ist der eigentliche Beweis: er faellt genau
     * dann um, wenn jemand die Drossel HINTER erzeugeCode() stellt. Die
     * Tageszahl kann hier nicht einstehen, es sind erst drei am Tag.
     */
    public function testStundengrenzeGreiftUndLaesstDenLaufendenCodeStehen(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 0; $i < CodeDrossel::MAX_JE_STUNDE; $i++) {
            $this->assertSame(
                EinmalcodeSender::STATUS_SENT,
                $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT),
                'Versand ' . ($i + 1) . ' muss durchgehen.'
            );
        }

        $laufend = $this->laufenderCode();
        $this->assertNotNull($laufend['hash']);

        $status = $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_GEDROSSELT, $status);
        $this->assertCount(CodeDrossel::MAX_JE_STUNDE, $this->meta->calls, 'Der vierte geht nicht raus.');
        $this->assertSame($laufend, $this->laufenderCode(), 'Der Code im Daumen bleibt gueltig.');
    }

    /**
     * Ruling GD-1, zweite Zahl: hoechstens FUENF am Kalendertag.
     *
     * Die Stundengrenze kann hier nicht einstehen — vor dem sechsten Versuch
     * liegen nur zwei Anforderungen in der letzten Stunde. Ohne diesen
     * getrennten Aufbau pruefte der Test zweimal dieselbe Grenze.
     */
    public function testTagesgrenzeGreiftAuchOhneStundenlast(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 0; $i < 3; $i++) {
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        }

        // Zwei Stunden weiter: das Stundenfenster ist leer, der Kalendertag
        // derselbe.
        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(2));

        $this->assertSame(EinmalcodeSender::STATUS_SENT, $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT));
        $this->assertSame(EinmalcodeSender::STATUS_SENT, $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT));
        $this->assertCount(CodeDrossel::MAX_JE_TAG, $this->meta->calls);

        $this->assertSame(
            EinmalcodeSender::STATUS_GEDROSSELT,
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT),
            'Der sechste am Tag faellt, obwohl in der letzten Stunde erst zwei liefen.'
        );
        $this->assertCount(CodeDrossel::MAX_JE_TAG, $this->meta->calls);
    }

    /**
     * Ein gedrosselter Versuch zaehlt NICHT mit.
     *
     * Wuerde er es, verlaengerte jedes Haemmern die Sperre um eine weitere
     * Stunde — die Bremse waere dann keine Bremse mehr, sondern eine
     * Aussperrung, die der Angreifer beim Opfer ausloest.
     */
    public function testGedrosselterVersuchZaehltNichtMit(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 0; $i < CodeDrossel::MAX_JE_STUNDE; $i++) {
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        }

        // Eine halbe Stunde lang haemmern.
        for ($i = 1; $i <= 4; $i++) {
            Carbon::setTestNow(Carbon::parse(self::JETZT)->addMinutes($i));
            $this->assertSame(
                EinmalcodeSender::STATUS_GEDROSSELT,
                $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT)
            );
        }

        // 61 Minuten nach dem DRITTEN echten Versand ist das Fenster leer —
        // aber nur, wenn die vier Fehlschlaege nicht mitgeschrieben wurden.
        Carbon::setTestNow(Carbon::parse(self::JETZT)->addMinutes(61));

        $this->assertSame(
            EinmalcodeSender::STATUS_SENT,
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT)
        );
    }

    // ---------------------------------------------- Zusage 4: das Geheimnis

    /**
     * Der gesendete Wert IST der gueltige Code — und er steht in keiner
     * Log-Zeile.
     *
     * Geprueft wird nicht der NAME des Parameters, sondern die Sache: der
     * gesendete Text wird gegen den in der Datenbank abgelegten Hash
     * verifiziert. Ein Sender, der irgendeine sechsstellige Zahl schickt,
     * faellt hier durch.
     */
    public function testDerGesendeteWertIstDerGueltigeCodeUndStehtInKeinemLog(): void
    {
        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_SENT, $status);
        $this->assertCount(1, $this->meta->calls);

        $call = $this->meta->calls[0];
        $this->assertSame(self::VORLAGE, $call['templateName']);
        $this->assertSame(self::NUMMER, $call['to']);
        $this->assertSame('de', $call['languageCode']);

        $gesendet = $this->einzigerParameter($call['components']);

        $zeile = $this->laufenderCode();
        $this->assertTrue(
            Einmalcode::istGueltig($zeile['hash'], $zeile['expires'], 0, $gesendet, self::JETZT, self::PFEFFER),
            'Der verschickte Wert muss der Code sein, den KontoWriter abgelegt hat.'
        );

        $this->assertStringNotContainsString($gesendet, $this->logText(), 'Der Code gehoert in kein Log.');
    }

    /**
     * Auch der Text einer AUSNAHME darf den Code nicht ins Log tragen.
     *
     * Das ist kein erfundener Fall: eine HTTP-Ausnahme fuehrt den gesendeten
     * Rumpf im Text mit, und der Rumpf enthaelt den Code. ProofReminderSender
     * protokolliert $e->getMessage() woertlich — hier darf das nicht sein.
     */
    public function testAusnahmeTextMitCodeWirdImLogGeschwaerzt(): void
    {
        $this->meta->wirftMitParameter = true;

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);

        $gesendet = $this->einzigerParameter($this->meta->calls[0]['components']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $gesendet);
        $this->assertStringNotContainsString($gesendet, $this->logText(), 'Der Code darf auch aus einer Ausnahme nicht ins Log.');
        $this->assertStringContainsString('HTTP 400', $this->logText(), 'Die Ursache bleibt lesbar.');
    }

    // ----------------------------------------------------- weitere Zusagen

    /** Beim Nummernwechsel geht der Code an die NEUE Nummer. */
    public function testNummernwechselGehtAnDieNeueNummer(): void
    {
        $status = (new EinmalcodeSender($this->cache))
            ->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::NEUE_NUMMER);

        $this->assertSame(EinmalcodeSender::STATUS_SENT, $status);
        $this->assertSame(self::NEUE_NUMMER, end($this->meta->calls)['to']);
    }

    /**
     * Der Zaehler je NUMMER haengt wirklich an der Nummer — nicht an der
     * Person, die ihn vollgemacht hat.
     *
     * Aufbau: Person A reizt die ZIELNUMMER aus, dann fordert Person B einen
     * Code an dieselbe Zielnummer an. Ihr eigener Personen-Zaehler ist leer;
     * blockieren kann hier also nur der Zaehler der Nummer. Ohne diese
     * zweite Person waere der Unterschied zwischen "je Nummer" und "je
     * Person" in dieser Testklasse gar nicht herstellbar.
     */
    public function testDieNummernbremseGiltAuchFuerEineZweitePerson(): void
    {
        $sender = new EinmalcodeSender($this->cache);
        $zweite = $this->zweitePerson();

        for ($i = 0; $i < CodeDrossel::MAX_JE_STUNDE; $i++) {
            $this->assertSame(
                EinmalcodeSender::STATUS_SENT,
                $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::ZIEL_NUMMER)
            );
        }

        $this->assertSame(
            EinmalcodeSender::STATUS_GEDROSSELT,
            $sender->sende($zweite, KontoWriter::ZWECK_NUMMERNWECHSEL, self::ZIEL_NUMMER),
            'Die Zielnummer ist ausgereizt, egal wer fragt.'
        );
    }

    /**
     * FUND F4: der Zielwechsel umgeht die Bremse NICHT.
     *
     * Die eigene Nummer ist ausgereizt; die FRISCHE Zielnummer hat einen
     * leeren eigenen Zaehler. Bliebe es beim Zaehler je Nummer, ginge die
     * Nachricht raus — und ein Angemeldeter koennte unbegrenzt
     * Vorlagennachrichten an fremde Nummern schicken, auf unsere Rechnung
     * und unter unserem Absender. Blockieren kann hier nur der Zaehler je
     * Person.
     */
    public function testZielwechselUmgehtDieBremseNicht(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 0; $i < CodeDrossel::MAX_JE_STUNDE; $i++) {
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        }

        $this->assertSame(
            EinmalcodeSender::STATUS_GEDROSSELT,
            $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::ZIEL_NUMMER),
            'Eine frische Zielnummer ist kein frisches Budget.'
        );
        $this->assertCount(CodeDrossel::MAX_JE_STUNDE, $this->meta->calls);
    }

    /**
     * FUND F4, die Tagesgrenze: auch mit lauter VERSCHIEDENEN Zielnummern
     * ist bei fuenf am Kalendertag Schluss.
     *
     * Jede Zielnummer wird nur einmal benutzt, ihr eigener Zaehler steht
     * also nie ueber eins — und vor dem sechsten Versuch liegen nur zwei
     * Anforderungen in der letzten Stunde. Weder die Nummern-Bremse noch die
     * Stundengrenze kann hier einstehen.
     */
    public function testPersonenGrenzeAmTagTrotzVerschiedenerZielnummern(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(
                EinmalcodeSender::STATUS_SENT,
                $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::zielNummer($i))
            );
        }

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(2));

        for ($i = 4; $i <= 5; $i++) {
            $this->assertSame(
                EinmalcodeSender::STATUS_SENT,
                $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::zielNummer($i))
            );
        }

        $this->assertSame(
            EinmalcodeSender::STATUS_GEDROSSELT,
            $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::zielNummer(6)),
        );
        $this->assertCount(CodeDrossel::MAX_JE_TAG, $this->meta->calls);
    }

    /**
     * Eine gesperrte Person bekommt keinen Code — die Regel lebt in
     * KontoWriter::offeneZeile() und wird hier NICHT nachgebaut, sondern nur
     * nicht verschluckt.
     */
    public function testGesperrtePersonBekommtKeinenCode(): void
    {
        DB::table('rec_persons')->where('id', $this->personId)->update(['locked_at' => self::JETZT]);

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
    }

    /**
     * OBSERVER-FREI: ein Versand fasst die Anstellung nicht an.
     *
     * Der Beleg ist rec_employees.updated_at (das fasst nur Eloquent
     * automatisch an) UND zas_changed_at — sonst spuelte eine Versandwelle
     * den halben Bestand in die naechste ZAS-Update-Datei. Der echte
     * RecEmployeeExportObserver ist registriert, der Test prueft also nicht
     * bloss dessen Abwesenheit.
     */
    public function testVersandFasstDieAnstellungNichtAn(): void
    {
        (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $ma = DB::table('rec_employees')->where('id', 1)->first();

        $this->assertSame(self::ANGEFASST, $ma->updated_at);
        $this->assertSame(self::ANGEFASST, $ma->zas_changed_at);
    }

    /**
     * Die AUSGELIEFERTE Konfiguration kennt alle drei Zwecke.
     *
     * Ohne diesen Test waeren alle anderen strukturell gruen: sie arbeiten
     * mit einer im Setup gebauten Konfiguration, und ein fehlender Block in
     * config/recruiting.php fiele erst auf prod auf — als Versand, der nie
     * stattfindet.
     */
    public function testDieAusgelieferteKonfigurationKenntAlleDreiZwecke(): void
    {
        $datei = require dirname(__DIR__, 2) . '/config/recruiting.php';

        $this->assertArrayHasKey('code_vorlagen', $datei['konto']);
        $this->assertIsArray(
            $datei['konto']['code_vorlagen_alt'] ?? null,
            'Fund N2: ohne diese Liste faellt ein umbenannter Vorlagenname aus der Schwaerzung.'
        );

        foreach ([KontoWriter::ZWECK_ANMELDUNG, KontoWriter::ZWECK_PASSWORT, KontoWriter::ZWECK_NUMMERNWECHSEL] as $zweck) {
            $this->assertArrayHasKey($zweck, $datei['konto']['code_vorlagen'], "Zweck {$zweck} fehlt.");
            $eintrag = $datei['konto']['code_vorlagen'][$zweck];
            $this->assertArrayHasKey('name', $eintrag);
            $this->assertArrayHasKey('sprache', $eintrag);
            $this->assertContains('code', $eintrag['platzhalter'], "Zweck {$zweck} ohne {{code}}.");
        }
    }

    /**
     * Die Attrappe darf nicht von der echten Signatur wegdriften.
     *
     * Muster: TrainingCertificateWhatsAppDeliveryTest. Eine Attrappe mit
     * anderer Parameterreihenfolge nimmt jeden Aufruf an und prueft nichts.
     */
    public function testAttrappenSignaturPasstZuSendTemplate(): void
    {
        $echt = (new \ReflectionMethod(WhatsAppMetaService::class, 'sendTemplate'))->getParameters();
        $attrappe = (new \ReflectionMethod($this->meta, 'sendTemplate'))->getParameters();

        $this->assertSame(
            array_map(fn ($p) => $p->getName(), $echt),
            array_map(fn ($p) => $p->getName(), $attrappe),
        );
    }

    /**
     * FUND F8: die {{code}}-Pflicht und die Platzhalter-Wache waren
     * verschieden streng — die eine verglich gross/klein genau, die andere
     * nicht. Eine Vorlage mit {{Code}} fiel deshalb an der Pflicht durch,
     * obwohl der Wert befuellbar gewesen waere. Jetzt vergleichen beide
     * klein.
     */
    public function testEinGrossGeschriebenerCodePlatzhalterZaehltAuch(): void
    {
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['platzhalter' => ['Code']]);
        // Die Attrappe bleibt Meta-treu: Meta laesst als parameter_name nur
        // Kleinbuchstaben zu, die genehmigte Vorlage heisst also {{code}}.
        // Genau daran haengt Fund N4 - die grosszuegige Lesart der
        // Konfiguration darf nicht in einen abgelehnten Versand muenden, denn
        // dann waere der Code erzeugt, verbraucht und nie angekommen.
        $this->meta->genehmigt[self::VORLAGE] = ['code'];

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_SENT, $status);

        $zeile = $this->laufenderCode();
        $this->assertTrue(Einmalcode::istGueltig(
            $zeile['hash'],
            $zeile['expires'],
            0,
            $this->einzigerParameter($this->meta->calls[0]['components']),
            self::JETZT,
            self::PFEFFER,
        ), 'Auch bei {{Code}} muss der echte Code drinstehen.');

        $this->assertSame(
            'code',
            $this->meta->calls[0]['components'][0]['parameters'][0]['parameter_name'],
            'Fund N4: der Name geht klein raus, sonst lehnt Meta ab.'
        );
    }

    /**
     * FUND F5: ein Zustand, in dem sich NIEMAND mehr anmelden kann, darf
     * nicht auf derselben Log-Stufe stehen wie jeder Erfolg — sonst geht er
     * in der Flut unter.
     */
    public function testAusfaelleStehenAufDerRichtigenLogStufe(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['name' => '']);
        $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        $this->assertSame('error', $this->log->zeilen[0]['stufe'], 'Keine Vorlage = niemand kommt mehr an einen Code.');

        $this->log->zeilen = [];
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['name' => self::VORLAGE]);
        $this->meta->lehntAbMitWert = true;
        $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        $this->assertSame('warning', $this->log->zeilen[0]['stufe'], 'Ein abgelehnter Versand ist Betrieb, kein Ausfall.');

        $this->log->zeilen = [];
        $this->meta->lehntAbMitWert = false;
        $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        $this->assertSame('info', $this->log->zeilen[0]['stufe'], 'Der Normalfall bleibt info.');
    }

    /**
     * FUND N1: der Sender bildet seinen Konfigurationspfad aus DERSELBEN
     * Konstante, aus der die Schwaerzung ihre Namen liest.
     *
     * Solange er einen eigenen Literal hatte, konnten die beiden
     * auseinanderlaufen — und das ist kein Schoenheitsfehler: der Sender
     * verschickte dann weiter, waehrend EinmalcodeVorlagen::namen() ins Leere
     * las. Der Code stuende wieder unmaskiert im Chat, genau der Fehler, den
     * F1 geschlossen hat, nur lautlos.
     */
    public function testDerSenderLiestDenselbenKonfigurationspfadWieDieSchwaerzung(): void
    {
        // Die Vorlagen stehen im Setup unter EinmalcodeVorlagen::KONFIG. Wenn
        // der Sender von dort liest, findet er sie; leserte er anderswo, waere
        // der Versand "nicht konfiguriert".
        $this->assertSame(
            EinmalcodeSender::STATUS_SENT,
            (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT)
        );

        // Und die Gegenrichtung: derselbe Pfad traegt die Schwaerzung.
        $this->assertTrue(EinmalcodeVorlagen::istCodeVorlage(self::VORLAGE));
    }

    /**
     * FUND N2: ein ABGELEGTER Vorlagenname wird weiterhin geschwaerzt.
     *
     * Heisst die Vorlage eines Tages anders, faellt der alte Name aus
     * code_vorlagen heraus — und ohne die zweite Liste stuenden alle alten
     * Nachrichten wieder unmaskiert im Verlauf, ohne dass irgendetwas rot
     * wuerde. Derselbe Gedanke wie bei der Schwaerzung ALLER Werte, nur eine
     * Ebene hoeher.
     */
    public function testEinAbgelegterVorlagenNameWirdWeiterhinGeschwaerzt(): void
    {
        config()->set(EinmalcodeVorlagen::KONFIG_ALT, ['konto_einmalcode_v1']);

        $this->assertTrue(
            EinmalcodeVorlagen::istCodeVorlage('konto_einmalcode_v1'),
            'Der Verlauf reicht weiter zurueck als die heutige Konfiguration.'
        );
        $this->assertSame(
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'parameter_name' => 'code', 'text' => EinmalcodeVorlagen::MASKE],
            ]]],
            EinmalcodeVorlagen::geschwaerzt([['type' => 'body', 'parameters' => [
                ['type' => 'text', 'parameter_name' => 'code', 'text' => '481516'],
            ]]]),
        );

        // Der SENDER liest diese Liste bewusst nicht: ein abgelegter Name
        // wird geschwaerzt, aber nicht mehr verschickt.
        $this->vorlageSetzen(KontoWriter::ZWECK_PASSWORT, ['name' => '']);
        $this->assertSame(
            EinmalcodeSender::STATUS_FAILED,
            (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT)
        );
        $this->assertSame([], $this->meta->calls);
    }

    // ------------------------------- F2: vier Zusagen, die niemand hielt

    /**
     * Der Drossel-Schluessel traegt die Nummer NICHT im Klartext.
     *
     * Mit genau dieser Eigenschaft wird die Cache-Entscheidung verteidigt
     * (der Wirt faehrt CACHE_STORE=database, cache.key ist ein varchar(255)
     * PRIMARY KEY, und eine vollstaendige Handynummer hat dort nichts zu
     * suchen) — die Attrappe schrieb die Schluessel bisher mit, ohne dass
     * sie je einer gelesen haette. Woertlich abgeschaut bei
     * PortalAuthKontoTest::test_der_cache_schluessel_traegt_die_nummer_nicht_im_klartext.
     */
    public function testDerDrosselSchluesselTraegtDieNummerNichtImKlartext(): void
    {
        (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertNotEmpty($this->cache->schluessel, 'Es muss ueberhaupt etwas geschrieben worden sein.');

        foreach ($this->cache->schluessel as $schluessel) {
            $this->assertStringNotContainsString(self::NUMMER, $schluessel);
            $this->assertStringNotContainsString('15111111111', $schluessel, 'auch nicht ohne Laendervorwahl');
            $this->assertLessThanOrEqual(255, strlen($schluessel), 'cache.key ist varchar(255).');
        }
    }

    /**
     * Auch die Fehlermeldung VON META darf den Code nicht ins Log tragen.
     *
     * Meta zitiert bei einem Parameterfehler den beanstandeten Wert. Diese
     * Meldung lief bisher ungeprueft durch — der zweite Weg des Geheimnisses
     * ins Log, neben dem Ausnahmetext.
     */
    public function testMetaFehlertextMitCodeWirdImLogGeschwaerzt(): void
    {
        $this->meta->lehntAbMitWert = true;

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);

        $gesendet = $this->einzigerParameter($this->meta->calls[0]['components']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $gesendet);
        $this->assertStringNotContainsString($gesendet, $this->logText());
        $this->assertStringContainsString('Parameter format does not match', $this->logText(), 'Die Ursache bleibt lesbar.');
    }

    /**
     * Die VOLLSTAENDIGE Nummer steht in keiner Log-Zeile — nur die letzten
     * vier Stellen, wie KontoWriter es mit derselben Begruendung haelt.
     *
     * Geprueft ueber alle Ausgaenge (Erfolg, gedrosselt, Fehler), weil die
     * Kuerzung an genau einer Stelle sitzt und ein Ausgang, der an ihr
     * vorbeischreibt, sonst unbemerkt bliebe.
     */
    public function testDieVolleNummerStehtInKeinerLogZeile(): void
    {
        $sender = new EinmalcodeSender($this->cache);

        for ($i = 0; $i <= CodeDrossel::MAX_JE_STUNDE; $i++) {
            $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
        }
        $this->vorlageSetzen(KontoWriter::ZWECK_ANMELDUNG, ['name' => '']);
        $sender->sende($this->personId, KontoWriter::ZWECK_ANMELDUNG);

        $text = $this->logText();

        $this->assertNotEmpty($this->log->zeilen);
        $this->assertStringNotContainsString(self::NUMMER, $text);
        $this->assertStringNotContainsString('15111111111', $text, 'auch nicht ohne Laendervorwahl');

        foreach ($this->log->zeilen as $zeile) {
            if (array_key_exists('nummer_endet_auf', $zeile['daten'])) {
                $this->assertSame('1111', $zeile['daten']['nummer_endet_auf'], 'genau vier Stellen, die letzten');
            }
        }
    }

    /**
     * Ohne aktiven WhatsApp-Kanal geht nichts raus — und es wird auch kein
     * Code erzeugt.
     *
     * Die Wache stand vor erzeugeCode(), war aber von keinem Test gehalten:
     * ohne sie liefe der Versand in eine Ausnahme aus dem Meta-Dienst, und
     * der laufende Code waere schon ueberschrieben.
     */
    public function testOhneKanalWirdNichtsVerschicktUndKeinCodeErzeugt(): void
    {
        $vorher = $this->laufenderCode();
        DB::table('comms_channels')->delete();

        $status = (new EinmalcodeSender($this->cache))->sende($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertSame(EinmalcodeSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertSame($vorher, $this->laufenderCode());
        $this->assertStringContainsString('Kanal', $this->logText());
    }

    // ------------------------------------------------------------- Hilfen

    /**
     * Eine von mehreren verschiedenen, je einmal benutzten Zielnummern.
     * Jede ist eine gueltige deutsche Mobilnummer (sonst bricht der Versand
     * schon an PhoneE164::normalize ab, und der Test pruefte nichts).
     */
    private static function zielNummer(int $i): string
    {
        return '+4915199900' . $i . '00';
    }

    /**
     * Eine zweite, eigenstaendige Person im selben Team — fuer den Nachweis,
     * dass der Nummern-Zaehler an der Nummer haengt und nicht am Anfordernden.
     */
    private function zweitePerson(): int
    {
        return (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-einmalcode-zweite',
            'team_id'    => self::TEAM,
            'phone'      => '+4915144444444',
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);
    }

    /** @return array{hash: ?string, expires: ?string, zweck: ?string} */
    private function laufenderCode(): array
    {
        $zeile = DB::table('rec_persons')->where('id', $this->personId)->first();

        return [
            'hash'    => $zeile->code_hash,
            'expires' => $zeile->code_expires_at,
            'zweck'   => $zeile->code_zweck,
        ];
    }

    /** Der eine Body-Parameter der gesendeten Komponenten. */
    private function einzigerParameter(array $components): string
    {
        $this->assertCount(1, $components, 'Genau ein Body-Component.');
        $this->assertSame('body', $components[0]['type']);
        $this->assertCount(1, $components[0]['parameters'], 'Genau ein Parameter.');

        return (string) $components[0]['parameters'][0]['text'];
    }

    private function logText(): string
    {
        return json_encode($this->log->zeilen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param array<string, mixed> $aenderung */
    private function vorlageSetzen(string $zweck, array $aenderung): void
    {
        $pfad = "recruiting.konto.code_vorlagen.{$zweck}";
        config()->set($pfad, array_merge((array) config($pfad), $aenderung));
    }

    /**
     * Die Meta-Attrappe — bewusst NICHT grosszuegig.
     *
     * Sie kennt GENAU EINE genehmigte Vorlage samt der Parameternamen, die
     * diese erwartet, und antwortet sonst mit status=failed und einer
     * Fehlermeldung im meta_payload — so wie Meta es tut. Eine Attrappe, die
     * jeden Vorlagennamen und jeden Platzhalter annimmt, wuerde nichts
     * pruefen: der Test waere auch dann gruen, wenn der Sender eine erfundene
     * Vorlage mit falschen Platzhaltern verschickt.
     */
    private function metaAttrappe(): object
    {
        return new class(self::VORLAGE) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            /** @var array<string, list<string>> Vorlagenname => erwartete Parameternamen in Reihenfolge */
            public array $genehmigt = [];

            /** Wirft eine Ausnahme, deren Text den gesendeten Parameter woertlich enthaelt. */
            public bool $wirftMitParameter = false;

            /**
             * Lehnt ab UND zitiert den beanstandeten Wert in der Meldung —
             * so, wie Meta es bei einem Parameterfehler tut
             * ("Parameter format does not match: <wert>"). Ohne diesen Knopf
             * koennte diese Testklasse gar nicht herstellen, was
             * ohneCode() an der meta_payload-Meldung zu verhindern hat.
             */
            public bool $lehntAbMitWert = false;

            public function __construct(string $vorlage)
            {
                $this->genehmigt = [$vorlage => ['code']];
            }

            public function sendTemplate(
                $channel,
                string $to,
                string $templateName,
                array $components = [],
                string $languageCode = 'de',
                $sender = null,
                bool $isAutoReply = false,
            ) {
                $this->calls[] = [
                    'channel'      => $channel,
                    'to'           => $to,
                    'templateName' => $templateName,
                    'components'   => $components,
                    'languageCode' => $languageCode,
                    'sender'       => $sender,
                    'isAutoReply'  => $isAutoReply,
                ];

                $namen  = [];
                $werte  = [];
                foreach ($components as $component) {
                    foreach ($component['parameters'] ?? [] as $parameter) {
                        $namen[] = (string) ($parameter['parameter_name'] ?? '');
                        $werte[] = (string) ($parameter['text'] ?? '');
                    }
                }

                if ($this->wirftMitParameter) {
                    throw new \RuntimeException(
                        'HTTP 400 beim Senden: {"template":{"name":"' . $templateName
                        . '","components":[{"text":"' . implode(',', $werte) . '"}]}}'
                    );
                }

                if ($this->lehntAbMitWert) {
                    return $this->abgelehnt('Parameter format does not match: ' . implode(',', $werte));
                }

                $erwartet = $this->genehmigt[$templateName] ?? null;

                if ($erwartet === null) {
                    return $this->abgelehnt('Template name (' . $templateName . ') does not exist in de');
                }

                if ($namen !== $erwartet) {
                    return $this->abgelehnt('Number of parameters does not match the expected number of params');
                }

                return new class {
                    public string $status = 'sent';

                    public array $meta_payload = [];
                };
            }

            private function abgelehnt(string $meldung): object
            {
                return new class($meldung) {
                    public string $status = 'failed';

                    public array $meta_payload;

                    public function __construct(string $meldung)
                    {
                        $this->meta_payload = ['error' => ['message' => $meldung]];
                    }
                };
            }
        };
    }

    /**
     * Ein mitschreibender Cache um einen ArrayStore.
     *
     * Der ArrayStore selbst ist grosszuegiger als der Wirt (dort laeuft
     * CACHE_STORE=database mit cache.key als varchar(255)); die
     * mitgeschriebenen Schluessel machen wenigstens sichtbar, was geschrieben
     * wurde.
     */
    private function cacheAttrappe(): object
    {
        return new class(new ArrayStore()) extends CacheRepository {
            /** @var list<string> */
            public array $schluessel = [];

            public function put($key, $value, $ttl = null): bool
            {
                $this->schluessel[] = (string) $key;

                return parent::put($key, $value, $ttl);
            }
        };
    }

    /**
     * comms_channels, integrations_whatsapp_accounts und
     * rec_applicant_settings aus den ECHTEN Migrationen — Vorbild
     * RecruitingChannelSetTest. Ihre Form gehoert nicht diesem Modul.
     */
    private function echteMigrationen(): void
    {
        $eigen        = dirname(__DIR__, 2);
        $crm          = $this->paketWurzel(CommsChannel::class);
        $integrations = $this->paketWurzel(IntegrationsWhatsAppTemplate::class);

        $dateien = [
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
        ];

        foreach ($dateien as [$wurzel, $relativ]) {
            $migration = require $wurzel . '/' . $relativ;
            $migration->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        return dirname((new \ReflectionClass($klasse))->getFileName(), 3);
    }

    /** WABA-Konto, Kanal und Team-Einstellung — der Weg, den RecruitingChannelResolver geht. */
    private function kanalAufsetzen(): void
    {
        $kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid'         => 'acc-konto-code',
            'phone_number' => '+49 160 5552001',
            'title'        => 'Recruiting-WABA',
            'active'       => true,
            'user_id'      => 1,
        ]);

        DB::table('comms_channels')->insert([
            'team_id'           => self::TEAM,
            'name'              => 'Recruiting WhatsApp',
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552001',
            'is_active'         => true,
            'meta'              => json_encode(['integrations_whatsapp_account_id' => $kontoId]),
            'created_at'        => self::ANGEFASST,
            'updated_at'        => self::ANGEFASST,
        ]);

        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(['auto_pilot_wa_account_id' => $kontoId]),
        ]);
    }
}
