<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Zugriffsstufe "Nur Veranstaltungen" (Gate Stufe 1, Kunde 03.09.):
 * Teamleiter-Konten (z. B. event@rheingedeck.de) sehen im Recruiting
 * ausschliesslich Disposition -> Veranstaltungen (Liste + VA-Seite lesend,
 * Chat inklusive) — kein Dashboard, keine Bewerber, keine MA-Akten, keine
 * Dispo-Einstellungen.
 *
 * Zuordnung per E-MAIL (kleingeschrieben) im Setting dispo_event_only_emails
 * am ZAS-Anker-Team — gepflegt in Disposition -> Einstellungen, damit HR
 * weitere Zugaenge ohne Deploy ergaenzen kann. E-Mail statt User-id, weil
 * das SSO Konten anhand der E-Mail anlegt: die Zuordnung darf VOR dem ersten
 * Login existieren.
 *
 * Fehlerrichtung: Wer NICHT auf der Liste steht (oder die Liste nicht lesbar
 * ist), ist normaler Nutzer — die Stufe ist ein Opt-in pro Konto.
 */
final class DispoAccess
{
    /** @var array{0: list<string>}|null Request-Memo (Middleware + Sidebar + Komponenten fragen mehrfach) */
    private static ?array $memo = null;

    /**
     * Bequemer Einstieg fuer Komponenten: aktueller Nutzer. Ohne gebootetes
     * Auth-System (Capsule-Tests) -> false, gleiche Opt-in-Fehlerrichtung.
     */
    public static function currentUserEventOnly(): bool
    {
        try {
            return self::eventOnly(auth()->user());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function eventOnly(mixed $user): bool
    {
        $email = mb_strtolower(trim((string) ($user?->email ?? '')));
        if ($email === '') {
            return false;
        }

        return in_array($email, self::eventOnlyEmails(), true);
    }

    /** @return list<string> kleingeschriebene E-Mails aus dem Setting */
    public static function eventOnlyEmails(): array
    {
        if (self::$memo !== null) {
            return self::$memo[0];
        }

        try {
            $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
            if ($teamId <= 0) {
                return (self::$memo = [[]])[0];
            }
            $raw = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('dispo_event_only_emails');
        } catch (\Throwable) {
            return (self::$memo = [[]])[0];
        }

        $emails = array_values(array_filter(array_map(
            fn ($v) => mb_strtolower(trim((string) $v)),
            is_array($raw) ? $raw : []
        ), fn ($v) => $v !== ''));

        return (self::$memo = [$emails])[0];
    }

    /** Nur fuer Tests: Request-Memo verwerfen. */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
