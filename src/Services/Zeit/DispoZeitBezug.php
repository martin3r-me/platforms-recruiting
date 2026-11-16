<?php

namespace Platform\Recruiting\Services\Zeit;

use Carbon\CarbonImmutable;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Comms\TeamClock;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitEintrag;

/** Baut den Zeit-Bezug aus einer Dispo-Einbuchung (Spec 2026-11-16 §2.2). Schichtzeiten sind Ortszeit. */
final class DispoZeitBezug
{
    public const PERSON_TYP = 'rec_employee';
    public const BEZUG_TYP = 'rec_dispo_assignment';

    /** null ohne zugeordnete Person (rec_employee_id) oder ohne gueltigen Einsatztag. */
    public static function fuer(RecDispoAssignment $a, ?int $teamId = null): ?ZeitBezug
    {
        if (empty($a->rec_employee_id)) {
            return null;
        }
        $datum = $a->datum;
        $tag = $datum instanceof \DateTimeInterface ? $datum->format('Y-m-d') : substr((string) $datum, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) {
            return null;
        }

        $zone = TeamClock::resolveTimezone(null);
        $tagStart = CarbonImmutable::parse($tag, $zone)->startOfDay();

        $von = self::uhr($a->von, false);
        $bis = self::uhr($a->bis, true);

        $sollVon = $von === null ? null : $tagStart->setTime($von[0], $von[1]);
        if ($bis === null) {
            // Ohne Ende: Tagesende des Einsatztags = 00:00 des Folgetags.
            $sollBis = $tagStart->addDay()->startOfDay();
        } else {
            $sollBis = $bis[0] === 24 ? $tagStart->addDay()->startOfDay() : $tagStart->setTime($bis[0], $bis[1]);
            if ($bis[0] !== 24 && $sollVon !== null && $sollBis->lessThanOrEqualTo($sollVon)) {
                // Schicht ueber Mitternacht: Ende am Folgetag (Wanduhr-Addition in der Zone).
                $sollBis = $tagStart->addDay()->setTime($bis[0], $bis[1]);
            }
        }

        return new ZeitBezug(self::PERSON_TYP, (int) $a->rec_employee_id, self::BEZUG_TYP, (int) $a->id, $tag, $sollVon, $sollBis, $teamId);
    }

    /** Aus den gespeicherten Feldern, fuer Einbuchungen, die es nicht mehr gibt. */
    public static function ausEintrag(ZeitEintrag $e): ZeitBezug
    {
        return new ZeitBezug(
            (string) $e->person_typ,
            (int) $e->person_id,
            (string) $e->bezug_typ,
            (int) $e->bezug_id,
            $e->tag instanceof \DateTimeInterface ? $e->tag->format('Y-m-d') : substr((string) $e->tag, 0, 10),
            $e->soll_von_at ? CarbonImmutable::instance($e->soll_von_at) : null,
            $e->soll_bis_at ? CarbonImmutable::instance($e->soll_bis_at) : null,
            $e->team_id === null ? null : (int) $e->team_id,
        );
    }

    /**
     * H:MM, HH:MM oder HH:MM:SS (getrimmt). 24:00 gilt nur als Ende (= 00:00 Folgetag, als [24, 0]).
     *
     * @return array{0:int,1:int}|null
     */
    private static function uhr(mixed $text, bool $alsEnde): ?array
    {
        if (!is_string($text) || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($text), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($alsEnde && $h === 24 && $min === 0) {
            return [24, 0];
        }

        return ($h > 23 || $min > 59) ? null : [$h, $min];
    }
}
