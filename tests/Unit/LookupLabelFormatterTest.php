<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\LookupLabelFormatter;

class LookupLabelFormatterTest extends TestCase
{
    private const MAP = ['tr' => 'Türkei', 'de' => 'Deutschland', 'xk' => 'Kosovo'];

    public function test_resolves_scalar_value_to_label(): void
    {
        $this->assertSame('Türkei', LookupLabelFormatter::format('tr', self::MAP));
    }

    public function test_resolves_array_to_comma_separated_labels(): void
    {
        $this->assertSame(
            'Türkei, Kosovo',
            LookupLabelFormatter::format(['tr', 'xk'], self::MAP)
        );
    }

    public function test_unknown_value_falls_back_to_raw_value(): void
    {
        $this->assertSame('zz', LookupLabelFormatter::format('zz', self::MAP));
    }

    public function test_empty_map_falls_back_to_raw_value(): void
    {
        $this->assertSame('tr', LookupLabelFormatter::format('tr', []));
    }

    public function test_null_and_empty_string_return_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format(null, self::MAP));
        $this->assertNull(LookupLabelFormatter::format('', self::MAP));
    }

    public function test_empty_array_returns_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format([], self::MAP));
    }

    public function test_array_with_only_empty_entries_returns_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format(['', null], self::MAP));
    }

    public function test_non_string_scalar_is_cast(): void
    {
        $this->assertSame('42', LookupLabelFormatter::format(42, self::MAP));
    }

    /**
     * BEWUSSTE ABWEICHUNG vom alten Verhalten. Der alte Code filterte die
     * Array-Labels mit ->filter() ohne Callback, also nach PHP-Truthiness —
     * damit fiel ein Label '0' (oder ein unbekannter Wert, der auf '0'
     * zurueckfaellt) still aus der Liste. Neu bleibt er drin. Das neue
     * Verhalten ist das richtige; der Test nagelt es fest.
     */
    public function test_zero_label_is_kept_unlike_old_filter_behaviour(): void
    {
        $this->assertSame('0', LookupLabelFormatter::format(['0'], self::MAP));
        $this->assertSame('Türkei, 0', LookupLabelFormatter::format(['tr', '0'], self::MAP));
    }

    /**
     * DOKUMENTIERTES BESTANDSVERHALTEN, kein Bug-Fix. Ein als JSON-String
     * gespeicherter Multi-Select wird NICHT dekodiert — er wird als Ganzes
     * in der Map gesucht und faellt auf den Rohwert zurueck. Der ZAS-Export
     * fuettert genau solche Rohwerte (ZasFieldResolver:447-451 liest die
     * value-Spalte ohne decodeSelectValue). Das hier zu "reparieren" wuerde
     * den ZAS-Export still veraendern — gehoert auf die ZAS-Phase-2-Liste.
     */
    public function test_json_string_is_not_decoded(): void
    {
        $this->assertSame('["tr","xk"]', LookupLabelFormatter::format('["tr","xk"]', self::MAP));
    }
}
