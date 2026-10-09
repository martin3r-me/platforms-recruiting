<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\CoreLookup;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\ProofReader;
use Platform\Recruiting\Services\ReissueContractService;
use Platform\Recruiting\Services\VertragAusAkte;
use Platform\Recruiting\Services\VertragsAngaben;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;
use Platform\Recruiting\Services\Zas\ZasEmployeeContactLinker;
use Platform\Recruiting\Support\DokumentAkteZeilen;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\FirstAiderDateGuard;
use Platform\Recruiting\Support\ProofTypes;
use Platform\Recruiting\Support\VertragsAnzeige;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\VertragsVorbelegung;
use Platform\Recruiting\Support\VorlagenMerkmale;
use Platform\Recruiting\Support\YmdDate;
use Platform\Recruiting\Support\ZuschlagWert;

/**
 * HR-Backend Detail-Edit-View fuer einen RecEmployee.
 *
 * Anders als das MA-Portal: HR sieht UND editiert ALLE Felder, auch
 * die im Portal Login-stabilen (first_name, last_name, birth_date,
 * identity_card_number). Plus Lifecycle-Felder + Legal-Status +
 * (spaeter) HR-only-Felder aus rec_employee_hr_data.
 */
class Show extends Component
{
    use WithFileUploads;

    public int $employeeId;
    public array $fieldValues = [];
    public array $hrFieldValues = [];
    public ?string $flash = null;
    public ?string $flashError = null;

    /** Kurzform "Dokument bereitstellen" in der Akte (Spec Dokumente §3.2). */
    public $dokumentDatei = null;
    public string $dokumentTitel = '';
    public string $dokumentKategorie = 'other';
    public string $dokumentAktion = 'none';

    // Vertrag neu ausstellen (siehe reissueContract())
    public bool $reissueModalShow = false;
    public ?int $reissueContractId = null;
    // Nullable, nicht `string`: ein geleertes Input (besonders type=date)
    // schickt null, und Livewire wuerde das in eine getypte string-Property
    // schreiben wollen — TypeError beim Hydrieren, mitten im Dialog.
    public ?string $reissueZuschlag = '';
    public ?string $reissueBeginn = '';
    public ?string $reissueEnde = '';
    public string $reissueReason = ReissueContractService::REASON_CORRECTION;
    public ?string $reissueNote = '';
    // Steuert nur die Anzeige (Text + Grund-Auswahl). Welcher Weg wirklich
    // laeuft, entscheidet reissueContract() am Vertrag selbst — sonst koennte
    // ein manipuliertes Property den falschen Zweig waehlen.
    public bool $reissueOpenMode = false;

    // Vertrag aus der Akte (Spec 2026-10-09 §2.1). Strings, nicht int/Datum:
    // ein geleertes Select/type=date schickt null bzw. '' — eine getypte
    // int-Property wuerde beim Hydrieren mit TypeError abbrechen, und an
    // einen Datums-Cast wird nie gebunden.
    public bool $vertragModalShow = false;
    public ?string $vertragVorlageId = '';
    public ?string $vertragBeginn = '';
    public ?string $vertragEnde = '';
    public ?string $vertragZuschlag = '';

    // File-Upload-Properties (separat, eine pro File-Field)
    public $uploadIdentityFront = null;
    public $uploadIdentityBack = null;
    public $uploadSelfie = null;
    public $uploadHealthInsuranceCard = null;
    public $uploadNationalpass = null;
    public $uploadAufenthaltstitelFront = null;
    public $uploadAufenthaltstitelBack = null;
    public $uploadVisumsblatt = null;
    public $uploadZusatzblatt = null;
    public $uploadZusatzblattBack = null;
    public $uploadImmatrikulation = null;
    public $uploadSchulbescheinigung = null;
    public $uploadFiktionFront = null;
    public $uploadFiktionBack = null;
    public $uploadErstbescheinigung = null;
    public $uploadFirstAiderCertificate = null;

    private const FILE_UPLOAD_MAP = [
        'identity_card_front_file_id'   => 'uploadIdentityFront',
        'identity_card_back_file_id'    => 'uploadIdentityBack',
        'selfie_file_id'                => 'uploadSelfie',
        'health_insurance_card_file_id' => 'uploadHealthInsuranceCard',
        'nationalpass_file_id'          => 'uploadNationalpass',
        'aufenthaltstitel_front_file_id' => 'uploadAufenthaltstitelFront',
        'aufenthaltstitel_back_file_id'  => 'uploadAufenthaltstitelBack',
        'visumsblatt_file_id'           => 'uploadVisumsblatt',
        'zusatzblatt_file_id'           => 'uploadZusatzblatt',
        'zusatzblatt_back_file_id'      => 'uploadZusatzblattBack',
        'immatrikulation_file_id'       => 'uploadImmatrikulation',
        'schulbescheinigung_file_id'    => 'uploadSchulbescheinigung',
        'fiktionsbescheinigung_front_file_id' => 'uploadFiktionFront',
        'fiktionsbescheinigung_back_file_id'  => 'uploadFiktionBack',
        'erstbescheinigung_file_id'           => 'uploadErstbescheinigung',
        'first_aider_certificate_file_id'     => 'uploadFirstAiderCertificate',
    ];

    // ---- CRM-Zuordnung (Kunde 07.09.): fehlt der Kontakt-Link, bietet die Akte
    // den Zuordnen-Dialog an — Kandidaten + Empfehlung liefert der Linker,
    // geschrieben wird ueber dessen execute() (gleiche Regeln wie der Automat).
    public bool $showContactAssignModal = false;

    /** Dispo-Taetigkeiten (aus ZAS, Kunde 15.09.) — nur Anzeige, gepflegt wird in ZAS. */
    public bool $showTaetigkeitenModal = false;

    public string $taetigkeitenSuche = '';

