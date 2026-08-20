<?php

declare(strict_types=1);

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Design-Vorschau des Mitarbeiterportals — eine statische HTML-Datei hinter
 * einem Zahlencode, damit sie dem Kunden per Link gezeigt werden kann.
 *
 * Bewusst KEIN Blade und KEIN Layout: die Datei bringt ihr eigenes <head> und
 * ihr eigenes CSS mit. Wuerde sie durch Blade laufen, wuerde jedes `{{` im
 * Markup interpretiert; wuerde sie im Guest-Layout haengen, kaemen dessen
 * Body-Klassen (`dark:text-white`) und die Figtree-Schrift dazu und der
 * Entwurf saehe anders aus als abgestimmt. Sie wird deshalb roh ausgeliefert.
 *
 * Das Schloss ist ein Sichtschutz, keine Zugangskontrolle: hinter der Seite
 * liegen ausschliesslich Beispieldaten. Wer hier jemals echte Daten
 * ausspielt, ersetzt das durch eine richtige Authentifizierung.
 */
final class PortalMockupController
{
    private const SESSION_KEY = 'recruiting.portal-mockup.unlocked';

    private const CODE = '1891';

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->isMethod('POST')) {
            if (hash_equals(self::CODE, trim((string) $request->input('code')))) {
                $request->session()->put(self::SESSION_KEY, true);

                return redirect()->route('recruiting.public.portal-mockup');
            }

            return $this->lockScreen(fehler: true)->setStatusCode(422);
        }

        if ($request->session()->get(self::SESSION_KEY) !== true) {
            return $this->lockScreen(fehler: false);
        }

        $pfad = __DIR__ . '/../../../resources/mockups/crew-portal.html';

        if (! is_file($pfad)) {
            abort(404);
        }

        return response((string) file_get_contents($pfad))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function lockScreen(bool $fehler): Response
    {
        $token = csrf_token();
        $hinweis = $fehler
            ? '<p class="err">Der Code stimmt nicht. Bitte noch einmal.</p>'
            : '';

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Crew-Portal — Entwurf</title>
            <link rel="preconnect" href="https://fonts.bunny.net">
            <link rel="stylesheet" href="https://fonts.bunny.net/css?family=chau-philomene-one:400|inter:400,500&display=swap">
            <style>
              *{box-sizing:border-box}
              body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e535e;
                   color:#fff;font-family:"Inter",system-ui,sans-serif;padding:24px}
              .box{width:100%;max-width:360px;text-align:center}
              h1{font-family:"Chau Philomene One",system-ui,sans-serif;font-weight:400;
                 font-size:34px;margin:0 0 10px;line-height:1.15}
              p{margin:0 0 26px;font-size:15px;color:rgba(255,255,255,.75);line-height:1.5}
              .err{color:#ffc9c2;font-size:14px;margin:0 0 16px}
              input{width:100%;padding:14px;border-radius:8px;border:1px solid rgba(255,255,255,.25);
                    background:rgba(255,255,255,.1);color:#fff;font-size:19px;text-align:center;
                    letter-spacing:.35em;font-family:inherit}
              input::placeholder{color:rgba(255,255,255,.4);letter-spacing:.2em}
              input:focus{outline:2px solid #90dce4;outline-offset:2px}
              button{width:100%;margin-top:12px;padding:14px;border:none;border-radius:8px;
                     background:#90dce4;color:#08343B;font-family:"Chau Philomene One",system-ui,sans-serif;
                     font-size:17px;cursor:pointer}
              button:hover{filter:brightness(1.05)}
            </style>
            </head>
            <body>
              <div class="box">
                <h1>Crew-Portal</h1>
                <p>Design-Entwurf. Bitte gib den Code ein, den du bekommen hast.</p>
                {$hinweis}
                <form method="POST">
                  <input type="hidden" name="_token" value="{$token}">
                  <input type="text" name="code" inputmode="numeric" autocomplete="off"
                         autofocus placeholder="Code" aria-label="Code">
                  <button type="submit">Ansehen</button>
                </form>
              </div>
            </body>
            </html>
            HTML;

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
