<?php
// tests/Unit/AutoPilotCooldownWiringTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\MessageCooldown;

/**
 * Platzierungs-Vertrag, KEIN Verhaltenstest.
 *
 * Die Entscheidung selbst liegt in MessageCooldown und ist dort echt getestet
 * (MessageCooldownTest, MessageCooldownLookupTest). Was hier abgesichert wird,
 * ist die Verdrahtung: dass ProcessAutoPilotApplicants den Waechter ueberhaupt
 * fragt — und zwar VOR beiden Sende-Zweigen. Ein Waechter, der hinter dem
 * Versand steht, ist genau der Fehler, den der Vorfall 15.09.2026 gezeigt hat.
 *
 * Warum kein Lauf gegen das echte Command: processApplicant() ruft als erstes
 * calculateProgress(), das die Extra-Field-Tabellen aus platforms-core braucht.
 * Die stehen in dieser Suite (handgebauter Container + SQLite, kein Laravel-
 * Bootstrap) nicht zur Verfuegung — der Lauf stuerbe ab, bevor er den Waechter
 * ueberhaupt erreicht. Solange das so ist, ist die Quelle der ehrlichste
 * verfuegbare Beleg; er faellt auf, wenn jemand den Aufruf entfernt oder
 * verschiebt.
 */
final class AutoPilotCooldownWiringTest extends TestCase
{
    private function command(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/ProcessAutoPilotApplicants.php');
    }

    public function testDasCommandFragtDenWaechter(): void
    {
        $src = $this->command();

        $this->assertStringContainsString('MessageCooldown::lastOutboundAt', $src, 'Command liest die letzte ausgehende Nachricht nicht');
        $this->assertStringContainsString('MessageCooldown::blocks', $src, 'Command fragt den Cooldown nicht');
    }

    public function testDerWaechterStehtVorBeidenSendeZweigen(): void
    {
        $src = $this->command();

        $waechter = strpos($src, 'MessageCooldown::blocks');
        $erstkontakt = strpos($src, "'template_sent', \"Erstkontakt per");
        $erinnerung = strpos($src, "'reminder_sent', \"Erinnerung");

        $this->assertNotFalse($waechter);
        $this->assertNotFalse($erstkontakt, 'Erstkontakt-Zweig nicht gefunden — Test anpassen, nicht loeschen');
        $this->assertNotFalse($erinnerung, 'Erinnerungs-Zweig nicht gefunden — Test anpassen, nicht loeschen');

        $this->assertLessThan($erstkontakt, $waechter, 'Cooldown muss vor dem Erstkontakt greifen');
        $this->assertLessThan($erinnerung, $waechter, 'Cooldown muss vor der Erinnerung greifen');
    }

    /**
     * Der Cooldown ist ueber Team/Position/Phase einstellbar — sonst laesst er
     * sich im Zwischenfall nicht ohne Deploy abschalten.
     */
    public function testDieStundenSindEineEinstellungMitDefault(): void
    {
        $this->assertArrayHasKey(MessageCooldown::SETTING_KEY, RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertSame(24, RecApplicantSettings::DEFAULT_SETTINGS[MessageCooldown::SETTING_KEY]);
        // Das Command referenziert die Konstante, nicht den Literal — damit ein
        // Umbenennen des Keys an einer Stelle passiert.
        $this->assertStringContainsString('MessageCooldown::SETTING_KEY', $this->command(), 'Command liest die Einstellung nicht');
    }
}
