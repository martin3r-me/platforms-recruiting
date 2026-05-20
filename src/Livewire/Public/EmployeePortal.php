<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Mitarbeiter-Portal — Login-geschuetzter Bereich nach Bewerber→MA-
 * Konvertierung (Phase-4-Abschluss). Anders als ApplicantPortal:
 *
 *  - Token-Lookup direkt auf rec_employees.portal_token
 *  - Zwei-Faktor-aehnliche Verifizierung: Geburtsdatum + letzte 4
 *    Ziffern der Ausweisnummer (beides aus P3-Pflichtfeldern erfasst)
 *  - Rate-Limit gegen Brute-Force: 5 falsche Versuche → 15min Sperre
 *  - Nach Verifikation: Vertraege unterschreiben + Daten nachpflegen
 *
 * State-Diagramm: loading → tokenInvalid | unverified → verified | rateLimited
 */
class EmployeePortal extends Component
{
    public string $state = 'loading';
    public ?int $employeeId = null;
    public string $token = '';
    public string $displayName = '';

    // Login-Form
    public string $birthDateInput = '';
    public string $idCardLast4Input = '';
    public ?string $loginError = null;

    // Daten-nachpflege Edit-State
    public string $editField = '';
    public string $editValue = '';
    public ?string $editFlash = null;

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function mount(string $token): void
    {
        $this->token = $token;

        $employee = RecEmployee::where('portal_token', $token)->first();

        if (!$employee || !$employee->is_active) {
            $this->state = 'tokenInvalid';
            return;
        }

        $this->employeeId = $employee->id;
        $this->displayName = trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) ?: 'Mitarbeiter';

        // Bereits verifiziert in dieser Session? (Session-Flag basiert
        // auf employeeId — kein dauerhaftes Login, nur fuer aktuellen
        // Browser-Tab solange Session lebt.)
        if (session()->has($this->sessionKey())) {
            $this->state = 'verified';
            return;
        }

        // Rate-Limited?
        if ($this->isRateLimited()) {
            $this->state = 'rateLimited';
            return;
        }

