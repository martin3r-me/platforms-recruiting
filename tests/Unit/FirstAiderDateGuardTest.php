<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FirstAiderDateGuard;

class FirstAiderDateGuardTest extends TestCase
{
    public function test_blocks_truthy_flag_without_date(): void
    {
        foreach (['1', 'true', 'ja', 'Ja', ' 1 '] as $flag) {
            $error = FirstAiderDateGuard::error($flag, '');
            $this->assertNotNull($error, "Flag '$flag' ohne Datum muss blocken");
            $this->assertStringContainsString('Ersthelfer', $error);
        }
    }

    public function test_blocks_whitespace_only_date(): void
    {
        $this->assertNotNull(FirstAiderDateGuard::error('1', '   '));
    }

    public function test_passes_with_date(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('1', '2027-03-01'));
        $this->assertNull(FirstAiderDateGuard::error('ja', '2027-03-01'));
    }

    public function test_passes_when_not_set(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('0', ''));
        $this->assertNull(FirstAiderDateGuard::error('nein', ''));
        $this->assertNull(FirstAiderDateGuard::error('', ''));
        $this->assertNull(FirstAiderDateGuard::error(null, null));
    }
}
