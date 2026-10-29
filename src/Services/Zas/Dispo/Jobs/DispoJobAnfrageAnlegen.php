<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Jobs\SendDispoJobWave;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Symfony\Component\Uid\UuidV7;

/**
 * DB-Teil von „Anfrage anlegen und Welle 1 starten“ (und „Neue Welle“).
 * Der Aufrufer prueft die Eingaben (DispoJobCreateState) und dispatcht
 * SendDispoJobWave ERST nach dem Commit — hier wird nur geschrieben.
 */
final class DispoJobAnfrageAnlegen
{
    /**
     * @param array{filial_nr:int, von:string, bis:string, modus:string, anlass:string, template_id:int,
     *   schichten: list<array{event:int, datum:string, von:?string, bis?:string, taetigkeit:string, dispoposten?:?string, gesucht:int|string}>,
     *   bloecke: list<int|null>, gruppen: list<string>, nur_passend: bool, stati?: ?list<string>, firmen?: ?list<string>, welle_max?: int, welle_abstand_minuten?: int, minuten: int} $daten
     * template_id = die beim Anlegen aus dem Anlass aufgeloeste Vorlage (bleibt fuer
     * spaetere Wellen fest, auch wenn die Einstellung sich aendert).
     * @throws \InvalidArgumentException wenn keine Schicht mit gesucht > 0 uebrig bleibt
     */
    public static function anlegen(array $daten, int $teamId, ?int $userId): RecDispoJobRequest
    {
        $schichten = self::schichten($daten['schichten'] ?? []);
        if ($schichten === []) {
            throw new \InvalidArgumentException('Keine Schicht mit gesucht > 0.');
        }

        return DB::transaction(function () use ($daten, $teamId, $userId, $schichten) {
            $request = RecDispoJobRequest::create([
                'team_id'              => $teamId,
                'filial_nr'            => (int) $daten['filial_nr'],
                'zeitraum_von'         => $daten['von'],
                'zeitraum_bis'         => $daten['bis'],
                'modus'                => $daten['modus'],
                'anlass'               => DispoJobAnlass::istGueltig((string) ($daten['anlass'] ?? '')) ? (string) $daten['anlass'] : null,
                'whatsapp_template_id' => (int) $daten['template_id'],
                'status'               => RecDispoJobRequest::STATUS_ENTWURF,
                'created_by_user_id'   => $userId,
                // Wellenplan (Spec 2026-10-27 §2): 0/leer = alle bzw. keine Folgewelle.
                'welle_max'             => (int) ($daten['welle_max'] ?? 0) > 0 ? (int) $daten['welle_max'] : null,
                'welle_abstand_minuten' => (int) ($daten['welle_abstand_minuten'] ?? 0) > 0 ? (int) $daten['welle_abstand_minuten'] : null,
            ]);
            foreach ($schichten as $s) {
                $request->shifts()->create($s);
            }
            self::welle($request, $daten);

            return $request;
        });
    }

    /**
     * Neue Welle mit nr = max + 1. Empfaenger werden erst beim Start berechnet.
     *
     * @param array{bloecke: list<int|null>, gruppen: list<string>, nur_passend: bool, stati?: ?list<string>, firmen?: ?list<string>, auto?: bool, minuten: int} $auswahl
     */
    public static function welle(RecDispoJobRequest $request, array $auswahl): RecDispoJobWave
    {
        $minuten = max(0, (int) ($auswahl['minuten'] ?? 0));
        $nr = (int) RecDispoJobWave::query()->where('request_id', $request->id)->max('nr') + 1;

        return $request->waves()->create([
            'nr'                      => $nr,
            'ma_bloecke'              => array_values(array_map(fn ($b) => $b === null ? null : (int) $b, (array) ($auswahl['bloecke'] ?? []))),
            'gruppen'                 => array_values(array_map('strval', (array) ($auswahl['gruppen'] ?? []))),
            // Statusauswahl GO/MA (Spec 2026-10-20). Fehlt sie, bleibt die Spalte
            // leer und die Welle gilt wie frueher fuer GO + MA.
            'stati'                   => array_key_exists('stati', $auswahl) && $auswahl['stati'] !== null
                ? DispoJobRecipientPlanner::stati((array) $auswahl['stati']) : null,
            // Gesellschaft RG/MA (Spec 2026-10-27 §3); null = alte Welle = beide.
            'firmen'                  => array_key_exists('firmen', $auswahl) && $auswahl['firmen'] !== null
                ? DispoGesellschaft::normalize((array) $auswahl['firmen']) : null,
            'auto'                    => (bool) ($auswahl['auto'] ?? false),
            'nur_passende_taetigkeit' => (bool) ($auswahl['nur_passend'] ?? true),
            'scheduled_for'           => $minuten > 0 ? now()->addMinutes($minuten) : null,
            'job_uuid'                => (string) UuidV7::generate(),
        ]);
    }

    /**
     * Welle in die Warteschlange — NACH dem Commit aufrufen. Geplante Wellen
     * mit Verzoegerung; der Job prueft scheduled_for selbst noch einmal.
     */
    public static function starten(RecDispoJobWave $wave, int $teamId, ?int $userId): void
    {
        $pending = SendDispoJobWave::dispatch((int) $wave->id, $teamId, $userId);
        if ($wave->scheduled_for !== null) {
            $pending->delay($wave->scheduled_for);
        }
        // PendingDispatch schickt beim Verlassen des Scopes ab.
    }

    /**
     * gesucht > 0, leerer Beginn als '' (Spalte NOT NULL), doppelte
     * (VA, Tag, Beginn, Taetigkeit, Posten) zusammengefasst — die letzte Zahl gewinnt.
     *
     * @return list<array{rec_dispo_event_id:int, datum:string, von:string, bis:string, taetigkeit:string, dispoposten_id:?string, gesucht:int}>
     */
    private static function schichten(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $event = (int) ($r['event'] ?? 0);
            $datum = substr(trim((string) ($r['datum'] ?? '')), 0, 10);
            $von = trim((string) ($r['von'] ?? ''));
            $bis = trim((string) ($r['bis'] ?? ''));
            if (preg_match(DispoJobZeit::MUSTER, $bis) !== 1) {
                $bis = '';
            }
            $taet = mb_substr(trim((string) ($r['taetigkeit'] ?? '')), 0, 190);
            $posten = trim((string) ($r['dispoposten'] ?? ''));
            $gesucht = (int) ($r['gesucht'] ?? 0);
            if ($event <= 0 || $datum === '' || $taet === '') {
                continue;
            }
            // Posten-Nummer im Schluessel: zwei Posten mit gleichem Beginn und gleicher
            // Taetigkeit bleiben zwei Schichten (Review I-1), Zeilen ohne Posten wie bisher.
            $key = $event . '|' . $datum . '|' . $von . '|' . mb_strtolower($taet) . '|' . $posten;
            if ($gesucht <= 0) {
                unset($out[$key]);
                continue;
            }
            unset($out[$key]); // ans Ende: Reihenfolge der letzten Eingabe
            $out[$key] = ['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => $von, 'bis' => $bis, 'taetigkeit' => $taet,
                'dispoposten_id' => $posten !== '' ? mb_substr($posten, 0, 32) : null, 'gesucht' => $gesucht];
        }

        return array_values($out);
    }
}
