<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecEmployee;

/**
 * Wer traegt die Nachricht an diesen Menschen? Eine Regel fuer die
 * Einsatz-Pruefung und "Erneut erinnern" im HR-Bereich (Spec HR-Bereich §3.3).
 * Umgezogen aus EinsatzPruefung::bote():
 *
 * F4: die Anstellung, ueber die dieser MENSCH sein Portal erreicht.
 *
 * `portal_v2_since` steht an der ANSTELLUNG, nicht am Menschen, und
 * umgestellt wird je Zeile (`recruiting:portal-umstellen --ids`). Bei
 * einem Doppelbeschaeftigten RG+MA ist "eine Zeile umgestellt, eine
 * nicht" der Normalfall einer Pilotumstellung. Gefragt wird deshalb der
 * ganze Personen-Umfang, und zwar nach der kleinsten Kennung — damit die
 * Antwort nicht davon abhaengt, an welcher Anstellung gerade eine
 * Einbuchung haengt.
 *
 * An dieser Anstellung haengen Rufnummer, Kanal und Portal-Token der
 * Nachricht; sie muessen zusammen passen, denn die Nachricht verweist
 * auf GENAU DIESES Portal.
 *
 * FOLGE FUER --team, und sie gehoert in die Deploy-Notiz: die Flagge
 * zaeunt seitdem den SENDEKANAL nicht mehr ein. Liegt die Buchung an
 * einer Anstellung in Team 3 und das Portal an der Zeile in Team 7,
 * laeuft der Mensch bei `--team=3` mit, und als Absender erscheint Team
 * 7. Das ist fachlich richtig — Nummer, Kanal und Token muessen zum
 * EXISTIERENDEN Portal passen, sonst fuehrt der Link ins Leere —, aber
 * operativ ueberraschend. `--team` grenzt den ZIELKREIS ein, nicht den
 * Absender.
 */
final class Bote
{
    /** @param  list<int>  $umfangIds */
    public static function fuer(array $umfangIds): ?RecEmployee
    {
        if ($umfangIds === []) {
            return null;
        }

        return RecEmployee::query()
            ->whereIn('id', $umfangIds)
            ->whereNotNull('portal_v2_since')
            ->orderBy('id')
            ->first();
    }
}
