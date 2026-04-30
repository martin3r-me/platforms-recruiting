<?php

namespace Platform\Recruiting\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmAddressType;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Importiert "Altbestand"-Bewerber aus dem CSV-Export des bisherigen
 * Dispo-Tools. Anlässlich des Cutovers — diese Personen waren schon vor
 * Recruiting-Modul-Einführung im Unternehmen, brauchen aber jetzt einen
 * frischen Vertrag (AV + IFSG) und/oder müssen in eine Schulung gebucht
 * werden können.
 *
 * Scope-bewusst minimal:
 *   - Nur die für AV+IFSG nötigen Personenstammdaten
 *     (Name, Geburtsdatum, Geburtsort, Adresse).
 *   - Keine Phase-Automation (auto_pilot=false), kein Posting,
 *     keine Schulungs-Buchung — alles manuell durch HR/SL.
 *   - Flag `import_source='csv_legacy'` für späteren Export-Filter
 *     (`WHERE import_source IS NULL`).
 *
 * Dedup-Strategie (Variante A):
 *   - Match per first_name + last_name + birth_date im Team
 *   - CrmContact existiert + irgendein Applicant existiert → skip
 *     (sowohl Re-Import als auch Kollision mit Mail-Flow-Bewerber)
 *   - CrmContact existiert + kein Applicant → Contact wiederverwenden,
 *     neuen Applicant mit import_source anlegen
 *   - Nichts existiert → Contact neu + Applicant neu
 *
 * Aufruf:
 *   php artisan recruiting:import-csv /pfad/zur/datei.csv --team-id=3 --dry-run
 *   php artisan recruiting:import-csv /pfad/zur/datei.csv --team-id=3
 */
class ImportApplicantsCsv extends Command
{
    protected $signature = 'recruiting:import-csv
        {file : Pfad zur CSV-Datei}
        {--team-id= : Team-ID (Pflicht, da Modul team-scoped)}
        {--dry-run : Zeigt nur was passieren würde, ohne zu schreiben}
        {--limit=0 : Maximale Anzahl Datensätze (0 = alle)}';

    protected $description = 'Importiert Altbestand-Bewerber aus CSV. Setzt import_source=csv_legacy. Kein Posting, kein AutoPilot.';

    private const IMPORT_SOURCE = 'csv_legacy';

