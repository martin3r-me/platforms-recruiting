<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die WhatsApp-Vorlagen des Mitarbeiterportals — Schluessel in den
 * Team-Einstellungen (RecApplicantSettings) plus die Pruefhelfer, die Sender
 * und Einstellungsmaske gemeinsam brauchen. Kein Rueckfall auf .env/Config.
 */
final class PortalVorlagen
{
    public const CODE_PASSWORT          = 'konto_code_passwort_wa_template_id';
    public const CODE_NUMMERNWECHSEL    = 'konto_code_nummernwechsel_wa_template_id';
    public const CODE_NOTFALL           = 'konto_code_notfall_wa_template_id';
    public const HINWEIS_ALTE_NUMMER    = 'konto_hinweis_alte_nummer_wa_template_id';
    public const AUFGABEN_NEU           = 'aufgaben_neu_wa_template_id';
    public const AUFGABEN_ERINNERUNG    = 'aufgaben_erinnerung_wa_template_id';
    public const EINSATZ_PRUEFUNG_AKTIV = 'einsatz_pruefung_aktiv';

    /** Zweck (KontoWriter::ZWECK_*) → Schluessel. 'anmeldung' fehlt bewusst (wird nirgends verschickt). */
    public const CODE_SCHLUESSEL = [
        'passwort'       => self::CODE_PASSWORT,
        'nummernwechsel' => self::CODE_NUMMERNWECHSEL,
        'notfall'        => self::CODE_NOTFALL,
    ];

    /** Anlass → Schluessel. */
    public const AUFGABEN_SCHLUESSEL = ['neu' => self::AUFGABEN_NEU, 'erinnerung' => self::AUFGABEN_ERINNERUNG];

    /** Schluessel → Feldname in den Einstellungen; Teil jeder Fehlermeldung der Sender. */
    public const FELDNAMEN = [
        'konto_code_passwort_wa_template_id'       => 'Code: Passwort vergessen',
        'konto_code_nummernwechsel_wa_template_id' => 'Code: neue Nummer bestätigen',
        'konto_code_notfall_wa_template_id'        => 'Code: Notfall-Weg',
        'konto_hinweis_alte_nummer_wa_template_id' => 'Hinweis an die alte Nummer',
        'aufgaben_neu_wa_template_id'              => 'Offene Aufgaben — neu',
        'aufgaben_erinnerung_wa_template_id'       => 'Offene Aufgaben — Erinnerung',
        'proof_reminder_wa_template_id'            => 'Nachweis läuft ab (Fristenlauf)',
        'document_wa_template_id'                  => 'Dokument liegt im Portal',
        'employee_contract_wa_template_id'         => 'Vertrag zur Unterschrift',
    ];

    /** Wo HR das Feld findet — Teil jeder Fehlermeldung. */
    public const ORT = 'Einstellungen → Kommunikation → Mitarbeiterportal';

    /**
     * Alle {{name}} im BODY in Fundreihenfolge, ohne Doppelte, Schreibweise
     * wie in der Vorlage (wie DokumentHinweisSender::rumpfPlatzhalter).
     *
     * @param  array<int, mixed>  $components
     * @return list<string>
     */
    public static function rumpfPlatzhalter(array $components): array
    {
        $namen = [];
        foreach ($components as $teil) {
            if (!is_array($teil) || ($teil['type'] ?? '') !== 'BODY') {
                continue;
            }
            preg_match_all('/\{\{(\w+)\}\}/', (string) ($teil['text'] ?? ''), $treffer);
            foreach ($treffer[1] as $name) {
                if (!in_array($name, $namen, true)) {
                    $namen[] = $name;
                }
            }
        }

        return $namen;
    }

    /** Braucht ein Knopf beim Senden einen Parameter? URL mit {{ in der URL, oder Typ OTP / COPY_CODE. */
    public static function hatKnopfMitParameter(array $components): bool
    {
        if (WhatsAppTemplateUrlButtons::dynamicIndexes($components) !== []) {
            return true;
        }

        foreach ($components as $teil) {
            if (!is_array($teil) || ($teil['type'] ?? '') !== 'BUTTONS' || !is_array($teil['buttons'] ?? null)) {
                continue;
            }
            foreach ($teil['buttons'] as $knopf) {
                if (is_array($knopf) && in_array(strtoupper((string) ($knopf['type'] ?? '')), ['OTP', 'COPY_CODE'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Team-Schalter Einsatz-Pruefung, strikt bool ('false'/'0' duerfen nicht per (bool)-Cast zu true werden). */
    public static function einsatzPruefungAktiv(int $teamId): bool
    {
        return self::istAn(RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(self::EINSATZ_PRUEFUNG_AKTIV));
    }

    /**
     * Derselbe Schalter, aber OHNE eine Einstellungszeile anzulegen — fuer
     * recruiting:einsatz-pruefung, dessen Trockenlauf verspricht, nichts zu
     * schreiben. Keine Zeile = Vorgabe = aus.
     */
    public static function einsatzPruefungAktivOhneAnlage(int $teamId): bool
    {
        $zeile = RecApplicantSettings::query()->where('team_id', $teamId)->first();

        return $zeile !== null && self::istAn($zeile->getSetting(self::EINSATZ_PRUEFUNG_AKTIV));
    }

    /** Strikt: nur true/1/'1'/'true'/'on' sind an — auch fuer das Speichern im Einstellungsfenster. */
    public static function istAn(mixed $wert): bool
    {
        return $wert === true || $wert === 1
            || in_array(is_string($wert) ? strtolower(trim($wert)) : $wert, ['1', 'true', 'on'], true);
    }
}
