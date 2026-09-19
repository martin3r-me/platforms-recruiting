<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * DIE Regel, welche offenen HR-Faelle auf dem Schreibtisch erscheinen — eine
 * Definition statt bisher drei wortgleicher Kopien (HrDesk\Index::cases(),
 * HrDesk\Index::reasonCounts(), Dashboard::applicantsQuery()).
 *
 * Der Schreibtisch ist das Sicherheitsnetz des Funnels: liegt dort ein
 * offener Fall, ist er die einzige Stelle, an der jemand ihn wieder
 * freigeben kann. Deshalb darf ein Sonderzustand des Bewerbers den Fall
 * NICHT ausblenden, sondern muss ihn beschriften (stateLabels).
 *
 * Anlass (14.09.2026): Zwei Teilnehmer derselben Schulung lagen mit offenem
 * Fall „Klaerung aus der Schulung" auf dem Schreibtisch und waren dort
 * unsichtbar — einer wegen is_active=false, einer wegen is_parked=true.
 * Beide Flags standen als Ausschluss in der whereHas. Die geparkte Person
 * fiel zusaetzlich durch die Parkplatz-Liste, weil die ihrerseits alle mit
 * is_on_hr_desk=true ausschliesst: in beiden Zustaenden gleichzeitig war sie
 * in KEINER Liste des Systems sichtbar.
 *
 * Was weiter ausschliesst:
 *  - rejected_at: abgelehnt ist erledigt, da gibt es nichts freizugeben.
 *  - is_unrouted: ohne Stelle ist der Bewerber nicht im Funnel.
 *  - ein vorhandener RecEmployee: wer eingestellt ist, hat den Funnel
 *    verlassen und gehoert nicht in die Bewerber-Triage. Der Bewerber-
 *    Datensatz lebt nach der MA-Anlage weiter (is_active=false nimmt ihn
 *    nur aus dem Dashboard), also koennen Regeln noch Monate spaeter Faelle
 *    an ihm eroeffnen — Fall #19 zwei Tage, Fall #34 sechs Wochen nach der
 *    Einstellung. HrDeskRoutingService verhindert das inzwischen an der
 *    Quelle; dieser Schnitt faengt die Altfaelle und alles, was kuenftig
 *    an dem Guard vorbeikommt.
 *  - is_on_hr_desk=false: das Flag IST die Mitgliedschaft am Schreibtisch.
 *    Ein offener Fall ohne Flag ist ein inkonsistenter Zustand — alle
 *    Schliess-Pfade raeumen beides zusammen ab. Einzige bekannte Quelle ist
 *    MigrateNonEuCases, das das Flag ohne Fall-Abschluss loescht. Wer das
 *    Flag hier rauswirft, holt damit Altfaelle an die Oberflaeche; das ist
 *    eine eigene Entscheidung und braucht vorher einen Blick in die Daten.
 */
final class HrDeskCaseVisibility
{
    /**
     * Bedingungen am BEWERBER, unter denen sein offener Fall sichtbar ist.
     * Als eigene Methode, weil sie zweimal gebraucht wird: einmal als
     * whereHas am Fall, einmal direkt auf der Bewerber-Query der Zaehler.
     */
    public static function constrainApplicant(Builder $query): Builder
    {
        return $query
            ->where('is_on_hr_desk', true)
            ->whereNull('rejected_at')
            ->where('is_unrouted', false)
            ->whereDoesntHave('employee');
    }

    /** Offene Faelle eines Teams, neueste zuerst. */
    public static function openCases(int $teamId): Builder
    {
        return RecHrDeskCase::query()
            ->forTeam($teamId)
            ->open()
            ->whereHas('applicant', fn (Builder $q) => self::constrainApplicant($q))
            ->orderBy('opened_at', 'desc');
    }

    /** Bewerber-Query fuer die Zaehler je Grund. */
    public static function applicants(int $teamId): Builder
    {
        return self::constrainApplicant(RecApplicant::forTeam($teamId));
    }

    /**
     * Sonderzustaende, die frueher zum Ausblenden gefuehrt haben und jetzt
     * als Badge am Fall stehen. Leeres Array = Normalfall, kein Badge.
     */
    public static function stateLabels(RecApplicant $applicant): array
    {
        $labels = [];

        if (!$applicant->is_active) {
            $labels[] = 'stillgelegt';
        }

        if ($applicant->is_parked) {
            $labels[] = 'geparkt';
        }

        return $labels;
    }
}
