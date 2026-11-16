<?php

namespace Platform\Recruiting\Services\Zeit;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Schalter und Freigeber fuers Stempeln (Spec 2026-11-16 §2.2/§5). Nicht final und
 * nicht statisch: Tests binden eine Attrappe in den Container. Jeder Fehler
 * heisst „aus“ bzw. „darf nicht“.
 */
class ZeitSchalter
{
    public const SETTING_AKTIV = 'zeit_stempeln_aktiv';
    public const SETTING_ENTSPERREN = 'zeit_entsperren_emails';

    /** Anker-Team wie die dispo_*-Settings; sonst das aktuelle Team des Nutzers; sonst 0. */
    public function teamId(): int
    {
        try {
            return (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function aktiv(): bool
    {
        try {
            $team = $this->teamId();
            if ($team === 0) {
                return false;
            }

            return (bool) $this->settings($team)?->getSetting(self::SETTING_AKTIV);
        } catch (\Throwable) {
            return false;
        }
    }

    /** E-Mail (kleingeschrieben, getrimmt) steht in der Liste; leere Liste: niemand. */
    public function darfEntsperren(mixed $user): bool
    {
        try {
            $email = mb_strtolower(trim((string) ($user->email ?? '')));
            $team = $this->teamId();
            if ($email === '' || $team === 0) {
                return false;
            }
            $liste = array_map(
                fn ($e) => mb_strtolower(trim((string) $e)),
                (array) ($this->settings($team)?->getSetting(self::SETTING_ENTSPERREN) ?? [])
            );

            return in_array($email, $liste, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lesen ohne Anlegen einer Zeile. */
    private function settings(int $teamId): ?RecApplicantSettings
    {
        return RecApplicantSettings::query()->where('team_id', $teamId)->first();
    }
}
