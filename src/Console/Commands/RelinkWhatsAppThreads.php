<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\ApplicationMatchingService;
use Platform\Recruiting\Services\Comms\ApplicantThreadLinker;
use Platform\Recruiting\Services\Comms\ThreadContextGate;
use Platform\Recruiting\Services\Comms\ThreadRelinkPlanner;

/**
 * Heilung für WhatsApp-Threads, die am nackten CrmContact statt am Bewerber
 * hängen (Kontext-Gate-Bug: CRM-Feature "Contact-as-Context" ab 04/2026
 * kollidierte mit dem Recruiting-Intake-Gate — Threads wurden nie auf den
 * Bewerber umgehängt und waren in Kommunikations-Übersicht & Nachrichten-
 * Spalte unsichtbar; Antworten wurden vom Gate verworfen).
 *
 * Schutzgeländer:
 *  - NUR Threads auf Bewerbungs-Eingangs-Kanälen (isIntakeChannel) — Dispo-/
 *    Sales-Threads bekannter Kontakte werden nie angefasst.
 *  - Threads mit fremdem Pivot-Kontext (HCM-Onboarding, Helpdesk, ...) werden
 *    übersprungen, auch wenn die Legacy-Spalte crm_contact zeigt.
 *  - Zuordnung primär über den exakten Kontakt-Link (Thread-Kontakt ist per
 *    crm_contact_links mit einem Bewerber verlinkt); Telefon-Match (kanonische
 *    Digit-Form, nur aktive Nummern) nur als Fallback für Dubletten-Kontakte
 *    wie Fall #2474 (WhatsApp-Kontakt ≠ Bewerber-Kontakt).
 *  - Bei mehreren Kandidaten gewinnt der Senior (aktiv vor inaktiv, kleinste
 *    ID) — dieselbe Eigentümer-Konvention wie der DuplicateApplicantGuard.
 *
 * Threads ohne Bewerber-Match werden als "verlorene Bewerbung" gelistet
 * (Kandidaten für Nach-Intake), nichts geändert.
 *
 * Idempotent. Erst mit --dry-run prüfen.
 *
 * Aufruf:
 *   php artisan recruiting:relink-whatsapp-threads --team-id=3 --dry-run
 *   php artisan recruiting:relink-whatsapp-threads --team-id=3
 */
