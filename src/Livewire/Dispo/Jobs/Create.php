<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfrageAnlegen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnlass;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobCreateState;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobKandidaten;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchichten;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobTage;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVorlagenWaechter;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;
use Platform\Recruiting\Support\CampaignTemplatePreview;
use Platform\Recruiting\Support\Filialen;

/**
 * Jobs anbieten → Neue Anfrage in drei Schritten (Spec 2026-10-14 §3).
 *
 * Alles, was der Browser schickt, gilt als Wunsch: VAs, Schichten, Anlass und
 * Bloecke werden beim Anlegen gegen die serverseitig berechneten Optionen
 * gefiltert. Zahlenfelder sind Strings (typisierte int-Properties an
 * wire:model crashen bei leerem Feld).
 */
class Create extends Component
{
    public const SESSION_FILIALE = 'dispo_jobs_filiale';

    #[Url]
    public ?int $event = null;

    #[Locked]
    public int $schritt = 1;

    public string $filialNr = '';
    public string $von = '';
    public string $bis = '';
    /** @var list<string> */
    public array $eventIds = [];
    /** Aktive Tag-Pillen je VA: event_id => list<Y-m-d>. @var array<int, list<string>> */
    public array $tage = [];
    /** DispoJobSchichten::schluessel => Zahl (String). @var array<string, string> */
    public array $gesucht = [];
    /** Freie Schichten: list<{event, datum, von, taetigkeit, gesucht}>. */
    public array $andere = [];

    public string $neuEventTag = '';
    public string $neuVon = '';
    public string $neuTaetigkeit = '';
    public string $neuGesucht = '';

    public string $modus = RecDispoJobRequest::MODUS_SAMMELN;
    /** DispoJobAnlass-Schluessel; die Vorlage ergibt sich aus den Einstellungen. */
    public string $anlass = '';
    /** @var list<string> Blocknummer oder 'ohne' */
    public array $maBloecke = [];
    /** @var list<string> */
    public array $gruppen = ['0'];
    public bool $nurPassend = true;
    public string $startModus = 'jetzt';
    public string $minuten = '30';

    public string $fehler = '';

    #[Locked]
    public bool $bloeckeVorbelegt = false;

    /** Vorschau „Sendet an N“ — siehe zahlenRechnen(). */
    #[Locked]
    public ?array $zahlen = null;

    /** Zuletzt angebotene Tage je VA — damit neue Tage nach einer Zeitraum-Aenderung aktiv dazukommen. @var array<int, list<string>> */
    #[Locked]
    public array $tageBekannt = [];

    public function mount(): void
    {
        $morgen = now()->addDay()->toDateString();
        $this->von = $morgen;
        $this->bis = now()->addDays(28)->toDateString();
        $this->filialNr = (string) (session(self::SESSION_FILIALE) ?? '');

        // Von der VA-Seite (?event=) ist es meist eilig, ueber das Menue der Normalfall.
        $this->anlass = $this->anlassVorbelegen($this->event !== null ? 'dringend' : 'vier_wochen');

        $va = $this->event !== null ? RecDispoEvent::query()->find($this->event) : null;
        if ($va !== null && $va->filial_nr !== null && $va->starts_on !== null) {
            $start = $va->starts_on->toDateString();
            $ende = ($va->ends_on ?? $va->starts_on)->toDateString();
            $this->filialNr = (string) $va->filial_nr;
            $this->von = max($morgen, $start);
            $this->bis = max($this->von, $ende);
            $this->eventIds = [(string) $va->id];
            $this->schritt = 2;
            $this->tageVorbelegen();
        }
    }

    /** Wunsch-Anlass, sonst der erste mit hinterlegter Vorlage, sonst keiner. */
    private function anlassVorbelegen(string $wunsch): string
    {
        $ids = $this->anlassVorlagen;
        if (($ids[$wunsch] ?? null) !== null) {
            return $wunsch;
        }
        foreach ($ids as $anlass => $id) {
            if ($id !== null) {
                return $anlass;
            }
        }

        return '';
    }

