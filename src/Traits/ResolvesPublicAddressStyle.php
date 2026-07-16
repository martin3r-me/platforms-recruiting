<?php

namespace Platform\Recruiting\Traits;

use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\PublicAddressStyle;

/**
 * Loest die Anrede fuer oeffentliche Seiten (Bewerber-/Mitarbeiter-Links)
 * aus dem Team-Setting `use_informal_address` auf. Default Sie.
 *
 * Erwartet ein Modell mit `team_id` (RecApplicant, RecEmployee).
 *
 * Der Methodenname `usesInformalAddress` ist zugleich der Core-Hook:
 * PublicExtraFieldForm prueft per method_exists — selbe Konvention wie
 * usesAccordionFormLayout / renderPublicFormCompletionExtras.
 */
trait ResolvesPublicAddressStyle
{
    public function usesInformalAddress(): bool
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

        return PublicAddressStyle::informal($settings->getSetting('use_informal_address'));
    }
}
