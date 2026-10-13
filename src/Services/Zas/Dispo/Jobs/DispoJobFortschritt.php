<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Fortschritt einer Anfrage (Runde 2, Spec §5): Summen ueber alle Schichten
 * und die Reihenfolge fuer die Seite — offene zuerst, volle dahinter, jeweils
 * nach Datum, Beginn, Taetigkeit. Rein rechnend; nimmt Modelle oder Arrays.
 *
 * `besetzt` zaehlt je Schicht hoechstens so viele Zusagen, wie gesucht sind —
 * im Modus „sammeln“ ist Ueberfuellen normal und darf eine leere Schicht nicht
 * gruen rechnen. `zugesagt` bleibt die rohe Summe.
 */
final class DispoJobFortschritt
{
    /**
     * @param iterable<object|array<string,mixed>> $schichten mit gesucht, zugesagt, eingetragen, angekommen, datum, von, taetigkeit
     * @return array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, besetzt:int, prozent:int, offen:list<mixed>, voll:list<mixed>}
     */
    public static function berechnen(iterable $schichten): array
    {
        $sum = ['gesucht' => 0, 'zugesagt' => 0, 'eingetragen' => 0, 'angekommen' => 0];
        $besetzt = 0;
        $offen = [];
        $voll = [];
        foreach ($schichten as $s) {
            foreach (array_keys($sum) as $k) {
                $sum[$k] += max(0, (int) self::feld($s, $k));
            }
            $besetzt += min(max(0, (int) self::feld($s, 'zugesagt')), max(0, (int) self::feld($s, 'gesucht')));
            $istVoll = (int) self::feld($s, 'gesucht') <= 0 || (int) self::feld($s, 'zugesagt') >= (int) self::feld($s, 'gesucht');
            $istVoll ? $voll[] = $s : $offen[] = $s;
        }
        $sortierer = fn ($a, $b) => [self::datum(self::feld($a, 'datum')), (string) self::feld($a, 'von'), mb_strtolower((string) self::feld($a, 'taetigkeit'))]
            <=> [self::datum(self::feld($b, 'datum')), (string) self::feld($b, 'von'), mb_strtolower((string) self::feld($b, 'taetigkeit'))];
        usort($offen, $sortierer);
        usort($voll, $sortierer);

        return $sum + [
            'besetzt' => $besetzt,
            'prozent' => $sum['gesucht'] > 0 ? min(100, intdiv($besetzt * 100, $sum['gesucht'])) : 0,
            'offen'   => $offen,
            'voll'    => $voll,
        ];
    }

    private static function feld(mixed $s, string $name): mixed
    {
        return is_array($s) ? ($s[$name] ?? null) : ($s->{$name} ?? null);
    }

    private static function datum(mixed $v): string
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr(trim((string) $v), 0, 10);
    }
}
