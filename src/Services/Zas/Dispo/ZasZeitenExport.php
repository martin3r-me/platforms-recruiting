<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Platform\Recruiting\Services\Zeit\DispoZeitBezug;
use Platform\Recruiting\Services\Zeit\DispoZeiten;
use Platform\Recruiting\Services\Zeit\RheingedeckZeitRegeln;
use Platform\Recruiting\Services\Zeit\ZeitAnzeige;
use Platform\Recruiting\Support\ZasPersonnelNumber;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitRechner;

/**
 * Zeiten an ZAS (Olaf 19./20.11.2026): erledigte Zeiteintraege im Format des
 * bisherigen Webportals, damit ZAS nichts umbauen muss
 * (Beispiel `Zeiterfassung.txt`):
 *
 *   PNr;VID;EDatum;VDatum;Start;Ende;Pause;Stunden;Fahrzeit;ZASID;erfasstvon;Bemerkung;Wäsche;Wgross;abgesagt;verspaetet;Wzurueck|;
 *
 * Hinten haengen WAbzug/WKunde/Wfehlt (Mail an Olaf 20.11.: „zusätzliche
 * Felder hinten dran, kein Problem“), bis zur Wäsche-Erfassung leer.
 *
 * Byteweise wie Olafs Beispiel: UTF-8 OHNE BOM, CRLF, Kopf endet auf `|;`,
 * Datenzeilen auf `;|;`. Pause und Stunden als Dezimalstunden mit Komma
 * (0,5 / 6,00), Pause leer bei 0. Fahrzeit bleibt leer, abgesagt/verspaetet
 * auch („kommt später“ ist nur Planung vor Ort, Markus 20.11.).
 *
 * Geliefert wird nur, was die Disposition als erledigt markiert hat — jeder
 * Eintrag genau einmal (Marker `zas_exported_at`, beanspruchen vor dem
 * Ausliefern wie bei den Buchungen). Danach ist er auch fuer das Wieder-Oeffnen
 * gesperrt (DispoZeiten::oeffnen), Korrekturen macht das Buero in ZAS.
 */
class ZasZeitenExport
{
    public const COLUMNS = [
        'PNr', 'VID', 'EDatum', 'VDatum', 'Start', 'Ende', 'Pause', 'Stunden', 'Fahrzeit', 'ZASID',
        'erfasstvon', 'Bemerkung', 'Wäsche', 'Wgross', 'abgesagt', 'verspaetet', 'Wzurueck',
        'WAbzug', 'WKunde', 'Wfehlt',
    ];

    private const ZEITFELDER = ['kommen_at', 'gehen_at'];

    public function __construct(private readonly RheingedeckZeitRegeln $regeln = new RheingedeckZeitRegeln()) {}

