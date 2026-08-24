<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\CoreLookup;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\TrainingCertificatePortalRows;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;

/**
 * Mitarbeiter-Portal — Login-geschuetzter Bereich nach Bewerber→MA-
 * Konvertierung (Phase-4-Abschluss). Anders als ApplicantPortal:
 *
 *  - Token-Lookup direkt auf rec_employees.portal_token
 *  - Zwei-Faktor-aehnliche Verifizierung: Geburtsdatum + letzte 4
 *    Stellen der Ausweisnummer — kann Buchstaben enthalten, Vergleich
 *    case-insensitiv (beides aus P3-Pflichtfeldern erfasst)
 *  - Rate-Limit gegen Brute-Force: 5 falsche Versuche → 15min Sperre
 *  - Nach Verifikation: Vertraege unterschreiben + Daten nachpflegen
 *
 * State-Diagramm: loading → tokenInvalid | unverified → verified | rateLimited
 */
class EmployeePortal extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $state = 'loading';
    #[Locked]
    public ?int $employeeId = null;
    #[Locked]
    public string $token = '';
    #[Locked]
    public string $displayName = '';
    #[Locked]
    public bool $duzen = false;

    /**
     * Server-only-Sperrflag (Eskalations-Stufe 3, DispoEmployeeGateway::
     * lockPortal). #[Locked] verhindert Client-Ueberschreiben per
     * $wire.set — wird ausschliesslich in mount()/verify()/den
     * datenaendernden Actions aus rec_employees.portal_locked_at gesetzt,
     * nie per wire:model gebunden. Wenn true, zeigt das Blade den
     * Sperr-Screen statt Inhalte; keine Feldwerte werden geladen.
     */
    #[Locked]
    public bool $portalLocked = false;

    // Login-Form
    public string $birthDateInput = '';
    public string $idCardLast4Input = '';
    public ?string $loginError = null;

    /**
     * Assoc-Array aller editierbaren Text/Lookup/Bool/Date-Felder.
     * Wird in mount/loadFieldValues mit current values vorbefuellt.
     * Bei saveAll() wird der Diff zum Employee-Record geschrieben.
     */
    public array $fieldValues = [];

    /** Flash-Message nach saveAll oder File-Upload */
    public ?string $editFlash = null;

    /**
     * File-Upload-Properties — pro File-Field eine eigene Property weil
     * Livewire WithFileUploads keine assoc-Arrays sauber handhabt.
     * Wird bei Datei-Auswahl sofort hochgeladen (kein Buffer).
     */
    public $uploadIdentityFront = null;
    public $uploadIdentityBack = null;
    public $uploadSelfie = null;
    public $uploadHealthInsuranceCard = null;
    public $uploadImmatrikulation = null;
    public $uploadSchulbescheinigung = null;
    public $uploadErstbescheinigung = null;

    /** Map File-Field-Key → property-Name fuer dynamische Zugriffe */
    private const FILE_FIELDS = [
        'identity_card_front_file_id'   => 'uploadIdentityFront',
        'identity_card_back_file_id'    => 'uploadIdentityBack',
        'selfie_file_id'                => 'uploadSelfie',
        'health_insurance_card_file_id' => 'uploadHealthInsuranceCard',
        'immatrikulation_file_id'       => 'uploadImmatrikulation',
        'schulbescheinigung_file_id'    => 'uploadSchulbescheinigung',
        'erstbescheinigung_file_id'     => 'uploadErstbescheinigung',
    ];

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
        $this->duzen = $employee->usesInformalAddress();

        // Eskalations-Stufe-3-Sperre (DispoEmployeeGateway::lockPortal):
        // serverseitig VOR jedem Feldladen pruefen — keine Daten laden,
        // Blade zeigt nur den Sperr-Screen. Kein Client-Bypass moeglich,
        // portalLocked ist #[Locked].
        if ($employee->portal_locked_at !== null) {
            $this->portalLocked = true;
            return;
        }

        // Initial-Werte fuer alle editierbaren Felder laden (Direct-Edit-UX)
        $this->loadFieldValues($employee);

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

        // Erneut pruefen: der MA kann zwischen mount() und verify() gesperrt
        // worden sein (Eskalations-Cron laeuft unabhaengig vom Request).
        if ($employee->portal_locked_at !== null) {
            $this->portalLocked = true;
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
        $this->loadFieldValues($employee->fresh());
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
     * Laedt aktuelle Werte aus dem Employee-Record in $fieldValues.
     * Wird in mount() aufgerufen sowie nach saveAll() um den State zu
     * refreshen.
     */
    private function loadFieldValues(RecEmployee $employee): void
    {
        $values = [];
        foreach ($employee->editableFieldsFlat() as $field => $meta) {
            $type = $meta['type'] ?? 'text';
            if ($type === 'file') {
                continue; // Files werden separat ueber upload-Properties gehandhabt
            }
            $raw = $employee->getAttribute($field);
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format('Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $values[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->fieldValues = $values;
    }

    /**
     * Globaler Speicher-Button — committed alle Text/Lookup/Bool/Date-
     * Aenderungen auf einmal. File-Uploads laufen separat sofort bei
     * Datei-Auswahl (siehe updatedUpload*-Hooks).
     */
    public function saveAll(): void
    {
        if ($this->state !== 'verified' || $this->portalLocked) {
            return;
        }
        $employee = $this->employee();
        if (!$employee) {
            return;
        }
        // Frisch pruefen — eine zwischenzeitliche Sperre darf den Save
        // nicht mehr durchlassen, unabhaengig vom gecachten Session-State.
        if ($employee->portal_locked_at !== null) {
            $this->portalLocked = true;
            return;
        }

        $allowed = $employee->editableFieldsFlat();
        $updates = [];
        foreach ($this->fieldValues as $field => $value) {
            if (!array_key_exists($field, $allowed)) {
                continue; // Schutz gegen manipulated POST
            }
            $meta = $allowed[$field];
            $type = $meta['type'] ?? 'text';
            $value = is_string($value) ? trim($value) : $value;

            if ($type === 'bool') {
                $updates[$field] = match ((string) $value) {
                    '1', 'true', 'ja' => true,
                    '0', 'false', 'nein' => false,
                    default => null,
                };
            } else {
                // text, lookup, date — alle als string-or-null
                $updates[$field] = ($value === '' || $value === null) ? null : $value;
            }
        }

        if (empty($updates)) {
            $this->editFlash = 'Keine Aenderungen.';
            return;
        }

        $employee->update($updates);
        $this->editFlash = 'Aenderungen gespeichert.';

        // State refreshen damit die Anzeige der gespeicherten Werte
        // sofort konsistent ist
        $this->loadFieldValues($employee->fresh());
        unset($this->employee, $this->missingFields, $this->editableGroups);
    }

    /**
     * Livewire-Hook: feuert automatisch wenn $uploadIdentityFront sich
     * aendert (= Datei wurde im Browser ausgewaehlt). Triggert sofort
     * den Upload — kein "Speichern"-Button noetig fuer Files.
     */
    public function updatedUploadIdentityFront(): void
    {
        $this->handleFileUpload('identity_card_front_file_id', 'uploadIdentityFront');
    }

    public function updatedUploadIdentityBack(): void
    {
        $this->handleFileUpload('identity_card_back_file_id', 'uploadIdentityBack');
    }

    public function updatedUploadSelfie(): void
    {
        $this->handleFileUpload('selfie_file_id', 'uploadSelfie');
    }

    public function updatedUploadHealthInsuranceCard(): void
    {
        $this->handleFileUpload('health_insurance_card_file_id', 'uploadHealthInsuranceCard');
    }

    public function updatedUploadImmatrikulation(): void
    {
        $this->handleFileUpload('immatrikulation_file_id', 'uploadImmatrikulation');
    }

    public function updatedUploadSchulbescheinigung(): void
    {
        $this->handleFileUpload('schulbescheinigung_file_id', 'uploadSchulbescheinigung');
    }

    public function updatedUploadErstbescheinigung(): void
    {
        $this->handleFileUpload('erstbescheinigung_file_id', 'uploadErstbescheinigung');
    }

    /**
     * Generischer File-Upload-Handler. Whitelist-checked, faengt
     * Service-Fehler ab, setzt FileId auf den Employee + reset
     * Upload-Property damit das File-Input wieder leer ist.
     */
    private function handleFileUpload(string $employeeField, string $propertyName): void
    {
        if ($this->state !== 'verified' || $this->portalLocked) {
            return;
        }
        $employee = $this->employee();
        if (!$employee) {
            return;
        }
        if ($employee->portal_locked_at !== null) {
            $this->portalLocked = true;
            return;
        }
        $file = $this->{$propertyName};
        if (!$file) {
            return;
        }

        $allowed = $employee->editableFieldsFlat();
        if (!array_key_exists($employeeField, $allowed) || ($allowed[$employeeField]['type'] ?? '') !== 'file') {
            return;
        }

        try {
            $result = app(ContextFileService::class)->uploadForContext(
                $file,
                'rec_employee',
                $employee->id,
                [
                    'team_id' => $employee->team_id,
                    'user_id' => null,
                ]
            );
            $employee->update([$employeeField => (int) $result['id']]);
            $this->editFlash = "{$allowed[$employeeField]['label']} hochgeladen.";
        } catch (\Throwable $e) {
            $this->editFlash = 'Upload-Fehler: ' . $e->getMessage();
        }

        // Upload-Property reset damit das File-Input clean ist
        $this->{$propertyName} = null;
        unset($this->employee, $this->missingFields, $this->editableGroups);
    }

    /**
     * Computed: Feldgruppen mit Meta-Info + aktuellen Werten. Strukturiert
     * fuer's Blade-Render:
     *   [
     *     'Kontakt' => [
     *       [
     *         'key' => 'email', 'label' => 'Email', 'type' => 'text',
     *         'value' => 'a@b.com', 'display' => 'a@b.com',
     *         'is_missing' => false,
     *       ],
     *       ...
     *     ],
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
            foreach ($fields as $key => $meta) {
                $value = $employee->getAttribute($key);
                $type = $meta['type'] ?? 'text';
                $display = $this->formatDisplayValue($value, $type, $meta);
                $isMissing = ($value === null || $value === '' || $value === []);
                $entries[] = [
                    'key'        => $key,
                    'label'      => $meta['label'] ?? $key,
                    'type'       => $type,
                    'lookup'     => $meta['lookup'] ?? null,
                    'options'    => $meta['options'] ?? null,
                    'value'      => $value,
                    'display'    => $display,
                    'is_missing' => $isMissing,
                ];
            }
            $out[$section] = $entries;
        }
        return $out;
    }

    /**
     * Formatiert den Anzeigewert fuer's Read-only-Display je nach Type.
     * - bool   → "Ja" / "Nein" / leer
     * - lookup → human-readable Label statt code-Wert
     * - date   → d.m.Y
     * - file   → Dateiname falls hochgeladen, sonst leer
     * - text   → raw
     */
    private function formatDisplayValue($value, string $type, array $meta): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        return match ($type) {
            'bool'   => $value ? 'Ja' : 'Nein',
            'lookup' => $this->lookupLabel($meta['lookup'] ?? '', (string) $value) ?? (string) $value,
            'date'   => $this->formatDate($value),
            'file'   => $this->fileNameForId((int) $value) ?? "Datei #{$value}",
            default  => (string) $value,
        };
    }

    private function formatDate($value): string
    {
        try {
            if (is_object($value) && method_exists($value, 'format')) {
                return $value->format('d.m.Y');
            }
            return \Carbon\Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function fileNameForId(?int $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }
        try {
            $file = \Platform\Core\Models\ContextFile::find($fileId);
            return $file?->original_name;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lookupLabel(string $lookupName, string $value): ?string
    {
        if ($lookupName === '' || $value === '') {
            return null;
        }
        $options = $this->lookupOptionsFor($lookupName);
        return $options[$value] ?? null;
    }

    /** Caches Lookup-Options per request, key = lookup-name */
    private array $lookupCache = [];

    /**
     * Lookup-Options ['value' => 'label'] fuer einen Lookup-Namen.
     * Returnt leeres Array wenn Lookup nicht existiert.
     */
    public function lookupOptionsFor(string $lookupName): array
    {
        if (!isset($this->lookupCache[$lookupName])) {
            try {
                $lookup = CoreLookup::where('name', $lookupName)->first();
                $this->lookupCache[$lookupName] = $lookup ? $lookup->getOptionsArray() : [];
            } catch (\Throwable) {
                $this->lookupCache[$lookupName] = [];
            }
        }
        return $this->lookupCache[$lookupName];
    }

    /**
     * Computed: Read-only-Display-Felder (z.B. recruited_by_personnel_number).
     */
    #[Computed]
    public function readOnlyDisplay(): array
    {
        return $this->employee()?->readOnlyDisplayFields() ?? [];
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

        // Applicant-Form-Link einmal pro Aufruf holen — wird fuer PDF-Download
        // benoetigt (ContractPdfController validiert via CorePublicFormLink-Token
        // des verlinkten Bewerbers, nicht ueber den MA-portal_token).
        $applicantToken = $employee->applicant->getOrCreatePublicFormLink()->token;

        $contractRows = $employee->applicant->contracts
            ->filter(fn ($c) => $c->status !== 'cancelled')
            ->map(function ($c) use ($applicantToken) {
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
                    'pdf_url'      => $c->status === 'completed'
                        ? route('recruiting.public.contract-pdf', ['token' => $applicantToken, 'contractId' => $c->id])
                        : null,
                ];
            })
            ->values()
            ->toArray();

        return TrainingCertificatePortalRows::append(
            $contractRows,
            $this->certificateRows((int) $employee->applicant->id)
        );
    }

    /**
     * Die Zertifikat-Zeilen eines Bewerbers, in der Form der Vertragszeilen.
     *
     * KEIN Filter auf `kind`: ein Bewerber darf Zertifikate mehrerer
     * Schulungsarten haben (die Dedup-Dimension der Tabelle ist
     * (rec_applicant_id, kind)), und im Portal sollen alle liegen. Der
     * kind-Filter gehoert an die AUSSTELLUNG (dort steht er, siehe
     * CertificateIssuanceEligibility), nicht an die Anzeige.
     *
     * Die Route wird ueber die uuid adressiert, nicht ueber einen Token — der
     * Grund steht bei TrainingCertificateWaTemplate::ROUTE_NAME und in
     * routes/public.php.
     *
     * @return list<array<string,mixed>>
     */
    private function certificateRows(int $applicantId): array
    {
        return RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicantId)
            ->orderBy('issued_at')
            ->get()
            ->map(fn (RecTrainingCertificate $cert) => TrainingCertificatePortalRows::row(
                $cert->id,
                $cert->issued_at,
                route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => $cert->uuid]),
            ))
            ->all();
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
