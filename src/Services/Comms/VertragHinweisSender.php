<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * "Im Portal liegt ein Vertrag zur Unterschrift" (Spec Vertrag aus der Akte
 * §2.4). Derselbe Weg wie der Dokument-Hinweis — aktiv, portal_v2_since,
 * Nummer, URL-Knopf mit Portal-Token, Platzhalter nur vorname/name/1,
 * `failed` ist kein Erfolg —, nur mit eigener Vorlage.
 *
 * RUECKFALL: ist `employee_contract_wa_template_id` leer, nimmt er
 * `document_wa_template_id` — der universelle Text "im Portal liegt etwas
 * fuer dich" passt auch hier. Die Gegenrichtung gibt es nicht.
 */
class VertragHinweisSender extends DokumentHinweisSender
{
    public const SETTINGS_KEY = 'employee_contract_wa_template_id';

    protected function vorlagenSchluessel(int $teamId): string
    {
        $eigene = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(self::SETTINGS_KEY);

        return ((int) $eigene) > 0 ? self::SETTINGS_KEY : parent::SETTINGS_KEY;
    }
}
