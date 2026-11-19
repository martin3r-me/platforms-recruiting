<?php

namespace Platform\Recruiting\Services\Statistics\Mitarbeiter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Recruiting\Models\RecKostenstellenSoll;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\KostenstellenBloecke;

/**
 * Lädt, was der MitarbeiterStatistikRechner braucht — drei Abfragen, reine Lesezugriffe über den
 * Query Builder (kein Beobachter, kein Export-Marker).
 *
 *  1. Anstellungen des Teams (ohne Test-MA), mit Kostenstelle der Stelle und Telefon der Person.
 *  2. Zählende Einbuchungen dieser Anstellungen ab dem frühesten benötigten Tag.
 *  3. Nummern mit eingehender WhatsApp-Nachricht im Zeitraum — kanalübergreifend (HR und Dispo).
 *     Zugeordnet wird über die Telefonnummer der Anstellung/Person; fremde Nummern fallen dabei
 *     von selbst heraus.
 */
class MitarbeiterStatistikDaten
{
    /**
     * @return array{personen: list<array<string,mixed>>, zeilen: list<array<string,mixed>>, gesamt: array<string,mixed>, soll: array<int,int>, stichtag: string}
     */
    public function auswerten(int $teamId, string $heute, string $von, string $bis, string $gesellschaft = MitarbeiterStatistikRechner::GESELLSCHAFT_RG): array
    {
        $anstellungen = $this->anstellungen($teamId);
        $stichtag = min($bis, $heute);
        $ab = min($von, MitarbeiterStatistikRechner::minusTage($stichtag, MitarbeiterStatistikRechner::PRODUKTIV_TAGE));
        $einsaetze = $this->einsaetze(array_column($anstellungen, 'id'), $ab);
        $nummern = $this->eingehendeNummern($von, $bis);

        $letzter = $this->letzterEinsatz(array_column($anstellungen, 'id'), $heute);
        $personen = MitarbeiterStatistikRechner::personen($anstellungen, $einsaetze, $nummern, $heute, $von, $bis, $letzter, $gesellschaft);
        $soll = RecKostenstellenSoll::geltend($teamId, $stichtag);
        $zeilen = MitarbeiterStatistikRechner::jeKostenstelle($personen, $soll);

        return [
            'personen' => $personen,
            'zeilen' => $zeilen,
            'gesamt' => MitarbeiterStatistikRechner::gesamt($zeilen),
            'soll' => $soll,
            'stichtag' => $stichtag,
        ];
    }

    /** @return list<array{id:int, person:?int, aktiv:bool, kostenstelle:?int, name:string, pnr:?string, phones:list<string>}> */
    public function anstellungen(int $teamId): array
    {
        $q = DB::table('rec_employees as e')
            ->leftJoin('rec_positions as p', 'p.id', '=', 'e.rec_position_id')
            ->where('e.team_id', $teamId)
            ->where(fn ($w) => $w->whereNull('e.is_test')->orWhere('e.is_test', false));

        $spalten = ['e.id', 'e.rec_person_id', 'e.is_active', 'e.cost_center', 'p.cost_center as stelle_cost_center',
            'e.first_name', 'e.last_name', 'e.personnel_number', 'e.phone', 'e.company'];
        // Status je Anstellung aus ZAS (GO/MA/INAKTIV): eine RG-Anstellung auf „MA“ ist zur MA gewechselt
        // und zählt nicht mehr zur RG (Markus 19.11.2026: „Steckel Merle steht auf MA in der RG“).
        if (Schema::hasTable('rec_employee_hr_data')) {
            $q->leftJoin('rec_employee_hr_data as h', 'h.rec_employee_id', '=', 'e.id');
            $spalten[] = 'h.export_status';
        }
        if (Schema::hasTable('rec_persons')) {
            $q->leftJoin('rec_persons as rp', 'rp.id', '=', 'e.rec_person_id');
            $spalten[] = 'rp.phone as person_phone';
        }

        $out = [];
        foreach ($q->orderBy('e.id')->get($spalten) as $r) {
            $phones = array_values(array_filter([(string) ($r->phone ?? ''), (string) ($r->person_phone ?? '')], fn ($n) => $n !== ''));
            $out[] = [
                'id' => (int) $r->id,
                'person' => $r->rec_person_id !== null ? (int) $r->rec_person_id : null,
                'aktiv' => (bool) $r->is_active,
                'kostenstelle' => KostenstellenBloecke::block(
                    $r->cost_center !== null ? (string) $r->cost_center : null,
                    $r->stelle_cost_center !== null ? (string) $r->stelle_cost_center : null,
                ),
                'name' => trim(((string) ($r->last_name ?? '')) . ', ' . ((string) ($r->first_name ?? '')), ', ') ?: ('#' . $r->id),
                'pnr' => $r->personnel_number !== null ? (string) $r->personnel_number : null,
                'phones' => $phones,
                'firma' => in_array(mb_strtoupper(trim((string) ($r->company ?? ''))), ['RG', 'MA'], true) ? mb_strtoupper(trim((string) $r->company)) : null,
                'status' => \Platform\Recruiting\Services\Zas\ZasStatus::normalize($r->export_status ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $employeeIds
     * @return list<array{employee_id:int, datum:string}>
     */
    public function einsaetze(array $employeeIds, string $abYmd): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($employeeIds, 1000) as $chunk) {
            $q = DB::table('rec_dispo_assignments')
                ->whereIn('rec_employee_id', $chunk)
                ->where('datum', '>=', $abYmd);
            GeleisteterEinsatz::einschraenken($q);
            foreach ($q->get(['rec_employee_id', 'datum']) as $r) {
                $out[] = ['employee_id' => (int) $r->rec_employee_id, 'datum' => substr((string) $r->datum, 0, 10)];
            }
        }

        return $out;
    }

    /**
     * Jüngster zählender Einsatz bis heute je Anstellung, ohne Untergrenze.
     *
     * @param list<int> $employeeIds
     * @return array<int,string>
     */
    public function letzterEinsatz(array $employeeIds, string $heute): array
    {
        $out = [];
        foreach (array_chunk($employeeIds, 1000) as $chunk) {
            $q = DB::table('rec_dispo_assignments')
                ->whereIn('rec_employee_id', $chunk)
                ->where('datum', '<=', $heute);
            GeleisteterEinsatz::einschraenken($q);
            foreach ($q->groupBy('rec_employee_id')->selectRaw('rec_employee_id, MAX(datum) as letzter')->get() as $r) {
                $out[(int) $r->rec_employee_id] = substr((string) $r->letzter, 0, 10);
            }
        }

        return $out;
    }

    /** @return array<string,bool> normalisierte Nummer => true */
    public function eingehendeNummern(string $von, string $bis): array
    {
        if (!Schema::hasTable('comms_whatsapp_messages') || !Schema::hasTable('comms_whatsapp_threads')) {
            return [];
        }

        $out = [];
        $nummern = DB::table('comms_whatsapp_messages as m')
            ->join('comms_whatsapp_threads as t', 't.id', '=', 'm.comms_whatsapp_thread_id')
            ->where('m.direction', 'inbound')
            ->where('m.created_at', '>=', $von . ' 00:00:00')
            ->where('m.created_at', '<=', $bis . ' 23:59:59')
            ->distinct()
            ->pluck('t.remote_phone_number');
        foreach ($nummern as $nummer) {
            $n = DispoPhoneMatcher::normalize((string) $nummer);
            if ($n !== null) {
                $out[$n] = true;
            }
        }

        return $out;
    }
}
