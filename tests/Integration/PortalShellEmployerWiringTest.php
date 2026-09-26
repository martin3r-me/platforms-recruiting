<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use ReflectionClass;

/**
 * VERDRAHTUNG der Arbeitgeber-Pflicht im NEUEN Portal -- Quelltext-Test wie
 * PortalMainEmployerWiringTest (fuer das alte Portal), aus demselben Grund:
 * die Livewire-Komponente laesst sich in dieser Suite nicht rendern (kein
 * 'view'-Binding, siehe TrainingCertificateRenderTest).
 *
 * GEDREHT am 25.09.2026 (Aufgabe 6, Portal-Gleichstand): "Arbeitgeber" ist
 * seit dem Gruppen-Umbau eine Gruppe wie jede andere. speichereArbeitgeber()
 * ist entfallen, der Rumpf-Test unten liest jetzt speichereGruppe() -- die
 * GEPRUEFTEN Entscheidungen sind dieselben wie vorher:
 *  1. Die Waechter kommen mit dem gemeinsamen Schreibweg, keine eigene Regel
 *     in der Komponente.
 *  2. Geschrieben wird ueber PortalProfileWriter (Eloquent), NICHT ueber
 *     DB::table(...) -- gedreht am 25.09.2026, Begruendung unten.
 *  3. render() liefert eine synthetische Aufgabe, solange die Antwort fehlt,
 *     UND zaehlt sie im 'offen'-Wert mit.
 *  4. Im Start-Bereich steht diese Aufgabe VOR jedem Nachweis aus der
 *     Aufgabenliste -- sie ist wichtiger, weil an ihr die Steuerklasse
 *     haengt.
 */
class PortalShellEmployerWiringTest extends TestCase
{
    private function quelle(): string
    {
        return file_get_contents((new ReflectionClass(PortalShell::class))->getFileName());
    }

    /** Nur der Rumpf von speichereGruppe() -- der Rest der Klasse schreibt weiter ueber den Query Builder (portal_verified_at). */
    private function rumpfVonSpeichereGruppe(string $src): string
    {
        $start = strpos($src, 'function speichereGruppe(');
        $this->assertNotFalse($start, 'speichereGruppe() fehlt');
        $ende = strpos($src, "\n    }\n", $start);

        return substr($src, $start, $ende - $start);
    }

    private function blade(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
    }

    public function test_arbeitgeber_felder_laufen_ueber_den_gemeinsamen_schreibweg(): void
    {
        // GEDREHT am 25.09.2026. Vorher verlangte dieser Test DB::table(...)
        // fuer is_main_employer/other_employer und verbot ausdruecklich
        // $employee->update(). Grund damals: die Entscheidung "ZAS sieht die
        // Angabe nicht" nicht nur der Feldliste ueberlassen.
        //
        // Grund jetzt: derselbe Query Builder unterschlaegt den LOHN-TRIGGER.
        // is_main_employer steht in RecApplicantSettings::DEFAULT_SETTINGS
        // ['employee_payroll_tracked_fields'] -- das alte Portal meldet den
        // Wechsel ans Lohnbuero, das neue tat es nicht. Ein ausgefallener
        // Trigger faellt erst der Lohnbuchhaltung auf.
        //
        // Der ZAS-Schutz bleibt: beide Spalten fehlen in
        // RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS. Er wird jetzt
        // am ERGEBNIS gemessen statt an der Schreibart --
        // PortalProfileWriterTest prueft jede der fuenf verbotenen Spalten
        // einzeln, mit registriertem Beobachter. Der Schreibweg gilt jetzt
        // fuer JEDE Gruppe, nicht nur "Arbeitgeber" -- deshalb hier der Rumpf
        // von speichereGruppe(), nicht mehr von speichereArbeitgeber().
        $src = $this->quelle();
        $rumpf = $this->rumpfVonSpeichereGruppe($src);

        $this->assertStringContainsString('PortalProfileWriter', $src);
        $this->assertStringContainsString('speichere(', $rumpf);
        $this->assertStringNotContainsString("DB::table('rec_employees')", $rumpf);
    }

