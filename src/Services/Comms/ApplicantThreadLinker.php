<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;

/**
 * EINE Stelle, die einen WhatsApp-Thread an einen Bewerber hängt.
 *
 * addContext() im CRM schreibt die Legacy-Spalten nur, wenn sie leer sind
 * ("first context wins"). Hängt der Thread noch am nackten CrmContact
 * (Contact-as-Context des CRM), muss der Bewerber aktiv befördert werden,
 * sonst bleibt der Chat für Kommunikations-Übersicht & Nachrichten-Spalte
 * (beide lesen die Legacy-Spalten) unsichtbar. Jeder Recruiting-Pfad, der
 * Threads an Bewerber hängt, MUSS über diesen Helper gehen — Promotion an
 * einzelnen Call-Sites zu wiederholen ist exakt die Bug-Klasse, die der
 * Kontext-Gate-Fix behebt.
 */
final class ApplicantThreadLinker
{
    public static function link(CommsWhatsAppThread $thread, int $applicantId, string $source): void
    {
        $morph = (new RecApplicant)->getMorphClass();

        $thread->addContext($morph, $applicantId, $source);

        if (ThreadContextGate::isBareContactContext($thread->context_model)) {
            $thread->updateQuietly([
                'context_model' => $morph,
                'context_model_id' => $applicantId,
            ]);
        }
    }
}
