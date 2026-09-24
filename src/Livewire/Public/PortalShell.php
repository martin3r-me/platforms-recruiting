<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\ProofReader;
use Platform\Recruiting\Services\ProofWriter;
use Platform\Recruiting\Support\ProofChecklist;
use Platform\Recruiting\Support\ProofTypes;
use Platform\Recruiting\Support\ProofUploadRules;

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
    use WithFileUploads;

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

    /**
     * Eingaben des Upload-Formulars — ebenfalls NICHT gesperrt, der Mensch
     * waehlt Nachweisart, Datum und Datei ja selbst. Die Sicherheit steckt
     * nicht darin, dass diese Felder unveraenderlich waeren, sondern darin,
     * dass speichereNachweis() bei JEDEM Aufruf erneut ueber
     * berechtigterMitarbeiter() prueft, ob ueberhaupt geschrieben werden darf.
     */
    public ?string $uploadCode = null;
    public string $uploadGueltigBis = '';
    public $uploadDatei = null;
    public string $uploadFehler = '';

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

        $this->employeeId = $employee->id;
        $this->duzen      = $employee->usesInformalAddress();

        if ($auth->isLocked($employee)) {
            $this->state = 'gesperrt';

            return;
        }

        if (session()->has(PortalAuth::sessionKey($employee->id))) {
            $this->identitaetLaden($employee);
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
        if (!$employee || $employee->id !== $this->employeeId || $employee->portal_v2_since === null) {
            // Auch die Weiche gilt weiter: wird der Pilot waehrend einer
            // offenen Anmeldeseite zurueckgenommen, endet der Versuch hier.
            $this->state = 'weg';

            return;
        }

        // Erneut pruefen — zwischen mount() und verify() koennen Minuten
        // liegen, und der Eskalations-Cron laeuft unabhaengig vom Request.
        // Dieser Waechter stand schon im alten Portal; beim Herausloesen der
        // Anmeldeschicht ist er zunaechst verlorengegangen.
        if ($auth->isLocked($employee)) {
            $this->state = 'gesperrt';

            return;
        }

        // Leere Eingaben kosten keinen Versuch. Ueber die Oberflaeche sind sie
        // durch `required` kaum zu treffen, ueber $wire.call('verify') aber
        // trivial — fuenf Leeraufrufe wuerden den Token 15 Minuten sperren.
        if (trim($this->birthDate) === '' || trim($this->idLast4) === '') {
            $this->fehler = 'Bitte beide Felder ausfüllen.';

            return;
        }

        $ergebnis = $auth->attempt($employee, $this->token, $this->birthDate, $this->idLast4);

        if ($ergebnis['status'] === PortalAuth::OK) {
            session()->put(PortalAuth::sessionKey($employee->id), true);

            // Denselben Stempel wie das alte Portal setzen — an ihm haengt die
            // Frage „wer nutzt das Portal ueberhaupt?", mit der die Groesse
            // der Umstellung bestimmt wird. Ueber den Query Builder, damit
            // kein Modell-Ereignis und damit kein Export-Marker entsteht.
            DB::table('rec_employees')->where('id', $employee->id)
                ->update(['portal_verified_at' => now()]);

            $this->identitaetLaden($employee);
            $this->state = 'verified';
            $this->fehler = '';
            $this->birthDate = '';
            $this->idLast4 = '';

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

    /**
     * Abmelden. Wichtig auf geteilten Geraeten: ohne das bleibt der Naechste,
     * der den Link oeffnet, angemeldet — und weil beide Portale sich den
     * Sitzungsschluessel teilen, auch gleich im alten.
     */
    public function logout(): void
    {
        if ($this->employeeId !== null) {
            session()->forget(PortalAuth::sessionKey($this->employeeId));
        }

        $this->state = 'unverified';
        $this->displayName = '';
        $this->initialen = '';
        $this->fehler = '';
        $this->birthDate = '';
        $this->idLast4 = '';
    }

    /**
     * Eine Aufgabe antippen — oeffnet das Formular fuer genau diese Art.
     *
     * Unbekannte Codes (kaputter Link, veraltetes Snapshot) fuehren still ins
     * Leere statt in einen Fehler: $uploadCode bleibt null, das Formular
     * zeigt sich nicht.
     */
    public function oeffneUpload(string $code): void
    {
        $this->uploadCode = ProofTypes::exists($code) ? $code : null;
        $this->uploadGueltigBis = '';
        $this->uploadDatei = null;
        $this->uploadFehler = '';
    }

    /** Das Formular schliessen, ohne zu speichern — z. B. „Abbrechen". */
    public function schliesseUpload(): void
    {
        $this->uploadCode = null;
        $this->uploadGueltigBis = '';
        $this->uploadDatei = null;
        $this->uploadFehler = '';
    }

    /**
     * Den Nachweis speichern.
     *
     * berechtigterMitarbeiter() ist hier keine Formalitaet: sie ist der
     * einzige Grund, warum $wire.call('speichereNachweis') ohne Anmeldung
     * (state manipuliert, Anmeldung nie erfolgt) ins Leere laeuft.
     */
    public function speichereNachweis(): void
    {
        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null || $this->uploadCode === null) {
            return;
        }

        $fehler = ProofUploadRules::pruefeDatum(
            $this->uploadCode,
            $this->uploadGueltigBis,
            now()->toDateString(),
        );
        if ($fehler !== null) {
            $this->uploadFehler = $fehler;

            return;
        }

        try {
            $this->validate([
                'uploadDatei' => 'required|file|mimes:' . implode(',', ProofUploadRules::MIME_TYPES)
                    . '|max:' . ProofUploadRules::MAX_KB,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Livewire legt die Meldung in die Fehler-Ablage, die diese
            // Ansicht aber nirgends anzeigt (nur $uploadFehler) — ohne den
            // Fang saehe der Mensch gar nichts, das Fenster bliebe stumm offen.
            $this->uploadFehler = 'Diese Datei können wir nicht annehmen. Erlaubt sind '
                . 'Fotos und PDF bis ' . (int) round(ProofUploadRules::MAX_KB / 1024) . ' MB.';

            return;
        }

        try {
            $ergebnis = app(ContextFileService::class)->uploadForContext(
                $this->uploadDatei, 'rec_employee', $employee->id,
                ['team_id' => $employee->team_id, 'user_id' => null],
            );
        } catch (\Throwable $e) {
            $this->uploadFehler = 'Das Hochladen hat nicht geklappt. Bitte versuch es noch einmal.';
            report($e);

            return;
        }

        app(ProofWriter::class)->store($employee, $this->uploadCode, [
            'file_id'     => (int) $ergebnis['id'],
            'valid_until' => ProofTypes::hasExpiry($this->uploadCode) ? $this->uploadGueltigBis : null,
            'uploaded_via' => 'employee',
        ]);

        $this->uploadCode = null;
        $this->uploadDatei = null;
        $this->uploadGueltigBis = '';
        $this->uploadFehler = '';
    }

    public function render()
    {
        $employee = $this->berechtigterMitarbeiter();

        $checklist = $employee ? app(ProofReader::class)->checklist($employee) : [];

        return view('recruiting::livewire.public.portal-shell', [
            'aufgaben'        => self::dekoriert($checklist),
            'offen'           => count(array_filter($checklist, fn ($z) => $z['offen'])),
            'anstellungen'    => $employee ? $this->anstellungen($employee) : collect(),
            'uploadLabel'     => $this->uploadCode !== null ? ProofTypes::label($this->uploadCode) : '',
            'uploadHatAblauf' => $this->uploadCode !== null && ProofTypes::hasExpiry($this->uploadCode),
            'uploadAccept'    => '.' . implode(',.', ProofUploadRules::MIME_TYPES),
        ])->layout('recruiting::layouts.portal', [
            'title' => 'Mein Portal · RheinGedeck',
        ]);
    }

    /**
     * Wer hier Daten sieht, muss sie bei JEDEM Durchgang noch sehen duerfen.
     *
     * mount() laeuft einmal, danach lebt die Seite oft Minuten oder Stunden
     * weiter. In dieser Zeit kann die Dispo sperren, HR den Mitarbeiter
     * stilllegen oder der Pilot zurueckgenommen werden. Ohne diese Pruefung
     * wirkt jede dieser Bremsen erst beim Neuladen.
     */
    private function berechtigterMitarbeiter(): ?RecEmployee
    {
        if ($this->state !== 'verified' || $this->employeeId === null) {
            return null;
        }

        $employee = RecEmployee::find($this->employeeId);

        if (!$employee || !$employee->is_active || $employee->portal_v2_since === null) {
            $this->state = 'weg';

            return null;
        }

        if ($employee->portal_locked_at !== null) {
            $this->state = 'gesperrt';

            return null;
        }

        return $employee;
    }

    /**
     * Name und Initialen kommen erst NACH der Anmeldung in den Zustand.
     *
     * #[Locked] schuetzt gegen Schreiben, nicht gegen Mitschicken: alles, was
     * hier steht, faehrt im wire:snapshot jeder Antwort mit — also auch auf
     * der Anmeldeseite, die verspricht, dass niemand anders die Daten sieht.
     * Wer nur den weitergeleiteten Link hat, soll daraus nicht den Vornamen
     * lesen koennen.
     */
    private function identitaetLaden(RecEmployee $employee): void
    {
        $this->displayName = $this->vorname($employee);
        $this->initialen   = $this->initialen($employee);
    }

    /**
     * Aus dem Status eine Farbe und einen Satz machen — hier und nicht in
     * Blade, damit die Ansicht nichts entscheidet und das Mapping pruefbar
     * bleibt. Rot heisst: er kann nicht arbeiten. Gelb: es laeuft auf etwas zu.
     *
     * @param  list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}> $zeilen
     * @return list<array{code:string, label:string, punkt:string, text:string, offen:bool}>
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

            return ['code' => $z['code'], 'label' => $z['label'], 'punkt' => $punkt, 'text' => $text, 'offen' => $z['offen']];
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
            ->where('is_active', true)
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
