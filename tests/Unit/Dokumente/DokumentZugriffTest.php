<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentZugriff;

/** Reihenfolge wie DispoAttachmentAccess: nie ein Existenz-Orakel. */
final class DokumentZugriffTest extends TestCase
{
    public function test_matrix(): void
    {
        // empfaengerGefunden, sitzungGueltig, portalGesperrt, zurueckgezogen → Code
        $faelle = [
            [false, true,  false, false, 404],
            [true,  false, false, false, 403],
            [true,  true,  true,  false, 403],
            [true,  true,  false, true,  404],
            [true,  true,  false, false, 200],
            [false, false, true,  true,  404],
        ];
        foreach ($faelle as [$e, $s, $g, $z, $erwartet]) {
            $this->assertSame($erwartet, DokumentZugriff::entscheide($e, $s, $g, $z), "Fall ($e,$s,$g,$z)");
        }
    }
}
