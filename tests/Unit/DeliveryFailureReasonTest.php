<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\DeliveryFailureReason;

/** Meta-Fehler -> Klartext fuer den Reiter "Nicht zugestellt". */
final class DeliveryFailureReasonTest extends TestCase
{
    public function test_webhook_fehlerliste(): void
    {
        $r = DeliveryFailureReason::fromPayload(['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]);

        $this->assertSame(131026, $r->code);
        $this->assertSame(DeliveryFailureReason::WHO_PERSON, $r->who);
        $this->assertStringContainsString('anrufen', $r->hint());
    }

    public function test_sofortige_ablehnung_als_einzelnes_objekt(): void
    {
        $r = DeliveryFailureReason::fromPayload(['error' => ['code' => 132001, 'message' => 'Template name does not exist']]);

        $this->assertSame(132001, $r->code);
        $this->assertSame(DeliveryFailureReason::WHO_SETUP, $r->who);
    }

    public function test_code_als_string(): void
    {
        $this->assertSame(131026, DeliveryFailureReason::fromPayload(['errors' => [['code' => '131026']]])->code);
    }

    public function test_unbekannter_code_zeigt_metas_text(): void
    {
        $r = DeliveryFailureReason::fromPayload(['errors' => [['code' => 999999, 'title' => 'Something odd']]]);

        $this->assertSame(999999, $r->code);
        $this->assertSame('Meta: Something odd', $r->text);
        $this->assertSame(DeliveryFailureReason::WHO_OTHER, $r->who);
    }

    public function test_erfolgreiche_antwort_ist_kein_fehler(): void
    {
        $this->assertNull(DeliveryFailureReason::fromPayload(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.x']]]));
        $this->assertNull(DeliveryFailureReason::fromPayload(null));
        $this->assertNull(DeliveryFailureReason::fromPayload(['errors' => []]));
    }
}
