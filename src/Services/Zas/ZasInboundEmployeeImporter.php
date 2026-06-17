<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Verarbeitet ZAS-Inbound-Datenzeilen: legt MA an, die bei uns noch nicht
 * existieren (Neuanlage-only). Bestehende (UUID- oder personnel_number-Match) werden
 * uebersprungen. Pro Zeile gekapselt — eine fehlerhafte Zeile stoppt nicht den Rest.
 */
class ZasInboundEmployeeImporter
{
    public function __construct(private ZasInboundRowMapper $mapper) {}

    public function import(array $rows, $inbound, bool $dryRun): array
    {
        $teamId = config('recruiting.zas.inbound_team_id');
        $created = [];
        $skipped = [];
        $failed = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            try {
                $mapped = $this->mapper->map($row);
                foreach ($mapped['warnings'] as $w) {
                    $warnings[] = "Zeile " . ($index + 1) . ": {$w}";
                }

                // Matching-Kaskade
                $existing = $this->findExisting($mapped['uuid'], $mapped['personnel_number'], $teamId);
                if ($existing !== null) {
                    $skipped[] = ['personnel_number' => $mapped['personnel_number'], 'employee_id' => $existing->id, 'reason' => 'exists'];
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

        $status = $failed !== [] ? ($created !== [] || $skipped !== [] ? 'partial' : 'failed') : 'processed';

        return compact('status', 'created', 'skipped', 'failed', 'warnings');
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
}
