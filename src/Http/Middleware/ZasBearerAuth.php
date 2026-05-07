<?php

namespace Platform\Recruiting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-Token-Auth fuer die ZAS-Endpunkte (CSV-Export + Datei-Streams).
 *
 * Vergleich via hash_equals (timing-safe). Token kommt aus
 * config('recruiting.zas.token') und wird per env gesetzt.
 *
 * 401 + WWW-Authenticate-Header bei fehlendem oder falschem Token —
 * keine Hinweise warum es scheiterte (kein Auth-Token-Probing).
 */
class ZasBearerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('recruiting.zas.token', '');

        // Defense-in-depth: wenn das Token nicht konfiguriert ist, lehnen
        // wir den Endpoint komplett ab. Sonst koennte ein versehentliches
        // null-Token den Endpoint oeffnen.
        if ($expected === '') {
            return response('ZAS endpoint not configured', 503)
                ->header('Cache-Control', 'no-store');
        }

        $provided = $this->extractBearer($request);

        if ($provided === null || !hash_equals($expected, $provided)) {
            return response('Unauthorized', 401)
                ->header('WWW-Authenticate', 'Bearer realm="ZAS"')
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }

    /**
     * Liest das Bearer-Token aus dem Authorization-Header.
     */
    protected function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (!is_string($header)) {
            return null;
        }
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }
}
