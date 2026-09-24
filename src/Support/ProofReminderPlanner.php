<?php

namespace Platform\Recruiting\Support;

final class ProofReminderPlanner
{
    /**
     * @param  list<array{id:int, rec_employee_id:int, proof_type_code:string,
     *                    valid_until:?string, reminded_at:?string, superseded_at:?string}> $nachweise
     * @param  string      $heute    Y-m-d
     * @param  string|null $stichtag Y-m-d — Fristen VOR diesem Tag bleiben stumm.
     *                               Das ist die Bremse gegen die Altbestands-Welle.
     * @return list<array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}>
     */
    public static function plan(array $nachweise, string $heute, ?string $stichtag): array
    {
        // $heute ist Aufrufer-Verantwortung — ein unlesbares Datum dort ist ein
        // Programmierfehler, nicht ein Datenzustand. Wir krachen statt still gegen
        // die Serverzeit zu rechnen. $bis und $stichtag bleiben tolerant — bei ihnen
        // ist null ein echter Zustand (fehlende/kaputte Einzeldaten, kein Stichtag).
        $heute_tag = self::alsTag($heute);
        if ($heute_tag === null) {
            throw new \InvalidArgumentException(
                "ProofReminderPlanner::plan() braucht ein Datum als Y-m-d, bekam '{$heute}'."
            );
        }

        $faellig = [];
        foreach ($nachweise as $n) {
            $bis = self::alsTag($n['valid_until'] ?? '');
            $code = (string) ($n['proof_type_code'] ?? '');

            if ($bis === null || !ProofTypes::exists($code) || !ProofTypes::hasExpiry($code)) {
                continue;   // ohne Frist gibt es nichts zu erinnern
            }
            if (($n['superseded_at'] ?? null) !== null) {
                continue;   // abgeloeste Fassung — der Nachfolger zaehlt
            }
            if (($n['reminded_at'] ?? null) !== null) {
                continue;   // genau eine Erinnerung, danach steht es im Portal
            }

            $stichtag_tag = self::alsTag($stichtag);
            if ($stichtag_tag !== null && $bis < $stichtag_tag) {
                continue;   // Altbestand: sichtbar im Portal, aber ungefragt
            }

            $vorlauf = ProofTypes::leadDays($code);
            // Heute hat jede ablaufende Art einen Vorlauf. Eine neue Art ohne
            // wuerde sonst ein falsches Fenster stillschweigend ergeben statt sichtbar
            // zu krachen. Fehlt der Vorlauf, erinnern wir nicht — das ist sicherer.
            if ($vorlauf === null) {
                continue;
            }

            $fenster = date('Y-m-d', strtotime($heute_tag . ' +' . $vorlauf . ' days'));
            if ($bis > $fenster) {
                continue;   // noch zu frueh
            }

            $faellig[] = [
                'proof_id'        => (int) $n['id'],
                'rec_employee_id' => (int) $n['rec_employee_id'],
                'code'            => $code,
                'valid_until'     => $bis,
            ];
        }

        // Das Dringendste zuerst — wenn --limit greift, soll es das Richtige treffen.
        usort($faellig, fn ($a, $b) => [$a['valid_until'], $a['proof_id']]
                                   <=> [$b['valid_until'], $b['proof_id']]);

        return $faellig;
    }

    /**
     * Auf Y-m-d zurechtstutzen. Der Vergleich oben ist ein
     * Zeichenkettenvergleich — er stimmt nur, solange alle drei Werte
     * dasselbe Format haben. Ein Eloquent-date-Cast liefert ueber
     * toArray() aber "2026-10-24T00:00:00.000000Z", und das ist
     * lexikografisch GROESSER als "2026-10-24". Ohne diese Zeile
     * verschoebe sich die Erinnerung an der Fenstergrenze um einen Tag.
     */
    private static function alsTag(?string $wert): ?string
    {
        $roh = trim((string) $wert);

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $roh, $m) ? $m[1] : null;
    }
}
