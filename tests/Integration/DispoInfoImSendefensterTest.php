<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Sheran 15.11.2026: die Mitarbeiter-Auswahl fuer Ticket/Hinweis steht direkt
 * im Fenster „Bestätigungen senden“ (kein zweites Fenster mehr). Die Auswahl
 * gilt NUR fuer die Infos — wer die Bestaetigung bekommt, bleibt unberuehrt.
 */
class DispoInfoImSendefensterTest extends DressTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Hinweis-Spalten der Einbuchung: ohne sie waere „Abgewaehlte bleiben
        // unberuehrt“ ein gruenes Nichts (SQLite-Spaltenluecke).
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }
        // event() laedt die Mitarbeiter der Einbuchungen mit — leere Tabelle reicht,
        // die Kontakte liefert die Gateway-Attrappe in setUp().
        $schema = Container::getInstance()->make('db.schema');
        if (!$schema->hasTable('rec_employees')) {
            $schema->create('rec_employees', function ($t) {
                $t->id();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        }

        // addError()/resetErrorBag() brauchen die View-Schicht und einen
        // gemeinsamen DataStore — gleiches Muster wie DispoDressSendFormTest.
        Container::getInstance()->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });
        Container::getInstance()->singleton(\Livewire\Mechanisms\DataStore::class);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('view');
        Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');

        // Kontakte ohne rec_employees-Tabelle: Name = „MA <id>“, alle mit Nummer.
        Container::getInstance()->instance(DispoEmployeeGateway::class, new class extends DispoEmployeeGateway {
            public function contacts(array $employeeIds): array
            {
                $out = [];
                foreach ($employeeIds as $id) {
                    $out[(int) $id] = ['name' => 'MA ' . $id, 'first_name' => 'MA', 'phone' => '+4915100000' . $id, 'portal_token' => 'tok-' . $id];
                }

                return $out;
            }

            public function qualifications(array $employeeIds): array
            {
                return ['byEmployee' => [], 'labels' => []];
            }
        });
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(DispoEmployeeGateway::class);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<int> kanonische ids der Angehakten */
    private function angehakt(object $c): array
    {
        return array_values(array_map(
            fn ($p) => $p['canonical'],
            array_filter($c->infoPreview['persons'], fn ($p) => $p['selected'])
        ));
    }

    public function test_keine_dann_eine_person_und_alle_wieder_zurueck(): void
    {
        $event = $this->event();
        $a = $this->assignment($event);
        $b = $this->assignment($event);
        $this->assignment($event);

        $c = $this->dispoComponent($event->id);
        $this->assertCount(3, $this->angehakt($c), 'Ausgangslage: alle angehakt');

        $c->infoKeine();
        $this->assertSame([], $this->angehakt($c));

        $c->toggleInfoPerson((int) $b->rec_employee_id);
        $this->assertSame([(int) $b->rec_employee_id], $this->angehakt($c), 'nur die eine Person');
        $this->assertNotContains((int) $a->rec_employee_id, $this->angehakt($c));

        $c->infoAlle();
        $this->assertCount(3, $this->angehakt($c));
    }

    public function test_aufklappen_und_zuklappen_verwirft_die_eingaben(): void
    {
        $event = $this->event();
        $a = $this->assignment($event);

        $c = $this->dispoComponent($event->id);
        $this->assertFalse($c->infoImSenden);
        $this->assertFalse($this->callPrivate($c, 'infoStehtAn'));

        $c->infoImSendenUmschalten();
        $this->assertTrue($c->infoImSenden);
        $this->assertFalse($this->callPrivate($c, 'infoStehtAn'), 'aufgeklappt, aber leer: nichts steht an');

        $c->infoNote = 'Ticket liegt am Eingang Nord';
        $c->toggleInfoPerson((int) $a->rec_employee_id);
        $this->assertTrue($this->callPrivate($c, 'infoStehtAn'));

        $c->infoImSendenUmschalten();
        $this->assertFalse($c->infoImSenden);
        $this->assertSame('', $c->infoNote, 'Zuklappen verwirft — sonst ginge Unsichtbares mit');
        $this->assertSame([], $c->infoExcluded);
        $this->assertFalse($this->callPrivate($c, 'infoStehtAn'));
    }

    /** Eingaben bei zugeklapptem Kasten (z. B. Reste aus „Info an Crew“) gehen nie mit der Bestaetigung mit. */
    public function test_zugeklappt_steht_nie_etwas_an(): void
    {
        $c = $this->dispoComponent($this->event()->id);
        $c->infoNote = 'Rest aus einem anderen Fenster';

        $this->assertFalse($this->callPrivate($c, 'infoStehtAn'));
    }

    public function test_hinterlegen_trifft_nur_die_angehakten(): void
    {
        $event = $this->event();
        $a = $this->assignment($event);
        $b = $this->assignment($event, ['individual_note' => '15 Min früher']);
        $x = $this->assignment($event);

        $c = $this->dispoComponent($event->id);
        $c->infoImSendenUmschalten();
        $c->infoKeine();
        $c->toggleInfoPerson((int) $a->rec_employee_id);
        $c->toggleInfoPerson((int) $b->rec_employee_id);

        $personen = array_values(array_filter($c->infoPreview['persons'], fn ($p) => $p['selected']));
        $ergebnis = $this->callPrivate($c, 'infoHinterlegen', [$personen, 'Ticket am Eingang Nord']);

        $this->assertSame(['attached' => 0, 'noted' => 2], $ergebnis);
        $this->assertSame('Ticket am Eingang Nord', RecDispoAssignment::find($a->id)->individual_note);
        $this->assertSame("15 Min früher\nTicket am Eingang Nord", RecDispoAssignment::find($b->id)->individual_note, 'bestehender Hinweis wird ergaenzt');
        $this->assertNull(RecDispoAssignment::find($x->id)->individual_note, 'Abgewaehlte bleiben unberuehrt');

        // Zweiter Lauf (z. B. Versand gescheitert, erneut geklickt): nichts doppelt.
        $nochmal = $this->callPrivate($c, 'infoHinterlegen', [$personen, 'Ticket am Eingang Nord']);
        $this->assertSame(0, $nochmal['noted']);
    }

    public function test_info_an_crew_von_der_seite_bleibt_wie_bisher(): void
    {
        $event = $this->event();
        $this->assignment($event);

        $c = $this->dispoComponent($event->id);
        $c->infoImSenden = true;
        $c->openInfoModal();

        $this->assertTrue($c->showInfoModal);
        $this->assertTrue((bool) $c->infoSendWhatsApp, 'von der VA-Seite: WhatsApp wie bisher vorbelegt');
        $this->assertFalse($c->infoImSenden);

        $c->closeInfoModal();
        $this->assertFalse($c->showInfoModal);
        $this->assertFalse($c->showSendModal, 'kein Sprung ins Bestaetigungs-Fenster');
    }

    /**
     * Vertrag an der Quelle (der volle Versand braucht Vorlagen, Kanal und Meta —
     * in dieser Suite nicht zu booten): geprueft wird vor allem Speichern,
     * hinterlegt wird vor dem Versand, und die Empfaenger der Bestaetigung
     * kommen ausschliesslich aus sendPreview — nie aus der Info-Auswahl.
     */
    public function test_reihenfolge_und_trennung_im_versand(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');
        $a = strpos($src, 'private function doSendConfirmations(): void');
        $methode = substr($src, $a, strpos($src, 'public function render()', $a) - $a);

        $pruefen = strpos($methode, '$this->infoStehtAn()');
        $eskalation = strpos($methode, '$this->persistEscalation()');
        $hinterlegen = strpos($methode, '$this->infoHinterlegen($infoPersonen');
        $senden = strpos($methode, '->send($event, $preview[\'recipients\'], $templateId, $escDue)');

        $this->assertNotFalse($pruefen);
        $this->assertNotFalse($hinterlegen);
        $this->assertNotFalse($senden);
        $this->assertLessThan($eskalation, $pruefen, 'Infos pruefen, bevor irgendetwas gespeichert wird');
        $this->assertLessThan($senden, $hinterlegen, 'erst hinterlegen, dann senden');
        $this->assertSame(1, substr_count($methode, '->send('), 'genau ein Versand');
        $this->assertStringNotContainsString('infoSendWhatsApp', $methode, 'aus dem Bestaetigungs-Fenster nie die WhatsApp „Neue Infos“');

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/show.blade.php');
        $this->assertSame(2, substr_count($blade, "@include('recruiting::livewire.dispo.events._info-felder')"), 'gleiche Felder in beiden Fenstern');
        $this->assertStringContainsString('nur für Datei und Hinweis', $blade);
    }
}
