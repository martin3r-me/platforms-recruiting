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

    /** Der Schluessel haengt am Team des angemeldeten Nutzers — hier das, was die Komponente selbst bildet. */
    private function schluessel(): string
    {
        $schluessel = (string) $this->callPrivate($this->dispoComponent($this->event()->id), 'eskalationMerkerSchluessel');
        $this->assertStringStartsWith(DispoEskalationsMerker::SESSION_KEY . '.', $schluessel);

        return $schluessel;
    }

    private const MERKER = ['day' => 'vortag', 'date' => '', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'];

    public function test_angepasste_eskalation_wird_gemerkt_und_in_der_naechsten_va_nur_angeboten(): void
    {
        $erste = $this->dispoComponent($this->event()->id);
        $erste->escDay = 'vortag';
        $erste->escTime1 = '09:00';
        $erste->escTime2 = '10:00';
        $erste->escTime3 = '11:00';
        $this->callPrivate($erste, 'eskalationMerken');

        $this->assertSame(self::MERKER, $this->sitzung->daten[$this->schluessel()]);

        $zweite = $this->dispoComponent($this->event()->id);
        $this->callPrivate($zweite, 'loadEscalationForm');
        $this->callPrivate($zweite, 'eskalationAngebotLaden');

        // Review 13.11.: nie automatisch — das Formular bleibt der Stand der VA.
        $this->assertSame(['vortag', '', '', '', ''], $this->formular($zweite));
        $this->assertFalse($zweite->escUebernommen);
        $this->assertSame('Vortag · 09:00 / 10:00 / 11:00', $zweite->escAngebot['label']);

        // Erst der Klick legt sie ins Formular.
        $this->callPrivate($zweite, 'eskalationInsFormular');
        $this->assertSame(['vortag', '', '09:00', '10:00', '11:00'], $this->formular($zweite));
        $this->assertTrue($zweite->escUebernommen);
        $this->assertNull($zweite->escAngebot, 'das Angebot ist eingelöst');
    }

    /** Der Klick liest die Sitzung, nicht $escAngebot — das kaeme vom Client. */
    public function test_klick_uebernimmt_nur_was_in_der_sitzung_liegt(): void
    {
        $c = $this->dispoComponent($this->event()->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $c->escAngebot = ['day' => 'einsatztag', 'date' => '', 't1' => '06:00', 't2' => '06:10', 't3' => '06:20', 'label' => 'gefälscht'];

        $this->callPrivate($c, 'eskalationInsFormular');

        $this->assertSame(['vortag', '', '', '', ''], $this->formular($c), 'ohne Merker in der Sitzung ändert der Klick nichts');
        $this->assertFalse($c->escUebernommen);
    }

    public function test_va_mit_eigenem_plan_bekommt_kein_angebot(): void
    {
        $this->sitzung->daten[$this->schluessel()] = self::MERKER;
        $event = $this->event();
        RecDispoEvent::query()->whereKey($event->id)->update([
            'escalation_time_1' => '12:00', 'escalation_time_2' => '13:00', 'escalation_time_3' => '13:30',
        ]);

        $c = $this->dispoComponent($event->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationAngebotLaden');
        $this->assertNull($c->escAngebot);

        $this->callPrivate($c, 'eskalationInsFormular');
        $this->assertSame(['vortag', '', '12:00', '13:00', '13:30'], $this->formular($c));
        $this->assertFalse($c->escUebernommen);
    }

    public function test_standard_loescht_den_merker_und_reset_nimmt_den_hinweis_weg(): void
    {
        $this->sitzung->daten[$this->schluessel()] = ['day' => 'einsatztag', 'date' => '', 't1' => '', 't2' => '', 't3' => ''];

        $c = $this->dispoComponent($this->event()->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationInsFormular');
        $this->assertSame('einsatztag', $c->escDay);
        $this->assertTrue($c->escUebernommen);

        $this->callPrivate($c, 'eskalationAufStandard'); // resetEscalation() ohne Fehler-Tasche (braucht die View-Schicht)
        $this->assertSame(['vortag', '', '', '', ''], $this->formular($c));
        $this->assertFalse($c->escUebernommen);

        // Mit Standard gesendet: der Merker ist weg, die naechste VA bekommt kein Angebot mehr.
        $this->callPrivate($c, 'eskalationMerken');
        $this->assertArrayNotHasKey($this->schluessel(), $this->sitzung->daten);
    }

    public function test_ohne_merker_und_ohne_sitzung_gibt_es_kein_angebot_und_nichts_wirft(): void
    {
        $c = $this->dispoComponent($this->event()->id);
        $this->callPrivate($c, 'loadEscalationForm');
        $this->callPrivate($c, 'eskalationAngebotLaden');
        $this->assertNull($c->escAngebot);

        Container::getInstance()->forgetInstance('session');
        $c->escTime1 = '09:00';
        $c->escTime2 = '10:00';
        $c->escTime3 = '11:00';
        $this->callPrivate($c, 'eskalationMerken');
        $this->callPrivate($c, 'eskalationAngebotLaden');
        $this->callPrivate($c, 'eskalationInsFormular');
        $this->assertNull($c->escAngebot);
        $this->assertFalse($c->escUebernommen);
    }

    /** Verdrahtung: gemerkt wird nur nach erfolgreichem Speichern; beim Oeffnen wird nur angeboten. */
    public function test_verdrahtung_in_senden_speichern_und_oeffnen(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');

        $this->assertMatchesRegularExpression('/if \(!\$this->persistEscalation\(\)\) \{\s+return;\s+\}\s+\$this->eskalationMerken\(\);/', $quelle, 'beim Senden nach dem Speichern');
        $this->assertMatchesRegularExpression('/\$this->escSaved = \$this->persistEscalation\(\);\s+if \(\$this->escSaved\) \{\s+\$this->eskalationMerken\(\);/', $quelle, '„Nur Eskalation speichern“');
        $this->assertMatchesRegularExpression('/\$this->loadEscalationForm\(\);\s+\$this->eskalationAngebotLaden\(\);/', $quelle, 'beim Öffnen nur das Angebot');
        $this->assertSame(1, substr_count($quelle, '$this->eskalationInsFormular();'), 'ins Formular nur über den Klick');
        $this->assertMatchesRegularExpression('/function resetEscalation\(\): void\s+\{\s+\$this->eskalationAufStandard\(\);/', $quelle);

        $ansicht = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/show.blade.php');
        $this->assertStringContainsString('wire:click="eskalationUebernehmen"', $ansicht);
        $this->assertStringContainsString('alle noch Offenen', $ansicht, 'der Hinweis sagt, für wen die Eskalation dann gilt');
        $this->assertSame(2, substr_count($ansicht, 'NachnameZuerst::von($assignment->employee->first_name, $assignment->employee->last_name)'), 'Tabelle und Karte: Nachname, Vorname');
        $this->assertStringContainsString('$dispoRausnehmen', $ansicht);
        $this->assertStringContainsString("\$a->datum->format('Y-m-d') >= \$dispoHeute", $ansicht, 'im Kopf nur ab heute');
    }
}
