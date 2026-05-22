<?php

namespace Platform\Recruiting\Services\Zas;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Mappt einen RecEmployee auf das assoziative Array
 * [ZAS-CSV-Spalte → String-Wert] fuer den Mitarbeiter-Export.
 *
 * Unterschied zum ZasFieldResolver (Bewerber):
 *  - Daten kommen direkt aus rec_employees + rec_employee_hr_data
 *    (keine extra_field_values mehr)
 *  - HR-only-Felder werden mit ausgeliefert
 *  - Computed-Felder (besch_erforderlich, aufenthalt_genehmigung_*,
 *    infektionsschutz_*) werden zur Laufzeit berechnet
 *  - Lookup-Felder werden via ZasLookupResolver durch Helper-Methoden
 *    auf Labels gemappt (analog zum Bewerber-Pfad, aber Lookups
 *    bezogen via Lookup-NAME statt definition_id — wir haben keine
 *    extra_field_definitions am Employee)
 */
class ZasEmployeeFieldResolver
{
    public function __construct(
        protected ZasSignedUrlGenerator $signedUrlGenerator,
    ) {}

    /**
     * Spalten-Reihenfolge im ZAS-Mitarbeiter-CSV. Initial semantisch
     * geordnet (Stammdaten → Kontakt → Adresse → Stelle → Bank →
     * Steuer/Versicherung → Sonstiges → Kleidung → Files →
     * Legal-Status → HR-only → Computed-Felder). Nach Schema-Review
     * mit Hr. Michel ggf. umgeordnet.
     */
    public const COLUMNS = [
        // Stammdaten
        'Name', 'Vorname', 'Geburtsname', 'Geburtsdatum', 'Geburtsort',
        'Nation', 'Geschlecht', 'Familienstand',
        'AusweisNr', 'AusweisBis',
        'Religion', 'KinderAnzahl',

        // Kontakt
        'Telefon', 'Email',

        // Adresse
        'Strasse', 'Hausnummer', 'PLZ', 'Ort', 'Land',

        // Stelle / Taetigkeit
        'Kostenstelle', 'Ichbin',

        // Bank
        'Bank', 'IBAN', 'BIC', 'Kontoinhaber',

        // Steuer / Versicherung
        'Steuerklasse', 'SteuerID', 'SVNummer', 'Krankenkasse',

        // Sonstiges
        'Fuehrerschein', 'PKW', 'GeworbenVonPersNr',

        // Kleidung
        'HemdGroesse', 'HosenGroesse', 'SchuhGroesse',

        // Files (Signed-URLs) — Spalten-Namen aus dem Bewerber-Endpunkt
        // bewusst beibehalten damit Hr. Michel nur EINE Spalten-Konvention
        // im Importer pflegen muss.
        'UplAuweis', 'UplAusw2', 'UplSelfie',
        'UplVersicher', 'UplImma', 'UplSchule',
        'UplArbErl', 'UplArbErl2',
        'UplVisum', 'UplZusatzblatt',
        'UplFiktion', 'UplFiktion2',
        'UplVertrag', 'UplIfsg',

        // Date-Felder Aufenthalt / Bescheinigungen
        'AufenthaltsErlaubnisBis', 'ArbeitsGenehmigungBis',
        'SchulBeschGueltigBis',

        // Gesundheit
        'InfekErstbescheinigung',

        // Lifecycle
        'EUBuerger',

        // HR-only
        'VertragVersendetAm', 'VertragZurueckAm', 'BefristetBis',
        'Status', 'Anstellungsart',
        'Waeschepaket', 'Sternebewertung', 'Qualifikation',

        // Computed-Felder
        'BeschErforderlich', 'AufenthaltGenehmigungErforderlich',
        'FolgeBescheinigungAm', 'InfekGueltigBis',
        'InfekBeschErforderlich', 'InfekBeschVorhanden',

        // Eintrittsdatum (= Vertragsbeginn vom AV-Vertrag) — letzte Spalte
        'Eintritt',
    ];

