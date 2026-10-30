<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Rueckkanal Zusagen -> ZAS als Buchungen (Spec 2026-10-30): jede zugesagte
 * Job-Schicht einmal im Importformat von Olaf Michel
 * (`PNr;Projekt;Dispo;VDatum`). ZAS legt die Zeile in der Dispo-Liste an und
 * bestaetigt sie, wenn sie zu den Zeiteintraegen passt; das Ankommen erkennt
 * danach DispoJobZasAbgleich ueber die naechste Lieferung.
 *
 * Anders als die Bestaetigungen MIT Marker: eine Buchung (Person, Posten, Tag),
 * die einmal geliefert wurde, geht nie wieder raus (sonst Dublette in ZAS).
 * Reihenfolge: erst beanspruchen (bedingter UPDATE je Zeile), dann ausliefern —
 * zwei gleichzeitige Abrufe teilen sich die Zeilen, keiner liefert doppelt.
 */
class ZasDispoBookingExport
{
    public const COLUMNS = ['PNr', 'Projekt', 'Dispo', 'VDatum'];

    /** Datenzeilen tragen eine fuenfte, leere Zelle — exakt wie Olafs Beispiel aus dem alten Portal. */
    private const ROW_COLUMNS = ['PNr', 'Projekt', 'Dispo', 'VDatum', ''];

    /** Werte, die `?dry_run=` als „nein“ gelten lassen; alles andere (auch ohne Wert) ist ein Trockenlauf. */
    private const NEIN = ['0', 'false', 'no', 'off', 'nein'];

    /**
     * Trockenlauf aus den Query-Parametern: `?dry_run`, `?dry_run=1`, `?dry_run=true`
     * sind Trockenlaeufe; nur ein ausdrueckliches Nein (0/false/no/off/nein) markiert.
     * Ein Test-Abruf darf nie aus Versehen Zeilen fuer immer verbrauchen.
     *
     * @param array<string, mixed> $query
     */
    public static function dryRun(array $query): bool
    {
        if (!array_key_exists('dry_run', $query)) {
            return false;
        }
        $wert = $query['dry_run'];
        if (!is_scalar($wert) && $wert !== null) {
            return true;
        }

        return !in_array(strtolower(trim((string) $wert)), self::NEIN, true);
    }

