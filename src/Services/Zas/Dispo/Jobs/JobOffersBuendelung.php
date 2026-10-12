<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Spec §9: Bieten zwei Anfragen derselben Person dieselbe Schicht (VA, Tag,
 * Beginn, Taetigkeit) an, steht sie auf /jobs/{token} nur einmal. Rein rechnend.
 *
 * Je Schicht bleibt eine Zeile: der Knopf wirkt auf die kleinste noch offene
 * Angebots-Schicht (sonst die kleinste ueberhaupt); zugesagt, sobald irgendeine
 * zugesagt ist; voll nur, wenn alle voll sind und keine zugesagt ist; „noch N
 * frei“ = kleinster Zaehler der offenen Zeilen. JobOffersZusage sagt beim Klick
 * die uebrigen Angebots-Schichten derselben Schicht mit zu.
 */
final class JobOffersBuendelung
{
    /**
     * @param list<array{offer_shift_id:int, event:int, datum:string, von:?string, taetigkeit:?string, accepted:bool, closed:bool, frei:?int}> $rows
     * @return list<array<string,mixed>> Zeile der Repraesentantin + offer_shift_ids, accepted, voll, frei
     */
    public static function buendeln(array $rows): array
    {
        $gruppen = [];
        foreach ($rows as $row) {
            $key = DispoJobRecipientPlanner::schichtSchluessel($row['event'] ?? 0, (string) $row['datum'], $row['von'] ?? '', $row['taetigkeit'] ?? '');
            $gruppen[$key][] = $row;
        }

        $out = [];
        foreach ($gruppen as $zeilen) {
            usort($zeilen, fn ($a, $b) => (int) $a['offer_shift_id'] <=> (int) $b['offer_shift_id']);
            $offen = array_values(array_filter($zeilen, fn ($z) => empty($z['closed'])));
            $accepted = array_filter($zeilen, fn ($z) => !empty($z['accepted'])) !== [];
            $zaehler = array_values(array_filter(array_map(fn ($z) => $z['frei'] ?? null, $offen), fn ($f) => $f !== null));

            $out[] = [
                'offer_shift_ids' => array_map(fn ($z) => (int) $z['offer_shift_id'], $zeilen),
                'accepted'        => $accepted,
                'voll'            => !$accepted && $offen === [],
                'frei'            => $zaehler === [] ? null : min($zaehler),
            ] + ($offen[0] ?? $zeilen[0]);
        }

        return $out;
    }
}
