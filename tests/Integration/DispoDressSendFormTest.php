<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\Events\Show;
use Platform\Recruiting\Models\RecDispoEventDress;

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

    /**
     * Fix-Runde 1, Befund 3 (Pflicht 1): persistDress() gegen die echte DB.
     * "2.OG" als Taetigkeit deckt gleichzeitig Befund 1 ab (Livewire zerlegt
     * wire:model-Pfade am Punkt) — hier laeuft der Wert nur noch als
     * gewoehnlicher Spaltenwert durch, nie mehr als Pfad.
     */
    public function test_persist_dress_writes_one_row_per_taetigkeit_and_all_without_collision(): void
    {
        $event = $this->event(['dresscode' => 'Testtext']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $this->assignment($event, ['taetigkeit' => '2.OG', 'rec_employee_id' => null]);
        $pkgAll = $this->package('Weiss', 'Weisses Hemd');
        $pkgService = $this->package('Schwarz', 'Schwarze Hose');

        $c = $this->dispoComponent($event->id);
        $order = $c->eventTaetigkeiten; // tatsaechliche Sortierung, nicht angenommen
        $this->assertSame(['2.OG', 'Service'], $order, 'Testannahme zur Sortierung — sonst zeigt der Test das Falsche.');

        $c->dressAll = (string) $pkgAll->id;
        // Index 0 = "2.OG" bleibt leer (erbt von "Alle uebrigen"), Index 1 = "Service" bekommt ein eigenes Paket.
        $c->dressByTaetigkeit = ['', (string) $pkgService->id];

        $this->callPrivate($c, 'persistDress', [$event]);

        $rows = RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get()
            ->keyBy('taetigkeit');

        $this->assertCount(2, $rows, 'Genau zwei Zeilen: ALL-Sentinel + "Service". "2.OG" bleibt ohne eigene Zeile.');
        $this->assertSame((int) $pkgAll->id, (int) $rows[RecDispoEventDress::ALL]->rec_dispo_dress_package_id);
        $this->assertSame((int) $pkgService->id, (int) $rows['Service']->rec_dispo_dress_package_id);
        $this->assertArrayNotHasKey('2.OG', $rows->all(), 'Der Punkt in der Taetigkeit darf keine eigene/verschachtelte Zeile erzeugen.');
    }

    /** Fix-Runde 1, Befund 3 (Pflicht 1): Leersetzen entfernt NUR die eine Zeile. */
    public function test_persist_dress_clearing_removes_only_that_row(): void
    {
        $event = $this->event(['dresscode' => 'Testtext']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $pkg = $this->package('Weiss', 'Weisses Hemd');

        $c = $this->dispoComponent($event->id);
        $c->dressAll = (string) $pkg->id;
        $c->dressByTaetigkeit = [(string) $pkg->id];
        $this->callPrivate($c, 'persistDress', [$event]);
        $this->assertCount(2, RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get());

        // Jetzt nur noch "Alle uebrigen" leeren — "Service" bleibt unberuehrt.
        $c2 = $this->dispoComponent($event->id);
        $c2->dressAll = '';
        $c2->dressByTaetigkeit = [(string) $pkg->id];
        $this->callPrivate($c2, 'persistDress', [$event]);

        $rows = RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get()->keyBy('taetigkeit');
        $this->assertCount(1, $rows, 'Nur die ALL-Zeile wurde geloescht.');
        $this->assertArrayNotHasKey(RecDispoEventDress::ALL, $rows->all());
        $this->assertSame((int) $pkg->id, (int) $rows['Service']->rec_dispo_dress_package_id);
    }

    /** Fix-Runde 1, Befund 3 (Pflicht 2): loadDressForm() holt bestehende Zuordnungen zurueck. */
    public function test_load_dress_form_restores_existing_assignments(): void
    {
        $event = $this->event(['dresscode' => 'Testtext', 'hinweis' => 'Bitte Ausweis mitbringen']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $this->assignment($event, ['taetigkeit' => '2.OG', 'rec_employee_id' => null]);
        $pkgAll = $this->package('Weiss', 'Weisses Hemd');
        $pkgService = $this->package('Schwarz', 'Schwarze Hose');

        RecDispoEventDress::create(['rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL, 'rec_dispo_dress_package_id' => $pkgAll->id]);
        RecDispoEventDress::create(['rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service', 'rec_dispo_dress_package_id' => $pkgService->id]);

        $c = $this->dispoComponent($event->id);
        $order = $c->eventTaetigkeiten;
        $this->callPrivate($c, 'loadDressForm');

        $this->assertSame((string) $pkgAll->id, $c->dressAll);
        $this->assertSame((string) $pkgService->id, $c->dressByTaetigkeit[array_search('Service', $order, true)]);
        $this->assertSame('', $c->dressByTaetigkeit[array_search('2.OG', $order, true)], '"2.OG" hat keine eigene Zeile, bleibt leer.');
        $this->assertSame('Bitte Ausweis mitbringen', $c->eventHinweis);
    }

    /** Fix-Runde 1, Befund 3 (Pflicht 2): Ack gilt nur, solange ZAS den Text nicht geaendert hat. */
    public function test_load_dress_form_ack_expires_when_zas_text_changes(): void
    {
        $unchanged = $this->event([
            'dresscode'        => 'Text A',
            'dresscode_ack'    => 'Text A',
            'dresscode_ack_at' => now(),
        ]);
        $c = $this->dispoComponent($unchanged->id);
        $this->callPrivate($c, 'loadDressForm');
        $this->assertTrue($c->dressAck, 'ZAS-Text unveraendert -> Bestaetigung zaehlt noch.');

        $changed = $this->event([
            'dresscode'        => 'Text B (neu von ZAS)',
            'dresscode_ack'    => 'Text A',
            'dresscode_ack_at' => now(),
        ]);
        $c2 = $this->dispoComponent($changed->id);
        $this->callPrivate($c2, 'loadDressForm');
        $this->assertFalse($c2->dressAck, 'ZAS hat den Text geaendert -> Riegel ist wieder scharf.');
    }
}
