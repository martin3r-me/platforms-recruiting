<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofReminderPlanner;

final class ProofReminderPlannerTest extends TestCase
{
    private function nachweis(array $a = []): array
    {
        return array_merge([
            'id' => 1, 'rec_employee_id' => 10, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-10', 'reminded_at' => null, 'superseded_at' => null,
        ], $a);
    }

    public function test_erinnert_innerhalb_der_vorlaufzeit(): void
    {
        // ausweis: 30 Tage Vorlauf
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-10-10'])], '2026-09-24', null);
        $this->assertCount(1, $plan);
    }

    public function test_erinnert_nicht_zu_frueh(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2027-05-01'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_erinnert_nur_einmal(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['reminded_at' => '2026-09-20 08:00:00'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_abgeloeste_fassung_zaehlt_nicht(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['superseded_at' => '2026-09-01 10:00:00'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_stichtag_haelt_den_altbestand_zurueck(): void
    {
        // Das ist die Bremse gegen die 500-WhatsApp-Welle.
        $plan = ProofReminderPlanner::plan([
            $this->nachweis(['id' => 1, 'valid_until' => '2026-08-01']),   // vor dem Stichtag
            $this->nachweis(['id' => 2, 'valid_until' => '2026-10-10']),   // danach
        ], '2026-09-24', '2026-10-01');

        $this->assertSame([2], array_column($plan, 'proof_id'));
    }

    public function test_ohne_stichtag_kommt_auch_der_altbestand(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-08-01'])], '2026-09-24', null);
        $this->assertCount(1, $plan);
    }

    public function test_arten_ohne_ablauf_kommen_nie(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['proof_type_code' => 'selfie', 'valid_until' => null])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_datum_mit_zeitanteil_wird_wie_ein_tag_behandelt(): void
    {
        // Eloquent-date-Cast liefert ueber toArray() ein ISO-8601-Format.
        // Das ist lexikografisch GROESSER als Y-m-d. Ohne Normalisierung
        // verschoebe sich diese Erinnerung um einen Tag.
        $plan = ProofReminderPlanner::plan(
            [$this->nachweis(['valid_until' => '2026-10-24T00:00:00.000000Z'])],
            '2026-09-24',
            null
        );
        $this->assertCount(1, $plan);
    }

    public function test_unlesbares_datum_wird_uebersprungen_statt_geraten(): void
    {
        // Ein nicht-standardisiertes Datum wird uebersprungen, nicht geraten.
        $plan = ProofReminderPlanner::plan(
            [$this->nachweis(['valid_until' => 'demnaechst'])],
            '2026-09-24',
            null
        );
        $this->assertSame([], $plan);
    }

    public function test_genau_am_stichtag_wird_noch_erinnert(): void
    {
        // Die Stichtagsgrenze ist $bis < $stichtag, nicht <=.
        // Genau auf dem Stichtag wird erinnert.
        $plan = ProofReminderPlanner::plan([
            $this->nachweis(['id' => 1, 'valid_until' => '2026-09-30']),   // einen Tag davor
            $this->nachweis(['id' => 2, 'valid_until' => '2026-10-01']),   // genau auf dem Stichtag
        ], '2026-09-24', '2026-10-01');

        $this->assertSame([2], array_column($plan, 'proof_id'));
    }

    public function test_unlesbares_heute_kracht_statt_still_falsch_zu_rechnen(): void
    {
        // Ein unlesbares $heute ist ein Programmierfehler beim Aufrufer.
        // Das wird nicht toleriert — wir krachen, nicht still gegen Serverzeit.
        $this->expectException(\InvalidArgumentException::class);
        ProofReminderPlanner::plan(
            [$this->nachweis()],
            'heute',
            null
        );
    }

    /**
     * Kundenfeedback 24.09.2026: "unbefristet" speichert valid_until = null bei
     * einer Art, die grundsaetzlich einen Ablauf hat (aufenthaltstitel, anders
     * als 'selfie' im Test oben, das von Haus aus keinen Ablauf kennt). Ohne
     * Datum gibt es nichts, wofuer eine Erinnerung faellig werden koennte.
     */
    public function test_unbefristeter_nachweis_wird_nie_erinnert(): void
    {
        $plan = ProofReminderPlanner::plan(
            [$this->nachweis(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => null])],
            '2026-09-24',
            null
        );
        $this->assertSame([], $plan);
    }

    public function test_unlesbarer_stichtag_schaltet_nur_die_bremse_ab(): void
    {
        // Ein unlesbares $stichtag ist kein Fehler — es heisst nur,
        // dass die Bremse nicht greift. Der Nachweis kommt in den Plan.
        $plan = ProofReminderPlanner::plan(
            [$this->nachweis(['valid_until' => '2026-08-01'])],
            '2026-09-24',
            'irgendwann'
        );
        $this->assertCount(1, $plan);
    }
}