class RelinkWhatsAppThreads extends Command
{
    protected $signature = 'recruiting:relink-whatsapp-threads
        {--team-id= : Team-ID (required)}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--thread-id= : Einzelnen Thread bearbeiten}';

    protected $description = 'Hängt WhatsApp-Threads mit nacktem CrmContact-Kontext auf Intake-Kanälen an ihre Bewerber um (Kontext-Gate-Heilung).';

    public function __construct(private ApplicationMatchingService $matching)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $teamId = (int) $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');
        $threadId = $this->option('thread-id');

        if ($teamId <= 0) {
            $this->error('--team-id ist erforderlich.');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereIn('context_model', ['crm_contact', 'Platform\\Crm\\Models\\CrmContact'])
            ->when($threadId, fn ($q) => $q->where('id', (int) $threadId))
            ->with(['channel', 'contexts'])
            ->orderBy('id')
            ->get();

        if ($threads->isEmpty()) {
            $this->info('Keine fehlgelinkten Threads gefunden.');
            return Command::SUCCESS;
        }

        [$byContactId, $byPhone] = $this->buildApplicantIndexes($teamId);
        $this->info("Prüfe {$threads->count()} Threads gegen " . count($byContactId) . ' verlinkte Kontakte / ' . count($byPhone) . ' Bewerber-Nummern...');
        $this->newLine();

        $stats = ['relinked' => 0, 'ambiguous' => 0, 'lost' => 0, 'skipped_channel' => 0, 'skipped_foreign' => 0];
        $lost = [];
        $intakeCache = [];

        foreach ($threads as $thread) {
            // Schutz 1: nur Bewerbungs-Eingangs-Kanäle.
            $channel = $thread->channel;
            if (!$channel) {
                $stats['skipped_channel']++;
                continue;
            }
            $intakeCache[$channel->id] ??= $this->matching->isIntakeChannel($channel);
            if (!$intakeCache[$channel->id]) {
                $this->line("  <fg=yellow>Thread #{$thread->id}</>: Kanal '{$channel->name}' ist kein Bewerbungs-Eingang, übersprungen.");
                $stats['skipped_channel']++;
                continue;
            }

            // Schutz 2: fremde Pivot-Kontexte (HCM, Helpdesk, ...) → Finger weg.
            $contextModels = $thread->contexts->pluck('context_model')
                ->push($thread->context_model)
                ->filter()
                ->unique()
                ->all();
            if (ThreadContextGate::blocksIntakeAny($contextModels)) {
                $this->line("  <fg=yellow>Thread #{$thread->id}</>: fremder Pivot-Kontext (" . implode(', ', $contextModels) . '), übersprungen.');
                $stats['skipped_foreign']++;
                continue;
            }

            // Zuordnung: exakter Kontakt-Link zuerst, Telefon nur als Fallback.
            $candidates = $byContactId[(int) $thread->context_model_id] ?? [];
            $via = 'kontakt';
            if ($candidates === []) {
                $key = ThreadRelinkPlanner::normalizePhone($thread->remote_phone_number);
                $candidates = $key !== null ? ($byPhone[$key] ?? []) : [];
                $via = 'telefon';
            }

            $chosen = ThreadRelinkPlanner::chooseApplicant($candidates);

            if ($chosen === null) {
                $lost[] = [$thread->id, $thread->remote_phone_number, $thread->last_inbound_at?->format('d.m.Y H:i') ?? '—'];
                $stats['lost']++;
                continue;
            }

            $note = " <fg=gray>via {$via}</>";
            if (count($candidates) > 1) {
                $ids = implode(', ', array_column($candidates, 'id'));
                $note .= " <fg=cyan>(mehrere Kandidaten: {$ids} — Senior gewählt)</>";
                $stats['ambiguous']++;
            }

            $this->line("  <fg=green>Thread #{$thread->id}</> {$thread->remote_phone_number} → Bewerber #{$chosen['id']}{$note}");

            if (!$dryRun) {
                $this->relink($thread, $chosen['id']);
            }
            $stats['relinked']++;
        }

        if ($lost !== []) {
            $this->newLine();
            $this->warn('Verlorene Bewerbungen (kein Bewerber zum Thread — Kandidaten für Nach-Intake):');
            $this->table(['Thread', 'Nummer', 'Letzter Eingang'], $lost);
        }

        $this->newLine();
        $this->table(
            ['Aktion', 'Anzahl'],
            [
                ['Umgehängt', $stats['relinked']],
                ['davon mehrdeutig (Senior gewählt)', $stats['ambiguous']],
                ['Verloren (kein Bewerber-Match)', $stats['lost']],
                ['Übersprungen: kein Intake-Kanal', $stats['skipped_channel']],
                ['Übersprungen: fremder Pivot-Kontext', $stats['skipped_foreign']],
            ]
        );

        return Command::SUCCESS;
    }

    private function relink(CommsWhatsAppThread $thread, int $applicantId): void
    {
        ApplicantThreadLinker::link($thread, $applicantId, 'relink_context_gate');

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicantId,
                'type' => 'thread_relinked',
                'summary' => "WhatsApp-Thread #{$thread->id} vom CrmContact auf den Bewerber umgehängt (Kontext-Gate-Heilung).",
                'details' => [
                    'thread_id' => $thread->id,
                    'phone' => $thread->remote_phone_number,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->warn("    Audit-Log fehlgeschlagen: {$e->getMessage()}");
        }
    }

    /**
     * Zwei Indizes über die Bewerber des Teams:
     *  - contact_id  → Kandidaten (exakter crm_contact_links-Join)
     *  - kanonische Nummer → Kandidaten (nur AKTIVE Telefonnummern —
     *    deaktivierte/ersetzte Nummern dürfen ihren Alt-Bewerber nicht
     *    mehr in den Index tragen)
     *
     * @return array{0: array<int, array<array{id: int, is_active: bool}>>,
     *               1: array<string, array<array{id: int, is_active: bool}>>}
     */
    private function buildApplicantIndexes(int $teamId): array
    {
        $applicants = RecApplicant::query()
            ->where('team_id', $teamId)
            ->whereHas('crmContactLinks')
            ->with('crmContactLinks.contact.phoneNumbers')
            ->get(['id', 'is_active']);

        $byContactId = [];
        $byPhone = [];

        foreach ($applicants as $applicant) {
            $candidate = [
                'id' => (int) $applicant->id,
                'is_active' => (bool) $applicant->is_active,
            ];

            foreach ($applicant->crmContactLinks as $link) {
                $byContactId[(int) $link->contact_id][$applicant->id] = $candidate;

                foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                    if (!$phone->is_active) {
                        continue;
                    }
                    $key = ThreadRelinkPlanner::normalizePhone($phone->international ?: $phone->raw_input);
                    if ($key === null) {
                        continue;
                    }
                    $byPhone[$key][$applicant->id] = $candidate;
                }
            }
        }

        // Innere Keys (Dedup pro Bewerber) wieder zu Listen glätten.
        return [
            array_map('array_values', $byContactId),
            array_map('array_values', $byPhone),
        ];
    }
}
