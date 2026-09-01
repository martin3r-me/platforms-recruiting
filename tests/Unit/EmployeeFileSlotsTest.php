<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ApplicantEmployeeFieldMapping;
use Platform\Recruiting\Support\EmployeeFileSlots;
use Platform\Recruiting\Support\NonEuDocumentMapping;

class EmployeeFileSlotsTest extends TestCase
{
    public function test_contains_exactly_the_employee_document_columns(): void
    {
        // Whitelist fuer den EmployeeFileController — schuetzt davor, dass
        // ueber den slot-Parameter beliebige Spalten angefragt werden koennen.
        $this->assertSame([
            'identity_card_front_file_id',
            'identity_card_back_file_id',
            'selfie_file_id',
            'health_insurance_card_file_id',
            'nationalpass_file_id',
            'aufenthaltstitel_front_file_id',
            'aufenthaltstitel_back_file_id',
            'visumsblatt_file_id',
            'zusatzblatt_file_id',
            'zusatzblatt_back_file_id',
            'immatrikulation_file_id',
            'schulbescheinigung_file_id',
            'fiktionsbescheinigung_front_file_id',
            'fiktionsbescheinigung_back_file_id',
            'erstbescheinigung_file_id',
            'first_aider_certificate_file_id',
        ], EmployeeFileSlots::COLUMNS);
    }

    public function test_every_mapped_file_column_is_an_allowed_slot(): void
    {
        // Drift-Schutz: was das Mapping auf den MA schreibt, muss auch
        // anzeigbar sein.
        $mappedColumns = array_merge(
            array_keys(ApplicantEmployeeFieldMapping::FILE_MAP),
            array_keys(NonEuDocumentMapping::MAP),
            array_keys(ApplicantEmployeeFieldMapping::FILE_ALIASES),
        );

        foreach (array_unique($mappedColumns) as $column) {
            $this->assertContains($column, EmployeeFileSlots::COLUMNS, "$column fehlt in EmployeeFileSlots");
        }
    }

    public function test_all_slots_are_file_id_columns(): void
    {
        foreach (EmployeeFileSlots::COLUMNS as $column) {
            $this->assertStringEndsWith('_file_id', $column);
        }
    }

    /**
     * Zweiter Drift-Schutz: jede Spalte, in die eine Maske hochladen kann,
     * muss auch anzeigbar sein. Ohne diesen Test blieb das Loch offen — der
     * Docblock von EmployeeFileSlots verspricht die Synchronitaet mit
     * FILE_UPLOAD_MAP seit jeher, geprueft wurde sie nie.
     */
    public function test_every_uploadable_column_is_an_allowed_slot(): void
    {
        $uploadColumns = array_merge(
            array_keys($this->privateConst(\Platform\Recruiting\Livewire\Employees\Show::class, 'FILE_UPLOAD_MAP')),
            array_keys($this->privateConst(\Platform\Recruiting\Livewire\Public\EmployeePortal::class, 'FILE_FIELDS')),
        );

        $this->assertNotEmpty($uploadColumns);
        foreach (array_unique($uploadColumns) as $column) {
            $this->assertContains($column, EmployeeFileSlots::COLUMNS, "$column fehlt in EmployeeFileSlots");
        }
    }

    private function privateConst(string $class, string $name): array
    {
        return (new \ReflectionClass($class))->getConstant($name);
    }
}
