<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Einmaliger Bestands-Fix (Befund 01.09.): normalisiert rec_employees.phone
 * nach E.164. Sicher als Daten-Korrektur, weil der ZAS-Import bei BESTANDS-MA
 * das Telefon nie anfasst (nur Status/Umstellungsdatum + PNr/Firma in leere
 * Felder) — die Korrektur bleibt also stehen. Neuanlagen bringen ggf. wieder
 * Rohformate mit; dafuer normalisieren die Sendewege zusaetzlich zur Laufzeit
 * (DispoEmployeeGateway / Eskalations-Alarm).
 *
 * Unparsebare Nummern und Festnetz werden NICHT angefasst, sondern gelistet —
 * das ist die Datenpflege-Liste fuer den Kunden (Nummer besorgen/korrigieren).
 *
 * Aufruf:
 *   php artisan recruiting:normalize-employee-phones --dry-run
 *   php artisan recruiting:normalize-employee-phones
 */
class NormalizeEmployeePhonesCommand extends Command
{
    protected $signature = 'recruiting:normalize-employee-phones
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur MA dieses Teams (Default: alle)}';

    protected $description = 'Normalisiert die Telefonnummern aktiver Mitarbeiter nach E.164 (+49...)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $team   = $this->option('team') !== null ? (int) $this->option('team') : null;

        $counts = $this->normalize($dryRun, $team, function (string $type, string $text): void {
            match ($type) {
                'warn'  => $this->warn($text),
                'line'  => $this->line($text),
                default => $this->info($text),
            };
        });

        $mode = $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT';
        $this->newLine();
        $this->info(sprintf(
            '%s: %d korrigiert, %d bereits sauber, %d Festnetz (gelistet), %d unparsebar (gelistet) — von %d aktiven MA mit Nummer.',
            $mode, $counts['fixed'], $counts['ok'], $counts['fixed_line'], $counts['unparseable'], $counts['total']
        ));

        return self::SUCCESS;
    }

    /**
     * Kern ohne Artisan-Lebenszyklus (testbar, Muster ZasCrmContactBackfill::backfill).
     *
     * @param callable(string,string):void $emit
     * @return array{total:int, fixed:int, ok:int, fixed_line:int, unparseable:int}
     */
    public function normalize(bool $dryRun, ?int $team, callable $emit): array
    {
        $counts = ['total' => 0, 'fixed' => 0, 'ok' => 0, 'fixed_line' => 0, 'unparseable' => 0];

        RecEmployee::query()
            ->where('is_active', true)
            ->when($team !== null, fn ($q) => $q->where('team_id', $team))
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($dryRun, $emit, &$counts) {
                foreach ($employees as $e) {
                    $counts['total']++;
                    $raw  = (string) $e->phone;
                    $e164 = PhoneE164::normalize($raw);
                    $name = trim(($e->last_name ?? '') . ', ' . ($e->first_name ?? ''));
                    $pnr  = (string) ($e->personnel_number ?: '—');

                    if ($e164 === null) {
                        $counts['unparseable']++;
                        $emit('warn', "UNPARSEBAR  {$pnr}  {$name}  '{$raw}' — bitte beim Kunden klaeren");
                        continue;
                    }

                    if (PhoneE164::isFixedLine($e164)) {
                        $counts['fixed_line']++;
                        $emit('warn', "FESTNETZ    {$pnr}  {$name}  '{$raw}' — vermutlich kein WhatsApp, Mobilnummer besorgen");
                        continue;
                    }

                    if ($e164 === $raw) {
                        $counts['ok']++;
                        continue;
                    }

                    $counts['fixed']++;
                    $emit('line', ($dryRun ? 'WUERDE ' : '') . "FIX  {$pnr}  {$name}  '{$raw}' -> {$e164}");
                    if (!$dryRun) {
                        $e->phone = $e164;
                        $e->save();
                    }
                }
            });

        if (!$dryRun && $counts['fixed'] > 0) {
            Log::info('employee_phones_normalized', $counts);
        }

        return $counts;
    }
}
