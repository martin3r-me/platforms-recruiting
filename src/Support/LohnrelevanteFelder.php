<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Angaben die Lohnabrechnung braucht (Sebastian 26.10.2026) — EINE
 * Liste fuer Portal-Start ("Personalakte noch unvollstaendig") und den
 * Lohn-Zweig der Themen-Auswahl. Der Ring im Profil zaehlt weiter ALLE Felder
 * (die Leute sollen alles ausfuellen), die Wächter beim Speichern bleiben,
 * wie sie sind.
 *
 * Grundlage: deutsche Lohnabrechnung mit SV-Meldung. Kirchensteuer und
 * Steuerklasse kommen ueber die Steuer-ID (ELStAM), deshalb sind Religion und
 * Familienstand freiwillig; fuer SEPA reicht die IBAN. Geburtsangaben braucht
 * es nur, solange keine SV-Nummer da ist (daraus wird sie vergeben).
 *
 * Kommt Olafs Pruefregel fuer BAR/Ueberweisung, wird NUR diese Liste angepasst.
 */
final class LohnrelevanteFelder
{
    public const IMMER = [
        'street', 'house_number', 'zip', 'city', 'country_code',
        'nationality', 'employment_type',
        'iban', 'account_holder',
        'steuer_id', 'tax_class',
        'sozialversicherungsnummer', 'health_insurance',
        'is_main_employer', 'other_employer',
        'number_of_children',
    ];

    /** Nur noetig, solange die SV-Nummer fehlt. */
    public const OHNE_SV_NUMMER = ['gender', 'birth_name', 'birth_place', 'birth_country'];

    /**
     * @param  array<string, array<string, mixed>> $meta       Feld => Meta (label, required_if) — die Felder des Profils
     * @param  array<string, mixed>                $datensatz  Feld => Wert
     * @return array<string, string>                           fehlendes Feld => Beschriftung
     */
    public static function fehlend(array $meta, array $datensatz): array
    {
        $felder = self::IMMER;
        if (PersonDatensatz::istLeer($datensatz['sozialversicherungsnummer'] ?? null)) {
            $felder = [...$felder, ...self::OHNE_SV_NUMMER];
        }

        $aus = [];
        foreach ($felder as $feld) {
            if (!isset($meta[$feld]) || !PortalFieldRelevance::istRelevant($meta[$feld], $datensatz)) {
                continue;
            }
            if (PersonDatensatz::istLeer($datensatz[$feld] ?? null) && !PortalFieldRelevance::leerErlaubt($meta[$feld])) {
                $aus[$feld] = (string) ($meta[$feld]['label'] ?? $feld);
            }
        }

        return $aus;
    }
}
