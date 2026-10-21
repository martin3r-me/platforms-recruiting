<?php

namespace Platform\Recruiting\Services\TestEmployee;

/**
 * Platzhalterbild fuer die Datei-Felder eines Test-MA: weiss, 600×400,
 * schwarzer Text (z. B. „TEST – Selfie"), als PNG-Bytes.
 *
 * Eingebaute GD-Schrift statt TTF: keine Schriftdatei noetig. Sie kennt nur
 * Latin-1, darum wird der Text vorher auf ASCII gebracht (– → -, ä → ae).
 * Klein gezeichnet und dann hochskaliert, damit der Text auf der
 * Portal-Kachel lesbar bleibt.
 */
final class PlaceholderImage
{
    private const BREITE = 600;
    private const HOEHE = 400;

    public static function png(string $label): string
    {
        $text = strtr($label, ['–' => '-', '—' => '-', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text);

        $font = 5;
        $textBreite = max(1, imagefontwidth($font) * strlen($text));
        $textHoehe = imagefontheight($font);

        // Text klein zeichnen, dann so weit wie moeglich hochziehen (max. 4×).
        $faktor = max(1, min(4, intdiv(self::BREITE - 40, $textBreite)));

        $klein = imagecreatetruecolor($textBreite, $textHoehe);
        imagefill($klein, 0, 0, imagecolorallocate($klein, 255, 255, 255));
        imagestring($klein, $font, 0, 0, $text, imagecolorallocate($klein, 0, 0, 0));

        $bild = imagecreatetruecolor(self::BREITE, self::HOEHE);
        imagefill($bild, 0, 0, imagecolorallocate($bild, 255, 255, 255));

        $zielBreite = $textBreite * $faktor;
        $zielHoehe = $textHoehe * $faktor;
        imagecopyresized(
            $bild, $klein,
            intdiv(self::BREITE - $zielBreite, 2), intdiv(self::HOEHE - $zielHoehe, 2),
            0, 0, $zielBreite, $zielHoehe, $textBreite, $textHoehe
        );

        ob_start();
        imagepng($bild);
        $bytes = (string) ob_get_clean();

        imagedestroy($klein);
        imagedestroy($bild);

        return $bytes;
    }
}
