<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\CoreAiProvider;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Core\Services\AiToolLoopRunner;
use Platform\Crm\Models\CommsEmailThread;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;

class EnrichInboxApplicants extends Command
{
    protected $signature = 'recruiting:enrich-inbox-applicants
        {--limit=10 : Maximale Anzahl Bewerbungen pro Run}
        {--applicant-id= : Optional: einzelne Bewerbung bearbeiten}
        {--dry-run : Zeigt nur, was bearbeitet würde}
        {--max-iterations=20 : Max. Tool-Loop Iterationen pro Bewerbung}
        {--max-output-tokens=2000 : Max. Output Tokens pro LLM Call}';

    protected $description = 'Enrichment-Pipeline: Extrahiert Daten aus WhatsApp/Email-Threads und Anhängen per LLM in Extra-Felder und CRM-Kontakt.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(100, (int) $this->option('limit')));
        $applicantId = $this->option('applicant-id');
        $applicantId = is_numeric($applicantId) ? (int) $applicantId : null;
        $maxIterations = max(1, min(200, (int) $this->option('max-iterations')));
        $maxOutputTokens = max(64, min(200000, (int) $this->option('max-output-tokens')));

        $lockKey = $applicantId
            ? "recruiting:enrich-inbox-applicant:{$applicantId}"
            : 'recruiting:enrich-inbox-applicants';
        $lock = Cache::lock($lockKey, 3600);
        if (! $lock->get()) {
            $this->warn('Läuft bereits (Lock aktiv).');
            return Command::SUCCESS;
        }

        try {
            if ($dryRun) {
                $this->warn('DRY-RUN — es werden keine Daten geändert.');
            }

            $runner = AiToolLoopRunner::make();
            $processed = 0;
            $seenIds = [];
            $originalAuthUser = Auth::user();

            while ($processed < $limit) {
                $applicant = $this->nextApplicant($applicantId, $seenIds);
                if (! $applicant) {
                    if ($processed === 0) {
                        $this->info('Keine offenen Inbox-Bewerbungen gefunden.');
                    }
                    break;
                }

                $seenIds[] = (int) $applicant->id;
                $processed++;

                $admin = $this->findTeamAdmin($applicant->team);
                if (! $admin) {
                    $this->line("Bewerbung #{$applicant->id}: übersprungen (kein Team-Admin).");
                    continue;
                }

                $model = $this->determineModel();
                $contactInfo = $this->loadContactInfo($applicant);
                $extraFields = $this->loadExtraFields($applicant);
                $whatsappThreads = $this->loadWhatsAppThreads($applicant);
                $emailThreads = $this->loadEmailThreads($applicant);
                $fileReferences = $this->loadFileReferences($applicant, $whatsappThreads, $emailThreads);

                $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->info("Bewerbung #{$applicant->id} → Admin: {$admin->name}");
                $this->line("Team: " . ($applicant->team?->name ?? '—'));
                $this->line("Model: {$model}");
                $this->line("Kontakte: " . count($contactInfo));
                $this->line("Extra-Fields: " . count($extraFields));
                $this->line("WhatsApp-Threads: " . count($whatsappThreads));
                $this->line("Email-Threads: " . count($emailThreads));
                $this->line("Datei-Referenzen: " . count($fileReferences));

                if ($dryRun) {
                    continue;
                }

                // Mark as processing in cache (not in DB — stays in inbox until done)
                Cache::put("enrichment:processing:{$applicant->id}", true, 600);

                $this->impersonateForTask($admin, $applicant->team);
                $toolContext = new ToolContext($admin, $applicant->team, [
                    'context_model' => get_class($applicant),
                    'context_model_id' => $applicant->id,
                ]);

                $preloadTools = [
                    'core.extra_fields.GET', 'core.extra_fields.PUT',
                    'core.context.files.GET', 'core.context.files.content.GET',
                    'crm.contacts.GET', 'crm.contacts.PUT',
                    'crm.lookups.GET', 'crm.lookup.GET',
                    'crm.postal_addresses.POST',
                    'recruiting.applicants.PUT',
                    'recruiting.applicant_contacts.POST',
                ];

                $messages = $this->buildMessages(
                    $applicant, $contactInfo, $extraFields,
                    $whatsappThreads, $emailThreads, $fileReferences
                );

                $this->logEnrichment($applicant, 'run_started', 'Enrichment-Run gestartet', [
                    'preload_tools' => $preloadTools,
                ]);

                try {
                    $result = $runner->run($messages, $model, $toolContext, [
                        'max_iterations' => $maxIterations,
                        'max_output_tokens' => $maxOutputTokens,
                        'include_web_search' => false,
                        'reasoning' => ['effort' => 'medium'],
                        'preload_tools' => $preloadTools,
                        'on_iteration' => function (int $iter, array $toolNames, int $textLen) {
                            $this->line("  Iter {$iter}: " . (empty($toolNames) ? '(keine Tools)' : implode(', ', $toolNames)));
                        },
                    ]);

                    $iterations = (int) ($result['iterations'] ?? 0);
                    $allToolCallNames = $result['all_tool_call_names'] ?? [];

                    $this->logEnrichment($applicant, 'run_completed', "Enrichment abgeschlossen: {$iterations} Iterationen", [
                        'iterations' => $iterations,
                        'all_tool_calls' => $allToolCallNames,
                    ]);

                    $this->line("  Iterationen: {$iterations} | Tools: " . (empty($allToolCallNames) ? '(keine)' : implode(', ', $allToolCallNames)));

                    $applicant->update(['enrichment_status' => 'enriched']);
                    Cache::forget("enrichment:processing:{$applicant->id}");
                    $this->info("  Enrichment abgeschlossen.");
                } catch (\Throwable $e) {
                    $this->logEnrichment($applicant, 'error', 'LLM-Fehler: ' . $e->getMessage());
                    $this->error("  Fehler: " . $e->getMessage());
                    $applicant->update(['enrichment_status' => 'failed']);
                    Cache::forget("enrichment:processing:{$applicant->id}");
                }
            }

            // Restore auth
            if ($originalAuthUser instanceof Authenticatable) {
                Auth::setUser($originalAuthUser);
            } else {
                try { Auth::guard()->logout(); } catch (\Throwable $e) {}
            }

            $this->newLine();
            $this->info("Fertig. Bearbeitet: {$processed} Bewerbung(en).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            try { Auth::guard()->logout(); } catch (\Throwable $e) {}
            try { $lock->release(); } catch (\Throwable $e) {}
        }
    }

