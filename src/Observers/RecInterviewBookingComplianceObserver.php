<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\NonEuPostTrainingGate;

/**
 * Nicht-EU-Abzweig "nach der Schulung": Statuswechsel einer Buchung auf
 * 'attended' routet ungeprüfte Nicht-EU-Bewerber (oder EU-Status
 * unbeantwortet) auf den HR-Schreibtisch — dort prüft HR und versendet
 * Verträge + Portallink selbst. Ersetzt das frühere P3-Routing.
 *
 * Fängt alle attended-Pfade (Nachbereitungs-Select, MCP-Tool) — attended
 * wird ausschließlich über Model-Saves gesetzt (verifiziert, kein
 * Query-Builder-Pfad, kein Event-Muting im Modul).
 */
class RecInterviewBookingComplianceObserver
{
    public static function register(): void
    {
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                // wasChanged greift nur bei Updates — ein Insert direkt mit
                // status='attended' (heute kein Pfad, aber Gate-Matrix deckt
                // null→attended ab) läuft über wasRecentlyCreated.
                if (!$booking->wasRecentlyCreated && !$booking->wasChanged('status')) {
                    return;
                }

                $applicant = $booking->applicant;
                if (!$applicant) {
                    return;
                }

                $legalStatus = $applicant->legalStatus;
                $shouldRoute = NonEuPostTrainingGate::shouldRoute(
                    $booking->getOriginal('status'),
                    $booking->status,
                    $legalStatus !== null,
                    $legalStatus?->is_eu_citizen,
                    (bool) $legalStatus?->isChecked(),
                );

                if (!$shouldRoute) {
                    return;
                }

                app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
                    $applicant,
                    RecHrDeskCase::REASON_NON_EU_CITIZEN,
                    null,
                    'Nach Schulung: Rechtsstatus prüfen + Verträge versenden.'
                );
            }, 'rec_interview_booking.saved.compliance', $booking->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Compliance-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
