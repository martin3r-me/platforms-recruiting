<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecAutoPilotLog;

/**
 * Ruhefrist zwischen zwei ausgehenden Nachrichten an denselben Bewerber.
 *
 * Vorfall 15.09.2026 (Kampagne „Neue Termine"): der Versand schliesst den
 * offenen Ort-Wartelisten-Eintrag (SendNewDatesCampaign::closeOrtWaitlist) und
 * loest damit die Bremse, die den Auto-Piloten pausiert hatte
 * (ProcessAutoPilotApplicants:171). Weil beim Versand bewusst KEIN Re-Arm
 * passiert (Kundenentscheid 28.08.), bleibt auto_pilot_last_reminder_at auf
 * dem alten, laengst faelligen Stand — die Erinnerung „wir warten noch auf
 * deine Rueckmeldung" ging deshalb Sekunden hinter die Kampagnen-Nachricht
 * raus, die sie meinte. 174 von 355 Empfaengern des Buchungs-Templates traf
 * es, 171 davon binnen 8-30 Sekunden.
 *
 * Der Waechter haengt bewusst NICHT am Auto-Pilot-Zaehler, sondern an der
 * Frage „wann ging zuletzt etwas von einem FREMDEN Sender an diese Person
 * raus?". Damit federt er genau die Faelle ab, die der Zaehler nie sehen
 * wuerde: eine Wartelisten-Benachrichtigung (int_available) oder eine Kampagne
 * kurz vor Erstkontakt oder Erinnerung. Die Wahrheit steht im Auto-Pilot-Log,
 * in das alle Sender ohnehin schreiben — kein neuer Zustand, keine Migration.
 *
 * Arbeitsteilung mit dem Erinnerungs-Intervall
 * (auto_pilot_reminder_interval_hours): das taktet die Kette des Auto-Piloten
 * mit sich selbst und bleibt dafuer allein zustaendig. Der Cooldown schaut nur
 * auf fremde Sender — siehe OUTBOUND_TYPES, warum die eigenen Sendungen
 * absichtlich nicht mitzaehlen.
 */
final class MessageCooldown
{
    /** Einstellung (Team → Position → Phase), Stunden. 0 = Waechter aus. */
    public const SETTING_KEY = 'auto_pilot_message_cooldown_hours';

    /**
     * Log-Typen FREMDER Sender, die eine tatsaechlich rausgegangene Nachricht
     * bezeugen:
     *
     *  - campaign_sent                Kampagne „Neue Termine", Sammelversand
     *                                 ohne Einsatz
     *  - waitlist_slot_available_sent Ort-Warteliste: „ein Termin ist frei"
     *  - waitlist_termin_sent         Termin-Warteliste (Dauerabo)
     *
     * Bewusst NICHT dabei: `template_sent` und `reminder_sent`, die eigenen
     * Sendungen des Auto-Piloten. Seine Taktung regelt
     * auto_pilot_reminder_interval_hours, pro Stelle auf 1-168 h einstellbar
     * (Position/Show.php:92). Zaehlte der Cooldown sie mit, wuerde eine Stelle
     * mit 12-Stunden-Takt von der 24-Stunden-Ruhefrist ueberstimmt — der
     * Waechter wuerde bremsen, wo gar kein fremder Sender im Spiel ist.
     *
     * Ebenfalls bewusst eine feste Liste statt „alles ausser silent": ein
     * neuer Log-Typ soll den Auto-Piloten nicht aus Versehen stilllegen,
     * sondern hier eingetragen werden.
     */
    public const OUTBOUND_TYPES = [
        'campaign_sent',
        'waitlist_slot_available_sent',
        'waitlist_termin_sent',
    ];

    /**
     * Reine Entscheidung ohne Framework (Muster CampaignSegment): Zeitpunkte
     * als 'Y-m-d H:i:s'-Strings, damit der Aufrufer die Zeitzone besitzt.
     *
     * @param string|null $lastOutboundAt letzte ausgehende Nachricht, null = noch nie
     * @param int         $cooldownHours  0 oder kleiner schaltet den Waechter ab
     */
    public static function blocks(?string $lastOutboundAt, int $cooldownHours, string $now): bool
    {
        if ($lastOutboundAt === null || $cooldownHours <= 0) {
            return false;
        }

        $frei = (new \DateTimeImmutable($lastOutboundAt))->modify('+' . $cooldownHours . ' hours');

        return new \DateTimeImmutable($now) < $frei;
    }

    /**
     * Juengster Zeitpunkt, zu dem nachweislich etwas an diesen Bewerber
     * rausging — 'Y-m-d H:i:s' oder null.
     *
     * Lesefehler geben null zurueck: ein kaputtes Log darf den Auto-Piloten
     * nicht stilllegen (Muster logAutoPilot()). Im Zweifel wird gesendet, denn
     * die Alternative waere ein Bewerber, der nie wieder etwas hoert.
     */
    public static function lastOutboundAt(int $applicantId): ?string
    {
        try {
            $letzte = RecAutoPilotLog::query()
                ->where('rec_applicant_id', $applicantId)
                ->whereIn('type', self::OUTBOUND_TYPES)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('created_at');
        } catch (\Throwable) {
            return null;
        }

        if ($letzte === null) {
            return null;
        }

        return $letzte instanceof \DateTimeInterface
            ? $letzte->format('Y-m-d H:i:s')
            : (string) $letzte;
    }
}
