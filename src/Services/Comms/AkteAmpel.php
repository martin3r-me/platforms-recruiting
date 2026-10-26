<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Comms\ThemenTexte;

/**
 * Ampel im Chat-Kopf (Markus, HR allgemein, 26.10.2026): ist die Akte des
 * Mitarbeiters vollstaendig? Dieselbe Pruefung wie Portal-Start und
 * Lohn-Zweig (LohnLuecken). Bei Rot ein Entwurf fuer HR — HR liest und
 * schickt selbst, damit die Nachricht als menschliche Antwort zaehlt.
 */
class AkteAmpel
{
    public function __construct(private readonly ?LohnLuecken $luecken = null) {}

    /** @return array{gruen: bool, luecken: list<string>, nachweise: int} nachweise = Zahl offener Nachweise */
    public function fuer(RecEmployee $employee): array
    {
        $g = ($this->luecken ?? app(LohnLuecken::class))->getrennt($employee);
        $luecken = array_values(array_unique([...$g['nachweise'], ...$g['angaben']]));

        return ['gruen' => $luecken === [], 'luecken' => $luecken, 'nachweise' => count($g['nachweise'])];
    }

    /** @param list<string> $luecken */
    public static function entwurf(RecEmployee $employee, bool $duzen, ?string $teamText, array $luecken): string
    {
        $text = ThemenTexte::lohnOffen($duzen, $teamText, $luecken);
        $url = LohnLuecken::portalUrl($employee);

        return $url === null ? $text : $text . "\n\n" . $url;
    }
}
