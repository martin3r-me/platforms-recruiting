<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Spec §5.2 als Tabellen-Test. Die Regel ist positions-agnostisch: sie kennt
 * nur die relative Lage zum Buchungsschritt, keine MGL-Phasen-IDs.
 */
final class CampaignSegmentTest extends TestCase
{
    /** MGL: P1 fields, P2 booking, P3 fields, P4 contract_sent. */
    private const MGL = [
        ['order' => 1, 'completion_type' => 'fields', 'completion_config' => null, 'is_active' => true],
        ['order' => 2, 'completion_type' => 'booking', 'completion_config' => ['waitlist_enabled' => true], 'is_active' => true],
        ['order' => 3, 'completion_type' => 'fields', 'completion_config' => ['confirm_booking_on_completion' => true], 'is_active' => true],
        ['order' => 4, 'completion_type' => 'contract_sent', 'completion_config' => ['creates_employee_on_completion' => true], 'is_active' => true],
    ];

    /** Legacy (Position 1): zwei fields-Phasen, Buchungslink am Ende von P2. */
    private const LEGACY = [
        ['order' => 1, 'completion_type' => 'fields', 'completion_config' => null, 'is_active' => true],
        ['order' => 2, 'completion_type' => 'fields', 'completion_config' => ['send_booking_notification_on_completion' => true], 'is_active' => true],
    ];

    private function input(array $override = []): array
    {
        return array_merge([
            'phase_order' => 2,
            'booking_order' => 2,
            'has_phone' => true,
            'has_active_booking' => false,
            'on_hr_desk' => false,
            'last_campaign_at' => null,
            'now' => '2026-08-28 12:00:00',
            'cancelled_bookings' => [],
            'waitlist' => null,
        ], $override);
    }

    public function testBuchungsschrittMgl(): void
    {
        $this->assertSame(2, CampaignSegment::bookingOrder(self::MGL));
    }

    public function testBuchungsschrittLegacyIstNachDerSendePhase(): void
    {
        $this->assertSame(3, CampaignSegment::bookingOrder(self::LEGACY));
    }

    public function testBuchungsschrittOhnePhasenIstEins(): void
    {
        $this->assertSame(1, CampaignSegment::bookingOrder([]));
    }

    public function testInaktivePhasenZaehlenNicht(): void
    {
        $phases = self::MGL;
        $phases[1]['is_active'] = false; // booking-Phase aus
        // Kein booking, kein send-Flag → letzte aktive Phase + 1 = 5
        $this->assertSame(5, CampaignSegment::bookingOrder($phases));
    }

    /** @return array<string, array{array, array}> */
    public static function faelle(): array
    {
        return [
            'P1 → A, angehakt, Badge unvollstaendig' => [
                ['phase_order' => 1],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'P2 → B, angehakt, kein Badge' => [
                ['phase_order' => 2],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => []],
            ],
            'P3 mit HR-Storno → B, angehakt, Badge' => [
                ['phase_order' => 3, 'cancelled_bookings' => [['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-26 17:35:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Storniert am 26.08.2026 (HR)']],
            ],
            'P3 mit Selbst-Storno → B, angehakt, Badge' => [
                ['phase_order' => 3, 'cancelled_bookings' => [['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-25 09:00:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Storniert am 25.08.2026 (Bewerber)']],
            ],
            'P4 Selbst-Storno → B, ABGEHAKT' => [
                ['phase_order' => 4, 'cancelled_bookings' => [['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-26 12:50:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin selbst storniert am 26.08.2026']],
            ],
            'P4 HR-Storno → B, ABGEHAKT' => [
                ['phase_order' => 4, 'cancelled_bookings' => [['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-20 08:00:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['HR-Storno am 20.08.2026']],
            ],
            'P4 ohne Storno-Info → B, abgehakt, generisches Badge' => [
                ['phase_order' => 4],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin storniert']],
            ],
            'juengster Storno gewinnt' => [
                ['phase_order' => 4, 'cancelled_bookings' => [
                    ['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-01 08:00:00'],
                    ['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-19 08:00:00'],
                ]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin selbst storniert am 19.08.2026']],
            ],
            'kein Telefon → nicht waehlbar' => [
                ['phase_order' => 2, 'has_phone' => false],
                ['template' => 'B', 'selectable' => false, 'checked' => false, 'badges' => ['kein Telefon']],
            ],
            'inzwischen gebucht → nicht waehlbar' => [
                ['phase_order' => 2, 'has_active_booking' => true],
                ['template' => 'B', 'selectable' => false, 'checked' => false, 'badges' => ['hat inzwischen gebucht']],
            ],
            'HR-Schreibtisch → abgehakt' => [
                ['phase_order' => 2, 'on_hr_desk' => true],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['HR-Schreibtisch']],
            ],
            'Kampagne vor 3 Tagen → abgehakt' => [
                ['phase_order' => 2, 'last_campaign_at' => '2026-08-25 10:00:00'],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['angeschrieben am 25.08.2026']],
            ],
            'Kampagne vor 15 Tagen → angehakt, Badge bleibt' => [
                ['phase_order' => 2, 'last_campaign_at' => '2026-08-13 10:00:00'],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['angeschrieben am 13.08.2026']],
            ],
            'Warteliste → Badge, Default bleibt' => [
                ['phase_order' => 2, 'waitlist' => ['enrolled_at' => '2026-07-10 09:00:00', 'notified_at' => '2026-07-15 09:00:00']],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Warteliste seit 10.07.2026, benachrichtigt am 15.07.2026']],
            ],
            'Warteliste ohne Benachrichtigung' => [
                ['phase_order' => 2, 'waitlist' => ['enrolled_at' => '2026-08-27 09:00:00', 'notified_at' => null]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Warteliste seit 27.08.2026']],
            ],
            'ohne Phase → wie P1' => [
                ['phase_order' => null],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'Legacy P2 (booking_order 3) → A' => [
                ['phase_order' => 2, 'booking_order' => 3],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'Badge-Reihenfolge: Phase, dann Ueberlagerungen' => [
                ['phase_order' => 1, 'on_hr_desk' => true, 'last_campaign_at' => '2026-08-27 10:00:00'],
                ['template' => 'A', 'selectable' => true, 'checked' => false, 'badges' => ['Bewerbung unvollständig', 'HR-Schreibtisch', 'angeschrieben am 27.08.2026']],
            ],
        ];
    }

    #[DataProvider('faelle')]
    public function testKlassifikation(array $override, array $expected): void
    {
        $this->assertSame($expected, CampaignSegment::classify($this->input($override)));
    }

    public function testAuswahlSchnittNurKohorteUndWaehlbar(): void
    {
        $selection = ['1' => true, 2 => true, 3 => false, 4 => true, 99 => true];
        $drillIds = [1, 2, 3, 4];
        $selectable = [1, 2, 3];

        $this->assertSame([1, 2], CampaignSegment::selectedIds($selection, $drillIds, $selectable));
    }
}
