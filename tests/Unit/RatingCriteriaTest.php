<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\RatingCriteria;

class RatingCriteriaTest extends TestCase
{
    public function test_hat_genau_fuenf_kriterien_in_fester_reihenfolge(): void
    {
        $this->assertSame([
            'rating_erscheinungsbild',
            'rating_fachkompetenz',
            'rating_auffassungsgabe',
            'rating_auftreten',
            'rating_teamintegration',
        ], RatingCriteria::columns());
    }

    public function test_labels_entsprechen_der_spec(): void
    {
        $labels = RatingCriteria::labels();
        $this->assertSame('Erscheinungsbild & Hygiene', $labels['rating_erscheinungsbild']);
        $this->assertSame('Fachliche Grundkompetenz', $labels['rating_fachkompetenz']);
        $this->assertSame('Auffassungsgabe & Lernbereitschaft', $labels['rating_auffassungsgabe']);
        $this->assertSame('Auftreten & Kommunikation', $labels['rating_auftreten']);
        $this->assertSame('Teamintegration & Verhalten', $labels['rating_teamintegration']);
    }

    public function test_zas_spaltennamen_sind_vertragsbestandteil(): void
    {
        // Diese Namen sind mit Hr. Michel abgestimmt — Aenderung = zweite
        // Abstimmungsrunde. Test schuetzt gegen stille Umbenennung.
        $this->assertSame([
            'rating_erscheinungsbild' => 'BewertungErscheinungsbild',
            'rating_fachkompetenz'    => 'BewertungFachkompetenz',
            'rating_auffassungsgabe'  => 'BewertungAuffassungsgabe',
            'rating_auftreten'        => 'BewertungAuftreten',
            'rating_teamintegration'  => 'BewertungTeamintegration',
        ], RatingCriteria::zasColumns());
    }

    public function test_zas_namen_ohne_umlaute_und_eindeutig(): void
    {
        $zas = array_values(RatingCriteria::zasColumns());
        $this->assertSame($zas, array_unique($zas), 'ZAS-Spaltennamen muessen eindeutig sein.');
        foreach ($zas as $name) {
            $this->assertSame($name, preg_replace('/[^A-Za-z]/', '', $name), "ZAS-Spalte {$name} darf nur Buchstaben enthalten.");
        }
    }

    public function test_jedes_kriterium_hat_einen_hilfetext_schluessel(): void
    {
        // Inhalt kommt spaeter aus dem Handout-PDF; der Schluessel muss von
        // Anfang an existieren, damit das Popover nicht auf null laeuft.
        foreach (RatingCriteria::columns() as $column) {
            $this->assertArrayHasKey($column, RatingCriteria::helpTexts());
            $this->assertIsString(RatingCriteria::helpTexts()[$column]);
        }
    }

    public function test_is_column_akzeptiert_nur_bekannte_kriterien(): void
    {
        $this->assertTrue(RatingCriteria::isColumn('rating_auftreten'));
        $this->assertFalse(RatingCriteria::isColumn('status'));
        $this->assertFalse(RatingCriteria::isColumn('team_id'));
        $this->assertFalse(RatingCriteria::isColumn('evaluation_note'));
        $this->assertFalse(RatingCriteria::isColumn(''));
    }
}
