<?php

namespace Platform\Recruiting\Support;

/**
 * Ergebnis einer HR-Aktion fuer die Oberflaeche: gruen (ok), gelb (hinweis:
 * nichts kaputt, aber auch keine Wirkung — schon erledigt, Pause, Vorlage
 * fehlt) oder rot (fehler: abgewiesen). Der Einladungs-Klartext reist NUR
 * hier mit und nirgends sonst hin (Spec §4.3).
 */
final class HrAktionErgebnis
{
    public const OK = 'ok';
    public const HINWEIS = 'hinweis';
    public const FEHLER = 'fehler';

    private function __construct(
        public readonly string $stufe,
        public readonly string $meldung,
        public readonly ?string $einladungsLink = null,
        public readonly ?string $einladungsCode = null,
    ) {
    }

    public static function ok(string $meldung): self
    {
        return new self(self::OK, $meldung);
    }

    public static function hinweis(string $meldung): self
    {
        return new self(self::HINWEIS, $meldung);
    }

    public static function fehler(string $meldung): self
    {
        return new self(self::FEHLER, $meldung);
    }

    public static function einladung(string $meldung, string $link, string $code): self
    {
        return new self(self::OK, $meldung, $link, $code);
    }

    public function istOk(): bool
    {
        return $this->stufe === self::OK;
    }
}
