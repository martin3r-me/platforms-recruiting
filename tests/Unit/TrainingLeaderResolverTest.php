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

    /**
     * Sammelt die PHP-Warnungen, die $fn auslöst, damit ein Guard, dessen
     * einzige Wirkung "keine Warnung" ist, eine ECHTE Assertion bekommt.
     *
     * Ohne das waeren die betroffenen Guards nur ueber failOnWarning="true" in
     * der phpunit.xml rot — laeuft die Suite mal ohne das Flag, sind sie
     * ungeschuetzt, und die Bannerzeile "OK, but there were issues!" liest
     * jeder als gruen.
     *
     * @return list<string>
     */
    private function warnungenBeim(callable $fn): array
    {
        $warnungen = [];

        set_error_handler(function (int $no, string $str) use (&$warnungen): bool {
            $warnungen[] = $str;

            return true;
        });

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $warnungen;
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

    public function testBeideDatenUnbrauchbarEntscheidetDieHoehereId(): void
    {
        // Sind BEIDE Termine unbrauchbar, sind beide gleich unbekannt — dann
        // muss der Tie-Break greifen, nicht die Ladereihenfolge. Die kleinere
        // Id steht bewusst VORNE: mit "return 0" fuer den Fall (beide Daten
        // unbrauchbar) laesst der stabile usort sie stehen und der falsche
        // Leiter kommt aufs Zertifikat, ohne dass etwas rot wird.
        $bookings = [
            $this->booking(4, 'attended', null, ['Kleinere Id']),
            $this->booking(7, 'attended', 'kaputt', ['Groessere Id']),
        ];

        $this->assertSame('Groessere Id', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
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

    public function testUhrzeitOhneDatumErgibtNichtHeute(): void
    {
        // Derselbe Schaden wie der Leerstring, durch eine andere Tuer:
        // new DateTimeImmutable('14:00:00') ist HEUTE um 14 Uhr (gemessen). Das
        // Zertifikat wuerde sein eigenes Ausstellungsdatum als Schulungsdatum
        // tragen. Ein Jahr-Guard faengt das nicht — das Jahr ist plausibel.
        $bookings = [$this->booking(1, 'attended', '14:00:00', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        $this->assertSame('X', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testPartiellesNulldatumErgibtLeeresDatum(): void
    {
        // '2026-00-00' parst ohne Exception zu 30.11.2025 (gemessen) — Jahr
        // plausibel, Rest kaputt, Ergebnis plausibel FALSCH. Das ist schlimmer
        // als "30.11.-0001", weil niemand es auf dem Dokument bemerkt.
        $bookings = [$this->booking(1, 'attended', '2026-00-00', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        $this->assertSame('X', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testRelativeAngabenSindKeinTermin(): void
    {
        // 'now' und '+1 day' parsen anstandslos und ergeben heute bzw. morgen.
        foreach (['now', 'tomorrow', '+1 day', '2026'] as $relativ) {
            $bookings = [$this->booking(1, 'attended', $relativ, ['X'])];

            $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings), $relativ);
        }
    }

    public function testNichtExistierendesDatumRolltNichtStillWeiter(): void
    {
        // '2026-02-30' gibt es nicht; PHP rollt still auf den 02.03.2026.
        $bookings = [$this->booking(1, 'attended', '2026-02-30', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testGueltigeFormateBleibenVerwertbar(): void
    {
        // Gegengewicht zu den Guards oben: der Vertrag ist 'Y-m-d H:i:s', aber
        // ein Carbon-Cast liefert je nach Aufrufer auch Mikrosekunden, ein
        // ISO-Offset oder ein reines Datum. Nichts davon darf der Guard
        // wegwerfen — sonst bleibt das Feld auf dem Zertifikat leer.
        $formate = [
            '2026-07-24 14:00:00' => '24.07.2026',
            '2026-7-05 10:00:00' => '05.07.2026',
            '2026-07-24' => '24.07.2026',
            '2026-07-24T14:00:00Z' => '24.07.2026',
            '2026-07-24T14:00:00+09:00' => '24.07.2026',
            '2026-07-24 14:00:00.000000' => '24.07.2026',
            '24.07.2026' => '24.07.2026',
        ];

        foreach ($formate as $eingabe => $erwartet) {
            $bookings = [$this->booking(1, 'attended', (string) $eingabe, ['X'])];

            $this->assertSame($erwartet, TrainingLeaderResolver::trainingDate($bookings), (string) $eingabe);
        }
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

    public function testModelAlsInterviewerIstVertragsbruchUndKnalltLaut(): void
    {
        // Der wahrscheinlichste Produzenten-Fehler und vorher der STILLSTE:
        // ->interviewers->all() liefert Models, und Model::__toString() liefert
        // toJson() (gemessen gegen echtes Illuminate). Ohne Guard stand damit
        // '{"id":7,"name":"Anna Bergmann"}' auf dem Zertifikat — keine Warnung,
        // kein Log, kein roter Test. Genau der Fall, in den der frueher hier
        // stehende Ratschlag ("ohne ->all() ist das ein Vertragsbruch")
        // hineinfuehrte, statt ihn zu schliessen.
        $model = new class {
            public function __toString(): string
            {
                return '{"id":7,"name":"Anna Bergmann"}';
            }
        };

        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', [$model])];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('erwartet list<string>');

        TrainingLeaderResolver::leaderNames($bookings);
    }

    public function testNichtStringAlsInterviewerIstVertragsbruch(): void
    {
        // Dieselbe Grenze fuer die ganze Familie. Wichtig ist die INT-Zeile:
        // ohne Guard wurde daraus 'Anna, 42' — eine Zahl als Name auf einem
        // Dokument. Auch 'Anna, 1' aus einem bool. Ein leeres Feld waere
        // besser, ein lautes Scheitern beim Produzenten ist noch besser.
        foreach ([42, true, 1.5, ['name' => 'Anna'], null] as $wert) {
            $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', ['Anna', $wert])];

            try {
                $ergebnis = TrainingLeaderResolver::leaderNames($bookings);
                $this->fail('Kein Vertragsbruch gemeldet fuer ' . get_debug_type($wert) . ", Ergebnis: '" . $ergebnis . "'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(get_debug_type($wert), $e->getMessage());
            }
        }
    }

    public function testAttendedBuchungOhneIdIstVertragsbruch(): void
    {
        // Vorher hing es an der ANZAHL der Buchungen, ob das auffaellt: bei
        // EINER Buchung ruft usort den Comparator nie auf, die fehlende id
        // wurde also nie gelesen (gemessen: 0 Warnungen). Bei zwei gab es zwei
        // "Undefined array key" — in Tests rot, in Produktion eine Logzeile und
        // ein Tie-Break, der 0 gegen 0 vergleicht. Der Doc-Block behauptete
        // "'id' muss da sein"; jetzt stimmt das auch.
        $ohneId = [
            ['status' => 'attended', 'starts_at' => '2026-07-24 14:00:00', 'interviewers' => ['A']],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Buchung ohne id');

        TrainingLeaderResolver::pickBooking($ohneId);
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

    public function testLeeresBuchungsArrayLoestKeineWarnungAus(): void
    {
        // Echte Assertion fuer einen Guard, dessen einzige Wirkung "keine
        // Warnung" ist: ohne die Leer-Pruefung in pickBooking() greift
        // $attended[0] auf ein leeres Array zu -> "Undefined array key 0".
        // Das ist NUR ueber failOnWarning rot, und wer das Flag mal entfernt,
        // verliert das Netz unbemerkt. Deshalb wird hier gemessen, nicht
        // gehofft.
        $this->assertSame([], $this->warnungenBeim(
            fn () => TrainingLeaderResolver::pickBooking([])
        ));
    }

    public function testFehlenderStatusSchluesselLoestKeineWarnungAus(): void
    {
        // Dasselbe fuer den ??-Default bei 'status': ohne ihn erzeugt jede
        // Buchung ohne den Schluessel ein "Undefined array key \"status\"".
        // Ein fehlender Schluessel ist hier ein fehlender WERT, kein Rauschen.
        $ohneStatus = [['id' => 5, 'starts_at' => '2026-08-01 10:00:00']];

        $this->assertSame([], $this->warnungenBeim(
            fn () => TrainingLeaderResolver::leaderNames($ohneStatus)
        ));
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
