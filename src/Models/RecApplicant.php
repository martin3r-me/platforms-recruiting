<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
use Platform\Recruiting\Traits\HasApplicantContact;
use Platform\Recruiting\Traits\RendersPublicFormCompletionExtras;
use Platform\Recruiting\Traits\UsesAccordionPublicForm;
use Platform\Hcm\Traits\SyncsCrmContactFields;
use Symfony\Component\Uid\UuidV7;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;

class RecApplicant extends Model implements InheritsExtraFields
{
    use HasApplicantContact;
    use HasExtraFields {
        getExtraFieldsWithLabels as private getExtraFieldsWithLabelsBase;
    }
    use HasPublicFormLink;
    use SyncsCrmContactFields;
    use UsesAccordionPublicForm;
    use RendersPublicFormCompletionExtras;

    protected $table = 'rec_applicants';

    protected $fillable = [
        'uuid', 'public_token', 'rec_applicant_status_id', 'rec_phase_id', 'progress', 'notes', 'applied_at',
        'is_active', 'is_parked', 'parked_at', 'is_on_hr_desk', 'rejected_at',
        'auto_pilot', 'auto_pilot_completed_at', 'auto_pilot_state_id',
        'auto_pilot_reminder_count', 'auto_pilot_last_reminder_at',
        'preferred_comms_channel_id', 'enrichment_status',
        'source_platform_id', 'is_unrouted',
        'contract_template_id',
        'import_source',
        'export_changed_at',
        'is_test',
        'team_id', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_parked' => 'boolean',
        'is_unrouted' => 'boolean',
        'parked_at' => 'datetime',
        'is_on_hr_desk' => 'boolean',
        'rejected_at' => 'datetime',
        'auto_pilot' => 'boolean',
        'auto_pilot_completed_at' => 'datetime',
        'auto_pilot_state_id' => 'integer',
        'auto_pilot_reminder_count' => 'integer',
        'auto_pilot_last_reminder_at' => 'datetime',
        'progress' => 'integer',
        'applied_at' => 'date',
        'export_changed_at' => 'datetime',
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
            if (empty($model->public_token)) {
                $model->public_token = $model->generatePublicToken();
            }
        });

        static::saving(function (self $model) {
            if ($model->isDirty('auto_pilot') && !$model->auto_pilot && $model->getOriginal('auto_pilot')) {
                if ($model->calculateProgress() < 100) {
                    $model->auto_pilot = true;
                }
            }
        });
    }

    public function generatePublicToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (self::where('public_token', $token)->exists());

        return $token;
    }

    public function getPublicUrl(): string
    {
        return $this->getOrCreatePublicFormLink()->getUrl();
    }

    /**
     * Override des Trait-Defaults: das options.always_show_in_form Flag soll
     * nur in der Defining-Phase greifen (= dort wo das Feld angelegt wurde).
     * Wenn das Feld via extraFieldParents()-Inheritance in eine spaetere
     * Phase wandert (z.B. vorname Phase 1 → sichtbar in Phase 3 + 6), soll
     * das Flag dort NICHT mehr greifen — der normale "filled"-Filter im
     * Form blendet das Feld dann aus weil schon ausgefuellt.
     *
     * Konkret: Phase 1 fragt vorname/nachname/email/telefonnummer mit
     * Bestaetigungs-Anzeige (always_show greift). Sobald das Feld in
     * Phase 3/6 vererbt wird: dort verschwindet es im Form, weil
     * Bewerber sie schon in Phase 1 ausgefuellt hat.
     *
     * Modifikation nur am Output-Array — die Definition in der DB bleibt
     * unangetastet. Andere Module (HCM-Onboarding etc.) nutzen die
     * Trait-Variante ohne Override und sehen das Default-Verhalten.
     *
     * Implementations-Detail: getExtraFieldsWithLabelsBase ist die
     * via Trait-Aliasing zugaengliche Trait-Original-Methode. parent::
     * geht hier nicht weil HasExtraFields ein Trait ist und die Methode
     * nicht auf Eloquent\Model existiert.
     */
    public function getExtraFieldsWithLabels(): array
    {
        $fields = $this->getExtraFieldsWithLabelsBase();

        $currentPhaseId = $this->rec_phase_id;
        if (!$currentPhaseId || empty($fields)) {
            return $fields;
        }

        $fieldIds = array_column($fields, 'id');
        $contextMap = \Platform\Core\Models\CoreExtraFieldDefinition::query()
            ->whereIn('id', $fieldIds)
            ->pluck('context_id', 'id');

        foreach ($fields as &$field) {
            $contextId = $contextMap[$field['id']] ?? null;
            if ($contextId === null) {
                continue;
            }
            // Nur fuer geerbte Felder (nicht in der Defining-Phase) das
            // always_show_in_form Flag aus dem Output-Array entfernen.
            if ((int) $contextId !== (int) $currentPhaseId) {
                if (is_array($field['options'] ?? null)
                    && array_key_exists('always_show_in_form', $field['options'])) {
                    unset($field['options']['always_show_in_form']);
                    if (empty($field['options'])) {
                        $field['options'] = null;
                    }
                }
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * Override: Recruiting nutzt den eigenen SyncApplicantExtraFieldsToCrm-
     * Service statt der HCM-Trait-Implementierung. Hintergrund: der
     * HCM-Trait fuegt CrmEmailAddress ohne email_type_id ein, das ist
     * NOT NULL ohne Default → SQLSTATE 1364 beim Form-Save.
     *
     * Unser Service:
     *  - resolved CrmEmailType/CrmPhoneType (PRIVATE/MOBILE als Default)
     *  - find-or-update auf bestehende Eintraege (kein Duplikat-Spam)
     *  - promotet Bewerber-eingegebene Mail/Phone zur primary
     *
     * HCM-Trait `syncCrmContactToExtraFields` (Reverse-Direction) bleibt
     * weiterhin via SyncsCrmContactFields verfuegbar — wird in
     * EnrichInboxApplicants genutzt. HCM-Module unberuehrt.
     *
     * Diagnose-Log via Log::critical bleibt als Sicherheitsnetz erhalten —
     * jeder ungefangene Sync-Fehler wird sofort sichtbar im Log.
     */
    public function syncExtraFieldsToCrmContact(): void
    {
        try {
            app(\Platform\Recruiting\Services\SyncApplicantExtraFieldsToCrm::class)
                ->sync($this);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::critical(
                '[RecApplicant.syncExtraFieldsToCrmContact] ' . $e->getMessage(),
                [
                    'class'        => get_class($e),
                    'file'         => $e->getFile() . ':' . $e->getLine(),
                    'applicant_id' => $this->id,
                    'trace'        => $e->getTraceAsString(),
                ]
            );
            throw $e;
        }
    }

    /**
     * Parent-Models von denen Extra-Field-Definitionen geerbt werden.
     * Applicants erben Extra-Felder von allen Phasen bis inkl. der aktuellen,
     * damit im Dashboard/API alle bisher gesammelten Daten sichtbar sind.
     */
    public function extraFieldParents(): array
    {
        $phase = $this->phase;

        if (!$phase) {
            // CSV-/Bulk-Imports haben keine Phase, brauchen aber Zugriff auf
            // alle Team-weit definierten Personenstamm-Extra-Felder
            // (geburtsort, IBAN, sozialversicherungsnummer, …) — sonst kann
            // weder der Import-Service setExtraField() schreiben noch der
            // Vertrags-Resolver applicant.extra_field.X lesen, und HR sieht
            // die Felder im Bewerber-Detail-Modal nicht.
            //
            // Special-Case bewusst eingegrenzt durch import_source IS NOT NULL —
            // normale phase-lose Bewerber (gibt's eigentlich nicht, aber
            // defensiv) kriegen weiter ein leeres Array.
            //
            // Hinweis: dass alle aktiven Team-Phasen zurueckgegeben werden ist
            // streng genommen kein "Parent"-Verhaeltnis im Sinne der Phase-
            // Order-Hierarchie — gerechtfertigt ist's nur weil Imports keinen
            // Phase-Pfad haben. Sauberer Refactor (Personenstamm-Felder auf
            // Team-Context) ist als separate Aufgabe geplant.
            if ($this->import_source) {
                return RecPhase::forTeam($this->team_id)
                    ->active()
                    ->orderBy('order')
                    ->get()
                    ->all();
            }
            return [];
        }

        return RecPhase::where('rec_position_id', $phase->rec_position_id)
            ->where('order', '<=', $phase->order)
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->all();
    }

    public function phase()
    {
        return $this->belongsTo(RecPhase::class, 'rec_phase_id');
    }

    public function postings()
    {
        return $this->belongsToMany(RecPosting::class, 'rec_applicant_posting', 'rec_applicant_id', 'rec_posting_id')
            ->using(RecApplicantPosting::class)
            ->withPivot(['applied_at', 'notes'])
            ->withTimestamps();
    }

    public function positions(): Collection
    {
        return $this->postings->map(fn ($posting) => $posting->position)->filter()->unique('id')->values();
    }

    public function applicantStatus()
    {
        return $this->belongsTo(RecApplicantStatus::class, 'rec_applicant_status_id');
    }

    public function preferredCommsChannel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsChannel::class, 'preferred_comms_channel_id');
    }

    public function autoPilotState()
    {
        return $this->belongsTo(RecAutoPilotState::class, 'auto_pilot_state_id');
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    public function ownedByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'owned_by_user_id');
    }

    public function autoPilotLogs()
    {
        return $this->hasMany(RecAutoPilotLog::class, 'rec_applicant_id');
    }

    public function interviewBookings()
    {
        return $this->hasMany(RecInterviewBooking::class, 'rec_applicant_id');
    }

    public function sourcePlatform()
    {
        return $this->belongsTo(RecSourcePlatform::class, 'source_platform_id');
    }

    public function contractTemplate()
    {
        return $this->belongsTo(RecContractTemplate::class, 'contract_template_id');
    }

    public function contracts()
    {
        return $this->hasMany(RecContract::class, 'rec_applicant_id');
    }

    /**
     * Hat 1:0..1 zum Mitarbeiter — wenn der Applicant via Phase-4-Hook
     * zum RecEmployee konvertiert wurde. Sonst null.
     */
    public function employee()
    {
        return $this->hasOne(RecEmployee::class, 'rec_applicant_id');
    }

    public function legalStatus()
    {
        return $this->hasOne(RecApplicantLegalStatus::class, 'rec_applicant_id');
    }

    public function hrDeskCases()
    {
        return $this->hasMany(RecHrDeskCase::class, 'rec_applicant_id');
    }

    public function openHrDeskCase()
    {
        return $this->hasOne(RecHrDeskCase::class, 'rec_applicant_id')
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->latestOfMany();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('is_parked', false)
            ->where('is_on_hr_desk', false)
            ->whereNull('rejected_at');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Only routed applicants — i.e. those whose inbound source matched a
     * known RecSourcePlatform. Unrouted applicants live in the dedicated
     * Eingangs-Inbox and are excluded from normal flows (Bewerber-Liste,
     * Dashboard-KPIs, AutoPilot, Enrichment-Cronjob).
     */
    public function scopeRouted($query)
    {
        return $query->where('is_unrouted', false);
    }

    public function scopeUnrouted($query)
    {
        return $query->where('is_unrouted', true);
    }

    /**
     * Schließt CSV-/Bulk-Importe aus — nutze diesen Scope auf allen
     * Recruiting-KPI- und Funnel-Queries (Dashboard, Time-to-Hire,
     * Conversion, Stuck-Indikatoren). Imports waren bereits Mitarbeiter
     * und durchlaufen den Funnel nicht — sie würden Zahlen verwässern.
     */
    public function scopeWithoutImports($query)
    {
        return $query->whereNull('import_source');
    }

    public function scopeOnlyImports($query)
    {
        return $query->whereNotNull('import_source');
    }

    public function checkAutoPilotCompletion(): void
    {
        if (!$this->auto_pilot || $this->auto_pilot_completed_at !== null) {
            return;
        }

        // completion_type-aware: respects the phase's setting (fields/booking/manual)
        if (!$this->isPhaseComplete()) {
            return;
        }

        // Bewerber-Form-Daten in den CrmContact synct (Vorname/Nachname/
        // Geburtsdatum/Adresse). Idempotent — laeuft bei jedem Phase-
        // Uebergang. Stellt sicher dass Vertragsvorlagen die per
        // contact.first_name / contact.address.* mappen, korrekt
        // personalisiert werden.
        try {
            app(\Platform\Recruiting\Services\SyncApplicantExtraFieldsToCrm::class)
                ->sync($this->fresh(['crmContactLinks.contact', 'extraFieldValues.definition']));
        } catch (\Throwable $e) {
            // Fail-safe: Sync-Fehler darf den Phase-Uebergang nicht blockieren
        }

        $phase = $this->phase;
        $nextPhase = $phase?->nextPhase();

        // Phase has a successor → advance to next phase
        if ($nextPhase && $phase?->auto_advance) {
            $this->rec_phase_id = $nextPhase->id;
            $this->auto_pilot_completed_at = null;
            $this->auto_pilot_reminder_count = 0;
            $this->auto_pilot_last_reminder_at = null;
            $this->progress = 0;
            $this->clearExtraFieldDefinitionsCache();
            $this->save();

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'phase_advanced',
                'summary' => "Phase \"{$phase->name}\" abgeschlossen — weiter zu \"{$nextPhase->name}\".",
            ]);

            $this->triggerPhaseCompletionHooks($phase);
            return;
        }

        // Last phase or manual advance → mark as completed
        $completedStateId = RecAutoPilotState::where('code', 'completed')
            ->whereNull('team_id')
            ->value('id');

        $this->auto_pilot_state_id = $completedStateId;
        $this->auto_pilot_completed_at = now();
        $this->save();

        $phaseName = $phase?->name ?? 'Unbekannt';
        RecAutoPilotLog::create([
            'rec_applicant_id' => $this->id,
            'type' => 'completed',
            'summary' => "Alle Pflichtfelder in Phase \"{$phaseName}\" ausgefüllt — AutoPilot abgeschlossen.",
        ]);

        // Phase-Completion-Hooks auch im "last phase"-Branch ausfuehren —
        // sonst greift creates_employee_on_completion nicht wenn die letzte
        // aktive Phase die MA-Anlage-Phase ist (typisch nach Phase-5-Deaktivierung
        // landet Phase 4 als de-facto Endphase hier).
        if ($phase) {
            $this->triggerPhaseCompletionHooks($phase);
        }

        // Phase entscheidet selbst ueber den Schulungs-Buchungs-Link am
        // Phase-Ende via completion_config.send_booking_notification_on_completion.
        // Legacy-Fallback: nicht konfiguriert + keine Folge-Phase → senden
        // (entspricht dem alten 2-Phasen-Verhalten in Duesseldorf/Koeln/Bonn/MGL,
        // wo Phase 2 = letzte Phase = Schulungs-Buchungs-Trigger).
        $config = $phase?->completion_config ?? [];
        $shouldSendBooking = array_key_exists('send_booking_notification_on_completion', $config)
            ? (bool) $config['send_booking_notification_on_completion']
            : (!$nextPhase);

        if ($shouldSendBooking) {
            $this->sendInterviewBookingNotification();
        }
    }

    /**
     * Manually advance applicant to next phase (for auto_advance=false phases).
     */
    public function advanceToNextPhase(): bool
    {
        $phase = $this->phase;
        $nextPhase = $phase?->nextPhase();

        if (!$nextPhase) {
            return false;
        }

        $this->rec_phase_id = $nextPhase->id;
        $this->auto_pilot_completed_at = null;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = null;
        $this->progress = 0;
        $this->clearExtraFieldDefinitionsCache();
        $this->save();

        RecAutoPilotLog::create([
            'rec_applicant_id' => $this->id,
            'type' => 'phase_advanced',
            'summary' => "Manuell weiter zu Phase \"{$nextPhase->name}\".",
        ]);

        $this->triggerPhaseCompletionHooks($phase);

        return true;
    }

    /**
     * Triggers configured side-effects that should fire when a phase completes,
     * based on the phase's completion_config.
     *
     * Currently supported flags:
     *  - confirm_booking_on_completion: bool
     *      If true, upgrades any active booking from 'booked' to 'registered'.
     *      (Status-Workflow seit Schritt 3 der finalen Phasen-Logik:
     *       booked → registered → confirmed. Phase-Hook macht den ersten
     *       Schritt; Reminder-Ja-Antwort macht den zweiten.)
     *      Use this on the last phase before the actual training (typically
     *      Onboarding) so that the slot is only marked registriert once the
     *      applicant has supplied all required data.
     *
     * Easy to extend with further flags later (notify_hr, set_hr_desk, ...).
     */
    protected function triggerPhaseCompletionHooks(?RecPhase $completedPhase): void
    {
        if (!$completedPhase) {
            return;
        }
        $config = $completedPhase->completion_config ?? [];

        // EU-Buerger-Sync: extra_field 'eu_burger' → rec_applicant_legal_statuses.is_eu_citizen.
        // Beim Abschluss jeder Phase neu evaluieren — typisch greift das nach
        // Phase 3 (Onboarding) wo der Bewerber EU-Buerger ja/nein angibt.
        // setEuCitizen() triggert intern HrDeskRoutingService::evaluateAndRoute
        // → bei is_eu_citizen=false landet der Bewerber automatisch auf dem
        // HR-Schreibtisch (Pflicht-Pruefung) und in der Schulungsnachbereitung
        // wird die Zeile rot markiert + Versand blockiert bis HR pruefen klickt.
        try {
            $this->syncEuCitizenFromExtraField();
        } catch (\Throwable $e) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type'             => 'eu_sync_failed',
                    'summary'          => "EU-Buerger-Sync fehlgeschlagen: " . $e->getMessage(),
                ]);
            } catch (\Throwable) {}
        }

        if (($config['confirm_booking_on_completion'] ?? false) === true) {
            $updated = $this->interviewBookings()
                ->where('status', 'booked')
                ->update(['status' => 'registered']);

            if ($updated > 0) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'booking_confirmed',
                        'summary' => "Schulungs-Buchung registriert durch Abschluss von Phase \"{$completedPhase->name}\".",
                    ]);
                } catch (\Throwable) {}
            }
        }

        // Bewerber → Mitarbeiter Konvertierung (opt-in pro Phase). Trigger
        // wird typischerweise auf Phase 4 (Schulung + Verträge versenden)
        // gesetzt. Production-Phasen ohne den Flag bleiben unberuehrt.
        // Service ist idempotent (mehrfaches Triggern erzeugt kein Duplikat).
        if (($config['creates_employee_on_completion'] ?? false) === true) {
            try {
                app(\Platform\Recruiting\Services\CreateEmployeeFromApplicantService::class)
                    ->createOrUpdate($this);
            } catch (\Throwable $e) {
                // Anlage-Fehler darf den Phase-Advance nicht hart blockieren —
                // HR sieht's im RecAutoPilotLog und kann manuell nachziehen.
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type'             => 'employee_create_failed',
                        'summary'          => "Mitarbeiter-Anlage fehlgeschlagen bei Phase \"{$completedPhase->name}\": " . $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
            }
        }
    }

    /**
     * Sync eu_burger-extra_field → rec_applicant_legal_statuses.is_eu_citizen.
     * Idempotent — schreibt nur wenn Wert sich aendert. Legt einen
     * legalStatus-Record an wenn noch keiner existiert. Ruft setEuCitizen()
     * auf, was intern den HrDeskRoutingService triggert.
     */
    protected function syncEuCitizenFromExtraField(): void
    {
        $raw = $this->getExtraField('eu_burger');
        if ($raw === null || $raw === '') {
            return;
        }
        $bool = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return;
        }
        $legalStatus = $this->legalStatus;
        if (!$legalStatus) {
            $legalStatus = $this->legalStatus()->create([
                'is_eu_citizen' => null,
                'team_id'       => $this->team_id,
            ]);
            $this->setRelation('legalStatus', $legalStatus);
        }
        if ($legalStatus->is_eu_citizen === $bool) {
            return;  // nichts zu tun — Wert ist schon synchron
        }
        $legalStatus->setEuCitizen($bool, null);
    }

    /**
     * Wird vom PublicExtraFieldForm-Renderer am Ende eines Form-Saves
     * aufgerufen. Recruiting-spezifische Schulungs-Bestaetigungsbox:
     * nach Abschluss der Phase mit confirm_booking_on_completion-Hook
     * (typisch P3 'Onboarding') bestaetigt diese Box dem Bewerber sein
     * Schulungs-Datum + verlinkt rheingedeck.de/schulungen.
     *
     * Bedingungen:
     *  - state === 'completed' (alle Pflichtfelder ausgefuellt)
     *  - Bewerber hat ein registered/confirmed/attended Booking
     *    (= eine Phase mit confirm_booking_on_completion=true wurde
     *    bereits abgeschlossen). Bei 'booked' (= nur gebucht, aber
     *    confirm-Hook noch nicht durch) wird die Box nicht gezeigt.
     */
    public function renderPublicFormCompletionExtras($state): ?string
    {
        if ($state !== 'completed') {
            return null;
        }
        $booking = $this->interviewBookings()
            ->whereIn('status', ['registered', 'confirmed', 'attended'])
            ->with('interview')
            ->latest('id')
            ->first();
        if (!$booking?->interview) {
            return null;
        }
        return view('recruiting::partials.public-form-completion', [
            'interview' => $booking->interview,
            'booking'   => $booking,
        ])->render();
    }

    /**
     * Send interview booking link via WhatsApp template (on AutoPilot completion).
     */
    public function sendInterviewBookingNotification(): bool
    {
        try {
            $this->loadMissing(['postings.position', 'crmContactLinks.contact.phoneNumbers']);

            // Resolve position settings → team settings cascade
            $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
            $positionSettings = $position?->auto_pilot_settings ?? [];
            $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

            $templateId = $positionSettings['interview_booking_wa_template_id']
                ?? $teamSettings->getSetting('interview_booking_wa_template_id');

            if (!$templateId) {
                return false;
            }

            if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                return false;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
            if (!$template || $template->status !== 'APPROVED') {
                return false;
            }

            // Resolve WA channel
            $waAccountId = $positionSettings['auto_pilot_wa_account_id']
                ?? $teamSettings->getSetting('auto_pilot_wa_account_id');

            if (!$waAccountId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
                return false;
            }

            $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($waAccountId);
            if (!$account || !$account->active) {
                return false;
            }

            $channel = \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            if (!$channel) {
                return false;
            }

            // Find primary phone number
            $phoneNumber = null;
            foreach ($this->crmContactLinks as $link) {
                $contact = $link->contact;
                if (!$contact) continue;

                $phoneNumber = $contact->phoneNumbers
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->whereNotNull('international')
                    ->first();

                if (!$phoneNumber) {
                    $phoneNumber = $contact->phoneNumbers
                        ->where('is_active', true)
                        ->whereNotNull('international')
                        ->first();
                }

                if ($phoneNumber) break;
            }

            if (!$phoneNumber) {
                return false;
            }

            // Build components
            $components = [];

            // Body parameters
            $bodyParams = [];
            foreach ($template->components ?? [] as $component) {
                if (($component['type'] ?? '') !== 'BODY') continue;

                $text = $component['text'] ?? '';
                $examplesByName = [];
                foreach ($component['example']['body_text_named_params'] ?? [] as $np) {
                    $examplesByName[$np['param_name']] = $np['example'] ?? '';
                }
                $positionalExamples = $component['example']['body_text'][0] ?? [];

                preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);
                foreach ($matches[1] as $i => $paramName) {
                    $bodyParams[] = [
                        'name' => $paramName,
                        'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                    ];
                }
            }

            if (!empty($bodyParams)) {
                $contactName = $this->getContact()?->full_name ?? 'Bewerber/in';
                $bodyParameters = [];
                foreach ($bodyParams as $param) {
                    $value = match (strtolower($param['name'])) {
                        '1', 'name', 'vorname' => $contactName,
                        default => $param['example'] ?: $contactName,
                    };
                    $paramEntry = ['type' => 'text', 'text' => $value];
                    if (!is_numeric($param['name'])) {
                        $paramEntry['parameter_name'] = $param['name'];
                    }
                    $bodyParameters[] = $paramEntry;
                }
                $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
            }

            // URL button with PublicFormLink-Token (kanonischer Bewerber-
            // Public-Token, gleiche Quelle wie /form/, /portal/, /contract/
            // und /recruiting/interviews/ — siehe InterviewBooking::mount).
            $hasUrlButton = collect($template->components ?? [])
                ->where('type', 'BUTTONS')
                ->flatMap(fn ($c) => $c['buttons'] ?? [])
                ->contains('type', 'URL');

            if ($hasUrlButton) {
                $formLinkToken = $this->getOrCreatePublicFormLink()->token;
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => 0,
                    'parameters' => [['type' => 'text', 'text' => $formLinkToken]],
                ];
            }

            // Send
            $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
            );

            // Link thread to applicant
            if ($thread = $message->thread ?? null) {
                $thread->addContext($this->getMorphClass(), $this->id, 'interview_booking');
            }

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'interview_booking_sent',
                'summary' => 'Interview-Buchungslink per WhatsApp gesendet.',
            ]);

            return true;
        } catch (\Throwable $e) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'error',
                    'summary' => 'Interview-Booking WA-Fehler: ' . $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            return false;
        }
    }

    /**
     * Schickt das WhatsApp-„Vertrags-Portal"-Template an den Bewerber.
     *
     * Nutzt das team-weite Setting `contract_wa_template_id` (+
     * `contract_wa_account_id`) aus RecApplicantSettings — gleiches Setting
     * das HR im UI nutzt wenn sie auf „Portal per WhatsApp senden" klicken.
     *
     * Wird von zwei Stellen aufgerufen:
     *  - Show::sendApplicantPortalViaWhatsApp() → manuelle HR-Aktion
     *  - SendContractsService::send() → automatisch nach Vertrags-Versand
     *    durch SL in der Schulungsnachbereitung
     *
     * Der Portal-Link wird als URL-Button-Parameter (= Token, nicht volle
     * URL) übergeben — Template enthält im URL eine `{{1}}`-Variable die
     * dynamisch durch den Token ersetzt wird.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function sendContractPortalNotification(): array
    {
        try {
            // Hinweis: auch wenn schon ein RecEmployee fuer diesen Bewerber
            // existiert, laeuft hier weiterhin der alte Applicant-Portal-
            // Pfad. Der explizite "MA-Portal aktivieren"-Button (separate
            // Iteration) ruft RecEmployee::sendPortalNotification mit
            // eigenem Template auf. Bis zur Verkabelung des neuen Buttons
            // bleibt der alte Link der einzige Comms-Pfad — ApplicantPortal
            // funktioniert weiterhin auch fuer konvertierte MAs.

            $this->loadMissing(['crmContactLinks.contact.phoneNumbers', 'contracts.contractTemplate']);

            $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);
            $templateId = $teamSettings->getSetting('contract_wa_template_id');
            $accountId = $teamSettings->getSetting('contract_wa_account_id');

            if (!$templateId) {
                return ['ok' => false, 'message' => 'Kein contract_wa_template_id-Setting konfiguriert.'];
            }

            if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                return ['ok' => false, 'message' => 'WhatsApp-Integrations-Modul nicht verfügbar.'];
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
            if (!$template || $template->status !== 'APPROVED') {
                return ['ok' => false, 'message' => 'Template nicht gefunden oder nicht genehmigt.'];
            }

            // Account-Fallback: wenn nicht gesetzt, vom Template ableiten
            if (!$accountId) {
                $accountId = $template->whatsapp_account_id;
            }

            if (!$accountId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
                return ['ok' => false, 'message' => 'Kein WhatsApp-Account konfiguriert.'];
            }

            $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId);
            if (!$account || !$account->active) {
                return ['ok' => false, 'message' => 'WhatsApp-Account nicht aktiv.'];
            }

            $channel = \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            if (!$channel) {
                return ['ok' => false, 'message' => 'Kein aktiver WhatsApp-Kanal für den Account.'];
            }

            $phoneNumber = null;
            foreach ($this->crmContactLinks as $link) {
                $contact = $link->contact;
                if (!$contact) continue;
                $phoneNumber = $contact->phoneNumbers
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->whereNotNull('international')
                    ->first();
                if (!$phoneNumber) {
                    $phoneNumber = $contact->phoneNumbers
                        ->where('is_active', true)
                        ->whereNotNull('international')
                        ->first();
                }
                if ($phoneNumber) break;
            }

            if (!$phoneNumber) {
                return ['ok' => false, 'message' => 'Keine Telefonnummer am CRM-Kontakt.'];
            }

            $portalLink = $this->getOrCreatePublicFormLink();
            $contactName = $this->getContact()?->full_name ?? 'Bewerber/in';

            $contractNames = $this->contracts
                ->filter(fn ($c) => in_array($c->status, ['sent', 'in_progress', 'pending']))
                ->map(fn ($c) => $c->contractTemplate?->name ?? 'Vertrag')
                ->implode(', ');
            if ($contractNames === '') {
                $contractNames = 'Ihre Verträge';
            }

            $variableValues = [
                'candidate_name' => $contactName,
                'name' => $contactName,
                'vorname' => $contactName,
                'portal_link' => route('recruiting.public.applicant-portal', ['token' => $portalLink->token]),
                'contract_names' => $contractNames,
            ];

            $variableMapping = $teamSettings->getSetting('contract_wa_template_variables', []);
            $autoMapDefaults = ['candidate_name', 'portal_link', 'contract_names'];

            $components = [];
            $bodyParams = [];
            foreach ($template->components ?? [] as $component) {
                if (($component['type'] ?? '') !== 'BODY') continue;
                $text = $component['text'] ?? '';
                $examplesByName = [];
                foreach ($component['example']['body_text_named_params'] ?? [] as $np) {
                    $examplesByName[$np['param_name']] = $np['example'] ?? '';
                }
                $positionalExamples = $component['example']['body_text'][0] ?? [];
                preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);
                foreach ($matches[1] as $i => $paramName) {
                    $bodyParams[] = [
                        'name' => $paramName,
                        'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                        'index' => $i,
                    ];
                }
            }

            if (!empty($bodyParams)) {
                $bodyParameters = [];
                foreach ($bodyParams as $param) {
                    $sourceKey = $variableMapping[$param['name']]
                        ?? ($autoMapDefaults[$param['index']] ?? null);
                    $value = $sourceKey ? ($variableValues[$sourceKey] ?? '') : '';
                    if ($value === '') {
                        // Fallback auf direkten Match nach Variable-Name
                        $value = $variableValues[strtolower($param['name'])] ?? $param['example'] ?? '';
                    }
                    $entry = ['type' => 'text', 'text' => (string) $value];
                    if (!is_numeric($param['name'])) {
                        $entry['parameter_name'] = $param['name'];
                    }
                    $bodyParameters[] = $entry;
                }
                $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
            }

            $hasUrlButton = collect($template->components ?? [])
                ->where('type', 'BUTTONS')
                ->flatMap(fn ($c) => $c['buttons'] ?? [])
                ->contains('type', 'URL');

            if ($hasUrlButton) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => 0,
                    'parameters' => [['type' => 'text', 'text' => $portalLink->token]],
                ];
            }

            $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
            );

            if ($thread = $message->thread ?? null) {
                $thread->addContext($this->getMorphClass(), $this->id, 'contract_portal_send');
            }

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'contract_portal_sent',
                'summary' => "Vertrags-Portal per WhatsApp an {$phoneNumber->international} gesendet.",
            ]);

            return ['ok' => true, 'message' => "An {$phoneNumber->international} gesendet."];
        } catch (\Throwable $e) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'error',
                    'summary' => 'Vertrags-Portal WA-Fehler: ' . $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * True wenn das Feld in der aktuellen Phase als required gilt — entweder
     * über das normale is_required-Flag oder über den Phase-Override
     * options.required_in_phase_orders (Array von Phase-Ordnungszahlen,
     * 1-basiert innerhalb der Position).
     *
     * Beispiel-Nutzung: ein Feld kann in Phase 3 (Onboarding) als optional
     * definiert werden (is_required=false) und gleichzeitig
     * options.required_in_phase_orders = [6] haben — dann wird's nur in der
     * Phase mit order=6 (= "Letzte Daten") als Pflichtfeld behandelt. Bewerber
     * gibt's einmal ein, der Wert lebt unter einer einzigen Definition, aber
     * der Phasen-Abschluss-Check zwingt zur Eingabe spätestens in Phase
     * order=6.
     *
     * Warum order und nicht id? Beim Duplizieren einer Position (Stellen-
     * Klon) werden neue Phase-Records mit neuen DB-IDs angelegt. Die
     * Phase-Order bleibt aber stabil (Phase 6 in Sandbox = Phase 6 in
     * Düsseldorf). Damit kann der options-JSON 1:1 mitgeklont werden ohne
     * dass IDs remapped werden müssen.
     *
     * Nimmt sowohl Eloquent-Modelle als auch Arrays/Objekte mit gleicher
     * Struktur entgegen — damit funktioniert's auch im Form-Loading-Pfad.
     *
     * @param mixed $def Field-Definition (Model | object | array)
     * @param RecPhase|null $currentPhase Phase des Bewerbers
     */
    public function isFieldRequiredInCurrentPhase($def, ?RecPhase $currentPhase): bool
    {
        $isRequired = is_array($def) ? ($def['is_required'] ?? false) : ($def->is_required ?? false);
        if ($isRequired) {
            return true;
        }

        if ($currentPhase === null) {
            return false;
        }

        $options = is_array($def) ? ($def['options'] ?? null) : ($def->options ?? null);
        $overrideOrders = $options['required_in_phase_orders'] ?? [];
        if (!is_array($overrideOrders) || empty($overrideOrders)) {
            return false;
        }

        return in_array((int) $currentPhase->order, array_map('intval', $overrideOrders), true);
    }

    public function calculateProgress(): int
    {
        $definitions = $this->getExtraFieldDefinitions();

        // Required-Felder nach effektivem Required-Status: entweder über das
        // is_required-Flag oder über den Phase-Override
        // options.required_in_phase_orders (= optional in früheren Phasen,
        // required in der aktuell-gewählten).
        $currentPhase = $this->phase;
        $requiredDefinitions = $definitions->filter(
            fn ($def) => $this->isFieldRequiredInCurrentPhase($def, $currentPhase)
        );

        if ($requiredDefinitions->isEmpty()) {
            return 100;
        }

        $values = $this->extraFieldValues()
            ->whereIn('definition_id', $requiredDefinitions->pluck('id'))
            ->get()
            ->keyBy('definition_id');

        // Visibility-aware: build current values map for visibility evaluator
        $valuesByName = [];
        foreach ($definitions as $def) {
            $val = $this->extraFieldValues->firstWhere('definition_id', $def->id);
            $valuesByName[$def->name] = $val?->value;
        }

        $evaluator = new \Platform\Core\Services\ExtraFieldConditionEvaluator();
        $relevant = 0;
        $filled = 0;

        foreach ($requiredDefinitions as $def) {
            $visibility = $def->visibility_config;
            $isVisible = !$visibility || !($visibility['enabled'] ?? false)
                || $evaluator->evaluate($visibility, $valuesByName);

            if (!$isVisible) {
                continue;  // unsichtbares Pflichtfeld zählt nicht
            }

            $relevant++;
            $val = $values->get($def->id);
            if ($val !== null && $val->value !== null && $val->value !== '' && $val->value !== '[]') {
                $filled++;
            }
        }

        if ($relevant === 0) {
            return 100;  // alle Pflichtfelder sind unsichtbar = nichts zu tun
        }

        return (int) round(($filled / $relevant) * 100);
    }

    /**
     * Determine if the applicant's current phase is complete.
     *
     * Reads the phase's completion_type setting:
     *  - 'fields' (default):       all visible required fields filled (calculateProgress >= 100)
     *  - 'booking':                a non-cancelled booking matches the optional completion_config
     *  - 'manual':                 never auto-complete; HR must advance explicitly
     *  - 'contract_sent':          at least one non-cancelled contract has sent_at set
     *  - 'all_contracts_signed':   all non-cancelled contracts have status='completed'
     */
    public function isPhaseComplete(?RecPhase $phase = null): bool
    {
        $phase = $phase ?? $this->phase;
        if (!$phase) {
            // No phase context: fall back to the legacy progress check
            return $this->calculateProgress() >= 100;
        }

        return match ($phase->completion_type) {
            'booking'              => $this->hasMatchingBooking($phase->completion_config),
            'manual'               => false,
            'contract_sent'        => $this->hasAnyContractSent(),
            'all_contracts_signed' => $this->allContractsSigned(),
            default                => $this->calculateProgress() >= 100,
        };
    }

    /**
     * True if the applicant has at least one non-cancelled contract with
     * sent_at set. Used by phases with completion_type='contract_sent' (typ.
     * "Schulung & Verträge versenden"-Phase: completes the moment the SL
     * triggers VertragVersenden).
     */
    public function hasAnyContractSent(): bool
    {
        return $this->contracts()
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('sent_at')
            ->exists();
    }

    /**
     * True when the applicant has at least one non-cancelled contract AND all
     * such contracts have status='completed' (= signed by the applicant).
     * Used by phases with completion_type='all_contracts_signed' (typ.
     * "Vertrag unterschreiben"-Phase).
     *
     * Empty-contracts case returns false on purpose — completing this phase
     * without ever having a contract makes no semantic sense.
     */
    public function allContractsSigned(): bool
    {
        $contracts = $this->contracts()
            ->whereNotIn('status', ['cancelled'])
            ->get(['status']);

        if ($contracts->isEmpty()) {
            return false;
        }

        return $contracts->every(fn ($c) => $c->status === 'completed');
    }

    /**
     * Returns the applicant's primary position — the earliest applied posting's
     * position. Used as the canonical "current Stelle" for the applicant.
     */
    public function primaryPosition(): ?RecPosition
    {
        return $this->postings
            ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
            ->first()
            ?->position;
    }

    /**
     * Switch the applicant to a new position: replaces the posting links with
     * a single new one in the target position, and remaps rec_phase_id to
     * the matching phase (same `order`) in the new position.
     *
     * Field-Werte werden NICHT umgehängt — sie bleiben unter den alten
     * Phase-Definition-IDs. HCM-Export greift via `name`-Join darauf zu.
     * In-App-UI sieht alte Werte nach Switch leer (siehe TODO 3.5a).
     */
    public function switchToPosition(RecPosition $newPosition): void
    {
        DB::transaction(function () use ($newPosition) {
            $currentOrder = $this->phase?->order;

            // 1. Alle bestehenden Posting-Verknüpfungen lösen
            $this->postings()->detach();

            // 2. Default-Posting der neuen Stelle anhängen
            $newPosting = $newPosition->postings()->where('is_active', true)->first();
            if (!$newPosting) {
                throw new \RuntimeException(
                    "Stelle '{$newPosition->title}' hat keine aktive Ausschreibung — Switch nicht möglich."
                );
            }
            $this->postings()->attach($newPosting->id, [
                'applied_at' => now()->toDateString(),
            ]);

            // 3. Phase auf neue Stelle mappen (gleicher order)
            if ($currentOrder !== null) {
                $newPhase = RecPhase::where('rec_position_id', $newPosition->id)
                    ->where('order', $currentOrder)
                    ->where('is_active', true)
                    ->first();
                if ($newPhase) {
                    $this->rec_phase_id = $newPhase->id;
                }
            }
            $this->save();

            // 4. Extra-Field-Werte vom alten Definitionen-Set auf das neue
            // umhaengen. Hintergrund: Definitionen sind position-spezifisch
            // (jede geklonte Stelle hat eigene Definition-IDs), Werte hängen
            // an definition_id. Ohne Remapping wirken bereits ausgefuellte
            // Felder beim Form-Render der neuen Position als leer und
            // muessen nochmal eingetragen werden.
            $this->remapExtraFieldValuesToPosition($newPosition);

            // 5. Audit-Log
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'position_switched',
                    'summary' => "Stelle gewechselt zu \"{$newPosition->title}\" durch Schulungs-Buchung.",
                ]);
            } catch (\Throwable) {}
        });
    }

    /**
     * Hängt alle Extra-Field-Werte vom alten Definitionen-Set (vorherige
     * Position) auf die Definitionen der neuen Position um, gematcht per
     * `name`. Behaelt Werte wenn:
     *  - alte Definition bereits zur neuen Position gehoert (kein Switch nötig)
     *  - es kein Equivalent in der neuen Position gibt (Wert wird "tot",
     *    bleibt aber in der DB für Audit/Restore-Zwecke)
     *
     * Bei Konflikten (Wert für gleiche Definition existiert bereits in der
     * neuen Position): alter Wert wird verworfen, neuer Wert behaelt
     * Vorrang (= juengeres Form-Submit gewinnt).
     */
    protected function remapExtraFieldValuesToPosition(RecPosition $newPosition): void
    {
        $newPhaseIds = $newPosition->phases()->pluck('id')->all();
        if (empty($newPhaseIds)) {
            return;
        }

        $newDefsByName = CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->whereIn('context_id', $newPhaseIds)
            ->get()
            ->keyBy('name');

        if ($newDefsByName->isEmpty()) {
            return;
        }

        $values = $this->extraFieldValues()->with('definition')->get();
        $valuesByDefId = $values->keyBy('definition_id');

        foreach ($values as $value) {
            $oldDef = $value->definition;
            if (!$oldDef || empty($oldDef->name)) {
                continue;
            }

            // Alte Definition gehoert bereits zur neuen Position — nichts zu tun
            if (in_array((int) $oldDef->context_id, array_map('intval', $newPhaseIds), true)) {
                continue;
            }

            $newDef = $newDefsByName->get($oldDef->name);
            if (!$newDef) {
                // Kein gleichnamiges Feld in neuer Position — Wert bleibt
                // unter alter definition_id liegen (nicht mehr sichtbar)
                continue;
            }

            // Konflikt: Bewerber hat schon einen Wert mit der neuen
            // definition_id (z.B. weil er nach Switch was eingegeben hat
            // bevor das Remapping lief). Alten Wert verwerfen.
            if ($valuesByDefId->has($newDef->id)) {
                $value->delete();
                continue;
            }

            $value->definition_id = $newDef->id;
            $value->save();
        }

        $this->clearExtraFieldDefinitionsCache();
    }

    /**
     * Check whether the applicant has at least one non-cancelled booking that
     * matches the optional completion_config (e.g. a specific interview type).
     *
     * @param array|null $config Optional config like ['interview_type_code' => 'training']
     */
    public function hasMatchingBooking(?array $config = null): bool
    {
        $query = $this->interviewBookings()
            ->whereNotIn('status', ['cancelled'])
            ->where('is_active', true);

        if ($config && !empty($config['interview_type_code'])) {
            $query->whereHas(
                'interview.interviewType',
                fn ($q) => $q->where('code', $config['interview_type_code'])
            );
        }

        return $query->exists();
    }

    /**
     * Returns the latest non-cancelled confirmed booking, eager-loaded with
     * its interview (slot) for date/time/location display.
     */
    public function confirmedBooking(): ?RecInterviewBooking
    {
        return $this->interviewBookings()
            ->with('interview')
            ->where('is_active', true)
            ->where('status', 'confirmed')
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the public schulung-URL for this applicant based on the primary
     * position's location. Falls back to the general overview page when the
     * location is unknown — never returns a 404 target.
     */
    public function getSchulungUrl(): string
    {
        $base = 'https://rheingedeck.de/schulung';
        $location = mb_strtolower($this->primaryPosition()?->location ?? '');

        $slug = match (true) {
            str_contains($location, 'düsseldorf')      => 'duesseldorf',
            str_contains($location, 'köln')            => 'koeln',
            str_contains($location, 'bonn')            => 'bonn',
            str_contains($location, 'mönchengladbach') => 'moenchengladbach',
            default                                    => null,
        };

        return $slug ? "{$base}/{$slug}" : $base;
    }
}
