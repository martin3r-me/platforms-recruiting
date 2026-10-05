<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Katalog der Weiterleitungs-Ziele und -Quellen (Spec 02.10.2026). Heute nur
 * dispo -> hr. Ein neues Ziel = neuer Eintrag hier + eine Ansicht, die per
 * RecConversationForward::openForTeam($team, $ziel) filtert + ggf. eine eigene
 * Erstnachricht-Regel. Bewusst KEIN Plugin-System, solange es ein Ziel gibt.
 */
final class ForwardTargets
{
    public const HR = 'hr';
    public const SOURCE_DISPO = 'dispo';

    /** @var array<string, string> Ziel => Anzeigename */
    private const TARGETS = [self::HR => 'HR'];

    /** @var list<string> */
    private const SOURCES = [self::SOURCE_DISPO];

    public static function isTarget(string $target): bool
    {
        return isset(self::TARGETS[$target]);
    }

    public static function isSource(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    public static function label(string $target): string
    {
        return self::TARGETS[$target] ?? $target;
    }

    public static function chipOpen(string $target): string
    {
        return 'bei ' . self::label($target);
    }

    public static function chipDone(string $target): string
    {
        return self::label($target) . ' erledigt';
    }

    public static function action(string $target): string
    {
        return 'An ' . self::label($target) . ' weiterleiten';
    }
}
