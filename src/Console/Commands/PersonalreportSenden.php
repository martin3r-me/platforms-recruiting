<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\PostmarkEmailService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikDaten;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\Personalreport;

/**
 * Täglicher Personalreport (Mo–Fr 08:00 im Zeitplan). Schickt die Übersicht je Kostenstelle an
 * die Empfänger aus Statistik → Mitarbeiter → Einstellungen.
 *
 * No-op, solange der Report dort nicht eingeschaltet ist oder keine Empfänger eingetragen sind.
 * Höchstens ein Versand pro Tag (Merker in den Settings) — ein zweiter Lauf schickt nichts,
 * außer mit --force. --dry-run zeigt den Inhalt, ohne zu senden; --an=adresse schickt nur an
 * diese Adresse (Probeversand, setzt den Tagesmerker nicht).
 */
class PersonalreportSenden extends Command
{
    protected $signature = 'recruiting:personalreport {--dry-run} {--force} {--an= : Probeversand nur an diese Adresse}';

    protected $description = 'Täglicher Personalreport (Soll/Aktiv/Produktiv je Kostenstelle) per E-Mail';

    public function handle(MitarbeiterStatistikDaten $daten): int
    {
        $team = (int) config('recruiting.zas.inbound_team_id');
        if ($team === 0) {
            $this->warn('Kein Team-Anker (recruiting.zas.inbound_team_id) – nichts zu tun.');

            return self::SUCCESS;
        }

        $settings = RecApplicantSettings::query()->where('team_id', $team)->first();
        $probe = trim((string) $this->option('an'));
        $empfaenger = $probe !== '' ? [$probe] : array_values(array_filter(array_map(
            fn ($m) => trim((string) $m),
            (array) ($settings?->getSetting(Personalreport::SETTING_EMPFAENGER) ?? []),
        )));
        $heute = now()->toDateString();

        if ($probe === '' && !$this->option('dry-run')) {
            if (!(bool) ($settings?->getSetting(Personalreport::SETTING_AKTIV) ?? false)) {
                $this->info('Personalreport ist ausgeschaltet – nichts gesendet.');

                return self::SUCCESS;
            }
            if (!$this->option('force') && ($settings?->getSetting(Personalreport::SETTING_LETZTER_VERSAND) ?? null) === $heute) {
                $this->info('Heute schon gesendet – nichts gesendet.');

                return self::SUCCESS;
            }
        }
        if ($empfaenger === []) {
            $this->warn('Keine Empfänger eingetragen – nichts gesendet.');

            return self::SUCCESS;
        }

        // Report = RG (Markus 19.11.2026: „Es dürfen nur aktive Mitarbeiter der RG aufgeführt werden“).
        $auswertung = $daten->auswerten($team, $heute, $heute, $heute, \Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner::GESELLSCHAFT_RG);
        $link = null;
        try {
            $link = route('recruiting.statistics.employees');
        } catch (\Throwable) {
        }
        $inhalt = Personalreport::inhalt($auswertung, $heute, $link);

        if ($this->option('dry-run')) {
            $this->line('Betreff: ' . $inhalt['betreff']);
            $this->line('An: ' . implode(', ', $empfaenger));
            $this->line($inhalt['text']);

            return self::SUCCESS;
        }

        $kanal = CommsChannel::query()->where('team_id', $team)->where('type', 'email')->where('is_active', true)->first();
        if ($kanal === null) {
            $this->error('Kein aktiver E-Mail-Kanal im Team – nichts gesendet.');

            return self::FAILURE;
        }

        $fehler = 0;
        $service = app(PostmarkEmailService::class);
        foreach ($empfaenger as $an) {
            try {
                $service->send(channel: $kanal, to: $an, subject: $inhalt['betreff'], htmlBody: $inhalt['html'], textBody: $inhalt['text'], opt: ['tag' => 'personalreport']);
                $this->line('gesendet an ' . $an);
            } catch (\Throwable $e) {
                $fehler++;
                report($e);
                $this->error('Fehler bei ' . $an . ': ' . $e->getMessage());
            }
        }

        if ($probe === '' && $fehler < count($empfaenger)) {
            $settings = RecApplicantSettings::getOrCreateForTeam($team);
            $settings->setSetting(Personalreport::SETTING_LETZTER_VERSAND, $heute);
            $settings->save();
        }

        return $fehler === 0 ? self::SUCCESS : self::FAILURE;
    }
}
