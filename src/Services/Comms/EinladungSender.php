<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\EinmalcodeVorlagen;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\PilotKreis;
use Platform\Recruiting\Support\PortalVorlagen;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Verschickt die EINLADUNG ZUM KONTO per WhatsApp-Vorlage (Sebastian
 * 19.10.2026: HR kann den Link ausserhalb des 24-h-Fensters nicht frei
 * schicken). Bis dahin wurde die Einladung nur ERZEUGT und angezeigt
 * (KontoEinladen, Konto-Kasten), weil keine genehmigte Vorlage da war.
 *
 * DIESE KLASSE ERZEUGT NICHTS. Den Einladungscode (EinladungsToken, 8
 * Zeichen, sieben Tage gueltig) legt der Aufrufer ueber KontoWriter an und
 * reicht den Klartext herein. Grund: der Aufrufer zeigt denselben Code HR
 * als Rueckfall an — ein zweites Erzeugen hier entwertete genau den, den HR
 * gerade sieht.
 *
 * VORBILD SIND DIE PORTAL-SENDER (DokumentHinweisSender, AufgabenSender,
 * EinmalcodeSender), mit ihren drei Abwehren:
 *  1. `$nachricht->status === 'failed'` ist ein Fehlschlag, kein Erfolg
 *     (RecEmployee::sendPortalNotification stempelte ein Meta-Nein als ok).
 *  2. Ein Platzhalter, den wir nicht befuellen koennen, verhindert den
 *     Versand — HoldingTemplateComponents::build() wird deshalb NICHT
 *     benutzt, es setzte still den Vornamen ein.
 *  3. sendTemplate() im try/catch — ein Meta-Ausfall kostet eine Einladung,
 *     nicht den Lauf (das Kommando schickt in Wellen).
 *
 * WOHER DIE VORLAGE KOMMT: Team-Einstellung PortalVorlagen::EINLADUNG
 * (Einstellungen → Kommunikation → Mitarbeiterportal → „Einladung zum
 * Konto"), aufgeloest ueber HoldingTemplateSender::resolveTarget() —
 * dieselbe Kette wie bei den Konto-Codes: Einstellung → Vorlage (APPROVED)
 * → Account → Kanal. Name, Sprache und Platzhalter kommen aus der gewaehlten
 * Vorlage; kein Vorlagenname ist hier fest verdrahtet.
 *
 * ANFORDERUNGEN AN DIE VORLAGE (sonst STATUS_VORLAGE_UNTAUGLICH, Grund im
 * Log, nichts verschickt):
 *  - Body: nur der Vorname ({{name}}, {{vorname}}, {{1}}) oder gar kein
 *    Platzhalter.
 *  - Keine Kopfzeile mit Variable oder Medium.
 *  - GENAU EIN dynamischer URL-Knopf, und der zeigt auf die Konto-Anlage
 *    (…/recruiting/konto/anlegen/{{1}}, PortalVorlagen::EINLADUNG_PFAD). Die
 *    Variable ist der Einladungscode. Ohne die Pfad-Pruefung ginge z. B. die
 *    Dokumente-Vorlage durch — der Code landete im Portal-Link, und der
 *    Knopf fuehrte ins Leere.
 *  - Keine Authentifizierungs-Vorlage (OTP-Knopf): die ist fuer Einmalcodes.
 *
 * NUR IM PILOT (PilotKreis::personHatNeuesPortal): ohne aktive Anstellung
 * mit neuem Portal landet der Mensch nach der Registrierung auf 'ohne-ziel'.
 * Der Aufrufer (Konto-Kasten, Kommando) prueft das schon vorher — diese
 * Klasse verlaesst sich nicht darauf und prueft selbst, VOR allem anderen.
 *
 * EMPFAENGER ist die Nummer der PERSON (rec_persons.phone), wie bei den
 * Konto-Codes: das Konto gehoert dem Menschen, nicht einer Anstellung.
 *
 * DER CODE BLEIBT AUS DEM VERLAUF. WhatsAppMetaService::handleSendResponse()
 * (platform-crm) legt unsere components als comms_whatsapp_messages.
 * template_params ab und ein zweites Mal in comms_logs.details. Dort stuende
 * der Einladungscode sieben Tage lang gueltig im Klartext — sichtbar fuer
 * jeden, der den Chat oder das Kommunikations-Protokoll lesen darf (und ueber
 * das MCP-Werkzeug ShowWhatsAppMessage). Darum schwaerzt
 * schwaerzeGespeichertes() beide Zeilen direkt nach dem Versand, mit
 * demselben Verfahren wie die Chat-Anzeige der Einmalcodes
 * (EinmalcodeVorlagen::geschwaerzt: alle Werte, auch der Knopf-Parameter).
 * Die Anzeige (DispoThreadDirectory) erkennt die Vorlage zusaetzlich selbst
 * (zweite Linie, falls das Nachschwaerzen scheitert).
 *
 * KEINE VOLLE RUFNUMMER, NIE DER CODE IM LOG — fremde Texte (Ausnahme,
 * Meta-Meldung) laufen durch ohneCode().
 *
 * OBSERVER-FREI: schreibt nichts an rec_employees (kein zas_changed_at).
 *
 * Nicht final: Aufrufer holen ihn ueber app(), Tests ersetzen ihn.
 */
class EinladungSender
{
    public const STATUS_GESENDET = 'gesendet';

    /** Im Feld „Einladung zum Konto" ist nichts gewaehlt, oder die Vorlage ist nicht (mehr) genehmigt. */
    public const STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert';

    /** Die gewaehlte Vorlage passt nicht (Platzhalter, Knopf, Kopfzeile). */
    public const STATUS_VORLAGE_UNTAUGLICH = 'vorlage_untauglich';

    /** Vorlage genehmigt, aber kein aktiver WhatsApp-Account/Kanal dazu. */
    public const STATUS_KEIN_KANAL = 'kein_kanal';

    /** Keine lesbare Nummer, von Meta abgelehnt, Ausnahme beim Senden, Person fehlt. */
    public const STATUS_FEHLGESCHLAGEN = 'fehlgeschlagen';

    /** Der Mensch hat kein neues Portal — es geht nichts raus. */
    public const STATUS_NICHT_IM_PILOT = 'nicht_im_pilot';

    /** HR-taugliche Kurztexte je Status (Konto-Kasten, Kommando). */
    public const STATUS_TEXT = [
        self::STATUS_GESENDET           => 'per WhatsApp verschickt',
        self::STATUS_NICHT_KONFIGURIERT => 'keine Vorlage eingestellt',
        self::STATUS_VORLAGE_UNTAUGLICH => 'Vorlage passt nicht',
        self::STATUS_KEIN_KANAL         => 'kein WhatsApp-Kanal zur Vorlage',
        self::STATUS_FEHLGESCHLAGEN     => 'Versand fehlgeschlagen',
        self::STATUS_NICHT_IM_PILOT     => 'kein neues Portal',
    ];

    /** Was als Vorname befuellt wird — dieselbe Liste wie in DokumentHinweisSender/AufgabenSender. */
    private const NAME_PLATZHALTER = ['name', 'vorname', '1'];

    public static function statusText(string $status): string
    {
        return self::STATUS_TEXT[$status] ?? $status;
    }

    /**
     * @param  string  $klartext  der Einladungscode, wie KontoWriter::ladeEin()
     *                            bzw. setzeKontoZurueckDurchHr() ihn liefert
     * @return string  eine der STATUS_*-Konstanten
     */
    public function sende(int $personId, string $klartext): string
    {
        // Fix-Runde 1: KEINE Ausnahme verlaesst diese Methode. Ein
        // Datenbankfehler in resolveTarget, PilotKreis oder beim Vornamen
        // risse sonst die Welle des Kommandos (und die Tabelle mit den Codes)
        // mit — die Einladungen davor waeren erzeugt und nie angezeigt.
        try {
            return $this->sendeGeprueft($personId, $klartext);
        } catch (\Throwable $e) {
            return $this->fertig($personId, null, self::STATUS_FEHLGESCHLAGEN, $this->ohneCode($e->getMessage(), $klartext), 'warning');
        }
    }

    /**
     * Taugt die Einstellung des Teams fuer einen Versand? null = ja, sonst der
     * Grund — dieselben Pruefungen wie in sende() (Feld gewaehlt, Vorlage
     * genehmigt, Kanal da, Vorlage tauglich), ohne Person und ohne Versand.
     *
     * Fuer recruiting:konto-einladen --senden: ohne diese Vorabfrage luede
     * eine Welle bei kaputter Einstellung alle ein, verschickte nichts, und
     * die naechste Welle uebersprange sie sieben Tage lang als "laufend".
     */
    public function vorabPruefung(int $teamId): ?string
    {
        try {
            $vorlage = $this->vorlageFuer($teamId);
        } catch (\Throwable $e) {
            return 'Pruefung der Vorlage gescheitert: ' . $e->getMessage();
        }

        return $vorlage['status'] === null ? null : self::statusText($vorlage['status']) . ' — ' . $vorlage['grund'];
    }

    private function sendeGeprueft(int $personId, string $klartext): string
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first(['id', 'team_id', 'phone']);
        if ($person === null || trim($klartext) === '') {
            return $this->fertig($personId, null, self::STATUS_FEHLGESCHLAGEN, 'Person oder Einladungscode fehlt.');
        }

        // Pilot zuerst: ein Nicht-Pilot bekommt nichts, egal wie die
        // Vorlage steht (Sebastian 19.10.2026).
        if (!PilotKreis::personHatNeuesPortal($personId)) {
            return $this->fertig($personId, null, self::STATUS_NICHT_IM_PILOT, null);
        }

        $geprueft = $this->vorlageFuer((int) $person->team_id);
        if ($geprueft['status'] !== null) {
            return $this->fertig($personId, null, $geprueft['status'], $geprueft['grund'], $geprueft['stufe']);
        }

        $ziel = $geprueft['ziel'];
        $vorlage = $ziel['template'];
        $name = (string) $vorlage->name;
        $teile = (array) ($vorlage->components ?? []);

        $nummer = PhoneE164::normalize($person->phone);
        if ($nummer === null || $nummer === '') {
            return $this->fertig($personId, null, self::STATUS_FEHLGESCHLAGEN, 'Keine lesbare Handynummer an der Person.');
        }

        $platzhalter = PortalVorlagen::rumpfPlatzhalter($teile);
        $parameter = [];
        if ($platzhalter !== []) {
            $vorname = $this->vorname($personId);
            if ($vorname === '') {
                // Leer befuellt lehnte Meta ab; mit etwas anderem befuellt
                // stuende Unsinn in der Anrede. Lieber gar nicht.
                return $this->fertig($personId, $nummer, self::STATUS_FEHLGESCHLAGEN, 'Die Vorlage braucht den Vornamen, an der Akte steht keiner.');
            }
            foreach ($platzhalter as $p) {
                $parameter[] = $this->parameter($p, $vorname);
            }
        }

        $knopf = ApplicantTemplateSender::buildTokenComponents($teile, $klartext);
        $components = $parameter === []
            ? $knopf['components']
            : array_merge([['type' => 'body', 'parameters' => $parameter]], $knopf['components']);

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $ziel['channel'],
                to:           $nummer,
                templateName: $name,
                components:   $components,
                languageCode: (string) ($vorlage->language ?: 'de'),
            );
        } catch (\Throwable $e) {
            // Eine QueryException beim INSERT des CRM traegt die Werte im
            // Text — darin steht der Code.
            return $this->fertig($personId, $nummer, self::STATUS_FEHLGESCHLAGEN, $this->ohneCode($e->getMessage(), $klartext), 'warning');
        }

        // Auch eine abgelehnte Nachricht liegt im Verlauf — also immer.
        $this->schwaerzeGespeichertes($nachricht, $klartext);

        if (($nachricht->status ?? null) === 'failed') {
            $meldung = (string) (($nachricht->meta_payload ?? [])['error']['message'] ?? 'Meta hat den Versand abgelehnt.');

            return $this->fertig($personId, $nummer, self::STATUS_FEHLGESCHLAGEN, $this->ohneCode($meldung, $klartext), 'warning');
        }

        return $this->fertig($personId, $nummer, self::STATUS_GESENDET, null);
    }

    /**
     * Einstellung → Vorlage → Kanal → Tauglichkeit, fuer sende() und
     * vorabPruefung() dieselbe Kette. status null = alles in Ordnung, dann
     * steht das Ergebnis von resolveTarget in 'ziel'.
     *
     * @return array{status: ?string, grund: ?string, stufe: string, ziel: ?array}
     */
    private function vorlageFuer(int $teamId): array
    {
        $nein = static fn (string $status, string $grund, string $stufe = 'error'): array => ['status' => $status, 'grund' => $grund, 'stufe' => $stufe, 'ziel' => null];

        // Ohne Wahl gar nicht erst aufloesen (resolveTarget meldete sonst den
        // irrefuehrenden Text der Eingangsbestaetigung).
        $keineWahl = PortalVorlagen::meldungWennKeineWahl($teamId, PortalVorlagen::EINLADUNG);
        if ($keineWahl !== null) {
            return $nein(self::STATUS_NICHT_KONFIGURIERT, $keineWahl, 'info');
        }

        $ziel = app(HoldingTemplateSender::class)->resolveTarget($teamId, PortalVorlagen::EINLADUNG);
        if ($ziel['error'] !== null) {
            $feld = PortalVorlagen::FELDNAMEN[PortalVorlagen::EINLADUNG];
            $status = $this->vorlageGenehmigt($teamId) ? self::STATUS_KEIN_KANAL : self::STATUS_NICHT_KONFIGURIERT;

            return $nein($status, PortalVorlagen::ORT . " → {$feld}: {$ziel['error']}");
        }

        $name = (string) $ziel['template']->name;
        $untauglich = $this->untauglichWeil((array) ($ziel['template']->components ?? []));
        if ($untauglich !== null) {
            return $nein(self::STATUS_VORLAGE_UNTAUGLICH, "Vorlage \"{$name}\": {$untauglich}");
        }

        return ['status' => null, 'grund' => null, 'stufe' => 'info', 'ziel' => $ziel];
    }

    /**
     * Warum die Vorlage nicht taugt — oder null. Alle Pruefungen gegen die
     * echte Vorlage, nicht gegen eine Liste.
     *
     * @param  array<int, mixed>  $teile
     */
    private function untauglichWeil(array $teile): ?string
    {
        if (PortalVorlagen::brauchtKopfParameter($teile)) {
            return PortalVorlagen::KOPFZEILE_MELDUNG;
        }

        $fremd = array_values(array_filter(
            PortalVorlagen::rumpfPlatzhalter($teile),
            static fn (string $n): bool => !in_array(strtolower($n), self::NAME_PLATZHALTER, true),
        ));
        if ($fremd !== []) {
            return 'unbekannter Platzhalter {{' . implode('}}, {{', $fremd) . '}} — befuellbar ist nur der Vorname ({{name}}, {{vorname}}, {{1}}).';
        }

        if (PortalVorlagen::otpKnopfPositionen($teile) !== []) {
            return 'Knopf „Code kopieren" (Authentifizierungs-Vorlage) — die Einladung braucht einen URL-Knopf auf die Konto-Anlage.';
        }

        $dynamisch = WhatsAppTemplateUrlButtons::dynamicIndexes($teile);
        if ($dynamisch === []) {
            return 'kein URL-Knopf mit Variable — ohne Link waere die Einladung eine Sackgasse.';
        }
        if (count($dynamisch) > 1) {
            return 'mehr als ein URL-Knopf mit Variable (Positionen ' . implode(', ', $dynamisch) . ') — nicht eindeutig.';
        }
        if (PortalVorlagen::einladungsKnopfPositionen($teile) !== $dynamisch) {
            return 'der URL-Knopf fuehrt nicht auf …/recruiting/konto/anlegen/{{1}} — der Code liefe ins Leere.';
        }

        return null;
    }

    /**
     * Die Vorlage im Feld ist da und genehmigt? Trennt "kein Kanal" von
     * "nicht konfiguriert", wenn resolveTarget() einen Fehler meldet.
     */
    private function vorlageGenehmigt(int $teamId): bool
    {
        $vorlagen = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class;
        if (!class_exists($vorlagen)) {
            return false;
        }
        $id = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(PortalVorlagen::EINLADUNG);
        $vorlage = is_numeric($id) ? $vorlagen::find((int) $id) : null;

        return $vorlage !== null && $vorlage->status === 'APPROVED';
    }

    /** @return array{type: string, text: string, parameter_name?: string} */
    private function parameter(string $name, string $vorname): array
    {
        // Positionell ({{1}}) geht OHNE parameter_name raus, benannt klein
        // geschrieben (Meta laesst als Namen nur Kleinbuchstaben zu).
        return ctype_digit($name)
            ? ['type' => 'text', 'text' => $vorname]
            : ['type' => 'text', 'parameter_name' => strtolower($name), 'text' => $vorname];
    }

    /** Vorname von der Anstellung (der Mensch hat keinen eigenen) — wie EinmalcodeSender. */
    private function vorname(int $personId): string
    {
        return trim((string) DB::table('rec_employees')
            ->where('rec_person_id', $personId)
            ->whereNotNull('first_name')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->value('first_name'));
    }

    /**
     * Der Einladungscode aus den GESPEICHERTEN Zeilen des CRM: template_params
     * und meta_payload der Nachricht, details der Protokollzeile.
     *
     * WARUM HIER UND NICHT IM CRM: die Ablage geschieht in platform-crm
     * (WhatsAppMetaService::handleSendResponse) fuer alle Sender gleich;
     * dort etwas zu aendern liegt ausserhalb dieses Moduls. Diese Klasse
     * kennt aber die Kennung der Nachricht, die sie gerade verschickt hat,
     * und den Klartext — damit laesst sich genau diese eine Zeile
     * nachschwaerzen, per Query Builder, ohne Modell-Ereignisse.
     *
     * Verfahren wie in der Chat-Anzeige: EinmalcodeVorlagen::geschwaerzt()
     * fuer template_params (Struktur bleibt, damit der Chat den Satz noch
     * rendern kann; alle Werte fallen, auch der Knopf-Parameter), dazu ein
     * woertliches Ersetzen des Klartexts im Rest (Meta zitiert bei einem
     * Parameterfehler den gesendeten Wert).
     *
     * Scheitert es, kippt der Versand nicht — die Nachricht ist draussen.
     * Dann bleibt die Anzeige-Schwaerzung (EinmalcodeVorlagen erkennt die
     * Vorlage) und eine Warnung im Log, damit jemand die Zeile findet.
     */
    private function schwaerzeGespeichertes(object $nachricht, string $klartext): void
    {
        $id = isset($nachricht->id) ? (int) $nachricht->id : 0;
        if ($id <= 0) {
            return;
        }

        try {
            $zeile = DB::table('comms_whatsapp_messages')->where('id', $id)->first(['id', 'template_params', 'meta_payload']);
            if ($zeile !== null) {
                $update = ['template_params' => json_encode(EinmalcodeVorlagen::geschwaerzt($zeile->template_params))];
                if ($zeile->meta_payload !== null) {
                    $update['meta_payload'] = $this->ohneCode((string) $zeile->meta_payload, $klartext);
                }
                DB::table('comms_whatsapp_messages')->where('id', $id)->update($update);
            }

            foreach (DB::table('comms_logs')->where('message_id', $id)->get(['id', 'details']) as $log) {
                $details = json_decode((string) $log->details, true);
                if (is_array($details) && array_key_exists('template_params', $details)) {
                    $details['template_params'] = EinmalcodeVorlagen::geschwaerzt($details['template_params']);
                }
                $neu = is_array($details) ? json_encode($details) : (string) $log->details;
                DB::table('comms_logs')->where('id', $log->id)->update(['details' => $this->ohneCode($neu, $klartext)]);
            }
        } catch (\Throwable $e) {
            Log::warning('recruiting.konto.einladung_schwaerzen_gescheitert', [
                'message_id' => $id,
                'fehler'     => $this->ohneCode($e->getMessage(), $klartext),
            ]);
        }
    }

    /** Eine Logzeile je Versuch: Person, Status, Nummer nur die letzten vier, nie der Code. */
    private function fertig(int $personId, ?string $nummer, string $status, ?string $meldung, string $stufe = 'info'): string
    {
        $daten = ['person_id' => $personId, 'status' => $status];
        if ($nummer !== null) {
            $daten['nummer_endet_auf'] = substr($nummer, -4);
        }
        if ($meldung !== null) {
            $daten['grund'] = $meldung;
        }
        Log::{$stufe}('recruiting.konto.einladung_versand', $daten);

        return $status;
    }

    private function ohneCode(string $text, string $klartext): string
    {
        return $klartext === '' ? $text : str_replace($klartext, EinmalcodeVorlagen::MASKE, $text);
    }
}
