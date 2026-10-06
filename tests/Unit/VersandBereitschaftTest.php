<?php
// tests/Unit/VersandBereitschaftTest.php
namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VersandBereitschaft;

final class VersandBereitschaftTest extends TestCase
{
    public function test_bereit(): void
    {
        $b = VersandBereitschaft::bereit();
        $this->assertTrue($b->istBereit());
        $this->assertSame('bereit', $b->status);
        $this->assertNull($b->grund);
        $this->assertSame('Bereit', $b->kurztext());
    }

    public function test_unvollstaendig_nennt_die_felder(): void
    {
        $b = VersandBereitschaft::unvollstaendig(['Straße', 'Ausweis-Foto Vorderseite']);
        $this->assertFalse($b->istBereit());
        $this->assertSame('unvollstaendig', $b->status);
        $this->assertSame(['Straße', 'Ausweis-Foto Vorderseite'], $b->fehlendeFelder);
        $this->assertSame('Onboarding unvollständig: Straße, Ausweis-Foto Vorderseite', $b->grund);
        $this->assertSame('Daten fehlen: Straße, Ausweis-Foto Vorderseite', $b->kurztext());
    }

    public function test_gesperrt(): void
    {
        $b = VersandBereitschaft::gesperrt('Die Phase gehört zur Stelle „Sonstiges“ …');
        $this->assertFalse($b->istBereit());
        $this->assertSame('gesperrt', $b->status);
        $this->assertStringStartsWith('Gesperrt: Die Phase', $b->kurztext());
    }
}
