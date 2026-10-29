<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoTagesPruefung;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersBuendelung;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersSichtbarkeit;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZugang;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAbsagen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersAbsage;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersLink;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersTexte;
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
    /** Anfrage aus dem Link („{token}.{anfrage}“, 29.10.): gesetzt → Seite zeigt nur diese Anfrage. Alte Links: null = alles. */
    #[Locked]
    public ?int $anfrageId = null;

    /** Rueckmeldung nach „Ich bin dabei“ (nur serverseitig gesetzt). */
    #[Locked]
    public string $hinweis = '';
    #[Locked]
    public bool $hinweisOk = false;

    /** „Kann nicht“ (nur Dringend, Spec 2026-10-27 §5): aufgeklappte Angebots-Schicht, Grund, Notiz. Besitz prueft der Dienst. */
    public ?int $absageOffen = null;
    public string $absageGrund = '';
    public string $absageNotiz = '';

    public function mount(string $token): void
    {
        ['token' => $token, 'anfrage' => $anfrage] = JobOffersLink::lesen($token);
        $this->token     = $token;
        $this->anfrageId = $anfrage;

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

    /** Portal gesperrt ODER Link-Datensatz inaktiv — beides: keine Zusage. */
    private function gesperrt(): bool
    {
        return JobOffersZugang::gesperrt($this->employeeIds, (int) $this->employeeId);
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

        $alle = RecDispoJobOfferShift::query()
            ->with(['offer', 'shift.event', 'shift.request'])
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $this->employeeIds))
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
                $zugesagtSchluessel[DispoJobRecipientPlanner::schichtSchluessel($os->shift->rec_dispo_event_id, $os->shift->datum->toDateString(), $os->shift->von, $os->shift->taetigkeit)] = true;
            }
        }

        $offerShifts = $this->anfrageId === null
            ? $alle
            : $alle->filter(fn ($os) => (int) $os->shift->request_id === $this->anfrageId)->values();
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

        // „Bitte antworte bis“ (§2.5): naechste wartende automatische Welle je Anfrage.
        $naechsteWelle = RecDispoJobWave::query()
            ->whereIn('request_id', $offerShifts->map(fn ($os) => (int) $os->shift->request_id)->unique()->all())
            ->where('auto', true)->whereNull('started_at')->where('scheduled_for', '>', now())
            ->selectRaw('request_id, min(scheduled_for) as bis')->groupBy('request_id')
            ->pluck('bis', 'request_id');

        $rows = $offerShifts->map(function ($os) use ($zusagenJeSchicht, $zugesagtTage, $zugesagtSchluessel, $naechsteWelle) {
            $shift = $os->shift;
            $zuerst = $shift->request->modus === RecDispoJobRequest::MODUS_ZUERST;
            $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->toDateString(), $shift->von, $shift->taetigkeit);
            $bis = $naechsteWelle[$shift->request_id] ?? null;

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
            $g['texte'] = JobOffersTexte::fuer(JobOffersTexte::anlassVorrang($g['anlaesse']), $this->firstName, $g['antworten_bis']);
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

    public function zusagen(int $offerShiftId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }

        // Frisch pruefen — Sperre oder Deaktivierung koennen nach mount() kommen.
        if ($this->gesperrt()) {
            $this->portalLocked = true;
            return;
        }

        $ergebnis = JobOffersZusage::ausfuehren($offerShiftId, $this->employeeIds);
        $this->hinweisOk = $ergebnis === 'ok';
        $this->hinweis = match ($ergebnis) {
            'ok'         => 'Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.',
            'voll'       => 'Diese Schicht ist schon voll — danke fürs Melden.',
            'tag_belegt' => 'Du hast für diesen Tag schon zugesagt.',
            default      => 'Dieses Angebot gibt es nicht mehr.',
        };
        $this->absageOffen = null;

        unset($this->angebote);
    }

    /** Absagen der Person in den letzten 60 Tagen — fuer den Satz „Das waere deine N. Absage“. */
    #[Computed]
    public function absagen60(): int
    {
        if ($this->employeeIds === []) {
            return 0;
        }

        return DispoJobAbsagen::anzahl($this->employeeIds, DispoJobAbsagen::seit());
    }

    public function absageOeffnen(int $offerShiftId): void
    {
        $this->absageOffen = $offerShiftId;
        $this->absageGrund = '';
        $this->absageNotiz = '';
        $this->hinweis = '';
    }

    public function absageAbbrechen(): void
    {
        $this->absageOffen = null;
    }

    public function absagen(): void
    {
        if ($this->employeeId === null || $this->portalLocked || $this->absageOffen === null) {
            return;
        }
        if ($this->gesperrt()) {
            $this->portalLocked = true;
            return;
        }
        $ergebnis = JobOffersAbsage::ausfuehren((int) $this->absageOffen, $this->employeeIds, trim($this->absageGrund), (string) $this->absageNotiz);
        if ($ergebnis === 'kein_grund') {
            $this->hinweisOk = false;
            $this->hinweis = 'Bitte wähle einen Grund.';
            return;
        }
        $this->hinweisOk = $ergebnis === 'ok';
        $this->hinweis = match ($ergebnis) {
            'ok'             => 'Schade — danke für die Rückmeldung.',
            'schon_zugesagt' => 'Du hast für diese Schicht schon zugesagt. Wenn du doch nicht kannst, melde dich bitte im Chat.',
            default          => 'Dieses Angebot gibt es nicht mehr.',
        };
        $this->absageOffen = null;
        unset($this->angebote, $this->absagen60);
    }

    public function render()
    {
        return view('recruiting::livewire.public.job-offers')
            ->layout('platform::layouts.guest');
    }
}
