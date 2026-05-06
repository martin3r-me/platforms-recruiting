<?php

namespace Platform\Recruiting\Traits;

/**
 * Opt-in Trait fuer Recruiting-Modelle die im PublicExtraFieldForm das
 * Akkordeon-Layout (Pflicht oben offen, Optional eingeklappt) nutzen wollen.
 *
 * Der Core PublicExtraFieldForm prueft via method_exists ob das Linkable
 * dieses Trait nutzt. Andere Module die diesen Trait nicht einbinden,
 * sehen weiterhin das klassische Layout — keine Verhaltens-Aenderung
 * fuer HCM-Onboarding etc.
 *
 * Nutzung:
 *   use Platform\Recruiting\Traits\UsesAccordionPublicForm;
 *
 *   class RecApplicant extends Model {
 *       use UsesAccordionPublicForm;
 *   }
 *
 * Wenn ein Recruiting-Modell das Akkordeon explizit nicht haben will
 * (Spezialfall), kann es die Methode ueberschreiben:
 *   public function usesAccordionFormLayout(): bool { return false; }
 */
trait UsesAccordionPublicForm
{
    public function usesAccordionFormLayout(): bool
    {
        return true;
    }
}
