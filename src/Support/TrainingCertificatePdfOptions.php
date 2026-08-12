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
 */
final class TrainingCertificatePdfOptions
{
    /**
     * @param string $fontPath absoluter Pfad zur TTF-Datei
     * @param string $chroot   Wurzel, unterhalb der DomPDF lesen darf
     *                         (in der Host-App: realpath(base_path()))
     * @return array<string,mixed>
     */
    public static function for(string $fontPath, string $chroot): array
    {
        if (!str_starts_with($fontPath, rtrim($chroot, '/'))) {
            throw new \InvalidArgumentException(
                'Der Font-Pfad liegt ausserhalb des chroot — DomPDF wuerde die '
                . 'Schrift stumm ignorieren und in Helvetica rendern. '
                . "font={$fontPath} chroot={$chroot}"
            );
        }

        return [
            'chroot' => rtrim($chroot, '/'),
            'isRemoteEnabled' => false,
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
        ];
    }
}
