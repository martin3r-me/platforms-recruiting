<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;

/**
 * Offene Jobangebote einer Person — EINE Berechnung fuer die Angebotsseite
 * /jobs/{token}, den Bereich „Freie Einsätze“ im Portal und die Karte
 * „N neue Einsätze“ (Spec 2026-11-13 §6.5, Markus 13.11.2026): Karte und
 * Liste duerfen nie auseinanderlaufen. Inhalt unveraendert aus
 * JobOffers::angebote() herausgezogen. Kanal (WhatsApp/Portal) spielt keine
 * Rolle: es zaehlt jedes Angebot einer laufenden Anfrage.
 */
final class JobOffersLaden
{
    /**
     * Offene Angebote, gruppiert VA -> Tag -> Schichten.
     *
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     * @param int $ankerId Datensatz hinter dem Link (alle Gruppen-Datensaetze werden auf ihn gelegt)
     * @param ?int $anfrageId gesetzt → nur diese Anfrage (Link „{token}.{anfrage}“); null = alles
     * @return list<array<string, mixed>>
     */
    public static function gruppen(array $employeeIds, int $ankerId, string $firstName, ?int $anfrageId = null): array
    {
        $alle = RecDispoJobOfferShift::query()
            ->with(['offer', 'shift.event', 'shift.request'])
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->whereDate('datum', '>=', now()->toDateString()))
            ->whereHas('shift.request', fn ($q) => $q->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at'))
            ->get();

        // Einmal pro Tag (§6) gilt ueber ALLE Anfragen — auch wenn die Seite nur
        // eine zeigt (29.10.): Zusage auf der Wochen-Seite sperrt den Tag auf der
        // Dringend-Seite. Schwestern (gleicher Schluessel) ausgenommen.
        $zugesagtTage = [];
        $zugesagtSchluessel = [];
        foreach ($alle as $os) {
            if ($os->accepted_at !== null && $os->declined_at === null) {
                $zugesagtTage[$os->shift->datum->toDateString()] = true;
                $zugesagtSchluessel[DispoJobRecipientPlanner::schichtSchluessel($os->shift->rec_dispo_event_id, $os->shift->datum->toDateString(), $os->shift->von, $os->shift->bis, $os->shift->taetigkeit)] = true;
            }
        }

        $offerShifts = $anfrageId === null
            ? $alle
            : $alle->filter(fn ($os) => (int) $os->shift->request_id === $anfrageId)->values();
        if ($offerShifts->isEmpty()) {
            return [];
        }

        $tage = $offerShifts->map(fn ($os) => $os->shift->datum->toDateString())->unique()->values()->all();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $employeeIds)
            ->whereIn('datum', $tage)
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => [
                'employee_id'        => (int) $a->rec_employee_id,
                'datum'              => $a->datum->format('Y-m-d'),
                'status_id'          => (int) $a->status_id,
                'missing_since'      => $a->missing_since,
                'deletion_marked_at' => $a->deletion_marked_at,
                'declined_at'        => $a->declined_at,
            ])->all();
        // Eine Person: alle Datensaetze der Gruppe auf einen Anker legen.
        $anchor = $ankerId;
        $gebucht = DispoTagesPruefung::gebuchteTage($assignments, array_fill_keys($employeeIds, $anchor))[$anchor] ?? [];

        $zusagenJeSchicht = RecDispoJobOfferShift::query()
            ->whereIn('shift_id', $offerShifts->pluck('shift_id')->unique()->all())
            ->whereNotNull('accepted_at')
            ->selectRaw('shift_id, count(*) as n')->groupBy('shift_id')
            ->pluck('n', 'shift_id');

        // „Bitte antworte bis“ (§2.5): naechste wartende automatische Welle je Anfrage.
        $naechsteWelle = RecDispoJobWave::query()
            ->whereIn('request_id', $offerShifts->map(fn ($os) => (int) $os->shift->request_id)->unique()->all())
            ->where('auto', true)->whereNull('started_at')->where('scheduled_for', '>', now())
            ->selectRaw('request_id, min(scheduled_for) as bis')->groupBy('request_id')
            ->pluck('bis', 'request_id');

        $rows = $offerShifts->map(function ($os) use ($zusagenJeSchicht, $zugesagtTage, $zugesagtSchluessel, $naechsteWelle) {
            $shift = $os->shift;
            $zuerst = $shift->request->modus === RecDispoJobRequest::MODUS_ZUERST;
            $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->toDateString(), $shift->von, $shift->bis, $shift->taetigkeit);
            $bis = $naechsteWelle[$shift->request_id] ?? null;

            return [
                'offer_shift_id' => (int) $os->id,
                'event'          => (int) $shift->rec_dispo_event_id,
                'datum'          => $shift->datum->toDateString(),
                'von'            => $shift->von,
                'bis'            => $shift->bis,
                'zeit'           => DispoJobZeit::spanne($shift->von, $shift->bis),
                'taetigkeit'     => $shift->taetigkeit,
                'laeuft'         => $shift->request->laeuft(),
                'closed'         => $shift->closed_at !== null,
                'accepted'       => $os->accepted_at !== null,
                'zuerst'         => $zuerst,
                'frei'           => $zuerst ? max(0, (int) $shift->gesucht - (int) ($zusagenJeSchicht[$shift->id] ?? 0)) : null,
                'anlass'         => $shift->request->anlass,
                'dringend'       => $shift->request->anlass === 'dringend',
                'declined'       => $os->declined_at !== null,
                'tag_belegt'     => $os->accepted_at === null && isset($zugesagtTage[$shift->datum->toDateString()]) && !isset($zugesagtSchluessel[$schluessel]),
                'antworten_bis'  => $bis !== null ? JobOffersTexte::antwortenBis(\Carbon\Carbon::parse($bis), now()) : null,
                'os'             => $os,
            ];
        })->all();

        // Spec §9: dieselbe Schicht aus mehreren Anfragen nur einmal (Knopf auf der Repraesentantin).
        $sichtbar = JobOffersBuendelung::buendeln(JobOffersSichtbarkeit::filtern($rows, $gebucht, now()->toDateString()));

        $groups = [];
        foreach ($sichtbar as $row) {
            $event = $row['os']->shift->event;

            $groups[$row['event']] ??= [
                'name'          => $event?->name ?? $event?->einsatz_ref ?? 'Einsatz',
                'ort'           => $event?->venue_text ?: $event?->ort,
                'anlaesse'      => [],
                'antworten_bis' => null,
                'days'          => [],
            ];
            $groups[$row['event']]['anlaesse'][] = $row['anlass'] ?? null;
            $groups[$row['event']]['antworten_bis'] ??= $row['antworten_bis'] ?? null;
            $groups[$row['event']]['days'][$row['datum']][] = [
                'offer_shift_id' => $row['offer_shift_id'],
                'von'            => $row['von'],
                'zeit'           => $row['zeit'],
                'taetigkeit'     => $row['taetigkeit'],
                'accepted'       => $row['accepted'],
                'zuerst'         => $row['zuerst'],
                'frei'           => $row['zuerst'] ? $row['frei'] : null,
                'voll'           => $row['voll'],
                'dringend'       => (bool) ($row['dringend'] ?? false),
                'declined'       => (bool) ($row['declined'] ?? false),
                'tag_belegt'     => (bool) ($row['tag_belegt'] ?? false),
            ];
        }

        foreach ($groups as &$g) {
            // Wortlaut 1:1 nach Markus' Folien 1–3; Frist = naechste automatische Welle (§2.5).
            $g['texte'] = JobOffersTexte::fuer(JobOffersTexte::anlassVorrang($g['anlaesse']), $firstName, $g['antworten_bis']);
            unset($g['anlaesse']);
            ksort($g['days']);
            foreach ($g['days'] as &$shifts) {
                usort($shifts, fn ($a, $b) => strcmp((string) $a['von'], (string) $b['von']));
            }
            unset($shifts);
        }
        unset($g);

        return array_values($groups);
    }

    /**
     * Zusagbare Schichten = Zeilen mit „Ich bin dabei“-Knopf: nicht
     * zugesagt, nicht abgesagt, nicht voll, Tag nicht schon belegt.
     *
     * @param list<array<string, mixed>> $gruppen Rueckgabe von gruppen()
     */
    public static function zusagbar(array $gruppen): int
    {
        $n = 0;
        foreach ($gruppen as $gruppe) {
            foreach ($gruppe['days'] ?? [] as $schichten) {
                foreach ($schichten as $s) {
                    if (empty($s['accepted']) && empty($s['declined']) && empty($s['voll']) && empty($s['tag_belegt'])) {
                        $n++;
                    }
                }
            }
        }

        return $n;
    }

    /**
     * Zahl fuer die Karte „N neue Einsätze für dich“ — ueber alle Anfragen,
     * 0 bei gesperrtem Portal oder inaktivem Datensatz (wie die Seite).
     *
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     */
    public static function anzahl(array $employeeIds, int $ankerId): int
    {
        if (JobOffersZugang::gesperrt($employeeIds, $ankerId)) {
            return 0;
        }

        return self::zusagbar(self::gruppen($employeeIds, $ankerId, ''));
    }
}
