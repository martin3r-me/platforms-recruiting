<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\OffenePunkte;

/**
 * Was ist offen, und fuer welchen Einsatz — das Scharnier zwischen den
 * reinen Regelklassen (ProofReader, Arbeitserlaubnis, EinsatzBezug) und dem
 * Portal/Versand.
 *
 * Handgebautes Schema (Modul-Konvention: Migrationen laufen hier NICHT).
 * Gegen die echten Migrationen abgeglichen (siehe Bericht): alle Spalten, die
 * OffenePunkte selbst in Bedingung/Spaltenliste nennt
 * (missing_since, rec_employee_id, datum, status_id, taetigkeit,
 * rec_dispo_event_id) sowie alle, die ProofReader/PersonScopeResolver lesen
 * (is_active, is_eu_citizen, employment_type, is_first_aider, rec_person_id,
 * person_key, phone) stehen hier — eine fehlende Spalte wuerde SQLite
 * stillschweigend zum String-Literal machen (reference_pruefmuster_gruenes_nichts).
 */
final class OffenePunkteTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    private int $naechsteId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

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
            $t->timestamps();
        });

        // Dokumente (Task 1): OffenePunkte liest jetzt auch rec_document_recipients — die Tabellen kommen aus den Migrationen.
        foreach (['2026_10_09_000001_create_rec_documents_table', '2026_10_09_000002_create_rec_document_recipients_table'] as $m) {
            (require dirname(__DIR__, 2) . '/database/migrations/' . $m . '.php')->up();
        }

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->timestamps();
        });

        // ProofReader::current() liest hier.
        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64);
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->integer('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
            $t->integer('uploaded_by_user_id')->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_dispo_events', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('einsatz_ref')->unique();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_dispo_assignments', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('ds_ref')->unique();
            $t->integer('rec_dispo_event_id')->nullable();
            $t->string('pnr_raw')->nullable();
            $t->integer('rec_employee_id')->nullable();
            $t->date('datum');
            $t->string('von', 8)->nullable();
            $t->string('bis', 8)->nullable();
            $t->unsignedTinyInteger('status_id')->default(0);
            $t->string('taetigkeit')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('missing_since')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function personAnlegen(): int
    {
        return (int) DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-'.$this->naechsteId++,
            'team_id' => self::TEAM,
            'created_at' => '2026-12-01 09:00:00',
            'updated_at' => '2026-12-01 09:00:00',
        ]);
    }

    /** Vorgabe: aktive Anstellung eines EU-Buergers ohne Besonderheit. */
    private function mitarbeiter(array $attr = []): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'uuid' => 'e-'.$this->naechsteId++,
            'team_id' => self::TEAM,
            'phone' => '+4915112345678',
            'is_active' => true,
            'is_eu_citizen' => true,
            'employment_type' => 'aushilfe',
            'is_first_aider' => false,
            'created_at' => '2026-12-01 09:00:00',
            'updated_at' => '2026-12-01 09:00:00',
        ], $attr));

        return RecEmployee::find($id);
    }

    private function einbuchung(RecEmployee $employee, array $attr = []): void
    {
        $eventId = (int) DB::table('rec_dispo_events')->insertGetId([
            'uuid' => 'ev-'.$this->naechsteId++,
            'einsatz_ref' => 'RG-'.$this->naechsteId,
            'name' => $attr['event_name'] ?? null,
            'created_at' => '2026-12-01 09:00:00',
            'updated_at' => '2026-12-01 09:00:00',
        ]);
        unset($attr['event_name']);

        DB::table('rec_dispo_assignments')->insert(array_merge([
            'uuid' => 'as-'.$this->naechsteId++,
            'ds_ref' => 'DS-'.$this->naechsteId,
            'rec_dispo_event_id' => $eventId,
            'pnr_raw' => 'RG14',
            'rec_employee_id' => $employee->id,
            'datum' => '2026-12-12',
            'status_id' => 1,
            'created_at' => '2026-12-01 09:00:00',
            'updated_at' => '2026-12-01 09:00:00',
        ], $attr));
    }

    private function nachweis(RecEmployee $employee, string $code, ?string $validUntil = null): void
    {
        DB::table('rec_employee_proofs')->insert([
            'uuid' => 'pf-'.$this->naechsteId++,
            'team_id' => self::TEAM,
            'rec_employee_id' => $employee->id,
            'proof_type_code' => $code,
            'valid_until' => $validUntil,
            'created_at' => '2026-12-01 09:00:00',
            'updated_at' => '2026-12-01 09:00:00',
        ]);
    }

    /** @param list<array{code:string}> $punkte */
    private function zeileMitCode(array $punkte, string $code): array
    {
        foreach ($punkte as $zeile) {
            if ($zeile['code'] === $code) {
                return $zeile;
            }
        }

        $this->fail("Kein Punkt mit Code '{$code}' in der Liste.");
    }

    public function test_die_liste_nennt_den_naechsten_einsatz_als_bezug(): void
    {
        $person = $this->personAnlegen();
        $ma = $this->mitarbeiter(['rec_person_id' => $person]);
        $this->einbuchung($ma, ['datum' => '2026-12-12', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $stand = (new OffenePunkte())->fuer($ma, '2026-12-01');

        $this->assertSame('2026-12-12', $stand['einsatz']['datum']);
        $this->assertSame('Service', $stand['einsatz']['taetigkeit']);
    }

    public function test_ohne_kommenden_einsatz_steht_kein_bezug_da(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);

        $this->assertNull((new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz']);
    }

    public function test_ein_fehlender_aufenthaltstitel_macht_den_punkt_zum_ko(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen(), 'is_eu_citizen' => false]);

        $stand = (new OffenePunkte())->fuer($ma, '2026-12-01');
        $titel = $this->zeileMitCode($stand['punkte'], 'aufenthaltstitel');

        $this->assertTrue($titel['ko']);
        $this->assertTrue($stand['gesperrt']);
        // Label und Status an den richtigen Schluesseln (nicht vertauscht) —
        // das Portal (Aufgabe 11) zeigt das Label an und entscheidet am Status.
        $this->assertSame('Aufenthaltstitel', $titel['label']);
        $this->assertSame('fehlt', $titel['status']);
    }

    public function test_ein_fehlender_ausweis_sperrt_nicht(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen(), 'is_eu_citizen' => true]);

        $stand = (new OffenePunkte())->fuer($ma, '2026-12-01');

        $this->assertFalse($stand['gesperrt']);
        $this->assertFalse($this->zeileMitCode($stand['punkte'], 'ausweis')['ko']);
    }

    public function test_erledigte_punkte_stehen_nicht_in_der_liste(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->nachweis($ma, 'ausweis');

        $codes = array_column((new OffenePunkte())->fuer($ma, '2026-12-01')['punkte'], 'code');

        $this->assertNotContains('ausweis', $codes);
    }

    /**
     * Mutationsprobe 3 (Brief): `whereNull('missing_since')` entfernen muss
     * diesen Test rot werden lassen. Eine verschwundene Einbuchung (im
     * letzten ZAS-Vollbestand nicht mehr enthalten) darf nicht als "naechster
     * Einsatz" angezeigt werden — sie ist keine verlaessliche Grundlage fuer
     * eine Aufgabe im Portal.
     */
    public function test_eine_verschwundene_einbuchung_zaehlt_nicht_als_naechster_einsatz(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->einbuchung($ma, [
            'datum' => '2026-12-12',
            'status_id' => 1,
            'missing_since' => '2026-11-30 09:00:00',
        ]);

        $this->assertNull((new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz']);
    }

    /**
     * Der Einsatz-Bezug gehoert der PERSON, nicht der einen Anstellung, mit
     * der man gerade zu tun hat (gleiches Muster wie
     * ProofReaderPersonPflichtenTest) — die Einbuchung haengt an der
     * SCHWESTER-Anstellung derselben Person, nicht an $ma selbst. Das deckt
     * NUR die Richtung "zu wenig" (die Einbuchung der Schwester darf nicht
     * fehlen) — nicht, dass ein FREMDER Mensch draussen bleibt (das deckt
     * test_ein_fremder_mensch_taucht_nicht_als_einsatz_bezug_auf), und nicht,
     * dass BEIDE eigenen Anstellungen gemeinsam zaehlen statt nur
     * irgendeiner (das deckt
     * test_der_einsatz_bezug_zaehlt_beide_anstellungen_eigene_zuerst).
     * Nachbesserung Review Runde 1: ein ersatzloses Entfernen des gesamten
     * Personen-Umfang-Filters ueberlebte hier noch (M-A), weil es in der
     * ganzen Testklasse keinen zweiten Menschen MIT Einbuchung gab.
     */
    public function test_der_einsatz_einer_anderen_anstellung_derselben_person_zaehlt_mit(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
        $ma = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);
        $this->einbuchung($rg, ['datum' => '2026-12-20', 'status_id' => 1, 'taetigkeit' => 'Garderobe']);

        $einsatz = (new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz'];

        $this->assertSame('2026-12-20', $einsatz['datum']);
        $this->assertSame('Garderobe', $einsatz['taetigkeit']);
    }

    /**
     * Nachbesserung M-A (Review Runde 1, der schwerste Fund): die
     * Gegenrichtung zum Test oben. Ein FREMDER Mensch (eigene
     * rec_person_id, keine Beziehung zu $ich) hat eine Einbuchung — die darf
     * $ich niemals als seinen Bezug sehen. Vorher liess sich der gesamte
     * Personen-Umfang-Filter in naechsterEinsatz() ersatzlos loeschen, ohne
     * dass ein Test rot wurde: "zu wenig" (Schwester fehlt) war gedeckt, "zu
     * viel" (ein Fremder leckt herein) nicht — und "zu viel" ist die
     * gefaehrlichere Richtung, weil sie die Daten eines anderen Menschen
     * zeigen wuerde.
     */
    public function test_ein_fremder_mensch_taucht_nicht_als_einsatz_bezug_auf(): void
    {
        $ich = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $fremder = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->einbuchung($fremder, ['datum' => '2026-12-05', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $this->assertNull((new OffenePunkte())->fuer($ich, '2026-12-01')['einsatz']);
    }

    /**
     * Nachbesserung M-C (Review Runde 1): Spiegelung des
     * Schwester-Tests oben. Die Einbuchung haengt diesmal an BEIDEN
     * Anstellungen derselben Person, die EIGENE (hier abgefragte, $ma)
     * zeitlich FRUEHER als die der Schwester ($rg). Nur so ist bewiesen,
     * dass die eigene Anstellung selbst mitzaehlt (nicht nur die Schwester)
     * UND dass die Vereinigung nach dem fruehesten Datum sortiert
     * (EinsatzBezug::naechster()). Ein Mutant, der nur die ERSTE Anstellung
     * des aufgeloesten Umfangs nimmt (z. B. array_slice auf Index 0), haette
     * den Schwester-Test allein ueberlebt, weil dort die Schwester ($rg) die
     * einzige mit Einbuchung ist und zugleich zuerst im Scope steht — hier
     * nicht, weil die Einbuchung der ZWEITEN Anstellung im Scope ($ma) die
     * naeher liegende ist.
     */
    public function test_der_einsatz_bezug_zaehlt_beide_anstellungen_eigene_zuerst(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
        $ma = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);
        $this->einbuchung($rg, ['datum' => '2026-12-20', 'status_id' => 1, 'taetigkeit' => 'Garderobe']);
        $this->einbuchung($ma, ['datum' => '2026-12-10', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $einsatz = (new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz'];

        $this->assertSame('2026-12-10', $einsatz['datum']);
        $this->assertSame('Service', $einsatz['taetigkeit']);
    }

    /**
     * Nachbesserung M-E (Review Runde 1): ein stornierter Einsatz darf nicht
     * als "naechster Bezug" durchgereicht werden. Die Regel selbst steckt in
     * EinsatzBezug::naechster() (ET-13) und ist dort schon gedeckt — dieser
     * Test sichert zu, dass OffenePunkte den ECHTEN status_id-Wert der
     * Einbuchung hereinreicht (nicht etwa hartcodiert 1), sonst koennte die
     * Regel hier nie greifen.
     */
    public function test_ein_stornierter_einsatz_ist_kein_naechster_bezug(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->einbuchung($ma, ['datum' => '2026-12-12', 'status_id' => 3]);

        $this->assertNull((new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz']);
    }

    /**
     * Nachbesserung M-F/M-U (Review Runde 1, die "Zeitbombe"): bis hierhin
     * benutzten ALLE Tests '2026-12-01' als Heute — und damit war der Wert
     * austauschbar gegen `now()->toDateString()`, ohne dass ein Test das
     * gemerkt haette. ZEITLOS statt eines zweiten Stichtags: $heute wird
     * absichtlich weit in die VERGANGENHEIT gelegt (2020), die Testdaten
     * liegen dazwischen (nach $heute, aber vor dem tatsaechlichen "jetzt" zum
     * Zeitpunkt JEDES kuenftigen Testlaufs — dieser Code existiert erst seit
     * 2026, "jetzt" liegt also garantiert immer nach 2024/2025). Mit dem
     * ECHTEN $heute (2020) ist der Einsatz noch Jahre entfernt (kommender
     * Bezug) und der Ausweis noch Jahre gueltig (status 'ok', nicht offen).
     * Ersetzt der Mutant $heute durch das echte "jetzt" (M-F) bzw. durch
     * null, das intern auf "jetzt" faellt (M-U, siehe ProofReader::checklist()
     * `$heute ?? now()->toDateString()`), kippt BEIDES: der Einsatz liegt
     * dann in der Vergangenheit (kein Bezug mehr) und der Ausweis ist
     * abgelaufen (steht dann in $punkte). Beide Assertions treffen daher
     * unabhaengig vom Tag, an dem dieser Test tatsaechlich laeuft.
     */
    public function test_ein_heute_weit_in_der_vergangenheit_veraendert_den_stand_nicht_auf_jetzt(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->nachweis($ma, 'ausweis', '2025-06-01');
        $this->einbuchung($ma, ['datum' => '2024-06-01', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $stand = (new OffenePunkte())->fuer($ma, '2020-01-01');

        $this->assertSame('2024-06-01', $stand['einsatz']['datum'] ?? null, 'Einsatz 2024 ist aus Sicht von 2020 noch kommend.');
        $this->assertNotContains('ausweis', array_column($stand['punkte'], 'code'), 'Ausweis ist aus Sicht von 2020 noch Jahre gueltig.');
    }

    /**
     * ET-8: der versprochene Rueckgabetyp fuer `einsatz` ist exakt
     * {datum, taetigkeit, event} — kein status_id. Das ist eine geprueften
     * Zusicherung, keine blosse Behauptung im Docblock.
     */
    public function test_der_einsatz_bezug_traegt_nur_die_drei_versprochenen_felder(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->einbuchung($ma, ['datum' => '2026-12-12', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $einsatz = (new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz'];

        $this->assertSame(['datum', 'taetigkeit', 'event'], array_keys($einsatz));
    }

    public function test_der_einsatz_bezug_nennt_den_namen_der_veranstaltung(): void
    {
        $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->einbuchung($ma, [
            'datum' => '2026-12-12',
            'status_id' => 1,
            'event_name' => 'Messe Duesseldorf',
        ]);

        $einsatz = (new OffenePunkte())->fuer($ma, '2026-12-01')['einsatz'];

        $this->assertSame('Messe Duesseldorf', $einsatz['event']);
    }
}
