<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\Drawer\Utils as LivewireUtils;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\KontoKasten;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Livewire\HrDesk\Mitarbeiter;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\EinladungsRiegel;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class KontoKastenTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();
        $c->instance(HrKontoAktionen::class, new HrKontoAktionen($this->portalAuth()));
        $c->instance(HrMitarbeiterListen::class, new HrMitarbeiterListen($this->portalAuth()));
        $c->instance(DispoIdentityResolver::class, new class extends DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        // Livewire-Datenspeicher (sonst je Aufruf neu, ohne ServiceProvider) — dort landen dispatch()-Events.
        $c->instance(\Livewire\Mechanisms\DataStore::class, new \Livewire\Mechanisms\DataStore());
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([HrKontoAktionen::class, HrMitarbeiterListen::class, DispoIdentityResolver::class, \Livewire\Mechanisms\DataStore::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function kasten(int $employeeId): KontoKasten
    {
        $k = new KontoKasten();
        $k->mount($employeeId);

        return $k;
    }

    /** Liest die privaten Einladungs-Felder (bewusst nicht oeffentlich, s. Klassen-Docblock). */
    private function geheim(KontoKasten $k, string $feld): ?string
    {
        return (new \ReflectionProperty(KontoKasten::class, $feld))->getValue($k);
    }

    /** @return list<string> Namen der Livewire-Events, die die Komponente ausgeloest hat. */
    private function ausgeloest(object $komponente): array
    {
        return array_map(fn ($e) => $e->serialize()['name'], (array) \Livewire\store($komponente)->get('dispatched', []));
    }

    public function test_akten_kennung_und_aktionskontext_sind_locked(): void
    {
        foreach (['employeeId', 'aktion', 'aktionEmployeeId', 'aktionWarnung', 'meldung', 'meldungStufe', 'modalFehler'] as $name) {
            $this->assertCount(1, (new \ReflectionProperty(KontoKasten::class, $name))->getAttributes(Locked::class), $name);
        }
        $this->assertCount(0, (new \ReflectionProperty(KontoKasten::class, 'notiz'))->getAttributes(Locked::class), 'die Notiz tippt HR');
    }

    /**
     * Link und Code sind KEINE oeffentlichen Eigenschaften: #[Locked] verhindert
     * das Setzen, nicht das Ausliefern — als public stuenden sie im wire:snapshot
     * (Lehre aus KontoAnlegen). Sie leben nur fuer die eine Antwort.
     */
    public function test_einladung_steht_nie_im_schnappschuss(): void
    {
        foreach (['einladungsLink', 'einladungsCode'] as $feld) {
            $this->assertTrue((new \ReflectionProperty(KontoKasten::class, $feld))->isPrivate(), $feld);
        }

        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $this->person([], $akte);
        $k = $this->kasten($akte->id);
        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();
        $code = (string) $this->geheim($k, 'einladungsCode');
        $this->assertNotSame('', $code);
        $this->assertTrue($k->einladungOffen);

        // Genau die Funktion, mit der Livewire dehydriert (HandleComponents::dehydrateProperties).
        $schnappschuss = LivewireUtils::getPublicPropertiesDefinedOnSubclass($k);
        $json = (string) json_encode($schnappschuss);
        $this->assertStringNotContainsString($code, $json);
        $this->assertStringNotContainsString('konto-anlegen', $json);

        // Naechster Request (Neuladen/Rehydrieren aus dem Schnappschuss): der Code ist weg.
        $neu = new KontoKasten();
        foreach ($schnappschuss as $name => $wert) {
            $neu->{$name} = $wert;
        }
        $this->assertNull($this->geheim($neu, 'einladungsCode'));
        $this->assertNull($this->geheim($neu, 'einladungsLink'));

        // Weder im Log noch im Vermerk noch in der Meldung.
        $this->assertStringNotContainsString($code, (string) json_encode($this->log->zeilen));
        $this->assertStringNotContainsString($code, (string) json_encode($this->vermerke()->all()));
        $this->assertStringNotContainsString($code, $k->meldung);
    }

    public function test_akte_aus_fremdem_team_zeigt_nichts_und_handelt_nicht(): void
    {
        $fremd = $this->akte(['team_id' => 8, 'birth_date' => '1990-05-17']);
        $this->person(['team_id' => 8], $fremd);
        $k = $this->kasten($fremd->id);

        $this->assertNull($k->kasten());
        $k->oeffne('einladung_neu');
        $this->assertFalse($k->modalOffen);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $k->meldung);
        $this->assertCount(0, $this->vermerke());
    }

    public function test_ohne_personen_zeile_sind_alle_knoepfe_aus(): void
    {
        $akte = $this->akte();
        $daten = $this->kasten($akte->id)->kasten();

        $this->assertTrue($daten['ohne_person']);
        $this->assertTrue($daten['knoepfe_aus']);
        $this->assertFalse($daten['kann_einladen']);

        $k = $this->kasten($akte->id);
        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();
        $this->assertStringContainsString('keine Personen-Zeile', $k->modalFehler);
        $this->assertNull($this->geheim($k, 'einladungsCode'));
    }

    public function test_einladung_neu_zeigt_link_und_code_einmal_und_schliessen_leert(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $personId = $this->person([], $akte);
        $k = $this->kasten($akte->id);
        $this->assertTrue($k->kasten()['kann_einladen']);
        $this->assertSame('nicht eingeladen', $k->kasten()['status_text']);

        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();

        $code = (string) $this->geheim($k, 'einladungsCode');
        $this->assertTrue($k->einladungOffen);
        $this->assertSame($personId, KontoWriter::personFuerEinladung($code));
        $this->assertStringContainsString($code, (string) $this->geheim($k, 'einladungsLink'));
        $this->assertSame(HrAktionErgebnis::OK, $k->meldungStufe);
        $this->assertStringNotContainsString($code, $k->meldung);

        $k->schliesseEinladung();
        $this->assertFalse($k->einladungOffen);
        $this->assertNull($this->geheim($k, 'einladungsCode'));
        $this->assertNull($this->geheim($k, 'einladungsLink'));
    }

    /** Jede wirksame Aktion leert die Zaehler des HR-Schreibtischs und meldet die Aenderung. */
    public function test_aktion_leert_die_zaehler_und_meldet_die_aenderung(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $this->person([], $akte);
        $this->cache->put(Mitarbeiter::CACHE_ZAHLEN . $this->team, ['alt'], 60);
        $this->cache->put(Mitarbeiter::CACHE_ABZEICHEN . $this->team, 99, 60);

        $k = $this->kasten($akte->id);
        $k->oeffne('einladung_neu');
        $k->notiz = 'kurz';
        $k->bestaetige();
        $this->assertSame([], $this->ausgeloest($k), 'Fehler aendert nichts — kein Event');
        $this->assertSame(99, $this->cache->get(Mitarbeiter::CACHE_ABZEICHEN . $this->team));

        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();
        $this->assertSame([Mitarbeiter::EVENT_GEAENDERT], $this->ausgeloest($k));
        $this->assertNull($this->cache->get(Mitarbeiter::CACHE_ZAHLEN . $this->team));
        $this->assertNull($this->cache->get(Mitarbeiter::CACHE_ABZEICHEN . $this->team));

        // Die Akte hoert mit (roter Kasten verschwindet ohne Neuladen).
        $on = (new \ReflectionMethod(Show::class, 'kontoGeaendert'))->getAttributes(\Livewire\Attributes\On::class);
        $this->assertCount(1, $on);
        $this->assertSame(Mitarbeiter::EVENT_GEAENDERT, $on[0]->getArguments()[0]);
    }

    public function test_zuruecksetzen_nur_fuer_registrierte(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-05 10:00:00', 'letzte_anmeldung_at' => '2026-10-11 08:15:00'], $akte);

        $daten = $this->kasten($akte->id)->kasten();

        $this->assertSame('registriert am 05.10.2026', $daten['status_text']);
        $this->assertSame('11.10.2026, 08:15', $daten['letzte_anmeldung']);
        $this->assertTrue($daten['kann_zuruecksetzen']);
        $this->assertFalse($daten['kann_einladen']);
        $this->assertSame('im neuen Portal seit 01.10.2026', $daten['pilot']);
    }

    /** Die Bestaetigung warnt: Sitzungen enden, ein offener Nummernwechsel-Antrag wird entfernt. */
    public function test_zuruecksetzen_warnt_vor_sitzungen_und_antrag(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-05 10:00:00'], $akte);
        $k = $this->kasten($akte->id);

        $k->oeffne('konto_zuruecksetzen');

        $this->assertTrue($k->modalOffen);
        $this->assertStringContainsString('laufende Anmeldungen', $k->aktionWarnung);
        $this->assertStringContainsString('Nummernwechsel-Antrag', $k->aktionWarnung);

        $k->schliesse();
        $this->assertSame('', $k->aktionWarnung);
        $k->oeffne('einladung_neu');
        $this->assertSame('', $k->aktionWarnung, 'nur beim Zuruecksetzen');
    }

    public function test_unbekannte_aktion_oeffnet_nichts(): void
    {
        $akte = $this->akte();
        $this->person([], $akte);
        $k = $this->kasten($akte->id);

        $k->oeffne('stoppen');
        $this->assertFalse($k->modalOffen);
        $k->bestaetige();
        $this->assertCount(0, $this->vermerke());
    }

    public function test_rote_box_der_akte_oeffnet_das_notiz_modal_des_kastens(): void
    {
        $this->assertFalse(method_exists(Show::class, 'unlockPortal'), 'ersetzt durch PortalEntsperrung (Spec §5.3)');
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/show.blade.php');
        $this->assertStringContainsString("\$dispatch('konto-kasten-portal-entsperren')", $blade);
        $this->assertStringContainsString('<livewire:recruiting.employees.konto-kasten', $blade);
        $this->assertStringContainsString('id="konto"', $blade);
        $this->assertStringNotContainsString('unlockPortal', $blade);

        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG', 'portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: X']);
        $this->person([], $ma, $rg);
        $k = $this->kasten($ma->id);

        $k->oeffnePortalEntsperren();
        $this->assertSame('portal_entsperren', $k->aktion);
        $this->assertSame($rg->id, $k->aktionEmployeeId, 'die gesperrte Schwester-Akte, nicht die angezeigte');

        $k->notiz = 'kurz';
        $k->bestaetige();
        $this->assertNotNull(DB::table('rec_employees')->where('id', $rg->id)->value('portal_locked_at'), 'Notiz ist Pflicht');

        $k->notiz = 'Dispo hat bestätigt.';
        $k->bestaetige();
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('portal_locked_at'));
        $this->assertSame('portal_entsperrt', $this->vermerke()->sole()->art);
    }

    public function test_verlauf_zeigt_die_letzten_zehn_beider_akten_mit_benutzer(): void
    {
        DB::table('users')->insert(['id' => 42, 'name' => 'Hanna HR', 'email' => 'hanna@example.org', 'created_at' => now(), 'updated_at' => now()]);
        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG']);
        $personId = $this->person([], $ma, $rg);
        for ($i = 1; $i <= 12; $i++) {
            \Carbon\Carbon::setTestNow(sprintf('2026-10-12 10:%02d:00', $i));
            RecHrVermerk::anfuegen($this->team, $i % 2 ? $personId : null, $i % 2 ? null : $rg->id, 'kontaktiert', "Notiz {$i}", null, 'ok', null, 42);
        }

        $verlauf = $this->kasten($ma->id)->kasten()['verlauf'];

        $this->assertCount(10, $verlauf);
        $this->assertSame('Notiz 12', $verlauf[0]['notiz']);
        $this->assertSame('Hanna HR', $verlauf[0]['wer']);
        $this->assertSame('Kontaktiert', $verlauf[0]['was']);
    }

    public function test_stillgelegte_person_zeigt_die_zielzeile_und_keine_knoepfe(): void
    {
        $akte = $this->akte();
        $ziel = $this->person([], $this->akte());
        $this->person(['merged_into_person_id' => $ziel, 'phone' => '+4915177777777'], $akte);

        $daten = $this->kasten($akte->id)->kasten();

        $this->assertSame($ziel, $daten['stillgelegt_ziel']);
        $this->assertTrue($daten['knoepfe_aus']);
        $this->assertFalse($daten['kann_einladen']);
        $this->assertFalse($daten['kann_zuruecksetzen']);
    }

    // ------------------------------------------------------------ EinladungsRiegel::fuerPerson

    public function test_riegel_fuer_eine_person(): void
    {
        $gut = $this->akte(['birth_date' => '1990-05-17']);
        $this->assertNull(EinladungsRiegel::fuerPerson($this->person([], $gut)));

        $this->assertSame(EinladungsRiegel::KEINE_NUMMER, EinladungsRiegel::fuerPerson(999999), 'unbekannte Person');

        $ohneNummer = $this->akte(['birth_date' => '1990-05-17']);
        $this->assertSame(EinladungsRiegel::KEINE_NUMMER, EinladungsRiegel::fuerPerson($this->person(['phone' => null], $ohneNummer)));

        $ohneDatum = $this->akte(['birth_date' => null]);
        $this->assertSame(EinladungsRiegel::KEIN_GEBURTSDATUM, EinladungsRiegel::fuerPerson($this->person([], $ohneDatum)));

        $ohneWa = $this->akte(['birth_date' => '1990-05-17', 'phone' => '+4915122223333']);
        $idOhneWa = $this->person([], $ohneWa);
        $this->crmNummer($ohneWa, 501, '+4915122223333', 'unavailable');
        $this->assertSame(EinladungsRiegel::KEIN_WHATSAPP, EinladungsRiegel::fuerPerson($idOhneWa));

        // Und der Kasten verweigert genau dann die Einladung.
        $k = $this->kasten($ohneWa->id);
        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();
        $this->assertNotSame('', $k->modalFehler);
        $this->assertNull($this->geheim($k, 'einladungsCode'));
        $this->assertCount(0, $this->vermerke());
    }

    private function crmNummer(RecEmployee $akte, int $kontaktId, string $nummer, string $status): void
    {
        DB::table('crm_contact_links')->insert([
            'uuid' => 'cl-' . $kontaktId, 'contact_id' => $kontaktId, 'team_id' => $this->team, 'linkable_id' => $akte->id,
            'linkable_type' => RecEmployee::class, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_phone_numbers')->insert([
            'uuid' => 'pn-' . $kontaktId, 'phone_type_id' => 1, 'phoneable_type' => 'crm_contact', 'phoneable_id' => $kontaktId, 'international' => $nummer,
            'is_active' => 1, 'whatsapp_status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
