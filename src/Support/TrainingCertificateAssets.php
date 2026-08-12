<?php

namespace Platform\Recruiting\Support;

/**
 * Loest die vier Zertifikat-Assets auf: Schrift-Pfad und drei Bilder als
 * Data-URIs, plus die Liste der fehlenden.
 *
 * Warum eine eigene Klasse und nicht je im Controller und im Editor:
 * genau diese Doppelung ist bei den DomPDF-Optionen fast zum Problem
 * geworden. Weicht ein Pfad zwischen Vorschau und Auslieferung ab, zeigt der
 * Test-PDF-Knopf etwas anderes als das ausgestellte Dokument — und der
 * Knopf existiert genau dazu, das zu verhindern. Ein Resolver, drei
 * Konsumenten: Controller loggt `missing`, Editor zeigt es an, Render-Test
 * assertiert `missing === []`.
 *
 * Laravel-frei, damit der Render-Test ohne Bootstrap laeuft. Das Loggen
 * fehlender Assets ist deshalb Sache des Aufrufers, nicht dieser Klasse.
 */
final class TrainingCertificateAssets
{
    private const FONT = 'fonts/Oswald-SemiBold.ttf';

    /** Reihenfolge ist Teil des Vertrags — die Tests assertieren sie. */
    private const IMAGES = [
        'logo' => 'images/certificates/logo.png',
        'headline' => 'images/certificates/headline-zertifikat.png',
        'signature' => 'images/certificates/signature-block.png',
    ];

    /**
     * @param string $resourcesDir absoluter Pfad auf das resources/-Verzeichnis des Moduls
     * @return array{font: string, logo: ?string, headline: ?string, signature: ?string, missing: list<string>}
     */
    public static function resolve(string $resourcesDir): array
    {
        $base = rtrim($resourcesDir, '/');
        $missing = [];

        $fontPath = $base . '/' . self::FONT;
        // 0-Byte-Datei zaehlt als fehlend: ein leeres @font-face waere kein
        // Absturz, aber eine Schrift, die es nur dem Namen nach gibt, und
        // "missing" ist der einzige Kanal, ueber den das jemand erfaehrt.
        // filesize() statt file_get_contents(), weil der Pfad selbst benoetigt
        // wird, nicht der Inhalt.
        if (!is_file($fontPath) || !is_readable($fontPath) || filesize($fontPath) === 0) {
            // Pfad trotzdem zurueckgeben: das @font-face braucht ihn, und
            // TrainingCertificatePdfOptions prueft ihn gegen den chroot.
            $missing[] = self::FONT;
        }

        $out = ['font' => $fontPath];

        foreach (self::IMAGES as $key => $relative) {
            $path = $base . '/' . $relative;

            if (!is_file($path) || !is_readable($path)) {
                $out[$key] = null;
                $missing[] = $relative;
                continue;
            }

            $binary = @file_get_contents($path);
            // Leerstring zaehlt wie false als fehlend: ein lesbares 0-Byte-Bild
            // wuerde sonst einen leeren Data-URI erzeugen, der im PDF nichts
            // rendert, ohne dass "missing" davon je erfaehrt — der Controller
            // loggt dann nichts und der Editor zeigt nichts an, obwohl das
            // Element fehlt.
            if ($binary === false || $binary === '') {
                $out[$key] = null;
                $missing[] = $relative;
                continue;
            }

            $out[$key] = 'data:image/png;base64,' . base64_encode($binary);
        }

        $out['missing'] = $missing;

        return $out;
    }
}
