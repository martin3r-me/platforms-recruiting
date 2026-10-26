<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\AkteAmpel;
use Platform\Recruiting\Services\Comms\LohnLuecken;

/** Ampel im Chat-Kopf (Markus, HR allgemein, 26.10.2026). */
final class AkteAmpelTest extends TestCase
{
    protected function setUp(): void
    {
        $c = Container::getInstance();
        $c->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return 'https://app.test/' . $name . '/' . ($parameters['token'] ?? '');
            }
        });
        Facade::setFacadeApplication($c);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('url');
        Facade::clearResolvedInstances();
    }

    public function test_gruen_ohne_luecken_rot_mit_luecken(): void
    {
        $this->assertSame(['gruen' => true, 'luecken' => []], (new AkteAmpel($this->luecken([])))->fuer(new RecEmployee()));
        $this->assertSame(['gruen' => false, 'luecken' => ['IBAN']], (new AkteAmpel($this->luecken(['IBAN'])))->fuer(new RecEmployee()));
    }

    public function test_entwurf_nennt_die_luecken_und_den_portal_link(): void
    {
        $ma = new RecEmployee();
        $ma->portal_token = 'tok-erika';
        $ma->portal_v2_since = '2026-10-20 10:00:00';

        $text = AkteAmpel::entwurf($ma, true, null, ['IBAN', 'Personalausweis']);

        $this->assertStringContainsString('• IBAN', $text);
        $this->assertStringContainsString('• Personalausweis', $text);
        $this->assertStringEndsWith('https://app.test/recruiting.public.employee-portal/tok-erika', $text);
    }

    public function test_entwurf_ohne_neues_portal_ohne_link(): void
    {
        $text = AkteAmpel::entwurf(new RecEmployee(), false, null, ['IBAN']);

        $this->assertStringContainsString('Ihrer Akte', $text);
        $this->assertStringNotContainsString('https://', $text);
    }

    public function test_chat_kopf_zeigt_ampel_und_knopf(): void
    {
        // Vertrag an der Quelle (Muster SharedPartialContractTest): Livewire-Render braucht den App-Boot.
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/conversations/inbox.blade.php');
        $this->assertStringContainsString('$this->akteAmpel', $blade);
        $this->assertStringContainsString('wire:click="akteHinweis"', $blade);
        $this->assertStringContainsString('Akte vollständig', $blade);
        $this->assertStringContainsString('Akte unvollständig', $blade);

        $php = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Conversations/Inbox.php');
        $this->assertMatchesRegularExpression('/#\[Computed\]\s*public function akteAmpel\(\): \?array/', $php);
        $this->assertStringContainsString('public function akteHinweis(): void', $php);
    }

    /** @param list<string> $l */
    private function luecken(array $l): LohnLuecken
    {
        return new class($l) extends LohnLuecken {
            public function __construct(private array $l) {}

            public function fuer(RecEmployee $employee): array
            {
                return $this->l;
            }
        };
    }
}
