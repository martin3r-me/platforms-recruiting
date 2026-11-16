<?php

namespace Platform\Recruiting\Zeit;

use Carbon\CarbonImmutable;

/** Fachliche Grenzen und Pausen; der Kern bringt sie nicht selbst mit. null = keine berechenbare Grenze. */
interface ZeitRegeln
{
    public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable;

    public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable;

    public function pauseMinuten(int $anwesendMinuten): int;
}
