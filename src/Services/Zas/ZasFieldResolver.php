<?php

namespace Platform\Recruiting\Services\Zas;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Mappt einen RecApplicant auf das assoziative Array
 * [ZAS-CSV-Spalte → String-Wert].
 *
 * Kern-Aufgabe: vereinheitlicht die unterschiedlichen Field-Keys aus
 * alter (z. B. Stelle Duesseldorf) und neuer Phase-Logik (Sandbox)
 * via Fallback-Reihenfolge — erster Treffer gewinnt.
 *
 * Datenquellen:
 *   - rec_applicants (id, uuid, created_at)
 *   - core_extra_field_values (Stammdaten, Adresse, Onboarding-Felder)
 *   - rec_contracts (sent_at / signed_at fuer UplVertrag)
 *   - rec_interview_bookings → rec_interviews → rec_event_locations
 *     (fuer SchulungsStandort)
 *
 * Format-Konventionen siehe docs/meingedeck/zas-applicant-export.md
 */
class ZasFieldResolver
{
    public function __construct(
        protected ZasLookupResolver $lookupResolver,
        protected ZasSignedUrlGenerator $signedUrlGenerator,
    ) {}

    /**
     * Spalten-Reihenfolge muss exakt zum ZAS-Header passen — Hr. Michel
     * importiert positional, nicht per Spaltennamen-Mapping.
     */
    public const COLUMNS = [
        'Name', 'Vorname', 'Strasse', 'PLZ', 'Ort', 'Geburtsdatum',
        'Familienstand', 'EUBuerger', 'Ichbin',
        'DateiUpload', 'FDatum',
        'Geburtsname', 'Geschlecht', 'Nation',
        'SVNummer', 'AusweisNr', 'SteuerID', 'AusweisBis',
        'Bank', 'IBAN', 'BIC', 'Fuehrerschein', 'PKW',
        'Telefon', 'Email', 'Krankenkasse', 'Geburtsort',
        'Impfschutz', 'SchulungsStandort',
        'UplArbErl', 'UplVersicher', 'UplAuweis', 'UplImma', 'UplImpf',
        'UplAusw2', 'UplSelfie', 'UplArbErl2', 'UplZusatzblatt', 'UplFiktion',
        'Immabis',
        // Erweiterungen (Hr. Michel ergaenzt am Ende seiner DB)
        'UplFiktion2', 'UplVisum', 'UplVertrag',
    ];

    /**
     * Vorzug: erster vorhandener Field-Key gewinnt. Hilft die alte und
     * neue Phase-Logik auf dasselbe ZAS-Slot abzubilden.
     *
     * Wird auch vom ZasFileController genutzt (Slot → Field-Keys).
     */
    public const FILE_FIELD_FALLBACKS = [
        'upl-arberl'      => ['aufenthaltstitel_vorderseite'],
        'upl-arberl2'     => ['aufenthaltstitel_ruckseite'],
        'upl-versicher'   => ['foto_versichertenkarte', 'foto_versicherungskarte'],
        'upl-auweis'      => ['ausweis_reisepass_foto_vorderseite', 'foto_ausweis_vorderseite'],
        'upl-ausw2'       => ['ausweis_reisepass_foto_ruckseite', 'foto_ausweis_ruckseite'],
        'upl-selfie'      => ['selfie_upload'],
        'upl-imma'        => ['immatrikulationsbescheinigung', 'immatrikulationsbescheinigung_schulbescheinigung'],
        'upl-zusatzblatt' => ['zusatzblatt_arbeitsgenehmigung_vorderseite', 'zusatzblatt'],
        'upl-fiktion'     => ['fiktionsbescheinigung_vorderseite'],
        // Erweiterungen
        'upl-fiktion2'    => ['fiktionsbescheinigung_ruckseite'],
        'upl-visum'       => ['visum_foto', 'visumsblatt'],
        // upl-vertrag wird nicht aus extra_fields aufgeloest, sondern
        // direkt aus rec_contracts (signed_at IS NOT NULL) — siehe Logik unten.
    ];

    /**
     * In-Run Cache von Definition-Lookups. Pro Field-Name → definition_id (oder null).
     */
    protected array $definitionIdCache = [];

