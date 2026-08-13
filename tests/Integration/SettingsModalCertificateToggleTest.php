<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\IssueTrainingCertificateService;

/**
 * Das Bedienelement des Team-Schalters issue_training_certificates im
 * Einstellungen-Modal: WAS es an den Browser schickt, und WO es in der Sektion
 * steht.
 *
 * Warum gerendert und nicht gegrept: ein Treffer auf
 * "issue_training_certificates" im Dateitext waere schon von einem Kommentar
 * erfuellt. Dieser Test schneidet den Schalter-Block aus der ECHTEN Datei,
 * kompiliert ihn mit dem echten BladeCompiler und fuehrt das Ergebnis aus —
 * geprueft wird die Ausgabe. Dass der Kommentar oberhalb des Blocks in der
 * Ausgabe NICHT mehr auftaucht, ist Teil der Pruefung: ein nicht geschlossener
 * Blade-Kommentar ist in diesem Modul schon einmal als 500er hochgekommen.
 *
 * Der zweite Teil prueft die REIHENFOLGE der Sektion, und der Grund dafuer ist
 * gemessen: der erste Vorschlag fuer den Einfuegepunkt haette den
 * Jugendschutz-Hinweis von seinem Select getrennt. Danach lesen sich beide
 * falsch — der Hinweis als Erklaerung des Zertifikat-Schalters, der Schalter
 * als Teil des Jugendschutzes. Die Reihenfolge ist deshalb festgenagelt, nicht
 * nur die Anwesenheit.
 *
 * PROZESSWEITER ZUSTAND: keiner. Kein Eloquent-Modell, keine Facade — der
 * Ausschnitt enthaelt keine <x-*>-Tags, die den Container brauchen (das ist
 * mitgeprueft: der Schnitt endet vor dem ersten @if der Gruppe). Aufgeraeumt
 * werden nur die eigenen Temp-Dateien.
 */
class SettingsModalCertificateToggleTest extends TestCase
{
    /** Das Einstellungen-Modal, relativ zum Modul-Root. */
    private const BLADE = 'resources/views/livewire/applicant/applicant-settings-modal.blade.php';

    /** Kopf der Zertifikat-Gruppe; Anfang des Schnitts. */
    private const MARKER_GRUPPE = '{{-- SCHULUNGSZERTIFIKAT';

    /**
     * Ende des Schnitts: das erste @if der Gruppe (davor steht der Schalter,
     * dahinter das Template-Select mit seinem <x-ui-input-select>).
     */
    private const MARKER_ENDE = '@if(';

