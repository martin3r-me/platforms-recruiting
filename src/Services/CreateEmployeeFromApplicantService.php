<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Legt einen RecEmployee aus einem RecApplicant an. Wird durch den
 * Phase-Completion-Hook `creates_employee_on_completion` getriggert.
 *
 * Mapping-Source:
 *  - extra_field_values vom Applicant (by name lookup)
 *  - rec_applicant_legal_statuses (is_eu_citizen + file_ids)
 *  - crm_contact (primary email + phone als Fallback)
 *  - primaryPosition() fuer rec_position_id
 *
 * Side-Effects:
 *  - Setzt applicant.is_active = false (raus aus default Dashboard)
 *  - Setzt applicant.auto_pilot = false (kein weiterer Reminder am Bewerber)
 *  - Dupliziert den CRM-Contact-Link mit linkable_type='rec_employee'
 *  - Schreibt RecAutoPilotLog Type 'employee_created'
 *
 * Idempotent: existiert schon ein RecEmployee fuer diesen Applicant
 * (FK rec_applicant_id), wird der existierende zurueckgegeben — kein
 * Re-Mapping, keine Duplicate-Cases. Manuelle Spalten-Updates im Portal
 * oder via HR bleiben erhalten.
 */
class CreateEmployeeFromApplicantService
{
    public function createOrUpdate(RecApplicant $applicant, ?int $createdByUserId = null): RecEmployee
    {
        // Idempotenz: schon angelegt? Zurueckgeben.
        $existing = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($applicant, $createdByUserId) {
            $applicant->loadMissing(['legalStatus', 'crmContactLinks.contact']);

            $extraValues = $this->collectExtraFieldValuesByName($applicant);
            $legalStatus = $applicant->legalStatus;
            $primaryContact = $applicant->crmContactLinks->first()?->contact;

            $employee = RecEmployee::create([
                'team_id'              => $applicant->team_id,
                'rec_applicant_id'     => $applicant->id,
                'rec_position_id'      => $applicant->primaryPosition()?->id,

                // Stammdaten — Fallback-Kette extra_field → crm_contact
                'first_name'           => $extraValues['vorname']   ?? $primaryContact?->first_name,
                'last_name'            => $extraValues['nachname']  ?? $primaryContact?->last_name,
                'birth_date'           => $extraValues['geburtsdatum'] ?? null,
                'identity_card_number' => $extraValues['ausweisnummer'] ?? null,
                'email'                => $extraValues['email']     ?? $primaryContact?->emailAddresses?->first()?->email_address,
                'phone'                => $extraValues['telefonnummer'] ?? $primaryContact?->phoneNumbers?->first()?->raw_input,

                // Adresse (extra_fields — wenn in P3 erfasst)
                'street'               => $extraValues['strasse'] ?? null,
                'zip'                  => $extraValues['plz']     ?? null,
                'city'                 => $extraValues['ort']     ?? null,
                'country_code'         => $extraValues['land']    ?? null,

                // Stelle/Taetigkeit
                'beschaftigungsort'    => $extraValues['beschaftigungsort'] ?? null,
                'art_der_tatigkeit'    => $this->normalizeArrayValue($extraValues['art_der_tatigkeit'] ?? null),

                // Bankdaten — typischerweise leer bei Anlage, kommen via Portal
                'iban'                       => $extraValues['iban'] ?? null,
                'bic'                        => $extraValues['bic'] ?? null,
                'steuer_id'                  => $extraValues['steuer_id'] ?? null,
                'sozialversicherungsnummer'  => $extraValues['sozialversicherungsnummer'] ?? null,

                // Legal-Status
                'is_eu_citizen'                  => $legalStatus?->is_eu_citizen,
                'nationalpass_file_id'           => $legalStatus?->nationalpass_file_id,
                'aufenthaltstitel_front_file_id' => $legalStatus?->aufenthaltstitel_front_file_id,
                'aufenthaltstitel_back_file_id'  => $legalStatus?->aufenthaltstitel_back_file_id,
                'visumsblatt_file_id'            => $legalStatus?->visumsblatt_file_id,
                'zusatzblatt_file_id'            => $legalStatus?->zusatzblatt_file_id,
                'immatrikulation_file_id'        => $legalStatus?->immatrikulation_file_id,

                // Lifecycle
                'is_active'            => true,
                'employed_since'       => now()->toDateString(),

                'created_by_user_id'   => $createdByUserId,
            ]);

            // CRM-Link duplizieren: gleicher Contact, neuer linkable_type
            $this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);

            // Bewerber deaktivieren — raus aus default Dashboard, Statistiken
            // greifen weiter via rec_applicants ohne is_active-Filter.
            $applicant->update([
                'is_active'  => false,
                'auto_pilot' => false,
            ]);

            // AutoPilot-Log fuer HR-Sichtbarkeit
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $applicant->id,
                    'type'             => 'employee_created',
                    'summary'          => "Mitarbeiter angelegt (RecEmployee #{$employee->id}). Bewerber-Funnel beendet, Daten-Nachpflege uebernimmt das MA-Portal.",
                ]);
            } catch (\Throwable $e) {
                Log::warning('[CreateEmployeeFromApplicantService] Could not write employee_created log', [
                    'applicant_id' => $applicant->id,
                    'employee_id'  => $employee->id,
                    'error'        => $e->getMessage(),
                ]);
            }

            return $employee->fresh();
        });
    }

    /**
     * Liest alle extra_field_values des Applicants und mapped sie auf
     * ein Assoc-Array [field_name => value]. Greift auf die ueber alle
     * Phasen gueltigen Definitionen zu (via getExtraFieldDefinitions),
     * die Phase-Inheritance schon handhabt.
     */
    private function collectExtraFieldValuesByName(RecApplicant $applicant): array
    {
        try {
            $definitions = $applicant->getExtraFieldDefinitions();
        } catch (\Throwable) {
            return [];
        }

        $values = $applicant->extraFieldValues()->get()->keyBy('definition_id');
        $byName = [];
        foreach ($definitions as $def) {
            if (empty($def->name)) {
                continue;
            }
            $val = $values->get($def->id);
            if (!$val) {
                continue;
            }
            $raw = $val->value;
            if ($raw === null || $raw === '' || $raw === '[]') {
                continue;
            }
            $byName[$def->name] = $raw;
        }
        return $byName;
    }

    /**
     * Multi-Lookup-Felder (z.B. art_der_tatigkeit) werden als JSON-Array
     * gespeichert. Wenn der Wert ein JSON-String ist → dekodieren;
     * sonst null lassen.
     */
    private function normalizeArrayValue($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && str_starts_with(trim($raw), '[')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return [$raw];
    }

    /**
     * Dupliziert die existierenden CRM-Contact-Links vom Applicant auf
     * den neuen Employee — gleicher Contact, neuer linkable_type. Damit
     * sieht das CRM-UI auf der Contact-Karte beide Verknuepfungen
     * (1x Bewerber, 1x Mitarbeiter).
     */
    private function mirrorCrmContactLinks(RecApplicant $applicant, RecEmployee $employee, ?int $userId): void
    {
        $employeeMorphClass = $employee->getMorphClass();

        foreach ($applicant->crmContactLinks as $link) {
            $alreadyMirrored = CrmContactLink::where('contact_id', $link->contact_id)
                ->where('linkable_type', $employeeMorphClass)
                ->where('linkable_id', $employee->id)
                ->exists();
            if ($alreadyMirrored) {
                continue;
            }
            CrmContactLink::create([
                'contact_id'         => $link->contact_id,
                'linkable_type'      => $employeeMorphClass,
                'linkable_id'        => $employee->id,
                'team_id'            => $employee->team_id,
                'created_by_user_id' => $userId,
            ]);
        }
    }
}
