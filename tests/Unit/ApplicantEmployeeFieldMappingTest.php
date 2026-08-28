<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ApplicantEmployeeFieldMapping;
use Platform\Recruiting\Support\NonEuDocumentMapping;

class ApplicantEmployeeFieldMappingTest extends TestCase
{
    public function test_maps_plain_text_fields_to_employee_columns(): void
    {
        $resolved = ApplicantEmployeeFieldMapping::resolve([
            'vorname'      => 'Majd',
            'nachname'     => 'Oreg',
            'geburtsort'   => 'Damascus',
            'iban'         => 'DE02120300000000202051',
            'geworben_von' => '1042',
        ]);

        $this->assertSame('Majd', $resolved['first_name']);
        $this->assertSame('Oreg', $resolved['last_name']);
        $this->assertSame('Damascus', $resolved['birth_place']);
        $this->assertSame('DE02120300000000202051', $resolved['iban']);
        $this->assertSame('1042', $resolved['recruited_by_personnel_number']);
    }

    public function test_source_field_names_match_the_phase_form_names(): void
    {
        // Schützt gegen Drift der Quell-Feldnamen (Ursache des Original-Bugs:
        // Mapping las aus Quellen, die es unter dem Namen nie gab).
        $map = ApplicantEmployeeFieldMapping::TEXT_MAP;
        $this->assertSame('vorname', $map['first_name']);
        $this->assertSame('nachname', $map['last_name']);
        $this->assertSame('geburtsdatum', $map['birth_date']);
        $this->assertSame('geldinstitut', $map['bank_institute']);
        $this->assertSame('ich_bin', $map['employment_type']);
        $this->assertSame('geschlecht', $map['gender']);
        $this->assertSame('krankenkasse', $map['health_insurance']);

        $this->assertSame('ausweis_gultig_bis', ApplicantEmployeeFieldMapping::DATE_MAP['identity_card_valid_until']);
        $this->assertSame('aufenthaltserlaubnis_bis', ApplicantEmployeeFieldMapping::DATE_MAP['residence_permit_valid_until']);
        $this->assertSame('arbeitsgenehmigung_bis', ApplicantEmployeeFieldMapping::DATE_MAP['work_permit_valid_until']);

        $this->assertSame('ausweis_reisepass_foto_vorderseite', ApplicantEmployeeFieldMapping::FILE_MAP['identity_card_front_file_id']);
        $this->assertSame('selfie_upload', ApplicantEmployeeFieldMapping::FILE_MAP['selfie_file_id']);
        $this->assertSame('foto_versichertenkarte', ApplicantEmployeeFieldMapping::FILE_MAP['health_insurance_card_file_id']);
    }

    public function test_absent_and_empty_source_fields_are_omitted(): void
    {
        // Kein null-Ausschreiben: resolve() liefert NUR befuellte Spalten,
        // damit der Backfill vorhandene MA-Werte nie mit null ueberschreibt
        // und der Create-Flow Contact-Fallbacks behalten kann (array_merge).
        $resolved = ApplicantEmployeeFieldMapping::resolve([
            'vorname'  => 'Majd',
            'nachname' => '',
        ]);

        $this->assertSame(['first_name'], array_keys(array_intersect_key($resolved, ['first_name' => 1, 'last_name' => 1])));
        $this->assertArrayNotHasKey('last_name', $resolved);
        $this->assertArrayNotHasKey('birth_place', $resolved);
    }

    public function test_city_falls_back_from_stadt_to_ort(): void
    {
        $this->assertSame('Köln', ApplicantEmployeeFieldMapping::resolve(['stadt' => 'Köln'])['city']);
        $this->assertSame('Bonn', ApplicantEmployeeFieldMapping::resolve(['ort' => 'Bonn'])['city']);
        $this->assertSame('Köln', ApplicantEmployeeFieldMapping::resolve(['stadt' => 'Köln', 'ort' => 'Bonn'])['city']);
    }