    /**
     * Kandidaten ermitteln, ohne etwas zu schreiben.
     *
     * `rows`: eine Zeile je Buchung (Person, Posten, Tag); `ids` sind alle
     * Zusage-Zeilen dahinter (Schwester-Anfragen tragen dieselbe Buchung
     * zweimal, JobOffersZusage sagt beiden zu). `abgedeckt`: Zusage-Zeilen,
     * deren Buchung schon ueber eine Schwester uebergeben, von Hand eingetragen
     * oder angekommen ist — sie werden nicht geliefert, nur als eingetragen
     * markiert.
     *
     * @return array{rows: list<array{id:int, ids:list<int>, PNr:string, Projekt:string, Dispo:string, VDatum:string}>, abgedeckt: list<int>}
     */
    public function ermitteln(?string $einsatzRef = null): array
    {
        $einsatzRef = trim((string) $einsatzRef);
        $heute = now()->toDateString();
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        $zeilen = RecDispoJobOfferShift::query()
            ->with([
                'shift:id,request_id,rec_dispo_event_id,datum,dispoposten_id',
                'shift.event:id,einsatz_ref',
                'offer:id,rec_employee_id',
                'offer.employee:id,personnel_number',
            ])
            ->whereNotNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNull('zas_exported_at')
            ->whereNull('zas_entered_at')
            ->whereNull('zas_assignment_id')
            ->whereHas('shift', fn ($q) => $q
                ->whereNotNull('dispoposten_id')->where('dispoposten_id', '!=', '')
                ->whereDate('datum', '>=', $heute)
                ->when($teamId > 0, fn ($q2) => $q2->whereHas('request', fn ($r) => $r->where('team_id', $teamId)))
                ->whereHas('event', fn ($e) => $e
                    ->whereNotNull('einsatz_ref')->where('einsatz_ref', '!=', '')
                    ->when($einsatzRef !== '', fn ($e2) => $e2->where('einsatz_ref', $einsatzRef))))
            ->whereHas('offer.employee', fn ($q) => $q
                ->whereNotNull('personnel_number')->where('personnel_number', '!=', '')
                ->where(fn ($t) => $t->whereNull('is_test')->orWhere('is_test', false)))
            ->orderBy('id')
            ->get()
            ->filter(fn (RecDispoJobOfferShift $z) => $z->shift !== null && $z->shift->event !== null && $z->offer?->employee !== null);

        $kandidaten = [];
        foreach ($zeilen as $z) {
            $pnr = trim((string) $z->offer->employee->personnel_number);
            $projekt = trim((string) $z->shift->event->einsatz_ref);
            $posten = trim((string) $z->shift->dispoposten_id);
            // Nach trim nochmal: reine Leerzeichen und Nummern ohne Firmenkennung (RG/MA) bleiben Handarbeit.
            if ($pnr === '' || $projekt === '' || $posten === '' || !ZasPersonnelNumber::hasPrefix($pnr)) {
                continue;
            }
            $kandidaten[] = [
                'id'        => (int) $z->id,
                'schluessel' => self::schluessel((int) $z->offer->rec_employee_id, $posten, $z->shift->datum->format('Y-m-d')),
                'PNr'       => $pnr,
                'Projekt'   => $projekt,
                'Dispo'     => $posten,
                'VDatum'    => $z->shift->datum->format('d.m.Y'),
                '_sort'     => $projekt . '|' . $z->shift->datum->format('Y-m-d') . '|' . $pnr . '|' . str_pad((string) $z->id, 12, '0', STR_PAD_LEFT),
            ];
        }
        if ($kandidaten === []) {
            return ['rows' => [], 'abgedeckt' => []];
        }

        $belegt = $this->belegteSchluessel(
            array_values(array_unique(array_map(fn ($k) => (int) explode('|', $k['schluessel'])[0], $kandidaten))),
            array_values(array_unique(array_column($kandidaten, 'Dispo'))),
        );

        $gruppen = [];
        $abgedeckt = [];
        foreach ($kandidaten as $k) {
            if (isset($belegt[$k['schluessel']])) {
                $abgedeckt[] = $k['id'];
                continue;
            }
            $gruppen[$k['schluessel']] ??= $k + ['ids' => []];
            $gruppen[$k['schluessel']]['ids'][] = $k['id'];
        }
        usort($gruppen, fn ($a, $b) => strcmp($a['_sort'], $b['_sort']));

        return [
            'rows'      => array_map(fn (array $g) => array_diff_key($g, ['_sort' => true, 'schluessel' => true]), array_values($gruppen)),
            'abgedeckt' => $abgedeckt,
        ];
    }

    /** Nur die lieferbaren Zeilen (Lesen ohne Schreiben). */
    public function rows(?string $einsatzRef = null): array
    {
        return $this->ermitteln($einsatzRef)['rows'];
    }

