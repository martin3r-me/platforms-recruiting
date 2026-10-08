<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Collection;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Vertragsbeginn/-ende aus der Schulungsnachbereitung, gespeichert sobald
 * jemand sie eintraegt (08.10.2026). Vorher lebten sie nur im Browserfenster:
 * zwei Laptops sahen verschiedene Daten, ein Neuladen loeschte sie.
 *
 * Gespeichert per Query-Builder — keine Model-Events, also kein ZAS-Marker
 * (die Werte sind reine Planung bis zum Versand).
 *
 * Nach dem Versand ist der Vertrag die Wahrheit: fuer Bewerber mit
 * versendetem Vertrag liest uebernehmen() diese Spalten nicht mehr.
 */
final class GeplanteVertragsdaten
{
    /** 'Y-m-d' oder null — alles andere wird verworfen. */
    public static function normalisieren(?string $wert): ?string
    {
        $wert = trim((string) $wert);
        if ($wert === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert)) {
            return null;
        }
        [$j, $m, $t] = array_map('intval', explode('-', $wert));

        return checkdate($m, $t, $j) ? $wert : null;
    }

    public static function speichern(int $teamId, int $applicantId, ?string $beginn, ?string $ende): void
    {
        RecApplicant::query()
            ->whereKey($applicantId)
            ->where('team_id', $teamId)
            ->toBase()
            ->update([
                'vertragsbeginn_geplant' => self::normalisieren($beginn),
                'vertragsende_geplant'   => self::normalisieren($ende),
                'vertragsdaten_geplant_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Offene, noch nicht angefasste Vormerkung auf die neuen Daten ziehen —
     * sonst zeigte die Seite das neue Datum, der automatische Versand naehme
     * aber das alte aus der Vormerkung (Review 08.10.). Ohne Vertragsbeginn
     * bleibt die Vormerkung unveraendert: sie haengt am Beginn.
     */
    public static function vormerkungNachziehen(int $applicantId, ?string $beginn, ?string $ende): void
    {
        $beginn = self::normalisieren($beginn);
        if ($beginn === null) {
            return;
        }

        RecContractSendReservation::query()
            ->where('rec_applicant_id', $applicantId)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->whereNull('claimed_at')
            ->toBase()
            ->update([
                'vertragsbeginn' => $beginn,
                'vertragsende'   => self::normalisieren($ende),
            ]);
    }

    /**
     * Die gespeicherte Planung aller Bewerber eines Termins — eine schlanke
     * eigene Abfrage, bewusst NICHT ueber $this->bookings der Seite: im
     * hydrate()-Hook wuerde das die gecachte Liste vor der Aktion fuellen, und
     * Suche, Filter, Buchen, Loeschen zeigten danach einen alten Stand
     * (Review 08.10.).
     */
    public static function fuerTermin(int $interviewId): Collection
    {
        return RecApplicant::query()
            ->select(['id', 'vertragsbeginn_geplant', 'vertragsende_geplant', 'vertragsdaten_geplant_at'])
            ->whereIn('id', RecInterviewBooking::query()
                ->where('rec_interview_id', $interviewId)
                ->select('rec_applicant_id'))
            ->whereNotNull('vertragsdaten_geplant_at')
            ->with('contracts:id,rec_applicant_id,status,sent_at')
            ->get();
    }

    /**
     * Gespeicherte Planung in den Eingabe-Zustand der Seite uebernehmen. Die
     * Datenbank gewinnt, weil sie den juengsten Stand JEDES Laptops haelt.
     * Bewerber mit versendetem Vertrag werden nicht angefasst.
     *
     * @param array<int, array<string, mixed>> $contractDates
     * @param iterable<RecApplicant|null> $applicants
     * @return array<int, array<string, mixed>>
     */
    public static function uebernehmen(array $contractDates, iterable $applicants): array
    {
        foreach ($applicants as $applicant) {
            if (!$applicant || self::vertragVersendet($applicant)) {
                continue;
            }
            // Ohne Marker nie in der Nachbereitung gesetzt → Fensterstand bleibt.
            // MIT Marker gilt die Datenbank auch dann, wenn beide Felder geleert
            // wurden — sonst behielte der andere Laptop die alten Werte und
            // versendete mit ihnen (Review 08.10.).
            if ($applicant->getAttribute('vertragsdaten_geplant_at') === null) {
                continue;
            }
            $beginn = self::alsDatum($applicant->getAttribute('vertragsbeginn_geplant'));
            $ende = self::alsDatum($applicant->getAttribute('vertragsende_geplant'));
            $contractDates[(int) $applicant->id] = [
                'vertragsbeginn' => $beginn,
                'vertragsende'   => $ende,
            ] + ($contractDates[(int) $applicant->id] ?? []);
        }

        return $contractDates;
    }

    /** Wie RecApplicant::hasAnyContractSent(), nutzt aber die geladene Relation (kein Query je Zeile). */
    private static function vertragVersendet(RecApplicant $applicant): bool
    {
        if ($applicant->relationLoaded('contracts')) {
            return $applicant->contracts->contains(
                fn ($c) => $c->status !== 'cancelled' && $c->sent_at !== null,
            );
        }

        return $applicant->hasAnyContractSent();
    }

    private static function alsDatum(mixed $wert): ?string
    {
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        return self::normalisieren($wert === null ? null : substr((string) $wert, 0, 10));
    }
}
