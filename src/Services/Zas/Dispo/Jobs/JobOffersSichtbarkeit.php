<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Welche Angebote sieht die Person auf /jobs/{token}? Rein rechnend.
 * Sichtbar: Anfrage laeuft, Tag >= heute, Tag nicht gebucht.
 * Geschlossene (volle) Schichten bleiben sichtbar (Zeile behaelt closed=true):
 * die Seite zeigt sie ohne Knopf als „schon voll“ (Spec §5).
 */
final class JobOffersSichtbarkeit
{
    /**
     * @param list<array{offer_shift_id:int, datum:string, laeuft:bool, closed:bool, accepted:bool}> $rows
     * @param array<string,true> $gebuchteTage Y-m-d => true
     * @return list<array<string,mixed>>
     */
    public static function filtern(array $rows, array $gebuchteTage, string $heute): array
    {
        $out = [];
        foreach ($rows as $row) {
            $tag = substr((string) $row['datum'], 0, 10);
            if (empty($row['laeuft']) || $tag < $heute || isset($gebuchteTage[$tag])) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
