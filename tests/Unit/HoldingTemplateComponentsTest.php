<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;

class HoldingTemplateComponentsTest extends TestCase
{
    public function test_named_values_fill_matching_params(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Wir sind vom {{von}} bis {{bis}} abwesend und ab {{wieder_da}} wieder da.',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'von', 'example' => '01.01.2026'],
                ['param_name' => 'bis', 'example' => '02.01.2026'],
                ['param_name' => 'wieder_da', 'example' => '03.01.2026'],
            ]],
        ]];

        $result = HoldingTemplateComponents::build($components, '', [
            'von' => '14.07.2026', 'bis' => '18.07.2026', 'wieder_da' => '21.07.2026',
        ]);

        $params = $result[0]['parameters'];
        $this->assertSame('14.07.2026', $params[0]['text']);
        $this->assertSame('18.07.2026', $params[1]['text']);
        $this->assertSame('21.07.2026', $params[2]['text']);
        $this->assertSame('von', $params[0]['parameter_name']);
    }

    public function test_named_values_take_precedence_over_examples_and_name_logic(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Hallo {{name}}, wieder da am {{wieder_da}}.',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'name', 'example' => 'Max'],
                ['param_name' => 'wieder_da', 'example' => '01.01.2026'],
            ]],
        ]];

        // name kommt weiterhin aus firstName, wieder_da aus namedValues
        $result = HoldingTemplateComponents::build($components, 'Nini', ['wieder_da' => '21.07.2026']);
        $params = $result[0]['parameters'];
        $this->assertSame('Nini', $params[0]['text']);
        $this->assertSame('21.07.2026', $params[1]['text']);
    }

    public function test_missing_named_value_falls_back_to_example_then_empty_guard(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Bis {{bis}}.',
            'example' => [],
        ]];

        // kein namedValue, kein Example, kein firstName -> leerer Param -> Guard greift
        $result = HoldingTemplateComponents::build($components, '', []);
        $this->assertTrue(HoldingTemplateComponents::hasEmptyRequiredParam($result));
    }
}
