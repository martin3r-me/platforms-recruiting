<?php

namespace Platform\Recruiting\Tests\Integration;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;

/**
 * Belegt, dass die Zertifikat-Arbeit den Vertragsweg nicht beruehrt.
 *
 * Rendert einen festen Beispielinhalt durch das ECHTE Stylesheet aus
 * resources/views/pdf/contract.blade.php und friert Seitenzahl und
 * Fontliste als SOLL ein (siehe render()), zusaetzlich den md5 des
 * Stylesheet-Blocks (siehe contractStylesheet()) und — ueber die echte
 * RendersContractPdf-Logik, nicht ueber ein synthetisches Render — die
 * Firmenstempel-Injektion fuer AV-* vs. andere Vorlagen.
 *
 * Bekannte Luecke: KEINE Assertion auf extrahierten PDF-Text. Die Spec
 * verlangt "extrahierter Text identisch" als eigenes Kriterium; das ist
 * hier nicht umgesetzt, weil personalizeContent() Models und DB braucht
 * und der Runner kein Laravel bootet (siehe Task-Brief, "Bewusste
 * Abweichung von der Spec"). Eine Aenderung an personalizeContent(),
 * die nur den Text veraendert, faellt durch dieses Netz.
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

    /**
     * Aufraeumen der Model-Boot-Hygiene, die diese Klasse selbst kaputt
     * macht. testFirmenstempelWirdBeiArbeitsvertragInjiziert() und
     * testFirmenstempelWirdBeiIfsgNichtInjiziert() bauen RecContractTemplate
     * und RecContract per `new` (kein ::create(), keine Capsule, kein
     * Dispatcher). Das reicht, um Eloquent zu booten: `new` ruft
     * bootIfNotBooted() auf, und Model::$booted ist PROZESSWEIT statisch,
     * nicht pro Testklasse.
     *
     * Wird eine dieser beiden Model-Klassen dabei zum ERSTEN Mal im ganzen
     * PHPUnit-Lauf gebootet (der Fall, wenn diese Klasse alphabetisch vor
     * anderen Integrationstests liegt, die dieselben Modelle per ::create()
     * verwenden), registrieren deren static::creating()-Hooks NICHTS: ohne
     * gesetzten Dispatcher ist die Hook-Registrierung ein No-Op. Jede
     * spaetere Testklasse erbt die Modelle dann als "bereits gebootet",
     * aber mit toten Hooks — die eigene uuid-Generierung von
     * RecContractTemplate feuert nicht mehr, und das Insert bricht mit
     * "NOT NULL constraint failed: rec_contract_templates.uuid". Das
     * Symptom taucht NUR im Gesamtlauf auf, nie im gefilterten Lauf dieser
     * Klasse allein — eine der Fehlerarten, die man einmal drei Stunden
     * sucht.
     *
     * Fix an der Quelle: hinter sich aufraeumen. Model::clearBootedModels()
     * hier zwingt jede Model-Klasse (nicht nur die beiden hier benutzten),
     * beim naechsten Gebrauch neu zu booten — gegen den Dispatcher, den die
     * dann laufende Testklasse sich selbst aufsetzt.
     */
    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        self::removeFontCache();
    }

    /**
     * Den eigenen fontCache wieder wegraeumen. Ohne das bleibt pro Testlauf ein
     * Verzeichnis liegen — gemessen 75 Stueck, bevor das auffiel. Der Name
     * enthaelt die PID, also kollidiert nichts, aber es sammelt sich eben auch
     * nie wieder auf. Task 9 wird dieses Muster kopieren, deshalb steht das
     * Aufraeumen hier und nicht nur als Vorsatz.
     *
     * Bleibt etwas liegen, geht die Meldung auf STDERR und der Testlauf bleibt
     * gruen. Diese Wahl ist bewusst und die Begruendung gehoert dazu: die
     * Methode laeuft in tearDownAfterClass(), also NACH allen Assertionen. Eine
     * Exception oder ein self::fail() von hier aus wuerde eine Aussage ueber die
     * Bestandsvertraege — die Tests dieser Klasse — rot faerben, obwohl gar nichts
     * am Vertragsweg kaputt ist, sondern nur ein Verzeichnis klemmt (Rechte,
     * offene Handles, gemountetes Laufwerk). Der Befund, der zu dieser Methode
     * gefuehrt hat, war nicht "raeumt zu wenig auf", sondern "raeumt lautlos
     * nicht auf". Genau das ist behoben: nichts bleibt mehr unbemerkt liegen.
     */
    private static function removeFontCache(): void
    {
        $dir = self::fontCacheDir();
        if (!is_dir($dir)) {
            return;
        }

        $leftovers = self::deleteRecursively($dir);
        if ($leftovers === []) {
            return;
        }

        fwrite(STDERR, PHP_EOL . sprintf(
            '[%s] fontCache nicht vollstaendig entfernt (%d Eintrag/Eintraege bleiben liegen):%s  %s%s',
            self::class,
            count($leftovers),
            PHP_EOL,
            implode(PHP_EOL . '  ', $leftovers),
            PHP_EOL
        ));
    }

    /**
     * Loescht $dir samt Inhalt und liefert zurueck, was NICHT wegzuraeumen war.
     *
     * scandir() statt glob(): glob() liefert Dotfiles nicht (ein .DS_Store im
     * fontCache blieb damit liegen) und liest *, ?, [ ] im Pfad als Muster —
     * ein Verzeichnisname mit eckigen Klammern liess glob() auf ALLES leer
     * laufen, nicht nur auf den Sonderfall. Gemessen an vier Faellen: der alte
     * Aufraeumer liess Dotfile, Unterverzeichnis und Metazeichen-Pfad liegen
     * (drei von vier), scandir() raeumt alle vier.
     *
     * Das @ vor unlink()/rmdir() unterdrueckt nur die PHP-Diagnose — die
     * phpunit.xml hat failOnWarning="true", eine Warnung von hier wuerde die
     * Klasse rot machen. Der Rueckgabewert wird stattdessen ausgewertet und
     * landet in der Liste. Wer das @ entfernt, muss das Signal weiterhin ueber
     * den Rueckgabewert fuehren, nicht ueber die Warnung.
     *
     * @return list<string> Pfade, die stehen geblieben sind; leer = sauber
     */
    private static function deleteRecursively(string $dir): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return [$dir];
        }

        $failed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            // is_dir() folgt Symlinks. Ein Symlink auf ein Verzeichnis wird
            // deshalb per unlink() entfernt (loescht den Link) und NICHT
            // rekursiv ausgeraeumt — sonst loeschte das Aufraeumen fremde
            // Dateien am Linkziel.
            if (!is_link($path) && is_dir($path)) {
                $failed = array_merge($failed, self::deleteRecursively($path));
                continue;
            }

            if (!@unlink($path)) {
                $failed[] = $path;
            }
        }

        if (!@rmdir($dir)) {
            $failed[] = $dir;
        }

        return $failed;
    }

    private static function fontCacheDir(): string
    {
        return sys_get_temp_dir() . '/rec-contract-pdf-fontcache-' . getmypid();
    }

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
        // Aufgeraeumt wird in tearDownAfterClass() — siehe removeFontCache().
        $options->set('fontCache', $this->prepareFontCacheDir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Legt das fontCache-Verzeichnis an und scheitert mit Grund, wenn das nicht
     * geht.
     *
     * Der Rueckgabewert von mkdir() MUSS geprueft werden. Gemessen mit einem
     * nicht beschreibbaren Elternverzeichnis: der vorherige Stand (@mkdir ohne
     * Pruefung) scheiterte nicht spaeter, sondern blieb GRUEN — "OK (6 tests,
     * 8 assertions)". DomPDF faellt bei nicht existierendem fontCache still auf
     * seinen Standard zurueck, und der ist der geteilte Fontordner der Host-App
     * (gemessen: Options::getFontCache() ohne Zuweisung liefert
     * meingedeck/vendor/dompdf/dompdf/lib/fonts, und DomPDF legt dort
     * DejaVuSans.ufm.json und DejaVuSans-Bold.ufm.json ab). Genau die Isolation,
     * die render() zusichert, verschwand also lautlos — der Test lief weiter und
     * behauptete weiter, ueber einen eigenen fontCache zu messen.
     *
     * Das @ bleibt, damit die PHP-Warnung nicht unter failOnWarning="true" eine
     * zweite, unvollstaendige Fehlermeldung erzeugt; der Grund wird aus
     * error_get_last() geholt und in die eigene Meldung gehoben. Wer das @
     * entfernt, muss den Grund weiterhin in die Meldung schreiben.
     */
    private function prepareFontCacheDir(): string
    {
        $fontCache = self::fontCacheDir();

        // mkdir() mit recursive=true liefert false, wenn das Verzeichnis schon
        // existiert — render() laeuft mehrfach pro Klasse, das ist der Normalfall.
        if (is_dir($fontCache)) {
            return $fontCache;
        }

        if (!@mkdir($fontCache, 0777, true) && !is_dir($fontCache)) {
            $reason = error_get_last()['message'] ?? 'PHP hat keinen Grund gemeldet';
            self::fail(sprintf(
                'fontCache-Verzeichnis konnte nicht angelegt werden: %s — %s. '
                . 'Der Test bricht hier ab statt weiterzulaufen: ohne eigenen fontCache '
                . 'benutzt DomPDF still den geteilten Fontordner der Host-App '
                . '(vendor/dompdf/dompdf/lib/fonts), und dann haengt das Ergebnis vom '
                . 'dortigen, veraenderlichen Zustand ab — der Lauf waere gruen, aber die '
                . 'zugesicherte Isolation weg. Pruefen: Schreibrechte auf %s, und ob dort '
                . 'schon eine Datei gleichen Namens liegt.',
                $fontCache,
                $reason,
                dirname($fontCache)
            ));
        }

        return $fontCache;
    }

    public function testSeitenzahlBleibtEins(): void
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $this->render(), $m);

        $this->assertCount(
            1,
            $m[0],
            'Bestandsvertrag: der feste Beispielinhalt muss auf EINE Seite passen. '
            . 'Sind es mehr, braucht das Vertragslayout aus resources/views/pdf/'
            . 'contract.blade.php jetzt mehr Platz als vorher — Schriftgroesse, '
            . 'Zeilenhoehe, margin oder @page haben sich geaendert. Die Zertifikat-Arbeit '
            . 'darf das nicht bewirken; wurde das Vertragslayout absichtlich geaendert, '
            . 'ist die neue Seitenzahl bewusst einzufrieren und im Commit zu begruenden.'
        );
    }

    public function testFontlisteIstEingefroren(): void
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/', $this->render(), $m);

        $normalized = array_values(array_unique(array_map(
            fn (string $f) => preg_replace('/^[A-Z]+\+/', '', $f),
            $m[1]
        )));
        sort($normalized);

        $this->assertSame(
            ['DejaVuSans', 'DejaVuSans-Bold'],
            $normalized,
            'Bestandsvertrag: das PDF muss genau DejaVuSans und DejaVuSans-Bold einbetten '
            . '(normal und fett). Eine kuerzere Liste heisst, dass ein Schnitt nicht mehr '
            . 'eingebettet wird und der Text still auf einen PDF-Core-Font zurueckfaellt — '
            . 'DomPDF meldet das nicht, das Ergebnis sieht nur anders aus. Ein zusaetzlicher '
            . 'Name heisst, dass eine fremde Schrift in den Vertragsweg gelangt ist; die '
            . 'Zertifikat-Schrift (Oswald) hat dort nichts zu suchen. Ursachen: geaenderte '
            . 'font-family/font-weight im Vertrags-Stylesheet, verstellter fontDir, '
            . 'anderer Dompdf-Stand. Wurde die Fontliste absichtlich geaendert, ist der neue '
            . 'SOLL bewusst einzufrieren und im Commit zu begruenden.'
        );
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

    /**
     * Anonyme Klasse, die den ECHTEN Trait RendersContractPdf nutzt und
     * seine beiden protected Methoden ueber Trait-Aliasing public macht.
     * Kein DB-/Laravel-Bootstrap noetig: prepareContractContentForPdf()
     * und loadCompanyStampDataUrl() greifen nicht auf die Datenbank zu,
     * solange die contractTemplate-Relation vorab per setRelation()
     * gesetzt ist (dann feuert die BelongsTo keine Query).
     */
    private function stampHost(): object
    {
        return new class {
            use RendersContractPdf {
                prepareContractContentForPdf as public pub;
                loadCompanyStampDataUrl as public stamp;
            }
        };
    }

    public function testFirmenstempelDataUrlHatPngPraefix(): void
    {
        $dataUrl = $this->stampHost()->stamp();

        $this->assertNotNull($dataUrl, 'resources/images/company-stamp.png sollte ladbar sein.');
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    /**
     * Positivfall: AV-* Vorlagen bekommen den Stempel injiziert.
     */
    public function testFirmenstempelWirdBeiArbeitsvertragInjiziert(): void
    {
        $template = new RecContractTemplate(['code' => 'AV-010']);
        $contract = new RecContract();
        $contract->personalized_content = "<p>Vertrag</p>\n<p>RheinGedeck GmbH</p>";
        $contract->setRelation('contractTemplate', $template);

        $result = $this->stampHost()->pub($contract);

        $this->assertStringContainsString(
            'data:image/png;base64,',
            $result,
            'AV-010 ist ein Arbeitsvertrag und sollte den Firmenstempel enthalten.'
        );
    }

    /**
     * Negativfall: eine Vorlage ohne AV-Praefix (hier IFSG) bekommt KEINEN
     * Stempel. Ohne diesen Fall wuerde ein Test, der den Stempel ploetzlich
     * ueberall injiziert, nicht auffallen.
     */
    public function testFirmenstempelWirdBeiIfsgNichtInjiziert(): void
    {
        $template = new RecContractTemplate(['code' => 'IFSG']);
        $contract = new RecContract();
        $contract->personalized_content = "<p>Belehrung</p>\n<p>RheinGedeck GmbH</p>";
        $contract->setRelation('contractTemplate', $template);

        $result = $this->stampHost()->pub($contract);

        $this->assertStringNotContainsString(
            'data:image/png;base64,',
            $result,
            'Nur AV-* Vorlagen sollen den Firmenstempel bekommen, IFSG nicht.'
        );
    }
}
