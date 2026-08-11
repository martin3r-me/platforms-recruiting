<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\ContractPreSigningType;
use Platform\Recruiting\Support\ResttagePlaceholder;

class ContractSigning extends Component
{
    #[Locked]
    public int $step = 1;

    public string $state = 'loading';

    #[Locked]
    public ?int $contractId = null;

    #[Locked]
    public string $contractContent = '';

    public string $contractTemplateName = '';

    #[Locked]
    public bool $requiresPreSigningStep = true;

    /**
     * 'par1516' | 'resttage' | null — welcher Vorschalt-Schritt gilt.
     *
     * NUR fuer die Darstellung. sign() leitet den Typ serverseitig neu aus
     * dem geladenen Vertrag ab und benutzt ausschliesslich diese lokale
     * Variable — sonst koennte ein Client den Typ auf null setzen und damit
     * an Validierung und Guard vorbeilaufen.
     */
    #[Locked]
    public ?string $preSigningType = null;

    /**
     * Restliche genehmigungsfreie Tage im laufenden Kalenderjahr (AT-140).
     *
     * Bewusst ?string und nicht ?int: Livewire kann ein geleertes
     * Zahlenfeld ('') nicht in ein typisiertes int-Property hydrieren.
     * Validierung prueft 'integer', gecastet wird bei Benutzung.
     */
    public ?string $resttage = null;

    /** Im angezeigten Text steht noch ein unaufgeloester {{...}}-Platzhalter. */
    #[Locked]
    public bool $contentIncomplete = false;

    public bool $par15HasPrevious = false;
    public array $par15Entries = [];

    public bool $par16WasJobseeking = false;
    public array $par16Entries = [];

    public ?string $signatureData = null;

    /** URL zurück ins Bewerber-Portal mit allen Verträgen — nach Signieren angezeigt */
    public ?string $portalUrl = null;

    public bool $duzen = false;

    public function mount(string $token): void
    {
        $link = CorePublicFormLink::where('token', $token)->first();

        if (! $link) {
            $this->state = 'invalid';
            return;
        }

        if (! $link->isValid()) {
            $this->state = 'expired';
            return;
        }

        $contract = $link->linkable;

        if (! $contract instanceof RecContract) {
            $this->state = 'invalid';
            return;
        }

        $this->duzen = $contract->applicant?->usesInformalAddress() ?? false;

        if ($contract->status === 'completed' || $contract->signed_at) {
            $this->state = 'already_signed';
            $this->portalUrl = $this->buildPortalUrl($contract);
            return;
        }

        if ($contract->status !== 'sent') {
            $this->state = 'invalid';
            return;
        }

        $this->contractId = $contract->id;
        $this->contractContent = $contract->personalized_content ?? '';
        $this->contractTemplateName = $contract->contractTemplate?->name ?? 'Vertrag';

        // Arbeitsvertraege (AV-*) fragen §15/§16 ab, die 140-Tage-Erklaerung
        // (AT-140) das Rest-Kontingent. IFSG und alles andere geht direkt
        // zu Ansicht und Unterschrift.
        $code = $contract->contractTemplate?->code;

        $this->preSigningType = ContractPreSigningType::forCode($code);
        $this->requiresPreSigningStep = $this->preSigningType !== null;
        $this->step = $this->requiresPreSigningStep ? 1 : 2;

        // Vollstaendigkeits-Guard nur fuer Zusatzvertraege (AT-*).
        //
        // Deckt insbesondere den Tippfehler-Fall ab: eine Vorlage "AT-0140"
        // steht nicht in RESTTAGE_CODES, bekommt also keinen Vorschalt-Schritt
        // und landet direkt in Schritt 2 — mit sichtbarem {{resttage}}.
        //
        // Bewusst NICHT an !requiresPreSigningStep gehaengt: das wuerde die
        // 203 IFSG-Vertraege mit einbeziehen, ohne dass das engere AT-Muster
        // einen Fall verliert. Kein Blast Radius auf den Bestand.
        //
        // Fuer AT-140 selbst ist der Wert hier true (der Platzhalter steht ja
        // noch) und wird in nextStep() nach der Ersetzung neu bewertet; die
        // Blade nutzt ihn ausschliesslich in Schritt 2.
        if ($code !== null && str_starts_with($code, 'AT-')) {
            $this->contentIncomplete = ResttagePlaceholder::hasUnresolvedPlaceholder($this->contractContent);
        }

        $this->state = 'form';
    }

    public function addPar15Entry(): void
    {
        $this->par15Entries[] = ['beginn' => '', 'ende' => '', 'arbeitgeber' => '', 'tage' => ''];
    }

    public function removePar15Entry(int $index): void
    {
        unset($this->par15Entries[$index]);
        $this->par15Entries = array_values($this->par15Entries);
    }

