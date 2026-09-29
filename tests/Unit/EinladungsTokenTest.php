<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinladungsToken;

/**
 * Analog zu EinmalcodeTest. Ruling GD-4: der Klartext IST der lesbare
 * 8-Zeichen-Code — Link und manuelles Eintippen tragen denselben Wert, es
 * gibt keine separate Anzeige-Ableitung mehr.
 */
final class EinladungsTokenTest extends TestCase
{
    public function test_der_klartext_hat_acht_zeichen_ohne_verwechsler(): void
    {
        ['klartext' => $k] = EinladungsToken::erzeuge();
        $this->assertSame(8, strlen($k));
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{8}$/', $k);
    }

    public function test_zwei_token_sind_verschieden(): void
    {
        $a = EinladungsToken::erzeuge()['klartext'];
        $b = EinladungsToken::erzeuge()['klartext'];
        $this->assertNotSame($a, $b, 'ein vorhersagbarer Token ist kein Nachweis');
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
