<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobTestdaten;
use Symfony\Component\Uid\UuidV7;

/**
 * Legt eine Test-Anfrage „Jobs anbieten“ zum Durchklicken an (Demo): Test-
 * Veranstaltung TEST-JOBS, drei Schichten, eine „gesendete“ Welle an ein paar
 * Mitarbeiter und einige Zusagen — es geht KEINE WhatsApp raus. Gibt die Links
 * fuer die Disponenten-Seite und die Mitarbeiter-Seiten aus.
 *
 * Auf Prod (APP_URL mitarbeiter.rheingedeck.de) nur mit --auch-auf-prod.
 * Aufraeumen: --aufraeumen entfernt alle Test-Anfragen und die Test-VA.
 */
class DispoJobsTestCommand extends Command
{
    protected $signature = 'recruiting:dispo-jobs-test
        {--mitarbeiter=* : IDs der Test-Mitarbeiter (Reihenfolge = wer zusagt). Leer = die 4 neuesten aktiven MA mit Portal-Link}
        {--filiale=200 : Filialnummer der Test-Veranstaltung}
        {--zusagen=3 : Wie viele der Mitarbeiter schon zugesagt haben}
        {--aufraeumen : Alle Test-Anfragen und die Test-Veranstaltung entfernen}
        {--auch-auf-prod : Ausdruecklich auch auf dem Produktivsystem ausfuehren}';

    protected $description = 'Test-Anfrage „Jobs anbieten“ anlegen (ohne WhatsApp) und die Links ausgeben';

    public function handle(): int
    {
        $basis = rtrim((string) config('app.url'), '/');
        if (str_contains($basis, 'mitarbeiter.rheingedeck.de') && !$this->option('auch-auf-prod')) {
            $this->error('Das ist das Produktivsystem. Nur mit --auch-auf-prod ausfuehren.');

            return self::FAILURE;
        }

        if ($this->option('aufraeumen')) {
            $r = DispoJobTestdaten::aufraeumen();
            $this->info(sprintf('Entfernt: %d Test-Anfrage(n)%s.', $r['anfragen'], $r['veranstaltung'] ? ' und die Test-Veranstaltung' : ''));

            return self::SUCCESS;
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
        if ($teamId <= 0) {
            $this->error('recruiting.zas.inbound_team_id ist nicht gesetzt.');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('mitarbeiter')), fn ($v) => $v > 0));
        $query = RecEmployee::query()->where('team_id', $teamId)->where('is_active', true)->whereNull('portal_locked_at');
        $mitarbeiter = $ids !== []
            ? $query->whereIn('id', $ids)->get()->sortBy(fn ($e) => array_search((int) $e->id, $ids, true))->values()
            : $query->orderByDesc('id')->limit(4)->get();
        if ($mitarbeiter->isEmpty()) {
            $this->error('Keine passenden aktiven Mitarbeiter gefunden (--mitarbeiter=ID angeben).');

            return self::FAILURE;
        }
        foreach ($mitarbeiter as $e) {
            if (empty($e->portal_token)) {
                $e->portal_token = (string) UuidV7::generate();
                $e->save();
            }
        }

        $event = DispoJobTestdaten::testVeranstaltung((int) $this->option('filiale'));
        $r = DispoJobTestdaten::anlegen($teamId, $event, $mitarbeiter->pluck('id')->map(fn ($v) => (int) $v)->all(), max(0, (int) $this->option('zusagen')));

        $this->info(sprintf('Test-Anfrage #%d angelegt: %d Schichten, %d Angebote, %d Zusagen. Es ging keine WhatsApp raus.', $r['request_id'], $r['schichten'], $r['angebote'], $r['zusagen']));
        $this->line('Disponent: ' . $basis . '/recruiting/dispo-jobs/' . $r['request_id']);
        $this->table(['Mitarbeiter', 'Seite für den Mitarbeiter'], $mitarbeiter->map(fn ($e) => [
            trim($e->first_name . ' ' . $e->last_name) . ' (#' . $e->id . ')',
            $basis . '/recruiting/jobs/' . $e->portal_token,
        ])->all());
        $this->line('Aufraeumen: php artisan recruiting:dispo-jobs-test --aufraeumen');

        return self::SUCCESS;
    }
}
