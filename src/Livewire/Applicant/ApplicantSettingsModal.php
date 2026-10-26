<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecServiceHours;
use Platform\Recruiting\Models\RecSourcePlatform;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Support\HrEinstellungen;
use Platform\Recruiting\Support\PilotKreis;
use Platform\Recruiting\Support\PortalVorlagen;

class ApplicantSettingsModal extends Component
{
    public $modalShow = false;
    public $activeTab = 'general';

    public ?RecApplicantSettings $settingsModel = null;

    /**
     * DEFAULT_SETTINGS und NICHT [] — die Schluessel muessen schon beim ersten
     * Seitenaufbau existieren, sonst kommt keine Select-Auswahl je am Server an.
     *
     * Das Modal haengt versteckt in jeder Bewerberliste (index.blade.php:428);
     * `openSettings()` laeuft erst beim Klick, Alpine initialisiert das x-data
     * der Selects aber sofort. Ab 20 Optionen laeuft x-ui-input-select im
     * Searchable-Modus, dessen einziger Rueckweg `@entangle` ist — und
     * Livewires generateEntangleFunction steigt OHNE Bindung aus, wenn
     * `$wire.get('settings.<key>')` in diesem Moment undefined ist (nur eine
     * console.error, kein sichtbarer Fehler). Alpine initialisiert dasselbe
     * x-data spaeter nicht erneut, das Binding bleibt also fuer die Lebensdauer
     * der Seite tot: die Auswahl bleibt im Browser, `save()` schreibt den alten
     * Wert zurueck. `null` als Wert ist unkritisch, es zaehlt allein die
     * Existenz des Schluessels. Festgenagelt in SettingsModalEntangleKeysTest.
     */
    public array $settings = RecApplicantSettings::DEFAULT_SETTINGS;

    public $serviceHours = [];
    public $showServiceHoursForm = false;
    public array $teamUsers = [];
    public array $newServiceZeit = [
        'name' => '',
        'description' => '',
        'is_active' => true,
        'use_auto_messages' => false,
        'auto_message_inside' => '',
        'auto_message_outside' => '',
        'service_hours' => []
    ];

    public $sourcePlatforms = [];
    public bool $showSourceForm = false;

    public array $intakeChannels = [];
    public ?int $editingSourceId = null;
    public array $newSource = [
        'name' => '',
        'url' => '',
        'match_pattern' => '',
        'is_active' => true,
        'priority' => 100,
    ];

    #[On('open-applicant-settings')]
    public function openSettings(): void
    {
        $teamId = Auth::user()->currentTeam->id;
        $this->settingsModel = RecApplicantSettings::getOrCreateForTeam($teamId);
        $this->settings = array_merge(RecApplicantSettings::DEFAULT_SETTINGS, $this->settingsModel->settings ?? []);

        $this->teamUsers = Auth::user()->currentTeam->users()->orderBy('name')->get()->toArray();

        $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        $this->newServiceZeit['service_hours'] = RecServiceHours::getDefaultServiceHours();

        $this->loadSourcePlatforms($teamId);
        $this->loadIntakeChannels($teamId);

        $this->activeTab = 'general';
        $this->modalShow = true;
    }

    private function loadSourcePlatforms(int $teamId): void
    {
        $this->sourcePlatforms = RecSourcePlatform::where('team_id', $teamId)
            ->orderByRaw('LENGTH(match_pattern) DESC')
            ->orderBy('priority')
            ->get()
            ->toArray();
    }

    private function loadIntakeChannels(int $teamId): void
    {
        $registered = \Platform\Recruiting\Models\RecIntakeChannel::query()
            ->where('team_id', $teamId)
            ->get()
            ->keyBy('comms_channel_id');

        $this->intakeChannels = \Platform\Crm\Models\CommsChannel::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get()
            ->map(fn ($channel) => [
                'channel_id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type,
                'is_intake' => $registered->has($channel->id) && $registered[$channel->id]->is_active,
                'default_posting_id' => $registered[$channel->id]->default_posting_id ?? null,
            ])
            ->toArray();
    }

