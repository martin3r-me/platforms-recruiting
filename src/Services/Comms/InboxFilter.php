<?php

namespace Platform\Recruiting\Services\Comms;

/** Filterzustand der neuen Liste. Reines DTO. */
final class InboxFilter
{
    public function __construct(
        /** all | unread | green | yellow | red | missed */
        public readonly string $level = 'all',
        /** all | mine | <userId> */
        public readonly string $owner = 'all',
        public readonly string $search = '',
        /** fuer owner = 'mine' */
        public readonly ?int $currentUserId = null,
        /** all | applicants | employees — Mitarbeiter = Mitarbeiter-Thread ODER Bewerbung mit aktivem Mitarbeiter */
        public readonly string $kind = 'all',
        /**
         * all | ohne | <Filial-Block, z. B. '200'> — Hunderterblock der Kostenstelle
         * (KostenstellenBloecke). Wirkt anders als owner/search AUCH auf die
         * Zaehler (Kunde 16.10.2026: Pillen zeigen die gewaehlte Filiale).
         */
        public readonly string $filiale = 'all',
    ) {}
}
