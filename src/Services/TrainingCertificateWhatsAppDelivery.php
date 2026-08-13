<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;
use Platform\Recruiting\Support\WhatsAppTemplateBodyVariables;

/**
 * Schickt den Link auf das ausgestellte Schulungszertifikat per WhatsApp —
 * Weg (a), NACH dem Commit der Ablehnung.
 *
 * WARUM DIE LOGIK HIER LIEGT UND NICHT IN DER LIVEWIRE-KOMPONENTE: das ist die
 * Stelle, an der ein Fehler direkt beim abgelehnten Bewerber ankommt, und
 * Livewire-Komponenten sind im Modul nicht instanziierbar (kein
 * Laravel-Bootstrap in den Tests). In HrDesk/Index::confirmResolve() stehen
 * deshalb nur Aufruf und Flash; jede Entscheidung — welches Zertifikat, welche
 * Nummer, welcher Link, senden oder nicht — steht hier und ist in
 * TrainingCertificateWhatsAppDeliveryTest gemessen.
 *
 * §D5 — EIN SENDEFEHLER KIPPT DIE ABLEHNUNG NICHT. Der Aufruf erfolgt nach dem
 * Commit, und diese Methode wirft bei einem Sendefehler nicht: sendToMany faengt
 * Throwables nur INNERHALB der Empfaenger-Schleife (`:72-74`), alles davor
 * (resolveConfig mit vier Queries) laeuft ungeschuetzt. Deshalb steht hier ein
 * eigenes catch um den Send. Rueckgabe statt Exception, damit HR eine Meldung
 * bekommt statt eines Livewire-Fehlers ueber einem offenen Modal.
 *
 * NICHT im catch: das Setzen von wa_sent_at nach einem GELUNGENEN Send. Wenn
 * dieses UPDATE scheitert, ist die Nachricht schon raus — „Versand
 * fehlgeschlagen" waere dann die falsche Aussage, und der einzige denkbare Grund
 * (Verbindung weg, unmittelbar nach einer committeten Transaktion) gehoert
 * gesehen und nicht geschluckt. Die Ablehnung ist auch dann committet.
 *
 * DER LINK TRAEGT DIE ZERTIFIKAT-uuid (§D1), nicht den Applicant-Token — siehe
 * TrainingCertificateWaTemplate::ROUTE_NAME.
 *
 * WAS DIESE KLASSE NICHT TUT: ausstellen. Das passiert in der Transaktion der
 * Ablehnung (HrDeskRoutingService::applyRejection). Findet sich hier kein
 * Zertifikat, wird auch keines angelegt — ein Versand, der sein eigenes
 * Dokument erzeugt, waere ein zweiter Ausstellungsweg ohne den Team-Schalter
 * davor.
 */
class TrainingCertificateWhatsAppDelivery
{
    /** Versendet, wa_sent_at gestempelt. */
    public const STATUS_SENT = 'sent';

    /** wa_sent_at war schon gesetzt — es ging nichts raus. */
    public const STATUS_ALREADY_SENT = 'already_sent';

    /** Kein Zertifikat dieser Schulungsart vorhanden. */
    public const STATUS_NO_CERTIFICATE = 'no_certificate';

    /** Bewerber ohne verwendbare Telefonnummer. */
    public const STATUS_NO_PHONE = 'no_phone';

    /** Kein Template in den Team-Einstellungen. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /** Template konfiguriert, aber ohne die Body-Variable fuer den Link. */
    public const STATUS_TEMPLATE_WITHOUT_VARIABLE = 'template_without_variable';

    /** Der Sender hat es versucht und es ist schiefgegangen. */
    public const STATUS_FAILED = 'failed';

    /**
     * @return array{status: string, error: ?string, link: ?string}
     *
     * `error` ist die fertige, HR-lesbare Meldung (oder null, wenn es nichts zu
     * melden gibt) — die Komponente flasht sie unveraendert. Der Text gehoert
     * hierher und nicht in die Blade, weil er sonst nicht testbar ist.
     */
    public function deliver(RecApplicant $applicant): array
    {
        // Team des BEWERBERS, nicht das aktive Team des Bedieners: Zertifikat,
        // Einstellungen und Template muessen aus derselben Quelle kommen wie das
        // Dokument selbst (IssueTrainingCertificateService macht es genauso).
        $teamId = (int) $applicant->team_id;

        $certificate = RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicant->id)
            // MIT kind-Filter: die Dedup-Dimension der Tabelle ist (Bewerber,
            // Art). Ohne ihn verlinkte der Versand irgendein Zertifikat des
            // Bewerbers, sobald es eine zweite Schulungsart gibt.
            ->where('kind', RecTrainingCertificate::KIND_SERVICE_BASIS)
            ->first();

        if ($certificate === null) {
            return $this->fehler(
                self::STATUS_NO_CERTIFICATE,
                'Es ist kein Schulungszertifikat vorhanden — es wurde keines ausgestellt '
                . '(der Grund steht im Verlauf des Bewerbers). Es ging keine WhatsApp-Nachricht raus.'
            );
        }

        // Ein zweiter Versand ist erreichbar: ein Bewerber kann zwei offene
        // HR-Faelle haben, und die zweite Ablehnung mit Haken bekommt dasselbe
        // Zertifikat zurueck (firstOrCreate-Semantik). Der Bewerber saehe dann
        // dieselbe Nachricht zweimal. Der Guard haengt an wa_sent_at und nicht an
        // einem Zaehler, damit ein FEHLGESCHLAGENER Versand wiederholbar bleibt.
        if ($certificate->wa_sent_at !== null) {
            return ['status' => self::STATUS_ALREADY_SENT, 'error' => null, 'link' => null];
        }

