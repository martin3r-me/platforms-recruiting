<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NonEuDocumentMapping;

class NonEuDocumentMappingTest extends TestCase
{
    public function test_maps_all_documents_from_extra_fields_to_employee_columns(): void
    {
        $extra = [
            'aufenthaltstitel_vorderseite'               => '11',
            'aufenthaltstitel_ruckseite'                 => '12',
            'visum_foto'                                 => '13',
            'zusatzblatt_arbeitsgenehmigung_vorderseite' => '14',
            'zusatzblatt_arbeitsgenehmigung_ruckseite'   => '15',
            'fiktionsbescheinigung_vorderseite'          => '16',
            'fiktionsbescheinigung_ruckseite'            => '17',
        ];

        $this->assertSame([
            'aufenthaltstitel_front_file_id'      => 11,
            'aufenthaltstitel_back_file_id'       => 12,
            'visumsblatt_file_id'                 => 13,
            'zusatzblatt_file_id'                 => 14,
            'zusatzblatt_back_file_id'            => 15,
            'fiktionsbescheinigung_front_file_id' => 16,
            'fiktionsbescheinigung_back_file_id'  => 17,
        ], NonEuDocumentMapping::resolve($extra));
    }

    public function test_missing_fields_become_null(): void
    {
        $result = NonEuDocumentMapping::resolve([]);
        // Alle Spalten vorhanden, alle null.
        $this->assertCount(7, $result);
        foreach ($result as $col => $value) {
            $this->assertNull($value, "$col sollte null sein");
        }
    }

    public function test_key_names_match_zas_relevant_columns(): void
    {
        // Schützt gegen versehentliches Umbenennen der Ziel-Spalten (ZAS-Mapping).
        $this->assertSame([
            'aufenthaltstitel_front_file_id',
            'aufenthaltstitel_back_file_id',
            'visumsblatt_file_id',
            'zusatzblatt_file_id',
            'zusatzblatt_back_file_id',
            'fiktionsbescheinigung_front_file_id',
            'fiktionsbescheinigung_back_file_id',
        ], array_keys(NonEuDocumentMapping::MAP));
    }

    public function test_extra_field_source_names_are_the_expected_p3_field_names(): void
    {
        // Schützt gegen Drift der Quell-Feldnamen (Ursache des Original-Bugs).
        $this->assertSame('aufenthaltstitel_vorderseite', NonEuDocumentMapping::MAP['aufenthaltstitel_front_file_id']);
        $this->assertSame('visum_foto', NonEuDocumentMapping::MAP['visumsblatt_file_id']);
        $this->assertSame('zusatzblatt_arbeitsgenehmigung_vorderseite', NonEuDocumentMapping::MAP['zusatzblatt_file_id']);
    }

    public function test_json_array_value_takes_first_id(): void
    {
        // Defensiv: falls ein Feld als Multi-File (JSON-Array) gespeichert ist.
        $this->assertSame(
            ['aufenthaltstitel_front_file_id' => 99],
            array_intersect_key(
                NonEuDocumentMapping::resolve(['aufenthaltstitel_vorderseite' => [99, 100]]),
                ['aufenthaltstitel_front_file_id' => true],
            ),
        );
    }

    public function test_zero_and_empty_are_null(): void
    {
        $r = NonEuDocumentMapping::resolve([
            'aufenthaltstitel_vorderseite' => '0',
            'visum_foto'                   => '',
        ]);
        $this->assertNull($r['aufenthaltstitel_front_file_id']);
        $this->assertNull($r['visumsblatt_file_id']);
    }
}
