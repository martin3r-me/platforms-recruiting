<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Platzierungs-Vertrag (Muster GeplanteVertragsdatenWiringTest): Die
 * Schulungsnachbereitung zeigt „Ich bin“ unter dem Namen. Das Verhalten steht
 * in IchBinLabelsTest; die Livewire-Komponente bootet in dieser Suite nicht.
 */
final class IchBinNachbereitungWiringTest extends TestCase
{
    public function test_component_loads_labels_for_all_bookings_in_one_go(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/InterviewBookings/Index.php');

        $this->assertMatchesRegularExpression('/#\[Computed\]\s+public function ichBinLabels\(\)/', $src);
        $this->assertStringContainsString('IchBinLabels::forApplicants(', $src);
    }

    public function test_view_shows_it_in_the_post_training_table_under_the_name(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/interview-bookings/index.blade.php');

        $tabelle = strpos($view, '<th class="px-4 py-3">Vertragsvorlage</th>');
        $zeile = strpos($view, '$this->ichBinLabels[$applicant->id]');
        $this->assertNotFalse($tabelle);
        $this->assertNotFalse($zeile);
        $this->assertGreaterThan($tabelle, $zeile, 'Gehoert in die Nachbereitungs-Tabelle, nicht in die Uebersicht.');
        $this->assertStringContainsString('Ich bin: keine Angabe', $view);
    }
}
