<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFolgewelle;

/** Automatische Folgewellen (Spec 2026-10-27 §2.4) — alle Stoppregeln an einer Stelle. */
final class DispoJobFolgewelleTest extends TestCase
{
    public function testFolgewelleNurWennOffenAbstandRestUndBedarf(): void
    {
        $this->assertSame(360, DispoJobFolgewelle::entscheiden(true, 360, 5, false));
        $this->assertNull(DispoJobFolgewelle::entscheiden(false, 360, 5, false), 'Anfrage geschlossen');
        $this->assertNull(DispoJobFolgewelle::entscheiden(true, null, 5, false), 'kein Abstand');
        $this->assertNull(DispoJobFolgewelle::entscheiden(true, 0, 5, false), '0 = keine');
        $this->assertNull(DispoJobFolgewelle::entscheiden(true, 360, 0, false), 'niemand mehr uebrig');
        $this->assertNull(DispoJobFolgewelle::entscheiden(true, 360, 5, true), 'alle Schichten gedeckt');
    }

    public function testGestopptGrund(): void
    {
        $this->assertSame('bedarf_gedeckt', DispoJobFolgewelle::gestopptGrund(true, 0, 3));
        $this->assertSame('niemand_uebrig', DispoJobFolgewelle::gestopptGrund(false, 0, 0));
        $this->assertNull(DispoJobFolgewelle::gestopptGrund(false, 2, 0));
        $this->assertNull(DispoJobFolgewelle::gestopptGrund(false, 0, 4), 'nur hinter der Obergrenze: kein Stopp');
        $this->assertSame('Bedarf gedeckt — keine weiteren Wellen', DispoJobFolgewelle::GRUENDE['bedarf_gedeckt']);
    }
}