    public function toggleIntakeChannel(int $channelId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        // Guard: Verify channel belongs to team
        $channelBelongsToTeam = \Platform\Crm\Models\CommsChannel::query()
            ->whereKey($channelId)
            ->where('team_id', $teamId)
            ->exists();
        if (!$channelBelongsToTeam) {
            return;
        }

        // Race-safe upsert
        $intake = \Platform\Recruiting\Models\RecIntakeChannel::firstOrCreate(
            [
                'team_id' => $teamId,
                'comms_channel_id' => $channelId,
            ],
            [
                'is_active' => true,
            ],
        );

        if (!$intake->wasRecentlyCreated) {
            $intake->update(['is_active' => !$intake->is_active]);
        }

        $this->loadIntakeChannels($teamId);
    }

    public function setIntakeDefaultPosting(int $channelId, string $postingId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        // Guard: Verify channel belongs to team
        $channelBelongsToTeam = \Platform\Crm\Models\CommsChannel::query()
            ->whereKey($channelId)
            ->where('team_id', $teamId)
            ->exists();
        if (!$channelBelongsToTeam) {
            return;
        }

        $postingIdInt = is_numeric($postingId) ? (int) $postingId : null;

        if ($postingIdInt !== null) {
            $valid = \Platform\Recruiting\Models\RecPosting::forTeam($teamId)->whereKey($postingIdInt)->exists();
            if (!$valid) {
                return;
            }
        }

        \Platform\Recruiting\Models\RecIntakeChannel::query()
            ->where('team_id', $teamId)
            ->where('comms_channel_id', $channelId)
            ->update(['default_posting_id' => $postingIdInt]);

        $this->loadIntakeChannels($teamId);
    }

    public function toggleSourceForm(): void
    {
        $this->showSourceForm = !$this->showSourceForm;
        if (!$this->showSourceForm) {
            $this->resetSourceForm();
        }
    }

    public function editSource(int $sourceId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $source = RecSourcePlatform::where('team_id', $teamId)->find($sourceId);
        if (!$source) {
            return;
        }
        $this->editingSourceId = $source->id;
        $this->newSource = [
            'name' => $source->name,
            'url' => $source->url ?? '',
            'match_pattern' => $source->match_pattern,
            'is_active' => (bool) $source->is_active,
            'priority' => (int) $source->priority,
        ];
        $this->showSourceForm = true;
    }

    public function saveSource(): void
    {
        $this->validate([
            'newSource.name' => 'required|string|max:60',
            'newSource.url' => 'nullable|string|max:255',
            'newSource.match_pattern' => 'required|string|max:255',
            'newSource.is_active' => 'boolean',
            'newSource.priority' => 'integer|min:1|max:1000',
        ], [
            'newSource.name.required' => 'Bitte einen Namen angeben.',
            'newSource.match_pattern.required' => 'Bitte ein Match-Pattern angeben.',
        ]);

        $teamId = (int) Auth::user()->currentTeam->id;

        if ($this->editingSourceId) {
            $source = RecSourcePlatform::where('team_id', $teamId)->find($this->editingSourceId);
            if (!$source) {
                $this->resetSourceForm();
                return;
            }
        } else {
            $source = new RecSourcePlatform();
            $source->team_id = $teamId;
        }

        $source->name = trim($this->newSource['name']);
        $source->url = trim($this->newSource['url']) ?: null;
        $source->match_pattern = trim($this->newSource['match_pattern']);
        $source->is_active = (bool) ($this->newSource['is_active'] ?? true);
        $source->priority = (int) ($this->newSource['priority'] ?? 100);
        $source->save();

        $this->loadSourcePlatforms($teamId);
        $this->resetSourceForm();
        $this->showSourceForm = false;
    }

    public function deleteSource(int $sourceId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        RecSourcePlatform::where('team_id', $teamId)->where('id', $sourceId)->delete();
        $this->loadSourcePlatforms($teamId);
    }

    public function toggleSourceActive(int $sourceId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $source = RecSourcePlatform::where('team_id', $teamId)->find($sourceId);
        if ($source) {
            $source->is_active = !$source->is_active;
            $source->save();
            $this->loadSourcePlatforms($teamId);
        }
    }

    private function resetSourceForm(): void
    {
        $this->editingSourceId = null;
        $this->newSource = [
            'name' => '',
            'url' => '',
            'match_pattern' => '',
            'is_active' => true,
            'priority' => 100,
        ];
    }