    public function anlassWaehlen(string $anlass): void
    {
        if (DispoJobAnlass::istGueltig($anlass) && ($this->anlassVorlagen[$anlass] ?? null) !== null) {
            $this->anlass = $anlass;
        }
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------

    public function weiter(): void
    {
        $this->fehler = '';
        if ($this->schritt >= 3) {
            return;
        }
        $fehler = DispoJobCreateState::kannWeiter($this->schritt, $this->state($this->schritt));
        if ($fehler !== null) {
            $this->fehler = $fehler;

            return;
        }
        if ($this->schritt === 1) {
            session([self::SESSION_FILIALE => $this->filialNr]);
        }
        $this->schritt++;
        if ($this->schritt === 2) {
            $this->tageVorbelegen();
        }
        if ($this->schritt === 3) {
            $this->bloeckeVorbelegen();
            $this->zahlenRechnen();
        }
    }

    public function zurueck(): void
    {
        $this->fehler = '';
        $this->schritt = max(1, $this->schritt - 1);
    }

    /** Nur rueckwaerts springen — vorwaerts geht es ausschliesslich ueber die Pruefung in weiter(). */
    public function zuSchritt(int $schritt): void
    {
        if ($schritt >= 1 && $schritt < $this->schritt) {
            $this->fehler = '';
            $this->schritt = $schritt;
        }
    }

    public function updatedFilialNr(): void
    {
        $this->eventIds = [];
        $this->tage = [];
        $this->tageBekannt = [];
        $this->gesucht = [];
        $this->andere = [];
        $this->maBloecke = [];
        $this->bloeckeVorbelegt = false;
    }

    public function updatedVon(): void
    {
        $this->hakenAngleichen();
    }

    public function updatedBis(): void
    {
        $this->hakenAngleichen();
    }

    /** Veranstaltungen, die nach einer Zeitraum-Aenderung nicht mehr gelistet sind, verlieren ihren Haken. */
    private function hakenAngleichen(): void
    {
        unset($this->events);
        $this->eventIds = DispoJobCreateState::hakenAngleichen($this->eventIds, $this->events->keys()->all());
    }

    public function tagUmschalten(int $eventId, string $datum): void
    {
        $aktiv = $this->tage[$eventId] ?? [];
        $this->tage[$eventId] = in_array($datum, $aktiv, true)
            ? array_values(array_diff($aktiv, [$datum]))
            : array_values(array_merge($aktiv, [$datum]));
        sort($this->tage[$eventId]);
    }

    public function andereHinzufuegen(): void
    {
        $this->fehler = '';
        [$eventId, $datum] = array_pad(explode('|', $this->neuEventTag, 2), 2, '');
        $eventId = (int) $eventId;
        $von = trim($this->neuVon);
        $taet = trim($this->neuTaetigkeit);
        $gesucht = ctype_digit(trim($this->neuGesucht)) ? (int) trim($this->neuGesucht) : 0;

        if (!in_array($datum, $this->eventTage[$eventId] ?? [], true)) {
            $this->fehler = 'Bitte wähle Veranstaltung und Tag der Schicht.';

            return;
        }
        if ($von !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $von) !== 1) {
            $this->fehler = 'Der Beginn muss als Uhrzeit (HH:MM) angegeben werden.';

            return;
        }
        if (!in_array($taet, $this->taetigkeitKatalog, true)) {
            $this->fehler = 'Bitte wähle eine Tätigkeit aus der Liste.';

            return;
        }
        if ($gesucht <= 0) {
            $this->fehler = 'Trag ein, wie viele Leute du für diese Schicht suchst.';

            return;
        }

        $this->andere[] = ['event' => $eventId, 'datum' => $datum, 'von' => $von, 'taetigkeit' => $taet, 'gesucht' => (string) $gesucht];
        $this->neuVon = '';
        $this->neuTaetigkeit = '';
        $this->neuGesucht = '';
    }

    public function andereEntfernen(int $index): void
    {
        unset($this->andere[$index]);
        $this->andere = array_values($this->andere);
    }

    /**
     * Tag-Pillen an den (evtl. geaenderten) Zeitraum angleichen: weggefallene
     * Tage raus, neue Tage aktiv dazu, abgewaehlte bestehende bleiben abgewaehlt.
     */
    private function tageVorbelegen(): void
    {
        foreach ($this->schichtOptionen as $eventId => $tage) {
            $verfuegbar = array_keys($tage);
            $this->tage[$eventId] = DispoJobTage::angleichen($this->tage[$eventId] ?? [], $verfuegbar, $this->tageBekannt[$eventId] ?? []);
            $this->tageBekannt[$eventId] = $verfuegbar;
        }
    }

    private function bloeckeVorbelegen(): void
    {
        if ($this->bloeckeVorbelegt) {
            return;
        }
        $this->bloeckeVorbelegt = true;
        if ($this->maBloecke !== []) {
            return;
        }
        foreach ($this->blockOptionen as $o) {
            if ($o['block'] !== null && (string) $o['block'] === $this->filialNr) {
                $this->maBloecke = [(string) $o['block']];
            }
        }
    }

    // ------------------------------------------------------------------
    // Daten
    // ------------------------------------------------------------------

