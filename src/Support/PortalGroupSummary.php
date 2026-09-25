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
            return 'Noch nichts hinterlegt';
        }

        $zeile = implode(' · ', $teile);

        return mb_strlen($zeile) > self::MAX
            ? mb_substr($zeile, 0, self::MAX - 1) . '…'
            : $zeile;
    }
}