    public function addPar16Entry(): void
    {
        $this->par16Entries[] = ['beginn' => '', 'ende' => '', 'arbeitsagentur' => ''];
    }

    public function removePar16Entry(int $index): void
    {
        unset($this->par16Entries[$index]);
        $this->par16Entries = array_values($this->par16Entries);
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validatePreSigningData();

            // Der Bewerber soll in Schritt 2 das fertige Dokument sehen,
            // das er unterschreibt — inklusive seiner Zahl. Immer vom
            // DB-Stand aus ersetzen, damit ein "Zurueck" mit korrigierter
            // Eingabe sauber neu greift.
            if ($this->preSigningType === ContractPreSigningType::RESTTAGE) {
                $contract = RecContract::find($this->contractId);
                $this->contractContent = ResttagePlaceholder::fill(
                    $contract?->personalized_content ?? '',
                    (int) $this->resttage
                );
                $this->contentIncomplete = ResttagePlaceholder::hasUnresolvedPlaceholder($this->contractContent);

                if ($this->contentIncomplete) {
                    Log::warning('[ContractSigning] Unaufgeloester Platzhalter nach Resttage-Ersetzung', [
                        'contract_id' => $this->contractId,
                    ]);
                }
            }
        }

        $this->step++;
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function sign(): void
    {
        // Reihenfolge bewusst: Vertrag VOR der Validierung laden, weil die
        // Validierungsregeln vom serverseitig abgeleiteten Typ abhaengen.
        $contract = RecContract::find($this->contractId);

        if (! $contract || $contract->status !== 'sent') {
            $this->state = 'invalid';
            return;
        }

        // Code UND Typ serverseitig aus dem frisch geladenen Vertrag ableiten.
        // Ab hier ausschliesslich diese lokalen Variablen benutzen — nie
        // $this->preSigningType. Sonst koennte ein Client den Typ auf null
        // setzen: dann griffe der else-Zweig, {{resttage}} liefe unersetzt
        // durch, und der Guard wuerde uebersprungen.
        $code = $contract->contractTemplate?->code;
        $type = ContractPreSigningType::forCode($code);

        $rules = ['signatureData' => 'required'];
        $messages = [
            'signatureData.required' => $this->duzen
                ? 'Bitte unterschreibe den Vertrag.'
                : 'Bitte unterschreiben Sie den Vertrag.',
        ];

        // Auch hier validieren, nicht nur in nextStep(): sign() ist direkt
        // aufrufbar, Schritt 1 also ueberspringbar. Ohne diese Regel landete
        // still eine 0 im unterschriebenen Dokument — und der Platzhalter-
        // Guard merkt das nicht, weil "noch 0 Tage" vollstaendig aussieht.
        if ($type === ContractPreSigningType::RESTTAGE) {
            $rules = array_merge($rules, $this->resttageRules());
            $messages = array_merge($messages, $this->resttageMessages());
        }

        $this->validate($rules, $messages);

        if ($type === ContractPreSigningType::PAR_15_16) {
            $preSigningData = [
                'par15_has_previous' => $this->par15HasPrevious,
                'par15_entries' => $this->par15HasPrevious ? $this->par15Entries : [],
                'par16_was_jobseeking' => $this->par16WasJobseeking,
                'par16_entries' => $this->par16WasJobseeking ? $this->par16Entries : [],
            ];
            $personalizedContent = RecContract::embedPreSigningData(
                $contract->personalized_content ?? '',
                $preSigningData
            );
        } elseif ($type === ContractPreSigningType::RESTTAGE) {
            // Die Zahl wandert fest ins Dokument UND strukturiert in
            // pre_signing_data — letzteres traegt den Typ, damit eine
            // spaetere Re-Personalisierung sie wieder einsetzen kann.
            $preSigningData = [
                'type'     => ResttagePlaceholder::TYPE,
                'resttage' => (int) $this->resttage,
            ];
            $personalizedContent = RecContract::embedPreSigningData(
                $contract->personalized_content ?? '',
                $preSigningData
            );
        } else {
            $preSigningData = null;
            $personalizedContent = $contract->personalized_content ?? '';
        }

        // Harter Guard: ein unterschriebenes Dokument ist ein Archivstueck.
        // Bleibt ein Platzhalter stehen, wird NICHT gespeichert — die
        // Signatur wuerde sich sonst dauerhaft auf einen kaputten Text
        // beziehen. Nur fuer Zusatzvertraege (AT-*), damit Bestandsvertraege
        // mit womoeglich vorhandenen geschweiften Klammern nicht blockiert
        // werden.
        //
        // GLEICHES PRAEDIKAT WIE mount() — sonst ist der Tippfehler-Fall nur
        // in der UI geschuetzt: Eine Vorlage "AT-0140" liefert $type === null,
        // damit griffe ein Guard auf RESTTAGE nicht, {{resttage}} liefe durch
        // den else-Zweig unersetzt ins Dokument, und sign() ist direkt
        // aufrufbar. #[Locked] auf contentIncomplete hilft hier nicht, weil
        // sign() das Flag gar nicht liest.
        //
        // WICHTIG: Hier NICHT $this->contentIncomplete setzen. Die Meldung
        // haengt per addError an x-ui-input-signature, und dieses Feld wird
        // in der Blade nur im @else-Zweig von $contentIncomplete gerendert.
        // Setzt man das Flag, verschwindet das Feld, an dem die Meldung
        // haengt — und der Bewerber sieht gar nichts.
        if ($code !== null && str_starts_with($code, 'AT-')
            && ResttagePlaceholder::hasUnresolvedPlaceholder($personalizedContent)) {
            Log::error('[ContractSigning] Signieren abgebrochen — unaufgeloester Platzhalter', [
                'contract_id' => $contract->id,
            ]);
            $this->addError('signatureData', $this->duzen
                ? 'Dieses Dokument ist noch nicht vollständig. Bitte melde dich bei uns.'
                : 'Dieses Dokument ist noch nicht vollständig. Bitte melden Sie sich bei uns.');
            return;
        }

        $contract->update([
            'pre_signing_data' => $preSigningData,
            'personalized_content' => $personalizedContent,
            'signature_data' => $this->signatureData,
            'signed_at' => now(),
            'completed_at' => now(),
            'status' => 'completed',
        ]);

        $this->portalUrl = $this->buildPortalUrl($contract);
        $this->state = 'already_signed';
    }

