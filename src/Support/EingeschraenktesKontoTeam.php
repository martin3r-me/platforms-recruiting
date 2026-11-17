<?php

namespace Platform\Recruiting\Support;

/**
 * Eingeschraenkte Konten (Stufen "Nur Veranstaltungen" / "Schulungsbewertung",
 * siehe DispoAccess) sind Sammelkonten: mehrere Leute teilen sich eines. Auf
 * der Bewerten-Seite zeigt der Core das Team-Menue — ein versehentlicher Klick
 * stellt das Konto auf sein persoenliches Team, dort ist Recruiting nicht
 * freigeschaltet, und ALLE Nutzer des Kontos landen auf "403 Keine
 * Berechtigung" (Vorfall event@, 16.11.2026).
 *
 * Deshalb: steht ein eingeschraenktes Konto nicht im Anker-Team, wird es
 * dorthin zurueckgestellt — sofern es dort Mitglied ist. Reine Entscheidung,
 * das Schreiben macht die Middleware (RecruitingAccessGate).
 */
final class EingeschraenktesKontoTeam
{
    /**
     * Das Team, auf das zurueckgestellt werden soll — oder null, wenn nichts zu tun ist.
     *
     * @param callable(int):bool $istMitglied prueft die Mitgliedschaft im Anker-Team (wird nur bei Bedarf gerufen)
     */
    public static function ziel(bool $eingeschraenkt, ?int $aktuellesTeamId, int $ankerTeamId, callable $istMitglied): ?int
    {
        if (!$eingeschraenkt || $ankerTeamId <= 0 || (int) $aktuellesTeamId === $ankerTeamId) {
            return null;
        }

        return $istMitglied($ankerTeamId) ? $ankerTeamId : null;
    }
}
