<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\Events\Index;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Spalte „Bestätigung versendet“ in der VA-Liste (Markus 12.11.2026): letzter
 * Versand und Anzahl, im selben Zaehl-Rahmen wie „gesamt/bestätigt“.
 */
class DispoEventsVersandSpalteTest extends DressTestCase
{
    private function zeile(RecDispoEvent $event, string $from = '', string $to = ''): RecDispoEvent
    {
        $c = new Index();
        $c->dateFrom = $from;
        $c->dateTo = $to;
        $m = new \ReflectionMethod($c, 'mitVersandStand');
        $m->setAccessible(true);

        return $m->invoke($c, RecDispoEvent::query()->whereKey($event->id))->firstOrFail();
    }

    public function test_zeigt_den_letzten_versand_und_die_anzahl(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-11-10 09:15:00']);
        $this->assignment($event, ['reminder_sent_at' => '2026-11-11 14:32:00']);
        $this->assignment($event);

        $zeile = $this->zeile($event);

        $this->assertSame('2026-11-11 14:32:00', (string) $zeile->reminder_last_sent_at);
        $this->assertSame(2, (int) $zeile->reminder_sent_count);
    }

    public function test_ohne_versand_bleibt_die_spalte_leer(): void
    {
        $event = $this->event();
        $this->assignment($event);

        $zeile = $this->zeile($event);

        $this->assertNull($zeile->reminder_last_sent_at);
        $this->assertSame(0, (int) $zeile->reminder_sent_count);
    }

    /** Verschwundene und zur Loeschung gemeldete zaehlen wie bei „gesamt“ nicht mit. */
    public function test_verschwundene_und_geloeschte_zaehlen_nicht(): void
    {
        $event = $this->event();
        $this->assignment($event, ['reminder_sent_at' => '2026-11-10 09:15:00']);
        $this->assignment($event, ['reminder_sent_at' => '2026-11-12 08:00:00', 'missing_since' => '2026-11-12 09:00:00']);
        $this->assignment($event, ['reminder_sent_at' => '2026-11-12 08:30:00', 'deletion_marked_at' => '2026-11-12 09:00:00']);

        $zeile = $this->zeile($event);

        $this->assertSame('2026-11-10 09:15:00', (string) $zeile->reminder_last_sent_at);
        $this->assertSame(1, (int) $zeile->reminder_sent_count);
    }

    /** Mit Datumsfilter zaehlen nur die Tage im Zeitraum — wie die anderen Zahlen der Zeile. */
    public function test_datumsfilter_grenzt_auch_den_versand_ein(): void
    {
        $event = $this->event();
        $this->assignment($event, ['datum' => '2026-10-01', 'reminder_sent_at' => '2026-09-28 10:00:00']);
        $this->assignment($event, ['datum' => '2026-10-02', 'reminder_sent_at' => '2026-09-29 11:00:00']);

        $zeile = $this->zeile($event, '2026-10-01', '2026-10-01');

        $this->assertSame('2026-09-28 10:00:00', (string) $zeile->reminder_last_sent_at);
        $this->assertSame(1, (int) $zeile->reminder_sent_count);
    }
}
