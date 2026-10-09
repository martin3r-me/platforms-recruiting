<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsWhatsAppMessage;

/**
 * Wem haben wir an welchem Tag die Vorlage „Wo bist du?" geschickt (Kunde
 * 09.10.)? Die VA faerbt damit die Sprechblase der Zeile dieses Tages ein —
 * „den haben wir schon gesucht". Nur Anzeige.
 *
 * Gezaehlt werden ausgehende Nachrichten mit diesem Template-Namen in den
 * Gespraechen der Person, die Meta nicht abgelehnt hat. Der Tag ist der
 * Versandtag (created_at, App-Zeit), nicht der Einsatztag der Einbuchung.
 */
final class DispoChaseMarks
{
    /** Meta-Name der Vorlage „Wo bist du?" aus den festen Chat-Vorlagen, null wenn nicht konfiguriert. */
    public static function templateName(): ?string
    {
        foreach (DispoChatTemplateSender::options() as $option) {
            if ($option['key'] === 'wo') {
                return $option['template'];
            }
        }

        return null;
    }

    /**
     * @param array<int, list<int>> $threadIdsByPerson kanonische id => Gespraechs-ids
     * @return array<int, array<string, string>> kanonische id => [Y-m-d => H:i der letzten Sendung des Tages]
     */
    public static function forPersons(array $threadIdsByPerson, string $templateName, string $from, string $to): array
    {
        $personByThread = [];
        foreach ($threadIdsByPerson as $person => $threadIds) {
            foreach ($threadIds as $threadId) {
                $personByThread[(int) $threadId] = (int) $person;
            }
        }
        if ($personByThread === []) {
            return [];
        }

        $rows = CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', array_keys($personByThread))
            ->where('direction', 'outbound')
            ->where('template_name', $templateName)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'failed'))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get(['comms_whatsapp_thread_id', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $person = $personByThread[(int) $row->comms_whatsapp_thread_id];
            // aufsteigend sortiert: die letzte Sendung des Tages gewinnt
            $out[$person][$row->created_at->format('Y-m-d')] = $row->created_at->format('H:i');
        }

        return $out;
    }
}
