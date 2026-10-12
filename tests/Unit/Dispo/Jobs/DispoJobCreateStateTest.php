<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobCreateState;

final class DispoJobCreateStateTest extends TestCase
{
    private static function state(array $o = []): array
    {
        return $o + [
            'heute' => '2026-10-12',
            'filial_nr' => '200',
            'von' => '2026-10-13',
            'bis' => '2026-11-09',
            'event_ids' => [5],
            'gesucht' => ['sabc' => '3'],
            'andere' => [],
            'template_id' => '7',
            'vorlagen_problem' => null,
            'gruppen' => ['0'],
            'bloecke' => ['200'],
            'modus' => 'sammeln',
        ];
    }

    public function testSchritteHabenFuenfNamen(): void
    {
        $this->assertSame([1 => 'Filiale', 2 => 'Zeitraum', 3 => 'Veranstaltungen', 4 => 'Schichten', 5 => 'Empfänger'], DispoJobCreateState::schritte());
    }

    public function testGueltigerZustandLaesstJedenSchrittDurch(): void
    {
        foreach ([1, 2, 3, 4, 5] as $s) {
            $this->assertNull(DispoJobCreateState::kannWeiter($s, self::state()), "Schritt $s");
        }
    }

    public function testSchritt1BrauchtEineFiliale(): void
    {
        $this->assertNotNull(DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => ''])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => 'abc'])));
    }

    public function testSchritt2PrueftDenZeitraum(): void
    {
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['von' => ''])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['von' => '2026-02-30'])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['von' => '2026-10-11'])), 'Vergangenheit');
        $this->assertNull(DispoJobCreateState::kannWeiter(2, self::state(['von' => '2026-10-12', 'bis' => '2026-10-12'])), 'heute ist erlaubt');
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['bis' => '2026-10-12'])), 'bis vor von');
    }

    public function testSchritt3BrauchtEineVeranstaltung(): void
    {
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['event_ids' => []])));
    }

    public function testSchritt4BrauchtEineGesuchteSchicht(): void
    {
        // Mutationsprobe: ueberall 0 / leer → Fehler.
        $this->assertNotNull(DispoJobCreateState::kannWeiter(4, self::state(['gesucht' => ['a' => '0', 'b' => '', 'c' => 0]])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(4, self::state(['gesucht' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(4, self::state(['gesucht' => ['a' => '-2']])));
        // eine andere Schicht reicht auch
        $this->assertNull(DispoJobCreateState::kannWeiter(4, self::state(['gesucht' => [], 'andere' => [['gesucht' => '2']]])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(4, self::state(['gesucht' => [], 'andere' => [['gesucht' => '0']]])));
    }

    public function testSchritt5BrauchtVorlageOhneProblemUndEmpfaengerAuswahl(): void
    {
        $this->assertNotNull(DispoJobCreateState::kannWeiter(5, self::state(['template_id' => ''])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(5, self::state(['vorlagen_problem' => 'kaputt'])));
        $this->assertSame('kaputt', DispoJobCreateState::kannWeiter(5, self::state(['vorlagen_problem' => 'kaputt'])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(5, self::state(['gruppen' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(5, self::state(['bloecke' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(5, self::state(['modus' => 'egal'])));
    }

    public function testAllesPrueftAlleSchritteDerReiheNach(): void
    {
        $this->assertNull(DispoJobCreateState::alles(self::state()));
        $this->assertSame(DispoJobCreateState::kannWeiter(3, self::state(['event_ids' => []])),
            DispoJobCreateState::alles(self::state(['event_ids' => [], 'template_id' => ''])));
    }
}
