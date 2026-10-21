<?php

namespace Platform\Recruiting\Services\TestEmployee;

use Platform\Recruiting\Support\NonEuDocumentMapping;

/**
 * Feldwerte der Test-MA-Profile (recruiting:test-ma --profil=…).
 *
 * Feldname → Wert, wie ein Bewerber sie im Public-Form eintraegt. Dazu zwei
 * Steuer-Schluessel:
 *  - `_files`: Datei-Felder, die das Profil befuellt (Platzhalterbild oder
 *    --datei). Ein eu-Test-MA hat keinen Aufenthaltstitel, kein Visum, keine
 *    Bescheinigung — sonst testen Portal-Bescheinigungen und Nicht-EU-Logik
 *    nichts (Spec „Ablauf Anlegen" 4).
 *  - `_eu`: geht in RecApplicantLegalStatus.is_eu_citizen.
 *
 * vorname/nachname/email setzt der Creator (Name aus --name, Zufalls-Mail).
 * Auswahlwerte (geschlecht, familienstand, …) sind Vorschlaege: der Creator
 * gleicht sie gegen die Optionen der Felddefinition ab und nimmt sonst den
 * ersten Optionswert.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class TestEmployeeProfiles
{
    public const PROFILES = ['eu', 'nicht-eu', 'student', 'schueler'];

    public const GEBURTSDATUM = '1995-01-01';

    /** Ausweis vorne/hinten, Selfie, Versichertenkarte — jedes Profil. */
    private const BASIS_DATEIEN = [
        'ausweis_reisepass_foto_vorderseite',
        'ausweis_reisepass_foto_ruckseite',
        'selfie_upload',
        'foto_versichertenkarte',
    ];

    /** Schul-/Immatrikulationsbescheinigung (aktuelles + aelteres Formularfeld). */
    private const BESCHEINIGUNG = [
        'immatrikulationsbescheinigung_schulbescheinigung',
        'immatrikulationsbescheinigung',
    ];

    /**
     * Nicht-EU-Felder ausser Dateien: nur das nicht-eu-Profil befuellt sie,
     * kein anderes bekommt dort einen Pflicht-Platzhalter.
     */
    private const NICHT_EU_DATEN = ['aufenthaltserlaubnis_bis', 'arbeitsgenehmigung_bis'];

    /** @return array<string, mixed> */
    public static function values(string $profil): array
    {
        if (!in_array($profil, self::PROFILES, true)) {
            throw new \InvalidArgumentException(
                "Unbekanntes Profil „{$profil}“. Erlaubt: " . implode(', ', self::PROFILES) . '.'
            );
        }

        $werte = [
            'geburtsdatum'              => self::GEBURTSDATUM,
            'geburtsort'                => 'Düsseldorf',
            'geburtsland'               => 'DE',
            'nationalitaet'             => 'DE',
            'strasse'                   => 'Teststraße',
            'hausnummer'                => '1',
            'plz'                       => '40210',
            'stadt'                     => 'Düsseldorf',
            'land'                      => 'DE',
            // Offizielle Test-IBAN der Commerzbank (Beispiel aus der Norm).
            'iban'                      => 'DE89370400440532013000',
            'bic'                       => 'COBADEFFXXX',
            'geldinstitut'              => 'Testbank',
            'steuer_id'                 => '12345678995',
            'sozialversicherungsnummer' => self::sozialversicherungsnummer('65', self::GEBURTSDATUM, 'T', '00'),
            'geschlecht'                => 'männlich',
            'familienstand'             => 'ledig',
            'krankenkasse'              => 'tk',
            '_files'                    => self::BASIS_DATEIEN,
            '_eu'                       => true,
        ];

        return match ($profil) {
            'eu'       => $werte,
            'nicht-eu' => array_merge($werte, [
                'nationalitaet'            => 'TR',
                'aufenthaltserlaubnis_bis' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
                'arbeitsgenehmigung_bis'   => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
                '_files'                   => array_values(array_unique(array_merge(
                    self::BASIS_DATEIEN,
                    ['nationalpass', 'zusatzblatt', 'visumsblatt'],
                    array_values(NonEuDocumentMapping::MAP),
                ))),
                '_eu'                      => false,
            ]),
            'student'  => array_merge($werte, [
                'ich_bin' => 'student',
                '_files'  => array_merge(self::BASIS_DATEIEN, self::BESCHEINIGUNG),
            ]),
            // Geburtsdatum bleibt volljaehrig: sonst greift der Minderjaehrigen-Pfad.
            'schueler' => array_merge($werte, [
                'ich_bin' => 'schueler',
                '_files'  => array_merge(self::BASIS_DATEIEN, self::BESCHEINIGUNG),
            ]),
        };
    }

    /**
     * Felder, die nur nach Profil befuellt werden — nie als Pflicht-Platzhalter.
     * Alle Datei-Felder, die irgendein Profil kennt, plus die Nicht-EU-Daten.
     * Grund: im echten Formular sind diese Felder oft Pflicht, aber per
     * Sichtbarkeitsregel nur fuer Nicht-EU/Student/Schueler zu sehen; die
     * Regeln wertet der Creator nicht aus (Spec „Ablauf Anlegen" 4).
     *
     * @return list<string>
     */
    public static function nurNachProfil(): array
    {
        $felder = self::NICHT_EU_DATEN;
        foreach (self::PROFILES as $profil) {
            $felder = array_merge($felder, self::values($profil)['_files']);
        }

        return array_values(array_unique($felder));
    }

    /**
     * Rentenversicherungsnummer mit Pruefziffer (DEUeV, Anlage 1 zu § 2 RVNG).
     *
     * Aufbau: Bereichsnummer (2) + Geburtsdatum TTMMJJ (6) + Anfangsbuchstabe
     * des Geburtsnamens (1) + Seriennummer (2) + Pruefziffer (1).
     *
     * Pruefziffer: der Buchstabe wird durch seine zweistellige Position im
     * Alphabet ersetzt (A=01 … Z=26) → 12 Ziffern. Diese werden mit
     * 2,1,2,5,7,1,2,1,2,1,2,1 multipliziert, von jedem Produkt die Quersumme
     * gebildet, alles addiert; Pruefziffer = Summe mod 10.
     */
    public static function sozialversicherungsnummer(string $bereich, string $geburtsdatum, string $buchstabe, string $serie): string
    {
        $datum = \DateTimeImmutable::createFromFormat('Y-m-d', $geburtsdatum)->format('dmy');
        $buchstabe = strtoupper($buchstabe);
        $position = str_pad((string) (ord($buchstabe) - ord('A') + 1), 2, '0', STR_PAD_LEFT);

        $ziffern = str_split($bereich . $datum . $position . $serie);
        $gewichte = [2, 1, 2, 5, 7, 1, 2, 1, 2, 1, 2, 1];

        $summe = 0;
        foreach ($ziffern as $i => $ziffer) {
            $produkt = (int) $ziffer * $gewichte[$i];
            $summe += intdiv($produkt, 10) + $produkt % 10;
        }

        return $bereich . $datum . $buchstabe . $serie . ($summe % 10);
    }
}
