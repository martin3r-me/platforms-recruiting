<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Recruiting-Routen ein eingeschraenktes Konto sehen darf.
 *
 * Zwei Zugriffsstufen, per E-Mail-Liste vergeben (siehe DispoAccess):
 *
 *  - "Nur Veranstaltungen" (03.09.2026): Disposition -> Veranstaltungen,
 *    lesend. Fuer Teamleiter-Konten im Einsatz.
 *  - "Schulungsbewertung" (14.09.2026): die schlanke Nachbereitungs-Ansicht.
 *    Anwesenheit, Bewertung, Klaerung an HR — sonst nichts.
 *
 * Ein Konto darf auf beiden Listen stehen; die Erlaubnisse addieren sich.
 *
 * WICHTIG — die Fehlerrichtung: Wer auf KEINER Liste steht, ist normaler
 * Nutzer und wird nicht angefasst (Opt-in). Wer auf MINDESTENS EINER steht,
 * ist ab dann eingeschraenkt und kommt NUR noch an die Routen seiner Listen.
 * Eine Stufe, die nur etwas erlaubt und nichts verbietet, waere kein Gate:
 * Ein Konto, das ausschliesslich bewerten soll, haette sonst Bewerberakten,
 * Loehne und jeden Vertragsversand-Knopf.
 *
 * Die Abwehr ruht nicht allein hier. Livewire-Actions laufen nicht ueber
 * recruiting.*-Routen — was hier geblockt wird, rendert aber auch nie, und
 * ohne gerenderte Seite gibt es keinen gueltigen Snapshot, ueber den sich
 * Methoden fremder Komponenten aufrufen liessen. Mutationen der erreichbaren
 * Seiten sind ZUSAETZLICH in den Komponenten selbst gesperrt.
 */
final class RecruitingRouteGate
{
    /** Routen der Stufe "Nur Veranstaltungen". */
    private const EVENT_ROUTES = [
        'recruiting.dispo.events.index',
        'recruiting.dispo.events.show',
        'recruiting.dispo.attachments.download',
    ];

    /** Routen der Stufe "Schulungsbewertung". */
    private const TRAINING_ROUTES = [
        'recruiting.training-review.index',
        'recruiting.training-review.show',
    ];

    /**
     * Core-Landeseiten: fuer eingeschraenkte Konten direkt zur erlaubten
     * Ansicht (Kunde 03.09.: nach dem SSO-Login nicht erst durchs Dashboard
     * klicken). Bewusst NUR diese Namen — Logout, Profil und Team-Wechsel
     * bleiben unangetastet.
     */
    private const CORE_LANDING = [
        'platform.dashboard',
    ];

    /**
     * Die Route, auf die umgeleitet werden muss — oder null, wenn der
     * Aufruf durchgehen darf.
     */
    public static function redirectTo(bool $eventOnly, bool $trainingLeader, string $routeName): ?string
    {
        if (!$eventOnly && !$trainingLeader) {
            return null;
        }

        $erlaubt = array_merge(
            $eventOnly ? self::EVENT_ROUTES : [],
            $trainingLeader ? self::TRAINING_ROUTES : [],
        );

        $istRecruiting = str_starts_with($routeName, 'recruiting.');
        $istLandeseite = in_array($routeName, self::CORE_LANDING, true);

        if (!$istRecruiting && !$istLandeseite) {
            return null;
        }

        if (in_array($routeName, $erlaubt, true)) {
            return null;
        }

        // Startseite der Stufe: Veranstaltungen gewinnen, weil das die
        // bestehende Gewohnheit der Konten ist.
        return $eventOnly ? self::EVENT_ROUTES[0] : self::TRAINING_ROUTES[0];
    }
}
