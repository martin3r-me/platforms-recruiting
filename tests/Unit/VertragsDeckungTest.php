<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VertragsDeckung;

/**
 * Spec Vertrag aus der Akte §3.1 — wann deckt ein Arbeitsvertrag EINER
 * Anstellung einen Einsatztag. Review-Focus 1: der Altbestand ohne
 * Laufzeitfelder darf keinen HR-Fall erzeugen.
 */
final class VertragsDeckungTest extends TestCase
{
    private static function av(int $id, string $status, ?string $beginn, ?string $ende, bool $ersetzt = false, string $code = 'AV-MA-LOG'): array
    {
        return [
            'id'         => $id,
            'code'       => $code,
            'status'     => $status,
            'signed_at'  => $status === 'completed' ? '2026-09-30 12:00:00' : null,
            'superseded' => $ersetzt,
            'beginn'     => $beginn,
            'ende'       => $ende,
        ];
    }

    public static function faelle(): array
    {
        $oktober = fn (int $id, string $status = 'completed') => self::av($id, $status, '2026-10-01', '2026-10-31');

        return [
            'unterschrieben, Tag mitten drin'     => [[$oktober(1)], '2026-10-15', 'unterschrieben', 1],
            'Grenztag Beginn'                     => [[$oktober(1)], '2026-10-01', 'unterschrieben', 1],
            'Grenztag Ende'                       => [[$oktober(1)], '2026-10-31', 'unterschrieben', 1],
            'Tag nach dem Ende'                   => [[$oktober(1)], '2026-11-01', 'keiner', null],
            'Tag vor dem Beginn'                  => [[$oktober(1)], '2026-09-30', 'keiner', null],
            'Altvertrag ohne Laufzeit deckt'      => [[self::av(2, 'completed', null, null)], '2027-03-01', 'unterschrieben', 2],
            'nur Beginn, offenes Ende'            => [[self::av(3, 'completed', '2026-01-01', null)], '2030-01-01', 'unterschrieben', 3],
            'deutsches Datumsformat'              => [[self::av(4, 'completed', '01.10.2026', '31.10.2026')], '2026-10-31', 'unterschrieben', 4],
            'ersetzter Vertrag deckt nicht'       => [[self::av(5, 'completed', '2026-10-01', '2026-10-31', true)], '2026-10-15', 'keiner', null],
            'storniert deckt nicht'               => [[$oktober(6, 'cancelled')], '2026-10-15', 'keiner', null],
            'versendet ist unterwegs'             => [[$oktober(7, 'sent')], '2026-10-15', 'unterwegs', 7],
            'versendet ohne Laufzeit ist unterwegs' => [[self::av(8, 'sent', null, null)], '2026-10-15', 'unterwegs', 8],
            'versendet ausserhalb ist keiner'     => [[$oktober(9, 'sent')], '2026-11-15', 'keiner', null],
            'pending zaehlt nicht'                => [[$oktober(10, 'pending')], '2026-10-15', 'keiner', null],
            'unterschrieben sticht unterwegs'     => [[$oktober(11, 'sent'), $oktober(12)], '2026-10-15', 'unterschrieben', 12],
            'IFSG ist kein Arbeitsvertrag'        => [[self::av(13, 'completed', null, null, false, 'IFSG')], '2026-10-15', 'keiner', null],
            'blanker Altcode AV zaehlt'           => [[self::av(14, 'completed', null, null, false, 'AV')], '2026-10-15', 'unterschrieben', 14],
            'completed ohne signed_at deckt nicht' => [[['id' => 15, 'code' => 'AV-1', 'status' => 'completed', 'signed_at' => null, 'superseded' => false, 'beginn' => null, 'ende' => null]], '2026-10-15', 'keiner', null],
            'leere Liste'                         => [[], '2026-10-15', 'keiner', null],
        ];
    }

    #[DataProvider('faelle')]
    public function test_deckung_am_tag(array $vertraege, string $tag, string $deckung, ?int $id): void
    {
        $this->assertSame(['deckung' => $deckung, 'vertrag_id' => $id], VertragsDeckung::amTag($vertraege, $tag));
    }

    public function test_ueberschneidung_sieht_jeden_nicht_stornierten_av(): void
    {
        $pending = self::av(1, 'pending', '2026-10-01', '2026-10-31');
        $this->assertSame(['id' => 1, 'ende' => '2026-10-31'], VertragsDeckung::ueberschneidung([$pending], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([$pending], '2026-11-01'), 'Folgemonat ist frei');

        $alt = self::av(2, 'completed', null, null);
        $this->assertSame(['id' => 2, 'ende' => null], VertragsDeckung::ueberschneidung([$alt], '2026-10-15'), 'unbefristet blockiert');

        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(3, 'cancelled', null, null)], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(4, 'completed', null, null, true)], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(5, 'completed', null, null, false, 'IFSG')], '2026-10-15'));
    }

    public function test_datum_normalisiert(): void
    {
        $this->assertSame('2026-10-01', VertragsDeckung::datum('2026-10-01'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum('2026-10-01 00:00:00'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum('01.10.2026'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum(new \DateTimeImmutable('2026-10-01 13:00:00')));
        $this->assertNull(VertragsDeckung::datum('2026-02-30'));
        $this->assertNull(VertragsDeckung::datum(''));
        $this->assertNull(VertragsDeckung::datum(null));
        $this->assertNull(VertragsDeckung::datum('demnaechst'));
    }
}
