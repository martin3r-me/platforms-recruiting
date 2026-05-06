<?php

namespace Platform\Recruiting\Traits;

/**
 * Recruiting-spezifischer Hook fuer den Core PublicExtraFieldForm-Hook
 * `renderPublicFormCompletionExtras($state)`.
 *
 * Rendert am Phase-Ende eine Bestaetigungsbox mit:
 *  - dem confirmed Schulungs-Termin (Datum/Uhrzeit/Location)
 *  - einem location-aware "Alle Infos zur Schulung"-Button
 *
 * Wenn der Bewerber noch keine confirmed Buchung hat (z.B. weil
 * `confirm_booking_on_completion` an der Phase nicht greift, oder die
 * Buchung aus anderen Gruenden noch registered ist), liefert der Hook
 * null und der Core-Form rendert nur sein Default-Card.
 */
trait RendersPublicFormCompletionExtras
{
    public function renderPublicFormCompletionExtras(string $state): ?string
    {
        if (!method_exists($this, 'confirmedBooking') || !method_exists($this, 'getSchulungUrl')) {
            return null;
        }

        $booking = $this->confirmedBooking();
        if (!$booking || !$booking->interview) {
            return null;
        }

        return view('recruiting::partials.public-form-completion-schulung', [
            'booking'     => $booking,
            'interview'   => $booking->interview,
            'schulungUrl' => $this->getSchulungUrl(),
        ])->render();
    }
}
