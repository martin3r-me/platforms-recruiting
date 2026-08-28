<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoTemplateLabels;

class DispoTemplateLabelsTest extends TestCase
{
    public function test_configured_label_wins_over_heuristic(): void
    {
        $labels = ['dispo_reminder_x' => 'Dispo-Alarm'];
        $this->assertSame('Dispo-Alarm', DispoTemplateLabels::label('dispo_reminder_x', $labels));
    }

    public function test_heuristic_fallbacks(): void
    {
        $this->assertSame('Letzte Erinnerung', DispoTemplateLabels::label('dispo_reminder2', []));
        $this->assertSame('Erinnerung', DispoTemplateLabels::label('dispo_reminder1', []));
        $this->assertSame('Dispo-Alarm', DispoTemplateLabels::label('dispo_alarm_v2', []));
        $this->assertSame('Bestätigungsanfrage', DispoTemplateLabels::label('dispo_einsatz_bestaetigung', []));
        $this->assertSame('irgendwas', DispoTemplateLabels::label('irgendwas', []));
    }

    public function test_human_preview(): void
    {
        $this->assertSame('Bestätigungsanfrage gesendet', DispoTemplateLabels::humanPreview('Template: dispo_einsatz_bestaetigung', []));
        $this->assertSame('Hallo', DispoTemplateLabels::humanPreview('Hallo', []));
    }
}
