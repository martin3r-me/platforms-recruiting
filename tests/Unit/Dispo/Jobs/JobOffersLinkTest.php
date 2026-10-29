<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersLink;

final class JobOffersLinkTest extends TestCase
{
    private const UUID = '0199b1c2-7d3e-7f40-8a1b-2c3d4e5f6a7b';

    public function test_suffix_traegt_token_und_anfrage(): void
    {
        $this->assertSame(self::UUID . '.17', JobOffersLink::suffix(self::UUID, 17));
        $this->assertSame(self::UUID, JobOffersLink::suffix(self::UUID, null));
        $this->assertSame(self::UUID, JobOffersLink::suffix(self::UUID, 0));
    }

    public function test_lesen_trennt_wieder(): void
    {
        $this->assertSame(['token' => self::UUID, 'anfrage' => 17], JobOffersLink::lesen(self::UUID . '.17'));
        $this->assertSame(['token' => self::UUID, 'anfrage' => null], JobOffersLink::lesen(self::UUID), 'alter Link ohne Anfrage');
        $this->assertSame(['token' => 'tok5', 'anfrage' => 3], JobOffersLink::lesen('tok5.3'));
        $this->assertSame(['token' => 'tok.abc', 'anfrage' => null], JobOffersLink::lesen('tok.abc'), 'nur Ziffern hinter dem Punkt zaehlen');
        $this->assertSame(['token' => '', 'anfrage' => null], JobOffersLink::lesen(''));
    }

    public function test_hin_und_zurueck(): void
    {
        foreach ([1, 42, 123456] as $id) {
            $this->assertSame(['token' => self::UUID, 'anfrage' => $id], JobOffersLink::lesen(JobOffersLink::suffix(self::UUID, $id)));
        }
    }
}
