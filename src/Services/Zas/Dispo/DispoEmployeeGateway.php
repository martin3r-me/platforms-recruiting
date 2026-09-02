<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecEmployee;

/**
 * ZWEITE Tuer vom Dispo-Code zum Recruiting-Personal (neben
 * DispoEmployeeDirectory, Entkopplungs-Leitplanke 3): liefert Kontaktdaten
 * fuer den Bestaetigungs-Versand. Beim Staffing-Auszug wird diese Klasse
 * gegen den dortigen Personen-Adapter getauscht.
 */
class DispoEmployeeGateway
{
    /** @return array<int, array{name: string, first_name: string, phone: ?string, portal_token: string, personnel_number: string, company: string}> */
    public function contacts(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'phone', 'portal_token', 'personnel_number', 'company'])
            ->mapWithKeys(fn ($e) => [(int) $e->id => [
                'name'              => trim($e->first_name . ' ' . $e->last_name),
                'first_name'        => trim((string) $e->first_name),
                // Sendewege bekommen IMMER E.164 (Befund 01.09.: nationale ZAS-Formate
                // -> Meta 131026); unparsebare Rohnummer geht unveraendert raus und
                // faellt als failed auf (nicht schlechter als vorher).
                'phone'             => ($e->phone !== null && trim($e->phone) !== '')
                    ? (\Platform\Recruiting\Support\PhoneE164::normalize($e->phone) ?? trim($e->phone))
                    : null,
                'portal_token'      => (string) $e->portal_token,
                'personnel_number'  => (string) ($e->personnel_number ?? ''),
                'company'           => (string) ($e->company ?? ''),
            ]])
            ->all();
    }

    /** @return array<int, ?string> employee_id => phone */
    public function phones(array $employeeIds): array
    {
        return array_map(fn ($c) => $c['phone'], $this->contacts($employeeIds));
    }

    /**
     * Personal-Kaertchen fuer das Crew-Modal der VA-Seite (Kunde 02.09.):
     * abgespeckte Sicht statt Link in die volle MA-Akte. Liefert je id Name,
     * PNr, Sterne (star_rating, sonst gerundeter Schnitt der Einzel-Ratings),
     * Qualifikations-LABELS (Lookup 'qualifikation') und die Selfie-URL
     * (Thumbnail-Variante bevorzugt, Muster InterviewBookings::selfies()).
     *
     * @param list<int> $employeeIds
     * @return array<int, array{name:string, personnel_number:string, stars:?int, qualifications:list<string>, selfie_url:?string}>
     */
    public function profileCards(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $employees = RecEmployee::query()->with('hrData')->whereIn('id', $employeeIds)->get();

        // Lookup-Map einmal laden (value => label).
        $lookupId = \Illuminate\Support\Facades\DB::table('core_lookups')->where('name', 'qualifikation')->value('id');
        $lookupMap = $lookupId
            ? \Illuminate\Support\Facades\DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('label', 'value')->all()
            : [];

        // Selfies: ContextFiles + Thumbnail-Variante in zwei Queries fuer alle ids.
        $fileIds = $employees->pluck('selfie_file_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $files = $fileIds === [] ? collect() : \Platform\Core\Models\ContextFile::whereIn('id', $fileIds)->get()->keyBy('id');
        $variants = $fileIds === [] ? collect() : \Platform\Core\Models\ContextFileVariant::whereIn('context_file_id', $fileIds)
            ->where('variant_type', 'like', 'thumbnail_%')
            ->orderBy('id')
            ->get()
            ->groupBy('context_file_id')
            ->map(fn ($group) => $group->firstWhere('variant_type', 'thumbnail_4_3') ?? $group->first());

        $cards = [];
        foreach ($employees as $e) {
            $hr = $e->hrData;

            $stars = $hr?->star_rating !== null ? (int) $hr->star_rating : null;
            if ($stars === null && $hr !== null) {
                $ratings = array_filter([
                    $hr->rating_erscheinungsbild, $hr->rating_fachkompetenz, $hr->rating_auffassungsgabe,
                    $hr->rating_auftreten, $hr->rating_teamintegration,
                ], fn ($v) => $v !== null);
                $stars = $ratings === [] ? null : (int) round(array_sum($ratings) / count($ratings));
            }

            $quals = $hr?->qualifications;
            if (is_string($quals)) {
                $decoded = json_decode($quals, true);
                $quals = is_array($decoded) ? $decoded : [];
            }
            $qualLabels = array_values(array_map(fn ($v) => (string) ($lookupMap[$v] ?? $v), is_array($quals) ? $quals : []));

            $selfieUrl = null;
            if ($e->selfie_file_id) {
                $file = $files->get((int) $e->selfie_file_id);
                if ($file && $file->isImage()) {
                    $selfieUrl = $variants->get((int) $e->selfie_file_id)?->url ?? $file->url;
                }
            }

            $cards[(int) $e->id] = [
                'name'             => trim($e->first_name . ' ' . $e->last_name),
                'personnel_number' => (string) ($e->personnel_number ?? ''),
                'stars'            => ($stars !== null && $stars >= 1 && $stars <= 5) ? $stars : null,
                'qualifications'   => $qualLabels,
                'selfie_url'       => $selfieUrl,
            ];
        }

        return $cards;
    }

    /**
     * Sperrt das MA-Portal (Eskalations-Stufe 3: 16-Uhr-Rausnahme). Idempotent —
     * ein bereits gesperrter MA wird NICHT ueberschrieben (Grund/Zeitpunkt der
     * ERSTEN Sperre bleiben erhalten). Kein Employee zu einer ID -> no-op fuer
     * diese ID. Nimmt eine einzelne id ODER mehrere (Dispo-Identitaetsgruppe,
     * damit z. B. RG- und MA-Datensatz derselben Person gemeinsam gesperrt werden).
     *
     * @param int|list<int> $employeeIds
     */
    public function lockPortal(int|array $employeeIds, string $reason): void
    {
        foreach ((array) $employeeIds as $employeeId) {
            $employee = RecEmployee::find($employeeId);
            if ($employee === null || $employee->portal_locked_at !== null) {
                continue;
            }

            $employee->portal_locked_at = now();
            $employee->portal_locked_reason = $reason;
            $employee->save();
        }
    }

    /** @return array<int, ?string> employee_id => Roh-Telefonnummer (nur aktive MA mit Nummer) */
    public function phoneDirectory(): array
    {
        // Team-Anker wie Resolver/Settings — Cross-Tenant-Nummern duerfen weder matchen noch Ambiguitaet ausloesen.
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()?->currentTeam?->id);

        return RecEmployee::query()
            ->when($teamId > 0, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone', 'id')
            ->map(fn ($p) => (string) $p)
            ->all();
    }
}
