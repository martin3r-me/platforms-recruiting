<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;

/**
 * Der Lohn-/Laufzeit-Vorschlag des Schulungsleiters: vorschlagen und
 * uebernehmen. Speichert NICHT — der Aufrufer entscheidet, wann.
 *
 * Der Zeitpunkt wird hereingereicht statt aus now() geholt, damit der
 * Zustandswechsel (siehe ContractProposalState) pruefbar ist statt
 * behauptet.
 */
class ContractProposalService
{
    /**
     * Vorschlag des Schulungsleiters schreiben. Jeder Schreibvorgang setzt
     * vorschlag_at neu — dadurch faellt ein bereits uebernommener Vorschlag
     * von allein wieder auf "offen" zurueck, und HR sieht die Korrektur.
     */
    public static function propose(
        RecApplicant $applicant,
        ?string $zuschlag,
        ?string $vertragsbeginn,
        ?string $vertragsende,
        ?int $userId,
        \DateTimeInterface $now,
    ): void {
        $applicant->zuschlag_vorschlag = self::zahlOderNull($zuschlag);
        $applicant->vertragsbeginn_vorschlag = self::textOderNull($vertragsbeginn);
        $applicant->vertragsende_vorschlag = self::textOderNull($vertragsende);
        $applicant->vorschlag_at = $now;
        $applicant->vorschlag_by = $userId;
    }

    /**
     * Uebernahme durch HR: der vorgeschlagene Lohn wird zum scharfen Wert.
     *
     * Leere Vorschlagsfelder ueberschreiben nichts — schlaegt der
     * Schulungsleiter nur eine Laufzeit vor, bleibt ein von HR bereits
     * getippter Zuschlag stehen.
     *
     * Die Laufzeit hat kein scharfes Gegenstueck: Vertragsbeginn/-ende gibt
     * es nur als Formularwert und spaeter auf dem Vertrag. Sie bleibt
     * deshalb im Vorschlagsfeld stehen, und der HR-Schreibtisch laedt sie
     * beim Uebernehmen in sein Formular.
     */
    public static function take(RecApplicant $applicant, \DateTimeInterface $now): void
    {
        if ($applicant->zuschlag_vorschlag !== null) {
            $applicant->zuschlag = $applicant->zuschlag_vorschlag;
        }

        $applicant->vorschlag_taken_at = $now;
    }

    /** Die vorgeschlagene Laufzeit fuers HR-Formular, oder null. */
    public static function proposedDates(RecApplicant $applicant): ?array
    {
        if ($applicant->vertragsbeginn_vorschlag === null && $applicant->vertragsende_vorschlag === null) {
            return null;
        }

        return [
            'vertragsbeginn' => $applicant->vertragsbeginn_vorschlag,
            'vertragsende'   => $applicant->vertragsende_vorschlag,
        ];
    }

    /** Wie setApplicantZuschlag: Komma erlaubt, leer = kein Vorschlag. */
    private static function zahlOderNull(?string $wert): ?float
    {
        $roh = trim((string) $wert);
        if ($roh === '' || !preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $roh)) {
            return null;
        }

        return round((float) str_replace(',', '.', $roh), 2);
    }

    private static function textOderNull(?string $wert): ?string
    {
        $roh = trim((string) $wert);

        return $roh === '' ? null : $roh;
    }
}
