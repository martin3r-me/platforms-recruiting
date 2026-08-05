<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EvaluationValues;

class EvaluationValuesTest extends TestCase
{
    public function test_felder_sind_die_acht_bewertungsfelder(): void
    {
        $this->assertSame([
            'rating_erscheinungsbild',
            'rating_fachkompetenz',
            'rating_auffassungsgabe',
            'rating_auftreten',
            'rating_teamintegration',
            'evaluation_note',
            'linen_package_items',
            'qualifications',
        ], EvaluationValues::FIELDS);
    }

    public function test_sterne_nur_1_bis_5_sonst_null(): void
    {
        $this->assertSame(1, EvaluationValues::normalizeStar(1));
        $this->assertSame(5, EvaluationValues::normalizeStar('5'));
        $this->assertSame(3, EvaluationValues::normalizeStar('3'));
        $this->assertNull(EvaluationValues::normalizeStar(0));
        $this->assertNull(EvaluationValues::normalizeStar(6));
        $this->assertNull(EvaluationValues::normalizeStar(-1));
        $this->assertNull(EvaluationValues::normalizeStar(null));
        $this->assertNull(EvaluationValues::normalizeStar(''));
        $this->assertNull(EvaluationValues::normalizeStar('abc'));
        $this->assertNull(EvaluationValues::normalizeStar([]));
        $this->assertNull(EvaluationValues::normalizeStar(2.7));
    }

    public function test_leere_liste_wird_null_niemals_leeres_array(): void
    {
        // Spec F7: "leer" == NULL. Ein leeres Array wuerde von der
        // Uebernahme-Pruefung (=== null) als "schon gefuellt" gelesen.
        $this->assertNull(EvaluationValues::normalizeList([]));
        $this->assertNull(EvaluationValues::normalizeList(null));
        $this->assertNull(EvaluationValues::normalizeList(['', null]));
        $this->assertNull(EvaluationValues::normalizeList('nicht-array'));
    }

    public function test_liste_wird_bereinigt_und_reindiziert(): void
    {
        $this->assertSame(['hemd', 'schuerze'], EvaluationValues::normalizeList(['hemd', '', 'schuerze', null]));
        $this->assertSame(['hemd'], EvaluationValues::normalizeList([2 => 'hemd']));
    }

    public function test_hat_bewertung_wenn_mindestens_ein_feld_gesetzt_ist(): void
    {
        $leer = array_fill_keys(EvaluationValues::FIELDS, null);

        $this->assertFalse(EvaluationValues::hasAny($leer));
        $this->assertFalse(EvaluationValues::hasAny([]));

        $this->assertTrue(EvaluationValues::hasAny(['rating_auftreten' => 3] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['evaluation_note' => 'passt'] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['linen_package_items' => ['hemd']] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['qualifications' => ['service']] + $leer));
    }

    public function test_leere_listen_und_leerer_text_zaehlen_nicht_als_bewertet(): void
    {
        $leer = array_fill_keys(EvaluationValues::FIELDS, null);

        $this->assertFalse(EvaluationValues::hasAny(['linen_package_items' => []] + $leer));
        $this->assertFalse(EvaluationValues::hasAny(['evaluation_note' => ''] + $leer));
        $this->assertFalse(EvaluationValues::hasAny(['evaluation_note' => '   '] + $leer));
    }

    public function test_kompakte_zeile_zeigt_fuenf_werte_mit_mittelpunkt(): void
    {
        $this->assertSame('4·3·5·4·4', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 4,
            'rating_teamintegration'  => 4,
        ]));
    }

    public function test_kompakte_zeile_zeigt_fehlende_werte_als_gedankenstrich(): void
    {
        $this->assertSame('–·–·–·–·–', EvaluationValues::compactLine([]));
        $this->assertSame('4·–·–·–·2', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 4,
            'rating_teamintegration'  => 2,
        ]));
    }

    public function test_kompakte_zeile_normalisiert_unsinnige_werte(): void
    {
        $this->assertSame('–·–·–·–·–', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 0,
            'rating_fachkompetenz'    => 9,
            'rating_auffassungsgabe'  => 'x',
            'rating_auftreten'        => '',
            'rating_teamintegration'  => null,
        ]));
    }
}
