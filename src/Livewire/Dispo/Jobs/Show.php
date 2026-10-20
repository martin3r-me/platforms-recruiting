<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Jobs\SendDispoJobWave;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfrageAnlegen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfragen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFortschritt;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobKandidaten;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVorlagenWaechter;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasHaken;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo;

/**
 * Seite einer Anfrage „Jobs anbieten“ (Spec 2026-10-12 §4.6–4.7): Kopf,
 * Schichten mit Zaehlern, Wellen (Fortschritt, Abbrechen, neue Welle) und
 * Rueckmeldungen mit dem Haken „in ZAS eingetragen“.
 *
 * Jede Aktion laedt die Anfrage team-gescopt neu (anfrage()); IDs aus dem
 * Browser werden nur innerhalb dieser Anfrage gesucht.
 */
class Show extends Component
{
    /** Reihenfolge der disjunkten Baender fuer den Vorschlag „naechste Gruppe“. */
    private const BAND_FOLGE = ['0', '1-4', '5-9', '10-19', '20+'];

    /** Ab so vielen Minuten Verzug gilt eine faellige, ungestartete Welle als haengend. */
    public const HAENGT_MINUTEN = 10;

    #[Locked]
    public int $requestId = 0;

    public bool $neueWelleOffen = false;
    /** @var list<string> */
    public array $wGruppen = [];
    /** @var list<string> Blocknummer oder 'ohne' */
    public array $wBloecke = [];
    public bool $wNurPassend = true;
    /** @var list<string> ZAS-Status GO/MA der neuen Welle — vorbelegt aus der letzten Welle. */
    public array $wStati = ['GO'];
    public string $wStartModus = 'jetzt';
    public string $wMinuten = '30';

    /** Vorschau-Zahlen der neuen Welle — nur bei Aenderung der Auswahl gerechnet, nicht bei jedem Poll. */
    #[Locked]
    public ?array $wZahlen = null;

    public string $fehler = '';
    public string $hinweis = '';

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
        $request = $this->anfrage();
        // Spec §4.7: letzter Tag vorbei → abgeschlossen (wie in der Uebersicht).
        if (DispoJobAnfragen::abgelaufeneSchliessen($this->teamId()) > 0) {
            $request->refresh();
        }

        $letzte = $request->waves()->orderByDesc('nr')->first();
        if ($letzte !== null) {
            $this->wBloecke = array_map(fn ($b) => $b === null ? DispoJobZahlen::OHNE : (string) $b, (array) $letzte->ma_bloecke);
            $this->wGruppen = self::naechsteGruppe((array) $letzte->gruppen);
            $this->wNurPassend = (bool) $letzte->nur_passende_taetigkeit;
            // Alte Welle ohne Statusauswahl wurde an GO + MA geschickt.
            $this->wStati = $letzte->stati === null ? array_keys(DispoJobRecipientPlanner::STATI) : DispoJobRecipientPlanner::stati((array) $letzte->stati);
        }
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    /** Team-gescopt — sonst 404. */
    private function anfrage(): RecDispoJobRequest
    {
        $request = RecDispoJobRequest::query()->where('team_id', $this->teamId())->whereKey($this->requestId)->first();
        if ($request === null) {
            abort(404);
        }

        return $request;
    }

    /** @param list<string> $letzte */
    public static function naechsteGruppe(array $letzte): array
    {
        if (in_array('alle', $letzte, true)) {
            return [];
        }
        $max = -1;
        foreach ($letzte as $g) {
            $i = array_search((string) $g, self::BAND_FOLGE, true);
            if ($i !== false) {
                $max = max($max, $i);
            }
        }
        $naechste = self::BAND_FOLGE[$max + 1] ?? null;

        return $naechste === null ? [] : [$naechste];
    }

    private static function istOffen(RecDispoJobRequest $r): bool
    {
        return $r->closed_at === null && $r->status !== RecDispoJobRequest::STATUS_ABGESCHLOSSEN;
    }

    // ------------------------------------------------------------------
    // Daten
    // ------------------------------------------------------------------

    #[Computed]
    public function vorgang(): RecDispoJobRequest
    {
        return $this->anfrage();
    }

    #[Computed]
    public function vorlageName(): string
    {
        $id = $this->vorgang->whatsapp_template_id;
        if ($id === null || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return '–';
        }

        return (string) (\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()->whereKey($id)->value('name') ?? '–');
    }

    #[Computed]
    public function schichten(): Collection
    {
        $angenommen = fn ($q) => $q->whereNotNull('accepted_at');

        return $this->vorgang->shifts()
            ->with('event:id,name')
            ->withCount([
                'offerShifts as zugesagt' => $angenommen,
                'offerShifts as eingetragen' => fn ($q) => $angenommen($q)->whereNotNull('zas_entered_at'),
                'offerShifts as angekommen' => fn ($q) => $angenommen($q)->whereNotNull('zas_assignment_id'),
            ])
            ->orderBy('datum')->orderBy('von')->orderBy('taetigkeit')
            ->get();
    }

