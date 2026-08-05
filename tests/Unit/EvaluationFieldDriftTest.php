<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Support\EvaluationValues;
use Platform\Recruiting\Support\RatingCriteria;
use ReflectionClass;

/**
 * Drift-Guard: die Bewertungsfelder muessen auf BEIDEN Modellen fillable und
 * identisch gecastet sein. Ohne fillable schluckt Eloquent den Wert still;
 * ohne Cast kaeme ein JSON-String statt eines Arrays zurueck.
 *
 * Gelesen per Reflection ohne Konstruktor — kein DB-Zugriff, kein
 * Laravel-Bootstrap noetig.
 */
class EvaluationFieldDriftTest extends TestCase
{
    /** @return array<int, string> */
    private function protectedArray(string $class, string $property): array
    {
        $rc  = new ReflectionClass($class);
        $obj = $rc->newInstanceWithoutConstructor();
        $p   = $rc->getProperty($property);
        $p->setAccessible(true);

        return $p->getValue($obj);
    }

    public function test_alle_acht_felder_sind_am_bewerber_fillable(): void
    {
        $fillable = $this->protectedArray(RecApplicant::class, 'fillable');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertContains($field, $fillable, "rec_applicants.{$field} fehlt in \$fillable.");
        }
    }

    public function test_alle_acht_felder_sind_an_hr_data_fillable(): void
    {
        $fillable = $this->protectedArray(RecEmployeeHrData::class, 'fillable');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertContains($field, $fillable, "rec_employee_hr_data.{$field} fehlt in \$fillable.");
        }
    }

    public function test_casts_sind_auf_beiden_modellen_identisch(): void
    {
        $applicant = $this->protectedArray(RecApplicant::class, 'casts');
        $hrData    = $this->protectedArray(RecEmployeeHrData::class, 'casts');

        foreach (RatingCriteria::columns() as $column) {
            $this->assertSame('integer', $applicant[$column] ?? null, "rec_applicants.{$column} muss integer casten.");
            $this->assertSame('integer', $hrData[$column] ?? null, "rec_employee_hr_data.{$column} muss integer casten.");
        }

        foreach (EvaluationValues::LIST_FIELDS as $field) {
            $this->assertSame('array', $applicant[$field] ?? null, "rec_applicants.{$field} muss array casten.");
            $this->assertSame('array', $hrData[$field] ?? null, "rec_employee_hr_data.{$field} muss array casten.");
        }
    }

    public function test_freitext_wird_nicht_gecastet(): void
    {
        // evaluation_note ist ein reines Textfeld — ein Cast waere ein Fehler.
        $applicant = $this->protectedArray(RecApplicant::class, 'casts');
        $hrData    = $this->protectedArray(RecEmployeeHrData::class, 'casts');

        $this->assertArrayNotHasKey(EvaluationValues::NOTE_FIELD, $applicant);
        $this->assertArrayNotHasKey(EvaluationValues::NOTE_FIELD, $hrData);
    }

    public function test_migrationen_legen_die_spalten_an(): void
    {
        // Die Migrationen sind ohne DB nicht ausfuehrbar; geprueft wird, dass
        // jede Spalte ueberhaupt in einer Migration vorkommt (Schutz gegen
        // "Model erweitert, Migration vergessen").
        $applicantMigration = file_get_contents(__DIR__ . '/../../database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php');
        $hrDataMigration    = file_get_contents(__DIR__ . '/../../database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertStringContainsString("'{$field}'", $applicantMigration, "Migration rec_applicants: {$field} fehlt.");
        }

        // Auf hrData existieren linen_package_items und qualifications bereits.
        foreach (array_merge(RatingCriteria::columns(), [EvaluationValues::NOTE_FIELD]) as $field) {
            $this->assertStringContainsString("'{$field}'", $hrDataMigration, "Migration hrData: {$field} fehlt.");
        }
    }
}
