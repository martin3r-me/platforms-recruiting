<?php

namespace Platform\Recruiting\Support;

/**
 * "12 von 14" fuer den HR-Ueberblick (Spec §8). Zurueckgezogene Zustellungen
 * stehen nicht im Nenner, werden aber gezaehlt.
 */
final class DokumentFortschritt
{
    /**
     * @param  list<array{withdrawn_at:?string, signed_at:?string, acknowledged_at:?string, first_viewed_at:?string}> $zeitstempelListe
     * @return array{erledigt:int, gesamt:int, zurueckgezogen:int, text:string}
     */
    public static function fuer(array $zeitstempelListe, string $aktion): array
    {
        $erledigt = 0;
        $gesamt = 0;
        $zurueck = 0;

        foreach ($zeitstempelListe as $z) {
            if (($z['withdrawn_at'] ?? null) !== null) {
                $zurueck++;
                continue;
            }
            $gesamt++;
            $fertig = $aktion === DokumentKategorie::AKTION_NONE
                ? ($z['first_viewed_at'] ?? null) !== null
                : DokumentStatus::istErledigt($z, $aktion);
            if ($fertig) {
                $erledigt++;
            }
        }

        $wort = match ($aktion) {
            DokumentKategorie::AKTION_SIGN        => 'unterschrieben',
            DokumentKategorie::AKTION_ACKNOWLEDGE => 'bestätigt',
            default                               => 'gesehen',
        };

        return [
            'erledigt'       => $erledigt,
            'gesamt'         => $gesamt,
            'zurueckgezogen' => $zurueck,
            'text'           => "{$erledigt} von {$gesamt} {$wort}",
        ];
    }
}
