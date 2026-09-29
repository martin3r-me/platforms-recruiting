<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Meta-Vorlagen tragen einen Einmalcode — und wie sieht eine solche
 * Nachricht aus, wenn sie NICHT der Mensch am Handy liest, sondern jemand im
 * Chat unter /recruiting/conversations?
 *
 * WARUM ES DIESE KLASSE GIBT. Der Sender schickt den Code als
 * Vorlagen-Parameter; WhatsAppMetaService::handleSendResponse() legt diese
 * Parameter als comms_whatsapp_messages.template_params ab, und
 * DispoThreadDirectory::messages() setzt sie ueber WhatsAppTemplateRenderer
 * wieder in den Vorlagentext ein (Kunde 23.09.: der Chat soll zeigen, was der
 * Mitarbeiter gelesen hat). Ohne diese Klasse stuende dort dauerhaft "Dein
 * Code lautet 123456" — fuer jeden, der die Unterhaltung sehen darf. Der
 * Einmalcode waere dann kein Einmalcode mehr, sondern ein Eintrag in einem
 * Verlauf.
 *
 * Was bleibt: DASS ein Code rausging und WANN — die Blase, der Zeitstempel,
 * der Zustellstatus. Was geht: WAS drinstand. Das gehoert dem Menschen mit
 * dem Handy in der Hand und niemandem sonst.
 *
 * ALLE WERTE WERDEN GESCHWAERZT, nicht nur der mit dem Namen "code". Zwei
 * Gruende, und beide sind Erfahrung, nicht Vorsicht:
 *  - Die Parameter einer ALTEN Nachricht wurden mit einer alten Konfiguration
 *    verschickt. Eine Regel, die nur den heute so benannten Platzhalter
 *    trifft, liesse den Code jeder Nachricht stehen, die vor einer Umbenennung
 *    rausging.
 *  - Die uebrigen Werte einer Code-Vorlage sind Vorname und Gueltigkeitsdauer.
 *    Beides steht ohnehin daneben in der Akte; sie mitzuschwaerzen kostet
 *    nichts und macht die Regel unabhaengig von der Reihenfolge.
 *
 * Die Kopplung an die KONFIGURATION ist Absicht: dieselbe Liste, aus der der
 * Sender seine Vorlagennamen nimmt, entscheidet hier ueber die Schwaerzung.
 * Eine zweite, gepflegte Liste waere genau die Stelle, an der jemand eine
 * neue Code-Vorlage eintraegt und die Schwaerzung vergisst.
 */
final class EinmalcodeVorlagen
{
    /** Der Konfigurationspfad — EINE Schreibweise, vom Sender und von hier benutzt. */
    public const KONFIG = 'recruiting.konto.code_vorlagen';

    /** Was statt des Codes in der Chat-Blase steht. */
    public const MASKE = '••••••';

    /**
     * Die konfigurierten Meta-Vorlagennamen, klein geschrieben.
     *
     * @return list<string>
     */
    public static function namen(): array
    {
        $namen = [];

        foreach ((array) config(self::KONFIG, []) as $eintrag) {
            $name = strtolower(trim((string) ($eintrag['name'] ?? '')));
            if ($name !== '' && !in_array($name, $namen, true)) {
                $namen[] = $name;
            }
        }

        return $namen;
    }

    /**
     * Traegt eine Nachricht mit diesem Vorlagennamen einen Einmalcode?
     *
     * Gross-/Kleinschreibung egal: Meta-Namen sind klein, aber ein
     * .env-Eintrag wird von Hand getippt.
     */
    public static function istCodeVorlage(?string $vorlagenName): bool
    {
        $name = strtolower(trim((string) $vorlagenName));

        return $name !== '' && in_array($name, self::namen(), true);
    }

    /**
     * Dieselben gesendeten Parameter, aber mit geschwaerzten Werten — die
     * Struktur bleibt, damit WhatsAppTemplateRenderer weiterhin jeden
     * Platzhalter trifft und die Blase den vollstaendigen Satz zeigt.
     *
     * @param  mixed  $sentParams  comms_whatsapp_messages.template_params
     * @return list<array<string, mixed>>
     */
    public static function geschwaerzt(mixed $sentParams): array
    {
        if (is_string($sentParams)) {
            $sentParams = json_decode($sentParams, true);
        }
        if (!is_array($sentParams)) {
            return [];
        }

        $out = [];
        foreach ($sentParams as $component) {
            if (!is_array($component)) {
                continue;
            }

            $parameter = [];
            foreach ((array) ($component['parameters'] ?? []) as $einzeln) {
                if (!is_array($einzeln)) {
                    continue;
                }
                // Nur der TEXT faellt. parameter_name bleibt stehen, sonst
                // faende der Renderer den benannten Platzhalter nicht mehr
                // und liesse stattdessen "{{code}}" im Satz stehen.
                if (array_key_exists('text', $einzeln)) {
                    $einzeln['text'] = self::MASKE;
                }
                $parameter[] = $einzeln;
            }

            $component['parameters'] = $parameter;
            $out[] = $component;
        }

        return $out;
    }
}
