<?php

namespace Platform\Recruiting\Support;

/**
 * Darf am HR-Schreibtisch ein Zertifikat ausgestellt werden?
 *
 * Kriterium ist die attended-Buchung, NICHT der Fall-Grund: auch ein
 * no_german_knowledge-Fall hat an der Schulung teilgenommen. Der heutige Anlass
 * ist die Nicht-EU-Ablehnung, aber eine in_array($reason, [...])-Bedingung
 * gehoert hier ausdruecklich nicht hin — sie wuerde dem Bewerber den Nachweis
 * genau dann verweigern, wenn ein neuer Ablehnungsgrund dazukommt.
 *
 * Der zweite Parameter ist der Team-Schalter issue_training_certificates
 * (IssueTrainingCertificateService::isEnabledForTeam), nicht die Existenz einer
 * Vorlage: mit dem Zuschnitt v3 steht der Inhalt als festes HTML in
 * TrainingCertificateContent, es gibt keine Zertifikat-Vorlage mehr.
 *
 * Reine Funktion ohne Query — die drei Bedingungen ermittelt der Aufrufer, weil
 * er sie billiger hat (die attended-Buchungen liegen als Batch-Ergebnis der
 * Liste schon vor).
 */
final class CertificateIssuanceEligibility
{
    public static function isAvailable(
        bool $hasAttendedBooking,
        bool $featureEnabledForTeam,
        bool $alreadyIssued
    ): bool {
        return $hasAttendedBooking && $featureEnabledForTeam && !$alreadyIssued;
    }
}
