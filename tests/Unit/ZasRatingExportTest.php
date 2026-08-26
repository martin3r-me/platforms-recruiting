<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Support\RatingCriteria;

class ZasRatingExportTest extends TestCase
{
    /** Testbare Resolver-Variante: leerer Konstruktor, resolveColumn public. */
    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, $hr, string $column): ?string
            {
                return $this->resolveColumn($e, $hr, $column);
            }
        };
    }

    /** setRawAttributes statt fill(): der Schreib-Cast braeuchte eine DB-Connection. */
    private function hrData(array $attributes): RecEmployeeHrData
    {
        $hr = new RecEmployeeHrData();
        $hr->setRawAttributes($attributes);
        return $hr;
    }

    public function test_die_fuenf_bewertungsspalten_stehen_als_block_zusammen(): void
    {
        // Konvention im Modul: neue Spalten immer ans Ende, nie dazwischen —
        // der ZAS-Importer liest positional (Spec F6).
        //
        // Geprueft wird deshalb die Kontiguitaet des Blocks, nicht mehr seine
        // Lage am Listenende: seit 2026-08-26 haengt hinter den Bewertungen die
        // von ZAS angefragte Spalte `Firma`. Wer weitere Spalten anhaengt,
        // laesst diese Assertion unveraendert — sie bleibt gueltig.
        // (Muster uebernommen aus ZasArbeitsschutzExportTest.)
        $columns  = ZasEmployeeFieldResolver::COLUMNS;
        $expected = array_values(RatingCriteria::zasColumns());
        $start    = array_search($expected[0], $columns, true);

        $this->assertNotFalse($start, 'Erste Bewertungsspalte fehlt im Export.');
        $this->assertSame($expected, array_slice($columns, $start, count($expected)));
    }

    public function test_firma_ist_die_letzte_spalte(): void
    {
        // Von ZAS angefragt; steht bewusst hinter allem anderen, damit sich
        // keine bestehende Spaltenposition verschiebt.
        $this->assertSame('Firma', array_slice(ZasEmployeeFieldResolver::COLUMNS, -1)[0]);
    }

    public function test_firma_loest_einen_update_export_aus(): void
    {
        // Die Spalte wird exportiert — also muss eine Korrektur in der
        // HR-Maske auch bei ZAS ankommen. Ohne Eintrag hier bliebe sie liegen.
        $this->assertContains('company', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }

    public function test_spaltennamen_sind_eindeutig(): void
    {
        $columns = ZasEmployeeFieldResolver::COLUMNS;
        $this->assertSame($columns, array_unique($columns), 'ZAS-Spaltennamen muessen eindeutig sein.');
    }

    public function test_bestandsspalten_bleiben_unveraendert_vorhanden(): void
    {
        // Rueckwaertskompatibilitaet fuer Hr. Michel: die drei alten Spalten
        // duerfen nicht verschwinden oder umbenannt werden.
        foreach (['Sternebewertung', 'Waeschepaket', 'Qualifikation'] as $column) {
            $this->assertContains($column, ZasEmployeeFieldResolver::COLUMNS);
        }
    }

    public function test_freitext_wird_nicht_exportiert(): void
    {
        // Spec §5: nicht wegen eines Schema-Risikos (ZasCsvBuilder::sanitize
        // bereinigt), sondern weil der Text verstuemmelt ankaeme und ZAS ihn
        // nicht nutzt.
        foreach (ZasEmployeeFieldResolver::COLUMNS as $column) {
            $this->assertStringNotContainsStringIgnoringCase('bewertungstext', $column);
            $this->assertStringNotContainsStringIgnoringCase('note', $column);
        }
    }

    public function test_bewertungswerte_werden_aus_hr_data_gelesen(): void
    {
        $r = $this->resolver();
        $employee = new RecEmployee();
        $hr = $this->hrData([
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 1,
            'rating_teamintegration'  => 2,
        ]);

        $this->assertSame('4', $r->col($employee, $hr, 'BewertungErscheinungsbild'));
        $this->assertSame('3', $r->col($employee, $hr, 'BewertungFachkompetenz'));
        $this->assertSame('5', $r->col($employee, $hr, 'BewertungAuffassungsgabe'));
        $this->assertSame('1', $r->col($employee, $hr, 'BewertungAuftreten'));
        $this->assertSame('2', $r->col($employee, $hr, 'BewertungTeamintegration'));
    }

    public function test_fehlende_bewertung_ist_null_nicht_null_string(): void
    {
        $r = $this->resolver();
        $employee = new RecEmployee();

        $this->assertNull($r->col($employee, $this->hrData([]), 'BewertungAuftreten'));
        $this->assertNull($r->col($employee, null, 'BewertungAuftreten'));
    }

    public function test_update_marker_kennt_die_fuenf_rating_felder(): void
    {
        // Ohne Eintrag in RELEVANT_HR_FIELDS erreicht eine HR-Korrektur den
        // ZAS-Update-Export nie (Spec F9).
        foreach (RatingCriteria::columns() as $column) {
            $this->assertContains(
                $column,
                RecEmployeeExportObserver::RELEVANT_HR_FIELDS,
                "{$column} fehlt in RELEVANT_HR_FIELDS.",
            );
        }
    }

    public function test_freitext_loest_keinen_re_export_aus(): void
    {
        // evaluation_note wird nicht exportiert — es darf deshalb auch keinen
        // Update-Marker setzen, sonst re-exportiert eine reine Notiz-Aenderung
        // ohne Inhaltsaenderung.
        $this->assertNotContains('evaluation_note', RecEmployeeExportObserver::RELEVANT_HR_FIELDS);
    }
}
