<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\Events\Index;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Zwei Marken in der VA-Liste (Sheran 13.11.2026):
 *  - „N rausnehmen“: zur Loeschung gemeldet, in ZAS aber noch drin. Diese
 *    Einbuchungen zaehlen seit 03.09. nicht mehr bei „gesamt“ mit — die Liste
 *    zeigte „0 gesamt · 0 bestätigt“, obwohl die Dispo sie rausnehmen muss.
 *  - „N nicht angeschrieben“: in einer VA, in der schon versendet wurde, steht
 *    jemand ohne Bestaetigung (z. B. nach einem Personenwechsel in ZAS).
 */
class DispoEventsHandlungsbedarfTest extends DressTestCase
{
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
        $this->assignment($event, ['deletion_marked_at' => '2026-11-13 16:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-11-13 16:00:00']);
        // In ZAS schon raus (Haken „ZAS raus“) bzw. aus der Lieferung verschwunden: erledigt.
        $this->assignment($event, ['deletion_marked_at' => '2026-11-13 16:00:00', 'zas_removed_at' => '2026-11-13 17:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-11-13 16:00:00', 'missing_since' => '2026-11-13 18:00:00']);
        $this->assignment($event, ['confirmed_at' => '2026-11-12 10:00:00']);

        $this->assertSame(2, (int) $this->zeile($event)->rausnehmen_count);
    }

    public function test_rausnehmen_folgt_dem_datumsfilter(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-10-01', 'deletion_marked_at' => '2026-09-30 16:00:00']);
        $this->assignment($event, ['datum' => '2026-10-02', 'deletion_marked_at' => '2026-10-01 16:00:00']);

        $this->assertSame(1, (int) $this->zeile($event, '2026-10-01', '2026-10-01')->rausnehmen_count);
        $this->assertSame(2, (int) $this->zeile($event)->rausnehmen_count);
    }

    public function test_nicht_angeschrieben_zaehlt_offene_ohne_versand(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-11-12 19:34:00']);
        $this->assignment($event, ['reminder_sent_at' => '2026-11-12 19:34:00', 'confirmed_at' => '2026-11-12 20:00:00']);
        $this->assignment($event); // neu eingetauscht, noch nichts bekommen
        $this->assignment($event, ['confirmed_at' => '2026-11-12 21:00:00']); // von Hand bestaetigt
        $this->assignment($event, ['declined_at' => '2026-11-12 21:00:00']); // abgesagt
        $this->assignment($event, ['missing_since' => '2026-11-12 22:00:00']);
        $this->assignment($event, ['deletion_marked_at' => '2026-11-13 16:00:00']);

        $zeile = $this->zeile($event);

        $this->assertSame(1, (int) $zeile->nicht_angeschrieben_count);
        $this->assertTrue((bool) $zeile->versand_begonnen);
    }

    /** Ohne jeden Versand ist „nicht angeschrieben“ der Normalzustand — die Marke bleibt weg. */
    public function test_ohne_versand_ist_versand_begonnen_falsch(): void
    {
        $event = $this->event();
        $this->assignment($event);
        $this->assignment($event);

        $zeile = $this->zeile($event);

        $this->assertSame(2, (int) $zeile->nicht_angeschrieben_count);
        $this->assertFalse((bool) $zeile->versand_begonnen);
    }

    /**
     * Personenwechsel bei nur einer Einbuchung: die alte Besetzung liegt als
     * verschwundene Zeile ab und traegt den Versand-Stempel. Sie zaehlt nirgends
     * mit, belegt aber, dass in dieser VA schon versendet wurde.
     */
    public function test_abgelegte_alte_besetzung_belegt_den_versand(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-11-12 19:34:00', 'missing_since' => '2026-11-13 09:00:00']);
        $this->assignment($event);

        $zeile = $this->zeile($event);

        $this->assertSame(1, (int) $zeile->nicht_angeschrieben_count);
        $this->assertTrue((bool) $zeile->versand_begonnen);
    }

    public function test_versand_begonnen_folgt_dem_datumsfilter(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-10-01', 'reminder_sent_at' => '2026-09-28 10:00:00']);
        $this->assignment($event, ['datum' => '2026-10-02']);

        $zeile = $this->zeile($event, '2026-10-02', '2026-10-02');

        $this->assertSame(1, (int) $zeile->nicht_angeschrieben_count);
        $this->assertFalse((bool) $zeile->versand_begonnen, 'für den 02.10. ist noch nichts rausgegangen');
    }
}
