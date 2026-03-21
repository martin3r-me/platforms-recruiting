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
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsEmailThread;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
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
                    'crm.contacts.GET', 'crm.contacts.POST', 'crm.contacts.PUT',
                    'crm.phone_numbers.POST', 'crm.email_addresses.POST',
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
                    'model' => $model,
                    'max_iterations' => $maxIterations,
                ]);

                $toolCallLog = [];

                try {
                    $result = $runner->run($messages, $model, $toolContext, [
                        'max_iterations' => $maxIterations,
                        'max_output_tokens' => $maxOutputTokens,
                        'include_web_search' => false,
                        'reasoning' => ['effort' => 'medium'],
                        'preload_tools' => $preloadTools,
                        'skip_discovery_tools' => true,
                        'on_iteration' => function (int $iter, array $toolNames, int $textLen) use ($applicant) {
                            $this->line("  Iter {$iter}: " . (empty($toolNames) ? '(keine Tools)' : implode(', ', $toolNames)));
                            $this->logEnrichment($applicant, 'iteration', "Iteration {$iter}", [
                                'iteration' => $iter,
                                'tools' => $toolNames,
                                'text_length' => $textLen,
                            ]);
                        },
                        'on_tool_result' => function (string $tool, array $args, array $result) use ($applicant, &$toolCallLog) {
                            $ok = $result['ok'] ?? false;
                            $logEntry = [
                                'tool' => $tool,
                                'args' => $args,
                                'ok' => $ok,
                            ];

                            if ($ok) {
                                $logEntry['result'] = $result['data'] ?? $result;
                            } else {
                                $logEntry['error'] = $result['error'] ?? $result;
                                $errMsg = $result['error']['message'] ?? $result['error']['code'] ?? 'unknown';
                                $this->warn("    ⚠ {$tool} FEHLER: {$errMsg}");
                                $this->warn("      Args: " . json_encode($args, JSON_UNESCAPED_UNICODE));
                            }

                            $toolCallLog[] = $logEntry;

                            $this->logEnrichment($applicant, $ok ? 'tool_call' : 'tool_error', ($ok ? '' : 'FEHLER: ') . $tool, $logEntry);
                        },
                    ]);

                    $iterations = (int) ($result['iterations'] ?? 0);
                    $allToolCallNames = $result['all_tool_call_names'] ?? [];

                    $this->logEnrichment($applicant, 'run_completed', "Enrichment abgeschlossen: {$iterations} Iterationen", [
                        'iterations' => $iterations,
                        'all_tool_calls' => $allToolCallNames,
                        'tool_call_count' => count($toolCallLog),
                    ]);

                    $this->line("  Iterationen: {$iterations} | Tools: " . (empty($allToolCallNames) ? '(keine)' : implode(', ', $allToolCallNames)));

                    // Deterministic post-LLM step: ensure contact is linked
                    $applicant->refresh();
                    $applicant->load('crmContactLinks');

                    if ($applicant->crmContactLinks->isEmpty()) {
                        $this->tryAutoLinkContact($applicant, $admin);
                        $applicant->load('crmContactLinks');
                    }

                    if ($applicant->crmContactLinks->isNotEmpty()) {
                        $applicant->update(['enrichment_status' => 'enriched']);
                        $this->info("  Enrichment abgeschlossen.");
                    } else {
                        $applicant->update(['enrichment_status' => 'no_contact']);
                        $this->warn("  Enrichment durchgelaufen, aber kein CRM-Kontakt verknüpft — manuelle Prüfung nötig.");
                        $this->logEnrichment($applicant, 'no_contact', 'Enrichment abgeschlossen, aber kein CRM-Kontakt verknüpft. Manuelle Prüfung erforderlich.');
                    }
                    Cache::forget("enrichment:processing:{$applicant->id}");

                    // Fix portal threads: replace portal address with primary CRM email
                    if (!$dryRun) {
                        $this->fixPortalThreadAddresses($applicant);
                    }

                    // Try to send initial WhatsApp template if phone number available
                    if (!$dryRun) {
                        $this->trySendInitialWhatsAppTemplate($applicant);
                    }
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
            ->with(['team', 'ownedByUser'])
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

        // Prefer owner (has all permissions), fallback to admin, then any team member
        return $team->users()->wherePivot('role', 'owner')->orderBy('id')->first()
            ?? $team->users()->wherePivot('role', 'admin')->orderBy('id')->first()
            ?? $team->users()->orderBy('id')->first();
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
                        if (method_exists($t, 'addContext')) {
                            $t->addContext($morphClass, $applicant->id, 'enrichment');
                        }
                        if (! $t->context_model) {
                            $t->updateQuietly([
                                'context_model' => $morphClass,
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
                'messages' => $t->messages->map(function ($m) {
                    $msg = [
                        'direction' => $m->direction,
                        'body' => $m->body,
                        'message_type' => $m->message_type,
                        'sent_at' => $m->sent_at?->toIso8601String(),
                        'created_at' => $m->created_at?->toIso8601String(),
                    ];

                    if ($m->message_type && $m->message_type !== 'text' && $m->message_type !== 'template') {
                        $fileRefs = [];
                        foreach ($m->getOrderedFileReferences() as $ref) {
                            if (!$ref->contextFile) { continue; }
                            $entry = [
                                'context_file_id' => $ref->contextFile->id,
                                'title' => $ref->contextFile->original_name ?? $ref->contextFile->title ?? '(Anhang)',
                                'mime_type' => $ref->contextFile->mime_type ?? null,
                            ];
                            if ($ref->caption) {
                                $entry['caption'] = $ref->caption;
                            }
                            $fileRefs[] = $entry;
                        }
                        if (!empty($fileRefs)) {
                            $msg['attachments'] = $fileRefs;
                        }
                    }

                    return $msg;
                })->toArray(),
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

    /**
     * Deterministic post-LLM step: If the LLM created a CRM contact but didn't link it,
     * find the contact and link it automatically.
     *
     * Strategy:
     * 1. Find CRM contacts created by the impersonated admin in the last 10 minutes
     *    for this team that are NOT already linked to any applicant.
     * 2. Match by name from email threads / WhatsApp threads if possible.
     * 3. If exactly one unlinked contact was recently created, link it.
     */
    private function tryAutoLinkContact(RecApplicant $applicant, User $admin): void
    {
        try {
            $applicantMorphClass = $applicant->getMorphClass();

            // Strategy 1: Find recently created contacts by this admin (LLM created but didn't link)
            $cutoff = Carbon::now()->subMinutes(10);
            $recentContacts = \Platform\Crm\Models\CrmContact::where('team_id', $applicant->team_id)
                ->where('created_by_user_id', $admin->id)
                ->where('created_at', '>=', $cutoff)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            $unlinked = $recentContacts->filter(function ($contact) use ($applicant, $applicantMorphClass) {
                return !\Platform\Crm\Models\CrmContactLink::where('contact_id', $contact->id)
                    ->where('linkable_id', $applicant->id)
                    ->where('linkable_type', $applicantMorphClass)
                    ->exists();
            });

            if ($unlinked->isNotEmpty()) {
                $contact = $unlinked->first();
                $applicant->crmContactLinks()->create([
                    'contact_id' => $contact->id,
                    'team_id' => $applicant->team_id,
                    'created_by_user_id' => $admin->id,
                ]);
                $this->info("  Auto-Link: Kontakt #{$contact->id} ({$contact->full_name}) verknüpft (kürzlich erstellt).");
                $this->logEnrichment($applicant, 'auto_linked', "CRM-Kontakt #{$contact->id} ({$contact->full_name}) automatisch verknüpft (kürzlich erstellt).");
                return;
            }

            // Strategy 2: Search existing CRM contacts by name from extra fields
            $nameFromFields = $this->extractNameFromExtraFields($applicant);
            if ($nameFromFields) {
                $query = \Platform\Crm\Models\CrmContact::where('team_id', $applicant->team_id)
                    ->where('is_active', true);

                if ($nameFromFields['last_name']) {
                    $query->where('last_name', $nameFromFields['last_name']);
                }
                if ($nameFromFields['first_name']) {
                    $query->where('first_name', $nameFromFields['first_name']);
                }

                // Only auto-link if exactly 1 match (avoid ambiguity)
                $matches = $query->limit(2)->get();

                if ($matches->count() === 1) {
                    $contact = $matches->first();

                    // Check not already linked
                    $alreadyLinked = \Platform\Crm\Models\CrmContactLink::where('contact_id', $contact->id)
                        ->where('linkable_id', $applicant->id)
                        ->where('linkable_type', $applicantMorphClass)
                        ->exists();

                    if (! $alreadyLinked) {
                        $applicant->crmContactLinks()->create([
                            'contact_id' => $contact->id,
                            'team_id' => $applicant->team_id,
                            'created_by_user_id' => $admin->id,
                        ]);
                        $this->info("  Auto-Link: Kontakt #{$contact->id} ({$contact->full_name}) verknüpft (Name-Match aus Extra-Fields).");
                        $this->logEnrichment($applicant, 'auto_linked', "CRM-Kontakt #{$contact->id} ({$contact->full_name}) automatisch verknüpft (Name-Match).");
                        return;
                    }
                } elseif ($matches->count() > 1) {
                    $this->line("  Auto-Link: Mehrere Kontakte gefunden für '{$nameFromFields['first_name']} {$nameFromFields['last_name']}' — übersprungen (mehrdeutig).");
                }
            }

            $this->line("  Auto-Link: Kein passender Kontakt gefunden.");
        } catch (\Throwable $e) {
            $this->warn("  Auto-Link Fehler: " . $e->getMessage());
        }
    }

    private function extractNameFromExtraFields(RecApplicant $applicant): ?array
    {
        try {
            $fields = $applicant->getExtraFieldsWithLabels();
            $firstName = null;
            $lastName = null;

            foreach ($fields as $field) {
                $key = strtolower($field['key'] ?? '');
                $value = $field['value'] ?? null;
                if (empty($value) || !is_string($value)) continue;

                if (in_array($key, ['first_name', 'vorname', 'firstname'])) {
                    $firstName = trim($value);
                } elseif (in_array($key, ['last_name', 'nachname', 'lastname', 'name'])) {
                    $lastName = trim($value);
                }
            }

            // Need at least last name for a meaningful search
            if (! $lastName) {
                return null;
            }

            return ['first_name' => $firstName, 'last_name' => $lastName];
        } catch (\Throwable $e) {
            return null;
        }
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

    /**
     * Try to send an initial WhatsApp template message to the applicant.
     * This is used to verify the phone number works and to initiate contact.
     */
    private function trySendInitialWhatsAppTemplate(RecApplicant $applicant): void
    {
        try {
            // Find a mobile phone number with unknown WhatsApp status
            $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers', 'postings.commsChannels']);

            $phoneToContact = null;
            foreach ($applicant->crmContactLinks as $link) {
                foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                    if (!$phone->is_active) {
                        continue;
                    }
                    // Only try if WhatsApp status is unknown (not yet tested)
                    if ($phone->whatsapp_status !== CrmPhoneNumber::WHATSAPP_UNKNOWN) {
                        $this->line("  WhatsApp: Status bereits bekannt ({$phone->whatsapp_status})");
                        continue;
                    }
                    $phoneToContact = $phone;
                    break 2;
                }
            }

            if (!$phoneToContact) {
                $this->line("  WhatsApp: Keine ungeprüfte Telefonnummer gefunden");
                return;
            }

            // Find a WhatsApp channel linked to the applicant's postings
            $whatsAppChannel = null;
            foreach ($applicant->postings as $posting) {
                foreach ($posting->commsChannels ?? [] as $channel) {
                    if ($channel->type === 'whatsapp' && $channel->is_active) {
                        $whatsAppChannel = $channel;
                        break 2;
                    }
                }
            }

            if (!$whatsAppChannel) {
                $this->line("  WhatsApp: Kein WhatsApp-Channel an Posting gefunden");
                return;
            }

            // Get the first available template from the channel meta
            $templateName = $whatsAppChannel->meta['default_template'] ?? 'begruesung_template';
            $templateLang = $whatsAppChannel->meta['default_template_lang'] ?? 'en';

            $phoneNumber = $phoneToContact->international ?: $phoneToContact->raw_input;
            if (!$phoneNumber) {
                $this->line("  WhatsApp: Keine Telefonnummer im internationalen Format");
                return;
            }

            $this->line("  WhatsApp: Sende Template '{$templateName}' an {$phoneNumber}...");

            $whatsAppService = app(WhatsAppMetaService::class);
            $message = $whatsAppService->sendTemplate(
                channel: $whatsAppChannel,
                to: $phoneNumber,
                templateName: $templateName,
                components: [],
                languageCode: $templateLang,
            );

            // Link thread to applicant so isWhatsAppWindowOpen() can find it
            $thread = $message->thread;
            if ($thread) {
                if (method_exists($thread, 'addContext')) {
                    $thread->addContext($applicant->getMorphClass(), $applicant->id, 'enrichment');
                }
                if (! $thread->context_model) {
                    $thread->updateQuietly([
                        'context_model' => $applicant->getMorphClass(),
                        'context_model_id' => $applicant->id,
                    ]);
                }
            }

            $this->logEnrichment($applicant, 'whatsapp_template_sent', "WhatsApp-Template '{$templateName}' gesendet", [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'message_id' => $message->id,
                'meta_message_id' => $message->meta_message_id,
            ]);

            $this->info("  WhatsApp: Template gesendet (Message #{$message->id})");

        } catch (\Throwable $e) {
            $this->logEnrichment($applicant, 'whatsapp_template_error', 'WhatsApp-Template Fehler: ' . $e->getMessage());
            $this->warn("  WhatsApp: Fehler - " . $e->getMessage());
        }
    }

    /**
     * Fix portal thread addresses: replace portal sender with primary CRM email.
     *
     * After enrichment the CRM contact should have the real email.
     * If any email thread's counterpart (last_inbound_from_address) differs
     * from the primary CRM email, we overwrite it so the AutoPilot replies
     * to the correct address — deterministically, no LLM involved.
     */
    private function fixPortalThreadAddresses(RecApplicant $applicant): void
    {
        try {
            // 1. Get primary email from CRM contacts (force-reload after enrichment)
            $applicant->load(['crmContactLinks.contact.emailAddresses']);

            $primaryEmail = null;
            foreach ($applicant->crmContactLinks as $link) {
                foreach ($link->contact?->emailAddresses ?? [] as $email) {
                    if ($email->is_primary) {
                        $primaryEmail = $email->email_address;
                        break 2;
                    }
                    if ($primaryEmail === null) {
                        $primaryEmail = $email->email_address;
                    }
                }
            }

            if (!$primaryEmail) {
                return;
            }

            // 2. Find email threads linked to this applicant
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
                ->get();

            if ($threads->isEmpty()) {
                return;
            }

            // 3. Fix threads where counterpart differs from primary email
            $primaryLower = strtolower(trim($primaryEmail));
            $fixed = 0;

            foreach ($threads as $thread) {
                $counterpart = strtolower(trim($thread->last_inbound_from_address ?? ''));

                if ($counterpart === '' || $counterpart === $primaryLower) {
                    continue;
                }

                $oldAddress = $thread->last_inbound_from_address;
                $thread->update([
                    'last_inbound_from_address' => $primaryEmail,
                ]);
                $fixed++;

                $this->line("  Thread #{$thread->id}: Adresse korrigiert ({$oldAddress} → {$primaryEmail})");
            }

            if ($fixed > 0) {
                $this->logEnrichment($applicant, 'thread_address_fixed', "{$fixed} Thread(s): Portal-Adresse durch primäre CRM-Email ersetzt ({$primaryEmail})");
            }
        } catch (\Throwable $e) {
            $this->warn("  Thread-Adresskorrektur fehlgeschlagen: " . $e->getMessage());
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
            . "- crm.contacts.POST — Neuen CRM-Kontakt erstellen. Nutze _code Parameter statt IDs:\n"
            . "  salutation_code: \"HERR\" | \"FRAU\" | \"DIVERS\"\n"
            . "  gender_code: \"MALE\" | \"FEMALE\" | \"DIVERSE\"\n"
            . "  contact_status_code: \"ACTIVE\"\n"
            . "  language_code: \"de\" | \"en\" etc.\n"
            . "  academic_title_id benötigt academic_title_confirm: true (nur setzen wenn Titel explizit genannt)\n"
            . "- crm.contacts.PUT — CRM-Kontakt aktualisieren (gleiche _code Parameter wie POST)\n"
            . "- crm.phone_numbers.POST — Telefonnummer an CRM-Kontakt anlegen. phone_type_code: \"MOBILE\" (Default wenn weggelassen)\n"
            . "- crm.email_addresses.POST — Email-Adresse an CRM-Kontakt anlegen. email_type_code: \"PRIVATE\" (Default wenn weggelassen)\n"
            . "- crm.postal_addresses.POST — Postadresse an CRM-Kontakt anlegen (address_type_code: \"PRIVATE\", country_code: \"DE\")\n"
            . "- recruiting.applicants.PUT — Bewerbung aktualisieren (notes, applied_at, etc.)\n"
            . "- recruiting.applicant_contacts.POST — CRM-Kontakt mit Bewerbung verknüpfen\n\n"
            . "ABLAUF:\n"
            . "1. Lade Extra-Field-Definitionen per core.extra_fields.GET um zu sehen was erwartet wird.\n"
            . "2. Falls crm_contacts LEER ist → SOFORT Kontakt erstellen und verknüpfen (Schritt 9). Ohne Kontakt können die folgenden Schritte nicht funktionieren.\n"
            . "3. Analysiere die bereitgestellten Nachrichten und Kontaktinfos.\n"
            . "4. Falls Datei-Referenzen vorhanden: Lies deren Inhalt per core.context.files.content.GET und extrahiere verwertbare Daten.\n"
            . "5. Schreibe alle extrahierbaren Werte per core.extra_fields.PUT.\n"
            . "   - Format: {\"fields\": {\"feldkey\": \"wert\", ...}} — nutze NUR die Keys aus core.extra_fields.GET (Schritt 1).\n"
            . "   - Sende NUR Felder mit einem tatsächlichen Wert. NIEMALS null oder \"\" mitsenden!\n"
            . "   - null oder \"\" LÖSCHT den bestehenden Wert — das ist fast nie gewollt.\n"
            . "   - Wenn du keinen Wert für ein Feld hast, lasse es komplett weg.\n"
            . "   - Für file-Felder: setze den Wert auf die context_file_id (Integer) der passenden Datei.\n"
            . "   - WICHTIG: Email, Telefon und Geburtsdatum sind KEINE Extra-Fields! Diese gehören an den CRM-Kontakt:\n"
            . "     - Email → crm.email_addresses.POST\n"
            . "     - Telefon → crm.phone_numbers.POST\n"
            . "     - Geburtsdatum → crm.contacts.PUT (birth_date)\n"
            . "6. Aktualisiere den CRM-Kontakt per crm.contacts.PUT:\n"
            . "   - Setze salutation_code (\"HERR\"/\"FRAU\"), gender_code (\"MALE\"/\"FEMALE\") wenn erkennbar.\n"
            . "   - Setze birth_date (Format: YYYY-MM-DD) wenn verfügbar.\n"
            . "   - Aktualisiere first_name, last_name falls vollständiger als bisherige Daten.\n"
            . "   - academic_title_id nur setzen wenn Titel explizit genannt (mit academic_title_confirm: true).\n"
            . "7. Falls Telefonnummer gefunden: crm.phone_numbers.POST mit entity_type=\"contact\", entity_id, raw_input (phone_type_code optional, Default: MOBILE).\n"
            . "8. Email-Adresse — WICHTIG, REIHENFOLGE BEACHTEN:\n"
            . "   a) Suche die persönliche Email-Adresse des Bewerbers. Bevorzugte Quellen (in dieser Reihenfolge):\n"
            . "      1. Anhänge/Lebenslauf (per core.context.files.content.GET lesen) — dort steht oft die echte Email.\n"
            . "      2. Email-Body (text_body) — im Fließtext oder in der Signatur.\n"
            . "      3. Absender-Adresse (from) — NUR als letzter Fallback und NUR wenn es eine persönliche Adresse ist.\n"
            . "   b) NIEMALS Portal-/System-Adressen verwenden (z.B. noreply@, notification@, *@jobs.*, *@portal.*, *@bewerbung.*).\n"
            . "   c) Lege genau EINE Email-Adresse an per crm.email_addresses.POST (entity_type=\"contact\", entity_id, email_address, is_primary=true). email_type_code optional, Default: PRIVATE.\n"
            . "9. Falls kein Kontakt verknüpft (crm_contacts ist leer) — DIESER SCHRITT HAT HÖCHSTE PRIORITÄT:\n"
            . "    a) Suche per crm.contacts.GET ob der Kontakt bereits existiert.\n"
            . "    b) Falls nicht gefunden: Erstelle per crm.contacts.POST einen neuen Kontakt (first_name, last_name, contact_status_code: \"ACTIVE\").\n"
            . "    c) Verknüpfe den Kontakt per recruiting.applicant_contacts.POST (contact_id).\n"
            . "    d) DANN: Lege Email, Telefon und Adresse am neu erstellten Kontakt an (Schritte 7-8).\n"
            . "    WICHTIG: Schritt 9 hat HÖCHSTE PRIORITÄT — führe ihn VOR den Detail-Schritten aus wenn kein Kontakt verknüpft ist (siehe Schritt 2).\n"
            . "10. Postadresse: Falls eine Adresse erkennbar ist (aus Lebenslauf, Email-Body, Anhängen),\n"
            . "    lege die Adresse per crm.postal_addresses.POST am Kontakt an (address_type_code: \"PRIVATE\", country_code: \"DE\" für deutsche Adressen).\n\n"
            . "PORTAL-BEWERBUNGEN:\n"
            . "- Bewerbungen kommen häufig über Job-Portale (StepStone, Indeed, etc.).\n"
            . "- Die Absender-Adresse (from) ist dann eine System-Adresse, NICHT die des Bewerbers.\n"
            . "- Die echte Email-Adresse des Bewerbers steht im Email-Body oder in Anhängen (Lebenslauf, Anschreiben).\n"
            . "- Lies IMMER den Email-Body (text_body) und Anhänge um die persönliche Email zu finden.\n"
            . "- Lege am CRM-Kontakt AUSSCHLIESSLICH die persönliche Email des Bewerbers an.\n"
            . "- Erkennungsmerkmale für Portal-Adressen: noreply@, notification@, bewerbung@, *@jobs.*, *@stepstone.*, *@indeed.*, *@portal.*\n"
            . "- Im Zweifel: Lieber KEINE Email anlegen als eine Portal-Adresse.\n\n"
            . "WICHTIG:\n"
            . "- Extrahiere ALLES was verwertbar ist: Name, Geburtsdatum, Adresse, Qualifikationen, "
            . "Berufserfahrung, Verfügbarkeit, Gehaltsvorstellung, etc.\n"
            . "- Wenn du Infos in den Nachrichten findest die zu einem Extra-Feld passen, schreibe sie.\n"
            . "- Lies Datei-Anhänge (Lebensläufe, Dokumente) per core.context.files.content.GET — sie enthalten oft die wichtigsten Infos.\n"
            . "- Beginne SOFORT mit Tool-Calls.\n\n"
            . "KRITISCH — KONTAKT VERKNÜPFEN:\n"
            . "- Am Ende MUSS ein CRM-Kontakt mit der Bewerbung verknüpft sein.\n"
            . "- Prüfe am Anfang ob crm_contacts leer ist. Falls ja:\n"
            . "  1. crm.contacts.POST → neuen Kontakt erstellen (mit contact_status_code: \"ACTIVE\")\n"
            . "  2. recruiting.applicant_contacts.POST → Kontakt mit Bewerbung verknüpfen (contact_id = ID des erstellten Kontakts)\n"
            . "- Das Erstellen allein reicht NICHT — du MUSST auch recruiting.applicant_contacts.POST aufrufen!\n"
            . "- Ohne Verknüpfung gilt die Enrichment als gescheitert.\n";

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
