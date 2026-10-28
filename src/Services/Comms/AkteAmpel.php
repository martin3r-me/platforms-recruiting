<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Comms\ThemenTexte;
use Platform\Recruiting\Support\PilotKreis;

/**
 * Ampel im Chat-Kopf (Markus, HR allgemein, 26.10.2026): ist die Akte des
 * Mitarbeiters vollstaendig? Dieselbe Pruefung wie Portal-Start und
 * Lohn-Zweig (LohnLuecken). Bei Rot ein Entwurf fuer HR — HR liest und
 * schickt selbst, damit die Nachricht als menschliche Antwort zaehlt.
 */
class AkteAmpel
{
    public function __construct(private readonly ?LohnLuecken $luecken = null) {}

    /**
     * nachweise = Zahl offener Nachweise; agenda = Zugang zum Agenda-Lohnportal
     * (Markus 28.10.2026), null = unbekannt.
     *
     * @return array{gruen: bool, luecken: list<string>, nachweise: int, agenda: ?bool}
     */
    public function fuer(RecEmployee $employee): array
    {
        $g = ($this->luecken ?? app(LohnLuecken::class))->getrennt($employee);
        $luecken = array_values(array_unique([...$g['nachweise'], ...$g['angaben']]));
        $agenda = $employee->agenda_zugang;

        return ['gruen' => $luecken === [], 'luecken' => $luecken, 'nachweise' => count($g['nachweise']), 'agenda' => $agenda === null ? null : (bool) $agenda];
    }

    /**
     * Neue Features erst im Pilot (Sebastian 19.10./26.10.2026): solange die
     * Sperre der Themen-Auswahl („nur Pilot“) an ist, nur fuer Pilot-MA.
     * Sonst stuende bei Leuten im alten Portal Rot fuer Nachweise, die sie
     * dort gar nicht nachreichen koennen. Ausrollen = Sperre aus.
     *
     * @param array<string, mixed> $settings rohe Team-Einstellungen
     */
    public static function zeigen(RecEmployee $employee, array $settings): bool
    {
        if (!PilotKreis::sperreAn($settings['comms_topic_nur_pilot'] ?? null)) {
            return true;
        }

        return PilotKreis::istImPilot((int) $employee->id);
    }

    /** @param list<string> $luecken */
    public static function entwurf(RecEmployee $employee, bool $duzen, ?string $teamText, array $luecken): string
    {
        $text = ThemenTexte::lohnOffen($duzen, $teamText, $luecken);
        $url = LohnLuecken::portalUrl($employee);

        return $url === null ? $text : $text . "\n\n" . $url;
    }
}
