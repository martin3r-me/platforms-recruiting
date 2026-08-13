<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

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
 * WARUM DIREKT WhatsAppMetaService::sendTemplate() UND NICHT HoldingTemplateSender:
 * der Link steckt ab jetzt in einem dynamischen URL-Button, und
 * HoldingTemplateComponents::build() kann strukturell keine Buttons — es
 * iteriert nur ueber type === 'BODY'. Den Builder zu erweitern faellt in den
 * Pfad, der auch Holding-Bestaetigung, OOO-Auto-Reply und Voice-Note-Antworten
 * bedient; deshalb ein eigener Sendepfad nach dem Muster der sechs bestehenden
 * Button-Stellen im Modul (naechste Vorlage: RecInterview.php:204-216). Template
 * und Kanal kommen weiter aus dem Sender, lesend (resolveTarget), und den Body
 * baut build() weiter — beides AUFRUFE, keine Erweiterungen.
 *
 * NICHT das Muster von Applicant/Show.php:543-552: der Block dort setzt den
 * Bewerber-FORMULAR-Token in jeden URL-Button, sobald das Template irgendeinen
 * hat — ohne zu pruefen, wohin er zeigt und ob seine URL ueberhaupt eine
 * Variable traegt. Hier kommt der Wert aus dem Zertifikat, das ohnehin in der
 * Hand ist, und der Guard ist die SENDEBEDINGUNG statt eines Ausloesers. In
 * dieser Klasse steht deshalb kein getPublicUrl(), kein
 * getOrCreatePublicFormLink() und kein portal_token — festgenagelt in
 * WhatsAppTemplateBodyVariablesTest.
 *
 * DER BUTTON-PARAMETER IST DIE uuid, NICHT DIE URL. Die Basis-URL
 * (https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/) steht im bei Meta
 * genehmigten Template; das Modul liefert nur das letzte Pfadsegment. Preis,
 * benannt: aendert sich die Domain, muss sie bei Meta nachgezogen werden, und
 * kein Guard hier kann das sehen. Die vollstaendige URL wird trotzdem weiter
 * gebaut — fuer die Rueckgabe und die Fehlermeldungen, damit HR von Hand
 * senden kann.
 *
 * DIESE KLASSE GEHOERT IN EINEN REQUEST, NICHT IN EINEN JOB. `sender:
 * auth()->user()` traegt den ausloesenden Benutzer in die Nachricht; einziger
 * Aufrufer ist HrDesk/Index::confirmResolve(), also ein Livewire-Request mit
 * angemeldetem Benutzer. Aus einem Command oder Queue-Job aufgerufen ist der
 * Absender still `null` — kein Fehler, nur eine Nachricht ohne Urheber. Wer sie
 * dort braucht, uebergibt den Benutzer, statt sich auf auth() zu verlassen.
 *
 * ES GIBT KEINEN WIEDERVERSAND-WEG. Gemessen: deliver() hat genau einen
 * Aufrufer (HrDesk/Index.php:270), wa_sent_at wird nirgends geleert, und es gibt
 * keinen „erneut senden"-Knopf. Der einzige zweite Eintritt ist eine zweite
 * Ablehnung mit Haken, und die faellt in STATUS_ALREADY_SENT. Wer einen
 * Wiederversand baut, muss ihn durch DIESE Methode fuehren — sonst hat der
 * Guard eine zweite Tuer, an der er nicht steht.
 *
 * §D5 — EIN SENDEFEHLER KIPPT DIE ABLEHNUNG NICHT. Der Aufruf erfolgt nach dem
 * Commit, und diese Methode wirft bei einem Sendefehler nicht. Mit dem Wechsel
 * auf den direkten Send ist das interne catch von sendToMany (`:72-74`)
 * weggefallen; das eigene catch hier traegt den ganzen Send allein. Rueckgabe
 * statt Exception, damit HR eine Meldung bekommt statt eines Livewire-Fehlers
 * ueber einem offenen Modal.
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

    /**
     * Template konfiguriert, aber ohne dynamischen URL-Button an Position 0.
     *
     * ZWEI FAELLE, EIN STATUS: gar kein dynamischer Button, oder einer an der
     * falschen Position. Fuer den Aufrufer ist beides dasselbe Ereignis (es
     * ging nichts raus); unterschiedlich ist nur die Anweisung an HR, und die
     * steht in der Meldung.
     */
    public const STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button';

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

        // Template UND Kanal aus einer Aufloesung (Spec W2). Vorher holte diese
        // Klasse das Template selbst nur fuer den Guard, waehrend der Sender es
        // unabhaengig ein zweites Mal aufloeste — zwei Lookups derselben ID.
        // Jetzt prueft der Guard genau das Template, das gleich gesendet wird.
        $target = app(HoldingTemplateSender::class)
            ->resolveTarget($teamId, TrainingCertificateWaTemplate::SETTINGS_KEY);

        $link = route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => $certificate->uuid]);

        if ($target['error'] !== null) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist nicht moeglich: '
                . $target['error'] . $this->vonHand(),
                $link,
                'Aufloesung von Template oder Kanal fehlgeschlagen',
                ['grund' => $target['error']]
            );
        }

        $template = $target['template'];
        $components = $template->components ?? [];

        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($components, TrainingCertificateWaTemplate::URL_BUTTON_INDEX)) {
            return $this->fehler(
                self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
                $this->buttonMeldung($template, $components),
                $link,
                'Template ohne dynamischen URL-Button an Position '
                . TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
                ['template' => (string) $template->name]
            );
        }

        // Leerwert-Riegel wie RecInterview.php:208. Praktisch unerreichbar (die
        // uuid entsteht bei der Ausstellung), aber ein Button-Parameter mit
        // leerem Text ist Meta 131008 — dieselbe Klasse Fehler, die
        // hasEmptyRequiredParam fuer den Body abfaengt.
        $uuid = trim((string) $certificate->uuid);
        if ($uuid === '') {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber es hat keine Kennung fuer den Link'
                . $this->vonHand(),
                null,
                'Zertifikat ohne uuid',
                ['zertifikat' => (int) $certificate->id]
            );
        }

        $firstName = $this->firstName($applicant);

        // Der Body kommt weiter von HoldingTemplateComponents::build() — das ist
        // ein AUFRUF, keine Erweiterung (Spec W3). Ein eigener Body-Parser waere
        // die achte Kopie derselben {{…}}-Schleife im Modul. $namedValues bleibt
        // leer: der Link steckt jetzt im Button, und damit ist auch der
        // Beispieltext-Mechanismus aus build():45 fuer ihn kein Risiko mehr.
        $sendComponents = HoldingTemplateComponents::build($components, $firstName);

        // Im Sender fuehrte ein leerer Pflicht-Parameter zu einem stillen
        // `skipped` (`:56-59`). Der direkte Pfad hat die Bremse nicht geerbt und
        // braucht sie ausdruecklich: erreichbar bei einem Bewerber ohne
        // aufloesbaren Vornamen und einem Template mit Anrede-Variable.
        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber eine Pflichtangabe der Nachricht ist leer '
                . '(meist der Vorname) — Meta lehnt solche Sends ab' . $this->vonHand(),
                $link,
                'Leerer Pflicht-Parameter im Body',
                ['template' => (string) $template->name]
            );
        }

        $sendComponents[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
            'parameters' => [['type' => 'text', 'text' => $uuid]],
        ];

        try {
            app(WhatsAppMetaService::class)->sendTemplate(
                channel: $target['channel'],
                to: $phone,
                templateName: (string) $template->name,
                components: $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
                sender: auth()->user(),
            );
        } catch (\Throwable $e) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist fehlgeschlagen: '
                . $e->getMessage() . $this->vonHand(),
                $link,
                'sendTemplate hat geworfen',
                ['fehler' => $e->getMessage()]
            );
        }

        // Bewusst NICHT in einem try: siehe Klassen-Docblock. Und es ist der
        // einzige Schritt nach dem Send — ein addContext() auf den Thread waere
        // eine Verhaltensaenderung ueber den Auftrag hinaus und steht als
        // eigener Punkt in docs/zertifikat/folgeliste.md (F10).
        $certificate->update(['wa_sent_at' => Carbon::now()]);

        return ['status' => self::STATUS_SENT, 'error' => null, 'link' => $link];
    }

    /**
     * Die Meldung fuer den Guard-Zweig — und sie muss die ANWEISUNG sagen,
     * nicht nur den Befund.
     *
     * Zwei Faelle, zwei Anweisungen: sitzt der dynamische Button an einer
     * anderen Position, ist „kein URL-Button gefunden" schlicht falsch und
     * schickt HR in die Suche nach einem Button, den es gibt. Deshalb liefert
     * WhatsAppTemplateUrlButtons Positionen und nicht bool.
     *
     * @param  array<int, mixed>  $components
     */
    private function buttonMeldung($template, array $components): string
    {
        $gefunden = WhatsAppTemplateUrlButtons::describe($components);
        $liste = $gefunden === [] ? 'keine Buttons' : implode(', ', $gefunden);
        $dynamisch = WhatsAppTemplateUrlButtons::dynamicIndexes($components);

        if ($dynamisch !== []) {
            return sprintf(
                'Zertifikat ausgestellt, aber im WhatsApp-Template „%s" sitzt der URL-Button mit '
                . 'Variable an Position %d statt an Position %d. Bitte ihn im Meta-Template an die '
                . 'erste Position verschieben (gefunden: %s). Es wurde NICHTS versendet%s',
                (string) $template->name,
                $dynamisch[0],
                TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
                $liste,
                $this->vonHand()
            );
        }

        return sprintf(
            'Zertifikat ausgestellt, aber das WhatsApp-Template „%s" hat keinen URL-Button mit '
            . 'Variable (gefunden: %s). Die Button-URL muss auf %s enden. Es wurde NICHTS '
            . 'versendet — sonst waere eine Nachricht ohne Link rausgegangen%s',
            (string) $template->name,
            $liste,
            'die Zertifikat-Route mit {{1}} am Ende',
            $this->vonHand()
        );
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

    /**
     * @param  array<string, mixed>  $kontext
     * @return array{status: string, error: ?string, link: ?string}
     *
     * VIER URSACHEN FALLEN AUF `failed`, und der Statuswert unterscheidet sie
     * nicht (Spec W8). Eigene Statuswerte waeren vier Zweige in
     * HrDesk/Index::confirmResolve(), die alle dasselbe taeten — fuer die
     * Diagnose reicht ein unterscheidbarer Log-Marker. Der Guard-Zweig wird
     * mitgeloggt, obwohl er kein Fehler ist: er wird nach dem Deploy der
     * haeufigste sein, und ohne Logzeile ist er nur so lange sichtbar, wie der
     * Flash am Bildschirm steht.
     *
     * Die vier Zweige oberhalb der Aufloesung (no_certificate, already_sent,
     * no_phone, not_configured) rufen ohne Marker — Zustaende des Bewerbers oder
     * der Konfiguration, keine Stoerungen des Versands.
     */
    private function fehler(
        string $status,
        string $meldung,
        ?string $link = null,
        ?string $logMarker = null,
        array $kontext = []
    ): array {
        if ($logMarker !== null) {
            Log::error('[TrainingCertificateWhatsAppDelivery] ' . $logMarker, $kontext + ['status' => $status]);
        }

        return ['status' => $status, 'error' => $meldung, 'link' => $link];
    }
}
