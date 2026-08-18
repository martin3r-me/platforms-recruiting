<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
use Platform\Recruiting\Traits\HasApplicantContact;
use Platform\Recruiting\Traits\ResolvesPublicAddressStyle;
use Platform\Recruiting\Traits\UsesAccordionPublicForm;
use Platform\Hcm\Traits\SyncsCrmContactFields;
use Symfony\Component\Uid\UuidV7;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Services\TerminLabel;
use Platform\Recruiting\Support\PhaseTransitionTrigger;
use Platform\Recruiting\Support\SeatStandbyPolicy;

class RecApplicant extends Model implements InheritsExtraFields
{
    use HasApplicantContact;
    use HasExtraFields {
        getExtraFieldsWithLabels as private getExtraFieldsWithLabelsBase;
    }
    use HasPublicFormLink;
    use ResolvesPublicAddressStyle;
    use SyncsCrmContactFields;
    use UsesAccordionPublicForm;

    protected $table = 'rec_applicants';

    protected $fillable = [
        'uuid', 'public_token', 'rec_applicant_status_id', 'rec_phase_id', 'rec_position_id', 'progress', 'notes', 'applied_at',
        'is_active', 'is_parked', 'parked_at', 'is_on_hr_desk', 'rejected_at',
        'auto_pilot', 'auto_pilot_completed_at', 'auto_pilot_state_id',
        'auto_pilot_reminder_count', 'auto_pilot_last_reminder_at',
        'duplicate_of_applicant_id',
        'preferred_comms_channel_id', 'enrichment_status',
        'source_platform_id', 'is_unrouted',
        'contract_template_id',
        'zuschlag',
        'import_source',
        'export_changed_at',
        'is_test',
        'suggested_posting_id',
        'match_reason',
        'team_id', 'created_by_user_id', 'owned_by_user_id',
        // Bewertung am Bewerber (Spec §1) — wandert bei der MA-Anlage auf hrData.
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        'evaluation_note',
        'linen_package_items',
        'qualifications',
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
        'zuschlag' => 'decimal:2',
        'is_test' => 'boolean',
        'rating_erscheinungsbild' => 'integer',
        'rating_fachkompetenz'    => 'integer',
        'rating_auffassungsgabe'  => 'integer',
        'rating_auftreten'        => 'integer',
        'rating_teamintegration'  => 'integer',
        'linen_package_items'     => 'array',
        'qualifications'          => 'array',
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
            ->withPivot(['applied_at', 'notes', 'matched_via', 'match_confidence'])
            ->withTimestamps();
    }

    public function positions(): Collection
    {
        return $this->postings->map(fn ($posting) => $posting->position)->filter()->unique('id')->values();
    }

