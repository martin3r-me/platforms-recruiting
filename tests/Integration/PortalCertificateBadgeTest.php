<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificatePortalRows;

/**
 * Was die Portal-Blades aus einer Zertifikat-Zeile machen — GERENDERT,
 * nicht gelesen.
 *
 * Warum so und nicht als Livewire-Test: Livewire-Komponenten sind in diesem
 * Modul nicht instanziierbar (kein Laravel-Bootstrap, kein testbench). Statt
 * die Blade als Text nach Stichworten zu durchsuchen — was ein Treffer in einem
 * Kommentar schon erfuellt haette — schneidet dieser Test den Badge-Block und
 * den Button-Block aus der ECHTEN Datei, kompiliert sie mit dem echten
 * BladeCompiler und fuehrt das Ergebnis mit einer Zeile aus. Geprueft wird
 * damit die Ausgabe, nicht der Quelltext.
 *
 * Der Ausschnitt haengt an Markern, die in ihrer Datei genau EINMAL vorkommen;
 * dass sie einmalig sind, prueft dieser Test mit (sonst schnitte er still an
 * der falschen Stelle).
 *
 * SEIT DEM PORTAL-GLEICHSTAND STEHT HIER AUCH DAS NEUE PORTAL
 * (`portal-shell.blade.php`). Es lag zuerst nicht in dieser Liste, und damit
 * galt E17 — die Zertifikat-Zeile darf keine Unterschrift behaupten — genau
 * fuer die Ansicht nicht, die das alte Portal abloesen soll. Das neue Portal
 * baut die Zeile anders auf (die Zweige stehen in einem @php-Block, Badge und
 * Knoepfe liegen in derselben Zeile), deshalb tragen die Blades ihre Marker
 * jetzt einzeln statt gemeinsam.
 *
 * GRENZE, benannt statt behauptet: der Rest der Seite (Zustands-Zweige,
 * Ueberschriften, Layout) laeuft hier nicht. `@svg(...)` ist im Ausschnitt
 * keine Direktive, sondern bleibt als Text stehen — der Icon-Name ist damit
 * NICHT geprueft, nur die Textbausteine und die Reihenfolge der Zweige.
 *
 * PROZESSWEITER ZUSTAND: keiner. Diese Klasse bootet kein Eloquent-Modell und
 * loest keine Facade auf (BladeCompiler + Filesystem sind reine Objekte, der
 * Ausschnitt enthaelt keine <x-*>-Tags, die den Container brauchen). Deshalb
 * KEIN Facade::clearResolvedInstances()/Model::clearBootedModels() — es gaebe
 * hier nichts wegzuraeumen, und ein Aufruf „zur Vorsorge" hat in diesem Paket
 * schon einmal die Suche nach dem echten Verursacher verhindert. Aufgeraeumt
 * werden nur die eigenen Temp-Dateien.
 */
class PortalCertificateBadgeTest extends TestCase
{
    /**
     * Die Portal-Blades, relativ zum Modul-Root, mit ihren Schnittmarken.
     *
     * 'badge'   Ausschnitt, der den Status-Text der Zeile erzeugt.
     * 'buttons' Ausschnitt, der Unterschreiben- und PDF-Knopf erzeugt.
     * 'start'   muss in der Datei GENAU EINMAL vorkommen; 'ende' ist die
     *           erste Fundstelle dahinter und wird mitgeschnitten.
     *
     * Beim neuen Portal ist es fuer beides derselbe Ausschnitt: dort stehen
     * Badge und Knoepfe in einer einzigen Zeilen-Schleife, und die Zweige
     * werden in einem @php-Block davor vorberechnet (Hausregel: kein @if im
     * Attribut). Der Ausschnitt ist deshalb die ganze @forelse-Schleife und
     * laeuft mit einer Liste aus genau einer Zeile.
     */
    private const BLADES = [
        'employee-portal' => [
            'pfad'    => 'resources/views/livewire/public/employee-portal.blade.php',
            'badge'   => ['start' => '<div class="mt-2 text-xs">', 'ende' => '</div>'],
            'buttons' => ['start' => '<div class="flex-shrink-0 flex items-center gap-2">', 'ende' => '</div>'],
            'schleife' => false,
        ],
        'applicant-portal' => [
            'pfad'    => 'resources/views/livewire/public/applicant-portal.blade.php',
            'badge'   => ['start' => '<div class="mt-2 text-xs">', 'ende' => '</div>'],
            'buttons' => ['start' => '<div class="flex-shrink-0 flex items-center gap-2">', 'ende' => '</div>'],
            'schleife' => false,
        ],
        'portal-shell' => [
            'pfad'    => 'resources/views/livewire/public/portal-shell.blade.php',
            'badge'   => ['start' => '@forelse ($dokumente as $dok)', 'ende' => '@endforelse'],
            'buttons' => ['start' => '@forelse ($dokumente as $dok)', 'ende' => '@endforelse'],
            'schleife' => true,
        ],
    ];

