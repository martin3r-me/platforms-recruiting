<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill/Heilung für Bewerber, deren Posting umgeschlüsselt wurde, ohne dass
 * Phase + Verantwortlicher mitgezogen wurden (historischer Bruch vor dem
 * reconcilePositionState()-Fix).
 *
 * Ruft pro Bewerber RecApplicant::reconcilePositionState() — identische Logik
 * wie der Live-Fix:
 *  - **Einzel-Posting** mit Phase auf falscher Stelle → Phase + Feldwerte +
 *    Verantwortlicher werden sauber an die primäre Stelle gezogen.
 *  - **Mehrfach-Posting** → nur Verantwortlicher (Phase mehrdeutig, löst sich
 *    bei der Buchung); separat zur manuellen Sichtung gelistet.
 *
 * Idempotent. Erst mit --dry-run prüfen.
 *
 * Aufruf:
 *   php artisan recruiting:reconcile-applicant-positions --dry-run --team-id=3
 *   php artisan recruiting:reconcile-applicant-positions --team-id=3
 *
 * @see \Platform\Recruiting\Models\RecApplicant::reconcilePositionState()
 * @see self::reconcile() Reine Abgleichs-Logik ohne Konsolen-I/O, ohne
 *      Artisan-Lebenszyklus testbar (Probe-Muster, siehe
 *      tests/Integration/ReconcileApplicantPositionsGateTest.php).
 */