    public function test_phone_extracts_e164_from_array_and_json(): void
    {
        $asArray = ApplicantEmployeeFieldMapping::resolve([
            'telefonnummer' => ['raw' => '015201882974', 'country' => 'DE', 'e164' => '+4915201882974'],
        ]);
        $this->assertSame('+4915201882974', $asArray['phone']);

        $asJson = ApplicantEmployeeFieldMapping::resolve([
            'telefonnummer' => '{"raw":"015201882974","e164":"+4915201882974"}',
        ]);
        $this->assertSame('+4915201882974', $asJson['phone']);

        $asString = ApplicantEmployeeFieldMapping::resolve(['telefonnummer' => '0152 01882974']);
        $this->assertSame('0152 01882974', $asString['phone']);
    }

    public function test_dates_normalize_german_and_iso_formats(): void
    {
        $r = ApplicantEmployeeFieldMapping::resolve([
            'ausweis_gultig_bis'       => '21.05.2030',
            'aufenthaltserlaubnis_bis' => '2027-03-01',
            'arbeitsgenehmigung_bis'   => 'kein datum',
        ]);
        $this->assertSame('2030-05-21', $r['identity_card_valid_until']);
        $this->assertSame('2027-03-01', $r['residence_permit_valid_until']);
        $this->assertArrayNotHasKey('work_permit_valid_until', $r);
    }

    public function test_multi_lookup_values_decode_to_arrays(): void
    {
        $r = ApplicantEmployeeFieldMapping::resolve([
            'beschaftigungsort' => '["duesseldorf","koeln"]',
            'art_der_tatigkeit' => ['servicekraft'],
        ]);
        $this->assertSame(['duesseldorf', 'koeln'], $r['beschaftigungsort']);
        $this->assertSame(['servicekraft'], $r['art_der_tatigkeit']);
    }

    public function test_bool_field_accepts_german_variants(): void
    {
        $this->assertTrue(ApplicantEmployeeFieldMapping::resolve(['pkw_vorhanden' => 'ja'])['has_car']);
        $this->assertFalse(ApplicantEmployeeFieldMapping::resolve(['pkw_vorhanden' => '0'])['has_car']);
        $this->assertArrayNotHasKey('has_car', ApplicantEmployeeFieldMapping::resolve(['pkw_vorhanden' => 'vielleicht']));
    }

    public function test_file_fields_cast_to_int_and_include_non_eu_documents(): void
    {
        $r = ApplicantEmployeeFieldMapping::resolve([
            'selfie_upload'                => '1797',
            'visum_foto'                   => '1798',
            'aufenthaltstitel_vorderseite' => '1801',
        ]);
        $this->assertSame(1797, $r['selfie_file_id']);
        $this->assertSame(1798, $r['visumsblatt_file_id']);
        $this->assertSame(1801, $r['aufenthaltstitel_front_file_id']);
        // Nicht hochgeladene Non-EU-Dokumente werden NICHT als null ausgegeben.
        $this->assertArrayNotHasKey('fiktionsbescheinigung_front_file_id', $r);
    }

    public function test_legacy_field_names_resolve_as_fallback(): void
    {
        // Aeltere Formular-Generationen (Definition-IDs 652ff., z.B. Bewerber
        // #1669) nutzten andere Feldnamen fuer dieselben Dokumente.
        $r = ApplicantEmployeeFieldMapping::resolve([
            'foto_ausweis_vorderseite'                         => '1552',
            'foto_ausweis_ruckseite'                           => '1553',
            'foto_versicherungskarte'                          => '1559',
            'zusatzblatt'                                      => '1556',
            'visumsblatt'                                      => '1560',
            'nationalpass'                                     => '1561',
            'immatrikulationsbescheinigung_schulbescheinigung' => '1563',
        ]);

        $this->assertSame(1552, $r['identity_card_front_file_id']);
        $this->assertSame(1553, $r['identity_card_back_file_id']);
        $this->assertSame(1559, $r['health_insurance_card_file_id']);
        $this->assertSame(1556, $r['zusatzblatt_file_id']);
        $this->assertSame(1560, $r['visumsblatt_file_id']);
        $this->assertSame(1561, $r['nationalpass_file_id']);
        $this->assertSame(1563, $r['immatrikulation_file_id']);
    }

    public function test_current_field_name_wins_over_legacy_alias(): void
    {
        $r = ApplicantEmployeeFieldMapping::resolve([
            'ausweis_reisepass_foto_vorderseite' => '100',
            'foto_ausweis_vorderseite'           => '200',
            'visum_foto'                         => '300',
            'visumsblatt'                        => '400',
        ]);

        $this->assertSame(100, $r['identity_card_front_file_id']);
        $this->assertSame(300, $r['visumsblatt_file_id']);
    }

