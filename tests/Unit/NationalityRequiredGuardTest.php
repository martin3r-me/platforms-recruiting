<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NationalityRequiredGuard;

/**
 * Staatsangehoerigkeit ist im MA-Portal Pflicht (Kundenentscheidung
 * 23.09.2026): ohne Wert wird das Profil nicht gespeichert. Harter Block
 * nach dem Muster FirstAiderDateGuard — nur im Portal, HR bleibt frei.
 */
class NationalityRequiredGuardTest extends TestCase
{
    public function test_missing_value_blocks_with_a_message(): void
    {
        $this->assertNotNull(NationalityRequiredGuard::error(null));
        $this->assertNotNull(NationalityRequiredGuard::error(''));
        $this->assertNotNull(NationalityRequiredGuard::error('   '));
    }

    public function test_message_names_the_field_and_says_nothing_was_saved(): void
    {
        $msg = NationalityRequiredGuard::error('');

        $this->assertStringContainsString('Staatsangehoerigkeit', $msg);
        $this->assertStringContainsString('nichts gespeichert', $msg);
    }

    public function test_present_value_passes(): void
    {
        $this->assertNull(NationalityRequiredGuard::error('de'));
        $this->assertNull(NationalityRequiredGuard::error(' bd '));
    }
}