    public function save(): void
    {
        // Validate: auto_start requires both WA templates
        if (!empty($this->settings['auto_start_auto_pilot'])) {
            if (empty($this->settings['auto_pilot_wa_initial_template_id']) || empty($this->settings['auto_pilot_wa_reminder_template_id'])) {
                $this->settings['auto_start_auto_pilot'] = false;
                $this->addError('settings.auto_start_auto_pilot', 'Beide WhatsApp-Templates müssen konfiguriert sein, um Auto-Start zu aktivieren.');
                return;
            }
        }

        // HR-Bereich (Spec §6/§7.2): Zahlen begrenzt speichern, Schalter als bool;
        // ein Kippen des Notfallwegs gehoert ins Log (wer, wann, an/aus).
        $vorher = (bool) ($this->settingsModel->getSetting(HrEinstellungen::NOTFALL_WEG) ?? false);
        $this->settings[HrEinstellungen::NOTFALL_WEG] = (bool) ($this->settings[HrEinstellungen::NOTFALL_WEG] ?? false);
        $this->settings[HrEinstellungen::ERINNERT_TAGE] = HrEinstellungen::begrenze($this->settings[HrEinstellungen::ERINNERT_TAGE] ?? null, 1, 60, 5);
        $this->settings[HrEinstellungen::RUHE_TAGE] = HrEinstellungen::begrenze($this->settings[HrEinstellungen::RUHE_TAGE] ?? null, 1, 30, 3);

        // Mitarbeiterportal (Spec Portal-Vorlagen §7): Vorlagen-IDs als int|null
        // (leere Auswahl kommt als '' an und darf nicht als '' liegen bleiben),
        // der Schalter Einsatz-Pruefung strikt bool ('false' ist aus).
        foreach (array_keys(PortalVorlagen::FELDNAMEN) as $schluessel) {
            $this->settings[$schluessel] = self::vorlagenId($this->settings[$schluessel] ?? null);
        }
        $pruefungVorher = PortalVorlagen::istAn($this->settingsModel->getSetting(PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV));
        $this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = PortalVorlagen::istAn($this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] ?? false);
        // Pilot-Sperre der HR-Faelle: fehlt der Schluessel, gilt die Vorgabe AN.
        $this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_NUR_PILOT] = PilotKreis::sperreAn($this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_NUR_PILOT] ?? null);

        // Themen-Auswahl (WhatsApp): siehe themenEinstellungen(). Ungueltiges wird
        // als null (= Vorgabe) gespeichert, die Meldung steht am Feld, das Fenster bleibt offen.
        ['settings' => $this->settings, 'fehler' => $themenFehler] = self::themenEinstellungen($this->settings);
        foreach ($themenFehler as $feld => $meldung) {
            $this->addError('settings.' . $feld, $meldung);
        }

        $this->settingsModel->settings = $this->settings;
        $this->settingsModel->save();

        if ($vorher !== $this->settings[HrEinstellungen::NOTFALL_WEG]) {
            Log::notice('recruiting.konto.notfallweg_schalter', [
                'team_id' => (int) $this->settingsModel->team_id,
                'user_id' => Auth::id(),
                'an'      => $this->settings[HrEinstellungen::NOTFALL_WEG],
            ]);
        }

        if ($pruefungVorher !== $this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]) {
            Log::notice('recruiting.einsatz_pruefung.schalter', [
                'team_id' => (int) $this->settingsModel->team_id,
                'user_id' => Auth::id(),
                'an'      => $this->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV],
            ]);
        }

        if ($themenFehler !== []) {
            return;
        }

        $this->modalShow = false;
    }

    /** Hoechstlaenge der Team-Texte: Meta erlaubt 1024 Zeichen Body, der Abwesenheits-Vorspann kommt noch davor. */
    public const THEMEN_TEXT_MAX = 1000;

    /**
     * Themen-Auswahl (WhatsApp) beim Speichern: Schalter strikt bool, Ruhezeit
     * 1-48 Stunden (Vorgabe 8), leere URL/Texte als null (= Vorgabe bzw. Text
     * ohne Knopf). Die Login-URL nur mit https:// (Meta lehnt sonst jeden
     * Lohn-Hinweis ab), Texte hoechstens THEMEN_TEXT_MAX Zeichen — beides sonst
     * null plus Meldung (Schlussreview m5).
     *
     * @param array<string, mixed> $settings
     * @return array{settings: array<string, mixed>, fehler: array<string, string>}
     */
    public static function themenEinstellungen(array $settings): array
    {
        $fehler = [];
        $settings['comms_topic_enabled'] = filter_var($settings['comms_topic_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // Pilot-Sperre: fehlt der Schluessel (Altbestand), gilt die Vorgabe AN —
        // sonst oeffnete das blosse Speichern die Auswahl fuer alle.
        $settings['comms_topic_nur_pilot'] = PilotKreis::sperreAn($settings['comms_topic_nur_pilot'] ?? null);
        $settings['comms_topic_quiet_hours'] = HrEinstellungen::begrenze($settings['comms_topic_quiet_hours'] ?? null, 0, 48, 8);

        foreach (['comms_topic_lohn_url', 'comms_topic_text_ask', 'comms_topic_text_lohn_offen', 'comms_topic_text_lohn_ok', 'comms_topic_text_lohn_frei'] as $schluessel) {
            $wert = trim((string) ($settings[$schluessel] ?? ''));
            if ($wert !== '' && $schluessel === 'comms_topic_lohn_url'
                && (!str_starts_with(strtolower($wert), 'https://') || filter_var($wert, FILTER_VALIDATE_URL) === false)) {
                $fehler[$schluessel] = 'Nicht gespeichert: die Adresse muss mit https:// beginnen.';
                $wert = '';
            } elseif ($wert !== '' && $schluessel !== 'comms_topic_lohn_url' && mb_strlen($wert) > self::THEMEN_TEXT_MAX) {
                $fehler[$schluessel] = 'Nicht gespeichert: höchstens ' . self::THEMEN_TEXT_MAX . ' Zeichen (' . mb_strlen($wert) . ' eingegeben). Es gilt der Vorgabetext.';
                $wert = '';
            }
            $settings[$schluessel] = $wert === '' ? null : $wert;
        }

        return ['settings' => $settings, 'fehler' => $fehler];
    }

    /** Vorlagen-ID aus einem Select: leer, '0' oder kein positiver Zahlwert → null. */
    private static function vorlagenId(mixed $wert): ?int
    {
        $id = is_numeric($wert) ? (int) $wert : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Die Felder der Sektion „Mitarbeiterportal" (Reiter Kommunikation), in
     * Anzeigereihenfolge. Die Beschriftung kommt aus PortalVorlagen::FELDNAMEN —
     * derselbe Text, den die Sender in ihre Fehlermeldungen schreiben, damit HR
     * das Feld aus der Logzeile wiederfindet. Die Hinweise sind fertiges HTML
     * (Platzhalter als Entities, damit keine Template-Engine sie anfasst).
     *
     * @return list<array{key: string, label: string, hinweis: string, nullLabel: string}>
     */
    public function portalVorlagenFelder(): array
    {
        // Seit 14.10.2026 zwei Arten (EinmalcodeSender): Meta genehmigt Codes
        // nur noch als AUTHENTICATION "Code kopieren"; die alte UTILITY-Form
        // mit {{code}} funktioniert weiter, wird aber nicht mehr genehmigt.
        // Eine Vorlage fuer alle drei Code-Felder genuegt.
        $code = 'Vorlage der Kategorie AUTHENTICATION mit Knopf „Code kopieren" (z. B. ma_portal_code, 10 Minuten Ablauf) — eine Vorlage für alle drei Code-Felder. '
            . 'Ältere UTILITY-Vorlagen mit &#123;&#123;code&#125;&#125; (Pflicht), optional Vorname, ohne Knopf mit Variable funktionieren weiter.';

        $hinweise = [
            PortalVorlagen::CODE_PASSWORT       => $code,
            PortalVorlagen::CODE_NUMMERNWECHSEL => $code,
            PortalVorlagen::CODE_NOTFALL        => $code . ' Geht nur raus, wenn der Notfall-Weg freigeschaltet ist.',
            PortalVorlagen::HINWEIS_ALTE_NUMMER => 'Ohne Platzhalter oder nur mit Vorname. Nennt die neue Nummer bewusst nicht.',
            PortalVorlagen::AUFGABEN_NEU        => '&#123;&#123;anzahl&#125;&#125;, optional Vorname, und ein URL-Knopf, dessen Variable am Ende steht.',
            // Anforderungen aus EinladungSender: nur Vorname oder kein
            // Platzhalter, genau ein URL-Knopf auf die Konto-Anlage.
            PortalVorlagen::EINLADUNG           => 'Geht raus bei „Einladung neu“ und „Konto zurücksetzen“ im Konto-Kasten und bei recruiting:konto-einladen --senden (z. B. ma_portal_einladung, UTILITY). '
                . 'Optional Vorname, sonst kein Platzhalter. Genau ein URL-Knopf mit Variable auf …/recruiting/konto/anlegen/&#123;&#123;1&#125;&#125; (die Variable ist der 8-stellige Einladungscode). '
                . 'Leer gelassen zeigt HR Link und Code wie bisher nur an.',
            // Anforderungen aus ProofReminderSender::send(): nur diese Platzhalter
            // sind befuellbar, und buildTokenComponents verlangt genau einen
            // dynamischen URL-Knopf. Seit Markus 12.10.2026 gebuendelt: eine
            // Nachricht je Person, {{anzahl}} neu, nachweis/datum = dringendster.
            'proof_reminder_wa_template_id'     => 'Geht raus, wenn Nachweise bald ablaufen oder abgelaufen sind — eine Nachricht je Person für alle fälligen Nachweise. '
                . 'Alle Platzhalter optional: &#123;&#123;anzahl&#125;&#125; (Zahl der fälligen Nachweise), &#123;&#123;nachweis&#125;&#125; und '
                . '&#123;&#123;datum&#125;&#125; (der dringendste), Vorname. Pflicht ist genau ein URL-Knopf mit Variable für den Portal-Link — sonst geht nichts raus.',
            'document_wa_template_id'           => 'Geht raus, wenn HR ein Dokument zur Kenntnisnahme oder Unterschrift bereitstellt. '
                . 'Vorlage mit &#123;&#123;vorname&#125;&#125; und einem URL-Knopf, dessen Variable am Ende steht.',
            'employee_contract_wa_template_id'  => 'Geht raus, wenn HR in der Mitarbeiterakte einen Vertrag erstellt. Leer gelassen wird das '
                . 'Dokumente-Template genommen. Vorlage mit &#123;&#123;vorname&#125;&#125; und einem URL-Knopf, dessen Variable am Ende steht.',
        ];

        $kopf = ' Keine Kopfzeile mit Variable oder Bild.';
        $mitKopfHinweis = [
            PortalVorlagen::CODE_PASSWORT, PortalVorlagen::CODE_NUMMERNWECHSEL, PortalVorlagen::CODE_NOTFALL,
            PortalVorlagen::HINWEIS_ALTE_NUMMER, PortalVorlagen::AUFGABEN_NEU, PortalVorlagen::EINLADUNG,
        ];

        $felder = [];
        foreach (PortalVorlagen::FELDNAMEN as $key => $label) {
            $felder[] = [
                'key'       => $key,
                'label'     => $label,
                'hinweis'   => $hinweise[$key] . (in_array($key, $mitKopfHinweis, true) ? $kopf : ''),
                'nullLabel' => $key === 'employee_contract_wa_template_id' ? '– wie Dokumente –' : '– Template wählen –',
            ];
        }

        return $felder;
    }

    public function addServiceHours(): void
    {
        $serviceHours = new RecServiceHours();
        $serviceHours->rec_applicant_settings_id = $this->settingsModel->id;
        $serviceHours->name = $this->newServiceZeit['name'];
        $serviceHours->description = $this->newServiceZeit['description'];
        $serviceHours->is_active = $this->newServiceZeit['is_active'];
        $serviceHours->use_auto_messages = $this->newServiceZeit['use_auto_messages'];
        $serviceHours->auto_message_inside = $this->newServiceZeit['auto_message_inside'];
        $serviceHours->auto_message_outside = $this->newServiceZeit['auto_message_outside'];
        $serviceHours->service_hours = $this->newServiceZeit['service_hours'];
        $serviceHours->order = $this->settingsModel->serviceHours()->count();
        $serviceHours->save();

        $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        $this->newServiceZeit = [
            'name' => '',
            'description' => '',
            'is_active' => true,
            'use_auto_messages' => false,
            'auto_message_inside' => '',
            'auto_message_outside' => '',
            'service_hours' => RecServiceHours::getDefaultServiceHours(),
        ];
        $this->showServiceHoursForm = false;
    }

    public function deleteServiceHours($serviceHoursId): void
    {
        $serviceHours = RecServiceHours::find($serviceHoursId);
        if ($serviceHours && $serviceHours->rec_applicant_settings_id == $this->settingsModel->id) {
            $serviceHours->delete();
            $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        }
    }

    public function toggleServiceHoursForm(): void
    {
        $this->showServiceHoursForm = !$this->showServiceHoursForm;
    }

    #[Computed]
    public function openPostings(): array
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        return \Platform\Recruiting\Models\RecPosting::forTeam($teamId)
            ->open()
            ->orderBy('title')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])
            ->toArray();
    }

    #[Computed]
    public function payrollFieldGroups(): array
    {
        return RecApplicantSettings::PAYROLL_TRACKABLE_FIELDS;
    }

    #[Computed]
    public function availableStatuses()
    {
        return RecApplicantStatus::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableWhatsAppTemplates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->with('whatsappAccount:id,title,phone_number')
            ->where('status', 'APPROVED');

        $accountId = $this->settings['auto_pilot_wa_account_id'] ?? null;
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        $options = $query->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->name} ({$t->language})" . (!$accountId && $t->whatsappAccount ? " — {$t->whatsappAccount->title}" : ''),
            ])
            ->values()
            ->all();

        return array_merge($options, $this->orphanedTemplateOptions($options));
    }

    /**
     * Die URL, die im Meta-Template hinter dem Zertifikat-Button stehen muss.
     *
     * Abgeleitet aus der Route (Spec W7): Host, Praefix und Pfad kommen alle aus
     * der laufenden App, damit der Hinweistext nicht neben der Wirklichkeit
     * steht. Was bei Meta WIRKLICH hinterlegt ist, sieht das Modul nie — deshalb
     * steht der Preis im Hinweistext daneben.
     */
    #[Computed]
    public function metaButtonUrl(): string
    {
        return \Platform\Recruiting\Support\TrainingCertificateWaTemplate::metaButtonUrlFrom(
            route(
                \Platform\Recruiting\Support\TrainingCertificateWaTemplate::ROUTE_NAME,
                ['uuid' => \Platform\Recruiting\Support\TrainingCertificateWaTemplate::UUID_SENTINEL]
            )
        );
    }

    /**
     * Bereits konfigurierte Templates, die durch den Account-Filter (oder eine
     * zurückgezogene Meta-Freigabe) aus der Liste fallen, trotzdem anbieten —
     * sonst zeigt das Select "nicht gewählt", während der Wert gespeichert
     * bleibt und weiter versendet wird. Mit Warn-Präfix, damit die Fehl-
     * konfiguration sichtbar ist und geändert werden kann.
     *
     * @param array<array{id: int, label: string}> $options
     * @return array<array{id: int, label: string}>
     */
    private function orphanedTemplateOptions(array $options): array
    {
        $listed = array_column($options, 'id');

        $configured = [];
        foreach ($this->settings as $key => $value) {
            if (str_ends_with((string) $key, '_template_id') && (int) $value > 0) {
                $configured[] = (int) $value;
            }
        }

        $missing = array_values(array_diff(array_unique($configured), $listed));
        if ($missing === []) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->with('whatsappAccount:id,title,phone_number')
            ->whereIn('id', $missing)
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "⚠ {$t->name} ({$t->language})"
                    . ($t->whatsappAccount ? " — {$t->whatsappAccount->title}" : '')
                    . ($t->status !== 'APPROVED' ? " — {$t->status}" : ' — anderer Account'),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function availableWhatsAppAccounts(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppAccount::query()
            ->withCount('templates')
            ->orderBy('title')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => "{$a->title} ({$a->phone_number})",
                'active' => $a->active,
                'templates_count' => $a->templates_count,
            ])
            ->toArray();
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.applicant-settings-modal');
    }
}