    /**
     * Reiter der Akte (Kunde 26.09.): 'stammdaten' | 'dispo'. In der URL, damit
     * Browser-Zurueck und geteilte Links den Reiter behalten. Die Einsaetze als
     * eigene Ansicht, weil die Akte sonst endlos lang wird.
     */
    #[\Livewire\Attributes\Url]
    public string $tab = 'stammdaten';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['stammdaten', 'dispo'], true) ? $tab : 'stammdaten';
    }

    /**
     * Disponierte Einsaetze dieser Person (Kunde 26.09.) — kommende zuerst,
     * Vergangene begrenzt.
     *
     * Ueber die GANZE Person, nicht nur ueber diesen Datensatz: wer als RG- und
     * MA-Nummer gefuehrt wird, ist ueber die eine oder die andere disponiert
     * (Fall Woettki 26.09.). Quellen: die Dispo-Identitaetsgruppe (CRM-Kontakt,
     * wie auf der VA-Seite) UND der person_key-Marker.
     *
     * @return array{upcoming: list<array<string,mixed>>, past: list<array<string,mixed>>, past_total: int, total: int}
     */
    #[Computed]
    public function dispoAssignments(): array
    {
        $employee = $this->employee;
        if ($employee === null) {
            return ['upcoming' => [], 'past' => [], 'past_total' => 0, 'total' => 0];
        }

        $ids = $this->personRecordIds((int) $employee->id, $employee->person_key);

        $rows = \Platform\Recruiting\Models\RecDispoAssignment::query()
            ->with('event:id,einsatz_ref,name,filiale,filial_nr')
            ->whereIn('rec_employee_id', $ids)
            ->orderBy('datum')
            ->orderBy('von')
            ->get();

        return \Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeAssignments::split($rows, now()->toDateString());
    }

    /**
     * Nur die Zahl fuer den Reiter — die volle Liste wird erst geladen, wenn
     * der Reiter offen ist.
     */
    #[Computed]
    public function dispoAssignmentCount(): int
    {
        $employee = $this->employee;
        if ($employee === null) {
            return 0;
        }

        return \Platform\Recruiting\Models\RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $this->personRecordIds((int) $employee->id, $employee->person_key))
            ->count();
    }

    /**
     * Alle Datensaetze DERSELBEN Person (RG + MA). Faellt auf den eigenen
     * Datensatz zurueck, wenn weder Gruppe noch Marker etwas hergeben.
     *
     * @return list<int>
     */
    /** @var array<string, list<int>> Cache je Anfrage (Reiter-Zahl + Liste fragen dasselbe). */
    private array $personRecordIdsCache = [];

    private function personRecordIds(int $employeeId, ?string $personKey): array
    {
        $cacheKey = $employeeId . '|' . ($personKey ?? '');
        if (isset($this->personRecordIdsCache[$cacheKey])) {
            return $this->personRecordIdsCache[$cacheKey];
        }

        $ids = [$employeeId];

        try {
            $groups = app(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class)->groupsFor([$employeeId]);
            foreach ($groups as $group) {
                foreach ($group as $id) {
                    $ids[] = (int) $id;
                }
            }
        } catch (\Throwable $e) {
            // Eine Stoerung der CRM-Aufloesung darf die Akte nicht abschiessen.
            \Illuminate\Support\Facades\Log::warning('employee_dispo_identity_failed', [
                'employee_id' => $employeeId, 'error' => $e->getMessage(),
            ]);
        }

        if ($personKey !== null && $personKey !== '') {
            foreach (RecEmployee::query()->where('person_key', $personKey)->pluck('id') as $id) {
                $ids[] = (int) $id;
            }
        }

        return $this->personRecordIdsCache[$cacheKey] = array_values(array_unique($ids));
    }

    /** @return array{values: list<string>, synced_at: ?string} */
    #[Computed]
    public function dispoTaetigkeiten(): array
    {
        $hr = $this->employee?->hrData;
        $values = (array) ($hr?->dispo_taetigkeiten ?? []);
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'values'    => array_values(array_map('strval', $values)),
            'synced_at' => $hr?->dispo_taetigkeiten_synced_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Nachweis-Uebersicht dieser Person (ueber ALLE ihre Anstellungen, siehe
     * ProofReader). Reine Anzeige.
     *
     * Korrektur K3 (24.09.2026): es gibt KEINE Bestaetigung mit Wirkung mehr,
     * weder hier noch in der HR-Inbox — ProofInbox::bestaetige() ist entfernt
     * (kein Konsument von confirmed_at, keine Einsatzsperre fuer Mitarbeiter).
     * needs_confirmation/confirmed_at/confirmed_by bleiben trotzdem in der
     * Rueckgabe: die Anzeige "bestaetigt von X am Y" in der Akte zeigt
     * weiterhin echten Altbestand, falls je etwas bestaetigt wurde.
     *
     * @return list<array{code:string, label:string, status:string, valid_until:?string, offen:bool, needs_confirmation:bool, confirmed_at:?string, confirmed_by:?string}>
     */
    #[Computed]
    public function nachweisUebersicht(): array
    {
        $emp = $this->employee();
        if ($emp === null) {
            return [];
        }

        $reader = app(ProofReader::class);
        $vorhanden = $reader->current($emp)->load('confirmedByUser')->keyBy('proof_type_code');

        return array_map(function (array $zeile) use ($vorhanden) {
            $proof = $vorhanden->get($zeile['code']);
            $zeile['needs_confirmation'] = ProofTypes::needsHrConfirmation($zeile['code']);
            $zeile['confirmed_at'] = $proof?->confirmed_at?->format('d.m.Y H:i');
            // Wozu wir confirmed_by_user_id speichern: in der Akte nennen,
            // nicht nur im Feld ablegen und nie wieder anschauen.
            $zeile['confirmed_by'] = $proof?->confirmedByUser?->name;
            return $zeile;
        }, $reader->checklist($emp));
    }

    /**
     * Zugewiesenes (`$hat`) und der ungefilterte Katalog (`$alle`), beide mit
     * kleingeschriebenem Schluessel. Die Suche greift hier bewusst NICHT.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function dispoTaetigkeitenBasis(): array
    {
        $hat = [];
        foreach ((array) ($this->employee?->hrData?->dispo_taetigkeiten ?? []) as $label) {
            $hat[mb_strtolower((string) $label)] = (string) $label;
        }

        $alle = $hat;
        $lookupId = DB::table('core_lookups')
            ->where('team_id', (int) $this->employee?->team_id)
            ->where('name', ZasDispoTaetigkeitSync::LOOKUP)
            ->value('id');
        if ($lookupId !== null) {
            foreach (DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value') as $value) {
                $alle[mb_strtolower((string) $value)] ??= (string) $value;
            }
        }

        return [$hat, $alle];
    }

    /** Wie viele Taetigkeiten ZAS insgesamt kennt — unabhaengig vom Suchtext. */
    #[Computed]
    public function dispoTaetigkeitenGesamt(): int
    {
        return count($this->dispoTaetigkeitenBasis()[1]);
    }

    public function openTaetigkeiten(): void
    {
        $this->taetigkeitenSuche = '';
        $this->showTaetigkeitenModal = true;
    }

    public function closeTaetigkeiten(): void
    {
        $this->showTaetigkeitenModal = false;
        $this->taetigkeitenSuche = '';
    }

    /**
     * Der ganze ZAS-Katalog mit Haken — damit die Frage "kann der Logistik?"
     * beantwortbar ist und nicht nur "was macht er ueblicherweise". Die Liste
     * kommt aus der Auswahlliste `dispo_taetigkeit`, die der Webexport bei
     * jeder Lieferung mit dem vollen Katalog fuettert.
     *
     * Zugewiesenes steht vorn und verschwindet nie, auch wenn die Auswahlliste
     * den Wert (noch) nicht kennt.
     *
     * @return list<array{label:string, hat:bool}>
     */
    #[Computed]
    public function dispoTaetigkeitenKatalog(): array
    {
        [$hat, $alle] = $this->dispoTaetigkeitenBasis();

        $suche = mb_strtolower(trim($this->taetigkeitenSuche));

        $out = [];
        foreach ($alle as $key => $label) {
            if ($suche !== '' && !str_contains($key, $suche)) {
                continue;
            }
            $out[] = ['label' => $label, 'hat' => isset($hat[$key])];
        }

        usort($out, function (array $a, array $b) {
            if ($a['hat'] !== $b['hat']) {
                return $a['hat'] ? -1 : 1;
            }
            return strnatcasecmp($a['label'], $b['label']);
        });

        return $out;
    }

    #[Computed]
    public function crmLinkMissing(): bool
    {
        return $this->employee !== null && !$this->employee->crmContactLinks()->exists();
    }

    /** @return list<array{id:int, name:string}> verknuepfte CRM-Kontakte (Anzeige in der Akte) */
    #[Computed]
    public function linkedContacts(): array
    {
        if ($this->employee === null) {
            return [];
        }

        return $this->employee->crmContactLinks()->with('contact')->get()
            ->map(fn ($l) => [
                'id'   => (int) $l->contact_id,
                'name' => trim((string) ($l->contact->first_name ?? '') . ' ' . (string) ($l->contact->last_name ?? '')) ?: ('Kontakt #' . $l->contact_id),
            ])->values()->all();
    }

    #[Computed]
    public function contactCandidates(): array
    {
        if (!$this->showContactAssignModal || $this->employee === null) {
            return [];
        }

        return app(ZasEmployeeContactLinker::class)->candidates($this->employee);
    }

    public function assignContact(int $contactId): void
    {
        $emp = $this->employee;
        if ($emp === null || !$this->crmLinkMissing) {
            return;
        }
        // Riegel: nur Kontakte aus der eigenen Kandidatenliste — kein freies Verknuepfen per id.
        if (!collect(app(ZasEmployeeContactLinker::class)->candidates($emp))->contains('id', $contactId)) {
            return;
        }

        app(ZasEmployeeContactLinker::class)->execute($emp, ['action' => 'link', 'contact_id' => $contactId], auth()->id());
        $this->showContactAssignModal = false;
        unset($this->employee, $this->crmLinkMissing, $this->contactCandidates);
    }

    public function createContactForEmployee(): void
    {
        $emp = $this->employee;
        if ($emp === null || !$this->crmLinkMissing) {
            return;
        }
        $email = mb_strtolower(trim((string) $emp->email));
        $phone = trim((string) $emp->phone);

        app(ZasEmployeeContactLinker::class)->execute($emp, [
            'action' => 'create',
            'email'  => $email !== '' ? $email : null,
            'phone'  => $phone !== '' ? $phone : null,
        ], auth()->id());
        $this->showContactAssignModal = false;
        unset($this->employee, $this->crmLinkMissing, $this->contactCandidates);
    }

    public function mount(int $employee): void
    {
        $this->employeeId = $employee;
        $emp = $this->employee();
        if ($emp) {
            $this->loadFieldValues($emp);

            // Vom HR-Schreibtisch (Fall "Vertrag fehlt fuer Einsatz"): Fenster
            // gleich offen, vorbelegt aus dem Einsatztag (Spec §3.3).
            if (request()->query('vertrag') === 'neu') {
                $einsatz = request()->query('einsatz');
                $this->openVertragModal(is_string($einsatz) ? $einsatz : null);
            }
        }
    }

    #[Computed]
    public function employee(): ?RecEmployee
    {
        return RecEmployee::with(['position', 'applicant', 'contracts.contractTemplate'])
            ->where('team_id', auth()->user()->currentTeam->id)
            ->find($this->employeeId);
    }

    /**
     * AKTIVITAETEN der MA-Akte (21.09.2026). Das Protokoll haengt an der
     * BEWERBUNG — HR landet aber von ueberall hier, und diese Seite zeigte von
     * der Vorgeschichte bisher nichts. Aufgefallen an der Klaerungs-Historie:
     * der Namenslink der Schulungs-Detailansicht fuehrt fuer genau diese
     * Menschen hierher (sie tragen eine Personalnummer), die Eintraege standen
     * aber eine Seite weiter.
     *
     * Ohne verknuepfte Bewerbung (die aus dem ZAS importierten Mitarbeiter)
     * bleibt die Liste LEER — es gibt bei uns schlicht keine Vorgeschichte.
     * Dieselbe Quelle und dieselbe Begrenzung wie in der Bewerberakte.
     */
    #[Computed]
    public function autoPilotLogs()
    {
        $applicant = $this->employee()?->applicant;
        if ($applicant === null) {
            return collect();
        }

        return $applicant->autoPilotLogs()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * Unterschriebene Vertraege DIESER Anstellung (Spec Vertrag an der
     * Anstellung §3.4) — der Token gehoert dem Menschen, die Menge der
     * Anstellung. Kein Rueckfall auf den Bewerber: der zeigte bei zwei
     * Anstellungen den RG-Vertrag in der MA-Akte.
     *
     * PDF-Download fuer HR im Backend. Wir nutzen den Applicant-Token, weil
     * der ContractPdfController via CorePublicFormLink validiert.
     */
    #[Computed]
    public function signedContracts(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }
        $applicantToken = null;

        return $emp->contracts
            ->filter(fn ($c) => $c->status === 'completed' && $c->signed_at)
            ->map(function ($c) use ($emp, &$applicantToken) {
                $code = $c->contractTemplate?->code;
                // EINE Stelle fuer den Anzeigenamen (Portal, VertragLeser, Akte).
                $displayName = VertragsAnzeige::name($code, $c->contractTemplate?->name);
                $isAv = $code !== null && (str_starts_with($code, 'AV-') || $code === 'AV');
                $ausBewerbung = $c->rec_applicant_id !== null && $emp->applicant !== null
                    && (int) $c->rec_applicant_id === (int) $emp->applicant->id;
                if ($ausBewerbung) {
                    // Alter Weg: ContractPdfController validiert ueber den Bewerber-Token.
                    $applicantToken ??= $emp->applicant->getOrCreatePublicFormLink()->token;
                    $pdfUrl = route('recruiting.public.contract-pdf', ['token' => $applicantToken, 'contractId' => $c->id]);
                } else {
                    $pdfUrl = route('recruiting.employees.vertrag-pdf', ['contractId' => $c->id]);
                }

                return [
                    'id'            => $c->id,
                    'display_name'  => $displayName,
                    'merkmale'      => VorlagenMerkmale::zeile($c->contractTemplate?->company, $c->contractTemplate?->taetigkeit),
                    'signed_at'     => $c->signed_at,
                    'pdf_url'       => $pdfUrl,
                    'superseded_by' => $c->superseded_by_contract_id,
                    // Ersetzen gilt nur fuer Arbeitsvertraege, nur einmal und
                    // nur mit Bewerbung (ReissueContractService braucht sie).
                    'can_reissue'   => $isAv && $c->superseded_by_contract_id === null && $c->rec_applicant_id !== null,
                ] + $this->laufzeitUndZuschlag($c);
            })
            ->values()
            ->toArray();
    }

    /**
     * Noch nicht unterschriebene Vertraege DIESER Anstellung (Spec Vertrag an
     * der Anstellung §3.4 — kein Rueckfall auf den Bewerber, siehe
     * signedContracts()), mit Signaturlink. Ohne diese Liste waere der
     * Link aus dem Ersetzen-Dialog nach dem naechsten Seitenaufbau verloren,
     * und HR muesste in die Bewerber-Akte wechseln, um ihn erneut zu erzeugen.
     *
     * Liest den Token NUR, wenn es schon einen gibt — getOrCreatePublicFormLink()
     * gehoert nicht in einen Lesepfad, der bei jedem Seitenaufbau laeuft.
     * Vertraege aus dem Ersetzen-Dialog und aus dem regulaeren Versand haben
     * ihren Link bereits; alles andere zeigt schlicht keinen an.
     */
    #[Computed]
    public function openContracts(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }

        return $emp->contracts
            ->filter(fn ($c) => !in_array($c->status, ['completed', 'cancelled'], true))
            ->map(function ($c) {
                $code = $c->contractTemplate?->code;

                return [
                    'id'           => $c->id,
                    'display_name' => VertragsAnzeige::name($code, $c->contractTemplate?->name),
                    'merkmale'     => VorlagenMerkmale::zeile($c->contractTemplate?->company, $c->contractTemplate?->taetigkeit),
                    'code'         => $code,
                    'status'       => $c->status,
                    'sent_at'      => $c->sent_at,
                    'can_reissue'  => $code !== null && (str_starts_with($code, 'AV-') || $code === 'AV') && $c->rec_applicant_id !== null,
                    'can_cancel'   => true,
                    'sign_url'     => $c->publicFormLink
                        ? route('recruiting.public.contract-signing', ['token' => $c->publicFormLink->token])
                        : null,
                ] + $this->laufzeitUndZuschlag($c);
            })
            ->values()
            ->toArray();
    }

    /**
     * Beginn, Ende, Zuschlag aus den Vertrags-Extrafeldern (Spec §2.7).
     * Alt-AV ohne Zuschlagsfeld: aus dem Code (AV-060 → 0,60).
     *
     * @return array{beginn:?string, ende:?string, zuschlag:?string}
     */
    private function laufzeitUndZuschlag(RecContract $c): array
    {
        $beginn = VertragsDeckung::datum($c->getExtraField('vertragsbeginn'));
        $ende = VertragsDeckung::datum($c->getExtraField('vertragsende'));
        $zuschlag = ZuschlagWert::lesen($c->getExtraField('zuschlag')) ?? ZuschlagWert::ausAvCode($c->contractTemplate?->code);

        return [
            'beginn'   => $beginn !== null ? \Carbon\Carbon::parse($beginn)->format('d.m.Y') : null,
            'ende'     => $ende !== null ? \Carbon\Carbon::parse($ende)->format('d.m.Y') : null,
            'zuschlag' => $zuschlag !== null ? ZuschlagWert::format($zuschlag) : null,
        ];
    }

    /**
     * HR-Entsperrung des MA-Portal-Zugangs (Eskalations-Stufe-3-Sperre,
     * siehe DispoEmployeeGateway::lockPortal). Leert Zeitpunkt + Grund —
     * serverseitig, nur ueber diese HR-Sicht erreichbar. Kein Fehler bei
     * einem bereits entsperrten MA (idempotent, wie das Setzen selbst).
     */
    public function unlockPortal(): void
    {
        $employee = $this->employee();
        if (!$employee) {
            $this->flashError = 'Mitarbeiter nicht gefunden.';
            return;
        }

        // Eskalation sperrt alle Datensaetze derselben Person (siehe DispoEscalateCommand Stufe 3 / DispoEmployeeGateway::lockPortal) -
        // die Entsperrung muss dieselbe Gruppe wieder freigeben, sonst bleibt
        // die verknuepfte Personalnummer stumm gesperrt liegen.
        $ids = app(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class)->groupFor((int) $employee->id);
        RecEmployee::query()->whereIn('id', $ids)->update([
            'portal_locked_at' => null,
            'portal_locked_reason' => null,
        ]);

        $this->flash = count($ids) > 1
            ? 'Portalzugang entsperrt — auch fuer die verknuepften Personalnummern derselben Person (' . (count($ids) - 1) . ').'
            : 'Portalzugang entsperrt.';
        $this->flashError = null;
        unset($this->employee);
    }

    public function openReissueModal(int $contractId): void
    {
        $emp = $this->employee();
        $contract = $emp?->contracts->firstWhere('id', $contractId);
        if (!$contract) {
            $this->flashError = 'Vertrag nicht gefunden.';
            return;
        }
        if ($contract->rec_applicant_id === null || $emp->applicant === null) {
            $this->flashError = 'Neu ausstellen geht nur bei Verträgen aus einer Bewerbung — diesen Vertrag stornieren und neu erstellen.';

            return;
        }

        $zuschlag = $emp->applicant->zuschlag;
        $beginn = $contract->getExtraField('vertragsbeginn');
        $ende = $contract->getExtraField('vertragsende');

        $this->reissueContractId = $contractId;
        $this->reissueOpenMode = !$this->isSigned($contract);
        $this->reissueZuschlag = $zuschlag !== null ? number_format((float) $zuschlag, 2, ',', '.') : '';
        $this->reissueBeginn = is_string($beginn) ? $beginn : '';
        $this->reissueEnde = is_string($ende) ? $ende : '';
        $this->reissueNote = '';
        // Vorbelegung nach Wirksamkeit: liegt der Vertragsbeginn in der
        // Zukunft, hat der alte Vertrag nie gewirkt — dann ist es eine
        // Korrektur und das Lohnbuero hat nichts davon. HR kann umschalten.
        $this->reissueReason = $this->beginnIsFuture((string) $this->reissueBeginn)
            ? ReissueContractService::REASON_CORRECTION
            : ReissueContractService::REASON_RAISE;
        $this->reissueModalShow = true;
    }

    public function closeReissueModal(): void
    {
        $this->reissueModalShow = false;
        $this->reissueContractId = null;
        $this->reissueNote = '';
        $this->reissueOpenMode = false;
    }

    private function beginnIsFuture(?string $beginn): bool
    {
        if ($beginn === null || $beginn === '') {
            return false;
        }
        try {
            return \Carbon\Carbon::parse($beginn)->startOfDay()->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ersetzt einen Arbeitsvertrag durch einen neuen mit korrigiertem
     * Zuschlag. Zwei Wege, ein Dialog: ein unterschriebener Vertrag bleibt
     * als Beleg stehen (reissue), ein noch offener wird storniert und sein
     * Signaturlink damit entwertet (reissueOpen). Die Arbeit macht
     * ReissueContractService — hier steht nur Eingabepruefung und
     * Rueckmeldung.
     */
    public function reissueContract(): void
    {
        $this->flash = null;
        $this->flashError = null;

        $emp = $this->employee();
        $contract = $emp?->contracts->firstWhere('id', $this->reissueContractId);
        if (!$contract) {
            $this->flashError = 'Vertrag nicht gefunden.';
            return;
        }

        $raw = trim((string) $this->reissueZuschlag);
        // Gleiche Validierung wie Nachbereitung/HR-Schreibtisch
        // (setDeskZuschlag): Ziffern, optional Komma/Punkt, max 2 Stellen.
        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $raw)) {
            $this->flashError = 'Zuschlag muss eine Zahl sein (z.B. 1,60).';
            return;
        }
        $zuschlag = round((float) str_replace(',', '.', $raw), 2);

        $beginn = (string) $this->reissueBeginn !== '' ? (string) $this->reissueBeginn : null;
        $ende = (string) $this->reissueEnde !== '' ? (string) $this->reissueEnde : null;
        $note = (string) $this->reissueNote !== '' ? trim((string) $this->reissueNote) : null;

        try {
            // Der Vertrag entscheidet, welcher Weg laeuft — nicht das
            // Property aus dem Browser.
            $result = $this->isSigned($contract)
                ? app(ReissueContractService::class)->reissue(
                    $contract, $zuschlag, $this->reissueReason, $beginn, $ende, $note, auth()->id(),
                )
                : app(ReissueContractService::class)->reissueOpen(
                    $contract, $zuschlag, $beginn, $ende, $note, auth()->id(),
                );
        } catch (\Throwable $e) {
            $this->flashError = 'Neu ausstellen fehlgeschlagen: ' . $e->getMessage();
            return;
        }

        $new = $result['contract'];
        // Signaturlink jetzt anlegen — die Liste "Offene Vertraege" liest ihn
        // danach bei jedem Seitenaufbau, ohne selbst Token zu erzeugen.
        $new->getOrCreatePublicFormLink();

        $this->reissueModalShow = false;
        $this->reissueContractId = null;
        $this->reissueOpenMode = false;
        unset($this->employee, $this->signedContracts, $this->openContracts);

        $this->flash = 'Neuer Vertrag #' . $new->id . ' ausgestellt. Signaturlink steht unten unter "Offene Vertraege".'
            . ($this->isSigned($contract)
                ? ''
                : ' Der alte Vertrag ist storniert — sein Signaturlink funktioniert nicht mehr.')
            . ($result['payroll_reported']
                ? ' Die Zuschlagsaenderung steht in den Lohnaenderungen.'
                : '');
    }

    /**
     * Die Vertragsarten dieser Akte (Spec §2.1): aktive Vorlagen vom Typ
     * Vertrag, Code AV-*, Gesellschaft = Gesellschaft der Akte.
     *
     * @return list<array{id:int, label:string}>
     */
    #[Computed]
    public function vertragsVorlagen(): array
    {
        $emp = $this->employee();
        $firma = trim((string) $emp?->company);
        if (!$emp || $firma === '') {
            return [];
        }

        return RecContractTemplate::query()
            ->where('team_id', $emp->team_id)
            ->where('type', RecContractTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->where('code', 'like', 'AV-%')
            ->where('company', $firma)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'taetigkeit'])
            ->map(fn (RecContractTemplate $t) => [
                'id'    => (int) $t->id,
                'label' => $t->name . (trim((string) $t->taetigkeit) !== '' ? ' (' . VorlagenMerkmale::taetigkeit($t->taetigkeit) . ')' : ''),
            ])
            ->values()
            ->all();
    }

    /** $einsatztag (Y-m-d) belegt Beginn/Ende vor — nur Vorbelegung, HR aendert frei. */
    public function openVertragModal(?string $einsatztag = null): void
    {
        $this->flash = null;
        $this->flashError = null;

        $emp = $this->employee();
        if (!$emp) {
            $this->flashError = 'Mitarbeiter nicht gefunden.';

            return;
        }
        if (!$emp->is_active) {
            $this->flashError = 'Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.';

            return;
        }

        $vorbelegung = $einsatztag !== null ? VertragsVorbelegung::fuerEinsatz($einsatztag) : null;
        $vorlagen = $this->vertragsVorlagen();

        $this->vertragVorlageId = count($vorlagen) === 1 ? (string) $vorlagen[0]['id'] : '';
        $this->vertragBeginn = $vorbelegung['beginn'] ?? '';
        $this->vertragEnde = $vorbelegung['ende'] ?? '';
        $this->vertragZuschlag = '';
        $this->vertragModalShow = true;
    }

    public function closeVertragModal(): void
    {
        $this->vertragModalShow = false;
        $this->vertragVorlageId = '';
        $this->vertragBeginn = '';
        $this->vertragEnde = '';
        $this->vertragZuschlag = '';
    }

    /** "Erstellen und senden" — die Arbeit macht VertragAusAkte, hier nur Eingabe und Rueckmeldung. */
    public function vertragErstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;

        $emp = $this->employee();
        if (!$emp) {
            $this->flashError = 'Mitarbeiter nicht gefunden.';

            return;
        }

        $vorlageId = (int) $this->vertragVorlageId;
        $erlaubt = in_array($vorlageId, array_column($this->vertragsVorlagen(), 'id'), true);
        $vorlage = $erlaubt ? RecContractTemplate::query()->where('team_id', $emp->team_id)->find($vorlageId) : null;
        if (!$vorlage) {
            $this->flashError = 'Bitte eine Vertragsart wählen.';

            return;
        }

        $beginn = trim((string) $this->vertragBeginn);
        if (!YmdDate::isValid($beginn)) {
            $this->flashError = 'Bitte einen Vertragsbeginn eintragen.';

            return;
        }
        $ende = trim((string) $this->vertragEnde);

        $zuschlag = ZuschlagWert::ausEingabe($this->vertragZuschlag);
        if ($zuschlag === null) {
            $this->flashError = 'Zuschlag muss eine Zahl sein (z. B. 0,60).';

            return;
        }

        $service = app(VertragAusAkte::class);
        try {
            $vertrag = $service->erstellen($emp, $vorlage, new VertragsAngaben($beginn, $ende !== '' ? $ende : null, $zuschlag), auth()->id());
        } catch (\DomainException $e) {
            $this->flashError = $e->getMessage();

            return;
        }

        $this->closeVertragModal();
        unset($this->employee, $this->signedContracts, $this->openContracts);

        $this->flash = 'Arbeitsvertrag #' . $vertrag->id . ' erstellt — er liegt im Portal zur Unterschrift. '
            . self::hinweisSatz($service->letzterHinweis());
    }

    /** Nur offene Vertraege DIESER Akte (Spec §2.1 "umkehrbar") — der Signaturlink stirbt mit. */
    public function vertragStornieren(int $contractId): void
    {
        $this->flash = null;
        $this->flashError = null;

        $vertrag = $this->employee()?->contracts->firstWhere('id', $contractId);
        if (!$vertrag || in_array($vertrag->status, ['completed', 'cancelled'], true)) {
            $this->flashError = 'Nur offene Verträge dieser Akte lassen sich hier stornieren.';

            return;
        }

        $vertrag->status = 'cancelled';
        $vertrag->notes = trim((string) $vertrag->notes . "\nStorniert in der Mitarbeiterakte am " . now()->format('d.m.Y H:i') . '.');
        $vertrag->save();

        unset($this->employee, $this->signedContracts, $this->openContracts);
        $this->flash = 'Vertrag #' . $vertrag->id . ' storniert — sein Signaturlink funktioniert nicht mehr.';
    }

    private static function hinweisSatz(?string $status): string
    {
        return match ($status) {
            'sent'               => 'Die WhatsApp mit dem Portal-Link ist raus.',
            'altes_portal'       => 'Keine WhatsApp: der Mitarbeiter ist noch nicht auf das neue Portal umgestellt — den Link unten unter „Offene Verträge" kopieren.',
            'no_phone'           => 'Keine WhatsApp: in der Akte steht keine gültige Handynummer.',
            'nicht_konfiguriert' => 'Keine WhatsApp: in den Einstellungen ist kein Template für Verträge oder Dokumente hinterlegt.',
            'vorlage_untauglich' => 'Keine WhatsApp: das hinterlegte Template passt nicht (Portal-Knopf fehlt).',
            default              => 'Die WhatsApp konnte nicht verschickt werden — den Link unten unter „Offene Verträge" kopieren.',
        };
    }

    public function updatedDokumentKategorie(): void
    {
        $this->dokumentAktion = DokumentKategorie::exists($this->dokumentKategorie)
            ? DokumentKategorie::defaultAktion($this->dokumentKategorie)
            : DokumentKategorie::AKTION_NONE;
    }

    /** Titel aus dem Dateinamen vorbelegen, solange HR nichts getippt hat. */
    public function updatedDokumentDatei(): void
    {
        if ($this->dokumentTitel === '' && $this->dokumentDatei) {
            $this->dokumentTitel = (string) pathinfo($this->dokumentDatei->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    public function dokumentBereitstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;
        $emp = $this->employee();
        if (!$emp) {
            return;
        }
        if (!$this->dokumentDatei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }

        try {
            $ergebnis = app(DokumentService::class)->bereitstellen(
                (int) $emp->team_id,
                ['title' => $this->dokumentTitel, 'category' => $this->dokumentKategorie, 'action' => $this->dokumentAktion],
                (string) file_get_contents($this->dokumentDatei->getRealPath()),
                (string) $this->dokumentDatei->getClientOriginalName(),
                [(int) $emp->id],
                null,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $e) {
            $this->flashError = $e->getMessage();

            return;
        }

        DokumentHinweiseVersenden::starten($ergebnis['dokument']);

        $this->dokumentDatei = null;
        $this->dokumentTitel = '';
        $this->dokumentKategorie = 'other';
        $this->dokumentAktion = 'none';
        unset($this->personDokumente);
        $this->flash = $ergebnis['versand_noetig']
            ? 'Dokument bereitgestellt, WhatsApp wird verschickt.'
            : 'Dokument bereitgestellt.';
    }

    public function dokumentErneutSenden(int $recipientId): void
    {
        $z = $this->zustellungDieserPerson($recipientId);
        if ($z === null) {
            return;
        }
        $status = app(DokumentService::class)->erneutSenden($z);
        unset($this->personDokumente);
        $this->flash = $status === 'sent' ? 'WhatsApp erneut gesendet.' : null;
        $this->flashError = $status === 'sent' ? null : 'Nicht gesendet: ' . $status;
    }

    public function dokumentZurueckziehen(int $recipientId): void
    {
        $z = $this->zustellungDieserPerson($recipientId);
        if ($z === null) {
            return;
        }
        $fehler = app(DokumentService::class)->zurueckziehen($z);
        unset($this->personDokumente);
        $this->flash = $fehler === null ? 'Dokument zurückgezogen.' : null;
        $this->flashError = $fehler;
    }

    /** Alle Zustellungen der Person (beide Anstellungen), neueste zuerst — auch zurueckgezogene, HR sieht Historie. */
    #[Computed]
    public function personDokumente(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }
        $ids = app(PersonScopeResolver::class)->forEmployee($emp)['ids'];

        return DokumentAkteZeilen::fuer(
            RecDocumentRecipient::query()
                ->ohneUnterschriftsbild()
                ->whereIn('rec_employee_id', $ids)
                ->with(['document' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('id')
                ->get()
                ->all()
        );
    }

    private function zustellungDieserPerson(int $recipientId): ?RecDocumentRecipient
    {
        $emp = $this->employee();
        if (!$emp) {
            return null;
        }
        $ids = app(PersonScopeResolver::class)->forEmployee($emp)['ids'];

        return RecDocumentRecipient::query()->whereIn('rec_employee_id', $ids)->find($recipientId);
    }

    private function isSigned(RecContract $contract): bool
    {
        return $contract->status === 'completed' && $contract->signed_at !== null;
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * Field-Definition aller editierbaren MA-Felder im HR-Backend.
     * Erweitert die MA-Portal-Sicht um Identity- + Lifecycle- +
     * Legal-Status-Felder die im Portal verboten sind.
     */
    public function fieldGroups(): array
    {
        // Non-EU-spezifische Felder werden nur bei is_eu_citizen=false
        // (oder null = unklar) angezeigt. Bei EU=true werden sie verborgen
        // damit die Ansicht uebersichtlicher bleibt.
        $emp = $this->employee();
        $showNonEuFields = ($emp?->is_eu_citizen !== true);

        $legalStatusFields = [
            'is_eu_citizen' => ['type' => 'bool', 'label' => 'EU-Buerger'],
        ];
        // Nationalpass (Reisepass aus Herkunftsland) ist semantisch ein
        // non-EU-Feld — EU-Buerger nutzen den Personalausweis (identity_card_*).
        if ($showNonEuFields) {
            $legalStatusFields['nationalpass_file_id']           = ['type' => 'file', 'label' => 'Nationalpass'];
            $legalStatusFields['aufenthaltstitel_front_file_id'] = ['type' => 'file', 'label' => 'Aufenthaltstitel Vorderseite'];
            $legalStatusFields['aufenthaltstitel_back_file_id']  = ['type' => 'file', 'label' => 'Aufenthaltstitel Rueckseite'];
            $legalStatusFields['visumsblatt_file_id']            = ['type' => 'file', 'label' => 'Visum'];
            $legalStatusFields['zusatzblatt_file_id']            = ['type' => 'file', 'label' => 'Zusatzblatt Vorderseite'];
            $legalStatusFields['zusatzblatt_back_file_id']       = ['type' => 'file', 'label' => 'Zusatzblatt Rueckseite'];
            $legalStatusFields['fiktionsbescheinigung_front_file_id'] = ['type' => 'file', 'label' => 'Fiktionsbescheinigung Vorderseite'];
            $legalStatusFields['fiktionsbescheinigung_back_file_id']  = ['type' => 'file', 'label' => 'Fiktionsbescheinigung Rueckseite'];
            $legalStatusFields['residence_permit_valid_until']   = ['type' => 'date', 'label' => 'Aufenthaltserlaubnis bis'];
            $legalStatusFields['work_permit_valid_until']        = ['type' => 'date', 'label' => 'Arbeitsgenehmigung bis'];
        }

        return [
            'Stammdaten' => [
                'first_name' => ['type' => 'text', 'label' => 'Vorname'],
                'last_name'  => ['type' => 'text', 'label' => 'Nachname'],
                'birth_name' => ['type' => 'text', 'label' => 'Geburtsname'],
                'birth_date' => ['type' => 'date', 'label' => 'Geburtsdatum'],
                'birth_place' => ['type' => 'text', 'label' => 'Geburtsort'],
                'birth_country' => ['type' => 'lookup', 'label' => 'Geburtsland', 'lookup' => 'geburtsland'],
                'nationality' => ['type' => 'lookup', 'label' => 'Staatsangehörigkeit', 'lookup' => 'geburtsland'],
                'gender' => ['type' => 'lookup', 'label' => 'Geschlecht', 'lookup' => 'geschlecht'],
                'marital_status' => ['type' => 'lookup', 'label' => 'Familienstand', 'lookup' => 'familienstand'],
            ],
            'Identifikation' => [
                'identity_card_number' => ['type' => 'text', 'label' => 'Ausweisnummer'],
                'identity_card_valid_until' => ['type' => 'date', 'label' => 'Ausweis gueltig bis'],
                'identity_card_front_file_id' => ['type' => 'file', 'label' => 'Ausweis Vorderseite'],
                'identity_card_back_file_id'  => ['type' => 'file', 'label' => 'Ausweis Rueckseite'],
                'selfie_file_id'              => ['type' => 'file', 'label' => 'Selfie'],
            ],
            'Kontakt' => [
                'email' => ['type' => 'text', 'label' => 'Email'],
                'phone' => ['type' => 'text', 'label' => 'Telefon'],
            ],
            'Adresse' => [
                'street' => ['type' => 'text', 'label' => 'Strasse'],
                'house_number' => ['type' => 'text', 'label' => 'Hausnummer'],
                'zip' => ['type' => 'text', 'label' => 'PLZ'],
                'city' => ['type' => 'text', 'label' => 'Ort'],
                'country_code' => ['type' => 'text', 'label' => 'Land'],
            ],
            'Persoenliches' => [
                'religion'           => ['type' => 'lookup', 'label' => 'Religion', 'lookup' => 'religion'],
                'number_of_children' => ['type' => 'text', 'label' => 'Anzahl Kinder'],
            ],
            'Stelle & Taetigkeit' => [
                'rec_position_id' => ['type' => 'position', 'label' => 'Stelle'],
                'beschaftigungsort' => ['type' => 'multi_lookup', 'label' => 'Beschaeftigungsort', 'lookup' => 'beschaeftigungsort'],
                'employment_type' => ['type' => 'lookup', 'label' => 'Ich bin (MA-Self-Deklaration)', 'lookup' => 'beschaeftigung_art'],
                'umfang_der_tatigkeit' => ['type' => 'lookup', 'label' => 'Umfang der Taetigkeit', 'lookup' => 'umfang_taetigkeit'],
            ],
            'Bankdaten' => [
                'iban' => ['type' => 'text', 'label' => 'IBAN'],
                'bic' => ['type' => 'text', 'label' => 'BIC'],
                'bank_institute' => ['type' => 'text', 'label' => 'Bank'],
                'account_holder' => ['type' => 'text', 'label' => 'Kontoinhaber'],
            ],
            'Steuer & Versicherung' => [
                'tax_class' => ['type' => 'inline_select', 'label' => 'Steuerklasse', 'options' => ['1','2','3','4','5','6']],
                'steuer_id' => ['type' => 'text', 'label' => 'Steuer-ID'],
                'sozialversicherungsnummer' => ['type' => 'text', 'label' => 'Sozialversicherungsnummer'],
                'health_insurance' => ['type' => 'lookup', 'label' => 'Krankenkasse', 'lookup' => 'krankenkasse'],
                'health_insurance_card_file_id' => ['type' => 'file', 'label' => 'Foto Versichertenkarte'],
            ],
            'Schul-/Immatrikulationsbescheinigung' => [
                'immatrikulation_file_id'         => ['type' => 'file', 'label' => 'Immatrikulationsbescheinigung'],
                'schulbescheinigung_file_id'      => ['type' => 'file', 'label' => 'Schulbescheinigung'],
                'school_certificate_valid_until'  => ['type' => 'date', 'label' => 'Gueltig bis'],
            ],
            'Gesundheit' => [
                'has_infection_protection_certificate' => ['type' => 'bool', 'label' => 'Infektionsschutzbescheinigung vorhanden?'],
                'infection_protection_first_issued_at' => ['type' => 'date', 'label' => 'Erstbescheinigung am'],
                'erstbescheinigung_file_id'            => ['type' => 'file', 'label' => 'Erstbescheinigung (Datei)'],
                // Nur HR, nicht im Portal. Bei Funnel-MA leer = Export rechnet
                // weiter aus dem IfSG-Vertrag der Bewerbung.
                'infection_protection_instructed_at'   => ['type' => 'date', 'label' => 'IfSG-Belehrung am'],
                'infection_protection_valid_until'     => ['type' => 'date', 'label' => 'IfSG-Belehrung gueltig bis'],
            ],
            'Arbeitskleidung' => [
                'shirt_size' => ['type' => 'inline_select', 'label' => 'Hemd / Bluse', 'options' => ['S','M','L','XL']],
                'pants_size' => ['type' => 'text', 'label' => 'Hosengroesse'],
                'shoe_size'  => ['type' => 'text', 'label' => 'Schuhgroesse'],
            ],
            'Legal-Status (EU/Non-EU)' => $legalStatusFields,
            'Sonstiges' => [
                'drivers_license_class' => ['type' => 'text', 'label' => 'Fuehrerschein-Klasse'],
                'has_car' => ['type' => 'bool', 'label' => 'PKW vorhanden'],
                'recruited_by_personnel_number' => ['type' => 'text', 'label' => 'Geworben von (Personalnummer)'],
            ],
            'Lifecycle' => [
                'is_active' => ['type' => 'bool', 'label' => 'Aktiv'],
                'employed_since' => ['type' => 'date', 'label' => 'Beschaeftigt seit'],
                'employment_ended_at' => ['type' => 'datetime', 'label' => 'Beschaeftigung beendet am'],
            ],
            // Liegen auf rec_employees (nicht hr_data), werden aber als HR-only
            // gerendert (gelb) — speisen Lohn-/ZAS-Export. MA-Portal sieht sie NIE.
            'ZAS / Abrechnung (HR-only)' => [
                'personnel_number' => ['type' => 'text', 'label' => 'Personalnummer (ZAS)'],
                // Steckt normalerweise im Praefix der Personalnummer und wird
                // beim Import daraus gesetzt. Aenderbar bleibt es fuer eigene
                // Neuanlagen, die noch keine Nummer von ZAS haben.
                'company'          => ['type' => 'inline_select', 'label' => 'Firma', 'options' => ['RG', 'MA']],
                'cost_center'      => ['type' => 'text', 'label' => 'Kostenstelle (Vorrang vor Stelle)'],
            ],
            // Seit 2026-09-01 pflegt der MA diese drei Felder selbst im Portal
            // (RecEmployee::editableFieldGroups), deshalb ohne "(HR-only)" —
            // die gelbe Markierung im Blade haengt an genau diesem Substring
            // und wuerde hier das Falsche behaupten.
            'Arbeitsschutz' => [
                'is_first_aider'          => ['type' => 'bool', 'label' => 'Ersthelfer'],
                'first_aider_valid_until' => ['type' => 'date', 'label' => 'Ersthelfer-Schein gueltig bis'],
                'first_aider_certificate_file_id' => ['type' => 'file', 'label' => 'Ersthelfer-Schein (Datei)'],
            ],
            // Pflegt der MA seit 23.09.2026 selbst im Portal, deshalb ohne
            // "(HR-only)". HR sieht es mit, weil die Angabe steuerlich zaehlt
            // (Steuerklasse VI) und bei Rueckfragen greifbar sein muss.
            'Arbeitgeber' => [
                'is_main_employer' => ['type' => 'bool', 'label' => 'Rheingedeck ist Hauptarbeitgeber'],
                'other_employer'   => ['type' => 'text', 'label' => 'Hauptarbeitgeber (falls nicht wir)'],
            ],
            // Sicherheitsbeauftragter benennt HR, nicht der MA — bleibt gelb.
            'Arbeitsschutz (HR-only)' => [
                'is_safety_officer' => ['type' => 'bool', 'label' => 'Sicherheitsbeauftragter'],
            ],
        ];
    }

    /**
     * HR-only-Feldgruppen aus rec_employee_hr_data. Separate Entity,
     * MA-Portal sieht das NIE.
     */
    public function hrFieldGroups(): array
    {
        return [
            'Vertrags-Status (HR-only, ZAS-Export)' => [
                'export_status'        => ['type' => 'inline_select', 'label' => 'Status (aus ZAS)', 'options' => ['GO', 'MA'], 'empty' => 'GO', 'readonly' => true],
                // Kommt ausschliesslich aus dem ZAS-Inbound (Spalte StatusMASeit).
                // Readonly wie der Status selbst: umgestellt wird in ZAS, ein
                // Editieren hier wuerde beide Seiten auseinanderlaufen lassen.
                'status_ma_since'      => ['type' => 'date', 'label' => 'MA-Status seit (aus ZAS)', 'empty' => 'steht nicht auf MA', 'readonly' => true],
                'contract_sent_date'   => ['type' => 'date', 'label' => 'Vertrags-Datum (Snapshot)'],
                'contract_signed_at'   => ['type' => 'date', 'label' => 'Vertrag zurueck am'],
                'contract_end_date'    => ['type' => 'date', 'label' => 'Befristet bis'],
                'employment_classification' => ['type' => 'lookup', 'label' => 'Anstellungsart', 'lookup' => 'anstellungsart'],
            ],
            // 70-Tage-Kreislauf (Markus 24.09.2026). Nur der erste Wert gehoert
            // uns — er wird aus der §15-Erklaerung des Arbeitsvertrags
            // gerechnet und einmal an ZAS uebergeben. Die beiden anderen
            // fuehrt ZAS ("da wird eingebucht") und liefert sie zurueck;
            // readonly wie der MA-Status, ein Editieren hier wuerde beide
            // Seiten auseinanderlaufen lassen.
            // Alle drei schreibgeschuetzt, aus zwei verschiedenen Gruenden:
            // Die beiden ZAS-Werte gehoeren ZAS. Der Startwert gehoert uns,
            // wird aber aus einer UNTERSCHRIEBENEN Erklaerung gerechnet — ihn
            // hier frei editierbar zu machen haette drei Loecher: ein
            // Textfeld auf einer Zahlenspalte (SQLSTATE-Abbruch bei "ca. 50"),
            // ein dauerhaft rot markiertes Pflichtfeld bei allen
            // Bestandsmitarbeitern, und ein vor der Unterschrift geoeffnetes
            // Formular, das den frisch gerechneten Wert beim Speichern wieder
            // leert. Wenn HR ihn aendern koennen soll, braucht es ein
            // Zahlenfeld mit Pruefung — dann bewusst und mit Test.
            'Kurzfristige Beschaeftigung (HR-only)' => [
                'short_term_days_allowed'   => ['type' => 'text', 'label' => 'Tage erlaubt (Startwert aus §15)', 'empty' => 'keine §15-Erklaerung', 'readonly' => true],
                // Eigene Zeile statt Zusatz am Wert: das Kontingent gilt je
                // Kalenderjahr, und ohne das Jahr ist die Zahl daneben in
                // zwoelf Wochen missverstaendlich — im Maerz 2027 stuende
                // dort der Startwert fuer 2026.
                'short_term_days_allowed_year' => ['type' => 'text', 'label' => 'Startwert gilt fuer das Jahr', 'empty' => '—', 'readonly' => true],
                'short_term_days_worked'    => ['type' => 'text', 'label' => 'Tage gearbeitet im Jahr (aus ZAS)', 'empty' => 'noch nichts geliefert', 'readonly' => true],
                'short_term_days_remaining' => ['type' => 'text', 'label' => 'Arbeitstage Rest (aus ZAS)', 'empty' => 'noch nichts geliefert', 'readonly' => true],
            ],
            'Ausstattung' => [
                'linen_package_items' => ['type' => 'multi_lookup', 'label' => 'Waeschepaket erhalten', 'lookup' => 'waeschepaket'],
            ],
            'Bewertung (Termin)' => [
                'rating_erscheinungsbild' => ['type' => 'inline_select', 'label' => 'Erscheinungsbild & Hygiene', 'options' => ['1','2','3','4','5']],
                'rating_fachkompetenz'    => ['type' => 'inline_select', 'label' => 'Fachliche Grundkompetenz', 'options' => ['1','2','3','4','5']],
                'rating_auffassungsgabe'  => ['type' => 'inline_select', 'label' => 'Auffassungsgabe & Lernbereitschaft', 'options' => ['1','2','3','4','5']],
                'rating_auftreten'        => ['type' => 'inline_select', 'label' => 'Auftreten & Kommunikation', 'options' => ['1','2','3','4','5']],
                'rating_teamintegration'  => ['type' => 'inline_select', 'label' => 'Teamintegration & Verhalten', 'options' => ['1','2','3','4','5']],
                'evaluation_note'         => ['type' => 'text', 'label' => 'Bewertungstext'],
            ],
            'Qualifikation & Altbestand' => [
                'qualifications' => ['type' => 'multi_lookup', 'label' => 'Qualifikation', 'lookup' => 'qualifikation'],
                // star_rating wird nicht mehr geschrieben (Spec §1). readonly,
                // weil der Blade auf leeren Feldern einen roten "fehlt"-Rand
                // setzt (:204-205) — sonst waere das Feld bei jedem neuen
                // Mitarbeiter dauerhaft rot markiert. 'empty' verhindert, dass
                // der readonly-Zweig bei leerem Wert den ersten Options-Wert
                // ("1") als Bewertung anzeigt, die niemand gesetzt hat.
                'star_rating'    => ['type' => 'inline_select', 'label' => 'Sternebewertung (Altbestand)', 'options' => ['1','2','3','4','5'], 'readonly' => true, 'empty' => '—'],
            ],
        ];
    }

    public function hrFieldsFlat(): array
    {
        $flat = [];
        foreach ($this->hrFieldGroups() as $section => $fields) {
            foreach ($fields as $key => $meta) {
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }

    public function fieldsFlat(): array
    {
        $flat = [];
        foreach ($this->fieldGroups() as $section => $fields) {
            foreach ($fields as $key => $meta) {
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }

    private function loadFieldValues(RecEmployee $employee): void
    {
        $values = [];
        foreach ($this->fieldsFlat() as $field => $meta) {
            $type = $meta['type'] ?? '';
            if ($type === 'file') {
                continue;
            }
            $raw = $employee->getAttribute($field);
            if ($type === 'multi_lookup') {
                $values[$field] = is_array($raw) ? $raw : [];
                continue;
            }
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format(($type === 'datetime') ? 'Y-m-d\TH:i' : 'Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $values[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->fieldValues = $values;

        // HR-Felder aus hrData laden (Lazy-Create wenn nicht vorhanden)
        $hrData = $employee->ensureHrData()->fresh();
        $hrValues = [];
        foreach ($this->hrFieldsFlat() as $field => $meta) {
            $raw = $hrData->getAttribute($field);
            $type = $meta['type'] ?? 'text';

            if ($type === 'multi_lookup') {
                $hrValues[$field] = is_array($raw) ? $raw : [];
                continue;
            }
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format('Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $hrValues[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->hrFieldValues = $hrValues;
    }

    public function saveAll(): void
    {
        $employee = $this->employee();
        if (!$employee) {
            return;
        }

        // Datumspflicht Ersthelfer (Endzustands-Pruefung, Spec 2026-07-17):
        // blockt JEDEN Save solange Ersthelfer=Ja ohne Datum — auch bei
        // unrelated Edits, damit lenient importierte MA repariert werden.
        // Early-Return OHNE loadFieldValues(): Eingaben bleiben stehen.
        $guardError = FirstAiderDateGuard::error(
            $this->fieldValues['is_first_aider'] ?? null,
            $this->fieldValues['first_aider_valid_until'] ?? null,
        );
        if ($guardError !== null) {
            $this->flashError = $guardError;
            $this->flash = null;
            return;
        }
        $this->flashError = null;

        // rec_employees Updates
        $allowed = $this->fieldsFlat();
        $updates = [];
        foreach ($this->fieldValues as $field => $value) {
            if (!array_key_exists($field, $allowed)) {
                continue;
            }
            $meta = $allowed[$field];
            $type = $meta['type'];

            if ($type === 'multi_lookup') {
                $updates[$field] = (is_array($value) && !empty($value))
                    ? array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null))
                    : null;
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            if ($type === 'bool') {
                // Truthy-Arme muessen identisch bleiben mit FirstAiderDateGuard (Support/FirstAiderDateGuard.php)
                $updates[$field] = match ((string) $value) {
                    '1', 'true', 'ja' => true,
                    '0', 'false', 'nein' => false,
                    default => null,
                };
            } elseif ($type === 'position') {
                $updates[$field] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
            } else {
                $updates[$field] = ($value === '' || $value === null) ? null : $value;
            }
        }

        // rec_employee_hr_data Updates
        $hrAllowed = $this->hrFieldsFlat();
        $hrUpdates = [];
        foreach ($this->hrFieldValues as $field => $value) {
            if (!array_key_exists($field, $hrAllowed)) {
                continue;
            }
            $meta = $hrAllowed[$field];
            // readonly-Felder (z.B. export_status) nicht durchschleifen
            if (($meta['readonly'] ?? false) === true) {
                continue;
            }
            $type = $meta['type'] ?? 'text';
            if ($type === 'multi_lookup') {
                $hrUpdates[$field] = (is_array($value) && !empty($value))
                    ? array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null))
                    : null;
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            $hrUpdates[$field] = ($value === '' || $value === null) ? null : $value;
        }

        $changesCount = 0;
        if (!empty($updates)) {
            $employee->update($updates);
            $changesCount++;
        }
        if (!empty($hrUpdates)) {
            $employee->ensureHrData()->update($hrUpdates);
            $changesCount++;
        }

        if ($changesCount === 0) {
            $this->flash = 'Keine Aenderungen.';
            return;
        }

        $this->flash = 'Aenderungen gespeichert.';
        $this->loadFieldValues($employee->fresh());
        unset($this->employee);
    }

    // File-Upload Hooks
    public function updatedUploadIdentityFront(): void { $this->handleFileUpload('identity_card_front_file_id', 'uploadIdentityFront'); }
    public function updatedUploadIdentityBack(): void { $this->handleFileUpload('identity_card_back_file_id', 'uploadIdentityBack'); }
    public function updatedUploadSelfie(): void { $this->handleFileUpload('selfie_file_id', 'uploadSelfie'); }
    public function updatedUploadHealthInsuranceCard(): void { $this->handleFileUpload('health_insurance_card_file_id', 'uploadHealthInsuranceCard'); }
    public function updatedUploadNationalpass(): void { $this->handleFileUpload('nationalpass_file_id', 'uploadNationalpass'); }
    public function updatedUploadAufenthaltstitelFront(): void { $this->handleFileUpload('aufenthaltstitel_front_file_id', 'uploadAufenthaltstitelFront'); }
    public function updatedUploadAufenthaltstitelBack(): void { $this->handleFileUpload('aufenthaltstitel_back_file_id', 'uploadAufenthaltstitelBack'); }
    public function updatedUploadVisumsblatt(): void { $this->handleFileUpload('visumsblatt_file_id', 'uploadVisumsblatt'); }
    public function updatedUploadZusatzblatt(): void { $this->handleFileUpload('zusatzblatt_file_id', 'uploadZusatzblatt'); }
    public function updatedUploadZusatzblattBack(): void { $this->handleFileUpload('zusatzblatt_back_file_id', 'uploadZusatzblattBack'); }
    public function updatedUploadImmatrikulation(): void { $this->handleFileUpload('immatrikulation_file_id', 'uploadImmatrikulation'); }
    public function updatedUploadSchulbescheinigung(): void { $this->handleFileUpload('schulbescheinigung_file_id', 'uploadSchulbescheinigung'); }
    public function updatedUploadFiktionFront(): void { $this->handleFileUpload('fiktionsbescheinigung_front_file_id', 'uploadFiktionFront'); }
    public function updatedUploadFiktionBack(): void { $this->handleFileUpload('fiktionsbescheinigung_back_file_id', 'uploadFiktionBack'); }
    public function updatedUploadErstbescheinigung(): void { $this->handleFileUpload('erstbescheinigung_file_id', 'uploadErstbescheinigung'); }
    public function updatedUploadFirstAiderCertificate(): void { $this->handleFileUpload('first_aider_certificate_file_id', 'uploadFirstAiderCertificate'); }

    private function handleFileUpload(string $employeeField, string $propertyName): void
    {
        $this->flashError = null;
        $employee = $this->employee();
        if (!$employee) {
            return;
        }
        $file = $this->{$propertyName};
        if (!$file) {
            return;
        }
        try {
            $result = app(ContextFileService::class)->uploadForContext(
                $file,
                'rec_employee',
                $employee->id,
                [
                    'team_id' => $employee->team_id,
                    'user_id' => auth()->id(),
                ]
            );
            $employee->update([$employeeField => (int) $result['id']]);
            $this->flash = "Datei hochgeladen.";
        } catch (\Throwable $e) {
            $this->flash = 'Upload-Fehler: ' . $e->getMessage();
        }
        $this->{$propertyName} = null;
        unset($this->employee);
    }

    public function uploadPropertyFor(string $fieldKey): ?string
    {
        return self::FILE_UPLOAD_MAP[$fieldKey] ?? null;
    }

    public function lookupOptionsFor(string $lookupName): array
    {
        $lookup = CoreLookup::where('name', $lookupName)->first();
        return $lookup ? $lookup->getOptionsArray() : [];
    }

    public function fileNameFor(?int $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }
        return ContextFile::find($fileId)?->original_name;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.show')
            ->layout('platform::layouts.app');
    }
}
