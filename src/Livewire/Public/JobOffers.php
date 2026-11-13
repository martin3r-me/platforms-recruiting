<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZugang;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAbsagen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersAbsage;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersLaden;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersLink;
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

    /**
     * Im Portal-Reiter „Einsätze“ als Bereich „Freie Einsätze“ eingebettet
     * (Spec 2026-11-13 §6.5): ohne eigenen Kopf und Gruss. Nur vom Server
     * beim Einbetten gesetzt.
     */
    #[Locked]
    public bool $eingebettet = false;
    /** Anrede der Person — nur eingebettet gelesen (das Portal spricht nach Team-Einstellung an). */
    #[Locked]
    public bool $duzen = true;

    public function mount(string $token, bool $eingebettet = false): void
    {
        ['token' => $token, 'anfrage' => $anfrage] = JobOffersLink::lesen($token);
        $this->token       = $token;
        $this->anfrageId   = $anfrage;
        $this->eingebettet = $eingebettet;

        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        if (!$employee) {
            $this->tokenInvalid = true;
            return;
        }

        $this->employeeId  = (int) $employee->id;
        $this->employeeIds = app(DispoIdentityResolver::class)->groupFor((int) $employee->id);
        $this->firstName   = (string) $employee->first_name;
        if ($eingebettet) {
            $this->duzen = $employee->usesInformalAddress();
        }

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
     * Offene Angebote, gruppiert VA -> Tag -> Schichten (JobOffersLaden).
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function angebote(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        // Eine Berechnung fuer Seite, Portal-Bereich und Portal-Karte (Spec 2026-11-13 §6.5).
        return JobOffersLaden::gruppen($this->employeeIds, (int) $this->employeeId, $this->firstName, $this->anfrageId);
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
        // Portal-Karte „N neue Einsätze“ zaehlt neu (Spec 2026-11-13 §6.5).
        $this->dispatch('angebote-geaendert');
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
        $this->dispatch('angebote-geaendert');
    }

    public function render()
    {
        return view('recruiting::livewire.public.job-offers')
            ->layout('platform::layouts.guest');
    }
}
