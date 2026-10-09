<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Recruiting\Models\RecDispoFilialeSettings;

/**
 * Billige Vorpruefung im Listener (Spec 2026-10-08, Entscheidung 3): nur
 * eingehende Nachrichten mit Text, und nur solange mindestens eine Filiale
 * die Erkennung heute eingeschaltet hat. Alles Weitere entscheidet der Job.
 */
final class DispoDeclineCheckGate
{
    public static function shouldQueue(CommsWhatsAppMessage $message): bool
    {
        if ($message->direction !== 'inbound' || trim((string) $message->body) === '') {
            return false;
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        return $teamId > 0 && RecDispoFilialeSettings::query()
            ->where('team_id', $teamId)
            ->declineCheckActiveAt(\Illuminate\Support\Carbon::parse($message->created_at ?? now()))
            ->exists();
    }
}
