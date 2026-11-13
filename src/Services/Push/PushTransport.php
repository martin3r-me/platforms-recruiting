<?php

namespace Platform\Recruiting\Services\Push;

use Platform\Recruiting\Models\RecPushAbo;

/**
 * Eine Benachrichtigung an ein Geraet (Spec Jobs anbieten 6.7, Markus 13.11.2026).
 * Getrennt vom Job, damit Tests ohne die Bibliothek ueber eine Attrappe laufen.
 */
interface PushTransport
{
    /**
     * @param array{title:string, body:string, url:string} $payload
     * @return 'ok'|'erloschen'|'fehler' erloschen = Push-Dienst kennt das Abo nicht mehr (404/410)
     */
    public function senden(RecPushAbo $abo, array $payload): string;
}