    /**
     * File-Slot → Employee-Spalte (file_id). Wird auch vom
     * ZasEmployeeFileController genutzt um den Slot aufzuloesen.
     */
    public const FILE_SLOT_FIELD_MAP = [
        'emp-auweis'             => 'identity_card_front_file_id',
        'emp-ausw2'              => 'identity_card_back_file_id',
        'emp-selfie'             => 'selfie_file_id',
        'emp-versicher'          => 'health_insurance_card_file_id',
        'emp-imma'               => 'immatrikulation_file_id',
        'emp-schule'             => 'schulbescheinigung_file_id',
        'emp-pass'               => 'nationalpass_file_id',
        'emp-arberl'             => 'aufenthaltstitel_front_file_id',
        'emp-arberl2'            => 'aufenthaltstitel_back_file_id',
        'emp-visum'              => 'visumsblatt_file_id',
        'emp-zusatzblatt'        => 'zusatzblatt_file_id',
        'emp-fiktion'            => 'fiktionsbescheinigung_front_file_id',
        'emp-fiktion2'           => 'fiktionsbescheinigung_back_file_id',
        // emp-vertrag + emp-ifsg sind contract-PDFs, nicht file_id-basiert
    ];

    /**
     * Cache lookup-name → ['value' => 'label']. Lazy-loaded.
     */
    protected array $lookupCache = [];

    public function resolve(RecEmployee $employee): array
    {
        $employee->loadMissing(['hrData', 'position', 'applicant.contracts.contractTemplate']);

        $hr = $employee->hrData;

        $row = [];
        foreach (self::COLUMNS as $column) {
            $row[$column] = (string) ($this->resolveColumn($employee, $hr, $column) ?? '');
        }
        return $row;
    }

    protected function resolveColumn(RecEmployee $employee, $hr, string $column): ?string
    {
        return match ($column) {
            'Name'                => $employee->last_name,
            'Vorname'             => $employee->first_name,
            'Geburtsname'         => $employee->birth_name,
            'Geburtsdatum'        => $this->formatDate($employee->birth_date),
            'Geburtsort'          => $employee->birth_place,
            'Nation'              => $this->lookupLabel('geburtsland', $employee->birth_country),
            'Geschlecht'          => $this->lookupLabel('geschlecht', $employee->gender),
            'Familienstand'       => $this->lookupLabel('familienstand', $employee->marital_status),
            'AusweisNr'           => $employee->identity_card_number,
            'AusweisBis'          => $this->formatDate($employee->identity_card_valid_until),
            'Religion'            => $this->lookupLabel('religion', $employee->religion),
            'KinderAnzahl'        => $employee->number_of_children !== null ? (string) $employee->number_of_children : null,

            'Telefon'             => $employee->phone,
            'Email'               => $employee->email,

            'Strasse'             => $employee->street,
            'Hausnummer'          => $employee->house_number,
            'PLZ'                 => $employee->zip,
            'Ort'                 => $employee->city,
            'Land'                => $employee->country_code,

            'Kostenstelle'        => $employee->position?->cost_center !== null ? (string) $employee->position->cost_center : null,
            'Ichbin'              => $this->lookupLabel('beschaeftigung_art', $employee->employment_type),

            'Bank'                => $employee->bank_institute,
            'IBAN'                => $employee->iban,
            'BIC'                 => $employee->bic,
            'Kontoinhaber'        => $employee->account_holder,

            'Steuerklasse'        => $employee->tax_class,
            'SteuerID'            => $employee->steuer_id,
            'SVNummer'            => $employee->sozialversicherungsnummer,
            'Krankenkasse'        => $this->lookupLabel('krankenkasse', $employee->health_insurance),

            'Fuehrerschein'       => $employee->drivers_license_class,
            'PKW'                 => $this->boolLabel($employee->has_car),
            'GeworbenVonPersNr'   => $employee->recruited_by_personnel_number,

            'HemdGroesse'         => $employee->shirt_size,
            'HosenGroesse'        => $employee->pants_size !== null ? (string) $employee->pants_size : null,
            'SchuhGroesse'        => $employee->shoe_size !== null ? (string) $employee->shoe_size : null,

            // Files — Spalten-Namen identisch zum Bewerber-Endpunkt
            'UplAuweis'              => $this->fileUrl($employee, 'emp-auweis', $employee->identity_card_front_file_id),
            'UplAusw2'               => $this->fileUrl($employee, 'emp-ausw2', $employee->identity_card_back_file_id),
            'UplSelfie'              => $this->fileUrl($employee, 'emp-selfie', $employee->selfie_file_id),
            'UplVersicher'           => $this->fileUrl($employee, 'emp-versicher', $employee->health_insurance_card_file_id),
            'UplImma'                => $this->fileUrl($employee, 'emp-imma', $employee->immatrikulation_file_id),
            'UplSchule'              => $this->fileUrl($employee, 'emp-schule', $employee->schulbescheinigung_file_id),
            'UplArbErl'              => $this->fileUrl($employee, 'emp-arberl', $employee->aufenthaltstitel_front_file_id),
            'UplArbErl2'             => $this->fileUrl($employee, 'emp-arberl2', $employee->aufenthaltstitel_back_file_id),
            'UplVisum'               => $this->fileUrl($employee, 'emp-visum', $employee->visumsblatt_file_id),
            'UplZusatzblatt'         => $this->fileUrl($employee, 'emp-zusatzblatt', $employee->zusatzblatt_file_id),
            'UplFiktion'             => $this->fileUrl($employee, 'emp-fiktion', $employee->fiktionsbescheinigung_front_file_id),
            'UplFiktion2'            => $this->fileUrl($employee, 'emp-fiktion2', $employee->fiktionsbescheinigung_back_file_id),
            'UplVertrag'             => $this->contractUrl($employee, 'arbeitsvertrag'),
            'UplIfsg'                => $this->contractUrl($employee, 'ifsg'),

            // Date-Felder Aufenthalt / Bescheinigungen
            'AufenthaltsErlaubnisBis' => $this->formatDate($employee->residence_permit_valid_until),
            'ArbeitsGenehmigungBis'   => $this->formatDate($employee->work_permit_valid_until),
            'SchulBeschGueltigBis'    => $this->formatDate($employee->school_certificate_valid_until),

            'InfekErstbescheinigung'  => $this->formatDate($employee->infection_protection_first_issued_at),

            'EUBuerger'               => $this->boolLabel($employee->is_eu_citizen),

            // HR-only
            'VertragVersendetAm'      => $this->formatDate($hr?->contract_sent_date),
            'VertragZurueckAm'        => $this->formatDate($hr?->contract_signed_at),
            'BefristetBis'            => $this->formatDate($this->avContractEndDate($employee) ?? $hr?->contract_end_date),
            'Status'                  => $hr?->export_status ?? 'GO',
            'Anstellungsart'          => $this->lookupLabel('anstellungsart', $hr?->employment_classification),
            'Waeschepaket'            => $this->multiLookupLabels('waeschepaket', $hr?->linen_package_items),
            'Sternebewertung'         => $hr?->star_rating !== null ? (string) $hr->star_rating : null,
            'Qualifikation'           => $this->multiLookupLabels('qualifikation', $hr?->qualifications),

            // Computed-Felder
            'BeschErforderlich'                 => $this->boolLabel(
                $employee->immatrikulation_file_id !== null
                || $employee->schulbescheinigung_file_id !== null
            ),
            'AufenthaltGenehmigungErforderlich' => $this->boolLabel($this->hasAufenthaltOrGenehmigung($employee)),
            'FolgeBescheinigungAm'              => $this->formatDate($this->ifsgSignedAt($employee)),
            'InfekGueltigBis'                   => $this->formatDate($this->ifsgValidUntil($employee)),
            'InfekBeschErforderlich'            => 'Ja',
            'InfekBeschVorhanden'               => $this->boolLabel($employee->infection_protection_first_issued_at !== null),

            // Eintrittsdatum (= Vertragsbeginn) — als letzte Spalte
            'Eintritt'                          => $this->formatDate($this->avContractStartDate($employee)),
        };
    }

