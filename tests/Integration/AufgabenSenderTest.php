<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Comms\AufgabenSender;

/**
 * Der Versand der Aufgaben-Nachricht — der Hinweis, dass im MA-Portal etwas
 * fuer den naechsten Einsatz offen ist (Spec §2.3, Aufgabe 9).
 *
 * DIE FUENF ZUSAGEN, DIE HIER FALSIFIZIERT WERDEN:
 *
 *  1. OHNE VORLAGE WIRD NICHT VERSCHICKT (Konfiguration, kein stiller
 *     Fehlschlag).
 *  2. EIN VON META ABGELEHNTER VERSAND IST KEIN ERFOLG.
 *     RecEmployee::sendPortalNotification() meldet ok:true direkt nach
 *     sendTemplate(), ohne $message->status zu pruefen. Die Attrappe hier
 *     ist deshalb bewusst keine grosszuegige: sie kennt genau eine
 *     genehmigte Vorlage je Anlass samt der erwarteten Parameternamen und
 *     antwortet sonst mit status=failed, so wie Meta es tut.
 *  3. EIN UNBEFUELLBARER PLATZHALTER VERHINDERT DEN VERSAND.
 *     HoldingTemplateComponents::build() setzt bei einem unbekannten
 *     Platzhalter still den Vornamen ein — hier wird stattdessen gar nicht
 *     erst verschickt.
 *  4. EINE HTTP-AUSNAHME BEIM SENDEN REISST NICHT DEN AUFRUFER MIT (ET-11).
 *     Der Brief fuer diese Aufgabe liess das try/catch um sendTemplate() aus
 *     — ohne es wuerde eine Meta-Stoerung den ganzen Lauf des Kommandos aus
 *     Aufgabe 10 abbrechen, nicht nur einen Menschen auslassen.
 *  5. DIE VOLLE RUFNUMMER STEHT IN KEINER LOG-ZEILE (ET-9). Der Beleg darf
 *     nicht ueber eine LEERE Logliste laufen: dieser Test faehrt bewusst den
 *     FEHLERZWEIG (Meta lehnt ab), verankert zuerst, dass ueberhaupt Zeilen
 *     geschrieben wurden, und sieht sie erst DANACH durch. Eine Pruefung
 *     ueber den Erfolgszweig waere strukturell gruen, weil dort nach den
 *     bindenden Vorgaben gar keine Nummer geloggt wird.
 *
 * AUFBAU: Container + Capsule + SQLite von Hand, Vorbild EinmalcodeSenderTest
 * — rec_employees wird handgebaut (nur die hier gelesenen Spalten), waehrend
 * comms_channels, integrations_whatsapp_accounts und rec_applicant_settings
 * ueber die ECHTEN Migrationen laufen (deren Form gehoert nicht diesem
 * Modul). Die Log-Attrappe wird VOR Facade::clearResolvedInstances()
 * gebunden (reference_log_facade_test_stub.md), sonst ReflectionException.
 */
final class AufgabenSenderTest extends TestCase
{
    private const TEAM = 3;

    private const NUMMER = '+4915111111111';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private const VORLAGE_NEU = 'aufgaben_neu';

    private const VORLAGE_ERINNERUNG = 'aufgaben_erinnerung';

    private Capsule $capsule;

    /** @var object{zeilen: list<array{stufe: string, nachricht: string, daten: array}>} */
    private object $log;

    /** @var object{calls: list<array<string, mixed>>} */
    private object $meta;

