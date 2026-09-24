<?php

namespace Platform\Recruiting\Support;

/**
 * „Was fehlt dir noch, was laeuft ab?" — die Aufgabenliste einer Person.
 *
 * Wird ABGELEITET, nicht gespeichert: Katalog plus Pflicht-Regel plus
 * vorhandene Nachweise ergeben den Stand. Eine Tabelle koennte von der
 * Wirklichkeit abweichen, eine Ableitung nicht.
 *
 * Die Liste enthaelt auch Nachweise, die nicht Pflicht sind, aber vorliegen —
 * es sind die Unterlagen des Menschen, er soll sie sehen.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class ProofChecklist
{
    public const FEHLT      = 'fehlt';
    public const ABGELAUFEN = 'abgelaufen';
    public const LAEUFT_AB  = 'laeuft_ab';
    public const OK         = 'ok';

    /** Offenes zuerst, und davon das Dringendste oben. */
    private const REIHENFOLGE = [self::ABGELAUFEN => 0, self::FEHLT => 1, self::LAEUFT_AB => 2, self::OK => 3];

    /**
     * @param  list<string> $pflicht      Codes aus ProofTypes::requiredFor()
     * @param  list<array{proof_type_code:string, valid_until:?string}> $vorhanden
     *         Nur die jeweils aktuelle Fassung je Art.
     * @param  string $heute              Y-m-d
     * @return list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}>
     */
    public static function build(array $pflicht, array $vorhanden, string $heute): array
    {
        $jeArt = [];
        foreach ($vorhanden as $nachweis) {
            $code = (string) ($nachweis['proof_type_code'] ?? '');
            if ($code !== '' && ProofTypes::exists($code)) {
                $jeArt[$code] = $nachweis['valid_until'] ?? null;
            }
        }

        $codes = array_values(array_unique(array_merge(
            array_values(array_filter($pflicht, fn ($c) => ProofTypes::exists($c))),
            array_keys($jeArt),
        )));

        $zeilen = [];
        foreach ($codes as $code) {
            $hatNachweis = array_key_exists($code, $jeArt);
            $gueltigBis = $hatNachweis ? $jeArt[$code] : null;

            $status = match (true) {
                !$hatNachweis                                   => self::FEHLT,
                $gueltigBis === null                            => self::OK,
                $gueltigBis < $heute                            => self::ABGELAUFEN,
                self::laeuftBaldAb($code, $gueltigBis, $heute)  => self::LAEUFT_AB,
                default                                         => self::OK,
            };

            $zeilen[] = [
                'code'        => $code,
                'label'       => ProofTypes::label($code),
                'status'      => $status,
                'valid_until' => $gueltigBis,
                'offen'       => $status !== self::OK,
            ];
        }

        usort($zeilen, static fn ($a, $b) => [self::REIHENFOLGE[$a['status']], $a['label']]
                                         <=> [self::REIHENFOLGE[$b['status']], $b['label']]);

        return $zeilen;
    }

    /** @param list<array{status:string}> $zeilen */
    public static function offeneAnzahl(array $zeilen): int
    {
        return count(array_filter($zeilen, static fn ($z) => $z['status'] !== self::OK));
    }

    private static function laeuftBaldAb(string $code, string $gueltigBis, string $heute): bool
    {
        $vorlauf = ProofTypes::leadDays($code);
        if ($vorlauf === null) {
            return false;
        }
        $grenze = date('Y-m-d', strtotime($heute . " +{$vorlauf} days"));

        return $gueltigBis <= $grenze;
    }
}
