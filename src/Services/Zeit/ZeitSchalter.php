<?php

namespace Platform\Recruiting\Services\Zeit;

use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;

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

    /**
     * Erledigte Zeiten wieder oeffnen (Markus 17.11.2026: „Als PL/Disponent mit Anmeldung im HCM darf ich es
     * nacharbeiten falls noetig, nur mit dem Eventzugang nicht mehr“):
     *  - Konten „Nur Veranstaltungen“ (Eventzugang): nie.
     *  - Liste leer: jedes andere Konto.
     *  - Liste gefuellt: nur die genannten Adressen (freiwillige Einschraenkung).
     * Ohne E-Mail, ohne Team oder bei einem Lesefehler: nein.
     */
    public function darfEntsperren(mixed $user): bool
    {
        try {
            $email = mb_strtolower(trim((string) ($user->email ?? '')));
            $team = $this->teamId();
            if ($email === '' || $team === 0) {
                return false;
            }
            if (DispoAccess::eventOnly($user)) {
                return false;
            }
            $liste = array_values(array_filter(array_map(
                fn ($e) => mb_strtolower(trim((string) $e)),
                (array) ($this->settings($team)?->getSetting(self::SETTING_ENTSPERREN) ?? [])
            ), fn ($e) => $e !== ''));

            return $liste === [] || in_array($email, $liste, true);
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
