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
                'birth_name'           => $extraValues['geburtsname'] ?? null,
                'birth_date'           => $extraValues['geburtsdatum'] ?? null,
                'birth_place'          => $extraValues['geburtsort'] ?? null,
                'birth_country'        => $extraValues['geburtsland'] ?? null,
                'identity_card_number'      => $extraValues['ausweisnummer'] ?? null,
                'identity_card_valid_until' => $this->normalizeDateValue($extraValues['ausweis_gultig_bis'] ?? null),
                'identity_card_front_file_id' => $this->normalizeFileId($extraValues['ausweis_reisepass_foto_vorderseite'] ?? null),
                'identity_card_back_file_id'  => $this->normalizeFileId($extraValues['ausweis_reisepass_foto_ruckseite'] ?? null),
                'selfie_file_id'              => $this->normalizeFileId($extraValues['selfie_upload'] ?? null),
                'email'                => $extraValues['email']     ?? $primaryContact?->emailAddresses?->first()?->email_address,
                'phone'                => $extraValues['telefonnummer'] ?? $primaryContact?->phoneNumbers?->first()?->raw_input,

                // Adresse (extra_fields — wenn in P3 erfasst)
                'street'               => $extraValues['strasse'] ?? null,
                'house_number'         => $extraValues['hausnummer'] ?? null,
                'zip'                  => $extraValues['plz']     ?? null,
                'city'                 => $extraValues['stadt'] ?? $extraValues['ort'] ?? null,
                'country_code'         => $extraValues['land']    ?? null,

                // Stelle/Taetigkeit
                'beschaftigungsort'    => $this->normalizeArrayValue($extraValues['beschaftigungsort'] ?? null),
                'art_der_tatigkeit'    => $this->normalizeArrayValue($extraValues['art_der_tatigkeit'] ?? null),
                'employment_type'      => $extraValues['ich_bin'] ?? null,
                'umfang_der_tatigkeit' => $extraValues['umfang_der_tatigkeit'] ?? null,

                // Bankdaten — typischerweise leer bei Anlage, kommen via Portal
                'iban'                       => $extraValues['iban'] ?? null,
                'bic'                        => $extraValues['bic'] ?? null,
                'bank_institute'             => $extraValues['geldinstitut'] ?? null,
                'steuer_id'                  => $extraValues['steuer_id'] ?? null,
                'sozialversicherungsnummer'  => $extraValues['sozialversicherungsnummer'] ?? null,

                // Persoenliches + Versicherung
                'gender'                          => $extraValues['geschlecht'] ?? null,
                'marital_status'                  => $extraValues['familienstand'] ?? null,
                'health_insurance'                => $extraValues['krankenkasse'] ?? null,
                'health_insurance_card_file_id'   => $this->normalizeFileId($extraValues['foto_versichertenkarte'] ?? null),
                'drivers_license_class'           => $extraValues['fuhrerschein_klasse'] ?? null,
                'has_car'                         => $this->normalizeBoolValue($extraValues['pkw_vorhanden'] ?? null),
                'recruited_by_personnel_number'   => $extraValues['geworben_von'] ?? null,

                // Legal-Status
                'is_eu_citizen'                  => $legalStatus?->is_eu_citizen,
                'nationalpass_file_id'           => $legalStatus?->nationalpass_file_id,
                'aufenthaltstitel_front_file_id' => $legalStatus?->aufenthaltstitel_front_file_id,
                'aufenthaltstitel_back_file_id'  => $legalStatus?->aufenthaltstitel_back_file_id,
                'visumsblatt_file_id'            => $legalStatus?->visumsblatt_file_id,
                'zusatzblatt_file_id'            => $legalStatus?->zusatzblatt_file_id,
                // Zusatzblatt-Rueckseite ist NICHT in rec_applicant_legal_statuses,
                // sondern als extra_field_value am Bewerber gespeichert.
                'zusatzblatt_back_file_id'       => $this->normalizeFileId($extraValues['zusatzblatt_arbeitsgenehmigung_ruckseite'] ?? null),
                'immatrikulation_file_id'        => $legalStatus?->immatrikulation_file_id,
                // Fiktionsbescheinigung: nicht in rec_applicant_legal_statuses,
                // sondern als extra_field_value am Bewerber gespeichert (P3 optional).
                'fiktionsbescheinigung_front_file_id' => $this->normalizeFileId($extraValues['fiktionsbescheinigung_vorderseite'] ?? null),
                'fiktionsbescheinigung_back_file_id'  => $this->normalizeFileId($extraValues['fiktionsbescheinigung_ruckseite'] ?? null),

                // Lifecycle
                'is_active'            => true,
                'employed_since'       => now()->toDateString(),

                'created_by_user_id'   => $createdByUserId,
            ]);

            // CRM-Link duplizieren: gleicher Contact, neuer linkable_type
            $this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);

            // HR-only-Datenrow anlegen — physisch getrennt vom MA-Portal-
            // sichtbaren rec_employees. Snapshot der Vertrags-Daten beim
            // Anlegen damit ZAS-Export direkt verfuegbar ist ohne JOIN.
            $hrData = $employee->ensureHrData();
            $this->snapshotContractDatesToHrData($applicant, $hrData);

            // Bewerber deaktivieren — raus aus default Dashboard, Statistiken
            // greifen weiter via rec_applicants ohne is_active-Filter.
            $applicant->update([
                'is_active'  => false,
                'auto_pilot' => false,
            ]);

            // ZAS-Doppel-Datensatz-Vermeidung: sobald der Bewerber zum MA
            // wird, soll er NICHT mehr im alten Bewerber-Update-Endpoint
            // erscheinen (sonst kriegt ZAS auf gleichen Match-Identifier
            // doppelte UPDATE-Operationen mit teils alten Daten). Direkt
            // per DB::update damit der RecApplicantExportObserver nicht
            // getriggert wird.
            DB::table('rec_applicants')
                ->where('id', $applicant->id)
                ->update(['export_changed_at' => null]);

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

            // Hinweis: RecEmployee::sendPortalNotification() ist verfuegbar
            // aber wird hier BEWUSST nicht automatisch getriggert. Der
            // explizite "MA-Portal aktivieren"-Button im Schulungs-Index
            // (eigene Iteration) wird das spaeter aufrufen. Bis dahin
            // laeuft der alte Notification-Pfad ueber
            // RecApplicant::sendContractPortalNotification weiter wie
            // bisher (alter Portal-Link funktioniert weiterhin auch fuer
            // bereits konvertierte MAs, weil ApplicantPortal kein
            // is_active-Check hat).

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
     * File-Felder werden in extra_field_values als file_id (numeric) gespeichert.
     * Casted zu int, null wenn leer/ungueltig.
     */
    private function normalizeFileId($raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        return null;
    }

    /**
     * Datums-Wert aus extra_field auf Y-m-d-Format normalisieren.
     * Akzeptiert Strings wie "2026-05-21" oder "21.05.2026".
     */
    private function normalizeDateValue($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Boolean-Wert aus extra_field zu echten Bool casten.
     */
    private function normalizeBoolValue($raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower((string) $raw);
        if (in_array($s, ['1', 'true', 'ja', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'nein', 'no'], true)) {
            return false;
        }
        return null;
    }

    /**
     * Schreibt Snapshot der Vertragsdaten auf die hrData-Row:
     *  - contract_sent_date  → frueheste sent_at aus den nicht-cancelled
     *    Vertraegen (= "Vertrags-Datum")
     *  - contract_end_date   → vertragsende-Extra-Field aus dem AV-Vertrag
     *    (= "Befristet bis")
     *
     * contract_signed_at bleibt initial null — wird gesetzt wenn alle
     * AV-Vertraege signed sind (separate Hook).
     */
    private function snapshotContractDatesToHrData(RecApplicant $applicant, $hrData): void
    {
        try {
            $contracts = $applicant->contracts()
                ->whereNotIn('status', ['cancelled'])
                ->with('contractTemplate')
                ->get();

            // Frueheste sent_at
            $sentDate = $contracts
                ->filter(fn ($c) => $c->sent_at !== null)
                ->sortBy('sent_at')
                ->first()?->sent_at?->toDateString();

            // contract_end aus AV-Vertrag extra_fields (vertragsende)
            $avContract = $contracts->first(function ($c) {
                $code = $c->contractTemplate?->code;
                return $code !== null && str_starts_with($code, 'AV-');
            });

            $endDate = null;
            if ($avContract && method_exists($avContract, 'getExtraField')) {
                $raw = $avContract->getExtraField('vertragsende');
                if ($raw) {
                    try {
                        $endDate = \Carbon\Carbon::parse($raw)->toDateString();
                    } catch (\Throwable) {}
                }
            }

            $updates = [];
            if ($sentDate) {
                $updates['contract_sent_date'] = $sentDate;
            }
            if ($endDate) {
                $updates['contract_end_date'] = $endDate;
            }
            if (!empty($updates)) {
                $hrData->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('[CreateEmployeeFromApplicantService] snapshotContractDates failed', [
                'applicant_id' => $applicant->id,
                'error'        => $e->getMessage(),
            ]);
        }
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
