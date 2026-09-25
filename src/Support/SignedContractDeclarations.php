<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Die Vorschalt-Angaben aus den unterschriebenen Arbeitsvertraegen eines
 * Bewerbers — juengster zuerst.
 *
 * Gemeinsame Quelle fuer alles, was aus einer unterschriebenen Erklaerung
 * gelesen wird: die Arbeitgeber-Erklaerung (SignedEmployerDeclaration) und
 * der Startwert des Tagekontos (SignedDayBudget). Die Auswahlregeln stehen
 * damit an EINER Stelle statt in jedem Leser neu.
 *
 * Auswahl:
 *  - nur ARBEITSVERTRAEGE, gleiches Praedikat wie ContractPreSigningType
 *    (Praefix AV-) — nur diese bekommen den Vorschalt-Schritt ueberhaupt
 *  - nur UNTERSCHRIEBENE; ein verschickter Vertrag ist keine Erklaerung
 *  - nicht storniert
 *  - juengster zuerst, id als Stichentscheid bei gleichem Datum
 *
 * Bewusst mehrere Zeilen statt nur der juengsten: ein juengerer Vertrag kann
 * unterschrieben sein, ohne die gesuchte Angabe zu tragen — UpdateContractTool
 * setzt signed_at ohne pre_signing_data. Der Aufrufer nimmt die erste Zeile,
 * die seine Angabe wirklich enthaelt.
 *
 * Ueber den Query Builder statt ueber das Modell: hier wird nur gelesen, und
 * die Aufrufer laufen in Pfaden, in denen Beziehungen nicht geladen sind.
 */
final class SignedContractDeclarations
{
    /** Mehr Arbeitsvertraege hat in der Praxis niemand; haelt die Abfrage beschraenkt. */
    private const MAX_ROWS = 10;

    /** @return list<array<string,mixed>> decodierte pre_signing_data, juengste zuerst */
    public static function preSigningDataFor(?int $applicantId): array
    {
        if (!$applicantId) {
            return [];
        }

        $rows = DB::table('rec_contracts as c')
            ->join('rec_contract_templates as t', 'c.rec_contract_template_id', '=', 't.id')
            ->where('c.rec_applicant_id', $applicantId)
            ->whereNotNull('c.signed_at')
            ->where('c.status', '!=', 'cancelled')
            ->where('t.code', 'like', 'AV-%')
            ->orderByDesc('c.signed_at')
            ->orderByDesc('c.id')
            ->limit(self::MAX_ROWS)
            ->get(['c.pre_signing_data']);

        $out = [];
        foreach ($rows as $row) {
            if ($row->pre_signing_data === null) {
                continue;
            }
            $data = json_decode((string) $row->pre_signing_data, true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }

        return $out;
    }
}
