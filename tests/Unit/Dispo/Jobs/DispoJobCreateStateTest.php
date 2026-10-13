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
            'anlass' => 'vier_wochen',
            'template_id' => '7',
            'vorlagen_problem' => null,
            'gruppen' => ['0'],
            'bloecke' => ['200'],
            'modus' => 'sammeln',
        ];
    }

    public function testSchritteHabenDreiNamen(): void
    {
        $this->assertSame([1 => 'Was suchst du?', 2 => 'Schichten', 3 => 'Senden'], DispoJobCreateState::schritte());
    }

    public function testGueltigerZustandLaesstJedenSchrittDurch(): void
    {
        foreach ([1, 2, 3] as $s) {
            $this->assertNull(DispoJobCreateState::kannWeiter($s, self::state()), "Schritt $s");
        }
    }

    public function testSchritt1PrueftFilialeZeitraumUndVeranstaltungenInDieserReihenfolge(): void
    {
        $this->assertSame('Bitte wähle eine Filiale.', DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => '', 'von' => '', 'event_ids' => []])));
        $this->assertSame('Bitte wähle eine Filiale.', DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => 'abc'])));
        $this->assertSame('Bitte gib ein gültiges Von- und Bis-Datum an.', DispoJobCreateState::kannWeiter(1, self::state(['von' => '', 'event_ids' => []])));
        $this->assertSame('Der Zeitraum darf nicht in der Vergangenheit beginnen.', DispoJobCreateState::kannWeiter(1, self::state(['von' => '2026-10-11'])));
        $this->assertSame('Das Bis-Datum liegt vor dem Von-Datum.', DispoJobCreateState::kannWeiter(1, self::state(['bis' => '2026-10-12'])));
        $this->assertNull(DispoJobCreateState::kannWeiter(1, self::state(['von' => '2026-10-12', 'bis' => '2026-10-12'])), 'heute ist erlaubt');
        $this->assertSame('Bitte wähle mindestens eine Veranstaltung.', DispoJobCreateState::kannWeiter(1, self::state(['event_ids' => []])));
        $this->assertSame('Bitte wähle mindestens eine Veranstaltung.', DispoJobCreateState::kannWeiter(1, self::state(['event_ids' => ['0', 'x']])));
    }

    public function testZeitraumProblemIstOeffentlichFuerDieLiveListe(): void
    {
        $this->assertNull(DispoJobCreateState::zeitraumProblem(self::state()));
        $this->assertNotNull(DispoJobCreateState::zeitraumProblem(self::state(['von' => '2026-02-30'])));
    }

    public function testSchritt2BrauchtEineGesuchteSchicht(): void
    {
        // Mutationsprobe: ueberall 0 / leer → Fehler.
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['gesucht' => ['a' => '0', 'b' => '', 'c' => 0]])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['gesucht' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['gesucht' => ['a' => '-2']])));
        // eine andere Schicht reicht auch
        $this->assertNull(DispoJobCreateState::kannWeiter(2, self::state(['gesucht' => [], 'andere' => [['gesucht' => '2']]])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(2, self::state(['gesucht' => [], 'andere' => [['gesucht' => '0']]])));
    }

    public function testSchritt3BrauchtGueltigenAnlass(): void
    {
        $this->assertSame('Bitte wähle einen Anlass.', DispoJobCreateState::kannWeiter(3, self::state(['anlass' => ''])));
        $this->assertSame('Bitte wähle einen Anlass.', DispoJobCreateState::kannWeiter(3, self::state(['anlass' => 'irgendwann'])));
        $this->assertSame('Bitte wähle einen Anlass.', DispoJobCreateState::kannWeiter(3, array_diff_key(self::state(), ['anlass' => 1])));
        foreach (['vier_wochen', 'naechste_woche', 'dringend'] as $a) {
            $this->assertNull(DispoJobCreateState::kannWeiter(3, self::state(['anlass' => $a])), $a);
        }
    }

    public function testSchritt3AnlassOhneHinterlegteVorlageSperrt(): void
    {
        $this->assertSame(
            'Für diesen Anlass ist noch keine Vorlage hinterlegt — bitte in den Einstellungen hinterlegen.',
            DispoJobCreateState::kannWeiter(3, self::state(['template_id' => '']))
        );
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['template_id' => null])));
    }

    public function testSchritt3BrauchtVorlageOhneProblemUndEmpfaengerAuswahl(): void
    {
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['template_id' => ''])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['vorlagen_problem' => 'kaputt'])));
        $this->assertSame('kaputt', DispoJobCreateState::kannWeiter(3, self::state(['vorlagen_problem' => 'kaputt'])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['gruppen' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['bloecke' => []])));
        $this->assertNotNull(DispoJobCreateState::kannWeiter(3, self::state(['modus' => 'egal'])));
    }

    public function testAllesPrueftAlleSchritteDerReiheNach(): void
    {
        $this->assertNull(DispoJobCreateState::alles(self::state()));
        $this->assertSame(DispoJobCreateState::kannWeiter(1, self::state(['event_ids' => []])),
            DispoJobCreateState::alles(self::state(['event_ids' => [], 'template_id' => ''])));
    }

    public function testHakenBleibenNurFuerNochGelisteteVeranstaltungen(): void
    {
        $this->assertSame(['5', '9'], DispoJobCreateState::hakenAngleichen(['5', 7, '9'], [5, 9, 11]));
        $this->assertSame([], DispoJobCreateState::hakenAngleichen(['5'], []));
        $this->assertSame([], DispoJobCreateState::hakenAngleichen([], [5]));
    }
}
