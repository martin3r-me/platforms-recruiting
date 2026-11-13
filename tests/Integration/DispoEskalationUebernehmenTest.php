<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoEskalationsMerker;

/**
 * Sheran 13.11.2026: die zuletzt von Hand gewaehlte Eskalation ist im
 * Sende-Fenster der naechsten Veranstaltung vorbelegt — aber nur, wenn die VA
 * keinen eigenen Plan hat, und nie unbemerkt.
 */
class DispoEskalationUebernehmenTest extends DressTestCase
{
    private object $sitzung;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = new class {
            public array $daten = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->daten[$key] ?? $default;
            }

            public function put(array|string $key, mixed $value = null): void
            {
                foreach (is_array($key) ? $key : [$key => $value] as $k => $v) {
                    $this->daten[$k] = $v;
                }
            }

            public function forget(string $key): void
            {
                unset($this->daten[$key]);
            }
        };
        Container::getInstance()->instance('session', $this->sitzung);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('session');
        parent::tearDown();
    }

    private function formular(object $c): array
    {
        return [$c->escDay, $c->escDate, $c->escTime1, $c->escTime2, $c->escTime3];
    }

    public function test_angepasste_eskalation_wird_gemerkt_und_in_der_naechsten_va_vorbelegt(): void
    {
        $erste = $this->dispoComponent($this->event()->id);
        $erste->escDay = 'vortag';
        $erste->escTime1 = '09:00';
        $erste->escTime2 = '10:00';
        $erste->escTime3 = '11:00';
        $this->callPrivate($erste, 'eskalationMerken');

        $this->assertSame(
            ['day' => 'vortag', 'date' => '', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'],
            $this->sitzung->daten[DispoEskalationsMerker::SESSION_KEY]
        );

        $zweite = $this->dispoComponent($this->event()->id);
        $this->callPrivate($zweite, 'loadEscalationForm');
        $this->callPrivate($zweite, 'eskalationUebernehmen');

        $this->assertSame(['vortag', '', '09:00', '10:00', '11:00'], $this->formular($zweite));
        $this->assertTrue($zweite->escUebernommen);
    }

    public function test_va_mit_eigenem_plan_behaelt_ihn(): void
    {
        $this->sitzung->daten[DispoEskalationsMerker::SESSION_KEY] = ['day' => 'vortag', 'date' => '', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'];
        $event = $this->event();
        RecDispoEvent::query()->whereKey($event->id)->update([
            'escalation_time_1' => '12:00', 'escalation_time_2' => '13:00', 'escalation_time_3' => '13:30',
        ]);

        $c = $this->dispoComponent($event->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationUebernehmen');

        $this->assertSame(['vortag', '', '12:00', '13:00', '13:30'], $this->formular($c));
        $this->assertFalse($c->escUebernommen);
    }

    public function test_standard_loescht_den_merker_und_reset_nimmt_den_hinweis_weg(): void
    {
        $this->sitzung->daten[DispoEskalationsMerker::SESSION_KEY] = ['day' => 'einsatztag', 'date' => '', 't1' => '', 't2' => '', 't3' => ''];

        $c = $this->dispoComponent($this->event()->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationUebernehmen');
        $this->assertSame('einsatztag', $c->escDay);
        $this->assertTrue($c->escUebernommen);

        $this->callPrivate($c, 'eskalationAufStandard'); // resetEscalation() ohne Fehler-Tasche (braucht die View-Schicht)
        $this->assertSame(['vortag', '', '', '', ''], $this->formular($c));
        $this->assertFalse($c->escUebernommen);

        // Mit Standard gesendet: der Merker ist weg, die naechste VA startet wieder mit Standard.
        $this->callPrivate($c, 'eskalationMerken');
        $this->assertArrayNotHasKey(DispoEskalationsMerker::SESSION_KEY, $this->sitzung->daten);
    }

    public function test_ohne_merker_und_ohne_sitzung_bleibt_das_formular_wie_die_va(): void
    {
        $c = $this->dispoComponent($this->event()->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationUebernehmen');
        $this->assertSame(['vortag', '', '', '', ''], $this->formular($c));
        $this->assertFalse($c->escUebernommen);

        Container::getInstance()->forgetInstance('session');
        $c->escTime1 = '09:00';
        $c->escTime2 = '10:00';
        $c->escTime3 = '11:00';
        $this->callPrivate($c, 'eskalationMerken'); // darf nicht werfen
        $this->callPrivate($c, 'eskalationUebernehmen');
        $this->assertFalse($c->escUebernommen);
    }

    /** Verdrahtung: gemerkt wird nur nach erfolgreichem Speichern, vorbelegt beim Oeffnen des Fensters. */
    public function test_verdrahtung_in_senden_speichern_und_oeffnen(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');

        $this->assertMatchesRegularExpression('/if \(!\$this->persistEscalation\(\)\) \{\s+return;\s+\}\s+\$this->eskalationMerken\(\);/', $quelle, 'beim Senden nach dem Speichern');
        $this->assertMatchesRegularExpression('/\$this->escSaved = \$this->persistEscalation\(\);\s+if \(\$this->escSaved\) \{\s+\$this->eskalationMerken\(\);/', $quelle, '„Nur Eskalation speichern“');
        $this->assertMatchesRegularExpression('/\$this->loadEscalationForm\(\);\s+\$this->eskalationUebernehmen\(\);/', $quelle, 'beim Öffnen des Sende-Fensters');

        $ansicht = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/show.blade.php');
        $this->assertStringContainsString('Übernommen von deiner letzten Sendung', $ansicht);
        $this->assertStringContainsString("\$errors->has('escTime1') || \$escUebernommen", $ansicht, 'der Abschnitt ist dann aufgeklappt');
        $this->assertSame(2, substr_count($ansicht, 'NachnameZuerst::von($assignment->employee->first_name, $assignment->employee->last_name)'), 'Tabelle und Karte: Nachname, Vorname');
        $this->assertStringContainsString('$dispoRausnehmen', $ansicht);
        $this->assertMatchesRegularExpression('/function resetEscalation\(\): void\s+\{\s+\$this->eskalationAufStandard\(\);/', $quelle);
    }
}
