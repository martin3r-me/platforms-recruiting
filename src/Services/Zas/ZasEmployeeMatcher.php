<?php

namespace Platform\Recruiting\Services\Zas;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Findet den Mitarbeiter zu einer ZAS-Zeile: UUID → exakte Personalnummer →
 * Kurzform → praefixlose Form, team-gescoped.
 *
 * Lag bis 2026-09-08 privat im ZasInboundEmployeeImporter. Der Datei-Eingang
 * (POST eines Selfies) braucht genau dieselbe Zuordnung — und zwei Kopien
 * dieser Kaskade waeren die gefaehrlichste Art von Drift, die dieses Modul
 * kennt: sie haengt Dokumente an die falsche Person. Deshalb ein gemeinsamer
 * Dienst statt einer zweiten Implementierung.
 *
 * Warum es mehrere Formen gibt: ZAS kuerzt Nummern oberhalb einer Milliarde im
 * Dispo-Export (`MA1000000878` → `MA878`), im Mitarbeiter-Export nicht mehr —
 * dieselbe Person kann uns also in beiden Formen begegnen. Und bis zur
 * Praefix-Umstellung kamen Nummern blank.
 */
class ZasEmployeeMatcher
{
    /**
     * @param  string $ownPrefix eigener Firmen-Praefix (fuer die praefixlose Form)
     * @return array{employee: ?RecEmployee, via: ?string} via: uuid|exact|shortened|bare
     */
    public function match(?string $uuid, ?string $personnelNumber, $teamId, string $ownPrefix = ''): array
    {
        if ($uuid) {
            $byUuid = RecEmployee::where('uuid', $uuid)->first();
            if ($byUuid) {
                return ['employee' => $byUuid, 'via' => 'uuid'];
            }
        }

        if (!$personnelNumber) {
            return ['employee' => null, 'via' => null];
        }

        $candidates = ['exact' => $personnelNumber];

        $shortened = ZasPersonnelNumber::shortenedForm($personnelNumber);
        if ($shortened !== null) {
            $candidates['shortened'] = $shortened;
        }

        if ($ownPrefix !== '' && str_starts_with($personnelNumber, $ownPrefix)) {
            $bare = substr($personnelNumber, strlen($ownPrefix));
            if ($bare !== '') {
                $candidates['bare'] = $bare;
            }
        }

        $employee = RecEmployee::whereIn('personnel_number', array_values($candidates))
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            // Liegen mehrere Formen vor, gewinnt die exakte.
            ->orderByRaw('personnel_number = ? DESC', [$personnelNumber])
            ->first();

        if ($employee === null) {
            return ['employee' => null, 'via' => null];
        }

        $via = array_search((string) $employee->personnel_number, $candidates, true);

        return ['employee' => $employee, 'via' => $via === false ? 'exact' : $via];
    }
}
