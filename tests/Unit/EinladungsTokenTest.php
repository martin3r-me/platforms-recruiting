<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinladungsToken;

/**
 * Analog zu EinmalcodeTest. Ruling GD-4: der Klartext IST der lesbare
 * 8-Zeichen-Code — Link und manuelles Eintippen tragen denselben Wert, es
 * gibt keine separate Anzeige-Ableitung mehr. Ruling GD-5: gehasht wird mit
 * Pfeffer (hash_hmac), nicht mit blossem SHA-256.
 */
final class EinladungsTokenTest extends TestCase
{
    private const PEPPER = 'test-pfeffer-1';

    public function test_der_klartext_hat_acht_zeichen_ohne_verwechsler(): void
    {
        ['klartext' => $k] = EinladungsToken::erzeuge(self::PEPPER);
        $this->assertSame(8, strlen($k));
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{8}$/', $k);
    }

    public function test_zwei_token_sind_verschieden(): void
    {
        $a = EinladungsToken::erzeuge(self::PEPPER)['klartext'];
        $b = EinladungsToken::erzeuge(self::PEPPER)['klartext'];
        $this->assertNotSame($a, $b, 'ein vorhersagbarer Token ist kein Nachweis');
    }

    public function test_der_richtige_token_gilt(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $this->assertTrue(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $k, '2026-09-29 12:00:00', self::PEPPER));
    }

    public function test_ein_falscher_token_gilt_nicht(): void
    {
        ['hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $anderer = EinladungsToken::erzeuge(self::PEPPER)['klartext'];
        $this->assertFalse(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $anderer, '2026-09-29 12:00:00', self::PEPPER));
    }

    public function test_ein_abgelaufener_token_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $this->assertFalse(EinladungsToken::istGueltig($h, '2026-09-29 12:00:00', null, $k, '2026-09-29 12:00:01', self::PEPPER));
    }

    public function test_ein_bereits_benutzter_token_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $this->assertFalse(EinladungsToken::istGueltig(
            $h,
            '2026-10-06 12:00:00',
            '2026-09-29 09:00:00',
            $k,
            '2026-09-29 12:00:00',
            self::PEPPER,
        ));
    }

    public function test_ohne_hash_gilt_nichts(): void
    {
        $this->assertFalse(EinladungsToken::istGueltig(null, null, null, 'irgendwas', '2026-09-29 12:00:00', self::PEPPER));
    }

    public function test_ohne_ablaufzeit_gilt_nichts(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $this->assertFalse(EinladungsToken::istGueltig($h, null, null, $k, '2026-09-29 12:00:00', self::PEPPER));
    }

    /**
     * Ruling GD-5: derselbe Klartext ergibt mit unterschiedlichem Pfeffer
     * unterschiedliche Hashes.
     */
    public function test_derselbe_klartext_ergibt_mit_unterschiedlichem_pfeffer_unterschiedliche_hashes(): void
    {
        $hash = new \ReflectionMethod(EinladungsToken::class, 'hash');
        $hash->setAccessible(true);

        $klartext = 'ABCD2345';
        $hashA = $hash->invoke(null, $klartext, 'pfeffer-a');
        $hashB = $hash->invoke(null, $klartext, 'pfeffer-b');

        $this->assertNotSame($hashA, $hashB);
    }

    /** Praktische Kehrseite derselben Regel: ein Pfefferwechsel entwertet bestehende Einladungen. */
    public function test_ein_pfefferwechsel_macht_eine_bestehende_einladung_ungueltig(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge('pfeffer-a');

        $this->assertTrue(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $k, '2026-09-29 12:00:00', 'pfeffer-a'));
        $this->assertFalse(EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $k, '2026-09-29 12:00:00', 'pfeffer-b'));
    }

    /** Ein leerer Pfeffer ist ein Konfigurationsfehler, kein Normalfall. */
    public function test_ein_leerer_pfeffer_wirft_beim_erzeugen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EinladungsToken::erzeuge('');
    }

    public function test_ein_leerer_pfeffer_wirft_beim_pruefen(): void
    {
        ['klartext' => $k, 'hash' => $h] = EinladungsToken::erzeuge(self::PEPPER);
        $this->expectException(\InvalidArgumentException::class);
        EinladungsToken::istGueltig($h, '2026-10-06 12:00:00', null, $k, '2026-09-29 12:00:00', '');
    }
}
