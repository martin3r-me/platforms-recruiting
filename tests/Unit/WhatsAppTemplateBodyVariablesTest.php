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
 * WOZU ueberhaupt eine eigene Klasse: der Zertifikat-Versand haengt daran,
 * dass das konfigurierte Template eine Body-Variable {{zertifikat_link}}
 * hat. Fehlt sie, fuellt HoldingTemplateComponents::build() die vorhandenen
 * Variablen mit dem BEISPIELTEXT des Templates (`:45`) — der Send gelingt,
 * wa_sent_at wird gestempelt, und beim abgelehnten Bewerber landet eine
 * Nachricht OHNE Link. Das ist der teuerste Fehlerfall dieses Tasks: er sieht
 * auf jeder Ebene wie Erfolg aus.
 *
 * DIE VERKLAMMERUNG ist der Punkt der Klasse, nicht die Regex. has() und
 * build() lesen dieselben Komponenten mit derselben Regex und demselben
 * BODY-Filter — zwei Stellen, die auseinanderlaufen koennen. Deshalb
 * behauptet dieser Test nicht "has() findet {{x}}", sondern prueft in
 * DENSELBEN Faellen beides:
 *   has() === true   =>  build() traegt den Link
 *   has() === false  =>  build() traegt ihn NICHT
 * Laeuft eine der beiden Stellen weg, wird ein Fall rot.
 */
class WhatsAppTemplateBodyVariablesTest extends TestCase
{
    /** Ein Link, wie ihn route() fuer die Zertifikat-uuid baut. */
    private const LINK = 'https://app.example/recruiting/zertifikat/019845f0-7b4a-7000-8000-8f4a1b2c3d4e';

    // -----------------------------------------------------------------
    // Lesen
    // -----------------------------------------------------------------

