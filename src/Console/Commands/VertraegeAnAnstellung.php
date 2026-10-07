<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Support\AnstellungsZuordnung;

/**
 * Haengt Bestandsvertraege an ihre Anstellung (Spec Vertrag an der Anstellung
 * §3.5). Schritt 0 typisiert die AV-Vorlagen (taetigkeit = eventmitarbeiter,
 * Annahme 1 der Spec), Schritt 1 setzt rec_contracts.rec_employee_id nach
 * DERSELBEN Regel wie der Live-Pfad (RecContractTemplate::anstellungFuer).
 *
 * Idempotent (nur rec_employee_id IS NULL), trockenlauf-faehig, OBSERVER-FREI:
 * Query Builder auf rec_contracts, nie RecContract::save() (der saved-Hook
 * schreibt auf die HR-Daten und stempelt den ZAS-Marker), nie eine
 * Eloquent-Schreibung auf rec_employees (Vorfall 02.09.2026).
 *
 * `mehrdeutig` (zwei Anstellungen derselben Firma) bleibt NULL und wird
 * gemeldet — Handarbeit mit Blick auf den Menschen; `firma_fehlt` ebenso:
 * ein RG-Vertrag an der MA-Akte waere der Fehler im Kleinen. Bericht nennt
 * Kennungen, nie Namen. FAILURE nur bei Datenbankfehlern.
 *
 * Deploy-Reihenfolge (§3.6): migrate → report-signed-without-employee
 * --backfill-links --skip-tests → dieses Kommando --dry-run → dieses Kommando.
 *
 * Aufruf:
 *   php artisan recruiting:vertraege-an-anstellung --dry-run
 *   php artisan recruiting:vertraege-an-anstellung
 *   php artisan recruiting:vertraege-an-anstellung --team=3
 */
class VertraegeAnAnstellung extends Command
{
    protected $signature = 'recruiting:vertraege-an-anstellung
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur Vertraege/Vorlagen dieses Teams (Default: alle)}';

    protected $description = 'Haengt Bestandsvertraege an ihre Anstellung und typisiert AV-Vorlagen (observer-frei)';

    public const TAETIGKEIT_AV = 'eventmitarbeiter';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $team   = $this->option('team') !== null ? (int) $this->option('team') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Es wird nichts geschrieben.');
        }

        try {
            $counts = $this->backfill($dryRun, $team, function (string $type, string $text): void {
                match ($type) {
                    'warn'  => $this->warn($text),
                    default => $this->line($text),
                };
            });
        } catch (\Throwable $e) {
            $this->error('Abgebrochen: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — Vorlagen typisiert: %d · zugeordnet: %d · mehrdeutig: %d · firma_fehlt: %d · ohne_anstellung: %d · verwaist: %d',
            $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT',
            $counts['vorlagen_typisiert'], $counts['zugeordnet'], $counts['mehrdeutig'],
            $counts['firma_fehlt'], $counts['ohne_anstellung'], $counts['verwaist'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(string,string):void $out
     * @return array{vorlagen_typisiert:int, zugeordnet:int, mehrdeutig:int, firma_fehlt:int, ohne_anstellung:int, verwaist:int}
     */
    public function backfill(bool $dryRun, ?int $teamId, callable $out): array
    {
        $counts = ['vorlagen_typisiert' => 0, 'zugeordnet' => 0, 'mehrdeutig' => 0, 'firma_fehlt' => 0, 'ohne_anstellung' => 0, 'verwaist' => 0];

        // Schritt 0 — AV-Vorlagen typisieren. Praefix-Filter ist die Regel:
        // Belehrung (IFSG) und Zusatzvereinbarung (AT-) sind keine Taetigkeit.
        $vorlagen = DB::table('rec_contract_templates')
            ->whereNull('deleted_at')
            ->where('code', 'like', 'AV-%')
            ->whereNull('taetigkeit')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'code', 'company']);
        foreach ($vorlagen as $v) {
            $counts['vorlagen_typisiert']++;
            $out('line', sprintf('Vorlage #%d %s: company=%s taetigkeit=%s', $v->id, $v->code, $v->company, self::TAETIGKEIT_AV));
            if (!$dryRun) {
                DB::table('rec_contract_templates')->where('id', $v->id)->update(['taetigkeit' => self::TAETIGKEIT_AV]);
            }
        }

        // Verwaiste Anker nur melden — kein FK, also sieht es sonst niemand.
        $verwaist = DB::table('rec_contracts as c')
            ->whereNotNull('c.rec_employee_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rec_employees as e')->whereColumn('e.id', 'c.rec_employee_id'))
            ->when($teamId !== null, fn ($q) => $q->where('c.team_id', $teamId))
            ->orderBy('c.id')
            ->get(['c.id', 'c.rec_employee_id']);
        foreach ($verwaist as $row) {
            $counts['verwaist']++;
            $out('warn', sprintf('Vertrag #%d: Anker rec_employee_id=%d zeigt auf keine Anstellung mehr (verwaist)', $row->id, $row->rec_employee_id));
        }

        // Schritt 1 — Vertraege ohne Anker, nach der einen Regel.
        $offene = DB::table('rec_contracts')
            ->whereNull('rec_employee_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'rec_applicant_id', 'rec_contract_template_id']);

        $vorlagenCache = [];
        $bewerberCache = [];
        foreach ($offene as $row) {
            $template = $vorlagenCache[$row->rec_contract_template_id] ??= RecContractTemplate::withTrashed()->find($row->rec_contract_template_id);
            $applicant = $bewerberCache[$row->rec_applicant_id] ??= RecApplicant::find($row->rec_applicant_id);
            if ($template === null || $applicant === null) {
                $counts['ohne_anstellung']++;
                $out('warn', sprintf('Vertrag #%d: Vorlage oder Bewerber fehlt — uebersprungen', $row->id));
                continue;
            }

            $z = $template->anstellungFuer($applicant);
            switch ($z->befund) {
                case AnstellungsZuordnung::ZUGEORDNET:
                    $counts['zugeordnet']++;
                    $out('line', sprintf('Vertrag #%d (%s, %s) → Anstellung #%d', $row->id, $template->code, $template->company, $z->anstellungId()));
                    if (!$dryRun) {
                        DB::table('rec_contracts')->where('id', $row->id)->whereNull('rec_employee_id')
                            ->update(['rec_employee_id' => $z->anstellungId(), 'updated_at' => now()]);
                    }
                    break;
                case AnstellungsZuordnung::MEHRDEUTIG:
                    $counts['mehrdeutig']++;
                    $out('warn', sprintf('Vertrag #%d (%s, %s): mehrere Anstellungen der Firma — Kandidaten %s — NULL gelassen', $row->id, $template->code, $template->company, implode(', ', $z->kandidatenIds)));
                    break;
                case AnstellungsZuordnung::FIRMA_FEHLT:
                    $counts['firma_fehlt']++;
                    $out('warn', sprintf('Vertrag #%d (%s, %s): Bewerber #%d hat nur Anstellungen anderer Firmen — NULL gelassen', $row->id, $template->code, $template->company, $applicant->id));
                    break;
                default:
                    $counts['ohne_anstellung']++;
                    break;
            }
        }

        return $counts;
    }
}
