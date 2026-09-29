<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Support\Einmalcode;

/**
 * KontoWriter ist der EINE Schreiber der Kontofelder an rec_persons.
 *
 * Diese Tests zielen auf die Stellen, an denen ein Fehler still bliebe:
 * ein Einladungs-Token, der ein zweites Mal gilt; ein Einmalcode, der nach
 * dem Einloesen zehn Minuten lang beliebig oft funktioniert (Fund F13 der
 * Pruefung — Einmalcode hat KEIN benutzt_at, das Entwerten muss hier
 * passieren); eine Anmeldung, deren Antwortzeit verraet, ob es die Nummer
 * gibt; und eine Kontoaenderung, die ueber Eloquent laeuft und damit den
 * halben Bestand in die naechste ZAS-Update-Datei spuelt.
 *
 * Das Schema wird von Hand gebaut (Migrationen laufen hier nicht), Vorbild
 * ist PersonLinkerTest — inklusive Log-Attrappe vor
 * Facade::clearResolvedInstances() (reference_log_facade_test_stub.md) und
 * dem ECHTEN RecEmployeeExportObserver, damit der Marker-Test nicht nur
 * beweist, dass es in diesem Lauf gar keinen Beobachter gibt.
 *
 * Der Hasher ist eine mitzaehlende Attrappe um BcryptHasher (mit wenigen
 * Runden, damit die Suite nicht an bcrypt haengt). Der Zaehler ist kein
 * Beiwerk: er ist der einzige Weg, den Dummy-Vergleich aus
 * pruefeAnmeldung() zu beweisen — ohne ihn koennte man den Vergleich
 * entfernen und kein Test wuerde rot.
 */
final class KontoWriterTest extends TestCase
{
    private const TEAM = 3;

    private const NUMMER = '+4915111111111';

    private const GEBURT = '1990-05-17';

    private const PASSWORT = 'ganz-geheim-2026';

    private const PFEFFER = 'pfeffer-fuer-den-test';

    /** Feste Uhrzeit fuer die Anstellungen — der ZAS-Marker-Test liest sie zurueck. */
    private const ANGEFASST = '2026-09-28 09:00:00';

    private Capsule $capsule;

