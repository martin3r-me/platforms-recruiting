<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Platzierungs-Vertrag, KEIN Verhaltenstest.
 *
 * Die Regel selbst liegt in HrDeskCaseVisibility und ist dort gegen eine
 * echte DB getestet (HrDeskCaseVisibilityTest). Was hier abgesichert wird,
 * ist, dass die beiden Ansichten sie auch BENUTZEN statt wieder eine eigene
 * Kopie mitzuschleppen — genau daran ist es am 14.09.2026 gescheitert: drei
 * wortgleiche Filter an drei Stellen, zwei davon mit einem Ausschluss zu
 * viel.
 *
 * Warum kein Lauf gegen die echten Komponenten: HrDesk\Index::cases() und
 * Dashboard::applicantsQuery() lesen Auth::user()->currentTeam und ziehen den
 * halben Livewire-Stack nach. Diese Suite baut Container und Capsule von Hand
 * (kein Laravel-Bootstrap) — der Lauf staerbe vor der Query. Solange das so
 * ist, ist die Quelle der ehrlichste verfuegbare Beleg; er faellt auf, wenn
 * jemand die Regel wieder lokal nachbaut.
 */
final class HrDeskVisibilityWiringTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/src/' . $relativePath);
    }

    public function testDerSchreibtischBenutztDieGemeinsameRegel(): void
    {
        $src = $this->source('Livewire/HrDesk/Index.php');

        $this->assertStringContainsString(
            'HrDeskCaseVisibility::openCases(',
            $src,
            'Die Fall-Liste baut ihre Sichtbarkeitsregel wieder selbst.',
        );
        // Seit 01.10.2026 rechnet die Regelklasse die Zaehler selbst
        // (HrDeskCaseVisibility::reasonCounts) — die Komponente ruft sie nur
        // noch auf. Grund: die Zaehler muessen seitdem BEIDE Haelften
        // zusammenziehen (Bewerber-Menschen und Mitarbeiter-Faelle), und
        // diese Rechnung stuende in der Komponente ungemessen, weil sie im
        // Modul nicht instanziierbar ist.
        $this->assertStringContainsString(
            'HrDeskCaseVisibility::reasonCounts(',
            $src,
            'Die Zaehler je Grund bauen ihre Sichtbarkeitsregel wieder selbst.',
        );

        // Und die Komponente rechnet NICHT mehr selbst mit: ein
        // zurueckgekehrtes whereHas auf hrDeskCases waere die alte Kopie.
        $this->assertStringNotContainsString(
            "whereHas('hrDeskCases'",
            $src,
            'Die Zaehler je Grund rechnen wieder in der Komponente.',
        );
    }

    public function testDerSchreibtischBlendetGeparkteUndStillgelegteNichtMehrAus(): void
    {
        $src = $this->source('Livewire/HrDesk/Index.php');

        $this->assertStringNotContainsString(
            "->where('is_parked', false)",
            $src,
            'Geparkte Bewerber werden wieder aus dem Schreibtisch gefiltert (Fall 1942).',
        );
        $this->assertStringNotContainsString(
            "->where('is_on_hr_desk', true)",
            $src,
            'Die Schreibtisch-Bedingung gehoert in HrDeskCaseVisibility, nicht in die Komponente.',
        );
    }

    /**
     * Die Dashboard-Kachel ist die zweite Tuer zum selben Schreibtisch. Liess
     * man dort den Park-Ausschluss stehen, waere eine geparkte Person auf der
     * einen Seite sichtbar und auf der anderen nicht — und genau dieses
     * Auseinanderlaufen war der Fehler.
     */
    public function testDieDashboardKachelSchliesstGeparkteNichtMehrAus(): void
    {
        $src = $this->source('Livewire/Dashboard/Dashboard.php');

        $this->assertStringNotContainsString(
            "->where('is_on_hr_desk', true)->where('is_parked', false)",
            $src,
            'Die Schreibtisch-Kachel des Dashboards blendet geparkte Faelle wieder aus.',
        );
    }
}
