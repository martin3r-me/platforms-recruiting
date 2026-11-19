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
    /** Klein, damit das Lebenszeichen auch bei langsamem Meta oft genug kommt. */
    public const PAKET = 5;
    /**
     * So lange gilt ein Lauf ohne neues Lebenszeichen noch als lebendig. Ein Paket
     * braucht normal fuenf Sekunden; haengt Meta, sind es je Nachricht bis zu 30.
     */
    public const LEBENSZEICHEN_SEKUNDEN = 240;
    public const ABGEBROCHEN = 'Der Versand wurde abgebrochen. Bereits verschickte Nachrichten sind markiert, die übrigen bitte erneut senden.';
    public const FREMDER_LAUF = 'Für diese Veranstaltung lief bereits ein anderer Versand. Dieser hier hat nichts verschickt.';

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

    /** Letzter Versand je Veranstaltung (uuid) — damit ein Ergebnis mit Fehlern auch nach dem Schliessen des Fensters ankommt. */
    public static function letzterKey(int $eventId): string
    {
        return 'recruiting:dispo-confirm-last:' . $eventId;
    }

    /**
     * beat = Unix-Zeit des letzten Lebenszeichens (beim Einreihen: der Klick).
     *
     * @return array{total:int, sent:int, skipped:int, failed:list<array{employee_id:int, error:string}>, started:bool, done:bool, message:?string, beat:int}
     */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'skipped' => 0, 'failed' => [], 'started' => false, 'done' => false, 'message' => null, 'beat' => time()];
    }

    /** Lebt der Lauf noch? Fertig = nein; sonst entscheidet das letzte Lebenszeichen. */
    public static function lebt(array $progress): bool
    {
        if (!empty($progress['done'])) {
            return false;
        }

        return (time() - (int) ($progress['beat'] ?? 0)) < self::LEBENSZEICHEN_SEKUNDEN;
    }

    /**
     * Nach dem einzigen fehlgeschlagenen Versuch (Timeout, Absturz): Fortschritt
     * schliessen, Veranstaltung freigeben.
     *
     * NICHT, solange der Lauf noch lebt (Review 19.11.2026): die Warteschlange gibt
     * einen Auftrag nach retry_after (Standard 90 s) erneut frei, obwohl er noch
     * sendet. Ein zweiter Worker ruft dann nur failed() — wuerde das den Lauf als
     * abgebrochen melden und die Veranstaltung freigeben, startete die Disponentin
     * einen zweiten Versand neben dem ersten. Der lebende Lauf schliesst sich selbst.
     */
    public function failed(?\Throwable $e): void
    {
        try {
            $cache = app(Cache::class);
            $progress = $cache->get(self::cacheKey($this->uuid));
            if (is_array($progress) && !empty($progress['started']) && self::lebt($progress)) {
                return;
            }
            if (is_array($progress) && !empty($progress['done'])) {
                $this->freigeben($cache);

                return;
            }
            $progress = is_array($progress) ? $progress : self::initialProgress(count($this->recipients));
            $progress['done'] = true;
            $progress['message'] = self::ABGEBROCHEN;
            $cache->put(self::cacheKey($this->uuid), $progress, self::CACHE_TTL_SECONDS);
            $this->freigeben($cache);
        } catch (\Throwable) {
            // Cache-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    public function handle(Cache $cache, DispoConfirmationSender $sender): void
    {
        $key = self::cacheKey($this->uuid);
        $laufKey = self::laufKey($this->eventId);
        $progress = self::initialProgress(count($this->recipients));

        // Gehoert die Veranstaltung inzwischen einem anderen Versand (Laufmerker
        // waehrend einer langen Wartezeit verfallen, jemand hat neu gestartet)?
        // Dann sendet dieser hier nichts. Fehlt der Merker nur, wird er neu gesetzt.
        $besitzer = $cache->get($laufKey);
        if (is_string($besitzer) && $besitzer !== '' && $besitzer !== $this->uuid) {
            $progress['done'] = true;
            $progress['message'] = self::FREMDER_LAUF;
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);

            return;
        }

        $progress['started'] = true;
        $this->lebenszeichen($cache, $progress);

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

            // Pakete, damit Fortschritt und Lebenszeichen waehrend des Laufs sichtbar sind.
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
                $this->lebenszeichen($cache, $progress);
            }
        } catch (\Throwable $e) {
            $progress['message'] = self::ABGEBROCHEN;

            throw $e;
        } finally {
            $progress['done'] = true;
            $progress['beat'] = time();
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
            $this->freigeben($cache);
        }
    }

    /** Fortschritt schreiben und den Laufmerker frisch halten (seine Frist zaehlt sonst ab dem Klick). */
    private function lebenszeichen(Cache $cache, array &$progress): void
    {
        $progress['beat'] = time();
        $cache->put(self::cacheKey($this->uuid), $progress, self::CACHE_TTL_SECONDS);
        $cache->put(self::laufKey($this->eventId), $this->uuid, self::LAUF_TTL_SECONDS);
    }

    /** Laufmerker nur loeschen, wenn er noch DIESEM Versand gehoert. */
    private function freigeben(Cache $cache): void
    {
        if ($cache->get(self::laufKey($this->eventId)) === $this->uuid) {
            $cache->forget(self::laufKey($this->eventId));
        }
    }
}
