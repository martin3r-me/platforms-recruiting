<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Support\SignedEmployerDeclaration;

/**
 * Traegt die Arbeitgeber-Erklaerung aus dem unterschriebenen Arbeitsvertrag
 * im Mitarbeiter-Bestand nach (Markus 24.09.2026).
 *
 * DER EIGENTLICHE GRUND ist nicht ein Fehlerfall, sondern der Normalbetrieb:
 * ContractSigning schreibt auf `applicant->employee` — eine 1:1-Beziehung.
 * Eine Person kann aber ZWEI rec_employees haben, weil ZAS zwei Firmen
 * bedient (RG und MA, Chaieb-Befund 10.09.2026). Beim Unterschreiben bekommt
 * dann nur einer der beiden Datensaetze die Erklaerung. Dieses Kommando
 * arbeitet pro MITARBEITER und schliesst die Luecke.
 *
 * Die Zugabe: scheitert der Uebertrag bei der Unterschrift (DB-Fehler), ist
 * der Nachleseweg ueber die MA-Anlage fuer diese Person vorbei — sie ist ja
 * schon angelegt. Auch das faengt dieser Lauf.
 *
 * NUR LEERE FELDER. Eine im Portal gegebene Antwort ist juenger als der
 * Vertrag und wird nie ueberschrieben.
 *
 * OBSERVER-FREI, bewusst — wie BackfillNationality. Geschrieben wird per
 * Query-Builder, `zas_changed_at` bleibt unberuehrt. Heute gehen die beiden
 * Spalten ohnehin nicht nach ZAS; sobald sie es tun, wuerde ein
 * Eloquent-Lauf den halben Bestand in die naechste updates.csv spuelen
 * (Vorfall 02.09.2026). Die Nachlieferung an ZAS ist ein eigener,
 * angekuendigter Schritt.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-employer-declaration --dry-run
 *   php artisan recruiting:backfill-employer-declaration
 *   php artisan recruiting:backfill-employer-declaration --employee=1372
 */
class BackfillEmployerDeclaration extends Command
{
    protected $signature = 'recruiting:backfill-employer-declaration
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur MA dieses Teams (Default: alle)}
        {--employee= : Nur diesen RecEmployee (ID)}';

    protected $description = 'Traegt die Arbeitgeber-Erklaerung aus dem Arbeitsvertrag nach (observer-frei, kein Export-Marker)';

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
            '%s — gesehen: %d, gesetzt: %d, uebersprungen: %d',
            $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT',
            $counts['seen'],
            $counts['set'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(string,string):void $out
     * @return array{seen:int, set:int, skipped:int}
     */
    public function backfill(bool $dryRun, ?int $teamId, ?int $employeeId, callable $out): array
    {
        $query = DB::table('rec_employees')
            // Nur Leerstellen. Eine Portal-Antwort ist juenger als der Vertrag.
            ->whereNull('is_main_employer')
            ->whereNotNull('rec_applicant_id');

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }
        if ($employeeId !== null) {
            $query->where('id', $employeeId);
        }

        $counts = ['seen' => 0, 'set' => 0, 'skipped' => 0];

        // Die Erklaerung haengt am BEWERBER, die Luecke aber am MITARBEITER —
        // deshalb wird je Mitarbeiter gelesen. Zwei Anstellungen derselben
        // Person bekommen so beide denselben Wert.
        foreach ($query->orderBy('id')->get(['id', 'rec_applicant_id']) as $row) {
            $counts['seen']++;

            $attributes = SignedEmployerDeclaration::forApplicant((int) $row->rec_applicant_id);
            if ($attributes === []) {
                $counts['skipped']++;
                continue;
            }

            $counts['set']++;
            $out('line', sprintf(
                'MA #%d: Hauptarbeitgeber=%s%s',
                $row->id,
                $attributes['is_main_employer'] ? 'ja' : 'nein',
                $attributes['other_employer'] !== null ? ' (' . $attributes['other_employer'] . ')' : '',
            ));

            if (!$dryRun) {
                DB::table('rec_employees')->where('id', $row->id)->update($attributes);
            }
        }

        return $counts;
    }
}