    private string $tmpDir = '';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/recruiting-settings-toggle-' . getmypid() . '-' . uniqid();
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            $this->fail('Temp-Verzeichnis nicht anlegbar: ' . $this->tmpDir);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];

        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $rest) {
                if (is_file($rest)) {
                    unlink($rest);
                }
            }
            rmdir($this->tmpDir);
        }
        $this->tmpDir = '';
    }

    /**
     * Der Kern: die Sektion schickt genau EIN Eingabefeld an den Browser, es
     * ist eine Checkbox, und sie haengt am Schluessel, den der Service liest.
     */
    public function testDerSchalterIstEineCheckboxAufDemSchluesselDesServices(): void
    {
        $out = $this->renderGruppe();

        $this->assertSame(
            1,
            substr_count($out, '<input '),
            "Der Schalter-Block schickt nicht genau ein Eingabefeld:\n{$out}"
        );
        $this->assertStringContainsString('type="checkbox"', $out, "Der Schalter ist keine Checkbox:\n{$out}");

        $this->assertMatchesRegularExpression(
            '/wire:model(\.[a-z]+)*="settings\.' . preg_quote(IssueTrainingCertificateService::SETTING_ENABLED, '/') . '"/',
            $out,
            'Die Checkbox haengt nicht an settings.' . IssueTrainingCertificateService::SETTING_ENABLED
            . " — ohne dieses Binding zeigt das Formular einen Haken, der nirgends ankommt:\n{$out}"
        );
    }

    /**
     * Falsifizierbarkeit dieses Tests (Regel 1): er wird rot, wenn der Schalter
     * auf <x-ui-input-checkbox> umgestellt wird. Das ist keine kosmetische
     * Grenze — die Komponente ist ein Toggle-Button mit
     * Alpine-entangle(...).live, und genau dieser Mechanismus verliert in
     * DIESEM Modal beim Speichern Werte (gemessen an x-ui-input-select,
     * Ledger-Befund "Settings-Modal: Selects speichern nicht").
     */
    public function testDerSchalterBenutztKeinEntangleUndKeinAlpine(): void
    {
        $out = $this->renderGruppe();

        $this->assertStringNotContainsString(
            'entangle',
            $out,
            "Der Schalter laeuft ueber entangle — in diesem Modal der gemessene Weg, auf dem Werte beim Speichern verloren gehen:\n{$out}"
        );
        $this->assertStringNotContainsString(
            'x-data',
            $out,
            "Der Schalter bringt eigenes Alpine mit; die beiden Nachbar-Checkboxen dieser Sektion tun das nicht:\n{$out}"
        );
    }

    /**
     * Der Kommentar der Gruppe ist ein Kommentar und kein Text auf der Seite.
     * Faengt einen nicht geschlossenen Blade-Kommentar — der Rest der Sektion
     * landete dann sichtbar im Modal.
     */
    public function testDerGruppenKommentarErreichtDenBrowserNicht(): void
    {
        $out = $this->renderGruppe();

        $this->assertStringNotContainsString('{{--', $out, "Kommentar-Anfang steht in der Ausgabe:\n{$out}");
        $this->assertStringNotContainsString('--}}', $out, "Kommentar-Ende steht in der Ausgabe:\n{$out}");
        $this->assertStringNotContainsString(
            'SCHULUNGSZERTIFIKAT —',
            $out,
            "Der Gruppen-Kommentar wird als Text gerendert — er ist nicht (mehr) geschlossen:\n{$out}"
        );
    }

    /** Der Schalter sagt dem Bediener, was AUS bedeutet — sonst ist er nicht bedienbar. */
    public function testDerSchalterBeschriftetSichUndSeinenAusZustand(): void
    {
        $out = $this->renderGruppe();

        $this->assertStringContainsString('Schulungszertifikate ausstellen', $out, "Beschriftung fehlt:\n{$out}");
        $this->assertStringContainsString('Ohne Haken', $out, "Erklaerung des Aus-Zustands fehlt:\n{$out}");
    }

    /**
     * Die Reihenfolge der Sektion, festgenagelt: Jugendschutz-Paar (Status-
     * Select, Template-Select, Hinweis) → Zertifikat-Gruppe (Schalter,
     * Template-Select, Hinweis) → Ansprechpartner.
     */
    public function testDieReihenfolgeDerSektionStehtFest(): void
    {
        $source = $this->source();

        $anker = [
            'Jugendschutz-Status-Select'   => 'name="settings.minor_rejection_status_id"',
            'Jugendschutz-Template-Select' => 'name="settings.minor_rejection_template_id"',
            'Jugendschutz-Hinweis'         => 'Bewerber unter 16 werden automatisch abgesagt',
            'Zertifikat-Gruppenkommentar'  => self::MARKER_GRUPPE,
            'Zertifikat-Schalter'          => 'settings.' . IssueTrainingCertificateService::SETTING_ENABLED,
            'Zertifikat-Template-Select'   => 'name="settings.training_certificate_wa_template_id"',
            // Anker des Hinweistexts: der Ausdruck, mit dem die Blade die aus
            // der Route abgeleitete Button-URL zieht. Vorher stand hier der Name
            // der Body-Variable — mit dem Wechsel auf den URL-Button gibt es
            // keinen solchen Namen mehr, den der Hinweis nennen koennte.
            // Nachgemessen: der Ausdruck kommt genau einmal in der Datei vor
            // (grep -c metaButtonUrl => 1); die substr_count-Pruefung unten
            // haelt das fest.
            'Zertifikat-Hinweis'           => 'metaButtonUrl',
            'Ansprechpartner-Select'       => 'name="settings.default_contact_user_id"',
        ];

        $positionen = [];
        foreach ($anker as $name => $needle) {
            $this->assertSame(
                1,
                substr_count($source, $needle),
                "Anker '{$name}' kommt nicht genau einmal vor — die Reihenfolge waere damit nicht mehr pruefbar: {$needle}"
            );
            $positionen[$name] = strpos($source, $needle);
        }

        $erwartet = array_keys($anker);
        asort($positionen);
        $tatsaechlich = array_keys($positionen);

        $this->assertSame(
            $erwartet,
            $tatsaechlich,
            'Die Sektion steht in anderer Reihenfolge als festgelegt. Erwartet: '
            . implode(' → ', $erwartet) . '; tatsaechlich: ' . implode(' → ', $tatsaechlich)
        );
    }

    /**
     * DER Fund, aus dem dieser Test entstand: zwischen dem Jugendschutz-
     * Template-Select und seinem Hinweistext darf NICHTS stehen. Ein hier
     * eingeschobenes Bedienelement macht aus dem Hinweis die Erklaerung des
     * eingeschobenen Elements — beide Bloecke lesen sich danach falsch, ohne
     * dass irgendwas kaputt aussieht.
     */
    public function testZwischenJugendschutzSelectUndSeinemHinweisStehtNichts(): void
    {
        $source = $this->source();

        $von = strpos($source, 'name="settings.minor_rejection_template_id"');
        $bis = strpos($source, 'Bewerber unter 16 werden automatisch abgesagt');
        $this->assertIsInt($von);
        $this->assertIsInt($bis);
        $this->assertLessThan($bis, $von, 'Der Jugendschutz-Hinweis steht vor seinem Select');

        $dazwischen = substr($source, $von, $bis - $von);

        $this->assertSame(
            0,
            substr_count($dazwischen, '<input '),
            "Zwischen dem Jugendschutz-Select und seinem Hinweis steht ein Eingabefeld:\n{$dazwischen}"
        );
        $this->assertSame(
            0,
            substr_count($dazwischen, '<x-ui-input'),
            "Zwischen dem Jugendschutz-Select und seinem Hinweis steht ein weiteres Formularfeld:\n{$dazwischen}"
        );
    }

    /** Quelltext der Blade, mit Existenz- und Lesbarkeitspruefung. */
    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::BLADE;
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertNotFalse($source, 'Blade nicht lesbar: ' . self::BLADE);

        return $source;
    }

    /**
     * Schneidet die Zertifikat-Gruppe ab ihrem Kommentar bis zum ersten @if
     * (also Kommentar + Schalter, ohne das Template-Select), kompiliert und
     * fuehrt sie aus.
     */
    private function renderGruppe(): string
    {
        $source = $this->source();

        $this->assertSame(
            1,
            substr_count($source, self::MARKER_GRUPPE),
            'Marker kommt nicht genau einmal vor — der Ausschnitt waere nicht eindeutig: ' . self::MARKER_GRUPPE
        );

        $start = strpos($source, self::MARKER_GRUPPE);
        $end   = strpos($source, self::MARKER_ENDE, $start);
        $this->assertNotFalse($end, 'Kein @if hinter dem Gruppen-Kommentar — der Schnitt haette keine Grenze');

        $fragment = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString(
            '<x-',
            $fragment,
            "Der Ausschnitt enthaelt ein Blade-Komponenten-Tag; das braucht den Container und ist hier nicht ausfuehrbar:\n{$fragment}"
        );

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $compiled = $compiler->compileString($fragment);

        $file = $this->tmpDir . '/' . md5(self::BLADE . self::MARKER_GRUPPE) . '.php';
        file_put_contents($file, $compiled);
        $this->tmpFiles[] = $file;

        return (static function (string $__file): string {
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        })($file);
    }
}
