<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;
use Platform\Recruiting\Support\WhatsAppTemplateBodyVariables;

/**
 * Der Lesetest auf die Body-Variablen eines Meta-Templates — und die
 * VERKLAMMERUNG mit dem Builder, der die Variablen wirklich fuellt.
 *
 * WOZU ueberhaupt eine eigene Klasse: HoldingTemplateComponents::build() fuellt
 * eine Variable, die NICHT in $namedValues steht, mit dem BEISPIELTEXT des
 * Templates (`:45`). Der Send gelingt dann, und beim Empfaenger landet der
 * Meta-Beispielwert statt des echten. Das ist der teuerste Fehlerfall dieses
 * Pfads: er sieht auf jeder Ebene wie Erfolg aus. has() ist die Frage, mit der
 * ein Sendeweg das vorher ausschliessen kann.
 *
 * DIE VERKLAMMERUNG ist der Punkt der Klasse, nicht die Regex. has() und
 * build() lesen dieselben Komponenten mit derselben Regex und demselben
 * BODY-Filter — zwei Stellen, die auseinanderlaufen koennen. Deshalb
 * behauptet dieser Test nicht "has() findet {{x}}", sondern prueft in
 * DENSELBEN Faellen beides:
 *   has() === true   =>  build() traegt den Wert
 *   has() === false  =>  build() traegt ihn NICHT
 * Laeuft eine der beiden Stellen weg, wird ein Fall rot.
 *
 * DER ZERTIFIKAT-LINK LAEUFT HIER NICHT MEHR DURCH (Spec W7): er geht als
 * dynamischer URL-Button raus, den dieser Builder strukturell nicht fuellt
 * (testUrlButtonBekommtKeinenParameter). Die Testdaten benutzen deshalb einen
 * neutralen Variablennamen — die Aussagen ueber build() gelten unveraendert, sie
 * gelten nur nicht mehr fuer den Zertifikat-Weg. Was am Zertifikat-Weg statt
 * eines Variablennamens zusammenpassen muss, ist eine FORM: die Button-URL bei
 * Meta. Sie wird abgeleitet, nicht gepflegt — dazu die drei Tests am Ende.
 */
class WhatsAppTemplateBodyVariablesTest extends TestCase
{
    /**
     * Ein beliebiger langer Wert fuer eine benannte Body-Variable.
     *
     * Bewusst KEINE Zertifikat-URL: dieser Builder fuellt Body-Variablen (OOO-
     * Daten, Vertretungen), der Zertifikat-Link steckt im Button.
     */
    private const LINK = 'https://app.example/nachweis/019845f0-7b4a-7000-8000-8f4a1b2c3d4e';

    /** Der Name der Testdaten-Variable — ein Name, kein Verweis auf den Betrieb. */
    private const VARIABLE = 'nachweis_link';

    // -----------------------------------------------------------------
    // Lesen
    // -----------------------------------------------------------------

