<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NachnameZuerst;

/** Sheran 13.11.2026: in der Einbuchungs-Tabelle „Nachname, Vorname“ wie in ZAS. */
class NachnameZuerstTest extends TestCase
{
    public function test_dreht_den_namen_um(): void
    {
        $this->assertSame('Kaldeich, Johanna', NachnameZuerst::von('Johanna', 'Kaldeich'));
        $this->assertSame('Yao, Jocelyne Aliya', NachnameZuerst::von(' Jocelyne Aliya ', ' Yao '));
    }

    public function test_fehlt_ein_teil_steht_der_andere_allein(): void
    {
        $this->assertSame('Kaldeich', NachnameZuerst::von('', 'Kaldeich'));
        $this->assertSame('Johanna', NachnameZuerst::von('Johanna', null));
        $this->assertSame('', NachnameZuerst::von(null, null));
    }
}
