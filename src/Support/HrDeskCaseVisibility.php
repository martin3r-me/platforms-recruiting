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
 * SEIT 01.10.2026 ZEIGT ER ZUSAETZLICH die Faelle, die an einem MITARBEITER
 * und an keinem Bewerber haengen (Einsatz-Trigger, REASON_WORK_PERMIT). Der
 * Zweig ist zum Bewerber-Zweig disjunkt und aendert an dessen Regeln nichts
 * — siehe employeeCases().
 *
 * Was am BEWERBER-Zweig weiter ausschliesst:
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

    /**
     * Faelle, die an einem MITARBEITER haengen und an keinem Bewerber.
     *
     * DIE ENTSCHEIDUNG (Aufgabe 10, 01.10.2026) — und sie ist bewusst ein
     * ZUSATZ und keine Aufweichung: der Einsatz-Trigger oeffnet bei fehlender
     * Arbeitserlaubnis einen Fall an einem Menschen, der nie Bewerber war
     * (der Grossteil des Bestands kam ueber ZAS). Ein solcher Fall fiel
     * bisher aus BEIDEN Abfragen heraus — `whereHas('applicant', ...)` hat
     * keinen Bewerber zum Anhaengen — und war damit doppelt unsichtbar. Eine
     * harte Sperre, die niemand sieht, ist so folgenlos wie keine.
     *
     * WARUM NICHT EINFACH DIE BEIDEN FILTER FALLEN LASSEN. Der zweite
     * Ausschluss (`whereDoesntHave('employee')` in constrainApplicant) ist
     * kein vergessener Filter, sondern Absicht: ein BEWERBER, der schon
     * Mitarbeiter ist, hat den Funnel verlassen und gehoert nicht in die
     * Bewerber-Triage (Faelle #19 und #34). Diese Absicht bleibt
     * unangetastet. Der Zweig hier ist zum Bewerber-Zweig DISJUNKT —
     * `rec_applicant_id IS NULL` schliesst jeden Bewerber-Fall aus —, und
     * deshalb kann er an der Sichtbarkeit von Bewerber-Faellen nichts
     * aendern, in keine Richtung. Gedeckt von
     * HrDeskCaseVisibilityTest::testBewerberFaelleAendernSichDurchDenMitarbeiterZweigNicht.
     *
     * Was der Schreibtisch damit wird: die Liste der offenen HR-Faelle
     * dieses Teams — die des Bewerberprozesses nach den Regeln des
     * Bewerberprozesses, die des Bestands nach ihren eigenen.
     */
    public static function employeeCases(int $teamId): Builder
    {
        return RecHrDeskCase::query()
            ->forTeam($teamId)
            ->open()
            ->whereNull('rec_applicant_id')
            ->whereNotNull('rec_employee_id');
    }

    /** Offene Faelle eines Teams, neueste zuerst. */
    public static function openCases(int $teamId): Builder
    {
        return RecHrDeskCase::query()
            ->forTeam($teamId)
            ->open()
            ->where(function (Builder $q) {
                $q->whereHas('applicant', fn (Builder $a) => self::constrainApplicant($a))
                    ->orWhere(fn (Builder $m) => self::constrainEmployeeCase($m));
            })
            ->orderBy('opened_at', 'desc');
    }

    /** Bewerber-Query fuer die Zaehler je Grund. */
    public static function applicants(int $teamId): Builder
    {
        return self::constrainApplicant(RecApplicant::forTeam($teamId));
    }

    /**
     * Die Zaehler je Grund — Bewerber UND Mitarbeiter-Faelle.
     *
     * HIER STATT IN DER KOMPONENTE, weil eine Livewire-Komponente in diesem
     * Modul nicht instanziierbar ist (sie liest Auth::user()->currentTeam
     * und zieht den halben Stack nach): stuende die Rechnung dort, waere sie
     * ungemessen.
     *
     * DIE BEIDEN HAELFTEN ZAEHLEN UNTERSCHIEDLICHE DINGE, und das ist kein
     * Versehen: links MENSCHEN (ein Bewerber mit zwei offenen Faellen zaehlt
     * einmal), rechts FAELLE. Die linke Haelfte ist unveraendert — wer sie
     * auf Faelle umstellt, aendert die Zahlen, die HR seit Monaten sieht.
     *
     * @return array<string, int>
     */
    public static function reasonCounts(int $teamId): array
    {
        $bewerber    = self::applicants($teamId);
        $mitarbeiter = self::employeeCases($teamId);

        $counts = ['all' => (clone $bewerber)->count() + (clone $mitarbeiter)->count()];

        foreach (array_keys(RecHrDeskCase::REASON_LABELS) as $reason) {
            $counts[$reason] = (clone $bewerber)
                    ->whereHas('hrDeskCases', fn (Builder $q) => $q->where('reason', $reason)->open())
                    ->count()
                + (clone $mitarbeiter)->where('reason', $reason)->count();
        }

        return $counts;
    }

    /**
     * Die Bedingung des Mitarbeiter-Zweigs, als eigene Methode, damit sie in
     * openCases() und employeeCases() nicht zweimal dasteht — dieselbe Sorge,
     * die constrainApplicant() hat.
     */
    private static function constrainEmployeeCase(Builder $query): Builder
    {
        return $query
            ->whereNull('rec_applicant_id')
            ->whereNotNull('rec_employee_id');
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
