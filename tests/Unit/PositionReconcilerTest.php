<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\PositionReconciler;

class PositionReconcilerTest extends TestCase
{
    // Köln-Phasen: order 1..4 → IDs 30..33. Düsseldorf-Phase 26 = order 2.
    private const KOELN_PHASES = [1 => 30, 2 => 31, 3 => 32, 4 => 33];

    /**
     * Der Matti-Fall: Phase steht auf Düsseldorf (Pos 8, order 2), primäre
     * Stelle ist aber Köln (Pos 9) ohne Owner. Erwartung: Phase wird auf Kölns
     * order-2-Phase (31) gemappt, Owner folgt der Köln-Stelle (Clara = 7).
     */
    public function test_desynced_applicant_is_remapped_to_primary_position(): void
    {
        $r = PositionReconciler::resolve(
            currentPhasePositionId: 8,   // Düsseldorf
            currentPhaseOrder: 2,
            primaryPositionId: 9,        // Köln
            orderToActivePhaseId: self::KOELN_PHASES,
            currentOwnerId: null,
            primaryPositionOwnerId: 7,   // Clara
            defaultContactId: null,
            teamOwnerId: 1,
        );

        $this->assertTrue($r['position_changed']);
        $this->assertSame(31, $r['phase_id']);
        $this->assertSame(7, $r['owner_id']);
    }

    public function test_position_change_owner_follows_new_position_even_if_set(): void
    {
        // Bewerber hatte Düsseldorf-Owner (9), landet auf Köln → Owner wird Clara (7).
        $r = PositionReconciler::resolve(8, 1, 9, self::KOELN_PHASES, 9, 7, null, 1);

        $this->assertTrue($r['position_changed']);
        $this->assertSame(30, $r['phase_id']); // order 1 → 30
        $this->assertSame(7, $r['owner_id']);
    }

    public function test_position_change_without_same_order_falls_back_to_first_phase(): void
    {
        // Aktuelle Phase order 7 existiert in der Zielstelle nicht → erste Phase (30).
        $r = PositionReconciler::resolve(8, 7, 9, self::KOELN_PHASES, null, 7, null, 1);

        $this->assertSame(30, $r['phase_id']);
    }

    public function test_position_change_without_current_phase_uses_first_phase(): void
    {
        // Frisch verknüpfter Bewerber ohne Phase → erste Phase der Stelle.
        $r = PositionReconciler::resolve(null, null, 9, self::KOELN_PHASES, null, 7, null, 1);

        $this->assertTrue($r['position_changed']);
        $this->assertSame(30, $r['phase_id']);
        $this->assertSame(7, $r['owner_id']);
    }

    public function test_target_position_without_active_phases_leaves_phase_untouched(): void
    {
        $r = PositionReconciler::resolve(8, 2, 9, [], null, 7, null, 1);

        $this->assertNull($r['phase_id']); // keine Änderung
        $this->assertSame(7, $r['owner_id']);
    }

    public function test_consistent_applicant_with_owner_is_left_alone(): void
    {
        // Phase gehört bereits zur primären Stelle, Owner gesetzt → keine Änderung.
        $r = PositionReconciler::resolve(9, 2, 9, self::KOELN_PHASES, 7, 7, null, 1);

        $this->assertFalse($r['position_changed']);
        $this->assertNull($r['phase_id']);
        $this->assertNull($r['owner_id']);
    }

    public function test_consistent_applicant_with_empty_owner_is_filled(): void
    {
        // Keine Stellenänderung, aber Owner leer → aus Stellen-Owner auffüllen.
        $r = PositionReconciler::resolve(9, 2, 9, self::KOELN_PHASES, null, 7, null, 1);

        $this->assertFalse($r['position_changed']);
        $this->assertNull($r['phase_id']);
        $this->assertSame(7, $r['owner_id']);
    }

    public function test_consistent_empty_owner_falls_back_to_default_then_team(): void
    {
        // Stelle ohne Owner → Default-Kontakt; ohne Default → Team-Owner.
        $this->assertSame(3, PositionReconciler::resolve(9, 2, 9, self::KOELN_PHASES, null, null, 3, 1)['owner_id']);
        $this->assertSame(1, PositionReconciler::resolve(9, 2, 9, self::KOELN_PHASES, null, null, null, 1)['owner_id']);
    }

    public function test_owner_never_overwritten_to_null_when_no_candidate(): void
    {
        // Kein Kandidat irgendwo → owner_id bleibt "keine Änderung" (null).
        $r = PositionReconciler::resolve(9, 2, 9, self::KOELN_PHASES, 7, null, null, null);

        $this->assertNull($r['owner_id']); // 7 bleibt erhalten (keine Änderung)
    }

    public function test_same_order_or_first_helper(): void
    {
        $this->assertSame(31, PositionReconciler::sameOrderOrFirst(2, self::KOELN_PHASES));
        $this->assertSame(30, PositionReconciler::sameOrderOrFirst(99, self::KOELN_PHASES));
        $this->assertSame(30, PositionReconciler::sameOrderOrFirst(null, self::KOELN_PHASES));
        $this->assertNull(PositionReconciler::sameOrderOrFirst(2, []));
    }
}
