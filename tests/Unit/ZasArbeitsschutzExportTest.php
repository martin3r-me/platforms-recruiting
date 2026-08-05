<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

class ZasArbeitsschutzExportTest extends TestCase
{
    /**
     * Testbare Resolver-Variante: resolveColumn public; leerer Konstruktor,
     * weil ZasSignedUrlGenerator fuer Arbeitsschutz-Spalten ungenutzt ist.
     */
    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, string $column): ?string
            {
                return $this->resolveColumn($e, null, $column);
            }
        };
    }

    /** setRawAttributes statt fill(): der Schreib-Cast braeuchte eine DB-Connection. */
    private function employee(array $attributes): RecEmployee
    {
        $e = new RecEmployee();
        $e->setRawAttributes($attributes);
        return $e;
    }

    public function test_arbeitsschutz_headers_stehen_als_block_in_fester_reihenfolge(): void
    {
        // Der Test hiess urspruenglich test_columns_end_with_arbeitsschutz_headers
        // und pruefte array_slice(..., -3), also "Arbeitsschutz ist der Schluss".
        // Das war richtig, solange Arbeitsschutz die jueingste Ergaenzung war —
        // per Konvention ("ans Ende, nie dazwischen") kann es aber nicht halten,
        // sobald ein neuer Block folgt; die Bewertungsspalten stehen jetzt danach.
        // Geschuetzt wird weiterhin, worauf es ankommt: die drei Spalten stehen
        // zusammenhaengend und in dieser Reihenfolge, wurden also nicht
        // auseinandergerissen oder in die Mitte eingeschoben.
        $columns = ZasEmployeeFieldResolver::COLUMNS;
        $start = array_search('Ersthelfer', $columns, true);

        $this->assertNotFalse($start, 'Ersthelfer-Spalte fehlt im Export.');
        $this->assertSame(
            ['Ersthelfer', 'ErsthelferBis', 'Sicherheitsbeauftragter'],
            array_slice($columns, $start, 3),
        );
    }

    public function test_bool_columns_render_ja_nein(): void
    {
        $r = $this->resolver();
        $this->assertSame('Ja', $r->col($this->employee(['is_first_aider' => 1]), 'Ersthelfer'));
        $this->assertSame('Nein', $r->col($this->employee(['is_first_aider' => 0]), 'Ersthelfer'));
        $this->assertSame('Nein', $r->col($this->employee([]), 'Ersthelfer'));
        $this->assertSame('Ja', $r->col($this->employee(['is_safety_officer' => 1]), 'Sicherheitsbeauftragter'));
        $this->assertSame('Nein', $r->col($this->employee([]), 'Sicherheitsbeauftragter'));
    }

    public function test_date_column_renders_dmy_or_empty(): void
    {
        $r = $this->resolver();
        $this->assertSame('01.03.2027', $r->col($this->employee(['first_aider_valid_until' => '2027-03-01']), 'ErsthelferBis'));
        // null ist korrekt: resolve() koalesziert jede Spalte mit
        // `(string) (resolveColumn(...) ?? '')` (Resolver:138), bevor der
        // ZasCsvBuilder implodiert — null erreicht den CSV-Pfad nie.
        $this->assertNull($r->col($this->employee([]), 'ErsthelferBis'));
    }

    public function test_observer_triggers_on_arbeitsschutz_fields(): void
    {
        foreach (['is_first_aider', 'first_aider_valid_until', 'is_safety_officer'] as $field) {
            $this->assertContains($field, RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
        }
    }
}
