<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmAddressType;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Pure-Logik des Altbestand-CSV-Imports — vom Console-Command sowie vom
 * Bewerber-Liste-Upload-Modal genutzt. Liefert ein strukturiertes Result
 * statt nach stdout zu schreiben, damit die UI dieselben Stats zeigen kann
 * wie das Konsolen-Output.
 */
class ImportApplicantsCsvService
{
    public const IMPORT_SOURCE = 'csv_legacy';

    /**
     * Mapping: CSV-Header → interner Feld-Key. Erste Match in der Header-
     * Zeile gewinnt — falls "Straße, Nr." UND "Straße" beide vorkommen
     * (passiert nicht in den realen Files, aber Schutz vor Drift).
     */
    private const FIELD_MAP = [
        'Vorname'       => 'first_name',
        'Nachname'      => 'last_name',
        'Geburtsdatum'  => 'birth_date',
        'Geburtsort'    => 'birth_place',
        'Straße, Nr.'   => 'street',
        'Straße'        => 'street',
        'HNr'           => 'house_number',
        'Postleitzahl'  => 'postal_code',
        'Wohnort'       => 'city',
        'Mobiltelefon'  => 'mobile_phone',
    ];

    /**
     * @return array{
     *   parsed: int,
     *   imported: int,
     *   skipped_dup: int,
     *   skipped_existing: int,
     *   skipped_incompl: int,
     *   details: array<int, array{action: string, row: int, name: string, note?: string}>,
     *   imported_applicant_ids: array<int, int>,
     *   errors: array<int, array{row: int, name: string, message: string}>,
     *   fatal: ?string
     * }
     *
     * details[].action ∈ {imported, skipped_existing, skipped_dup}
     * skipped_incompl wird absichtlich nicht in details gelogged — das sind
     * meist Header-/Marker-/Leerzeilen und würden nur Lärm produzieren.
     *
     * imported_applicant_ids enthält nur tatsächlich angelegte Bewerber
     * (also nicht im Dry-Run-Mode); wird vom Bewerber-Liste-Modal genutzt
     * um direkt nach dem Import in eine Schulung buchen zu können.
     */
    public function importFromFile(string $filepath, int $teamId, bool $dryRun = false, int $limit = 0): array
    {
        $result = [
            'parsed'                 => 0,
            'imported'               => 0,
            'skipped_dup'            => 0,
            'skipped_existing'       => 0,
            'skipped_incompl'        => 0,
            'details'                => [],
            'imported_applicant_ids' => [],
            'errors'                 => [],
            'fatal'                  => null,
        ];

        if (!is_file($filepath) || !is_readable($filepath)) {
            $result['fatal'] = 'Datei nicht gefunden oder nicht lesbar.';
            return $result;
        }

        $team = Team::find($teamId);
        if (!$team) {
            $result['fatal'] = "Team #{$teamId} nicht gefunden.";
            return $result;
        }

        $createdByUserId = $this->findTeamAdmin($team)?->id;

        $addressTypeId = CrmAddressType::where('code', 'PRIVATE')->value('id');
        if (!$addressTypeId) {
            $result['fatal'] = "Kein CrmAddressType mit code='PRIVATE' gefunden — Adresse kann nicht angelegt werden.";
            return $result;
        }

        $mobilePhoneTypeId = CrmPhoneType::where('code', 'MOBILE')->value('id');
        if (!$mobilePhoneTypeId) {
            $result['fatal'] = "Kein CrmPhoneType mit code='MOBILE' gefunden — Mobilnummer kann nicht angelegt werden.";
            return $result;
        }

        $rows = $this->readCsv($filepath, $result);
        if ($rows === null) {
            return $result;
        }

        $seenInRun = [];

        foreach ($rows as $rowIdx => $row) {
            if ($limit > 0 && $result['imported'] >= $limit) {
                break;
            }
            $result['parsed']++;

            $first  = $this->clean($row['first_name'] ?? '');
            $last   = $this->clean($row['last_name'] ?? '');
            $postal = $this->clean($row['postal_code'] ?? '');

            // Header-/Marker-/Leerzeile (mehrzeiliger Header, Trennzeile)
            if ($first === '' || $last === '' || $postal === '') {
                $result['skipped_incompl']++;
                continue;
            }

            $birthDate    = $this->parseDate($row['birth_date'] ?? null);
            $birthPlace   = $this->clean($row['birth_place'] ?? '');
            $street       = $this->clean($row['street'] ?? '');
            $houseNr      = $this->clean($row['house_number'] ?? '');
            $city         = $this->clean($row['city'] ?? '');
            $mobileRaw    = $this->clean($row['mobile_phone'] ?? '');
            $mobileE164   = $this->normalizePhoneE164($mobileRaw);

            $rowNo = $rowIdx + 2; // +1 header +1 1-based
            $displayName = trim("{$first} {$last}");

            // Within-Run-Dedup
            $dedupKey = mb_strtolower($first . '|' . $last . '|' . ($birthDate ?: ''));
            if (isset($seenInRun[$dedupKey])) {
                $result['skipped_dup']++;
                $result['details'][] = [
                    'action' => 'skipped_dup',
                    'row'    => $rowNo,
                    'name'   => $displayName,
                    'note'   => 'Gleiche Person bereits weiter oben in dieser CSV.',
                ];
                continue;
            }
            $seenInRun[$dedupKey] = true;

            // Cross-Run-Dedup: Contact + Applicant gibts schon → skip
            $existingContact = $this->findContact($first, $last, $birthDate, $teamId);

            if ($existingContact) {
                $existingApplicant = RecApplicant::where('team_id', $teamId)
                    ->whereHas('crmContactLinks', fn ($q) => $q->where('contact_id', $existingContact->id))
                    ->first();

                if ($existingApplicant) {
                    $note = "Match: Contact #{$existingContact->id}, bereits Bewerber #{$existingApplicant->id}";
                    if ($existingApplicant->import_source) {
                        $note .= ' (früherer Import)';
                    }
                    $result['skipped_existing']++;
                    $result['details'][] = [
                        'action' => 'skipped_existing',
                        'row'    => $rowNo,
                        'name'   => $displayName,
                        'note'   => $note,
                    ];
                    continue;
                }
            }

            if ($dryRun) {
                $result['imported']++;
                $result['details'][] = [
                    'action' => 'imported',
                    'row'    => $rowNo,
                    'name'   => $displayName,
                    'note'   => $existingContact
                        ? "Würde Contact #{$existingContact->id} wiederverwenden + neuen Bewerber anlegen"
                        : 'Würde Contact + Bewerber neu anlegen',
                ];
                continue;
            }

            try {
                $newApplicantId = DB::transaction(function () use (
                    $existingContact, $first, $last, $birthDate, $birthPlace,
                    $street, $houseNr, $postal, $city, $mobileRaw, $mobileE164,
                    $teamId, $createdByUserId, $addressTypeId, $mobilePhoneTypeId
                ) {
                    $contact = $existingContact ?? CrmContact::create([
                        'first_name'         => $first,
                        'last_name'          => $last,
                        'birth_date'         => $birthDate,
                        'team_id'            => $teamId,
                        'created_by_user_id' => $createdByUserId,
                        'is_active'          => true,
                    ]);

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

                    // Mobiltelefon: nur anlegen wenn normalisierbar UND der
                    // Kontakt noch keine primary phone hat. Bestehende
                    // Nummer wird nicht ueberschrieben — Datenqualitaet
                    // bestehender CRM-Daten gewinnt.
                    if ($mobileE164) {
                        $hasPrimaryPhone = $contact->phoneNumbers()->where('is_primary', true)->exists();
                        if (!$hasPrimaryPhone) {
                            $contact->phoneNumbers()->create([
                                'raw_input'     => $mobileRaw ?: $mobileE164,
                                'international' => $mobileE164,
                                'phone_type_id' => $mobilePhoneTypeId,
                                'is_primary'    => true,
                                'is_active'     => true,
                            ]);
                        }
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

                    return $applicant->id;
                });

                $result['imported']++;
                $result['imported_applicant_ids'][] = $newApplicantId;
                $result['details'][] = [
                    'action' => 'imported',
                    'row'    => $rowNo,
                    'name'   => $displayName,
                    'note'   => $existingContact
                        ? "Contact #{$existingContact->id} wiederverwendet, neuer Bewerber #{$newApplicantId} angelegt"
                        : "Contact + Bewerber #{$newApplicantId} neu angelegt",
                ];
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'row'     => $rowNo,
                    'name'    => $displayName,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Liest CSV in ein Array von Assoc-Arrays {feldKey => wert}.
     * Erkennt Encoding (Windows-1252 / UTF-8 / ISO-8859-1) und konvertiert
     * nach UTF-8. Strippt BOM. Schreibt fatale Fehler in $result['fatal'].
     */
    private function readCsv(string $file, array &$result): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            $result['fatal'] = 'Konnte Datei nicht lesen.';
            return null;
        }

        $raw = \Platform\Recruiting\Support\CsvEncodingNormalizer::toUtf8((string) $raw);

        $tmp = tmpfile();
        if (!$tmp) {
            $result['fatal'] = 'Konnte temporäre Datei nicht öffnen.';
            return null;
        }
        fwrite($tmp, (string) $raw);
        rewind($tmp);

        $header = fgetcsv($tmp, 0, ';');
        if (!$header) {
            fclose($tmp);
            $result['fatal'] = 'Header-Zeile konnte nicht gelesen werden.';
            return null;
        }

        $columnMap = [];
        foreach ($header as $colIdx => $colName) {
            $name = trim((string) $colName);
            if ($name === '') continue;
            if (isset(self::FIELD_MAP[$name]) && !isset($columnMap[self::FIELD_MAP[$name]])) {
                $columnMap[self::FIELD_MAP[$name]] = $colIdx;
            }
        }

        $missing = array_diff(['first_name', 'last_name', 'postal_code'], array_keys($columnMap));
        if (!empty($missing)) {
            fclose($tmp);
            $result['fatal'] = 'Pflicht-Spalten in CSV-Header nicht gefunden: ' . implode(', ', $missing)
                . '. Erkannt: ' . (empty($columnMap) ? '(keine)' : implode(', ', array_keys($columnMap)));
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

    private function parseDate($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;

        try {
            return Carbon::createFromFormat('d.m.Y', $value)->format('Y-m-d');
        } catch (\Throwable) {
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

    /**
     * Normalisiert eine Telefonnummer auf E.164 (z.B. +491766408059).
     * Für WhatsApp-Versand zwingend nötig.
     *
     * Reihenfolge der Regeln (erste passende gewinnt):
     *   - "+49 176 6408 0594"   → +491766408594  (Leerzeichen/Trennzeichen weg)
     *   - "0049176..."          → +49176...      (00-Prefix wird zu +)
     *   - "0176..."             → +49176...      (Deutsche Inlands-Notation, 0 weg)
     *   - "49176..."            → +49176...      (Ländercode ohne +; in DE
     *                                              eindeutig, weil keine
     *                                              Inlandsvorwahl mit 49 beginnt)
     *   - "176..."              → +49176...      (kein Prefix → default DE)
     *   - "abc" / leer          → null           (nicht parseable)
     */
    private function normalizePhoneE164(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Nur Ziffern + führendes + behalten (Leerzeichen, /, -, () weg)
        $clean = preg_replace('/[^\d+]/', '', $raw);
        if (!is_string($clean) || $clean === '') return null;

        if (str_starts_with($clean, '+')) {
            // bereits in E.164-Form (oder fast) — nichts zu tun
        } elseif (str_starts_with($clean, '00')) {
            // 0049... → +49...
            $clean = '+' . substr($clean, 2);
        } elseif (str_starts_with($clean, '0')) {
            // Deutsche Inlands-Notation: 0176... → +49176...
            $clean = '+49' . substr($clean, 1);
        } elseif (str_starts_with($clean, '49')) {
            // Ländercode ohne +: 49176... → +49176...
            // (Kein Konflikt mit Inlandsvorwahlen — die starten in DE alle mit 0)
            $clean = '+' . $clean;
        } else {
            // Kein Prefix → default DE
            $clean = '+49' . $clean;
        }

        // Sanity: + gefolgt von 8-15 Ziffern (E.164-Spezifikation)
        if (!preg_match('/^\+\d{8,15}$/', $clean)) {
            return null;
        }

        return $clean;
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
