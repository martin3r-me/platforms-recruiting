<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

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
 * WORAN EINE CODE-NACHRICHT ERKANNT WIRD — eins von drei Merkmalen reicht
 * (Spec 2026-10-12 §4; die fruehere Liste in der Config gibt es nicht mehr):
 *  1. STRUKTUR: der BODY der Vorlagen-Definition enthaelt {{code}}, Gross/
 *     Klein egal. Das trifft jede Code-Vorlage, auch eine umbenannte oder
 *     alte, solange ihre Definition noch da ist — und es haengt an keiner
 *     gepflegten Liste. Das ist der tragende Weg.
 *  2. EINSTELLUNG: der Name ist in irgendeinem Team in einem der drei
 *     Code-Felder gewaehlt (PortalVorlagen::CODE_SCHLUESSEL). Dieselbe
 *     Quelle, aus der der EinmalcodeSender liest.
 *  3. ALT-LISTE: der Name steht in KONFIG_ALT (abgelegte Namen, nie
 *     gekuerzt).
 */
final class EinmalcodeVorlagen
{
    /**
     * Der Konfigurationspfad der ABGELEGTEN Vorlagennamen — eine schlichte
     * Liste von Namen, die einmal Code-Vorlagen waren.
     *
     * WOZU (Fund N2, derselbe Gedanke wie bei der Schwaerzung aller Werte):
     * eine Nachricht von gestern wurde mit der Einstellung von gestern
     * verschickt. Tauscht HR die Code-Vorlage in den Einstellungen gegen eine
     * andere, faellt der alte Name aus Weg 2 heraus. Meist faengt ihn Weg 1
     * (die Definition traegt {{code}}) — aber nur, solange die Definition
     * noch existiert. Fuer den Rest steht der Name hier.
     *
     * DESHALB IST DIE LISTE NACH OBEN OFFEN UND WIRD NIE GEKUERZT. Der SENDER
     * liest sie nicht (er verschickt nur mit der eingestellten Vorlage), die
     * SCHWAERZUNG liest sie.
     */
    public const KONFIG_ALT = 'recruiting.konto.code_vorlagen_alt';

    /** Was statt des Codes in der Chat-Blase steht. */
    public const MASKE = '••••••';

    /**
     * Weg 2, gemerkt je Prozess (eine Anfrage, ein Chat): klein geschriebene
     * Namen der in irgendeinem Team gewaehlten Code-Vorlagen. null = noch
     * nicht geladen.
     *
     * @var list<string>|null
     */
    private static ?array $eingestellt = null;

    /**
     * ALLE bekannten Namen von Code-Vorlagen — die in den Team-Einstellungen
     * gewaehlten (Weg 2) und die abgelegten aus KONFIG_ALT (Weg 3), klein
     * geschrieben. Weg 1 (Struktur) braucht keinen Namen und steht deshalb
     * nicht hier.
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

        foreach (self::eingestellteNamen() as $name) {
            $merke($name);
        }

        // Abgelegte Namen stehen als blosse Zeichenketten da: zum Schwaerzen
        // braucht es nur den Namen, und je weniger dort zu pflegen ist, desto
        // eher wird es gepflegt.
        foreach ((array) config(self::KONFIG_ALT, []) as $alt) {
            if (is_string($alt)) {
                $merke($alt);
            }
        }

        return $namen;
    }

    /**
     * Traegt eine Nachricht mit dieser Vorlage einen Einmalcode?
     *
     * $definition ist das, was DispoThreadDirectory an
     * WhatsAppTemplateRenderer::render gibt: die components der Vorlage (Liste
     * von Teilen mit type/text) — als Array oder als JSON-Text, oder null,
     * wenn die Vorlage nicht (mehr) synchronisiert ist.
     *
     * Gross-/Kleinschreibung egal, beim Namen wie beim Platzhalter.
     */
    public static function istCodeVorlage(?string $vorlagenName, mixed $definition = null): bool
    {
        if (self::rumpfTraegtCode($definition)) {
            return true;
        }

        $name = strtolower(trim((string) $vorlagenName));

        return $name !== '' && in_array($name, self::namen(), true);
    }

    /** Fuer Tests: Weg 2 beim naechsten Aufruf neu laden. */
    public static function vergiss(): void
    {
        self::$eingestellt = null;
    }

    /** Weg 1: steht {{code}} (Gross/Klein egal) in einem BODY-Teil? */
    private static function rumpfTraegtCode(mixed $definition): bool
    {
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }
        if (!is_array($definition)) {
            return false;
        }

        foreach ($definition as $teil) {
            if (is_array($teil)
                && strtoupper((string) ($teil['type'] ?? '')) === 'BODY'
                && preg_match('/\{\{\s*code\s*\}\}/i', (string) ($teil['text'] ?? '')) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weg 2: Template-IDs aus den drei Code-Feldern ALLER Teams → Namen.
     *
     * Zwei Abfragen je Prozess (alle Einstellungszeilen, dann die Namen zu den
     * IDs). Der Chat darf daran NICHT kippen: fehlt das Integrations-Paket
     * oder eine Tabelle, oder wirft die Abfrage, gibt es hier keine Namen —
     * Weg 1 und Weg 3 greifen weiter. Ein Fehlschlag wird NICHT gemerkt,
     * damit ein voruebergehender Aussetzer nicht fuer den Rest eines
     * langlebigen Prozesses gilt.
     *
     * @return list<string>
     */
    private static function eingestellteNamen(): array
    {
        if (self::$eingestellt !== null) {
            return self::$eingestellt;
        }

        $vorlagen = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class;
        if (!class_exists($vorlagen)) {
            return self::$eingestellt = [];
        }

        try {
            $ids = [];
            foreach (RecApplicantSettings::query()->toBase()->pluck('settings') as $settings) {
                if (is_string($settings)) {
                    $settings = json_decode($settings, true);
                }
                if (!is_array($settings)) {
                    continue;
                }
                foreach (PortalVorlagen::CODE_SCHLUESSEL as $schluessel) {
                    $id = $settings[$schluessel] ?? null;
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[(int) $id] = true;
                    }
                }
            }

            $namen = $ids === []
                ? []
                : $vorlagen::query()->whereIn('id', array_keys($ids))->pluck('name')
                    ->map(static fn ($n) => strtolower(trim((string) $n)))
                    ->filter(static fn (string $n) => $n !== '')
                    ->unique()->values()->all();
        } catch (\Throwable) {
            return [];
        }

        return self::$eingestellt = $namen;
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
