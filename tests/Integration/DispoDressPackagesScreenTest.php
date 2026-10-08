<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\DressPackages;
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Chip-Editor + aufgewertete Liste (10/2026): items_text bleibt in der DB ein
 * Semikolon-Text, die Chips sind reine Bedienung (siehe Klassenkommentar
 * DressPackages). Testaufbau wie DispoDressSendFormTest: 'view'-Attrappe +
 * Livewire-DataStore als Singleton fuer addError()/getErrorBag(), Team-Anker
 * ueber den Config-Key, weil teamId() sonst auf auth()->user() zurueckfaellt.
 */
class DispoDressPackagesScreenTest extends DressTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        \Illuminate\Container\Container::getInstance()->instance(
            'config',
            new \Illuminate\Config\Repository(['recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]]])
        );

        \Illuminate\Container\Container::getInstance()->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });

        \Illuminate\Container\Container::getInstance()->singleton(\Livewire\Mechanisms\DataStore::class);
    }

    public static function tearDownAfterClass(): void
    {
        \Illuminate\Container\Container::getInstance()->forgetInstance('view');
        \Illuminate\Container\Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        parent::tearDownAfterClass();
    }

    /**
     * Baut eine DressPackages-Komponente OHNE Livewire-Mount/Request-Zyklus
     * und verdrahtet die #[Computed]-Properties von Hand — gleiches Muster
     * wie DressTestCase::dispoComponent(), hier fuer diese Komponentenklasse.
     */
    private function component(): DressPackages
    {
        $c = new DressPackages();
        ComputedWiring::wire($c);

        return $c;
    }

    // --- addItem() ----------------------------------------------------

    public function test_add_item_appends_trimmed_value(): void
    {
        $c = $this->component();
        $c->newItem = '  weißes Hemd  ';

        $c->addItem();

        $this->assertSame(['weißes Hemd'], $c->items);
        $this->assertSame('', $c->newItem, 'Eingabefeld wird nach dem Hinzufuegen geleert.');
    }

    public function test_add_item_ignores_empty_input(): void
    {
        $c = $this->component();
        $c->newItem = '   ';

        $c->addItem();

        $this->assertSame([], $c->items);
        $this->assertFalse($c->getErrorBag()->has('newItem'), 'Leere Eingabe ist kein Fehler, nur ein No-op.');
    }

    public function test_add_item_rejects_a_part_containing_a_semicolon(): void
    {
        $c = $this->component();
        $c->newItem = 'Hemd; Hose';

        $c->addItem();

        $this->assertSame([], $c->items, 'Darf nicht uebernommen werden.');
        $this->assertTrue($c->getErrorBag()->has('newItem'));
    }

    public function test_add_item_rejects_case_insensitive_duplicate(): void
    {
        $c = $this->component();
        $c->items = ['Weißes Hemd'];
        $c->newItem = 'weißes hemd'; // gleiche Woerter, andere Gross-/Kleinschreibung

        $c->addItem();

        $this->assertSame(['Weißes Hemd'], $c->items, 'Gross-/Kleinschreibungs-Dublette wird NICHT uebernommen.');
        $this->assertTrue($c->getErrorBag()->has('newItem'));
    }

    // --- addSuggestion() ---------------------------------------------------

    /** Reviewer-Nachtrag: addSuggestion() ist ein duenner Wrapper um addItem() — genau das hier belegen. */
    public function test_add_suggestion_takes_over_the_value_as_a_chip(): void
    {
        $c = $this->component();

        $c->addSuggestion('Schuhe');

        $this->assertSame(['Schuhe'], $c->items);
        $this->assertSame('', $c->newItem, 'Derselbe Weg wie addItem() — Eingabefeld wird geleert.');
    }

    /** Reviewer-Nachtrag: die Dubletten-Abwehr aus addItem() muss auch ueber addSuggestion() greifen. */
    public function test_add_suggestion_is_rejected_when_already_present_case_insensitively(): void
    {
        $c = $this->component();
        $c->items = ['Weißes Hemd'];

        $c->addSuggestion('weißes hemd');

        $this->assertSame(['Weißes Hemd'], $c->items, 'Vorschlag darf keine Dublette durchlassen.');
        $this->assertTrue($c->getErrorBag()->has('newItem'));
    }

    // --- removeItem() ---------------------------------------------------

    public function test_remove_item(): void
    {
        $c = $this->component();
        $c->items = ['A', 'B', 'C'];

        $c->removeItem(1);

        $this->assertSame(['A', 'C'], $c->items, 'Liste bleibt lueckenlos indiziert.');
    }

    public function test_remove_item_with_unknown_index_is_a_noop(): void
    {
        $c = $this->component();
        $c->items = ['A', 'B'];

        $c->removeItem(5);

        $this->assertSame(['A', 'B'], $c->items);
    }

    // --- moveItem() -------------------------------------------------------

    public function test_move_item_swaps_with_neighbor(): void
    {
        $c = $this->component();
        $c->items = ['A', 'B', 'C'];

        $c->moveItem(1, -1);
        $this->assertSame(['B', 'A', 'C'], $c->items);

        $c->moveItem(1, 1);
        $this->assertSame(['B', 'C', 'A'], $c->items);
    }

    public function test_move_item_is_a_noop_at_the_edges(): void
    {
        $c = $this->component();
        $c->items = ['A', 'B', 'C'];

        $c->moveItem(0, -1);
        $this->assertSame(['A', 'B', 'C'], $c->items, 'Erstes Element kann nicht weiter nach oben.');

        $c->moveItem(2, 1);
        $this->assertSame(['A', 'B', 'C'], $c->items, 'Letztes Element kann nicht weiter nach unten.');
    }

    // --- itemSuggestions() ------------------------------------------------

    public function test_item_suggestions_come_from_other_packages_of_the_team_excluding_items_already_added(): void
    {
        $this->package('Paket A', 'Hemd; Hose');
        $this->package('Paket B', 'Hemd; Schuhe');

        $c = $this->component();
        $c->items = ['Hemd'];

        $this->assertSame(['Hose', 'Schuhe'], $c->itemSuggestions, 'Bereits enthaltenes "Hemd" fehlt, Rest alphabetisch.');
    }

    public function test_item_suggestions_are_filtered_by_the_current_input(): void
    {
        $this->package('Paket A', 'Hemd; Hose');
        $this->package('Paket B', 'Schuhe');

        $c = $this->component();
        $c->newItem = 'sch';

        $this->assertSame(['Schuhe'], $c->itemSuggestions);
    }

    public function test_item_suggestions_are_capped_at_eight_and_sorted_alphabetically(): void
    {
        $this->package('Viele Teile', 'j; i; h; g; f; e; d; c; b; a');

        $c = $this->component();

        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], $c->itemSuggestions);
    }

    // --- Roundtrip edit() -> $items -> save() -> items_text ---------------

    public function test_edit_then_save_roundtrips_items_text(): void
    {
        $package = $this->package('Bestehend', 'Hemd; Hose');

        $c = $this->component();
        $c->edit($package->id);

        $this->assertSame(['Hemd', 'Hose'], $c->items);
        $this->assertSame($package->id, $c->editingId);

        $c->removeItem(1); // Hose raus
        $c->newItem = 'Schuhe';
        $c->addItem();

        $c->save();

        $this->assertTrue($c->saved);
        $this->assertSame('', $c->name, 'Formular wird nach dem Speichern geleert.');
        $package->refresh();
        $this->assertSame('Hemd; Schuhe', $package->items_text);
    }

    public function test_save_creates_a_new_package_with_joined_items_text(): void
    {
        $c = $this->component();
        $c->name = 'Neues Paket';
        $c->items = ['Hemd', 'Hose', 'Schuhe'];

        $c->save();

        $created = RecDispoDressPackage::query()->where('name', 'Neues Paket')->firstOrFail();
        $this->assertSame('Hemd; Hose; Schuhe', $created->items_text);
    }

    public function test_save_without_any_item_is_blocked(): void
    {
        $c = $this->component();
        $c->name = 'Leer';
        $c->items = [];

        $c->save();

        $this->assertFalse($c->saved);
        $this->assertTrue($c->getErrorBag()->has('items'));
        $this->assertNull(RecDispoDressPackage::query()->where('name', 'Leer')->first());
    }

    /**
     * Reviewer-Nachtrag: die alte itemsText-Grenze (max:2000) war ersatzlos
     * weggefallen. Die Grenze liegt bewusst auf dem ZUSAMMENGESETZTEN Text,
     * nicht auf der Zahl der Chips — ein einzelner sehr langer Teil reicht
     * schon, um sie zu reissen.
     */
    public function test_save_blocks_when_the_joined_items_text_exceeds_2000_characters(): void
    {
        $c = $this->component();
        $c->name = 'Zu lang';
        $c->items = [str_repeat('A', 2001)];

        $c->save();

        $this->assertFalse($c->saved);
        $this->assertTrue($c->getErrorBag()->has('items'));
        $this->assertNull(RecDispoDressPackage::query()->where('name', 'Zu lang')->first());
    }

    public function test_save_allows_exactly_2000_characters(): void
    {
        $c = $this->component();
        $c->name = 'Genau am Limit';
        $c->items = [str_repeat('A', 2000)];

        $c->save();

        $this->assertTrue($c->saved);
    }

    // --- duplicate() --------------------------------------------------

    public function test_duplicate_fills_the_form_without_saving(): void
    {
        $package = $this->package('Original', 'Hemd; Hose');
        $countBefore = RecDispoDressPackage::query()->count();

        $c = $this->component();
        $c->duplicate($package->id);

        $this->assertSame('Original (Kopie)', $c->name);
        $this->assertSame(['Hemd', 'Hose'], $c->items);
        $this->assertNull($c->editingId, 'Duplizieren ist ein NEUES Paket, kein Bearbeiten.');
        $this->assertSame($countBefore, RecDispoDressPackage::query()->count(), 'Es wird noch nichts gespeichert.');
    }

    // --- usageCounts() --------------------------------------------------

    public function test_usage_counts_count_distinct_events_per_package(): void
    {
        $used = $this->package('Benutzt', 'Hemd');
        $unused = $this->package('Unbenutzt', 'Hose');
        $eventA = $this->event();
        $eventB = $this->event();

        RecDispoEventDress::create(['rec_dispo_event_id' => $eventA->id, 'taetigkeit' => RecDispoEventDress::ALL, 'rec_dispo_dress_package_id' => $used->id]);
        RecDispoEventDress::create(['rec_dispo_event_id' => $eventB->id, 'taetigkeit' => RecDispoEventDress::ALL, 'rec_dispo_dress_package_id' => $used->id]);
        // Zweite Zeile derselben Veranstaltung darf nicht doppelt zaehlen (distinct event_id).
        RecDispoEventDress::create(['rec_dispo_event_id' => $eventB->id, 'taetigkeit' => 'Service', 'rec_dispo_dress_package_id' => $used->id]);

        $c = $this->component();

        $this->assertSame(2, $c->usageCounts[$used->id]);
        $this->assertArrayNotHasKey($unused->id, $c->usageCounts, 'Unbenutztes Paket taucht gar nicht erst auf.');
    }

    public function test_request_retire_asks_first_when_in_use_and_direct_when_not(): void
    {
        $used = $this->package('Benutzt', 'Hemd');
        $event = $this->event();
        RecDispoEventDress::create(['rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL, 'rec_dispo_dress_package_id' => $used->id]);
        $unused = $this->package('Unbenutzt', 'Hose');

        $c = $this->component();
        $c->requestRetire($used->id);
        $this->assertTrue($used->fresh()->is_active, 'Noch nicht ausgemustert, erst die Rueckfrage.');
        $this->assertTrue($c->confirmingRetire[$used->id] ?? false);

        $c->confirmRetire($used->id);
        $this->assertFalse($used->fresh()->is_active);
        $this->assertArrayNotHasKey($used->id, $c->confirmingRetire);

        $c2 = $this->component();
        $c2->requestRetire($unused->id);
        $this->assertFalse($unused->fresh()->is_active, 'Ohne Verwendung direkt ausgemustert, keine Rueckfrage noetig.');
        $this->assertArrayNotHasKey($unused->id, $c2->confirmingRetire);
    }

    // --- moveUp()/moveDown() inkl. Gleichstand bei sort_order ------------

    public function test_move_up_and_down_swap_neighbors_in_the_current_order(): void
    {
        // $this->package() laesst sort_order auf dem DB-Default (0) — alle drei
        // stehen also im Gleichstand, Sekundaersortierung ist der Name.
        $alpha = $this->package('Alpha', 'x');
        $beta = $this->package('Beta', 'x');
        $gamma = $this->package('Gamma', 'x');

        $c = $this->component();
        $names = fn () => RecDispoDressPackage::query()->where('team_id', self::TEAM)
            ->orderBy('sort_order')->orderBy('name')->pluck('name')->all();

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $names(), 'Testannahme: Gleichstand faellt auf Name zurueck.');

        $c->moveUp($beta->id);
        $this->assertSame(['Beta', 'Alpha', 'Gamma'], $names(), 'Tausch muss trotz identischem sort_order wirken.');

        $c->moveDown($gamma->id);
        $this->assertSame(['Beta', 'Alpha', 'Gamma'], $names(), 'Letztes Element: moveDown ist ein No-op.');

        $c->moveUp($beta->id);
        $this->assertSame(['Beta', 'Alpha', 'Gamma'], $names(), 'Erstes Element: moveUp ist ein No-op.');
    }
}
