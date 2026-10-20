<?php

namespace Platform\Recruiting\Support;

/**
 * Lohn einer MA-Anstellung fuer den ZAS-Export (Spec 2026-10-19 §3).
 *
 * Grundlohn = EG 1. Zuschlag gleicht an den RG-Gesamtlohn der Person an
 * (Markus 15.10.2026, E14: "mindestens den identischen Gesamtlohn"), dazu
 * +1,00 EUR, wenn die RG-Schwester Steuerklasse 1-5 und die MA die 6 hat
 * (Markus 14.10.2026). Fehlt eine der beiden Steuerklassen, bleibt der
 * Zuschlag leer (Fall steuerklasse_fehlt). Ohne RG-Anstellung gibt es nichts
 * anzugleichen ("nur MA ohne Zuschlag", 16.10.2026). Ist der RG-Zuschlag
 * unbekannt (Bestand ohne Bewerbung, nichts eingetragen), bleibt der
 * Zuschlag LEER — ein geratener Betrag landet im Lohn, ein leerer faellt auf.
 *
 * In beiden Leer-Faellen (unbekannt, steuerklasse_fehlt) geht auch der
 * Grundlohn LEER raus (Pre-Merge-Review I2, 20.10.2026): ZAS behaelt dann
 * sein bisheriges, zueinander passendes Paar. Neuer Grundlohn 15,33 neben
 * einem alten ZAS-Zuschlag, der auf 14,96 / 14,53 gerechnet war, ergaebe
 * einen zu hohen Gesamtlohn. Der Wert $grundlohn bleibt intern EG 1 (Anzeige
 * der Rechnung, Vertrag), nur der Exporttext grundlohnText() ist null.
 *
 * Reine Wertklasse, kein Framework/DB.
 */
final class LohnMa
{
    public const ANGLEICH  = 'angleich';
    public const ZAS       = 'zas';
    public const UNBEKANNT = 'unbekannt';
    public const MANUELL   = 'manuell';
    public const NUR_MA    = 'nur_ma';
    public const STEUERKLASSE_FEHLT = 'steuerklasse_fehlt';

    public const QUELLE_BEWERBUNG = 'bewerbung';
    public const QUELLE_ZAS       = 'zas';
    public const QUELLE_MANUELL   = 'manuell';

    public const KLASSE_SECHS_EURO = 1.00;

    private function __construct(
        public readonly string $fall,
        public readonly float $grundlohn,
        public readonly ?float $zuschlag,
        public readonly string $begruendung,
    ) {
    }

