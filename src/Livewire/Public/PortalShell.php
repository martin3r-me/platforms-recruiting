<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\CoreLookup;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\PortalProfileWriter;
use Platform\Recruiting\Services\ProofReader;
use Platform\Recruiting\Services\ProofWriter;
use Platform\Recruiting\Support\PortalBoolValue;
use Platform\Recruiting\Support\PortalCompleteness;
use Platform\Recruiting\Support\PortalFieldAccess;
use Platform\Recruiting\Support\PortalFieldRelevance;
use Platform\Recruiting\Support\PortalGroupSummary;
use Platform\Recruiting\Support\PortalSectionHints;
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
     * Welche Gruppe gerade im Profil-Blatt offen ist.
     *
     * #[Locked], weil sie entscheidet, WELCHE Felder geschrieben werden --
     * dieselbe Lehre wie aus dem Auth-Bypass. Gesetzt wird sie nur von
     * oeffneGruppe(), und die prueft den Namen gegen die fuer DIESEN Menschen
     * sichtbaren Gruppen (R27/R29/R30). Die Gruppe "Arbeitgeber" (Markus
     * 24.09.2026) ist seit dem 25.09.2026 eine Gruppe wie jede andere --
     * speichereArbeitgeber() und ihre drei Formularfelder sind entfallen.
     */
    #[Locked] public ?string $profilGruppe = null;

    /**
     * Die Eingaben des offenen Profil-Blatts -- NICHT gesperrt, sie kommen
     * vom Menschen. Die Sicherheit sitzt in speichereGruppe() ueber
     * berechtigterMitarbeiter() und im Schnitt auf die offene Gruppe
     * (PortalProfileWriter::speichere()).
     */
    public array $profilWerte = [];
    public string $profilFehler = '';
    public string $profilMeldung = '';

    /** Request-Cache fuer lookupOptionen() -- ein Lookup wird pro Aufruf hoechstens einmal gelesen. */
    private array $lookupCache = [];

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
     * Eine Profil-Gruppe antippen -- oeffnet das Blatt fuer genau diese
     * Gruppe und belegt es mit dem aktuellen Stand vor.
     *
     * Unbekannte oder gerade nicht sichtbare Namen (kaputter Link, HR hat
     * waehrenddessen den Status geaendert, oder die Non-EU-Gruppe ist fuer
     * DIESEN Menschen gar keine) fuehren still ins Leere -- $profilGruppe
     * bleibt null, das Blatt zeigt sich nicht (R27/R29/R30).
     */
    public function oeffneGruppe(string $gruppe): void
    {
        $this->profilFehler = '';
        $this->profilMeldung = '';
        $this->profilGruppe = null;
        $this->profilWerte = [];

        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null) {
            return;
        }

        $gruppen = $employee->editableFieldGroups();
        $sichtbar = PortalFieldAccess::sichtbareGruppen(
            $gruppen,
            $this->datensatzWerte($employee, $gruppen),
            [],   // beim Oeffnen gibt es noch keine Formulareingaben
        );
        if (!array_key_exists($gruppe, $sichtbar)) {
            return;   // kaputter Link, veraltetes Snapshot -- still ins Leere
        }

        $this->profilGruppe = $gruppe;
        foreach ($sichtbar[$gruppe] as $schluessel => $meta) {
            if (($meta['type'] ?? 'text') === 'file') {
                continue;   // Dateien laufen ueber das Nachweis-Blatt (R20/E8)
            }
            $this->profilWerte[$schluessel] = $this->formularwert($employee, $schluessel);
        }
    }

    /** Das Profil-Blatt schliessen, ohne zu speichern -- z. B. „Abbrechen". */
    public function schliesseGruppe(): void
    {
        $this->profilGruppe = null;
        $this->profilWerte = [];
        $this->profilFehler = '';
        $this->profilMeldung = '';
    }

    /**
     * Die offene Gruppe speichern -- UEBER DEN GEMEINSAMEN SCHREIBWEG
     * (PortalProfileWriter, also ueber Eloquent), NICHT ueber den Query
     * Builder. Gilt seit 25.09.2026 fuer JEDE Gruppe, nicht mehr nur fuer den
     * Arbeitgeber:
     *
     *  - der Query Builder unterschlaegt den LOHN-TRIGGER: is_main_employer
     *    steht in RecApplicantSettings::DEFAULT_SETTINGS
     *    ['employee_payroll_tracked_fields'] -- an der Angabe haengt die
     *    Steuerklasse. Das alte Portal meldet den Wechsel ans Lohnbuero, ein
     *    Query-Builder-Schreibweg wuerde das verschweigen.
     *  - der ZAS-Schutz bleibt trotzdem: die fuenf Spalten mit Marker-VERBOT
     *    (is_main_employer, other_employer, phone,
     *    erstbescheinigung_file_id, first_aider_certificate_file_id) fehlen
     *    schlicht in RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS.
     *    Gemessen wird das am Ergebnis (PortalProfileWriterTest), nicht an
     *    der Schreibart.
     *
     * berechtigterMitarbeiter() ist auch hier keine Formalitaet: ohne
     * gueltige Anmeldung laeuft $wire.call('speichereGruppe') ins Leere,
     * genau wie bei speichereNachweis().
     *
     * Die Waechter kommen mit dem Schreibweg (PortalProfileGuards) -- seit
     * C1 (Fixrunde 1, Ruling des Koordinators) aber nur noch mit der
     * REICHWEITE der offenen Gruppe, nicht mehr alle drei unabhaengig davon.
     * Vorher blockte eine fehlende Angabe aus einer FREMDEN Gruppe (z. B.
     * Staatsangehoerigkeit) jedes Speichern, auch Bankdaten oder
     * Hemdgroesse -- bei zwei gleichzeitig fehlenden Pflichtangaben ein
     * echter Ping-Pong-Deadlock (siehe PortalProfileGuards-Docblock). Der
     * gruppenlose Pfad dieses Schreibwegs (Vollspeicherung ohne Gruppe)
     * bleibt unveraendert bei allen drei Waechtern -- RICHTIGSTELLUNG
     * (Fixrunde 2, 26.09.2026): das ist NICHT dasselbe wie EmployeePortal::
     * saveAll(). Das alte Portal ruft PortalProfileWriter/PortalProfileGuards
     * gar nicht auf, es hat seine eigene, unberuehrte Kaskade; der
     * gruppenlose Pfad hier hat aktuell keinen Produktionsaufrufer. Ein
     * Waechterfehler haelt das Blatt OFFEN, OHNE die Eingaben neu zu laden --
     * sie bleiben stehen
     * (EmployeePortal.php:288).
     */
    public function speichereGruppe(): void
    {
        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null || $this->profilGruppe === null) {
            return;
        }

        $ergebnis = app(PortalProfileWriter::class)
            ->speichere($employee, $this->profilWerte, $this->profilGruppe);

        if (!$ergebnis['ok']) {
            // Blatt bleibt offen, Eingaben bleiben stehen.
            $this->profilFehler = (string) $ergebnis['fehler'];
            $this->profilMeldung = '';

            return;
        }

        $this->profilFehler = '';
        $this->profilMeldung = (string) $ergebnis['meldung'];
        $this->profilGruppe = null;
        $this->profilWerte = [];
    }

    /**
     * Lookup-Optionen ['value' => 'label'] fuer einen Lookup-Namen -- das
     * Blade ruft sie beim Rendern des offenen Blatts. Request-Cache je
     * Lookup-Name, unbekannter/gescheiterter Lookup gibt ein leeres Array
     * (wie EmployeePortal::lookupOptionsFor()).
     *
     * @return array<string,string>
     */
    public function lookupOptionen(string $lookup): array
    {
        if (!isset($this->lookupCache[$lookup])) {
            try {
                $eintrag = CoreLookup::where('name', $lookup)->first();
                $this->lookupCache[$lookup] = $eintrag ? $eintrag->getOptionsArray() : [];
            } catch (\Throwable) {
                $this->lookupCache[$lookup] = [];
            }
        }

        return $this->lookupCache[$lookup];
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

        $profil = $this->profilDaten($employee);

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
            'profilGruppen'   => $profil['gruppen'],
            'profilStand'     => $profil['stand'],
            'profilFelder'    => $profil['felder'],
            'profilHinweis'   => $profil['hinweis'],
            'nurLesen'        => $profil['nurLesen'],
            'kacheln'         => $profil['kacheln'],
        ])->layout('recruiting::layouts.portal', [
            'title' => 'Mein Portal · RheinGedeck',
        ]);
    }

    /**
     * Alle Render-Daten des Profil-Bereichs auf einmal -- Gruppenzeilen mit
     * Zusammenfassung, Vollstaendigkeitsring, die Felder des GERADE offenen
     * Blatts, der Erklaertext dazu, die Nur-Lese-Felder und die
     * Nachweis-Kacheln. Ein Durchlauf durch editableFieldGroups(), damit
     * Task 7 (das Blade) nur noch anzeigt und keine eigene Zuordnung
     * aufmacht (§1.4 Punkt 2, sonst droht E7 wieder).
     *
     * @return array{gruppen:array, stand:array, felder:array, hinweis:?string, nurLesen:array, kacheln:array}
     */
    private function profilDaten(?RecEmployee $employee): array
    {
        if ($employee === null) {
            return [
                'gruppen'  => [],
                'stand'    => PortalCompleteness::stand([], []),
                'felder'   => [],
                'hinweis'  => null,
                'nurLesen' => [],
                'kacheln'  => [],
            ];
        }

        $gruppen = $employee->editableFieldGroups();
        $datensatz = $this->datensatzWerte($employee, $gruppen);
        // Beim Rendern gibt es keinen "aktiven" Formularstand ausserhalb des
        // offenen Blatts -- Sichtbarkeit von Gruppen/Feldern misst sich also
        // am Datensatz, nicht an $profilWerte (die gehoeren nur zur offenen
        // Gruppe und wuerden fuer alle anderen Gruppen gar nicht passen).
        $sichtbar = PortalFieldAccess::sichtbareGruppen($gruppen, $datensatz, []);

        $profilGruppen = [];
        foreach ($sichtbar as $name => $felder) {
            $anzeigewerte = [];
            $offenInGruppe = 0;
            // Auflage 3 (Fixrunde 3, Aufgabe 7, 26.09.2026): ob DIESE Gruppe
            // mindestens einen belegten Datei-Wert hat -- gebraucht, um die
            // Zeile geradezuziehen (siehe unten).
            $hatDateiWert = false;
            foreach ($felder as $schluessel => $meta) {
                // I1 (Fixrunde 1, Aufgabe 6): Datei-Felder laufen exklusiv
                // ueber die Kacheln (Vollstaendigkeits-Icons) -- sie stehen
                // auch nicht im Blatt (siehe unten). Eine rohe Datei-Id oder
                // ein per Lookup aufgeloester Dateiname waere in der
                // Gruppenzeile nur Rauschen und doppelt gemoppelt (Fund: die
                // Ausweis-Zeile lautete "01.01.2032 · 5002 · 5003 · 5004").
                // Der "offen"-Zaehler zaehlt ein fehlendes Datei-Feld
                // trotzdem mit -- ein fehlendes Selfie bleibt eine offene
                // Aufgabe, nur eine, die nicht in der Zeile auftaucht.
                if (($meta['type'] ?? 'text') !== 'file') {
                    $anzeigewerte[$schluessel] = $this->anzeigewert($employee, $schluessel, $meta);
                } elseif (($datensatz[$schluessel] ?? null) !== null) {
                    $hatDateiWert = true;
                }
                if (PortalFieldRelevance::istRelevant($meta, $datensatz)) {
                    $wert = $datensatz[$schluessel] ?? null;
                    if ($wert === null || $wert === '' || $wert === []) {
                        $offenInGruppe++;
                    }
                }
            }

            // Auflage 3 (Fixrunde 3, Aufgabe 7, 26.09.2026): eine Gruppe wie
            // "Ausweis" kann alle drei Fotos haben und trotzdem nur das
            // Gueltigkeitsdatum vermissen -- I1 nimmt Datei-Felder bewusst
            // aus der Zeile heraus, wodurch $anzeigewerte dann leer bleibt
            // und PortalGroupSummary::zeile() die Leer-Meldung sagt, obwohl
            // direkt darueber drei gruene Kacheln stehen. Der "offen"-
            // Zaehler zaehlt weiterhin exakt (I1), NUR die Wortwahl der
            // leeren Zeile wird hier korrigiert, wenn tatsaechlich ein
            // Datei-Wert da ist.
            //
            // Fixrunde 1 (Befund 2): istLeer() statt eines eigenen
            // Zeichenketten-Vergleichs gegen den Wortlaut -- sonst waere das
            // eine geratene Kopplung an PortalGroupSummary::zeile(), die
            // still auseinanderfaellt, sobald dort jemand den Satz aendert.
            $zeile = PortalGroupSummary::zeile($felder, $anzeigewerte);
            if (PortalGroupSummary::istLeer($zeile) && $hatDateiWert) {
                $zeile = 'Nachweise liegen vor';
            }

            $profilGruppen[$name] = [
                'felder' => $felder,
                'zeile'  => $zeile,
                'offen'  => $offenInGruppe,
            ];
        }

        // R28: nur relevante Felder zaehlen -- sonst haengt jeder EU-Buerger
        // dauerhaft unter 100 %, weil die Non-EU-Gruppe (fuer ihn gar nicht
        // vorhanden) mitgezaehlt wuerde. sichtbareFelderFlach() liefert die
        // Non-EU-Gruppe fuer ihn ohnehin gar nicht erst mit.
        $flach = PortalFieldAccess::sichtbareFelderFlach($gruppen, $datensatz, []);
        $stand = PortalCompleteness::stand($flach, $datensatz);

        $profilFelder = [];
        $hinweis = null;
        if ($this->profilGruppe !== null && array_key_exists($this->profilGruppe, $sichtbar)) {
            $hinweis = PortalSectionHints::fuer($this->profilGruppe, $this->duzen);

            // C2 (Critical, Fixrunde 1 zu Aufgabe 6): NUR fuer die OFFENE
            // Gruppe zaehlt der FORMULARSTAND ($this->profilWerte), nicht
            // nur der Datensatz -- alle anderen Gruppen bleiben oben gegen
            // den Datensatz (siehe Kommentar dort, unveraendert). Ohne
            // diesen Rueckfall blieb ein visible_if-Feld wie other_employer
            // unsichtbar, waehrend der Mensch GERADE per Live-Auswahl
            // (is_main_employer hat 'live' => true) von "ja" auf "nein"
            // umstellt: der Formularwert ist schon '0', der Datensatz noch
            // true, also verschwand das Namensfeld nie -- "Nein" war damit
            // unerreichbar, obwohl gespeichert schon lange nicht mehr
            // blockiert (C1). Genau daran haengt die Korrektur zu
            // Steuerklasse VI.
            $offeneGruppeSichtbar = PortalFieldAccess::sichtbareGruppen(
                [$this->profilGruppe => $gruppen[$this->profilGruppe]],
                $datensatz,
                $this->profilWerte,
            )[$this->profilGruppe] ?? [];

            // Auflage 4 (Fixrunde 3, Aufgabe 7, 26.09.2026): dieselbe
            // Reichweite wie bei C2 oben, nur fuer die PFLICHT statt fuer
            // die SICHTBARKEIT. Ohne diesen Rueckfall sah other_employer
            // zwar sichtbar aus (C2), bekam aber nie den roten Rand: sein
            // required_if wurde noch gegen den ALTEN Datensatz (is_main_
            // employer=true) geprueft, waehrend der Mensch GERADE per
            // Live-Auswahl auf "nein" umstellt -- die Pflicht wurde erst
            // beim geblockten Speichern sichtbar, nie vorher. Datensatz
            // bleibt fuer alle ANDEREN Gruppen unangetastet (siehe C2).
            $datensatzOffeneGruppe = $datensatz;
            foreach ($gruppen[$this->profilGruppe] as $schluessel => $meta) {
                if (!array_key_exists($schluessel, $this->profilWerte)) {
                    continue;
                }
                $roh = $this->profilWerte[$schluessel];
                if (trim((string) $roh) === '') {
                    continue;   // leerer Formularwert ist keine Aussage (E16, wie istSichtbar)
                }
                $datensatzOffeneGruppe[$schluessel] = ($meta['type'] ?? 'text') === 'bool'
                    ? PortalBoolValue::parse($roh)
                    : $roh;
            }

            foreach ($offeneGruppeSichtbar as $schluessel => $meta) {
                if (($meta['type'] ?? 'text') === 'file') {
                    continue;   // Dateien laufen ueber das Nachweis-Blatt (R20/E8)
                }
                $fehlt = PortalFieldRelevance::istRelevant($meta, $datensatzOffeneGruppe)
                    && trim((string) ($this->profilWerte[$schluessel] ?? '')) === '';
                $profilFelder[$schluessel] = [
                    'type'      => $meta['type'] ?? 'text',
                    'label'     => $meta['label'] ?? $schluessel,
                    'lookup'    => $meta['lookup'] ?? null,
                    'options'   => $meta['options'] ?? null,
                    'maxlength' => $meta['maxlength'] ?? null,
                    'live'      => (bool) ($meta['live'] ?? false),
                    'fehlt'     => $fehlt,
                ];
            }
        }

        // Kacheln: je Datei-Feld der sichtbaren Gruppen eine Nachweis-Kachel.
        // Doppelte Codes (Vorder-/Rueckseite derselben Art, z. B. Ausweis)
        // werden zusammengefasst -- "da" ist wahr, sobald mindestens eine der
        // Altspalten belegt ist. ProofTypes ist die einzige Zuordnungsstelle
        // (E7-Lehre), keine zweite Liste hier.
        $kachelnDa = [];
        $kachelnReihenfolge = [];
        foreach ($sichtbar as $felder) {
            foreach ($felder as $schluessel => $meta) {
                if (($meta['type'] ?? 'text') !== 'file') {
                    continue;
                }
                $code = ProofTypes::codeForLegacyColumn($schluessel);
                if ($code === null) {
                    continue;
                }
                if (!array_key_exists($code, $kachelnDa)) {
                    $kachelnReihenfolge[] = $code;
                    $kachelnDa[$code] = false;
                }
                if ($employee->getAttribute($schluessel) !== null) {
                    $kachelnDa[$code] = true;
                }
            }
        }
        $kacheln = array_map(
            static fn (string $code) => ['code' => $code, 'label' => ProofTypes::label($code), 'da' => $kachelnDa[$code]],
            $kachelnReihenfolge,
        );

        return [
            'gruppen'  => $profilGruppen,
            'stand'    => $stand,
            'felder'   => $profilFelder,
            'hinweis'  => $hinweis,
            'nurLesen' => $employee->readOnlyDisplayFields(),
            'kacheln'  => $kacheln,
        ];
    }

    /**
     * Die GECASTETEN Attributwerte aller Felder aus $gruppen, gelesen mit
     * getAttribute() (nicht getAttributes()) -- der Unterschied ist
     * tragend: visible_if/required_if vergleichen strikt gegen
     * true/false/null, die Rohwerte aus der Datenbank waeren 1/0/null
     * (R27, R28).
     *
     * @param array<string, array<string, array<string,mixed>>> $gruppen
     * @return array<string,mixed>
     */
    private function datensatzWerte(RecEmployee $employee, array $gruppen): array
    {
        $werte = [];
        foreach ($gruppen as $felder) {
            foreach (array_keys($felder) as $schluessel) {
                $werte[$schluessel] = $employee->getAttribute($schluessel);
            }
        }

        return $werte;
    }

    /**
     * Formularwert fuer ein einzelnes Feld beim Oeffnen eines Blatts --
     * woertlich wie EmployeePortal::loadFieldValues() (Zeilen 232-249):
     * Datum wird zu 'Y-m-d', bool zu '1'/'0', null zu ''.
     */
    private function formularwert(RecEmployee $employee, string $feld): string
    {
        $roh = $employee->getAttribute($feld);
        if ($roh instanceof \DateTimeInterface) {
            $roh = $roh->format('Y-m-d');
        } elseif (is_bool($roh)) {
            $roh = $roh ? '1' : '0';
        }

        return $roh === null ? '' : (string) $roh;
    }

    /**
     * Anzeigewert fuer ein einzelnes Feld (Gruppenzeile, PortalGroupSummary)
     * -- woertlich wie EmployeePortal::formatDisplayValue() (Zeilen 547-559)
     * inklusive des default-Zweigs, in den inline_select faellt (§1.4
     * Punkt 1: Wert und Beschriftung sind dort derselbe String).
     *
     * OHNE 'file'-Zweig, ANDERS als EmployeePortal::formatDisplayValue()
     * (Entscheidung, Fixrunde 2 zu Aufgabe 6, 26.09.2026): der einzige
     * Aufrufer (profilDaten(), Gruppenzeile) ruft diese Methode seit I1 gar
     * nicht mehr fuer Datei-Felder auf -- sie laufen exklusiv ueber die
     * Kacheln (Vollstaendigkeits-Icons), eine zweite Erscheinung in der Zeile
     * waere Rauschen (die Kacheln zeigen ohnehin, ob eine Datei da ist) und
     * ein Dateiname-Lookup pro Render zusaetzliche Abfragen (allein
     * "Ausweis" drei Datei-Felder). Ein 'file'-Zweig ohne erreichbaren
     * Aufrufer waere Ballast mit einem Test, der nur sich selbst pinnt --
     * deshalb hier bewusst NICHT nachgebaut. Faellt ein Datei-Feld doch
     * durch, landet es im default-Zweig (rohe Id) -- das darf nicht
     * passieren, siehe der Ausschluss in profilDaten().
     */
    private function anzeigewert(RecEmployee $employee, string $feld, array $meta): string
    {
        $wert = $employee->getAttribute($feld);
        if ($wert === null || $wert === '' || $wert === []) {
            return '';
        }

        $typ = $meta['type'] ?? 'text';

        return match ($typ) {
            'bool'   => $wert ? 'Ja' : 'Nein',
            'lookup' => $this->lookupOptionen($meta['lookup'] ?? '')[(string) $wert] ?? (string) $wert,
            'date'   => $this->anzeigedatum($wert),
            default  => (string) $wert,
        };
    }

    private function anzeigedatum($wert): string
    {
        try {
            if (is_object($wert) && method_exists($wert, 'format')) {
                return $wert->format('d.m.Y');
            }

            return \Carbon\Carbon::parse((string) $wert)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $wert;
        }
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
