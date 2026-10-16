<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Support\Comms\ThemenTexte;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Schlanker Wrapper um WhatsAppMetaService::sendInteractive() fuer die
 * Themen-Auswahl. Drei Abwehren: Meta-Grenzen (Anzahl, Titellaenge) werden VOR
 * dem Send erzwungen; ein von Meta abgelehnter Versand ist kein Erfolg; eine
 * Ausnahme fliegt nicht weiter, sondern kommt als Status zurueck.
 * Alle Sends sind Auto-Antworten.
 */
final class InteraktivSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';

    public function __construct(private readonly ?WhatsAppMetaService $meta = null) {}

    /**
     * @param list<array{id:string,title:string}> $knoepfe
     * @return array{status:string, message_id:?int, error:?string}
     */
    public function knoepfe(CommsChannel $channel, string $to, string $body, array $knoepfe): array
    {
        if (count($knoepfe) < 1 || count($knoepfe) > 3) {
            throw new \InvalidArgumentException('Meta erlaubt 1 bis 3 Knöpfe.');
        }
        foreach ($knoepfe as $k) {
            $this->titelPruefen($k['title'], ThemenTexte::MAX_BUTTON, 'Knopf-Titel');
        }
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => array_map(
                fn ($k) => ['type' => 'reply', 'reply' => ['id' => $k['id'], 'title' => $k['title']]],
                array_values($knoepfe),
            )],
        ];

        return $this->senden($channel, $to, $interactive, $body);
    }

    /**
     * @param list<array{id:string,title:string}> $zeilen
     * @return array{status:string, message_id:?int, error:?string}
     */
    public function liste(CommsChannel $channel, string $to, string $body, string $knopfTitel, array $zeilen): array
    {
        if (count($zeilen) < 1 || count($zeilen) > 10) {
            throw new \InvalidArgumentException('Meta erlaubt 1 bis 10 Listenzeilen.');
        }
        $this->titelPruefen($knopfTitel, ThemenTexte::MAX_BUTTON, 'Listen-Knopf');
        foreach ($zeilen as $z) {
            $this->titelPruefen($z['title'], ThemenTexte::MAX_LIST, 'Zeilen-Titel');
        }
        $interactive = [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => $knopfTitel,
                'sections' => [['rows' => array_map(
                    fn ($z) => ['id' => $z['id'], 'title' => $z['title']],
                    array_values($zeilen),
                )]],
            ],
        ];

        return $this->senden($channel, $to, $interactive, $body);
    }

    /** @return array{status:string, message_id:?int, error:?string} */
    public function linkKnopf(CommsChannel $channel, string $to, string $body, string $knopfTitel, string $url): array
    {
        $this->titelPruefen($knopfTitel, ThemenTexte::MAX_BUTTON, 'Link-Knopf');
        $interactive = [
            'type' => 'cta_url',
            'body' => ['text' => $body],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $knopfTitel, 'url' => $url]],
        ];

        return $this->senden($channel, $to, $interactive, $body);
    }

    private function titelPruefen(string $titel, int $max, string $was): void
    {
        if (mb_strlen($titel) > $max) {
            throw new \InvalidArgumentException("{$was} länger als {$max} Zeichen: {$titel}");
        }
    }

    /** @return array{status:string, message_id:?int, error:?string} */
    private function senden(CommsChannel $channel, string $to, array $interactive, string $displayBody): array
    {
        try {
            $message = ($this->meta ?? app(WhatsAppMetaService::class))->sendInteractive($channel, $to, $interactive, $displayBody, null, true);
        } catch (\Throwable $e) {
            // Nur die letzten Ziffern loggen, nie die ganze Nummer.
            Log::warning('recruiting.themen.send_fehler', ['to' => PhoneE164::suffix($to), 'error' => $e->getMessage()]);

            return ['status' => self::STATUS_ERROR, 'message_id' => null, 'error' => $e->getMessage()];
        }
        if (($message->status ?? null) === 'failed') {
            $fehler = (string) ($message->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.');

            return ['status' => self::STATUS_FAILED, 'message_id' => (int) $message->id, 'error' => $fehler];
        }

        return ['status' => self::STATUS_SENT, 'message_id' => (int) $message->id, 'error' => null];
    }
}
