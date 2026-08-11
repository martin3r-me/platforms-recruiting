<?php

namespace Platform\Recruiting\Support;

/**
 * Der Platzhalter fuer das Rest-Kontingent in der 140-Tage-Erklaerung.
 *
 * Er ist in der Vorlage bewusst NICHT als field_mapping hinterlegt: damit
 * ueberlebt er personalizeContent() (das nur ueber die gemappten Keys
 * ersetzt) und wird erst beim Unterschreiben durch die Angabe des
 * Bewerbers ersetzt.
 */
final class ResttagePlaceholder
{
    public const PLACEHOLDER = '{{resttage}}';

    /** Diskriminator in rec_contracts.pre_signing_data. */
    public const TYPE = 'resttage';

    public static function fill(string $content, int $tage): string
    {
        return str_replace(self::PLACEHOLDER, (string) $tage, $content);
    }

    /**
     * Ist dieses pre_signing_data-Array vom Resttage-Typ?
     *
     * Bestandszeilen (§15/§16) haben keinen 'type'-Schluessel — bis AT-140
     * war das der einzige Vorschalt-Schritt. Fehlt der Schluessel, ist die
     * Antwort deshalb immer false.
     */
    public static function appliesTo(array $data): bool
    {
        return ($data['type'] ?? null) === self::TYPE;
    }

    /**
     * Einstiegspunkt fuer RecContract::embedPreSigningData().
     *
     * null  = nicht zustaendig (Altdaten ohne 'type').
     * Sonst = der Inhalt. Gefuellt, wenn eine brauchbare Zahl vorliegt.
     *         UNVERAENDERT, wenn nicht — der Platzhalter bleibt dann stehen,
     *         damit der Guard im Signier-Flow greift.
     *
     * Bewusst KEIN `?? 0`-Fallback: RePersonalizeContractsTool liest
     * pre_signing_data unvalidiert aus der DB. Ein Default 0 wuerde still
     * "noch 0 Tage" in ein bereits unterschriebenes Dokument schreiben —
     * ohne Validator davor und ohne dass der Guard es merkt, weil
     * "noch 0 Tage" syntaktisch vollstaendig ist.
     */
    public static function embed(string $content, array $data): ?string
    {
        if (!self::appliesTo($data)) {
            return null;
        }

        $tage = $data['resttage'] ?? null;

        // Bewusst NICHT is_numeric: das liesse '-5' (→ "noch -5 Tage"),
        // '1e3' (→ "noch 1000 Tage") und 87.9 (→ stille Trunkierung auf 87)
        // durch. Der Schreibpfad erlaubt integer|min:0|max:140; hier wird
        // dieselbe Form verlangt, nur ohne die Obergrenze zu duplizieren —
        // sonst stuende die 140 an einer dritten Stelle.
        $isValidInt = is_int($tage) && $tage >= 0;
        $isValidString = is_string($tage) && preg_match('/^\d+$/', $tage);

        if (!($isValidInt || $isValidString)) {
            return $content;
        }

        return self::fill($content, (int) $tage);
    }

    /**
     * Steht im Text noch ein unaufgeloester {{...}}-Platzhalter?
     *
     * Bewusst generisch statt auf PLACEHOLDER begrenzt: der wahrscheinlichste
     * Fehler ist ein Tippfehler in der Vorlage ("{{ resttage }}" mit
     * Leerzeichen), den eine exakte Suche nicht faende.
     *
     * Zeichenklasse bewusst [^{}]+ und nicht [A-Za-z0-9_]+: Der Vorlagen-
     * Editor validiert Platzhalternamen nur als required|string|max:255,
     * Punkte und Bindestriche sind also erlaubt. Ein Guard, der bei
     * {{kontakt.vorname}} nicht ausloest, schuetzt nicht. Einfache
     * geschweifte Klammern (CSS wie {color:red}) bleiben unberuehrt, weil
     * zwei Klammern gefordert sind.
     */
    public static function hasUnresolvedPlaceholder(string $content): bool
    {
        return preg_match('/\{\{[^{}]+\}\}/', $content) === 1;
    }
}
