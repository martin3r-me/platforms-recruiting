<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;

/** Spec Lohn MA §5/§11: Feld RG-Zuschlag und Block "Lohn an ZAS" in der Akte (Logik ohne Livewire-Render). */
final class LohnMaAkteTest extends TestCase
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

    public function test_feld_nur_an_rg_akte_ohne_bewerbung(): void
    {
        $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]);
        $rgMitBewerbung = $this->akte(['company' => 'RG', 'rec_applicant_id' => 500]);
        $ma = $this->akte(['company' => 'MA']);

        $this->assertTrue(Show::zeigtZuschlagManuell($rg));
        $this->assertFalse(Show::zeigtZuschlagManuell($rgMitBewerbung));
        $this->assertFalse(Show::zeigtZuschlagManuell($ma));

        // R2: ein brauchbarer ZAS-Wert (Grundlohn > 0, Zuschlag gesetzt) macht das Feld ueberfluessig.
        $rgZas = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]);
        DB::table('rec_employee_hr_data')->insert([
            'uuid' => 'hr-' . $rgZas->id, 'team_id' => $this->team, 'rec_employee_id' => $rgZas->id, 'zas_grundlohn' => 13.90, 'zas_zuschlag' => 1.10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse(Show::zeigtZuschlagManuell($rgZas));

        // Grundlohn 0 = "bei ZAS nicht gepflegt" -> Feld bleibt sichtbar.
        $rgNull = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]);
        DB::table('rec_employee_hr_data')->insert([
            'uuid' => 'hr-' . $rgNull->id, 'team_id' => $this->team, 'rec_employee_id' => $rgNull->id, 'zas_grundlohn' => 0, 'zas_zuschlag' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(Show::zeigtZuschlagManuell($rgNull));
    }

    /** Review Focus 5. */
    public function test_eingabe_wird_geprueft(): void
    {
        $this->assertSame(1.10, Show::zuschlagManuellAusEingabe('1,10'));
        $this->assertSame(1.10, Show::zuschlagManuellAusEingabe('1.10'));
        $this->assertNull(Show::zuschlagManuellAusEingabe(''), 'leer = leeren');
        $this->assertSame('ungueltig', Show::zuschlagManuellAusEingabe('abc'));
        $this->assertSame('ungueltig', Show::zuschlagManuellAusEingabe('1,1,1'));
    }

    public function test_block_lohn_nur_an_ma_akte(): void
    {
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $ma = $this->akte(['company' => 'MA', 'tax_class' => '6']));

        // person() setzt rec_person_id nur in der Datenbank — Modelle neu laden.
        $rg = $rg->fresh();
        $ma = $ma->fresh();

        $this->assertNull(Show::lohnBlock($rg));
        $b = Show::lohnBlock($ma);
        $this->assertSame('unbekannt', $b['fall']);
        $this->assertNull($b['zuschlag']);
        $this->assertSame((int) $rg->id, $b['rg_employee_id']);
        $this->assertMatchesRegularExpression('~^\d{2}\.\d{2}\.\d{4}$~', $b['eg1_ab']);
    }

    public function test_zas_lohnfelder_zeigen_euro_mit_komma(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Employees/Show.php');
        $this->assertSame(2, preg_match_all("~'zas_(grundlohn|zuschlag)'[^\\n]*'format' => 'euro'~", $quelle));

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/show.blade.php');
        $this->assertStringContainsString("(\$meta['format'] ?? null) === 'euro'", $blade);
        $this->assertStringContainsString("number_format((float) \$displayValue, 2, ',', '.')", $blade);
    }
}
