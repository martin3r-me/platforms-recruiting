<?php

namespace Platform\Recruiting\Services\TestEmployee;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\CoreLookupValue;
use Platform\Core\Services\ContextFileService;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;
use Platform\Recruiting\Services\OwnerResolver;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;
use Platform\Recruiting\Support\NonEuDocumentMapping;

/**
 * Legt einen Test-MA ueber den ECHTEN Pfad an (recruiting:test-ma):
 * CRM-Kontakt → Bewerber (is_test) mit Feldwerten → RecApplicantLegalStatus →
 * CreateEmployeeFromApplicantService::createOrUpdate().
 *
 * Bewusst KEINE MA-Spalte direkt: dann testete man nicht, was echte MA
 * durchlaufen (Anti-Ziel der Spec). Alles, was am MA steht, kommt ueber
 * Feldwert + Mapping.
 *
 * Ebenso bewusst NICHT setEuCitizen(): das ruft
 * HrDeskRoutingService::evaluateAndRoute und oeffnet beim Profil nicht-eu
 * einen HR-Fall. Kein Versand, AutoPilot aus.
 *
 * Alle Pruefungen laufen VOR dem ersten Schreiben — ein Tippfehler in
 * --set/--datei/--leer soll nicht als halber Datensatz liegen bleiben
 * (setExtraField verwirft unbekannte Namen sonst still).
 */
