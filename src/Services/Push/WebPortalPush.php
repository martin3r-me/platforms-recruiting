<?php

namespace Platform\Recruiting\Services\Push;

use Platform\Recruiting\Jobs\SendPortalPush;

/**
 * Echter Push-Dienst (Spec Jobs anbieten 6.7, Markus 13.11.2026): reiht nur ein,
 * gesendet wird im Job. Ohne VAPID-Schluessel oder ohne Bibliothek inaktiv —
 * dann kein Einschalt-Knopf im Portal und nichts in der Warteschlange.
 */
class WebPortalPush implements PortalPush
{
    public function aktiv(): bool
    {
        return (string) config('recruiting.push.vapid.public', '') !== ''
            && (string) config('recruiting.push.vapid.private', '') !== ''
            && $this->bibliothekVorhanden();
    }

    public function neueAngebote(array $employeeIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn (int $id) => $id > 0)));
        if ($ids === [] || !$this->aktiv()) {
            return;
        }
        $this->einreihen(new SendPortalPush($ids, SendPortalPush::ART_ANGEBOTE));
    }

    public function probe(int $employeeId): void
    {
        if ($employeeId <= 0 || !$this->aktiv()) {
            return;
        }
        $this->einreihen(new SendPortalPush([$employeeId], SendPortalPush::ART_PROBE));
    }

    /** Naht fuer Tests: die Bibliothek ist im Test-vendor nicht installiert. */
    protected function bibliothekVorhanden(): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class);
    }

    /** In die Warteschlange — getrennt, damit Tests ohne Bus das Einreihen nur sehen. */
    protected function einreihen(SendPortalPush $job): void
    {
        dispatch($job);
    }
}
