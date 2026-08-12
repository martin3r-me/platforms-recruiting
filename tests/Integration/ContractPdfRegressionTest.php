<?php

namespace Platform\Recruiting\Tests\Integration;

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

/**
 * Belegt, dass die Zertifikat-Arbeit den Vertragsweg nicht beruehrt.
 *
 * Rendert einen festen Beispielinhalt durch das ECHTE Stylesheet aus
 * resources/views/pdf/contract.blade.php und friert Seitenzahl, Fontliste
 * und Textinhalt als SOLL ein.
 *
 * Abweichung vom urspruenglichen Task-Brief: Der Brief nennt als SOLL
 * ['DejaVuSans', 'Times-Bold'] und begruendet das mit einem angenommenen
 * Bestandsmakel (fette Zellen fielen auf den Core-Font zurueck). Gemessen
 * gegen den tatsaechlichen Testrunner (dompdf v3.1.5, wie in
 * meingedeck/composer.lock gepinnt) reproduziert sich das nicht: die
 * th-Zellen (font-weight:600 aus contract.blade.php UND font-weight:bold
 * aus dompdfs Default-UA-Stylesheet) werden korrekt auf DejaVuSans-Bold
 * abgebildet, deterministisch ueber mehrere Laeufe. Der eingefrorene SOLL
 * ist deshalb der tatsaechlich beobachtete Ist-Zustand:
 * ['DejaVuSans', 'DejaVuSans-Bold']. Wer das aendert (z.B. durch einen
 * Dompdf- oder Font-Cache-Wechsel), aendert diesen SOLL-Wert bewusst.
 */
class ContractPdfRegressionTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    private function contractStylesheet(): string
    {
        $blade = file_get_contents(self::MODULE_ROOT . '/resources/views/pdf/contract.blade.php');
        $css = preg_replace('/^.*?<style>/s', '<style>', $blade);

        return preg_replace('/<\/style>.*$/s', '</style>', $css);
    }

    private function render(): string
    {
        $body = '<div class="contract-content">'
            . '<p>§1 Vertragsgegenstand</p>'
            . '<p>Zwischen der RheinGedeck GmbH und Erika Mustermann, geb. 01.01.2000, '
            . 'wird folgender Arbeitsvertrag geschlossen. Stundenlohn 13,50 €.</p>'
            . '<table><tr><th>Feld</th><th>Wert</th></tr>'
            . '<tr><td>Beginn</td><td>01.09.2026</td></tr></table>'
            . '</div>';

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . $this->contractStylesheet() . '</head><body>' . $body . '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('dpi', 96);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        // Eigener fontCache pro Lauf, damit der Test nicht in den geteilten
        // vendor-Fontordner der Host-App (meingedeck) schreibt und sein
        // Ergebnis nicht vom dortigen, veraenderlichen Zustand abhaengt.
        // fontDir bleibt unangetastet — dort liegen die gebuendelten
        // DejaVu-Fonts, die lesbar bleiben muessen.
        $fontCache = sys_get_temp_dir() . '/rec-contract-pdf-fontcache-' . getmypid();
        @mkdir($fontCache, 0777, true);
        $options->set('fontCache', $fontCache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function testSeitenzahlBleibtEins(): void
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $this->render(), $m);

        $this->assertCount(1, $m[0]);
    }

    public function testFontlisteIstEingefroren(): void
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/', $this->render(), $m);

        $normalized = array_values(array_unique(array_map(
            fn (string $f) => preg_replace('/^[A-Z]+\+/', '', $f),
            $m[1]
        )));
        sort($normalized);

        $this->assertSame(['DejaVuSans', 'DejaVuSans-Bold'], $normalized);
    }

    /**
     * md5 ueber den gesamten <style>-Block, nicht zwei Stichproben.
     *
     * Dass ein legitimer Edit an contract.blade.php diesen Test rot macht,
     * ist der ZWECK: die Zertifikat-Arbeit darf das Vertrags-Stylesheet nicht
     * anfassen. Wer es aus einem anderen Grund aendert, aktualisiert den
     * Hash bewusst und begruendet es im Commit.
     */
    public function testVertragsstylesheetIstUnveraendert(): void
    {
        $css = $this->contractStylesheet();

        $this->assertSame(1347, strlen($css), 'Laenge des <style>-Blocks abgewichen.');
        $this->assertSame(
            '9e0d883726cd80892ad640c236103a67',
            md5($css),
            'contract.blade.php wurde geaendert. Zertifikat-Arbeit darf das nicht — '
            . 'war die Aenderung beabsichtigt, Hash bewusst aktualisieren.'
        );
    }
}