    public function test_known_source_fields_span_current_and_legacy_names(): void
    {
        // Basis fuer den Unmapped-Report des Backfill-Commands: alles was
        // hier fehlt, wird im Dry-Run als "nicht gemappt" gemeldet.
        $known = ApplicantEmployeeFieldMapping::knownSourceFields();
        foreach (['vorname', 'telefonnummer', 'stadt', 'ort', 'visum_foto', 'pkw_vorhanden',
                  'foto_ausweis_vorderseite', 'visumsblatt', 'nationalpass'] as $field) {
            $this->assertContains($field, $known);
        }
    }

    public function test_covers_every_non_eu_document_column(): void
    {
        // Drift-Schutz: das Gesamt-Mapping muss alle Spalten aus
        // NonEuDocumentMapping abdecken — sonst zieht der Backfill
        // genau die Felder nicht nach, wegen derer es ihn gibt.
        $extra = array_map(fn ($i) => (string) ($i + 100), array_flip(array_values(NonEuDocumentMapping::MAP)));
        $resolved = ApplicantEmployeeFieldMapping::resolve($extra);
        foreach (array_keys(NonEuDocumentMapping::MAP) as $column) {
            $this->assertArrayHasKey($column, $resolved, "$column fehlt im Gesamt-Mapping");
        }
    }

    public function test_overlong_text_values_are_clamped_to_the_column_length(): void
    {
        // Realfall Bewerber #2381: das Enrichment schrieb einen Kommentar statt
        // eines Werts in `geworben_von`. 91 Zeichen gegen varchar(64) → der
        // INSERT starb mit SQLSTATE[22001] und riss die komplette MA-Anlage
        // mit (und damit still den Portal-Versand an den Bewerber).
        $kommentar = '(Eingabe im Formular: 01774493549 – vermutlich keine Personalnummer, sondern Telefonnummer)';
        $this->assertGreaterThan(64, mb_strlen($kommentar));

        $resolved = ApplicantEmployeeFieldMapping::resolve(['geworben_von' => $kommentar]);

        $this->assertSame(64, mb_strlen($resolved['recruited_by_personnel_number']));
        $this->assertSame(mb_substr($kommentar, 0, 64), $resolved['recruited_by_personnel_number']);
    }

    public function test_clamping_is_multibyte_safe(): void
    {
        // mb_substr statt substr: sonst wird ein Umlaut mitten im Byte-Paar
        // gekappt und MySQL lehnt den kaputten UTF-8-String erst recht ab.
        $strasse = str_repeat('ä', 300);

        $resolved = ApplicantEmployeeFieldMapping::resolve(['strasse' => $strasse]);

        $this->assertSame(255, mb_strlen($resolved['street']));
        $this->assertTrue(mb_check_encoding($resolved['street'], 'UTF-8'));
    }

    public function test_values_within_the_limit_are_untouched(): void
    {
        $resolved = ApplicantEmployeeFieldMapping::resolve([
            'geworben_von' => '01774493549',
            'vorname'      => 'Muhammad',
        ]);

        $this->assertSame('01774493549', $resolved['recruited_by_personnel_number']);
        $this->assertSame('Muhammad', $resolved['first_name']);
    }

    public function test_every_scalar_string_column_has_a_length_limit(): void
    {
        // Drift-Schutz: eine neue Text-Spalte ohne Eintrag in MAX_LENGTHS
        // waere wieder ein ungeschuetzter Weg in denselben 22001-Absturz.
        // birth_date ist die einzige TEXT_MAP-Spalte ohne Laengenbegrenzung
        // (DATE-Spalte, wird von normalizeDateValue geformt).
        $columns = array_diff(array_keys(ApplicantEmployeeFieldMapping::TEXT_MAP), ['birth_date']);
        foreach (array_merge($columns, ['city', 'phone']) as $column) {
            $this->assertArrayHasKey(
                $column,
                ApplicantEmployeeFieldMapping::MAX_LENGTHS,
                "$column hat keine Laengenbegrenzung"
            );
        }
    }
}
