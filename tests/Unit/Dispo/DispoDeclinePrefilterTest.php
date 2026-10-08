<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclinePrefilter;

/**
 * Was ohne Sprachmodell als Quittung durchgeht (Spec 2026-10-08, Entscheidung 5).
 * Der teure Fehler ist hier die verschluckte Absage — deshalb pruefen die
 * Faelle vor allem, dass Negationen und Fremdwoerter NIE als Quittung gelten.
 */
class DispoDeclinePrefilterTest extends TestCase
{
    /** @return list<array{string}> */
    public static function acks(): array
    {
        return [['ja'], ['Ja!'], ['OK'], ['Okay, danke'], ['Passt 👍'], ['👍'], ['👍🏼'], ['✅'], ['Bin dabei'],
            ['Ich bin dabei!'], ['Alles klar, danke dir'], ['Super, vielen Dank'], ['geht klar'], ['Ja passt. LG'],
            ['Bestätigen'], ['Komme 👍'], ['Wir sehen uns'], ['Ok bis dann']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acks')]
    public function test_obvious_acknowledgements_skip_the_model(string $text): void
    {
        $this->assertTrue(DispoDeclinePrefilter::isObviousAck($text), $text);
    }

    /** @return list<array{string}> */
    public static function notAcks(): array
    {
        return [
            ['ich bin nicht dabei'], ['geht nicht'], ['nein'], ['Ja, aber ich kann erst ab 18 Uhr'],
            ['ok ich bin krank'], ['leider nicht'], ['😢'], ['👎'], [''], ['   '],
            ['ja ' . str_repeat('danke ', 15)], // zu lang fuer "offensichtlich"
            ['Ja nein'], ['ich komme nicht'], ['komme später'], ['bis dann leider nicht'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAcks')]
    public function test_anything_with_a_foreign_word_goes_to_the_model(string $text): void
    {
        $this->assertFalse(DispoDeclinePrefilter::isObviousAck($text), $text);
    }
}