    /**
     * DIE Stelle der Bewerbung — wo die Person bearbeitet wird.
     *
     * Nicht verwechseln mit positions(): das liefert die Stellen der verknuepften
     * ANZEIGEN, also woher die Bewerbung kam. Beides war bis hierher dasselbe Feld,
     * und genau daran hat sich der Stellenwechsel die KPI-Zahlen verdorben.
     */
    public function position()
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    /**
     * Hat sich die Person auf einen Schulungsort festgelegt?
     *
     * Die Regel war bisher an drei Stellen abgeleitet (u. a.
     * InterviewBooking::resolvePositionIdsForApplicant) — hier steht sie einmal.
     * Eine STORNIERTE Buchung zaehlt nicht: wer storniert, waehlt neu und soll
     * wieder die Termine aller Wunschorte sehen.
     */
    public function istFestgelegt(): bool
    {
        if (($this->phase?->order ?? 0) >= 3) {
            return true;
        }

        return $this->interviewBookings()
            ->whereNotIn('status', ['cancelled'])
            ->exists();
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

    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_applicant_id');
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

    public function suggestedPosting()
    {
        return $this->belongsTo(RecPosting::class, 'suggested_posting_id');
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

    /**
     * True wenn der Bewerber rechtsstatus-pruefung-pflichtig ist (nicht-EU
     * oder unbeantwortet) und HR ihn noch NICHT geprueft hat. Blockiert
     * Vertrags-/Portal-Versand UND Schulungs-Reminder. Delegiert an den
     * zentralen LegalStatusGate, damit alle Call-Sites dieselbe Regel teilen.
     */
    public function isLegalStatusUnchecked(): bool
    {
        $legal = $this->legalStatus;

        return \Platform\Recruiting\Services\LegalStatusGate::isUnchecked(
            hasLegalStatus: $legal !== null,
            isEuCitizen: $legal?->is_eu_citizen,
            isChecked: $legal?->legal_status_checked_at !== null,
        );
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
        // Direkteinstellung & Co.: Bewerber mit AutoPilot=OFF durchlaufen den
        // regulaeren AutoPilot-Phase-Advance NICHT. Trotzdem muss der Phasen-
        // Abschluss-Hook (insb. creates_employee_on_completion) feuern, wenn der
        // Bewerber seine Daten ueber das oeffentliche Portal vollstaendig
        // ausfuellt. Der Core-PublicExtraFieldForm ruft checkAutoPilotCompletion()
        // nach jedem Save auf — wir haengen uns hier in genau diese Kette ein,
        // ohne Phase zu advancen oder AutoPilot-Logs zu schreiben.
        if (!$this->auto_pilot) {
            if ($this->rec_phase_id && $this->isPhaseComplete()) {
                if ($this->guardSeatReclaim($this->phase) !== self::RECLAIM_GUARD_OK) {
                    return; // HR-Fall geloggt — keine Hooks, kein Phantom-Upgrade
                }
                $this->triggerPhaseCompletionHooks($this->phase);
            }
            return;
        }

        if ($this->auto_pilot_completed_at !== null) {
            return;
        }

        // completion_type-aware: respects the phase's setting (fields/booking/manual)
        if (!$this->isPhaseComplete()) {
            return;
        }

        // Standby-Re-Claim MUSS vor dem Advance passieren — bei Fehlschlag
        // wurde der Bewerber bereits zurueck in die Buchen-Phase gesetzt.
        if ($this->guardSeatReclaim($this->phase) !== self::RECLAIM_GUARD_OK) {
            return;
        }

        // Jugendschutz-Gate: kein Phasen-Aufstieg für Minderjährige ohne
        // HR-Freigabe. Greift beim ersten Aufstieg nach P1 (geburtsdatum ist
        // dort Pflichtfeld): <16 → Auto-Absage, 16-17 → HR-Schreibtisch.
        if (!$this->guardMinorAge()) {
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
            PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::AUTO_ADVANCE);
            try {
                $this->save();
            } finally {
                PhaseTransitionTrigger::forget($this->id);
            }

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
     * Jugendschutz-Gate. True = Automatik darf weiterlaufen.
     *
     *  - pass/unknown → true (unknown blockt nur Hooks/Verträge nicht den
     *    P1-Aufstieg — geburtsdatum ist dort Pflichtfeld, Altbestand ohne
     *    Datum darf nicht einfrieren)
     *  - review (16-17) → HR-Schreibtisch-Fall (idempotent), false bis ein
     *    Fall freigegeben ist; abgelehnter Fall bleibt false ohne neuen Fall
     *  - reject (<16) → Auto-Absage (Template + Status aus den Bewerber-
     *    Einstellungen, Buchungen storniert, deaktiviert); solange Template/
     *    Status nicht konfiguriert sind: Schreibtisch statt stiller Absage
     */
    public function guardMinorAge(bool $forHooks = false): bool
    {
        $verdict = \Platform\Recruiting\Support\MinorAgeGate::verdict(
            $this->getExtraField('geburtsdatum'),
            new \DateTimeImmutable('today'),
        );

        if ($verdict === \Platform\Recruiting\Support\MinorAgeGate::VERDICT_PASS) {
            // Datum korrigiert (z.B. Tippfehler 2010→2000): offene Minor-Fälle
            // automatisch schließen, sonst hängt der Bewerber ewig am Desk.
            app(\Platform\Recruiting\Services\HrDeskRoutingService::class)->autoCloseObsoleteCases(
                $this,
                RecHrDeskCase::REASON_MINOR,
                'Automatisch geschlossen: Geburtsdatum weist Volljährigkeit aus.',
            );
            return true;
        }

        $birthDateRaw = $this->getExtraField('geburtsdatum');
        $birthDate = is_scalar($birthDateRaw) ? (string) $birthDateRaw : 'unbekannt';

        // Kein plausibles Geburtsdatum: Phasen-Aufstieg/Datensammlung laufen
        // weiter (Altbestand darf nicht einfrieren), aber Vertragsversand/
        // MA-Anlage (Hooks) brauchen ein geklärtes Datum oder HR-Freigabe.
        if ($verdict === \Platform\Recruiting\Support\MinorAgeGate::VERDICT_UNKNOWN && !$forHooks) {
            return true;
        }

        $latestCase = RecHrDeskCase::where('rec_applicant_id', $this->id)
            ->where('reason', RecHrDeskCase::REASON_MINOR)
            ->orderByDesc('id')
            ->first();

        if ($verdict !== \Platform\Recruiting\Support\MinorAgeGate::VERDICT_REJECT) {
            // REVIEW (16–17) oder UNKNOWN-vor-Hooks: HR-Freigabe entsperrt.
            if ($latestCase?->status === RecHrDeskCase::STATUS_APPROVED) {
                return true;
            }
            if ($latestCase?->status === RecHrDeskCase::STATUS_REJECTED) {
                return false;
            }

            $note = $verdict === \Platform\Recruiting\Support\MinorAgeGate::VERDICT_REVIEW
                ? "Minderjährig (16–17, geb. {$birthDate}): Jugendschutz/Einverständnis prüfen. Freigabe schaltet den Phasen-Aufstieg frei."
                : "Geburtsdatum fehlt oder ist unplausibel ({$birthDate}) — Vertragsversand/MA-Anlage blockiert. Bitte Datum klären; Freigabe schaltet frei.";
            app(\Platform\Recruiting\Services\HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
                $this,
                RecHrDeskCase::REASON_MINOR,
                null,
                $note,
            );
            return false;
        }

        // VERDICT_REJECT (<16): zwingende Auto-Absage. Eine HR-Freigabe
        // entsperrt hier bewusst NICHT (Kundenregel: unter 16 immer absagen) —
        // sie verhindert nur nichts, weil dieser Zweig sie ignoriert.
        if (!$this->is_active) {
            return false; // bereits abgesagt/deaktiviert — nichts mehr tun
        }
        if ($latestCase && $latestCase->isOpen()) {
            return false; // Fall liegt bei HR (z.B. Versand fehlgeschlagen) — kein Auto-Retry-Spam
        }
        if ($latestCase?->status === RecHrDeskCase::STATUS_REJECTED) {
            return false; // HR hat bereits manuell abgelehnt
        }

        // Eine Quelle für "welcher Status" — dieselbe nutzt die HR-Desk-Ablehnung.
        $statusId = RecApplicantSettings::getOrCreateForTeam($this->team_id)->minorRejectionStatusId();
        $templateOk = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
            ->configuredTemplateName($this->team_id, 'minor_rejection_template_id') !== null;
        $phone = $this->primaryContactPhone();

        if ($statusId === null || !$templateOk || $phone === null) {
            $missing = $statusId === null ? 'Absage-Status' : (!$templateOk ? 'Absage-Template' : 'Telefonnummer');
            app(\Platform\Recruiting\Services\HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
                $this,
                RecHrDeskCase::REASON_MINOR,
                null,
                "Unter 16 (geb. {$birthDate}) — Auto-Absage nicht möglich ({$missing} fehlt). Bitte manuell absagen bzw. Bewerber-Einstellungen vervollständigen.",
            );
            return false;
        }

        $this->executeMinorRejection($statusId, $phone, $birthDate);
        return false;
    }

    /**
     * Auto-Absage <16: idempotent (genau eine Absage-Nachricht), storniert
     * offene Buchungen, setzt Status + deaktiviert. is_active=false stoppt
     * AutoPilot & Erinnerungen zuverlässig (auto_pilot=false würde der
     * saving-Guard bei progress<100 zurückdrehen).
     */
    private function executeMinorRejection(int $statusId, string $phone, string $birthDate): void
    {
        $firstName = trim((string) ($this->getExtraField('vorname')
            ?? $this->crmContactLinks->first()?->contact?->first_name ?? ''));

        // Send-first: die Absage-Nachricht MUSS raus sein, bevor irgendetwas
        // deaktiviert wird — sonst entsteht genau die stille Absage, die das
        // Feature verspricht auszuschließen. Fehlversand → Fall an HR, der
        // offene Fall stoppt weitere Auto-Versuche (guardMinorAge).
        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
            ->sendOne($this->team_id, $phone, $firstName, 'minor_rejection_template_id');

        if (($result['sent'] ?? 0) < 1) {
            app(\Platform\Recruiting\Services\HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
                $this,
                RecHrDeskCase::REASON_MINOR,
                null,
                "Unter 16 (geb. {$birthDate}) — Absage-Nachricht konnte nicht versendet werden ("
                    . ($result['error'] ?? 'Sendefehler') . "). Bitte manuell absagen.",
            );
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'minor_rejection_send_failed',
                    'summary' => 'Auto-Absage (unter 16) abgebrochen — Template-Versand fehlgeschlagen, Fall an HR übergeben.',
                    'details' => ['template_result' => $result],
                ]);
            } catch (\Throwable) {}
            return;
        }

        // Idempotenz trägt is_active=false (guardMinorAge prüft das zuerst) —
        // das Log ist reines Audit und darf scheitern.
        $this->rec_applicant_status_id = $statusId;
        $this->rejected_at = now();
        $this->is_active = false;
        $this->save();

        $openBookings = $this->interviewBookings()->whereNotIn('status', ['cancelled'])->get();
        foreach ($openBookings as $booking) {
            $booking->status = 'cancelled';
            $booking->cancelled_by = 'system';
            $booking->cancelled_at = now();
            $booking->save();
        }

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'minor_rejected',
                'summary' => "Auto-Absage: Bewerber unter 16 (geb. {$birthDate}). Absage-Template versendet, "
                    . $openBookings->count() . ' Buchung(en) storniert, Bewerber deaktiviert.',
                'details' => [
                    'birth_date' => $birthDate,
                    'template_result' => $result,
                    'cancelled_bookings' => $openBookings->pluck('id')->all(),
                ],
            ]);
        } catch (\Throwable) {}
    }

    /** Erste aktive Telefonnummer der verknüpften Kontakte (international bevorzugt). */
    public function primaryContactPhone(): ?string
    {
        $this->loadMissing('crmContactLinks.contact.phoneNumbers');
        foreach ($this->crmContactLinks as $link) {
            foreach ($link->contact?->phoneNumbers ?? [] as $phoneNumber) {
                if (!$phoneNumber->is_active) {
                    continue;
                }
                $value = $phoneNumber->international ?: $phoneNumber->raw_input;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
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
        PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::MANUAL);
        try {
            $this->save();
        } finally {
            PhaseTransitionTrigger::forget($this->id);
        }

        RecAutoPilotLog::create([
            'rec_applicant_id' => $this->id,
            'type' => 'phase_advanced',
            'summary' => "Manuell weiter zu Phase \"{$nextPhase->name}\".",
        ]);

        $this->triggerPhaseCompletionHooks($phase);

        return true;
    }

    /**
     * Expliziter Ruecksprung in die Termin-Buchen-Phase — einziger Pfad,
     * der rueckwaerts durch die Phasen geht (fehlgeschlagener Standby-
     * Re-Claim: Termin ist inzwischen voll/vergangen).
     *
     * Spiegelt die Advance-Reset-Semantik (checkAutoPilotCompletion) PLUS
     * auto_pilot_state_id = null: der Bewerber steht in diesem Moment auf
     * review_needed, und die Auto-Pilot-Query schliesst review_needed aus —
     * ohne State-Reset bekaeme er nie das Termin-Template.
     */
    public function returnToBookingPhase(): bool
    {
        $current = $this->phase;
        if (!$current) {
            return false;
        }

        $target = RecPhase::where('rec_position_id', $current->rec_position_id)
            ->where('is_active', true)
            ->where('completion_type', 'booking')
            ->where('order', '<', $current->order)
            ->orderByDesc('order')
            ->first();

        if (!$target) {
            return false;
        }

        $this->rec_phase_id = $target->id;
        $this->auto_pilot_completed_at = null;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = null;
        $this->auto_pilot_state_id = null;
        $this->progress = 0;
        $this->clearExtraFieldDefinitionsCache();
        PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::RETURNED);
        try {
            $this->save();
        } finally {
            PhaseTransitionTrigger::forget($this->id);
        }

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'phase_returned',
                'summary' => "Zurück zu Phase \"{$target->name}\" — Schulungsplatz war nicht mehr verfügbar.",
                'details' => ['from_phase_id' => $current->id, 'to_phase_id' => $target->id],
            ]);
        } catch (\Throwable) {}

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

        // EU-Bürger-Sync VOR dem Jugendschutz-Backstop: reiner Daten-Sync,
        // der auch für blockierte Minderjährige laufen muss — sonst bleibt
        // is_eu_citizen null und die Non-EU-Compliance-Prüfung greift nie.
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

        // Jugendschutz-Backstop: Phase-Abschluss-Hooks (Vertragsversand,
        // MA-Anlage, Buchungs-Bestätigung) laufen NIE für Minderjährige ohne
        // freigegebenen HR-Fall oder ohne plausibles Geburtsdatum — auch nicht
        // auf Pfaden am AutoPilot vorbei (Direkteinstellung, manueller Advance).
        if (!$this->guardMinorAge(forHooks: true)) {
            try {
                // Ein Log pro Bewerber reicht — der Hook feuert bei jedem
                // Public-Form-Save erneut, solange die Freigabe aussteht.
                $alreadyLogged = RecAutoPilotLog::where('rec_applicant_id', $this->id)
                    ->where('type', 'minor_hooks_blocked')
                    ->exists();
                if (!$alreadyLogged) {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'minor_hooks_blocked',
                        'summary' => "Phase-Abschluss-Hooks von \"{$completedPhase->name}\" blockiert — Bewerber minderjährig, HR-Freigabe fehlt.",
                    ]);
                }
            } catch (\Throwable) {}
            return;
        }

        $config = $completedPhase->completion_config ?? [];

        if (($config['confirm_booking_on_completion'] ?? false) === true) {
            // Per Model-Save statt Bulk-Update: Observer (Re-Arm bei "wieder
            // voll") sehen das Upgrade, und der saving-Guard raeumt
            // seat_released_at ab (Invariante, deckt auch den manuellen
            // HR-Advance via advanceToNextPhase ab = bewusste Uebersteuerung).
            $bookings = $this->interviewBookings()->where('status', 'booked')->get();
            foreach ($bookings as $booking) {
                // Vor dem Save festhalten — der saving-Guard loescht den Marker
                // beim Upgrade. Im regulaeren Auto-Pilot-Pfad hat der Guard den
                // Marker bereits im Lock geloescht (is_standby=false) — dieses
                // Log feuert also exakt nur auf dem ungeguardeten manuellen
                // HR-Advance (advanceToNextPhase) und macht den Belegungspfad
                // fuer die Rueckhol-Quote sichtbar.
                $wasStandby = $booking->is_standby;

                $booking->status = 'registered';
                $booking->save();

                if ($wasStandby) {
                    try {
                        RecAutoPilotLog::create([
                            'rec_applicant_id' => $this->id,
                            'type' => 'seat_reclaimed_override',
                            'summary' => "Standby-Buchung #{$booking->id} durch Phasen-Abschluss auf 'registered' gehoben — Platz bewusst konsumiert (HR-Advance).",
                            'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'status' => 'registered'],
                        ]);
                    } catch (\Throwable) {}
                }
            }

            if ($bookings->isNotEmpty()) {
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

    public const RECLAIM_GUARD_OK = 'ok';
    public const RECLAIM_GUARD_RETURNED = 'returned';
    public const RECLAIM_GUARD_HR_CASE = 'hr_case';

    /**
     * Pre-Advance-Guard: Standby-Buchungen muessen ihren Platz zurueckholen,
     * BEVOR die Phase advanced (der Hook selbst feuert erst nach dem
     * persistierten Advance und kann nichts mehr verhindern).
     *
     * Ergebnis:
     *  - OK:       kein Standby oder Platz erfolgreich re-claimt → Advance normal
     *  - RETURNED: Termin voll/vergangen, Buchung storniert, Bewerber zurueck
     *              in der Buchen-Phase → Aufrufer bricht ab (kein Advance)
     *  - HR_CASE:  wie RETURNED, aber Auto-Pilot ist aus (Direkteinstellung) —
     *              Buchung bleibt Standby, HR entscheidet (Log reclaim_failed)
     */
    protected function guardSeatReclaim(?RecPhase $phase): string
    {
        $config = $phase?->completion_config ?? [];
        if (($config['confirm_booking_on_completion'] ?? false) !== true) {
            return self::RECLAIM_GUARD_OK;
        }

        $standbyBookings = $this->interviewBookings()
            ->where('status', 'booked')
            ->whereNotNull('seat_released_at')
            ->get();

        if ($standbyBookings->isEmpty()) {
            return self::RECLAIM_GUARD_OK;
        }

        $failedBookings = [];
        foreach ($standbyBookings as $booking) {
            $outcome = DB::transaction(function () use ($booking) {
                // Zeilensperre auf dem Termin — serialisiert gegen parallele
                // Buchungen (Task 4) und andere Re-Claims.
                // Kein Off-by-one: die eigene Standby-Buchung hat hier noch
                // seat_released_at != null und ist damit NICHT in
                // takenSeatsCount() enthalten (seatTaking = whereNull) —
                // bei taken == max-1 gelingt der Re-Claim korrekt.
                $interview = RecInterview::query()->lockForUpdate()->find($booking->rec_interview_id);

                $result = SeatStandbyPolicy::reclaimOutcome(
                    true,
                    $interview ? $interview->takenSeatsCount() : 0,
                    $interview?->max_participants,
                    (bool) $interview?->starts_at?->isFuture(),
                );

                if ($result === SeatStandbyPolicy::RECLAIM_OK) {
                    // Platz sofort IM Lock konsumieren — das Status-Upgrade
                    // auf 'registered' macht danach der Phase-Hook.
                    $booking->seat_released_at = null;
                    $booking->save();
                }

                return $result;
            });

            if ($outcome === SeatStandbyPolicy::RECLAIM_OK) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'seat_reclaimed',
                        'summary' => "Standby-Platz zurückgeholt (Buchung #{$booking->id}) — Onboarding abgeschlossen.",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id],
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            $failedBookings[] = $booking;
        }

        if ($failedBookings === []) {
            return self::RECLAIM_GUARD_OK;
        }

        // Teilerfolg (Multi-Standby ist via Tool-Zweitbuchung/HR-Status-Revival
        // erreichbar): mindestens ein Platz ist gesichert — der Bewerber wird
        // NICHT zurueckgeworfen. Gescheiterte Geschwister-Buchungen werden in
        // BEIDEN Auto-Pilot-Modi storniert, sonst wuerde der Hook den
        // Standby-Rest kapazitaetsfrei auf 'registered' heben.
        if (count($failedBookings) < $standbyBookings->count()) {
            foreach ($failedBookings as $booking) {
                $booking->status = 'cancelled';
                $booking->cancelled_by = 'system';
                $booking->cancelled_at = now();
                $booking->save();

                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'reclaim_failed',
                        'summary' => "Termin voll/vergangen (Buchung #{$booking->id}) — storniert, anderer Standby-Platz erfolgreich zurückgeholt.",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'sibling_cancelled'],
                    ]);
                } catch (\Throwable) {}
            }
            return self::RECLAIM_GUARD_OK;
        }

        if (!$this->auto_pilot) {
            // Direkteinstellung & Co.: keine Auto-Pilot-Kommunikation moeglich.
            // Buchung bleibt Standby, HR entscheidet (ueberbuchen/umbuchen).
            foreach ($failedBookings as $booking) {
                // Idempotenz: checkAutoPilotCompletion feuert bei JEDEM
                // Public-Form-Save erneut, und die Standby-Buchung bleibt
                // hier bestehen — ohne Guard entsteht pro Save ein neues
                // reclaim_failed-Log. Nur EIN Log pro Release-Fenster
                // (seit seat_released_at).
                $alreadyLogged = RecAutoPilotLog::where('rec_applicant_id', $this->id)
                    ->where('type', 'reclaim_failed')
                    ->when($booking->seat_released_at, fn ($q) => $q->where('created_at', '>=', $booking->seat_released_at))
                    ->exists();
                if ($alreadyLogged) {
                    continue;
                }
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'reclaim_failed',
                        'summary' => "Termin inzwischen voll/vergangen (Buchung #{$booking->id}) — HR-Entscheidung nötig (Auto-Pilot aus).",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'hr_case'],
                    ]);
                } catch (\Throwable) {}
            }
            return self::RECLAIM_GUARD_HR_CASE;
        }

        // Auto-Pilot-Flow: Buchung stornieren (Observer bietet den Platz ggf.
        // der Warteliste an — no-op bei vollem Termin) + Ruecksprung.
        foreach ($failedBookings as $booking) {
            $booking->status = 'cancelled';
            $booking->cancelled_by = 'system';
            $booking->cancelled_at = now();
            $booking->save(); // saving-Guard raeumt seat_released_at mit ab

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'reclaim_failed',
                    'summary' => "Termin inzwischen voll/vergangen (Buchung #{$booking->id}) — zurück zur Terminwahl.",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'returned'],
                ]);
            } catch (\Throwable) {}
        }

        return $this->returnToBookingPhase()
            ? self::RECLAIM_GUARD_RETURNED
            : self::RECLAIM_GUARD_HR_CASE;
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
            // Fehlgeschlagener Standby-Re-Claim: Bewerber wurde zurueck in die
            // Buchen-Phase gesetzt (Buchung storniert). Statt stillem null eine
            // Erklaerung + Link zur Terminauswahl rendern.
            $this->loadMissing('phase');
            $hasActiveBooking = $this->interviewBookings()
                ->whereNotIn('status', ['cancelled'])
                ->exists();
            // Nur der Re-Claim-Guard und der Drop-out-Observer stornieren mit
            // cancelled_by='system' — Bewerber ohne jede Buchungshistorie
            // (frisch in der Buchen-Phase) kriegen den Hinweis nicht.
            $hadSystemCancelledBooking = $this->interviewBookings()
                ->where('status', 'cancelled')
                ->where('cancelled_by', 'system')
                ->exists();

            if (\Platform\Recruiting\Support\SeatStandbyPolicy::shouldShowSeatLostNotice(
                $this->phase?->completion_type === 'booking',
                $hasActiveBooking,
                $hadSystemCancelledBooking,
            )) {
                $url = url('/recruiting/interviews/' . $this->public_token);
                $text = $this->usesInformalAddress()
                    ? 'Danke, deine Angaben sind vollständig! Dein ursprünglicher Schulungstermin ist leider inzwischen voll geworden — bitte wähle einen neuen Termin.'
                    : 'Danke, Ihre Angaben sind vollständig! Ihr ursprünglicher Schulungstermin ist leider inzwischen voll geworden — bitte wählen Sie einen neuen Termin.';

                // Markup an die Bestaetigungsbox in
                // resources/views/partials/public-form-completion.blade.php
                // angelehnt (rounded-xl/border/p-6/text-center-Container,
                // Heading- und CTA-Button-Klassen), Farbton amber statt
                // emerald. Kein @svg()-Icon: diese Methode liefert reines
                // HTML als String (kein Blade-Kontext) und die Task
                // beschraenkt die Aenderung auf RecApplicant.php.
                return '<div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">'
                    . '<h3 class="text-lg font-semibold text-amber-900 mb-2">' . e($text) . '</h3>'
                    . '<a href="' . e($url) . '" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-md hover:bg-amber-700 transition-colors">'
                    . 'Neuen Termin wählen'
                    . '</a>'
                    . '</div>';
            }

            return null;
        }
        $duzen = $this->usesInformalAddress();
        $wort = \Platform\Recruiting\Support\TerminWort::from($booking->interview->interviewType);

        return view('recruiting::partials.public-form-completion', [
            'interview'      => $booking->interview,
            'booking'        => $booking,
            'duzen'          => $duzen,
            'bestaetigtSatz' => ucfirst($wort->possessiv($duzen)) . ' ist bestätigt!',
        ])->render();
    }

    /**
     * Send interview booking link via WhatsApp template (on AutoPilot completion).
     */
    public function sendInterviewBookingNotification(): bool
    {
        return $this->sendBookingLinkWhatsApp(
            'interview_booking_wa_template_id',
            'interview_booking_sent',
            'Interview-Buchungslink per WhatsApp gesendet.'
        );
    }

    /**
     * Schickt den Buchungslink erneut, wenn ein Schulungstermin frei
     * geworden ist (Warteliste). Nutzt ein eigenes Template
     * (interview_waitlist_wa_template_id) mit anderem Wording, aber
     * denselben Link-Token wie der reguläre Buchungs-Versand.
     */
    public function sendWaitlistAvailableNotification(): bool
    {
        return $this->sendBookingLinkWhatsApp(
            'interview_waitlist_wa_template_id',
            'waitlist_slot_available_sent',
            'Warteliste: Benachrichtigung "Termin frei geworden" per WhatsApp gesendet.'
        );
    }

    /**
     * Termin-Warteliste (Dauerabo): "In deinem Termin ist ein Platz frei
     * geworden" — mit {{termin}}-Variable ("Samstag, 25. Juli 2026 um
     * 15:00 Uhr"). Fallback aufs generische Ort-Template, solange das
     * Termin-Template nicht konfiguriert/approved ist oder der Versand
     * damit fehlschlägt — so bleibt das Feature vor dem Meta-Approval
     * funktionsfähig.
     */
    public function sendTerminWaitlistNotification(RecInterview $interview): bool
    {
        $terminLabel = TerminLabel::format($interview->starts_at);

        $sent = $this->sendBookingLinkWhatsApp(
            'interview_waitlist_termin_wa_template_id',
            'waitlist_termin_sent',
            'Termin-Warteliste: Benachrichtigung "Platz im Termin frei" per WhatsApp gesendet.',
            'interview_booking',
            [
                'termin' => $terminLabel,
                // Positional-Fallback, falls das Meta-Template {{2}} statt
                // {{termin}} nutzt ({{1}} ist konventionell der Name).
                '2'      => $terminLabel,
            ]
        );

        return $sent ?: $this->sendWaitlistAvailableNotification();
    }

    /**
     * Versand-Kern für den Buchungslink: löst das Template (per Settings-Key,
     * Position→Team-Kaskade) und den WA-Account auf, baut Body- und
     * URL-Button-Parameter und sendet das Template an die primäre
     * Telefonnummer des Bewerbers. Wird von sendInterviewBookingNotification
     * und sendWaitlistAvailableNotification mit unterschiedlichen
     * Template-Keys/Log-Typen wiederverwendet.
     */
    private function sendBookingLinkWhatsApp(string $templateSettingKey, string $logType, string $logSummary, string $contextPurpose = 'interview_booking', array $bodyValues = []): bool
    {
        try {
            $this->loadMissing(['postings.position', 'crmContactLinks.contact.phoneNumbers']);

            // Resolve position settings → team settings cascade
            $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
            $positionSettings = $position?->auto_pilot_settings ?? [];
            $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

            $templateId = $positionSettings[$templateSettingKey]
                ?? $teamSettings->getSetting($templateSettingKey);

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
                $contactName = $this->getContact()?->first_name ?? 'Bewerber/in';
                $bodyParameters = [];
                foreach ($bodyParams as $param) {
                    // Explizit übergebene Werte (z.B. {{termin}}) gewinnen
                    // über die Default-Auflösung (Name/Beispielwert).
                    $paramKey = strtolower($param['name']);
                    $value = $bodyValues[$paramKey] ?? match ($paramKey) {
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

            // Ab hier ist die WhatsApp RAUS — Buchhaltungs-Fehler (Log,
            // Thread-Link) dürfen den erfolgreichen Versand NIEMALS als
            // false zurückmelden. Sonst passieren zwei Dinge: (1) der
            // Termin-Fallback schickt zusätzlich das generische Template
            // (Doppel-WhatsApp, live passiert am 15.07. durch zu langen
            // Log-Typ vs. type-Spalte varchar(30)), und (2) der Notify-Job
            // rollt seinen armed-Claim zurück, obwohl die Nachricht ankam
            // (= verzögerte Doppel-WA nach Ablauf des Cooldowns).
            // Muster wie in cancelSchulung(): Log-Fehler dürfen die
            // Aktion nicht blockieren.
            try {
                // Link thread to applicant
                if ($thread = $message->thread ?? null) {
                    $thread->addContext($this->getMorphClass(), $this->id, $contextPurpose);
                }

                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => $logType,
                    'summary' => $logSummary,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[RecApplicant] Versand-Buchhaltung fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), [
                    'applicant_id' => $this->id,
                    'log_type'     => $logType,
                ]);
            }

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
     * Schickt das allgemeine „Re-Open"-/Holding-Template an den Bewerber, um
     * ein geschlossenes WhatsApp 24h-Fenster wieder zu öffnen: Antwortet die
     * Person, wird das Fenster reaktiviert und freie Nachrichten sind möglich.
     *
     * Nutzt das team-weite Setting `comms_holding_template_id` (+
     * `auto_pilot_wa_account_id` für den Kanal). Wiederverwendet die komplette
     * Channel-/Phone-/Component-Auflösung von sendBookingLinkWhatsApp.
     */
    public function sendHoldingWhatsApp(): bool
    {
        return $this->sendBookingLinkWhatsApp(
            'comms_holding_template_id',
            'holding',
            'Holding/Re-Open-Template gesendet',
            'comms_holding',
        );
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
            $contactName = $this->getContact()?->first_name ?? 'Bewerber/in';

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
        // Praedikat als Scope, damit ManualBookingCandidates dieselbe Definition
        // benutzt statt einer zweiten Kopie (siehe RecContract::scopeSent()).
        return $this->contracts()->sent()->exists();
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
     * DIE Stelle der Bewerbung.
     *
     * Liest das Feld rec_position_id. Der frueher hier stehende Weg (Stelle der
     * fruehesten verknuepften Anzeige) bleibt als Fallback, solange das Feld leer
     * ist — und zwar DAUERHAFT, nicht nur bis zum Backfill: entstehen Bewerbungen
     * ueber einen Weg, an dem das Setzen vergessen wurde, ist ein veralteter Wert
     * besser als gar keiner. Ein entfernter Fallback machte daraus einen stillen
     * Datenfehler.
     *
     * Wer die Stelle braucht, ruft diese Methode — nicht postings->first(). Genau
     * das Raten an zehn Stellen war der Grund, warum der Stellenwechsel den Pivot
     * umschreiben musste.
     */
    public function primaryPosition(): ?RecPosition
    {
        if ($this->rec_position_id !== null) {
            return $this->position;
        }

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
    public function switchToPosition(RecPosition $newPosition, ?RecInterview $interview = null): void
    {
        DB::transaction(function () use ($newPosition, $interview) {
            $currentOrder = $this->phase?->order;

            // VOR dem Loesen festhalten: danach ist die Herkunft nicht mehr lesbar.
            $this->loadMissing('postings.position');
            $alteAnzeigen = $this->postings->pluck('title')->filter()->implode(', ');
            $alteStellen = $this->postings
                ->map(fn ($p) => $p->position?->title)->filter()->unique()->implode(', ');

            $this->postings()->detach();

            $newPosting = $this->postingFuerStellenwechsel($newPosition, $interview);
            if (!$newPosting) {
                throw new \RuntimeException(
                    "Stelle '{$newPosition->title}' hat keine aktive Ausschreibung — Switch nicht möglich."
                );
            }

            $this->postings()->attach($newPosting->id, [
                'applied_at' => now()->toDateString(),
                // Diese Verknuepfung ist KEINE Bewerbung auf diese Anzeige. Der Marker
                // ist die einzige Spur davon, sobald die alte Verknuepfung geloescht
                // ist — die Statistik zaehlt sie damit nicht als Bewerbung mit.
                'matched_via' => 'position_switch',
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
            PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::POSITION_SWITCH);
            try {
                $this->save();
            } finally {
                PhaseTransitionTrigger::forget($this->id);
            }

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
                    'summary' => "Stelle gewechselt zu \"{$newPosition->title}\" durch Schulungs-Buchung"
                        . ($alteStellen !== '' ? " (vorher Stelle: {$alteStellen})" : '')
                        . ($alteAnzeigen !== '' ? ", Anzeige: {$alteAnzeigen}" : '')
                        . '.',
                ]);
            } catch (\Throwable) {}
        });
    }

    /**
     * Welche Ausschreibung der neuen Stelle bekommt die Verknuepfung?
     *
     * Vorher stand hier `->where('is_active', true)->first()` OHNE Sortierung: die
     * Auswahl haing davon ab, in welcher Reihenfolge die Datenbank die Zeilen
     * liefert, und war damit nicht reproduzierbar. Gemessen wurden 15 Wechsel,
     * davon 11 in sechs Tagen — jeder verschob eine Bewerbung in eine
     * Statistik-Zeile, in die sie nicht gehoert.
     *
     * 1. Die Ausschreibung des GEBUCHTEN TERMINS ist die richtige Antwort: die
     *    Person geht zu genau dieser Schulung. Sie muss zur neuen Stelle gehoeren,
     *    sonst haetten wir das Problem nur verschoben.
     * 2. Ist am Termin keine gepflegt (das Feld ist neu), entscheidet die kleinste
     *    ID — beliebig, aber stabil. Reproduzierbar zu sein ist hier mehr wert als
     *    klug zu sein.
     */
    private function postingFuerStellenwechsel(RecPosition $newPosition, ?RecInterview $interview): ?RecPosting
    {
        if ($interview?->rec_posting_id !== null) {
            $ausTermin = $newPosition->postings()
                ->where('rec_postings.id', $interview->rec_posting_id)
                ->first();

            if ($ausTermin) {
                return $ausTermin;
            }
        }

        return $newPosition->postings()
            ->where('is_active', true)
            ->orderBy('rec_postings.id')
            ->first();
    }

    /**
     * Gleicht abgeleiteten Zustand an die PRIMÄRE Stelle an, nachdem das Posting
     * geändert wurde (Enrichment-Umschlüsselung, manuelles Verknüpfen, HR-Zuweisung).
     * Diese Pfade ändern nur das Pivot — ohne diesen Abgleich blieben Phase und
     * Verantwortlicher auf der alten Stelle stehen.
     *
     * Verhalten (idempotent, reagiert nur auf einen bereits geänderten Posting-Stand):
     *  - **Verantwortlicher:** auffüllen, wenn leer (Kaskade Stelle → Default → Team).
     *    Bestehender Owner wird nie überschrieben. Verhindert Auto-Pilot-Unsichtbarkeit
     *    (Selektions-Query: whereNotNull('owned_by_user_id')).
     *  - **Phase + Feldwerte:** NUR bei eindeutigem **Einzel-Posting**, dessen Phase zu
     *    einer ANDEREN Stelle gehört → rec_phase_id auf die gleiche-order-Phase der
     *    primären Stelle, und Feldwerte per Name mitziehen (remapExtraFieldValuesToPosition,
     *    dieselbe Mechanik wie switchToPosition) → kein Verwaisen.
     *  - **Mehrfach-Posting:** Phase NICHT anfassen. Die primäre Stelle ist mehrdeutig
     *    (echter Mehr-Orts-Wunsch ist normal und löst sich bei der Buchung via
     *    switchToPosition). Nur Owner auffüllen.
     *  - **is_unrouted:** false, sobald eine Stelle verknüpft ist.
     */
    public function reconcilePositionState(): void
    {
        $this->loadMissing(['postings.position', 'phase', 'team']);

        $primaryPosition = $this->primaryPosition();
        if (!$primaryPosition) {
            return; // keine Stelle verknüpft → nichts abzugleichen
        }

        $dirty = false;
        $phaseRemapped = false;

        // Phase + Feldwerte nur bei eindeutigem Einzel-Posting angleichen, dessen
        // Phase zu einer anderen Stelle gehört (oder fehlt).
        $isSinglePosting = $this->postings->count() === 1;
        $phaseBelongsElsewhere = $this->phase === null
            || (int) $this->phase->rec_position_id !== (int) $primaryPosition->id;

        if ($isSinglePosting && $phaseBelongsElsewhere) {
            $targetPhaseId = $this->sameOrderPhaseId($primaryPosition, $this->phase?->order);
            if ($targetPhaseId && $targetPhaseId !== (int) $this->rec_phase_id) {
                $this->rec_phase_id = $targetPhaseId;
                $dirty = true;
                $phaseRemapped = true;
            }
        }

        // Verantwortlichen auffüllen, falls leer.
        if (!$this->owned_by_user_id) {
            $settings = RecApplicantSettings::getOrCreateForTeam($this->team_id);
            $ownerId = \Platform\Recruiting\Services\OwnerResolver::resolve(
                null, // leer → auffüllen (sonst wären wir nicht hier)
                $primaryPosition->owned_by_user_id ? (int) $primaryPosition->owned_by_user_id : null,
                (int) ($settings->getSetting('default_contact_user_id') ?? 0) ?: null,
                $this->team?->user_id ? (int) $this->team->user_id : null,
            );
            if ($ownerId) {
                $this->owned_by_user_id = $ownerId;
                $dirty = true;
            }
        }

        if ($this->is_unrouted) {
            $this->is_unrouted = false;
            $dirty = true;
        }

        if (!$dirty) {
            return;
        }

        $this->save();

        // Feldwerte erst NACH dem Phasen-Wechsel umhängen (gleiche Mechanik wie
        // switchToPosition) — sonst würden sie unter der alten Stelle verwaisen.
        if ($phaseRemapped) {
            $this->remapExtraFieldValuesToPosition($primaryPosition);

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'position_reconciled',
                    'summary' => "Phase + Feldwerte an primäre Stelle \"{$primaryPosition->title}\" angeglichen (nach Posting-Wechsel).",
                ]);
            } catch (\Throwable) {
                // Log-Fehler darf den Abgleich nicht blockieren
            }
        }
    }

    /**
     * Liefert die ID der Phase mit gleichem `order` in $position (Fallback:
     * erste aktive Phase). null, wenn $position keine aktive Phase hat.
     */
    private function sameOrderPhaseId(RecPosition $position, ?int $order): ?int
    {
        $phases = RecPhase::where('rec_position_id', $position->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'order']);

        return \Platform\Recruiting\Services\PhaseMatcher::sameOrderOrFirst(
            $order,
            $phases->mapWithKeys(fn ($p) => [(int) $p->order => (int) $p->id])->all(),
        );
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