        $phone = $applicant->primaryContactPhone();
        if ($phone === null) {
            return $this->fehler(
                self::STATUS_NO_PHONE,
                'Zertifikat ausgestellt, aber der Bewerber hat keine hinterlegte Telefonnummer'
                . $this->vonHand()
            );
        }

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $templateId = (int) ($settings->getSetting(TrainingCertificateWaTemplate::SETTINGS_KEY) ?? 0);

        if ($templateId <= 0) {
            // Eigener Zweig, obwohl der Sender das auch meldet: resolveConfig
            // antwortet fuer JEDEN Settings-Key mit „Kein
            // Eingangsbestaetigungs-Template konfiguriert (Einstellungen →
            // Kommunikation)". Das schickte HR in die falsche Einstellung.
            return $this->fehler(
                self::STATUS_NOT_CONFIGURED,
                'Zertifikat ausgestellt, aber es ist kein WhatsApp-Template fuer den Versand '
                . 'konfiguriert (Bewerber-Einstellungen → „Schulungszertifikat — WhatsApp-Template '
                . 'mit Link")' . $this->vonHand()
            );
        }

        $template = class_exists(IntegrationsWhatsAppTemplate::class)
            ? IntegrationsWhatsAppTemplate::find($templateId)
            : null;

        // Nur wenn die Zeile WIRKLICH da ist, wird ueber ihren Inhalt geurteilt.
        // Fehlt sie (bei Meta geloescht, Integration nicht installiert), ist
        // „keine Body-Variable" die falsche Diagnose — diese Faelle beantwortet
        // resolveConfig praeziser, also weiterlaufen lassen.
        if ($template !== null && !WhatsAppTemplateBodyVariables::has(
            $template->components,
            TrainingCertificateWaTemplate::BODY_VARIABLE
        )) {
            $gefunden = WhatsAppTemplateBodyVariables::names($template->components);

            return $this->fehler(
                self::STATUS_TEMPLATE_WITHOUT_VARIABLE,
                sprintf(
                    'Zertifikat ausgestellt, aber das WhatsApp-Template „%s" hat keine Body-Variable '
                    . '{{%s}} (gefunden: %s). Es wurde NICHTS versendet — sonst waere eine Nachricht '
                    . 'ohne Link rausgegangen%s',
                    (string) $template->name,
                    TrainingCertificateWaTemplate::BODY_VARIABLE,
                    $gefunden === [] ? 'keine Variable' : '{{' . implode('}}, {{', $gefunden) . '}}',
                    $this->vonHand()
                )
            );
        }

        $link = route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => $certificate->uuid]);
        $firstName = $this->firstName($applicant);

        try {
            $result = app(HoldingTemplateSender::class)->sendOne(
                $teamId,
                $phone,
                $firstName,
                TrainingCertificateWaTemplate::SETTINGS_KEY,
                [TrainingCertificateWaTemplate::BODY_VARIABLE => $link],
            );
        } catch (\Throwable $e) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist fehlgeschlagen: '
                . $e->getMessage() . $this->vonHand()
            );
        }

        if ((int) ($result['sent'] ?? 0) < 1) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist fehlgeschlagen: '
                . ($result['error'] ?? 'Der Sender hat keine Nachricht abgesetzt (Nummer oder '
                    . 'Template-Parameter abgelehnt).')
                . $this->vonHand(),
                $link
            );
        }

        // Bewusst NICHT in einem try: siehe Klassen-Docblock.
        $certificate->update(['wa_sent_at' => Carbon::now()]);

        return ['status' => self::STATUS_SENT, 'error' => null, 'link' => $link];
    }

    /**
     * Der Vorname fuer die Anrede.
     *
     * Der selbst eingetragene Vorname aus dem Bewerbungsformular gewinnt
     * (dasselbe Muster wie die Jugendschutz-Absage). Der Kontakt-Fallback nimmt
     * die KLEINSTE contact_id — nicht ->first(): crmContactLinks ist ein
     * morphMany ohne Ordering (Spec F11), und dieselbe Wahl trifft
     * IssueTrainingCertificateService::contactOf fuer den Namen AUF dem
     * Zertifikat. Ohne sie koennte die Anrede der Nachricht einen anderen Namen
     * tragen als das Dokument, auf das sie verlinkt.
     */
    private function firstName(RecApplicant $applicant): string
    {
        $eigen = trim((string) ($applicant->getExtraField('vorname') ?? ''));
        if ($eigen !== '') {
            return $eigen;
        }

        $contact = $applicant->crmContactLinks()
            ->with('contact')
            ->orderBy('contact_id')
            ->first()
            ?->contact;

        return trim((string) ($contact->first_name ?? ''));
    }

    /**
     * Der Satz, der an JEDER Fehlermeldung haengt: HR hat einen Weg, und der
     * steht in der Meldung. Eine Fehlermeldung ohne Handlungsanweisung fuehrt
     * hier zu einem Bewerber, der sein Zertifikat nie bekommt.
     */
    private function vonHand(): string
    {
        return ' — bitte das PDF herunterladen und manuell senden.';
    }

    /** @return array{status: string, error: ?string, link: ?string} */
    private function fehler(string $status, string $meldung, ?string $link = null): array
    {
        return ['status' => $status, 'error' => $meldung, 'link' => $link];
    }
}
