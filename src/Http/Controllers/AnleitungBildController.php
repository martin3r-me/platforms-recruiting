<?php

namespace Platform\Recruiting\Http\Controllers;

use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Liefert ein Anleitungs-Bild aus docs/anleitungen/bilder/<slug>/ — alles andere 404. */
class AnleitungBildController
{
    public function __invoke(string $slug, string $datei)
    {
        $pfad = AnleitungKatalog::standard()->bildPfad($slug, $datei);
        abort_if($pfad === null, 404);

        return response()->file($pfad, ['Cache-Control' => 'private, max-age=3600']);
    }
}