    public function testNamenKommenNurAusBodyKomponenten(): void
    {
        $components = [
            ['type' => 'HEADER', 'text' => 'Dein Nachweis {{kopf_var}}'],
            ['type' => 'BODY', 'text' => "Hallo {{name}},\nhier ist dein Nachweis: {{nachweis_link}}"],
            ['type' => 'FOOTER', 'text' => 'Viele Gruesse {{fuss_var}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Oeffnen', 'url' => 'https://example.org/{{1}}'],
            ]],
        ];

        $this->assertSame(
            ['name', 'nachweis_link'],
            WhatsAppTemplateBodyVariables::names($components),
            'HEADER, FOOTER und BUTTONS zaehlen nicht — build() ignoriert sie ebenfalls.'
        );
        $this->assertTrue(WhatsAppTemplateBodyVariables::has($components, self::VARIABLE));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, 'kopf_var'));
    }

    public function testKeineKomponentenKeineVariablen(): void
    {
        $this->assertSame([], WhatsAppTemplateBodyVariables::names(null));
        $this->assertSame([], WhatsAppTemplateBodyVariables::names([]));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has(null, self::VARIABLE));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has([], self::VARIABLE));

        // Ein Template ohne jede Variable ist der Normalfall unter den
        // genehmigten Templates (Holding, Absage) — und genau der Fall, den HR
        // im Dropdown versehentlich waehlt.
        $ohne = [['type' => 'BODY', 'text' => 'Wir kuemmern uns um deine Bewerbung.']];
        $this->assertSame([], WhatsAppTemplateBodyVariables::names($ohne));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has($ohne, self::VARIABLE));
    }

    /**
     * Gross-/Kleinschreibung trennt. {{Nachweis_Link}} ist bei Meta eine ANDERE
     * Variable als {{nachweis_link}}: HoldingTemplateComponents vergleicht mit
     * array_key_exists auf $namedValues (`:42`), also exakt. Ein tolerantes
     * has() wuerde hier gruen sagen und der Wert kaeme trotzdem nicht an.
     */
    public function testGrossKleinschreibungTrenntWieBeimBuilder(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Link: {{Nachweis_Link}}']];

        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, self::VARIABLE));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            self::VARIABLE => self::LINK,
        ]);
        $this->assertStringNotContainsString(
            self::LINK,
            json_encode($gebaut),
            'Der Builder ist ebenfalls exakt — deshalb darf has() nicht tolerant sein.'
        );
    }

    // -----------------------------------------------------------------
    // Verklammerung mit dem echten Builder
    // -----------------------------------------------------------------

    /**
     * has() === true, und der Wert landet wirklich im Body-Parameter — mit
     * parameter_name, wie Meta es fuer benannte Variablen verlangt.
     */
    public function testMitVariableTraegtDerLink(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Hallo {{name}}, hier ist dein Nachweis: {{nachweis_link}}',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'name', 'example' => 'Max'],
                ['param_name' => 'nachweis_link', 'example' => 'https://example.org/beispiel'],
            ]],
        ]];

        $this->assertTrue(WhatsAppTemplateBodyVariables::has($components, self::VARIABLE));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            self::VARIABLE => self::LINK,
        ]);

        $this->assertSame(
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Erika', 'parameter_name' => 'name'],
                ['type' => 'text', 'text' => self::LINK, 'parameter_name' => 'nachweis_link'],
            ]]],
            $gebaut,
            'Der Vorname kommt weiter aus firstName, der Wert aus namedValues.'
        );
    }

    /**
     * has() === false, und der Wert landet NICHT im Send — statt seiner geht der
     * Beispieltext des Templates raus. GENAU DAS ist der Grund, warum ein
     * Sendeweg vor dem Send fragen muss, ob die Variable ueberhaupt da ist:
     * ohne die Frage bekaeme der Empfaenger "https://example.org/beispiel", und
     * der Send gilt als gelungen.
     */
    public function testOhneVariableGehtDerBeispieltextRausUndNichtDerLink(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Hallo {{name}}, dein Nachweis liegt hier: {{link}}',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'name', 'example' => 'Max'],
                ['param_name' => 'link', 'example' => 'https://example.org/beispiel'],
            ]],
        ]];

        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, self::VARIABLE));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            self::VARIABLE => self::LINK,
        ]);

        $texte = array_column($gebaut[0]['parameters'], 'text');
        $this->assertSame(['Erika', 'https://example.org/beispiel'], $texte);
        $this->assertNotContains(self::LINK, $texte, 'Kein Wert — und kein Fehler. Deshalb die Vorpruefung.');
    }

    /**
     * Positionale Variablen sind KEIN Weg fuer einen eigenen Wert: {{1}} gilt
     * dem Builder als Namensvariable (`:37`) und bekommt den Vornamen, egal was
     * in namedValues steht. Ein Template mit "Nachweis: {{1}}" verschickt also
     * den VORNAMEN als Link-Text.
     */
    public function testPositionaleVariableBekommtDenVornamenNichtDenLink(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Dein Nachweis: {{1}}']];

        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, self::VARIABLE));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            self::VARIABLE => self::LINK,
        ]);

        $this->assertSame([['type' => 'text', 'text' => 'Erika']], $gebaut[0]['parameters']);
    }

    /**
     * Ein URL-BUTTON mit Variable bekommt von DIESEM Builder keinen Parameter —
     * er baut ausschliesslich den Body.
     *
     * DIE BEGRUENDUNG HAT SICH GEDREHT, die Assertion nicht (Spec W3/W4).
     * Vorher stand hier: "deshalb ist die Body-Variable die einzige
     * Moeglichkeit". Jetzt steht hier: deshalb haengt der Zertifikat-Versand
     * seinen Button-Component SELBST an die von build() gelieferten Komponenten
     * an — build() liefert nur den Body, und das soll so bleiben.
     *
     * Der Test steht damit weiter gegen denselben Umbau ("dann fuellen wir den
     * Button eben hier mit"): HoldingTemplateComponents bedient auch Holding,
     * Auto-Reply und Voice-Note-Antworten (Spec H6) — ein Button-Zweig hier
     * riskiert drei fremde Sendewege fuer einen.
     */
    public function testUrlButtonBekommtKeinenParameter(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, dein Nachweis: {{nachweis_link}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Nachweis oeffnen', 'url' => 'https://app.example/z/{{1}}'],
            ]],
        ];

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            self::VARIABLE => self::LINK,
        ]);

        $this->assertCount(1, $gebaut, 'Genau eine Komponente — der Body.');
        $this->assertSame('body', $gebaut[0]['type']);
        $this->assertSame(
            [],
            array_filter($gebaut, fn (array $c) => ($c['type'] ?? '') === 'button'),
            'Kein Button-Component, also auch kein Button-Parameter.'
        );
    }

    // -----------------------------------------------------------------
    // Die Namen und die Form des Zertifikat-Wegs
    // -----------------------------------------------------------------

    /**
     * Die Namen und die FORM stehen an einer Stelle.
     *
     * Vorher hing hier der Body-Variablenname; mit dem Button gibt es keinen
     * mehr, dafuer eine Form, die zusammenpassen muss: die bei Meta hinterlegte
     * Button-URL endet auf das Pfadsegment der Zertifikat-Route mit {{1}}.
     *
     * DIE FORM WIRD ABGELEITET, NICHT GEPFLEGT (Spec W7/B1): ein
     * handgeschriebener String waere eine dritte Stelle mit derselben Annahme —
     * die Route registriert nur /zertifikat/{uuid}, das Praefix kommt aus dem
     * ServiceProvider. Was die Ableitung im Betrieb ergibt, prueft
     * TrainingCertificatePublicRouteTest gegen den echten Router; hier steht
     * nur der Sentinel-Tausch, und der ist eine pure Funktion.
     */
    public function testFormDerButtonUrlEntstehtAusDerRoute(): void
    {
        $this->assertSame(0, TrainingCertificateWaTemplate::URL_BUTTON_INDEX);

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}',
            TrainingCertificateWaTemplate::metaButtonUrlFrom(
                'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/'
                . TrainingCertificateWaTemplate::UUID_SENTINEL
            )
        );
    }

    /**
     * Der Sentinel muss die URL-Kodierung ueberleben.
     *
     * GEMESSEN, nicht vermutet: {{1}} direkt durch route() zu schicken ergibt
     * %7B%7B1%7D%7D — deshalb ein Wort als Platzhalter. Ein Sentinel mit
     * Sonderzeichen haette denselben Fehler an anderer Stelle.
     */
    public function testSentinelIstUrlsicher(): void
    {
        $sentinel = TrainingCertificateWaTemplate::UUID_SENTINEL;

        $this->assertSame($sentinel, rawurlencode($sentinel), 'Der Sentinel darf nicht kodiert werden.');
        $this->assertNotSame('', $sentinel);
    }

    /**
     * Das Einstellungs-Modal nennt keine URL als Literal.
     *
     * Ohne diese Assertion waere die Ableitung gebaut und danach umgangen: ein
     * hartkodierter Hinweistext ueberlebt jeden Praefix- und Domainwechsel
     * still falsch.
     */
    public function testDasModalNenntKeineHartkodierteZertifikatUrl(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php'
        );

        $this->assertStringContainsString(
            'settings.' . TrainingCertificateWaTemplate::SETTINGS_KEY,
            $blade,
            'Ohne das Select im Modal ist der Schluessel nur per SQL setzbar.'
        );
        $this->assertStringContainsString(
            'metaButtonUrl',
            $blade,
            'Der Hinweistext muss die abgeleitete Form zeigen, keine getippte URL.'
        );
        $this->assertStringNotContainsString(
            '/recruiting/zertifikat/',
            $blade,
            'Kein URL-Literal in der Blade — die Form kommt aus der Route.'
        );
        $this->assertStringContainsString(
            'URL-Button mit Variable an erster Position',
            $blade,
            'Der Hinweistext muss den Button verlangen — der Body-Weg ist ersetzt, '
            . 'nicht als Fallback behalten.'
        );
    }
}
