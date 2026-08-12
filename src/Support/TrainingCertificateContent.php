<?php

namespace Platform\Recruiting\Support;

/**
 * Der INHALT des Schulungszertifikats: fester Text plus fuenf Werte.
 *
 * Warum das hier steht und nicht in rec_contract_templates: das Dokument hat
 * drei variable Werte, eine Schulungsart und einen Text, der sich praktisch
 * nie aendert — die Vorlagen-Infrastruktur trug die Kosten einer Flexibilitaet,
 * die niemand braucht (Spec, "Revision v3"). Der Preis ist benannt: HR kann
 * den Text nicht selbst aendern, eine zweite Schulungsart braucht ein Deploy
 * mit einem zweiten Block hier.
 *
 * Warum eine eigene Klasse und nicht im Service: laravel-frei, damit
 * unit-testbar ohne DB (Modulkonvention, tests/bootstrap.php ist ein reiner
 * Autoloader). Und weil es GENAU EINE Quelle des Inhalts geben muss — der
 * Render-Test und die Vorschau brauchen denselben Text wie die Ausstellung.
 * Wer hier eine Kopie anlegt, hat zwei Zertifikate, die sich langsam
 * auseinanderentwickeln; TestSchema erzaehlt dieselbe Geschichte fuer Schemata.
 *
 * DIE HUELLE GEHOERT NICHT HIERHER. Layout, Schrift und die drei Bilder loest
 * TrainingCertificateHtml::build() beim Rendern auf, Muster wie beim
 * Firmenstempel bei Vertraegen. Der Rueckgabewert wird als
 * personalized_content gespeichert; mit Huelle lagen ~550 KB Base64 pro
 * ausgestelltem Zertifikat in der Spalte.
 *
 * SCHREIBWEISE DER PLATZHALTER IST VERTRAG. Sie ist identisch zur
 * Vorlagen-Fassung, weil ResttagePlaceholder::hasUnresolvedPlaceholder() genau
 * dieses Muster prueft und der Rueckweg (Inhalt wieder als Vorlage) dieselben
 * Namen braucht.
 */
final class TrainingCertificateContent
{
    /**
     * Die Werte, die render() erwartet — vollstaendig, in der Reihenfolge des
     * Dokuments. Ein neuer Platzhalter im HTML gehoert hier mit hinein; wer das
     * vergisst, laeuft in den Nachcheck von render() (laut, nicht still).
     */
    public const PLACEHOLDERS = [
        'kontakt_vorname',
        'kontakt_nachname',
        'schulung_datum',
        'datum_heute',
        'schulung_leiter',
    ];

    /** Die eine ausgelieferte Schulungsart, passend zu RecTrainingCertificate::KIND_SERVICE_BASIS. */
    public const COURSE = 'Service-Basisschulung';

    /**
     * Der Inhalt mit eingesetzten Werten.
     *
     * @param array<string,string> $values Platzhaltername (ohne Klammern) => Wert
     */
    public static function render(array $values): string
    {
        $content = self::template();

        foreach (self::PLACEHOLDERS as $name) {
            if (!array_key_exists($name, $values)) {
                // Absichtlich NICHT ersetzen und NICHT auf '' ausweichen: der
                // Platzhalter bleibt stehen und der Nachcheck unten wirft mit
                // seinem Namen. Ein '?? ""' hier waere der stille Schaden — ein
                // Zertifikat ohne Schulungsdatum sieht vollstaendig aus, weil
                // ein leeres Feld auf diesem Dokument ein plausibler Zustand
                // ist.
                continue;
            }

            $content = str_replace(
                '{{' . $name . '}}',
                self::escape((string) $values[$name]),
                $content
            );
        }

        // LEER ist erlaubt, FEHLEND nicht: TrainingLeaderResolver liefert
        // bewusst '' fuer "kein Schulungsleiter bekannt". Deshalb prueft dieser
        // Guard das ERGEBNIS auf stehengebliebene Platzhalter und nicht die
        // Werte auf Nichtleere — sonst blockierte er genau die legitimen Faelle.
        // Dieselbe Prueffrage stellt Task 9 an das gerenderte PDF; die Quelle
        // des Musters ist ResttagePlaceholder, damit es nur eine gibt.
        if (ResttagePlaceholder::hasUnresolvedPlaceholder($content)) {
            preg_match_all('/\{\{[^{}]+\}\}/', $content, $offen);

            throw new \InvalidArgumentException(
                'Zertifikat-Inhalt mit unaufgeloesten Platzhaltern: '
                . implode(', ', array_unique($offen[0]))
                . '. Erwartet werden alle Werte aus '
                . self::class . '::PLACEHOLDERS.'
            );
        }

        return $content;
    }

