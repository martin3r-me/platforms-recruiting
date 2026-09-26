<?php

namespace Platform\Recruiting\Support;

/**
 * Die eine Zeile unter dem Gruppennamen (.grouprow .v im Entwurf
 * resources/mockups/crew-portal.html).
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PortalGroupSummary
{
    private const MAX = 80;

    /**
     * Der Satz, wenn eine Gruppe NICHTS Anzeigbares hat -- privat, weil
     * niemand ausserhalb dieser Klasse den Wortlaut selbst nachbauen oder
     * dagegen vergleichen soll (Fixrunde 1 zu Aufgabe 7: PortalShell verglich
     * bislang per Zeichenkette gegen genau diesen Satz -- eine geratene
     * Kopplung, die still auseinanderfaellt, sobald hier jemand den Wortlaut
     * aendert). istLeer() ist der EINE Weg, die Frage zu stellen.
     */
    private const LEER = 'Noch nichts hinterlegt';

    /**
     * @param array<string,array<string,mixed>> $felder
     * @param array<string,string> $anzeigewerte bereits formatierte Werte
     */
    public static function zeile(array $felder, array $anzeigewerte): string
    {
        $teile = [];
        foreach (array_keys($felder) as $schluessel) {
            $wert = trim((string) ($anzeigewerte[$schluessel] ?? ''));
            if ($wert !== '') {
                $teile[] = $wert;
            }
        }

        if ($teile === []) {
            return self::LEER;
        }

        $zeile = implode(' · ', $teile);

        return mb_strlen($zeile) > self::MAX
            ? mb_substr($zeile, 0, self::MAX - 1) . '…'
            : $zeile;
    }

    /**
     * Ist diese Zeile die Leer-Meldung? Aufrufer, die auf den Sonderfall
     * "gar nichts Anzeigbares" reagieren muessen (z. B. PortalShell::
     * profilDaten(), Auflage 3 -- eine Datei-only-Gruppe mit belegten
     * Datei-Werten soll nicht "Noch nichts hinterlegt" sagen), fragen
     * darueber statt den Wortlaut selbst zu vergleichen.
     */
    public static function istLeer(string $zeile): bool
    {
        return $zeile === self::LEER;
    }
}
