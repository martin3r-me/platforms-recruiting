<?php
// tests/Unit/MessageCooldownTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\MessageCooldown;

/**
 * Vorfall 15.09.2026: die Kampagne „Neue Termine" schloss beim Versand den
 * offenen Ort-Wartelisten-Eintrag (SendNewDatesCampaign::closeOrtWaitlist) und
 * loeste damit die Bremse, die den Auto-Piloten bis dahin pausiert hatte
 * (ProcessAutoPilotApplicants:171). Weil der Versand bewusst KEIN Re-Arm macht,
 * blieb auto_pilot_last_reminder_at auf dem alten, laengst faelligen Stand —
 * der naechste Lauf schickte die Erinnerung „wir warten noch auf deine
 * Rueckmeldung" also Sekunden hinter die Kampagnen-Nachricht.
 *
 * Belege aus den Comms-Logs des Tages:
 *   Template B (statistik_p1_4): 174 von 355 Empfaengern bekamen zusaetzlich
 *   den reminder, 171 davon binnen 8-30 Sekunden.
 *   Template A (statistik_p1):     1 von 129 — der Unterschied ist genau die
 *   Warteliste, auf der nur das Buchungs-Segment sitzt.
 *
 * Regel jetzt: der Auto-Pilot schweigt, solange die letzte ausgehende
 * Nachricht an diesen Bewerber juenger als der Cooldown ist — egal, wer sie
 * geschickt hat (Auto-Pilot, Kampagne, Warteliste).
 */
final class MessageCooldownTest extends TestCase
{
    public function testSperrtWennDieLetzteNachrichtJuengerAlsDerCooldownIst(): void
    {
        $this->assertTrue(MessageCooldown::blocks('2026-09-15 19:08:06', 24, '2026-09-15 19:08:31'));
    }

    public function testGibtFreiWennDerCooldownAbgelaufenIst(): void
    {
        $this->assertFalse(MessageCooldown::blocks('2026-09-15 19:08:06', 24, '2026-09-16 19:08:06'));
    }

    /** Wer noch nie angeschrieben wurde, wartet nicht — der Erstkontakt geht sofort raus. */
    public function testOhneVorherigeNachrichtKeineSperre(): void
    {
        $this->assertFalse(MessageCooldown::blocks(null, 24, '2026-09-15 19:08:31'));
    }

    /** Cooldown 0 schaltet den Waechter ab (Notausgang ohne Deploy). */
    public function testCooldownNullSchaltetDenWaechterAb(): void
    {
        $this->assertFalse(MessageCooldown::blocks('2026-09-15 19:08:06', 0, '2026-09-15 19:08:31'));
    }

    /** Die Sperre endet exakt mit der letzten Cooldown-Sekunde, nicht danach. */
    public function testGrenzeLiegtGenauAufDerCooldownStunde(): void
    {
        $this->assertTrue(MessageCooldown::blocks('2026-09-15 19:00:00', 24, '2026-09-16 18:59:59'));
        $this->assertFalse(MessageCooldown::blocks('2026-09-15 19:00:00', 24, '2026-09-16 19:00:00'));
    }
}
