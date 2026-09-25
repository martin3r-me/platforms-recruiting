<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalFieldRelevance;

/**
 * R28 — bedingte Pflicht (required_if), geloest aus RecEmployee::fieldIsRelevant()
 * heraus. Reine Logik: kein Framework, keine DB.
 *
 * Strikter Vergleich gegen den DATENSATZ, bewusst nicht gegen den
 * Formularwert: die Pflicht beschreibt den gespeicherten Zustand, nicht die
 * gerade getippte Absicht (anders als PortalFieldAccess::istSichtbar).
 */
final class PortalFieldRelevanceTest extends TestCase
{
    public function test_feld_ohne_bedingung_ist_immer_relevant(): void
    {
        $this->assertTrue(PortalFieldRelevance::istRelevant(['type' => 'text'], []));
    }

    public function test_strikter_vergleich_trennt_unbeantwortet_von_nein(): void
    {
        // R28: is_first_aider ist dreiwertig. Ein lockerer Vergleich wuerde
        // null (unbeantwortet) mit false (Nein) verwechseln — dann haette jeder
        // Nicht-Ersthelfer dauerhaft zwei rote Felder (E9).
        $meta = ['type' => 'date', 'required_if' => ['is_first_aider' => true]];

        $this->assertTrue(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => true]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => false]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => null]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => 1]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, []));
    }

    public function test_other_employer_ist_nur_bei_nein_pflicht(): void
    {
        $meta = ['type' => 'text', 'required_if' => ['is_main_employer' => false]];

        $this->assertTrue(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => false]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => true]));
        $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => null]));
    }
}