final class TestEmployeeCreator
{
    /**
     * @param array<string, string> $set   Feldname → Wert (--set)
     * @param array<string, string> $files Feldname → lokaler Pfad (--datei)
     * @param list<string>          $leer  Feldnamen, die leer bleiben (--leer)
     */
    public function create(
        int $positionId,
        string $profil,
        string $name,
        ?string $phone,
        array $set,
        array $files,
        array $leer,
        ?int $ownerId,
    ): TestEmployeeResult {
        // ---- Pruefen (nichts schreiben) ----

        $profilWerte = TestEmployeeProfiles::values($profil);

        $position = RecPosition::find($positionId);
        if (!$position) {
            throw new \InvalidArgumentException("Stelle #{$positionId} nicht gefunden.");
        }

        $phasen = RecPhase::where('rec_position_id', $position->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
        if ($phasen->isEmpty()) {
            throw new \InvalidArgumentException("Stelle #{$position->id} hat keine aktive Phase.");
        }

        // Die Phase, an der ein echter MA entsteht; ohne solche die letzte.
        $zielphase = $phasen->first(
            fn (RecPhase $p) => (($p->completion_config ?? [])['creates_employee_on_completion'] ?? false) === true
        ) ?? $phasen->last();

        $katalog = $this->feldkatalog($phasen->filter(fn (RecPhase $p) => $p->order <= $zielphase->order));

        $unbekannt = array_values(array_unique(array_diff(
            array_merge(array_keys($set), array_keys($files), $leer),
            array_keys($katalog),
        )));
        if ($unbekannt !== []) {
            throw new \InvalidArgumentException(
                'Unbekannte Feldnamen bis Phase „' . $zielphase->name . '": ' . implode(', ', $unbekannt)
                . '. Bekannt: ' . implode(', ', array_keys($katalog)) . '.'
            );
        }

        // Datei-Felder nur ueber --datei: ein --set-Wert waere keine gueltige Datei-ID.
        foreach (array_keys($set) as $feld) {
            if ($katalog[$feld]->type === 'file') {
                throw new \InvalidArgumentException("Feld „{$feld}\" ist ein Datei-Feld — bitte mit --datei={$feld}=/pfad setzen, nicht mit --set.");
            }
        }

        foreach ($files as $feld => $pfad) {
            if ($katalog[$feld]->type !== 'file') {
                throw new \InvalidArgumentException("Feld „{$feld}\" ist kein Datei-Feld (Typ {$katalog[$feld]->type}).");
            }
            if (!is_file($pfad) || !is_readable($pfad)) {
                throw new \InvalidArgumentException("Datei für „{$feld}\" nicht lesbar: {$pfad}");
            }
        }

        $posting = RecPosting::where('rec_position_id', $position->id)
            ->published()
            ->active()
            ->orderBy('id')
            ->first();
        if (!$posting) {
            throw new \InvalidArgumentException("Stelle #{$position->id} hat keine veröffentlichte Ausschreibung.");
        }

        // Kaskade wie beim echten Eingang (IncomingApplicationService). Settings
        // nur LESEN: getOrCreateForTeam() wuerde schon hier schreiben.
        $owner = $ownerId ?? OwnerResolver::resolve(
            null,
            $position->owned_by_user_id ? (int) $position->owned_by_user_id : null,
            (int) (RecApplicantSettings::where('team_id', $position->team_id)->first()?->getSetting('default_contact_user_id') ?? 0) ?: null,
            $position->team?->user_id ? (int) $position->team->user_id : null,
        );
        if (!$owner) {
            throw new \InvalidArgumentException(
                'Kein Verantwortlicher ermittelbar (Stelle, Team-Einstellung, Team-Owner leer). '
                . 'Bitte --owner=<user_id> angeben — ohne Owner ist der Bewerber unsichtbar.'
            );
        }

        // ---- Anlegen ----

        $teamId = (int) $position->team_id;
        [$vorname, $nachname] = $this->nameTeilen($name);
        $email = 'test-ma-' . Str::random(8) . '@invalid.local';

        $profilWerte = array_merge($profilWerte, [
            'vorname'  => $vorname,
            'nachname' => $nachname,
            'email'    => $email,
        ]);

        return DB::transaction(function () use (
            $teamId, $vorname, $nachname, $email, $phone, $owner, $posting, $zielphase,
            $katalog, $profilWerte, $set, $files, $leer,
        ) {
            $contact = $this->kontaktAnlegen($teamId, $vorname, $nachname, $email, $phone, $owner);

            $applicant = RecApplicant::create([
                'team_id'            => $teamId,
                'is_test'            => true,
                'is_active'          => true,
                'auto_pilot'         => false,
                'progress'           => 0,
                'applied_at'         => now()->toDateString(),
                'owned_by_user_id'   => $owner,
                'created_by_user_id' => $owner,
            ]);
            $applicant->linkContact($contact);
            $applicant->anzeigeVerknuepfen($posting, ['notes' => 'Test-MA (recruiting:test-ma)']);

            $applicant->rec_phase_id = $zielphase->id;
            $applicant->save();
            // Sonst loest setExtraField gegen den Feldkatalog der alten Phase auf.
            $applicant->clearExtraFieldDefinitionsCache();

            $gefuellt = [];
            $leerGeblieben = [];
            $nurNachProfil = TestEmployeeProfiles::nurNachProfil();

            foreach ($katalog as $feld => $definition) {
                $wert = $this->wertFuer(
                    $definition, $applicant, (int) $zielphase->order, $profilWerte, $nurNachProfil,
                    $set, $files, $leer, $phone, $email,
                );

                if ($wert === null || $wert === '') {
                    $leerGeblieben[] = $feld;
                    continue;
                }

                $applicant->setExtraField($feld, $wert);
                $gefuellt[$feld] = $wert;
            }

            // Rechtsstatus direkt — NICHT setEuCitizen() (HR-Routing, s. o.).
            $dateiId = fn (string $feld) => NonEuDocumentMapping::normalizeFileId($gefuellt[$feld] ?? null);
            RecApplicantLegalStatus::updateOrCreate(
                ['rec_applicant_id' => $applicant->id],
                [
                    'team_id'                 => $teamId,
                    'is_eu_citizen'           => (bool) $profilWerte['_eu'],
                    'nationalpass_file_id'    => $dateiId('nationalpass'),
                    'immatrikulation_file_id' => $dateiId('immatrikulationsbescheinigung_schulbescheinigung')
                        ?? $dateiId('immatrikulationsbescheinigung'),
                ],
            );

            $employee = app(CreateEmployeeFromApplicantService::class)
                ->createOrUpdate($applicant->fresh(), $owner);

            return new TestEmployeeResult(
                applicantId: (int) $applicant->id,
                employeeId: (int) $employee->id,
                contactId: (int) $contact->id,
                portalUrl: $this->portalUrl($employee->portal_token),
                filled: array_keys($gefuellt),
                empty: $leerGeblieben,
            );
        });
    }

    /**
     * Feld-Definitionen, die der Bewerber in der Zielphase erbt — dieselbe
     * Vererbung wie RecApplicant::extraFieldParents(): alle aktiven Phasen bis
     * einschliesslich der Zielphase, bei gleichem Namen gewinnt die fruehere.
     *
     * @return array<string, CoreExtraFieldDefinition>
     */
    private function feldkatalog(iterable $phasen): array
    {
        $katalog = [];
        foreach ($phasen as $phase) {
            foreach ($phase->getExtraFieldDefinitions() as $definition) {
                if (!empty($definition->name)) {
                    $katalog[$definition->name] ??= $definition;
                }
            }
        }

        return $katalog;
    }

    /**
     * Reihenfolge: --leer gewinnt; dann --datei / --set; dann Profilwert;
     * sonst Platzhalter nur bei Pflichtfeldern. Datei-Felder und Nicht-EU-Daten,
     * die ein Profil kennt, gibt es nur nach Profil (siehe
     * TestEmployeeProfiles::nurNachProfil()).
     */
    private function wertFuer(
        CoreExtraFieldDefinition $definition,
        RecApplicant $applicant,
        int $zielOrder,
        array $profilWerte,
        array $nurNachProfil,
        array $set,
        array $files,
        array $leer,
        ?string $phone,
        string $email,
    ): mixed {
        $feld = $definition->name;

        if (in_array($feld, $leer, true)) {
            return null;
        }
        if (isset($files[$feld])) {
            return $this->hochladen($applicant, (string) file_get_contents($files[$feld]), basename($files[$feld]));
        }
        if (array_key_exists($feld, $set)) {
            return $set[$feld];
        }

        if ($definition->type === 'file') {
            $vomProfil = in_array($feld, $profilWerte['_files'], true);
            $pflichtOhneProfil = !in_array($feld, $nurNachProfil, true) && $this->istPflicht($definition, $zielOrder);

            return ($vomProfil || $pflichtOhneProfil)
                ? $this->hochladen($applicant, PlaceholderImage::png("TEST – {$feld}"), "test-ma-{$feld}.png")
                : null;
        }

        // Telefon: nur, wenn --phone gesetzt ist — sonst bleibt es leer, auch als Pflichtfeld.
        if ($definition->type === 'phone') {
            return $phone !== null && $phone !== '' ? $phone : null;
        }

        if (array_key_exists($feld, $profilWerte)) {
            return in_array($definition->type, ['select', 'lookup'], true)
                ? $this->aufOption($definition, $profilWerte[$feld])
                : $profilWerte[$feld];
        }

        if (in_array($feld, $nurNachProfil, true) || !$this->istPflicht($definition, $zielOrder)) {
            return null;
        }

        return match ($definition->type) {
            'text', 'textarea', 'regex' => 'Test',
            'number'                    => 1,
            'date'                      => now()->addYears(2)->toDateString(),
            'boolean'                   => false,
            'select', 'lookup'          => $this->aufOption($definition, null),
            'email'                     => $email,
            default                     => null, // address: leer
        };
    }

    /** Wie PublicExtraFieldForm::isFieldEffectivelyRequired() — fuer die Zielphase. */
    private function istPflicht(CoreExtraFieldDefinition $definition, int $zielOrder): bool
    {
        if ($definition->is_mandatory || $definition->is_required) {
            return true;
        }

        $orders = ($definition->options ?? [])['required_in_phase_orders'] ?? [];

        return is_array($orders) && in_array($zielOrder, array_map('intval', $orders), true);
    }

    /**
     * Profilwert gegen die Optionen der Definition (Wert ODER Label, Gross-/
     * Kleinschreibung egal); kein Treffer → erster Optionswert. Mehrfachauswahl
     * wird als Liste gespeichert.
     */
    private function aufOption(CoreExtraFieldDefinition $definition, mixed $wert): mixed
    {
        $optionen = $this->optionen($definition);
        if ($optionen === []) {
            return $wert;
        }

        $treffer = ZasLookupReverseResolver::matchValue($optionen, $wert === null ? null : (string) $wert, false);
        $gewaehlt = ($wert !== null && $treffer['matched']) ? $treffer['value'] : $optionen[0]['value'];

        return (($definition->options ?? [])['multiple'] ?? false) ? [$gewaehlt] : $gewaehlt;
    }

    /** @return list<array{value: string, label: string}> */
    private function optionen(CoreExtraFieldDefinition $definition): array
    {
        $options = $definition->options ?? [];

        if ($definition->type === 'lookup') {
            if (empty($options['lookup_id'])) {
                return [];
            }

            return CoreLookupValue::where('lookup_id', (int) $options['lookup_id'])
                ->where('is_active', true)
                ->orderBy('order')
                ->orderBy('label')
                ->get(['value', 'label'])
                ->map(fn ($v) => ['value' => (string) $v->value, 'label' => (string) $v->label])
                ->all();
        }

        return array_values(array_map(
            fn ($c) => is_array($c)
                ? ['value' => (string) ($c['value'] ?? ''), 'label' => (string) ($c['label'] ?? $c['value'] ?? '')]
                : ['value' => (string) $c, 'label' => (string) $c],
            (array) ($options['choices'] ?? []),
        ));
    }

    /**
     * Ablage wie beim Public-Form: Kontext = Bewerber. Der Core-Dienst will ein
     * UploadedFile, darum der Umweg ueber eine temporaere Datei (Muster
     * ZasEmployeeFileStore; `test: true`, sonst prueft Symfony auf echten
     * HTTP-Upload).
     */
    private function hochladen(RecApplicant $applicant, string $bytes, string $dateiname): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test-ma-');
        if ($tmp === false) {
            throw new \RuntimeException('Kein temporaerer Dateiname verfuegbar.');
        }

        try {
            file_put_contents($tmp, $bytes);

            $result = app(ContextFileService::class)->uploadForContext(
                new UploadedFile($tmp, $dateiname, null, null, true),
                RecApplicant::class,
                (int) $applicant->id,
                ['team_id' => (int) $applicant->team_id, 'user_id' => null],
            );

            return (int) $result['id'];
        } finally {
            @unlink($tmp);
        }
    }

