<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;

class HoldingTemplateComponentsTest extends TestCase
{
    private function body(string $text, array $namedExamples = []): array
    {
        $comp = ['type' => 'BODY', 'text' => $text];
        if ($namedExamples) {
            $comp['example']['body_text_named_params'] = array_map(
                fn ($k, $v) => ['param_name' => $k, 'example' => $v],
                array_keys($namedExamples),
                array_values($namedExamples),
            );
        }
        return $comp;
    }

    public function test_no_body_vars_yields_empty(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Wir melden uns!')], 'Matti');
        $this->assertSame([], $out);
    }

    public function test_named_name_var_filled_with_first_name(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{name}}, wir melden uns!')], 'Matti');

        $this->assertCount(1, $out);
        $this->assertSame('body', $out[0]['type']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Matti', 'parameter_name' => 'name'],
        ], $out[0]['parameters']);
    }

    public function test_vorname_alias_also_filled(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hi {{vorname}}')], 'Lea');
        $this->assertSame('Lea', $out[0]['parameters'][0]['text']);
        $this->assertSame('vorname', $out[0]['parameters'][0]['parameter_name']);
    }

    public function test_positional_param_has_no_parameter_name(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{1}}')], 'Sam');

        $this->assertSame('Sam', $out[0]['parameters'][0]['text']);
        $this->assertArrayNotHasKey('parameter_name', $out[0]['parameters'][0]);
    }

    public function test_unknown_var_uses_example_if_present(): void
    {
        $out = HoldingTemplateComponents::build(
            [$this->body('Ticket {{ticket}}', ['ticket' => 'ABC-1'])],
            'Matti',
        );
        $this->assertSame('ABC-1', $out[0]['parameters'][0]['text']);
    }

    public function test_unknown_var_without_example_falls_back_to_first_name(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Ticket {{ticket}}')], 'Matti');
        $this->assertSame('Matti', $out[0]['parameters'][0]['text']);
    }

    public function test_multiple_vars_in_order(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{name}}, dein Ticket {{ticket}}', ['ticket' => 'T-9'])], 'Ana');

        $this->assertCount(2, $out[0]['parameters']);
        $this->assertSame('Ana', $out[0]['parameters'][0]['text']);
        $this->assertSame('T-9', $out[0]['parameters'][1]['text']);
    }

    public function test_empty_name_produces_empty_param(): void
    {
        // Kein Vorname → Pflicht-Parameter bliebe leer (Meta lehnt das ab).
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{name}}')], '');
        $this->assertSame('', $out[0]['parameters'][0]['text']);
    }

    public function test_has_empty_required_param_detects_empty_text(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{name}}')], '');
        $this->assertTrue(HoldingTemplateComponents::hasEmptyRequiredParam($out));
    }

    public function test_has_empty_required_param_false_when_filled(): void
    {
        $out = HoldingTemplateComponents::build([$this->body('Hallo {{name}}')], 'Lea');
        $this->assertFalse(HoldingTemplateComponents::hasEmptyRequiredParam($out));
    }

    public function test_has_empty_required_param_false_when_no_params(): void
    {
        // Variablenfreies Template → keine Parameter → nichts kann leer sein.
        $this->assertFalse(HoldingTemplateComponents::hasEmptyRequiredParam([]));
    }
}