    /**
     * @param  float        $eg1            Tarifsatz EG 1 am Stichtag
     * @param  float        $mindestlohn    RG-Grundlohn (Team-Einstellung minimum_wage_hourly)
     * @param  bool         $hatRg          aktive RG-Anstellung derselben Person vorhanden
     * @param  float|null   $rgZuschlag     RG-Zuschlag; null = unbekannt
     * @param  string|null  $quelle         QUELLE_BEWERBUNG | QUELLE_ZAS | QUELLE_MANUELL | null
     * @param  string|null  $steuerklasseMa tax_class der MA-Anstellung ('1'..'6' oder null)
     * @param  string|null  $steuerklasseRg tax_class der RG-Schwester ('1'..'6' oder null)
     */
    public static function rechne(float $eg1, float $mindestlohn, bool $hatRg, ?float $rgZuschlag, ?string $quelle, ?string $steuerklasseMa, ?string $steuerklasseRg = null): self
    {
        $teile = ['EG 1 ' . self::euro($eg1)];

        // Nur MA: nichts anzugleichen, NIE ein Euro ("Nur MA immer EG 1 ohne
        // Zuschlag", Markus 16.10.2026) — egal welche Steuerklasse.
        if (!$hatRg) {
            $teile[] = 'nur MA, kein Angleich';
            $teile[] = 'Zuschlag ' . self::euro(0.0);

            return new self(self::NUR_MA, $eg1, 0.0, implode(' · ', $teile));
        }

        // Unbekannter RG-Zuschlag hat Vorrang vor der Steuerklasse.
        if ($rgZuschlag === null) {
            $teile[] = 'RG-Zuschlag unbekannt (keine Bewerbung, nichts eingetragen)';
            $teile[] = self::PAAR_LEER;

            return new self(self::UNBEKANNT, $eg1, null, implode(' · ', $teile));
        }

        $gesamt = $mindestlohn + $rgZuschlag;
        $angleich = max(0.0, $gesamt - $eg1);
        $herkunft = match ($quelle) {
            self::QUELLE_MANUELL => ', von HR eingetragen',
            self::QUELLE_ZAS     => ', laut ZAS',
            default              => '',
        };
        $teile[] = sprintf('Angleich an RG %s (%s + %s%s) → %s',
            self::euro($gesamt), self::zahl($mindestlohn), self::zahl($rgZuschlag), $herkunft, self::euro($angleich));

        // Steuerklasse an MA oder RG leer: der +1-Euro-Vergleich ist nicht
        // moeglich. Lieber leere Zellen (ZAS behaelt sein Paar aus Grundlohn
        // und Zuschlag) als ein falscher Wert.
        $ma = trim((string) $steuerklasseMa);
        $rg = trim((string) $steuerklasseRg);
        if ($ma === '' || $rg === '') {
            $fehlt = $ma === '' && $rg === '' ? 'MA und RG' : ($ma === '' ? 'MA' : 'RG');
            $teile[] = 'Steuerklasse fehlt (' . $fehlt . ') — ' . self::PAAR_LEER;

            return new self(self::STEUERKLASSE_FEHLT, $eg1, null, implode(' · ', $teile));
        }

        // Markus 14.10.: RG 1-5 und in der MA die 6 → 1,00 EUR mehr.
        $sechs = ($ma === '6' && in_array($rg, ['1', '2', '3', '4', '5'], true)) ? self::KLASSE_SECHS_EURO : 0.0;
        if ($sechs > 0) {
            $teile[] = 'Steuerklasse 6 +' . self::euro($sechs);
        }
        $zuschlag = round($angleich + $sechs, 2);
        $teile[] = 'Zuschlag ' . self::euro($zuschlag);

        $fall = match ($quelle) {
            self::QUELLE_MANUELL => self::MANUELL,
            self::QUELLE_ZAS     => self::ZAS,
            default              => self::ANGLEICH,
        };

        return new self($fall, $eg1, $zuschlag, implode(' · ', $teile));
    }

    /**
     * Fall "unbekannt" aus einem anderen Grund als dem fehlenden RG-Zuschlag
     * (Ruling R4: Personen-Zuordnung fehlt oder ist unklar). Eigener
     * Einstieg, damit rechne() unveraendert bleibt; Zuschlag leer, kein
     * Klasse-6-Euro — ohne Zuschlag gibt es nichts aufzuschlagen.
     */
    public static function unbekanntWeil(float $eg1, string $grund): self
    {
        return new self(self::UNBEKANNT, $eg1, null, implode(' · ', ['EG 1 ' . self::euro($eg1), $grund, self::PAAR_LEER]));
    }

    /** Begruendungs-Schluss der beiden Leer-Faelle (Pre-Merge-Review I2). */
    public const PAAR_LEER = 'Grundlohn und Zuschlag leer — ZAS behält sein bisheriges Paar';

    /**
     * Grundlohn fuer den ZAS-Export. Null (leere Zelle) in den Faellen
     * unbekannt und steuerklasse_fehlt: Grundlohn und Zuschlag gehen nur
     * zusammen raus, sonst passt der neue Grundlohn nicht zum alten
     * ZAS-Zuschlag (Pre-Merge-Review I2).
     */
    public function grundlohnText(): ?string
    {
        if ($this->paarLeer()) {
            return null;
        }

        return self::zahl($this->grundlohn);
    }

    /** Geht in diesem Fall weder Grundlohn noch Zuschlag an ZAS? */
    public function paarLeer(): bool
    {
        return $this->fall === self::UNBEKANNT || $this->fall === self::STEUERKLASSE_FEHLT;
    }

    public function zuschlagText(): ?string
    {
        return $this->zuschlag === null ? null : self::zahl($this->zuschlag);
    }

    public static function zahl(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private static function euro(float $v): string
    {
        return self::zahl($v) . ' €';
    }
}