    /** Muster CreateApplicantTool / Applicant\Show: Kontakt + E-Mail + ggf. Telefon. */
    private function kontaktAnlegen(int $teamId, string $vorname, string $nachname, string $email, ?string $phone, int $owner): CrmContact
    {
        $contact = CrmContact::create([
            'team_id'            => $teamId,
            'first_name'         => $vorname,
            'last_name'          => $nachname,
            'is_active'          => true,
            'created_by_user_id' => $owner,
        ]);

        $emailTypeId = CrmEmailType::where('code', 'PRIVATE')->value('id');
        if ($emailTypeId) {
            $contact->emailAddresses()->create([
                'email_address' => $email,
                'email_type_id' => $emailTypeId,
                'is_primary'    => true,
                'is_active'     => true,
            ]);
        }

        $phoneTypeId = ($phone !== null && $phone !== '') ? CrmPhoneType::where('code', 'MOBILE')->value('id') : null;
        if ($phoneTypeId) {
            $contact->phoneNumbers()->create([
                'raw_input'       => $phone,
                'international'   => $phone,
                'phone_type_id'   => $phoneTypeId,
                'is_primary'      => true,
                'is_active'       => true,
                'whatsapp_status' => CrmPhoneNumber::WHATSAPP_UNKNOWN,
            ]);
        }

        return $contact;
    }

    /** "Vorname Nachname" → [Vorname, Nachname]; Default „Test Mitarbeiter". */
    private function nameTeilen(string $name): array
    {
        $teile = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            ($teile[0] ?? '') !== '' ? $teile[0] : 'Test',
            $teile[1] ?? 'Mitarbeiter',
        ];
    }

    /** Portal-Link; ohne Router (z. B. im Test) null statt Abbruch. */
    private function portalUrl(?string $token): ?string
    {
        if (!$token) {
            return null;
        }

        try {
            return route('recruiting.public.employee-portal', ['token' => $token]);
        } catch (\Throwable) {
            return null;
        }
    }
}
