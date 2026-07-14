<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\WaitlistEnrollmentPlanner;

class WaitlistEnrollmentPlannerTest extends TestCase
{
    // --- resolveWunschorte ---

    public function test_resolve_filtert_leere_werte_und_reindexiert(): void
    {
        $this->assertSame(
            ['Köln', 'Bonn'],
            WaitlistEnrollmentPlanner::resolveWunschorte(['Köln', null, '', 'Bonn'], null)
        );
    }

    public function test_resolve_wrappt_skalar(): void
    {
        $this->assertSame(
            ['Köln'],
            WaitlistEnrollmentPlanner::resolveWunschorte('Köln', null)
        );
    }

    public function test_resolve_faellt_auf_primaeren_ort_zurueck(): void
    {
        $this->assertSame(
            ['Düsseldorf'],
            WaitlistEnrollmentPlanner::resolveWunschorte(null, 'Düsseldorf')
        );
        $this->assertSame(
            ['Düsseldorf'],
            WaitlistEnrollmentPlanner::resolveWunschorte(['', null], 'Düsseldorf')
        );
    }

    public function test_resolve_leer_ohne_fallback(): void
    {
        $this->assertSame([], WaitlistEnrollmentPlanner::resolveWunschorte(null, null));
        $this->assertSame([], WaitlistEnrollmentPlanner::resolveWunschorte([], ''));
    }

    // --- plan: kein offener Eintrag ---

    public function test_kein_eintrag_mit_orten_ergibt_create(): void
    {
        $this->assertSame(
            ['action' => 'create', 'wunschorte' => ['Köln']],
            WaitlistEnrollmentPlanner::plan(null, ['Köln'])
        );
    }

    public function test_kein_eintrag_ohne_orte_ergibt_noop(): void
    {
        // Kein matchbarer Ort → kein stiller Geister-Eintrag (heutiges Verhalten).
        $this->assertSame(
            ['action' => 'noop', 'wunschorte' => []],
            WaitlistEnrollmentPlanner::plan(null, [])
        );
    }

    // --- plan: offener Eintrag, noch nicht benachrichtigt ---

    public function test_wartender_eintrag_bleibt_unangetastet(): void
    {
        $this->assertSame(
            ['action' => 'noop', 'wunschorte' => []],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => false, 'wunschorte' => ['Köln']],
                ['Köln', 'Bonn']
            )
        );
    }

    // --- plan: offener Eintrag, bereits benachrichtigt → Re-Arm ---

    public function test_benachrichtigter_eintrag_wird_rearmed_mit_frischem_snapshot(): void
    {
        $this->assertSame(
            ['action' => 'rearm', 'wunschorte' => ['Bonn']],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => true, 'wunschorte' => ['Köln']],
                ['Bonn']
            )
        );
    }

    public function test_rearm_behaelt_alten_snapshot_wenn_aufloesung_leer(): void
    {
        // Wunschorte inzwischen nicht mehr auflösbar → alten (matchbaren)
        // Snapshot behalten statt ihn zu leeren.
        $this->assertSame(
            ['action' => 'rearm', 'wunschorte' => ['Köln']],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => true, 'wunschorte' => ['Köln']],
                []
            )
        );
    }
}
