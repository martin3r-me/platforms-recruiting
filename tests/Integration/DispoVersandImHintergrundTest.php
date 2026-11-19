<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Platform\Recruiting\Jobs\SendDispoConfirmations;
use Platform\Recruiting\Livewire\Dispo\Events\Show;

/**
 * Bestaetigungs-Versand im Hintergrund (Vorfall 19.11.2026): der Klick legt den
 * Versand in die Warteschlange, je Veranstaltung laeuft hoechstens einer, und das
 * Fenster liest den Fortschritt aus dem Cache. Die Versandlogik selbst und der
 * Auftrag sind in DispoConfirmationSenderChannelTest abgedeckt.
 */
class DispoVersandImHintergrundTest extends DressTestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Repository(new ArrayStore());
        Cache::clearResolvedInstances();
        Container::getInstance()->instance('cache', $this->cache);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('cache');
        Cache::clearResolvedInstances();
        parent::tearDownAfterClass();
    }

    /** Seite, die den Auftrag nur einsammelt statt ihn in eine Warteschlange zu legen. */
    private function seite(int $eventId, bool $wirft = false): Show
    {
        $c = new class extends Show {
            /** @var list<SendDispoConfirmations> */
            public array $gestartet = [];
            public bool $wirft = false;

            protected function versandStarten(SendDispoConfirmations $job): void
            {
                if ($this->wirft) {
                    throw new \RuntimeException('Warteschlange nicht erreichbar');
                }
                $this->gestartet[] = $job;
            }
        };
        $c->eventId = $eventId;
        $c->wirft = $wirft;
        ComputedWiring::wire($c);

        return $c;
    }

    /** @return list<array<string, mixed>> */
    private function empfaenger(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['employee_id' => $i, 'phone' => '+4915100000' . $i, 'assignment_ids' => [$i], 'first_datum' => '2026-10-01', 'is_reminder' => false];
        }

        return $out;
    }

    public function test_klick_legt_genau_einen_auftrag_an_und_zeigt_den_fortschritt(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $due = [1 => new \DateTimeImmutable('2026-09-30 10:00:00'), 2 => new \DateTimeImmutable('2026-09-30 12:00:00'), 3 => new \DateTimeImmutable('2026-09-30 14:00:00')];

        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(3), 55, $due]);

        $this->assertCount(1, $c->gestartet);
        $job = $c->gestartet[0];
        $this->assertSame($event->id, $job->eventId);
        $this->assertSame(55, $job->templateId);
        $this->assertCount(3, $job->recipients);
        $this->assertSame([1 => '2026-09-30 10:00:00', 2 => '2026-09-30 12:00:00', 3 => '2026-09-30 14:00:00'], $job->escalationDue);
        $this->assertSame($job->uuid, $c->sendJobUuid);
        $this->assertNull($c->sendResult, 'Noch kein Ergebnis — das Fenster zeigt den Fortschritt.');
        $this->assertSame(['total' => 3, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'started' => false, 'wartet_lange' => false], $c->sendProgress);
        $this->assertEqualsWithDelta(time(), strtotime($job->geplantAm), 5, 'Planungszeitpunkt = jetzt.');
        $this->assertSame($job->uuid, $this->cache->get(SendDispoConfirmations::letzterKey($event->id)));
        $this->assertSame($job->uuid, $this->cache->get(SendDispoConfirmations::laufKey($event->id)));
        $this->assertSame(3, $this->cache->get(SendDispoConfirmations::cacheKey($job->uuid))['total']);
    }

    public function test_zweiter_klick_waehrend_des_laufs_startet_nichts_und_zeigt_den_laufenden_versand(): void
    {
        $event = $this->event();
        $erste = $this->seite($event->id);
        $this->callPrivate($erste, 'versandImHintergrund', [$this->empfaenger(2), 55, null]);

        // Andere Person, gleiche Veranstaltung.
        $zweite = $this->seite($event->id);
        $this->callPrivate($zweite, 'versandImHintergrund', [$this->empfaenger(2), 55, null]);

        $this->assertSame([], $zweite->gestartet, 'Solange ein Versand laeuft, startet kein zweiter.');
        $this->assertSame($erste->sendJobUuid, $zweite->sendJobUuid, 'Die zweite Person sieht den laufenden Versand.');
        $this->assertTrue($zweite->sendLiefSchon, 'Sie erfaehrt, dass ihr Klick nichts gestartet hat.');
        $this->assertFalse($erste->sendLiefSchon);
    }

    public function test_andere_veranstaltung_ist_nicht_gesperrt(): void
    {
        $a = $this->event();
        $b = $this->event();
        $this->callPrivate($this->seite($a->id), 'versandImHintergrund', [$this->empfaenger(1), 55, null]);

        $andere = $this->seite($b->id);
        $this->callPrivate($andere, 'versandImHintergrund', [$this->empfaenger(1), 55, null]);

        $this->assertCount(1, $andere->gestartet);
    }

    public function test_scheitert_das_einreihen_ist_die_veranstaltung_wieder_frei(): void
    {
        \Illuminate\Support\Facades\Log::clearResolvedInstances();
        Container::getInstance()->instance('log', new class {
            public function error($message, array $context = []): void
            {
            }
        });
        Container::getInstance()->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });
        Container::getInstance()->singleton(\Livewire\Mechanisms\DataStore::class);

        try {
            $event = $this->event();
            $c = $this->seite($event->id, true);
            $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(1), 55, null]);

            $this->assertNull($c->sendJobUuid);
            $this->assertNull($this->cache->get(SendDispoConfirmations::laufKey($event->id)), 'Sonst waere die Veranstaltung 35 Minuten gesperrt.');
            $this->assertTrue($c->getErrorBag()->has('vorlaufMinuten'));
        } finally {
            Container::getInstance()->forgetInstance('log');
            Container::getInstance()->forgetInstance('view');
            Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
            \Illuminate\Support\Facades\Log::clearResolvedInstances();
        }
    }

    public function test_fortschritt_wird_gelesen_und_am_ende_zum_ergebnis(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(4), 55, null]);
        $key = SendDispoConfirmations::cacheKey($c->sendJobUuid);

        $this->cache->put($key, ['total' => 4, 'sent' => 2, 'skipped' => 1, 'failed' => [], 'started' => true, 'done' => false, 'message' => null, 'beat' => time()], 600);
        $c->sendFortschritt();
        $this->assertSame(['total' => 4, 'sent' => 2, 'skipped' => 1, 'failed' => 0, 'started' => true, 'wartet_lange' => false], $c->sendProgress);
        $this->assertNull($c->sendResult);
        $this->assertNotNull($c->sendJobUuid);

        $this->cache->put($key, ['total' => 4, 'sent' => 2, 'skipped' => 1, 'failed' => [['employee_id' => 4, 'error' => 'Meta sagt nein']], 'started' => true, 'done' => true, 'message' => null], 600);
        $c->sendFortschritt();
        $this->assertSame(['sent' => 2, 'skipped' => 1, 'message' => null, 'failed' => [['employee_id' => 4, 'error' => 'Meta sagt nein']]], $c->sendResult);
        $this->assertNull($c->sendJobUuid, 'Fertig: das Fenster hoert auf nachzufragen.');
        $this->assertNull($c->sendProgress);
        $this->assertNull($this->cache->get(SendDispoConfirmations::letzterKey($event->id)), 'Gesehen: wird beim naechsten Oeffnen nicht wiederholt.');
    }

    public function test_lauf_ohne_lebenszeichen_wird_als_abgebrochen_geschlossen_und_freigegeben(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(4), 55, null]);
        $uuid = $c->sendJobUuid;
        $this->cache->put(SendDispoConfirmations::cacheKey($uuid), ['total' => 4, 'sent' => 1, 'skipped' => 0, 'failed' => [], 'started' => true, 'done' => false, 'message' => null,
            'beat' => time() - SendDispoConfirmations::LEBENSZEICHEN_SEKUNDEN - 5], 600);

        $c->sendFortschritt();

        $this->assertSame(SendDispoConfirmations::ABGEBROCHEN, $c->sendResult['message']);
        $this->assertSame(1, $c->sendResult['sent']);
        $this->assertNull($this->cache->get(SendDispoConfirmations::laufKey($event->id)), 'Die Veranstaltung ist wieder frei.');
        $this->assertTrue($this->cache->get(SendDispoConfirmations::cacheKey($uuid))['done']);
    }

    public function test_wartender_versand_wird_nie_als_abgebrochen_gewertet_meldet_aber_die_lange_wartezeit(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(2), 55, null]);
        $p = SendDispoConfirmations::initialProgress(2);
        $p['beat'] = time() - 3600;
        $this->cache->put(SendDispoConfirmations::cacheKey($c->sendJobUuid), $p, 600);

        $c->sendFortschritt();

        $this->assertNull($c->sendResult);
        $this->assertNotNull($c->sendJobUuid, 'In der Warteschlange heisst nicht tot — sonst startete jemand einen zweiten Versand.');
        $this->assertTrue($c->sendProgress['wartet_lange']);
    }

    public function test_ergebnis_mit_fehlern_kommt_auch_nach_geschlossenem_fenster_an_genau_einmal(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(2), 55, null]);
        $uuid = $c->sendJobUuid;
        // Fenster zu; der Auftrag endet und gibt die Veranstaltung frei.
        $this->cache->put(SendDispoConfirmations::cacheKey($uuid), ['total' => 2, 'sent' => 1, 'skipped' => 0, 'failed' => [['employee_id' => 2, 'error' => 'nein']], 'started' => true, 'done' => true, 'message' => null, 'beat' => time()], 600);
        $this->cache->forget(SendDispoConfirmations::laufKey($event->id));

        $spaeter = $this->seite($event->id);
        $this->callPrivate($spaeter, 'letztesErgebnisAufnehmen');
        $this->assertSame([['employee_id' => 2, 'error' => 'nein']], $spaeter->sendResult['failed']);

        $nochmal = $this->seite($event->id);
        $this->callPrivate($nochmal, 'letztesErgebnisAufnehmen');
        $this->assertNull($nochmal->sendResult, 'Nur einmal — danach wieder das normale Fenster.');
    }

    public function test_glatt_durchgelaufener_versand_wird_nicht_nachgereicht(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(2), 55, null]);
        $this->cache->put(SendDispoConfirmations::cacheKey($c->sendJobUuid), ['total' => 2, 'sent' => 2, 'skipped' => 0, 'failed' => [], 'started' => true, 'done' => true, 'message' => null, 'beat' => time()], 600);

        $spaeter = $this->seite($event->id);
        $this->callPrivate($spaeter, 'letztesErgebnisAufnehmen');

        $this->assertNull($spaeter->sendResult);
    }

    public function test_verlorener_stand_laesst_das_fenster_nicht_endlos_warten(): void
    {
        $event = $this->event();
        $c = $this->seite($event->id);
        $this->callPrivate($c, 'versandImHintergrund', [$this->empfaenger(1), 55, null]);
        $this->cache->forget(SendDispoConfirmations::cacheKey($c->sendJobUuid));

        $c->sendFortschritt();

        $this->assertNull($c->sendJobUuid);
        $this->assertNull($c->sendResult);
    }

    public function test_fenster_nimmt_einen_laufenden_versand_beim_oeffnen_auf(): void
    {
        $event = $this->event();
        $this->callPrivate($this->seite($event->id), 'versandImHintergrund', [$this->empfaenger(5), 55, null]);

        $neu = $this->seite($event->id);
        $this->assertTrue($this->callPrivate($neu, 'laufendenVersandAufnehmen'));
        $this->assertNotNull($neu->sendJobUuid);
        $this->assertSame(5, $neu->sendProgress['total']);

        $frei = $this->seite($this->event()->id);
        $this->assertFalse($this->callPrivate($frei, 'laufendenVersandAufnehmen'));
        $this->assertNull($frei->sendJobUuid);
    }
}
