<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DomPdfFontDir;

/**
 * Was DomPdfFontDir::ensureWritable() zusichert — und wogegen.
 *
 * DIE ZWEI ROTEN FAELLE SIND DER GRUND FUER DIESE KLASSE, nicht die zwei
 * gruenen: ein Guard, der nur "Verzeichnis existiert" prueft, sieht plausibel
 * aus und laesst den Fehlerfall durch (DomPDF legt dort .ufm-Dateien AN, ein
 * gesperrtes Verzeichnis gibt denselben TypeError wie ein fehlendes). Deshalb
 * steht hier je ein Fall fuer beide Stufen: Elternverzeichnis nicht
 * beschreibbar (mkdir scheitert) und Zielverzeichnis existiert, ist aber
 * gesperrt (mkdir wird nicht einmal versucht).
 *
 * WARUM chmod UND NICHT EIN MOCK: gemessen werden soll die Reaktion auf echte
 * Dateirechte, nicht auf einen abgesprochenen Rueckgabewert. Der Preis ist eine
 * Umgebungsabhaengigkeit: als root laufende Prozesse umgehen Dateirechte, ein
 * chmod 0500 haelt sie dann nicht auf. Beide Faelle pruefen deshalb ZUERST, ob
 * die Sperre ueberhaupt greift, und brechen mit Grund ab statt gruen zu werden —
 * ein gruener Test, dessen Sperre nicht greift, behauptet einen Schutz, den
 * niemand gemessen hat. Gemessen an diesem Arbeitsplatz (uid 908085942):
 * Elternverzeichnis 0500 -> is_writable=false, mkdir(): Permission denied;
 * Zielverzeichnis 0500 -> is_dir=true, is_writable=false, touch()=false.
 *
 * clearstatcache() nach jedem chmod ist Pflicht und keine Vorsicht: is_dir()
 * und is_writable() lesen PHPs Stat-Cache, und der Test hat dieselben Pfade
 * vorher schon per mkdir()/is_dir() angefasst. Ohne das Leeren pruefte
 * ensureWritable() den Zustand VOR dem chmod und der Fall waere gruen, ohne
 * je die Sperre gesehen zu haben. In der Auslieferung braucht es das nicht: die
 * Rechte aendern sich nicht mitten im Request.
 */
class DomPdfFontDirTest extends TestCase
{
    /** @var list<string> Verzeichnisse, deren Rechte im tearDown zurueckgesetzt werden muessen. */
    private array $gesperrt = [];

    /** @var list<string> Wurzeln, die wieder wegzuraeumen sind. */
    private array $wurzeln = [];

    protected function tearDown(): void
    {
        // Zuerst die Sperren loesen, sonst laesst sich nichts loeschen.
        foreach ($this->gesperrt as $pfad) {
            @chmod($pfad, 0777);
        }
        $this->gesperrt = [];

        foreach ($this->wurzeln as $wurzel) {
            $this->rekursivLoeschen($wurzel);
        }
        $this->wurzeln = [];

        clearstatcache();
    }

    /** Eine frische, noch nicht existierende Wurzel im Temp-Verzeichnis. */
    private function wurzel(): string
    {
        $pfad = rtrim(sys_get_temp_dir(), '/') . '/zert-fontdir-' . getmypid() . '-' . count($this->wurzeln);
        $this->wurzeln[] = $pfad;

        return $pfad;
    }

    private function rekursivLoeschen(string $pfad): void
    {
        if (!is_dir($pfad)) {
            @unlink($pfad);

            return;
        }

        $eintraege = scandir($pfad);
        foreach ($eintraege === false ? [] : $eintraege as $eintrag) {
            if ($eintrag !== '.' && $eintrag !== '..') {
                $this->rekursivLoeschen($pfad . '/' . $eintrag);
            }
        }

        @rmdir($pfad);
    }

    /**
     * Sperrt $pfad und belegt, dass die Sperre greift.
     *
     * Greift sie nicht (root, oder ein Dateisystem ohne Rechte), wird
     * uebersprungen — mit dem Grund, nicht stillschweigend.
     */
    private function sperren(string $pfad): void
    {
        @chmod($pfad, 0500);
        $this->gesperrt[] = $pfad;
        clearstatcache(true, $pfad);

        if (is_writable($pfad)) {
            self::markTestSkipped(sprintf(
                'chmod 0500 greift hier nicht: %s ist danach weiter beschreibbar (uid %s). '
                . 'Dieser Fall wird uebersprungen statt gruen gemeldet — ein gruener Lauf '
                . 'wuerde einen Schutz behaupten, der nicht gemessen wurde.',
                $pfad,
                function_exists('posix_geteuid') ? (string) posix_geteuid() : 'unbekannt'
            ));
        }
    }

    // -----------------------------------------------------------------
    // Gutfaelle
    // -----------------------------------------------------------------

    public function testLegtEinFehlendesVerzeichnisAnUndLiefertDenPfad(): void
    {
        // Mehrstufig mit Absicht: storage/fonts fehlt in der Produktion unter
        // einem existierenden storage/, aber der Guard muss auch tiefer greifen.
        $ziel = $this->wurzel() . '/tiefer/fonts';

        $this->assertDirectoryDoesNotExist($ziel);
        $this->assertSame($ziel, DomPdfFontDir::ensureWritable($ziel));
        $this->assertDirectoryExists($ziel);
        $this->assertDirectoryIsWritable($ziel);
    }

    public function testZweiterAufrufAufDasselbeVerzeichnisIstStill(): void
    {
        // Der Normalfall in der Auslieferung: fontDir und fontCache zeigen auf
        // denselben Pfad, ensureWritable() laeuft also zweimal pro Aufruf. Ein
        // ungeprueftes mkdir() liefert beim zweiten Mal false — wer daraus einen
        // Fehler macht, hat einen 500 auf dem Normalfall gebaut.
        $ziel = $this->wurzel() . '/fonts';

        $this->assertSame($ziel, DomPdfFontDir::ensureWritable($ziel));
        $this->assertSame($ziel, DomPdfFontDir::ensureWritable($ziel));
    }