    public function testNamenKommenNurAusBodyKomponenten(): void
    {
        $components = [
            ['type' => 'HEADER', 'text' => 'Dein Zertifikat {{kopf_var}}'],
            ['type' => 'BODY', 'text' => "Hallo {{name}},\nhier ist dein Zertifikat: {{zertifikat_link}}"],
            ['type' => 'FOOTER', 'text' => 'Viele Gruesse {{fuss_var}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Oeffnen', 'url' => 'https://example.org/{{1}}'],
            ]],
        ];

        $this->assertSame(
            ['name', 'zertifikat_link'],
            WhatsAppTemplateBodyVariables::names($components),
            'HEADER, FOOTER und BUTTONS zaehlen nicht — build() ignoriert sie ebenfalls.'
        );
        $this->assertTrue(WhatsAppTemplateBodyVariables::has($components, 'zertifikat_link'));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, 'kopf_var'));
    }

    public function testKeineKomponentenKeineVariablen(): void
    {
        $this->assertSame([], WhatsAppTemplateBodyVariables::names(null));
        $this->assertSame([], WhatsAppTemplateBodyVariables::names([]));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has(null, 'zertifikat_link'));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has([], 'zertifikat_link'));

        // Ein Template ohne jede Variable ist der Normalfall unter den
        // genehmigten Templates (Holding, Absage) — und genau der Fall, den HR
        // im Dropdown versehentlich waehlt.
        $ohne = [['type' => 'BODY', 'text' => 'Wir kuemmern uns um deine Bewerbung.']];
        $this->assertSame([], WhatsAppTemplateBodyVariables::names($ohne));
        $this->assertFalse(WhatsAppTemplateBodyVariables::has($ohne, 'zertifikat_link'));
    }

    /**
     * Gross-/Kleinschreibung trennt. {{Zertifikat_Link}} ist bei Meta eine
     * ANDERE Variable als {{zertifikat_link}}: HoldingTemplateComponents
     * vergleicht mit array_key_exists auf $namedValues (`:42`), also exakt.
     * Ein tolerantes has() wuerde hier gruen sagen und der Link kaeme trotzdem
     * nicht an.
     */
    public function testGrossKleinschreibungTrenntWieBeimBuilder(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Link: {{Zertifikat_Link}}']];

        $this->assertFalse(WhatsAppTemplateBodyVariables::has($components, 'zertifikat_link'));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            TrainingCertificateWaTemplate::BODY_VARIABLE => self::LINK,
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
     * has() === true, und der Link landet wirklich im Body-Parameter — mit
     * parameter_name, wie Meta es fuer benannte Variablen verlangt.
     */
    public function testMitVariableTraegtDerLink(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Hallo {{name}}, hier ist dein Zertifikat: {{zertifikat_link}}',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'name', 'example' => 'Max'],
                ['param_name' => 'zertifikat_link', 'example' => 'https://example.org/beispiel'],
            ]],
        ]];

        $this->assertTrue(WhatsAppTemplateBodyVariables::has(
            $components,
            TrainingCertificateWaTemplate::BODY_VARIABLE
        ));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            TrainingCertificateWaTemplate::BODY_VARIABLE => self::LINK,
        ]);

        $this->assertSame(
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Erika', 'parameter_name' => 'name'],
                ['type' => 'text', 'text' => self::LINK, 'parameter_name' => 'zertifikat_link'],
            ]]],
            $gebaut,
            'Der Vorname kommt weiter aus firstName, der Link aus namedValues.'
        );
    }

    /**
     * has() === false, und der Link landet NICHT im Send — statt seiner geht
     * der Beispieltext des Templates raus. GENAU DAS ist der Grund fuer den
     * Guard vor dem Versand: ohne ihn bekaeme der abgelehnte Bewerber
     * "https://example.org/beispiel" statt seines Zertifikats, und der Send
     * gilt als gelungen.
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

        $this->assertFalse(WhatsAppTemplateBodyVariables::has(
            $components,
            TrainingCertificateWaTemplate::BODY_VARIABLE
        ));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            TrainingCertificateWaTemplate::BODY_VARIABLE => self::LINK,
        ]);

        $texte = array_column($gebaut[0]['parameters'], 'text');
        $this->assertSame(['Erika', 'https://example.org/beispiel'], $texte);
        $this->assertNotContains(self::LINK, $texte, 'Kein Link — und kein Fehler. Deshalb der Guard.');
    }

    /**
     * Positionale Variablen sind KEIN Weg fuer den Link: {{1}} gilt dem Builder
     * als Namensvariable (`:37`) und bekommt den Vornamen, egal was in
     * namedValues steht. Ein Template mit "Zertifikat: {{1}}" verschickt also
     * den VORNAMEN als Link-Text.
     */
    public function testPositionaleVariableBekommtDenVornamenNichtDenLink(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Dein Zertifikat: {{1}}']];

        $this->assertFalse(WhatsAppTemplateBodyVariables::has(
            $components,
            TrainingCertificateWaTemplate::BODY_VARIABLE
        ));

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            TrainingCertificateWaTemplate::BODY_VARIABLE => self::LINK,
        ]);

        $this->assertSame([['type' => 'text', 'text' => 'Erika']], $gebaut[0]['parameters']);
    }

    /**
     * G7 als Assertion statt als Satz in der Spec: ein URL-BUTTON mit Variable
     * bekommt von diesem Sendeweg KEINEN Parameter. Deshalb ist die
     * Body-Variable nicht eine von zwei Moeglichkeiten, sondern die einzige.
     *
     * Der Test steht hier, damit der naheliegende Umbau ("dann fuellen wir den
     * Button eben mit") an einer roten Zeile scheitert und nicht an einer
     * Erinnerung: HoldingTemplateComponents bedient auch Holding, Auto-Reply
     * und Voice-Note-Antworten.
     */
    public function testUrlButtonBekommtKeinenParameter(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, dein Zertifikat: {{zertifikat_link}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Zertifikat oeffnen', 'url' => 'https://app.example/z/{{1}}'],
            ]],
        ];

        $gebaut = HoldingTemplateComponents::build($components, 'Erika', [
            TrainingCertificateWaTemplate::BODY_VARIABLE => self::LINK,
        ]);

        $this->assertCount(1, $gebaut, 'Genau eine Komponente — der Body.');
        $this->assertSame('body', $gebaut[0]['type']);
        $this->assertSame(
            [],
            array_filter($gebaut, fn (array $c) => ($c['type'] ?? '') === 'button'),
            'Kein Button-Component, also auch kein Button-Parameter.'
        );
    }

    /**
     * Der Variablenname steht an EINER Stelle. Ohne diese Assertion koennte der
     * Hinweistext im Einstellungs-Modal einen anderen Namen nennen als der
     * Versand einsetzt — und HR haette ein korrekt konfiguriertes Template, das
     * nichts ausliefert.
     */
    public function testDerVariablennameStehtAnEinerStelle(): void
    {
        $this->assertSame('zertifikat_link', TrainingCertificateWaTemplate::BODY_VARIABLE);
        $this->assertSame(
            'training_certificate_wa_template_id',
            TrainingCertificateWaTemplate::SETTINGS_KEY
        );

        $blade = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php'
        );
        $this->assertStringContainsString(
            TrainingCertificateWaTemplate::BODY_VARIABLE,
            (string) $blade,
            'Der Hinweistext im Modal muss denselben Variablennamen nennen.'
        );
        $this->assertStringContainsString(
            'settings.' . TrainingCertificateWaTemplate::SETTINGS_KEY,
            (string) $blade,
            'Ohne das Select im Modal ist der Schluessel nur per SQL setzbar.'
        );
    }
}
