<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DuplicateApplicantGuard;

class DuplicateApplicantGuardDecideTest extends TestCase
{
    private function m(int $id, ?string $contactedAt = null): object
    {
        return (object) ['id' => $id, 'auto_pilot_last_reminder_at' => $contactedAt];
    }

    public function test_kein_match_sendet(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(5, null, []));
    }

    public function test_ein_kontaktierter_match_flaggt_auf_dessen_id(): void
    {
        $this->assertSame(9, DuplicateApplicantGuard::decide(5, null, [$this->m(9, '2026-07-15 13:21:20')]));
    }

    public function test_mehrere_matches_einer_kontaktiert_flaggt_auf_den_kontaktierten(): void
    {
        $matches = [$this->m(2), $this->m(9, '2026-07-15 13:21:20'), $this->m(11)];
        $this->assertSame(9, DuplicateApplicantGuard::decide(5, null, $matches));
    }

    public function test_mehrere_kontaktierte_flaggt_auf_kleinste_kontaktierte_id(): void
    {
        $matches = [$this->m(9, '2026-07-15 13:21:20'), $this->m(4, '2026-07-15 13:22:19')];
        $this->assertSame(4, DuplicateApplicantGuard::decide(5, null, $matches));
    }

    public function test_alle_unkontaktiert_kandidat_kleinste_id_sendet(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(3, null, [$this->m(7), $this->m(9)]));
    }

    public function test_alle_unkontaktiert_kleinere_match_id_flaggt(): void
    {
        $this->assertSame(3, DuplicateApplicantGuard::decide(7, null, [$this->m(3), $this->m(9)]));
    }

    public function test_senior_regel_ordnungsunabhaengig_genau_einer_sendet(): void
    {
        // Zwei frische Dubletten (IDs 10 und 20) — egal wer zuerst verarbeitet wird:
        // 10 sendet, 20 flaggt auf 10. Kein Doppel-Flag.
        $this->assertNull(DuplicateApplicantGuard::decide(10, null, [$this->m(20)]));
        $this->assertSame(10, DuplicateApplicantGuard::decide(20, null, [$this->m(10)]));
    }

    public function test_eigene_id_im_match_set_wird_ignoriert(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(5, null, [$this->m(5)]));
    }

    public function test_kandidat_kontaktiert_unkontaktierter_match_kleinerer_id_sendet(): void
    {
        // Reminder des Originals darf nicht durch später angelegten Datensatz blockieren:
        // kontaktiert schlaegt ID-Vergleich.
        $this->assertNull(DuplicateApplicantGuard::decide(8, '2026-07-15 13:21:20', [$this->m(2)]));
    }

    public function test_beide_kontaktiert_kleinere_id_remindert_weiter_groessere_flaggt(): void
    {
        // Bestandsfall #2378/#2379 im Reminder-Zweig.
        $this->assertNull(DuplicateApplicantGuard::decide(2378, '2026-07-16 01:22:19', [$this->m(2379, '2026-07-16 01:22:20')]));
        $this->assertSame(2378, DuplicateApplicantGuard::decide(2379, '2026-07-16 01:22:20', [$this->m(2378, '2026-07-16 01:22:19')]));
    }

    public function test_carbon_instanz_als_kandidat_kontakt_signal(): void
    {
        // Im Command kommt candidateLastReminderAt als Carbon (datetime-Cast,
        // RecApplicant $casts Zeile 65), Match-Rows aus dem JOIN als String —
        // beide müssen via !empty() äquivalent als "kontaktiert" fallen.
        $carbon = new \Carbon\Carbon('2026-07-15 13:21:20');
        $this->assertNull(DuplicateApplicantGuard::decide(8, $carbon, [$this->m(2)]));
        $this->assertSame(2, DuplicateApplicantGuard::decide(8, $carbon, [$this->m(2, '2026-07-15 13:00:00')]));
    }
}