class ReconcileApplicantPositions extends Command
{
    protected $signature = 'recruiting:reconcile-applicant-positions
        {--team-id= : Optional auf ein Team beschränken}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--include-inactive : Auch inaktive Bewerber einbeziehen (Default: nur aktive)}
        {--limit=0 : Maximale Anzahl Bewerber pro Run (0 = alle)}';

    protected $description = 'Zieht Phase + Feldwerte + Verantwortlichen bei umgeschlüsselten Einzel-Posting-Bewerbern sauber nach; Mehrfach-Posting nur Owner + Liste.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');
        $limit = max(0, (int) $this->option('limit'));
        $includeInactive = (bool) $this->option('include-inactive');

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        // $emit gibt jede Zeile GENAU an der Stelle im Loop aus, an der sie vorher
        // inline ausgegeben wurde (Live-Fortschritt bei langen Laeufen bleibt
        // erhalten) — reconcile() selbst fasst keine Konsole an und ist darum ohne
        // Artisan-Lebenszyklus aufrufbar.
        $report = $this->reconcile($dryRun, $teamId, $limit, $includeInactive,
            function (string $type, string $text): void {
                $type === 'error' ? $this->error($text) : $this->line($text);
            });

        if (!empty($report['multiPosting'])) {
            $this->warn('');
            $this->warn('MEHRFACH-POSTING — Phase NICHT angefasst (manuell prüfen ob ein Standort falsch):');
            foreach ($report['multiPosting'] as $line) {
                $this->line($line);
            }
        }

        $this->info('');
        $this->info("Geprüft:                    {$report['checked']}");
        $this->info("Einzel-Posting geheilt:     {$report['changed']}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("  davon Phase+Felder:       {$report['phaseAligned']}");
        $this->info("  davon Owner gefüllt:      {$report['ownerFilled']}");
        $this->info('Mehrfach-Posting (manuell): ' . count($report['multiPosting']));
        $this->info("Wegen Festlegung übersprungen: {$report['festgelegtSkipped']}");
        if ($report['errors'] > 0) {
            $this->warn("Fehler:                     {$report['errors']}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Die eigentliche Abgleichs-Logik, herausgehoben aus handle() — OHNE jeden
     * Zugriff auf $this->option()/$this->line() & Co., damit sie ohne Artisan
     * (kein Input/Output, kein Service Container) direkt aufrufbar ist. $emit
     * ist optional: handle() reicht einen Callback durch, der wortgleich
     * dieselben Zeilen ausgibt wie vor dieser Extraktion; Tests lassen ihn weg
     * und werten stattdessen die Rueckgabe (Zaehler) sowie den DB-Zustand aus.
     *
     * Verhalten unveraendert — nur der Ort, an dem der Code steht, hat sich
     * geaendert (siehe Task-6-Fix-Bericht).
     *
     * @return array{
     *     checked:int, phaseAligned:int, ownerFilled:int, changed:int,
     *     errors:int, festgelegtSkipped:int, multiPosting:list<string>,
     * }
     */
    protected function reconcile(bool $dryRun, ?string $teamId, int $limit, bool $includeInactive, ?callable $emit = null): array
    {
        $emit ??= function (string $type, string $text): void {};

        $query = RecApplicant::query()
            ->whereHas('postings')
            ->with(['postings.position', 'phase', 'team']);

        if (!$includeInactive) {
            $query->where('is_active', true);
        }
        if ($teamId) {
            $query->where('team_id', (int) $teamId);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $phaseAligned = 0;
        $ownerFilled = 0;
        $changed = 0;
        $errors = 0;
        $festgelegtSkipped = 0;
        $multiPosting = [];

        foreach ($query->cursor() as $applicant) {
            $checked++;

            // Dieselbe Regel wie RecApplicant::reconcilePositionState(): die Stelle
            // folgt einer korrigierten Anzeige nur, solange die Person sich nicht
            // festgelegt hat (aktive Buchung oder Phase ≥ 3). Dieses Kommando ruft
            // reconcilePositionState() nicht selbst auf (siehe Klassendoc), darum
            // steht der Gate-Aufruf hier separat — und zwar VOR primaryPosition(),
            // sonst liest der Rest des Loop-Durchlaufs den alten Stand.
            $ausAnzeige = $applicant->postings
                ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
                ->first()
                ?->rec_position_id;

            if ($ausAnzeige !== null && (int) $ausAnzeige !== (int) $applicant->rec_position_id) {
                if ($applicant->istFestgelegt()) {
                    $festgelegtSkipped++;
                } elseif (!$dryRun) {
                    $applicant->rec_position_id = (int) $ausAnzeige;
                    $applicant->save();
                }
            }

            $primaryPosition = $applicant->primaryPosition();
            if (!$primaryPosition) {
                continue;
            }

            // Mehrfach-Posting: nicht automatisch (Phase mehrdeutig). Separat listen.
            if ($applicant->postings->count() > 1) {
                // Owner trotzdem auffüllen, falls leer — das ist eindeutig.
                $ownerWasEmpty = !$applicant->owned_by_user_id;
                if (!$dryRun) {
                    try { $applicant->reconcilePositionState(); } catch (\Throwable $e) { $errors++; }
                }
                if ($ownerWasEmpty) { $ownerFilled++; }

                $titles = $applicant->postings->map(fn ($p) => $p->position?->title ?? '?')->unique()->implode(', ');
                $multiPosting[] = sprintf(' #%-5d %-26s : %s%s', $applicant->id, mb_substr($this->displayName($applicant), 0, 26), $titles, $ownerWasEmpty ? '  [Owner gefüllt]' : '');
                continue;
            }

            // Einzel-Posting: was würde sich ändern?
            $phaseElsewhere = $applicant->phase === null
                || (int) $applicant->phase->rec_position_id !== (int) $primaryPosition->id;
            $ownerEmpty = !$applicant->owned_by_user_id;

            if (!$phaseElsewhere && !$ownerEmpty && !$applicant->is_unrouted) {
                continue; // bereits sauber
            }

            $parts = [];
            if ($phaseElsewhere) { $parts[] = "Phase → Stelle \"{$primaryPosition->title}\" (+ Feldwerte)"; $phaseAligned++; }
            if ($ownerEmpty)     { $parts[] = 'Owner gefüllt'; $ownerFilled++; }
            if ($applicant->is_unrouted) { $parts[] = 'is_unrouted→false'; }

            $emit('line', sprintf(
                ' #%-5d %-26s : %s',
                $applicant->id,
                mb_substr($this->displayName($applicant), 0, 26),
                implode(', ', $parts),
            ));

            if (!$dryRun) {
                try {
                    $applicant->reconcilePositionState();
                    $changed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $emit('error', " Fehler bei #{$applicant->id}: {$e->getMessage()}");
                }
            } else {
                $changed++;
            }
        }

        return compact('checked', 'phaseAligned', 'ownerFilled', 'changed', 'errors', 'festgelegtSkipped', 'multiPosting');
    }

    private function displayName(RecApplicant $applicant): string
    {
        $contact = $applicant->crmContactLinks?->first()?->contact;
        return $contact?->full_name ?? "(Bewerber #{$applicant->id})";
    }
}
