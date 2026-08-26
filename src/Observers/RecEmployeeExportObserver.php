<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Models\RecPosition;

/**
 * Setzt rec_employees.zas_changed_at = now() bei Aenderungen, die fuer
 * den ZAS-Mitarbeiter-Update-Export relevant sind. Der Update-Endpoint
 * liefert nur Datensaetze mit gesetztem Marker aus und nullt ihn nach
 * erfolgreicher Auslieferung.
 *
 * Beobachtete Modelle:
 *   - RecEmployee::updated      Stammdaten-Aenderungen (NICHT created
 *                               — frischer MA ist im Initial-Endpoint)
 *   - RecEmployeeHrData::saved  HR-only-Aenderungen
 *   - RecContract::saved        signed_at-Transition bei MA-zugehoerigem
 *                               Vertrag (relevant fuer IFSG-Computed-Felder)
 *
 * Alle Listener in safelyRun-Wrapper. Direkter DB::update zur Rekursion-
 * Vermeidung.
 *
 * Siehe docs Plan: meine-idee-wir-brauchen-compressed-torvalds.md
 */
class RecEmployeeExportObserver
{
    /**
     * Spalten auf rec_employees deren Aenderung den Update-Marker triggern.
     * Identity-Felder (uuid, portal_token, audit) sind bewusst ausgenommen
     * — die zaehlen nicht als Business-Change fuer ZAS.
     */
    public const RELEVANT_EMPLOYEE_FIELDS = [
        // Kostenstelle (MA-eigenes Feld; Vorrang vor Stelle im Export)
        'cost_center',

        // Stammdaten
        'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place',
        'birth_country', 'gender', 'marital_status',
        'identity_card_number', 'identity_card_valid_until',
        'religion', 'number_of_children',

        // Kontakt
        'email', 'phone',

        // Adresse
        'street', 'house_number', 'zip', 'city', 'country_code',

        // Stelle / Taetigkeit
        'rec_position_id', 'beschaftigungsort', 'employment_type',

        // Bank
        'iban', 'bic', 'bank_institute', 'account_holder',

        // Steuer / Versicherung
        'tax_class', 'steuer_id', 'sozialversicherungsnummer', 'health_insurance',

        // Sonstiges
        'drivers_license_class', 'has_car', 'recruited_by_personnel_number',
        // Firma (RG/MA) — wird exportiert, eine HR-Korrektur soll ZAS also
        // erreichen. Der Import selbst schreibt sie observer-frei und loest
        // damit keinen Rueckexport der gerade empfangenen Werte aus.
        'company',
        // personnel_number bewusst NICHT hier — Feld ist HR-only im
        // MA-Backend, geht aktuell nicht in den ZAS-Export → Aenderung
        // soll auch keinen Update-Pull ausloesen. Wenn PersNr spaeter
        // doch exportiert wird, hier wieder aufnehmen.

        // Kleidung
        'shirt_size', 'pants_size', 'shoe_size',

        // Files
        'identity_card_front_file_id', 'identity_card_back_file_id',
        'selfie_file_id', 'health_insurance_card_file_id',
        'immatrikulation_file_id', 'schulbescheinigung_file_id', 'nationalpass_file_id',
        'aufenthaltstitel_front_file_id', 'aufenthaltstitel_back_file_id',
        'visumsblatt_file_id', 'zusatzblatt_file_id',
        'fiktionsbescheinigung_front_file_id', 'fiktionsbescheinigung_back_file_id',

        // Date-Felder
        'residence_permit_valid_until', 'work_permit_valid_until',
        'school_certificate_valid_until',

        // Gesundheit
        'has_infection_protection_certificate',
        'infection_protection_first_issued_at',

        // Arbeitsschutz
        'is_first_aider', 'first_aider_valid_until', 'is_safety_officer',

        // Lifecycle
        'is_eu_citizen', 'employment_ended_at',
        'is_active',
    ];

    /**
     * Spalten auf rec_employee_hr_data deren Aenderung den Update-Marker
     * triggern. system-Felder (uuid/timestamps) ausgenommen.
     */
    public const RELEVANT_HR_FIELDS = [
        'contract_signed_at', 'contract_sent_date', 'contract_end_date',
        'export_status', 'employment_classification',
        'linen_package_items', 'star_rating', 'qualifications',
        // Bewertung (Spec §5): loest den Update-Marker aus, damit HR-Korrekturen
        // ZAS erreichen. evaluation_note fehlt hier ABSICHTLICH — es wird nicht
        // exportiert, ein Marker waere ein Re-Export ohne Inhaltsaenderung.
        'rating_erscheinungsbild', 'rating_fachkompetenz', 'rating_auffassungsgabe',
        'rating_auftreten', 'rating_teamintegration',
    ];

