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
    /**
     * Der Konfigurationspfad der AKTUELLEN Vorlagen.
     *
     * EINE Schreibweise: EinmalcodeSender::vorlage() bildet seinen Pfad aus
     * genau dieser Konstante (Fund N1). Ein eigener Literal dort waere die
     * Stelle, an der die beiden auseinanderlaufen — und dann sendete der
     * Sender weiter, waehrend namen() ins Leere liest und der Code wieder
     * unmaskiert im Chat stuende.
     */
    public const KONFIG = 'recruiting.konto.code_vorlagen';

    /**
     * Der Konfigurationspfad der ABGELEGTEN Vorlagennamen — eine schlichte
     * Liste von Namen, die einmal Code-Vorlagen waren.
     *
     * WOZU (Fund N2, derselbe Gedanke wie bei der Schwaerzung aller Werte):
     * eine Nachricht von gestern wurde mit der Konfiguration von gestern
     * verschickt. Heisst die Vorlage eines Tages konto_einmalcode_v2, faellt
     * der alte Name aus KONFIG heraus — und JEDE alte Nachricht stuende
     * wieder unmaskiert im Verlauf. Nichts wuerde rot, niemand merkte es.
     *
     * DESHALB IST DIE LISTE NACH OBEN OFFEN UND WIRD NIE GEKUERZT. Der SENDER
     * liest sie nicht (er verschickt nur mit den aktuellen Namen), die
     * SCHWAERZUNG liest beide.
     */
    public const KONFIG_ALT = 'recruiting.konto.code_vorlagen_alt';

    /** Was statt des Codes in der Chat-Blase steht. */
    public const MASKE = '••••••';

    /**
     * ALLE Vorlagennamen, die je einen Code getragen haben — die aktuellen
     * aus KONFIG und die abgelegten aus KONFIG_ALT, klein geschrieben.
     *
     * Bewusst beide: der Verlauf reicht weiter zurueck als die heutige
     * Konfiguration (Fund N2).
     *
     * @return list<string>
     */
    public static function namen(): array
    {
        $namen = [];
        $merke = static function (string $name) use (&$namen): void {
            $name = strtolower(trim($name));
            if ($name !== '' && !in_array($name, $namen, true)) {
                $namen[] = $name;
            }
        };

        foreach ((array) config(self::KONFIG, []) as $eintrag) {
            $merke((string) ($eintrag['name'] ?? ''));
        }

        // Abgelegte Namen stehen als blosse Zeichenketten da, nicht als
        // Eintraege mit Platzhaltern: zum Schwaerzen braucht es nur den Namen,
        // und je weniger dort zu pflegen ist, desto eher wird es gepflegt.
        foreach ((array) config(self::KONFIG_ALT, []) as $alt) {
            if (is_string($alt)) {
                $merke($alt);
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
