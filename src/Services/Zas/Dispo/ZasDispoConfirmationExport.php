<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Rueckkanal Bestaetigungen -> ZAS (Kunde 08.09.): NUR die bestaetigten
 * Einbuchungen (User-Entscheid) — Schluessel ist die DS-ID aus der ZAS-eigenen
 * Lieferung, der Import auf ZAS-Seite matcht darueber und setzt den Haken
 * "bestaetigt".
 *
 * Bewusst OHNE Marker/Delta (anders als der MA-Update-Export): der Stand ist
 * klein, idempotent und jeder Abruf liefert die volle Wahrheit ab
 * (heute - $daysBack).
 */
class ZasDispoConfirmationExport
{
    public const COLUMNS = [
        'DSID', 'EinsatzRef', 'Einsatz', 'Datum', 'PNr', 'BestaetigtAm',
    ];

    /**
     * @return list<array<string, string>> Zeilen im COLUMNS-Schema
     */
    public function rows(int $daysBack = 7, ?string $einsatzRef = null): array
    {
        $cutoff = now()->subDays(max(0, $daysBack))->toDateString();

        return RecDispoAssignment::query()
            ->with('event')
            ->whereNotNull('confirmed_at')
            ->whereDate('datum', '>=', $cutoff)
            ->when($einsatzRef !== null && $einsatzRef !== '', fn ($q) => $q
                ->whereHas('event', fn ($e) => $e->where('einsatz_ref', $einsatzRef)))
            ->orderBy('rec_dispo_event_id')->orderBy('datum')->orderBy('pnr_raw')
            ->get()
            ->map(fn (RecDispoAssignment $a) => [
                'DSID'         => (string) $a->ds_ref,
                'EinsatzRef'   => (string) ($a->event->einsatz_ref ?? ''),
                'Einsatz'      => (string) ($a->event->name ?? ''),
                'Datum'        => $a->datum->format('d.m.Y'),
                'PNr'          => (string) $a->pnr_raw,
                'BestaetigtAm' => $a->confirmed_at?->format('d.m.Y H:i') ?? '',
            ])
            ->values()
            ->all();
    }
}
