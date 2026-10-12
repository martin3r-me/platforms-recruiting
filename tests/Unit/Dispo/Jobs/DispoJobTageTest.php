<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobTage;

final class DispoJobTageTest extends TestCase
{
    public function testErsterEintrittAktiviertAlleTage(): void
    {
        $this->assertSame(['2026-10-24', '2026-10-25'], DispoJobTage::angleichen([], ['2026-10-25', '2026-10-24'], []));
    }

    public function testNeueTageKommenAktivDazuWeggefalleneFallenRaus(): void
    {
        $aktiv = ['2026-10-24', '2026-10-25'];
        $vorher = ['2026-10-24', '2026-10-25'];
        $neu = ['2026-10-25', '2026-10-26'];

        $this->assertSame(['2026-10-25', '2026-10-26'], DispoJobTage::angleichen($aktiv, $neu, $vorher));
    }

    public function testAbwahlBestehenderTageBleibtErhalten(): void
    {
        // 24. abgewaehlt, Zeitraum um den 26. erweitert
        $this->assertSame(['2026-10-25', '2026-10-26'],
            DispoJobTage::angleichen(['2026-10-25'], ['2026-10-24', '2026-10-25', '2026-10-26'], ['2026-10-24', '2026-10-25']));
    }

    public function testFremdeTageAusDemBrowserFallenRaus(): void
    {
        $this->assertSame(['2026-10-24'], DispoJobTage::angleichen(['2026-10-24', '1999-01-01'], ['2026-10-24'], ['2026-10-24']));
    }
}