    /**
     * Beanspruchen vor dem Ausliefern: je Buchung ein bedingter UPDATE auf die
     * fuehrende Zeile (`zas_exported_at IS NULL`). Nur Zeilen, die DIESER Abruf
     * beansprucht hat, kommen in die Datei; der Rest gehoert einem parallelen
     * Abruf. Schwestern derselben Buchung werden mit markiert. `zas_entered_at`
     * wird gesetzt, wo noch kein Haken von Hand steht (`zas_entered_by_user_id`
     * bleibt leer: das war die Automatik).
     *
     * @param array{rows: list<array<string, mixed>>, abgedeckt: list<int>} $ermittelt
     * @return list<array{id:int, ids:list<int>, PNr:string, Projekt:string, Dispo:string, VDatum:string}> gelieferte Zeilen
     */
    public function beanspruchen(array $ermittelt): array
    {
        $jetzt = now();
        $geliefert = [];
        foreach ($ermittelt['rows'] ?? [] as $row) {
            $n = RecDispoJobOfferShift::query()
                ->whereKey((int) $row['id'])->whereNull('zas_exported_at')
                ->update(['zas_exported_at' => $jetzt]);
            if ($n !== 1) {
                continue; // ein paralleler Abruf war schneller — der liefert diese Zeile
            }
            $ids = array_values(array_unique(array_map('intval', (array) ($row['ids'] ?? [$row['id']]))));
            RecDispoJobOfferShift::query()->whereIn('id', $ids)->whereNull('zas_exported_at')->update(['zas_exported_at' => $jetzt]);
            RecDispoJobOfferShift::query()->whereIn('id', $ids)->whereNull('zas_entered_at')->update(['zas_entered_at' => $jetzt]);
            $geliefert[] = $row;
        }

        $abgedeckt = array_values(array_unique(array_map('intval', $ermittelt['abgedeckt'] ?? [])));
        if ($abgedeckt !== []) {
            // Buchung ist ueber eine Schwester schon in ZAS: als eingetragen markieren, nie liefern.
            RecDispoJobOfferShift::query()->whereIn('id', $abgedeckt)->whereNull('zas_entered_at')->update(['zas_entered_at' => $jetzt]);
        }

        if ($geliefert !== [] || $abgedeckt !== []) {
            Log::info('ZAS dispo bookings: Zusagen uebergeben', [
                'geliefert' => count($geliefert),
                'ids'       => array_merge(...array_map(fn ($r) => (array) $r['ids'], $geliefert ?: [['ids' => []]])),
                'abgedeckt' => $abgedeckt,
            ]);
        }

        return $geliefert;
    }

    /**
     * Datei im ZAS-Format: Kopf mit vier Spalten, Datenzeilen mit fuenfter leerer Zelle.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function csv(array $rows, ZasCsvBuilder $builder): string
    {
        $out = ZasCsvBuilder::BOM . $builder->buildHeaderLine(self::COLUMNS);
        foreach ($rows as $row) {
            $out .= $builder->buildRow($row, self::ROW_COLUMNS);
        }

        return $out;
    }

    public static function schluessel(int $employeeId, string $posten, string $datumIso): string
    {
        return $employeeId . '|' . $posten . '|' . $datumIso;
    }

    /**
     * Buchungen (Person, Posten, Tag), die schon uebergeben, von Hand eingetragen
     * oder angekommen sind — eingegrenzt auf die Personen und Posten der Kandidaten.
     *
     * @param list<int> $employeeIds
     * @param list<string> $posten
     * @return array<string, true>
     */
    private function belegteSchluessel(array $employeeIds, array $posten): array
    {
        if ($employeeIds === [] || $posten === []) {
            return [];
        }
        $rows = RecDispoJobOfferShift::query()
            ->with(['shift:id,datum,dispoposten_id', 'offer:id,rec_employee_id'])
            ->whereNotNull('accepted_at')->whereNull('declined_at')
            ->where(fn ($q) => $q->whereNotNull('zas_exported_at')->orWhereNotNull('zas_entered_at')->orWhereNotNull('zas_assignment_id'))
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->whereIn('dispoposten_id', array_map('strval', $posten)))
            ->get();
        $out = [];
        foreach ($rows as $r) {
            if ($r->shift === null || $r->offer === null) {
                continue;
            }
            $out[self::schluessel((int) $r->offer->rec_employee_id, trim((string) $r->shift->dispoposten_id), $r->shift->datum->format('Y-m-d'))] = true;
        }

        return $out;
    }
}
