<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Livewire\Dispo\Events\Index;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Zwei Marken in der VA-Liste (Sheran 13.11.2026):
 *  - „N rausnehmen“: zur Loeschung gemeldet, in ZAS aber noch drin. Diese
 *    Einbuchungen zaehlen seit 03.09. nicht mehr bei „gesamt“ mit — die Liste
 *    zeigte „0 gesamt · 0 bestätigt“, obwohl die Dispo sie rausnehmen muss.
 *  - „N nicht angeschrieben“: an einem Tag, fuer den schon versendet wurde,
 *    steht jemand ohne Bestaetigung (z. B. nach einem Personenwechsel in ZAS).
 * Beide nur ab heute. „Heute“ ist im Test der 30.09.2026; die Einbuchungen der
 * Basisklasse liegen am 01.10.2026.
 */
class DispoEventsHandlungsbedarfTest extends DressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function zeile(RecDispoEvent $event, string $from = '', string $to = ''): RecDispoEvent
    {
        $c = new Index();
        $c->dateFrom = $from;
        $c->dateTo = $to;
        $m = new \ReflectionMethod($c, 'mitHandlungsbedarf');
        $m->setAccessible(true);

        return $m->invoke($c, RecDispoEvent::query()->whereKey($event->id))->firstOrFail();
    }

    public function test_rausnehmen_zaehlt_gemeldete_die_in_zas_noch_drin_sind(): void
    {
        $event = $this->event();
        $this->assignment($event, ['deletion_marked_at' => '2026-09-30 16:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-09-30 16:00:00']);
        // In ZAS schon raus (Haken „ZAS raus“) bzw. aus der Lieferung verschwunden: erledigt.
        $this->assignment($event, ['deletion_marked_at' => '2026-09-30 16:00:00', 'zas_removed_at' => '2026-09-30 17:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-09-30 16:00:00', 'missing_since' => '2026-09-30 18:00:00']);
        $this->assignment($event, ['confirmed_at' => '2026-09-29 10:00:00']);

        $this->assertSame(2, (int) $this->zeile($event)->rausnehmen_count);
    }

    public function test_rausnehmen_folgt_dem_datumsfilter_und_laesst_vergangene_tage_weg(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-10-01', 'deletion_marked_at' => '2026-09-30 16:00:00']);
        $this->assignment($event, ['datum' => '2026-10-02', 'deletion_marked_at' => '2026-09-30 16:00:00']);
        // Gestern: verschwindet aus keiner Lieferung mehr — die Marke bliebe sonst fuer immer.
        $this->assignment($event, ['datum' => '2026-09-29', 'deletion_marked_at' => '2026-09-28 16:00:00']);

        $this->assertSame(1, (int) $this->zeile($event, '2026-10-01', '2026-10-01')->rausnehmen_count);
        $this->assertSame(2, (int) $this->zeile($event)->rausnehmen_count);
    }

    public function test_nicht_angeschrieben_zaehlt_offene_ohne_versand(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-09-29 19:34:00']);
        $this->assignment($event, ['reminder_sent_at' => '2026-09-29 19:34:00', 'confirmed_at' => '2026-09-29 20:00:00']);
        $this->assignment($event); // neu eingetauscht, noch nichts bekommen
        $this->assignment($event, ['rec_employee_id' => null]); // ohne Zuordnung: auch ein Fall fuer die Dispo
        $this->assignment($event, ['confirmed_at' => '2026-09-29 21:00:00']); // von Hand bestaetigt
        $this->assignment($event, ['declined_at' => '2026-09-29 21:00:00']); // abgesagt
        $this->assignment($event, ['missing_since' => '2026-09-29 22:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-09-30 16:00:00']);
        $this->assignment($event, ['status_id' => RecDispoAssignment::STATUS_ANGEBOT]); // wird nie angeschrieben
        $this->assignment($event, ['status_id' => RecDispoAssignment::STATUS_STORNO]);

        $this->assertSame(2, (int) $this->zeile($event)->nicht_angeschrieben_count);
    }

    /** Ohne jeden Versand ist „nicht angeschrieben“ der Normalzustand — die Marke bleibt weg. */
    public function test_ohne_versand_keine_marke(): void
    {
        $event = $this->event();
        $this->assignment($event);
        $this->assignment($event);

        $this->assertSame(0, (int) $this->zeile($event)->nicht_angeschrieben_count);
    }

    /**
     * Personenwechsel bei nur einer Einbuchung: die alte Besetzung liegt als
     * verschwundene Zeile ab und traegt den Versand-Stempel. Sie zaehlt nirgends
     * mit, belegt aber, dass fuer diesen Tag schon versendet wurde.
     */
    public function test_abgelegte_alte_besetzung_belegt_den_versand(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-09-29 19:34:00', 'missing_since' => '2026-09-30 09:00:00']);
        $this->assignment($event);

        $this->assertSame(1, (int) $this->zeile($event)->nicht_angeschrieben_count);
    }

    /** Mehrtaeger, tageweise verschickt: nur der Tag mit Versand zaehlt — auch ohne Datumsfilter (Review 13.11.). */
    public function test_versand_gilt_je_tag_nicht_je_veranstaltung(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-10-01', 'reminder_sent_at' => '2026-09-28 10:00:00']);
        $this->assignment($event, ['datum' => '2026-10-01']); // nachgerueckt am Tag mit Versand
        $this->assignment($event, ['datum' => '2026-10-02']); // fuer den 02.10. ist noch nichts rausgegangen
        $this->assignment($event, ['datum' => '2026-10-02']);

        $this->assertSame(1, (int) $this->zeile($event)->nicht_angeschrieben_count);
        $this->assertSame(0, (int) $this->zeile($event, '2026-10-02', '2026-10-02')->nicht_angeschrieben_count);
    }

    public function test_vergangene_tage_zaehlen_nicht(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-09-29', 'reminder_sent_at' => '2026-09-27 10:00:00']);
        $this->assignment($event, ['datum' => '2026-09-29']);

        $this->assertSame(0, (int) $this->zeile($event)->nicht_angeschrieben_count);
    }
}
