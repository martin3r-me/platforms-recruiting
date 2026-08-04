<?php

namespace Platform\Recruiting\Support;

/**
 * Transienter Trigger-Kontext fuer den Phase-Observer: die vier bekannten
 * Phasenwechsel-Methoden setzen ihn VOR dem save() (try/finally!); der
 * Observer konsumiert ihn beim Schreiben der Transition. Alle anderen
 * Schreibpfade (LLM-Tool, DirectHire, Reconcile, SyncPhases, ...) laufen als
 * 'unknown' — bewusst, damit keiner still falsch etikettiert wird (Spec §5).
 *
 * An die Applicant-ID gebunden (P1): Queue-Worker leben stundenlang im selben
 * Prozess — ein liegengebliebener Wert darf nie den naechsten Wechsel eines
 * anderen Bewerbers etikettieren. consume() leert deshalb IMMER.
 */
final class PhaseTransitionTrigger
{
    public const AUTO_ADVANCE   = 'auto_advance';
    public const MANUAL         = 'manual';
    public const RETURNED       = 'returned';
    public const POSITION_SWITCH = 'position_switch';
    public const FIX            = 'fix';
    public const PHASE_DELETED  = 'phase_deleted';
    public const UNKNOWN        = 'unknown';

    private static ?int $applicantId = null;
    private static ?string $trigger = null;

    public static function set(int $applicantId, string $trigger): void
    {
        self::$applicantId = $applicantId;
        self::$trigger = $trigger;
    }

    /** Liefert den Trigger nur bei ID-Match — und leert in JEDEM Fall. */
    public static function consume(int $applicantId): string
    {
        $value = (self::$applicantId === $applicantId && self::$trigger !== null)
            ? self::$trigger
            : self::UNKNOWN;
        self::$applicantId = null;
        self::$trigger = null;

        return $value;
    }

    /** Aufraeumen im finally der Setz-Stellen — Exception im save() darf nichts stehen lassen. */
    public static function forget(int $applicantId): void
    {
        if (self::$applicantId === $applicantId) {
            self::$applicantId = null;
            self::$trigger = null;
        }
    }
}
