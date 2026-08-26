<?php

namespace Platform\Recruiting\Support;

/**
 * Firmen-Praefix an der ZAS-Personalnummer (`RG353`, `MA353`).
 *
 * ZAS bedient zwei Firmen. Die Dispo-Lieferung traegt den Praefix seit jeher,
 * der Mitarbeiter-Export bisher nicht — und weil beide Firmen dieselben
 * Ziffernfolgen vergeben (belegt: 276, 322, 325, 353), war die Personalnummer
 * bei uns nicht eindeutig. Sie ist aber gleichzeitig Dubletten-Schluessel beim
 * Import UND Zuordnungsschluessel in der Disposition; die Folge waren Einsaetze
 * der MA-Person beim gleichnamigen RG-Mitarbeiter (Befund 2026-08-26).
 *
 * ZAS stellt den Mitarbeiter-Export auf die Praefix-Form um. Damit es dafuer
 * keinen Stichtag braucht, normalisieren wir beim Import selbst: eine blanke
 * Nummer bekommt den konfigurierten eigenen Praefix. Vorher wie nachher steht
 * bei uns dieselbe Form.
 *
 * Ein FREMDER Praefix wird nie angefasst — sonst wuerde aus einer MA-Person
 * eine RG-Person.
 */
final class ZasPersonnelNumber
{
    /**
     * Vorgabe fuer den eigenen Praefix. Steht hier statt nur in der Config,
     * weil auch die Migration ihn braucht — und die laeuft in einigen
     * Testharnessen ohne gebundene Config.
     */
    public const DEFAULT_PREFIX = 'RG';

    /**
     * Traegt der Wert bereits einen Firmen-Praefix?
     *
     * Erkennungsmerkmal ist schlicht ein Buchstabe am Anfang: ZAS-Nummern sind
     * sonst rein numerisch.
     */
    public static function hasPrefix(?string $value): bool
    {
        return (bool) preg_match('/^\p{L}/u', trim((string) $value));
    }

    /**
     * Firmenkuerzel aus der Nummer — `MA1000000878` ergibt `MA`.
     *
     * Der Praefix IST die Firmenzugehoerigkeit; ZAS liefert ihn seit 08/2026
     * mit. Eine blanke Nummer traegt die Information nicht in sich, dann muss
     * sie von aussen kommen (Vorgabe oder HR-Eingabe).
     */
    public static function prefixOf(?string $value): ?string
    {
        if (!preg_match('/^(\p{L}+)/u', trim((string) $value), $m)) {
            return null;
        }

        return mb_strtoupper($m[1]);
    }

    /**
     * @param  string $ownPrefix eigener Firmen-Praefix; leer schaltet ab
     * @return string|null normalisierte Nummer, oder null wenn nichts geliefert
     */
    public static function normalize(?string $raw, string $ownPrefix): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if ($ownPrefix === '' || self::hasPrefix($value)) {
            return $value;
        }

        return $ownPrefix . $value;
    }
}
