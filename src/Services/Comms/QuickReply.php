<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * War diese eingehende Nachricht ein Klick auf einen vorgefertigten Button?
 *
 * WhatsAppMetaService (platform-crm) normalisiert Vorlagen-Buttons ('button')
 * und interaktive Antworten ('interactive') beim Speichern zu message_type
 * 'text' und legt den Button-Text als body ab. Ein geklicktes „Ja“ und ein
 * getipptes „Ja“ sehen danach gleich aus — unterscheidbar nur am Original-Typ
 * im meta_payload, den der Core unveraendert mitspeichert.
 *
 * Reine Funktion ohne Laravel, unit-testbar wie ConversationEscalation.
 */
final class QuickReply
{
    /** Interaktive Antworten, die eine vorgegebene Auswahl sind — nicht nfm_reply (Formular). */
    private const INTERACTIVE_TYPES = ['button_reply', 'list_reply'];

    public static function isQuickReply(?array $metaPayload): bool
    {
        $type = $metaPayload['type'] ?? null;

        if ($type === 'button') {
            return true;
        }

        return $type === 'interactive'
            && in_array($metaPayload['interactive']['type'] ?? null, self::INTERACTIVE_TYPES, true);
    }
}
