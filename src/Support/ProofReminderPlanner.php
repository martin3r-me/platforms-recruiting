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
        $faellig = [];
        foreach ($nachweise as $n) {
            $bis = trim((string) ($n['valid_until'] ?? ''));
            $code = (string) ($n['proof_type_code'] ?? '');

            if ($bis === '' || !ProofTypes::exists($code) || !ProofTypes::hasExpiry($code)) {
                continue;   // ohne Frist gibt es nichts zu erinnern
            }
            if (($n['superseded_at'] ?? null) !== null) {
                continue;   // abgeloeste Fassung — der Nachfolger zaehlt
            }
            if (($n['reminded_at'] ?? null) !== null) {
                continue;   // genau eine Erinnerung, danach steht es im Portal
            }
            if ($stichtag !== null && $bis < $stichtag) {
                continue;   // Altbestand: sichtbar im Portal, aber ungefragt
            }

            $vorlauf = ProofTypes::leadDays($code);
            // Heute hat jede ablaufende Art einen Vorlauf. Eine neue Art ohne
            // wuerde sonst den ganzen taeglichen Lauf mit einer Ausnahme abreissen.
            // Fehlt der Vorlauf, erinnern wir nicht — das ist sicherer als zu krachen.
            if ($vorlauf === null) {
                continue;
            }

            $fenster = date('Y-m-d', strtotime($heute . ' +' . $vorlauf . ' days'));
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
}