    public function testSchlussTrennzeichenWirdEntfernt(): void
    {
        // Die Paket-Konfiguration schreibt "*Please note the trailing slash*" an
        // die font_dir-Zeile. DomPDF haengt selbst "/" plus Dateiname an, ein
        // doppelter Schraegstrich ist harmlos — aber der zurueckgegebene Pfad
        // landet in Fehlermeldungen und Logzeilen, und dort ist genau eine
        // Schreibweise weniger verwirrend.
        $ziel = $this->wurzel() . '/fonts';

        $this->assertSame($ziel, DomPdfFontDir::ensureWritable($ziel . '/'));
        $this->assertSame($ziel, DomPdfFontDir::ensureWritable($ziel . '///'));
    }

    // -----------------------------------------------------------------
    // Rote Faelle
    // -----------------------------------------------------------------

    public function testLeererPfadWirft(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Kein Font-Verzeichnis angegeben/');

        DomPdfFontDir::ensureWritable('');
    }

    public function testPfadAusLeerzeichenWirft(): void
    {
        // ' ' ist nicht leer, aber als Verzeichnis genauso unbrauchbar: mkdir(' ')
        // legte ein Verzeichnis mit dem Namen Leerzeichen an, und die Metriken
        // lagen dann im Arbeitsverzeichnis des Webservers.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Kein Font-Verzeichnis angegeben/');

        DomPdfFontDir::ensureWritable('   ');
    }

    /**
     * FALSIFIKATOR 1: Elternverzeichnis nicht beschreibbar, Ziel fehlt.
     *
     * Ohne die Pruefung des mkdir()-Rueckgabewerts liefe die Auslieferung
     * weiter und DomPDF faellt still auf sein eigenes vendor-Verzeichnis
     * zurueck — gemessen in meingedeck/vendor/dompdf/dompdf/lib/fonts, wo aus
     * einem frueheren Lauf zert_normal_<md5>.ufm liegt. Genau dieser stille
     * Rueckfall ist der Grund, warum hier geworfen wird.
     */
    public function testNichtBeschreibbaresElternverzeichnisWirftMitPfadUndGrund(): void
    {
        $eltern = $this->wurzel();
        mkdir($eltern, 0777, true);
        $ziel = $eltern . '/fonts';

        $this->sperren($eltern);

        // Gefangene Exception in einer Variablen statt try/fail/catch, und das
        // ist hier keine Stilfrage: PHPUnits AssertionFailedError IST eine
        // \RuntimeException (gemessen: AssertionFailedError -> PHPUnit\Framework\
        // Exception -> RuntimeException), ein catch(\RuntimeException) schluckt
        // also das fail() dieses Tests mit und meldet den Fehlschlag als
        // Wortlaut-Problem an einer fremden Meldung. Die erste Fassung dieses
        // Tests hatte genau das; aufgefallen ist es erst in der Mutation.
        // Dasselbe Muster mit derselben Messung: IssueTrainingCertificateServiceTest.
        $gefangen = null;
        try {
            DomPdfFontDir::ensureWritable($ziel);
        } catch (\RuntimeException $e) {
            $gefangen = $e;
        }

        $this->assertNotNull($gefangen, sprintf(
            'ensureWritable() hat %s akzeptiert, obwohl das Elternverzeichnis %s '
            . 'gesperrt ist. Damit waere der Guard wirkungslos.',
            $ziel,
            $eltern
        ));

        // Pfad UND Grund muessen in der Meldung stehen: ohne den Pfad sucht der
        // Betrieb am falschen Ort, ohne den Grund (Rechte? Volle Platte? Datei
        // im Weg?) faengt er von vorn an.
        $this->assertStringContainsString($ziel, $gefangen->getMessage());
        $this->assertStringContainsString($eltern, $gefangen->getMessage());
        $this->assertStringContainsString('Permission denied', $gefangen->getMessage());

        // Und kein Ausweichen: es darf NICHTS angelegt worden sein.
        $this->assertDirectoryDoesNotExist($ziel);
    }

    /**
     * FALSIFIKATOR 2: Zielverzeichnis existiert, ist aber nicht beschreibbar.
     *
     * Der Fall, den ein Guard mit is_dir() allein durchlaesst — und er ist in
     * der Produktion der wahrscheinlichere von beiden: storage/fonts wird von
     * Hand oder per Deploy-Skript angelegt, und dann gehoert es dem falschen
     * Benutzer.
     */
    public function testExistierendesAberGesperrtesVerzeichnisWirft(): void
    {
        $ziel = $this->wurzel() . '/fonts';
        mkdir($ziel, 0777, true);

        $this->sperren($ziel);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ist nicht beschreibbar/');
        $this->expectExceptionMessageMatches('/' . preg_quote($ziel, '/') . '/');

        DomPdfFontDir::ensureWritable($ziel);
    }

    public function testDateiAnStelleDesVerzeichnissesWirft(): void
    {
        // Eine DATEI namens fonts ist kein exotischer Fall: ein verirrtes
        // "touch storage/fonts" im Deploy-Skript erzeugt genau das. is_dir() ist
        // dann false, mkdir() scheitert mit "File exists" — und ohne Guard
        // schriebe DomPDF wieder in sein vendor-Verzeichnis.
        $ziel = $this->wurzel();
        file_put_contents($ziel, 'keine Verzeichnis');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/konnte nicht angelegt werden/');

        DomPdfFontDir::ensureWritable($ziel);
    }
}
