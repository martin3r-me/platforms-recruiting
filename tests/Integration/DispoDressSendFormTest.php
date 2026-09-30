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
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Fix-Runde 3, Befund 2: persistDress() prueft die eingereichte
        // Paket-ID gegen die waehlbare Menge (Show::dressPackages()), und die
        // haengt an settingsTeamId(). Ohne diesen Key faellt settingsTeamId()
        // auf auth()->user() zurueck — in der Capsule-Suite ein Fehler.
        // NUR hier gesetzt, nicht in DressTestCase: der Key schaltet
        // gleichzeitig DispoIdentityResolver scharf (crm_contact_links).
        \Illuminate\Container\Container::getInstance()->instance(
            'config',
            new \Illuminate\Config\Repository(['recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]]])
        );

        // Livewire::getErrorBag() holt sich die zuvor geteilten Fehler ueber
        // app('view') — ohne gebootetes View-System waere jedes addError()
        // in saveDress() eine BindingResolutionException. Attrappe mit dem
        // einen Aufruf, den der Pfad braucht.
        \Illuminate\Container\Container::getInstance()->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });

        // Der Fehler-Beutel liegt in Livewires DataStore. Ohne
        // Singleton-Bindung erzeugt jeder app(DataStore::class)-Aufruf eine
        // neue, leere Instanz — set() und get() traefen sich nie (gleiches
        // Muster wie die EventBus-Bindung in DressTestCase).
        \Illuminate\Container\Container::getInstance()->singleton(\Livewire\Mechanisms\DataStore::class);
    }

    public static function tearDownAfterClass(): void
    {
        \Illuminate\Container\Container::getInstance()->forgetInstance('view');
        \Illuminate\Container\Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        parent::tearDownAfterClass();
    }

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

        // In der echten Komponente stammt der Schnappschuss aus loadDressForm();
        // hier direkt gesetzt, um die Indizes im Test kontrolliert vorzugeben.
        $c->dressTaetigkeitenSnapshot = $order;
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
        $c->dressTaetigkeitenSnapshot = ['Service'];
        $c->dressAll = (string) $pkg->id;
        $c->dressByTaetigkeit = [(string) $pkg->id];
        $this->callPrivate($c, 'persistDress', [$event]);
        $this->assertCount(2, RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get());

        // Jetzt nur noch "Alle uebrigen" leeren — "Service" bleibt unberuehrt.
        $c2 = $this->dispoComponent($event->id);
        $c2->dressTaetigkeitenSnapshot = ['Service'];
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

    /**
     * Fix-Runde 2: Reihenfolge-Drift der Index-Bindung. eventTaetigkeiten()
     * ist #[Computed] und wird bei jedem Livewire-Roundtrip (= jeder
     * Hydration, also jeder eigenen Show-Instanz) frisch berechnet — kommt
     * zwischen Oeffnen (loadDressForm, Request 1 / Instanz $cOpen) und
     * Senden (persistDress, Request 2 / Instanz $cSend, mit den von
     * Livewire "rehydrierten" oeffentlichen Properties) eine ZAS-Lieferung
     * herein, die eine neue, alphabetisch VORN einsortierte Taetigkeit
     * bringt, verschiebt sich die sortierte Liste und Index 0 zeigt danach
     * auf eine andere Taetigkeit als beim Oeffnen. Ohne Schnappschuss
     * wuerde persistDress() das fuer "Service" gewaehlte Paket dann
     * lautlos an "Empfang" haengen.
     */
    public function test_persist_dress_survives_taetigkeit_drift_between_open_and_send(): void
    {
        $event = $this->event(['dresscode' => 'Testtext']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $pkg = $this->package('Weiss', 'Weisses Hemd');

        // Request 1: Fenster oeffnen.
        $cOpen = $this->dispoComponent($event->id);
        $this->callPrivate($cOpen, 'loadDressForm');
        $this->assertSame(['Service'], $cOpen->dressTaetigkeitenSnapshot, 'Testannahme: beim Oeffnen gibt es nur "Service".');

        // Dispo waehlt fuer "Service" (Index 0 im Schnappschuss) ein Paket.
        $cOpen->dressByTaetigkeit[0] = (string) $pkg->id;

        // Waehrenddessen: eine ZAS-Lieferung bringt eine neue Einbuchung mit
        // einer Taetigkeit, die alphabetisch VOR "Service" einsortiert
        // ("Empfang" < "Service") — eine frische Berechnung liefert jetzt
        // ['Empfang', 'Service'], Index 0 verschiebt sich also.
        $this->assignment($event, ['taetigkeit' => 'Empfang', 'rec_employee_id' => null]);

        // Request 2: Senden. Eine NEUE Show-Instanz (wie nach einer echten
        // Livewire-Rehydration), oeffentliche Properties wie von Livewire
        // aus dem Request-Payload restauriert — aber eventTaetigkeiten()
        // wird in DIESER Instanz zum ersten Mal berechnet, also frisch.
        $cSend = $this->dispoComponent($event->id);
        $cSend->dressAll = $cOpen->dressAll;
        $cSend->dressByTaetigkeit = $cOpen->dressByTaetigkeit;
        $cSend->dressTaetigkeitenSnapshot = $cOpen->dressTaetigkeitenSnapshot;

        $this->assertSame(
            ['Empfang', 'Service'],
            $cSend->eventTaetigkeiten,
            'Testannahme: die frische Berechnung hat sich tatsaechlich verschoben — sonst zeigt der Test das Falsche.'
        );

        $this->callPrivate($cSend, 'persistDress', [$event]);

        $rows = RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get()->keyBy('taetigkeit');
        $this->assertSame(
            (int) $pkg->id,
            (int) ($rows['Service']->rec_dispo_dress_package_id ?? null),
            'Das fuer "Service" gewaehlte Paket muss an "Service" landen, nicht an der neu dazugekommenen "Empfang".'
        );
        $this->assertArrayNotHasKey('Empfang', $rows->all(), '"Empfang" kam erst NACH der Auswahl hinzu — die Dispo hat dafuer nie ein Paket gewaehlt.');
    }

    /**
     * Fix-Runde 3, Befund 1: die Paketauswahl muss OHNE Versand speicherbar
     * sein. Der Senden-Knopf ist deaktiviert, sobald die VA durchbestaetigt
     * ist (DispoRecipientPlanner wirft bestaetigte und bereits angeschriebene
     * Einbuchungen aus der Empfaengermenge) — ohne eigenen Knopf waere die
     * Auswahl bei jeder Nachbesserung unerreichbar.
     */
    public function test_save_dress_persists_selection_hinweis_and_ack_without_sending(): void
    {
        $event = $this->event(['dresscode' => 'Ansprechpartner: Tristan anrufen']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $pkg = $this->package('Weiss', 'Weisses Hemd');

        $c = $this->dispoComponent($event->id);
        $this->callPrivate($c, 'loadDressForm');
        $c->dressAll = (string) $pkg->id;
        $c->eventHinweis = 'Bitte Ausweis mitbringen';
        $c->dressAck = true;

        $c->saveDress();

        $this->assertTrue($c->dressSaved, 'Sichtbare Bestaetigung wie beim Eskalations-Knopf.');
        $rows = RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get()->keyBy('taetigkeit');
        $this->assertSame((int) $pkg->id, (int) $rows[RecDispoEventDress::ALL]->rec_dispo_dress_package_id);

        $event->refresh();
        $this->assertSame('Bitte Ausweis mitbringen', $event->hinweis);
        $this->assertSame('Ansprechpartner: Tristan anrufen', $event->dresscode_ack);
        $this->assertNotNull($event->dresscode_ack_at);
    }

    /** Fix-Runde 3, Befund 1: der Riegel greift auch im Speichern-Weg. */
    public function test_save_dress_is_blocked_by_the_ack_gate(): void
    {
        $event = $this->event(['dresscode' => 'Ansprechpartner: Tristan anrufen']);
        $this->assignment($event, ['taetigkeit' => 'Service', 'rec_employee_id' => null]);
        $pkg = $this->package('Weiss', 'Weisses Hemd');

        $c = $this->dispoComponent($event->id);
        $this->callPrivate($c, 'loadDressForm');
        $c->dressAll = (string) $pkg->id;
        $c->dressAck = false;

        $c->saveDress();

        $this->assertFalse($c->dressSaved);
        $this->assertTrue($c->getErrorBag()->has('dressAck'), 'Ohne "Text gesehen" wird nicht gespeichert.');
        $this->assertCount(0, RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)->get(),
            'Nichts darf durchrutschen, solange der Riegel zu ist.');
    }
}
