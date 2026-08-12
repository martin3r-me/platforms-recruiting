<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingLeaderResolver;

/**
 * Die drei variablen Werte des Schulungszertifikats (Datum, Schulungsleiter)
 * kommen aus der massgeblichen Buchung. Diese Tests nageln fest, WELCHE
 * Buchung das ist und was passiert, wenn Daten fehlen oder krumm sind.
 *
 * Alles landet in einem Dokument, das ein Bewerber liest. Deshalb ist die
 * Leitlinie: lieber ein leeres Feld als ein falsches. Ein fehlender
 * Schulungsleiter ist ein legitimes Zertifikat, kein Fehlerfall — daher
 * ueberall leere Rueckgabe statt Exception.
 */
class TrainingLeaderResolverTest extends TestCase
{
    /** @return array{id: int, status: string, starts_at: ?string, interviewers: list<string>} */
    private function booking(int $id, string $status, ?string $startsAt, array $interviewers): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'starts_at' => $startsAt,
            'interviewers' => $interviewers,
        ];
    }

    // ---------------------------------------------------------------------
    // Der Kern: welche Buchung ist die massgebliche
    // ---------------------------------------------------------------------

    public function testSpaetesterTerminGewinntNichtDasJuengsteInsert(): void
    {
        // Der wertvollste Fall des Tasks. Umbuchungsfall: Buchung 9 wurde
        // SPAETER erfasst, hat aber ein FRUEHERES Termindatum. Auf dem
        // Dokument muss das spaeteste tatsaechliche Teilnahmedatum stehen,
        // nicht das juengste Insert — deshalb bewusst kein latest('id').
        $bookings = [
            $this->booking(3, 'attended', '2026-07-24 14:00:00', ['Spaeter Termin']),
            $this->booking(9, 'attended', '2026-06-02 14:00:00', ['Juengeres Insert']),
        ];

        $this->assertSame('Spaeter Termin', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testLeiterUndDatumStammenAusDerselbenBuchung(): void
    {
        // Die massgebliche Buchung hat KEINEN Interviewer, eine fruehere hat
        // einen. Es waere hilfsbereit und falsch, den Leiter dort zu holen:
        // dann stuende auf dem Dokument ein Leiter, der an diesem Termin nicht
        // geschult hat. Beide Werte kommen aus EINER Buchung oder gar nicht.
        $bookings = [
            $this->booking(2, 'attended', '2026-06-02 14:00:00', ['Frueherer Leiter']),
            $this->booking(3, 'attended', '2026-07-24 14:00:00', []),
        ];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testTieBreakUeberIdAbsteigend(): void
    {
        $bookings = [
            $this->booking(4, 'attended', '2026-07-24 14:00:00', ['Alt']),
            $this->booking(7, 'attended', '2026-07-24 14:00:00', ['Neu']),
        ];

        $this->assertSame('Neu', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testNurAttendedZaehlt(): void
    {
        $bookings = [
            $this->booking(1, 'no_show', '2026-07-01 14:00:00', ['Falsch']),
            $this->booking(2, 'attended', '2026-06-01 14:00:00', ['Richtig']),
        ];

        $this->assertSame('Richtig', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testBekanntesDatumSchlaegtUnbekanntes(): void
    {
        // Buchung ohne Termin darf die Auswahl nicht gewinnen, auch nicht mit
        // der hoeheren ID: ein bekanntes Datum ist mehr wert als keins.
        $bookings = [
            $this->booking(9, 'attended', null, ['Ohne Termin']),
            $this->booking(2, 'attended', '2026-07-24 14:00:00', ['Mit Termin']),
        ];

        $this->assertSame('Mit Termin', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testUnparsbaresDatumVerdraengtNichtDieGueltigeBuchung(): void
    {
        // Ein Vergleich der Rohstrings (strcmp) sortiert "kaputt" GANZ NACH
        // VORNE, weil "k" > "2" ist: die Muellbuchung gewinnt, das Datum wird
        // leer und der falsche Leiter steht auf dem Dokument — und nichts
        // wird rot. Ein unbrauchbares Datum ist ein unbekanntes Datum.
        $bookings = [
            $this->booking(5, 'attended', 'kaputt', ['Muell']),
            $this->booking(2, 'attended', '2026-07-24 14:00:00', ['Echt']),
        ];

        $this->assertSame('Echt', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testUnpadierterMonatWirdAlsDatumVerglichenNichtAlsZeichenkette(): void
    {
        // "2026-7-05" ist Juli, liegt also VOR Oktober. Als Zeichenkette
        // verglichen gewinnt es ("7" > "1") und das Zertifikat traegt den
        // frueheren Termin. Verglichen wird der Zeitpunkt, nicht der String.
        $bookings = [
            $this->booking(11, 'attended', '2026-7-05 10:00:00', ['Juli']),
            $this->booking(2, 'attended', '2026-10-01 10:00:00', ['Oktober']),
        ];

        $this->assertSame('Oktober', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('01.10.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    // ---------------------------------------------------------------------
    // Datum: Formatierung und die Faelle, in denen es leer bleiben muss
    // ---------------------------------------------------------------------

    public function testBuchungOhneTerminErgibtLeeresDatum(): void
    {
        $bookings = [$this->booking(1, 'attended', null, ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        // Der Leiter ist trotzdem bekannt — die Buchung bleibt waehlbar.
        $this->assertSame('X', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testLeerstringAlsTerminErgibtNichtHeute(): void
    {
        // new DateTimeImmutable('') und ('  ') liefern JETZT, nicht eine
        // Exception (gemessen, PHP 8.4). Ohne Leer-Guard traegt das Zertifikat
        // still das Ausstellungsdatum als Schulungsdatum.
        foreach (['', '   '] as $leer) {
            $bookings = [$this->booking(1, 'attended', $leer, ['X'])];

            $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        }
    }

    public function testMysqlNulldatumErgibtLeeresDatum(): void
    {
        // '0000-00-00 00:00:00' parst ohne Exception und formatiert zu
        // "30.11.-0001" (gemessen). Das darf nicht auf ein Dokument geraten.
        $bookings = [$this->booking(1, 'attended', '0000-00-00 00:00:00', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        $this->assertSame('X', TrainingLeaderResolver::leaderNames($bookings));
    }

    // ---------------------------------------------------------------------
    // Schulungsleiter
    // ---------------------------------------------------------------------

    public function testZweiInterviewerWerdenVerbunden(): void
    {
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', ['Michel Zimmer', 'Anna Bergmann'])];

        $this->assertSame('Michel Zimmer, Anna Bergmann', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testKeinInterviewerErgibtLeerenString(): void
    {
        // Kein Fehlerfall: ein Zertifikat ohne Schulungsleiter ist legitim.
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', [])];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testLeereNamenWerdenAussortiert(): void
    {
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', ['', '  ', 'Echt'])];

        $this->assertSame('Echt', TrainingLeaderResolver::leaderNames($bookings));
    }

    // ---------------------------------------------------------------------
    // Nichts da
    // ---------------------------------------------------------------------

    public function testKeineAttendedBuchungErgibtLeereStrings(): void
    {
        $bookings = [$this->booking(1, 'registered', '2026-07-24 14:00:00', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testLeereListe(): void
    {
        $this->assertSame('', TrainingLeaderResolver::leaderNames([]));
        $this->assertSame('', TrainingLeaderResolver::trainingDate([]));
    }

    public function testUnvollstaendigeBuchungGiltAlsFehlenderWert(): void
    {
        // Der Produzent (Task 8) baut diese Arrays von Hand. Vergisst er einen
        // Schluessel, darf das kein Rauschen und keine Exception werden:
        // kein 'status' heisst "nicht attended", kein 'interviewers' heisst
        // "kein Leiter bekannt". Deckt die beiden ??-Defaults ab; ohne sie
        // laeuft dieser Test in PHP-Warnungen (failOnWarning="true").
        $ohneStatus = [['id' => 5, 'starts_at' => '2026-08-01 10:00:00']];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($ohneStatus));
        $this->assertSame('', TrainingLeaderResolver::trainingDate($ohneStatus));

        $ohneInterviewer = [['id' => 6, 'status' => 'attended', 'starts_at' => '2026-07-24 14:00:00']];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($ohneInterviewer));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($ohneInterviewer));
    }

    public function testPickBookingLiefertDieMassgeblicheBuchungUndSonstNull(): void
    {
        // pickBooking() ist Teil der Schnittstelle (Task 8 braucht die Buchung
        // selbst, nicht nur die zwei Strings) und wird hier direkt geprueft.
        $bookings = [
            $this->booking(3, 'attended', '2026-07-24 14:00:00', ['Richtig']),
            $this->booking(9, 'attended', '2026-06-02 14:00:00', ['Falsch']),
            $this->booking(12, 'no_show', '2026-08-01 14:00:00', ['Auch falsch']),
        ];

        $picked = TrainingLeaderResolver::pickBooking($bookings);

        $this->assertIsArray($picked);
        $this->assertSame(3, $picked['id']);
        $this->assertNull(TrainingLeaderResolver::pickBooking([]));
        $this->assertNull(TrainingLeaderResolver::pickBooking([
            $this->booking(1, 'registered', '2026-07-24 14:00:00', ['X']),
        ]));
    }
}
