<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Services\Comms\LohnParken;

/**
 * Geparkte Lohn-Chats freigeben, sobald die Akte vollstaendig ist (Variante B,
 * 26.10.2026). Alle 5 Minuten im Scheduler — ein Lauf statt Ausloesern an jeder
 * Speicherstelle, weil ZAS-Import und Nachweise teils an den Observern
 * vorbeischreiben.
 */
class LohnParkenFreigeben extends Command
{
    protected $signature = 'recruiting:lohn-parken-freigeben';

    protected $description = 'Geparkte Lohn-Chats an HR freigeben, deren Akte inzwischen vollständig ist';

    public function handle(): int
    {
        $parken = app(LohnParken::class);
        $zahlen = [];
        foreach (self::kandidaten() as $topic) {
            try {
                $status = $parken->freigebenWennVollstaendig($topic);
            } catch (\Throwable $e) {
                report($e);
                $status = 'fehler';
            }
            $zahlen[$status] = ($zahlen[$status] ?? 0) + 1;
        }

        ksort($zahlen);
        foreach ($zahlen as $status => $n) {
            $this->line($status . ': ' . $n);
        }

        return self::SUCCESS;
    }

    /**
     * Geparkte Zeilen, die noch die juengste ihres Chats sind — eine von einer
     * neueren Frage abgeloeste Zeile parkt nichts mehr.
     *
     * @return Collection<int, RecConversationTopic>
     */
    public static function kandidaten(): Collection
    {
        return RecConversationTopic::query()
            ->where('state', RecConversationTopic::STATE_GEPARKT)
            ->whereIn('id', function ($q) {
                $q->from('rec_conversation_topics')
                    ->selectRaw('MAX(id)')
                    ->groupBy('comms_whatsapp_thread_id');
            })
            ->orderBy('id')
            ->get();
    }
}