    private string $tmpDir = '';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/recruiting-portal-badge-' . getmypid() . '-' . uniqid();
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
     * PFLICHT 2 der Spec, und der Grund fuer diesen ganzen Test: der
     * issued-Zweig muss VOR der Bedingung `status === 'completed' ||
     * signed_at` stehen. Steht er dahinter, gewinnt sie — signed_at ist
     * gesetzt — und die Zeile behauptet „Unterschrieben am ..." ueber ein
     * Dokument, das niemand unterschrieben hat.
     */
    public function testZertifikatZeileSagtAusgestelltUndNichtUnterschrieben(): void
    {
        foreach (array_keys(self::BLADES) as $name) {
            $out = $this->render($name, 'badge', $this->certificateRow());

            $this->assertStringContainsString('Ausgestellt', $out, "{$name}: Badge sagt nicht „Ausgestellt“");
            $this->assertStringContainsString('am 12.08.2026', $out, "{$name}: Ausstellungsdatum fehlt");
            $this->assertStringNotContainsString(
                'Unterschrieben',
                $out,
                "{$name}: Badge behauptet „Unterschrieben“ ueber das Zertifikat — der issued-Zweig steht hinter der signed_at-Bedingung"
            );
            $this->assertStringNotContainsString(
                'issued',
                $out,
                "{$name}: der Rohwert 'issued' steht in der Ausgabe — der issued-Zweig fehlt ganz und der Fallback greift"
            );
        }
    }

    /**
     * Negativkontrolle zur Pflicht 2: der neue Zweig darf den Bestand nicht
     * uebernehmen. Ein unterschriebener Vertrag sagt weiterhin
     * „Unterschrieben am ..." und NICHT „Ausgestellt".
     */
    public function testUnterschriebenerVertragSagtWeiterhinUnterschrieben(): void
    {
        $vertrag = [
            'id'           => 12,
            'display_name' => 'Arbeitsvertrag',
            'status'       => 'completed',
            'signed_at'    => '2026-08-01 08:00:00',
            'completed_at' => '2026-08-01 08:05:00',
            'sign_url'     => 'https://example.test/sign/tok',
            'pdf_url'      => 'https://example.test/pdf/12',
        ];

        foreach (array_keys(self::BLADES) as $name) {
            $out = $this->render($name, 'badge', $vertrag);

            $this->assertStringContainsString('Unterschrieben', $out, "{$name}: Bestandszeile verliert „Unterschrieben“");
            $this->assertStringContainsString('am 01.08.2026', $out, "{$name}: Bestandszeile verliert das Datum");
            $this->assertStringNotContainsString('Ausgestellt', $out, "{$name}: Vertrag laeuft in den Zertifikat-Zweig");
        }
    }

    /** Zweite Negativkontrolle: die Kette hinter dem neuen Zweig ist intakt. */
    public function testOffenerVertragWartetWeiterhinAufDieUnterschrift(): void
    {
        $vertrag = $this->contractRow('sent', null);

        foreach (array_keys(self::BLADES) as $name) {
            $out = $this->render($name, 'badge', $vertrag);

            $this->assertStringContainsString('Wartet auf', $out, "{$name}: 'sent'-Zweig nicht mehr erreichbar");
            $this->assertStringNotContainsString('Ausgestellt', $out, "{$name}: 'sent' laeuft in den Zertifikat-Zweig");
            $this->assertStringNotContainsString('Unterschrieben', $out, "{$name}: 'sent' laeuft in den Unterschrieben-Zweig");
        }
    }

