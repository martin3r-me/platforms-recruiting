<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Gegenstueck zum ApplicantThreadLinker fuer Mitarbeiter: haengt einen
 * WhatsApp-Thread an einen RecEmployee und befoerdert die Legacy-Spalten,
 * wenn sie nur auf dem nackten CrmContact stehen (sonst zeigt die
 * Kommunikationsseite den Chat nicht als Mitarbeiter-Chat an).
 *
 * Danach blockt ThreadContextGate jede weitere Nachricht dieses Threads
 * fuer den Bewerber-Eingang — der Mitarbeiter wird nicht pro Nachricht neu
 * gesucht.
 *
 * RecEmployee steht nicht in der Morph-Map; getMorphClass() liefert die volle
 * Klasse, genau das, was InboxQuery::isEmployeeContext() erwartet.
 */
final class EmployeeThreadLinker
{
    public static function link(CommsWhatsAppThread $thread, int $employeeId, string $source): void
    {
        $morph = (new RecEmployee)->getMorphClass();

        $thread->addContext($morph, $employeeId, $source);

        if (ThreadContextGate::isBareContactContext($thread->context_model)) {
            $thread->updateQuietly([
                'context_model' => $morph,
                'context_model_id' => $employeeId,
            ]);
        }
    }
}
