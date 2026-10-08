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

        return self::text($erledigt, $gesamt, $zurueck, $aktion);
    }

    /**
     * Dasselbe aus den Zaehlern von DokumentZaehler::fuer() (eine gruppierte
     * Abfrage statt geladener Zeilen). "Erledigt" je Aktion wie oben: none =
     * gesehen, acknowledge = bestaetigt, sign = unterschrieben.
     *
     * @param  array{gesamt:int, zurueckgezogen:int, unterschrieben:int, bestaetigt:int, gesehen:int} $z
     * @return array{erledigt:int, gesamt:int, zurueckgezogen:int, text:string}
     */
    public static function ausZaehlern(array $z, string $aktion): array
    {
        $erledigt = match ($aktion) {
            DokumentKategorie::AKTION_SIGN        => (int) ($z['unterschrieben'] ?? 0),
            DokumentKategorie::AKTION_ACKNOWLEDGE => (int) ($z['bestaetigt'] ?? 0),
            DokumentKategorie::AKTION_NONE        => (int) ($z['gesehen'] ?? 0),
            default                               => (int) ($z['gesamt'] ?? 0) - (int) ($z['zurueckgezogen'] ?? 0),
        };
        $zurueck = (int) ($z['zurueckgezogen'] ?? 0);

        return self::text($erledigt, (int) ($z['gesamt'] ?? 0) - $zurueck, $zurueck, $aktion);
    }

    /** @return array{erledigt:int, gesamt:int, zurueckgezogen:int, text:string} */
    private static function text(int $erledigt, int $gesamt, int $zurueck, string $aktion): array
    {
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
