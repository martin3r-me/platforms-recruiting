<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobTemplateSettings;

class JobOfferTemplateSettingsTest extends TestCase
{
    public function testNormalisiert(): void
    {
        $this->assertSame([3, 12], DispoJobTemplateSettings::normalisieren(['12', '3', 'x', '0', '', 12, null]));
    }
}
