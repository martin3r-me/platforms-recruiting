<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;

/**
 * Das „?“ muss den Routennamen fest mitbekommen: Livewire-Neurender laufen
 * ueber die Route livewire.update — aus request() gelesen waere das „?“ nach
 * dem ersten Klick auf der Seite weg.
 */
class HilfeEinbauTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function einbau(): array
    {
        return [
            'Dispo-Einstellungen' => ['resources/views/livewire/dispo/settings.blade.php', 'recruiting.dispo.settings'],
            'VA-Seite' => ['resources/views/livewire/dispo/events/show.blade.php', 'recruiting.dispo.events.show'],
            'Schulungstermine' => ['resources/views/livewire/interview-schedule/index.blade.php', 'recruiting.interview-schedule.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('einbau')]
    public function test_seite_bindet_das_fragezeichen_mit_festem_routennamen_ein(string $view, string $route): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $view);

        $this->assertStringContainsString("@include('recruiting::anleitungen._hilfe', ['route' => '{$route}'])", $quelle);
    }

    public function test_partial_liest_die_route_nicht_aus_dem_request(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/anleitungen/_hilfe.blade.php');

        $this->assertStringNotContainsString('request()', $quelle);
        $this->assertStringNotContainsString('Route::current', $quelle);
        $this->assertStringContainsString('fuerRoute($route)', $quelle);
    }
}
