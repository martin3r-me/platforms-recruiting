<?php

namespace Platform\Recruiting\Support;

/**
 * Der Vollstaendigkeitsring aus dem Entwurf (.ring-row in
 * resources/mockups/crew-portal.html). Gezaehlt werden nur nach R28
 * RELEVANTE Felder — sonst staende bei jedem, den eine bedingte Pflicht
 * gar nicht betrifft (z.B. Nicht-Ersthelfer), dauerhaft ein unerfuellbarer
 * Rest, obwohl fuer ihn alles vollstaendig ist.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PortalCompleteness
{
    /**
     * @param array<string,array<string,mixed>> $felder  sichtbare Felder (flach)
     * @param array<string,mixed> $datensatz             gecastete Attributwerte
     * @return array{prozent:int, gesamt:int, gefuellt:int, fehlend:list<string>}
     */
    public static function stand(array $felder, array $datensatz): array
    {
        $gesamt = 0;
        $gefuellt = 0;
        $fehlend = [];

        foreach ($felder as $schluessel => $meta) {
            if (!PortalFieldRelevance::istRelevant($meta, $datensatz)) {
                continue;
            }
            $gesamt++;
            $wert = $datensatz[$schluessel] ?? null;
            if ($wert === null || $wert === '' || $wert === []) {
                $fehlend[] = (string) ($meta['label'] ?? $schluessel);
                continue;
            }
            $gefuellt++;
        }

        return [
            'prozent'  => $gesamt === 0 ? 100 : (int) round($gefuellt / $gesamt * 100),
            'gesamt'   => $gesamt,
            'gefuellt' => $gefuellt,
            'fehlend'  => $fehlend,
        ];
    }
}