    /**
     * Lieferbare Zeilen ermitteln, ohne etwas zu schreiben.
     *
     * @return list<array<string, mixed>> je Zeile `id` (Zeiteintrag) plus die Spalten
     */
    public function ermitteln(?string $einsatzRef = null): array
    {
        $einsatzRef = trim((string) $einsatzRef);
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        $eintraege = ZeitEintrag::query()
            ->where('person_typ', DispoZeitBezug::PERSON_TYP)
            ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
            ->whereNotNull('gesperrt_at')
            ->where('gesperrt_grund', DispoZeiten::GRUND_ERLEDIGT)
            ->whereNotNull('kommen_at')
            ->whereNotNull('gehen_at')
            ->whereNull('zas_exported_at')
            ->when($teamId > 0, fn ($q) => $q->where(fn ($t) => $t->whereNull('team_id')->orWhere('team_id', $teamId)))
            ->orderBy('tag')->orderBy('id')
            ->get();
        if ($eintraege->isEmpty()) {
            return [];
        }

        $assignments = RecDispoAssignment::query()
            ->with(['event:id,einsatz_ref', 'employee:id,personnel_number,is_test'])
            ->whereIn('id', $eintraege->pluck('bezug_id')->unique()->values()->all())
            ->get()->keyBy('id');

        $verlauf = $this->letzteZeitbuchung($eintraege->pluck('id')->all());

        $zeilen = [];
        foreach ($eintraege as $e) {
            $a = $assignments->get((int) $e->bezug_id);
            if ($a === null || $a->event === null || $a->employee === null) {
                continue; // Einbuchung weg: nichts, woran ZAS die Zeile haengen koennte
            }
            if ((int) $a->rec_employee_id !== (int) $e->person_id || (bool) $a->employee->is_test) {
                continue; // Zeile gehoert inzwischen jemand anderem (Personenwechsel) bzw. Testperson
            }
            $pnr = trim((string) $a->employee->personnel_number);
            $vid = trim((string) $a->event->einsatz_ref);
            $zasId = trim((string) $a->ds_ref);
            if ($pnr === '' || $vid === '' || $zasId === '' || !ZasPersonnelNumber::hasPrefix($pnr)) {
                continue;
            }
            if ($einsatzRef !== '' && $vid !== $einsatzRef) {
                continue;
            }

            $anwesend = ZeitRechner::anwesendMinuten($e->kommen_at, $e->gehen_at);
            $pause = ZeitRechner::pauseMinuten($e->pause_minuten, $anwesend, $this->regeln);
            $arbeit = (int) ZeitRechner::arbeitsMinuten($anwesend, $pause);

            $zeilen[] = [
                'id'         => (int) $e->id,
                'PNr'        => $pnr,
                'VID'        => $vid,
                'EDatum'     => ZeitAnzeige::ortszeit($e->gesperrt_at)?->format('d.m.Y') ?? '',
                'VDatum'     => $e->tag->format('d.m.Y'),
                'Start'      => ZeitAnzeige::uhrzeit($e->kommen_at),
                'Ende'       => ZeitAnzeige::uhrzeit($e->gehen_at),
                'Pause'      => $pause > 0 ? self::dezimal($pause, false) : '',
                'Stunden'    => self::dezimal($arbeit, true),
                'ZASID'      => $zasId,
                'erfasstvon' => $this->erfasstVon($verlauf[(int) $e->id] ?? null, $a),
                'Bemerkung'  => trim((string) $e->bemerkung),
            ];
        }

        return $zeilen;
    }

    /**
     * Beanspruchen vor dem Ausliefern: bedingter UPDATE je Zeile. Nur Zeilen, die
     * DIESER Abruf markiert hat, kommen in die Datei; der Eintrag muss dabei noch
     * erledigt sein (sonst wurde er inzwischen wieder geoeffnet).
     *
     * @param list<array<string, mixed>> $zeilen
     * @return list<array<string, mixed>>
     */
    public function beanspruchen(array $zeilen): array
    {
        $jetzt = now();
        $geliefert = [];
        foreach ($zeilen as $z) {
            $n = ZeitEintrag::query()
                ->whereKey((int) $z['id'])
                ->whereNull('zas_exported_at')
                ->whereNotNull('gesperrt_at')
                ->update(['zas_exported_at' => $jetzt]);
            if ($n === 1) {
                $geliefert[] = $z;
            }
        }
        if ($geliefert !== []) {
            Log::info('ZAS Zeiten: erledigte Zeiten uebergeben', ['anzahl' => count($geliefert), 'ids' => array_column($geliefert, 'id')]);
        }

        return $geliefert;
    }

    /** @param list<array<string, mixed>> $zeilen */
    public function csv(array $zeilen, ZasCsvBuilder $builder): string
    {
        $out = implode(ZasCsvBuilder::SEPARATOR, self::COLUMNS) . "|;\r\n";
        foreach ($zeilen as $z) {
            $out .= substr($builder->buildRow($z, self::COLUMNS), 0, -1) . "\r\n";
        }

        return $out;
    }

