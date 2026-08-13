<?php

namespace Platform\Recruiting\Support;

/**
 * Die drei Namen, an denen die WhatsApp-Zustellung des Schulungszertifikats
 * haengt — an EINER Stelle.
 *
 * Sie muessen zusammenpassen, stehen aber an vier verschiedenen Orten im
 * Betrieb: die Einstellung im Bewerber-Einstellungs-Modal, die Body-Variable im
 * bei Meta genehmigten Template, der Route-Name in routes/public.php und der
 * Versand in TrainingCertificateWhatsAppDelivery. Drei Strings, vier Orte, und
 * ein Tippfehler faellt erst beim abgelehnten Bewerber auf — deshalb Konstanten
 * und nicht Literale.
 *
 * Laravel-frei, damit der Unit-Test die Namen gegen die Blade-Datei pruefen
 * kann, ohne einen Container zu bauen (WhatsAppTemplateBodyVariablesTest
 * ::testDerVariablennameStehtAnEinerStelle).
 */
final class TrainingCertificateWaTemplate
{
    /**
     * Team-Einstellung mit der ID des genehmigten Meta-Templates.
     *
     * Eigener Schluessel und NICHT comms_holding_template_id: dieses Template
     * braucht die Body-Variable unten, die Holding-/Auto-Reply-Templates haben
     * sie nicht.
     */
    public const SETTINGS_KEY = 'training_certificate_wa_template_id';

    /**
     * Die Body-Variable, in der der Link steckt: {{zertifikat_link}}.
     *
     * BODY-Variable und KEIN URL-Button, und das ist keine Stilfrage:
     * HoldingTemplateComponents::build() iteriert nur ueber Komponenten mit
     * type === 'BODY' — ein URL-Button bekaeme keinen Parameter und Meta wiese
     * den Send ab (Spec G7). Der einzige Pfad im Modul, der URL-Buttons fuellt
     * (Applicant/Show::sendManualTemplate), setzt dort den
     * Bewerber-FORMULAR-Token ein (G8) und ist damit erst recht keine Option.
     *
     * SO ist das Template zu bauen, wenn hier jemand einen Button vermisst:
     * Fliesstext mit {{zertifikat_link}} an der Stelle, an der die URL stehen
     * soll. WhatsApp macht URLs im Text automatisch klickbar. Der Umbau von
     * HoldingTemplateComponents ist der falsche Weg — der Pfad bedient auch
     * Holding, Auto-Reply und Voice-Note-Antworten.
     */
    public const BODY_VARIABLE = 'zertifikat_link';

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
}
