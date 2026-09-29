<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Einmalcode;

/**
 * Der Einmalcode ist das schwaechste Glied der Kette: nur sechs Ziffern.
 * Die Tests decken deshalb genau die Dinge ab, die ihn trotzdem sicher
 * machen — Zufall, Ablauf, Versuchszaehler, Pfeffer (Ruling GD-5) — sowie
 * den konstanten Vergleich (der sich nicht direkt messen laesst, siehe
 * hash_equals() in der Klasse).
 */
final class EinmalcodeTest extends TestCase
{
    private const PEPPER = 'test-pfeffer-1';

    public function test_ein_code_hat_sechs_ziffern(): void
    {
        ['klartext' => $k] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $k);
    }

    public function test_zwei_codes_sind_verschieden(): void
    {
        $a = Einmalcode::erzeuge(self::PEPPER)['klartext'];
        $b = Einmalcode::erzeuge(self::PEPPER)['klartext'];
        $this->assertNotSame($a, $b, 'ein vorhersagbarer Code ist kein Nachweis');
    }

    public function test_der_richtige_code_gilt(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertTrue(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00', self::PEPPER));
    }

    public function test_ein_falscher_code_gilt_nicht(): void
    {
        ['hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, '000000', '2026-09-29 12:05:00', self::PEPPER));
    }

    public function test_ein_abgelaufener_code_gilt_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:11:00', self::PEPPER));
    }

    public function test_nach_zu_vielen_versuchen_gilt_auch_der_richtige_nicht(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(
            Einmalcode::istGueltig($h, '2026-09-29 12:10:00', Einmalcode::MAX_VERSUCHE, $k, '2026-09-29 12:05:00', self::PEPPER),
            'sonst kann man sechs Ziffern in Ruhe durchprobieren',
        );
    }

    public function test_ohne_hash_gilt_nichts(): void
    {
        $this->assertFalse(Einmalcode::istGueltig(null, null, 0, '123456', '2026-09-29 12:00:00', self::PEPPER));
    }

    /** Ein Versuch unterhalb der Grenze darf noch nicht bremsen — nur "erreicht" blockiert. */
    public function test_kurz_vor_der_versuchsgrenze_gilt_der_richtige_code_noch(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertTrue(Einmalcode::istGueltig(
            $h,
            '2026-09-29 12:10:00',
            Einmalcode::MAX_VERSUCHE - 1,
            $k,
            '2026-09-29 12:05:00',
            self::PEPPER,
        ));
    }

    /** Ohne bekannten Ablauf darf nichts als gueltig durchgehen — im Zweifel abgelaufen. */
    public function test_ohne_ablaufzeit_gilt_nichts(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, null, 0, $k, '2026-09-29 12:05:00', self::PEPPER));
    }

    /** F8: die Ablaufsekunde selbst zaehlt schon als abgelaufen (strenger Vergleich >=). */
    public function test_die_ablaufsekunde_selbst_gilt_bereits_als_abgelaufen(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:10:00', self::PEPPER));
    }

    /** F11: ein unlesbarer Ablauf ist ein Datenfehler, kein Nachweis — false statt 500er. */
    public function test_ein_unlesbarer_ablauf_gilt_als_ungueltig(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, 'kaputt', 0, $k, '2026-09-29 12:05:00', self::PEPPER));
    }

    /** F11: ebenso bei unlesbarem "jetzt". */
    public function test_ein_unlesbares_jetzt_gilt_als_ungueltig(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, 'kaputt', self::PEPPER));
    }

    /**
     * Ruling GD-5: derselbe Klartext ergibt mit unterschiedlichem Pfeffer
     * unterschiedliche Hashes — das ist die Regel, die gegen einen reinen
     * DB-Dump traegt (der Pfeffer lebt in der .env, nicht in der Datenbank).
     */
    public function test_derselbe_klartext_ergibt_mit_unterschiedlichem_pfeffer_unterschiedliche_hashes(): void
    {
        $hash = new \ReflectionMethod(Einmalcode::class, 'hash');
        $hash->setAccessible(true);

        $klartext = '123456';
        $hashA = $hash->invoke(null, $klartext, 'pfeffer-a');
        $hashB = $hash->invoke(null, $klartext, 'pfeffer-b');

        $this->assertNotSame($hashA, $hashB);
    }

    /** Praktische Kehrseite derselben Regel: ein Pfefferwechsel entwertet bestehende Codes. */
    public function test_ein_pfefferwechsel_macht_einen_bestehenden_code_ungueltig(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge('pfeffer-a');

        $this->assertTrue(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00', 'pfeffer-a'));
        $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00', 'pfeffer-b'));
    }

    /** Ein leerer Pfeffer ist ein Konfigurationsfehler, kein Normalfall — still ungepfeffert waere unsicher. */
    public function test_ein_leerer_pfeffer_wirft_beim_erzeugen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Einmalcode::erzeuge('');
    }

    public function test_ein_leerer_pfeffer_wirft_beim_pruefen(): void
    {
        ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge(self::PEPPER);
        $this->expectException(\InvalidArgumentException::class);
        Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00', '');
    }
}