    // ------------------------------------------------------------------
    // Format-Helpers
    // ------------------------------------------------------------------

    protected function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance(Carbon::parse($value))->format('d.m.Y');
            }
            return Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function boolLabel(?bool $value): string
    {
        return $value === true ? 'Ja' : 'Nein';
    }

    // ------------------------------------------------------------------
    // Lookup-Resolution per Lookup-NAME (statt definition_id)
    // ------------------------------------------------------------------

    protected function lookupLabel(string $lookupName, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $map = $this->loadLookupMap($lookupName);
        return $map[$value] ?? $value;
    }

    protected function multiLookupLabels(string $lookupName, mixed $values): ?string
    {
        if ($values === null || $values === [] || $values === '') {
            return null;
        }
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : [$values];
        }
        if (!is_array($values)) {
            return null;
        }
        $map = $this->loadLookupMap($lookupName);
        $labels = array_map(fn ($v) => $map[$v] ?? (string) $v, $values);
        return $labels === [] ? null : implode(', ', $labels);
    }

    protected function loadLookupMap(string $lookupName): array
    {
        if (!isset($this->lookupCache[$lookupName])) {
            $lookupId = DB::table('core_lookups')->where('name', $lookupName)->value('id');
            if (!$lookupId) {
                $this->lookupCache[$lookupName] = [];
            } else {
                $this->lookupCache[$lookupName] = DB::table('core_lookup_values')
                    ->where('lookup_id', $lookupId)
                    ->pluck('label', 'value')
                    ->all();
            }
        }
        return $this->lookupCache[$lookupName];
    }

    // ------------------------------------------------------------------
    // File-URL-Helpers
    // ------------------------------------------------------------------

    protected function fileUrl(RecEmployee $employee, string $slot, mixed $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }
        return $this->signedUrlGenerator->generate((string) $employee->uuid, $slot);
    }

    /**
     * Vertrags-URL: nur generiert wenn ein signierter Vertrag des
     * entsprechenden Typs existiert. Greift auf den Original-Bewerber
     * zu (Verträge haengen weiterhin am rec_applicant, nicht am MA).
     */
    protected function contractUrl(RecEmployee $employee, string $type): ?string
    {
        if (!$employee->rec_applicant_id) {
            return null;
        }
        $query = DB::table('rec_contracts')
            ->join('rec_contract_templates', 'rec_contracts.rec_contract_template_id', '=', 'rec_contract_templates.id')
            ->where('rec_contracts.rec_applicant_id', $employee->rec_applicant_id)
            ->whereNotNull('rec_contracts.signed_at');
        $query = match ($type) {
            'arbeitsvertrag' => $query->where('rec_contract_templates.code', 'like', 'AV%'),
            'ifsg'           => $query->where('rec_contract_templates.code', '=', 'IFSG'),
            default          => $query,
        };
        if (!$query->exists()) {
            return null;
        }
        $slot = $type === 'ifsg' ? 'emp-ifsg' : 'emp-vertrag';
        return $this->signedUrlGenerator->generate((string) $employee->uuid, $slot);
    }

    // ------------------------------------------------------------------
    // Computed-Felder
    // ------------------------------------------------------------------

    protected function hasAufenthaltOrGenehmigung(RecEmployee $employee): bool
    {
        return $employee->aufenthaltstitel_front_file_id !== null
            || $employee->aufenthaltstitel_back_file_id !== null
            || $employee->residence_permit_valid_until !== null
            || $employee->work_permit_valid_until !== null
            || $employee->visumsblatt_file_id !== null
            || $employee->zusatzblatt_file_id !== null;
    }

    /**
     * signed_at des IFSG-Vertrags vom Bewerber. Null wenn kein
     * Bewerber-Link, kein IFSG-Template oder noch nicht signed.
     */
    protected function ifsgSignedAt(RecEmployee $employee): ?Carbon
    {
        if (!$employee->rec_applicant_id) {
            return null;
        }
        $row = DB::table('rec_contracts')
            ->join('rec_contract_templates', 'rec_contracts.rec_contract_template_id', '=', 'rec_contract_templates.id')
            ->where('rec_contracts.rec_applicant_id', $employee->rec_applicant_id)
            ->where('rec_contract_templates.code', '=', 'IFSG')
            ->whereNotNull('rec_contracts.signed_at')
            ->orderByDesc('rec_contracts.signed_at')
            ->select('rec_contracts.signed_at')
            ->first();
        if (!$row || !$row->signed_at) {
            return null;
        }
        try {
            return Carbon::parse($row->signed_at);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function ifsgValidUntil(RecEmployee $employee): ?Carbon
    {
        $signed = $this->ifsgSignedAt($employee);
        if (!$signed) {
            return null;
        }
        return $signed->copy()->addYears(2);
    }

    /**
     * Vertragsbeginn (Eintrittsdatum) und Vertragsende (BefristetBis) —
     * werden in der Schulungsnachbereitung als vertragsbeginn/vertragsende
     * extra_field auf den AV-Vertrag geschrieben. Wir nehmen den juengsten
     * nicht-cancelled AV-Vertrag des Bewerbers als Quelle der Wahrheit
     * (nicht den hrData-Snapshot — der koennte veraltet sein wenn HR den
     * Vertrag nachtraeglich editiert).
     */
    protected function avContractStartDate(RecEmployee $employee): ?Carbon
    {
        return $this->avContractExtraDate($employee, 'vertragsbeginn');
    }

    protected function avContractEndDate(RecEmployee $employee): ?Carbon
    {
        return $this->avContractExtraDate($employee, 'vertragsende');
    }

    protected function avContractExtraDate(RecEmployee $employee, string $field): ?Carbon
    {
        if (!$employee->rec_applicant_id) {
            return null;
        }
        $contract = \Platform\Recruiting\Models\RecContract::query()
            ->where('rec_applicant_id', $employee->rec_applicant_id)
            ->whereNotIn('status', ['cancelled'])
            ->whereHas('contractTemplate', fn ($q) => $q->where('code', 'like', 'AV%'))
            ->orderByDesc('id')
            ->first();
        if (!$contract) {
            return null;
        }
        $value = $contract->getExtraField($field);
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
