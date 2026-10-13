<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/** Textliste der Zusagen einer Schicht fuer die Zwischenablage (Runde 2, Spec §6). Rein. */
final class DispoJobZasListe
{
    /** @param list<array{name:string, personnel_number:string, phone:string}> $zeilen */
    public static function text(array $zeilen): string
    {
        $out = [];
        foreach ($zeilen as $z) {
            $out[] = implode(' · ', [
                trim((string) ($z['name'] ?? '')) !== '' ? trim((string) $z['name']) : '–',
                trim((string) ($z['personnel_number'] ?? '')) !== '' ? trim((string) $z['personnel_number']) : '–',
                trim((string) ($z['phone'] ?? '')) !== '' ? trim((string) $z['phone']) : '–',
            ]);
        }

        return implode("\n", $out);
    }
}