    private function buildPortalUrl(RecContract $contract): ?string
    {
        $applicant = $contract->applicant;
        if (!$applicant) {
            return null;
        }
        try {
            $link = $applicant->getOrCreatePublicFormLink();
            return route('recruiting.public.applicant-portal', ['token' => $link->token]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validatePreSigningData(): void
    {
        $rules = [];
        $messages = [];

        if ($this->preSigningType === ContractPreSigningType::RESTTAGE) {
            $this->validate($this->resttageRules(), $this->resttageMessages());

            return;
        }

        if ($this->par15HasPrevious) {
            $rules = array_merge($rules, [
                'par15Entries' => 'required|array|min:1',
                'par15Entries.*.beginn' => 'required|string',
                'par15Entries.*.ende' => 'required|string',
                'par15Entries.*.arbeitgeber' => 'required|string',
                'par15Entries.*.tage' => 'required|integer|min:1',
            ]);
            $messages = array_merge($messages, [
                'par15Entries.required' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par15Entries.min' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par15Entries.*.beginn.required' => 'Beginn ist erforderlich.',
                'par15Entries.*.ende.required' => 'Ende ist erforderlich.',
                'par15Entries.*.arbeitgeber.required' => 'Arbeitgeber ist erforderlich.',
                'par15Entries.*.tage.required' => 'Anzahl Tage ist erforderlich.',
            ]);
        }

        if ($this->par16WasJobseeking) {
            $rules = array_merge($rules, [
                'par16Entries' => 'required|array|min:1',
                'par16Entries.*.beginn' => 'required|string',
                'par16Entries.*.ende' => 'required|string',
                'par16Entries.*.arbeitsagentur' => 'required|string',
            ]);
            $messages = array_merge($messages, [
                'par16Entries.required' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par16Entries.min' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par16Entries.*.beginn.required' => 'Beginn ist erforderlich.',
                'par16Entries.*.ende.required' => 'Ende ist erforderlich.',
                'par16Entries.*.arbeitsagentur.required' => 'Arbeitsagentur ist erforderlich.',
            ]);
        }

        if (! empty($rules)) {
            $this->validate($rules, $messages);
        }
    }

    /**
     * Validierungsregel fuer das Rest-Kontingent.
     *
     * Bewusst zentral: die Obergrenze haengt an der Rechtsgrundlage (heute
     * 140 nach dem Ursprungsdokument). Aendert sie sich, ist das hier EINE
     * Stelle — plus max="140" in der Blade, das sich nicht teilen laesst.
     */
    private function resttageRules(): array
    {
        return ['resttage' => 'required|integer|min:0|max:140'];
    }

    private function resttageMessages(): array
    {
        return [
            'resttage.required' => $this->duzen
                ? 'Bitte gib an, wie viele Tage dir noch zur Verfügung stehen.'
                : 'Bitte geben Sie an, wie viele Tage Ihnen noch zur Verfügung stehen.',
            'resttage.integer' => 'Bitte eine ganze Zahl eingeben.',
            'resttage.min' => 'Die Zahl darf nicht negativ sein.',
            'resttage.max' => 'Es können höchstens 140 Tage sein.',
        ];
    }

    public function render()
    {
        return view('recruiting::livewire.public.contract-signing')
            ->layout('platform::layouts.guest');
    }
}
