<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Services\DokumentService;

/**
 * Hintergrundversand der Dokument-Hinweise (Spec §3.1 Schritt 5, Nachtrag
 * 09.10.2026): der Klick legt nur Zeilen an, dieser Job schickt. Idempotent
 * ueber DokumentService::hinweiseVersenden() — ein zweiter Lauf versucht
 * nichts erneut, deshalb ist $tries = 1 ungefaehrlich und $tries > 1 unnoetig.
 * Kein SerializesModels: nur die ID, das Dokument wird frisch geladen.
 */
class DokumentHinweiseVersenden implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;
    public $timeout = 900;

    public function __construct(private int $documentId)
    {
    }

    /**
     * Einziger Einstieg fuer die drei Oberflaechen — stellt nur ein, wenn es
     * etwas zu schicken gibt. "Ohne WhatsApp" (notify_mode none) stellt sich
     * gar nicht erst ein: hinweiseVersenden() prueft es zwar auch, aber ein
     * Job, der nichts tut, waere im Queue-Log ein falsches Signal.
     */
    public static function starten(RecDocument $dokument): void
    {
        if (DokumentService::hinweisFaellig((string) $dokument->category, (string) $dokument->action, $dokument->notify_mode)) {
            self::dispatch((int) $dokument->id);
        }
    }

    public function handle(): void
    {
        $dokument = RecDocument::find($this->documentId);
        if ($dokument === null) {
            return;
        }
        $e = app(DokumentService::class)->hinweiseVersenden($dokument);
        Log::info('recruiting.dokumente.hinweise_versendet', ['document_id' => $dokument->id] + $e);
    }
}
