<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\TrainingReview\Show;

/**
 * Der Waechter der Teamleiter-Ansicht (Kundenwunsch 14.09.2026):
 * "Teamleiter sollen generell keinen Vertragsversand anstossen koennen."
 *
 * Die Absicherung ruht nicht auf einem @if, das jemand vergessen kann,
 * sondern darauf, dass es die Methoden schlicht NICHT GIBT: Livewire kann
 * nur aufrufen, was auf der Komponente steht. Dieser Test haelt genau das
 * fest — er faellt an dem Tag, an dem jemand der schlanken Ansicht eine
 * Versand- oder Lohnmethode anhaengt, ohne diese Entscheidung zu kennen.
 *
 * Er prueft NAMEN, nicht Verhalten. Das ist Absicht: eine Methode, die
 * "sendContracts" heisst, gehoert hier auch dann nicht hin, wenn sie im
 * Moment harmlos waere.
 */
class TrainingReviewHasNoDispatchTest extends TestCase
{
    /** Was diese Ansicht koennen darf — abschliessend. */
    private const ERLAUBT = [
        'mount', 'render',
        'setAttendance',
        'openEvaluationModal', 'closeEvaluationModal', 'saveEvaluation', 'evaluationWriteTarget',
        'openClarifyModal', 'closeClarifyModal', 'submitClarification',
        // Liest nur den Anzeigenamen aus den CRM-Kontaktverknuepfungen —
        // keine Mutation, kein Sprung in die Akte.
        'contactCandidatesFor',
    ];

    public function test_die_ansicht_hat_keine_versand_oder_lohnmethode(): void
    {
        foreach ($this->eigeneMethoden() as $name) {
            foreach (['send', 'dispatch', 'contract', 'portal', 'zuschlag', 'lohn', 'proposal', 'delete', 'destroy'] as $verboten) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $verboten,
                    $name,
                    "Die Teamleiter-Ansicht hat eine Methode '{$name}'. Sie darf nur Anwesenheit, "
                    . "Bewertung und Klaerung an HR koennen — siehe Spec "
                    . "docs/superpowers/specs/2026-09-14-teamleiter-bewertungsansicht-design.md",
                );
            }
        }
    }

    public function test_die_liste_der_koennen_ist_abschliessend(): void
    {
        // Faengt auch harmlos benannte Zugaenge ("freigeben", "uebernehmen"),
        // die der Namensfilter oben durchliesse.
        $unerwartet = array_diff($this->eigeneMethoden(), self::ERLAUBT);

        $this->assertSame([], array_values($unerwartet),
            'Neue oeffentliche Methode in der Teamleiter-Ansicht. Ist sie wirklich '
            . 'fuer ein Konto gedacht, das weder Loehne noch Vertraege sehen darf?');
    }

    /** @return list<string> oeffentliche Methoden ohne Livewire-Eigenes und Computeds */
    private function eigeneMethoden(): array
    {
        $reflection = new \ReflectionClass(Show::class);
        $namen = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Nur was in dieser Klasse oder ihrem Bewertungs-Trait steht —
            // Livewires eigene Component-Methoden interessieren hier nicht.
            $declaring = $method->getDeclaringClass()->getName();
            if ($declaring !== Show::class) {
                continue;
            }
            if ($method->getAttributes(\Livewire\Attributes\Computed::class) !== []) {
                continue;
            }
            $namen[] = $method->getName();
        }

        return $namen;
    }
}
