<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicant;

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
            ]);
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
            $beginn = self::alsDatum($applicant->getAttribute('vertragsbeginn_geplant'));
            $ende = self::alsDatum($applicant->getAttribute('vertragsende_geplant'));
            if ($beginn === null && $ende === null) {
                continue;
            }
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
