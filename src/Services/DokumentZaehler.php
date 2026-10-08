<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Support\DokumentAkteZeilen;

/**
 * Zahlen je Dokument fuer die Ueberblicke (Seite Dokumente, Veranstaltung)
 * als EINE gruppierte Abfrage ueber rec_document_recipients — statt alle
 * Zustellungen (samt signature_data) der letzten 200 Dokumente bei jedem
 * Render und jedem Poll zu laden (Final-Review Fund 3).
 *
 * "erledigt"-Zaehler zaehlen nur nicht zurueckgezogene Zeilen, wie
 * DokumentFortschritt::fuer(). "ausstehend" = unversucht und nicht
 * zurueckgezogen; "ausstehend_alt" = davon aelter als die Versandfrist
 * (DokumentAkteZeilen::VERSAND_FRIST_MINUTEN).
 */
final class DokumentZaehler
{
    /**
     * @param  list<int> $documentIds
     * @return array<int, array{gesamt:int, zurueckgezogen:int, unterschrieben:int, bestaetigt:int, gesehen:int, benachrichtigt:int, ausstehend:int, ausstehend_alt:int}>
     */
    public static function fuer(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        if ($documentIds === []) {
            return [];
        }
        $grenze = DokumentAkteZeilen::versandGrenze()->format('Y-m-d H:i:s');
        $aktiv = 'withdrawn_at IS NULL';
        $unversucht = "$aktiv AND notified_at IS NULL AND notify_error IS NULL";

        $zeilen = DB::table('rec_document_recipients')
            ->whereIn('rec_document_id', $documentIds)
            ->groupBy('rec_document_id')
            ->selectRaw(
                'rec_document_id,'
                . ' COUNT(*) AS gesamt,'
                . ' SUM(CASE WHEN withdrawn_at IS NOT NULL THEN 1 ELSE 0 END) AS zurueckgezogen,'
                . " SUM(CASE WHEN $aktiv AND signed_at IS NOT NULL THEN 1 ELSE 0 END) AS unterschrieben,"
                . " SUM(CASE WHEN $aktiv AND acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS bestaetigt,"
                . " SUM(CASE WHEN $aktiv AND first_viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS gesehen,"
                . ' SUM(CASE WHEN notified_at IS NOT NULL THEN 1 ELSE 0 END) AS benachrichtigt,'
                . " SUM(CASE WHEN $unversucht THEN 1 ELSE 0 END) AS ausstehend,"
                . " SUM(CASE WHEN $unversucht AND (created_at IS NULL OR created_at < ?) THEN 1 ELSE 0 END) AS ausstehend_alt",
                [$grenze]
            )
            ->get();

        $out = [];
        foreach ($zeilen as $z) {
            $out[(int) $z->rec_document_id] = [
                'gesamt'         => (int) $z->gesamt,
                'zurueckgezogen' => (int) $z->zurueckgezogen,
                'unterschrieben' => (int) $z->unterschrieben,
                'bestaetigt'     => (int) $z->bestaetigt,
                'gesehen'        => (int) $z->gesehen,
                'benachrichtigt' => (int) $z->benachrichtigt,
                'ausstehend'     => (int) $z->ausstehend,
                'ausstehend_alt' => (int) $z->ausstehend_alt,
            ];
        }

        return $out;
    }

    /** Die leere Zeile fuer ein Dokument ohne Zustellungen. */
    public static function leer(): array
    {
        return ['gesamt' => 0, 'zurueckgezogen' => 0, 'unterschrieben' => 0, 'bestaetigt' => 0, 'gesehen' => 0, 'benachrichtigt' => 0, 'ausstehend' => 0, 'ausstehend_alt' => 0];
    }
}
