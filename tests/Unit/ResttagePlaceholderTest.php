<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ResttagePlaceholder;

class ResttagePlaceholderTest extends TestCase
{
    public function test_replaces_placeholder_with_number(): void
    {
        $content = '<p>im laufenden Kalenderjahr noch {{resttage}} Tage</p>';

        $this->assertSame(
            '<p>im laufenden Kalenderjahr noch 87 Tage</p>',
            ResttagePlaceholder::fill($content, 87)
        );
    }

    public function test_replaces_every_occurrence(): void
    {
        $content = '{{resttage}} und nochmal {{resttage}}';

        $this->assertSame('12 und nochmal 12', ResttagePlaceholder::fill($content, 12));
    }

    public function test_zero_is_written_out(): void
    {
        $this->assertSame('noch 0 Tage', ResttagePlaceholder::fill('noch {{resttage}} Tage', 0));
    }

    public function test_fill_is_idempotent(): void
    {
        $once = ResttagePlaceholder::fill('noch {{resttage}} Tage', 90);

        $this->assertSame($once, ResttagePlaceholder::fill($once, 90));
    }

    public function test_content_without_placeholder_is_untouched(): void
    {
        $content = '<p>Ein Vertrag ohne Platzhalter</p>';

        $this->assertSame($content, ResttagePlaceholder::fill($content, 140));
    }

    public function test_applies_to_recognises_resttage_type(): void
    {
        $this->assertTrue(ResttagePlaceholder::appliesTo(['type' => 'resttage', 'resttage' => 90]));
    }

    public function test_applies_to_rejects_legacy_par1516_rows(): void
    {
        // Bestandszeilen haben keinen 'type'-Schluessel — sie sind immer §15/§16.
        $this->assertFalse(ResttagePlaceholder::appliesTo([
            'par15_has_previous' => false,
            'par15_entries' => [],
            'par16_was_jobseeking' => false,
            'par16_entries' => [],
        ]));
    }

    public function test_applies_to_rejects_empty_array(): void
    {
        $this->assertFalse(ResttagePlaceholder::appliesTo([]));
    }

    public function test_detects_unresolved_placeholder(): void
    {
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('noch {{resttage}} Tage'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('noch {{ resttage }} Tage'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt_vorname}}'));
    }

    public function test_clean_content_has_no_unresolved_placeholder(): void
    {
        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder('noch 90 Tage'));
        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder('<p style="{color:red}">x</p>'));
    }

    public function test_embed_is_not_responsible_for_legacy_rows(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertNull(ResttagePlaceholder::embed($content, [
            'par15_has_previous' => false,
            'par15_entries' => [],
        ]));
        $this->assertNull(ResttagePlaceholder::embed($content, []));
    }

    public function test_embed_fills_when_number_present(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => 87])
        );
    }

    public function test_embed_accepts_numeric_string(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => '87'])
        );
    }

    /**
     * KERN DES LESEPFAD-SCHUTZES. RePersonalizeContractsTool nimmt
     * pre_signing_data unvalidiert aus der DB. Fehlt die Zahl, darf NICHT
     * still "noch 0 Tage" in ein unterschriebenes Dokument geschrieben
     * werden — der Platzhalter muss stehen bleiben, damit der Guard greift.
     */
    public function test_embed_leaves_content_untouched_when_number_missing(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => null]));
    }

    public function test_embed_leaves_content_untouched_when_number_not_numeric(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => 'abc']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => []]));
    }

    public function test_embed_result_still_carries_placeholder_for_the_guard(): void
    {
        $result = ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage']);

        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder($result));
    }

    /**
     * Der Lesepfad muss dieselbe Form verlangen wie der Schreibpfad
     * (integer|min:0|max:140). is_numeric waere zu permissiv gewesen:
     * '-5' und '1e3' landeten als Zahl im Dokument, 87.9 wuerde still auf
     * 87 trunkiert — eine falsche Zahl in einer haftungsbewehrten
     * Selbstauskunft, ohne Absturz und ohne Auffaelligkeit.
     */
    public function test_embed_rejects_values_the_write_path_would_never_produce(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '-5']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => -5]));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '1e3']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => 87.9]));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '+5']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => ' 87']));
    }

    /**
     * Der Schreibpfad castet auf int, bevor er speichert — dieser Weg muss
     * offen bleiben. Fuehrende Nullen sind zulaessig und werden zur Zahl;
     * hier festgenagelt, damit das niemand spaeter auf ctype_digit plus
     * Laengenpruefung umbaut und dabei '007' verliert.
     */
    public function test_embed_accepts_int_and_leading_zeroes(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => 87])
        );
        $this->assertSame(
            'noch 7 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => '007'])
        );
    }

    /**
     * Der Vorlagen-Editor validiert Platzhalternamen nur als
     * required|string|max:255 — Punkte und Bindestriche sind erlaubt. Dass
     * heute alle Mappings snake_case sind, ist eine Momentaufnahme, keine
     * Systemeigenschaft. Ein Guard, der hier nicht ausloest, schuetzt nicht.
     */
    public function test_detects_placeholders_with_dots_and_dashes(): void
    {
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt.vorname}}'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt-vorname}}'));
    }
}
