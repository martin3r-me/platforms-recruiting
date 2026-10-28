<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVorlagenWaechter;

class DispoJobVorlagenWaechterTest extends TestCase
{
    private function button(): array
    {
        return ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Ansehen', 'url' => 'https://x/jobs/{{1}}']]];
    }

    public function testOhneDynamischenButtonAnPositionNull(): void
    {
        $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}']];
        $this->assertStringContainsString('Link-Button', (string) DispoJobVorlagenWaechter::problem($c));
    }

    public function testFremdePlatzhalter(): void
    {
        $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}, {{ort}}'], $this->button()];
        $this->assertStringContainsString('ort', (string) DispoJobVorlagenWaechter::problem($c));
    }

    /** 28.10.: Dringend-Vorlage traegt {{datum}}, der Sender fuellt es — der Waechter darf es nicht blocken. */
    public function testDatumIstErlaubt(): void
    {
        $c = [['type' => 'BODY', 'text' => 'Hey {{name}}, für den {{datum}} brauchen wir dich'], $this->button()];
        $this->assertNull(DispoJobVorlagenWaechter::problem($c));
    }

    public function testPasst(): void
    {
        $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}'], $this->button()];
        $this->assertNull(DispoJobVorlagenWaechter::problem($c));
        $this->assertNull(DispoJobVorlagenWaechter::problem(json_encode($c)));
    }

    public function testLeereVorlage(): void
    {
        $this->assertNotNull(DispoJobVorlagenWaechter::problem(null));
        $this->assertNotNull(DispoJobVorlagenWaechter::problem('kaputt'));
    }
}
