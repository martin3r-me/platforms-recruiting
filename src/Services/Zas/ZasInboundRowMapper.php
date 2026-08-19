<?php

namespace Platform\Recruiting\Services\Zas;

use Carbon\Carbon;

/**
 * Bildet eine ZAS-CSV-Datenzeile (Header→Wert-Map) auf RecEmployee- und
 * RecEmployeeHrData-Feld-Arrays ab. Inversion der ZasEmployeeFieldResolver-Tabelle.
 * Reine Transformation, keine DB-Schreibzugriffe.
 */
class ZasInboundRowMapper
{
    /** CSV-Spalte → rec_employees-Spalte (String, getrimmt) */
    private const DIRECT = [
        'Name' => 'last_name', 'Vorname' => 'first_name', 'Geburtsname' => 'birth_name',
        'Geburtsort' => 'birth_place', 'AusweisNr' => 'identity_card_number',
        'Telefon' => 'phone', 'Email' => 'email', 'Strasse' => 'street',
        'Hausnummer' => 'house_number', 'PLZ' => 'zip', 'Ort' => 'city',
        'Bank' => 'bank_institute', 'IBAN' => 'iban', 'BIC' => 'bic',
        'Kontoinhaber' => 'account_holder', 'Steuerklasse' => 'tax_class',
        'SteuerID' => 'steuer_id', 'SVNummer' => 'sozialversicherungsnummer',
        'Fuehrerschein' => 'drivers_license_class',
        'GeworbenVonPersNr' => 'recruited_by_personnel_number', 'HemdGroesse' => 'shirt_size',
        'Kostenstelle' => 'cost_center',
    ];

    /** CSV-Spalte → rec_employees-Datumsspalte (d.m.Y → Y-m-d) */
    private const DATES = [
        'Geburtsdatum' => 'birth_date', 'AusweisBis' => 'identity_card_valid_until',
        'AufenthaltsErlaubnisBis' => 'residence_permit_valid_until',
        'ArbeitsGenehmigungBis' => 'work_permit_valid_until',
        'SchulBeschGueltigBis' => 'school_certificate_valid_until',
        'InfekErstbescheinigung' => 'infection_protection_first_issued_at',
        'Eintritt' => 'employed_since',
        'ErsthelferBis' => 'first_aider_valid_until',
    ];

    /** CSV-Spalte → rec_employees-Integer-Spalte */
    private const INTS = [
        'KinderAnzahl' => 'number_of_children', 'HosenGroesse' => 'pants_size', 'SchuhGroesse' => 'shoe_size',
    ];

    /** CSV-Spalte → rec_employees-Bool-Spalte (Ja/Nein) */
    private const BOOLS = [
        'PKW' => 'has_car', 'EUBuerger' => 'is_eu_citizen',
        'Ersthelfer' => 'is_first_aider', 'Sicherheitsbeauftragter' => 'is_safety_officer',
    ];

    /** CSV-Spalte → [field, lookup, prefix] auf rec_employees */
    private const LOOKUPS = [
        'Geschlecht'    => ['gender', 'geschlecht', false],
        'Familienstand' => ['marital_status', 'familienstand', false],
        'Religion'      => ['religion', 'religion', false],
        'Krankenkasse'  => ['health_insurance', 'krankenkasse', false],
        'Ichbin'        => ['employment_type', 'beschaeftigung_art', false],
        'Nation'        => ['birth_country', 'geburtsland', false],
    ];

    /** CSV-Spalte → rec_employee_hr_data-Datumsspalte */
    private const HR_DATES = [
        'VertragVersendetAm' => 'contract_sent_date',
        'VertragZurueckAm'   => 'contract_signed_at',
        'BefristetBis'       => 'contract_end_date',
    ];

    public function __construct(private ZasLookupReverseResolver $lookups) {}

