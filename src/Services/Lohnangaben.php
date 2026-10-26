<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\LohnrelevanteFelder;
use Platform\Recruiting\Support\PersonenFelder;
use Platform\Recruiting\Support\PortalFieldAccess;

/**
 * Lohnrelevante Luecken einer Anstellung (26.10.2026) ausserhalb des Portals
 * — fuer den Lohn-Zweig der Themen-Auswahl. Dieselbe Regel wie der
 * Portal-Start: Profilfelder der Akte, Personenfelder personenweit
 * (eigener Wert, sonst juengste aktive Schwester, Spec 2026-10-22 §3), die
 * Liste aus LohnrelevanteFelder.
 */
class Lohnangaben
{
    /** @return array<string, string> fehlendes Feld => Beschriftung */
    public function fehlendFuer(RecEmployee $employee): array
    {
        $gruppen = $employee->editableFieldGroups();
        $schwestern = null;
        $datensatz = [];
        foreach ($gruppen as $felder) {
            foreach (array_keys($felder) as $feld) {
                $wert = $employee->getAttribute($feld);
                if (\Platform\Recruiting\Support\PersonDatensatz::istLeer($wert) && in_array($feld, PersonenFelder::SPIEGELN, true)) {
                    $schwestern ??= (new PersonSchwestern())->aktive($employee);
                    $wert = PersonSchwestern::ersterWert($employee, $feld, $schwestern);
                }
                $datensatz[$feld] = $wert;
            }
        }

        return LohnrelevanteFelder::fehlend(PortalFieldAccess::sichtbareFelderFlach($gruppen, $datensatz, []), $datensatz);
    }
}
