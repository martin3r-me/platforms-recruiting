<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\ApplicantPortal;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use ReflectionClass;
use ReflectionMethod;

/**
 * Die VERDRAHTUNG der Zertifikat-Zeilen in den zwei Livewire-Komponenten.
 *
 * Das ist ein Quelltext-Test, und das ist eine Notloesung mit Grund: die
 * Komponenten sind in diesem Modul nicht instanziierbar (kein
 * Laravel-Bootstrap, kein testbench), und die Verdrahtung selbst — Query,
 * route(), Zuweisung an die Properties — ist damit nur per Sichtpruefung
 * abzudecken. Was hier gemessen wird, ist deshalb bewusst schmal: dass die
 * beiden Methoden existieren und dass der Bewerber-Portal-Zustand aus DERSELBEN
 * Anweisung kommt wie die Zeilenliste.
 *
 * Der letzte Punkt ist der eigentliche Zweck. Die Auflage lautet „das Portal
 * darf sich nicht fuer leer erklaeren, waehrend ein Zertifikat existiert", und
 * der Defekt dazu ist eine REIHENFOLGE: Zertifikate erst nach der
 * `state`-Zeile anhaengen. Statt die Reihenfolge zu pruefen, nimmt die
 * Umsetzung sie weg — beide Werte kommen aus einem Aufruf und werden in einer
 * Anweisung destrukturiert. Dieser Test nagelt genau diese Form fest; wer sie
 * in zwei Anweisungen aufloest, holt sich die Reihenfolge zurueck und wird hier
 * rot.
 *
 * Der Rueckgabewert selbst (zaehlt Zertifikate mit) ist reines PHP und in
 * TrainingCertificatePortalRowsTest gemessen.
 *
 * PROZESSWEITER ZUSTAND: keiner. Reflection LAEDT die Komponentenklassen, sie
 * instanziiert sie nicht; es wird kein Eloquent-Modell gebootet und keine
 * Facade aufgeloest. Deshalb kein clearBootedModels()/clearResolvedInstances().
 */
class PortalCertificateWiringTest extends TestCase
{
    public function testBeidePortaleHabenEineMethodeFuerDieZertifikatZeilen(): void
    {
        foreach ([EmployeePortal::class, ApplicantPortal::class] as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->hasMethod('certificateRows'),
                $class . ' hat keine certificateRows()-Methode'
            );
        }
    }

    /**
     * Beide Portale haengen die Zertifikate ueber den gemeinsamen Helfer an —
     * und nicht zweimal per eigenem array_merge, das auseinanderlaufen kann.
     */
    public function testBeidePortaleHaengenUeberDenGemeinsamenHelferAn(): void
    {
        $erwartet = [
            EmployeePortal::class  => 'TrainingCertificatePortalRows::append(',
            ApplicantPortal::class => 'TrainingCertificatePortalRows::appendWithState(',
        ];

        foreach ($erwartet as $class => $call) {
            $this->assertStringContainsString(
                $call,
                $this->classSource($class),
                $class . ' benutzt ' . $call . ' nicht'
            );
        }
    }

    /**
     * Zeilenliste UND Zustand aus einer Anweisung — siehe Klassen-Docblock.
     */
    public function testBewerberPortalSetztZeilenUndZustandInEinerAnweisung(): void
    {
        $mount = $this->methodSource(ApplicantPortal::class, 'mount');

        $treffer = preg_match(
            '/\[\s*\$this->contracts\s*,\s*\$this->state\s*\]\s*=\s*TrainingCertificatePortalRows::appendWithState\(/',
            $mount,
            $m,
            PREG_OFFSET_CAPTURE
        );

        $this->assertSame(
            1,
            $treffer,
            'ApplicantPortal::mount() setzt contracts und state nicht in einer Anweisung aus appendWithState()'
        );

        // Nach der Destrukturierung darf keine der beiden Properties noch
        // einmal gesetzt werden — eine spaetere Zuweisung ist genau der
        // Rueckfall in die Reihenfolge (die frueheren state-Zuweisungen der
        // Abbruchzweige 'invalid'/'expired' stehen DAVOR und sind erlaubt,
        // deshalb wird ab dem Treffer gemessen und nicht global gezaehlt).
        $danach = substr($mount, $m[0][1]);

        $this->assertSame(
            0,
            preg_match_all('/\$this->state\s*=/', $danach),
            'ApplicantPortal::mount() setzt $this->state nach appendWithState() noch einmal — die Reihenfolge ist zurueck'
        );
        $this->assertSame(
            0,
            preg_match_all('/\$this->contracts\s*=/', $danach),
            'ApplicantPortal::mount() setzt $this->contracts nach appendWithState() noch einmal'
        );
        $this->assertStringNotContainsString(
            'count($this->contracts)',
            $mount,
            'ApplicantPortal::mount() zaehlt die Zeilen wieder selbst statt appendWithState() zu benutzen'
        );
    }

    private function classSource(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $this->assertNotFalse($file, $class . ': keine Quelldatei');

        $source = file_get_contents($file);
        $this->assertNotFalse($source, $class . ': Quelldatei nicht lesbar');

        return $source;
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file       = $reflection->getFileName();
        $lines      = file($file);
        $this->assertNotFalse($lines, $class . ': Quelldatei nicht lesbar');

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
