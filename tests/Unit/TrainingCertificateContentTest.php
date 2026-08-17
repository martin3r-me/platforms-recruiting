<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificateContent;

/**
 * Der INHALT des Zertifikats (fester Text + die fuenf Platzhalterwerte).
 *
 * Der Inhalt ist mit dem Zuschnitt v3 keine Vorlagenzeile mehr, sondern Code —
 * damit ist er hier unit-testbar, ohne DB und ohne Laravel.
 */
class TrainingCertificateContentTest extends TestCase
{
    /** @return array<string,string> */
    private function values(array $overrides = []): array
    {
        return array_merge([
            'kontakt_vorname' => 'Erika',
            'kontakt_nachname' => 'Mustermann',
            'schulung_datum' => '24.07.2026',
            'datum_heute' => '12.08.2026',
        ], $overrides);
    }

    /**
     * Die vier Werte landen im Dokument, und danach steht KEIN {{...}} mehr
     * darin. Die zweite Haelfte ist die wichtigere: ein Zertifikat mit
     * "{{schulung_datum}}" im Text geht an den Bewerber, ohne dass irgendwo
     * ein Fehler auftaucht.
     */
    public function testAlleVierWerteWerdenEingesetzt(): void
    {
        $content = TrainingCertificateContent::render($this->values());

        $this->assertStringContainsString('Erika', $content);
        $this->assertStringContainsString('Mustermann', $content);
        $this->assertStringContainsString('24.07.2026', $content);
        $this->assertStringContainsString('12.08.2026', $content);
        // Und die Gegenprobe zum Wegfall des Namens: der Inhalt baut den
        // rechten Fussblock nicht mehr. Er kommt seit 17.08.2026 aus
        // TrainingCertificateHtml, weil dort das Unterschriften-Asset liegt.
        $this->assertStringNotContainsString('zert-fuss-rechts', $content);
        $this->assertStringNotContainsString('Schulungsleiter', $content);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $content);
    }

    /**
     * Die Platzhalter-SCHREIBWEISE ist Vertrag, nicht Geschmack: Task 9 prueft
     * mit ResttagePlaceholder::hasUnresolvedPlaceholder() gegen genau dieses
     * Muster, und der Rueckweg (Vorlage statt festes HTML) braucht dieselben
     * Namen. Deshalb wird die Rohfassung festgenagelt, nicht nur das Ergebnis.
     */
    public function testDieRohfassungTraegtGenauDieVierBekanntenPlatzhalter(): void
    {
        preg_match_all('/\{\{[^{}]+\}\}/', TrainingCertificateContent::template(), $treffer);

        $this->assertSame(
            [
                '{{kontakt_vorname}}',
                '{{kontakt_nachname}}',
                '{{schulung_datum}}',
                '{{datum_heute}}',
            ],
            $treffer[0],
            'Schreibweise und Satz der Platzhalter sind Vertrag (Task 9 prueft dasselbe Muster).'
        );
    }

    /**
     * Ein fehlender Wert ist LAUT. Der stille Nachbarfall waere ein '?? ""':
     * dann ginge ein Zertifikat ohne Schulungsdatum raus, und weil ein leeres
     * Feld auf diesem Dokument ein plausibler Zustand ist, faellt es niemandem
     * auf.
     */
    public function testFehlenderWertWirftUndNenntDenPlatzhalter(): void
    {
        $werte = $this->values();
        unset($werte['schulung_datum']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\{\{schulung_datum\}\}/');

        TrainingCertificateContent::render($werte);
    }

    /**
     * LEER ist erlaubt und muss still durchgehen — die Gegenrichtung zum Test
     * darueber. TrainingLeaderResolver liefert bewusst '' fuer "keine
     * attended-Buchung bekannt" (ein leeres Feld ist besser als ein falsches),
     * und ein Guard, der "leer" mit "fehlt" verwechselt, wuerde die Ausstellung
     * fuer genau diese legitimen Faelle sprengen.
     */
    public function testLeererWertGehtStillDurch(): void
    {
        $content = TrainingCertificateContent::render($this->values([
            'schulung_datum' => '',
        ]));

        $this->assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $content);
        // Das leere Feld bleibt als leeres Element stehen, statt die Zeile zu
        // verschlucken: ein Zertifikat ohne Schulungsdatum sieht dann
        // unvollstaendig aus — und genau das soll es.
        $this->assertStringContainsString('<div class="val"></div>', $content);
    }

    /**
     * Werte werden HTML-escaped. "Mueller & Sohn" ist ein realistischer Name,
     * und ein unescapetes '<' in einem Namen wuerde das Layout des Dokuments
     * zerlegen. Entities sind der richtige Weg und kein Kompromiss: DomPDF
     * loest sie auf, und FontGlyphCoverage::inspect() dekodiert sie ebenfalls,
     * die Zeichenpruefung bleibt also scharf.
     */
    public function testWerteWerdenEscaped(): void
    {
        $content = TrainingCertificateContent::render($this->values([
            'kontakt_nachname' => 'Mueller & <b>Sohn</b>',
        ]));

        $this->assertStringContainsString('Mueller &amp; &lt;b&gt;Sohn&lt;/b&gt;', $content);
        $this->assertStringNotContainsString('<b>Sohn</b>', $content);
    }

    /**
     * Der Stern steht als Entity in einem span, das per CSS auf DejaVu
     * schaltet. BEIDES ist Absicht: Oswald hat kein U+2605, ohne den
     * span-Umweg steht "?" im PDF (DomPDF macht bei Custom-Fonts keinen
     * Glyph-Fallback, ohne Warnung). Wer den Stern als Literal einsetzt,
     * verliert die Entity-Dekodierung von FontGlyphCoverage mit.
     */
    public function testSternKommtAlsEntityImDejaVuSpan(): void
    {
        $content = TrainingCertificateContent::render($this->values());

        $this->assertStringContainsString('<span class="zeichen">&#9733;</span>', $content);
        $this->assertStringNotContainsString('★', $content);
    }

    /**
     * DIE HUELLE GEHOERT NICHT IN DEN INHALT. Der Rueckgabewert wird als
     * personalized_content gespeichert; Layout, Schrift und die drei Bilder
     * loest erst TrainingCertificateHtml::build() beim Rendern auf. Baut jemand
     * die Huelle hier mit ein, liegen ~550 KB Base64 pro ausgestelltem
     * Zertifikat in der DB-Spalte.
     */
    public function testInhaltEnthaeltKeineHuelleUndKeineAssets(): void
    {
        $content = TrainingCertificateContent::render($this->values());

        $this->assertStringNotContainsString('<html', $content);
        $this->assertStringNotContainsString('@font-face', $content);
        $this->assertStringNotContainsString('<style', $content);
        $this->assertStringNotContainsString('base64', $content);
    }

    /**
     * Der Kursname und der Ort sind Literaltext, kein Platzhalter — eine
     * Schulungsart pro HTML-Block, und in "DUESSELDORF, DEN ..." gehoert ein
     * Stadtname, waehrend rec_interviews.location die volle Adresse traegt.
     */
    public function testKursnameUndOrtSindLiteral(): void
    {
        $content = TrainingCertificateContent::render($this->values());

        $this->assertStringContainsString('Service-Basisschulung', $content);
        $this->assertStringContainsString('Düsseldorf, den 12.08.2026', $content);
    }

    // -----------------------------------------------------------------
    // isBlank(): die Bedingung, an der der 500 im Controller haengt
    // -----------------------------------------------------------------

    public function testNullUndLeerstringGeltenAlsLeer(): void
    {
        $this->assertTrue(TrainingCertificateContent::isBlank(null));
        $this->assertTrue(TrainingCertificateContent::isBlank(''));
    }

    /**
     * DER TEURE FALL. null faellt auf, Weissraum nicht: die Huelle wuerde ein
     * Dokument mit Logo, Headline und Unterschriftsblock erzeugen, aber ohne
     * Name, Kurs und Datum. Wer die Bedingung im Controller auf
     * "$content === ''" verkuerzt, macht diesen Test rot — das ist sein Zweck.
     */
    public function testNurWeissraumGiltAlsLeer(): void
    {
        $this->assertTrue(TrainingCertificateContent::isBlank(' '));
        $this->assertTrue(TrainingCertificateContent::isBlank("\n\t  \r\n"));
    }

    public function testEchterInhaltGiltNichtAlsLeer(): void
    {
        // Der ausgestellte Inhalt selbst, nicht ein getippter String: ein Guard,
        // der das ausgelieferte Dokument verwirft, ist der teuerste von allen.
        $this->assertFalse(TrainingCertificateContent::isBlank(
            TrainingCertificateContent::render($this->values())
        ));
    }

    public function testHtmlWeissraumGiltABSICHTLICHNichtAlsLeer(): void
    {
        // Festgenagelt, damit die Grenze bewusst bleibt: "<p></p>" ist fuer
        // isBlank() Inhalt. Wer das aendert, aendert es hier sichtbar und nicht
        // beilaeufig im Controller.
        $this->assertFalse(TrainingCertificateContent::isBlank('<p></p>'));
    }
}
