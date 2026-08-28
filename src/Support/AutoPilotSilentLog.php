<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecAutoPilotLog;

/**
 * Dedupe fuer `silent`-Eintraege des Auto-Piloten.
 *
 * Der Auto-Pilot laeuft jede Minute und stellt fuer stille Bewerber (Phase mit
 * auto_pilot_disabled, offener Ort-Wartelisten-Eintrag) jedes Mal dasselbe
 * fest. Bis 28.08.2026 schrieb er das jedes Mal ins Log: ~1.440 identische
 * Zeilen pro Bewerber und Tag, ~84.000/Tag allein fuer MGL, Log-IDs bei
 * 19 Mio., das Enrichment-Log dieser Bewerber nur noch Rauschen
 * (Ticket docs/tickets/2026-08-28-autopilot-silent-log-flood.md).
 *
 * Regel: geschrieben wird nur, wenn der JUENGSTE Log-Eintrag des Bewerbers
 * nicht bereits derselbe stille Text ist. Damit steht der Zustand einmal beim
 * Eintritt im Log — und noch einmal, sobald dazwischen etwas anderes passiert
 * ist (Erinnerung, Buchung, anderer stiller Grund), weil dann der juengste
 * Eintrag ein anderer ist. Kein Cache, kein zusaetzlicher Zustand: die
 * Wahrheit steht in der Tabelle, in die ohnehin geschrieben wird.
 *
 * Nicht: Bewerber aus der Auto-Pilot-Query filtern. Der Lauf muss sie weiter
 * sehen, weil am Anfang von processApplicant() der Phasen-Abschluss geprueft
 * wird (Wartelisten-Bucher, Vertrags-Ruecklauf).
 */
final class AutoPilotSilentLog
{
    public const TYPE = 'silent';

    /**
     * @return bool true = Eintrag geschrieben, false = verschluckt (identisch
     *              zum juengsten Eintrag) oder Schreibfehler
     */
    public static function record(int $applicantId, string $summary): bool
    {
        try {
            $latest = RecAutoPilotLog::query()
                ->where('rec_applicant_id', $applicantId)
                ->orderByDesc('id')
                ->first(['id', 'type', 'summary']);

            if ($latest !== null && $latest->type === self::TYPE && (string) $latest->summary === $summary) {
                return false;
            }

            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicantId,
                'type' => self::TYPE,
                'summary' => $summary,
            ]);

            return true;
        } catch (\Throwable) {
            // Log darf den Auto-Pilot-Lauf nie kippen (Muster logAutoPilot()).
            return false;
        }
    }
}
