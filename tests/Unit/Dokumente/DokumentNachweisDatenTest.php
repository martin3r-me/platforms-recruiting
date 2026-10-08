<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentNachweisDaten;

final class DokumentNachweisDatenTest extends TestCase
{
    public function test_alle_felder_und_der_abstand(): void
    {
        $d = DokumentNachweisDaten::fuer(
            ['title' => 'Verschwiegenheit', 'original_filename' => 'v.pdf', 'file_sha256' => str_repeat('a', 64), 'created_at' => '2026-10-09 09:00:00'],
            ['created_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 09:05:00', 'acknowledged_at' => '2026-10-09 09:06:30', 'signed_at' => '2026-10-09 09:06:30', 'signature_data' => 'data:image/png;base64,AAA='],
            ['first_name' => 'Anna', 'last_name' => 'Test', 'personnel_number' => 'RG1464', 'company' => 'RheinGedeck GmbH'],
        );

        $this->assertSame('Anna Test', $d['name']);
        $this->assertSame('RG1464', $d['personalnummer']);
        $this->assertSame('RheinGedeck GmbH', $d['firma']);
        $this->assertSame('09.10.2026 09:05:00', $d['geoeffnet']);
        $this->assertSame('09.10.2026 09:06:30', $d['unterschrieben']);
        $this->assertSame(90, $d['abstand_sekunden']);
        $this->assertSame('data:image/png;base64,AAA=', $d['unterschrift']);
    }

    public function test_leere_werte_werden_strich(): void
    {
        $d = DokumentNachweisDaten::fuer(
            ['title' => 'T', 'original_filename' => 't.pdf', 'file_sha256' => 'x', 'created_at' => null],
            ['created_at' => null, 'first_viewed_at' => null, 'acknowledged_at' => null, 'signed_at' => null, 'signature_data' => null],
            ['first_name' => '', 'last_name' => '', 'personnel_number' => null, 'company' => null],
        );

        $this->assertSame('—', $d['name']);
        $this->assertSame('—', $d['personalnummer']);
        $this->assertSame('—', $d['geoeffnet']);
        $this->assertNull($d['abstand_sekunden']);
        $this->assertNull($d['unterschrift']);
    }
}
