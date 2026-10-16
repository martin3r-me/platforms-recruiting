<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Katalog der Weiterleitungs-Ziele und -Quellen (Spec 02.10.2026). Zwei
 * Richtungen: dispo -> hr und seit 16.10.2026 hr -> dispo. Ein neues Ziel =
 * neuer Eintrag hier + eine Ansicht, die per
 * RecConversationForward::openForTeam($team, $ziel) filtert + ggf. eine eigene
 * Erstnachricht-Regel. Bewusst KEIN Plugin-System.
 *
 * Ziel "dispo" ist ein bestimmter Kanal (Filiale) — die Beschriftung
 * ("Dispo Köln") kommt aus DispoForwardChannels, deshalb nehmen label()/chip*()
 * optional die Kanal-Beschriftung entgegen.
 */
final class ForwardTargets
{
    public const HR = 'hr';
    public const DISPO = 'dispo';
    public const SOURCE_DISPO = 'dispo';
    public const SOURCE_HR = 'hr';

    /** @var array<string, string> Ziel => Anzeigename */
    private const TARGETS = [self::HR => 'HR', self::DISPO => 'Dispo'];

    /** @var list<string> */
    private const SOURCES = [self::SOURCE_DISPO, self::SOURCE_HR];

    /** @var array<string, string> erlaubte Richtungen: Quelle => Ziel */
    private const ROUTES = [self::SOURCE_DISPO => self::HR, self::SOURCE_HR => self::DISPO];

    public static function isTarget(string $target): bool
    {
        return isset(self::TARGETS[$target]);
    }

    public static function isSource(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /** Nur die beiden Richtungen dispo -> hr und hr -> dispo. */
    public static function isRoute(string $source, string $target): bool
    {
        return (self::ROUTES[$source] ?? null) === $target;
    }

    /** $channelLabel: Beschriftung des Ziel-Kanals ("Dispo Köln"), ersetzt den Zielnamen. */
    public static function label(string $target, ?string $channelLabel = null): string
    {
        if ($channelLabel !== null && $channelLabel !== '') {
            return $channelLabel;
        }

        return self::TARGETS[$target] ?? $target;
    }

    public static function chipOpen(string $target, ?string $channelLabel = null): string
    {
        return 'bei ' . self::label($target, $channelLabel);
    }

    public static function chipDone(string $target, ?string $channelLabel = null): string
    {
        return self::label($target, $channelLabel) . ' erledigt';
    }

    public static function action(string $target): string
    {
        return 'An ' . self::label($target) . ' weiterleiten';
    }
}
