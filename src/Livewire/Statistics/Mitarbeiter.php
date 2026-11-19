<?php

namespace Platform\Recruiting\Livewire\Statistics;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecKostenstellenSoll;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikDaten;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\Personalreport;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;
use Platform\Recruiting\Support\Filialen;

/**
 * Statistik → Reiter „Mitarbeiter“ (Markus 18./19.11.2026): aktive und produktive Mitarbeiter je
 * Kostenstelle, Termine im Zeitraum in Stufen, Drill-down bei 0 Terminen (mit/ohne Rückmeldung),
 * Soll je Kostenstelle und Empfänger des täglichen Reports. Gerechnet wird im
 * MitarbeiterStatistikRechner, diese Klasse lädt und zeigt nur.
 */
class Mitarbeiter extends Component
{
    /** Y-m-d-Strings (x-ui-input-date nie an Datums-Casts binden). Standard: letzte 4 Wochen. */
    public ?string $von = null;
    public ?string $bis = null;

    /** 'alle' | 'ohne' | Nummer der Kostenstelle — untyped wie die anderen Selects. */
    public $kostenstelleFilter = 'alle';

    public string $drillKostenstelle = '';
    public string $drillMerkmal = '';
    public bool $showDrill = false;

    public bool $showEinstellungen = false;
    /** @var array<string,string> kostenstelle => Soll (Eingabe) */
    public array $sollWerte = [];
    public ?string $sollGueltigAb = null;
    public string $empfaenger = '';
    public bool $reportAktiv = false;
    public string $einstellungenMeldung = '';

    public function mount(): void
    {
        abort_if(DispoAccess::eventOnly(auth()->user()), 403);
        $this->standardZeitraum();
    }

    private function standardZeitraum(): void
    {
        $heute = now()->toDateString();
        $this->bis = $heute;
        $this->von = MitarbeiterStatistikRechner::minusTage($heute, 27);
    }

    public function updatedVon($value): void
    {
        $this->von = $value ?: null;
        $this->ordneZeitraum();
    }

    public function updatedBis($value): void
    {
        $this->bis = $value ?: null;
        $this->ordneZeitraum();
    }

    /** Leeres oder unsinniges Datum fällt auf den Standard zurück; von > bis wird getauscht. */
    private function ordneZeitraum(): void
    {
        $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        if (!$ok($this->von) || !$ok($this->bis)) {
            $von = $ok($this->von) ? $this->von : null;
            $bis = $ok($this->bis) ? $this->bis : null;
            $this->standardZeitraum();
            $this->von = $von ?? $this->von;
            $this->bis = $bis ?? $this->bis;
        }
        if ($this->von > $this->bis) {
            [$this->von, $this->bis] = [$this->bis, $this->von];
        }
        $this->schliesseDrill();
    }

    public function updatedKostenstelleFilter($value): void
    {
        $this->kostenstelleFilter = ($value === '' || $value === null) ? 'alle' : (string) $value;
        $this->schliesseDrill();
    }

