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

    /**
     * Zeichen-Obergrenzen der Zielspalten (Stand der Migrationen).
     *
     * Warum ueberhaupt: ein zu langer Wert in einem NEBENfeld liess bisher die
     * ganze Zeile in SQLSTATE 22001 laufen — der Mensch fehlte danach komplett
     * im System (Massenimport 2026-08-25: `Fuehrerschein` enthielt einen
     * Freitext-Satz gegen string(32)).
     *
     * Wird eine Grenze ueberschritten, bleibt das Feld LEER und es gibt eine
     * Warnung MIT Originalwert. Bewusst kein Kappen: ein abgeschnittener Satz
     * ist ein plausibel aussehender Falschwert. Gleiche Haltung wie date()
     * weiter unten — fehlende Daten fallen auf, falsche nicht.
     *
     * Pflegehinweis: Spaltenbreite in einer Migration geaendert? Hier mit.
     */
    private const MAX_LENGTHS = [
        // rec_employees — Stammdaten
        'first_name' => 120, 'last_name' => 120, 'birth_name' => 120, 'birth_place' => 120,
        'identity_card_number' => 64,
        // Kontakt / Adresse
        'phone' => 64, 'email' => 255,
        'street' => 255, 'house_number' => 16, 'zip' => 16, 'city' => 120,
        'country_code' => 64,
        // Bank
        'bank_institute' => 120, 'iban' => 64, 'bic' => 32, 'account_holder' => 120,
        // Steuer / Versicherung
        'tax_class' => 1, 'steuer_id' => 32, 'sozialversicherungsnummer' => 32,
        'health_insurance' => 64,
        // Lookup-Ziele (koennen Rohwerte tragen, wenn kein Treffer)
        'gender' => 32, 'marital_status' => 32, 'religion' => 32,
        'employment_type' => 64, 'birth_country' => 64,
        // Sonstiges
        'drivers_license_class' => 32, 'recruited_by_personnel_number' => 64,
        'cost_center' => 32, 'shirt_size' => 8,
        // rec_employee_hr_data
        'employment_classification' => 32,
    ];

    /** CSV-Spalte → rec_employee_hr_data-Datumsspalte */
    private const HR_DATES = [
        'VertragVersendetAm' => 'contract_sent_date',
        'VertragZurueckAm'   => 'contract_signed_at',
        'BefristetBis'       => 'contract_end_date',
    ];

    /**
     * ZAS-Spalten, die map() von Hand liest — Default, Sonderregel oder
     * Schluessel, jedenfalls nicht ueber eine der Tabellen oben.
     */
    private const HANDLED_SEPARATELY = ['Land', 'Status', 'StatusMASeit', 'Anstellungsart', 'UUID', 'ZasPersonalNr'];

    /**
     * Alle ZAS-Spalten, aus denen map() ueberhaupt etwas uebernimmt.
     *
     * Zweck: der Spalten-Bericht (recruiting:zas-inbound-columns) soll die
     * Spalte "gelesen?" belegen statt sie zu pflegen — eine zweite,
     * handgefuehrte Liste wuerde beim naechsten neuen Feld auseinanderlaufen
     * und eine gelesene Spalte als Luecke ausweisen.
     *
     * @return list<string>
     */
    public static function knownColumns(): array
    {
        $columns = array_merge(
            array_keys(self::DIRECT),
            array_keys(self::DATES),
            array_keys(self::INTS),
            array_keys(self::BOOLS),
            array_keys(self::LOOKUPS),
            array_keys(self::HR_DATES),
            self::HANDLED_SEPARATELY,
        );

        return array_values(array_unique($columns));
    }

    public function __construct(private ZasLookupReverseResolver $lookups) {}

    public function map(array $row): array
    {
        $get = fn (string $col): string => trim((string) ($row[$col] ?? ''));
        $employee = [];
        $hr = [];
        $warnings = [];

        foreach (self::DIRECT as $col => $field) {
            $v = $get($col);
            if ($v === '') {
                continue;
            }
            $tooLong = $this->lengthWarning($field, $v);
            if ($tooLong !== null) {
                $warnings[] = $tooLong;
                continue;
            }
            $employee[$field] = $v;
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
            // Ohne Lookup-Treffer landet der ROHWERT in der Spalte — der kann
            // beliebig lang sein (Freitext in einem Code-Feld).
            $tooLong = $this->lengthWarning($field, (string) $res['value']);
            if ($tooLong !== null) {
                $warnings[] = $tooLong;
                continue;
            }
            $employee[$field] = $res['value'];
            if (!$res['matched']) {
                $warnings[] = "{$field}: '{$v}' roh gespeichert (kein Lookup-Treffer)";
            }
        }

        // Land → country_code (kein Lookup; Default 'de' wenn leer)
        // Ein unbrauchbar langer Wert wird wie "nicht geliefert" behandelt und
        // faellt auf denselben Default zurueck — mit Warnung.
        $land = $get('Land');
        if ($land !== '') {
            $tooLong = $this->lengthWarning('country_code', $land);
            if ($tooLong !== null) {
                $warnings[] = $tooLong;
                $land = '';
            }
        }
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
            $res     = $this->lookups->resolve('anstellungsart', $anst, true);
            $tooLong = $this->lengthWarning('employment_classification', (string) $res['value']);
            if ($tooLong !== null) {
                $warnings[] = $tooLong;
            } else {
                $hr['employment_classification'] = $res['value'];
                if (!$res['matched']) {
                    $warnings[] = "employment_classification: '{$anst}' roh gespeichert (kein Lookup-Treffer)";
                }
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
     * Prueft die Zeichenlaenge gegen die Zielspalte.
     *
     * @return string|null Warntext, wenn der Wert NICHT gespeichert werden darf;
     *                     null, wenn er passt (oder die Spalte keine Grenze hat).
     *
     * mb_strlen statt strlen: MySQL-VARCHAR(n) zaehlt unter utf8mb4 Zeichen,
     * nicht Bytes — mit strlen wuerden Werte mit Umlauten grundlos abgewiesen.
     */
    private function lengthWarning(string $field, string $value): ?string
    {
        $max = self::MAX_LENGTHS[$field] ?? null;
        if ($max === null) {
            return null;
        }
        $len = mb_strlen($value);
        if ($len <= $max) {
            return null;
        }

        return "{$field}: Wert ist {$len} Zeichen lang, erlaubt sind {$max} — nicht uebernommen,"
            . " bitte in ZAS pruefen: '{$value}'";
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
