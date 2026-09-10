<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Traegt die Firma (RG/MA) bei eigenen Mitarbeiter-Anlagen nach, denen sie
 * fehlt.
 *
 * ANLASS (Kundenmeldung 10.09.2026): 23 frisch uebernommene Moenchengladbacher
 * MA kamen mit leerer Spalte `Firma` im ZAS-Export an. ZAS leitet die Filiale
 * aus Firma + Kostenstelle ab und fiel ohne Firma auf DUS zurueck, obwohl die
 * Kostenstelle 200 (MGL) stimmte. Ursache war die fehlende Zuweisung in
 * CreateEmployeeFromApplicantService (dort gefixt) — dieser Befehl raeumt den
 * Bestand auf, der zwischen dem 26.08. und dem Fix entstanden ist.
 *
 * NUR EIGENE ANLAGEN (rec_zas_inbound_file_id IS NULL). Bei Zeilen aus einer
 * ZAS-Lieferung gehoert das Feld ZAS; der Inbound traegt es dort beim naechsten
 * Lauf selbst nach (ZasInboundEmployeeImporter, companyFill). Sie hier
 * mitzunehmen wuerde den Vorfall vom 02.09.2026 wiederholen — der Telefon-Lauf
 * spuelte damals ~500 ZAS-Bestandsmitarbeiter in den Update-Export, der VOLLE
 * Zeilen liefert und in ZAS gepflegte Akten ueberschreibt.
 *
 * PER ELOQUENT, also MIT Update-Marker — bewusst anders als
 * NormalizeEmployeePhonesCommand, der observer-frei schreibt. Eine fehlende
 * Firma ist keine Schreibweise, sondern eine fachliche Angabe, die ZAS braucht:
 * ohne den Marker bliebe die Korrektur bei uns liegen und die Filialen in ZAS
 * blieben falsch.
 *
 * NIE UEBERSCHREIBEN. Gefuellt wird ausschliesslich ein leeres Feld, und der
 * Praefix einer vorhandenen Personalnummer gewinnt vor der Vorgabe aus der
 * Konfiguration — sonst wuerde aus einer MA-Person eine RG-Person.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-employee-company --dry-run
 *   php artisan recruiting:backfill-employee-company
 *   php artisan recruiting:backfill-employee-company --employee=1372
 */
class BackfillEmployeeCompany extends Command
{
    protected $signature = 'recruiting:backfill-employee-company
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur MA dieses Teams (Default: alle)}
        {--employee= : Nur diesen RecEmployee (ID)}';

    protected $description = 'Traegt die Firma (RG/MA) bei eigenen MA-Anlagen ohne Wert nach';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $team     = $this->option('team') !== null ? (int) $this->option('team') : null;
        $employee = $this->option('employee') !== null ? (int) $this->option('employee') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Es wird nichts geschrieben.');
        }

        $counts = $this->backfill($dryRun, $team, $employee, function (string $type, string $text): void {
            match ($type) {
                'warn'  => $this->warn($text),
                default => $this->line($text),
            };
        });

        $this->newLine();
        $this->info(sprintf(
            '%s: %d nachgetragen, %d ZAS-Anlagen uebersprungen — von %d MA ohne Firma.',
            $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT',
            $counts['filled'],
            $counts['skipped_zas'],
            $counts['total'],
        ));

        if (!$dryRun && $counts['filled'] > 0) {
            $this->info('Die nachgetragenen MA stehen jetzt im ZAS-Update-Export (updates.csv).');
        }

        return self::SUCCESS;
    }

    /**
     * Kern ohne Artisan-Lebenszyklus (testbar, Muster
     * NormalizeEmployeePhonesCommand::normalize).
     *
     * @param  callable(string,string):void  $emit
     * @return array{total:int, filled:int, skipped_zas:int}
     */
    public function backfill(bool $dryRun, ?int $team, ?int $employeeId, callable $emit): array
    {
        $vorgabe = (string) config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX);
        $counts  = ['total' => 0, 'filled' => 0, 'skipped_zas' => 0];

        RecEmployee::query()
            ->where(function ($q) {
                $q->whereNull('company')->orWhere('company', '');
            })
            ->when($team !== null, fn ($q) => $q->where('team_id', $team))
            ->when($employeeId !== null, fn ($q) => $q->where('id', $employeeId))
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($dryRun, $vorgabe, $emit, &$counts) {
                foreach ($employees as $e) {
                    $counts['total']++;
                    $label = trim("#{$e->id} " . ($e->last_name ?? '') . ', ' . ($e->first_name ?? ''));

                    if ($e->rec_zas_inbound_file_id !== null) {
                        $counts['skipped_zas']++;
                        $emit('warn', "UEBERSPRUNGEN {$label} — aus ZAS-Lieferung #{$e->rec_zas_inbound_file_id}, "
                            . 'die Firma traegt dort der Inbound nach');
                        continue;
                    }

                    // Praefix vor Vorgabe: hat der Inbound bereits eine
                    // Personalnummer in das leere Feld nachgetragen, steht die
                    // Firma dort schon drin und ist verbindlich.
                    $firma = ZasPersonnelNumber::prefixOf($e->personnel_number) ?? $vorgabe;
                    if ($firma === '') {
                        $emit('warn', "UEBERSPRUNGEN {$label} — keine Vorgabe konfiguriert");
                        continue;
                    }

                    $counts['filled']++;
                    $emit('line', ($dryRun ? 'WUERDE ' : '') . "SETZEN  {$label} -> {$firma}");

                    if (!$dryRun) {
                        // Eloquent, nicht DB::table: der RecEmployeeExportObserver
                        // soll zas_changed_at setzen (Begruendung im Klassenkopf).
                        $e->company = $firma;
                        $e->save();
                    }
                }
            });

        return $counts;
    }
}
