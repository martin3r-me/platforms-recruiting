<?php

namespace Platform\Recruiting\Services\Zeit\Waesche;

/**
 * Rechnet je Mitarbeiter und Einsatz, was die Wäsche kostet (Markus, siehe WaescheArtikel):
 * Abzug für den Lohn, Anzahl Leih-Teile für den Kunden, nicht zurückgegebene Teile.
 * Reine Rechnerei ohne Datenbank, Beträge in Cent.
 */
final class WaescheRechner
{
    /**
     * @param list<array{artikel: WaescheArtikel, anzahl: int, zurueck: int}> $posten
     * @return array{abzugCent: int, kunde: array<string, int>, fehlt: list<string>, ausgegeben: list<string>, allesZurueck: bool}
     */
    public static function rechne(array $posten): array
    {
        $abzug = 0;
        $kunde = [];
        $fehlt = [];
        $ausgegeben = [];
        foreach ($posten as $p) {
            $anzahl = max(0, (int) $p['anzahl']);
            if ($anzahl === 0) {
                continue;
            }
            $zurueck = min($anzahl, max(0, (int) $p['zurueck']));
            $offen = $anzahl - $zurueck;
            $a = $p['artikel'];

            $abzug += $zurueck * $a->reinigungLohnCent + $offen * $a->preisCent;
            if ($a->leiheKunde) {
                $kunde[$a->key] = ($kunde[$a->key] ?? 0) + $anzahl;
            }
            for ($i = 0; $i < $anzahl; $i++) {
                $ausgegeben[] = $a->name;
            }
            for ($i = 0; $i < $offen; $i++) {
                $fehlt[] = $a->name;
            }
        }

        return [
            'abzugCent'    => $abzug,
            'kunde'        => $kunde,
            'fehlt'        => $fehlt,
            'ausgegeben'   => $ausgegeben,
            'allesZurueck' => $ausgegeben !== [] && $fehlt === [],
        ];
    }

    /** 3400 -> "34,00" */
    public static function euro(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '');
    }
}
