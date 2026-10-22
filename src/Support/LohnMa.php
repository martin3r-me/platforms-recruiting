<?php

namespace Platform\Recruiting\Support;

/**
 * Lohn einer MA-Anstellung fuer den ZAS-Export (Spec 2026-10-19 §3).
 *
 * Grundlohn = EG 1. Zuschlag gleicht an den RG-Gesamtlohn der Person an
 * (Markus 15.10.2026, E14: "mindestens den identischen Gesamtlohn"), dazu
 * +1,00 EUR, wenn die RG-Schwester Steuerklasse 1-5 und die MA die 6 hat
 * (Markus 14.10.2026). Fehlt eine der beiden Steuerklassen oder stehen
 * beide auf 1-5 (widerspruechlich), bleibt der Zuschlag leer (Fall
 * steuerklasse_fehlt). Ohne RG-Anstellung gibt es nichts
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
    /**
     * Hotfix 21.10.2026 (Fall 1568): ZAS fuehrt fuer DIESE MA-Anstellung schon
     * einen Lohn (zas_grundlohn > 0). Dann senden wir Grundlohn und Zuschlag
     * leer, ZAS behaelt seinen Wert — der kann vertraglich vereinbart sein und
     * darf nicht still gesenkt werden ("lieber leer als falsch"). Die Regel
     * gilt damit nur fuer MA-Anstellungen, die bei ZAS noch keinen Lohn haben.
     * Die Rechnung nach der Regel bleibt in $regel zum Vergleich.
     */
    public const ZAS_BESTAND = 'zas_bestand';

    public const QUELLE_BEWERBUNG = 'bewerbung';
    public const QUELLE_ZAS       = 'zas';
    public const QUELLE_MANUELL   = 'manuell';

    public const KLASSE_SECHS_EURO = 1.00;

    private function __construct(
        public readonly string $fall,
        public readonly float $grundlohn,
        public readonly ?float $zuschlag,
        public readonly string $begruendung,
        private readonly ?string $grund = null,
        public readonly ?self $regel = null,
        /**
         * Markus 22.10.2026 (Variante A): kann die Regel nicht exakt
         * umgesetzt werden, geht der Wert trotzdem raus (Tarif ist Pflicht),
         * aber die Akte steht zur Pruefung in der HR-Liste "Lohn". Null =
         * nichts zu pruefen.
         */
        public readonly ?string $pruefen = null,
    ) {
    }

    /**
     * @param  float        $eg1            Tarifsatz EG 1 am Stichtag
     * @param  float        $mindestlohn    RG-Grundlohn (Team-Einstellung minimum_wage_hourly)
     * @param  bool         $hatRg          aktive RG-Anstellung derselben Person vorhanden
     * @param  float|null   $rgZuschlag     RG-Zuschlag; null = unbekannt
     * @param  string|null  $quelle         QUELLE_BEWERBUNG | QUELLE_ZAS | QUELLE_MANUELL | null
     * @param  string|null  $steuerklasseMa tax_class der MA-Anstellung ('1'..'6', 'P' oder null)
     * @param  string|null  $steuerklasseRg tax_class der RG-Schwester ('1'..'6', 'P' oder null)
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

            return new self(self::UNBEKANNT, $eg1, null, implode(' · ', $teile), 'RG-Zuschlag unbekannt');
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

            return new self(self::STEUERKLASSE_FEHLT, $eg1, null, implode(' · ', $teile), 'Steuerklasse fehlt (' . $fehlt . ')');
        }

        // Beide Seiten 1-5 = zwei Hauptarbeitgeber, ein Datenfehler (20.10.2026,
        // "1/1"/"2/2" bei ZAS). Welche Seite stimmt, wissen wir nicht — also
        // wie fehlende Klasse: beide Zellen leer, bis HR klaert.
        $haupt = ['1', '2', '3', '4', '5'];
        if (in_array(strtoupper($ma), $haupt, true) && in_array(strtoupper($rg), $haupt, true)) {
            $teile[] = 'Steuerklasse widersprüchlich (beide Hauptarbeitgeber) — ' . self::PAAR_LEER;

            return new self(self::STEUERKLASSE_FEHLT, $eg1, null, implode(' · ', $teile), 'Steuerklasse widersprüchlich (beide Hauptarbeitgeber)');
        }

        // Markus 22.10.2026 (Variante A): "Wir betrachten immer den
        // Gesamtstundenlohn des Mitarbeiters in beiden Firmen. In der Firma
        // mit der schlechteren Steuerklasse erhaelt er genau 1,00 EUR mehr."
        // RG 1-5 und MA 6 → MA-Gesamt = RG-Gesamt + 1,00; der Zuschlag ist
        // die Differenz zu EG 1. Liegt RG + 1 unter EG 1, zahlt MA den Tarif
        // (Zuschlag 0) und die Akte geht zur Pruefung. MA 1-5 und RG 6: der
        // Euro gehoert zu RG, deren Lohn wir nicht setzen — Angleich wie
        // bisher und zur Pruefung. Sonst (6/6, P, S, /): Angleich, kein Euro.
        $pruefen = null;
        $rgHaupt = in_array($rg, $haupt, true);
        $maHaupt = in_array(strtoupper($ma), $haupt, true);
        if ($rgHaupt && $ma === '6') {
            $ziel = round($gesamt + self::KLASSE_SECHS_EURO, 2);
            $zuschlag = round($ziel - $eg1, 2);
            $teile[] = 'Steuerklasse RG ' . $rg . ' / MA 6: RG + ' . self::euro(self::KLASSE_SECHS_EURO) . ' = ' . self::euro($ziel);
            if ($zuschlag < 0) {
                $zuschlag = 0.0;
                $pruefen = 'RG + 1 € liegt unter EG 1 — MA zahlt den Tarif';
            }
        } elseif ($maHaupt && $rg === '6') {
            $zuschlag = round($angleich, 2);
            $pruefen = 'Steuerklasse MA ' . $ma . ' / RG 6 — der Euro gehört zu RG, RG-Lohn prüfen';
        } else {
            $zuschlag = round($angleich, 2);
        }
        $teile[] = 'Zuschlag ' . self::euro($zuschlag);
        if ($pruefen !== null) {
            $teile[] = 'zur Prüfung: ' . $pruefen;
        }

        $fall = match ($quelle) {
            self::QUELLE_MANUELL => self::MANUELL,
            self::QUELLE_ZAS     => self::ZAS,
            default              => self::ANGLEICH,
        };

        return new self($fall, $eg1, $zuschlag, implode(' · ', $teile), null, null, $pruefen);
    }

    /**
     * Fall "unbekannt" aus einem anderen Grund als dem fehlenden RG-Zuschlag
     * (Ruling R4: Personen-Zuordnung fehlt oder ist unklar). Eigener
     * Einstieg, damit rechne() unveraendert bleibt; Zuschlag leer, kein
     * Klasse-6-Euro — ohne Zuschlag gibt es nichts aufzuschlagen.
     */
    public static function unbekanntWeil(float $eg1, string $grund): self
    {
        return new self(self::UNBEKANNT, $eg1, null, implode(' · ', ['EG 1 ' . self::euro($eg1), $grund, self::PAAR_LEER]), $grund);
    }

    /**
     * Fall zas_bestand (Hotfix 21.10.2026): ZAS fuehrt fuer die MA-Anstellung
     * schon Grundlohn (> 0) und Zuschlag. Ein Fall, der ohnehin leer
     * rausgeht, bleibt wie er ist. Grundlohn 0 heisst bei ZAS "nicht
     * gepflegt" (Befund 19.10.2026) und zaehlt nicht als Lohn.
     */
    public function beiZasLohn(?float $zasGrundlohn, ?float $zasZuschlag): self
    {
        if ($this->paarLeer() || $zasGrundlohn === null || $zasGrundlohn <= 0) {
            return $this;
        }
        $zas = self::euro($zasGrundlohn) . ' + ' . ($zasZuschlag === null ? 'kein Zuschlag' : self::euro($zasZuschlag));
        $grund = 'ZAS führt eigenen MA-Lohn (' . $zas . ')';
        $text = $grund . ' — wir senden nichts, ZAS behält ihn · Regel ergäbe: ' . $this->begruendung;

        return new self(self::ZAS_BESTAND, $this->grundlohn, null, $text, $grund, $this);
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

    /**
     * Nur der Grund, warum der Zuschlag nicht berechenbar ist — ohne Rechnung
     * und ohne ZAS-Wortlaut (fuer den Hinweis "Vertrag aus der Akte": der
     * Vertrag ist nicht der Export). Null in den berechenbaren Faellen.
     */
    public function grundKurz(): ?string
    {
        return $this->paarLeer() ? $this->grund : null;
    }

    /** Geht in diesem Fall weder Grundlohn noch Zuschlag an ZAS? */
    public function paarLeer(): bool
    {
        return $this->fall === self::UNBEKANNT || $this->fall === self::STEUERKLASSE_FEHLT || $this->fall === self::ZAS_BESTAND;
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
