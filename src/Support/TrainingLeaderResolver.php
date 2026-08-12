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
 * Feld ist besser als ein falsches. Ein FEHLENDER SCHLUESSEL gilt dabei als
 * fehlender Wert (kein 'status' => nicht attended, kein 'interviewers' =>
 * kein Leiter). Ein VORHANDENER Schluessel mit kaputtem Wert dagegen nicht:
 * das ist ein Vertragsbruch und knallt.
 *
 * Ein verletzter Eingabe-VERTRAG ist naemlich ein Programmierfehler und knallt
 * absichtlich: ein Buchungseintrag, der kein Array ist, scheitert schon am
 * array-Parameter des Filters; 'interviewers' muss — wenn der Schluessel da ist
 * — eine Liste aus nicht-leeren, nicht-numerischen STRINGS sein, und eine
 * 'attended'-Buchung muss ihre 'id' mitbringen. Beides wird durchgesetzt, nicht
 * nur behauptet — ein Kommentar, der eine Garantie beschreibt, die niemand
 * prueft, ist teurer als kein Kommentar. Diese Faelle stillzulegen wuerde nur
 * den Produzenten-Bug verstecken, und zwar hinter einem leeren oder —
 * schlimmer — falschen Feld auf dem Zertifikat.
 *
 * Fuer den Produzenten (Task 8) die scharfe Kante, gemessen gegen echtes
 * Illuminate: RICHTIG ist $booking->interviewers->pluck('name')->all().
 * pluck('name') allein liefert eine Collection, kein Array — das scheitert laut
 * am array-Typ. Der gefaehrliche Nachbarfehler ist ->interviewers->all(): das
 * ergibt eine Liste von MODELS, und
 * Illuminate\Database\Eloquent\Model::__toString() liefert toJson(). Vorher
 * stand damit '{"id":7,"name":"Anna Bergmann"}, …' auf dem Zertifikat — ohne
 * Warnung, ohne Log, ohne roten Test. Der vollstaendige Vertrag der Liste steht
 * als vier Faelle am Docblock von leaderNames(); alles, was danach kein
 * brauchbarer Name ist, wirft jetzt.
 *
 * Noch eine Kante fuer denselben Produzenten: users.name ist string NOT NULL,
 * users.lastname dagegen NULLABLE (gemessen in platform-core,
 * 0001_01_01_000000_create_users_table.php). Wer die Liste
 * als pluck('lastname')->all() oder [$u->name, $u->lastname] baut, hat
 * potenziell null darin und laeuft in den Typ-Guard. Das ist richtig so — ein
 * fehlender Nachname darf kein leerer Name auf einem Dokument werden —, aber
 * der Hinweis hier ist billiger als der Vorfall.
 *
 * 'starts_at' ist ein naiver 'Y-m-d H:i:s'-String oder null. Verglichen wird
 * ueber getTimestamp(), also absolute Zeitpunkte; FORMATIERT wird in der
 * App-Zeitzone (date_default_timezone_get()), nicht in der Zeitzone der
 * Eingabe. Ohne diese Normalisierung ergibt '2026-07-24T23:30:00Z' auf dem
 * Zertifikat '24.07.2026', obwohl es in einer Europe/Berlin-App lokal schon
 * der 25.07. ist (gemessen) — ein stiller Tagesfehler, und einer, der real
 * erreichbar ist: Laravels serializeDate() macht aus einem datetime-Cast
 * genau UTC-ISO, sobald der Produzent die Arrays ueber ->toArray() oder einen
 * JSON-Umweg baut. Die Normalisierung ist deshalb DURCHGESETZT und gemessen
 * (testOffsetDatenWerdenInDieAppZeitzoneNormalisiert setzt die Zeitzone
 * selbst und stellt sie zurueck), nicht als Merksatz an den Aufrufer
 * delegiert. Beim naiven 'Y-m-d H:i:s' aendert sie nichts: ein Wert ohne
 * Offset gilt bereits als App-Zeitzone.
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

    /**
     * Die Schulungsleiter der massgeblichen Buchung, mit ', ' verbunden.
     *
     * SO baut der Produzent die Liste: $booking->interviewers->pluck('name')->all().
     * Das ergibt genau, was der Vertrag verlangt — eine Liste echter Strings, in
     * jedem ein Name. Hat die Buchung keinen Leiter, gehoert [] hinein oder der
     * Schluessel gar nicht ins Array.
     *
     * Der Vertrag als VIER FAELLE. Eine Regel, keine Ausnahme: fehlender
     * Schluessel = fehlender Wert, vorhandener Schluessel mit falschem Wert =
     * Vertragsbruch.
     *  1. 'interviewers' fehlt ganz -> '', still. Kein Schluessel heisst "kein
     *     Leiter bekannt", und ein Zertifikat ohne Schulungsleiter ist legitim.
     *     Ebenso still leer: [] — eine leere LISTE ist die Aussage "keine".
     *  2. 'interviewers' => null (oder '' oder ein Skalar) -> laut. Der
     *     Schluessel ist da, der Wert ist keine Liste: kein fehlender Wert,
     *     sondern ein kaputter Produzent. Bis Task 7 hat '?? []' genau diesen
     *     Unterschied verwischt — die eine Ausnahme, die die Regel zu viel hatte.
     *  3. Ein Element ist kein String (Model, array, bool, int, float, null)
     *     -> laut. Siehe Klassen-Docblock: ein Model wuerde als JSON auf dem
     *     Zertifikat landen.
     *  4. Ein Element IST ein String, aber leer (auch nur Whitespace) oder
     *     numerisch -> laut.
     *
     * Fall 4 ist die neue Kante und der Grund fuer diese Runde: '42' wurde
     * vorher zu 'Anna, 42' und '' wurde still herausgefiltert. Beides ist
     * derselbe Fehler wie Fall 3, nur eine Tuer weiter. Ein Schulungsleiter, der
     * '42' heisst oder gar keinen Namen hat, ist kein fehlender Wert, sondern
     * ein kaputter Produzent; filtert man ihn still weg, steht das Feld leer da
     * und niemand erfaehrt, dass etwas nicht stimmte — und weil "leer" auf
     * diesem Dokument ein plausibler Zustand ist, faellt es auch nicht auf.
     *
     * NUMERISCH heisst is_numeric(), NICHT "enthaelt Ziffern". Gemessen mit PHP
     * 8.4.19: '42', '0', '3.14', '1e5', '-42', ' 42' sind numerisch und fallen
     * heraus; '42a', 'Anna 2', 'Mueller-42' und 'Anna Mueller-Luedenscheidt 2'
     * sind es nicht und bleiben gueltige Namen. Wer hier auf Ziffern prueft
     * (preg_match('/\d/', …) o.ae.), macht den Guard zu scharf und das
     * Zertifikatsfeld leer — derselbe Schaden aus der anderen Richtung.
     * Festgenagelt ist beide Richtungen: testNumerischerNameIstVertragsbruch
     * und testNamenMitZiffernBleibenGueltig.
     *
     * @param list<array<string,mixed>> $bookings
     */
    public static function leaderNames(array $bookings): string
    {
        $booking = self::pickBooking($bookings);

        // ABSICHTSDOKUMENTATION, keine Verhaltensgrenze: null['interviewers']
        // ?? [] ergibt in PHP still [] (gemessen, keine Warnung), dieser Guard
        // ist also durch keinen Test aushebelbar. Er bleibt trotzdem stehen,
        // weil ohne ihn die Zeilen darunter auf einem ?array indizieren wuerden
        // — fuer jeden Leser ein Fehler, auch wenn PHP ihn schluckt. Static
        // Analysis als zweiten Zeugen aufzurufen waere unehrlich: dieses Modul
        // hat keine (kein phpstan.neon, kein psalm.xml, composer.json ohne
        // require-dev — gemessen). Die Leser-Begruendung traegt allein.
        // Dasselbe gilt fuer trainingDate().
        if ($booking === null) {
            return '';
        }

        // Fall 1: Schluessel fehlt ganz -> still leer. array_key_exists() statt
        // '?? []', weil '??' auch 'interviewers' => null schluckt und damit
        // "kein Leiter bekannt" und "kaputter Wert" zum selben leeren Feld
        // macht. Der Unterschied ist die ganze Regel.
        if (!array_key_exists('interviewers', $booking)) {
            return '';
        }

        // Fall 2: Schluessel da, Wert ist keine Liste.
        if (!is_array($booking['interviewers'])) {
            throw new \InvalidArgumentException(
                'interviewers: erwartet list<string>, bekam '
                . get_debug_type($booking['interviewers'])
                . '. Ist kein Leiter bekannt, gehoert [] hinein oder der '
                . 'Schluessel gar nicht ins Array, nicht null.'
            );
        }

        return implode(', ', array_map(
            fn (mixed $n): string => self::interviewerName($n),
            $booking['interviewers']
        ));
    }

    /**
     * Ein Listeneintrag als verwendbarer Name — oder laut.
     *
     * Die Faelle 3 und 4 des Vertrags aus leaderNames(): ein Eintrag, der kein
     * String ist, verwandelt sich hier NICHT in einen Namen (ein Model wuerde
     * ueber __toString() als JSON auf dem Zertifikat landen, eine Zahl als
     * Zahl — beides still), und ein String, der leer oder numerisch ist, ist
     * kein Name. "Leeres Feld statt Exception" gilt fuer FEHLENDE Daten, nicht
     * fuer einen verletzten Vertrag.
     */
    private static function interviewerName(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                'interviewers: erwartet list<string>, bekam '
                . get_debug_type($raw) . '. Aus einer Relation wird das '
                . "mit pluck('name')->all(), nicht mit ->all()."
            );
        }

        // trim() ist hier Verhalten, nicht Kosmetik: ' Anna Bergmann ' ergibt
        // 'Anna Bergmann' (sonst stuende das Leerzeichen vor dem Komma auf dem
        // Zertifikat), und '   ' ist damit derselbe Fall wie ''.
        $name = trim($raw);

        if ($name === '') {
            throw new \InvalidArgumentException(
                'interviewers: leerer Name in der Liste. Ein Eintrag ohne Namen '
                . 'ist kein fehlender Leiter, sondern ein kaputter Produzent — '
                . 'ist kein Leiter bekannt, gehoert kein Eintrag in die Liste.'
            );
        }

        if (is_numeric($name)) {
            throw new \InvalidArgumentException(
                "interviewers: numerischer Name '" . $name . "'. Das ist eine "
                . 'Id, kein Name — die Liste kommt aus '
                . "pluck('name')->all(), nicht aus pluck('id')->all()."
            );
        }

        return $name;
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
     *  - RELATIV OHNE DATUM: 'now' -> heute, 'tomorrow' und '+1 day' ->
     *    13.08.2026, 'yesterday' -> 11.08.2026. ACHTUNG beim Aufraeumen: diese
     *    Familie faengt schon der Jahr-Guard (date_parse liefert year=false),
     *    NICHT die relative-Klausel — gemessen, Klausel entfernt, alle vier
     *    bleiben leer. Wer die Klausel an DIESER Zeile falsifizieren will,
     *    misst das Falsche.
     *  - DATUM PLUS RELATIVER ZUSATZ: '2026-07-24 +1 day' -> 25.07.2026,
     *    '2026-07-24 next monday' -> 27.07.2026. errors=[], warnings=[],
     *    year/month/day = 2026/7/24 — kein anderer Zweig haelt das auf, hier
     *    traegt allein isset($parsed['relative']). Ohne sie verschiebt sich
     *    das Datum still um 1-3 Tage, und die Suite bleibt gruen (gemessen).
     *  - WOCHENTAGSNAME IM FORMAT: RFC2822 (Carbons toRfc2822String()),
     *    HTTP-Date, RFC850, asctime. date_parse setzt dafuer relative.weekday,
     *    also fallen sie ALLE aus — auch die, deren Wochentag stimmt. Das ist
     *    Absicht und die konservative Seite:
     *        new DateTimeImmutable('Mon, 24 Jul 2026 14:00:00 +0200')
     *        -> 27.07.2026
     *    Der 24.07.2026 ist ein FREITAG; PHP springt auf den genannten
     *    Wochentag vor. Ein falscher Wochentagsname verschiebt das Datum also
     *    still um Tage, ein leeres Feld tut das nicht. Wer RFC2822 hier
     *    zulassen will, weil es "doch ein legitimes Format" ist, muss zuerst
     *    den Wochentagsnamen gegen das Datum pruefen — sonst holt er genau
     *    diesen Fehler zurueck. Der Vertrag ist ohnehin 'Y-m-d H:i:s'.
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
     * Mikrosekunden) und '24.07.2026'. Die beiden Offset-Formen werden in die
     * App-Zeitzone normalisiert, siehe Klassen-Docblock.
     *
     * Kein trim(): der Parser vertraegt fuehrende und schliessende Leerzeichen
     * und \n von sich aus (' 2026-07-24 ' -> 2026/7/24, err=0, wrn=0), und
     * reiner Whitespace faellt ueber year=false heraus. Ein trim() hier war
     * verhaltenstot — bitte nicht "zur Sicherheit" wieder einbauen. Der
     * (string)-Cast dagegen traegt: er macht aus null den Leerstring, und der
     * hat errors=1.
     */
    private static function moment(mixed $raw): ?\DateTimeImmutable
    {
        $value = (string) $raw;

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
            // Auf die App-Zeitzone normalisieren, BEVOR irgendwer formatiert:
            // ein Wert mit Offset ('…T23:30:00Z' aus Laravels serializeDate())
            // wuerde sonst in der Zeitzone der Eingabe gerendert und traegt in
            // einer Europe/Berlin-App den falschen Tag. Den Vergleich in
            // pickBooking() beruehrt das nicht: getTimestamp() ist derselbe
            // absolute Zeitpunkt, setTimezone() aendert nur die Darstellung.
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }
}
