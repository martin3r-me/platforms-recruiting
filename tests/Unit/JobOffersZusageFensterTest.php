<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\JobOffers;

/**
 * Spec 2026-11-20 Punkt 1 (Markus 19.11.2026): „Ich bin dabei“ oeffnet nur das
 * Fenster — gespeichert wird erst mit „OK“ (zusagen). Ohne Datenbank: oeffnen
 * und abbrechen fassen nichts an.
 */
final class JobOffersZusageFensterTest extends TestCase
{
    public function test_erster_klick_oeffnet_nur_das_fenster(): void
    {
        $c = new JobOffers();
        $this->assertNull($c->zusageOffen);

        $c->zusageFragen(55);
        $this->assertSame(55, $c->zusageOffen);
        $this->assertSame('', $c->hinweis);

        $c->zusageAbbrechen();
        $this->assertNull($c->zusageOffen);
    }

    public function test_ohne_person_schliesst_ok_das_fenster_ohne_zu_speichern(): void
    {
        $c = new JobOffers();
        $c->zusageFragen(55);
        $c->zusagen(55); // employeeId null → kein Zugriff auf die Datenbank
        $this->assertNull($c->zusageOffen);
        $this->assertSame('', $c->hinweis);
    }

    public function test_seite_speichert_nur_ueber_ok_im_fenster(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/job-offers.blade.php');
        $this->assertSame(1, substr_count($view, 'wire:click="zusagen('), 'nur der OK-Knopf speichert');
        $this->assertSame(3, substr_count($view, 'wire:click="zusageFragen('), 'alle drei „dabei“-Knoepfe fragen erst');
        $this->assertStringContainsString('Mit der Zusage wirst du verbindlich für diesen Einsatz gebucht.', $view);
        $this->assertStringContainsString('Du bist für diese Schicht ausgetragen', $view);
    }
}
