<?php

namespace Platform\Recruiting\Support;

/**
 * Die zwei Erklaertexte, die es im alten Portal nur im Blade gab
 * (employee-portal.blade.php:255-270) und die von keiner generischen
 * Feldruebernahme mitgenommen werden.
 *
 * Der Arbeitgeber-Text ist BEWUSST NICHT "wo du am meisten verdienst":
 * das ist eine Faustregel, keine Regel. Man hat genau EIN erstes
 * Dienstverhaeltnis, jedes weitere laeuft ueber Steuerklasse VI — welches
 * das erste ist, entscheidet der Mitarbeiter. Und ein Minijob zaehlt
 * nicht mit, was bei Schuelern und Studenten der Normalfall ist (E15).
 *
 * OFFEN laut Commit 0f9cffa: die Formulierung soll noch jemand freigeben,
 * der die Lohnabrechnung verantwortet. Bis dahin bleibt sie woertlich so.
 */
final class PortalSectionHints
{
    public static function fuer(string $gruppe, bool $duzen): ?string
    {
        return match ($gruppe) {
            'Arbeitsschutz' => $duzen
                ? 'Bist du Ersthelfer? Wenn ja, trag bitte das Gültigkeitsdatum ein und lade deinen Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wähl einfach „Nein".'
                : 'Sind Sie Ersthelfer? Wenn ja, tragen Sie bitte das Gültigkeitsdatum ein und laden Sie Ihren Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wählen Sie einfach „Nein".',
            'Arbeitgeber' => $duzen
                ? 'Wenn du nur bei uns arbeitest, sind wir dein Hauptarbeitgeber — dann wähl „Ja" und lass das Feld darunter leer. Arbeitest du noch woanders, kannst du trotzdem nur bei einem Arbeitgeber der Hauptarbeitgeber sein. Ist das ein anderer, wähl „Nein" und trag ihn ein. Ein Minijob zählt dabei nicht mit. Du weißt es nicht sicher? Frag uns kurz — die Angabe wirkt sich auf deine Steuer aus.'
                : 'Wenn Sie nur bei uns arbeiten, sind wir Ihr Hauptarbeitgeber — dann wählen Sie „Ja" und lassen das Feld darunter leer. Arbeiten Sie noch woanders, können Sie trotzdem nur bei einem Arbeitgeber den Hauptarbeitgeber haben. Ist das ein anderer, wählen Sie „Nein" und tragen ihn ein. Ein Minijob zählt dabei nicht mit. Sie wissen es nicht sicher? Fragen Sie uns kurz — die Angabe wirkt sich auf Ihre Steuer aus.',
            default => null,
        };
    }
}
