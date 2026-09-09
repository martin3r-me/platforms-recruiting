<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Einmalige Bereinigung: nimmt den ZAS-Export-Marker von Bewerbern, die der
 * Endpunkt ohnehin nicht ausliefern kann.
 *
 * ANLASS (09.09.2026): `export_changed_at` war bei 2252 Bewerbern gesetzt, aber
 * nur 317 davon kamen durch das Gate des Endpunkts (versendeter Vertrag, kein
 * Testdatensatz). Die restlichen 1935 waren per Konstruktion nicht lieferbar —
 * der Observer markierte bedingungslos, der Endpunkt filterte wieder weg, der
 * Marker blieb stehen. Ausgeliefert wurde dadurch nie etwas Falsches, aber die
 * Zahl „wartende Marker" war als Ueberwachungssignal wertlos: sie bestand zu
 * 86 % aus Rauschen und liess einen Auslieferungsstau vermuten, den es so
 * nicht gab.
 *
 * Das Gate steckt seit demselben Tag im Observer (RecApplicantExportObserver),
 * neue Marker entstehen also nicht mehr. Dieser Command raeumt den Altbestand
 * weg, damit „Marker gesetzt" wieder „liegt zur Auslieferung an" bedeutet.
 *
 * NICHT ANGEFASST werden die lieferbaren Marker: wer einen versendeten Vertrag
 * hat, bleibt markiert und wird beim naechsten Abruf ausgeliefert. Der Command
 * nimmt also nichts weg, was ZAS je bekommen haette.
 *
 * Aufruf:
 *   php artisan recruiting:zas-export-marker-cleanup --dry-run
 *   php artisan recruiting:zas-export-marker-cleanup
 *   php artisan recruiting:zas-export-marker-cleanup --force   (ohne Rueckfrage)
 */
class ZasExportMarkerCleanup extends Command
{
    protected $signature = 'recruiting:zas-export-marker-cleanup
        {--team= : Nur Bewerber dieses Teams}
        {--dry-run : Nur zaehlen, nichts schreiben}
        {--force : Ohne Rueckfrage schreiben}';

    protected $description = 'Entfernt ZAS-Export-Marker bei Bewerbern ohne versendeten Vertrag (Altbestands-Rauschen)';

    public function handle(): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;

        $lieferbar = $this->query($teamId, true)->count();
        $rauschen = $this->query($teamId, false)->count();

        $this->newLine();
        $this->info(sprintf('Marker gesetzt: %d — davon lieferbar %d, nicht lieferbar %d.',
            $lieferbar + $rauschen, $lieferbar, $rauschen));
        $this->line('  lieferbar     = versendeter Vertrag vorhanden, bleibt unangetastet');
        $this->line('  nicht lieferbar = kein versendeter Vertrag oder Testdatensatz → Marker wird genullt');
        $this->newLine();

        if ($rauschen === 0) {
            $this->info('Nichts zu bereinigen.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: {$rauschen} Marker wuerden genullt. Re-run ohne --dry-run zum Anwenden.");

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("{$rauschen} Marker jetzt nullen?", true)) {
            $this->warn('Abgebrochen.');

            return self::SUCCESS;
        }

        $affected = $this->query($teamId, false)->update(['export_changed_at' => null]);

        $this->info(sprintf('OK — %d Marker genullt. Verbleibend in der Warteschlange: %d.', $affected, $lieferbar));

        return self::SUCCESS;
    }

    /**
     * @param  bool $deliverable true = die lieferbaren, false = das Rauschen
     * @return \Illuminate\Database\Query\Builder
     */
    protected function query(?int $teamId, bool $deliverable)
    {
        $hasSentContract = function ($q) {
            $q->select(DB::raw(1))
                ->from('rec_contracts')
                ->whereColumn('rec_contracts.rec_applicant_id', 'rec_applicants.id')
                ->whereNotNull('rec_contracts.sent_at');
        };

        $query = DB::table('rec_applicants')
            ->whereNotNull('export_changed_at')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId));

        // Lieferbar heisst: kein Testdatensatz UND versendeter Vertrag. Das
        // Rauschen ist die Gegenmenge — Testdatensaetze eingeschlossen, denn
        // die filtert der Endpunkt ebenfalls weg.
        return $deliverable
            ? $query->where('is_test', false)->whereExists($hasSentContract)
            : $query->where(function ($q) use ($hasSentContract) {
                $q->where('is_test', true)->orWhereNotExists($hasSentContract);
            });
    }
}
