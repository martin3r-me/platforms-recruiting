<?php

namespace Platform\Recruiting\Support;

/**
 * Status einer Zustellung, ABGELEITET aus Zeitstempeln (Spec 2026-10-08, §2.2).
 * Reihenfolge ist Absicht: zurueckgezogen > unterschrieben > bestaetigt >
 * gesehen > offen. Bei Aktion `none` gibt es nur abgelegt/gesehen. Der
 * Benachrichtigungsstand ist eine zweite Achse und kommt hier nicht vor.
 */
final class DokumentStatus
{
    public const ZURUECKGEZOGEN = 'zurueckgezogen';
    public const UNTERSCHRIEBEN = 'unterschrieben';
    public const BESTAETIGT     = 'bestaetigt';
    public const GESEHEN        = 'gesehen';
    public const OFFEN          = 'offen';
    public const ABGELEGT       = 'abgelegt';

    private const LABELS = [
        self::ZURUECKGEZOGEN => 'Zurückgezogen',
        self::UNTERSCHRIEBEN => 'Unterschrieben',
        self::BESTAETIGT     => 'Bestätigt',
        self::GESEHEN        => 'Gesehen',
        self::OFFEN          => 'Offen',
        self::ABGELEGT       => 'Abgelegt',
    ];

    /**
     * @param array{withdrawn_at:?string, signed_at:?string, acknowledged_at:?string, first_viewed_at:?string} $z
     */
    public static function fuer(array $z, string $aktion): string
    {
        if (($z['withdrawn_at'] ?? null) !== null) {
            return self::ZURUECKGEZOGEN;
        }
        if ($aktion === DokumentKategorie::AKTION_SIGN && ($z['signed_at'] ?? null) !== null) {
            return self::UNTERSCHRIEBEN;
        }
        if ($aktion !== DokumentKategorie::AKTION_NONE && ($z['acknowledged_at'] ?? null) !== null) {
            return self::BESTAETIGT;
        }
        if (($z['first_viewed_at'] ?? null) !== null) {
            return self::GESEHEN;
        }

        return $aktion === DokumentKategorie::AKTION_NONE ? self::ABGELEGT : self::OFFEN;
    }

    /** Erledigt heisst: das Verlangte ist passiert. Nur ablegen verlangt nichts. */
    public static function istErledigt(array $z, string $aktion): bool
    {
        if (($z['withdrawn_at'] ?? null) !== null) {
            return false;
        }

        return match ($aktion) {
            DokumentKategorie::AKTION_SIGN        => ($z['signed_at'] ?? null) !== null,
            DokumentKategorie::AKTION_ACKNOWLEDGE => ($z['acknowledged_at'] ?? null) !== null,
            default                               => true,
        };
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
