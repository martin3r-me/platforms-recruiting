<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecContract;

/**
 * Woher ein Vertrag kommt — die EINE Stelle fuer diesen Merker
 * (Schlussreview I1/I3 + N1, Spec Vertrag aus der Akte §2.2).
 *
 * "Aus der Akte" = das Vertrags-Extrafeld `herkunft` steht auf `akte`.
 * Geschrieben wird es NUR hier (markieren()), aufgerufen von
 * VertragAusAkte und von "Neu ausstellen" (ReissueContractService), wenn
 * der Vorgaenger selbst aus der Akte kam. Der Bewerbungsweg
 * (SendContractsService) setzt es nie; im "Felder"-Dialog der
 * Bewerberseite ist es ausgeblendet (istMerkerFeld()).
 *
 * WARUM NICHT DAS ZUSCHLAGSFELD (Schlussreview N1): eine Live-Vorlage des
 * Bewerbungswegs mappt contract.extra_field.zuschlag — deren Vertraege
 * tragen den Zuschlag ebenfalls am Vertrag und waeren sonst "aus der Akte"
 * (offene Punkte, Neu-Rendern ueber die Anstellung). Das Zuschlagsfeld
 * bleibt Quelle fuer den Betrag (applicant.zuschlag bevorzugt es in allen
 * Vertraegen), entscheidet aber nicht mehr ueber die Herkunft.
 * rec_applicant_id taugt ebenfalls NICHT: Akte-Vertraege an einer RG-Zeile
 * aus dem Funnel tragen die Bewerbung mit (ZAS-Upload-Links, Bewerberliste).
 *
 * Keine neue Spalte (keine Migration): das Feld legt
 * recruiting:seed-rec-contract-extra-fields an. Ohne Definition waere
 * setExtraField() ein stiller No-Op — markieren() bricht dann laut ab.
 */
final class VertragsHerkunft
{
    public const FELD = 'herkunft';

    public const AKTE = 'akte';

    public static function ausAkte(RecContract $vertrag): bool
    {
        return trim((string) $vertrag->getExtraField(self::FELD)) === self::AKTE;
    }

    /**
     * Setzt Herkunft UND Zuschlag am Vertrag. Das Zuschlagsfeld ist ab dem
     * Seed Text ("0,60"); hat ein Team es frueher als Zahl angelegt,
     * verwirft setTypedValue() das Komma still — dort steht die Zahl.
     *
     * @throws \DomainException wenn die Felddefinition fehlt (Seed nicht gelaufen)
     */
    public static function markieren(RecContract $vertrag, float $zuschlag): void
    {
        $vertrag->setExtraField(self::FELD, self::AKTE);
        if (!self::ausAkte($vertrag)) {
            throw new \DomainException('Vertragsfeld „herkunft“ fehlt — bitte recruiting:seed-rec-contract-extra-fields ausführen.');
        }

        $typ = $vertrag->getExtraFieldDefinitions()->firstWhere('name', 'zuschlag')?->type;
        $vertrag->setExtraField('zuschlag', $typ === 'number' ? (string) $zuschlag : ZuschlagWert::format($zuschlag));
    }

    /** Der Merker ist kein HR-Feld: im "Felder"-Dialog ausblenden. */
    public static function istMerkerFeld(?string $name): bool
    {
        return $name === self::FELD;
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
