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
     * Log-Typ der Selbstbedienungs-Reaktion (RecApplicant::registerSelfServiceReaction,
     * z. B. Buchung ueber die oeffentliche Terminseite).
     */
    public const REACTION_TYPE = 'autopilot_reacted';

    /**
     * Die fremde Nachricht, die den Auto-Piloten JETZT noch bremst — 'Y-m-d H:i:s'
     * oder null.
     *
     * Wie lastOutboundAt(), mit einer Ausnahme (06.10.2026, Fall 4312): hat der
     * Bewerber NACH der fremden Nachricht selbst reagiert (gebucht), bremst sie
     * nicht mehr. Die Auto-Pilot-Nachricht danach ist die Antwort auf seine
     * Handlung, nicht eine zweite Nachricht hinter der Kampagne. Vorher hielt
     * die Ruhefrist z. B. die Onboarding-Vorlage nach einer Wartelisten-Buchung
     * 24 h zurueck — bei Nachbuchungen kurz vor der Schulung zu spaet.
     *
     * Bewusst eng: nur die Selbstbedienungs-Reaktion zaehlt, kein Phasenwechsel
     * durch HR und keine Reminder-Antwort. Reihenfolge ueber die Log-ID, nicht
     * die Uhrzeit — beide koennen in derselben Sekunde liegen. Eine neue fremde
     * Nachricht nach der Reaktion bremst wieder.
     */
    public static function blockingOutboundAt(int $applicantId): ?string
    {
        try {
            $fremd = RecAutoPilotLog::query()
                ->where('rec_applicant_id', $applicantId)
                ->whereIn('type', self::OUTBOUND_TYPES)
                ->orderByDesc('id')
                ->first(['id', 'created_at']);

            if ($fremd === null) {
                return null;
            }

            $reagiertDanach = RecAutoPilotLog::query()
                ->where('rec_applicant_id', $applicantId)
                ->where('type', self::REACTION_TYPE)
                ->where('id', '>', $fremd->id)
                ->exists();
        } catch (\Throwable) {
            return null;
        }

        if ($reagiertDanach) {
            return null;
        }

        $at = $fremd->created_at;

        return $at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s') : (string) $at;
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
