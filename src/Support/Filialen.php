<?php

namespace Platform\Recruiting\Support;

/**
 * Zentrale Zuordnung Filialnummer → Filiale. Die Nummer ist der kanonische
 * Schluessel: sie kommt aus dem ZAS-Webexport ({Dispo2} filial_nr) und liegt
 * zugleich als rec_positions.cost_center an den Recruiting-Stellen. Quelle ist
 * config('recruiting.filialen'); diese Klasse ist die eine Aufloesungs-Tuer
 * fuer Dispo UND Recruiting, damit Code/Name an genau einer Stelle gepflegt wird.
 */
final class Filialen
{
    /** @return array<int, array{code: string, name: string}> */
    public static function all(): array
    {
        $out = [];
        foreach ((array) config('recruiting.filialen', []) as $nr => $data) {
            $out[(int) $nr] = [
                'code' => (string) ($data['code'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
            ];
        }

        return $out;
    }

    /** ZAS-Kuerzel zur Nummer (Anzeige), null wenn unbekannt/leer. */
    public static function code(?int $nr): ?string
    {
        if ($nr === null) {
            return null;
        }

        $code = self::all()[$nr]['code'] ?? '';

        return $code === '' ? null : $code;
    }

    /** Klarname zur Nummer, null wenn unbekannt/leer. */
    public static function name(?int $nr): ?string
    {
        if ($nr === null) {
            return null;
        }

        $name = self::all()[$nr]['name'] ?? '';

        return $name === '' ? null : $name;
    }

    /**
     * Options fuer Filter-Dropdowns: Nummer => Code (nur bekannte Nummern).
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $nr => $data) {
            if ($data['code'] !== '') {
                $out[$nr] = $data['code'];
            }
        }

        return $out;
    }
}
