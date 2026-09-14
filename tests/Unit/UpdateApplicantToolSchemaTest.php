<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Tools\UpdateApplicantTool;

/**
 * Schema-Pin fuer recruiting.applicants.PUT.
 *
 * Hintergrund (Fall Jana Derichs, 11.09.2026): Der Enrichment-Lauf fuellt das
 * Tool-Schema vollstaendig aus — auch Felder, zu denen er gar keine Aussage
 * treffen wollte. Fuer Integer faengt die "0 = nicht geaendert"-Konvention das
 * ab (rec_phase_id, owned_by_user_id blieben folgenlos). Bei einem Boolean gibt
 * es diesen Sentinel nicht: `is_active: false` ist von einer Absicht nicht zu
 * unterscheiden und schlug durch. Der Bewerber wurde inaktiv, der Inbound-Dedup
 * fand ihn 22 Sekunden spaeter nicht mehr und legte eine Dublette an.
 *
 * Konsequenz analog zu applied_at (siehe Kommentar in UpdateApplicantTool):
 * Felder, deren versehentliche Aenderung Datensaetze unsichtbar macht, gehoeren
 * nicht ins LLM-Schema. HR schaltet is_active im UI
 * (resources/views/livewire/applicant/show.blade.php).
 */
class UpdateApplicantToolSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function properties(): array
    {
        $schema = (new UpdateApplicantTool())->getSchema();

        return $schema['properties'] ?? $schema['parameters']['properties'] ?? [];
    }

    public function test_schema_bietet_is_active_nicht_an(): void
    {
        $this->assertArrayNotHasKey(
            'is_active',
            $this->properties(),
            'is_active darf nicht im LLM-Schema stehen — ein erfundenes false macht '
            . 'den Bewerber unsichtbar und erzeugt beim naechsten Inbound eine Dublette.'
        );
    }

    public function test_schema_bietet_weiterhin_die_regulaeren_felder_an(): void
    {
        $properties = $this->properties();

        // Absicherung gegen einen zu breiten Rundumschlag: entfernt werden soll
        // genau ein Feld, nicht das halbe Schema.
        foreach (['applicant_id', 'notes', 'progress', 'auto_pilot', 'is_test'] as $expected) {
            $this->assertArrayHasKey($expected, $properties, "Feld {$expected} fehlt im Schema.");
        }
    }
}
