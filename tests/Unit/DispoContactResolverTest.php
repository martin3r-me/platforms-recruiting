<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoContactResolver as R;

class DispoContactResolverTest extends TestCase
{
    private array $leads = [
        ['employee_id' => 7, 'name' => 'Markus Ammerer', 'phone' => '+49 172 1', 'label' => 'Markus Ammerer (+49 172 1)'],
        ['employee_id' => 9, 'name' => 'Ali Zwei', 'phone' => '+49 160 1', 'label' => 'Ali Zwei (+49 160 1)'],
    ];

    public function test_nothing_stored_and_lead_present_is_auto_default(): void
    {
        $this->assertSame(['label' => 'Markus Ammerer (+49 172 1)', 'source' => 'auto'], R::effective(null, $this->leads));
        $this->assertSame(['label' => 'Markus Ammerer (+49 172 1)', 'source' => 'auto'], R::effective('  ', $this->leads));
    }

    public function test_stored_manual_value_wins(): void
    {
        $this->assertSame(['label' => 'Jeton', 'source' => 'manual'], R::effective('Jeton', $this->leads));
    }

    public function test_stored_equal_to_default_lead_counts_as_auto(): void
    {
        $this->assertSame('auto', R::effective('Markus Ammerer (+49 172 1)', $this->leads)['source']);
    }

    public function test_stored_equal_to_second_lead_is_manual_choice(): void
    {
        $this->assertSame(['label' => 'Ali Zwei (+49 160 1)', 'source' => 'manual'], R::effective('Ali Zwei (+49 160 1)', $this->leads));
    }

    public function test_no_leads_and_nothing_stored_is_empty(): void
    {
        $this->assertSame(['label' => null, 'source' => null], R::effective(null, []));
        $this->assertSame(['label' => 'Jeton', 'source' => 'manual'], R::effective('Jeton', []));
    }

    public function test_to_store_empty_or_default_lead_is_null(): void
    {
        $this->assertNull(R::toStore('', $this->leads));
        $this->assertNull(R::toStore('   ', $this->leads));
        $this->assertNull(R::toStore('Markus Ammerer (+49 172 1)', $this->leads));
        $this->assertNull(R::toStore('  Markus Ammerer (+49 172 1)  ', $this->leads));
    }

    public function test_to_store_keeps_manual_text_and_other_lead(): void
    {
        $this->assertSame('Jeton', R::toStore(' Jeton ', $this->leads));
        $this->assertSame('Ali Zwei (+49 160 1)', R::toStore('Ali Zwei (+49 160 1)', $this->leads));
        $this->assertSame('Jeton', R::toStore('Jeton', []));
    }

    public function test_to_store_truncates_to_255(): void
    {
        $this->assertSame(255, mb_strlen((string) R::toStore(str_repeat('x', 300), $this->leads)));
    }
}
