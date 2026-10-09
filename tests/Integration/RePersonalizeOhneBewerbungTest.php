<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Tools\RePersonalizeContractsTool;
use ReflectionMethod;

/**
 * Spec Vertrag aus der Akte §2.6: das MCP-Werkzeug zum Neu-Rendern warf bei
 * einem Vertrag ohne Bewerbung einen TypeError (personalizeContent() verlangt
 * einen Bewerber) — und der Catch-all liess damit den ganzen Lauf scheitern.
 * Probe: im Werkzeug den Zweig ohne Bewerbung entfernen → hier rot.
 */
final class RePersonalizeOhneBewerbungTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function neuRendern(RecContract $v): ?string
    {
        $m = new ReflectionMethod(RePersonalizeContractsTool::class, 'neuRendern');
        $m->setAccessible(true);

        return $m->invoke(new RePersonalizeContractsTool(), $v->fresh(['contractTemplate', 'applicant']));
    }

    public function test_vertrag_ohne_bewerbung_rendert_ueber_die_anstellung(): void
    {
        $ma = $this->anstellung(['first_name' => 'Ida', 'last_name' => 'Ohnebewerbung']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));

        $inhalt = $this->neuRendern($v);

        $this->assertSame($v->contractTemplate->personalizeFuerAnstellung($ma, $v), $inhalt);
        $this->assertStringContainsString('Ida', (string) $inhalt);
    }

    public function test_vertrag_mit_bewerbung_bleibt_auf_dem_alten_weg(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG3']), $b);
        $v = $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'));

        $this->assertSame($v->contractTemplate->personalizeContent($b, $v), $this->neuRendern($v));
    }

    public function test_vertrag_ganz_ohne_anker_wird_uebersprungen(): void
    {
        $v = RecContract::create([
            'rec_contract_template_id' => $this->vorlage('AV-MA-LOG')->id, 'team_id' => $this->team,
            'status' => 'sent', 'personalized_content' => '<p>alt</p>',
        ]);

        $this->assertNull($this->neuRendern($v));
    }
}
