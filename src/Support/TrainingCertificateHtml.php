<?php

namespace Platform\Recruiting\Support;

/**
 * Die HTML-Huelle des Schulungszertifikats.
 *
 * Bewusst KEINE Blade: der Render-Test laeuft ohne Laravel-Bootstrap
 * (tests/bootstrap.php ist ein reiner Autoloader), und eine Blade waere dort
 * nur mit handverdrahtetem BladeCompiler + FileViewFinder + Engine-Resolver
 * zu rendern. Als Klasse ist die Huelle direkt unit-testbar — und es gibt
 * keine zweite Blade, die jemand versehentlich in ein gemeinsames Layout
 * mit dem Vertragsweg ziehen koennte.
 *
 * Aufteilung: die drei Bilder emittiert die Huelle an fixen Positionen, weil
 * sie ueber alle Zertifikat-Vorlagen identisch sind und HR sie nicht
 * verschieben soll. Der Vorlageninhalt liefert nur Text.
 *
 * Datum und Unterschriftszeile sind absolut am Seitenfuss verankert. Damit
 * kann der fliessende Mittelteil keinen Seitenumbruch erzeugen — die
 * Einzelseiten-Eigenschaft ist strukturell erzwungen, nicht durch Abstaende
 * austariert. Als <table> funktioniert das in DomPDF 3.1.5 nicht: eine
 * bottom-verankerte Tabelle laeuft unten aus der Seite.
 */
final class TrainingCertificateHtml
{
    /**
     * @param array{font: string, logo: ?string, headline: ?string, signature: ?string} $assets
     */
    public static function build(string $personalizedContent, array $assets): string
    {
        $font = $assets['font'];
        $logo = $assets['logo'] ?? null;
        $headline = $assets['headline'] ?? null;
        $signature = $assets['signature'] ?? null;

        $logoHtml = $logo === null
            ? ''
            : '<div><img class="zert-logo" src="' . $logo . '" alt="RheinGedeck"></div>';

        $headlineHtml = $headline === null
            ? ''
            : '<div><img class="zert-headline" src="' . $headline . '" alt="Zertifikat"></div>';

        $signatureHtml = $signature === null
            ? ''
            : '<div class="zert-fuss-links"><img class="zert-signatur" src="' . $signature
              . '" alt="RheinGedeck GmbH"></div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><style>
  @font-face { font-family: "Zert"; font-weight: normal; font-style: normal;
               src: url("{$font}") format("truetype"); }
  @page { margin: 0; size: A4; }
  body  { margin: 0; padding: 15mm 18mm 11mm; background: #FDF3E0;
          font-family: "Zert", sans-serif; color: #3C4A63; text-align: center; }

  /* Bilder — Positionen aus dem verifizierten Prototyp */
  .zert-logo     { width:  40mm; }
  .zert-headline { width: 116mm; margin: 4mm 0 6mm; }
  .zert-signatur { width:  54mm; }

  /* Fuss-Verankerung: Divs, nicht Tabelle (DomPDF-Einschraenkung) */
  .zert-datum       { position: absolute; left:  18mm; width: 174mm; bottom: 46mm;
                      font-size: 11.5pt; letter-spacing: 2px; text-transform: uppercase; }
  .zert-fuss-links  { position: absolute; left:  24mm; width:  54mm; bottom: 12mm; text-align: center; }
  .zert-fuss-rechts { position: absolute; left: 116mm; width:  66mm; bottom: 10mm; text-align: center; }

  /* Vokabular fuer den Vorlageninhalt */
  .lab    { font-size: 11pt;   letter-spacing: 2.5px; text-transform: uppercase; }
  .val    { font-size: 15pt;   letter-spacing: 2px;   text-transform: uppercase; margin: 2mm 0 6mm; }
  .kurs   { font-size: 24pt;   letter-spacing: 3px;   text-transform: uppercase; margin: 2mm 0 6mm; }
  .intro  { font-size: 11.5pt; letter-spacing: 2px;   text-transform: uppercase; margin: 8mm 0 4mm; }
  .skill  { font-size: 12pt;   letter-spacing: 1.6px; text-transform: uppercase; margin: 1.1mm 0; }
  .leiter { font-size: 9.5pt;  letter-spacing: 1.5px; text-transform: uppercase; }
  .cap    { font-size: 10pt;   letter-spacing: 2px;   text-transform: uppercase; }
  .linie  { border-top: 1px solid #3C4A63; margin: 1.5mm 0; }

  /* Sonderzeichen: Oswald hat kein ★. Ohne diesen Umweg steht "?" im PDF. */
  .zeichen { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; padding: 0 3mm; }

  /* Basis-Styles fuer nackte Elemente: wer nur einen Satz ergaenzt, tippt
     einen <p> und es passt. Die Klassen oben sind dann Feinsteuerung. */
  p      { font-size: 11.5pt; letter-spacing: 1.5px; margin: 3mm 0; }
  h2     { font-size: 16pt;   letter-spacing: 2px; text-transform: uppercase; margin: 4mm 0; font-weight: normal; }
  strong { font-weight: normal; letter-spacing: 2.5px; }
  li     { font-size: 12pt; letter-spacing: 1.6px; list-style: none; margin: 1.1mm 0; }
</style></head><body>
{$logoHtml}
{$headlineHtml}
{$personalizedContent}
{$signatureHtml}
</body></html>
HTML;
    }
}
