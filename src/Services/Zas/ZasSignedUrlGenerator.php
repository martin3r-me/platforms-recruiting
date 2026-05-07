<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\URL;

/**
 * Erzeugt zeitlich begrenzte signierte URLs fuer den ZAS-Datei-Endpoint.
 *
 * Stabilitaets-Garantie: URLs sind deterministisch fuer
 * applicant_uuid + slot — solange der Bewerber existiert und das Sekret
 * unveraendert ist, ergibt jeder Aufruf die gleiche URL (nur expires
 * + sig variieren). Das ist wichtig damit ZAS Bilder beim Pull nicht
 * doppelt herunterlaedt.
 *
 * URL-Form:
 *   GET /recruiting/zas/files/{applicant_uuid}/{slot}?expires={ts}&sig={hmac}
 *
 * Signatur: hash_hmac('sha256', "{uuid}|{slot}|{expires}", $secret)
 *
 * Der ZasFileController validiert die Signatur via hash_equals und
 * streamt die zum Slot gehoerende ContextFile.
 */
class ZasSignedUrlGenerator
{
    public function __construct(
        protected string $secret,
        protected int $ttlDays = 7,
    ) {
        if ($secret === '') {
            // Wir wollen lieber laut crashen als versehentlich mit
            // leerem Sekret signieren.
            throw new \RuntimeException(
                'ZasSignedUrlGenerator: signed_url_secret ist leer. '
                . 'Setze RECRUITING_ZAS_SIGNED_URL_SECRET in der .env.'
            );
        }
    }

    /**
     * Generiert eine signierte URL fuer einen bestimmten Bewerber-Slot.
     * Slot-Konvention: kebab-case ZAS-Spaltenname, z. B. "upl-versicher".
     */
    public function generate(string $applicantUuid, string $slot): string
    {
        $expires = now()->addDays($this->ttlDays)->timestamp;
        $sig = $this->sign($applicantUuid, $slot, $expires);

        return URL::to(sprintf(
            '/recruiting/zas/files/%s/%s?expires=%d&sig=%s',
            urlencode($applicantUuid),
            urlencode($slot),
            $expires,
            $sig,
        ));
    }

    /**
     * Validiert eine eingehende signierte URL. Vom ZasFileController genutzt.
     */
    public function isValid(string $applicantUuid, string $slot, int $expires, string $sig): bool
    {
        if ($expires < time()) {
            return false;
        }
        $expected = $this->sign($applicantUuid, $slot, $expires);
        return hash_equals($expected, $sig);
    }

    protected function sign(string $applicantUuid, string $slot, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $applicantUuid . '|' . $slot . '|' . $expires,
            $this->secret,
        );
    }
}
