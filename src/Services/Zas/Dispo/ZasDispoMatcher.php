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
 *
 * Treffen mehrere Datensaetze auf dieselbe Dispo-Nummer (exakt gegen
 * gekuerzte Form, oder Kollision im Rueckfall), gewinnt der einzige aktive;
 * sonst bleibt die Zuordnung offen ('ambiguous').
 */
class ZasDispoMatcher
{
    /** @var array<string, int> */
    private array $byPnr;

    /** @var array<string, list<int>> Nummer ohne eigenen Praefix => Kandidaten (mehr als einer = Kollision) */
    private array $byOwnNumber = [];

    /** @var array<string, list<int>> gekuerzte Dispo-Form => Kandidaten (mehr als einer = Kollision) */
    private array $byShortForm = [];

    /** @var array<int, true>|null aktive employee_ids; null = keine Aussage, kein Vorrang */
    private ?array $active;

    /**
     * @param array<string, int> $byPnr     personnel_number => employee_id
     * @param string             $ownPrefix eigener Firmen-Praefix; leer laesst nur den exakten Vergleich zu
     * @param list<int>|null     $activeIds aktive employee_ids (DispoEmployeeDirectory::activeIds()).
     *                                      Treffen mehrere Datensaetze auf eine Dispo-Nummer und ist genau
     *                                      EINER davon aktiv, gewinnt der. Seit ZAS inaktive Altbestaende
     *                                      liefert, liegt neben dem aktiven `MA1000000878` (Dispo: `MA878`)
     *                                      oft ein inaktiver Alt-Datensatz `MA878` — ohne Vorrang verloere
     *                                      der Aktive jede Lieferung seine Einsaetze.
     */
    public function __construct(array $byPnr, private string $ownPrefix = '', ?array $activeIds = null)
    {
        $this->byPnr = $byPnr;
        $this->active = $activeIds === null ? null : array_fill_keys(array_map('intval', $activeIds), true);

        foreach ($byPnr as $pnr => $id) {
            $key = $this->ownNumber((string) $pnr);
            if ($key === null) {
                continue;
            }
            // Kollision (z. B. '353' und 'RG353' nebeneinander) => nur
            // zuordnen, wenn genau einer aktiv ist. Der exakte Vergleich
            // greift ohnehin zuerst.
            $this->byOwnNumber[$key][] = (int) $id;
        }

        foreach ($byPnr as $pnr => $id) {
            $short = ZasPersonnelNumber::shortenedForm((string) $pnr);
            if ($short === null) {
                continue;
            }
            $this->byShortForm[$short][] = (int) $id;
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
            // meint die Dispo-Zeile eine von beiden. Genau davor hat ZAS
            // gewarnt — zuordnen nur, wenn genau einer davon aktiv ist.
            $exact = (int) $this->byPnr[$pnr];
            $candidates = array_values(array_unique([$exact, ...($this->byShortForm[$pnr] ?? [])]));
            if (count($candidates) > 1) {
                return $this->resolve($candidates, 'exact');
            }

            return ['employee_id' => $exact, 'reason' => 'exact'];
        }

        $key = $this->ownNumber($pnr);
        if ($key !== null && array_key_exists($key, $this->byOwnNumber)) {
            return $this->resolve($this->byOwnNumber[$key], 'own_prefix');
        }

        if (array_key_exists($pnr, $this->byShortForm)) {
            return $this->resolve($this->byShortForm[$pnr], 'shortened');
        }

        return ['employee_id' => null, 'reason' => 'none'];
    }

    /**
     * Ein Kandidat => Treffer mit $reason. Mehrere => der einzige aktive
     * gewinnt ('active_preferred'); sonst (beide aktiv, beide inaktiv oder
     * keine Aktiv-Liste) bleibt es 'ambiguous'.
     *
     * @param list<int> $candidates
     * @return array{employee_id: ?int, reason: string}
     */
    private function resolve(array $candidates, string $reason): array
    {
        $candidates = array_values(array_unique($candidates));
        if (count($candidates) === 1) {
            return ['employee_id' => $candidates[0], 'reason' => $reason];
        }

        if ($this->active !== null) {
            $active = array_values(array_filter($candidates, fn (int $id) => isset($this->active[$id])));
            if (count($active) === 1) {
                return ['employee_id' => $active[0], 'reason' => 'active_preferred'];
            }
        }

        return ['employee_id' => null, 'reason' => 'ambiguous'];
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
