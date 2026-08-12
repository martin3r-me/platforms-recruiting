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
}