    private function nextApplicant(?int $applicantId, array $excludeIds = []): ?RecApplicant
    {
        $query = RecApplicant::query()
            ->with(['applicantStatus', 'team', 'ownedByUser'])
            ->whereNull('enrichment_status');

        if ($applicantId) {
            $query->where('id', $applicantId);
        }

        if (! empty($excludeIds)) {
            $query->whereNotIn('id', array_map('intval', $excludeIds));
        }

        return $query->orderBy('created_at', 'asc')->first();
    }

    private function findTeamAdmin(?Team $team): ?User
    {
        if (! $team) {
            return null;
        }

        return $team->users()
            ->wherePivot('role', 'admin')
            ->orderBy('id')
            ->first();
    }

    private function determineModel(): string
    {
        try {
            $provider = CoreAiProvider::where('key', 'openai')->where('is_active', true)->with('defaultModel')->first();
            $fallback = $provider?->defaultModel?->model_id;
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        } catch (\Throwable $e) {}

        return 'gpt-5.2';
    }

    private function impersonateForTask(User $user, ?Team $team): void
    {
        Auth::setUser($user);

        if ($team) {
            $user->current_team_id = (int) $team->id;
            $user->setRelation('currentTeamRelation', $team);
        }
    }

    private function loadContactInfo(RecApplicant $applicant): array
    {
        try {
            $applicant->loadMissing([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
            ]);

            return $applicant->crmContactLinks->map(function ($link) {
                $c = $link->contact;
                if (! $c) { return null; }
                return [
                    'contact_id' => $c->id,
                    'full_name' => $c->full_name,
                    'emails' => $c->emailAddresses?->map(fn ($e) => [
                        'email' => $e->email_address,
                        'is_primary' => (bool) $e->is_primary,
                    ])->values()->toArray() ?? [],
                    'phones' => $c->phoneNumbers?->map(fn ($p) => [
                        'number' => $p->international,
                        'is_primary' => (bool) $p->is_primary,
                    ])->values()->toArray() ?? [],
                ];
            })->filter()->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadExtraFields(RecApplicant $applicant): array
    {
        try {
            return $applicant->getExtraFieldsWithLabels();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadWhatsAppThreads(RecApplicant $applicant): array
    {
        try {
            if (! class_exists(CommsWhatsAppThread::class)) {
                return [];
            }

            $morphClass = $applicant->getMorphClass();
            $fullClass = get_class($applicant);

            // 1. Threads directly linked via context_model (morph alias or full class)
            $threads = CommsWhatsAppThread::query()
                ->where(function ($q) use ($morphClass, $fullClass, $applicant) {
                    $q->where(function ($q2) use ($morphClass, $applicant) {
                        $q2->where('context_model', $morphClass)
                            ->where('context_model_id', $applicant->id);
                    })->orWhere(function ($q2) use ($fullClass, $applicant) {
                        $q2->where('context_model', $fullClass)
                            ->where('context_model_id', $applicant->id);
                    });
                })
                ->with(['messages' => fn ($q) => $q->orderBy('created_at', 'asc')])
                ->orderByDesc(DB::raw('COALESCE(last_inbound_at, last_outbound_at, updated_at)'))
                ->limit(10)
                ->get();

            // 2. If none found, try matching by contact phone numbers
            if ($threads->isEmpty()) {
                $phones = $this->getContactPhoneNumbers($applicant);
                if (! empty($phones)) {
                    $threads = CommsWhatsAppThread::query()
                        ->where(function ($q) use ($phones) {
                            foreach ($phones as $phone) {
                                $digits = preg_replace('/[^0-9]/', '', $phone);
                                $q->orWhereRaw("REPLACE(REPLACE(remote_phone_number, '+', ''), ' ', '') LIKE ?", ['%' . $digits]);
                            }
                        })
                        ->with(['messages' => fn ($q) => $q->orderBy('created_at', 'asc')])
                        ->orderByDesc(DB::raw('COALESCE(last_inbound_at, last_outbound_at, updated_at)'))
                        ->limit(10)
                        ->get();

                    // Link found threads to applicant for future lookups
                    foreach ($threads as $t) {
                        if (! $t->context_model) {
                            $t->update([
                                'context_model' => $fullClass,
                                'context_model_id' => $applicant->id,
                            ]);
                        }
                    }
                }
            }

            return $threads->map(fn ($t) => [
                'thread_id' => $t->id,
                'remote_phone_number' => $t->remote_phone_number,
                'last_inbound_at' => $t->last_inbound_at?->toIso8601String(),
                'last_outbound_at' => $t->last_outbound_at?->toIso8601String(),
                'messages' => $t->messages->map(fn ($m) => [
                    'direction' => $m->direction,
                    'body' => $m->body,
                    'message_type' => $m->message_type,
                    'sent_at' => $m->sent_at?->toIso8601String(),
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->toArray(),
            ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getContactPhoneNumbers(RecApplicant $applicant): array
    {
        try {
            $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers']);
            $phones = [];
            foreach ($applicant->crmContactLinks as $link) {
                foreach ($link->contact?->phoneNumbers ?? [] as $p) {
                    if ($p->international) {
                        $phones[] = $p->international;
                    } elseif ($p->raw_input) {
                        $phones[] = $p->raw_input;
                    }
                }
            }
            return $phones;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadEmailThreads(RecApplicant $applicant): array
    {
        try {
            if (! class_exists(CommsEmailThread::class)) {
                return [];
            }

            $morphClass = $applicant->getMorphClass();
            $fullClass = get_class($applicant);

            $threads = CommsEmailThread::query()
                ->where(function ($q) use ($morphClass, $fullClass, $applicant) {
                    $q->where(function ($q2) use ($morphClass, $applicant) {
                        $q2->where('context_model', $morphClass)
                            ->where('context_model_id', $applicant->id);
                    })->orWhere(function ($q2) use ($fullClass, $applicant) {
                        $q2->where('context_model', $fullClass)
                            ->where('context_model_id', $applicant->id);
                    });
                })
                ->with([
                    'inboundMails' => fn ($q) => $q->orderBy('received_at', 'asc'),
                    'outboundMails' => fn ($q) => $q->orderBy('created_at', 'asc'),
                ])
                ->orderByDesc(DB::raw('COALESCE(last_inbound_at, last_outbound_at, updated_at)'))
                ->limit(10)
                ->get();

            return $threads->map(fn ($t) => [
                'thread_id' => $t->id,
                'subject' => $t->subject,
                'counterpart' => $t->last_inbound_from_address ?: $t->last_outbound_to_address,
                'last_inbound_at' => $t->last_inbound_at?->toIso8601String(),
                'last_outbound_at' => $t->last_outbound_at?->toIso8601String(),
                'inbound_messages' => $t->inboundMails->map(fn ($m) => [
                    'from' => $m->from,
                    'subject' => $m->subject,
                    'text_body' => $m->text_body,
                    'received_at' => $m->received_at?->toIso8601String(),
                ])->toArray(),
                'outbound_messages' => $t->outboundMails->map(fn ($m) => [
                    'to' => $m->to,
                    'subject' => $m->subject,
                    'text_body' => $m->text_body ?? null,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->toArray(),
            ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadFileReferences(RecApplicant $applicant, array $whatsappThreads, array $emailThreads): array
    {
        $refs = [];

        try {
            // WhatsApp message attachments
            if (class_exists(\Platform\Crm\Models\CommsWhatsAppMessage::class)) {
                $threadIds = array_column($whatsappThreads, 'thread_id');
                if (! empty($threadIds)) {
                    $messages = \Platform\Crm\Models\CommsWhatsAppMessage::whereIn('comms_whatsapp_thread_id', $threadIds)->get();
                    foreach ($messages as $msg) {
                        foreach ($msg->getOrderedFileReferences() as $ref) {
                            if (! $ref->contextFile) { continue; }
                            $refs[] = [
                                'source' => 'whatsapp',
                                'context_file_id' => $ref->contextFile->id,
                                'title' => $ref->contextFile->original_name ?? $ref->contextFile->title ?? '(unbekannt)',
                                'mime_type' => $ref->contextFile->mime_type ?? null,
                            ];
                        }
                    }
                }
            }

            // Email inbound attachments
            if (class_exists(\Platform\Crm\Models\CommsEmailInboundMail::class)) {
                $threadIds = array_column($emailThreads, 'thread_id');
                if (! empty($threadIds)) {
                    $mails = \Platform\Crm\Models\CommsEmailInboundMail::whereIn('thread_id', $threadIds)->get();
                    foreach ($mails as $mail) {
                        foreach ($mail->getOrderedFileReferences() as $ref) {
                            if (! $ref->contextFile) { continue; }
                            $refs[] = [
                                'source' => 'email',
                                'context_file_id' => $ref->contextFile->id,
                                'title' => $ref->contextFile->original_name ?? $ref->contextFile->title ?? '(unbekannt)',
                                'mime_type' => $ref->contextFile->mime_type ?? null,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore — file loading should not break the run
        }

        return $refs;
    }

    private function logEnrichment(RecApplicant $applicant, string $type, string $summary, ?array $details = null): void
    {
        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => '[Enrichment] ' . $summary,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // ignore — logging should never break the run
        }
    }

    private function buildMessages(
        RecApplicant $applicant,
        array $contactInfo,
        array $extraFields,
        array $whatsappThreads,
        array $emailThreads,
        array $fileReferences,
    ): array {
        $system = "Du bist ein Datenextraktions-Agent für ein Recruiting-System.\n"
            . "Deine Aufgabe: Analysiere alle bereitgestellten Daten (Nachrichten, Anhänge, Kontaktinfos) "
            . "und extrahiere alle verwertbaren Informationen.\n\n"
            . "REGELN:\n"
            . "- Schreibe extrahierte Daten in die Extra-Felder der Bewerbung (core.extra_fields.PUT).\n"
            . "- Aktualisiere den CRM-Kontakt (Name, Telefon, Email) falls du bessere/vollständigere Daten findest (crm.contacts.PUT).\n"
            . "- Falls kein CRM-Kontakt verknüpft ist, erstelle/suche einen und verknüpfe ihn (recruiting.applicant_contacts.POST).\n"
            . "- Du arbeitest autonom per Tool-Calls. Schreibe keine Reports oder Zusammenfassungen.\n"
            . "- Antworte auf Deutsch.\n\n"
            . "VERBOTEN:\n"
            . "- Sende KEINE Nachrichten — NIEMALS. Kein Email, kein WhatsApp, nichts.\n"
            . "- Rufe NICHT tools.GET auf — alle benötigten Tools sind bereits geladen.\n"
            . "- Lade KEINE zusätzlichen Tools nach. Du hast alles was du brauchst.\n\n"
            . "VERFÜGBARE TOOLS (bereits geladen):\n"
            . "- core.extra_fields.GET — Extra-Field-Definitionen und Werte laden\n"
            . "- core.extra_fields.PUT — Extra-Field-Werte schreiben (auch für file-Felder: Wert = context_file_id)\n"
            . "- core.context.files.GET — Dateien am Bewerber-Objekt auflisten\n"
            . "- core.context.files.content.GET — Datei-Inhalt lesen (Text, PDF-Text, Bilder als URL)\n"
            . "- crm.contacts.GET — CRM-Kontakt laden\n"
            . "- crm.contacts.PUT — CRM-Kontakt aktualisieren (Name, Vorname, Nachname, salutation_id, academic_title_id, gender_id, birth_date)\n"
            . "- crm.lookups.GET — Lookup-Typen auflisten (z.B. salutation, academic_title, gender, country, address_type)\n"
            . "- crm.lookup.GET — Einzelnen Lookup mit allen Werten laden (IDs für Anrede, Titel, Geschlecht etc.)\n"
            . "- crm.postal_addresses.POST — Postadresse an CRM-Kontakt anlegen (street, zip, city, country_id, address_type_id)\n"
            . "- recruiting.applicants.PUT — Bewerbung aktualisieren (Notes)\n"
            . "- recruiting.applicant_contacts.POST — CRM-Kontakt mit Bewerbung verknüpfen\n\n"
            . "ABLAUF:\n"
            . "1. Lade Extra-Field-Definitionen per core.extra_fields.GET um zu sehen was erwartet wird.\n"
            . "2. Lade Lookup-IDs per crm.lookups.GET, dann crm.lookup.GET für: salutation, academic_title, gender.\n"
            . "   Diese IDs brauchst du, um Anrede, Titel und Geschlecht am CRM-Kontakt zu setzen.\n"
            . "3. Analysiere die bereitgestellten Nachrichten und Kontaktinfos.\n"
            . "4. Falls Datei-Referenzen vorhanden: Lies deren Inhalt per core.context.files.content.GET und extrahiere verwertbare Daten.\n"
            . "5. Schreibe alle extrahierbaren Werte per core.extra_fields.PUT.\n"
            . "   - Für file-Felder: setze den Wert auf die context_file_id (Integer) der passenden Datei.\n"
            . "6. Aktualisiere den CRM-Kontakt per crm.contacts.PUT:\n"
            . "   - Setze salutation_id (Anrede: Herr/Frau), academic_title_id (Titel: Dr., Prof. etc.), gender_id wenn erkennbar.\n"
            . "   - Setze birth_date (Format: YYYY-MM-DD) wenn verfügbar.\n"
            . "   - Aktualisiere first_name, last_name falls vollständiger als bisherige Daten.\n"
            . "7. Falls eine Adresse erkennbar ist: Lade per crm.lookups.GET/crm.lookup.GET die IDs für country und address_type,\n"
            . "   dann lege die Adresse per crm.postal_addresses.POST am Kontakt an.\n"
            . "8. Falls kein Kontakt verknüpft: suche oder erstelle einen und verknüpfe per recruiting.applicant_contacts.POST.\n\n"
            . "WICHTIG:\n"
            . "- Extrahiere ALLES was verwertbar ist: Name, Geburtsdatum, Adresse, Qualifikationen, "
            . "Berufserfahrung, Verfügbarkeit, Gehaltsvorstellung, etc.\n"
            . "- Wenn du Infos in den Nachrichten findest die zu einem Extra-Feld passen, schreibe sie.\n"
            . "- Lies Datei-Anhänge (Lebensläufe, Dokumente) per core.context.files.content.GET — sie enthalten oft die wichtigsten Infos.\n"
            . "- Beginne SOFORT mit Tool-Calls.\n";

        $data = [
            'applicant_id' => $applicant->id,
            'team_id' => $applicant->team_id,
            'notes' => $applicant->notes,
            'crm_contacts' => $contactInfo,
            'extra_fields' => $extraFields,
        ];

        if (! empty($whatsappThreads)) {
            $data['whatsapp_threads'] = $whatsappThreads;
        }

        if (! empty($emailThreads)) {
            $data['email_threads'] = $emailThreads;
        }

        if (! empty($fileReferences)) {
            $data['file_references'] = $fileReferences;
        }

        $user = "Bewerbung (JSON):\n"
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . "Extrahiere alle verwertbaren Informationen und schreibe sie in die passenden Felder. "
            . "Alle Tools sind bereits geladen — beginne SOFORT mit core.extra_fields.GET.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
