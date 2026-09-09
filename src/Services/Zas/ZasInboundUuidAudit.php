<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Sammelt die zurueckgespiegelten UUID-Werte aus den gespeicherten
 * ZAS-Lieferungen und ordnet sie unseren Datensaetzen zu.
 *
 * ANLASS (09.09.2026): 17 von 221 selbst angelegten Mitarbeitern haben seit
 * bis zu dreieinhalb Monaten keine Personalnummer aus ZAS zurueckbekommen,
 * der aelteste Fall vom 27.05. Die entscheidende Frage dabei ist nicht
 * beantwortbar, solange man nur auf unsere Tabellen schaut: existiert die
 * Person in ZAS ueberhaupt? Beides sieht bei uns identisch aus — „nie
 * angelegt" und „angelegt, aber nie zurueckgeliefert".
 *
 * Die Antwort steckt in den Rohdateien. Wir schicken in jeder Zeile unseres
 * Mitarbeiter-Exports `rec_employees.uuid` mit (ZasEmployeeFieldResolver) und
 * im Bewerber-Export `rec_applicants.uuid` (ZasFieldResolver). Taucht eine
 * dieser UUIDs in einer Lieferung auf, kennt ZAS den Datensatz. Taucht sie
 * nirgends auf, ist er dort nie entstanden.
 *
 * WARUM DIE ZEILENQUOTE NICHT REICHT: der Spaltenbericht sagt „UUID zu 15,1%
 * gefuellt", aber das sind Zeilen ueber alle Lieferungen. ZAS-Ursprungsleute
 * koennen per Definition keine UUID tragen und stecken in jeder Lieferung,
 * unsere Anlagen sind neu und stecken in wenigen — die Zeilenquote misst
 * also vor allem die Herkunftsverteilung des Bestands. Dieser Dienst zaehlt
 * deshalb PERSONEN: wie viele unserer Anlagen wurden je zurueckgespiegelt.
 *
 * Reine Logik ohne DB/Storage: der Command liest die Dateien und loest die
 * UUIDs auf, dieser Dienst zaehlt nur. Gleiches Muster wie
 * ZasInboundColumnReport.
 */
class ZasInboundUuidAudit
{
    /** Spalte, in der ZAS unsere UUID zurueckspiegelt. */
    public const COLUMN = 'UUID';

    /**
     * Zaehlt die UUID-Vorkommen ueber alle Lieferungen.
     *
     * @param  list<array{id:int,rows:list<array<string,string>>}> $deliveries
     * @return array{
     *     rows:int,
     *     with_uuid:int,
     *     seen:array<string,array{count:int,first_file:int,last_file:int}>
     * }
     */
    public static function collect(array $deliveries): array
    {
        $rows = 0;
        $withUuid = 0;
        $seen = [];

        foreach ($deliveries as $delivery) {
            $fileId = (int) ($delivery['id'] ?? 0);

            foreach ($delivery['rows'] ?? [] as $row) {
                $rows++;
                $uuid = strtolower(trim((string) ($row[self::COLUMN] ?? '')));
                if ($uuid === '') {
                    continue;
                }
                $withUuid++;

                if (!isset($seen[$uuid])) {
                    $seen[$uuid] = ['count' => 0, 'first_file' => $fileId, 'last_file' => $fileId];
                }
                $seen[$uuid]['count']++;
                // Lieferungen kommen aufsteigend herein; defensiv trotzdem
                // beide Grenzen fuehren, damit die Reihenfolge des Aufrufers
                // das Ergebnis nicht verfaelscht.
                $seen[$uuid]['first_file'] = min($seen[$uuid]['first_file'], $fileId);
                $seen[$uuid]['last_file'] = max($seen[$uuid]['last_file'], $fileId);
            }
        }

        return ['rows' => $rows, 'with_uuid' => $withUuid, 'seen' => $seen];
    }

    /**
     * Teilt die gesehenen UUIDs in Mitarbeiter, Bewerber und Unbekannte.
     *
     * „Unbekannt" ist der interessante Topf: eine UUID, die wir nie
     * verschickt haben, kann nur aus einer fremden Quelle stammen — oder aus
     * einem Datensatz, den wir zwischenzeitlich geloescht haben.
     *
     * @param  array<string,array{count:int,first_file:int,last_file:int}> $seen
     * @param  array<string,int> $employeeUuids  uuid (lowercase) => employee id
     * @param  array<string,int> $applicantUuids uuid (lowercase) => applicant id
     * @return array{employees:int,applicants:int,unknown:int,unknown_samples:list<string>}
     */
    public static function classify(array $seen, array $employeeUuids, array $applicantUuids, int $maxSamples = 5): array
    {
        $employees = 0;
        $applicants = 0;
        $unknown = 0;
        $samples = [];

        foreach (array_keys($seen) as $uuid) {
            if (isset($employeeUuids[$uuid])) {
                $employees++;
                continue;
            }
            if (isset($applicantUuids[$uuid])) {
                $applicants++;
                continue;
            }
            $unknown++;
            if (count($samples) < $maxSamples) {
                $samples[] = $uuid;
            }
        }

        return [
            'employees' => $employees,
            'applicants' => $applicants,
            'unknown' => $unknown,
            'unknown_samples' => $samples,
        ];
    }

    /**
     * Die Gegenrichtung und der eigentliche Zweck: wurde DIESER Datensatz je
     * zurueckgespiegelt?
     *
     * @param  list<array{id:int,uuid:?string}> $records
     * @param  array<string,array{count:int,first_file:int,last_file:int}> $seen
     * @return list<array{id:int,seen:bool,count:int,last_file:?int}>
     */
    public static function trace(array $records, array $seen): array
    {
        $out = [];

        foreach ($records as $record) {
            $uuid = strtolower(trim((string) ($record['uuid'] ?? '')));
            $hit = $uuid !== '' ? ($seen[$uuid] ?? null) : null;

            $out[] = [
                'id' => (int) $record['id'],
                'seen' => $hit !== null,
                'count' => $hit['count'] ?? 0,
                'last_file' => $hit['last_file'] ?? null,
            ];
        }

        return $out;
    }
}