    public static function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
    }

    #[Computed]
    public function auswertung(): array
    {
        $this->ordneZeitraumStill();

        return app(MitarbeiterStatistikDaten::class)->auswerten(self::teamId(), now()->toDateString(), $this->von, $this->bis);
    }

    private function ordneZeitraumStill(): void
    {
        if ($this->von === null || $this->bis === null) {
            $this->standardZeitraum();
        }
    }

    /** Zeilen der Tabelle nach Kostenstellen-Filter. */
    #[Computed]
    public function zeilen(): array
    {
        $alle = $this->auswertung['zeilen'];
        if ($this->kostenstelleFilter === 'alle') {
            return $alle;
        }

        return array_values(array_filter($alle, fn ($z) => ($z['kostenstelle'] === null ? 'ohne' : (string) $z['kostenstelle']) === (string) $this->kostenstelleFilter));
    }

    #[Computed]
    public function summe(): array
    {
        return MitarbeiterStatistikRechner::gesamt($this->zeilen);
    }

    /** @return array<string,string> */
    #[Computed]
    public function kostenstellenOptionen(): array
    {
        $out = ['alle' => 'Alle Kostenstellen'];
        foreach ($this->auswertung['zeilen'] as $z) {
            $key = $z['kostenstelle'] === null ? 'ohne' : (string) $z['kostenstelle'];
            $out[$key] = self::kostenstellenName($z['kostenstelle']);
        }

        return $out;
    }

    public static function kostenstellenName(?int $kostenstelle): string
    {
        if ($kostenstelle === null) {
            return 'ohne Kostenstelle';
        }

        return Filialen::name($kostenstelle) ?? ('Kostenstelle ' . $kostenstelle);
    }

    // ---------------------------------------------------------------- Drill-down

    private const MERKMALE = ['aktiv', 'produktiv', 'nicht_produktiv', 'rueckmeldung:ja', 'rueckmeldung:nein'];

    public function drill(string $kostenstelle, string $merkmal): void
    {
        $merkmalOk = in_array($merkmal, self::MERKMALE, true)
            || (str_starts_with($merkmal, 'stufe:') && array_key_exists(substr($merkmal, 6), MitarbeiterStatistikRechner::STUFEN));
        $ksOk = $kostenstelle === 'alle' || $kostenstelle === 'ohne' || ctype_digit($kostenstelle);
        if (!$merkmalOk || !$ksOk) {
            return;
        }
        $this->drillKostenstelle = $kostenstelle;
        $this->drillMerkmal = $merkmal;
        $this->showDrill = true;
    }

    public function schliesseDrill(): void
    {
        $this->showDrill = false;
        $this->drillKostenstelle = '';
        $this->drillMerkmal = '';
    }

    #[Computed]
    public function drillPersonen(): array
    {
        if ($this->drillMerkmal === '') {
            return [];
        }

        return array_values(array_filter(
            $this->auswertung['personen'],
            fn ($p) => MitarbeiterStatistikRechner::passt($p, $this->drillKostenstelle, $this->drillMerkmal),
        ));
    }

    #[Computed]
    public function drillTitel(): string
    {
        $merkmal = match (true) {
            $this->drillMerkmal === 'aktiv' => 'Aktive Mitarbeiter',
            $this->drillMerkmal === 'produktiv' => 'Produktive Mitarbeiter',
            $this->drillMerkmal === 'nicht_produktiv' => 'Aktiv, aber nicht produktiv',
            $this->drillMerkmal === 'rueckmeldung:ja' => '0 Termine, mit Rückmeldung',
            $this->drillMerkmal === 'rueckmeldung:nein' => '0 Termine, ohne Rückmeldung',
            str_starts_with($this->drillMerkmal, 'stufe:') => 'Termine: ' . (MitarbeiterStatistikRechner::STUFEN_LABEL[substr($this->drillMerkmal, 6)] ?? ''),
            default => '',
        };
        $ks = match ($this->drillKostenstelle) {
            'alle', '' => 'alle Kostenstellen',
            'ohne' => 'ohne Kostenstelle',
            default => self::kostenstellenName((int) $this->drillKostenstelle),
        };

        return $merkmal . ' · ' . $ks;
    }

    // ---------------------------------------------------------------- Einstellungen

    public function oeffneEinstellungen(): void
    {
        $team = self::teamId();
        $heute = now()->toDateString();
        $geltend = RecKostenstellenSoll::geltend($team, $heute);
        $this->sollWerte = [];
        foreach ($this->sollKostenstellen() as $ks) {
            $this->sollWerte[(string) $ks] = isset($geltend[$ks]) ? (string) $geltend[$ks] : '';
        }
        $this->sollGueltigAb = $heute;
        $settings = RecApplicantSettings::query()->where('team_id', $team)->first();
        $this->empfaenger = implode("\n", (array) ($settings?->getSetting(Personalreport::SETTING_EMPFAENGER) ?? []));
        $this->reportAktiv = (bool) ($settings?->getSetting(Personalreport::SETTING_AKTIV) ?? false);
        $this->einstellungenMeldung = '';
        $this->showEinstellungen = true;
    }

    /** Kostenstellen mit Soll-Feld: alle bekannten Niederlassungen plus alles, was in den Daten vorkommt. */
    public function sollKostenstellen(): array
    {
        $ks = array_keys(Filialen::all());
        foreach ($this->auswertung['zeilen'] as $z) {
            if ($z['kostenstelle'] !== null) {
                $ks[] = $z['kostenstelle'];
            }
        }
        $ks = array_values(array_unique(array_map('intval', $ks)));
        sort($ks);

        return $ks;
    }

    public function speichereEinstellungen(): void
    {
        $team = self::teamId();
        if ($team === 0) {
            $this->einstellungenMeldung = 'Kein Team gefunden – nichts gespeichert.';

            return;
        }

        $ab = (string) $this->sollGueltigAb;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ab) !== 1) {
            $this->einstellungenMeldung = 'Bitte ein Datum für „gültig ab“ wählen.';

            return;
        }

        $empfaenger = [];
        foreach (preg_split('/[\s,;]+/', $this->empfaenger) ?: [] as $mail) {
            $mail = mb_strtolower(trim($mail));
            if ($mail === '') {
                continue;
            }
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                $this->einstellungenMeldung = '„' . $mail . '“ ist keine gültige E-Mail-Adresse – nichts gespeichert.';

                return;
            }
            $empfaenger[] = $mail;
        }
        $empfaenger = array_values(array_unique($empfaenger));
        if ($this->reportAktiv && $empfaenger === []) {
            $this->einstellungenMeldung = 'Für den täglichen Report bitte mindestens eine E-Mail-Adresse eintragen.';

            return;
        }

        $neu = [];
        foreach ($this->sollWerte as $ks => $wert) {
            $wert = trim((string) $wert);
            if ($wert === '') {
                continue;
            }
            if (!ctype_digit($wert) || !ctype_digit((string) $ks)) {
                $this->einstellungenMeldung = 'Soll bitte als ganze Zahl eintragen – nichts gespeichert.';

                return;
            }
            $neu[(int) $ks] = (int) $wert;
        }

        // Nur geänderte Werte schreiben: das Soll ist eine Historie, keine Momentaufnahme.
        $bisher = RecKostenstellenSoll::geltend($team, $ab);
        foreach ($neu as $ks => $soll) {
            if (($bisher[$ks] ?? null) === $soll) {
                continue;
            }
            RecKostenstellenSoll::query()->updateOrCreate(
                ['team_id' => $team, 'kostenstelle' => $ks, 'gueltig_ab' => $ab],
                ['soll' => $soll, 'herkunft' => RecKostenstellenSoll::HERKUNFT_MANUELL, 'created_by_user_id' => auth()->id()],
            );
        }

        $settings = RecApplicantSettings::getOrCreateForTeam($team);
        $settings->setSetting(Personalreport::SETTING_EMPFAENGER, $empfaenger);
        $settings->setSetting(Personalreport::SETTING_AKTIV, $this->reportAktiv);
        $settings->save();

        unset($this->auswertung, $this->zeilen, $this->summe);
        $this->showEinstellungen = false;
        $this->einstellungenMeldung = '';
    }

    /** Verlauf der Sollzahlen (jüngste zuerst) für die Anzeige im Einstellungsfenster. */
    #[Computed]
    public function sollVerlauf(): array
    {
        return RecKostenstellenSoll::query()
            ->where('team_id', self::teamId())
            ->orderByDesc('gueltig_ab')->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($s) => [
                'kostenstelle' => self::kostenstellenName($s->kostenstelle),
                'soll' => $s->soll,
                'gueltig_ab' => $s->gueltig_ab?->format('d.m.Y'),
                'herkunft' => $s->herkunft,
            ])->all();
    }

    public function render()
    {
        return view('recruiting::livewire.statistics.mitarbeiter')
            ->layout('platform::layouts.app');
    }
}
