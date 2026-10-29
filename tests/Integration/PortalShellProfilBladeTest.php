<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Quelltext-Waechter am Blade fuer den Profil-Bereich (Aufgabe 7,
 * Portal-Gleichstand) -- Muster wie PortalShellEmployerWiringTest: die
 * Komponente laesst sich in dieser Suite nicht rendern, es fehlt das
 * 'view'-Binding (siehe TrainingCertificateRenderTest).
 */
class PortalShellProfilBladeTest extends TestCase
{
    private function blade(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
    }

    public function test_das_profil_zeigt_den_ring(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('ring-row', $blade);
        // Der Entwurf hat 85 % fest in der CSS. Der echte Wert muss als
        // Inline-Stil mitkommen, sonst zeigt der Ring bei jedem dasselbe.
        $this->assertStringContainsString('conic-gradient', $blade);
        $this->assertStringContainsString('$profilStand[\'prozent\']', $blade);
    }

    public function test_gruppen_sind_antippbare_zeilen(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('grouprow tap', $blade);
        $this->assertStringContainsString('wire:click="oeffneGruppe(', $blade);
        $this->assertStringContainsString('chev', $blade);
    }

    public function test_datei_felder_erscheinen_als_kacheln_und_oeffnen_das_nachweis_blatt(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('class="uploads"', $blade);
        $this->assertStringContainsString('oeffneUpload(', $blade);

        // Kein zweiter Upload-Weg: im Profil gibt es KEIN eigenes Datei-Feld
        // (R20/E8) und keine zweite Zuordnungsliste (E7).
        //
        // Geprueft NUR innerhalb des Gruppen-Blatts selbst (zwischen
        // wire:submit="speichereGruppe" und seinem eigenen "Abbrechen") --
        // NICHT global: das Blade traegt daneben weiterhin das echte,
        // EINZIGE Nachweis-Formular (wire:model="uploadDatei" /
        // "uploadDateiRueckseite"), das dieses Substring legitim enthaelt.
        // Eine globale Pruefung wuerde genau den Upload-Weg treffen, den es
        // laut R20/E8 geben MUSS, statt einen zweiten zu verhindern.
        $start = strpos($blade, 'wire:submit="speichereGruppe"');
        $this->assertNotFalse($start, 'Gruppen-Blatt (speichereGruppe) fehlt im Blade');
        $endeMarker = 'wire:click="schliesseGruppe">Abbrechen</button>';
        $ende = strpos($blade, $endeMarker, $start);
        $this->assertNotFalse($ende, 'Gruppen-Blatt endet nicht wie erwartet');
        $gruppenBlatt = substr($blade, $start, $ende + strlen($endeMarker) - $start);

        $this->assertStringNotContainsString('wire:model="upload', $gruppenBlatt);
    }

    public function test_das_gruppen_blatt_zeigt_nachweis_kacheln_die_das_nachweis_blatt_oeffnen(): void
    {
        // Erstbescheinigung direkt im Fenster Gesundheit (13.10.2026): die
        // Kachel im Gruppen-Blatt oeffnet DASSELBE Nachweis-Blatt -- kein
        // zweiter Upload-Weg (die Pruefung oben auf wire:model="upload
        // innerhalb des Gruppen-Blatts bleibt bestehen).
        $blade = $this->blade();
        $start = strpos($blade, 'wire:submit="speichereGruppe"');
        $ende = strpos($blade, 'wire:click="schliesseGruppe">Abbrechen</button>', $start);
        $gruppenBlatt = substr($blade, $start, $ende - $start);

        $this->assertStringContainsString('$profilNachweise', $gruppenBlatt);
        $this->assertStringContainsString('type="button"', $gruppenBlatt);
        $this->assertStringContainsString("wire:click=\"oeffneUpload('{{ \$nachweis['code'] }}')\"", $gruppenBlatt);
    }

    public function test_das_nachweis_blatt_liegt_ueber_dem_gruppen_blatt(): void
    {
        // Beide Blaetter sind gleichzeitig offen, wenn die Kachel im
        // Gruppen-Blatt angetippt wird. Das Nachweis-Blatt muss oben liegen,
        // sonst verdeckt das Gruppen-Blatt (spaeter im DOM) das Formular.
        $blade = $this->blade();
        $this->assertStringContainsString('class="upload-overlay nachweis-overlay"', $blade);

        $layout = file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');
        $this->assertMatchesRegularExpression('/\.upload-overlay\.nachweis-overlay\s*\{[^}]*z-index:\s*21/', $layout);
    }

    public function test_das_gruppen_blatt_traegt_den_erklaertext(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('$profilHinweis', $blade);
    }

    public function test_pflichtfelder_bekommen_einen_roten_rand(): void
    {
        // E2: roter Rand statt "noch nicht eingetragen" an jedem leeren Feld.
        $this->assertStringContainsString("'fehlt'", $this->blade());
    }

    public function test_das_lebendige_ja_nein_gibt_es_nur_wo_es_gebraucht_wird(): void
    {
        // E16: 'live' nur beim Hauptarbeitgeber -- alle anderen Ja/Nein-Felder
        // bleiben bei der gesammelten Uebertragung, kein zusaetzlicher Serverweg.
        $blade = $this->blade();

        $this->assertStringContainsString("\$feld['live']", $blade);
        $this->assertStringContainsString('wire:model.live="profilWerte.', $blade);
        $this->assertStringContainsString('wire:model.defer="profilWerte.', $blade);
    }

    public function test_maxlength_kommt_aus_der_feld_definition(): void
    {
        // E14
        $this->assertStringContainsString("\$feld['maxlength']", $this->blade());
    }

