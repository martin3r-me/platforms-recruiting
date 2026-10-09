<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecContract;

/**
 * Woher ein Vertrag kommt — die EINE Stelle fuer diesen Merker
 * (Schlussreview I1/I3, Spec Vertrag aus der Akte §2.2).
 *
 * "Aus der Akte" = das Vertrags-Extrafeld `zuschlag` ist gesetzt. Das setzt
 * VertragAusAkte immer; der Bewerbungsweg (SendContractsService) nie.
 * rec_applicant_id taugt NICHT als Merker: Akte-Vertraege an einer RG-Zeile
 * aus dem Funnel tragen die Bewerbung mit (ZAS-Upload-Links, Bewerberliste).
 *
 * Bekannte Grenze: ein Bewerbungs-Vertrag, dem ReissueContractService
 * (Vorlagen mit contract.extra_field.zuschlag) oder HR im "Felder"-Dialog
 * einen Zuschlag eingetragen hat, gilt ebenfalls als "aus der Akte" — dort
 * steht der Zuschlag dann auch im Vertrag, also rendert er richtig.
 */
final class VertragsHerkunft
{
    public static function ausAkte(RecContract $vertrag): bool
    {
        $zuschlag = $vertrag->getExtraField('zuschlag');

        return $zuschlag !== null && trim((string) $zuschlag) !== '';
    }

    /**
     * Neuer Text fuer einen bestehenden Vertrag. Akte-Vertraege (mit
     * Anstellung) ueber personalizeFuerAnstellung — Zuschlag aus dem
     * Vertragsfeld, Anstellung als Rueckfall. Alles andere wie bisher ueber
     * die Bewerbung; ohne Bewerbung ueber die Anstellung; ohne beides null.
     */
    public static function neuRendern(RecContract $vertrag): ?string
    {
        $vorlage = $vertrag->contractTemplate;
        if ($vorlage === null) {
            return null;
        }

        $anstellung = $vertrag->employee;
        if ($anstellung !== null && ($vertrag->applicant === null || self::ausAkte($vertrag))) {
            return $vorlage->personalizeFuerAnstellung($anstellung, $vertrag);
        }
        if ($vertrag->applicant !== null) {
            return $vorlage->personalizeContent($vertrag->applicant, $vertrag);
        }

        return null;
    }
}
