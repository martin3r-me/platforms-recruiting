<?php

namespace Platform\Recruiting\Support;

/**
 * Single Source of Truth der fuenf Bewertungskriterien: Spaltenname (identisch
 * auf rec_applicants UND rec_employee_hr_data), Anzeige-Label, ZAS-CSV-Spalte
 * und Handout-Hilfetext.
 *
 * Die ZAS-Spaltennamen sind mit Hr. Michel abgestimmter Vertragsbestandteil —
 * Umbenennen erfordert eine neue Abstimmungsrunde (Spec F6).
 *
 * Die Hilfetexte stammen aus dem Schulungsleiter-Handout und werden
 * nachgetragen; die Schluessel existieren von Anfang an, damit das Popover
 * nicht auf null laeuft.
 */
final class RatingCriteria
{
    public const CRITERIA = [
        'rating_erscheinungsbild' => [
            'label' => 'Erscheinungsbild & Hygiene',
            'zas'   => 'BewertungErscheinungsbild',
            'help'  => '',
        ],
        'rating_fachkompetenz' => [
            'label' => 'Fachliche Grundkompetenz',
            'zas'   => 'BewertungFachkompetenz',
            'help'  => '',
        ],
        'rating_auffassungsgabe' => [
            'label' => 'Auffassungsgabe & Lernbereitschaft',
            'zas'   => 'BewertungAuffassungsgabe',
            'help'  => '',
        ],
        'rating_auftreten' => [
            'label' => 'Auftreten & Kommunikation',
            'zas'   => 'BewertungAuftreten',
            'help'  => '',
        ],
        'rating_teamintegration' => [
            'label' => 'Teamintegration & Verhalten',
            'zas'   => 'BewertungTeamintegration',
            'help'  => '',
        ],
    ];

    /** @return array<int, string> */
    public static function columns(): array
    {
        return array_keys(self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_map(fn (array $c) => $c['label'], self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function zasColumns(): array
    {
        return array_map(fn (array $c) => $c['zas'], self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function helpTexts(): array
    {
        return array_map(fn (array $c) => $c['help'], self::CRITERIA);
    }

    public static function isColumn(string $column): bool
    {
        return array_key_exists($column, self::CRITERIA);
    }
}
