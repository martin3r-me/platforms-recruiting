<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\QuickReply;

/**
 * Ein Klick auf einen vorgefertigten Button ist keine Arbeit fuer HR
 * (Spec 16.10.2026). Der Core speichert beides als 'text' — erkennbar ist
 * der Klick nur am Original-Typ im meta_payload.
 */
class QuickReplyTest extends TestCase
{
    public function test_vorlagen_button_ist_quick_reply(): void
    {
        $this->assertTrue(QuickReply::isQuickReply([
            'type' => 'button',
            'button' => ['text' => 'Ja', 'payload' => 'Ja'],
        ]));
    }

    public function test_interaktiver_button_ist_quick_reply(): void
    {
        $this->assertTrue(QuickReply::isQuickReply([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => '1', 'title' => 'Ja']],
        ]));
    }

    public function test_listen_auswahl_ist_quick_reply(): void
    {
        $this->assertTrue(QuickReply::isQuickReply([
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => '1', 'title' => 'Montag']],
        ]));
    }

    public function test_getippter_text_ist_keiner(): void
    {
        $this->assertFalse(QuickReply::isQuickReply([
            'type' => 'text',
            'text' => ['body' => 'Ja bin dabei'],
        ]));
    }

    public function test_formular_antwort_ist_keiner(): void
    {
        // WhatsApp-Flows (nfm_reply) tragen frei ausgefuellte Inhalte.
        $this->assertFalse(QuickReply::isQuickReply([
            'type' => 'interactive',
            'interactive' => ['type' => 'nfm_reply'],
        ]));
    }

    public function test_ohne_payload_ist_keiner(): void
    {
        $this->assertFalse(QuickReply::isQuickReply(null));
        $this->assertFalse(QuickReply::isQuickReply([]));
    }
    public function test_auswahl_id_aus_knopf_und_liste(): void
    {
        $this->assertSame('topic_lohn', QuickReply::auswahlId([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'topic_lohn', 'title' => 'Lohn & Gehalt']],
        ]));
        $this->assertSame('dispo_7', QuickReply::auswahlId([
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'dispo_7', 'title' => 'Dispo Köln']],
        ]));
        // Vorlagen-Knopf traegt keine Auswahl-ID, getippter Text erst recht nicht.
        $this->assertNull(QuickReply::auswahlId(['type' => 'button', 'button' => ['text' => 'Ja', 'payload' => 'topic_hr']]));
        $this->assertNull(QuickReply::auswahlId(['type' => 'text', 'text' => ['body' => 'topic_hr']]));
        $this->assertNull(QuickReply::auswahlId(null));
    }

    public function test_themen_klick_nur_fuer_unsere_ids(): void
    {
        foreach (['topic_dispo', 'topic_lohn', 'topic_hr', 'topic_ack', 'dispo_12'] as $id) {
            $this->assertTrue(QuickReply::istThemenKlick([
                'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']],
            ]), $id);
        }
        $this->assertTrue(QuickReply::istThemenKlick([
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'dispo_3', 'title' => 'x']],
        ]));
        foreach (['1', 'ja', 'xtopic_hr', ''] as $id) {
            $this->assertFalse(QuickReply::istThemenKlick([
                'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']],
            ]), $id);
        }
        $this->assertFalse(QuickReply::istThemenKlick(['type' => 'button', 'button' => ['text' => 'Ja']]));
        $this->assertFalse(QuickReply::istThemenKlick(null));
    }
}
