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
 * array-Parameter des Filters; 'interviewers' muss eine Liste von STRINGS sein
 * und eine 'attended'-Buchung muss ihre 'id' mitbringen. Beides wird
 * durchgesetzt, nicht nur behauptet — ein Kommentar, der eine Garantie
 * beschreibt, die niemand prueft, ist teurer als kein Kommentar. Diese Faelle
 * stillzulegen wuerde nur den Produzenten-Bug verstecken, und zwar hinter
 * einem leeren oder — schlimmer — falschen Feld auf dem Zertifikat.
 *
 * Fuer den Produzenten (Task 8) die scharfe Kante, gemessen gegen echtes
 * Illuminate: pluck('name') liefert eine Collection, kein Array — das
 * scheitert laut am array-Typ. Der gefaehrliche Nachbarfehler ist
 * ->interviewers->all(): das ergibt eine Liste von MODELS, und
 * Illuminate\Database\Eloquent\Model::__toString() liefert toJson(). Vorher
 * stand damit '{"id":7,"name":"Anna Bergmann"}, …' auf dem Zertifikat — ohne
 * Warnung, ohne Log, ohne roten Test. Richtig ist pluck('name')->all();
 * alles, was kein String ist, wirft jetzt.
 *
 * 'starts_at' ist ein naiver 'Y-m-d H:i:s'-String oder null. Verglichen wird
 * ueber getTimestamp(), also absolute Zeitpunkte — formatiert wird aber in der
 * Zeitzone der EINGABE, nicht in der App-Zeitzone: '2026-07-24T23:30:00Z'
 * ergibt '24.07.2026', obwohl es in einer Europe/Berlin-App lokal schon der
 * 25.07. ist (gemessen). Beim gedachten Produzenten (naives Format aus einem
 * Carbon-Cast) faellt das nicht an; wer hier je Offsets hereingibt, muss
 * vorher in die App-Zeitzone konvertieren.
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
     * Die Terminart steht nicht im Eingabe-Vertrag. Wer sie doch filtern will,
     * muesste es beim Produzenten tun (Task 8, die Buchungs-Query) — und wuerde
     * damit genau die zweite Definition von "Schulung" erzeugen, die dieser
     * Absatz verhindert.
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

        // Die 'id' ist die einzige Eigenschaft, die der Vertrag als IMMER
        // vorhanden behauptet — und sie wird bei genau einer Buchung nie
        // gelesen (usort ruft den Comparator nicht auf), bei zwei dagegen doch.
        // Ohne diese Pruefung haengt es also an der ANZAHL der Buchungen, ob
        // ein Produzenten-Bug auffaellt. Und sie ist nicht nur Tie-Break: Task 8
        // braucht die id fuer das Zertifikat-Log und die Idempotenz.
        foreach ($attended as $booking) {
            if (!isset($booking['id'])) {
                throw new \InvalidArgumentException(
                    'Buchung ohne id: der Eingabe-Vertrag verlangt id fuer jede '
                    . "'attended'-Buchung (Tie-Break und Idempotenz beim Aufrufer)."
                );
            }
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

        // ABSICHTSDOKUMENTATION, keine Verhaltensgrenze: null['interviewers']
        // ?? [] ergibt in PHP still [] (gemessen, keine Warnung), dieser Guard
        // ist also durch keinen Test aushebelbar. Er bleibt trotzdem stehen,
        // weil ohne ihn die Zeilen darunter auf einem ?array indizieren wuerden
        // — fuer jeden Leser und jede Static Analysis ein Fehler, auch wenn PHP
        // ihn schluckt. Dasselbe gilt fuer trainingDate().
        if ($booking === null) {
            return '';
        }

        $interviewers = $booking['interviewers'] ?? [];

        // Nicht-Strings werfen, statt sich in einen Namen zu verwandeln. Ein
        // Model wuerde ueber __toString() als JSON auf dem Zertifikat landen,
        // eine Zahl als Zahl — beides still. "Leeres Feld statt Exception" gilt
        // fuer FEHLENDE Daten, nicht fuer einen verletzten Typ-Vertrag.
        $names = array_values(array_filter(
            array_map(
                fn ($n) => is_string($n)
                    ? trim($n)
                    : throw new \InvalidArgumentException(
                        'interviewers: erwartet list<string>, bekam '
                        . get_debug_type($n) . '. Aus einer Relation wird das '
                        . "mit pluck('name')->all(), nicht mit ->all()."
                    ),
                $interviewers
            ),
            fn (string $n) => $n !== ''
        ));

        return implode(', ', $names);
    }

    /** @param list<array<string,mixed>> $bookings */
    public static function trainingDate(array $bookings): string
    {
        $booking = self::pickBooking($bookings);

        // Siehe leaderNames(): Absichtsdokumentation, nicht aushebelbar.
        if ($booking === null) {
            return '';
        }

        return self::moment($booking['starts_at'] ?? null)?->format('d.m.Y') ?? '';
    }

    /**
     * Ein verwertbarer Zeitpunkt oder null.
     *
     * Ein Wert, den PHP irgendwie parst, ist noch kein verwertbares Datum. Eine
     * Nachpruefung des ERGEBNISSES (etwa "Jahr < 1", der erste Entwurf hier) ist
     * dafuer das falsche Instrument: sie trifft nur das MySQL-Nulldatum und
     * laesst die ganze Familie "Jahr plausibel, Rest kaputt" durch. Deshalb wird
     * die EINGABE vollstaendig verprobt, bevor sie zum Zeitpunkt wird.
     *
     * Nicht verwertbar sind die folgenden FAMILIEN — die Beispiele sind
     * Vertreter, keine abschliessende Liste. Alle rutschen ohne Guard STILL
     * durch, alle gemessen mit PHP 8.4.19 bei "heute" = 12.08.2026:
     *  - LEER: null, '', nur Whitespace -> new DateTimeImmutable('') ist JETZT.
     *  - UHRZEIT OHNE DATUM: '14:00:00' -> 12.08.2026. Derselbe Schaden wie
     *    leer, nur durch eine andere Tuer: das Zertifikat traegt sein eigenes
     *    Ausstellungsdatum als Schulungsdatum.
     *  - RELATIV: 'now' -> heute, 'tomorrow' und '+1 day' -> 13.08.2026.
     *  - UNVOLLSTAENDIG: '2026' -> 12.08.2026, weil es als Uhrzeit 20:26 parst.
     *  - TEIL-NULLDATUM: '0000-00-00 …' -> 30.11.-0001 (sichtbarer Unsinn),
     *    '2026-00-00' -> 30.11.2025 — plausibel und deshalb schlimmer.
     *  - EXISTIERT NICHT: '2026-02-30' -> stiller Rollover auf 02.03.2026.
     *  - UNPARSBAR: 'kaputt' -> DateMalformedStringException.
     * Wer diese Aufzaehlung erweitert, prueft bitte gegen den Guard, nicht
     * gegen die Liste: die Liste war schon zweimal unvollstaendig.
     *
     * Verwertbar bleiben (ebenfalls gemessen): '2026-07-24 14:00:00',
     * '2026-7-05 10:00:00' (unpadierter Monat), '2026-07-24',
     * '2026-07-24T14:00:00Z', '…+09:00', '…14:00:00.000000' (Carbons
     * Mikrosekunden) und '24.07.2026'.
     */
    private static function moment(mixed $raw): ?\DateTimeImmutable
    {
        $value = trim((string) $raw);

        $parsed = date_parse($value);

        if ($parsed['errors'] !== [] || $parsed['warnings'] !== [] || isset($parsed['relative'])
            || !is_int($parsed['year']) || !is_int($parsed['month']) || !is_int($parsed['day'])
            || $parsed['year'] < 1 || $parsed['month'] < 1 || $parsed['day'] < 1) {
            return null;
        }

        // NETZ, kein Guard: date_parse() und der Konstruktor benutzen denselben
        // Parser, hinter dem Guard ist kein Wert bekannt, der hier noch wirft
        // (dass es keinen gibt, ist erschlossen, nicht gemessen). Es bleibt
        // stehen, weil aus einem Dokument-Renderer keine Exception fliegen
        // darf — die Policy dieser Klasse ist das leere Feld.
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