    /**
     * Mapping: CSV-Header → interner Feld-Key.
     * "Vorname" gewinnt vor evtl. anderen "Vorname"-Spalten weiter rechts.
     */
    private const FIELD_MAP = [
        'Vorname'       => 'first_name',
        'Nachname'      => 'last_name',
        'Geburtsdatum'  => 'birth_date',
        'Geburtsort'    => 'birth_place',
        'Straße, Nr.'   => 'street',  // Header-Text aus Beispiel-CSV
        'Straße'        => 'street',  // falls Header sauberer benannt ist
        'HNr'           => 'house_number',
        'Postleitzahl'  => 'postal_code',
        'Wohnort'       => 'city',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        $teamId = (int) $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        if (!is_file($file) || !is_readable($file)) {
            $this->error("Datei nicht gefunden oder nicht lesbar: {$file}");
            return self::FAILURE;
        }
        if ($teamId <= 0) {
            $this->error('--team-id ist Pflicht.');
            return self::FAILURE;
        }

        $team = Team::find($teamId);
        if (!$team) {
            $this->error("Team #{$teamId} nicht gefunden.");
            return self::FAILURE;
        }

        $admin = $this->findTeamAdmin($team);
        $createdByUserId = $admin?->id;

        $addressTypeId = CrmAddressType::where('code', 'PRIVATE')->value('id');
        if (!$addressTypeId) {
            $this->error("Kein CrmAddressType mit code='PRIVATE' im Team — Adresse kann nicht angelegt werden. Erst Address-Types seeden.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — es werden keine Schreibvorgänge ausgeführt.');
        }

        $rows = $this->readCsv($file);
        if ($rows === null) {
            return self::FAILURE;
        }

        $stats = [
            'parsed'           => 0,
            'imported'         => 0,
            'skipped_dup'      => 0,
            'skipped_existing' => 0,
            'skipped_incompl'  => 0,
            'errors'           => 0,
        ];

        $seenInRun = [];

        foreach ($rows as $rowIdx => $row) {
            if ($limit > 0 && $stats['imported'] >= $limit) {
                break;
            }

            $stats['parsed']++;

            $first = $this->clean($row['first_name'] ?? '');
            $last = $this->clean($row['last_name'] ?? '');
            $postal = $this->clean($row['postal_code'] ?? '');

            // Header-/Marker-/Leerzeilen erkennen
            if ($first === '' || $last === '' || $postal === '') {
                $stats['skipped_incompl']++;
                continue;
            }

            $birthDate = $this->parseDate($row['birth_date'] ?? null);
            $birthPlace = $this->clean($row['birth_place'] ?? '');
            $street = $this->clean($row['street'] ?? '');
            $houseNr = $this->clean($row['house_number'] ?? '');
            $city = $this->clean($row['city'] ?? '');

            // Within-Run-Dedup: gleiche Person in derselben CSV doppelt → skip
            $dedupKey = mb_strtolower($first . '|' . $last . '|' . ($birthDate ?: ''));
            if (isset($seenInRun[$dedupKey])) {
                $stats['skipped_dup']++;
                continue;
            }
            $seenInRun[$dedupKey] = true;

            // Cross-Run-Dedup: Contact + Applicant existiert schon → skip
            $existingContact = $this->findContact($first, $last, $birthDate, $teamId);

            if ($existingContact) {
                $hasApplicant = RecApplicant::where('team_id', $teamId)
                    ->whereHas('crmContactLinks', fn ($q) => $q->where('contact_id', $existingContact->id))
                    ->exists();

                if ($hasApplicant) {
                    $this->line(sprintf(
                        ' #%-3d %s %s : skip (existiert schon als Bewerber)',
                        $rowIdx + 2, // +1 header +1 1-based
                        str_pad($first, 15),
                        str_pad($last, 20)
                    ));
                    $stats['skipped_existing']++;
                    continue;
                }
            }

            $this->line(sprintf(
                ' #%-3d %s %s %s%s%s%s%s',
                $rowIdx + 2,
                str_pad($first, 15),
                str_pad($last, 20),
                $birthDate ? "*{$birthDate} " : '',
                $birthPlace ? "[{$birthPlace}] " : '',
                $street ? "{$street} " : '',
                $houseNr ? "{$houseNr}, " : '',
                ($postal && $city) ? "{$postal} {$city}" : ''
            ));

            if ($dryRun) {
                $stats['imported']++;
                continue;
            }

            try {
                DB::transaction(function () use (
                    $existingContact, $first, $last, $birthDate, $birthPlace,
                    $street, $houseNr, $postal, $city,
                    $teamId, $createdByUserId, $addressTypeId
                ) {
                    $contact = $existingContact ?? CrmContact::create([
                        'first_name'         => $first,
                        'last_name'          => $last,
                        'birth_date'         => $birthDate,
                        'team_id'            => $teamId,
                        'created_by_user_id' => $createdByUserId,
                        'is_active'          => true,
                    ]);

                    // Adresse: nur anlegen wenn keine primary existiert und
                    // wir mind. Straße ODER PLZ haben
                    $hasPrimary = $contact->postalAddresses()->where('is_primary', true)->exists();
                    if (!$hasPrimary && ($street !== '' || $postal !== '')) {
                        $contact->postalAddresses()->create([
                            'street'          => $street,
                            'house_number'    => $houseNr,
                            'postal_code'     => $postal,
                            'city'            => $city,
                            'address_type_id' => $addressTypeId,
                            'is_primary'      => true,
                            'is_active'       => true,
                        ]);
                    }

                    $applicant = RecApplicant::create([
                        'applied_at'              => now()->toDateString(),
                        'progress'                => 100,
                        'team_id'                 => $teamId,
                        'created_by_user_id'      => $createdByUserId,
                        'is_active'               => true,
                        'auto_pilot'              => false,
                        'auto_pilot_completed_at' => now(),
                        'import_source'           => self::IMPORT_SOURCE,
                    ]);

                    $applicant->crmContactLinks()->create([
                        'contact_id'         => $contact->id,
                        'team_id'            => $teamId,
                        'created_by_user_id' => $createdByUserId,
                    ]);

                    if ($birthPlace !== '') {
                        $applicant->setExtraField('geburtsort', $birthPlace);
                    }
                });

                $stats['imported']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("     ✗ Fehler in Zeile {$rowIdx}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Parsed:                    {$stats['parsed']}");
        $this->info("Importiert:                {$stats['imported']}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Skipped (Dup im Run):      {$stats['skipped_dup']}");
        $this->info("Skipped (existiert schon): {$stats['skipped_existing']}");
        $this->info("Skipped (unvollständig):   {$stats['skipped_incompl']}");
        if ($stats['errors'] > 0) {
            $this->warn("Fehler:                    {$stats['errors']}");
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /**
     * Liest CSV in ein Array von Assoc-Arrays {feldKey => wert}.
     * Behandelt Encoding (Windows-1252 → UTF-8) und mehrzeilige Header.
     */
    private function readCsv(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->error('Konnte Datei nicht lesen.');
            return null;
        }

        $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true) ?: 'Windows-1252';
        if ($encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
            $this->line("Encoding erkannt: {$encoding} → UTF-8 konvertiert.");
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);

        $tmp = tmpfile();
        if (!$tmp) {
            $this->error('Konnte temporäre Datei nicht öffnen.');
            return null;
        }
        fwrite($tmp, (string) $raw);
        rewind($tmp);

        $header = fgetcsv($tmp, 0, ';');
        if (!$header) {
            fclose($tmp);
            $this->error('Header-Zeile konnte nicht gelesen werden.');
            return null;
        }

        // Header-Index → interner Key. Erste Spalte mit passendem Header-Text gewinnt.
        $columnMap = [];
        foreach ($header as $colIdx => $colName) {
            $name = trim((string) $colName);
            if ($name === '') {
                continue;
            }
            if (isset(self::FIELD_MAP[$name]) && !isset($columnMap[self::FIELD_MAP[$name]])) {
                $columnMap[self::FIELD_MAP[$name]] = $colIdx;
            }
        }

        $missing = array_diff(['first_name', 'last_name', 'postal_code'], array_keys($columnMap));
        if (!empty($missing)) {
            $this->error('Pflicht-Spalten in CSV-Header nicht gefunden: ' . implode(', ', $missing));
            $this->line('Erkannte Spalten: ' . implode(', ', array_keys($columnMap)));
            fclose($tmp);
            return null;
        }

        $rows = [];
        while (($cells = fgetcsv($tmp, 0, ';')) !== false) {
            $row = [];
            foreach ($columnMap as $key => $idx) {
                $row[$key] = $cells[$idx] ?? null;
            }
            $rows[] = $row;
        }
        fclose($tmp);

        return $rows;
    }

    /**
     * Versucht Datums-Parse: erst d.m.Y, dann Carbon::parse als Fallback.
     * Liefert Y-m-d-string oder null wenn nicht parsebar/leer.
     */
    private function parseDate($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d.m.Y', $value)->format('Y-m-d');
        } catch (\Throwable) {
            // fallback
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(mixed $value): string
    {
        if ($value === null) return '';
        return trim((string) $value);
    }

    private function findContact(string $first, string $last, ?string $birthDate, int $teamId): ?CrmContact
    {
        $query = CrmContact::where('team_id', $teamId)
            ->where('first_name', $first)
            ->where('last_name', $last);

        if ($birthDate) {
            $query->whereDate('birth_date', $birthDate);
        } else {
            $query->whereNull('birth_date');
        }

        return $query->first();
    }

    private function findTeamAdmin(?Team $team): ?User
    {
        if (!$team) return null;

        return $team->users()->wherePivot('role', 'owner')->orderBy('id')->first()
            ?? $team->users()->wherePivot('role', 'admin')->orderBy('id')->first()
            ?? $team->users()->orderBy('id')->first();
    }
}