    /**
     * PFLICHT 3, gemessen statt uebernommen: mit status='issued' und
     * sign_url=null bleibt der Unterschreiben-Button weg, und der PDF-Button
     * erscheint von allein — ohne jede Aenderung am Button-Block.
     */
    public function testZertifikatZeileZeigtPdfAberKeinenUnterschreibenButton(): void
    {
        $row = $this->certificateRow();

        foreach (array_keys(self::BLADES) as $name) {
            $out = $this->render($name, 'buttons', $row);

            $this->assertStringNotContainsString(
                'Jetzt unterschreiben',
                $out,
                "{$name}: das Zertifikat bekommt einen Unterschreiben-Button"
            );
            $this->assertStringContainsString(
                $row['pdf_url'],
                $out,
                "{$name}: der PDF-Link des Zertifikats fehlt"
            );
        }
    }

    /** Negativkontrolle zum Button-Block: 'sent' bekommt seinen Button weiterhin. */
    public function testOffenerVertragZeigtDenUnterschreibenButton(): void
    {
        $vertrag = $this->contractRow('sent', null);

        foreach (array_keys(self::BLADES) as $name) {
            $out = $this->render($name, 'buttons', $vertrag);

            $this->assertStringContainsString(
                'Jetzt unterschreiben',
                $out,
                "{$name}: der Unterschreiben-Button des Bestands ist weg"
            );
            $this->assertStringContainsString($vertrag['sign_url'], $out, "{$name}: sign_url fehlt im Button");
        }
    }

    /**
     * Die Zeile, wie sie im Betrieb aus dem Model kommt: signed_at ist ein
     * Carbon (datetime-Cast auf issued_at), kein String. Genau dieser Typ
     * landet in \Carbon\Carbon::parse() in der Blade.
     */
    private function certificateRow(): array
    {
        return TrainingCertificatePortalRows::row(
            9,
            \Carbon\Carbon::parse('2026-08-12 09:30:00'),
            'https://example.test/recruiting/zertifikat/0198f0aa-1111-7222-8333-444455556666'
        );
    }

    private function contractRow(string $status, ?string $signedAt): array
    {
        return [
            'id'           => 5,
            'display_name' => 'Arbeitsvertrag',
            'status'       => $status,
            'signed_at'    => $signedAt,
            'completed_at' => null,
            'sign_url'     => 'https://example.test/sign/tok',
            'pdf_url'      => null,
        ];
    }

    /**
     * Schneidet den Ausschnitt $welcher ('badge' oder 'buttons') aus der Blade
     * $name, kompiliert ihn und fuehrt ihn mit $row aus.
     *
     * Die beiden Bauarten unterscheiden sich nur darin, wie die Zeile
     * hereinkommt: die alten Portale rendern einen Block INNERHALB ihrer
     * Schleife (Variable `$c`), das neue Portal rendert die Schleife selbst
     * (Variable `$dokumente`). Beide bekommen ausserdem `$duzen`.
     */
    private function render(string $name, string $welcher, array $row): string
    {
        $blade = self::BLADES[$name];
        $marke = $blade[$welcher];

        $path = dirname(__DIR__, 2) . '/' . $blade['pfad'];
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertNotFalse($source, "Blade nicht lesbar: {$blade['pfad']}");

        $this->assertSame(
            1,
            substr_count($source, $marke['start']),
            "Marker kommt in {$blade['pfad']} nicht genau einmal vor — der Ausschnitt waere nicht mehr eindeutig: {$marke['start']}"
        );

        $start = strpos($source, $marke['start']);
        $end   = strpos($source, $marke['ende'], $start);
        $this->assertNotFalse(
            $end,
            "Kein {$marke['ende']} nach dem Marker in {$blade['pfad']}"
        );

        $fragment = substr($source, $start, $end - $start + strlen($marke['ende']));

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $compiled = $compiler->compileString($fragment);

        $file = $this->tmpDir . '/' . md5($blade['pfad'] . $welcher) . '.php';
        file_put_contents($file, $compiled);
        $this->tmpFiles[] = $file;

        // Eine kompilierte @forelse-Schleife ruft $__env fuer die
        // $loop-Variable. Statt einer handgeschriebenen Attrappe kommt das
        // ECHTE Laravel-Verhalten zum Einsatz (Concerns\ManagesLoops) — eine
        // Attrappe wuerde hier sonst mitwandern muessen, sobald Laravel die
        // Schleifen-Kompilierung aendert.
        $env = new class {
            use \Illuminate\View\Concerns\ManagesLoops;
        };

        return (static function (string $__file, array $c, array $dokumente, bool $duzen, object $__env): string {
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        })($file, $row, $blade['schleife'] ? [$row] : [], false, $env);
    }
}