    /**
     * Die Rohfassung mit Platzhaltern — fuer Vorschau und Render-Test, die den
     * Text brauchen, ohne einen Bewerber aufzuloesen.
     */
    public static function template(): string
    {
        // Der Stern steht als HTML-Entity in einem span, das per CSS auf DejaVu
        // schaltet. BEIDES ist Absicht, bitte nicht aufraeumen:
        //   - Oswald hat kein U+2605. Ohne den span-Umweg steht "?" im PDF,
        //     ohne Warnung (DomPDF macht bei Custom-Fonts keinen Fallback).
        //   - FontGlyphCoverage::inspect() dekodiert Entities, die
        //     Zeichenpruefung greift also auch hier.
        // Wer die Entity durch ein literales ★ ersetzt, muss beide Punkte
        // mitdenken; festgenagelt ist der sichere Weg in
        // TrainingCertificateContentTest::testSternKommtAlsEntityImDejaVuSpan.
        $stern = '<span class="zeichen">&#9733;</span>';

        $kenntnisse = [
            'Fachgerechte Tellerschulung 2-er Obergriff',
            'Stehempfang' . $stern . 'Flying Buffet',
            'Buffetservice',
            '3-Gang-Menü fachgerecht eindecken',
            'Weinservice',
            'Gästebetreuung und Kommunikation',
        ];

        $liste = '';
        foreach ($kenntnisse as $k) {
            $liste .= '<div class="skill">' . $stern . '<span>' . $k . '</span>' . $stern . '</div>' . "\n";
        }

        $kurs = self::COURSE;

        // Kursname und Ort sind Literaltext: eine Schulungsart pro Block, und
        // in "DÜSSELDORF, DEN ..." gehoert ein Stadtname, waehrend
        // rec_interviews.location die volle Adresse traegt (live geprueft).
        return <<<HTML
<div class="lab">Herr / Frau</div>
<div class="val">{{kontakt_vorname}} {{kontakt_nachname}}</div>

<div class="lab">hat am Kurs</div>
<div class="kurs">{$kurs}</div>

<div class="lab">am</div>
<div class="val">{{schulung_datum}}</div>

<div class="lab">mit Erfolg teilgenommen.</div>

<div class="intro">Bei der Schulung wurden folgende Grundkenntnisse vermittelt:</div>
{$liste}
<div class="zert-datum">Düsseldorf, den {{datum_heute}}</div>

<div class="zert-fuss-rechts">
  <div class="leiter">{{schulung_leiter}}</div>
  <div class="linie"></div>
  <div class="cap">Schulungsleiter</div>
</div>
HTML;
    }

    /**
     * Werte landen in HTML, also als Entities. Ein Name wie "Mueller & Sohn"
     * ist realistisch, ein '<' darin zerlegte das Layout des Dokuments.
     * ENT_SUBSTITUTE, damit ungueltige UTF-8-Bytes zum Ersatzzeichen werden und
     * nicht die ganze Ausgabe leeren (Default-Verhalten von htmlspecialchars
     * ohne dieses Flag).
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
