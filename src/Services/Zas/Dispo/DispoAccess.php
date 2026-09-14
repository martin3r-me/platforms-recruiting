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
    /** Setting-Keys der beiden Zugriffsstufen. */
    public const SETTING_EVENT_ONLY = 'dispo_event_only_emails';
    public const SETTING_TRAINING_LEADER = 'dispo_training_leader_emails';

    /** @var array<string, list<string>> Request-Memo je Setting-Key (Middleware + Sidebar + Komponenten fragen mehrfach) */
    private static array $memo = [];

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
        return self::gelistet($user, self::SETTING_EVENT_ONLY);
    }

    /**
     * Stufe "Schulungsbewertung" (Kundenwunsch 14.09.2026): Teamleiter-Konten
     * duerfen ihre Schulungen nachbereiten — Anwesenheit, Bewertung, Klaerung
     * an HR. Kein Lohn, kein Vertragsversand, keine Akten.
     *
     * BEWUSST eine zweite Liste und keine Erweiterung der ersten: ein Konto
     * nur fuer Veranstaltungen soll nicht automatisch Bewerberdaten sehen.
     * Ein Konto darf auf beiden Listen stehen — das ist beim Kunden der
     * Normalfall.
     */
    public static function trainingLeader(mixed $user): bool
    {
        return self::gelistet($user, self::SETTING_TRAINING_LEADER);
    }

    /** Bequemer Einstieg fuer Komponenten: aktueller Nutzer. */
    public static function currentUserTrainingLeader(): bool
    {
        try {
            return self::trainingLeader(auth()->user());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> kleingeschriebene E-Mails aus dem Setting */
    public static function eventOnlyEmails(): array
    {
        return self::emails(self::SETTING_EVENT_ONLY);
    }

    /** @return list<string> kleingeschriebene E-Mails aus dem Setting */
    public static function trainingLeaderEmails(): array
    {
        return self::emails(self::SETTING_TRAINING_LEADER);
    }

    private static function gelistet(mixed $user, string $key): bool
    {
        $email = mb_strtolower(trim((string) ($user?->email ?? '')));
        if ($email === '') {
            return false;
        }

        return in_array($email, self::emails($key), true);
    }

    /** @return list<string> */
    private static function emails(string $key): array
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        try {
            $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
            if ($teamId <= 0) {
                return self::$memo[$key] = [];
            }
            $raw = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting($key);
        } catch (\Throwable) {
            return self::$memo[$key] = [];
        }

        return self::$memo[$key] = array_values(array_filter(array_map(
            fn ($v) => mb_strtolower(trim((string) $v)),
            is_array($raw) ? $raw : []
        ), fn ($v) => $v !== ''));
    }

    /** Nur fuer Tests: Request-Memo verwerfen. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
