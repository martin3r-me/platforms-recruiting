<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Support\BookingConfirmationBackfill;

/**
 * Einmaliger Backfill fuer rec_interview_bookings.confirmed_at (07.09.2026).
 *
 * Zwei Quellen, beide nie ueberschreibend (nur confirmed_at IS NULL):
 *
 *  1. Log booking_confirmed_by_reply (WhatsApp-"Ja"): exakter Zeitpunkt =
 *     created_at des Log-Eintrags, Buchungs-ID aus dem Summary-Text geparst
 *     (BookingConfirmationBackfill — die ID steht dort nur im Text). Der
 *     FRUEHESTE Eintrag je Buchung gewinnt, wie beim Mutator.
 *  2. Buchungen, die HEUTE auf status='confirmed' stehen: Stempel =
 *     updated_at (Naeherung — der echte Zeitpunkt ist nicht protokolliert).
 *
 * NICHT rekonstruierbar: manuell Bestaetigte, deren Status bereits mit
 * attended/no_show ueberschrieben wurde und die nie per WhatsApp geantwortet
 * haben — dafuer existiert keine Spur. Der Spalten-Tooltip der Statistik
 * benennt diese Luecke.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-booking-confirmations --dry-run
 *   php artisan recruiting:backfill-booking-confirmations
 */
class BackfillBookingConfirmations extends Command
{
    protected $signature = 'recruiting:backfill-booking-confirmations
        {--dry-run : Nur anzeigen, was gestempelt wuerde}';

    protected $description = 'Fuellt rec_interview_bookings.confirmed_at aus Auto-Pilot-Log und aktuellem Status (nie ueberschreibend)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->info('[DRY-RUN] Keine Aenderungen werden vorgenommen.');
        }

        // Quelle 1: fruehester Log-Zeitpunkt je Buchung. chunkById, weil die
        // Log-Tabelle gross ist (Silent-Flood-Altlast); der Filter auf den Typ
        // haelt die Menge klein.
        $ausLog = [];
        RecAutoPilotLog::where('type', 'booking_confirmed_by_reply')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use (&$ausLog) {
                foreach ($logs as $log) {
                    $bookingId = BookingConfirmationBackfill::bookingIdFromSummary($log->summary);
                    if ($bookingId === null) {
                        continue;
                    }
                    // Erster (aeltester) Treffer gewinnt — Logs kommen id-aufsteigend
                    $ausLog[$bookingId] ??= (string) $log->created_at;
                }
            });

        $gestempeltLog = 0;
        foreach ($ausLog as $bookingId => $zeitpunkt) {
            $query = RecInterviewBooking::whereKey($bookingId)->whereNull('confirmed_at');
            if ($dryRun) {
                $gestempeltLog += $query->count();
                continue;
            }
            // Query-Update statt Model-Save: bewusst OHNE Mutator/updated_at —
            // ein historischer Stempel soll die Zeile nicht als "geaendert" markieren.
            $gestempeltLog += $query->toBase()->update(['confirmed_at' => $zeitpunkt]);
        }

        // Quelle 2: aktueller Status confirmed, noch ohne Stempel
        $aktuellQuery = RecInterviewBooking::where('status', 'confirmed')->whereNull('confirmed_at');
        if ($dryRun) {
            $gestempeltAktuell = $aktuellQuery->count();
        } else {
            $gestempeltAktuell = 0;
            foreach ($aktuellQuery->get() as $booking) {
                $booking->newQuery()->whereKey($booking->id)
                    ->toBase()->update(['confirmed_at' => (string) $booking->updated_at]);
                $gestempeltAktuell++;
            }
        }

        $this->line('Aus dem Log gestempelt' . ($dryRun ? ' (wuerde)' : '') . ': ' . $gestempeltLog
            . ' (Log-Eintraege mit Buchungs-ID: ' . count($ausLog) . ')');
        $this->line('Aus aktuellem Status confirmed' . ($dryRun ? ' (wuerde)' : '') . ': ' . $gestempeltAktuell);

        return self::SUCCESS;
    }
}
