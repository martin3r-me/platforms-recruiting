<?php

namespace Platform\Recruiting\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Platform\Recruiting\Support\MetaAbrechnung;

/**
 * WhatsApp-Kosten eines Kalendermonats je Rufnummer/Kostenstelle inkl.
 * Aufschlag als CSV (Markus, Mail 18.11.2026). Rechnung: Support\MetaAbrechnung.
 *
 * Nur lesend: fragt Metas pricing_analytics je WABA ab, schreibt nichts in
 * die Datenbank. Erst ab dem 2. des Folgemonats laufen lassen — Meta bucht
 * den Monatsrest am 1. ab, die Auswertung ist vorher evtl. noch nicht fertig.
 *
 * Aufruf:
 *   php artisan recruiting:meta-abrechnung                 (Vormonat)
 *   php artisan recruiting:meta-abrechnung --monat=2026-10
 */
class MetaAbrechnungCommand extends Command
{
    protected $signature = 'recruiting:meta-abrechnung
        {--monat= : Abrechnungsmonat Y-m (Default: Vormonat)}
        {--team= : Dispo-Einstellungen nur dieses Teams fuer die Filial-Zuordnung}
        {--pfad= : CSV-Pfad (Default storage/app/recruiting/meta-abrechnung-<monat>.csv)}';

    protected $description = 'WhatsApp-Kosten (Meta) eines Monats je Rufnummer/Kostenstelle mit Aufschlag als CSV';

    public function handle(): int
    {
        $tz = 'Europe/Berlin';
        $monat = $this->option('monat') !== null ? (string) $this->option('monat') : Carbon::now($tz)->subMonthNoOverflow()->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $monat)) {
            $this->error('--monat bitte als Y-m, z. B. 2026-10');
            return self::FAILURE;
        }
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $monat . '-01 00:00:00', $tz);
        $ende = $start->copy()->addMonthNoOverflow();
        $zeitraum = $start->format('d.m.Y') . '–' . $ende->copy()->subDay()->format('d.m.Y');

        $prozent = (float) config('recruiting.meta_abrechnung.aufschlag_prozent', 30);
        $wabaIds = (array) config('recruiting.meta_abrechnung.waba_ids', []);
        $konten = DB::table('integrations_whatsapp_accounts')->whereIn('external_id', $wabaIds)->get(['external_id', 'title', 'phone_number', 'access_token']);
        $fehlend = array_diff($wabaIds, $konten->pluck('external_id')->all());
        if ($fehlend !== []) {
            $this->error('WhatsApp-Konto nicht gefunden: ' . implode(', ', $fehlend) . ' (recruiting.meta_abrechnung.waba_ids)');
            return self::FAILURE;
        }

        $version = 'v' . ltrim((string) config('integrations.oauth2.providers.meta.api_version', '21.0'), 'v');
        $felder = sprintf('currency,pricing_analytics.start(%d).end(%d).granularity(MONTHLY).dimensions(["PHONE","PRICING_TYPE"])', $start->timestamp, $ende->timestamp);

        $punkte = [];
        $waehrungen = [];
        foreach ($konten as $k) {
            $json = (array) Http::timeout(30)->get("https://graph.facebook.com/{$version}/{$k->external_id}", [
                'fields' => $felder,
                'access_token' => $k->access_token,
            ])->json();
            try {
                $punkte = array_merge($punkte, MetaAbrechnung::datenpunkte($json));
            } catch (\RuntimeException $e) {
                $this->error("{$k->title}: {$e->getMessage()}");
                return self::FAILURE;
            }
            $waehrungen[(string) ($json['currency'] ?? '?')] = true;
        }
        if (count($waehrungen) !== 1) {
            $this->error('Konten in unterschiedlicher Waehrung: ' . implode(', ', array_keys($waehrungen)));
            return self::FAILURE;
        }
        $waehrung = array_key_first($waehrungen);

        $zuordnung = $this->zuordnung($konten);
        $a = MetaAbrechnung::aufstellen($punkte, $zuordnung, $prozent);

        $pfad = $this->option('pfad') !== null ? (string) $this->option('pfad') : storage_path("app/recruiting/meta-abrechnung-{$monat}.csv");
        if (!is_dir(dirname($pfad))) {
            mkdir(dirname($pfad), 0775, true);
        }
        $fh = fopen($pfad, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute/Gedankenstrich richtig liest
        $csv = MetaAbrechnung::csvZeilen($a, $zeitraum, $waehrung, $prozent);
        foreach ($csv as $zeile) {
            fputcsv($fh, $zeile, ';');
        }
        fclose($fh);

        $this->table(array_shift($csv), $csv);
        $this->line(sprintf('Meta ungerundet: %s %s — zum Abgleich mit den Abbuchungen des Monats (+ Rest am 1. des Folgemonats).', number_format($a['gesamt']['meta_exakt'], 4, ',', '.'), $waehrung));
        foreach ($a['zeilen'] as $z) {
            if ($z['bezeichnung'] === 'nicht zugeordnet') {
                $this->warn("Rufnummer {$z['rufnummer']} hat keine Filiale in den Dispo-Einstellungen und steht nicht in recruiting.meta_abrechnung.nummern_ohne_filiale.");
            }
        }
        $this->info("CSV: {$pfad}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{kostenstelle:?int, bezeichnung:string, rufnummer:string}>
     */
    private function zuordnung(iterable $konten): array
    {
        $q = DB::table('rec_dispo_filiale_settings as s')
            ->join('comms_channels as c', 'c.id', '=', 's.comms_channel_id')
            ->whereNull('c.deleted_at')
            ->orderBy('s.filial_nr')
            ->select(['s.filial_nr', 'c.sender_identifier']);
        if ($this->option('team') !== null) {
            $q->where('s.team_id', (int) $this->option('team'));
        }

        return MetaAbrechnung::zuordnung(
            (array) config('recruiting.meta_abrechnung.nummern_ohne_filiale', []),
            collect($konten)->pluck('phone_number')->all(),
            $q->get()->map(fn ($r) => ['filial_nr' => (int) $r->filial_nr, 'rufnummer' => (string) $r->sender_identifier])->all(),
            fn (string $text) => $this->warn($text),
        );
    }
}
