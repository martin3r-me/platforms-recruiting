<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Services\ContractSendReservationService;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\ReservedSendTrigger;

/**
 * Ausloeser und Aufraeumen fuer vorgemerkte Versaende (Spec Versand vormerken
 * §4–§6). Haengt am Modell, damit JEDER Weg zaehlt: Autopilot, oeffentliches
 * Formular, Dashboard-Button, Werkzeuge. Jeder Body in safelyRun(): ein Fehler
 * hier darf keinen regulaeren Save kippen.
 */
class RecContractSendReservationObserver
{
    public static function register(): void
    {
        RecApplicant::saved(static function (RecApplicant $applicant): void {
            self::safelyRun(function () use ($applicant): void {
                // Erst die billige Frage (geaenderte Spalten), dann die Abfrage —
                // sonst laeuft bei JEDEM Bewerber-Save eine Query mit.
                // is_active=false zaehlt NUR als Ausstieg, wenn weder Vertrag raus
                // noch MA angelegt ist: die MA-Anlage (CreateEmployeeFromApplicantService)
                // deaktiviert die Bewerbung MITTEN im Versand — vor dem Abschliessen
                // der Vormerkung. Ohne diese Ausnahme nahme jeder erfolgreiche
                // Versand seine eigene Vormerkung zurueck. Billige Pruefung zuerst.
                $ausgestiegen = ($applicant->wasChanged('rejected_at') && $applicant->rejected_at)
                    || ($applicant->wasChanged('is_parked') && $applicant->is_parked)
                    || ($applicant->wasChanged('is_active') && !$applicant->is_active
                        && !$applicant->hasAnyContractSent() && !$applicant->employee()->exists());
                $phaseGewechselt = $applicant->wasChanged('rec_phase_id') && $applicant->rec_phase_id;
                if (!$ausgestiegen && !$phaseGewechselt) {
                    return;
                }

                $vormerkung = $applicant->contractSendReservations()->offen()->first();
                if (!$vormerkung) {
                    return;
                }

                if ($ausgestiegen) {
                    app(ContractSendReservationService::class)->zuruecknehmen($vormerkung, 'Bewerber geparkt, abgelehnt oder deaktiviert.');
                    return;
                }

                if ($phaseGewechselt) {
                    $legtAn = ((RecPhase::find($applicant->rec_phase_id)?->completion_config ?? [])['creates_employee_on_completion'] ?? false) === true;
                    if ($legtAn) {
                        app(ReservedSendTrigger::class)->anstossen($applicant->id, 'phase');
                    }
                }
            }, 'rec_applicant.saved.reservation', $applicant->id);
        });

        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->wasChanged('status') || !in_array($booking->status, ['cancelled', 'no_show', 'rejected_on_site'], true)) {
                    return;
                }
                $vormerkung = RecContractSendReservation::query()
                    ->where('rec_applicant_id', $booking->rec_applicant_id)->offen()
                    ->where(fn ($q) => $q->whereNull('rec_interview_booking_id')->orWhere('rec_interview_booking_id', $booking->id))
                    ->first();
                if ($vormerkung) {
                    app(ContractSendReservationService::class)->zuruecknehmen($vormerkung, 'Buchung auf „' . $booking->status_label . '“ gesetzt.');
                }
            }, 'rec_interview_booking.saved.reservation', $booking->id);
        });

        RecApplicantLegalStatus::saved(static function (RecApplicantLegalStatus $legal): void {
            self::safelyRun(function () use ($legal): void {
                $euNein = $legal->wasChanged('is_eu_citizen') && $legal->is_eu_citizen === false;
                $geprueft = $legal->wasChanged('legal_status_checked_at') && $legal->legal_status_checked_at !== null;
                if (!$euNein && !$geprueft) {
                    return;
                }
                $applicant = RecApplicant::find($legal->rec_applicant_id);
                $vormerkung = $applicant?->contractSendReservations()->offen()->first();
                if (!$applicant || !$vormerkung) {
                    return;
                }

                if ($euNein) {
                    app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen($applicant, RecHrDeskCase::REASON_NON_EU_CITIZEN, null, sprintf(
                        'Versand vorgemerkt am %s (Vertragsbeginn %s) — nach Rechtsstatus-Prüfung und Freigabe geht er automatisch raus.',
                        $vormerkung->reserved_at?->format('d.m.Y H:i'),
                        $vormerkung->vertragsbeginn?->format('d.m.Y') ?? '—',
                    ));
                }

                if ($geprueft) {
                    app(ReservedSendTrigger::class)->anstossen($applicant->id, 'rechtsstatus');
                }
            }, 'rec_applicant_legal_status.saved.reservation', $legal->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Vormerkungs-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
