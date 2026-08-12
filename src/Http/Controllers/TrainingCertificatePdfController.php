<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\DomPdfFontDir;
use Platform\Recruiting\Support\TrainingCertificateAssets;
use Platform\Recruiting\Support\TrainingCertificateContent;
use Platform\Recruiting\Support\TrainingCertificateHtml;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

/**
 * Liefert ein ausgestelltes Schulungszertifikat als PDF.
 *
 * KEIN STATUS-GUARD, und das ist Absicht: der abgelehnte, inaktive Bewerber ist
 * der NORMALFALL dieses Dokuments. Ein Guard auf "aktiv" oder "nicht abgelehnt"
 * saehe hier plausibel aus und schloesse genau die Zielgruppe aus. Geprueft wird
 * nur, dass die Zertifikat-Zeile existiert.
 *
 * Adressiert wird ueber die Zertifikat-uuid, NICHT ueber den Applicant-Token:
 * der oeffnet auch Bewerbungsformular und Vertrags-PDFs, unbegrenzt und ohne
 * Rotation. Ihn per WhatsApp aktiv erneut zu versenden waere eine neu
 * geoeffnete Tuer in eine bestehende Luecke — der Trostpreis soll das
 * Zertifikat zustellen, nicht den Generalschluessel. Die uuid oeffnet genau ein
 * Dokument.
 *
 * DESHALB STEHT DIE uuid IN KEINER LOGZEILE: sie IST das Zugangsmerkmal des
 * Dokuments. Geloggt wird die certificate_id — damit findet der Betrieb die
 * Zeile, ohne dass ein Logeintrag den Link oeffnet.
 *
 * stream() statt download(): der Link kommt per WhatsApp, das PDF soll
 * angezeigt werden und nicht als Datei im Downloadordner landen.
 *
 * Huelle, Assets und DomPDF-Optionen kommen aus denselben Klassen, gegen die
 * der Render-Test rendert (TrainingCertificateRenderTest). Wer hier eine Option
 * direkt setzt statt sie in TrainingCertificatePdfOptions zu ergaenzen, liefert
 * eine anders konfigurierte Engine aus als die getestete — und der Render-Test
 * bliebe gruen.
 *
 * WANN DOMPDF DIESE OPTIONEN LIEST (gemessen, korrigierte Fassung — die
 * frueher hier behauptete Regel "fontDir darf nie spaet kommen" war falsch und
 * ist mit adce27c zurueckgenommen):
 *
 *  1. chroot, dpi, defaultFont, isRemoteEnabled, isHtml5ParserEnabled werden
 *     erst zur render()-Zeit gelesen — das CSS wird in render() geparst
 *     (Stack: Dompdf::render -> processHtml -> Stylesheet::load_css). Der letzte
 *     Wert VOR render() gewinnt; sie nach loadHTML() zu setzen ist deshalb
 *     erlaubt, und frueh zu setzen ist Cache-Hygiene, keine
 *     Korrektheitsbedingung.
 *  2. Ein spaet gesetzter FALSCHER chroot bricht die Schrift sehr wohl:
 *     gemessen liegt dann nur noch Helvetica und DejaVuSans im PDF, ohne
 *     Exception und ohne Logzeile. "Spaet erlaubt" heisst nicht "beliebig".
 *  3. fontDir/fontCache werden an ZWEI Zeitpunkten gelesen, und das ist der
 *     Teil, der in der ersten Fassung dieser Regel fehlte: beim Konstruieren
 *     entscheidet der Wert, WELCHE Fonts als registriert gelten
 *     (FontMetrics::__construct liest fontDir/installed-fonts.json), zur
 *     render()-Zeit, WOHIN die Metriken geschrieben werden. Zeigen beide auf
 *     dasselbe Verzeichnis, ist der Unterschied unsichtbar — genau deshalb sah
 *     die Messung wie "nur render() zaehlt" aus. Zeigen sie auf verschiedene,
 *     verteilen sich die Artefakte auf beide Ordner, und eine fremde
 *     Registrierung im Konstruktions-Ordner gewinnt vollstaendig (gemessen:
 *     /BaseFont /zert_normal_<md5> aus einer alten Kopie in
 *     vendor/dompdf/dompdf/lib/fonts statt /BaseFont /SUBAAB+Oswald-SemiBold).
 *     HIER IST DAS UNPROBLEMATISCH, und nur deshalb darf es spaet kommen: der
 *     Pfad ist derselbe, den die Konfiguration DomPDF beim Konstruieren schon
 *     gegeben hat. Gesetzt wird er trotzdem noch einmal, damit das zugesicherte
 *     Verzeichnis beweisbar das benutzte ist.
 */
