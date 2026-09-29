<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinladungsToken;

/**
 * Analog zu EinmalcodeTest, aber mit zwei Darstellungen desselben Tokens:
 * dem langen Klartext (Link) und der kurzen `lesbar()`-Form (Vorlesen).
 * Der Sicherheitsnachweis (istGueltig) haengt ausschliesslich am Klartext.
 */
final class EinladungsTokenTest extends TestCase
{
    public function test_der_klartext_ist_ausreichend_lang(): void
    {
        ['klartext' => $k] = EinladungsToken::erzeuge();
        // 32 Zufallsbytes als Hex -> 64 Zeichen, passend zur Spaltenbreite
        // von invite_token_hash.
        $this->assertSame(64, strlen($k));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $k);
    }

    public function test_zwei_token_sind_verschieden(): void
    {
        $a = EinladungsToken::erzeuge()['klartext'];
        $b = EinladungsToken::erzeuge()['klartext'];
        $this->assertNotSame($a, $b, 'ein vorhersagbarer Token ist kein Nachweis');
    }

    public function test_die_lesbare_form_hat_acht_zeichen_ohne_verwechsler(): void
    {
        ['klartext' => $k] = EinladungsToken::erzeuge();
        $lesbar = EinladungsToken::lesbar($k);

        $this->assertSame(8, strlen($lesbar));
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{8}$/', $lesbar);
    }

    public function test_die_lesbare_form_ist_fuer_denselben_klartext_immer_gleich(): void
    {
        ['klartext' => $k] = EinladungsToken::erzeuge();
        $this->assertSame(EinladungsToken::lesbar($k), EinladungsToken::lesbar($k));
    }

    public function test_der_richtige_token_gilt(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge();
        $this->assertTrue(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $k, '2026-09-29 12:00:00'));
    }

    public function test_ein_falscher_token_gilt_nicht(): void
    {
        ['hash' => $h] = EinladungsToken::erzeuge();
        $anderer = EinladungsToken::erzeuge()['klartext'];
        $this->assertFalse(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $anderer, '2026-09-29 12:00:00'));
    }

    public function test_ein_abgelaufener_token_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge();
        $this->assertFalse(EinladungsToken::istGueltig($h, '2026-09-29 12:00:00', null, $k, '2026-09-29 12:00:01'));
    }

    public function test_ein_bereits_benutzter_token_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge();
        $this->assertFalse(EinladungsToken::istGueltig(
            $h,
            '2026-10-06 12:00:00',
            '2026-09-29 09:00:00',
            $k,
            '2026-09-29 12:00:00',
        ));
    }

    public function test_ohne_hash_gilt_nichts(): void
    {
        $this->assertFalse(EinladungsToken::istGueltig(null, null, null, 'irgendwas', '2026-09-29 12:00:00'));
    }

    public function test_ohne_ablaufzeit_gilt_nichts(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge();
        $this->assertFalse(EinladungsToken::istGueltig($h, null, null, $k, '2026-09-29 12:00:00'));
    }
}
