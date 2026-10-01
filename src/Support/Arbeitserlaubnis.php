<?php

namespace Platform\Recruiting\Support;

/**
 * Darf dieser Mensch arbeiten?
 *
 * ABGELEITET, NIE GESPEICHERT (Spec 2.6). Ein gespeicherter Zustand veraltet
 * in dem Augenblick, in dem ein Dokument ablaeuft — und genau dann waere er
 * falsch.
 *
 * Nur fehlend oder abgelaufen sperrt. "Laeuft ab" sperrt NICHT: sonst sperrt
 * die Vorlaufzeit von sechzig Tagen Menschen, die alles richtig gemacht
 * haben.
 *
 * ACHTUNG, Reichweite: eine Sperre hier ist heute eine Sperre UNSERES
 * PORTALS. Wir koennen niemanden aus der Disposition nehmen — der Rueckkanal
 * zu ZAS traegt nur Bestaetigungen (Spec 5). Bis der Export erweitert ist,
 * muss ein Mensch handeln; deshalb der HR-Fall.
 */
final class Arbeitserlaubnis
{
    private const SPERRENDE_ZUSTAENDE = [ProofChecklist::FEHLT, ProofChecklist::ABGELAUFEN];

    /** @param list<array{code:string, status:string}> $checkliste */
    public static function istGesperrt(array $checkliste): bool
    {
        return self::gruende($checkliste) !== [];
    }

    /**
     * @param list<array{code:string, status:string}> $checkliste
     * @return list<string> die betroffenen Codes, in der Reihenfolge der Checkliste
     */
    public static function gruende(array $checkliste): array
    {
        $gruende = [];

        foreach ($checkliste as $zeile) {
            if (ProofTypes::istKo($zeile['code'] ?? '')
                && in_array($zeile['status'] ?? '', self::SPERRENDE_ZUSTAENDE, true)) {
                $gruende[] = $zeile['code'];
            }
        }

        return $gruende;
    }
}
