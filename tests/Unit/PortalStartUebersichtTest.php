<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portal-Startseite als Uebersicht (29.10.2026): kurze Kacheln mit Sprung
 * in den Bereich, keine doppelte Nachweisliste. Vertrag an der Quelle.
 */
class PortalStartUebersichtTest extends TestCase
{
    private static function start(): string
    {
        $b = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
        $von = strpos($b, '{{-- ---------------- START ---------------- --}}');
        $bis = strpos($b, '{{-- ---------------- EINSAETZE ---------------- --}}');

        return substr($b, (int) $von, (int) $bis - (int) $von);
    }

    public function test_keine_doppelte_nachweisliste_auf_start(): void
    {
        $this->assertStringNotContainsString('Liegt vor <span', self::start(), 'die Liste lebt unter Dokumente & Infos');
        $this->assertStringContainsString("@click=\"tab = 'docs'\"", self::start(), 'Sprung zu den Unterlagen');
    }

    public function test_kachel_naechster_einsatz_springt_in_den_reiter(): void
    {
        $s = self::start();
        $this->assertStringContainsString('@if ($naechsterEinsatz !== null)', $s);
        $this->assertStringContainsString("@click=\"tab = 'jobs'\"", $s);
        $this->assertStringContainsString("\$naechsterEinsatz['tag']", $s);
        $this->assertStringContainsString('sobald die Dispo dich einplant', $s, 'Leerzustand fuer Neue');
    }

    public function test_lohn_kontakt_und_startbildschirm_hinweis(): void
    {
        $s = self::start();
        $this->assertStringContainsString('$lohnAnleitungUrl', $s);
        // Sebastian 29.10.: Auszahlungssatz bleibt (aus Claras Vorlage).
        $this->assertStringContainsString('zum 15. des Folgemonats', $s);
        $this->assertStringContainsString('$schreibUnsUrl', $s);
        $this->assertStringContainsString("localStorage", $s, 'Startbildschirm-Hinweis nur einmal');
        $this->assertStringContainsString('display-mode: standalone', $s, 'nicht zeigen, wenn schon installiert');
    }

    /**
     * Sichttest 29.10.: Text in den Kacheln war unsichtbar (.hint ist am Rechner
     * weiss, fuer den dunklen Login-Hintergrund), Knoepfe klebten am Rand.
     * Dazu: ohne Agenda-Zugang „Zugang anfordern“ mit vorgefuelltem Text.
     */
    public function test_kacheln_lesbar_und_agenda_zugang_anfordern(): void
    {
        $s = self::start();
        $this->assertStringNotContainsString('<p class="hint"', $s);
        $this->assertSame(2, substr_count($s, '<div class="card kachel"'), 'Lohn und Kontakt mit Innenabstand');
        $this->assertStringContainsString('@if ($agendaZugang !== true && $schreibUnsUrl !== null)', $s);
        $this->assertStringContainsString("rawurlencode(", $s);
        $this->assertStringContainsString('Zugang anfordern', $s);

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');
        $this->assertStringContainsString('.portal-body .card.kachel', $css);
        $this->assertStringContainsString('.portal-body a.btn', $css);
        // Sichttest hell/dunkel: Kasten-Ueberschrift lesbar, Mini-Knoepfe mit eigener Schriftfarbe.
        $this->assertStringContainsString('.portal-body .next .kicker { color: var(--brand-light)', $css);
        $this->assertStringContainsString('.portal-body .mini:not(.primary) { color: var(--ink) }', $css);
    }

    /** Sichttest 29.10.: „Dokumente & Infos“ brach in der Reiterleiste unten in drei Zeilen um. */
    public function test_reiterleiste_unten_bricht_nicht_um(): void
    {
        $b = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
        $leiste = substr($b, (int) strpos($b, '<button class="tab" role="tab"'));
        $this->assertStringNotContainsString('</span>Dokumente <span class="amp">&amp;</span> Infos', $leiste, 'unten kurz: „Dokumente“');
        $this->assertStringContainsString('aria-label="Dokumente & Infos"', $leiste, 'Vorleser bekommt den vollen Namen');

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');
        $this->assertStringContainsString('.portal-body .tab { white-space: nowrap', $css);
    }

    /** Sebastian 29.10.: Profil unter 100 % — der Ring steht auch auf Start, Tipp oeffnet das Profil. */
    public function test_profil_ring_auf_start_unter_hundert_prozent(): void
    {
        $s = self::start();
        $this->assertStringContainsString("@if (\$profilStand['prozent'] < 100)", $s);
        $this->assertStringContainsString('<button type="button" class="ring-row tap" @click="tab = \'me\'"', $s);

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');
        $this->assertStringContainsString('.portal-body button.ring-row', $css);
    }
}
