<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeCreator;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeRemover;

/**
 * Legt einen Test-Mitarbeiter ueber den echten Pfad an (Bewerber → MA),
 * listet vorhandene Test-MA oder entfernt einen restlos wieder.
 *
 * handle() parst nur Optionen und gibt aus; die Logik liegt in
 * TestEmployeeCreator / TestEmployeeRemover.
 */
class CreateTestEmployee extends Command
{
    protected $signature = 'recruiting:test-ma
        {--stelle= : ID der Stelle (Pflicht beim Anlegen)}
        {--profil=eu : Profil des Test-MA (z. B. eu, non-eu, schueler)}
        {--name=Test Mitarbeiter : Name; ein einzelnes Wort wird zum Vornamen, Nachname ist dann „Mitarbeiter"}
        {--phone= : Handynummer des Test-MA (z. B. dein eigenes Handy)}
        {--set=* : Feldwert als feld=wert (mehrfach moeglich)}
        {--datei=* : Datei-Feld als feld=/lokaler/pfad (mehrfach moeglich)}
        {--leer= : Kommagetrennte Feldnamen, die leer bleiben sollen}
        {--owner= : User-ID des Besitzers des Bewerbers}
        {--remove= : ID des Test-MA, der restlos entfernt wird (eine Test-VA vorher mit recruiting:dispo-test-va --remove entfernen)}
        {--list : Alle Test-MA auflisten}';

    protected $description = 'Legt einen Test-Mitarbeiter an, listet sie auf oder entfernt sie wieder (nie an ZAS)';

    public function handle(): int
    {
        try {
            if ($this->option('list')) {
                return $this->listAll();
            }

            $removeId = trim((string) $this->option('remove'));
            if ($removeId !== '') {
                return $this->remove((int) $removeId);
            }

            $stelle = trim((string) $this->option('stelle'));
            if ($stelle === '') {
                $this->error('Bitte --stelle angeben (oder --list / --remove).');

                return self::FAILURE;
            }

            $phone = trim((string) $this->option('phone'));
            $owner = trim((string) $this->option('owner'));

            $result = app(TestEmployeeCreator::class)->create(
                (int) $stelle,
                (string) $this->option('profil'),
                (string) $this->option('name'),
                $phone !== '' ? $phone : null,
                self::parsePairs((array) $this->option('set')),
                self::parsePairs((array) $this->option('datei')),
                self::parseList($this->option('leer')),
                $owner !== '' ? (int) $owner : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test-MA angelegt.');
        $this->table(['Feld', 'Wert'], [
            ['Bewerber-ID', (string) $result->applicantId],
            ['MA-ID', (string) $result->employeeId],
            ['Kontakt-ID', (string) $result->contactId],
            ['Portal-Link', $result->portalUrl ?: '—'],
            ['Befuellt', $result->filled === [] ? '—' : implode(', ', $result->filled)],
            ['Leer', $result->empty === [] ? '—' : implode(', ', $result->empty)],
        ]);
        $this->line('Aufraeumen: recruiting:test-ma --remove=' . $result->employeeId);

        return self::SUCCESS;
    }

    private function listAll(): int
    {
        $rows = RecEmployee::query()->where('is_test', true)->orderBy('id')->get()
            ->map(fn ($e) => [
                $e->id,
                trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')),
                (string) ($e->phone ?: '—'),
                (string) ($e->created_at ?? '—'),
            ])->all();

        if ($rows === []) {
            $this->info('Keine Test-MA vorhanden.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Telefon', 'angelegt'], $rows);

        return self::SUCCESS;
    }

    private function remove(int $employeeId): int
    {
        $counters = app(TestEmployeeRemover::class)->remove($employeeId);

        if ($counters === []) {
            $this->info('Nichts zu entfernen.');

            return self::SUCCESS;
        }

        $this->info('Test-MA #' . $employeeId . ' entfernt.');
        $this->table(['Tabelle', 'Anzahl'], array_map(
            fn ($k, $v) => [$k, (string) $v],
            array_keys($counters),
            array_values($counters)
        ));

        if ((int) ($counters['comms_threads_verbleibend'] ?? 0) > 0) {
            $this->warn('Hinweis: Chat-Threads (Kommunikation) bleiben bestehen und wurden nicht geloescht.');
        }
        if ((int) ($counters['crm_contacts_behalten'] ?? 0) > 0) {
            $this->warn('Hinweis: Der CRM-Kontakt wurde behalten, weil andere Datensaetze darauf verweisen.');
        }
        if ((int) ($counters['context_files_fehlgeschlagen'] ?? 0) > 0) {
            $this->warn('Achtung: Einige Dateien konnten nicht geloescht werden — bitte manuell pruefen.');
        }

        return self::SUCCESS;
    }

    /**
     * ["a=b", "c=d"] → ['a' => 'b', 'c' => 'd']; Eintrag ohne "=" wirft.
     *
     * @param  array<int, string>  $raw
     * @return array<string, string>
     */
    public static function parsePairs(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $entry = (string) $entry;
            $pos = strpos($entry, '=');
            if ($pos === false) {
                throw new \InvalidArgumentException("Ungueltiger Eintrag „{$entry}\" — erwartet feld=wert.");
            }
            $out[trim(substr($entry, 0, $pos))] = substr($entry, $pos + 1);
        }

        return $out;
    }

    /** @return list<string> */
    public static function parseList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($v) => $v !== ''
        ));
    }
}
