<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;

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
    public const LIMIT = 500;

    /**
     * @param  array{suche:string, firma:string, aktiv:string, taetigkeit:string, event_id:?int} $filter
     * @return list<array{id:int, ids:list<int>, name:string, nummern:string, hat_portal:bool}>
     */
    public static function finde(int $teamId, array $filter, int $limit = self::LIMIT): array
    {
        return self::findeMitGrenze($teamId, $filter, $limit)['treffer'];
    }

    /**
     * Wie finde(), meldet aber, ob es mehr Treffer gab als $limit — die Seite
     * darf eine Liste nie stillschweigend abschneiden und dann "an alle" senden.
     *
     * @param  array{suche:string, firma:string, aktiv:string, taetigkeit:string, event_id:?int} $filter
     * @return array{treffer: list<array{id:int, ids:list<int>, name:string, nummern:string, hat_portal:bool}>, abgeschnitten: bool}
     */
    public static function findeMitGrenze(int $teamId, array $filter, int $limit = self::LIMIT): array
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

        $roh = $q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit($limit + 1)
            ->get(self::SPALTEN)
            ->all();

        $abgeschnitten = count($roh) > $limit;
        $roh = array_slice($roh, 0, $limit);

        return ['treffer' => self::jePerson($teamId, $roh, $aktiv), 'abgeschnitten' => $abgeschnitten];
    }

    /**
     * Die von HR angehakten Personen, frisch aus der Datenbank: nur ids dieses
     * Teams, Unbekanntes faellt stumm weg. Bewusst OHNE die Listenfilter — die
     * Auswahl bleibt ueber Such- und Filterwechsel stehen (Opt-in-Waehler).
     * Grundlage fuer die Anzeige "Ausgewaehlt" UND fuer den Versand; nie die
     * ids aus dem Browser ungeprueft weiterreichen.
     *
     * @param  list<int> $ids
     * @return list<array{id:int, ids:list<int>, name:string, nummern:string, hat_portal:bool}>
     */
    public static function zeilenFuerIds(int $teamId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $zeilen = [];
        foreach (array_chunk($ids, 900) as $paket) {
            foreach (DB::table('rec_employees')->where('team_id', $teamId)->whereIn('id', $paket)->get(self::SPALTEN) as $r) {
                $zeilen[] = $r;
            }
        }
        usort($zeilen, fn ($a, $b) => [(string) $a->last_name, (string) $a->first_name, (int) $a->id] <=> [(string) $b->last_name, (string) $b->first_name, (int) $b->id]);

        return self::jePerson($teamId, $zeilen, 'all');
    }

    private const SPALTEN = ['id', 'first_name', 'last_name', 'personnel_number', 'company', 'person_key', 'rec_person_id', 'portal_v2_since', 'is_active'];

    /**
     * Anstellungen → EINE Zeile je Mensch (29.10.2026): HR waehlt Menschen,
     * nicht Anstellungen. Die Geschwister der Treffer werden nachgeladen,
     * damit die Zeile alle Personalnummern zeigt und die Zustellung von
     * derselben Anstellung getragen wird wie beim Bereitstellen
     * (EmpfaengerEntdoppler: kleinste gebundene id). Reihenfolge = erste
     * Nennung in $roh.
     *
     * @param  list<object> $roh
     * @param  string $aktiv 'active' | 'inactive' | 'all' — welche Nummern die Zeile zeigt
     * @return list<array{id:int, ids:list<int>, name:string, nummern:string, hat_portal:bool}>
     */
    private static function jePerson(int $teamId, array $roh, string $aktiv): array
    {
        if ($roh === []) {
            return [];
        }
        $alle = [];
        foreach ($roh as $r) {
            $alle[(int) $r->id] = $r;
        }
        $personen = array_values(array_unique(array_filter(array_map(fn ($r) => (int) $r->rec_person_id, $roh))));
        $schluessel = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r->person_key), $roh))));
        if ($personen !== [] || $schluessel !== []) {
            $q = DB::table('rec_employees')->where('team_id', $teamId)
                ->where(function ($w) use ($personen, $schluessel) {
                    foreach (array_chunk($personen, 900) as $p) {
                        $w->orWhereIn('rec_person_id', $p);
                    }
                    foreach (array_chunk($schluessel, 900) as $k) {
                        $w->orWhereIn('person_key', $k);
                    }
                });
            foreach ($q->get(self::SPALTEN) as $r) {
                $alle[(int) $r->id] ??= $r;
            }
        }

        $anstellungen = array_map(fn ($r) => [
            'id' => (int) $r->id, 'person_key' => $r->person_key, 'rec_person_id' => $r->rec_person_id === null ? null : (int) $r->rec_person_id,
        ], array_values($alle));
        $gruppe = EmpfaengerEntdoppler::gruppen($anstellungen);

        $mitglieder = [];
        foreach ($alle as $id => $r) {
            $mitglieder[$gruppe[$id]][] = $r;
        }

        $out = [];
        foreach ($roh as $r) {
            $g = $gruppe[(int) $r->id];
            if (isset($out[$g])) {
                continue;
            }
            $traegerZeile = self::traeger($mitglieder[$g]);
            // Angezeigt werden die Nummern, die zum Statusfilter passen; getragen wird immer gleich.
            $teil = array_values(array_filter($mitglieder[$g], fn ($m) => match ($aktiv) {
                'active'   => (bool) $m->is_active,
                'inactive' => !$m->is_active,
                default    => true,
            }));
            usort($teil, fn ($a, $b) => [strtoupper((string) $a->company) === 'RG' ? 0 : 1, (string) $a->personnel_number, (int) $a->id]
                <=> [strtoupper((string) $b->company) === 'RG' ? 0 : 1, (string) $b->personnel_number, (int) $b->id]);
            $nummern = array_values(array_filter(array_map(fn ($m) => trim((string) $m->personnel_number), $teil)));
            $out[$g] = [
                'id'         => (int) $traegerZeile->id,
                'ids'        => array_map(fn ($m) => (int) $m->id, $teil),
                'name'       => trim((string) $traegerZeile->first_name . ' ' . (string) $traegerZeile->last_name) ?: ('Mitarbeiter #' . $traegerZeile->id),
                'nummern'    => $nummern === [] ? '—' : implode(' · ', $nummern),
                'hat_portal' => array_filter($teil, fn ($m) => $m->portal_v2_since !== null) !== [],
            ];
        }

        return array_values($out);
    }

    /**
     * Traeger einer Personen-Gruppe, unabhaengig von Suche und Filter (sonst
     * zeigten Treffer- und Auswahlliste verschiedene ids und der Haken stuende
     * falsch): kleinste gebundene AKTIVE Anstellung, dann gebundene, dann
     * aktive, dann irgendeine. Gebunden zuerst, weil nur deren Portal-Umfang
     * die ganze Person sieht (EmpfaengerEntdoppler).
     *
     * @param list<object> $mitglieder
     */
    private static function traeger(array $mitglieder): object
    {
        usort($mitglieder, fn ($a, $b) => [$a->rec_person_id === null ? 1 : 0, $a->is_active ? 0 : 1, (int) $a->id]
            <=> [$b->rec_person_id === null ? 1 : 0, $b->is_active ? 0 : 1, (int) $b->id]);

        return $mitglieder[0];
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
