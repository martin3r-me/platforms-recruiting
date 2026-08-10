<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Comms\ThreadContextGate;
use Platform\Recruiting\Services\Comms\ThreadRelinkPlanner;

/**
 * Heilung für WhatsApp-Threads, die am nackten CrmContact statt am Bewerber
 * hängen (Kontext-Gate-Bug: CRM-Feature "Contact-as-Context" ab 04/2026
 * kollidierte mit dem Recruiting-Intake-Gate — Threads wurden nie auf den
 * Bewerber umgehängt und waren in Kommunikations-Übersicht & Nachrichten-
 * Spalte unsichtbar; Antworten wurden vom Gate verworfen).
 *
 * Ordnet Threads per Telefonnummer (letzte 10 Ziffern, formattolerant) ihrem
 * Bewerber zu: addContext() + Beförderung der Legacy-Spalten. Threads ohne
 * Bewerber-Match werden als "verlorene Bewerbung" gelistet (Kandidat für
 * Nach-Intake), nichts geändert.
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

    protected $description = 'Hängt WhatsApp-Threads mit nacktem CrmContact-Kontext per Telefonnummer an ihre Bewerber um (Kontext-Gate-Heilung).';

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
            ->orderBy('id')
            ->get();

        if ($threads->isEmpty()) {
            $this->info('Keine fehlgelinkten Threads gefunden.');
            return Command::SUCCESS;
        }

        $phoneIndex = $this->buildApplicantPhoneIndex($teamId);
        $this->info("Prüfe {$threads->count()} Threads gegen {$this->indexSize($phoneIndex)} Bewerber-Nummern...");
        $this->newLine();

        $stats = ['relinked' => 0, 'ambiguous' => 0, 'lost' => 0, 'no_phone' => 0];
        $lost = [];

        foreach ($threads as $thread) {
            $key = ThreadRelinkPlanner::normalizePhone($thread->remote_phone_number);
            if ($key === null) {
                $this->line("  <fg=yellow>Thread #{$thread->id}</>: keine verwertbare Nummer ({$thread->remote_phone_number}), übersprungen.");
                $stats['no_phone']++;
                continue;
            }

            $candidates = $phoneIndex[$key] ?? [];
            $chosen = ThreadRelinkPlanner::chooseApplicant($candidates);

            if ($chosen === null) {
                $lost[] = [$thread->id, $thread->remote_phone_number, $thread->last_inbound_at?->format('d.m.Y H:i') ?? '—'];
                $stats['lost']++;
                continue;
            }

            $note = '';
            if (count($candidates) > 1) {
                $ids = implode(', ', array_column($candidates, 'id'));
                $note = " <fg=cyan>(mehrere Kandidaten: {$ids} — aktivster/neuester gewählt)</>";
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
            $this->warn('Verlorene Bewerbungen (kein Bewerber zur Nummer — Kandidaten für Nach-Intake):');
            $this->table(['Thread', 'Nummer', 'Letzter Eingang'], $lost);
        }

        $this->newLine();
        $this->table(
            ['Aktion', 'Anzahl'],
            [
                ['Umgehängt', $stats['relinked']],
                ['davon mehrdeutig (neuester gewählt)', $stats['ambiguous']],
                ['Verloren (kein Bewerber-Match)', $stats['lost']],
                ['Keine verwertbare Nummer', $stats['no_phone']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Thread → Bewerber: Pivot-Kontext ergänzen UND Legacy-Spalten befördern.
     * addContext() allein reicht nicht — Legacy-Spalten folgen "first context
     * wins" und genau sie werden von Kommunikations-Übersicht & Nachrichten-
     * Spalte gelesen.
     */
    private function relink(CommsWhatsAppThread $thread, int $applicantId): void
    {
        $morph = (new RecApplicant)->getMorphClass();

        $thread->addContext($morph, $applicantId, 'relink_context_gate');

        if (ThreadContextGate::isBareContactContext($thread->context_model)) {
            $thread->updateQuietly([
                'context_model' => $morph,
                'context_model_id' => $applicantId,
            ]);
        }

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
     * Index normalisierte Nummer → Bewerber-Kandidaten für das Team.
     * Eine Nummer kann auf mehrere Bewerber zeigen (Mehrfach-Bewerbung) —
     * die Auswahl trifft ThreadRelinkPlanner::chooseApplicant().
     *
     * @return array<string, array<array{id: int, is_active: bool}>>
     */
    private function buildApplicantPhoneIndex(int $teamId): array
    {
        $applicants = RecApplicant::query()
            ->where('team_id', $teamId)
            ->whereHas('crmContactLinks.contact.phoneNumbers')
            ->with('crmContactLinks.contact.phoneNumbers')
            ->get(['id', 'is_active', 'team_id']);

        $index = [];
        foreach ($applicants as $applicant) {
            foreach ($applicant->crmContactLinks as $link) {
                foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                    $key = ThreadRelinkPlanner::normalizePhone($phone->international ?: $phone->raw_input);
                    if ($key === null) {
                        continue;
                    }
                    $index[$key][$applicant->id] = [
                        'id' => (int) $applicant->id,
                        'is_active' => (bool) $applicant->is_active,
                    ];
                }
            }
        }

        // Innere Keys (Dedup pro Bewerber) wieder zu Listen glätten.
        return array_map('array_values', $index);
    }

    private function indexSize(array $index): int
    {
        return count($index);
    }
}
