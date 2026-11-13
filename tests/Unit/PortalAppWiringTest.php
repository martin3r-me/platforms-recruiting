<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portal als App und Push einschalten (Spec Jobs anbieten 6.6/6.7, Markus
 * 13.11.2026) — Verdrahtung von Layout, Huelle und Karte. Verhalten am
 * echten Handy ist hier nicht pruefbar; das steht im Sichttest.
 */
class PortalAppWiringTest extends TestCase
{
    private function quelle(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_karte_steht_nur_im_angemeldeten_start_bereich(): void
    {
        $b = $this->quelle('resources/views/livewire/public/portal-shell.blade.php');
        $include = "@include('recruiting::livewire.public._portal-app-karte')";

        $this->assertSame(1, substr_count($b, $include));
        $pos = strpos($b, $include);
        $verified = strpos($b, "@if (\$state !== 'verified')");
        $sonst = strpos($b, '@else', (int) $verified);
        $start = strpos($b, '{{-- ---------------- START ---------------- --}}');
        $einsaetze = strpos($b, '{{-- ---------------- EINSAETZE ---------------- --}}');

        $this->assertNotFalse($verified);
        $this->assertGreaterThan($sonst, $pos, 'erst nach dem Anmelde-Zweig');
        $this->assertGreaterThan($start, $pos);
        $this->assertLessThan($einsaetze, $pos, 'auf der Startseite');
        $this->assertStringNotContainsString('rg-portal-startbildschirm', $b, 'der alte Tipp ist in der Karte aufgegangen');
    }

    public function test_layout_verweist_auf_manifest_und_app_kennzeichen(): void
    {
        $l = $this->quelle('resources/views/layouts/portal.blade.php');

        $this->assertStringContainsString('<link rel="manifest" href="{{ $manifestUrl }}">', $l);
        $this->assertStringContainsString('@if (!empty($manifestUrl))', $l, 'nur wo die Huelle ein Manifest mitgibt');
        $this->assertStringContainsString('<meta name="theme-color" content="#0e535e">', $l);
        $this->assertStringContainsString('rel="apple-touch-icon"', $l);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $l);

        $shell = $this->quelle('src/Livewire/Public/PortalShell.php');
        $this->assertStringContainsString("'manifestUrl'", $shell);
        $this->assertStringContainsString("'/recruiting/portal-app/manifest/' . rawurlencode(\$this->token) . '.webmanifest'", $shell);
    }

    public function test_karte_fragt_erst_nach_dem_tipp_und_faengt_alles_ab(): void
    {
        $k = $this->quelle('resources/views/livewire/public/_portal-app-karte.blade.php');

        // Push-Teil nur mit Push-Dienst; Schluessel nur dann im Markup.
        $this->assertStringContainsString('@if ($pushAktiv)', $k);
        $this->assertSame(1, substr_count($k, '$vapidPublic'));
        $this->assertStringContainsString("\$appSchluessel = \$pushAktiv ? \$vapidPublic : '';", $k);

        // Erlaubnis nur in der Klick-Methode, nicht beim Start der Karte.
        $einschalten = strpos($k, 'async einschalten()');
        $this->assertNotFalse($einschalten);
        $this->assertSame(1, substr_count($k, 'Notification.requestPermission('));
        $this->assertGreaterThan($einschalten, strpos($k, 'Notification.requestPermission('));
        $this->assertStringContainsString('@click="einschalten()"', $k);

        $this->assertStringContainsString("register('/recruiting/portal-app/sw.js', { scope: '/recruiting/' })", $k);
        $this->assertStringContainsString('$wire.pushAboSpeichern(', $k);
        $this->assertStringContainsString('$wire.pushAboLoeschen(', $k);
        $this->assertStringContainsString('.prompt()', $k, 'Android-Knopf loest die Nachfrage aus');
        $this->assertStringContainsString('display-mode: standalone', $k);
        $this->assertStringContainsString('navigator.standalone', $k);
        $this->assertStringContainsString('Erst auf den Startbildschirm legen, dann lassen sich Benachrichtigungen einschalten.', $k);
        $this->assertStringContainsString('Benachrichtigungen sind an', $k);
        $this->assertStringContainsString('Zum Home-Bildschirm', $k);
        $this->assertStringContainsString('Auf den Startbildschirm', $k);
        $this->assertStringContainsString('localStorage', $k);
        $this->assertStringContainsString('x-cloak', $k, 'ohne Alpine/JS nichts sichtbar');
        $this->assertStringContainsString('$duzen', $k);

        // Jeder localStorage-Zugriff in try.
        preg_match_all('/try \{[^}]*localStorage/', $k, $mitTry);
        $this->assertSame(substr_count($k, 'localStorage.'), count($mitTry[0]), 'localStorage nur in try/catch');
    }

    /** Die Karte mit dem echten Compiler rendern (die Huellen-Tests lassen das Partial aus). */
    private function rendereKarte(bool $pushAktiv, bool $duzen): string
    {
        $dir = sys_get_temp_dir() . '/recruiting-app-karte-' . getmypid();
        @mkdir($dir, 0777, true);
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $dir);
        $datei = $dir . '/karte-' . uniqid('', true) . '.php';
        file_put_contents($datei, $compiler->compileString($this->quelle('resources/views/livewire/public/_portal-app-karte.blade.php')));

