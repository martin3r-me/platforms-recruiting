<?php

namespace Platform\Recruiting\Support;

/**
 * Lohn einer MA-Anstellung fuer den ZAS-Export (Spec 2026-10-19 §3).
 *
 * Grundlohn = EG 1. Zuschlag gleicht an den RG-Gesamtlohn der Person an
 * (Markus 15.10.2026, E14: "mindestens den identischen Gesamtlohn"), dazu
 * +1,00 EUR bei Steuerklasse 6. Ohne RG-Anstellung gibt es nichts
 * anzugleichen ("nur MA ohne Zuschlag", 16.10.2026). Ist der RG-Zuschlag
 * unbekannt (Bestand ohne Bewerbung, nichts eingetragen), bleibt der
 * Zuschlag LEER — ein geratener Betrag landet im Lohn, ein leerer faellt auf.
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
     * @param  float        $eg1          Tarifsatz EG 1 am Stichtag
     * @param  float        $mindestlohn  RG-Grundlohn (Team-Einstellung minimum_wage_hourly)
     * @param  bool         $hatRg        aktive RG-Anstellung derselben Person vorhanden
     * @param  float|null   $rgZuschlag   RG-Zuschlag; null = unbekannt
     * @param  string|null  $quelle       QUELLE_BEWERBUNG | QUELLE_ZAS | QUELLE_MANUELL | null
     * @param  string|null  $steuerklasse tax_class der MA-Anstellung ('1'..'6' oder null)
     */
    public static function rechne(float $eg1, float $mindestlohn, bool $hatRg, ?float $rgZuschlag, ?string $quelle, ?string $steuerklasse): self
    {
        $teile = ['EG 1 ' . self::euro($eg1)];
        $klasse = trim((string) $steuerklasse);
        $sechs = $klasse === '6' ? self::KLASSE_SECHS_EURO : 0.0;

        if (!$hatRg) {
            $teile[] = 'nur MA, kein Angleich';
            $zuschlag = round($sechs, 2);
            $fall = self::NUR_MA;
        } elseif ($rgZuschlag === null) {
            $teile[] = 'RG-Zuschlag unbekannt (keine Bewerbung, nichts eingetragen)';
            $teile[] = 'Zuschlag leer';

            return new self(self::UNBEKANNT, $eg1, null, implode(' · ', $teile));
        } else {
            $gesamt = $mindestlohn + $rgZuschlag;
            $angleich = max(0.0, $gesamt - $eg1);
            $herkunft = match ($quelle) {
                self::QUELLE_MANUELL => ', von HR eingetragen',
                self::QUELLE_ZAS     => ', laut ZAS',
                default              => '',
            };
            $teile[] = sprintf('Angleich an RG %s (%s + %s%s) → %s',
                self::euro($gesamt), self::zahl($mindestlohn), self::zahl($rgZuschlag), $herkunft, self::euro($angleich));
            $zuschlag = round($angleich + $sechs, 2);
            $fall = match ($quelle) {
                self::QUELLE_MANUELL => self::MANUELL,
                self::QUELLE_ZAS     => self::ZAS,
                default              => self::ANGLEICH,
            };
        }

        if ($sechs > 0) {
            $teile[] = 'Steuerklasse 6 +' . self::euro($sechs);
        } elseif ($klasse === '') {
            $teile[] = 'Steuerklasse unbekannt, kein +1 €';
        }
        $teile[] = 'Zuschlag ' . self::euro($zuschlag);

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
        return new self(self::UNBEKANNT, $eg1, null, implode(' · ', ['EG 1 ' . self::euro($eg1), $grund, 'Zuschlag leer']));
    }

    public function grundlohnText(): string
    {
        return self::zahl($this->grundlohn);
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
