<?php

namespace Platform\Recruiting\Http\Controllers;

use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Eigenstaendige Druckseite (ohne App-Layout): jede Folie eine A4-Querseite, oeffnet den Druckdialog. */
class AnleitungDruckController
{
    public function __invoke(string $slug)
    {
        $anleitung = AnleitungKatalog::standard()->finde($slug);
        abort_if($anleitung === null || $anleitung->fehler !== null, 404);

        return view('recruiting::anleitungen.druck', ['anleitung' => $anleitung]);
    }
}
