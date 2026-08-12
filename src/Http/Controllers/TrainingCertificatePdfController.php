<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\TrainingCertificateAssets;
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
 * stream() statt download(): der Link kommt per WhatsApp, das PDF soll
 * angezeigt werden und nicht als Datei im Downloadordner landen.
 *
 * Huelle, Assets und DomPDF-Optionen kommen aus denselben drei Klassen, gegen
 * die der Render-Test rendert (TrainingCertificateRenderTest). Wer hier eine
 * Option direkt setzt statt sie in TrainingCertificatePdfOptions zu ergaenzen,
 * liefert eine anders konfigurierte Engine aus als die getestete — und der
 * Render-Test bliebe gruen.
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
        // TrainingCertificatePdfOptions::for() wirft unten mit Font-Pfad und
        // chroot in der Meldung. Das ist der laute Pfad, deshalb steht hier kein
        // zweiter Guard dafuer.
        if ($assets['missing'] !== []) {
            Log::warning('[TrainingCertificatePdfController] Assets fehlen', [
                'certificate_uuid' => $uuid,
                'missing' => $assets['missing'],
            ]);
        }

        $content = (string) ($certificate->personalized_content ?? '');

        if (trim($content) === '') {
            // Hier NICHT mit '' weiterrendern. Die Huelle wuerde ein Dokument
            // mit Logo, Headline und Unterschriftsblock erzeugen, aber ohne
            // Name, Kurs und Datum: ein amtlich aussehendes Blatt, das nichts
            // aussagt — und niemand erfuehre davon, weil der Bewerber den Link
            // per WhatsApp bekommt und nicht nachfragt. Die Spalte ist nullable
            // (Migration 2026_08_12_000002), der Fall ist also erreichbar.
            //
            // 500 und nicht 404: die Zeile EXISTIERT, sie ist kaputt. Ein 404
            // behauptete, es gebe kein Zertifikat, und schickte die Suche in die
            // falsche Richtung. Der sichere Weg ist laut abbrechen und die uuid
            // loggen, damit die Zeile gefunden und neu ausgestellt werden kann.
            Log::error('[TrainingCertificatePdfController] Zertifikat ohne Inhalt', [
                'certificate_uuid' => $uuid,
                'certificate_id' => $certificate->id,
            ]);

            abort(500, 'Das Zertifikat hat keinen Inhalt und kann nicht ausgeliefert werden.');
        }

        $html = TrainingCertificateHtml::build($content, $assets);

        $pdf = Pdf::loadHTML($html);
        foreach (TrainingCertificatePdfOptions::for($assets['font'], (string) realpath(base_path())) as $key => $value) {
            $pdf->setOption($key, $value);
        }

        return $pdf->setPaper('a4')->stream('zertifikat.pdf');
    }
}
