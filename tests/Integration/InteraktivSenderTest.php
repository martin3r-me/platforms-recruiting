<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;
use Platform\Recruiting\Services\Comms\InteraktivSender;
use Platform\Recruiting\Services\Comms\ThemenAuswahlEmpfaenger;
use Psr\Log\NullLogger;

/**
 * Der Versand der Themen-Auswahl: Meta-Grenzen werden VOR dem Send erzwungen,
 * ein abgelehnter Versand ist kein Erfolg, eine Ausnahme fliegt nicht weiter.
 */
final class InteraktivSenderTest extends TestCase
{
    protected function setUp(): void
    {
        // Log-Attrappe VOR clearResolvedInstances (reference_log_facade_test_stub.md).
        $c = new Container();
        $c->instance('log', new Logger(new NullLogger()));
        Container::setInstance($c);
        Facade::setFacadeApplication($c);
        Facade::clearResolvedInstances();
    }

    /** @return object{meta: WhatsAppMetaService, calls: \ArrayObject} */
    private function attrappe(?CommsWhatsAppMessage $antwort = null, ?\Throwable $wirft = null): object
    {
        $calls = new \ArrayObject();
        $antwort ??= $this->nachricht(11, 'sent');
        $meta = new class($calls, $antwort, $wirft) extends WhatsAppMetaService {
            public function __construct(private \ArrayObject $calls, private CommsWhatsAppMessage $antwort, private ?\Throwable $wirft) {}

            public function sendInteractive(CommsChannel $channel, string $to, array $interactive, string $displayBody, ?\Platform\Core\Models\User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage
            {
                $this->calls[] = compact('to', 'interactive', 'displayBody', 'isAutoReply', 'sender');
                if ($this->wirft) {
                    throw $this->wirft;
                }

                return $this->antwort;
            }
        };

        return (object) ['meta' => $meta, 'calls' => $calls];
    }

    private function nachricht(int $id, string $status, array $payload = []): CommsWhatsAppMessage
    {
        $m = new CommsWhatsAppMessage();
        $m->id = $id;
        $m->status = $status;
        $m->meta_payload = $payload;

        return $m;
    }

    public function test_knoepfe_bauen_das_meta_objekt_und_senden_als_auto_reply(): void
    {
        $a = $this->attrappe();
        $r = (new InteraktivSender($a->meta))->knoepfe(new CommsChannel(), '4915112345678', 'Worum geht es?', [
            ['id' => 'topic_dispo', 'title' => 'Disposition'],
            ['id' => 'topic_hr', 'title' => 'HR allgemein'],
        ]);

        $this->assertSame(['status' => 'sent', 'message_id' => 11, 'error' => null], $r);
        $this->assertCount(1, $a->calls);
        $this->assertSame([
            'type' => 'button',
            'body' => ['text' => 'Worum geht es?'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'topic_dispo', 'title' => 'Disposition']],
                ['type' => 'reply', 'reply' => ['id' => 'topic_hr', 'title' => 'HR allgemein']],
            ]],
        ], $a->calls[0]['interactive']);
        $this->assertSame('Worum geht es?', $a->calls[0]['displayBody']);
        $this->assertTrue($a->calls[0]['isAutoReply']);
    }

    public function test_vier_knoepfe_werfen_ohne_send(): void
    {
        $a = $this->attrappe();
        $k = array_fill(0, 4, ['id' => 'x', 'title' => 'x']);
        try {
            (new InteraktivSender($a->meta))->knoepfe(new CommsChannel(), '49', 'b', $k);
            $this->fail('Ausnahme erwartet');
        } catch (\InvalidArgumentException) {
        }
        $this->assertCount(0, $a->calls);
    }

    public function test_knopf_titel_ueber_20_wirft(): void
    {
        $a = $this->attrappe();
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new InteraktivSender($a->meta))->knoepfe(new CommsChannel(), '49', 'b', [['id' => 'x', 'title' => str_repeat('a', 21)]]);
        } finally {
            $this->assertCount(0, $a->calls);
        }
    }

    public function test_liste_baut_sections_und_begrenzt_zeilen_und_titel(): void
    {
        $a = $this->attrappe();
        $s = new InteraktivSender($a->meta);
        $s->liste(new CommsChannel(), '49', 'Welche?', 'Auswahl', [['id' => 'f1', 'title' => 'Düsseldorf'], ['id' => 'f2', 'title' => 'Köln']]);
        $this->assertSame([
            'type' => 'list',
            'body' => ['text' => 'Welche?'],
            'action' => ['button' => 'Auswahl', 'sections' => [['rows' => [
                ['id' => 'f1', 'title' => 'Düsseldorf'],
                ['id' => 'f2', 'title' => 'Köln'],
            ]]]],
        ], $a->calls[0]['interactive']);

        $elf = array_map(fn ($i) => ['id' => "f$i", 'title' => "F$i"], range(1, 11));
        $this->assertThrows(fn () => $s->liste(new CommsChannel(), '49', 'b', 'k', $elf));
        $this->assertThrows(fn () => $s->liste(new CommsChannel(), '49', 'b', 'k', [['id' => 'x', 'title' => str_repeat('a', 25)]]));
        $this->assertThrows(fn () => $s->liste(new CommsChannel(), '49', 'b', 'k', []));
        $this->assertCount(1, $a->calls, 'Die abgelehnten Aufrufe haben nichts gesendet.');
    }

    public function test_link_knopf_baut_cta_url(): void
    {
        $a = $this->attrappe();
        (new InteraktivSender($a->meta))->linkKnopf(new CommsChannel(), '49', 'Text', 'Schreiben', 'https://wa.me/49?text=Hi');
        $this->assertSame([
            'type' => 'cta_url',
            'body' => ['text' => 'Text'],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => 'Schreiben', 'url' => 'https://wa.me/49?text=Hi']],
        ], $a->calls[0]['interactive']);
        $this->assertThrows(fn () => (new InteraktivSender($a->meta))->linkKnopf(new CommsChannel(), '49', 'b', str_repeat('a', 21), 'https://x'));
    }

    /** 26.10.2026: „Unterlagen anfordern“ aus dem Chat — Knopf-Nachricht als HR-Antwort, nicht als Auto-Antwort. */
    public function test_link_knopf_als_hr_antwort(): void
    {
        $a = $this->attrappe();
        $hr = new \Platform\Core\Models\User();
        $s = new InteraktivSender($a->meta);

        $s->linkKnopf(new CommsChannel(), '49', 'Text', 'Zum Portal', 'https://x', $hr, false);
        $s->linkKnopf(new CommsChannel(), '49', 'Text', 'Zum Portal', 'https://x');

        $this->assertFalse($a->calls[0]['isAutoReply']);
        $this->assertSame($hr, $a->calls[0]['sender']);
        $this->assertTrue($a->calls[1]['isAutoReply'], 'Vorgabe bleibt Auto-Antwort (Themen-Auswahl, Parken)');
        $this->assertNull($a->calls[1]['sender']);
    }

    public function test_abgelehnter_versand_ist_failed_mit_meta_fehler(): void
    {
        $a = $this->attrappe($this->nachricht(12, 'failed', ['error' => ['message' => 'Template paused']]));
        $r = (new InteraktivSender($a->meta))->knoepfe(new CommsChannel(), '49', 'b', [['id' => 'x', 'title' => 'x']]);
        $this->assertSame(['status' => 'failed', 'message_id' => 12, 'error' => 'Template paused'], $r);
    }

    public function test_ausnahme_wird_zu_error_und_fliegt_nicht(): void
    {
        $a = $this->attrappe(null, new \RuntimeException('HTTP 500'));
        $r = (new InteraktivSender($a->meta))->knoepfe(new CommsChannel(), '4915112345678', 'b', [['id' => 'x', 'title' => 'x']]);
        $this->assertSame(['status' => 'error', 'message_id' => null, 'error' => 'HTTP 500'], $r);
    }

    public function test_empfaenger_liefert_nur_bei_eindeutigem_mitarbeiter_die_id(): void
    {
        $stub = fn (array $antwort) => new class($antwort) extends EmployeeSenderResolver {
            public function __construct(private array $antwort) {}

            public function resolve(string $senderPhone, int $teamId): array
            {
                return $this->antwort;
            }
        };

        $this->assertSame(7, (new ThemenAuswahlEmpfaenger($stub(['status' => 'employee', 'employee_id' => 7, 'employee_ids' => [7]])))->aktiverMitarbeiter('49', 1));
        $this->assertNull((new ThemenAuswahlEmpfaenger($stub(['status' => 'none', 'employee_id' => null, 'employee_ids' => []])))->aktiverMitarbeiter('49', 1));
        $this->assertNull((new ThemenAuswahlEmpfaenger($stub(['status' => 'ambiguous', 'employee_id' => null, 'employee_ids' => [7, 9]])))->aktiverMitarbeiter('49', 1));
    }

    private function assertThrows(callable $fn): void
    {
        try {
            $fn();
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('InvalidArgumentException erwartet');
    }
}