    /**
     * Pre-loaded Extra-Field-Values pro Applicant-ID (definition_id → value).
     */
    protected array $applicantExtraFields = [];

    /**
     * Erzeugt die Spalten-Werte fuer einen Bewerber.
     *
     * @return array<string, string>  ZAS-Spaltenname → String-Wert
     */
    public function resolve(RecApplicant $applicant): array
    {
        $this->preloadExtraFields($applicant);

        $row = [];
        foreach (self::COLUMNS as $column) {
            $row[$column] = (string) ($this->resolveColumn($applicant, $column) ?? '');
        }
        return $row;
    }

    protected function resolveColumn(RecApplicant $applicant, string $column): ?string
    {
        return match ($column) {
            'Name'               => $this->getStringField($applicant, ['nachname']),
            'Vorname'            => $this->getStringField($applicant, ['vorname']),
            'Strasse'            => $this->getStrasseConcat($applicant),
            'PLZ'                => $this->getStringField($applicant, ['plz']),
            'Ort'                => $this->getStringField($applicant, ['stadt']),
            'Geburtsdatum'       => $this->getDateField($applicant, ['geburtsdatum']),
            'Familienstand'      => $this->getLookupField($applicant, ['familienstand']),
            'EUBuerger'          => $this->getBooleanField($applicant, ['eu_burger']),
            'Ichbin'             => $this->getLookupField($applicant, ['ich_bin']),
            'DateiUpload'        => $this->getFileUrl($applicant, 'upl-selfie'), // Hr. Michel bestaetigt: = Profilbild
            'FDatum'             => $this->formatDate($applicant->created_at),
            'Geburtsname'        => $this->getStringField($applicant, ['geburtsname']),
            'Geschlecht'         => $this->getLookupField($applicant, ['geschlecht']),
            'Nation'             => $this->getLookupField($applicant, ['geburtsland']),
            'SVNummer'           => $this->getStringField($applicant, ['sozialversicherungsnummer']),
            'AusweisNr'          => $this->getStringField($applicant, ['ausweisnummer']),
            'SteuerID'           => $this->getStringField($applicant, ['steuer_id']),
            'AusweisBis'         => $this->getDateField($applicant, ['ausweis_gultig_bis']),
            'Bank'               => $this->getStringField($applicant, ['geldinstitut']),
            'IBAN'               => $this->getStringField($applicant, ['iban']),
            'BIC'                => $this->getStringField($applicant, ['bic']),
            'Fuehrerschein'      => $this->getStringField($applicant, ['fuhrerschein_klasse']),
            'PKW'                => $this->getBooleanField($applicant, ['pkw_vorhanden']),
            'Telefon'            => $this->getStringField($applicant, ['telefonnummer']),
            'Email'              => $this->getStringField($applicant, ['email']),
            'Krankenkasse'       => $this->getLookupField($applicant, ['krankenkasse']),
            'Geburtsort'         => $this->getStringField($applicant, ['geburtsort']),
            'Impfschutz'         => null, // nicht erfasst
            'SchulungsStandort'  => $this->getSchulungsStandort($applicant),
            'UplArbErl'          => $this->getFileUrl($applicant, 'upl-arberl'),
            'UplVersicher'       => $this->getFileUrl($applicant, 'upl-versicher'),
            'UplAuweis'          => $this->getFileUrl($applicant, 'upl-auweis'),
            'UplImma'            => $this->getFileUrl($applicant, 'upl-imma'),
            'UplImpf'            => null, // nicht erfasst
            'UplAusw2'           => $this->getFileUrl($applicant, 'upl-ausw2'),
            'UplSelfie'          => $this->getFileUrl($applicant, 'upl-selfie'),
            'UplArbErl2'         => $this->getFileUrl($applicant, 'upl-arberl2'),
            'UplZusatzblatt'     => $this->getFileUrl($applicant, 'upl-zusatzblatt'),
            'UplFiktion'         => $this->getFileUrl($applicant, 'upl-fiktion'),
            'Immabis'            => null, // nicht erfasst
            'UplFiktion2'        => $this->getFileUrl($applicant, 'upl-fiktion2'),
            'UplVisum'           => $this->getFileUrl($applicant, 'upl-visum'),
            'UplVertrag'         => $this->getContractUrl($applicant),
        };
    }

