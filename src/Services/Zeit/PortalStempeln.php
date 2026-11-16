<?php

namespace Platform\Recruiting\Services\Zeit;

use Carbon\CarbonImmutable;
use Platform\Recruiting\Livewire\Public\EmployeeAssignments;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitAkteur;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitRechner;
use Platform\Recruiting\Zeit\ZeitRegeln;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/**
 * Stempeln im Mitarbeiter-Portal (Spec 2026-11-16 §3). Die Sitzungspruefung
 * liegt NICHT hier, sondern in PortalShell::berechtigterMitarbeiter() — dieser
 * Dienst bekommt nur einen schon berechtigten Mitarbeiter. Er prueft aber
 * selbst: Schalter, Sperre der Identitaetsgruppe und dass die Einbuchung (bzw.
 * der offene Zeitdatensatz) zu dieser Gruppe gehoert.
 *
 * Zwei Wege zu einer Zeile:
 *  1. regulaer: sichtbare, bestaetigte Einbuchung der Gruppe mit offenem Fenster;
 *  2. offener Stempel: Kommen gestempelt, Gehen fehlt — unabhaengig vom Zustand
 *     der Einbuchung (verschoben, verschwunden, abgesagt, geloescht).
 */
class PortalStempeln
{
    /** Ein offener Stempel bleibt so viele Tage nach dem Einsatztag in der Kachel (danach sieht ihn die Disposition). */
    public const OFFEN_SICHTBAR_TAGE = 7;

    public function __construct(
        private Stempeluhr $uhr,
        private ZeitSchalter $schalter,
        private DispoIdentityResolver $identitaet,
        private ZeitRegeln $regeln,
    ) {
    }

    /**
     * @return list<array{assignment_id:int,name:string,tag:string,soll:string,zustand:string,kommen:?string,gehen:?string,pause:?int,arbeitszeit:?string,kann_kommen:bool,kann_gehen:bool,fenster_vorbei:bool,heute:bool}>
     */
    public function zeilen(RecEmployee $angemeldet, \DateTimeInterface $jetzt): array
    {
        $zeilen = [];
        foreach ($this->kandidaten($angemeldet, $jetzt) as $k) {
            $zeilen[] = $k['zeile'];
        }

        return $zeilen;
    }

    /** Monate, die „Meine Zeiten“ zurueckreicht (am Ortstag gerechnet). */
    public const ZEITEN_MONATE = 3;

    /**
     * „Meine Zeiten“ (Spec 2026-11-16 §3): Zeitdatensaetze der Identitaetsgruppe
     * mit gesetztem Kommen, letzte drei Monate, neueste zuerst. Gelesen ueber den
     * Zeitdatensatz — auch Zeiten zu verschwundenen Einbuchungen bleiben (dann
     * ohne Veranstaltungsname). Leer bei Schalter aus oder gesperrter Gruppe.
     *
     * @return list<array{tag:string,name:?string,kommen:?string,gehen:?string,pause:?int,arbeitszeit:?string,zustand:string,vermerk:?string}>
     */
    public function meineZeiten(RecEmployee $angemeldet, \DateTimeInterface $jetzt): array
    {
        if (!$this->schalter->aktiv()) {
            return [];
        }
        $ids = $this->identitaet->groupFor((int) $angemeldet->id);
        if (RecEmployee::query()->whereIn('id', $ids)->whereNotNull('portal_locked_at')->exists()) {
            return [];
        }

        $grenze = ZeitAnzeige::ortszeit($this->alsCarbon($jetzt))->subMonthsNoOverflow(self::ZEITEN_MONATE)->format('Y-m-d');
        $eintraege = ZeitEintrag::query()
            ->where('person_typ', DispoZeitBezug::PERSON_TYP)
            ->whereIn('person_id', $ids)
            ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
            ->whereNotNull('kommen_at')
            ->whereDate('tag', '>=', $grenze)
            ->orderByDesc('tag')->orderByDesc('id')
            ->get();
        if ($eintraege->isEmpty()) {
            return [];
        }

        // Gebuendelt: Veranstaltungsnamen und Verlaufs-Arten je eine Abfrage.
        $namen = [];
        foreach (RecDispoAssignment::query()->with('event')->whereIn('id', $eintraege->pluck('bezug_id')->unique()->all())->get() as $a) {
            $n = trim((string) $a->event?->name);
            $namen[(int) $a->id] = $n !== '' ? $n : null;
        }
        $arten = [];
        foreach (ZeitVerlauf::query()
            ->whereIn('rec_zeit_eintrag_id', $eintraege->pluck('id')->all())
            ->whereIn('art', [Stempeluhr::ART_KORREKTUR, Stempeluhr::ART_STELLVERTRETEND])
            ->distinct()->get(['rec_zeit_eintrag_id', 'art']) as $v) {
            $arten[(int) $v->rec_zeit_eintrag_id][(string) $v->art] = true;
        }

        $liste = [];
        foreach ($eintraege as $e) {
            $anwesend = ZeitRechner::anwesendMinuten($e->kommen_at, $e->gehen_at);
            $pause = $anwesend === null ? null : ZeitRechner::pauseMinuten($e->pause_minuten === null ? null : (int) $e->pause_minuten, $anwesend, $this->regeln);
            $arbeit = $anwesend === null ? null : ZeitRechner::arbeitsMinuten($anwesend, (int) $pause);
            $a = $arten[(int) $e->id] ?? [];
            $liste[] = [
                'tag' => \Carbon\Carbon::parse($e->tag)->format('d.m.Y'),
                'name' => $namen[(int) $e->bezug_id] ?? null,
                'kommen' => ZeitAnzeige::uhrzeit($e->kommen_at),
                'gehen' => $e->gehen_at === null ? null : ZeitAnzeige::uhrzeit($e->gehen_at),
                'pause' => $pause,
                'arbeitszeit' => $arbeit === null ? null : ZeitRechner::alsStunden($arbeit),
                'zustand' => $e->zustand(),
                'vermerk' => isset($a[Stempeluhr::ART_KORREKTUR]) ? 'korrigiert'
                    : (isset($a[Stempeluhr::ART_STELLVERTRETEND]) ? 'von der Disposition erfasst' : null),
            ];
        }

        return $liste;
    }

