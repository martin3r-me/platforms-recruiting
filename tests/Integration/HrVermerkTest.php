<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Support\HrEinstellungen;

final class HrVermerkTest extends TestCase
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

    public function test_tabelle_und_spalte_kommen_aus_den_migrationen(): void
    {
        $spalten = Capsule::schema()->getColumnListing('rec_hr_vermerke');
        sort($spalten);
        $this->assertSame(
            ['art', 'bezug', 'created_at', 'ergebnis', 'id', 'notiz', 'rec_employee_id', 'rec_person_id', 'ruhe_bis', 'team_id', 'user_id'],
            $spalten,
            'kein updated_at: nur anfuegen (Spec §7.1)'
        );
        $this->assertTrue(Capsule::schema()->hasColumn('rec_persons', 'sitzungen_ungueltig_ab'));
    }

    public function test_down_raeumt_beides_wieder_ab(): void
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations/';
        (require $dir . '2026_10_12_000003_add_sitzungen_ungueltig_ab_to_rec_persons.php')->down();
        (require $dir . '2026_10_12_000002_create_rec_hr_vermerke_table.php')->down();

        $this->assertFalse(Capsule::schema()->hasTable('rec_hr_vermerke'));
        $this->assertFalse(Capsule::schema()->hasColumn('rec_persons', 'sitzungen_ungueltig_ab'));

        // hasTable-/hasColumn-Wache: ein zweites up() nach up() wirft nicht.
        (require $dir . '2026_10_12_000002_create_rec_hr_vermerke_table.php')->up();
        (require $dir . '2026_10_12_000002_create_rec_hr_vermerke_table.php')->up();
        (require $dir . '2026_10_12_000003_add_sitzungen_ungueltig_ab_to_rec_persons.php')->up();
        (require $dir . '2026_10_12_000003_add_sitzungen_ungueltig_ab_to_rec_persons.php')->up();
        $this->assertTrue(Capsule::schema()->hasTable('rec_hr_vermerke'));
    }

    public function test_anfuegen_schreibt_eine_zeile_mit_allen_feldern(): void
    {
        $id = RecHrVermerk::anfuegen($this->team, 5, 9, 'kontaktiert', '  angerufen, kommt morgen  ', null, 'ok', now()->addDays(3), 42);

        $zeile = DB::table('rec_hr_vermerke')->where('id', $id)->first();
        $this->assertSame('kontaktiert', $zeile->art);
        $this->assertSame('angerufen, kommt morgen', $zeile->notiz, 'getrimmt');
        $this->assertSame(5, (int) $zeile->rec_person_id);
        $this->assertSame(9, (int) $zeile->rec_employee_id);
        $this->assertSame(42, (int) $zeile->user_id);
        $this->assertSame('2026-10-15 10:00:00', (string) $zeile->ruhe_bis);
        $this->assertSame('2026-10-12 10:00:00', (string) $zeile->created_at);
    }

    public function test_unbekannte_art_fehlende_notiz_und_fehlender_bezugspunkt_werfen(): void
    {
        foreach ([
            fn () => RecHrVermerk::anfuegen($this->team, 5, null, 'geloescht', 'eine Notiz'),
            fn () => RecHrVermerk::anfuegen($this->team, 5, null, 'antrag_gestoppt', '   '),
            fn () => RecHrVermerk::anfuegen($this->team, null, null, 'kontaktiert', 'eine Notiz'),
        ] as $fall) {
            try {
                $fall();
                $this->fail('erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('rec_hr_vermerke')->count());

        // erneut_erinnert ohne Notiz ist erlaubt (Spec §7.1).
        RecHrVermerk::anfuegen($this->team, 5, null, 'erneut_erinnert', null, 'aufgaben', 'sent');
        $this->assertSame(1, DB::table('rec_hr_vermerke')->count());
    }

    public function test_letzte_liefert_person_und_akten_neueste_zuerst_nur_im_team(): void
    {
        RecHrVermerk::anfuegen($this->team, 5, null, 'kontaktiert', 'erste Notiz', null, 'ok', null, 42);
        \Carbon\Carbon::setTestNow('2026-10-12 11:00:00');
        RecHrVermerk::anfuegen($this->team, null, 9, 'portal_entsperrt', 'zweite Notiz', null, 'ok', null, 42);
        RecHrVermerk::anfuegen(99, 5, null, 'kontaktiert', 'fremdes Team', null, 'ok', null, 42);

        $zeilen = RecHrVermerk::letzte($this->team, 5, [9], 10);

        $this->assertSame(['portal_entsperrt', 'kontaktiert'], $zeilen->pluck('art')->all());
        $this->assertNotContains('fremdes Team', $zeilen->pluck('notiz')->all());
    }

    public function test_einstellungen_haben_vorgaben_und_werden_begrenzt(): void
    {
        $this->assertFalse(HrEinstellungen::notfallWegAktiv($this->team));
        $this->assertSame(5, HrEinstellungen::erinnertTage($this->team));
        $this->assertSame(3, HrEinstellungen::kontaktiertRuheTage($this->team));

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->einstellung('hr_erinnert_tage', '99');
        $this->einstellung('hr_kontaktiert_ruhe_tage', '0');

        $this->assertTrue(HrEinstellungen::notfallWegAktiv($this->team));
        $this->assertSame(60, HrEinstellungen::erinnertTage($this->team));
        $this->assertSame(1, HrEinstellungen::kontaktiertRuheTage($this->team));
        $this->assertFalse(HrEinstellungen::notfallWegAktiv(null), 'Person ohne Team: fail closed');
        $this->assertFalse(HrEinstellungen::notfallWegAktiv(123), 'Team ohne Einstellungszeile: Vorgabe aus');
    }
}
