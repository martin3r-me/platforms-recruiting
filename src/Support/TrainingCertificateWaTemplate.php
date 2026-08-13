<?php

namespace Platform\Recruiting\Support;

/**
 * Zwei Namen und eine FORM, an denen die WhatsApp-Zustellung des
 * Schulungszertifikats haengt — an EINER Stelle.
 *
 * Die Namen muessen zusammenpassen, stehen aber an verschiedenen Orten im
 * Betrieb: die Einstellung im Bewerber-Einstellungs-Modal, der Route-Name in
 * routes/public.php und der Versand in TrainingCertificateWhatsAppDelivery. Ein
 * Tippfehler faellt erst beim abgelehnten Bewerber auf — deshalb Konstanten und
 * nicht Literale.
 *
 * DIE FORM IST NEU AN DIE STELLE DER BODY-VARIABLE GETRETEN (Spec W7): der Link
 * geht als dynamischer URL-Button raus, es gibt also keinen Variablennamen mehr,
 * den zwei Seiten gleich schreiben muessen. Stattdessen muss die bei Meta
 * hinterlegte Button-URL auf das Pfadsegment der Zertifikat-Route enden.
 * metaButtonUrlFrom() erzeugt diese Form aus der Route, statt sie als String zu
 * pflegen — die Begruendung steht dort.
 *
 * Laravel-frei, damit der Unit-Test die Namen und den Sentinel-Tausch pruefen
 * kann, ohne einen Container zu bauen (WhatsAppTemplateBodyVariablesTest
 * ::testFormDerButtonUrlEntstehtAusDerRoute). route() ruft diese Klasse deshalb
 * NICHT selbst auf.
 */
final class TrainingCertificateWaTemplate
{
    /**
     * Team-Einstellung mit der ID des genehmigten Meta-Templates.
     *
     * Eigener Schluessel und NICHT comms_holding_template_id: dieses Template
     * braucht den dynamischen URL-Button unten, die Holding-/Auto-Reply-
     * Templates haben ihn nicht.
     */
    public const SETTINGS_KEY = 'training_certificate_wa_template_id';

    /**
     * Urlsicherer Platzhalter fuer die Ableitung der Meta-Button-URL.
     *
     * GEMESSEN, nicht vermutet: {{1}} direkt durch route() zu schicken ergibt
     * %7B%7B1%7D%7D — die Klammern werden kodiert. Deshalb ein Wort durch
     * route() schicken und danach tauschen.
     */
    public const UUID_SENTINEL = 'ZERTUUIDPLATZHALTER';

    /**
     * Die Position des dynamischen URL-Buttons im Meta-Template.
     *
     * Eine echte geteilte Zahl: der Sendepfad setzt den Parameter auf diesen
     * index, und der Guard prueft genau diese Position. Alle sechs
     * Sendestellen im Modul hardcodieren 0 (Spec H3) — die Zahl ist damit
     * geteilte Annahme, nicht Wahrheit; der Fall „Button an Position 1" wird
     * hier sichtbar gemacht statt falsch gesendet.
     */
    public const URL_BUTTON_INDEX = 0;

    /**
     * Die oeffentliche PDF-Route (Task 10), adressiert ueber die
     * Zertifikat-uuid.
     *
     * NICHT ueber den Applicant-Token: der oeffnet Bewerbungsformular,
     * Vertrags-PDFs und die ganze Vertragsliste, unbegrenzt und ohne Rotation.
     * Die uuid oeffnet genau ein Dokument. Der Parametername ist deshalb
     * 'uuid' — festgenagelt in TrainingCertificatePublicRouteTest.
     */
    public const ROUTE_NAME = 'recruiting.public.training-certificate';

    /**
     * Aus einer mit UUID_SENTINEL gebauten Route-URL die Form machen, die im
     * Meta-Template hinter dem Button stehen muss.
     *
     * WARUM ABGELEITET UND NICHT ALS KONSTANTE: die Route registriert nur
     * /zertifikat/{uuid} (routes/public.php), das Praefix "recruiting" kommt aus
     * RecruitingServiceProvider.php:128, und der Host kommt aus dem Request. Ein
     * getippter String waere eine dritte Stelle mit derselben Annahme — und ein
     * Praefixwechsel haette ihn still falsch gemacht, statt einen Test rot zu
     * machen. Die Erwartung an das Ergebnis steht deshalb im Test
     * (TrainingCertificatePublicRouteTest), nicht hier.
     *
     * DIESE KLASSE BLEIBT LARAVEL-FREI: sie ruft route() nicht selbst, sondern
     * nimmt die fertige URL. Den Aufruf macht die Livewire-Komponente, die den
     * Hinweistext rendert.
     */
    public static function metaButtonUrlFrom(string $routeUrlWithSentinel): string
    {
        return str_replace(self::UUID_SENTINEL, '{{1}}', $routeUrlWithSentinel);
    }
}
