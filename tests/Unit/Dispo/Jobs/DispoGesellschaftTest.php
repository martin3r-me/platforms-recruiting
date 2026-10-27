<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoGesellschaft;

/**
 * Gesellschaft (Firma RG/MA der Anstellung) — NICHT der ZAS-Status GO/MA.
 * Kunde 27.10.2026: je Filiale je Gesellschaft ausweisen und so anschreiben.
 */
final class DispoGesellschaftTest extends TestCase
{
    public function testNormalizeNurBekannteInFesterReihenfolge(): void
    {
        $this->assertSame(['RG', 'MA'], DispoGesellschaft::normalize(['MA', 'XY', 'rg', 'RG']));
        $this->assertSame([], DispoGesellschaft::normalize([]));
        $this->assertSame(['MA'], DispoGesellschaft::normalize([['x'], 'MA']), 'verschachtelte Werte vom Client fallen heraus');
    }

    public function testAusCompanyUnbekanntIstRg(): void
    {
        $this->assertSame('MA', DispoGesellschaft::ausCompany(' ma '));
        $this->assertSame('RG', DispoGesellschaft::ausCompany('RG'));
        $this->assertSame('RG', DispoGesellschaft::ausCompany(null), 'Export-Default ist RG');
        $this->assertSame('RG', DispoGesellschaft::ausCompany('irgendwas'));
    }

    public function testAusEinsatzfirmaErkenntBeideSonstNull(): void
    {
        $this->assertSame('RG', DispoGesellschaft::ausEinsatzfirma('RheinGedeck GmbH'));
        $this->assertSame('RG', DispoGesellschaft::ausEinsatzfirma('RG'));
        $this->assertSame('MA', DispoGesellschaft::ausEinsatzfirma('MA'));
        $this->assertSame('MA', DispoGesellschaft::ausEinsatzfirma('MA Service GmbH'));
        $this->assertNull(DispoGesellschaft::ausEinsatzfirma('Maritim Hotel'), '„Ma“ als Wortanfang ist keine Gesellschaft');
        $this->assertNull(DispoGesellschaft::ausEinsatzfirma(null));
        $this->assertNull(DispoGesellschaft::ausEinsatzfirma(''));
    }

    public function testLabel(): void
    {
        $this->assertSame('', DispoGesellschaft::label(null), 'alte Welle = beide = kein Zusatz');
        $this->assertSame('', DispoGesellschaft::label(['MA', 'RG']));
        $this->assertSame('RG', DispoGesellschaft::label(['RG']));
        $this->assertSame('MA', DispoGesellschaft::label(['MA']));
        $this->assertSame('keine Gesellschaft', DispoGesellschaft::label([]));
    }
}
