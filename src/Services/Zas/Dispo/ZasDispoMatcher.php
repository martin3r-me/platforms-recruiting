<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * PNr-Matching mit Firmen-Praefix. Pure — bekommt die Map aus
 * DispoEmployeeDirectory::map() injiziert.
 *
 * Frueher: exakter Vergleich, sonst Rueckfall auf die blanken Ziffern. Der
 * Rueckfall war noetig, solange unsere Nummern keinen Praefix trugen — er hat
 * aber `MA353` an einen RG-Mitarbeiter mit der Nummer 353 gehaengt, weil ZAS
 * fuer beide Firmen dieselben Ziffernfolgen vergibt (belegt: 276, 322, 325,
 * 353). Damit lagen fremde Einsaetze bei unseren Leuten (Befund 2026-08-26).
 *
 * Jetzt entscheidet der Praefix:
 *   1. exakter Vergleich
 *   2. sonst: nur wenn BEIDE Seiten zur eigenen Firma gehoeren. Eine blanke
 *      Nummer gilt dabei als eigene Firma — dieselbe Annahme, mit der auch der
 *      Mitarbeiter-Import normalisiert (ZasPersonnelNumber). Das faengt von
 *      Hand im Backend eingetragene Nummern ab, die noch ohne Praefix stehen.
 *   3. ein fremder Praefix trifft nie.
 */
class ZasDispoMatcher
{
    /** @var array<string, int> */
    private array $byPnr;

    /** @var array<string, int|null> Nummer ohne eigenen Praefix => employee_id, null = mehrdeutig */
    private array $byOwnNumber = [];

    /** @var array<string, int|null> gekuerzte Dispo-Form => employee_id, null = mehrdeutig */
    private array $byShortForm = [];

    /**
     * @param array<string, int> $byPnr     personnel_number => employee_id
     * @param string             $ownPrefix eigener Firmen-Praefix; leer laesst nur den exakten Vergleich zu
     */
    public function __construct(array $byPnr, private string $ownPrefix = '')
    {
        $this->byPnr = $byPnr;

        foreach ($byPnr as $pnr => $id) {
            $key = $this->ownNumber((string) $pnr);
            if ($key === null) {
                continue;
            }
            // Kollision (z. B. '353' und 'RG353' nebeneinander) => bewusst
            // nicht zuordnen. Der exakte Vergleich greift ohnehin zuerst.
            $this->byOwnNumber[$key] = array_key_exists($key, $this->byOwnNumber) ? null : $id;
        }

        foreach ($byPnr as $pnr => $id) {
            $short = ZasPersonnelNumber::shortenedForm((string) $pnr);
            if ($short === null) {
                continue;
            }
            $this->byShortForm[$short] = array_key_exists($short, $this->byShortForm) ? null : $id;
        }
    }

    /** @return array{employee_id: ?int, reason: string} */
    public function match(?string $pnrRaw): array
    {
        $pnr = trim((string) $pnrRaw);
        if ($pnr === '') {
            return ['employee_id' => null, 'reason' => 'empty'];
        }

        if (array_key_exists($pnr, $this->byPnr)) {
            // Traegt jemand anderes dieselbe Nummer in der gekuerzten Form,
            // meint die Dispo-Zeile eine von beiden und wir koennen nicht
            // wissen welche. Genau davor hat ZAS gewarnt.
            $alias = $this->byShortForm[$pnr] ?? null;
            if ($alias !== null && $alias !== $this->byPnr[$pnr]) {
                return ['employee_id' => null, 'reason' => 'ambiguous'];
            }

            return ['employee_id' => $this->byPnr[$pnr], 'reason' => 'exact'];
        }

        $key = $this->ownNumber($pnr);
        if ($key !== null && array_key_exists($key, $this->byOwnNumber)) {
            return $this->byOwnNumber[$key] === null
                ? ['employee_id' => null, 'reason' => 'ambiguous']
                : ['employee_id' => $this->byOwnNumber[$key], 'reason' => 'own_prefix'];
        }

        if (array_key_exists($pnr, $this->byShortForm)) {
            return $this->byShortForm[$pnr] === null
                ? ['employee_id' => null, 'reason' => 'ambiguous']
                : ['employee_id' => $this->byShortForm[$pnr], 'reason' => 'shortened'];
        }

        return ['employee_id' => null, 'reason' => 'none'];
    }

    /**
     * Nummer ohne den eigenen Praefix — oder null, wenn der Wert nicht zur
     * eigenen Firma gehoert (fremder Praefix) bzw. keine Nummer uebrig bleibt.
     */
    private function ownNumber(string $value): ?string
    {
        if ($this->ownPrefix === '') {
            return null;
        }

        if (!ZasPersonnelNumber::hasPrefix($value)) {
            return $value;
        }

        if (str_starts_with($value, $this->ownPrefix)) {
            $rest = substr($value, strlen($this->ownPrefix));

            return $rest === '' ? null : $rest;
        }

        return null;
    }
}
