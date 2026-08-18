<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Fuellt rec_applicants.rec_position_id fuer den BESTAND — alle Schreib- und
 * Lesewege sind bereits umgestellt (primaryPosition() und Konsorten, siehe
 * RecApplicant::fruehesteAnzeige()), diesem Kommando faellt nur noch zu, das
 * Feld einmalig nachzuziehen.
 *
 * Hintergrund: bis zu diesem Umbau war die Stelle einer Bewerbung
 * DEFINITIONSGEMAESS die der fruehesten verknuepften Anzeige — exakt das, was
 * RecApplicant::fruehesteAnzeige() berechnet (vormals an vier Stellen
 * wortgleich dupliziert: primaryPosition(), stelleAusAnzeigeUebernehmen(),
 * reconcilePositionState(), ReconcileApplicantPositions::reconcile() — jetzt
 * eine gemeinsame Methode, siehe deren Klassendoc). Der Backfill schreibt
 * also keine Schaetzung, sondern macht explizit, was vorher implizit galt.
 *
 * Verhalten:
 *  - Fuellt NUR leere rec_position_id (whereNull) — ein gepflegter Wert wird
 *    NIE ueberschrieben. Idempotent, gefahrlos mehrfach lauffaehig.
 *  - Altfaelle: ~15 Bewerbungen haben VOR diesem Umbau bereits die Stelle
 *    gewechselt; ihre Pivot-Verknuepfung ist keine echte Bewerbung auf diese
 *    Anzeige — die urspruengliche Anzeige wurde geloescht und ist nicht
 *    rekonstruierbar. Erkennbar am Transition-Log (trigger =
 *    PhaseTransitionTrigger::POSITION_SWITCH); die vorhandene Verknuepfung
 *    wird im Pivot markiert (matched_via = 'position_switch'), NUR wenn
 *    matched_via noch leer ist — eine echte Match-Information aus dem
 *    Inbound-Matching darf nicht ueberschrieben werden. Der Marker ist ab
 *    jetzt rein HISTORISCH: switchToPosition() fasst den Pivot seit diesem
 *    Umbau nicht mehr an, der laufende Betrieb erzeugt keine neuen Faelle
 *    mehr. Ein spaeterer Task laesst die Statistik ihn lesen.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-applicant-position --dry-run
 *   php artisan recruiting:backfill-applicant-position
 *   php artisan recruiting:backfill-applicant-position --team-id=8
 *
 * @see \Platform\Recruiting\Models\RecApplicant::fruehesteAnzeige()
 * @see self::backfill() reine Abgleichs-Logik ohne Konsolen-I/O, ohne
 *      Artisan-Lebenszyklus testbar (Probe-Muster, siehe
 *      tests/Integration/BackfillApplicantPositionTest.php).
 */
class BackfillApplicantPosition extends Command
{
    protected $signature = 'recruiting:backfill-applicant-position
        {--dry-run : Nur anzeigen, was gesetzt wuerde}
        {--team-id= : Auf ein Team beschraenken}';

    protected $description = 'Fuellt rec_applicants.rec_position_id aus der fruehesten verknuepften Anzeige (nie ueberschreibend)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Aenderungen werden vorgenommen.');
        }

        $report = $this->backfill($dryRun, $teamId);

        $this->newLine();
        $this->components->bulletList([
            ($dryRun ? 'Wuerden gesetzt: ' : 'Gesetzt: ') . $report['gesetzt'],
            "Ohne Anzeige (blieb leer): {$report['ohneAnzeige']}",
            'Altfaelle markiert (Stellenwechsel): ' . $report['markiert']
                . ($dryRun ? ' (dry-run: nicht geschrieben)' : ''),
        ]);

        return self::SUCCESS;
    }

    /**
     * Reine Abgleichs-Logik, herausgehoben aus handle() — OHNE jeden Zugriff
     * auf $this->option()/$this->line() & Co, damit sie ohne Artisan (kein
     * Input/Output, kein Service Container) direkt aufrufbar ist. Muster:
     * ReconcileApplicantPositions::reconcile().
     *
     * @return array{gesetzt:int, ohneAnzeige:int, markiert:int}
     */
    protected function backfill(bool $dryRun, ?string $teamId): array
    {
        $gesetzt = 0;
        $ohneAnzeige = 0;

        RecApplicant::query()
            ->whereNull('rec_position_id')
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->with('postings')
            ->chunkById(500, function ($applicants) use ($dryRun, &$gesetzt, &$ohneAnzeige) {
                foreach ($applicants as $applicant) {
                    // Der Backfill ist EXAKT: bis zu diesem Umbau war die Stelle
                    // definitionsgemaess die der fruehesten Anzeige — dieselbe
                    // Berechnung wie primaryPosition() (siehe fruehesteAnzeige()).
                    $positionId = $applicant->fruehesteAnzeige()?->rec_position_id;

                    if ($positionId === null) {
                        $ohneAnzeige++; // bleibt leer, kein Raten
                        continue;
                    }

                    if (! $dryRun) {
                        $applicant->rec_position_id = (int) $positionId;
                        $applicant->save();
                    }
                    $gesetzt++;
                }
            });

        // Altfaelle: vor dem Umbau bereits gewechselte Bewerbungen, deren Pivot
        // keine echte Bewerbung auf diese Anzeige ist (siehe Klassendoc).
        $altfaelle = DB::table('rec_phase_transitions')
            ->where('trigger', PhaseTransitionTrigger::POSITION_SWITCH)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->distinct()
            ->pluck('rec_applicant_id');

        if (! $dryRun && $altfaelle->isNotEmpty()) {
            // whereNull('matched_via') ist Pflicht: eine echte Match-Information
            // aus dem Inbound-Matching darf nicht ueberschrieben werden.
            DB::table('rec_applicant_posting')
                ->whereIn('rec_applicant_id', $altfaelle)
                ->whereNull('matched_via')
                ->update(['matched_via' => PhaseTransitionTrigger::POSITION_SWITCH]);
        }

        return [
            'gesetzt' => $gesetzt,
            'ohneAnzeige' => $ohneAnzeige,
            'markiert' => $altfaelle->count(),
        ];
    }
}
