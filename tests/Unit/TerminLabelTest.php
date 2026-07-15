<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\TerminLabel;

class TerminLabelTest extends TestCase
{
    public function test_format_deutsch_mit_wochentag_und_uhrzeit(): void
    {
        $this->assertSame(
            'Samstag, 25. Juli 2026 um 15:00 Uhr',
            TerminLabel::format(Carbon::create(2026, 7, 25, 15, 0))
        );
    }

    public function test_format_ignoriert_ambiente_locale(): void
    {
        // Explizites ->locale('de') im Format — auch wenn die globale
        // Carbon-Locale (APP_LOCALE-Sync) auf en steht, kommt Deutsch raus.
        Carbon::setLocale('en');
        try {
            $this->assertSame(
                'Mittwoch, 24. Dezember 2025 um 08:05 Uhr',
                TerminLabel::format(Carbon::create(2025, 12, 24, 8, 5))
            );
        } finally {
            Carbon::setLocale('en');
        }
    }

    public function test_format_fuehrende_null_bei_minuten(): void
    {
        $this->assertSame(
            'Montag, 3. August 2026 um 09:07 Uhr',
            TerminLabel::format(Carbon::create(2026, 8, 3, 9, 7))
        );
    }
}