    // ------------------------------------------------------------------
    // Field-Type-Helpers
    // ------------------------------------------------------------------

    /**
     * Liefert den ersten nicht-leeren Wert aus dem Fallback-Pfad.
     *
     * Phone-Felder werden als JSON-Objekt gespeichert
     * ({"raw":"...","e164":"+49...","international":"+49 ..."}). Wir
     * geben den E.164-String aus — das ist das Format das Hr. Michel
     * im Bestands-CSV hatte.
     */
    protected function getStringField(RecApplicant $applicant, array $fieldNames): ?string
    {
        foreach ($fieldNames as $name) {
            $value = $this->getRawExtraField($applicant, $name);
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                // Phone-Object → bevorzugt e164, dann international, dann raw
                if (isset($value['e164'])) {
                    return (string) $value['e164'];
                }
                if (isset($value['international'])) {
                    return (string) $value['international'];
                }
                if (isset($value['raw'])) {
                    return (string) $value['raw'];
                }
                // Sonstiges Multi-Value → comma-separiert
                return implode(', ', array_map(fn ($v) => (string) $v, $value));
            }
            return (string) $value;
        }
        return null;
    }

    protected function getDateField(RecApplicant $applicant, array $fieldNames): ?string
    {
        foreach ($fieldNames as $name) {
            $value = $this->getRawExtraField($applicant, $name);
            if ($value === null || $value === '') {
                continue;
            }
            return $this->formatDate($value);
        }
        return null;
    }

    protected function getBooleanField(RecApplicant $applicant, array $fieldNames): ?string
    {
        foreach ($fieldNames as $name) {
            $value = $this->getRawExtraField($applicant, $name);
            if ($value === null || $value === '') {
                continue;
            }
            // CoreExtraFieldValue speichert Booleans als "0"/"1"-Strings
            $isTrue = in_array(strtolower((string) $value), ['1', 'true', 'ja', 'yes'], true);
            return $isTrue ? 'Ja' : 'Nein';
        }
        return null;
    }

    protected function getLookupField(RecApplicant $applicant, array $fieldNames): ?string
    {
        foreach ($fieldNames as $name) {
            $defId = $this->getDefinitionId($applicant, $name);
            if (!$defId) {
                continue;
            }
            $value = $this->applicantExtraFields[$applicant->id][$defId] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $label = $this->lookupResolver->resolveLabel($defId, $value);
            if ($label !== null && $label !== '') {
                return $label;
            }
        }
        return null;
    }

    /**
     * Strasse + Hausnummer concat. ZAS hat eine einzige `Strasse`-Spalte.
     */
    protected function getStrasseConcat(RecApplicant $applicant): ?string
    {
        $strasse = trim((string) ($this->getRawExtraField($applicant, 'strasse') ?? ''));
        $hausnummer = trim((string) ($this->getRawExtraField($applicant, 'hausnummer') ?? ''));

        if ($strasse === '' && $hausnummer === '') {
            return null;
        }
        if ($strasse === '') {
            return $hausnummer;
        }
        if ($hausnummer === '') {
            return $strasse;
        }
        return $strasse . ' ' . $hausnummer;
    }

    /**
     * Schulungs-Standort aus dem letzten Interview-Booking.
     *
     * rec_interviews speichert den Standort direkt im Free-Text-Feld
     * `location`. rec_event_locations existiert zwar als Lookup-Tabelle,
     * ist aber nicht per FK an rec_interviews gehaengt — die UI bietet
     * nur einen Dropdown an, gespeichert wird der Anzeigetext direkt.
     */
    protected function getSchulungsStandort(RecApplicant $applicant): ?string
    {
        $row = DB::table('rec_interview_bookings as b')
            ->join('rec_interviews as i', 'b.rec_interview_id', '=', 'i.id')
            ->where('b.rec_applicant_id', $applicant->id)
            ->whereNull('b.deleted_at')
            ->orderByDesc('b.booked_at')
            ->select('i.location')
            ->first();

        $text = trim((string) ($row->location ?? ''));
        return $text === '' ? null : $text;
    }

    /**
     * Erzeugt eine signierte URL fuer einen Datei-Slot, falls fuer
     * diesen Bewerber tatsaechlich eine Datei vorliegt. Sonst null.
     */
    protected function getFileUrl(RecApplicant $applicant, string $slot): ?string
    {
        $candidateFields = self::FILE_FIELD_FALLBACKS[$slot] ?? [];
        $hasFile = false;
        foreach ($candidateFields as $name) {
            $value = $this->getRawExtraField($applicant, $name);
            if ($value === null || $value === '') {
                continue;
            }
            // value kann file-id (int) oder array sein
            if (is_array($value)) {
                $hasFile = count($value) > 0;
            } else {
                $hasFile = (int) $value > 0;
            }
            if ($hasFile) {
                break;
            }
        }
        if (!$hasFile) {
            return null;
        }
        return $this->signedUrlGenerator->generate((string) $applicant->uuid, $slot);
    }

    /**
     * UplVertrag-URL: nur wenn der Bewerber mindestens einen
     * unterschriebenen Vertrag hat. Stream-Pfad geht im ZasFileController
     * an den ContractPdfController.
     */
    protected function getContractUrl(RecApplicant $applicant): ?string
    {
        $hasSignedContract = DB::table('rec_contracts')
            ->where('rec_applicant_id', $applicant->id)
            ->whereNotNull('signed_at')
            ->exists();

        if (!$hasSignedContract) {
            return null;
        }
        return $this->signedUrlGenerator->generate((string) $applicant->uuid, 'upl-vertrag');
    }

    // ------------------------------------------------------------------
    // Extra-Field-Loading
    // ------------------------------------------------------------------

    /**
     * Laedt alle relevanten core_extra_field_values eines Bewerbers in
     * einem Query in den Cache.
     */
    protected function preloadExtraFields(RecApplicant $applicant): void
    {
        if (isset($this->applicantExtraFields[$applicant->id])) {
            return;
        }

        $this->applicantExtraFields[$applicant->id] = DB::table('core_extra_field_values')
            ->where('fieldable_type', 'rec_applicant')
            ->where('fieldable_id', $applicant->id)
            ->pluck('value', 'definition_id')
            ->all();
    }

    /**
     * Holt den Raw-Wert eines Extra-Fields fuer einen Bewerber.
     */
    protected function getRawExtraField(RecApplicant $applicant, string $name): mixed
    {
        $defId = $this->getDefinitionId($applicant, $name);
        if (!$defId) {
            return null;
        }
        $value = $this->applicantExtraFields[$applicant->id][$defId] ?? null;
        if ($value === null) {
            return null;
        }
        // Multi-File / Multi-Select als JSON-Array, Phone/Address als JSON-Object
        if (is_string($value)) {
            if ((str_starts_with($value, '[') && str_ends_with($value, ']'))
                || (str_starts_with($value, '{') && str_ends_with($value, '}'))) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        return $value;
    }

    /**
     * Resolved Field-Name zu definition_id im Kontext des Bewerbers.
     *
     * Wir nutzen `getExtraFieldDefinitions()` vom HasExtraFields-Trait,
     * weil das auch geerbte Definitionen via extraFieldParents() findet
     * (Phase → Position → Posting Inheritance).
     */
    protected function getDefinitionId(RecApplicant $applicant, string $name): ?int
    {
        $cacheKey = $applicant->id . '|' . $name;
        if (array_key_exists($cacheKey, $this->definitionIdCache)) {
            return $this->definitionIdCache[$cacheKey];
        }

        $definition = $applicant->getExtraFieldDefinitions()->firstWhere('name', $name);
        $defId = $definition ? (int) $definition->id : null;

        $this->definitionIdCache[$cacheKey] = $defId;
        return $defId;
    }

    // ------------------------------------------------------------------
    // Format-Helpers
    // ------------------------------------------------------------------

    /**
     * Formatiert einen Datums-Wert nach ZAS-Konvention TT.MM.JJJJ.
     * Robust gegen verschiedene Eingabeformate.
     */
    protected function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon || $value instanceof \DateTimeInterface) {
            return Carbon::instance(Carbon::parse($value))->format('d.m.Y');
        }
        try {
            return Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
