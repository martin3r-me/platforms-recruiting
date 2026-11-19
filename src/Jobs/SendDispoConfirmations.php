<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;

/**
 * Bestaetigungs-Versand einer Veranstaltung im Hintergrund (Vorfall 19.11.2026).
 *
 * Vorher lief der Versand im Seitenaufruf: rund eine Nachricht je Sekunde, bei
 * 170 Empfaengern also drei Minuten — der Lastverteiler wartet eine. Er kappte
 * die Verbindung, die Disponentin sah eine Fehlerseite und sendete erneut,
 * waehrend der erste Lauf im Hintergrund weiterlief.
 *
 * Jetzt: der Klick plant die Empfaenger, legt diesen Job in die Warteschlange
 * und kehrt sofort zurueck. Fortschritt im Cache unter cacheKey(); je
 * Veranstaltung laeuft hoechstens ein Versand (laufKey()). Die Versandlogik
 * selbst bleibt DispoConfirmationSender.
 */
class SendDispoConfirmations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public const CACHE_TTL_SECONDS = 86400;
    /** Laenger als $timeout: der Laufmerker darf nie vor dem Job verfallen. */
    public const LAUF_TTL_SECONDS = 2100;
    public const PAKET = 10;

    /**
     * @param list<array{employee_id:int, phone:string, assignment_ids:list<int>, first_datum:string, is_reminder:bool}> $recipients
     * @param array{1:string,2:string,3:string}|null $escalationDue Zeitpunkte als Text (Y-m-d H:i:s)
     * @param string $geplantAm Zeitpunkt, zu dem die Empfaengerliste geplant wurde (Y-m-d H:i:s)
     */
    public function __construct(
        public readonly int $eventId,
        public readonly array $recipients,
        public readonly int $templateId,
        public readonly ?array $escalationDue,
        public readonly string $uuid,
        public readonly string $geplantAm,
    ) {
    }

    public static function cacheKey(string $uuid): string
    {
        return 'recruiting:dispo-confirm:' . $uuid;
    }

    /** Laufmerker je Veranstaltung: Wert = uuid des laufenden Versands. */
    public static function laufKey(int $eventId): string
    {
        return 'recruiting:dispo-confirm-run:' . $eventId;
    }

    /** @return array{total:int, sent:int, skipped:int, failed:list<array{employee_id:int, error:string}>, started:bool, done:bool, message:?string} */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'skipped' => 0, 'failed' => [], 'started' => false, 'done' => false, 'message' => null];
    }

    /** Nach dem einzigen fehlgeschlagenen Versuch (z. B. Timeout): Fortschritt schliessen, Veranstaltung freigeben. */
    public function failed(?\Throwable $e): void
    {
        try {
            $cache = app(Cache::class);
            $progress = $cache->get(self::cacheKey($this->uuid)) ?? self::initialProgress(count($this->recipients));
            $progress['done'] = true;
            $progress['message'] = 'Der Versand wurde abgebrochen. Bereits verschickte Nachrichten sind markiert, die übrigen bitte erneut senden.';
            $cache->put(self::cacheKey($this->uuid), $progress, self::CACHE_TTL_SECONDS);
            $this->freigeben($cache);
        } catch (\Throwable) {
            // Cache-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    public function handle(Cache $cache, DispoConfirmationSender $sender): void
    {
        $key = self::cacheKey($this->uuid);
        $progress = self::initialProgress(count($this->recipients));
        $progress['started'] = true;
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);

        try {
            $event = RecDispoEvent::query()->find($this->eventId);
            if ($event === null) {
                $progress['message'] = 'Die Veranstaltung gibt es nicht mehr.';

                return;
            }

            $due = null;
            if ($this->escalationDue !== null) {
                $due = [
                    1 => Carbon::parse($this->escalationDue[1]),
                    2 => Carbon::parse($this->escalationDue[2]),
                    3 => Carbon::parse($this->escalationDue[3]),
                ];
            }
            $geplant = Carbon::parse($this->geplantAm);

            // Pakete, damit der Fortschritt waehrend des Laufs sichtbar ist.
            foreach (array_chunk($this->recipients, self::PAKET) as $paket) {
                $r = $sender->send($event, $paket, $this->templateId, $due, $geplant);
                if (!$r['ok']) {
                    // Vorlage oder Kanal fehlt — das trifft jedes weitere Paket genauso.
                    $progress['message'] = (string) $r['message'];
                    break;
                }
                $progress['sent'] += (int) $r['sent'];
                $progress['skipped'] += (int) ($r['skipped'] ?? 0);
                foreach ($r['failed'] as $f) {
                    $progress['failed'][] = ['employee_id' => (int) $f['employee_id'], 'error' => (string) $f['error']];
                }
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
            }
        } finally {
            $progress['done'] = true;
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
            $this->freigeben($cache);
        }
    }

    /** Laufmerker nur loeschen, wenn er noch DIESEM Versand gehoert. */
    private function freigeben(Cache $cache): void
    {
        if ($cache->get(self::laufKey($this->eventId)) === $this->uuid) {
            $cache->forget(self::laufKey($this->eventId));
        }
    }
}
