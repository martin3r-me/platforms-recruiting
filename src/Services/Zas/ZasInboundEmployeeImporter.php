<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Verarbeitet ZAS-Inbound-Datenzeilen: legt MA an, die bei uns noch nicht
 * existieren, und synchronisiert bei bestehenden (UUID- oder
 * personnel_number-Match) AUSSCHLIESSLICH die Statusfelder. Pro Zeile
 * gekapselt — eine fehlerhafte Zeile stoppt nicht den Rest.
 *
 * Warum kein Voll-Update bei Treffern: ZAS wuerde damit HR-gepflegte Felder
 * ueberschreiben. Gesynct wird nur, was ZAS gehoert — der Status und sein
 * Umstellungsdatum (Kundenwunsch 2026-08-18, "seit wann steht jemand auf MA").
 */
class ZasInboundEmployeeImporter
{
    public function __construct(private ZasInboundRowMapper $mapper) {}

    public function import(array $rows, $inbound, bool $dryRun): array
    {
        $teamId = config('recruiting.zas.inbound_team_id');
        $created = [];
        $updated = [];
        $skipped = [];
        $failed = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            try {
                // Guard 1: Struktur — verschobene Zeilen erzeugen Muell-Daten
                // in falschen Feldern; lieber abweisen und ZAS melden.
                $structureIssue = $this->detectRowStructureIssue($row);
                if ($structureIssue !== null) {
                    $pn = trim((string) ($row['ZasPersonalNr'] ?? ''));
                    $failed[] = ['personnel_number' => $pn !== '' ? $pn : null, 'reason' => "Zeile " . ($index + 1) . ": {$structureIssue}"];
                    continue;
                }

                $mapped = $this->mapper->map($row);
                // PersNr in jeder Warnung: macht den Sammel-Bericht nach dem
                // Massenimport pro Person zuordenbar ("Zeile 12" allein sagt
                // HR nichts, wenn 9 Paeckchen a 100 Zeilen durchlaufen).
                $pnLabel = $mapped['personnel_number'] !== null ? ' (PersNr ' . $mapped['personnel_number'] . ')' : '';
                foreach ($mapped['warnings'] as $w) {
                    $warnings[] = "Zeile " . ($index + 1) . "{$pnLabel}: {$w}";
                }

                // Guard 2: ohne ZAS-Personalnummer kein Dubletten-Schluessel —
                // ein Re-Send wuerde die Zeile doppelt anlegen. Abweisen.
                if (!$mapped['personnel_number']) {
                    $failed[] = ['personnel_number' => null, 'reason' => "Zeile " . ($index + 1) . ": ZasPersonalNr fehlt — nicht importiert (kein Dubletten-Schluessel)"];
                    continue;
                }

                // Matching-Kaskade
                $existing = $this->findExisting($mapped['uuid'], $mapped['personnel_number'], $teamId);
                if ($existing !== null) {
                    $changes = $this->statusSyncChanges($existing, $mapped['hr']);
                    if ($changes === []) {
                        $skipped[] = ['personnel_number' => $mapped['personnel_number'], 'employee_id' => $existing->id, 'reason' => 'exists'];
                        continue;
                    }
                    if ($dryRun) {
                        $updated[] = [
                            'would_update'     => true,
                            'employee_id'      => $existing->id,
                            'personnel_number' => $mapped['personnel_number'],
                            'changed'          => array_keys($changes),
                        ];
                        continue;
                    }
                    $this->syncStatusFields($existing, $changes);
                    $updated[] = [
                        'employee_id'      => $existing->id,
                        'personnel_number' => $mapped['personnel_number'],
                        'changed'          => array_keys($changes),
                    ];
                    continue;
                }

                if (!$teamId) {
                    $failed[] = ['personnel_number' => $mapped['personnel_number'], 'reason' => 'RECRUITING_ZAS_INBOUND_TEAM_ID nicht konfiguriert'];
                    continue;
                }

                if ($dryRun) {
                    $created[] = [
                        'would_create'     => true,
                        'personnel_number' => $mapped['personnel_number'],
                        'name'             => trim(($mapped['employee']['last_name'] ?? '') . ', ' . ($mapped['employee']['first_name'] ?? '')),
                    ];
                    continue;
                }

                $employee = $this->createEmployee($mapped, $teamId, $inbound->id);
                $created[] = ['employee_id' => $employee->id, 'personnel_number' => $employee->personnel_number];
            } catch (\Throwable $e) {
                $failed[] = ['personnel_number' => $row['ZasPersonalNr'] ?? null, 'reason' => $e->getMessage()];
            }
        }

        $status = $failed !== [] ? ($created !== [] || $updated !== [] || $skipped !== [] ? 'partial' : 'failed') : 'processed';

