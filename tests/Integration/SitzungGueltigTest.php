<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Services\PortalAuth;

final class SitzungGueltigTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_ohne_sitzungswert_ist_nichts_gueltig(): void
    {
        $ma = $this->akte();
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
    }

    /** Review Focus 3, Hälfte 1: Altsitzungen von vor dem Deploy leben weiter. */
    public function test_altwert_true_lebt_ohne_ungueltigkeit_weiter(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id));
    }

    /** Review Focus 3, Hälfte 2. */
    public function test_altwert_true_stirbt_mit_dem_zuruecksetzen(): void
    {
        $ma = $this->akte();
        $personId = $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
        $this->assertFalse($this->sitzung->has(PortalAuth::sessionKey($ma->id)), 'der Schluessel wird verworfen');
    }

    public function test_anmeldung_vor_der_grenze_stirbt_danach_lebt(): void
    {
        $ma = $this->akte();
        $personId = $this->person([], $ma);

        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());
        Carbon::setTestNow('2026-10-12 10:05:00');
        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id), 'gleich alt oder aelter als die Grenze: weg');

        Carbon::setTestNow('2026-10-12 10:06:00');
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());
        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id), 'neue Anmeldung nach der Grenze gilt');
    }

    public function test_grenze_gilt_fuer_alle_akten_der_person(): void
    {
        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG', 'personnel_number' => 'RG777']);
        $personId = $this->person([], $ma, $rg);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);
        $this->sitzung->put(PortalAuth::sessionKey($rg->id), true);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
        $this->assertFalse(PortalAuth::sitzungGueltig($rg->id));
    }

    public function test_akte_ohne_personen_zeile_gilt_wie_heute(): void
    {
        $ma = $this->akte();
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id));
    }

    public function test_alle_schreibstellen_speichern_den_anmeldezeitpunkt(): void
    {
        $this->assertSame('2026-10-12T10:00:00+00:00', PortalAuth::anmeldeWert());
        foreach (['src/Livewire/Public/KontoAnmelden.php', 'src/Livewire/Public/PortalShell.php', 'src/Livewire/Public/EmployeePortal.php'] as $datei) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $datei);
            $this->assertDoesNotMatchRegularExpression('/session\(\)->put\([^;]*,\s*true\)/', $code, "{$datei} schreibt noch true");
            $this->assertDoesNotMatchRegularExpression('/session\(\)->has\(/', $code, "{$datei} prueft die Sitzung noch selbst");
        }
    }

    public function test_portal_shell_mount_und_jede_aktion_beenden_eine_ungueltige_sitzung(): void
    {
        $ma = $this->akte(['portal_token' => 'tok-shell']);
        $personId = $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $offen = new PortalShell();
        $offen->mount('tok-shell', $this->portalAuth());
        $this->assertSame('verified', $offen->state);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        // Offene Gruppe/Dokument mit Daten — muss mit dem Ende der Sitzung weg.
        $offen->profilGruppe = 'Bank';
        $offen->profilWerte = ['iban' => 'DE89370400440532013000'];
        $offen->dokumentId = 77;

        // Die schon offene Seite: die naechste Aktion beendet sie (berechtigterMitarbeiter).
        $offen->oeffneDokument(999999);
        $this->assertSame('unverified', $offen->state);
        $this->assertSame('', $offen->displayName);
        $this->assertNull($offen->profilGruppe);
        $this->assertSame([], $offen->profilWerte);
        $this->assertNull($offen->dokumentId);

        // Neu laden: mount sieht keine gueltige Sitzung mehr.
        $neu = new PortalShell();
        $neu->mount('tok-shell', $this->portalAuth());
        $this->assertSame('unverified', $neu->state);
    }

    /**
     * Anmeldung in derselben Sekunde wie das Zuruecksetzen: deterministisch
     * UNGUELTIG (Anmeldezeit <= Grenze). Lieber einmal zu oft neu anmelden als
     * eine Sitzung, die den Reset womoeglich ueberlebt.
     */
    public function test_anmeldung_in_derselben_sekunde_wie_die_grenze_ist_ungueltig(): void
    {
        $ma = $this->akte();
        $personId = $this->person([], $ma);

        Carbon::setTestNow('2026-10-12 10:05:00');
        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());

        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
        $this->assertFalse($this->sitzung->has(PortalAuth::sessionKey($ma->id)));
    }

    public function test_pruefstelle_nutzt_die_uebergebene_sitzung(): void
    {
        $ma = $this->akte();
        $andere = new \Illuminate\Session\Store('andere', new \Illuminate\Session\ArraySessionHandler(60));
        $andere->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id, $andere));
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id), 'die Standard-Sitzung ist leer');
    }

    public function test_anmeldezeitpunkt_altwert_und_unlesbares_sind_null(): void
    {
        $this->assertSame(0, PortalAuth::anmeldeZeitpunkt(true));
        $this->assertSame(0, PortalAuth::anmeldeZeitpunkt('kaputt'));
        $this->assertSame(strtotime('2026-10-12T10:00:00+00:00'), PortalAuth::anmeldeZeitpunkt('2026-10-12T10:00:00+00:00'));
    }

    /**
     * Haertung: ohne Personen-Kennung (NULL, 0) keine Abfrage auf rec_persons.
     * Die 0 entsteht auch, wenn SQLite eine fehlende Spalte als String-Literal
     * liefert ("rec_person_id" -> (int) 0).
     */
    public function test_personen_kennung_null_oder_0_fragt_rec_persons_nicht(): void
    {
        $ma = $this->akte();
        DB::table('rec_employees')->where('id', $ma->id)->update(['rec_person_id' => 0]);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id));
        $sqls = array_column(DB::connection()->getQueryLog(), 'query');
        DB::connection()->disableQueryLog();
        foreach ($sqls as $sql) {
            $this->assertStringNotContainsString('rec_persons', $sql);
        }
    }
}
