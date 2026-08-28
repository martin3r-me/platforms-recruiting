<?php
// tests/Unit/AutoPilotCycleResetTest.php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Spec §5.1: Der Zyklus-Reset beim Auto-Advance muss den Auto-Pilot-Status
 * mit zuruecksetzen. Vorher blieb `review_needed` stehen, und die Auto-Pilot-
 * Query (ProcessAutoPilotApplicants::633) hat den Bewerber in der neuen Phase
 * nie wieder gesehen — der Erstkontakt der Folgephase kam nie.
 *
 * Modell-Test mit minimalem Capsule-Connection-Resolver fuer die Datums-Casts:
 * die Methode setzt nur Attribute (Serialisierung/Deserialisierung der Datums-
 * felder braucht aber einen Connection-Kontext). Keine Tabellen, kein Schema.
 */
final class AutoPilotCycleResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
    }
    public function testResetLoeschtAuchDenStatus(): void
    {
        $a = new RecApplicant();
        $a->auto_pilot_state_id = 5;          // review_needed
        $a->auto_pilot_completed_at = '2026-08-01 10:00:00';
        $a->auto_pilot_reminder_count = 2;
        $a->auto_pilot_last_reminder_at = '2026-08-02 10:00:00';
        $a->progress = 100;

        $a->resetAutoPilotCycle();

        $this->assertNull($a->auto_pilot_state_id, 'Status muss null sein, sonst bleibt review_needed kleben.');
        $this->assertNull($a->auto_pilot_completed_at);
        $this->assertSame(0, $a->auto_pilot_reminder_count);
        $this->assertNull($a->auto_pilot_last_reminder_at);
        $this->assertSame(0, $a->progress);
    }

    /**
     * Der Advance-Zweig muss die Methode benutzen — sonst ist der Fix nur eine
     * ungenutzte Methode. Quelltext-Probe, weil checkAutoPilotCompletion() ohne
     * volle DB (Extra-Felder, Phasen, Gates) nicht ausfuehrbar ist.
     */
    public function testAdvanceZweigRuftDenResetAuf(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Models/RecApplicant.php');
        $start = strpos($src, 'if ($nextPhase && $phase?->auto_advance) {');
        $this->assertNotFalse($start, 'Advance-Zweig nicht gefunden — Test anpassen.');
        $block = substr($src, $start, 900);

        $this->assertStringContainsString('$this->resetAutoPilotCycle();', $block);
        $this->assertStringNotContainsString('$this->auto_pilot_reminder_count = 0;', $block, 'Die fuenf Einzelzeilen sind durch den Aufruf ersetzt.');
    }
}
