<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

/**
 * Kandidaten fuer den Empfaengerwaehler der Seite Dokumente (Spec §3.4).
 * Dieselben Filter wie die Mitarbeiterliste plus Taetigkeit (ZAS-Katalog,
 * Vergleich in Kleinschreibung) und Veranstaltung (EingebuchteFuerDokument).
 *
 * Taetigkeit: rec_employee_hr_data.dispo_taetigkeiten ist eine JSON-Liste von
 * Katalognamen; json_encode schreibt Umlaute als ü, deshalb KEIN LIKE auf
 * den Rohtext, sondern whereJsonContains mit dem Katalog-Schreibweise. Der
 * Filterwert kommt in beliebiger Schreibung an und wird ueber den Katalog
 * auf die kanonische Form gebracht.
 */
final class DokumentEmpfaengerSuche
{
    /**
     * @param  array{suche:string, firma:string, aktiv:string, taetigkeit:string, event_id:?int} $filter
     * @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}>
     */
    public static function finde(int $teamId, array $filter, int $limit = 500): array
    {
        $q = DB::table('rec_employees')->where('team_id', $teamId);

        $aktiv = (string) ($filter['aktiv'] ?? 'active');
        if ($aktiv === 'active') {
            $q->where('is_active', true);
        } elseif ($aktiv === 'inactive') {
            $q->where('is_active', false);
        }

        $firma = trim((string) ($filter['firma'] ?? ''));
        if ($firma !== '') {
            $q->where('company', $firma);
        }

        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $needle = '%' . $suche . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('first_name', 'like', $needle)
                  ->orWhere('last_name', 'like', $needle)
                  ->orWhere('personnel_number', 'like', $needle);
            });
        }

        $taetigkeit = trim((string) ($filter['taetigkeit'] ?? ''));
        if ($taetigkeit !== '') {
            $kanonisch = self::kanonisch($teamId, $taetigkeit);
            $ids = DB::table('rec_employee_hr_data')
                ->whereJsonContains('dispo_taetigkeiten', $kanonisch)
                ->pluck('rec_employee_id');
            $q->whereIn('id', $ids);
        }

        $eventId = $filter['event_id'] ?? null;
        if ($eventId !== null && (int) $eventId > 0) {
            $q->whereIn('id', EingebuchteFuerDokument::ids((int) $eventId));
        }

        return $q->orderBy('last_name')->orderBy('first_name')->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'personnel_number', 'company', 'person_key', 'portal_v2_since'])
            ->map(fn ($r) => [
                'id'               => (int) $r->id,
                'name'             => trim((string) $r->first_name . ' ' . (string) $r->last_name) ?: ('Mitarbeiter #' . $r->id),
                'personnel_number' => $r->personnel_number,
                'company'          => $r->company,
                'person_key'       => $r->person_key,
                'hat_portal'       => $r->portal_v2_since !== null,
            ])
            ->all();
    }

    /** @return list<string> der ganze ZAS-Katalog, natuerlich sortiert */
    public static function taetigkeiten(int $teamId): array
    {
        $lookupId = DB::table('core_lookups')->where('team_id', $teamId)->where('name', ZasDispoTaetigkeitSync::LOOKUP)->value('id');
        if ($lookupId === null) {
            return [];
        }
        $werte = DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value')->map(fn ($v) => (string) $v)->all();
        usort($werte, 'strnatcasecmp');

        return array_values($werte);
    }

    private static function kanonisch(int $teamId, string $eingabe): string
    {
        foreach (self::taetigkeiten($teamId) as $wert) {
            if (mb_strtolower($wert) === mb_strtolower($eingabe)) {
                return $wert;
            }
        }

        return $eingabe;
    }
}
