<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoJobOfferShift;

/**
 * Gibt an ZAS uebergebene Zusagen fuer einen erneuten Abruf frei (Spec 2026-10-30,
 * Review F3): der Abruf markiert VOR dem Senden — reisst die Verbindung oder
 * scheitert Olafs Import, waeren die Zeilen sonst fuer immer weg.
 *
 * Geleert werden `zas_exported_at` und der automatisch gesetzte Haken
 * (`zas_entered_at`, nur wenn `zas_entered_by_user_id` leer ist). Ein Haken von
 * Hand bleibt stehen. Schon angekommene Zusagen (`zas_assignment_id`) werden
 * nie freigegeben — die stehen in ZAS.
 *
 * @see self::freigeben() Reine Logik, direkt aus dem Test aufrufbar.
 */
class DispoBuchungenErneutCommand extends Command
{
    protected $signature = 'recruiting:dispo-buchungen-erneut {--id=* : nur diese Zusage-Zeilen (rec_dispo_job_offer_shifts.id)} {--seit= : alle Uebergaben ab diesem Zeitpunkt (Y-m-d H:i)} {--dry-run : nur zeigen}';

    protected $description = 'Gibt an ZAS uebergebene Zusagen fuer einen erneuten Abruf frei (nach Abbruch oder gescheitertem Import)';

    /**
     * @param list<int> $ids
     * @return array{rows: list<array{id:int, uebergeben:string, haken_von_hand:bool}>, freigegeben:int}
     */
    public static function freigeben(array $ids, ?string $seit, bool $dryRun): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $seit = trim((string) $seit);
        if ($ids === [] && $seit === '') {
            return ['rows' => [], 'freigegeben' => 0];
        }
        $q = RecDispoJobOfferShift::query()
            ->whereNotNull('zas_exported_at')
            ->whereNull('zas_assignment_id')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($seit !== '', fn ($q) => $q->where('zas_exported_at', '>=', \Carbon\Carbon::parse($seit)))
            ->orderBy('id');
        $rows = $q->get()->map(fn (RecDispoJobOfferShift $z) => [
            'id'             => (int) $z->id,
            'uebergeben'     => $z->zas_exported_at?->format('Y-m-d H:i:s') ?? '',
            'haken_von_hand' => $z->zas_entered_by_user_id !== null,
        ])->values()->all();
        if ($dryRun || $rows === []) {
            return ['rows' => $rows, 'freigegeben' => 0];
        }
        $treffer = array_column($rows, 'id');
        RecDispoJobOfferShift::query()->whereIn('id', $treffer)->whereNull('zas_entered_by_user_id')->update(['zas_entered_at' => null]);
        $n = RecDispoJobOfferShift::query()->whereIn('id', $treffer)->update(['zas_exported_at' => null]);

        return ['rows' => $rows, 'freigegeben' => (int) $n];
    }

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->option('id'));
        $seit = $this->option('seit') !== null ? (string) $this->option('seit') : null;
        if ($ids === [] && trim((string) $seit) === '') {
            $this->error('Bitte --id=... und/oder --seit="Y-m-d H:i" angeben.');

            return self::FAILURE;
        }
        $ergebnis = self::freigeben($ids, $seit, (bool) $this->option('dry-run'));
        foreach ($ergebnis['rows'] as $r) {
            $this->line(sprintf('#%d uebergeben %s%s', $r['id'], $r['uebergeben'], $r['haken_von_hand'] ? ' (Haken von Hand bleibt)' : ''));
        }
        $this->info($this->option('dry-run')
            ? count($ergebnis['rows']) . ' Zeile(n) wuerden freigegeben.'
            : $ergebnis['freigegeben'] . ' Zeile(n) freigegeben — der naechste Abruf liefert sie erneut.');

        return self::SUCCESS;
    }
}
