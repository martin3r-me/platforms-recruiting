<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verdrahtung der Zeiterfassung in den Blades (Schluss-Review K3): Die Partials
 * sind je fuer sich getestet — ob die Seiten sie ueberhaupt einbinden und an der
 * richtigen Stelle, prueft nur dieser Quelltext-Vertrag.
 */
class ZeitVerdrahtungTest extends TestCase
{
    private static function blade(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/' . $pfad);
    }

    /** Inhalt eines Portal-Reiters: vom Kopf des Reiters bis zum naechsten. */
    private function reiter(string $name): string
    {
        $shell = self::blade('public/portal-shell.blade.php');
        $kopf = '<div class="pane" :class="tab === \'' . $name . '\' && \'on\'">';
        $this->assertSame(1, substr_count($shell, $kopf), "Reiter {$name} nicht eindeutig gefunden");
        $ab = strpos($shell, $kopf) + strlen($kopf);
        $naechster = strpos($shell, '<div class="pane" :class="tab ===', $ab);

        return substr($shell, $ab, ($naechster === false ? strlen($shell) : $naechster) - $ab);
    }

    public function test_portal_bindet_die_stempel_kachel_im_reiter_start_ein(): void
    {
        $einbindung = "@include('recruiting::livewire.public._stempel-kachel', ['stempelZeilen' => \$stempelZeilen, 'stempelMeldung' => \$stempelMeldung, 'duzen' => \$duzen])";
        $this->assertStringContainsString($einbindung, $this->reiter('start'));
        $this->assertSame(1, substr_count(self::blade('public/portal-shell.blade.php'), '_stempel-kachel'));
    }

    public function test_portal_bindet_meine_zeiten_im_reiter_jobs_ein(): void
    {
        $einbindung = "@include('recruiting::livewire.public._meine-zeiten', ['meineZeiten' => \$meineZeiten, 'duzen' => \$duzen])";
        $this->assertStringContainsString($einbindung, $this->reiter('jobs'));
        $this->assertSame(1, substr_count(self::blade('public/portal-shell.blade.php'), '_meine-zeiten'));
    }

    public function test_kachel_ruft_die_stempel_aktionen_der_seite(): void
    {
        $kachel = self::blade('public/_stempel-kachel.blade.php');
        $this->assertStringContainsString('wire:click="stempelKommen(', $kachel);
        $this->assertStringContainsString('wire:click="stempelGehen(', $kachel);
    }

    public function test_dispo_seite_bindet_das_zeit_fenster_ein_wenn_es_offen_ist(): void
    {
        $show = self::blade('dispo/events/show.blade.php');
        $this->assertMatchesRegularExpression(
            '/@if \(\$showZeitModal\)\s*@include\(\'recruiting::livewire\.dispo\.events\._zeit-fenster\'\)\s*@endif/',
            $show
        );
    }

    public function test_dispo_seite_zeigt_die_stempel_zelle_in_tabelle_und_karte(): void
    {
        $show = self::blade('dispo/events/show.blade.php');
        $einbindung = "@include('recruiting::livewire.dispo.events._stempel-zelle', ['assignment' => \$assignment, 'zelle' => \$zeitDienst->zelle(\$zeitKarte[(int) \$assignment->id] ?? null), 'eventOnly' => \$eventOnly])";

        $karteAb = strpos($show, '<div class="divide-y divide-gray-100 lg:hidden">');
        $tabelleAb = strpos($show, '<table class="w-full text-sm">');
        $tabelleBis = strpos($show, '</table>', (int) $tabelleAb);
        $this->assertNotFalse($karteAb, 'Karten-Liste (mobil) nicht gefunden');
        $this->assertNotFalse($tabelleAb, 'Tabelle nicht gefunden');
        $this->assertNotFalse($tabelleBis);
        $this->assertLessThan($tabelleAb, $karteAb);

        $karte = substr($show, $karteAb, $tabelleAb - $karteAb);
        $tabelle = substr($show, $tabelleAb, $tabelleBis - $tabelleAb);
        $this->assertSame(1, substr_count($karte, $einbindung), 'Karte (mobil)');
        $this->assertSame(1, substr_count($tabelle, $einbindung), 'Tabelle');
        $this->assertStringContainsString('<th', $tabelle);
        $this->assertStringContainsString('Stempel', $tabelle, 'Spaltenkopf');

        $zelle = self::blade('dispo/events/_stempel-zelle.blade.php');
        $this->assertStringContainsString('wire:click="openZeit({{ $assignment->id }})"', $zelle);
    }

    public function test_sammel_knopf_steht_hinter_schalter_und_ausschluss_der_nur_veranstaltungen_konten(): void
    {
        $show = self::blade('dispo/events/show.blade.php');
        $this->assertSame(1, substr_count($show, 'wire:click="zeitAlleErledigt"'));
        $knopf = strpos($show, 'wire:click="zeitAlleErledigt"');

        // Die naechste Bedingung davor umschliesst den Knopf: dazwischen wird nichts geschlossen.
        $if = strrpos(substr($show, 0, $knopf), '@if (');
        $this->assertNotFalse($if);
        $dazwischen = substr($show, $if, $knopf - $if);
        $this->assertStringNotContainsString('@endif', $dazwischen);
        $this->assertStringNotContainsString('@else', $dazwischen);
        $bedingung = substr($dazwischen, 0, (int) strpos($dazwischen, "\n"));
        $this->assertSame('@if ($zeitAktiv && !$eventOnly && $zeitErledigbar > 0)', trim($bedingung));

        // $eventOnly kommt vom Server-Riegel der Seite, nicht aus einer losen Variable.
        $this->assertStringContainsString('$eventOnly = $this->eventOnly;', $show);
    }

    public function test_zeit_fenster_ruft_die_aktionen_der_seite(): void
    {
        $fenster = self::blade('dispo/events/_zeit-fenster.blade.php');
        foreach (['zeitSpeichern', 'zeitJetztKommen', 'zeitJetztGehen', 'zeitErledigt', 'zeitOeffnen', 'closeZeit'] as $aktion) {
            $this->assertStringContainsString('wire:click="' . $aktion . '"', $fenster, $aktion);
        }
    }
}
