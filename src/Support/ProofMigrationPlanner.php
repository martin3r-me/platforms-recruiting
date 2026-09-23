<?php

namespace Platform\Recruiting\Support;

/**
 * Plant den Umzug der 16 Dateispalten in Nachweis-Zeilen.
 *
 * Die Tuecke: Eine Person kann zwei Anstellungen haben, und in beiden kann
 * dieselbe Datei stehen — entweder weil frueher von Hand gespiegelt wurde oder
 * weil ZAS beide Zeilen befuellt hat. Wuerde je Zeile eine Nachweis-Zeile
 * entstehen, saehe der Mensch seinen Ausweis im Portal doppelt.
 *
 * Deshalb wird JE PERSON UND ART genau ein Nachweis geplant. Gewinnt der
 * Datensatz, der am meisten weiss: erst einer mit Gueltig-bis, dann der
 * juengere. Die Herkunft bleibt am Nachweis vermerkt (rec_employee_id).
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class ProofMigrationPlanner
{
    /**
     * @param  list<array<string,mixed>> $employees  Zeilen mit id, person_key und den Altspalten
     * @return list<array{rec_employee_id:int, person_key:?string, proof_type_code:string,
     *                    file_id:?int, file_back_id:?int, valid_until:?string}>
     */
    public static function plan(array $employees): array
    {
        $beste = [];

        foreach ($employees as $zeile) {
            $id = (int) ($zeile['id'] ?? 0);
            $key = trim((string) ($zeile['person_key'] ?? ''));
            $person = $key !== '' ? "p:{$key}" : "e:{$id}";

            foreach (ProofTypes::all() as $code) {
                $spalten = ProofTypes::legacyFileColumns($code);
                if ($spalten === []) {
                    continue; // neue Art ohne Altbestand
                }

                $fileId = self::intOrNull($zeile[$spalten[0]] ?? null);
                $backId = isset($spalten[1]) ? self::intOrNull($zeile[$spalten[1]] ?? null) : null;

                // Ohne Vorderseite gibt es nichts umzuziehen. Eine Rueckseite
                // allein waere ein Datenfehler, kein Nachweis.
                if ($fileId === null) {
                    continue;
                }

                $ablaufSpalte = ProofTypes::legacyExpiryColumn($code);
                $gueltigBis = $ablaufSpalte !== null ? self::dateOrNull($zeile[$ablaufSpalte] ?? null) : null;

                $kandidat = [
                    'rec_employee_id' => $id,
                    'person_key'      => $key !== '' ? $key : null,
                    'proof_type_code' => $code,
                    'file_id'         => $fileId,
                    'file_back_id'    => $backId,
                    'valid_until'     => $gueltigBis,
                ];

                $schluessel = $person . '|' . $code;
                if (!isset($beste[$schluessel]) || self::istBesser($kandidat, $beste[$schluessel])) {
                    $beste[$schluessel] = $kandidat;
                }
            }
        }

        return array_values($beste);
    }

    /** Erst wer ein Gueltig-bis hat, dann der juengere Datensatz. */
    private static function istBesser(array $neu, array $alt): bool
    {
        $neuHatDatum = $neu['valid_until'] !== null;
        $altHatDatum = $alt['valid_until'] !== null;

        if ($neuHatDatum !== $altHatDatum) {
            return $neuHatDatum;
        }

        return $neu['rec_employee_id'] > $alt['rec_employee_id'];
    }

    private static function intOrNull($wert): ?int
    {
        return ($wert === null || $wert === '' || (int) $wert === 0) ? null : (int) $wert;
    }

    private static function dateOrNull($wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $text = substr((string) $wert, 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $teile) !== 1) {
            return null;
        }

        // MySQL-Altbestaende liefern '0000-00-00'; das passt zwar aufs Muster,
        // ist aber kein Datum. Ein Nachweis mit diesem Gueltig-bis waere
        // sofort abgelaufen und erzeugte eine Aufgabe aus dem Nichts.
        [, $jahr, $monat, $tag] = array_map('intval', $teile);

        return ($jahr >= 1900 && checkdate($monat, $tag, $jahr)) ? $text : null;
    }
}
