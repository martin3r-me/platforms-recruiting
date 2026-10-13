<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoTagesPruefung;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersBuendelung;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersRuecknahme;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersSichtbarkeit;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZusage;

/**
 * Oeffentliche Jobangebote-Seite (Spec 2026-10-12), token-only wie die
 * Einsatz-Seite: EIN Link je Person, er buendelt alle offenen Angebote der
 * Identitaets-Gruppe ueber alle laufenden Anfragen. Zeigt nur Tage, an denen
 * die Person frei ist (beim Laden neu geprueft). Kein portal_last_seen_at-Stempel.
 */
class JobOffers extends Component
{
    #[Locked]
    public string $token = '';
    #[Locked]
    public ?int $employeeId = null;
    /** @var list<int> */
    #[Locked]
    public array $employeeIds = [];
    #[Locked]
    public string $firstName = '';
    #[Locked]
    public bool $tokenInvalid = false;
    #[Locked]
    public bool $portalLocked = false;

    /** Rueckmeldung nach „Ich bin dabei“ / „Doch nicht?“ (nur serverseitig gesetzt). */
    #[Locked]
    public string $hinweis = '';
    #[Locked]
    public bool $hinweisOk = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        if (!$employee) {
            $this->tokenInvalid = true;
            return;
        }

        $this->employeeId  = (int) $employee->id;
        $this->employeeIds = app(DispoIdentityResolver::class)->groupFor((int) $employee->id);
        $this->firstName   = (string) $employee->first_name;

        if ($this->gesperrt()) {
            $this->portalLocked = true;
        }
    }

    private function gesperrt(): bool
    {
        return RecEmployee::query()->whereIn('id', $this->employeeIds)->whereNotNull('portal_locked_at')->exists();
    }

    /**
     * Offene Angebote, gruppiert VA -> Tag -> Schichten.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function angebote(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        $offerShifts = RecDispoJobOfferShift::query()
            ->with(['offer', 'shift.event', 'shift.request'])
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $this->employeeIds))
            ->whereHas('shift', fn ($q) => $q->whereDate('datum', '>=', now()->toDateString()))
            ->whereHas('shift.request', fn ($q) => $q->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at'))
            ->get();
        if ($offerShifts->isEmpty()) {
            return [];
        }

        $tage = $offerShifts->map(fn ($os) => $os->shift->datum->toDateString())->unique()->values()->all();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $this->employeeIds)
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
        $anchor = (int) $this->employeeId;
        $gebucht = DispoTagesPruefung::gebuchteTage($assignments, array_fill_keys($this->employeeIds, $anchor))[$anchor] ?? [];

        $zusagenJeSchicht = RecDispoJobOfferShift::query()
            ->whereIn('shift_id', $offerShifts->pluck('shift_id')->unique()->all())
            ->whereNotNull('accepted_at')
            ->selectRaw('shift_id, count(*) as n')->groupBy('shift_id')
            ->pluck('n', 'shift_id');

        $rows = $offerShifts->map(function ($os) use ($zusagenJeSchicht) {
            $shift = $os->shift;
            $zuerst = $shift->request->modus === RecDispoJobRequest::MODUS_ZUERST;

            return [
                'offer_shift_id' => (int) $os->id,
                'event'          => (int) $shift->rec_dispo_event_id,
                'datum'          => $shift->datum->toDateString(),
                'von'            => $shift->von,
                'zeit'           => DispoJobZeit::spanne($shift->von, $shift->bis),
                'taetigkeit'     => $shift->taetigkeit,
                'laeuft'         => $shift->request->laeuft(),
                'closed'         => $shift->closed_at !== null,
                'accepted'       => $os->accepted_at !== null,
                // „Doch nicht“: zugesagt und vom Disponenten noch nicht in ZAS eingetragen.
                'zurueck'        => $os->accepted_at !== null && $os->zas_entered_at === null && $os->zas_assignment_id === null,
                // Zugesagt und in ZAS eingetragen/angekommen — sperrt die Ruecknahme der ganzen Gruppe.
                'eingetragen'    => $os->accepted_at !== null && ($os->zas_entered_at !== null || $os->zas_assignment_id !== null),
                'zuerst'         => $zuerst,
                'frei'           => $zuerst ? max(0, (int) $shift->gesucht - (int) ($zusagenJeSchicht[$shift->id] ?? 0)) : null,
                'os'             => $os,
            ];
        })->all();

        // Spec §9: dieselbe Schicht aus mehreren Anfragen nur einmal (Knopf auf der Repraesentantin).
        $sichtbar = JobOffersBuendelung::buendeln(JobOffersSichtbarkeit::filtern($rows, $gebucht, now()->toDateString()));

        $groups = [];
        foreach ($sichtbar as $row) {
            $event = $row['os']->shift->event;

            $groups[$row['event']] ??= [
                'name' => $event?->name ?? $event?->einsatz_ref ?? 'Einsatz',
                'ort'  => $event?->venue_text ?: $event?->ort,
                'days' => [],
            ];
            $groups[$row['event']]['days'][$row['datum']][] = [
                'offer_shift_id' => $row['offer_shift_id'],
                'von'            => $row['von'],
                'zeit'           => $row['zeit'],
                'taetigkeit'     => $row['taetigkeit'],
                'accepted'       => $row['accepted'],
                'zurueck'        => $row['zurueck'],
                'zurueck_id'     => $row['zurueck_id'],
                'zuerst'         => $row['zuerst'],
                'frei'           => $row['zuerst'] ? $row['frei'] : null,
                'voll'           => $row['voll'],
            ];
        }

        foreach ($groups as &$g) {
            ksort($g['days']);
            foreach ($g['days'] as &$shifts) {
                usort($shifts, fn ($a, $b) => strcmp((string) $a['von'], (string) $b['von']));
            }
            unset($shifts);
        }
        unset($g);

        return array_values($groups);
    }

    public function zusagen(int $offerShiftId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }

        // Frisch pruefen — die Sperre kann nach mount() gesetzt worden sein.
        if ($this->gesperrt()) {
            $this->portalLocked = true;
            return;
        }

        $ergebnis = JobOffersZusage::ausfuehren($offerShiftId, $this->employeeIds);
        $this->hinweisOk = $ergebnis === 'ok';
        $this->hinweis = match ($ergebnis) {
            'ok'    => 'Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.',
            'voll'  => 'Diese Schicht ist schon voll — danke fürs Melden.',
            default => 'Dieses Angebot gibt es nicht mehr.',
        };

        unset($this->angebote);
    }

    public function zuruecknehmen(int $offerShiftId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }
        if ($this->gesperrt()) {
            $this->portalLocked = true;
            return;
        }

        $ergebnis = JobOffersRuecknahme::ausfuehren($offerShiftId, $this->employeeIds);
        $this->hinweisOk = $ergebnis === 'ok';
        $this->hinweis = match ($ergebnis) {
            'ok'          => 'Alles klar, deine Zusage ist zurückgenommen.',
            'eingetragen' => 'Du bist schon eingetragen — bitte melde dich bei deiner Dispo.',
            default       => 'Dieses Angebot gibt es nicht mehr.',
        };

        unset($this->angebote);
    }

    public function render()
    {
        return view('recruiting::livewire.public.job-offers')
            ->layout('platform::layouts.guest');
    }
}
