<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Feld-Parser fuer ZAS-Webexport-Werte: dd.mm.yyyy, HH:MM(:SS),
 * Dezimal-Komma, <br/>-HTML. Unparsebares wird null — nie werfen
 * (Sichtung/Import sind Best-Effort, Rohdatei bleibt die Wahrheit).
 */
class ZasDispoFieldParser
{
    public static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }

    public static function time(?string $v): ?string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $i);
    }

    public static function decimal(?string $v): ?float
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $normalized = str_replace(',', '.', str_replace('.', '', $v));
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    public static function int(?string $v): ?int
    {
        $v = trim((string) $v);
        return ($v !== '' && preg_match('/^-?\d+$/', $v)) ? (int) $v : null;
    }

    public static function text(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $out = preg_replace('/<br\s*\/?>/i', "\n", $v) ?? $v;
        $out = trim($out);

        return $out === '' ? null : $out;
    }
}