    public static function register(): void
    {
        RecEmployee::updated(static function (RecEmployee $employee): void {
            self::safelyRun(function () use ($employee): void {
                $changes = array_keys($employee->getChanges());
                $relevant = array_intersect($changes, self::RELEVANT_EMPLOYEE_FIELDS);
                if (empty($relevant)) {
                    return;
                }
                self::markEmployeeId($employee->id);
            }, 'rec_employee.updated', $employee->id);

            // Payroll-Tracking: lohnrelevante Aenderungen separat tracken
            self::safelyRun(function () use ($employee): void {
                self::trackPayrollChanges($employee);
            }, 'rec_employee.updated.payroll', $employee->id);
        });

        RecEmployeeHrData::saved(static function (RecEmployeeHrData $hr): void {
            self::safelyRun(function () use ($hr): void {
                $changes = array_keys($hr->getChanges());
                $relevant = array_intersect($changes, self::RELEVANT_HR_FIELDS);
                if (empty($relevant)) {
                    return;
                }
                if ($hr->rec_employee_id) {
                    self::markEmployeeId((int) $hr->rec_employee_id);
                }
            }, 'rec_employee_hr_data.saved', $hr->id);
        });

        RecContract::saved(static function (RecContract $contract): void {
            self::safelyRun(function () use ($contract): void {
                if (!$contract->wasChanged('signed_at')) {
                    return;
                }
                if (!$contract->rec_applicant_id) {
                    return;
                }
                $employeeId = DB::table('rec_employees')
                    ->where('rec_applicant_id', $contract->rec_applicant_id)
                    ->value('id');
                if (!$employeeId) {
                    return;
                }
                self::markEmployeeId((int) $employeeId);
            }, 'rec_contract.saved.signed_at', $contract->id);
        });

        // Position-Kostenstelle aendert sich → alle aktiven MAs dieser
        // Stelle muessen ein Update an ZAS bekommen (Kostenstelle ist
        // teil des MA-Exports).
        RecPosition::updated(static function (RecPosition $position): void {
            self::safelyRun(function () use ($position): void {
                if (!$position->wasChanged('cost_center')) {
                    return;
                }
                DB::table('rec_employees')
                    ->where('rec_position_id', $position->id)
                    ->where('is_active', true)
                    ->update(['zas_changed_at' => now()]);
            }, 'rec_position.updated.cost_center', $position->id);
        });
    }

    protected static function safelyRun(callable $fn, string $context, mixed $entityId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \Log::warning('[ZAS-EmployeeObserver] failed silently', [
                'context'   => $context,
                'entity_id' => $entityId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Direkter DB::update um Eloquent-Save/Observer-Loop zu vermeiden.
     */
    protected static function markEmployeeId(int $employeeId): void
    {
        DB::table('rec_employees')
            ->where('id', $employeeId)
            ->update(['zas_changed_at' => now()]);
    }

    /**
     * Payroll-Tracking: lohnrelevante Aenderungen als JSON-Eintraege
     * an payroll_data_changed_fields appenden + Timestamp setzen.
     *
     * Update via DB::table (umgeht Observer-Rekursion). Initial-Setzungen
     * (null/"" -> Wert) zaehlen nicht als Aenderung — sonst wuerde jeder
     * Onboarding-Flow die Liste fluten. Echte Veraenderungen zwischen
     * zwei Werten werden getrackt.
     */
    protected static function trackPayrollChanges(RecEmployee $employee): void
    {
        $changes = array_keys($employee->getChanges());

        $settings = RecApplicantSettings::getOrCreateForTeam($employee->team_id);
        $trackedFields = $settings->getSetting(
            'employee_payroll_tracked_fields',
            RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields'] ?? []
        );

        $candidates = array_intersect($changes, $trackedFields);
        if (empty($candidates)) {
            return;
        }

        // Echte Aenderungen filtern (Initial-Setzungen ignorieren)
        $realChanges = [];
        foreach ($candidates as $field) {
            $old = self::normalizePayrollValue($employee->getOriginal($field));
            $new = self::normalizePayrollValue($employee->getAttribute($field));

            if ($old === null) {
                // Erstbefuellung — kein Tracking
                continue;
            }
            if ($old === $new) {
                continue;
            }
            $realChanges[$field] = ['old' => $old, 'new' => $new];
        }

        if (empty($realChanges)) {
            return;
        }

        $existing = DB::table('rec_employees')
            ->where('id', $employee->id)
            ->value('payroll_data_changed_fields');
        $entries = $existing ? json_decode($existing, true) : [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $now = now()->toIso8601String();
        foreach ($realChanges as $field => $values) {
            $entries[] = [
                'field' => $field,
                'old'   => $values['old'],
                'new'   => $values['new'],
                'at'    => $now,
            ];
        }

        DB::table('rec_employees')
            ->where('id', $employee->id)
            ->update([
                'payroll_data_changed_at'     => now(),
                'payroll_data_changed_fields' => json_encode($entries),
            ]);
    }

    /**
     * Leere/whitespace-only Werte als null behandeln, damit "" und null
     * nicht als unterschiedlich zaehlen. Skalare bleiben skalar.
     */
    protected static function normalizePayrollValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        return $value;
    }
}
