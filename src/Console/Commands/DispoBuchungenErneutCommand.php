<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;

/**
 * Gibt an ZAS uebergebene Zusagen fuer einen erneuten Abruf frei (Spec 2026-10-30 §3,
 * Review F3): der Abruf markiert VOR dem Senden — reisst die Verbindung oder
 * scheitert Olafs Import, waeren die Zeilen sonst fuer immer weg.
 *
 * NUR mit Olafs Bestaetigung einsetzen, dass die Datei bei ihm nicht importiert
 * wurde. „Nicht angekommen“ heisst nicht „nicht importiert“: ZAS kann die
 * Buchung laengst haben (noch kein Webexport seitdem) oder sie steht dort
 * „zum Bearbeiten markiert“ — ein erneutes Senden waere dann eine Dublette.
 * Darum: IDs aus der Logzeile des betroffenen Abrufs, `--seit` nur nach `--dry-run`.
 *
 * Nicht angefasst: Zusagen mit Haken von Hand (`zas_entered_by_user_id`, die
 * stehen in ZAS) und schon angekommene (`zas_assignment_id`). Schwestern
 * derselben Buchung (gleicher Schluessel, gleiche Uebergabezeit) werden immer
 * mit freigegeben — sonst bliebe der Schluessel belegt und die Zeile wuerde
 * beim naechsten Abruf nur „abgedeckt“ statt geliefert.
 *
 * @see self::freigeben() Reine Logik, direkt aus dem Test aufrufbar.
 */
class DispoBuchungenErneutCommand extends Command
{
    protected $signature = 'recruiting:dispo-buchungen-erneut {--id=* : Zusage-Zeilen (rec_dispo_job_offer_shifts.id, aus der Logzeile des Abrufs)} {--seit= : alle Uebergaben ab diesem Zeitpunkt (Y-m-d H:i) — nur nach --dry-run} {--dry-run : nur zeigen}';

    protected $description = 'Gibt an ZAS uebergebene Zusagen fuer einen erneuten Abruf frei (nur wenn ZAS die Datei nachweislich nicht importiert hat)';

    /**
     * @param list<int> $ids
     * @return array{rows: list<array{id:int, uebergeben:string, schwester:bool}>, uebersprungen: list<array{id:int, grund:string}>, freigegeben:int}
     */
    public static function freigeben(array $ids, ?string $seit, bool $dryRun): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $seit = trim((string) $seit);
        if ($ids === [] && $seit === '') {
            return ['rows' => [], 'uebersprungen' => [], 'freigegeben' => 0];
        }
        $genannt = RecDispoJobOfferShift::query()
            ->with(['shift:id,datum,dispoposten_id', 'offer:id,rec_employee_id'])
            ->whereNotNull('zas_exported_at')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($seit !== '', fn ($q) => $q->where('zas_exported_at', '>=', \Carbon\Carbon::parse($seit)))
            ->orderBy('id')
            ->get();

        $uebersprungen = [];
        $treffer = [];
        foreach ($genannt as $z) {
            if ($z->zas_assignment_id !== null) {
                $uebersprungen[] = ['id' => (int) $z->id, 'grund' => 'in ZAS angekommen'];
            } elseif ($z->zas_entered_by_user_id !== null) {
                $uebersprungen[] = ['id' => (int) $z->id, 'grund' => 'Haken von Hand — steht in ZAS'];
            } else {
                $treffer[(int) $z->id] = $z;
            }
        }

        // Schwestern derselben Buchung (gleicher Schluessel, gleiche Uebergabezeit) mitnehmen.
        $schwestern = [];
        if ($treffer !== []) {
            $schluessel = [];
            foreach ($treffer as $z) {
                if ($z->shift !== null && $z->offer !== null) {
                    $schluessel[ZasDispoBookingExport::schluessel((int) $z->offer->rec_employee_id, trim((string) $z->shift->dispoposten_id), $z->shift->datum->format('Y-m-d')) . '@' . $z->zas_exported_at->format('Y-m-d H:i:s')] = true;
                }
            }
            $kandidaten = RecDispoJobOfferShift::query()
                ->with(['shift:id,datum,dispoposten_id', 'offer:id,rec_employee_id'])
                ->whereNotNull('zas_exported_at')->whereNull('zas_assignment_id')->whereNull('zas_entered_by_user_id')
                ->whereNotIn('id', array_keys($treffer))
                ->whereIn('zas_exported_at', array_values(array_unique(array_map(fn ($z) => $z->zas_exported_at->format('Y-m-d H:i:s'), $treffer))))
                ->get();
            foreach ($kandidaten as $k) {
                if ($k->shift === null || $k->offer === null) {
                    continue;
                }
                $key = ZasDispoBookingExport::schluessel((int) $k->offer->rec_employee_id, trim((string) $k->shift->dispoposten_id), $k->shift->datum->format('Y-m-d')) . '@' . $k->zas_exported_at->format('Y-m-d H:i:s');
                if (isset($schluessel[$key])) {
                    $schwestern[(int) $k->id] = $k;
                }
            }
        }

        $rows = [];
        foreach ($treffer as $z) {
            $rows[] = ['id' => (int) $z->id, 'uebergeben' => $z->zas_exported_at->format('Y-m-d H:i:s'), 'schwester' => false];
        }
        foreach ($schwestern as $z) {
            $rows[] = ['id' => (int) $z->id, 'uebergeben' => $z->zas_exported_at->format('Y-m-d H:i:s'), 'schwester' => true];
        }
        usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
        if ($dryRun || $rows === []) {
            return ['rows' => $rows, 'uebersprungen' => $uebersprungen, 'freigegeben' => 0];
        }
        $n = RecDispoJobOfferShift::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->whereNull('zas_assignment_id')->whereNull('zas_entered_by_user_id')
            ->update(['zas_exported_at' => null, 'zas_entered_at' => null]);

        return ['rows' => $rows, 'uebersprungen' => $uebersprungen, 'freigegeben' => (int) $n];
    }

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->option('id'));
        $seit = $this->option('seit') !== null ? (string) $this->option('seit') : null;
        if ($ids === [] && trim((string) $seit) === '') {
            $this->error('Bitte --id=... (aus der Logzeile des Abrufs) und/oder --seit="Y-m-d H:i" angeben.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $ergebnis = self::freigeben($ids, $seit, $dryRun);
        foreach ($ergebnis['uebersprungen'] as $u) {
            $this->line(sprintf('#%d bleibt: %s', $u['id'], $u['grund']));
        }
        foreach ($ergebnis['rows'] as $r) {
            $this->line(sprintf('#%d uebergeben %s%s', $r['id'], $r['uebergeben'], $r['schwester'] ? ' (Schwester, mit freigegeben)' : ''));
        }
        $this->info($dryRun
            ? count($ergebnis['rows']) . ' Zeile(n) wuerden freigegeben.'
            : $ergebnis['freigegeben'] . ' Zeile(n) freigegeben — der naechste Abruf liefert sie erneut. Nur sinnvoll, wenn ZAS die Datei nicht importiert hat!');

        return self::SUCCESS;
    }
}
