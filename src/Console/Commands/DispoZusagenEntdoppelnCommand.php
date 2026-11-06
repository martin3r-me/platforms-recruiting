<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Einmalige Bereinigung (Markus 06.11., Anfrage 16): bis zum Fix galten zwei
 * Schichten mit gleichem Beginn und anderem Ende als eine, jede Zusage landete
 * auf beiden. Hier bleibt je Person und Tag EINE Zusage stehen:
 *  1. die Zeile, die schon in ZAS ist (uebergeben, Haken oder angekommen),
 *  2. sonst die Zeile auf der Schicht --behalten (die Schicht, die im Link stand),
 *  3. gibt es keine davon: Abbruch mit Hinweis — nichts raten.
 * Die anderen Zeilen verlieren ihre Zusage (accepted_at = null), bleiben als
 * Angebot bestehen. Ohne --ausfuehren nur Vorschau.
 */
class DispoZusagenEntdoppelnCommand extends Command
{
    protected $signature = 'recruiting:dispo-zusagen-entdoppeln {requestId} {--behalten=} {--ausfuehren}';
    protected $description = 'Doppelte Zusagen einer Anfrage (gleicher Beginn, anderes Ende) auf eine Schicht zurueckfuehren';

    public function handle(): int
    {
        $request = RecDispoJobRequest::query()->find((int) $this->argument('requestId'));
        if ($request === null) {
            $this->error('Anfrage nicht gefunden.');
            return self::FAILURE;
        }
        $behalten = $this->option('behalten') !== null ? (int) $this->option('behalten') : null;
        if ($behalten !== null && !$request->shifts()->whereKey($behalten)->exists()) {
            $this->error('--behalten ist keine Schicht dieser Anfrage.');
            return self::FAILURE;
        }
        $ausfuehren = (bool) $this->option('ausfuehren');

        $zeilen = RecDispoJobOfferShift::query()
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->whereNotNull('accepted_at')->whereNull('declined_at')
            ->with(['offer.employee:id,first_name,last_name', 'shift'])
            ->get();

        // Gruppe = Person + VA + Tag + Beginn + Taetigkeit (der alte Schluessel ohne Ende).
        $gruppen = [];
        foreach ($zeilen as $z) {
            if ($z->shift === null || $z->offer === null) {
                continue;
            }
            $k = $z->offer_id . '|' . $z->shift->rec_dispo_event_id . '|' . $z->shift->datum->format('Y-m-d') . '|' . trim((string) $z->shift->von) . '|' . mb_strtolower(trim((string) $z->shift->taetigkeit));
            $gruppen[$k][] = $z;
        }

        $entfernen = [];
        $offen = 0;
        foreach ($gruppen as $g) {
            if (count($g) < 2) {
                continue;
            }
            $inZas = array_values(array_filter($g, fn ($z) => $z->zas_assignment_id !== null || $z->zas_exported_at !== null || $z->zas_entered_at !== null));
            $bleibt = $inZas !== [] ? $inZas : array_values(array_filter($g, fn ($z) => $behalten !== null && (int) $z->shift_id === $behalten));
            $name = trim(($g[0]->offer->employee?->last_name ?? '') . ', ' . ($g[0]->offer->employee?->first_name ?? ''));
            if ($bleibt === []) {
                $offen++;
                $this->warn(sprintf('%s: %s — keine Zeile in ZAS und keine auf --behalten, bleibt unveraendert', $name,
                    implode(' / ', array_map(fn ($z) => self::zeit($z), $g))));
                continue;
            }
            $bleibtIds = array_map(fn ($z) => (int) $z->id, $bleibt);
            foreach ($g as $z) {
                if (!in_array((int) $z->id, $bleibtIds, true)) {
                    $entfernen[] = $z;
                    $this->line(sprintf('%s: Zusage auf %s entfernen, bleibt auf %s%s', $name, self::zeit($z),
                        implode(' + ', array_map(fn ($b) => self::zeit($b), $bleibt)), $inZas !== [] ? ' (in ZAS)' : ''));
                }
            }
        }

        $this->info(sprintf('%d Zusagen zu entfernen, %d Personen ohne Entscheidung.', count($entfernen), $offen));
        if (!$ausfuehren) {
            $this->line('Vorschau — mit --ausfuehren wird geschrieben.');
            return self::SUCCESS;
        }
        foreach ($entfernen as $z) {
            RecDispoJobOfferShift::query()->whereKey($z->id)->update(['accepted_at' => null, 'zas_entered_at' => null, 'zas_entered_by_user_id' => null]);
        }
        $this->info(sprintf('%d Zusagen entfernt.', count($entfernen)));

        return self::SUCCESS;
    }

    private static function zeit(RecDispoJobOfferShift $z): string
    {
        return '#' . $z->shift_id . ' ' . \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($z->shift->von, $z->shift->bis);
    }
}