class TrainingCertificatePdfController extends Controller
{
    public function __invoke(string $uuid)
    {
        // Kein ->with(...): dieses Dokument braucht keine Beziehung, der ganze
        // Text steht als Snapshot in personalized_content. Insbesondere gibt es
        // seit Zuschnitt v3 KEINE contractTemplate-Beziehung mehr an
        // RecTrainingCertificate (der Inhalt ist festes HTML, keine Vorlage) —
        // ein Eager-Load darauf waere eine BadMethodCallException bei JEDEM
        // Aufruf, also ein 500 auf dem Link, den der Bewerber per WhatsApp
        // bekommt.
        $certificate = RecTrainingCertificate::where('uuid', $uuid)->firstOrFail();

        $content = (string) ($certificate->personalized_content ?? '');

        // ZUERST der Inhalt, DANN die Assets: die drei PNGs werden als Base64
        // aufgeloest (~550 KB), und der Fehlerpfad soll diese Arbeit nicht mehr
        // machen. Fachlich ist die Reihenfolge ohnehin die richtige — ohne
        // Inhalt gibt es kein Dokument, ueber dessen Bilder zu reden waere.
        //
        // Was leer heisst, entscheidet TrainingCertificateContent::isBlank()
        // (dort mit Falsifikator fuer den Weissraum-Fall).
        if (TrainingCertificateContent::isBlank($content)) {
            // Hier NICHT mit '' weiterrendern. Die Huelle wuerde ein Dokument
            // mit Logo, Headline und Unterschriftsblock erzeugen, aber ohne
            // Name, Kurs und Datum: ein amtlich aussehendes Blatt, das nichts
            // aussagt — und niemand erfuehre davon, weil der Bewerber den Link
            // per WhatsApp bekommt und nicht nachfragt. Die Spalte ist nullable
            // (Migration 2026_08_12_000002), der Fall ist also erreichbar.
            //
            // 500 und nicht 404: die Zeile EXISTIERT, sie ist kaputt. Ein 404
            // behauptete, es gebe kein Zertifikat, und schickte die Suche in die
            // falsche Richtung. Der sichere Weg ist laut abbrechen und die
            // certificate_id loggen, damit die Zeile gefunden und neu
            // ausgestellt werden kann.
            Log::error('[TrainingCertificatePdfController] Zertifikat ohne Inhalt', [
                'certificate_id' => $certificate->id,
            ]);

            abort(500, 'Das Zertifikat hat keinen Inhalt und kann nicht ausgeliefert werden.');
        }

        // Das Verzeichnis, in das DomPDF die Font-Metriken schreibt, muss
        // existieren und beschreibbar sein — sonst bricht render() mit einem
        // TypeError in AdobeFontMetrics ab, also VOR jeder Ausgabe: 500 auf
        // 100 % der Aufrufe. meingedeck hat keine eigene config/dompdf.php, der
        // Paket-Default ist storage_path('fonts'), und dieses Verzeichnis
        // existiert dort nicht. Begruendung, Messwerte und die zwei
        // Falsifikatoren: DomPdfFontDir.
        //
        // Gelesen wird die Konfiguration, nicht storage_path('fonts') direkt:
        // sonst sicherte dieser Controller ein anderes Verzeichnis zu als das,
        // in das DomPDF schreibt. Der Fallback greift nur beim FEHLENDEN
        // Schluessel und ist der Paket-Default selbst; ein ausdruecklich auf
        // null gesetzter font_dir kommt als leerer Pfad an und wirft — das ist
        // die Absicht, denn er bedeutet "schreib in dein vendor-Verzeichnis".
        //
        // Bekannte, heute unerreichbare Kante: setzt jemand in einer eigenen
        // config/dompdf.php den Schluessel "defines", baut der Paket-Provider
        // seine Optionen DARAUS und ignoriert "options" ganz
        // (ServiceProvider.php:30-48) — dann zeigte die Zusicherung hier auf ein
        // Verzeichnis, das die Engine nicht benutzt. meingedeck hat keine eigene
        // config/dompdf.php, der Fall ist also nicht erreichbar; wer eine
        // anlegt, muss diese Zeile mitziehen.
        $fontDir = DomPdfFontDir::ensureWritable(
            (string) config('dompdf.options.font_dir', storage_path('fonts'))
        );
        $fontCache = DomPdfFontDir::ensureWritable(
            (string) config('dompdf.options.font_cache', $fontDir)
        );

        $assets = TrainingCertificateAssets::resolve(
            (string) realpath(__DIR__ . '/../../../resources')
        );

        // Fehlende Assets sind kein Absturz, aber auch nicht harmlos: das PDF
        // kaeme ohne Logo/Headline/Unterschrift heraus, oder — bei fehlender
        // Schrift — komplett in Helvetica. Beides ist ein Dokument, das
        // amtlich aussieht und falsch ist. Der Resolver bleibt laravel-frei und
        // laesst fehlende Bilder still weg, das Melden ist deshalb Sache dieses
        // Controllers.
        //
        // Loest realpath() oben nicht auf (Modul nicht am erwarteten Ort), sind
        // hier ALLE Assets als fehlend gemeldet und
        // TrainingCertificatePdfOptions::applyTo() wirft unten mit Font-Pfad und
        // chroot in der Meldung. Das ist der laute Pfad, deshalb steht hier kein
        // zweiter Guard dafuer.
        if ($assets['missing'] !== []) {
            Log::warning('[TrainingCertificatePdfController] Assets fehlen', [
                'certificate_id' => $certificate->id,
                'missing' => $assets['missing'],
            ]);
        }

        $pdf = Pdf::loadHTML(TrainingCertificateHtml::build($content, $assets));

        TrainingCertificatePdfOptions::applyTo(
            $pdf,
            $assets['font'],
            (string) realpath(base_path()),
            $fontDir,
            $fontCache
        );

        return $pdf->setPaper('a4')->stream('zertifikat.pdf');
    }
}
