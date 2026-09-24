<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\ProofReader;
use Platform\Recruiting\Support\ProofChecklist;

/**
 * Das neue Mitarbeiterportal — die Huelle.
 *
 * Vier Bereiche (Start / Einsaetze / Dokumente / Profil) hinter einer
 * Anmeldung. Die Optik ist der am 24.08.2026 abgenommene Entwurf
 * (resources/mockups/crew-portal.html), das Layout bringt dessen CSS mit.
 *
 * Diese Komponente ist bewusst duenn: sie klaert WER da ist (PortalAuth) und
 * WAS ansteht (ProofReader), alles Weitere haengt in den Bereichen. So kann
 * Canvas 68 die Anmeldung spaeter gegen Nummer+Passwort tauschen, ohne die
 * Bereiche anzufassen.
 *
 * Das alte EmployeePortal bleibt unberuehrt daneben stehen. Wer hier landet,
 * entscheidet rec_employees.portal_v2_since — gesetzt vom Kommando
 * recruiting:portal-umstellen. Ohne Stempel: 404, als gaebe es die Seite nicht.
 *
 * Sicherheit: dieselbe Lehre wie aus dem Auth-Bypass vom 09/2026 — alles, was
 * ueber Identitaet oder Zustand entscheidet, ist #[Locked]. $wire.set kommt
 * daran nicht vorbei.
 */
class PortalShell extends Component
{
    #[Locked] public string $token = '';
    #[Locked] public ?int $employeeId = null;
    #[Locked] public string $state = 'unverified';
    #[Locked] public string $displayName = '';
    #[Locked] public string $initialen = '';
    #[Locked] public bool $duzen = true;

    /** Eingaben der Anmeldung — bewusst NICHT gesperrt, sie kommen ja vom Menschen. */
    public string $birthDate = '';
    public string $idLast4 = '';
    public string $fehler = '';

    public function mount(string $token, PortalAuth $auth): void
    {
        $this->token = $token;

        $employee = $auth->employeeForToken($token);

        // Kein Mitarbeiter ODER noch nicht umgestellt: die Seite existiert
        // fuer diesen Menschen schlicht nicht. Bewusst 404 und nicht
        // „noch nicht freigeschaltet" — das waere eine Auskunft darueber,
        // dass es den Token gibt.
        if (!$employee || $employee->portal_v2_since === null) {
            abort(404);
        }

        $this->employeeId  = $employee->id;
        $this->displayName = $this->vorname($employee);
        $this->initialen   = $this->initialen($employee);
        $this->duzen       = $employee->usesInformalAddress();

        if ($auth->isLocked($employee)) {
            $this->state = 'gesperrt';

            return;
        }

        if (session()->has(PortalAuth::sessionKey($employee->id))) {
            $this->state = 'verified';

            return;
        }

        $this->state = $auth->isRateLimited($token) ? 'rateLimited' : 'unverified';
    }

    public function verify(PortalAuth $auth): void
    {
        if ($this->state !== 'unverified') {
            return;
        }

        $employee = $auth->employeeForToken($this->token);
        if (!$employee || $employee->id !== $this->employeeId) {
            abort(404);
        }

        $ergebnis = $auth->attempt($employee, $this->token, $this->birthDate, $this->idLast4);

        if ($ergebnis['status'] === PortalAuth::OK) {
            session()->put(PortalAuth::sessionKey($employee->id), true);
            $this->state = 'verified';
            $this->fehler = '';
            $this->reset('birthDate', 'idLast4');

            return;
        }

        if ($ergebnis['status'] === PortalAuth::GESPERRT) {
            $this->state = 'rateLimited';
            $this->fehler = '';

            return;
        }

        $this->fehler = $this->duzen
            ? "Das passt noch nicht zusammen. Du hast noch {$ergebnis['verbleibend']} Versuche."
            : "Das passt noch nicht zusammen. Sie haben noch {$ergebnis['verbleibend']} Versuche.";
        $this->idLast4 = '';
    }

    public function render()
    {
        $employee = $this->state === 'verified' && $this->employeeId !== null
            ? RecEmployee::find($this->employeeId)
            : null;

        $reader = app(ProofReader::class);
        $checklist = $employee ? $reader->checklist($employee) : [];

        return view('recruiting::livewire.public.portal-shell', [
            'aufgaben'    => self::dekoriert($checklist),
            'offen'       => count(array_filter($checklist, fn ($z) => $z['offen'])),
            'nachweise'   => $employee ? $reader->current($employee) : collect(),
            'anstellungen' => $employee ? $this->anstellungen($employee) : collect(),
            'employee'    => $employee,
        ])->layout('recruiting::layouts.portal', [
            'title' => 'Mein Portal · RheinGedeck',
        ]);
    }

    /**
     * Aus dem Status eine Farbe und einen Satz machen — hier und nicht in
     * Blade, damit die Ansicht nichts entscheidet und das Mapping pruefbar
     * bleibt. Rot heisst: er kann nicht arbeiten. Gelb: es laeuft auf etwas zu.
     *
     * @param  list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}> $zeilen
     * @return list<array{label:string, punkt:string, text:string, offen:bool}>
     */
    public static function dekoriert(array $zeilen): array
    {
        return array_map(static function (array $z): array {
            $datum = $z['valid_until'] !== null
                ? \Carbon\Carbon::parse($z['valid_until'])->format('d.m.Y')
                : null;

            [$punkt, $text] = match ($z['status']) {
                ProofChecklist::ABGELAUFEN => ['crit', 'Abgelaufen am ' . $datum],
                ProofChecklist::FEHLT      => ['crit', 'Fehlt noch'],
                ProofChecklist::LAEUFT_AB  => ['warn', 'Läuft ab am ' . $datum],
                default                    => ['ok', $datum !== null ? 'Gültig bis ' . $datum : 'Liegt vor'],
            };

            return ['label' => $z['label'], 'punkt' => $punkt, 'text' => $text, 'offen' => $z['offen']];
        }, $zeilen);
    }

    /**
     * Die Anstellungen DER PERSON, nicht nur die des Tokens — wer bei
     * RHEINGEDECK und MA arbeitet, soll beides sehen (Canvas 68).
     */
    private function anstellungen(RecEmployee $employee): Collection
    {
        $ids = app(PersonScopeResolver::class)->forEmployee($employee)['ids'];

        return RecEmployee::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'personnel_number', 'company', 'employment_type']);
    }

    private function vorname(RecEmployee $employee): string
    {
        return trim((string) ($employee->first_name ?? '')) ?: 'zusammen';
    }

    private function initialen(RecEmployee $employee): string
    {
        $teile = array_filter([$employee->first_name, $employee->last_name]);
        $kurz = implode('', array_map(fn ($t) => mb_strtoupper(mb_substr(trim((string) $t), 0, 1)), $teile));

        return $kurz !== '' ? $kurz : '·';
    }
}
