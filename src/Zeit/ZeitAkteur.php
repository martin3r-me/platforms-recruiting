<?php

namespace Platform\Recruiting\Zeit;

/** Wer handelt (Mitarbeiter, Dispo-User, System). Der Name ist ein Schnappschuss fuer den Verlauf. */
final class ZeitAkteur
{
    public function __construct(
        public readonly string $typ,
        public readonly ?int $id,
        public readonly string $name,
    ) {
    }
}
