<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\Events\Show;

/**
 * Riegel im Sende-Fenster: wer ein Paket setzt, waehrend in ZAS noch Text
 * steht, muss ihn einmal gesehen haben. Rund ein Drittel dieser Texte traegt
 * Organisatorisches (Ansprechpartner, "Ausweis mitnehmen") — das darf nicht
 * stillschweigend verschwinden.
 */
class DispoDressSendFormTest extends DressTestCase
{
    public function test_no_package_chosen_means_no_gate(): void
    {
        $this->assertFalse(Show::dressNeedsAck([], 'Bitte folgende Kleidung: weisses Hemd', false));
        $this->assertFalse(Show::dressNeedsAck(['', ''], 'Bitte folgende Kleidung: weisses Hemd', false));
    }

    public function test_package_with_empty_zas_text_means_no_gate(): void
    {
        $this->assertFalse(Show::dressNeedsAck(['7'], '   ', false));
        $this->assertFalse(Show::dressNeedsAck(['7'], null, false));
    }

    public function test_package_with_zas_text_needs_acknowledgement(): void
    {
        $this->assertTrue(Show::dressNeedsAck(['7'], 'Ansprechpartner: Tristan anrufen', false));
        $this->assertFalse(Show::dressNeedsAck(['7'], 'Ansprechpartner: Tristan anrufen', true),
            'Einmal bestaetigt reicht.');
    }
}