    /** @return array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, prozent:int, offen:list<\Platform\Recruiting\Models\RecDispoJobShift>, voll:list<\Platform\Recruiting\Models\RecDispoJobShift>} */
    #[Computed]
    public function fortschritt(): array
    {
        return DispoJobFortschritt::berechnen($this->schichten);
    }

    /** @return array{zeilen: array<int, list<RecDispoJobOfferShift>>, info: array<int, array<string,mixed>>} */
    #[Computed]
    public function rueckmeldungen(): array
    {
        $shiftIds = $this->schichten->pluck('id')->all();
        if ($shiftIds === []) {
            return ['zeilen' => [], 'info' => []];
        }
        $zeilen = RecDispoJobOfferShift::query()
            ->whereIn('shift_id', $shiftIds)
            ->whereNotNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $this->requestId))
            ->with('offer:id,rec_employee_id,anchor_employee_id')
            ->orderBy('accepted_at')
            ->get();
        $ids = $zeilen->map(fn ($z) => (int) $z->offer?->rec_employee_id)->filter()->unique()->values()->all();

        return [
            'zeilen' => $zeilen->groupBy('shift_id')->map(fn ($g) => $g->all())->all(),
            'info'   => DispoJobZusagenInfo::laden($ids, now()->format('Y-m')),
        ];
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function wellen(): array
    {
        $out = [];
        foreach ($this->vorgang->waves()->orderBy('nr')->get() as $w) {
            $laeuft = $w->started_at !== null && $w->finished_at === null;
            $faellig = $w->started_at === null && $w->finished_at === null && ($w->scheduled_for === null || !$w->scheduled_for->isFuture());
            $fortschritt = $laeuft && $w->job_uuid ? Cache::get(SendDispoJobWave::cacheKey($w->job_uuid)) : null;
            $out[] = [
                'id'          => (int) $w->id,
                'nr'          => (int) $w->nr,
                'gruppen'     => array_map(fn ($g) => DispoEinsatzZaehler::BAENDER[(string) $g] ?? (string) $g, (array) $w->gruppen),
                'bloecke'     => array_map(fn ($b) => DispoJobKandidaten::blockLabel($b === null ? null : (int) $b), (array) $w->ma_bloecke),
                'nur_passend' => (bool) $w->nur_passende_taetigkeit,
                'stati'       => DispoJobRecipientPlanner::statiLabel($w->stati),
                'geplant'     => $w->scheduled_for,
                'gestartet'   => $w->started_at,
                'fertig'      => $w->finished_at,
                'wartet'      => $w->started_at === null && $w->finished_at === null,
                'faellig'     => $faellig,
                // Faellig, aber seit ueber HAENGT_MINUTEN nicht gestartet: der Hintergrunddienst laeuft vermutlich nicht.
                'haengt'      => $faellig && ($w->scheduled_for ?? $w->created_at)?->lt(now()->subMinutes(self::HAENGT_MINUTEN)) === true,
                'laeuft'      => $laeuft,
                'stand'       => $laeuft ? (is_array($fortschritt) ? $fortschritt : null) : (is_array($w->summary) ? $w->summary : null),
            ];
        }

        return $out;
    }

    /** @return list<array{block:?int, label:string, nummern:list<string>, count:int}> */
    #[Computed(persist: true, seconds: 300)]
    public function blockOptionen(): array
    {
        return DispoJobKandidaten::blockOptionen($this->teamId());
    }

    private function auswahl(): array
    {
        $erlaubt = array_map(fn ($o) => $o['block'] === null ? DispoJobZahlen::OHNE : (string) $o['block'], $this->blockOptionen);

        return [
            'gruppen'     => DispoJobZahlen::gruppen($this->wGruppen),
            'bloecke'     => DispoJobZahlen::bloecke(array_intersect($this->wBloecke, $erlaubt)),
            'nur_passend' => $this->wNurPassend,
            'stati'       => DispoJobRecipientPlanner::stati($this->wStati),
        ];
    }

    /** Offene, kuenftige Schichten — dieselbe Menge, die der Job beim Start anbietet. */
    private function offeneSchichten(RecDispoJobRequest $request): array
    {
        return $request->shifts()->whereNull('closed_at')->whereDate('datum', '>=', now()->toDateString())->get()
            ->map(fn ($s) => ['id' => (int) $s->id, 'datum' => $s->datum->format('Y-m-d'), 'taetigkeit' => (string) $s->taetigkeit,
                'event' => (int) $s->rec_dispo_event_id, 'von' => (string) $s->von])->all();
    }

    private function zahlenRechnen(): void
    {
        $request = $this->anfrage();
        $schon = RecDispoJobOffer::query()->where('request_id', $request->id)->pluck('anchor_employee_id')
            ->mapWithKeys(fn ($v) => [(int) $v => true])->all();
        $this->wZahlen = DispoJobZahlen::berechnen($this->teamId(), $this->offeneSchichten($request), $schon, $this->auswahl());
    }

    // ------------------------------------------------------------------
    // Aktionen
    // ------------------------------------------------------------------

    public function neueWelleUmschalten(): void
    {
        $this->fehler = '';
        $this->neueWelleOffen = !$this->neueWelleOffen && self::istOffen($this->anfrage());
        $this->wZahlen = null;
        if ($this->neueWelleOffen) {
            $this->zahlenRechnen();
        }
    }

    public function updated(string $name): void
    {
        if ($this->neueWelleOffen && in_array(explode('.', $name)[0], ['wGruppen', 'wBloecke', 'wNurPassend', 'wStati'], true)) {
            $this->zahlenRechnen();
        }
    }

    public function neueWelle(): void
    {
        $this->fehler = '';
        $this->hinweis = '';
        $request = $this->anfrage();
        if (!self::istOffen($request)) {
            $this->fehler = 'Die Anfrage ist abgeschlossen.';

            return;
        }
        $auswahl = $this->auswahl();
        $minuten = 0;
        if ($this->wStartModus === 'spaeter') {
            $minuten = ctype_digit(trim($this->wMinuten)) ? (int) trim($this->wMinuten) : 0;
        }
        $fehler = match (true) {
            $auswahl['bloecke'] === [] => 'Bitte wähle mindestens eine Mitarbeiterfiliale.',
            $auswahl['gruppen'] === [] => 'Bitte wähle mindestens eine Gruppe.',
            $auswahl['stati'] === [] => 'Bitte wähle mindestens einen Status.',
            $this->offeneSchichten($request) === [] => 'Alle Schichten dieser Anfrage liegen in der Vergangenheit.',
            $this->wStartModus === 'spaeter' && ($minuten < 1 || $minuten > 1440) => 'Bitte gib zwischen 1 und 1440 Minuten an.',
            default => null,
        };
        if ($fehler !== null) {
            $this->fehler = $fehler;

            return;
        }

        // Vorlage je Welle neu pruefen — sie kann seit dem Anlegen bei Meta geaendert oder abgelehnt worden sein.
        $vorlagenProblem = DispoJobVorlagenWaechter::fuerVorlage($request->whatsapp_template_id !== null ? (int) $request->whatsapp_template_id : null);
        if ($vorlagenProblem !== null) {
            $this->fehler = 'Mit dieser Vorlage kannst du keine Welle starten: ' . $vorlagenProblem
                . ' Lege bitte eine neue Anfrage mit einer passenden Vorlage an.';

            return;
        }

        $wave = DispoJobAnfrageAnlegen::welle($request, $auswahl + ['minuten' => $minuten]);
        DispoJobAnfrageAnlegen::starten($wave, $this->teamId(), auth()->id() !== null ? (int) auth()->id() : null);

        $this->hinweis = $minuten > 0
            ? 'Welle ' . $wave->nr . ' ist geplant für ' . $wave->scheduled_for->format('H:i') . ' Uhr.'
            : 'Welle ' . $wave->nr . ' startet gleich.';
        $this->neueWelleOffen = false;
        $this->wZahlen = null;
        $this->wGruppen = self::naechsteGruppe($auswahl['gruppen']);
    }

    /** Geplante Welle abbrechen = loeschen, solange sie nicht gestartet ist (der Job ueberspringt fehlende Wellen). */
    public function abbrechen(int $waveId): void
    {
        $this->fehler = '';
        $request = $this->anfrage();
        $geloescht = RecDispoJobWave::query()->whereKey($waveId)->where('request_id', $request->id)->whereNull('started_at')->delete();
        if ($geloescht === 0) {
            $this->fehler = 'Die Welle läuft schon — abbrechen geht nicht mehr.';
        }
    }

    public function zasHaken(int $offerShiftId): void
    {
        DispoJobZasHaken::umschalten($this->anfrage(), $offerShiftId, auth()->id() !== null ? (int) auth()->id() : null);
    }

    public function alleEintragen(int $shiftId): void
    {
        $this->fehler = '';
        $n = DispoJobZasHaken::alle($this->anfrage(), $shiftId, auth()->id() !== null ? (int) auth()->id() : null);
        $this->hinweis = $n > 0 ? $n . ' ' . ($n === 1 ? 'Zusage' : 'Zusagen') . ' als in ZAS eingetragen markiert.' : '';
    }

    public function abschliessen(): void
    {
        $request = $this->anfrage();
        if (self::istOffen($request)) {
            $request->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => now()]);
        }
        $this->neueWelleOffen = false;
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.jobs.show', [
            'offen' => self::istOffen($this->vorgang),
            'toepfeLabels' => DispoJobRecipientPlanner::TOEPFE,
        ])->layout('platform::layouts.app');
    }
}
