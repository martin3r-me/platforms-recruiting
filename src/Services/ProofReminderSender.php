<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\ProofTypes;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Verschickt GENAU EINE Erinnerung per WhatsApp fuer einen faelligen Nachweis.
 *
 * Drei Fehler duerfen sich hier nicht wiederholen — zwei aus dem Bestand,
 * einer aus der Schlusspruefung dieses Pakets (M4):
 *
 *  1. `RecEmployee::sendPortalNotification()` liefert `ok: true` direkt nach
 *     `sendTemplate()`, ohne `$message->status` zu pruefen — ein von Meta
 *     ABGELEHNTER Versand gilt dort als Erfolg. Diese Klasse prueft
 *     `$message->status` (Muster: `ApplicantTemplateSender`,
 *     `DispoConfirmationSender`) und meldet einen Fehlschlag als
 *     `STATUS_FAILED` — der Aufrufer (das Kommando) setzt `reminded_at` NUR
 *     bei `STATUS_SENT`.
 *  2. `Applicant/Show::sendManualTemplate` haengt den Bewerber-FORMULAR-Token
 *     an JEDEN URL-Knopf, unabhaengig davon, wohin er zeigt (Fall Theo Wirtz).
 *     Der Knopf hier bekommt ausschliesslich `$ma->portal_token` — ueber
 *     `ApplicantTemplateSender::buildTokenComponents()`, dieselbe Fix-Klasse,
 *     die den Theo-Wirtz-Fehler bereits einmal behoben hat. Keine eigene
 *     zweite Kopie dieser Logik.
 *  3. `HoldingTemplateComponents::build()` setzt bei einem UNBEKANNTEN
 *     Platzhalter still den Vornamen oder den Beispielwert der Vorlage ein.
 *     Heisst der Platzhalter in der Meta-Vorlage spaeter anders als hier
 *     gedacht, laesen 540 Menschen „dein Kevin laeuft am Kevin ab", Meta
 *     naehme es an, `reminded_at` stuende — und eine zweite Erinnerung gibt es
 *     nie. Die geteilte Klasse bleibt unangetastet (andere Sender haengen
 *     daran); dieser Sender prueft stattdessen selbst und lehnt den Versand
 *     mit `STATUS_TEMPLATE_UNKNOWN_PLACEHOLDER` ab, mit Nennung des
 *     Platzhalters. Siehe `unbefuellbarePlatzhalter()`.
 *
 * Template + Kanal kommen ueber `HoldingTemplateSender::resolveTarget()`
 * (lesend, Settings-Key `SETTINGS_KEY`) — dieselbe Aufloesungskette wie
 * Holding-Bestaetigung, OOO-Auto-Reply und Schulungszertifikat. Keine eigene
 * Kopie von Settings → Template → Account → Kanal.
 *
 * WELCHES PORTAL DER KNOPF OEFFNET: das entscheidet NICHT diese Klasse. Sie
 * bekommt ausschliesslich Mitarbeiter zum Senden uebergeben, die der Aufrufer
 * bereits gefiltert hat — NUR `portal_v2_since` gesetzt, OHNE Ausnahme (Fixrunde
 * 2, Befund C2: eine Ausnahme-Option wurde ersatzlos entfernt, weil sie nur
 * Schaden anrichten konnte — wer noch auf dem alten Portal ist, kann den
 * Nachweis dort nicht hochladen, der Knopf fuehrt auf eine 404-Seite, UND der
 * Mensch gilt danach dauerhaft als erinnert). Die Basis-URL des Knopfes steht
 * im bei Meta genehmigten Template; dieser Sender liefert nur den Token
 * (Muster: `TrainingCertificateWhatsAppDelivery` — "der Button-Parameter ist
 * die uuid, nicht die URL").
 *
 * JEDER VERSUCH LANDET IM LOG (Fixrunde 2, Befund I6): `finish()` schreibt ein
 * `Log::info` bei JEDEM Ausgang — unabhaengig davon, ob `RecAutoPilotLog`
 * greift (das braucht `rec_applicant_id`, und genau das fehlt den
 * ZAS-Bestandsmitarbeitern ohne Bewerbung, also der groessten Gruppe der
 * ~540 Alt-Nachweise). Ohne dieses Log gaebe es fuer sie keinerlei Spur.
 */
final class ProofReminderSender
{
    /** Team-Einstellung mit der ID des genehmigten Meta-Templates. */
    public const SETTINGS_KEY = 'proof_reminder_wa_template_id';

    /** Passt in rec_auto_pilot_logs.type (string(30)). */
    public const LOG_TYPE = 'proof_reminder_sent';

    /**
     * Die Platzhalternamen, die HoldingTemplateComponents::build() aus dem
     * VORNAMEN befuellt (isNameVar). Diese Liste muss mit der dortigen
     * uebereinstimmen — sie steht hier als Kopie, weil die geteilte Klasse
     * nicht angefasst werden darf (andere Sender haengen daran).
     *
     * @var list<string>
     */
    private const NAME_PLATZHALTER = ['name', 'vorname', '1'];

    public const STATUS_SENT = 'sent';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button';
    public const STATUS_TEMPLATE_UNKNOWN_PLACEHOLDER = 'template_unknown_placeholder';
    public const STATUS_FAILED = 'failed';

    /**
     * @param  array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}  $eintrag
     * @return array{status:string, error:?string}
     */
    public function send(RecEmployee $ma, array $eintrag): array
    {
        $phone = $this->resolvePhone($ma);
        if ($phone === null) {
            return $this->finish($ma, $eintrag, ['status' => self::STATUS_NO_PHONE, 'error' => 'Keine Telefonnummer am CRM-Kontakt.']);
        }

        $target = app(HoldingTemplateSender::class)->resolveTarget((int) $ma->team_id, self::SETTINGS_KEY);
        if ($target['error'] !== null) {
            return $this->finish($ma, $eintrag, ['status' => self::STATUS_NOT_CONFIGURED, 'error' => $target['error']]);
        }

        $template = $target['template'];
        $components = $template->components ?? [];

        if (WhatsAppTemplateUrlButtons::dynamicIndexes($components) === []) {
            return $this->finish($ma, $eintrag, [
                'status' => self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
                'error' => 'Template „' . $template->name . '“ hat keinen URL-Button mit Variable — '
                    . 'ohne Link wäre die Erinnerung eine Sackgasse.',
            ]);
        }

        // Portal-Token an die tatsaechlich gefundene Button-Position — dieselbe
        // Fix-Klasse wie beim Theo-Wirtz-Fehler, keine eigene Kopie. Traegt bei
        // mehr als einem dynamischen Knopf einen sprechenden Fehler statt eines
        // geratenen Index.
        $built = ApplicantTemplateSender::buildTokenComponents($components, (string) $ma->portal_token);
        if (!$built['ok']) {
            return $this->finish($ma, $eintrag, ['status' => self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON, 'error' => $built['error']]);
        }

        // Namen fuer ein Template, das es noch nicht gibt (der Text liegt bei
        // RHEINGEDECK, siehe Plan „Danach, nicht von mir abhaengig"). Heisst
        // ein Platzhalter dort spaeter anders, faellt das ab hier auf, statt
        // still den Vornamen einzusetzen.
        $namedValues = [
            'nachweis' => ProofTypes::label($eintrag['code']),
            'datum'    => $this->formatDatum($eintrag['valid_until']),
        ];
        $firstName = trim((string) ($ma->first_name ?? ''));

        // DIESELBE STRENGE WIE BEIM URL-KNOPF: kann die Vorlage einen ihrer
        // Body-Platzhalter nicht aus unseren eigenen Werten fuellen, geht sie
        // gar nicht erst raus.
        $unbefuellbar = $this->unbefuellbarePlatzhalter($components, array_keys($namedValues));
        if ($unbefuellbar !== []) {
            return $this->finish($ma, $eintrag, [
                'status' => self::STATUS_TEMPLATE_UNKNOWN_PLACEHOLDER,
                'error' => 'Template „' . $template->name . '“ hat den Platzhalter {{'
                    . implode('}}, {{', $unbefuellbar) . '}}, den wir nicht befüllen können — '
                    . 'bekannt sind nur {{nachweis}}, {{datum}} und der Vorname ({{name}}, {{vorname}}, {{1}}). '
                    . 'Ungeprüft würde dort still der Vorname oder der Beispielwert der Vorlage stehen.',
            ]);
        }
        $sendComponents = HoldingTemplateComponents::build($components, $firstName, $namedValues);

        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return $this->finish($ma, $eintrag, [
                'status' => self::STATUS_FAILED,
                'error' => 'Leerer Pflicht-Parameter im Body (meist der Vorname) — Meta lehnt solche Sends ab.',
            ]);
        }

        $sendComponents = array_merge($sendComponents, $built['components']);

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $target['channel'],
                to:           $phone,
                templateName: (string) $template->name,
                components:   $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            $this->log($ma, 'error', 'Nachweis-Erinnerung: Versand fehlgeschlagen — ' . $e->getMessage(), $eintrag);

            return $this->finish($ma, $eintrag, ['status' => self::STATUS_FAILED, 'error' => $e->getMessage()]);
        }

        // DAS IST DIE ZEILE, DIE DEN BEKANNTEN FEHLER VERMEIDET: ein Erfolg
        // gilt erst nach diesem Check, nicht schon nach der blossen Rueckkehr
        // von sendTemplate().
        if (($message->status ?? null) === 'failed') {
            $fehler = (string) ($message->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.');
            $this->log($ma, 'error', 'Nachweis-Erinnerung: von Meta abgelehnt — ' . $fehler, $eintrag);

            return $this->finish($ma, $eintrag, ['status' => self::STATUS_FAILED, 'error' => $fehler]);
        }

        $this->log(
            $ma,
            self::LOG_TYPE,
            'Nachweis-Erinnerung gesendet (' . ProofTypes::label($eintrag['code']) . ').',
            $eintrag
        );

        return $this->finish($ma, $eintrag, ['status' => self::STATUS_SENT, 'error' => null]);
    }

    /**
     * Welche Body-Platzhalter der Vorlage koennen wir NICHT aus eigenen Werten
     * befuellen?
     *
     * HoldingTemplateComponents::build() ist geteilt (Holding-Bestaetigung,
     * OOO-Auto-Reply, Schulungszertifikat) und darf nicht strenger werden.
     * Sie setzt bei einem unbekannten Platzhalter STILL den Vornamen oder den
     * Beispielwert der Vorlage ein. Meta nimmt so eine Nachricht an: die Leute
     * laesen dann „dein Kevin laeuft am Kevin ab", reminded_at wuerde gesetzt
     * — und weil es genau EINE Erinnerung je Nachweis gibt, bekaeme niemand
     * je eine zweite. Deshalb prueft dieser Sender selbst, vor dem Versand.
     *
     * Muster und Namenserkennung sind absichtlich identisch mit
     * HoldingTemplateComponents::build() — was dort als Vorname durchgeht,
     * gilt hier als bekannt, und nichts sonst.
     *
     * @param  list<string>  $eigeneNamen  Schluessel aus $namedValues
     * @return list<string>  die unbekannten Platzhalternamen, in Fundreihenfolge
     */
    private function unbefuellbarePlatzhalter(array $templateComponents, array $eigeneNamen): array
    {
        $bekannt = array_map(
            static fn (string $n): string => strtolower($n),
            array_merge(self::NAME_PLATZHALTER, $eigeneNamen),
        );

        $unbekannt = [];
        foreach ($templateComponents as $component) {
            if (($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            preg_match_all('/\{\{(\w+)\}\}/', (string) ($component['text'] ?? ''), $treffer);
            foreach ($treffer[1] as $name) {
                if (!in_array(strtolower($name), $bekannt, true) && !in_array($name, $unbekannt, true)) {
                    $unbekannt[] = $name;
                }
            }
        }

        return $unbekannt;
    }

    /**
     * Letzte Station JEDES Ausgangs von send() (Fixrunde 2, Befund I6):
     * schreibt ein Log::info unabhaengig davon, ob RecAutoPilotLog greift
     * (das braucht rec_applicant_id, ZAS-Bestandsmitarbeiter ohne Bewerbung
     * haben keine — sonst gaebe es fuer genau die Zielgruppe der ~540
     * Alt-Nachweise keinerlei Spur). Reicht das Ergebnis unveraendert durch.
     *
     * @param  array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}  $eintrag
     * @param  array{status:string, error:?string}  $result
     * @return array{status:string, error:?string}
     */
    private function finish(RecEmployee $ma, array $eintrag, array $result): array
    {
        $context = [
            'rec_employee_id' => $ma->id,
            'proof_id'        => $eintrag['proof_id'] ?? null,
            'proof_type_code' => $eintrag['code'] ?? null,
            'status'          => $result['status'],
        ];
        if ($result['error'] !== null) {
            $context['error'] = $result['error'];
        }

        Log::info('[ProofReminderSender] Versand ' . $result['status'], $context);

        return $result;
    }

    /**
     * Telefonnummer ueber die CRM-Kontakte des Mitarbeiters — NICHT ueber
     * rec_employees.phone. Gleiche Prioritaet wie
     * RecEmployee::sendPortalNotification(): primaer+international zuerst,
     * sonst die erste aktive mit internationaler Nummer.
     */
    private function resolvePhone(RecEmployee $ma): ?string
    {
        $ma->loadMissing('crmContactLinks.contact.phoneNumbers');

        foreach ($ma->crmContactLinks as $link) {
            $contact = $link->contact;
            if (!$contact) {
                continue;
            }

            $phoneNumber = $contact->phoneNumbers
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereNotNull('international')
                ->first();

            if (!$phoneNumber) {
                $phoneNumber = $contact->phoneNumbers
                    ->where('is_active', true)
                    ->whereNotNull('international')
                    ->first();
            }

            if ($phoneNumber) {
                return $phoneNumber->international;
            }
        }

        return null;
    }

    private function formatDatum(string $ymd): string
    {
        $zeit = strtotime($ymd);

        return $zeit !== false ? date('d.m.Y', $zeit) : $ymd;
    }

    /** @param array{proof_id:int, rec_employee_id:int, code:string, valid_until:string} $eintrag */
    private function log(RecEmployee $ma, string $type, string $summary, array $eintrag): void
    {
        // Kein rec_applicant_id (ZAS-Bestandsmitarbeiter ohne Bewerbung) —
        // RecAutoPilotLog haengt zwingend an einer Bewerbung, dann bleibt es
        // still. Der Versand selbst ist davon unabhaengig, das Log ist nur
        // Betriebs-Sichtbarkeit.
        if (!$ma->rec_applicant_id) {
            return;
        }

        try {
            $log = new RecAutoPilotLog([
                'rec_applicant_id' => $ma->rec_applicant_id,
                'type'             => $type,
                'summary'          => $summary,
                'details'          => $eintrag,
            ]);
            $log->created_at = now();
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('[ProofReminderSender] Log fehlgeschlagen: ' . $e->getMessage(), [
                'rec_employee_id' => $ma->id, 'type' => $type,
            ]);
        }
    }
}