    /** @var object{checks: int} */
    private object $hasher;

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden —
        // sonst fliegt eine ReflectionException, sobald der echte Observer
        // in safelyRun() \Log::warning(...) aufruft.
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['konto' => ['pepper' => self::PFEFFER]],
        ]));

        // Vier Runden statt zwoelf: bcrypt ist absichtlich langsam, und diese
        // Klasse hasht in fast jedem Testfall. Der Hasher zaehlt jeden
        // check() mit — siehe Klassen-Docblock.
        $this->hasher = new class(['rounds' => 4]) extends BcryptHasher {
            public int $checks = 0;

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $container->instance('hash', $this->hasher);

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

        // RecEmployeeExportObserver::trackPayrollChanges() liest die
        // Team-Einstellungen — ohne die Tabelle liefe der Beobachter in den
        // stillen Fehlerzweig, und der Marker-Test pruefte den Marker, ohne
        // den echten Beobachter-Code gelaufen zu sein.
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        RecEmployeeExportObserver::register();

        $this->personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-konto-test',
            'team_id'    => self::TEAM,
            'phone'      => self::NUMMER,
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);

        // Zwei Anstellungen derselben Person (RG und MA) — genau der Fall,
        // fuer den es die Personen-Klammer gibt.
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
             'birth_date' => self::GEBURT, 'rec_person_id' => $this->personId, 'company' => 'RG',
             'phone' => self::NUMMER, 'is_active' => 1,
             'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST],
            ['id' => 2, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Zweite',
             'birth_date' => self::GEBURT, 'rec_person_id' => $this->personId, 'company' => 'MA',
             'phone' => self::NUMMER, 'is_active' => 1,
             'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST],
        ]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance('hash');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- Hilfen

    private function zeile(): object
    {
        return DB::table('rec_persons')->where('id', $this->personId)->first();
    }

    private function kontoEinrichten(): void
    {
        $token = KontoWriter::ladeEin($this->personId);
        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
    }

    /**
     * Nur den Schluessel umsetzen, nicht die ganze Konfiguration ersetzen:
     * die Capsule legt ihre Verbindungsdaten in DIESE Repository-Instanz
     * (Capsule::setupContainer nutzt ein vorhandenes 'config'), ein Austausch
     * nimmt der Datenbank also die Verbindung.
     */
    private function setzePfeffer(string $pfeffer): void
    {
        Container::getInstance()->make('config')->set('recruiting.konto.pepper', $pfeffer);
    }

    /** Ein Code, der sicher NICHT der richtige ist — kein Zufall, kein Millionstel-Risiko. */
    private function falscherCode(string $richtiger): string
    {
        return ((int) $richtiger[0] + 1) % 10 . substr($richtiger, 1);
    }

    // ------------------------------------------------------------ Einladung

    public function test_registrieren_setzt_passwort_und_verbraucht_den_token(): void
    {
        $token = KontoWriter::ladeEin($this->personId);

        $vorher = $this->zeile();
        $this->assertNotNull($vorher->invite_token_hash);
        $this->assertNotSame(
            $token,
            $vorher->invite_token_hash,
            'der Klartext des Tokens darf nie in der Datenbank stehen, nur sein Hash',
        );
        $this->assertNotNull($vorher->invite_expires_at);

        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);

        $nachher = $this->zeile();
        $this->assertNotNull($nachher->password_hash);
        $this->assertNotSame(self::PASSWORT, $nachher->password_hash, 'das Passwort liegt als Hash, nie im Klartext');
        $this->assertTrue(Hash::check(self::PASSWORT, $nachher->password_hash));
        $this->assertNotNull($nachher->registered_at);
        $this->assertNotNull($nachher->invite_used_at, 'der Token muss als verbraucht markiert sein');
    }

    public function test_ein_zweites_mal_mit_demselben_token_geht_nicht(): void
    {
        $token = KontoWriter::ladeEin($this->personId);
        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);

        try {
            KontoWriter::registriere($this->personId, $token, self::GEBURT, 'ein-anderes-passwort');
            $this->fail('der verbrauchte Token haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertTrue(
            Hash::check(self::PASSWORT, $this->zeile()->password_hash),
            'ein zweiter Lauf darf das Passwort des echten Inhabers nicht ueberschreiben',
        );
    }

    public function test_ein_abgelaufener_token_geht_nicht(): void
    {
        $token = KontoWriter::ladeEin($this->personId);
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['invite_expires_at' => '2026-09-01 10:00:00']);

        try {
            KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
            $this->fail('der abgelaufene Token haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertNull($this->zeile()->password_hash);
    }

    public function test_falsches_geburtsdatum_geht_nicht(): void
    {
        $token = KontoWriter::ladeEin($this->personId);

        try {
            KontoWriter::registriere($this->personId, $token, '1991-05-17', self::PASSWORT);
            $this->fail('das falsche Geburtsdatum haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertNull($this->zeile()->password_hash);
        $this->assertNull(
            $this->zeile()->invite_used_at,
            'ein Tippfehler im Geburtsdatum darf die Einladung nicht verbrennen',
        );

        // Gegenprobe: mit dem richtigen Datum geht es danach noch.
        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
        $this->assertNotNull($this->zeile()->password_hash);
    }

    /**
     * Ohne hinterlegtes Geburtsdatum gibt es keinen zweiten Nachweis — und
     * ohne zweiten Nachweis traegt der achtstellige Token die Anmeldung
     * allein (Spec §2.4). Dann lieber gar keine Registrierung.
     */
    public function test_ohne_hinterlegtes_geburtsdatum_gibt_es_keine_registrierung(): void
    {
        DB::table('rec_employees')->update(['birth_date' => null]);
        $token = KontoWriter::ladeEin($this->personId);

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
    }

    /**
     * Eine zweite Einladung muss die erste toeten (sonst laufen zwei
     * Geheimnisse nebeneinander) und selbst gelten — und nach einer
     * abgeschlossenen Registrierung muss ein erneutes Einladen wieder
     * funktionieren, also invite_used_at abraeumen. Ohne dieses Abraeumen
     * waere jede zweite Einladung still tot.
     */
    public function test_eine_neue_einladung_ersetzt_die_alte_und_gilt_auch_nach_einer_registrierung(): void
    {
        $alt = KontoWriter::ladeEin($this->personId);
        $neu = KontoWriter::ladeEin($this->personId);

        try {
            KontoWriter::registriere($this->personId, $alt, self::GEBURT, self::PASSWORT);
            $this->fail('die ersetzte Einladung haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        KontoWriter::registriere($this->personId, $neu, self::GEBURT, self::PASSWORT);
        $this->assertNotNull($this->zeile()->invite_used_at);

        // Und jetzt der Fall "HR laedt noch einmal ein" — invite_used_at
        // steht, die neue Einladung muss trotzdem gelten.
        $nochmal = KontoWriter::ladeEin($this->personId);
        $this->assertNull($this->zeile()->invite_used_at, 'eine neue Einladung raeumt den Verbraucht-Stempel ab');

        KontoWriter::registriere($this->personId, $nochmal, self::GEBURT, 'zweites-passwort-2026');
        $this->assertTrue(Hash::check('zweites-passwort-2026', $this->zeile()->password_hash));
    }

    // ------------------------------------------------------------ Anmeldung

    public function test_anmelden_mit_richtigem_passwort(): void
    {
        $this->kontoEinrichten();

        $this->assertSame(
            $this->personId,
            KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT),
        );
        $this->assertNotNull($this->zeile()->letzte_anmeldung_at);
    }

    /**
     * Der Mensch tippt "0151...", gespeichert ist "+49151..." — ohne
     * Normalisierung an dieser Grenze faende die Anmeldung ihr eigenes Konto
     * nicht (dieselbe Falle wie Ruling T3-B bei PersonLinker).
     */
    public function test_die_nummer_wird_vor_der_suche_normalisiert(): void
    {
        $this->kontoEinrichten();

        $this->assertSame(
            $this->personId,
            KontoWriter::pruefeAnmeldung(self::TEAM, '0151 11111111', self::PASSWORT),
        );
    }

    public function test_anmelden_mit_falschem_passwort_scheitert(): void
    {
        $this->kontoEinrichten();

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, 'falsches-passwort'));
        $this->assertNull($this->zeile()->letzte_anmeldung_at);
    }

    /**
     * Ohne den Dummy-Vergleich verraet die Antwortzeit, ob es die Nummer
     * gibt — und dann laesst sich der Bestand an Nummern durchprobieren.
     * Der Zaehler der Hasher-Attrappe ist der einzige Beleg dafuer, dass
     * wirklich verglichen wurde.
     */
    public function test_eine_unbekannte_nummer_prueft_trotzdem_ein_passwort(): void
    {
        $this->kontoEinrichten();
        $this->hasher->checks = 0;

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, '+4915199999999', self::PASSWORT));

        $this->assertGreaterThan(
            0,
            $this->hasher->checks,
            'auch bei unbekannter Nummer muss ein Passwortvergleich stattfinden, sonst verraet die Antwortzeit das Konto',
        );
    }

    public function test_eine_gesperrte_person_meldet_sich_nicht_an(): void
    {
        $this->kontoEinrichten();
        DB::table('rec_persons')->where('id', $this->personId)->update(['locked_at' => '2026-09-29 08:00:00']);
        $this->hasher->checks = 0;

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT));

        $this->assertGreaterThan(0, $this->hasher->checks, 'auch die Sperre wird erst NACH dem Vergleich beantwortet');
        $this->assertNull($this->zeile()->letzte_anmeldung_at);
    }

    public function test_eine_stillgelegte_person_meldet_sich_nicht_an(): void
    {
        $this->kontoEinrichten();
        $sieger = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-sieger', 'team_id' => self::TEAM,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
        DB::table('rec_persons')->where('id', $this->personId)->update(['merged_into_person_id' => $sieger]);
        $this->hasher->checks = 0;

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT));

        $this->assertGreaterThan(0, $this->hasher->checks, 'auch die Stilllegung wird erst NACH dem Vergleich beantwortet');
    }

    /**
     * Canvas 1793: Konten Ausgeschiedener werden gesperrt, weil Anbieter
     * Handynummern nach Monaten neu vergeben — sonst meldet sich der
     * NAECHSTE Inhaber der Nummer in einer fremden Akte an.
     */
    public function test_wer_nur_inaktive_anstellungen_hat_meldet_sich_nicht_an(): void
    {
        $this->kontoEinrichten();
        DB::table('rec_employees')->update(['is_active' => 0]);
        $this->hasher->checks = 0;

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT));

        $this->assertGreaterThan(0, $this->hasher->checks, 'auch das Ausscheiden wird erst NACH dem Vergleich beantwortet');

        // Gegenprobe: EINE aktive Anstellung genuegt.
        DB::table('rec_employees')->where('id', 2)->update(['is_active' => 1]);
        $this->assertSame($this->personId, KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT));
    }

    // ----------------------------------------------------------------- Codes

    public function test_ein_code_fuer_passwort_gilt_nicht_fuer_den_nummernwechsel(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);

        try {
            KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, $code);
            $this->fail('ein abgefangener Passwort-Code darf keinen Nummernwechsel bestaetigen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertSame(
            self::NUMMER,
            DB::table('rec_persons')->where('id', $this->personId)->value('phone'),
            'die Nummer darf sich dabei nicht geaendert haben',
        );

        // Gegenprobe: fuer seinen eigenen Zweck gilt der Code weiterhin.
        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);
    }

    /**
     * Fund F13: Einmalcode hat KEIN benutzt_at wie der Einladungs-Token.
     * Wer das Entwerten hier vergisst, baut einen Code, der zehn Minuten
     * lang beliebig oft gilt.
     */
    public function test_ein_eingeloester_code_gilt_kein_zweites_mal(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);

        $zeile = $this->zeile();
        $this->assertNull($zeile->code_hash);
        $this->assertNull($zeile->code_expires_at);
        $this->assertNull($zeile->code_zweck);
        $this->assertNull($zeile->code_neue_nummer);
        $this->assertSame(0, (int) $zeile->code_versuche);

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);
    }

    public function test_ein_abgelaufener_code_gilt_nicht(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['code_expires_at' => '2026-09-01 10:00:00']);

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);
    }

    /**
     * Sechs Ziffern sind eine Million Moeglichkeiten — ohne mitzaehlenden
     * Zaehler waere das an einem Nachmittag durchprobiert. Der Zaehler wird
     * bei MAX_VERSUCHE gedeckelt: code_versuche ist ein TINYINT, ein
     * ungebremstes Hochzaehlen liefe irgendwann in den Ueberlauf.
     */
    public function test_fehlversuche_werden_gezaehlt_und_sperren_den_code(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);
        $falsch = $this->falscherCode($code);

        for ($i = 0; $i < Einmalcode::MAX_VERSUCHE + 1; $i++) {
            try {
                KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $falsch);
                $this->fail('ein falscher Code haette abgewiesen werden muessen');
            } catch (\InvalidArgumentException $e) {
                // erwartet
            }
        }

        $this->assertSame(
            Einmalcode::MAX_VERSUCHE,
            (int) $this->zeile()->code_versuche,
            'jeder Fehlversuch zaehlt, aber der Zaehler laeuft nicht ueber den TINYINT hinaus',
        );

        $this->expectException(\InvalidArgumentException::class);

        // Jetzt gilt auch der RICHTIGE Code nicht mehr.
        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);
    }

    public function test_die_nummer_wandert_beim_wechsel_auf_alle_anstellungen(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, '0152 33344455');

        $this->assertSame(
            '+4915233344455',
            $this->zeile()->code_neue_nummer,
            'die neue Nummer wird normalisiert abgelegt, sonst geht der Code an eine andere Schreibweise',
        );

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, $code);

        $this->assertSame('+4915233344455', $this->zeile()->phone);
        $this->assertSame(
            ['+4915233344455', '+4915233344455'],
            DB::table('rec_employees')->orderBy('id')->pluck('phone')->all(),
            'sonst kommt der naechste Einmalcode auf einer anderen Nummer an als die Anmeldung',
        );
    }

    public function test_ein_nummernwechsel_ohne_lesbare_neue_nummer_wird_abgewiesen(): void
    {
        try {
            KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL);
            $this->fail('ein Nummernwechsel ohne Ziel haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, 'nicht-lesbar');
    }

    /**
     * Ein getippter Zweck ("passwort_reset") wuerde sonst still einen Code
     * ablegen, den kein Einloesen je trifft — und der Mensch wartet auf eine
     * Bestaetigung, die nie kommt.
     */
    public function test_ein_unbekannter_zweck_wird_abgewiesen(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::erzeugeCode($this->personId, 'passwort_reset');
    }

    /**
     * Ohne Ruecksetzen des Zaehlers waere ein Mensch nach fuenf Fehlversuchen
     * dauerhaft ausgesperrt: jeder neue Code kaeme schon verbraucht zur Welt.
     * Die Bremse gegen unbegrenztes Nachfordern ist nicht dieser Zaehler,
     * sondern CodeDrossel an der Versandstelle.
     */
    public function test_ein_neuer_code_gibt_frische_versuche(): void
    {
        $alt = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);
        $falsch = $this->falscherCode($alt);

        for ($i = 0; $i < Einmalcode::MAX_VERSUCHE; $i++) {
            try {
                KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $falsch);
            } catch (\InvalidArgumentException $e) {
                // erwartet
            }
        }

        $neu = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);
        $this->assertSame(0, (int) $this->zeile()->code_versuche);

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $neu);
        $this->assertNull($this->zeile()->code_hash);
    }

    /** Ein neuer Code fuer einen anderen Zweck darf keine alte neue Nummer mitschleppen. */
    public function test_ein_neuer_code_raeumt_die_neue_nummer_des_alten_ab(): void
    {
        KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, '0152 33344455');

        KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->assertNull($this->zeile()->code_neue_nummer);
        $this->assertSame(KontoWriter::ZWECK_PASSWORT, $this->zeile()->code_zweck);
    }

    public function test_der_code_steht_nur_als_hash_in_der_datenbank(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_ANMELDUNG);

        $this->assertNotSame($code, $this->zeile()->code_hash);
        $this->assertStringNotContainsString($code, (string) $this->zeile()->code_hash);
    }

    // -------------------------------------------------------------- Passwort

    public function test_setze_passwort_tauscht_das_alte_aus(): void
    {
        $this->kontoEinrichten();

        KontoWriter::setzePasswort($this->personId, 'ein-neues-geheimnis');

        $this->assertNull(KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT));
        $this->assertSame(
            $this->personId,
            KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, 'ein-neues-geheimnis'),
        );
    }

    public function test_ein_zu_kurzes_passwort_wird_abgewiesen(): void
    {
        $this->kontoEinrichten();

        try {
            KontoWriter::setzePasswort($this->personId, 'kurz');
            $this->fail('das zu kurze Passwort haette abgewiesen werden muessen');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Zeichen', $e->getMessage(), 'die Meldung der Regel muss durchgereicht werden');
        }

        $this->assertTrue(
            Hash::check(self::PASSWORT, $this->zeile()->password_hash),
            'das alte Passwort muss stehen bleiben',
        );

        // Auch beim Registrieren gilt die Regel — sonst gibt es zwei Wege
        // mit verschiedenen Massstaeben.
        DB::table('rec_persons')->where('id', $this->personId)->update(['password_hash' => null]);
        $token = KontoWriter::ladeEin($this->personId);

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::registriere($this->personId, $token, self::GEBURT, 'kurz');
    }

    // ----------------------------------------------------------- Pfeffer GD-5

    /**
     * Ruling GD-5: Erzeugen und Pruefen muessen denselben Pfeffer benutzen.
     * Wechselt er, sterben die offenen Einladungen (sieben Tage) — das ist
     * gewollt und der Preis dafuer, dass ein Datenbank-Dump allein die
     * Geheimnisse nicht zurueckrechnen kann.
     */
    public function test_ein_pfefferwechsel_toetet_die_offene_einladung(): void
    {
        $token = KontoWriter::ladeEin($this->personId);

        $this->setzePfeffer('ein-ganz-anderer-pfeffer');

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
    }

    /**
     * Die andere Haelfte von GD-5 und der Grund fuer die Asymmetrie: das
     * PASSWORT wird nicht gepfeffert. Wuerde der Pfeffer es beruehren,
     * sperrte sein Verlust jeden Mitarbeiter DAUERHAFT aus statt nur die
     * offenen Einladungen.
     */
    public function test_ein_pfefferwechsel_sperrt_niemanden_aus(): void
    {
        $this->kontoEinrichten();

        $this->setzePfeffer('ein-ganz-anderer-pfeffer');

        $this->assertSame(
            $this->personId,
            KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT),
            'das Passwort haengt an Hash::make(), nicht am Pfeffer',
        );
    }

    /**
     * Derselbe Code, mit zwei verschiedenen Pfeffern erzeugt und geprueft,
     * darf nie gelten — sonst waere der Pfeffer nur Zierde.
     */
    public function test_ein_pfefferwechsel_toetet_den_laufenden_code(): void
    {
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);

        $this->setzePfeffer('ein-ganz-anderer-pfeffer');

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_PASSWORT, $code);
    }

    // --------------------------------------------------------------- Wachen

    public function test_eine_gesperrte_person_bekommt_weder_einladung_noch_code_noch_passwort(): void
    {
        DB::table('rec_persons')->where('id', $this->personId)->update(['locked_at' => '2026-09-29 08:00:00']);

        foreach ([
            fn () => KontoWriter::ladeEin($this->personId),
            fn () => KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT),
            fn () => KontoWriter::setzePasswort($this->personId, self::PASSWORT),
        ] as $nummer => $aufruf) {
            try {
                $aufruf();
                $this->fail("Aufruf {$nummer} haette an der Sperre scheitern muessen");
            } catch (\InvalidArgumentException $e) {
                // erwartet
            }
        }

        $zeile = $this->zeile();
        $this->assertNull($zeile->invite_token_hash);
        $this->assertNull($zeile->code_hash);
        $this->assertNull($zeile->password_hash);
    }

    public function test_eine_stillgelegte_person_bekommt_keine_einladung(): void
    {
        $sieger = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-sieger', 'team_id' => self::TEAM,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
        DB::table('rec_persons')->where('id', $this->personId)->update(['merged_into_person_id' => $sieger]);

        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::ladeEin($this->personId);
    }

    public function test_eine_unbekannte_person_wird_benannt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KontoWriter::ladeEin(999999);
    }

    // ------------------------------------------------------------ ZAS-Marker

    /**
     * Der wichtigste Test dieser Klasse. zas_changed_at ALLEIN beweist
     * nichts: die Kontofelder stehen gar nicht in
     * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS, und 'phone' steht
     * dort seit dem 03.09. bewusst ebenfalls nicht — ein Wechsel auf
     * Eloquent liesse den Marker also unberuehrt und den Test still gruen.
     *
     * Der tragende Beleg ist updated_at: Eloquent fasst die Spalte bei jedem
     * Speichern automatisch an, der Query Builder nur auf ausdrueckliche
     * Anweisung. Und damit der Test nicht aus dem anderen Grund gruen bleibt
     * — weil gar nichts geschrieben wurde — wird zuerst belegt, dass die
     * neue Nummer WIRKLICH an beiden Anstellungen steht.
     */
    public function test_kontoaenderungen_setzen_keinen_zas_marker(): void
    {
        DB::table('rec_employees')->update(['zas_changed_at' => null]);

        $token = KontoWriter::ladeEin($this->personId);
        KontoWriter::registriere($this->personId, $token, self::GEBURT, self::PASSWORT);
        KontoWriter::pruefeAnmeldung(self::TEAM, self::NUMMER, self::PASSWORT);
        KontoWriter::setzePasswort($this->personId, 'noch-ein-geheimnis');
        $code = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, '0152 33344455');
        KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, $code);

        // Vorflug: die Aenderung hat rec_employees wirklich erreicht.
        $this->assertSame(
            ['+4915233344455', '+4915233344455'],
            DB::table('rec_employees')->orderBy('id')->pluck('phone')->all(),
            'sonst waere dieser Test aus dem falschen Grund gruen',
        );

        $this->assertSame(
            0,
            (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(),
            'eine Kontoaenderung darf niemanden in die updates.csv spuelen',
        );
        $this->assertSame(
            [self::ANGEFASST, self::ANGEFASST],
            DB::table('rec_employees')->orderBy('id')->pluck('updated_at')->map(fn ($v) => (string) $v)->all(),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, der Query Builder nicht',
        );
    }
}
