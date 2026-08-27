<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentAccess;

class DispoAttachmentAccessTest extends TestCase
{
    public function test_unknown_token_is_404_even_if_attachment_would_exist(): void
    {
        $this->assertSame(404, DispoAttachmentAccess::decide(false, false, true));
    }

    public function test_locked_portal_is_403_before_ownership(): void
    {
        $this->assertSame(403, DispoAttachmentAccess::decide(true, true, true));
        $this->assertSame(403, DispoAttachmentAccess::decide(true, true, false));
    }

    public function test_foreign_or_missing_attachment_is_404(): void
    {
        $this->assertSame(404, DispoAttachmentAccess::decide(true, false, false));
    }

    public function test_owned_attachment_is_200(): void
    {
        $this->assertSame(200, DispoAttachmentAccess::decide(true, false, true));
    }
}
