<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalSectionHints;

/**
 * Die zwei Erklaertexte, die im alten Portal fest im Blade standen
 * (employee-portal.blade.php:255-270) und zu keinem Feld gehoeren — keine
 * generische Feldruebernahme nimmt sie mit, kein Test hielt sie bisher fest.
 *
 * Der Arbeitgeber-Text ist der einzige Ort im Produkt, der den
 * Minijob-Sonderfall bei der Arbeitgeber-Frage erklaert (E15): faellt er
 * weg, entstehen falsche Steuerklassen ausgerechnet bei Schuelern und
 * Studenten.
 */
final class PortalSectionHintsTest extends TestCase
{
    public function test_arbeitgeber_text_nennt_den_minijob(): void
    {
        // E15: "Der Job, bei dem du am meisten verdienst" war sachlich falsch.
        // Ein 556-Euro-Minijob woanders zaehlt NICHT mit — bei Schuelern und
        // Studenten, also der Mehrheit der Zielgruppe, ist das der Normalfall.
        foreach ([true, false] as $duzen) {
            $text = PortalSectionHints::fuer('Arbeitgeber', $duzen);

            $this->assertNotNull($text);
            $this->assertStringContainsString('Minijob', $text);
            $this->assertStringNotContainsString('am meisten verdienst', $text);
            $this->assertStringNotContainsString('am meisten verdienen', $text);
        }
    }

    public function test_arbeitgeber_text_laedt_zum_nachfragen_ein(): void
    {
        $this->assertStringContainsString('Frag uns', PortalSectionHints::fuer('Arbeitgeber', true));
        $this->assertStringContainsString('Fragen Sie uns', PortalSectionHints::fuer('Arbeitgeber', false));
    }

    public function test_arbeitsschutz_text_nennt_beide_pflichten(): void
    {
        foreach ([true, false] as $duzen) {
            $text = PortalSectionHints::fuer('Arbeitsschutz', $duzen);

            $this->assertNotNull($text);
            $this->assertStringContainsString('Ersthelfer', $text);
            // Datum UND Schein — R15 verlangt beides.
            $this->assertMatchesRegularExpression('/g(ü|ue)ltig/i', $text);
            $this->assertStringContainsString('Schein', $text);
        }
    }

    public function test_alle_anderen_gruppen_haben_keinen_text(): void
    {
        $this->assertNull(PortalSectionHints::fuer('Bankdaten', true));
        $this->assertNull(PortalSectionHints::fuer('Kontakt', false));
    }

    public function test_du_und_sie_sind_wirklich_verschieden(): void
    {
        $this->assertNotSame(
            PortalSectionHints::fuer('Arbeitgeber', true),
            PortalSectionHints::fuer('Arbeitgeber', false),
        );
    }

    /**
     * Abnahmeprotokoll, kein zweiter Wahrheitsort: der Text IST die Vorgabe,
     * nicht die Ableitung von etwas anderem (anders als die Spaltenliste in
     * Aufgabe 1). Diese vier Zusicherungen halten die zum Commit-Zeitpunkt
     * geprueft-wortgleiche Fassung fest — wer sie aendert, aendert diesen
     * Test bewusst mit. Die Formulierung des Arbeitgeber-Texts wartet laut
     * Commit 0f9cffa noch auf eine Freigabe durch die Lohnabrechnung; bis
     * dahin bleibt sie woertlich so, auch hier.
     */
    public function test_wortlaut_ist_woertlich_festgenagelt(): void
    {
        $this->assertSame(
            'Bist du Ersthelfer? Wenn ja, trag bitte das Gültigkeitsdatum ein und lade deinen Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wähl einfach „Nein".',
            PortalSectionHints::fuer('Arbeitsschutz', true),
        );
        $this->assertSame(
            'Sind Sie Ersthelfer? Wenn ja, tragen Sie bitte das Gültigkeitsdatum ein und laden Sie Ihren Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wählen Sie einfach „Nein".',
            PortalSectionHints::fuer('Arbeitsschutz', false),
        );
        $this->assertSame(
            'Wenn du nur bei uns arbeitest, sind wir dein Hauptarbeitgeber — dann wähl „Ja" und lass das Feld darunter leer. Arbeitest du noch woanders, kannst du trotzdem nur bei einem Arbeitgeber der Hauptarbeitgeber sein. Ist das ein anderer, wähl „Nein" und trag ihn ein. Ein Minijob zählt dabei nicht mit. Du weißt es nicht sicher? Frag uns kurz — die Angabe wirkt sich auf deine Steuer aus.',
            PortalSectionHints::fuer('Arbeitgeber', true),
        );
        $this->assertSame(
            'Wenn Sie nur bei uns arbeiten, sind wir Ihr Hauptarbeitgeber — dann wählen Sie „Ja" und lassen das Feld darunter leer. Arbeiten Sie noch woanders, können Sie trotzdem nur bei einem Arbeitgeber den Hauptarbeitgeber haben. Ist das ein anderer, wählen Sie „Nein" und tragen ihn ein. Ein Minijob zählt dabei nicht mit. Sie wissen es nicht sicher? Fragen Sie uns kurz — die Angabe wirkt sich auf Ihre Steuer aus.',
            PortalSectionHints::fuer('Arbeitgeber', false),
        );
    }
}
