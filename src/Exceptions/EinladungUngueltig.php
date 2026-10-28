<?php

namespace Platform\Recruiting\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Einladungslink oder -code ungueltig, abgelaufen, verbraucht, gesperrt —
 * bewusst EINE Antwort fuer alle Faelle (Status 404, keine Auskunft, ob es
 * den Token gibt). Statt der englischen Standardseite ohne Weg zurueck
 * (Pilot-Review 28.10.2026) eine eigene Seite: zur Anmeldung oder den Code
 * neu eintippen. Laravel ruft render() vor der Standard-Fehlerseite auf.
 */
class EinladungUngueltig extends NotFoundHttpException
{
    public function render(): \Illuminate\Http\Response
    {
        return response()->view('recruiting::public.einladung-ungueltig', [], 404);
    }
}
