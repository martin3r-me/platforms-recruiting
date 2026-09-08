<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

/**
 * Dokumentslots, die ZAS bei uns BESCHREIBEN darf.
 *
 * Bewusst eine eigene, kurze Liste und nicht die 16 Slots, die wir ausliefern:
 * der Datei-Eingang ist ein schreibender Endpunkt mit geteiltem Token. Offen
 * ist nur, was ausdruecklich gebraucht wird — die Selfies der ~1100
 * Bestands-Mitarbeiter, fuer die es keine andere Quelle als ZAS gibt.
 *
 * Erweitern ist eine Zeile, falls spaeter Ausweise oder Versichertenkarten
 * dazukommen sollen. Die Zielspalte kommt aus derselben Tabelle, die der
 * Ausliefer-Endpunkt liest — Eingang und Ausgang sprechen dieselbe Sprache.
 */
final class ZasInboundFileSlots
{
    /** @var list<string> */
    public const ALLOWED = ['emp-selfie'];

    /**
     * Zielspalte auf rec_employees, oder null wenn der Slot nicht offen ist.
     */
    public static function columnFor(string $slot): ?string
    {
        if (!in_array($slot, self::ALLOWED, true)) {
            return null;
        }

        return ZasEmployeeFieldResolver::FILE_SLOT_FIELD_MAP[$slot] ?? null;
    }
}