        $lauf = static function (array $__v, string $__datei): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };
        $html = $lauf(['pushAktiv' => $pushAktiv, 'vapidPublic' => 'BGeheimNichtGeheim_-1', 'duzen' => $duzen], $datei);
        @unlink($datei);

        return $html;
    }

    public function test_karte_ohne_push_dienst_zeigt_nur_den_installationsteil(): void
    {
        $html = $this->rendereKarte(false, true);

        $this->assertStringContainsString('Auf den Startbildschirm', $html);
        $this->assertStringContainsString('Leg dir das Portal', $html);
        $this->assertStringNotContainsString('Benachrichtigungen einschalten', $html);
        $this->assertStringNotContainsString('BGeheimNichtGeheim', $html, 'kein Schluessel ohne Push-Dienst');
        $this->assertStringContainsString('pushServer: false', $html);
    }

    public function test_karte_mit_push_dienst_und_in_sie_form(): void
    {
        $html = $this->rendereKarte(true, false);

        $this->assertStringContainsString('Benachrichtigungen einschalten', $html);
        $this->assertStringContainsString("schluessel: 'BGeheimNichtGeheim_-1'", $html);
        $this->assertStringContainsString('pushServer: true', $html);
        $this->assertStringContainsString('Legen Sie sich das Portal', $html);
        $this->assertStringContainsString('Wählen Sie „Zum Home-Bildschirm“.', $html);
        $this->assertStringContainsString('Wir sagen Ihnen Bescheid', $html);
        $this->assertStringNotContainsString('Leg dir', $html);
        $this->assertStringNotContainsString('Tipp: Leg dir', $html);
        $this->assertStringNotContainsString('Wir sagen dir', $html);
    }

    public function test_huelle_rechnet_push_schalter_und_schluessel_serverseitig(): void
    {
        $s = $this->quelle('src/Livewire/Public/PortalShell.php');
        $this->assertStringContainsString("'pushAktiv'", $s);
        $this->assertStringContainsString("'vapidPublic'", $s);
        $this->assertStringContainsString('public function pushAboSpeichern(array $abo): bool', $s);
        $this->assertStringContainsString('public function pushAboLoeschen(string $endpoint): bool', $s);
        $this->assertStringContainsString('public function pushAboStatus(string $endpoint): bool', $s);
    }

    /**
     * Review Task 8 (I1): „an“ nur, wenn der Server es bestaetigt — beim
     * Einschalten aus der Rueckgabe, beim Laden ueber pushAboStatus; der
     * Browser meldet sich nur ab, wenn der Server das Loeschen bestaetigt.
     */
    public function test_karte_glaubt_beim_an_nur_dem_server(): void
    {
        $k = $this->quelle('resources/views/livewire/public/_portal-app-karte.blade.php');

        $this->assertStringContainsString("this.\$wire.pushAboStatus(a.endpoint)", $k);
        $this->assertStringContainsString("if (meins === true) { this.push = 'an'; }", $k);
        $this->assertStringContainsString("this.push = gespeichert === true ? 'an' : 'fehler';", $k);
        $this->assertStringContainsString("if (geloescht !== true) { this.push = 'fehler'; return; }", $k);
        $this->assertLessThan(strpos($k, 'await abo.unsubscribe()'), strpos($k, 'geloescht !== true'));
        $this->assertSame(1, substr_count($k, "this.push = 'an'"), 'kein „an“ aus dem Browser allein');
    }

    /** Review Task 8 (I3/M1): Rueckfall-Zeile statt nichts; iPhone-Hinweis nur, wo die Anleitung steht. */
    public function test_rueckfall_zeile_und_iphone_hinweis_nur_bei_safari(): void
    {
        $k = $this->quelle('resources/views/livewire/public/_portal-app-karte.blade.php');

        $this->assertStringContainsString("get installTeil() { return !this.alsApp && (this.nachfrage !== null || this.iosSafari || this.beruehrung); }", $k);
        $this->assertStringContainsString("get menueTipp() { return this.nachfrage === null && !this.iosSafari; }", $k);
        $this->assertStringContainsString("get iosHinweis() { return this.pushServer && this.iosSafari && !this.alsApp; }", $k);
        $this->assertStringContainsString("(pointer: coarse)", $k);

        foreach ([true, false] as $duzen) {
            foreach ([true, false] as $push) {
                $html = $this->rendereKarte($push, $duzen);
                $this->assertStringContainsString('iPhone: Teilen › „Zum Home-Bildschirm“. Android: Menü ⋮ › „Zum Startbildschirm hinzufügen“.', $html);
                $this->assertStringContainsString($duzen ? 'Tipp: Leg dir das Portal auf den Startbildschirm.' : 'Tipp: Legen Sie sich das Portal auf den Startbildschirm.', $html);
                $this->assertStringContainsString('x-show="menueTipp"', $html);
            }
        }
    }
}
