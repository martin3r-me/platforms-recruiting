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
     * E1 (Pruefung Runde 1) — DIE KARTE LUEGT NICHT MEHR.
     *
     * Seit der Schreibtisch auch Faelle ohne Bewerber zeigt, stuende ohne
     * diesen Zweig an jeder solchen Karte "— Gelöschter Bewerber —" — eine
     * sichtbare Luege ueber einen Menschen, der nie Bewerber war. Der Zweig
     * liess sich spurlos zuruecknehmen; keine Zusicherung beruehrte ihn.
     *
     * Quelltext-Zusicherung, wie testDerSchreibtischBenutztDieGemeinsameRegel:
     * eine Livewire-Komponente ist in diesem Modul nicht instanziierbar, und
     * die Quelle ist der ehrlichste verfuegbare Beleg.
     */
    public function testDieKarteNenntDenMitarbeiterStattEinesGeloeschtenBewerbers(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString(
            '@elseif($case->rec_employee_id)',
            $blade,
            'Die Karte hat keinen Zweig mehr fuer den Fall ohne Bewerber.',
        );
        $this->assertStringContainsString(
            'Mitarbeiter #{{ $case->rec_employee_id }}',
            $blade,
            'Die Karte nennt die Mitarbeiter-Kennung nicht.',
        );

        // Und die alte Beschriftung wird nur noch EINMAL ausgegeben, also
        // nur im letzten Zweig, wo wirklich beide Kennungen fehlen. Gezaehlt
        // wird die gerenderte Form mit den Gedankenstrichen, nicht das blosse
        // Wortpaar — das steht auch im Kommentar darueber.
        $this->assertSame(
            1,
            substr_count($blade, '— Gelöschter Bewerber —'),
            'Die alte Beschriftung wird mehrfach ausgegeben — einer der Zweige luegt.',
        );
    }

    /**
     * E2 — "ABLEHNEN" NUR, WO ES JEMANDEN ZUM ABLEHNEN GIBT.
     *
     * Der Knopf loest eine Ablehnung des BEWERBERS aus (rejected_at,
     * stillgelegt, AutoPilot aus). Bei einem Fall ohne Bewerber gibt es
     * niemanden, den das traefe; der Service faengt es ab (zweiter Riegel,
     * gemessen in HrDeskNoRoutingForEmployeesTest), aber ein Knopf, der
     * sichtbar nichts tut, gehoert nicht an die Karte.
     *
     * Geprueft wird die STRUKTUR, nicht ein Wortlaut: zwischen dem
     * umschliessenden @if($applicant) und dem Knopf darf kein @endif liegen.
     */
    public function testDerAblehnenKnopfStehtNurWoEsEinenBewerberGibt(): void
    {
        $blade = $this->blade();

        $knopf = strpos($blade, "openResolveModal({{ \$case->id }}, 'reject')");
        $this->assertNotFalse($knopf, 'Den Ablehnen-Knopf gibt es nicht mehr — dann gehoert dieser Test angepasst.');

        $davor = substr($blade, 0, $knopf);
        $letztesIf = strrpos($davor, '@if($applicant)');
        $this->assertNotFalse($letztesIf, 'Vor dem Ablehnen-Knopf steht kein @if($applicant).');

        $this->assertFalse(
            strpos($davor, '@endif', $letztesIf),
            'Zwischen dem @if($applicant) und dem Ablehnen-Knopf steht ein @endif — '
            .'der Knopf liegt also ausserhalb der Wache und erscheint auch an einem Fall ohne Bewerber.',
        );
    }

    private function blade(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2).'/resources/views/livewire/hr-desk/index.blade.php'
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
