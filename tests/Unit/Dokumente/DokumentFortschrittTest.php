<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentFortschritt;

final class DokumentFortschrittTest extends TestCase
{
    private function z(array $set = []): array
    {
        return array_merge(['withdrawn_at' => null, 'signed_at' => null, 'acknowledged_at' => null, 'first_viewed_at' => null], $set);
    }

    public function test_zwoelf_von_vierzehn(): void
    {
        $liste = array_merge(
            array_fill(0, 12, $this->z(['signed_at' => '2026-10-09 10:00:00'])),
            array_fill(0, 2, $this->z()),
        );
        $f = DokumentFortschritt::fuer($liste, 'sign');
        $this->assertSame(12, $f['erledigt']);
        $this->assertSame(14, $f['gesamt']);
        $this->assertSame('12 von 14 unterschrieben', $f['text']);
    }

    public function test_zurueckgezogene_zaehlen_nicht_im_nenner(): void
    {
        $f = DokumentFortschritt::fuer([
            $this->z(['acknowledged_at' => '2026-10-09 10:00:00']),
            $this->z(['withdrawn_at' => '2026-10-09 11:00:00']),
        ], 'acknowledge');
        $this->assertSame(['erledigt' => 1, 'gesamt' => 1, 'zurueckgezogen' => 1, 'text' => '1 von 1 bestätigt'], $f);
    }

    public function test_none_zaehlt_gesehen(): void
    {
        $f = DokumentFortschritt::fuer([$this->z(['first_viewed_at' => '2026-10-09 10:00:00']), $this->z()], 'none');
        $this->assertSame('1 von 2 gesehen', $f['text']);
        $this->assertSame(1, $f['erledigt']);
    }
}
