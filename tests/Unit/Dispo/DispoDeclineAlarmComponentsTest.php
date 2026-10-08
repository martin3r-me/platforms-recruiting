<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineAlarmComponents;

/** Parameter der Absage-Alarm-Vorlage (Spec 2026-10-08, Entscheidung 9). */
class DispoDeclineAlarmComponentsTest extends TestCase
{
    private function body(string $text): array
    {
        return ['type' => 'BODY', 'text' => $text];
    }

    public function test_positional_placeholders_get_name_event_and_dates_by_number(): void
    {
        $def = [$this->body('Moegliche Absage in {{2}}: {{1}} am {{3}}')];
        $out = DispoDeclineAlarmComponents::build('Max M.', 'Rheinterrassen', '12.10.', 7, $def);

        $this->assertSame([['type' => 'body', 'parameters' => [
            ['type' => 'text', 'text' => 'Max M.'],
            ['type' => 'text', 'text' => 'Rheinterrassen'],
            ['type' => 'text', 'text' => '12.10.'],
        ]]], $out);
    }

    public function test_named_placeholders_are_filled_in_order_of_appearance(): void
    {
        $def = json_encode([$this->body('{{name}} sagt vermutlich ab: {{va}}')]);
        $out = DispoDeclineAlarmComponents::build('Max M.', 'Rheinterrassen', '12.10.', 7, $def);

        $this->assertSame([
            ['type' => 'text', 'text' => 'Max M.', 'parameter_name' => 'name'],
            ['type' => 'text', 'text' => 'Rheinterrassen', 'parameter_name' => 'va'],
        ], $out[0]['parameters']);
    }

    public function test_dynamic_url_button_gets_the_event_id_static_one_does_not(): void
    {
        $def = [
            $this->body('{{1}}'),
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.org'],
                ['type' => 'URL', 'text' => 'Zur VA', 'url' => 'https://app.example/dispo/{{1}}'],
            ]],
        ];
        $out = DispoDeclineAlarmComponents::build('Max', 'VA', '12.10.', 4711, $def);

        $this->assertCount(2, $out);
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => '4711']]], $out[1]);
    }

    public function test_template_asking_for_more_than_three_values_is_rejected(): void
    {
        $this->assertNull(DispoDeclineAlarmComponents::build('a', 'b', 'c', 1, [$this->body('{{1}} {{2}} {{3}} {{4}}')]));
        $this->assertNull(DispoDeclineAlarmComponents::build('a', 'b', 'c', 1, [$this->body('{{a}} {{b}} {{c}} {{d}}')]));
    }

    public function test_placeholder_in_the_header_is_rejected(): void
    {
        $def = [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Absage {{1}}'], $this->body('{{1}}')];

        $this->assertNull(DispoDeclineAlarmComponents::build('a', 'b', 'c', 1, $def));
        $this->assertNotNull(DispoDeclineAlarmComponents::build('a', 'b', 'c', 1, [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Absage'], $this->body('{{1}}')]));
    }

    public function test_empty_value_is_never_sent_blank(): void
    {
        $out = DispoDeclineAlarmComponents::build('Max', '', '12.10.', 1, [$this->body('{{1}} {{2}}')]);

        $this->assertSame('-', $out[0]['parameters'][1]['text']);
    }
}
