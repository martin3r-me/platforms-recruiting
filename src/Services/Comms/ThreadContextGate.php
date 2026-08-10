<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Entscheidet, ob ein WhatsApp-Thread-Kontext den Recruiting-Intake blockt.
 *
 * Hintergrund: Seit dem CRM-Feature "Contact-as-Context" (04/2026) bekommt
 * jeder eingehende Thread automatisch den CrmContact als Kontext angeheftet.
 * Ein nackter CRM-Kontakt bedeutet aber nur "Nummer ist im Adressbuch" — er
 * ist KEIN Fachprozess und darf den Bewerbungs-Eingang nicht blocken. Blocken
 * sollen weiterhin nur echte Fremd-Kontexte (HCM-Onboarding, Helpdesk,
 * Mitarbeiter, Companies, ...), damit deren Chats nicht als Bewerbung
 * fehlinterpretiert werden.
 *
 * Pure PHP (keine Laravel-Abhängigkeit): Morph-Alias und volle Klassennamen
 * sind als Literale hinterlegt, weil beide Schreibweisen in der DB vorkommen.
 */
final class ThreadContextGate
{
    private const APPLICANT_CONTEXTS = [
        'rec_applicant',
        'Platform\\Recruiting\\Models\\RecApplicant',
    ];

    private const BARE_CONTACT_CONTEXTS = [
        'crm_contact',
        'Platform\\Crm\\Models\\CrmContact',
    ];

    /**
     * True = Thread gehört einem fremden Fachprozess, Recruiting fasst ihn
     * nicht an. False = Intake darf laufen (neu, Bewerber oder nur Adressbuch).
     */
    public static function blocksIntake(?string $contextModel): bool
    {
        if ($contextModel === null || $contextModel === '') {
            return false;
        }

        return !in_array($contextModel, self::APPLICANT_CONTEXTS, true)
            && !in_array($contextModel, self::BARE_CONTACT_CONTEXTS, true);
    }

    /**
     * Wie blocksIntake(), aber über ALLE Kontexte eines Threads (Legacy-Spalte
     * + Pivot-Zeilen). Nötig, weil die Legacy-Spalte per "first context wins"
     * auf crm_contact stehen bleibt, auch wenn ein Fachprozess (HCM-Onboarding,
     * Helpdesk) den Thread später per Pivot-addContext() übernommen hat —
     * ein einziger fremder Kontext blockt den Intake.
     *
     * @param iterable<string|null> $contextModels
     */
    public static function blocksIntakeAny(iterable $contextModels): bool
    {
        foreach ($contextModels as $contextModel) {
            if (self::blocksIntake($contextModel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True, wenn der Kontext nur ein nackter CRM-Kontakt ist. Solche Threads
     * werden nach dem Intake auf den Bewerber "befördert" (Legacy-Spalten
     * umgeschrieben), damit Kommunikations-Übersicht & Nachrichten-Spalte
     * den Thread finden — addContext() allein lässt Legacy-Spalten unberührt
     * ("first context wins").
     */
    public static function isBareContactContext(?string $contextModel): bool
    {
        return $contextModel !== null
            && in_array($contextModel, self::BARE_CONTACT_CONTEXTS, true);
    }
}
