<?php

namespace Platform\Recruiting\Support;

/**
 * Waehlt die massgebliche Schulungsbuchung und liefert Datum und
 * Schulungsleiter fuer das Schulungszertifikat.
 *
 * Selektionsregel: status='attended', sortiert nach Termindatum absteigend,
 * Tie-Break Buchungs-ID absteigend.
 *
 * Bewusst NICHT "juengste Buchung": bei einer Umbuchung kann die zuletzt
 * erfasste Buchung ein frueheres Termindatum haben. Auf dem Dokument steht
 * das Datum, das der Bewerber liest — es muss das spaeteste tatsaechliche
 * Teilnahmedatum sein.
 *
 * Kein Filter auf die Terminart: Kriterium ist 'attended'. Ein Filter auf
 * eine interview_type_id waere eine zweite, stillschweigende Definition von
 * "Schulung" neben der, die das Modul benutzt.
 *
 * Beide Werte kommen aus DERSELBEN Buchung. Den Leiter aus einer anderen
 * Buchung nachzuschlagen, weil die massgebliche keinen hat, waere hilfsbereit
 * und falsch: dann stuende ein Name auf dem Dokument, der an diesem Termin
 * nicht geschult hat.
 *
 * FEHLENDE Daten ergeben leere Rueckgabe, nie eine Exception (bzw. null bei
 * pickBooking): die Werte landen in einem Dokument, und ein fehlender
 * Schulungsleiter ist ein legitimes Zertifikat, kein Fehlerfall. Ein leeres
 * Feld ist besser als ein falsches. Ein fehlender Schluessel gilt dabei als
 * fehlender Wert (kein 'status' => nicht attended, kein 'interviewers' =>
 * kein Leiter).
 *
 * Ein verletzter Eingabe-VERTRAG ist dagegen ein Programmierfehler und knallt
 * absichtlich: ein Buchungseintrag, der kein Array ist, scheitert schon am
 * array-Parameter des Filters, 'interviewers' muss ein Array sein und 'id'
 * muss da sein. Diese Faelle stillzulegen wuerde nur den Produzenten-Bug
 * verstecken — und zwar hinter einem leeren Feld auf dem Zertifikat. Wichtig
 * fuer den Produzenten: pluck('name') liefert eine Collection, kein Array —
 * ohne ->all() ist das ein Vertragsbruch.
 *
 * Verglichen wird der geparste ZEITPUNKT, nicht der Rohstring. Ein
 * strcmp() auf 'starts_at' sortiert alles, was nicht mit einer Ziffer
 * beginnt, nach VORNE ("kaputt" > "2026-…"), sortiert unpadierte Monate
 * falsch und ist nur bei durchgaengig identischem Format ueberhaupt richtig.
 * Ein nicht verwertbarer Wert ist hier ein UNBEKANNTES Datum und sortiert
 * damit nach hinten — ein bekanntes Datum schlaegt ein unbekanntes.
 *
 * Reine Datenstrukturen als Eingabe (keine Models) — damit unit-testbar
 * ohne Laravel, Muster wie Support/TrainingCertificatePdfOptions.
 */
final class TrainingLeaderResolver
{
    private const ATTENDED = 'attended';

    /**
     * WER HIER LANDET, WEIL EIN ZERTIFIKAT EIN FALSCHES DATUM TRAEGT: das ist
     * die wahrscheinlichste Ursache, und sie ist Absicht.
     *
     * Es wird NICHT auf die Terminart gefiltert. Kriterium ist allein
     * status='attended'. Hat ein Bewerber also eine spaetere 'attended'-Buchung
     * an einem ANDEREN Termintyp — Vorstellungsgespraech, Nachtermin, was auch
     * immer das Modul noch kennt —, dann wird DIESE die massgebliche
     * "Schulungsbuchung", und ihr Datum und ihre Interviewer stehen auf dem
     * Zertifikat.
     *
     * Das ist die bewusste Wahl, kein Versehen: ein Filter auf eine
     * interview_type_id waere eine zweite, stillschweigende Definition von
     * "Schulung" neben der, die das Modul benutzt — und die zweite Definition
     * wuerde beim ersten neuen Termintyp still falsch. Lieber eine Regel, die
     * man nachlesen kann, als zwei, die auseinanderlaufen.
     *
     * Beim Debuggen also NICHT hier den Termintyp einbauen, sondern zuerst
     * nachsehen, welche 'attended'-Buchungen der Bewerber ueberhaupt hat. Ist
     * eine davon fachlich keine Schulung, ist die Frage, warum sie 'attended'
     * ist — nicht, warum dieser Resolver sie nimmt.
     *
     * @param list<array{id: int, status: string, starts_at: ?string, interviewers: list<string>}> $bookings
     * @return array{id: int, status: string, starts_at: ?string, interviewers: list<string>}|null
     */
    public static function pickBooking(array $bookings): ?array
    {
        $attended = array_values(array_filter(
            $bookings,
            fn (array $b) => ($b['status'] ?? null) === self::ATTENDED
        ));

        if ($attended === []) {
            return null;
        }

        usort($attended, function (array $a, array $b) {
            $aTime = self::moment($a['starts_at'] ?? null)?->getTimestamp();
            $bTime = self::moment($b['starts_at'] ?? null)?->getTimestamp();

            if ($aTime !== $bTime) {
                // Buchungen ohne verwertbares Datum sortieren nach hinten.
                if ($aTime === null) {
                    return 1;
                }
                if ($bTime === null) {
                    return -1;
                }

                return $bTime <=> $aTime;
            }

            return ((int) $b['id']) <=> ((int) $a['id']);
        });

        return $attended[0];
    }

    /** @param list<array<string,mixed>> $bookings */
    public static function leaderNames(array $bookings): string
    {
        $booking = self::pickBooking($bookings);

        if ($booking === null) {
            return '';
        }

        $interviewers = $booking['interviewers'] ?? [];

        $names = array_values(array_filter(
            array_map(fn ($n) => trim((string) $n), $interviewers),
            fn (string $n) => $n !== ''
        ));

        return implode(', ', $names);
    }

    /** @param list<array<string,mixed>> $bookings */
    public static function trainingDate(array $bookings): string
    {
        $booking = self::pickBooking($bookings);

        if ($booking === null) {
            return '';
        }

        return self::moment($booking['starts_at'] ?? null)?->format('d.m.Y') ?? '';
    }

    /**
     * Ein verwertbarer Zeitpunkt oder null. Drei Faelle, die alle NICHT
     * verwertbar sind und alle ohne Guard still durchrutschen:
     *  - null / Leerstring / nur Whitespace: new DateTimeImmutable('') liefert
     *    JETZT, also stuende das Ausstellungsdatum als Schulungsdatum da.
     *  - unparsbar ("kaputt"): wirft DateMalformedStringException.
     *  - MySQL-Nulldatum ('0000-00-00 …'): parst ohne Exception und
     *    formatiert zu "30.11.-0001". Jahr < 1 gibt es auf keinem Zertifikat.
     */
    private static function moment(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        try {
            $moment = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        if ((int) $moment->format('Y') < 1) {
            return null;
        }

        return $moment;
    }
}