    public function map(array $row): array
    {
        $get = fn (string $col): string => trim((string) ($row[$col] ?? ''));
        $employee = [];
        $hr = [];
        $warnings = [];

        foreach (self::DIRECT as $col => $field) {
            $v = $get($col);
            if ($v !== '') {
                $employee[$field] = $v;
            }
        }
        foreach (self::DATES as $col => $field) {
            $v = $get($col);
            $d = $this->date($v);
            if ($d !== null) {
                $employee[$field] = $d;
            } elseif ($v !== '') {
                $warnings[] = "{$field}: '{$v}' kein gueltiges Datum (TT.MM.JJJJ erwartet) — leer gelassen";
            }
        }
        foreach (self::INTS as $col => $field) {
            $v = $get($col);
            if ($v !== '' && is_numeric($v)) {
                $employee[$field] = (int) $v;
            }
        }
        foreach (self::BOOLS as $col => $field) {
            $v = $get($col);
            if ($v !== '') {
                $employee[$field] = mb_strtolower($v) === 'ja';
            }
        }
        // Arbeitsschutz-Kopplung: Ersthelfer=Ja verlangt fachlich ein
        // Bis-Datum. Lenient: trotzdem importieren, aber warnen — der
        // Datumspflicht-Guard der HR-Maske erzwingt die Reparatur beim
        // naechsten Edit. array_key_exists ist korrekt: die DATES-Schleife
        // setzt den Ziel-Key bei leerem UND bei unparsebarem Datum gar
        // nicht (nur `if ($d !== null)` schreibt, RowMapper:82-87).
        if (($employee['is_first_aider'] ?? false) === true
            && !array_key_exists('first_aider_valid_until', $employee)) {
            $warnings[] = "first_aider_valid_until: Ersthelfer=Ja ohne gueltiges Bis-Datum — bitte in der HR-Ansicht nachpflegen";
        }

        foreach (self::LOOKUPS as $col => [$field, $lookup, $prefix]) {
            $v = $get($col);
            if ($v === '') {
                continue;
            }
            $res = $this->lookups->resolve($lookup, $v, $prefix);
            $employee[$field] = $res['value'];
            if (!$res['matched']) {
                $warnings[] = "{$field}: '{$v}' roh gespeichert (kein Lookup-Treffer)";
            }
        }

        // Land → country_code (kein Lookup; Default 'de' wenn leer)
        $land = $get('Land');
        $employee['country_code'] = $land !== '' ? $land : 'de';

        // HR-Daten
        foreach (self::HR_DATES as $col => $field) {
            $v = $get($col);
            $d = $this->date($v);
            if ($d !== null) {
                $hr[$field] = $d;
            } elseif ($v !== '') {
                $warnings[] = "{$field}: '{$v}' kein gueltiges Datum (TT.MM.JJJJ erwartet) — leer gelassen";
            }
        }
        $status = $get('Status');
        if ($status !== '') {
            $hr['export_status'] = mb_strtoupper($status); // "go" → "GO"
        }

        // StatusMASeit — Tag der Umstellung GO→MA. Bewusst NICHT in HR_DATES:
        // dort bedeutet ein leerer Wert "nicht anfassen", hier muss er LOESCHEN
        // koennen (ZAS leert das Feld beim Zuruecksetzen auf GO).
        //
        // Damit eine kaputte Lieferung (Spalte versehentlich leer) nicht den
        // ganzen Bestand abraeumt, ist das Loeschen an die Status-Spalte
        // derselben Zeile gekoppelt: nur ein Status != MA bestaetigt die
        // Rueckstellung. Konvention nach oben: Key fehlt = nicht anfassen,
        // Key = null = aktiv leeren.
        $statusUpper = mb_strtoupper($status);
        $maSince     = $get('StatusMASeit');
        if (!array_key_exists('StatusMASeit', $row)) {
            // Bewusst OHNE Warnung: eine Lieferung ohne die Spalte traegt keine
            // Information ueber das Feld (z.B. jede Lieferung vor dem ZAS-Ausbau).
            // Eine Warnung pro Zeile waere hunderte Zeilen Rauschen; ob die Spalte
            // dabei war, steht ohnehin in den erkannten Spalten der Lieferung.
            $maSince = '';
        } elseif ($statusUpper === '') {
            $warnings[] = "status_ma_since: Status fehlt in derselben Zeile — Wert unveraendert (Loeschen nur mit Status-Bestaetigung)";
        } elseif ($statusUpper === 'MA') {
            $d = $this->date($maSince);
            if ($d !== null) {
                $hr['status_ma_since'] = $d;
            } else {
                // Leer oder unparsebar BEI Status=MA ist kein Zuruecksetzen,
                // sondern ein Lieferfehler — Bestandswert bleibt stehen.
                $warnings[] = "status_ma_since: Status=MA, aber '{$maSince}' ist kein gueltiges Datum (TT.MM.JJJJ erwartet) — Wert unveraendert";
            }
        } else {
            $hr['status_ma_since'] = null;
            if ($maSince !== '') {
                $warnings[] = "status_ma_since: Status={$statusUpper}, aber Datum '{$maSince}' geliefert — Wert geleert";
            }
        }
        $anst = $get('Anstellungsart');
        if ($anst !== '') {
            $res = $this->lookups->resolve('anstellungsart', $anst, true);
            $hr['employment_classification'] = $res['value'];
            if (!$res['matched']) {
                $warnings[] = "employment_classification: '{$anst}' roh gespeichert (kein Lookup-Treffer)";
            }
        }

        return [
            'uuid'              => $get('UUID') !== '' ? $get('UUID') : null,
            'personnel_number'  => $get('ZasPersonalNr') !== '' ? $get('ZasPersonalNr') : null,
            'employee'          => $employee,
            'hr'       => $hr,
            'warnings' => $warnings,
        ];
    }

    /**
     * Parst strikt TT.MM.JJJJ (das ZAS-Format) — sonst null. Bewusst KEIN
     * Carbon::parse-Fallback: der wuerde kaputte Strings ("2018", "13.2024",
     * vertauschte Formate) still in plausible-aber-falsche Daten verwandeln.
     * Fehlende Daten fallen im Portal/HR auf, falsche nicht.
     *
     * Roundtrip-Check faengt zusaetzlich Overflow-Rollover ab (32.01.2020
     * wuerde createFromFormat sonst als 01.02.2020 akzeptieren).
     */
    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            $dt = Carbon::createFromFormat('d.m.Y', $value);
        } catch (\Throwable) {
            return null;
        }
        if ($dt === false || ($dt->format('d.m.Y') !== $value && $dt->format('j.n.Y') !== $value)) {
            return null;
        }
        return $dt->format('Y-m-d');
    }
}
