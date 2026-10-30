<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;

/**
 * Rueckkanal Zusagen -> ZAS als Buchungen (Spec 2026-10-30): jede zugesagte
 * Job-Schicht einmal im Importformat von Olaf Michel
 * (`PNr;Projekt;Dispo;VDatum`). ZAS legt die Zeile in der Dispo-Liste an und
 * bestaetigt sie, wenn sie zu den Zeiteintraegen passt; das Ankommen erkennt
 * danach DispoJobZasAbgleich ueber die naechste Lieferung.
 *
 * Anders als die Bestaetigungen MIT Marker: eine Zeile, die einmal geliefert
 * wurde, geht nie wieder raus (sonst Dublette in ZAS).
 */
class ZasDispoBookingExport
{
    public const COLUMNS = ['PNr', 'Projekt', 'Dispo', 'VDatum'];

    /** Datenzeilen tragen eine fuenfte, leere Zelle — exakt wie Olafs Beispiel aus dem alten Portal. */
    private const ROW_COLUMNS = ['PNr', 'Projekt', 'Dispo', 'VDatum', ''];

    /**
     * @return list<array{id:int, PNr:string, Projekt:string, Dispo:string, VDatum:string}>
     */
    public function rows(?string $einsatzRef = null): array
    {
        $einsatzRef = trim((string) $einsatzRef);
        $heute = now()->toDateString();

        $zeilen = RecDispoJobOfferShift::query()
            ->with([
                'shift:id,rec_dispo_event_id,datum,dispoposten_id',
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
                ->whereHas('event', fn ($e) => $e
                    ->whereNotNull('einsatz_ref')->where('einsatz_ref', '!=', '')
                    ->when($einsatzRef !== '', fn ($e2) => $e2->where('einsatz_ref', $einsatzRef))))
            ->whereHas('offer.employee', fn ($q) => $q
                ->whereNotNull('personnel_number')->where('personnel_number', '!=', '')
                ->where(fn ($t) => $t->whereNull('is_test')->orWhere('is_test', false)))
            ->get();

        return $zeilen
            ->filter(fn (RecDispoJobOfferShift $z) => $z->shift !== null && $z->shift->event !== null && $z->offer?->employee !== null)
            ->map(fn (RecDispoJobOfferShift $z) => [
                'id'      => (int) $z->id,
                'PNr'     => trim((string) $z->offer->employee->personnel_number),
                'Projekt' => trim((string) $z->shift->event->einsatz_ref),
                'Dispo'   => trim((string) $z->shift->dispoposten_id),
                'VDatum'  => $z->shift->datum->format('d.m.Y'),
                '_sort'   => trim((string) $z->shift->event->einsatz_ref) . '|' . $z->shift->datum->format('Y-m-d') . '|' . trim((string) $z->offer->employee->personnel_number) . '|' . str_pad((string) $z->id, 12, '0', STR_PAD_LEFT),
            ])
            ->sortBy('_sort')
            ->map(fn (array $r) => array_diff_key($r, ['_sort' => true]))
            ->values()
            ->all();
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

    /**
     * Marker nach der Lieferung: uebergeben + (falls noch nicht von Hand) in ZAS
     * eingetragen. Bedingt auf `zas_exported_at IS NULL`, damit ein doppelter
     * Abruf nie zweimal markiert. `zas_entered_by_user_id` bleibt leer: das war
     * die Automatik.
     *
     * @param list<int> $ids
     * @return int Anzahl als uebergeben markierter Zeilen
     */
    public function markieren(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $jetzt = now();
        // Zwischen Lesen und Markieren von Hand eingetragen? Dann bleibt der Haken-Zeitpunkt stehen.
        RecDispoJobOfferShift::query()
            ->whereIn('id', $ids)->whereNull('zas_exported_at')->whereNull('zas_entered_at')
            ->update(['zas_entered_at' => $jetzt]);

        return RecDispoJobOfferShift::query()
            ->whereIn('id', $ids)->whereNull('zas_exported_at')
            ->update(['zas_exported_at' => $jetzt]);
    }
}