    public function test_die_waechter_kommen_mit_dem_schreibweg(): void
    {
        // Vorher rief die Komponente MainEmployerRequiredGuard::error() selbst
        // auf -- und NUR den. Jetzt haengt die ganze Kaskade
        // (PortalProfileGuards: Ersthelfer, Staatsangehoerigkeit,
        // Hauptarbeitgeber) am Schreibweg, wie in EmployeePortal::saveAll().
        // Eine zweite Regel in der Komponente waere genau das Auseinander-
        // laufen, das PortalBoolValue schon einmal gekostet hat.
        $rumpf = $this->rumpfVonSpeichereGruppe($this->quelle());

        $this->assertStringNotContainsString('MainEmployerRequiredGuard::error(', $rumpf);
        // Der Schreibweg begrenzt sich auf die GERADE offene Gruppe --
        // speichereGruppe() ist generisch (jede Gruppe, nicht nur
        // "Arbeitgeber"), deshalb steht hier die Variable, kein literaler
        // Gruppenname mehr.
        $this->assertStringContainsString('$this->profilGruppe', $rumpf, 'Der Schreibweg muss auf die offene Gruppe begrenzt sein.');
    }

    /**
     * GEDREHT am 26.09.2026 (Schlussfix F1): der Test verlangte woertlich
     * 'arbeitgeberAufgabe' und 'arbeitgeberOffen' im Rumpf von render().
     * Die Arbeitgeber-Frage ist seit F1 keine Sonderzeile mehr, sondern EINE
     * der drei Pflichtangaben, die alle aus derselben Quelle kommen
     * (PortalMandatory ueber PortalProfileGuards::WAECHTER) -- vorher
     * zaehlten Staatsangehoerigkeit und Ersthelfer-Kopplung im Offen-Wert
     * gar nicht mit. Die ABSICHT des Tests bleibt: die Aufgabe wird geliefert
     * UND sie zaehlt im Offen-Wert. Gemessen wird das jetzt am Ergebnis
     * (PortalPflichtangabenTest), hier nur noch die Verdrahtung.
     */
    public function test_render_liefert_die_aufgabe_und_zaehlt_sie_im_offen_wert(): void
    {
        $src = $this->quelle();

        $renderStart = strpos($src, 'function ansichtsDaten()');
        $this->assertNotFalse($renderStart, 'Die Ansichtsdaten werden nicht mehr an einer Stelle gebaut.');
        $renderBody = substr($src, $renderStart, 2600);

        $this->assertStringContainsString('pflichtAufgaben', $renderBody);
        $this->assertStringContainsString('count($pflichtAufgaben)', $renderBody, 'Die Pflichtangaben zaehlen nicht mehr im Offen-Wert.');
        // Und die Arbeitgeber-Frage ist eine davon -- ihr eigener Satz
        // (Steuerklasse) haengt weiterhin an dieser Klasse.
        $this->assertStringContainsString('arbeitgeberAufgabe', $src);
    }

    public function test_start_bereich_zeigt_die_arbeitgeber_aufgabe_vor_den_nachweisen(): void
    {
        $blade = $this->blade();

        $pflichtPos = strpos($blade, '@foreach ($pflichtAufgaben as $aufgabe)');
        $foreachPos = strpos($blade, "@foreach (\$offeneAufgaben as \$aufgabe)");

        $this->assertNotFalse($pflichtPos, 'Blade zeigt die Pflichtangaben nicht an');
        $this->assertNotFalse($foreachPos);
        $this->assertLessThan($foreachPos, $pflichtPos, 'Die Pflichtangaben muessen VOR der Nachweisliste stehen');
    }

    public function test_profil_bereich_bietet_die_frage_ueber_den_gemeinsamen_gruppen_mechanismus_an(): void
    {
        // GEDREHT am 26.09.2026 (Aufgabe 7, Portal-Gleichstand): dieser Test
        // verlangte bislang woertlich arbeitgeberIstHaupt/"speichereArbeitgeber"
        // im Blade -- Namen, die es seit dem Gruppen-Umbau (Aufgabe 6) nicht
        // mehr gibt. Damit stand der Test GRUEN, WEIL das Blade kaputt war
        // (Renderfehler bei jedem echten Aufruf) -- und haette bei jedem
        // Versuch, das Blade zu heilen, die tote Verdrahtung erzwungen.
        // "Arbeitgeber" ist seit dem Umbau eine Gruppe wie jede andere: die
        // Frage laeuft ueber denselben generischen Mechanismus wie Bankdaten
        // oder Adresse (antippbare Zeile -> Blatt -> speichereGruppe()), kein
        // eigenes Formular mehr im Profil-Bereich.
        $blade = $this->blade();

        $this->assertStringNotContainsString('arbeitgeberIstHaupt', $blade);
        $this->assertStringNotContainsString('speichereArbeitgeber', $blade);
        $this->assertStringContainsString('wire:click="oeffneGruppe(', $blade);
        $this->assertStringContainsString('wire:submit="speichereGruppe"', $blade);
    }
}
