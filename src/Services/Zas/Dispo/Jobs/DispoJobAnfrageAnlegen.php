<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
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
     * @param array{filial_nr:int, von:string, bis:string, modus:string, template_id:int,
     *   schichten: list<array{event:int, datum:string, von:?string, taetigkeit:string, gesucht:int|string}>,
     *   bloecke: list<int|null>, gruppen: list<string>, nur_passend: bool, minuten: int} $daten
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
                'whatsapp_template_id' => (int) $daten['template_id'],
                'status'               => RecDispoJobRequest::STATUS_ENTWURF,
                'created_by_user_id'   => $userId,
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
     * @param array{bloecke: list<int|null>, gruppen: list<string>, nur_passend: bool, minuten: int} $auswahl
     */
    public static function welle(RecDispoJobRequest $request, array $auswahl): RecDispoJobWave
    {
        $minuten = max(0, (int) ($auswahl['minuten'] ?? 0));
        $nr = (int) RecDispoJobWave::query()->where('request_id', $request->id)->max('nr') + 1;

        return $request->waves()->create([
            'nr'                      => $nr,
            'ma_bloecke'              => array_values(array_map(fn ($b) => $b === null ? null : (int) $b, (array) ($auswahl['bloecke'] ?? []))),
            'gruppen'                 => array_values(array_map('strval', (array) ($auswahl['gruppen'] ?? []))),
            'nur_passende_taetigkeit' => (bool) ($auswahl['nur_passend'] ?? true),
            'scheduled_for'           => $minuten > 0 ? now()->addMinutes($minuten) : null,
            'job_uuid'                => (string) UuidV7::generate(),
        ]);
    }

    /**
     * gesucht > 0, leerer Beginn als '' (Spalte NOT NULL), doppelte
     * (VA, Tag, Beginn, Taetigkeit) zusammengefasst — die letzte Zahl gewinnt.
     *
     * @return list<array{rec_dispo_event_id:int, datum:string, von:string, taetigkeit:string, gesucht:int}>
     */
    private static function schichten(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $event = (int) ($r['event'] ?? 0);
            $datum = substr(trim((string) ($r['datum'] ?? '')), 0, 10);
            $von = trim((string) ($r['von'] ?? ''));
            $taet = mb_substr(trim((string) ($r['taetigkeit'] ?? '')), 0, 190);
            $gesucht = (int) ($r['gesucht'] ?? 0);
            if ($event <= 0 || $datum === '' || $taet === '') {
                continue;
            }
            $key = $event . '|' . $datum . '|' . $von . '|' . mb_strtolower($taet);
            if ($gesucht <= 0) {
                unset($out[$key]);
                continue;
            }
            unset($out[$key]); // ans Ende: Reihenfolge der letzten Eingabe
            $out[$key] = ['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => $von, 'taetigkeit' => $taet, 'gesucht' => $gesucht];
        }

        return array_values($out);
    }
}
