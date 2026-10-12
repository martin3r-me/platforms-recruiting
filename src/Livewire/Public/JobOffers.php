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

    /** Rueckmeldung nach „Ich bin dabei“ (nur serverseitig gesetzt). */
    #[Locked]
    public string $hinweis = '';

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

        $rows = $offerShifts->map(fn ($os) => [
            'offer_shift_id' => (int) $os->id,
            'datum'          => $os->shift->datum->toDateString(),
            'laeuft'         => $os->shift->request->laeuft(),
            'closed'         => $os->shift->closed_at !== null,
            'accepted'       => $os->accepted_at !== null,
            'os'             => $os,
        ])->all();

        $groups = [];
        foreach (JobOffersSichtbarkeit::filtern($rows, $gebucht, now()->toDateString()) as $row) {
            $os = $row['os'];
            $shift = $os->shift;
            $event = $shift->event;
            $zuerst = $shift->request->modus === RecDispoJobRequest::MODUS_ZUERST;

            $groups[$shift->rec_dispo_event_id] ??= [
                'name' => $event->name ?? $event->einsatz_ref ?? 'Einsatz',
                'ort'  => $event->venue_text ?: $event->ort,
                'days' => [],
            ];
            $groups[$shift->rec_dispo_event_id]['days'][$row['datum']][] = [
                'offer_shift_id' => $row['offer_shift_id'],
                'von'            => $shift->von,
                'taetigkeit'     => $shift->taetigkeit,
                'accepted'       => $row['accepted'],
                'zuerst'         => $zuerst,
                'frei'           => $zuerst ? max(0, (int) $shift->gesucht - (int) ($zusagenJeSchicht[$shift->id] ?? 0)) : null,
                'voll'           => $row['closed'] && !$row['accepted'],
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
        $this->hinweis = match ($ergebnis) {
            'ok'    => 'Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.',
            'voll'  => 'Diese Schicht ist schon voll — danke fürs Melden.',
            default => 'Dieses Angebot gibt es nicht mehr.',
        };

        unset($this->angebote);
    }

    public function render()
    {
        return view('recruiting::livewire.public.job-offers')
            ->layout('platform::layouts.guest');
    }
}