    /** @return array<int,string> */
    #[Computed]
    public function filialOptionen(): array
    {
        $out = [];
        foreach (RecDispoEvent::query()->whereNotNull('filial_nr')->distinct()->orderBy('filial_nr')->pluck('filial_nr') as $nr) {
            $out[(int) $nr] = Filialen::code((int) $nr) ?? ('#' . $nr);
        }

        return $out;
    }

    private function zeitraumGueltig(): bool
    {
        // Eigenes Array statt state(): state() holt die Veranstaltungen, die ihrerseits diese Pruefung brauchen.
        return DispoJobCreateState::zeitraumProblem(['heute' => now()->toDateString(), 'von' => $this->von, 'bis' => $this->bis]) === null;
    }

    /** VAs der Filiale mit mindestens einer (nicht verschwundenen) Einbuchung im Zeitraum. */
    #[Computed]
    public function events(): Collection
    {
        if (!ctype_digit($this->filialNr) || !$this->zeitraumGueltig()) {
            return collect();
        }
        $scope = fn ($q) => $q->whereNull('missing_since')->whereNull('deletion_marked_at')
            ->whereDate('datum', '>=', $this->von)->whereDate('datum', '<=', $this->bis);

        return RecDispoEvent::query()
            ->where('filial_nr', (int) $this->filialNr)
            ->whereHas('assignments', $scope)
            ->withCount(['assignments as einbuchungen' => $scope])
            ->orderByRaw('starts_on IS NULL, starts_on ASC')
            ->get()
            ->keyBy('id');
    }

    /** Gewaehlte VAs — nur solche, die die Liste auch anbietet. */
    #[Computed]
    public function gewaehlteEvents(): Collection
    {
        $ids = array_map('intval', $this->eventIds);

        return $this->events->filter(fn ($e) => in_array((int) $e->id, $ids, true));
    }

