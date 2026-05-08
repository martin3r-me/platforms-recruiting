<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateApplicantTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/applicants/{id} - Aktualisiert einen Bewerber. Parameter: applicant_id (required). Hinweis: CRM-Contact-Link wird ueber recruiting.applicant_contacts.* Tools verwaltet.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH). Nutze "recruiting.applicants.GET".',
                ],
                'rec_applicant_status_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: neuer Bewerbungsstatus (>0). Nutze "recruiting.lookup.GET" mit lookup=applicant_statuses. 0/leer = NICHT geaendert (bestehender Wert bleibt). Zum Loeschen UI verwenden.',
                ],
                'progress' => [
                    'type' => 'integer',
                    'description' => 'Optional: Fortschritt (0-100).',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen zur Bewerbung.',
                ],
                // applied_at wird BEWUSST NICHT exponiert. Es wird vom
                // Inbound-Listener auf den Tag unseres Eingangs gesetzt
                // und ist die Wahrheit für KPIs (Time-to-Hire,
                // Stuck-Indikatoren, Pipeline-Volumen). Enrichment-LLMs
                // haben in der Vergangenheit applied_at mit einem aus dem
                // Mail-Body extrahierten Datum überschrieben (Indeed-
                // Stamp, Kleinanzeigen-Anfrage-Datum etc.) — was die
                // KPIs verfälschte. Manuelle HR-Korrekturen passieren
                // entweder im UI oder über einen dedizierten Admin-Pfad.
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status.',
                ],
                'is_test' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Test-Bewerber-Flag. true = Bewerber wird vom ZAS-Export ausgeschlossen (taucht nicht im CSV auf, wird nicht vom Backfill markiert). Fuer manuell angelegte Test-/Demo-Datensaetze. Default false.',
                ],
                'auto_pilot' => [
                    'type' => 'boolean',
                    'description' => 'Optional: AutoPilot-Flag aktivieren/deaktivieren. true = Phase-Übergänge greifen automatisch (via checkAutoPilotCompletion); false = Bewerber pausiert, HR muss manuell schalten. Wird bei Production normalerweise vom Enrichment-Cronjob basierend auf Position-Setting auto_start_auto_pilot gesetzt.',
                ],
                'owned_by_user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Owner des Bewerber-Datensatzes (>0). 0/leer = NICHT geaendert.',
                ],
                'auto_pilot_state_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: AutoPilot-State-ID (>0). Nutze "recruiting.lookup.GET" mit lookup=auto_pilot_states. 0/leer = NICHT geaendert.',
                ],
                'auto_pilot_completed_at' => [
                    'type' => 'string',
                    'description' => 'Optional: ISO-Datetime oder "now" um auto_pilot_completed_at zu setzen. Leerer String = NICHT geaendert (bestehender Wert bleibt).',
                ],
                'contract_template_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Vertragsvorlage (rec_contract_templates.id, >0). Wird vom Schulungsleiter in der Schulungsnachbereitung gewählt — bestimmt welche AV-Variante (Zuschlag) bei "Vertrag versenden" erstellt wird. 0/leer = NICHT geaendert.',
                ],
                'rec_phase_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Phasen-ID (rec_phases.id, >0) auf die der Bewerber gesetzt werden soll. Muss zur Stelle des Bewerbers gehören, sonst wird der Wert verworfen. Hauptsächlich für Tests/Migrationen — der normale Flow läuft über AutoPilot/checkAutoPilotCompletion. 0/leer = NICHT geaendert.',
                ],
            ],
            'required' => ['applicant_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $found = $this->validateAndFindModel(
                $arguments,
                $context,
                'applicant_id',
                RecApplicant::class,
                'NOT_FOUND',
                'Bewerber nicht gefunden.'
            );
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecApplicant $applicant */
            $applicant = $found['model'];

            if ((int)$applicant->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf diesen Bewerber.');
            }

            // Simple text fields
            if (array_key_exists('notes', $arguments)) {
                $applicant->notes = $arguments['notes'] === '' ? null : $arguments['notes'];
            }

            if (array_key_exists('is_active', $arguments)) {
                $applicant->is_active = (bool) $arguments['is_active'];
            }

            if (array_key_exists('is_test', $arguments)) {
                $applicant->is_test = (bool) $arguments['is_test'];
            }

            if (array_key_exists('auto_pilot', $arguments)) {
                $applicant->auto_pilot = (bool) $arguments['auto_pilot'];
            }

            // Progress: clamp to 0-100
            if (array_key_exists('progress', $arguments)) {
                $val = $arguments['progress'];
                if (is_numeric($val)) {
                    $applicant->progress = max(0, min(100, (int) $val));
                }
            }

            // applied_at: BEWUSST NICHT übernommen (siehe Schema-Kommentar).
            // Wenn das Argument vorhanden ist, wird es ignoriert. Damit
            // bleibt der vom Inbound-Listener gesetzte Wert die Wahrheit.

            // auto_pilot_completed_at:
            //  - "now"        → setze auf now()
            //  - leerer String → IGNORIEREN (OpenAI-Default, nicht eine
            //    explizite Aufforderung zum Loeschen)
            //  - sonst        → parse als Datetime und setze
            //
            // HR-User die das Feld leeren wollen, machen das ueber die UI —
            // nicht via LLM-Tool. Das LLM hat keinen legitimen Grund den
            // AutoPilot-Completed-Marker zu loeschen.
            if (array_key_exists('auto_pilot_completed_at', $arguments)) {
                $val = trim((string) ($arguments['auto_pilot_completed_at'] ?? ''));
                if ($val === 'now') {
                    $applicant->auto_pilot_completed_at = now();
                } elseif ($val !== '') {
                    try {
                        $applicant->auto_pilot_completed_at = \Carbon\Carbon::parse($val);
                    } catch (\Throwable $e) {
                        // Invalid datetime — ignore silently
                    }
                }
                // val === '' → KEINE Aenderung (war frueher null-set, was Bug war)
            }

            // FK fields: 0/leer = IGNORIEREN (nicht null setzen).
            //
            // Hintergrund: OpenAI's Function-Calling fuellt Schema-Felder mit
            // Defaults (integer → 0) wenn der LLM keinen expliziten Wert
            // angibt. Frueher haben wir 0 als "loeschen" interpretiert, was
            // dazu fuehrte dass jeder LLM-Update unintendiert FK-Felder
            // genullt hat. Phase, Status, AutoPilot-State, Vertragsvorlage
            // gingen alle versehentlich verloren.
            //
            // Neuer Vertrag: nur >0 = setzen, alles andere = ignorieren.
            // HR-Manuelle Wegnahme passiert ueber die UI, nicht ueber das
            // LLM-Tool.
            $fkFields = [
                'auto_pilot_state_id' => \Platform\Recruiting\Models\RecAutoPilotState::class,
                'rec_applicant_status_id' => \Platform\Recruiting\Models\RecApplicantStatus::class,
                'owned_by_user_id' => \Platform\Core\Models\User::class,
                'contract_template_id' => \Platform\Recruiting\Models\RecContractTemplate::class,
            ];

            // rec_phase_id: zusaetzlich Position-Match-Check.
            if (array_key_exists('rec_phase_id', $arguments) && is_numeric($arguments['rec_phase_id']) && (int) $arguments['rec_phase_id'] > 0) {
                $val = (int) $arguments['rec_phase_id'];
                $phase = \Platform\Recruiting\Models\RecPhase::where('id', $val)
                    ->where('team_id', $teamId)
                    ->first();
                if ($phase) {
                    $applicantPositionIds = $applicant->postings()->pluck('rec_position_id')->unique();
                    if ($applicantPositionIds->contains($phase->rec_position_id)) {
                        $applicant->rec_phase_id = $phase->id;
                    }
                }
            }

            foreach ($fkFields as $field => $modelClass) {
                if (!array_key_exists($field, $arguments)) {
                    continue;
                }
                $val = $arguments[$field];
                if (is_numeric($val) && (int) $val > 0) {
                    if ($modelClass::where('id', (int) $val)->exists()) {
                        $applicant->{$field} = (int) $val;
                    }
                    // Invalid FK (ID existiert nicht) — ignore silently
                }
                // 0/null/leer/non-numeric → KEINE Aenderung
            }

            $applicant->save();

            return ToolResult::success([
                'id' => $applicant->id,
                'uuid' => $applicant->uuid,
                'rec_applicant_status_id' => $applicant->rec_applicant_status_id,
                'progress' => $applicant->progress,
                'team_id' => $applicant->team_id,
                'is_active' => (bool)$applicant->is_active,
                'is_test' => (bool)$applicant->is_test,
                'auto_pilot' => (bool)$applicant->auto_pilot,
                'auto_pilot_state_id' => $applicant->auto_pilot_state_id,
                'auto_pilot_completed_at' => $applicant->auto_pilot_completed_at?->toISOString(),
                'contract_template_id' => $applicant->contract_template_id,
                'rec_phase_id' => $applicant->rec_phase_id,
                'message' => 'Bewerber erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Bewerbers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicants', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
