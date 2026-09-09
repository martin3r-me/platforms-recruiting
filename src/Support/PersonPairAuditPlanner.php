<?php

namespace Platform\Recruiting\Support;

/**
 * Bestands-Audit der Personen-Paare: gruppiert Mitarbeiter ueber den exakten
 * Namens-Schluessel + Geburtsdatum (PersonPairing) und teilt in zwei Etagen:
 *
 *  - SICHER (automatisch stempelbar mit --apply): genau ZWEI Datensaetze,
 *    noch nicht (vollstaendig) gepaart, hoechstens EINER mit Bewerber-Link —
 *    der andere erbt ihn (dieselbe Regel wie der Import-Auto-Link).
 *  - PRUEFEN (Mensch entscheidet): mehr als zwei Kandidaten, oder beide an
 *    VERSCHIEDENEN Bewerbungen — automatisch zu stempeln hiesse dort, still
 *    zwei Bewerbungen zu einer Person zu erklaeren.
 *
 * Sortierung: Einsaetze auf UNVERLINKTEN Datensaetzen zuerst — das sind die
 * Faelle, die den Schulung→Einsatz-Abgleich gerade verfaelschen.
 *
 * Pure Planung ueber Arrays; das Kommando ist nur die Huelle
 * (Muster EnableManualBookingPlanner).
 */
final class PersonPairAuditPlanner
{
    /**
     * @param  list<array{id:int, first_name:?string, last_name:?string, birth_date:?string,
     *                    personnel_number:?string, rec_applicant_id:?int, person_key:?string,
     *                    einsaetze:int}>  $employees
     * @return array{sicher: list<array>, pruefen: list<array>}
     */
    public static function plan(array $employees): array
    {
        $gruppen = [];
        foreach ($employees as $employee) {
            $nameKey = PersonPairing::nameKey($employee['first_name'] ?? null, $employee['last_name'] ?? null);
            $geburt = trim((string) ($employee['birth_date'] ?? ''));
            if ($nameKey === null || $geburt === '') {
                continue; // zu schwach fuer jede Aussage — nicht mal „pruefen"
            }
            $gruppen[$nameKey . '@' . $geburt][] = $employee;
        }

        $sicher = [];
        $pruefen = [];
        foreach ($gruppen as $gruppe) {
            if (count($gruppe) < 2) {
                continue;
            }

            usort($gruppe, fn ($a, $b) => $a['id'] <=> $b['id']);

            // Schon vollstaendig gepaart? Dann ist hier nichts zu tun.
            $keys = array_unique(array_map(fn ($e) => $e['person_key'], $gruppe));
            if (count($keys) === 1 && $keys[0] !== null) {
                continue;
            }

            $applicantIds = array_values(array_unique(array_filter(
                array_map(fn ($e) => $e['rec_applicant_id'], $gruppe),
            )));
            $einsaetzeUnverlinkt = array_sum(array_map(
                fn ($e) => $e['rec_applicant_id'] === null ? (int) $e['einsaetze'] : 0,
                $gruppe,
            ));

            $eintrag = [
                'ids' => array_map(fn ($e) => (int) $e['id'], $gruppe),
                'name' => trim(($gruppe[0]['first_name'] ?? '') . ' ' . ($gruppe[0]['last_name'] ?? '')),
                'birth_date' => $gruppe[0]['birth_date'],
                'nummern' => array_map(fn ($e) => (string) ($e['personnel_number'] ?? '—'), $gruppe),
                'applicant_id' => count($applicantIds) === 1 ? (int) $applicantIds[0] : null,
                'einsaetze_unverlinkt' => $einsaetzeUnverlinkt,
            ];

            if (count($gruppe) === 2 && count($applicantIds) <= 1) {
                $sicher[] = $eintrag;
            } else {
                $pruefen[] = $eintrag;
            }
        }

        $nachDringlichkeit = fn ($a, $b) => [$b['einsaetze_unverlinkt'], $a['ids'][0]]
            <=> [$a['einsaetze_unverlinkt'], $b['ids'][0]];
        usort($sicher, $nachDringlichkeit);
        usort($pruefen, $nachDringlichkeit);

        return ['sicher' => $sicher, 'pruefen' => $pruefen];
    }
}