    private RecEmployee $ma;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden —
        // sonst fliegt eine ReflectionException, sobald der erste
        // Log::warning(...) laeuft (reference_log_facade_test_stub.md).
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
                'aufgaben' => [
                    'vorlagen' => [
                        'neu' => [
                            'name' => self::VORLAGE_NEU, 'sprache' => 'de', 'platzhalter' => ['anzahl'],
                        ],
                        'erinnerung' => [
                            'name' => self::VORLAGE_ERINNERUNG, 'sprache' => 'de', 'platzhalter' => ['anzahl', 'datum'],
                        ],
                    ],
                ],
            ],
        ]));

        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);

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

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        $this->echteMigrationen();

        // Der ECHTE Beobachter, nicht seine Abwesenheit, ist die Zusicherung
        // der Observer-frei-Pruefung (Muster EinmalcodeSenderTest).
        RecEmployeeExportObserver::register();

        $this->kanalAufsetzen();

        $maId = (int) DB::table('rec_employees')->insertGetId([
            'team_id'        => self::TEAM,
            'first_name'     => 'Gregor',
            'last_name'      => 'Erste',
            'phone'          => self::NUMMER,
            'is_active'      => 1,
            'created_at'     => self::ANGEFASST,
            'updated_at'     => self::ANGEFASST,
        ]);
        $this->ma = RecEmployee::find($maId);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------- Konfiguration

    public function testOhneVorlageWirdNichtsVerschickt(): void
    {
        $this->vorlageSetzen('neu', ['name' => '']);

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_NICHT_KONFIGURIERT, $status);
        $this->assertSame([], $this->meta->calls);
    }

    public function testDieAusgelieferteKonfigurationKenntBeideAnlaesse(): void
    {
        $datei = require dirname(__DIR__, 2) . '/config/recruiting.php';

        $this->assertArrayHasKey('aufgaben', $datei);
        $this->assertArrayHasKey('vorlagen', $datei['aufgaben']);

        foreach (['neu' => ['anzahl'], 'erinnerung' => ['anzahl', 'datum']] as $anlass => $platzhalter) {
            $this->assertArrayHasKey($anlass, $datei['aufgaben']['vorlagen'], "Anlass {$anlass} fehlt.");
            $eintrag = $datei['aufgaben']['vorlagen'][$anlass];
            $this->assertArrayHasKey('name', $eintrag);
            $this->assertArrayHasKey('sprache', $eintrag);
            $this->assertSame($platzhalter, array_values(array_map('strval', $eintrag['platzhalter'])));
        }
    }

    // ------------------------------------------------------------- Platzhalter

    public function testEinUnbefuellbarerPlatzhalterVerhindertDenVersand(): void
    {
        $this->vorlageSetzen('neu', ['platzhalter' => ['gibt_es_nicht']]);

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls, 'Kein Versand.');
        $this->assertStringContainsString('gibt_es_nicht', $this->logText(), 'Das Log nennt den Platzhalter.');
    }

    /**
     * Die Erinnerung erwartet {{datum}} — ohne bevorstehenden Einsatz ist der
     * Wert nicht befuellbar, und die Nachricht geht nicht raus. Eine leere
     * Vorlage mit Datum waere formal zustellbar und MISSVERSTAENDLICH ("am "
     * ohne Tag) — lieber kein Versand als ein falscher.
     */
    public function testFehlenderEinsatzBeiDerErinnerungVerhindertDenVersand(): void
    {
        $status = (new AufgabenSender())->sende($this->ma, $this->stand(datum: null), 'erinnerung');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
    }

    /**
     * Gegenprobe zu obigem Test: `anzahl` darf bei NULL offenen Punkten den
     * Wert "0" tragen und MUSS trotzdem als befuellt gelten. Eine Pruefung
     * ueber `empty($wert)` statt `$wert === ''` wuerde "0" faelschlich als
     * unbefuellbar behandeln und faende keinen eigenen Test — deshalb eigens
     * hier verankert.
     */
    public function testNullOffenePunkteGiltAlsBefuellt(): void
    {
        $status = (new AufgabenSender())->sende($this->ma, $this->stand(anzahl: 0), 'neu');

        $this->assertSame(AufgabenSender::STATUS_SENT, $status);
        $this->assertSame('0', $this->meta->calls[0]['components'][0]['parameters'][0]['text']);
    }

    /**
     * Ein grossgeschrieben eingetragener Platzhalter ('Anzahl') wird trotzdem
     * befuellt UND als parameter_name KLEIN verschickt — Meta laesst als
     * parameter_name nur Kleinbuchstaben zu (gleiche Begruendung wie Fund N4
     * bei EinmalcodeSender). Eine Konfiguration, die die Grossschreibung
     * durchreicht, wuerde bei Meta abgelehnt — und der Versand gaelte als
     * Fehlschlag, obwohl die Konfiguration formal vollstaendig war.
     */
    public function testEinGrossGeschriebenerPlatzhalterWirdKleinVerschickt(): void
    {
        $this->vorlageSetzen('neu', ['platzhalter' => ['Anzahl']]);

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(anzahl: 3), 'neu');

        $this->assertSame(AufgabenSender::STATUS_SENT, $status);
        $this->assertSame('anzahl', $this->meta->calls[0]['components'][0]['parameters'][0]['parameter_name']);
    }

    // ------------------------------------------------------------------ Meta

    /**
     * Ein von Meta ABGELEHNTER Versand ergibt `failed`, nicht `sent`
     * (Altfehler 1: RecEmployee::sendPortalNotification() prueft
     * $message->status nicht).
     */
    public function testEinVonMetaAbgelehnterVersandIstKeinErfolg(): void
    {
        $this->meta->lehntAb();

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status);
        $this->assertCount(1, $this->meta->calls, 'Der Versuch lief — abgelehnt hat Meta, nicht wir.');
    }

    /**
     * ET-11: eine HTTP-Ausnahme beim Senden darf nicht aus sende()
     * herausfliegen — sie wuerde sonst die Schleife des Kommandos aus
     * Aufgabe 10 fuer ALLE nachfolgenden Menschen abbrechen, nicht nur fuer
     * diesen einen.
     */
    public function testEineAusnahmeBeimSendenWirdAbgefangenUndErgibtFailed(): void
    {
        $this->meta->wirft(new \RuntimeException('HTTP 500 bei Meta'));

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status);
    }

    public function testErfolgreicherVersandSchicktDieKonfigurierteVorlage(): void
    {
        $status = (new AufgabenSender())->sende($this->ma, $this->stand(anzahl: 2), 'neu');

        $this->assertSame(AufgabenSender::STATUS_SENT, $status);
        $this->assertCount(1, $this->meta->calls);

        $call = $this->meta->calls[0];
        $this->assertSame(self::VORLAGE_NEU, $call['templateName']);
        $this->assertSame(self::NUMMER, $call['to']);
        $this->assertSame('de', $call['languageCode']);
        $this->assertSame(
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'parameter_name' => 'anzahl', 'text' => '2'],
            ]]],
            $call['components'],
        );
    }

    public function testErinnerungSchicktAuchDasDatum(): void
    {
        $status = (new AufgabenSender())->sende($this->ma, $this->stand(anzahl: 1, datum: '2026-10-20'), 'erinnerung');

        $this->assertSame(AufgabenSender::STATUS_SENT, $status);
        $this->assertSame(
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'parameter_name' => 'anzahl', 'text' => '1'],
                ['type' => 'text', 'parameter_name' => 'datum', 'text' => '2026-10-20'],
            ]]],
            $this->meta->calls[0]['components'],
        );
    }

    /**
     * Die Nachricht nennt die Punkte NICHT einzeln (Spec §2.1) — nur ihre
     * Anzahl steht in den Komponenten. Ein Test, der eine Code- oder
     * Label-Zeichenkette in den Parametern erwartet, haette hier nichts zu
     * pruefen: es gibt dort keinen Platz dafuer.
     */
    public function testDieNachrichtNenntKeineEinzelnenPunkte(): void
    {
        $stand = $this->stand(anzahl: 2);
        $stand['punkte'][0]['label'] = 'Aufenthaltstitel';
        $stand['punkte'][1]['label'] = 'Ausweis';

        (new AufgabenSender())->sende($this->ma, $stand, 'neu');

        $text = json_encode($this->meta->calls[0]['components']);
        $this->assertStringNotContainsString('Aufenthaltstitel', $text);
        $this->assertStringNotContainsString('Ausweis', $text);
    }

    // --------------------------------------------------------------- Kanal

    public function testOhneAktivenKanalWirdNichtsVerschickt(): void
    {
        DB::table('comms_channels')->delete();

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
    }

    // ------------------------------------------------------- ET-9: das Log

    /**
     * DIE VOLLE RUFNUMMER STEHT IN KEINER LOG-ZEILE.
     *
     * Dieser Test faehrt bewusst den FEHLERZWEIG (Meta lehnt ab) und
     * verankert ZUERST, dass ueberhaupt Zeilen geschrieben wurden — sonst
     * waere die anschliessende Schleife ueber eine leere Liste strukturell
     * gruen, ohne irgendetwas geprueft zu haben (genau die Luecke, die der
     * Brief fuer diese Aufgabe hatte).
     */
    public function testDieVolleNummerStehtInKeinerLogZeile(): void
    {
        $this->meta->lehntAb();

        $status = (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $this->assertSame(AufgabenSender::STATUS_FAILED, $status, 'Vorflug: der Fehlerzweig muss tatsaechlich laufen.');
        $this->assertNotEmpty($this->log->zeilen, 'Es muss ueberhaupt etwas geloggt worden sein.');

        foreach ($this->log->zeilen as $zeile) {
            $this->assertStringNotContainsString(self::NUMMER, json_encode($zeile));
            $this->assertStringNotContainsString('15111111111', json_encode($zeile), 'auch nicht ohne Laendervorwahl');
        }

        $mitNummer = array_filter($this->log->zeilen, fn ($z) => array_key_exists('nummer_endet_auf', $z['daten']));
        $this->assertNotEmpty($mitNummer, 'Mindestens eine Zeile traegt die gekuerzte Nummer.');
        foreach ($mitNummer as $zeile) {
            $this->assertSame('1111', $zeile['daten']['nummer_endet_auf'], 'genau vier Stellen, die letzten');
        }
    }

    // ------------------------------------------------------- Observer-frei

    /**
     * OBSERVER-FREI: ein Versand fasst die Anstellung nicht an. Beleg ist
     * updated_at (das fasst nur ein Eloquent-Schreibweg automatisch an) UND
     * zas_changed_at — sonst spuelte eine Versandwelle den halben Bestand in
     * die naechste ZAS-Update-Datei. Der echte RecEmployeeExportObserver ist
     * registriert, der Test prueft also nicht bloss dessen Abwesenheit.
     */
    public function testVersandFasstDieAnstellungNichtAn(): void
    {
        (new AufgabenSender())->sende($this->ma, $this->stand(), 'neu');

        $zeile = DB::table('rec_employees')->where('id', $this->ma->id)->first();

        $this->assertSame(self::ANGEFASST, $zeile->updated_at);
        $this->assertNull($zeile->zas_changed_at);
    }

    // ------------------------------------------------------------ Attrappe

    /**
     * Die Attrappe darf nicht von der echten Signatur wegdriften — sonst
     * nimmt sie jeden Aufruf an und prueft nichts (Muster EinmalcodeSenderTest).
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

    // ------------------------------------------------------------- Hilfen

    /**
     * @return array{punkte: list<array{code:string,label:string,status:string,ko:bool}>, einsatz: ?array{datum:string,taetigkeit:?string,event:?string}, gesperrt: bool}
     */
    private function stand(int $anzahl = 1, ?string $datum = '2026-10-20'): array
    {
        $punkte = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $punkte[] = ['code' => 'ausweis', 'label' => 'Ausweis', 'status' => 'fehlt', 'ko' => true];
        }

        return [
            'punkte'   => $punkte,
            'einsatz'  => $datum !== null ? ['datum' => $datum, 'taetigkeit' => 'Service', 'event' => 'Messe'] : null,
            'gesperrt' => false,
        ];
    }

    private function logText(): string
    {
        return json_encode($this->log->zeilen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param array<string, mixed> $aenderung */
    private function vorlageSetzen(string $anlass, array $aenderung): void
    {
        $pfad = "recruiting.aufgaben.vorlagen.{$anlass}";
        config()->set($pfad, array_merge((array) config($pfad), $aenderung));
    }

    /**
     * Die Meta-Attrappe — bewusst NICHT grosszuegig. Sie kennt genau die
     * zwei konfigurierten Vorlagen samt der Parameternamen, die diese in
     * dieser Reihenfolge erwarten, und antwortet sonst mit status=failed —
     * so wie Meta es tut. Eine Attrappe, die jeden Vorlagennamen und jeden
     * Platzhalter annimmt, wuerde nichts pruefen.
     */
    private function metaAttrappe(): object
    {
        return new class(self::VORLAGE_NEU, self::VORLAGE_ERINNERUNG) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            /** @var array<string, list<string>> */
            public array $genehmigt = [];

            private bool $lehntAb = false;

            private ?\Throwable $wirft = null;

            public function __construct(string $neu, string $erinnerung)
            {
                $this->genehmigt = [
                    $neu        => ['anzahl'],
                    $erinnerung => ['anzahl', 'datum'],
                ];
            }

            public function lehntAb(): void
            {
                $this->lehntAb = true;
            }

            public function wirft(\Throwable $e): void
            {
                $this->wirft = $e;
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

                if ($this->wirft !== null) {
                    throw $this->wirft;
                }

                if ($this->lehntAb) {
                    return $this->abgelehnt('Meta hat den Versand abgelehnt.');
                }

                $namen = [];
                foreach ($components as $component) {
                    foreach ($component['parameters'] ?? [] as $parameter) {
                        $namen[] = (string) ($parameter['parameter_name'] ?? '');
                    }
                }

                $erwartet = $this->genehmigt[$templateName] ?? null;
                if ($erwartet === null || $namen !== $erwartet) {
                    return $this->abgelehnt('Template oder Parameter unbekannt: ' . $templateName);
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
     * comms_channels, integrations_whatsapp_accounts und
     * rec_applicant_settings aus den ECHTEN Migrationen — Vorbild
     * EinmalcodeSenderTest. Ihre Form gehoert nicht diesem Modul.
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
            'uuid'         => 'acc-aufgaben',
            'phone_number' => '+49 160 5552002',
            'title'        => 'Recruiting-WABA',
            'active'       => true,
            'user_id'      => 1,
        ]);

        DB::table('comms_channels')->insert([
            'team_id'           => self::TEAM,
            'name'              => 'Recruiting WhatsApp',
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552002',
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