        $this->state = 'unverified';
    }

    public function verify(): void
    {
        if ($this->isRateLimited()) {
            $this->state = 'rateLimited';
            return;
        }

        $employee = $this->employee();
        if (!$employee) {
            $this->state = 'tokenInvalid';
            return;
        }

        $birth = trim($this->birthDateInput);
        $last4 = trim($this->idCardLast4Input);
        if ($birth === '' || $last4 === '') {
            $this->loginError = 'Bitte beide Felder ausfuellen.';
            return;
        }

        if (!$employee->verifyPortalAccess($birth, $last4)) {
            $this->recordFailedAttempt();
            $remaining = max(0, self::MAX_ATTEMPTS - $this->failedAttempts());
            if ($remaining === 0) {
                $this->state = 'rateLimited';
                return;
            }
            $this->loginError = "Daten passen nicht. Noch {$remaining} Versuch(e) bis zur Sperre.";
            return;
        }

        // Verifikation erfolgreich — Session-Flag setzen + Audit
        session()->put($this->sessionKey(), true);
        $employee->update(['portal_verified_at' => now()]);
        $this->clearFailedAttempts();
        $this->loginError = null;
        $this->state = 'verified';
    }

    public function logout(): void
    {
        session()->forget($this->sessionKey());
        $this->state = 'unverified';
        $this->birthDateInput = '';
        $this->idCardLast4Input = '';
        $this->loginError = null;
    }

    /**
     * Beginnt das Editieren eines fehlenden Stammdaten-Feldes. Whitelist
     * ist missingFields() — andere Spalten koennen nicht ueber's Portal
     * editiert werden (Schutz gegen manipulated POSTs).
     */
    public function startEdit(string $field): void
    {
        if ($this->state !== 'verified') {
            return;
        }
        $employee = $this->employee();
        if (!$employee) {
            return;
        }
        $allowed = $employee->editableFieldsFlat();
        if (!array_key_exists($field, $allowed)) {
            return;
        }
        $this->editField = $field;
        // Vor-Belegung mit aktuellem Wert damit MA seine Daten nicht
        // neu tippen muss (wenn er nur korrigieren will).
        $this->editValue = (string) ($employee->getAttribute($field) ?? '');
        $this->editFlash = null;
    }

    public function cancelEdit(): void
    {
        $this->editField = '';
        $this->editValue = '';
    }

    public function saveField(): void
    {
        if ($this->state !== 'verified' || $this->editField === '') {
            return;
        }
        $employee = $this->employee();
        if (!$employee) {
            return;
        }

        // Whitelist (Defense-in-Depth): nur Felder aus editableFieldGroups
        // duerfen geschrieben werden. Schuetzt vor manipulated POSTs die
        // versuchen z.B. identity_card_number oder is_eu_citizen zu setzen.
        $allowed = $employee->editableFieldsFlat();
        if (!array_key_exists($this->editField, $allowed)) {
            $this->cancelEdit();
            return;
        }

        $value = trim($this->editValue);
        // Leerstring → Feld zuruecksetzen (User wollte den Wert loeschen).
        // null erlaubt damit "Daten nachpflegen" wieder anzeigt.
        $employee->update([$this->editField => $value !== '' ? $value : null]);

        $this->editFlash = "{$allowed[$this->editField]} gespeichert.";
        $this->editField = '';
        $this->editValue = '';

        // Cache der Computed-Properties invalidieren, damit aktuelle
        // Werte + missingFields neu berechnet werden
        unset($this->employee, $this->missingFields, $this->editableGroups);
    }

    /**
     * Computed: Feldgruppen mit aktuellen Werten — fuer die Stammdaten-
     * Sektion im Portal. Struktur:
     *   [
     *     'Kontakt' => [
     *       ['key' => 'email', 'label' => 'Email', 'value' => 'a@b.com', 'is_missing' => false],
     *       ...
     *     ],
     *     ...
     *   ]
     */
    #[Computed]
    public function editableGroups(): array
    {
        $employee = $this->employee();
        if (!$employee) {
            return [];
        }

        $out = [];
        foreach ($employee->editableFieldGroups() as $section => $fields) {
            $entries = [];
            foreach ($fields as $key => $label) {
                $value = $employee->getAttribute($key);
                $entries[] = [
                    'key'        => $key,
                    'label'      => $label,
                    'value'      => $value,
                    'is_missing' => ($value === null || $value === ''),
                ];
            }
            $out[$section] = $entries;
        }
        return $out;
    }

    #[Computed]
    public function employee(): ?RecEmployee
    {
        if (!$this->employeeId) {
            return null;
        }
        return RecEmployee::with(['applicant.contracts.contractTemplate'])
            ->find($this->employeeId);
    }

    #[Computed]
    public function contracts(): array
    {
        $employee = $this->employee();
        if (!$employee || !$employee->applicant) {
            return [];
        }

        return $employee->applicant->contracts
            ->filter(fn ($c) => $c->status !== 'cancelled')
            ->map(function ($c) {
                $contractLink = $c->getOrCreatePublicFormLink();
                $code = $c->contractTemplate?->code;
                $displayName = match (true) {
                    $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag',
                    $code === 'IFSG'                                => 'Infektionsschutzgesetz',
                    $code !== null && str_starts_with($code, 'AT-') => 'Zusatzvereinbarung',
                    default                                         => $c->contractTemplate?->name ?? 'Vertrag',
                };
                return [
                    'id'           => $c->id,
                    'display_name' => $displayName,
                    'status'       => $c->status,
                    'signed_at'    => $c->signed_at,
                    'completed_at' => $c->completed_at,
                    'sign_url'     => route('recruiting.public.contract-signing', ['token' => $contractLink->token]),
                ];
            })
            ->values()
            ->toArray();
    }

    #[Computed]
    public function missingFields(): array
    {
        return $this->employee()?->missingFields() ?? [];
    }

    public function render()
    {
        return view('recruiting::livewire.public.employee-portal')
            ->layout('platform::layouts.guest');
    }

    // ---- Rate-Limit-Helpers ----

    private function sessionKey(): string
    {
        return "employee_portal_verified:{$this->employeeId}";
    }

    private function attemptCacheKey(): string
    {
        return "employee_portal_attempts:{$this->token}";
    }

    private function lockoutCacheKey(): string
    {
        return "employee_portal_locked:{$this->token}";
    }

    private function failedAttempts(): int
    {
        return (int) Cache::get($this->attemptCacheKey(), 0);
    }

    private function recordFailedAttempt(): void
    {
        $key = $this->attemptCacheKey();
        $attempts = $this->failedAttempts() + 1;
        Cache::put($key, $attempts, now()->addMinutes(self::LOCKOUT_MINUTES));
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put($this->lockoutCacheKey(), true, now()->addMinutes(self::LOCKOUT_MINUTES));
        }
    }

    private function isRateLimited(): bool
    {
        return (bool) Cache::get($this->lockoutCacheKey(), false);
    }

    private function clearFailedAttempts(): void
    {
        Cache::forget($this->attemptCacheKey());
        Cache::forget($this->lockoutCacheKey());
    }
}
