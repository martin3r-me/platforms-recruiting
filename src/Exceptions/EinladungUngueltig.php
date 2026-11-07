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

    /**
     * NICHT ueber redirect() (07.11.2026, 500er bei Markus): die Ausnahme
     * faellt in mount(), und solange die Komponente laeuft, ist 'redirect'
     * Livewires Redirector — dessen route() liefert sich selbst statt einer
     * RedirectResponse. Deshalb Antwort und Hinweis direkt.
     */
    public function render(): \Illuminate\Http\RedirectResponse
    {
        session()->flash(self::HINWEIS, true);

        return new \Illuminate\Http\RedirectResponse(route('recruiting.public.konto'));
    }
}