        return compact('status', 'created', 'updated', 'skipped', 'failed', 'warnings');
    }

    /**
     * Felder, die bei einem Treffer ueberhaupt angefasst werden duerfen.
     * Bewusst kurz: alles andere gehoert HR, nicht ZAS.
     */
    protected const STATUS_SYNC_FIELDS = ['export_status', 'status_ma_since'];

    /**
     * Ermittelt die tatsaechlichen Aenderungen an den Statusfeldern.
     *
     * Ein Feld, das der Mapper NICHT gesetzt hat, fehlt hier als Key und wird
     * nicht angefasst — so bleibt "nicht geliefert" (bzw. Lieferfehler)
     * unterscheidbar von "aktiv geleert" (Key mit null).
     *
     * @param  array<string,mixed> $hr Mapper-Ausgabe fuer rec_employee_hr_data
     * @return array<string,mixed> nur die abweichenden Felder
     */
    protected function statusSyncChanges(RecEmployee $existing, array $hr): array
    {
        $hrRow = $existing->hrData;
        $norm = static fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ($v === null ? null : (string) $v);

        $changes = [];
        foreach (self::STATUS_SYNC_FIELDS as $field) {
            if (!array_key_exists($field, $hr)) {
                continue;
            }
            if ($norm($hrRow?->getAttribute($field)) !== $norm($hr[$field])) {
                $changes[$field] = $hr[$field];
            }
        }

        return $changes;
    }

    /**
     * Schreibt die Statusfelder und stellt den Export-Marker exakt so wieder
     * her, wie er vorher war.
     *
     * Grund: der HrData-save triggert den RecEmployeeExportObserver, der wegen
     * export_status in RELEVANT_HR_FIELDS zas_changed_at setzt. Wir wuerden ZAS
     * damit den Wert zurueckschicken, den ZAS uns gerade geliefert hat — bei
     * einer Bestandslieferung mit hunderten Zeilen also den Update-Export
     * fluten. Absichtlich RESTAURIEREN statt hart auf null: ein vorher
     * gesetzter Marker stammt aus einer echten Aenderung und wuerde sonst
     * verschluckt, der Export ginge verloren.
     *
     * @param array<string,mixed> $changes
     */
    protected function syncStatusFields(RecEmployee $existing, array $changes): void
    {
        DB::transaction(function () use ($existing, $changes): void {
            $marker = DB::table('rec_employees')->where('id', $existing->id)->value('zas_changed_at');

            $existing->ensureHrData()->fill($changes)->save();

            DB::table('rec_employees')
                ->where('id', $existing->id)
                ->update(['zas_changed_at' => $marker]);
        });
    }

    protected function findExisting(?string $uuid, ?string $personnelNumber, $teamId): ?RecEmployee
    {
        if ($uuid) {
            $byUuid = RecEmployee::where('uuid', $uuid)->first();
            if ($byUuid) {
                return $byUuid;
            }
        }
        if ($personnelNumber) {
            return RecEmployee::where('personnel_number', $personnelNumber)
                ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
                ->first();
        }
        return null;
    }

    protected function createEmployee(array $mapped, int $teamId, int $inboundId): RecEmployee
    {
        return DB::transaction(function () use ($mapped, $teamId, $inboundId) {
            $employee = RecEmployee::create(array_merge($mapped['employee'], [
                'team_id'                 => $teamId,
                'rec_applicant_id'        => null,
                'personnel_number'        => $mapped['personnel_number'],
                'rec_zas_inbound_file_id' => $inboundId,
                'is_active'               => true,
                // Export-Schleifen-Schutz: nicht erneut an ZAS exportieren.
                'zas_initial_exported_at' => now(),
            ]));

            if ($mapped['hr'] !== []) {
                $hr = $employee->ensureHrData();
                $hr->fill($mapped['hr'])->save();
            }

            // Export-Schleifen-Schutz, Teil 2: der HrData-save oben triggert den
            // RecEmployeeExportObserver, der zas_changed_at setzt — was den frisch
            // importierten MA sofort in den ZAS-Update-Export spuelen wuerde.
            // Direktes DB-Update (ohne Observer) macht das wieder rueckgaengig.
            DB::table('rec_employees')
                ->where('id', $employee->id)
                ->update(['zas_changed_at' => null]);

            return $employee;
        });
    }

    /**
     * Erkennt verschobene/kaputte Zeilen (Erkenntnis aus dem 100er-Testlauf:
     * eine Zeile mit Spaltenversatz haette einen Muell-MA ohne Dubletten-
     * Schluessel angelegt).
     *
     *  - col_N-Keys: die Zeile hatte MEHR Werte als der Header (zip() im
     *    Controller haengt Ueberzaehlige als col_N an) — typisch: Semikolon
     *    im Feldwert.
     *  - '|'-Marker: das ZAS-Zeilenende `;|;` erzeugt eine '|'-Spalte, deren
     *    Wert in jeder intakten Zeile '|' ist. Alles andere = Versatz/zu kurz.
     */
    protected function detectRowStructureIssue(array $row): ?string
    {
        foreach (array_keys($row) as $key) {
            if (str_starts_with((string) $key, 'col_')) {
                return 'Zeile hat mehr Spalten als der Header (Spaltenversatz, vermutlich Semikolon im Feldwert) — nicht importiert';
            }
        }
        if (array_key_exists('|', $row) && trim((string) $row['|']) !== '|') {
            return 'Zeilenende-Marker verschoben (Spaltenversatz oder Zeile zu kurz) — nicht importiert';
        }
        return null;
    }
}
