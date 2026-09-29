<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Einmalcode;

/**
 * Der Einmalcode ist das schwaechste Glied der Kette: nur sechs Ziffern.
 * Die Tests decken deshalb genau die drei Dinge ab, die ihn trotzdem sicher
 * machen — Zufall, Ablauf, Versuchszaehler — sowie den konstanten Vergleich
 * (der sich nicht direkt messen laesst, siehe hash_equals() in der Klasse).
 */
final class EinmalcodeTest extends TestCase
{
    public function test_ein_code_hat_sechs_ziffern(): void
    {
        ['klartext' => $k] = Einmalcode::erzeuge();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $k);
    }

    public function test_zwei_codes_sind_verschieden(): void
    {
        $a = Einmalcode::erzeuge()['klartext'];
        $b = Einmalcode::erzeuge()['klartext'];
        $this->assertNotSame($a, $b, 'ein vorhersagbarer Code ist kein Nachweis');
    }

    public function test_der_richtige_code_gilt(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
        $this->assertTrue(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00'));
    }

    public function test_ein_falscher_code_gilt_nicht(): void
    {
        ['hash' => $h] = Einmalcode::erzeuge();
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, '000000', '2026-09-29 12:05:00'));
    }

    public function test_ein_abgelaufener_code_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:11:00'));
    }

    public function test_nach_zu_vielen_versuchen_gilt_auch_der_richtige_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
        $this->assertFalse(
            Einmalcode::istGueltig($h, '2026-09-29 12:10:00', Einmalcode::MAX_VERSUCHE, $k, '2026-09-29 12:05:00'),
            'sonst kann man sechs Ziffern in Ruhe durchprobieren',
        );
    }

    public function test_ohne_hash_gilt_nichts(): void
    {
        $this->assertFalse(Einmalcode::istGueltig(null, null, 0, '123456', '2026-09-29 12:00:00'));
    }

    /** Ein Versuch unterhalb der Grenze darf noch nicht bremsen — nur "erreicht" blockiert. */
    public function test_kurz_vor_der_versuchsgrenze_gilt_der_richtige_code_noch(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
        $this->assertTrue(Einmalcode::istGueltig(
            $h,
            '2026-09-29 12:10:00',
            Einmalcode::MAX_VERSUCHE - 1,
            $k,
            '2026-09-29 12:05:00',
        ));
    }

    /** Ohne bekannten Ablauf darf nichts als gueltig durchgehen — im Zweifel abgelaufen. */
    public function test_ohne_ablaufzeit_gilt_nichts(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
        $this->assertFalse(Einmalcode::istGueltig($h, null, 0, $k, '2026-09-29 12:05:00'));
    }
}
