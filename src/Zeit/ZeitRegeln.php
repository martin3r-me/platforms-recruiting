<?php

namespace Platform\Recruiting\Zeit;

use Carbon\CarbonImmutable;

/** Fachliche Grenzen und Pausen; der Kern bringt sie nicht selbst mit. null = keine berechenbare Grenze. */
interface ZeitRegeln
{
    public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable;

    /** Selbst-Kommen spaetestens bis hier; null = keine eigene Grenze (dann gilt nur spaetestesStempeln). */
    public function spaetestesKommen(ZeitBezug $b): ?CarbonImmutable;

    /** Selbst-Gehen (und damit jedes Selbst-Stempeln) spaetestens bis hier. */
    public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable;

    /**
     * Rundung jedes gespeicherten Kommen/Gehen (Stempel wie Korrektur). Die Fenster-Pruefung laeuft
     * VORHER an der echten Uhrzeit; die echte Uhrzeit eines Stempels bleibt als Zeitpunkt der Verlaufszeile erhalten.
     */
    public function runde(\DateTimeInterface $zeit): CarbonImmutable;

    public function pauseMinuten(int $anwesendMinuten): int;
}
