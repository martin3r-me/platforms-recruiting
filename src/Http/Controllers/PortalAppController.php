<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Response;
use Platform\Recruiting\Models\RecEmployee;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal als App (Spec Jobs anbieten 6.6, Markus 13.11.2026): Manifest,
 * Service Worker und Symbole kommen ueber Modul-Routen, NIE ueber publizierte
 * Dateien — publizierte Assets in meingedeck driften (Vorfall Livewire-Assets).
 *
 * Das Manifest traegt den Portal-Token im Pfad: Startadresse ist die
 * Token-Adresse des Portals, dasselbe Geheimnis wie die Seite selbst. Vom
 * Startbildschirm landet der Mitarbeiter so in seinem Portal — mit gueltiger
 * Sitzung direkt, sonst nach genau einer Anmeldung (Token-Pruefung oder
 * Konto, PortalShell::mount). Auf dem iPhone hat die App einen eigenen
 * Speicher, dort kommt diese eine Anmeldung beim ersten Oeffnen.
 *
 * Antworten werden direkt gebaut (nicht ueber response()), damit sie ohne
 * gebootete App pruefbar sind. no-store setzt NoCacheHeaders fuer die ganze
 * Gruppe ohnehin; hier zusaetzlich, damit es nicht an der Gruppe haengt.
 */
class PortalAppController
{
    public const SCOPE = '/recruiting/';

    public const GROESSEN = ['192', '512'];

    public function manifest(string $token): Response
    {
        if ($token === '' || !RecEmployee::query()->where('portal_token', $token)->exists()) {
            throw new NotFoundHttpException();
        }

        $json = json_encode(self::manifestDaten($token), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new Response($json, 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifestDaten(string $token): array
    {
        return [
            'name' => 'Mein Rheingedeck',
            'short_name' => 'Rheingedeck',
            'lang' => 'de',
            'display' => 'standalone',
            'scope' => self::SCOPE,
            'start_url' => '/recruiting/mitarbeiter/neu/' . rawurlencode($token),
            'theme_color' => '#0e535e',
            'background_color' => '#EDF1F2',
            // Vollflaechiges Quadrat, Schrift in der Schutzzone — taugt fuer
            // beide Zwecke, Android schneidet „maskable“ rund zu.
            'icons' => array_map(static fn (string $g): array => [
                'src' => '/recruiting/portal-app/icon-' . $g . '.png',
                'sizes' => $g . 'x' . $g,
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ], self::GROESSEN),
        ];
    }

    /**
     * Nur Push und Antippen der Benachrichtigung; kein Abfangen und kein
     * Zwischenspeichern von Seiten. Liegt unter /recruiting/portal-app/,
     * soll aber das ganze Portal abdecken — daher Service-Worker-Allowed.
     */
    public function sw(): Response
    {
        return new Response((string) file_get_contents(self::pwaPfad('sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => self::SCOPE,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function icon(string $size): Response
    {
        // Feste Liste statt Pfad aus der Anfrage — die Route prueft es auch.
        if (!in_array($size, self::GROESSEN, true)) {
            throw new NotFoundHttpException();
        }

        return new Response((string) file_get_contents(self::pwaPfad('icon-' . $size . '.png')), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function pwaPfad(string $datei): string
    {
        return dirname(__DIR__, 3) . '/resources/pwa/' . $datei;
    }
}
