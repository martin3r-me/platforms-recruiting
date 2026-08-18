<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Review-Befund (final-review.md, Punkt 4): der Fussnotentext von
 * fremdeFilialeTotals() blieb im Plural-Zweig ("N Unterschriften zaehlen ...")
 * bei "die Bewerbung kam" statt "die Bewerbungen kamen" — auf einer Seite,
 * deren Zweck Nachvollziehbarkeit ist, liest sich das wie ein Fehler in der
 * Zahl selbst.
 *
 * fremdeFilialeTotals() ist rein: sie liest ausser $this->viewModel()
 * (== new CohortViewModel(), pure) nichts an, darum reicht new Index() ohne
 * jeden Lifecycle/Container — dasselbe Argument wie bei CohortAssigner/
 * CohortViewModel (Modul-Konvention "pure Klassen bleiben ohne DB testbar").
 */
class FremdeFilialeReasonTextTest extends TestCase
{
    public function test_singular_bleibt_singular(): void
    {
        $component = new Index();

        $groups = [
            ['posting_id' => 1, 'columns' => ['unterschrieben' => [101]]],
        ];
        $cohort = [
            'applicant_position_ids' => [101 => 5],
            'posting_position_ids' => [1 => 9],
        ];

        $result = $component->fremdeFilialeTotals($groups, $cohort);

        $this->assertSame(1, $result['count']);
        $this->assertStringContainsString('1 Unterschrift zählt', $result['reason']);
        $this->assertStringContainsString('über die die Bewerbung kam', $result['reason']);
        $this->assertStringContainsString('eingestellt wurde die Person', $result['reason']);
    }

    public function test_plural_zieht_den_satz_konsequent_durch(): void
    {
        $component = new Index();

        // Zwei Unterschriften bei DERSELBEN Anzeige (posting_id 1), beide mit
        // einer anderen Stelle als die der Anzeige — der Plural-Zweig.
        $groups = [
            ['posting_id' => 1, 'columns' => ['unterschrieben' => [101, 102]]],
        ];
        $cohort = [
            'applicant_position_ids' => [101 => 5, 102 => 5],
            'posting_position_ids' => [1 => 9],
        ];

        $result = $component->fremdeFilialeTotals($groups, $cohort);

        $this->assertSame(2, $result['count']);
        $this->assertStringContainsString('2 Unterschriften zählen', $result['reason']);
        $this->assertStringContainsString('eingestellt wurden die Personen', $result['reason']);

        // Der eigentliche Befund: auch die Herkunfts-Halbsatz muss im
        // Plural-Zweig auf Plural durchgezogen werden.
        $this->assertStringContainsString('über die die Bewerbungen kamen', $result['reason'],
            'Plural-Zweig darf nicht bei "die Bewerbung kam" (Singular) stehen bleiben');
        $this->assertStringNotContainsString('über die die Bewerbung kam', $result['reason']);
    }
}
