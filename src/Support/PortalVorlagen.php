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
    /**
     * Einladung zum Konto (EinladungSender, Sebastian 19.10.2026): HR kann den
     * Link ausserhalb des 24-h-Fensters nicht frei schicken, also per Vorlage.
     * Leer = wie bisher nur anzeigen, HR gibt von Hand weiter.
     */
    public const EINLADUNG              = 'konto_einladung_wa_template_id';
    public const EINSATZ_PRUEFUNG_AKTIV = 'einsatz_pruefung_aktiv';
    /** HR-Faelle der Einsatz-Pruefung nur fuer die Pilotgruppe (PilotKreis), Vorgabe an. */
    public const EINSATZ_PRUEFUNG_NUR_PILOT = 'einsatz_pruefung_nur_pilot';

    /** Zweck (KontoWriter::ZWECK_*) → Schluessel. 'anmeldung' fehlt bewusst (wird nirgends verschickt). */
    public const CODE_SCHLUESSEL = [
        'passwort'       => self::CODE_PASSWORT,
        'nummernwechsel' => self::CODE_NUMMERNWECHSEL,
        'notfall'        => self::CODE_NOTFALL,
    ];

    /**
     * Anlass → Schluessel.
     *
     * Kundenentscheidung Markus 12.10.2026: nur noch 'neu'. Die Erinnerung
     * vor dem Einsatz entfaellt, und der HR-Knopf „Erneut erinnern"
     * (HrKontoAktionen::erinnereAufgaben) schickt ueber dieselbe Vorlage
     * AUFGABEN_NEU. Der fruehere Schluessel aufgaben_erinnerung_wa_template_id
     * kann in bestehenden Team-Einstellungen noch stehen — er wird nicht
     * mehr gelesen und bewusst nicht aus der Datenbank geraeumt.
     */
    public const AUFGABEN_SCHLUESSEL = ['neu' => self::AUFGABEN_NEU];

    /**
     * Systemnachrichten (Sebastian 22.10.2026): Einladung, Codes, Hinweis an
     * die alte Nummer. Sie sind kein Gespraech und starten die Ruhezeit der
     * Themen-Auswahl nicht (ThemenAuswahlHandler).
     */
    public const SYSTEM_SCHLUESSEL = [self::EINLADUNG, self::CODE_PASSWORT, self::CODE_NUMMERNWECHSEL, self::CODE_NOTFALL, self::HINWEIS_ALTE_NUMMER];

    /**
     * Namen der in den Team-Einstellungen gewaehlten Systemvorlagen — so,
     * wie sie in comms_whatsapp_messages.template_name stehen.
     *
     * @return list<string>
     */
    public static function systemVorlagenNamen(RecApplicantSettings $settings): array
    {
        $ids = [];
        foreach (self::SYSTEM_SCHLUESSEL as $schluessel) {
            $id = $settings->getSetting($schluessel);
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        // Fehlt die Tabelle (anderes Modul-Setup), zaehlt eben alles — die
        // Themen-Auswahl darf daran nicht scheitern (Review 22.10.2026).
        try {
            return \Illuminate\Support\Facades\DB::table('integrations_whatsapp_templates')
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('name')->map(fn ($n) => (string) $n)->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Schluessel → Feldname in den Einstellungen; Teil jeder Fehlermeldung der Sender. */
    public const FELDNAMEN = [
        'konto_code_passwort_wa_template_id'       => 'Code: Passwort vergessen',
        'konto_code_nummernwechsel_wa_template_id' => 'Code: neue Nummer bestätigen',
        'konto_code_notfall_wa_template_id'        => 'Code: Notfall-Weg',
        'konto_hinweis_alte_nummer_wa_template_id' => 'Hinweis an die alte Nummer',
        'aufgaben_neu_wa_template_id'              => 'Offene Aufgaben — neu',
        'konto_einladung_wa_template_id'           => 'Einladung zum Konto',
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

    /**
     * Der Pfad, auf den der Einladungs-Knopf zeigen muss — Route
     * recruiting.public.konto-anlegen (/recruiting/konto/anlegen/{token}).
     * Ohne Host (der steht bei Meta und ist je Umgebung ein anderer), aber
     * mit /recruiting davor — ein fremdes /x/konto/anlegen/ zaehlt nicht.
     */
    public const EINLADUNG_PFAD = '/recruiting/konto/anlegen/{{';

    /**
     * Positionen der dynamischen URL-Knoepfe, die auf die Konto-Anlage zeigen
     * (gezaehlt wie WhatsAppTemplateUrlButtons::dynamicIndexes, ueber ALLE
     * Knoepfe). Zwei Nutzer: EinladungSender prueft, dass der eine
     * dynamische Knopf wirklich dorthin fuehrt (sonst landete der Code z. B.
     * im Dokumente-Link und der Knopf liefe ins Leere), und die
     * Chat-Schwaerzung (EinmalcodeVorlagen) erkennt daran eine
     * Einladungs-Nachricht auch ohne Einstellung.
     *
     * @param  array<int, mixed>  $components
     * @return list<int>
     */
    public static function einladungsKnopfPositionen(array $components): array
    {
        $positionen = [];
        $position = 0;

        foreach ($components as $teil) {
            if (!is_array($teil) || strtoupper((string) ($teil['type'] ?? '')) !== 'BUTTONS' || !is_array($teil['buttons'] ?? null)) {
                continue;
            }
            foreach ($teil['buttons'] as $knopf) {
                if (is_array($knopf)
                    && strtoupper((string) ($knopf['type'] ?? '')) === 'URL'
                    && str_contains((string) ($knopf['url'] ?? ''), self::EINLADUNG_PFAD)
                ) {
                    $positionen[] = $position;
                }
                $position++;
            }
        }

        return $positionen;
    }

    /** Meldung, wenn eine Vorlage eine Kopfzeile mit Variable oder Medium hat — die befuellen wir nicht. */
    public const KOPFZEILE_MELDUNG = 'Die Vorlage hat eine Kopfzeile mit Variable oder Medium — die befuellen wir nicht, Meta wuerde ablehnen.';

    /**
     * Braucht die Kopfzeile beim Senden einen Parameter? Ein HEADER mit Format
     * IMAGE/VIDEO/DOCUMENT/LOCATION, oder ein TEXT-HEADER mit {{ im Text.
     * Eine feste Text-Kopfzeile ist unproblematisch.
     *
     * @param  array<int, mixed>  $components
     */
    public static function brauchtKopfParameter(array $components): bool
    {
        foreach ($components as $teil) {
            if (!is_array($teil) || strtoupper((string) ($teil['type'] ?? '')) !== 'HEADER') {
                continue;
            }
            $format = strtoupper((string) ($teil['format'] ?? 'TEXT'));
            if ($format !== 'TEXT' && $format !== '') {
                return true;
            }
            if (str_contains((string) ($teil['text'] ?? ''), '{{')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leere Auswahl in den Einstellungen → eigene Meldung mit Ort und
     * Feldname (statt des Eingangsbestaetigungs-Texts aus HoldingTemplateSender).
     * null = es ist etwas gewaehlt.
     */
    public static function meldungWennKeineWahl(int $teamId, string $schluessel): ?string
    {
        if (RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting($schluessel)) {
            return null;
        }
        $feldname = self::FELDNAMEN[$schluessel] ?? $schluessel;

        return 'Keine Vorlage gewählt unter ' . self::ORT . " → {$feldname}";
    }

    /** Braucht ein Knopf beim Senden einen Parameter? URL mit {{ in der URL, oder Typ OTP / COPY_CODE. */
    public static function hatKnopfMitParameter(array $components): bool
    {
        if (WhatsAppTemplateUrlButtons::dynamicIndexes($components) !== []) {
            return true;
        }

        // OTP-Knopf in beiden Schreibweisen (siehe otpKnopfPositionen()).
        return self::otpKnopfPositionen($components) !== [];
    }

    /** Metas Kategorie fuer Einmalcode-Vorlagen ("Authentifizierung", Knopf "Code kopieren"). */
    public const KATEGORIE_AUTH = 'AUTHENTICATION';

    /**
     * Ist das eine Vorlage der Kategorie AUTHENTICATION?
     *
     * WARUM ES DAS GIBT (14.10.2026): Meta lehnt die Code-Vorlage in der
     * Kategorie UTILITY ab. Eine Einmalcode-Vorlage MUSS AUTHENTICATION sein,
     * und dort gibt Meta Text und Knopf fest vor: Body "*{{1}}* ist dein
     * Bestaetigungscode." (positionell, ein Name ist nicht moeglich) plus ein
     * Knopf "Code kopieren" (OTP/COPY_CODE), der den Code beim Senden ein
     * zweites Mal als Parameter braucht.
     *
     * ERKANNT AN DER KATEGORIE, die der Meta-Abgleich woertlich in
     * integrations_whatsapp_templates.category ablegt. Nur wenn sie FEHLT
     * (alte Zeile, Abgleich ohne Feld), entscheidet der Knopf: ein OTP-Knopf
     * kommt bei Meta nur in Authentifizierungsvorlagen vor. Steht dagegen
     * ausdruecklich eine andere Kategorie da, gilt sie — eine UTILITY-Vorlage
     * mit OTP-Knopf ist ein Widerspruch und bleibt, was sie vorher war:
     * ungeeignet (Knopf mit Variable).
     *
     * @param  array<int, mixed>  $components
     */
    public static function istAuthVorlage(?string $kategorie, array $components): bool
    {
        $kategorie = strtoupper(trim((string) $kategorie));
        if ($kategorie !== '') {
            return $kategorie === self::KATEGORIE_AUTH;
        }

        return self::otpKnopfPositionen($components) !== [];
    }

    /**
     * Positionen der Knoepfe "Code kopieren" (OTP), gezaehlt ueber ALLE
     * Knoepfe, so wie Meta den `index` der Button-Komponente zaehlt.
     *
     * ZWEI SCHREIBWEISEN, weil Meta beim Anlegen und beim Abrufen nicht
     * dieselbe benutzt: angelegt wird `{type: OTP, otp_type: COPY_CODE}`,
     * die Graph-API liefert denselben Knopf beim Abruf aber (je nach
     * Version) als `{type: URL, url: "https://www.whatsapp.com/otp/code/
     * ?otp_type=COPY_CODE&...&code=otp{{1}}"}` zurueck. Beide sind derselbe
     * Knopf; wer nur eine kennt, haelt die andere fuer einen gewoehnlichen
     * URL-Knopf mit Variable. 'COPY_CODE' als Typ zaehlt mit, weil
     * hatKnopfMitParameter() ihn schon immer so gefuehrt hat.
     *
     * @param  array<int, mixed>  $components
     * @return list<int>
     */
    public static function otpKnopfPositionen(array $components): array
    {
        $positionen = [];
        $position = 0;

        foreach ($components as $teil) {
            if (!is_array($teil) || strtoupper((string) ($teil['type'] ?? '')) !== 'BUTTONS' || !is_array($teil['buttons'] ?? null)) {
                continue;
            }
            foreach ($teil['buttons'] as $knopf) {
                if (is_array($knopf) && self::istOtpKnopf($knopf)) {
                    $positionen[] = $position;
                }
                $position++;
            }
        }

        return $positionen;
    }

    /** @param  array<string, mixed>  $knopf */
    private static function istOtpKnopf(array $knopf): bool
    {
        $typ = strtoupper((string) ($knopf['type'] ?? ''));
        if (in_array($typ, ['OTP', 'COPY_CODE'], true)) {
            return true;
        }

        return $typ === 'URL' && stripos((string) ($knopf['url'] ?? ''), 'otp_type=') !== false;
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