    /** ok | schon | zu_frueh | zu_spaet | gesperrt | aus | nicht_erlaubt */
    public function kommen(RecEmployee $angemeldet, int $assignmentId, \DateTimeInterface $jetzt): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $k = $this->kandidaten($angemeldet, $jetzt)[$assignmentId] ?? null;
        if ($k === null || !$k['weg1']) {
            return 'nicht_erlaubt';
        }

        return $this->uhr->kommen($k['bezug'], $k['akteur'], Stempeluhr::ART_SELBST, $this->alsCarbon($jetzt));
    }

    /** ok | kein_kommen | schon | zu_spaet | gesperrt | ungueltig | aus | nicht_erlaubt */
    public function gehen(RecEmployee $angemeldet, int $assignmentId, \DateTimeInterface $jetzt): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $k = $this->kandidaten($angemeldet, $jetzt)[$assignmentId] ?? null;
        if ($k === null) {
            return 'nicht_erlaubt';
        }

        return $this->uhr->gehen($k['bezug'], $k['akteur'], Stempeluhr::ART_SELBST, $this->alsCarbon($jetzt));
    }

    /** Fruehester Kommen-Zeitpunkt dieser Einbuchung (fuer „ab 13:00 moeglich“); null wenn nicht der Gruppe oder ohne Grenze. */
    public function fruehestes(RecEmployee $angemeldet, int $assignmentId): ?CarbonImmutable
    {
        try {
            $ids = $this->identitaet->groupFor((int) $angemeldet->id);
            $a = RecDispoAssignment::query()->find($assignmentId);
            if ($a === null || !in_array((int) $a->rec_employee_id, $ids, true)) {
                return null;
            }
            $bezug = DispoZeitBezug::fuer($a, $this->teamId());

            return $bezug === null ? null : $this->regeln->fruehestesKommen($bezug);
        } catch (\Throwable) {
            return null;
        }
    }

    /** null bei ok/schon (kein Text noetig). */
    public static function meldung(string $status, ?\DateTimeInterface $fruehestens, bool $duzen): ?string
    {
        return match ($status) {
            'ok', 'schon' => null,
            'zu_frueh' => $fruehestens === null
                ? 'zu früh'
                : 'zu früh – ab ' . ZeitAnzeige::uhrzeit($fruehestens) . ' möglich',
            'zu_spaet' => $duzen
                ? 'Zeitfenster vorbei – bitte bei der Disposition melden'
                : 'Zeitfenster vorbei – melden Sie sich bitte bei der Disposition',
            'gesperrt' => 'gesperrt',
            default => 'Das geht gerade nicht.',
        };
    }

    /**
     * Alle Zeilen, die der Mitarbeiter bedienen duerfte, nach Einbuchungs-ID.
     * Leer bei Schalter aus oder gesperrter Identitaetsgruppe.
     *
     * @return array<int, array{zeile:array, bezug:ZeitBezug, akteur:ZeitAkteur, weg1:bool}>
     */
    private function kandidaten(RecEmployee $angemeldet, \DateTimeInterface $jetzt): array
    {
        if (!$this->schalter->aktiv()) {
            return [];
        }
        $ids = $this->identitaet->groupFor((int) $angemeldet->id);
        if (RecEmployee::query()->whereIn('id', $ids)->whereNotNull('portal_locked_at')->exists()) {
            return [];
        }

        $jetzt = $this->alsCarbon($jetzt);
        $team = $this->teamId();
        $name = trim($angemeldet->first_name . ' ' . $angemeldet->last_name);
        $aus = [];

        // Weg 1: regulaer.
        foreach (EmployeeAssignments::sichtbareEinsaetze($ids, $jetzt) as $a) {
            if ($a->confirmed_at === null) {
                continue;
            }
            $bezug = DispoZeitBezug::fuer($a, $team);
            if ($bezug === null) {
                continue;
            }
            $frueh = $this->regeln->fruehestesKommen($bezug);
            $spaet = $this->regeln->spaetestesStempeln($bezug);
            if ($frueh === null || $jetzt->lt($frueh) || ($spaet !== null && $jetzt->gt($spaet))) {
                continue;
            }
            $e = ZeitEintrag::finde($bezug->personTyp, $bezug->personId, $bezug->bezugTyp, $bezug->bezugId);
            $aus[(int) $a->id] = [
                'zeile' => $this->zeile((int) $a->id, $a->event?->name, $bezug, $e, $spaet, $jetzt, true),
                'bezug' => $bezug,
                'akteur' => new ZeitAkteur('rec_employee', (int) $a->rec_employee_id, $name),
                'weg1' => true,
            ];
        }

        // Weg 2: offener Stempel, unabhaengig vom Zustand der Einbuchung.
        $offene = ZeitEintrag::query()
            ->where('person_typ', DispoZeitBezug::PERSON_TYP)
            ->whereIn('person_id', $ids)
            ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
            ->whereNotNull('kommen_at')
            ->whereNull('gehen_at')
            ->whereNull('gesperrt_at')
            ->orderBy('tag')->orderBy('id')
            ->get();
        foreach ($offene as $e) {
            $id = (int) $e->bezug_id;
            if (isset($aus[$id])) {
                continue;
            }
            $a = RecDispoAssignment::query()->with('event')->find($id);
            $bezug = null;
            if ($a !== null && (int) $a->rec_employee_id === (int) $e->person_id) {
                $bezug = DispoZeitBezug::fuer($a, $team);
            }
            $bezug ??= DispoZeitBezug::ausEintrag($e);
            $heute = ZeitAnzeige::ortszeit($jetzt)->format('Y-m-d');
            if ($heute > \Carbon\Carbon::parse($bezug->tag)->addDays(self::OFFEN_SICHTBAR_TAGE)->format('Y-m-d')) {
                continue;
            }
            $spaet = $this->regeln->spaetestesStempeln($bezug);
            $aus[$id] = [
                'zeile' => $this->zeile($id, $a?->event?->name, $bezug, $e, $spaet, $jetzt, false),
                'bezug' => $bezug,
                'akteur' => new ZeitAkteur('rec_employee', (int) $e->person_id, $name),
                'weg1' => false,
            ];
        }

        return $aus;
    }

    private function zeile(int $id, ?string $veranstaltung, ZeitBezug $bezug, ?ZeitEintrag $e, ?CarbonImmutable $spaet, CarbonImmutable $jetzt, bool $imFenster): array
    {
        $zustand = $e?->zustand() ?? 'offen';
        $kommen = $e?->kommen_at;
        $gehen = $e?->gehen_at;
        $anwesend = ZeitRechner::anwesendMinuten($kommen, $gehen);
        $pause = $anwesend === null ? null : ZeitRechner::pauseMinuten($e->pause_minuten === null ? null : (int) $e->pause_minuten, $anwesend, $this->regeln);
        $arbeit = $anwesend === null ? null : ZeitRechner::arbeitsMinuten($anwesend, (int) $pause);
        $innerhalb = $spaet === null || $jetzt->lte($spaet);
        $von = ZeitAnzeige::uhrzeit($bezug->sollVon);
        $bis = ZeitAnzeige::uhrzeit($bezug->sollBis);

        return [
            'assignment_id' => $id,
            'name' => trim((string) $veranstaltung) !== '' ? (string) $veranstaltung : 'Einsatz',
            'tag' => \Carbon\Carbon::parse($bezug->tag)->format('d.m.Y'),
            'soll' => $von === '' ? '' : $von . '–' . $bis,
            'zustand' => $zustand,
            'kommen' => $kommen === null ? null : ZeitAnzeige::uhrzeit($kommen),
            'gehen' => $gehen === null ? null : ZeitAnzeige::uhrzeit($gehen),
            'pause' => $pause,
            'arbeitszeit' => $arbeit === null ? null : ZeitRechner::alsStunden($arbeit),
            'kann_kommen' => $imFenster && $zustand === 'offen',
            'kann_gehen' => $zustand === 'eingecheckt' && $innerhalb,
            'fenster_vorbei' => $zustand === 'eingecheckt' && !$innerhalb,
            'heute' => $bezug->tag === ZeitAnzeige::ortszeit($jetzt)->format('Y-m-d'),
        ];
    }

    private function teamId(): ?int
    {
        $t = $this->schalter->teamId();

        return $t > 0 ? $t : null;
    }

    private function alsCarbon(\DateTimeInterface $t): CarbonImmutable
    {
        return CarbonImmutable::instance($t);
    }
}
