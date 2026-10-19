<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\LohnMaErmittlung;
use Platform\Recruiting\Support\LohnMa;

/**
 * Vorflug und Neu-Export der MA-Loehne (Spec Lohn MA 2026-10-19 §9).
 *
 * Ohne --markieren: rechnet fuer alle aktiven MA-Anstellungen Grundlohn und
 * Zuschlag, zaehlt je Fall und schreibt eine CSV (personenbezogen — bleibt
 * auf dem Server). Mit --markieren: setzt zusaetzlich zas_changed_at auf
 * genau diese Zeilen (Query Builder), damit sie in die naechste
 * updates.csv gehen. Vorher Olaf Michel ankuendigen.
 *
 * Wann: vor dem Start (Vorflug), bei jeder Tarifstufe (EntgeltgruppeEins),
 * nach einer Aenderung von minimum_wage_hourly. Bewusst NICHT im Zeitplan.
 *
 * Aufruf:
 *   php artisan recruiting:lohn-ma-export
 *   php artisan recruiting:lohn-ma-export --markieren
 *   php artisan recruiting:lohn-ma-export --team=1 --datum=2027-04-01
 */
class LohnMaExport extends Command
{
    protected $signature = 'recruiting:lohn-ma-export
        {--markieren : zas_changed_at auf alle Zeilen des Laufs setzen (Neu-Export)}
        {--team= : Nur dieses Team}
        {--datum= : Stichtag fuer EG 1 (Y-m-d, Default heute)}
        {--pfad= : CSV-Pfad (Default storage/app/recruiting/lohn-ma-<datum>-<zeit>.csv)}';

    protected $description = 'MA-Loehne (EG 1 + Angleich) je Anstellung rechnen, zaehlen, als CSV ablegen; optional fuer ZAS markieren';

    public function handle(): int
    {
        $markieren = (bool) $this->option('markieren');
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;
        $datum = $this->option('datum') !== null ? (string) $this->option('datum') : null;
        $pfad = $this->option('pfad') !== null ? (string) $this->option('pfad')
            : storage_path('app/recruiting/lohn-ma-' . ($datum ?? now()->toDateString()) . '-' . now()->format('His') . '.csv');

        $r = $this->lauf($markieren, $team, $datum, $pfad, function (string $typ, string $text): void {
            match ($typ) {
                'warn' => $this->warn($text),
                default => $this->line($text),
            };
        });

        $this->newLine();
        foreach ($r['zahlen'] as $fall => $n) {
            $this->line(sprintf('%-10s %5d', $fall, $n));
        }
        $this->info(sprintf('%d MA-Zeilen, davon Steuerklasse 6: %d — CSV: %s', $r['gesamt'], $r['sechs'], $r['csv']));
        $this->line(sprintf('Vergleich mit ZAS-Stand: gleich %d, abweichend %d, bei ZAS leer %d', $r['vergleich']['gleich'], $r['vergleich']['abweichend'], $r['vergleich']['zas_leer']));
        $this->info($markieren ? sprintf('MARKIERT: %d Zeilen gehen in die naechste updates.csv.', $r['markiert']) : 'Keine Marker gesetzt (ohne --markieren).');

        return self::SUCCESS;
    }

    /**
     * @param  callable(string,string):void $out
     * @return array{zahlen: array<string,int>, vergleich: array<string,int>, sechs:int, gesamt:int, markiert:int, csv:string}
     */
    public function lauf(bool $markieren, ?int $teamId, ?string $datum, string $csvPfad, callable $out): array
    {
        $tag = $datum ?? now()->toDateString();
        $e = new LohnMaErmittlung();
        $zahlen = [LohnMa::ANGLEICH => 0, LohnMa::ZAS => 0, LohnMa::UNBEKANNT => 0, LohnMa::MANUELL => 0, LohnMa::NUR_MA => 0, LohnMa::STEUERKLASSE_FEHLT => 0];
        $vergleich = ['gleich' => 0, 'abweichend' => 0, 'zas_leer' => 0];
        $sechs = 0;
        $ids = [];

        $dir = dirname($csvPfad);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fh = fopen($csvPfad, 'w');
        fputcsv($fh, ['employee_id', 'personnel_number', 'name', 'person_id', 'rg_employee_id', 'fall', 'grundlohn', 'zuschlag', 'steuerklasse', 'zas_grundlohn', 'zas_zuschlag', 'vergleich', 'begruendung']);

        $q = RecEmployee::query()->where('is_active', 1)->whereRaw('UPPER(TRIM(company)) = ?', ['MA'])->orderBy('id');
        if ($teamId !== null) {
            $q->where('team_id', $teamId);
        }
        foreach ($q->cursor() as $ma) {
            $rg = $e->rgSchwester($ma);
            $l = $e->ermittelnMitSchwester($ma, $rg, $tag);
            $zahlen[$l->fall]++;
            if (trim((string) $ma->tax_class) === '6') {
                $sechs++;
            }
            // Vergleich mit dem ZAS-Stand DIESER MA-Zeile (Inbound seit 19.10.2026):
            // nur eine Zahl fuer die Ankuendigung, keine Abarbeitungsliste.
            $zas = DB::table('rec_employee_hr_data')->where('rec_employee_id', (int) $ma->id)->first(['zas_grundlohn', 'zas_zuschlag']);
            $zasGrund = $zas?->zas_grundlohn !== null ? (float) $zas->zas_grundlohn : null;
            $zasZu = $zas?->zas_zuschlag !== null ? (float) $zas->zas_zuschlag : null;
            if ($zasGrund === null || $zasGrund <= 0) {
                $v = 'zas_leer';
            } elseif (abs($zasGrund - $l->grundlohn) < 0.005 && $l->zuschlag !== null && $zasZu !== null && abs($zasZu - $l->zuschlag) < 0.005) {
                $v = 'gleich';
            } else {
                $v = 'abweichend';
            }
            $vergleich[$v]++;
            $ids[] = (int) $ma->id;
            fputcsv($fh, [
                $ma->id, (string) $ma->personnel_number, trim($ma->first_name . ' ' . $ma->last_name),
                $ma->rec_person_id, $rg?->id, $l->fall, $l->grundlohnText(), $l->zuschlagText() ?? '',
                (string) $ma->tax_class,
                $zasGrund === null ? '' : LohnMa::zahl($zasGrund), $zasZu === null ? '' : LohnMa::zahl($zasZu), $v,
                $l->begruendung,
            ]);
        }
        fclose($fh);

        $markiert = 0;
        if ($markieren && $ids !== []) {
            $vorher = DB::table('rec_employees')->whereNotNull('zas_changed_at')->count();
            foreach (array_chunk($ids, 500) as $block) {
                $markiert += DB::table('rec_employees')->whereIn('id', $block)->update(['zas_changed_at' => now()]);
            }
            $out('line', sprintf('Marker vorher: %d, jetzt: %d', $vorher, DB::table('rec_employees')->whereNotNull('zas_changed_at')->count()));
        }

        return ['zahlen' => $zahlen, 'vergleich' => $vergleich, 'sechs' => $sechs, 'gesamt' => count($ids), 'markiert' => $markiert, 'csv' => $csvPfad];
    }
}
