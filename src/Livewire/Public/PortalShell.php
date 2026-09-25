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
use Platform\Recruiting\Support\MainEmployerRequiredGuard;
use Platform\Recruiting\Support\PortalBoolValue;
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
    /**
     * Zweite, OPTIONALE Datei fuer die vier zweiseitigen Arten (Ausweis,
     * Aufenthaltstitel, Arbeitsgenehmigung, Fiktionsbescheinigung). Welche das
     * sind, sagt der Katalog ueber die Zahl seiner Altspalten — zwei
     * Eintraege heissen Vorder- und Rueckseite. Ohne dieses Feld gab es fuer
     * die Rueckseite im neuen Portal gar keinen Weg, und das Doppelschreiben
     * loeschte sie bei jedem Upload (M1).
     */
    public $uploadDateiRueckseite = null;
    public string $uploadFehler = '';

    /**
     * Formularwerte der Arbeitgeber-Pflichtfrage (Markus 24.09.2026) im
     * Profil-Bereich. Dieselbe Konvention wie im alten Portal
     * (PortalBoolValue): Strings aus wire:model, dreiwertig ('', '1', '0').
     * NICHT gesperrt -- die Sicherheit sitzt in speichereArbeitgeber() ueber
     * berechtigterMitarbeiter(), nicht in der Unveraenderlichkeit dieser
     * Eingaben (gleiches Muster wie die Upload-Felder oben).
     */
    public string $arbeitgeberIstHaupt = '';
    public string $arbeitgeberAnderer = '';
    public string $arbeitgeberFehler = '';

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
        $this->uploadDateiRueckseite = null;
        $this->uploadFehler = '';
    }

    /** Das Formular schliessen, ohne zu speichern — z. B. „Abbrechen". */
    public function schliesseUpload(): void
    {
        $this->uploadCode = null;
        $this->uploadGueltigBis = '';
        $this->uploadDatei = null;
        $this->uploadDateiRueckseite = null;
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

        $dateiRegel = 'file|mimes:' . implode(',', ProofUploadRules::MIME_TYPES)
            . '|max:' . ProofUploadRules::MAX_KB;

        try {
            $this->validate([
                'uploadDatei' => 'required|' . $dateiRegel,
                // Die Rueckseite ist freiwillig — aber wenn eine kommt, gelten
                // dieselben Regeln. „nullable" statt „required".
                'uploadDateiRueckseite' => 'nullable|' . $dateiRegel,
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

            // Beide Dateien VOR dem Schreiben hochladen: sonst haette der
            // Nachweis eine Vorderseite und die Rueckseite fehlte stumm.
            $ergebnisRueckseite = $this->uploadDateiRueckseite !== null
                ? app(ContextFileService::class)->uploadForContext(
                    $this->uploadDateiRueckseite, 'rec_employee', $employee->id,
                    ['team_id' => $employee->team_id, 'user_id' => null],
                )
                : null;
        } catch (\Throwable $e) {
            $this->uploadFehler = 'Das Hochladen hat nicht geklappt. Bitte versuch es noch einmal.';
            report($e);

            return;
        }

        app(ProofWriter::class)->store($employee, $this->uploadCode, [
            'file_id'      => (int) $ergebnis['id'],
            // null heisst hier ausdruecklich „keine Aussage" — ProofWriter
            // laesst eine vorhandene Rueckseite dann in Ruhe (M1).
            'file_back_id' => $ergebnisRueckseite !== null ? (int) $ergebnisRueckseite['id'] : null,
            'valid_until'  => ProofTypes::hasExpiry($this->uploadCode) ? $this->uploadGueltigBis : null,
            'uploaded_via' => 'employee',
        ]);

        $this->uploadCode = null;
        $this->uploadDatei = null;
        $this->uploadDateiRueckseite = null;
        $this->uploadGueltigBis = '';
        $this->uploadFehler = '';
    }

    /**
     * Haupt-/Nebenarbeitgeber speichern (Markus 24.09.2026) -- wiederverwendet
     * MainEmployerRequiredGuard statt einer zweiten Regel, siehe
     * MainEmployerRequiredGuardTest und EmployeePortal::saveAll().
     *
     * berechtigterMitarbeiter() ist auch hier keine Formalitaet: ohne
     * gueltige Anmeldung laeuft $wire.call('speichereArbeitgeber') ins Leere,
     * genau wie bei speichereNachweis().
     *
     * GESCHRIEBEN WIRD UEBER DEN QUERY BUILDER, NICHT UEBER ELOQUENT:
     * is_main_employer/other_employer stehen NICHT in
     * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS (siehe Kommentar
     * dort und Migration 2026_09_23_000002_add_employer_fields_to_rec_employees)
     * -- ZAS soll diese Angabe (noch) nicht sehen: die Rueckfrage an den
     * Kunden ist offen, und unsere Aktualisierungsdatei liefert VOLLE ZEILEN.
     * Ein Marker auf einem Bestandsmitarbeiter wuerde also dessen komplette,
     * in ZAS gepflegte Akte ueberschreiben (Vorfall 02.09.2026). Das alte
     * Portal schreibt zwar ueber Eloquent, loest wegen exakt derselben
     * Feldliste aber ebenfalls keinen Marker aus (EmployerFieldsNoExportMarkerTest)
     * -- der Query Builder haelt diese Entscheidung explizit fest, statt sich
     * auf die Feldliste allein zu verlassen: sie bleibt auch dann sicher,
     * wenn ZAS die Angabe irgendwann doch bekommt und die Liste sich aendert.
     */
    public function speichereArbeitgeber(): void
    {
        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null) {
            return;
        }

        $fehler = MainEmployerRequiredGuard::error($this->arbeitgeberIstHaupt, $this->arbeitgeberAnderer);
        if ($fehler !== null) {
            $this->arbeitgeberFehler = $fehler;

            return;
        }
        $this->arbeitgeberFehler = '';

        $istHaupt = PortalBoolValue::parse($this->arbeitgeberIstHaupt);

        // "Ja" leert einen zuvor eingetragenen anderen Arbeitgeber -- dieselbe
        // Regel wie EmployeePortal::saveAll(): die Spalte ist AUSSCHLIESSLICH
        // die Antwort auf "wenn nicht wir, wer dann" und darf keine zwei
        // Bedeutungen tragen.
        $anderer = $istHaupt === true ? null : trim($this->arbeitgeberAnderer);
        $anderer = $anderer === '' ? null : $anderer;

        DB::table('rec_employees')->where('id', $employee->id)->update([
            'is_main_employer' => $istHaupt,
            'other_employer'   => $anderer,
        ]);

        $this->arbeitgeberIstHaupt = $istHaupt ? '1' : '0';
        $this->arbeitgeberAnderer  = (string) ($anderer ?? '');
    }

    /**
     * Synthetische oberste Aufgabe im Start-Bereich: die Arbeitgeber-
     * Pflichtfrage, solange sie unbeantwortet ist. Kein ProofChecklist-
     * Eintrag -- es ist kein Nachweis, sondern eine Angabe, und ein Klick
     * fuehrt ins Profil statt ins Upload-Formular. Gleiche Form wie eine
     * dekorierte Zeile, damit Blade beide gleich rendern kann.
     */
    public static function arbeitgeberAufgabe(bool $duzen): array
    {
        return [
            'code'  => 'arbeitgeber_frage',
            'label' => 'Hauptarbeitgeber',
            'punkt' => 'crit',
            'text'  => $duzen
                ? 'Bitte gib an, ob wir dein Hauptarbeitgeber sind — daran hängt deine Steuerklasse.'
                : 'Bitte geben Sie an, ob wir Ihr Hauptarbeitgeber sind — daran hängt Ihre Steuerklasse.',
            'offen' => true,
        ];
    }

    public function render()
    {
        $employee = $this->berechtigterMitarbeiter();

        $checklist = $employee ? app(ProofReader::class)->checklist($employee) : [];
        $offenAusNachweisen = count(array_filter($checklist, fn ($z) => $z['offen']));

        // Die Arbeitgeber-Pflichtfrage ist wichtiger als jeder Nachweis --
        // solange sie fehlt, gehoert sie ganz oben in den Start-Bereich, und
        // sie zaehlt im Gesamt-"offen" mit (Nav-Punkt, Reiter-Abzeichen).
        $arbeitgeberOffen = $employee !== null && $employee->is_main_employer === null;

        return view('recruiting::livewire.public.portal-shell', [
            'aufgaben'          => self::dekoriert($checklist),
            'offen'             => $offenAusNachweisen + ($arbeitgeberOffen ? 1 : 0),
            'arbeitgeberAufgabe' => $arbeitgeberOffen ? self::arbeitgeberAufgabe($this->duzen) : null,
            'anstellungen'      => $employee ? $this->anstellungen($employee) : collect(),
            'uploadLabel'       => $this->uploadCode !== null ? ProofTypes::label($this->uploadCode) : '',
            'uploadHatAblauf'   => $this->uploadCode !== null && ProofTypes::hasExpiry($this->uploadCode),
            // Zwei Altspalten = Vorder- und Rueckseite. Der Katalog ist die
            // einzige Stelle, die das weiss — keine zweite Liste hier.
            'uploadHatRueckseite' => $this->uploadCode !== null
                && count(ProofTypes::legacyFileColumns($this->uploadCode)) === 2,
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

        // Formular der Arbeitgeber-Frage vorbelegen -- sonst zeigt die
        // Auswahl bei jedem Neuladen leer, obwohl schon geantwortet wurde.
        // Dieselbe dreiwertige Stringform wie ueberall im Portal (siehe
        // PortalBoolValue): null bleibt '', sonst '1'/'0'.
        $this->arbeitgeberIstHaupt = $employee->is_main_employer === null
            ? ''
            : ($employee->is_main_employer ? '1' : '0');
        $this->arbeitgeberAnderer = (string) ($employee->other_employer ?? '');
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