    public function test_es_gibt_weiterhin_kein_inputmode(): void
    {
        // E3 -- Login-Blocker vom 06.08.2026. Gilt fuer das ganze Blade,
        // auch fuer Kommentare: die Erklaerung dazu (Zeile ~68) nennt das
        // Attribut deshalb bewusst nicht mehr beim Namen.
        $this->assertStringNotContainsString('inputmode', $this->blade());
    }

    public function test_nur_lese_felder_sind_als_solche_gekennzeichnet(): void
    {
        // §1.2
        $blade = $this->blade();

        $this->assertStringContainsString('$nurLesen', $blade);
        $this->assertStringContainsString('nicht änderbar', $blade);
    }

    public function test_ablaufdaten_werden_nur_gezeigt_und_sagen_warum(): void
    {
        // F4: fuenf Datumsfelder sind zugleich Ablaufspalte einer
        // Nachweisart. Sie stehen im Blatt, aber ohne Eingabefeld -- und mit
        // einem Satz, der sagt, wo man sie aendert. Ohne diesen Satz waere
        // das Feld einfach kaputt.
        $blade = $this->blade();

        $this->assertStringContainsString("!empty(\$feld['fest'])", $blade);
        $this->assertStringContainsString('Dieses Datum kommt vom Nachweis', $blade);

        // Der feste Zweig steht VOR den Eingabe-Zweigen -- sonst faengt der
        // 'date'-Zweig das Feld ab und baut doch ein Eingabefeld.
        $festPos = strpos($blade, "!empty(\$feld['fest'])");
        $datumPos = strpos($blade, "\$feld['type'] === 'date'");
        $this->assertNotFalse($datumPos);
        $this->assertLessThan($datumPos, $festPos);
    }

    public function test_der_gruppenname_steht_nicht_in_einer_zweiten_liste(): void
    {
        // §1.4 Punkt 2: die Doppelliste ist der Grund fuer E7. Im Blade darf
        // kein @php-Block eine eigene Feld- oder Datei-Zuordnung aufmachen.
        $this->assertStringNotContainsString('fileUploadProps', $this->blade());
    }

    public function test_blade_kompiliert(): void
    {
        $datei = dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php';
        $werkzeug = dirname(__DIR__, 2) . '/tools/blade-check.php';

        exec('php ' . escapeshellarg($werkzeug) . ' ' . escapeshellarg($datei), $ausgabe, $code);

        $this->assertSame(0, $code, implode("\n", $ausgabe));
    }

    /** Der Block "Das fehlt noch" -- von der Ueberschrift bis zu seiner Ende-Marke. */
    private function fehltNochBlock(): string
    {
        $blade = $this->blade();
        $start = strpos($blade, 'Das fehlt noch <span');
        $this->assertNotFalse($start, 'Kein Block "Das fehlt noch" im Blade.');
        $ende = strpos($blade, 'Ende "Das fehlt noch"', $start);
        $this->assertNotFalse($ende);

        return substr($blade, $start, $ende - $start);
    }

    public function test_das_fehlt_noch_springt_direkt_ins_passende_fenster(): void
    {
        $block = $this->fehltNochBlock();

        // Knopf (Tastatur, Vorleser), kein Div -- und der Klick geht ueber
        // oeffneAngabe() direkt ins Fenster, nicht mehr in den Profil-Reiter.
        $this->assertStringContainsString(
            '<button type="button" class="task tap" wire:click="oeffneAngabe(\'{{ $aufgabe[\'feld\'] }}\')">',
            $block,
        );
        $this->assertStringNotContainsString("tab = 'me'", $block, 'Der Umweg ueber den Profil-Reiter ist zurueck.');
    }

    public function test_das_gruppen_blatt_fokussiert_die_angeklickte_angabe(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('$profilFokus', $blade);
        $this->assertStringContainsString('scrollIntoView', $blade);
    }

    public function test_die_spalten_auf_dem_handy_sind_volle_breite(): void
    {
        // portal-styles setzt .bcols{align-items:start} fuer das Raster am
        // Rechner. Im Handy-Stapel (flex-direction:column) hiesse das:
        // jede Spalte nur so breit wie ihr Inhalt -- "Liegt vor" endete
        // rechts zu frueh.
        $layout = file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');

        $this->assertMatchesRegularExpression(
            '#\.portal-body \.bcols \{ display: flex; flex-direction: column; align-items: stretch;#',
            $layout,
        );
        // Eine einzelne Spalte am Rechner nimmt die ganze Breite.
        $this->assertStringContainsString('.portal-body .bcols > :only-child { grid-column: 1 / -1 }', $layout);
    }

    /**
     * Markus 12.10.2026 (E8): die Arbeitgeber-Frage gilt je Anstellung. Die
     * Karte „Deine Anstellungen" fuehrt deshalb in das Portal der jeweils
     * ANDEREN Anstellung (eigener Token derselben Person), und die Komponente
     * liest dafuer Token und Umstellungs-Stempel mit.
     */
    public function test_anstellungen_karte_verlinkt_die_andere_anstellung(): void
    {
        $blade = $this->blade();
        $this->assertStringContainsString("route('recruiting.public.portal-shell', ['token' => \$anstellung->portal_token])", $blade);
        $this->assertStringContainsString('$anstellung->portal_v2_since !== null', $blade);
        $this->assertStringContainsString('(int) $anstellung->id === (int) $employeeId', $blade);

        $shell = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Public/PortalShell.php');
        $this->assertStringContainsString("'portal_token', 'portal_v2_since']", $shell);
    }
}
