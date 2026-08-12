<?php

namespace Platform\Recruiting\Support;

/**
 * Die EINZIGE Quelle der DomPDF-Optionen fuer das Schulungszertifikat.
 *
 * Warum eine eigene Klasse: der Vertrags-Controller setzt seine Optionen
 * selbst (defaultFont, isHtml5ParserEnabled). Wuerden Zertifikat-Controller
 * und Render-Test das ebenfalls je selbst tun, pruefte der Test eine anders
 * konfigurierte Engine als die ausgelieferte — und waere gruen ohne Aussage.
 * Genau so ist im Prototyp ein isRemoteEnabled-Unterschied entstanden, der
 * nur durch manuelles Nachsehen auffiel.
 *
 * chroot ist nicht Kosmetik: ohne passenden chroot ignoriert DomPDF das
 * @font-face STUMM und rendert in Helvetica — keine Exception, kein Log.
 *
 * Die Grenz-Pruefung ist reines String-Matching auf Verzeichnisebene, kein
 * Dateisystem-Zugriff: sie verlangt entweder Gleichheit mit dem chroot oder
 * ein Praefix INKLUSIVE Trennzeichen ("$root/"), damit ein Nachbarverzeichnis
 * wie "/apply" bei chroot "/app" nicht als "innerhalb" durchgeht (reines
 * str_starts_with ohne Trennzeichen wuerde das faelschlich zulassen). Dot-
 * Segment-Traversal (z. B. "/app/../secret/x.ttf") faengt das bewusst NICHT
 * ab — das ist kein Angriffsschutz, sondern eine Fehlkonfigurations-Pruefung.
 * Aufrufer muessen daher bereits AUFGELOESTE, absolute Pfade uebergeben (die
 * Host-App tut das ueber realpath(base_path())); realpath() gehoert nicht in
 * diese Klasse, weil sie laravel- und dateisystemunabhaengig bleiben muss
 * (Unit-Tests arbeiten mit fiktiven, nicht existierenden Pfaden).
 */
final class TrainingCertificatePdfOptions
{
    /**
     * @param string $fontPath absoluter, bereits AUFGELOESTER Pfad zur TTF-Datei
     * @param string $chroot   Wurzel, unterhalb der DomPDF lesen darf
     *                         (in der Host-App: realpath(base_path()))
     * @return array<string,mixed>
     */
    public static function for(string $fontPath, string $chroot): array
    {
        $root = rtrim($chroot, '/');
        $insideChroot = ($fontPath === $root) || str_starts_with($fontPath, $root . '/');

        if (!$insideChroot) {
            throw new \InvalidArgumentException(
                'Der Font-Pfad liegt ausserhalb des chroot — DomPDF wuerde die '
                . 'Schrift stumm ignorieren und in Helvetica rendern. '
                . "font={$fontPath} chroot={$chroot}"
            );
        }

        return [
            'chroot' => $root,
            'isRemoteEnabled' => false,
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
        ];
    }

    /**
     * Schiebt alle Optionen in ein DomPDF-Objekt und liefert, was gesetzt wurde.
     *
     * WARUM DIESE SCHLEIFE NICHT IM CONTROLLER STEHT: dort hatte sie keinen
     * Falsifikator. Ein versehentlich entfernter oder abgebrochener Durchlauf
     * liefert eine unkonfigurierte Engine — chroot fehlt, DomPDF verwirft das
     * @font-face STUMM und das Zertifikat kommt in Helvetica heraus, ohne
     * Exception und ohne Logzeile. Hier ist die Schleife gegen einen
     * mitschreibenden Doppelgaenger pruefbar (TrainingCertificatePdfOptionsTest).
     *
     * fontDir und fontCache kommen von aussen dazu und stehen ABSICHTLICH nicht
     * in for(): sie sind kein Rendering-Merkmal, sondern der Ort, an den DomPDF
     * seine Font-Metriken schreibt, und dieser Ort ist pro Umgebung anders (in
     * der Host-App config('dompdf.options.font_dir'), im Render-Test ein eigener
     * Temp-Ordner). Der Aufrufer muss sie mit DomPdfFontDir::ensureWritable()
     * zugesichert haben — ein fehlendes oder gesperrtes Verzeichnis ist ein
     * TypeError mitten in render().
     *
     * $target ist absichtlich object und nicht auf eine Klasse festgelegt: der
     * Controller uebergibt die laravel-dompdf-Huelle (Barryvdh\DomPDF\PDF), der
     * Test einen Doppelgaenger. Beides muss nur setOption($key, $value) koennen.
     *
     * @param object $target etwas mit setOption($key, $value)
     * @return array<string,mixed> die gesetzten Optionen, in gesetzter Reihenfolge
     */
    public static function applyTo(
        object $target,
        string $fontPath,
        string $chroot,
        string $fontDir,
        string $fontCache
    ): array {
        $options = self::for($fontPath, $chroot) + [
            'fontDir' => $fontDir,
            'fontCache' => $fontCache,
        ];

        foreach ($options as $key => $value) {
            $target->setOption($key, $value);
        }

        return $options;
    }
}
