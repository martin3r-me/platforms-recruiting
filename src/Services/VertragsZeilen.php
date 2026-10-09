<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\VertragsDeckung;

/**
 * Die Arbeitsvertraege EINER Anstellung in der Form, die VertragsDeckung
 * erwartet. Eine Stelle fuer Neuanlage (Doppelabdeckung) und Einsatz-
 * Pruefung — zwei Lader liefen auseinander.
 *
 * $firma filtert nach der Gesellschaft der VORLAGE: an einer Anstellung
 * haengt ein RG-AV nur, wenn jemand ihn falsch angehaengt hat — er darf dann
 * trotzdem keinen MA-Einsatz decken (Review-Focus 3).
 * Vorlagen mit SoftDelete zaehlen mit (wie ContractAnchorService).
 */
final class VertragsZeilen
{
    /** @return list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}> */
    public static function fuerAnstellung(int $anstellungId, ?string $firma = null): array
    {
        $vertraege = RecContract::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('status', '!=', 'cancelled')
            ->with(['contractTemplate' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')
            ->get();

        $zeilen = [];
        foreach ($vertraege as $v) {
            $vorlage = $v->contractTemplate;
            if ($vorlage === null || !VertragsDeckung::istAv($vorlage->code)) {
                continue;
            }
            if ($firma !== null && strtoupper(trim((string) $vorlage->company)) !== strtoupper(trim($firma))) {
                continue;
            }
            $zeilen[] = [
                'id'         => (int) $v->id,
                'code'       => (string) $vorlage->code,
                'status'     => (string) $v->status,
                'signed_at'  => $v->signed_at?->format('Y-m-d H:i:s'),
                'superseded' => $v->superseded_by_contract_id !== null,
                'beginn'     => VertragsDeckung::datum($v->getExtraField('vertragsbeginn')),
                'ende'       => VertragsDeckung::datum($v->getExtraField('vertragsende')),
            ];
        }

        return $zeilen;
    }
}
