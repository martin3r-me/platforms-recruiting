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
        $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}, {{datum}}'], $this->button()];
        $this->assertStringContainsString('datum', (string) DispoJobVorlagenWaechter::problem($c));
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
