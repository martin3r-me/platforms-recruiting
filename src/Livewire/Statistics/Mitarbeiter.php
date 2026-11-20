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

    /** Angehakte Gesellschaften ('RG', 'MA'); Standard nur RG. Beide angehakt = „beide“. Untyped (Livewire-Eingabe). */
    public $gesellschaften = [MitarbeiterStatistikRechner::GESELLSCHAFT_RG];

    /** Angehakte ZAS-Status ('GO', 'MA'); Standard beide. Hat nichts mit der Firma zu tun. Untyped (Livewire-Eingabe). */
    public $stati = [MitarbeiterStatistikRechner::STATUS_GO, MitarbeiterStatistikRechner::STATUS_MA];

    /** 'alle' | 'ohne' | Nummer der Kostenstelle — untyped wie die anderen Selects. */
    public $kostenstelleFilter = 'alle';

    public string $drillKostenstelle = '';
    public string $drillMerkmal = '';
    public bool $showDrill = false;

    public bool $showEinstellungen = false;
    /** @var array<string,string> kostenstelle => Soll (Eingabe) */
    public array $sollWerte = [];
    /**
     * Was beim Öffnen in den Feldern stand. Gespeichert wird nur, was der Nutzer davon ÄNDERT —
     * sonst schriebe ein rückdatiertes „gültig ab“ die heutigen Werte aller Felder in den Verlauf
     * (Review 19.11.).
     */
    #[\Livewire\Attributes\Locked]
    public array $sollAnzeige = [];
    public ?string $sollGueltigAb = null;
    /**
     * Empfänger des Reports als Liste — ausgewählt aus den HCM-Benutzern oder einzeln geprüft
     * hinzugefügt, nie als Freitext (Sebastian 19.11.2026: „sodass er keinen Fehler machen kann“).
     *
     * @var list<string>
     */
    public array $empfaengerListe = [];
    /** Auswahl in der Benutzerliste; wird beim Wählen sofort übernommen und geleert. */
    public $neuerBenutzer = '';
    /** Freie Adresse für Empfänger ohne HCM-Konto. */
    public string $neueAdresse = '';
    public string $adressMeldung = '';
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
        $ok = fn ($d) => self::istDatum($d);
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

    /** Echtes Kalenderdatum im Format Y-m-d (kein 2026-13-45 aus einem gebastelten Request). */
    public static function istDatum(mixed $d): bool
    {
        return is_string($d)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function updatedGesellschaften($value = null): void
    {
        $this->gesellschaften = self::angehakt($this->gesellschaften, ['RG', 'MA'], [MitarbeiterStatistikRechner::GESELLSCHAFT_RG]);
        $this->schliesseDrill();
    }

    public function updatedStati($value = null): void
    {
        $this->stati = self::angehakt($this->stati, ['GO', 'MA'], [MitarbeiterStatistikRechner::STATUS_GO, MitarbeiterStatistikRechner::STATUS_MA]);
        $this->schliesseDrill();
    }

    /**
     * Nur erlaubte Werte, feste Reihenfolge. Nichts angehakt geht nicht (leere Statistik ergibt keinen Sinn):
     * dann gilt wieder der Standard — der zuletzt abgehakte Haken bleibt also stehen.
     *
     * @param list<string> $erlaubt
     * @param list<string> $standard
     * @return list<string>
     */
    private static function angehakt($werte, array $erlaubt, array $standard): array
    {
        $werte = is_array($werte) ? array_map(fn ($w) => is_scalar($w) ? (string) $w : '', $werte) : [];
        $sauber = array_values(array_filter($erlaubt, fn ($e) => in_array($e, $werte, true)));

        return $sauber === [] ? $standard : $sauber;
    }

    /** Angehakte Gesellschaften → Rechner-Wert ('RG' | 'MA' | 'beide'). */
    private function gesellschaftSicher(): string
    {
        $g = self::angehakt($this->gesellschaften, ['RG', 'MA'], [MitarbeiterStatistikRechner::GESELLSCHAFT_RG]);

        return count($g) === 2 ? MitarbeiterStatistikRechner::GESELLSCHAFT_BEIDE : $g[0];
    }

    /** Angehakte Status → Rechner-Wert ('GO' | 'MA' | 'beide'). */
    private function statusSicher(): string
    {
        $s = self::angehakt($this->stati, ['GO', 'MA'], [MitarbeiterStatistikRechner::STATUS_GO, MitarbeiterStatistikRechner::STATUS_MA]);

        return count($s) === 2 ? MitarbeiterStatistikRechner::STATUS_BEIDE : $s[0];
    }

    public function updatedKostenstelleFilter($value): void
    {
        $this->kostenstelleFilter = (is_string($value) || is_int($value)) && $value !== '' ? (string) $value : 'alle';
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

        return app(MitarbeiterStatistikDaten::class)->auswerten(self::teamId(), now()->toDateString(), $this->von, $this->bis, $this->gesellschaftSicher(), $this->statusSicher());
    }

    private function ordneZeitraumStill(): void
    {
        if (!self::istDatum($this->von) || !self::istDatum($this->bis)) {
            $this->standardZeitraum();
        }
        if ($this->von > $this->bis) {
            [$this->von, $this->bis] = [$this->bis, $this->von];
        }
    }

    /** Zeilen der Tabelle nach Kostenstellen-Filter. */
    #[Computed]
    public function zeilen(): array
    {
        $alle = $this->auswertung['zeilen'];
        if (!is_string($this->kostenstelleFilter) && !is_int($this->kostenstelleFilter)) {
            $this->kostenstelleFilter = 'alle';
        }
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

        return $merkmal . ' · ' . $ks . ' · ' . MitarbeiterStatistikRechner::GESELLSCHAFTEN[$this->gesellschaftSicher()]
            . ' · Status ' . MitarbeiterStatistikRechner::STATUSFILTER[$this->statusSicher()];
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
        $this->sollAnzeige = $this->sollWerte;
        $this->sollGueltigAb = $heute;
        $settings = RecApplicantSettings::query()->where('team_id', $team)->first();
        $this->empfaengerListe = array_values(array_filter(array_map(
            fn ($m) => mb_strtolower(trim((string) $m)),
            (array) ($settings?->getSetting(Personalreport::SETTING_EMPFAENGER) ?? []),
        ), fn ($m) => $m !== ''));
        $this->neuerBenutzer = '';
        $this->neueAdresse = '';
        $this->adressMeldung = '';
        $this->reportAktiv = (bool) ($settings?->getSetting(Personalreport::SETTING_AKTIV) ?? false);
        $this->einstellungenMeldung = '';
        $this->showEinstellungen = true;
    }

    /**
     * Kostenstellen mit Soll-Feld: nur die, in denen es aktive Mitarbeiter gibt, plus die, für die
     * schon ein Soll gilt (damit es sich auch wieder ändern lässt). Niederlassungen aus der
     * Konfiguration ohne Mitarbeiter (Wuppertal, Verwaltung) erscheinen nicht (Sebastian 19.11.2026).
     */
    public function sollKostenstellen(): array
    {
        $ks = array_keys(RecKostenstellenSoll::geltend(self::teamId(), now()->toDateString()));
        foreach ($this->auswertung['zeilen'] as $z) {
            if ($z['kostenstelle'] !== null && $z['aktiv'] > 0) {
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

        $ab = is_string($this->sollGueltigAb) ? $this->sollGueltigAb : '';
        if (!self::istDatum($ab)) {
            $this->einstellungenMeldung = 'Bitte ein Datum für „gültig ab“ wählen.';

            return;
        }

        // Die Liste wird schon beim Hinzufügen geprüft; hier nur noch einmal als Schloss gegen
        // einen gebastelten Request.
        $empfaenger = array_values(array_unique(array_filter(
            array_map(fn ($m) => is_string($m) ? mb_strtolower(trim($m)) : '', $this->empfaengerListe),
            fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL) !== false,
        )));
        if ($this->reportAktiv && $empfaenger === []) {
            $this->einstellungenMeldung = 'Für den täglichen Report bitte mindestens einen Empfänger hinzufügen.';

            return;
        }

        // Nur Felder, die der Nutzer geändert hat; leer geräumt = ab „gültig ab“ kein Soll mehr.
        $neu = [];
        foreach ($this->sollWerte as $ks => $wert) {
            if (!is_scalar($wert) || !ctype_digit((string) $ks)) {
                continue;
            }
            $wert = trim((string) $wert);
            if ($wert === trim((string) ($this->sollAnzeige[(string) $ks] ?? ''))) {
                continue;
            }
            if ($wert !== '' && (!ctype_digit($wert) || strlen($wert) > 6)) {
                $this->einstellungenMeldung = 'Soll bitte als ganze Zahl eintragen – nichts gespeichert.';

                return;
            }
            $neu[(int) $ks] = $wert === '' ? null : (int) $wert;
        }

        foreach ($neu as $ks => $soll) {
            RecKostenstellenSoll::query()->updateOrCreate(
                ['team_id' => $team, 'kostenstelle' => $ks, 'gueltig_ab' => $ab],
                ['soll' => $soll, 'herkunft' => RecKostenstellenSoll::HERKUNFT_MANUELL, 'created_by_user_id' => auth()->id()],
            );
        }

        $settings = RecApplicantSettings::getOrCreateForTeam($team);
        $settings->setSetting(Personalreport::SETTING_EMPFAENGER, $empfaenger);
        $settings->setSetting(Personalreport::SETTING_AKTIV, $this->reportAktiv);
        $settings->save();

        unset($this->auswertung, $this->zeilen, $this->summe, $this->sollVerlauf);
        $this->showEinstellungen = false;
        $this->einstellungenMeldung = '';
    }

    /**
     * HCM-Benutzer des Teams mit E-Mail-Adresse, zur Auswahl als Empfänger.
     *
     * @return array<string,string> email => Name
     */
    #[Computed]
    public function teamBenutzer(): array
    {
        try {
            $team = \Platform\Core\Models\Team::query()->find(self::teamId());
            if ($team === null) {
                return [];
            }
            $out = [];
            foreach ($team->users()->get() as $u) {
                if (($u->type ?? null) === 'ai_user') {
                    continue; // KI-Benutzer bekommen keine Mails
                }
                $mail = mb_strtolower(trim((string) ($u->email ?? '')));
                if ($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $name = trim((string) ($u->fullname ?? '')) ?: trim((string) ($u->name ?? '')) ?: $mail;
                $out[$mail] = $name;
            }
            asort($out, SORT_NATURAL | SORT_FLAG_CASE);

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function updatedNeuerBenutzer($value): void
    {
        $mail = is_string($value) ? mb_strtolower(trim($value)) : '';
        $this->neuerBenutzer = '';
        if ($mail !== '' && array_key_exists($mail, $this->teamBenutzer)) {
            $this->fuegeEmpfaengerHinzu($mail);
        }
    }

    public function fuegeAdresseHinzu(): void
    {
        $mail = mb_strtolower(trim($this->neueAdresse));
        if ($mail === '') {
            return;
        }
        if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            $this->adressMeldung = '„' . $mail . '“ ist keine gültige E-Mail-Adresse.';

            return;
        }
        $this->fuegeEmpfaengerHinzu($mail);
        $this->neueAdresse = '';
    }

    private function fuegeEmpfaengerHinzu(string $mail): void
    {
        $this->adressMeldung = '';
        if (!in_array($mail, $this->empfaengerListe, true)) {
            $this->empfaengerListe[] = $mail;
        }
    }

    public function entferneEmpfaenger(string $mail): void
    {
        $this->empfaengerListe = array_values(array_filter($this->empfaengerListe, fn ($m) => $m !== $mail));
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
                'gueltig_ab' => \Carbon\Carbon::parse(substr((string) $s->gueltig_ab, 0, 10))->format('d.m.Y'),
                'herkunft' => $s->herkunft,
            ])->all();
    }

    public function render()
    {
        return view('recruiting::livewire.statistics.mitarbeiter')
            ->layout('platform::layouts.app');
    }
}