    /** @return array<int, array<string, list<array{key:string, von:string, taetigkeit:string, zeilen:int, platzhalter:int}>>> */
    #[Computed]
    public function schichtOptionen(): array
    {
        $ids = $this->gewaehlteEvents->keys()->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return [];
        }
        $rows = RecDispoAssignment::query()
            ->whereIn('rec_dispo_event_id', $ids)
            ->whereDate('datum', '>=', $this->von)->whereDate('datum', '<=', $this->bis)
            ->get(['rec_dispo_event_id', 'datum', 'von', 'taetigkeit', 'pnr_raw', 'missing_since', 'deletion_marked_at'])
            ->groupBy('rec_dispo_event_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            foreach (DispoJobSchichten::optionen($rows->get($id, collect()), []) as $datum => $schichten) {
                $out[$id][$datum] = array_map(
                    fn ($s) => ['key' => DispoJobSchichten::schluessel($id, $datum, $s['von'], $s['taetigkeit'])] + $s,
                    $schichten
                );
            }
        }

        return $out;
    }

    /**
     * Tage, an denen eine „andere Schicht“ liegen darf: Einsatztage aus ZAS plus
     * die Laufzeit der VA, beides im Zeitraum.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function eventTage(): array
    {
        $out = [];
        foreach ($this->gewaehlteEvents as $id => $e) {
            $tage = array_keys($this->schichtOptionen[(int) $id] ?? []);
            if ($e->starts_on !== null) {
                $tag = Carbon::parse(max($this->von, $e->starts_on->toDateString()));
                $ende = min($this->bis, ($e->ends_on ?? $e->starts_on)->toDateString());
                for ($i = 0; $i < 366 && $tag->toDateString() <= $ende; $i++, $tag->addDay()) {
                    $tage[] = $tag->toDateString();
                }
            }
            $tage = array_values(array_unique($tage));
            sort($tage);
            $out[(int) $id] = $tage;
        }

        return $out;
    }

    /** @return list<string> Katalog der Taetigkeiten aus ZAS (Lookup dispo_taetigkeit). */
    #[Computed]
    public function taetigkeitKatalog(): array
    {
        $lookupId = DB::table('core_lookups')->where('team_id', $this->teamId())->where('name', ZasDispoTaetigkeitSync::LOOKUP)->value('id');
        if ($lookupId === null) {
            return [];
        }
        $werte = DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value')
            ->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();
        sort($werte, SORT_NATURAL | SORT_FLAG_CASE);

        return $werte;
    }

    /**
     * Alle Schichten der aktuellen Auswahl (ZAS-Schichten der aktiven Tage + andere),
     * auch mit gesucht 0 — DispoJobAnfrageAnlegen filtert.
     *
     * @return list<array{event:int, datum:string, von:string, taetigkeit:string, gesucht:int}>
     */
    #[Computed]
    public function gewaehlteSchichten(): array
    {
        $out = [];
        foreach ($this->schichtOptionen as $eventId => $tage) {
            $aktiv = $this->tage[$eventId] ?? [];
            foreach ($tage as $datum => $schichten) {
                if (!in_array($datum, $aktiv, true)) {
                    continue;
                }
                foreach ($schichten as $s) {
                    $out[] = ['event' => (int) $eventId, 'datum' => $datum, 'von' => $s['von'], 'taetigkeit' => $s['taetigkeit'],
                        'gesucht' => self::zahl($this->gesucht[$s['key']] ?? '')];
                }
            }
        }
        $eventTage = $this->eventTage;
        $katalog = $this->taetigkeitKatalog;
        foreach ($this->andere as $a) {
            $eventId = (int) ($a['event'] ?? 0);
            $datum = (string) ($a['datum'] ?? '');
            $von = trim((string) ($a['von'] ?? ''));
            $taet = trim((string) ($a['taetigkeit'] ?? ''));
            if (!in_array($datum, $eventTage[$eventId] ?? [], true) || !in_array($taet, $katalog, true)
                || ($von !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $von) !== 1)) {
                continue;
            }
            $out[] = ['event' => $eventId, 'datum' => $datum, 'von' => $von, 'taetigkeit' => $taet, 'gesucht' => self::zahl($a['gesucht'] ?? '')];
        }

        return $out;
    }

    /** @return list<array{block:?int, label:string, nummern:list<string>, count:int}> */
    #[Computed(persist: true, seconds: 300)]
    public function blockOptionen(): array
    {
        return DispoJobKandidaten::blockOptionen($this->teamId());
    }

    /** @return array<string, ?int> Anlass => in den Einstellungen hinterlegte Vorlagen-ID */
    #[Computed]
    public function anlassVorlagen(): array
    {
        return DispoJobAnlass::templateIds(RecApplicantSettings::getOrCreateForTeam($this->teamId()));
    }

    /** @return array<int, object> id => Vorlage (nur die in den Einstellungen hinterlegten) */
    #[Computed]
    public function vorlagen(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter($this->anlassVorlagen)));
        if ($ids === []) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name', 'language', 'status', 'components'])
            ->keyBy('id')->all();
    }

    /** Die zum gewaehlten Anlass hinterlegte Vorlagen-ID (null = keiner gewaehlt / nichts hinterlegt). */
    #[Computed]
    public function vorlagenId(): ?int
    {
        return DispoJobAnlass::istGueltig($this->anlass) ? ($this->anlassVorlagen[$this->anlass] ?? null) : null;
    }

    #[Computed]
    public function vorlage(): ?object
    {
        return $this->vorlagenId !== null ? ($this->vorlagen[$this->vorlagenId] ?? null) : null;
    }

    /** @return array{text:?string, buttons:list<string>}|null */
    #[Computed]
    public function vorschau(): ?array
    {
        $t = $this->vorlage;
        if ($t === null) {
            return null;
        }
        $r = CampaignTemplatePreview::render($t->components);

        return ['text' => $r['text'], 'buttons' => $r['buttons']];
    }

    #[Computed]
    public function vorlagenProblem(): ?string
    {
        if ($this->vorlagen === []) {
            return 'In den Dispo-Einstellungen ist noch keine Vorlage für Jobangebote hinterlegt.';
        }
        if (!DispoJobAnlass::istGueltig($this->anlass)) {
            return 'Noch kein Anlass gewählt — ohne ihn geht nichts raus.';
        }
        if ($this->vorlagenId === null) {
            return 'Für diesen Anlass ist noch keine Vorlage hinterlegt — bitte in den Einstellungen hinterlegen.';
        }
        $t = $this->vorlage;
        if ($t === null) {
            return 'Die Vorlage für diesen Anlass ist nicht mehr verfügbar — bitte in den Einstellungen eine andere hinterlegen.';
        }
        if ($t->status !== 'APPROVED') {
            return 'Die Vorlage „' . $t->name . '“ ist bei Meta nicht (mehr) freigegeben — bitte in den Einstellungen eine andere hinterlegen.';
        }

        return DispoJobVorlagenWaechter::problem($t->components);
    }

    /**
     * Zahlen vor dem Senden mit der AKTUELLEN Auswahl (neue Anfrage: noch
     * niemand angeschrieben). Gespeichert statt Computed: der Lauf laedt alle
     * Mitarbeiter und soll nur bei Eintritt in Schritt 3 und bei Aenderung von
     * Bloecken/Gruppen/Haken laufen — nicht bei Anlass, Modus oder Startzeit.
     */
    private function zahlenRechnen(): void
    {
        $heute = now()->toDateString();
        $schichten = [];
        foreach ($this->gewaehlteSchichten as $i => $s) {
            if ($s['gesucht'] > 0 && $s['datum'] >= $heute) {
                $schichten[] = ['id' => $i + 1, 'datum' => $s['datum'], 'taetigkeit' => $s['taetigkeit'], 'event' => (int) $s['event'], 'von' => (string) $s['von']];
            }
        }
        $this->zahlen = DispoJobZahlen::berechnen($this->teamId(), $schichten, [], $this->auswahl());
    }

    public function updated(string $name): void
    {
        if ($this->schritt === 3 && in_array(explode('.', $name)[0], ['maBloecke', 'gruppen', 'nurPassend'], true)) {
            $this->zahlenRechnen();
        }
    }

    private function auswahl(): array
    {
        $erlaubt = array_map(fn ($o) => $o['block'] === null ? DispoJobZahlen::OHNE : (string) $o['block'], $this->blockOptionen);

        return [
            'gruppen'     => DispoJobZahlen::gruppen($this->gruppen),
            'bloecke'     => DispoJobZahlen::bloecke(array_intersect($this->maBloecke, $erlaubt)),
            'nur_passend' => $this->nurPassend,
        ];
    }

    /**
     * Zustand fuer DispoJobCreateState — nur serverseitig bekannte Werte, und
     * nur so viel, wie Schritt $bisSchritt braucht (Schritt 3 laedt alle
     * Mitarbeiter fuer die Bloecke — das soll nicht schon in Schritt 1 laufen).
     */
    private function state(int $bisSchritt = 3): array
    {
        $state = [
            'heute'     => now()->toDateString(),
            'filial_nr' => $this->filialNr,
            'von'       => $this->von,
            'bis'       => $this->bis,
        ];
        $state['event_ids'] = $this->gewaehlteEvents->keys()->all();
        if ($bisSchritt >= 2) {
            $state['gesucht'] = array_column($this->gewaehlteSchichten, 'gesucht');
            $state['andere'] = []; // schon in gesucht enthalten (gewaehlteSchichten prueft sie)
        }
        if ($bisSchritt >= 3) {
            $auswahl = $this->auswahl();
            $state += [
                'anlass'           => $this->anlass,
                'template_id'      => (string) ($this->vorlagenId ?? ''),
                'vorlagen_problem' => $this->vorlagenProblem,
                'gruppen'          => $auswahl['gruppen'],
                'bloecke'          => $auswahl['bloecke'],
                'modus'            => $this->modus,
            ];
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Anlegen
    // ------------------------------------------------------------------

    public function anlegenUndSenden(): void
    {
        $this->fehler = '';
        if ($this->schritt !== 3) {
            return;
        }
        $fehler = DispoJobCreateState::alles($this->state());
        $minuten = 0;
        if ($fehler === null && $this->startModus === 'spaeter') {
            $minuten = ctype_digit(trim($this->minuten)) ? (int) trim($this->minuten) : 0;
            if ($minuten < 1 || $minuten > 1440) {
                $fehler = 'Bitte gib zwischen 1 und 1440 Minuten an.';
            }
        }
        if ($fehler !== null) {
            $this->fehler = $fehler;

            return;
        }

        $auswahl = $this->auswahl();
        $teamId = $this->teamId();
        $userId = auth()->id() !== null ? (int) auth()->id() : null;
        $request = DispoJobAnfrageAnlegen::anlegen([
            'filial_nr'   => (int) $this->filialNr,
            'von'         => $this->von,
            'bis'         => $this->bis,
            'modus'       => $this->modus,
            'anlass'      => $this->anlass,
            'template_id' => (int) $this->vorlagenId,
            'schichten'   => $this->gewaehlteSchichten,
            'minuten'     => $minuten,
        ] + $auswahl, $teamId, $userId);

        // Erst nach dem Commit (anlegen() hat seine Transaktion abgeschlossen).
        $wave = $request->waves()->orderBy('nr')->first();
        DispoJobAnfrageAnlegen::starten($wave, $teamId, $userId);

        session([self::SESSION_FILIALE => $this->filialNr]);
        $this->redirectRoute('recruiting.dispo.jobs.show', ['requestId' => $request->id]);
    }

    private static function zahl(mixed $v): int
    {
        $s = trim((string) $v);

        return ctype_digit($s) ? min(9999, (int) $s) : 0;
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.jobs.create')->layout('platform::layouts.app');
    }
}