    /** Minuten als Dezimalstunden mit Komma: Stunden immer zwei Stellen (6,00), Pause knapp (0,5 / 0,75). */
    public static function dezimal(int $minuten, bool $zweiStellen): string
    {
        $text = number_format(max(0, $minuten) / 60, 2, ',', '');

        return $zweiStellen ? $text : rtrim(rtrim($text, '0'), ',');
    }

    /**
     * Wer die Zeit gebucht hat (Olaf: „erfasstvon“ = Personalnummer, darf leer
     * sein). Massgeblich ist die letzte Buchung von Kommen oder Gehen:
     * Mitarbeiter selbst → seine Nummer; Teamleiter am Eventzugang → die Nummer
     * des gleichnamigen Teamleiters dieser Veranstaltung, wenn eindeutig;
     * Buero mit eigener Anmeldung oder unklar → leer.
     *
     * @param array{akteur_typ:string, akteur_id:?int, akteur_name:string}|null $buchung
     */
    private function erfasstVon(?array $buchung, RecDispoAssignment $a): string
    {
        if ($buchung === null) {
            return '';
        }
        if ($buchung['akteur_typ'] === DispoZeitBezug::PERSON_TYP && $buchung['akteur_id'] !== null) {
            $pnr = trim((string) RecEmployee::query()->whereKey($buchung['akteur_id'])->value('personnel_number'));

            return ZasPersonnelNumber::hasPrefix($pnr) ? $pnr : '';
        }
        $zusatz = \Platform\Recruiting\Livewire\Dispo\Events\Show::ZEIT_EVENTZUGANG;
        $name = $buchung['akteur_name'];
        if ($buchung['akteur_typ'] !== 'user' || !str_ends_with($name, $zusatz)) {
            return '';
        }
        $gesucht = self::namensSchluessel(substr($name, 0, -strlen($zusatz)));
        if ($gesucht === '') {
            return '';
        }

        $treffer = [];
        $kollegen = RecDispoAssignment::query()
            ->with('employee:id,first_name,last_name,personnel_number')
            ->where('rec_dispo_event_id', $a->rec_dispo_event_id)
            ->whereNotNull('rec_employee_id')
            ->get();
        foreach ($kollegen as $k) {
            $emp = $k->employee;
            if ($emp === null) {
                continue;
            }
            $voll = self::namensSchluessel($emp->first_name . ' ' . $emp->last_name);
            $umgekehrt = self::namensSchluessel($emp->last_name . ' ' . $emp->first_name);
            if ($gesucht === $voll || $gesucht === $umgekehrt) {
                $pnr = trim((string) $emp->personnel_number);
                if (ZasPersonnelNumber::hasPrefix($pnr)) {
                    $treffer[$pnr] = true;
                }
            }
        }

        return count($treffer) === 1 ? (string) array_key_first($treffer) : '';
    }

    private static function namensSchluessel(string $name): string
    {
        $name = str_replace(',', ' ', $name);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Letzte Buchung von Kommen/Gehen je Zeiteintrag.
     *
     * @param list<int> $ids
     * @return array<int, array{akteur_typ:string, akteur_id:?int, akteur_name:string}>
     */
    private function letzteZeitbuchung(array $ids): array
    {
        $aus = [];
        $zeilen = \Illuminate\Support\Facades\DB::table('rec_zeit_verlauf')
            ->whereIn('rec_zeit_eintrag_id', $ids)
            ->whereIn('feld', self::ZEITFELDER)
            ->orderBy('id')
            ->get(['rec_zeit_eintrag_id', 'akteur_typ', 'akteur_id', 'akteur_name']);
        foreach ($zeilen as $z) {
            $aus[(int) $z->rec_zeit_eintrag_id] = [
                'akteur_typ'  => (string) $z->akteur_typ,
                'akteur_id'   => $z->akteur_id === null ? null : (int) $z->akteur_id,
                'akteur_name' => (string) $z->akteur_name,
            ];
        }

        return $aus;
    }
}
