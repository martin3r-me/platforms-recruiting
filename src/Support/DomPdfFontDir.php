<?php

namespace Platform\Recruiting\Support;

/**
 * Sichert zu, dass das Verzeichnis existiert, in das DomPDF Font-Metriken
 * schreibt — und bricht laut ab, wenn das nicht herzustellen ist.
 *
 * DER ANLASS IST EIN GEMESSENER PRODUKTIONSFEHLER, kein Vorsichtsgedanke.
 * Gemessene Kette:
 *
 *   meingedeck/storage/           -> app, framework, logs   (KEIN fonts/)
 *   meingedeck/config/dompdf.php  -> existiert nicht
 *   vendor/barryvdh/laravel-dompdf/config/dompdf.php:48,58
 *      'font_dir'   => storage_path('fonts')
 *      'font_cache' => storage_path('fonts')
 *
 * Mit fehlendem Verzeichnis, sonst exakt dem Produktionspfad:
 *
 *   PHP Warning: fopen(.../zert_normal_....ufm): Failed to open stream:
 *                No such file or directory   (php-font-lib AdobeFontMetrics.php:44)
 *   TypeError: fwrite(): Argument #1 ($stream) must be of type resource,
 *              false given                   (AdobeFontMetrics.php:226)
 *
 * Der Fatal passiert in render(), also VOR jeder Ausgabe: 500 auf 100 % der
 * Aufrufe, auf genau dem Link, der per WhatsApp an abgelehnte Bewerber geht.
 * Mit existierendem Verzeichnis, sonst identisch: PDF, Oswald-SemiBold
 * eingebettet. Das Verzeichnis war die einzige Variable.
 *
 * Betroffen ist nur der Zertifikat-Weg, und deshalb ist es niemandem
 * aufgefallen: das Zertifikat ist das erste @font-face-PDF der Host-App.
 * Vertraege rendern mit dem gebuendelten DejaVu Sans, dessen Metriken bereits
 * in vendor/dompdf/dompdf/lib/fonts liegen — dort wird nichts geschrieben.
 *
 * WARUM GEWORFEN UND NICHT AUSGEWICHEN WIRD. DomPDF selbst weicht aus: ohne
 * brauchbares fontDir schreibt es still nach vendor/dompdf/dompdf/lib/fonts
 * und registriert die Familie dort. Gemessen an diesem Arbeitsplatz — in
 * meingedeck/vendor/dompdf/dompdf/lib/fonts liegen aus einem frueheren Lauf
 * zert_normal_<md5>.ttf/.ufm plus eine installed-fonts.json mit der Familie
 * "zert" — und die Folge ist doppelt schlecht: die Auslieferung schreibt in ein
 * per composer ersetzbares Verzeichnis, und der naechste Lauf findet dort eine
 * fremde Registrierung vor (gemessene Wirkung: eingebettet wird dann die alte
 * Kopie unter ihrem Dateinamen, /BaseFont /zert_normal_<md5> statt
 * /BaseFont /SUBAAB+Oswald-SemiBold). Ein Fallback dieser Klasse auf irgendein
 * anderes Verzeichnis waere derselbe Fehler in eigener Handschrift. Wer den
 * Pfad nicht herstellen kann, muss ihn erfahren.
 *
 * WARUM SCHREIBBARKEIT UND NICHT NUR EXISTENZ: DomPDF legt in diesem
 * Verzeichnis .ufm-Dateien AN. Ein existierendes, nicht beschreibbares
 * Verzeichnis erzeugt denselben Fatal wie ein fehlendes — is_dir() allein
 * waere ein Guard, der plausibel aussieht und den Fehlerfall durchlaesst.
 * Beide Faelle haben einen Falsifikator in DomPdfFontDirTest.
 *
 * Laravel-frei mit Absicht: der Aufrufer uebergibt den Pfad (in der Host-App
 * aus config('dompdf.options.font_dir')), damit diese Klasse unit-testbar
 * bleibt und der Render-Test sie ohne Bootstrap benutzen kann.
 */
final class DomPdfFontDir
{
    /**
     * Legt $dir an, falls noetig, und liefert den normalisierten Pfad zurueck.
     *
     * @param string $dir absoluter Pfad; in der Host-App der Wert von
     *                    config('dompdf.options.font_dir')
     * @return string derselbe Pfad ohne Schluss-Trennzeichen
     *
     * @throws \RuntimeException wenn kein Pfad angegeben ist, das Verzeichnis
     *                           nicht anlegbar oder nicht beschreibbar ist
     */
    public static function ensureWritable(string $dir): string
    {
        if (trim($dir) === '') {
            throw new \RuntimeException(
                'Kein Font-Verzeichnis angegeben (leerer Pfad). DomPDF schreibt seine '
                . 'Font-Metriken sonst in sein eigenes vendor-Verzeichnis, und das '
                . 'ueberlebt kein composer install. Erwartet wird der Wert von '
                . "config('dompdf.options.font_dir'), in der Host-App storage_path('fonts')."
            );
        }

        // Schluss-Trennzeichen weg, aber "/" bleibt "/": rtrim allein machte
        // aus der Wurzel den leeren String, und die Fehlermeldung nannte dann
        // keinen Pfad mehr.
        $path = $dir === '/' ? '/' : rtrim($dir, '/');

        if (!is_dir($path)) {
            // Das @ haelt die PHP-Warnung aus dem Log heraus, der Grund wird aus
            // error_get_last() in die eigene Meldung gehoben. Wer das @ entfernt,
            // muss den Grund weiterhin in die Meldung schreiben.
            //
            // Die zweite Bedingung ist kein Zierrat: mkdir(recursive) liefert
            // auch dann false, wenn ein paralleler Prozess das Verzeichnis
            // zwischen is_dir() und mkdir() angelegt hat. Ohne sie waere der
            // haeufigste Normalfall unter Last ein 500.
            if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                $grund = error_get_last()['message'] ?? 'PHP hat keinen Grund gemeldet';

                throw new \RuntimeException(sprintf(
                    'Font-Verzeichnis %s konnte nicht angelegt werden: %s. Ohne dieses '
                    . 'Verzeichnis bricht DomPDF beim Schreiben der Font-Metriken mit '
                    . 'einem TypeError in AdobeFontMetrics ab — mitten in render(), also '
                    . 'vor jeder Ausgabe. Pruefen: existiert %s und ist es fuer den '
                    . 'Webserver-Benutzer beschreibbar?',
                    $path,
                    $grund,
                    dirname($path)
                ));
            }
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(sprintf(
                'Font-Verzeichnis %s ist nicht beschreibbar (Eigentuemer/Rechte pruefen). '
                . 'DomPDF legt dort .ufm-Dateien an; ein existierendes, aber gesperrtes '
                . 'Verzeichnis erzeugt denselben TypeError in AdobeFontMetrics wie ein '
                . 'fehlendes.',
                $path
            ));
        }

        return $path;
    }
}
