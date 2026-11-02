<?php

namespace Platform\Recruiting\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Einladungslink oder -code ungueltig, abgelaufen, verbraucht, gesperrt —
 * bewusst EINE Antwort fuer alle Faelle (keine Auskunft, ob es den Token
 * gibt). Laravel ruft render() vor der Standard-Fehlerseite auf.
 *
 * Seit 02.11.2026 (Pilot-Feedback Markus) leitet die Antwort direkt zur
 * Anmeldung: der Knopf "Konto anlegen" bleibt im WhatsApp-Verlauf, und wer
 * ihn nach der Einrichtung wieder antippt, will sich anmelden. Vorher stand
 * dazwischen eine Fehlerseite "Link nicht mehr gueltig". Die Anmeldeseite
 * zeigt einen Hinweis fuer die, die noch kein Konto haben.
 */
class EinladungUngueltig extends NotFoundHttpException
{
    public const HINWEIS = 'recruiting.konto.einladung_ungueltig';

    public function render(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('recruiting.public.konto')->with(self::HINWEIS, true);
    }
}
