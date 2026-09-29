<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;
use Platform\Recruiting\Support\EinladungsToken;
use Platform\Recruiting\Support\Einmalcode;
use Platform\Recruiting\Support\PasswortRegeln;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Der EINE Schreiber der Kontofelder an rec_persons (Canvas 68, Spec
 * 2026-09-29 "Konto-Anmeldeschicht"). Einladung, Registrierung, Anmeldung,
 * Einmalcode, Passwort — alles, was ueber password_hash, invite_* und code_*
 * entscheidet, laeuft hier durch und nirgends sonst. Muster und Begruendung
 * wie bei PersonLinker, mit denselben drei Gruenden:
 *
 *  - EIN SCHREIBER: die Kontofelder stehen bewusst NICHT in
 *    RecPerson::$fillable. Gaebe es einen zweiten Weg (ein
 *    RecPerson::update([...]) irgendwo in einer Livewire-Seite), waere jede
 *    Regel dieser Klasse — Zwei-Nachweis-Regel, Versuchszaehler, Entwerten
 *    des Codes — nur noch eine Verabredung. Bei Anmeldedaten ist der Preis
 *    eines Fehlers hoch: ein durchgelassenes Geheimnis zeigt einem Menschen
 *    die Akte eines anderen.
 *
 *  - OBSERVER-FREI: jeder Schreibzugriff laeuft ueber DB::table(...), nie
 *    ueber Eloquent. Eine Kontoaenderung ist keine fachliche Aenderung am
 *    Mitarbeiter und darf zas_changed_at nicht setzen — sonst spuelt eine
 *    Einladungswelle den halben Bestand in die naechste ZAS-Update-Datei.
 *    Der Beleg dafuer im Test ist NICHT zas_changed_at (die Kontofelder und
 *    auch 'phone' stehen gar nicht in RELEVANT_EMPLOYEE_FIELDS), sondern
 *    rec_employees.updated_at: das fasst nur Eloquent automatisch an.
 *
 *  - GEHEIMNISSE NUR ALS HASH: Passwort ueber Hash::make(), Token und Code
 *    ueber die HMAC-Hashes aus EinladungsToken/Einmalcode. Der Klartext
 *    existiert genau einmal — in der Nachricht an den Menschen.
 *
 * Ruling GD-5, der Pfeffer: die Regel-Klassen LESEN ihn nicht, sie bekommen
 * ihn hereingereicht, damit sie rein und ohne Framework testbar bleiben.
 * Diese Klasse ist die Stelle, die ihn liest — an genau EINER privaten
 * Stelle (pfeffer()), nicht an fuenf Aufrufstellen. Verschiedene Pfeffer
 * beim Erzeugen und beim Pruefen hiessen: das Geheimnis gilt nie, und zwar
 * lautlos. Das PASSWORT wird ausdruecklich NICHT gepfeffert (Hash::make /
 * Hash::check): wuerde der Pfeffer es beruehren, sperrte sein Verlust jeden
 * Mitarbeiter DAUERHAFT aus statt nur die offenen Einladungen (sieben Tage)
 * und laufenden Codes (zehn Minuten). Diese Asymmetrie ist Absicht.
 *
 * Was hier bewusst NICHT liegt:
 *  - Die Nummer selbst. Beim Nummernwechsel ruft loeseCodeEin()
 *    PersonLinker::setzeNummer() auf; die Regel "die Nummer wandert auf alle
 *    Anstellungen" (und die Wache gegen eine im Team schon vergebene Nummer)
 *    lebt dort und nur dort.
 *  - Der Versand. erzeugeCode() legt den Code ab und gibt den Klartext
 *    zurueck; wer ihn per WhatsApp schickt, entscheidet der Aufrufer.
 *  - Die Sendedrossel (CodeDrossel). Sie zaehlt Anforderungs-ZEITPUNKTE, die
 *    hier nicht gefuehrt werden — sie gehoert an die Seite, die den Versand
 *    ausloest (Aufgabe 6). Diese Klasse hat keine Meinung dazu, wie oft
 *    jemand einen Code anfordern darf.
 *  - rec_employees.portal_locked_at (Dispo-Sperre, Eskalationsstufe 3). Die
 *    Sperre haengt an der ANSTELLUNG, nicht am Menschen, und wird von den
 *    Portal-Seiten ausgewertet (PortalShell, EmployeeAssignments,
 *    DispoAttachmentController). Wer zwei Anstellungen hat, von denen eine
 *    gesperrt ist, muss sich weiterhin anmelden koennen und die andere
 *    sehen — deshalb entscheidet pruefeAnmeldung() nicht darueber. Sie
 *    bleibt damit unveraendert wirksam, sie wandert nur nicht hierher.
 */
final class KontoWriter
{
    /** Anmeldung per Einmalcode (Konto einrichten/ohne Passwort hinein). */
    public const ZWECK_ANMELDUNG = 'anmeldung';

    /** Passwort zuruecksetzen. */
    public const ZWECK_PASSWORT = 'passwort';

    /** Neue Handynummer bestaetigen — der Code geht an die NEUE Nummer. */
    public const ZWECK_NUMMERNWECHSEL = 'nummernwechsel';

    /**
     * Ein getippter Zweck ("passwort_reset") wuerde sonst still einen Code
     * ablegen, den kein Einloesen je trifft: der Mensch wartet dann auf eine
     * Bestaetigung, die nie kommen kann. Deshalb eine geschlossene Liste.
     */
    private const ZWECKE = [self::ZWECK_ANMELDUNG, self::ZWECK_PASSWORT, self::ZWECK_NUMMERNWECHSEL];

    /**
     * Gegen-Hash fuer den Vergleich ins Leere (Nummer unbekannt, Konto noch
     * ohne Passwort). Ohne ihn verriete die Antwortzeit von pruefeAnmeldung(),
     * ob es zu einer Nummer ein Konto gibt — und Handynummern lassen sich
     * durchprobieren. Ein fester bcrypt-Wert mit den ueblichen zwoelf Runden,
     * damit der Leerlauf ungefaehr so lange dauert wie der echte Vergleich;
     * sein Klartext ist ein weggeworfener Zufallswert und passt zu keiner
     * Eingabe. (Hash::check() kuerzt bei leerem Hash sofort ab — genau das
     * ist der Grund, warum hier nicht einfach '' stehen kann.)
     */
    private const LEERLAUF_HASH = '$2y$12$50GzD3LePr.U3rylacg2S.qE2zJ4ihABopdCOstAjxwUDKBGPsfpC';

    /**
     * Erzeugt einen Einladungs-Token, speichert nur den Hash, gibt den
     * Klartext zurueck. Eine neue Einladung ersetzt die alte vollstaendig
     * und raeumt dabei invite_used_at ab — sonst waere jede zweite Einladung
     * an denselben Menschen still tot (istGueltig() lehnt bei gesetztem
     * invite_used_at ab).
     */
    public static function ladeEin(int $personId): string
    {
        self::offeneZeile($personId);

        ['klartext' => $klartext, 'hash' => $hash] = EinladungsToken::erzeuge(self::pfeffer());

        DB::table('rec_persons')->where('id', $personId)->update([
            'invite_token_hash' => $hash,
            'invite_expires_at' => now()->addDays(EinladungsToken::GUELTIG_TAGE),
            'invite_used_at'    => null,
            'invited_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $klartext;
    }

    /**
     * Token + Geburtsdatum + Passwort -> Konto steht.
     *
     * Zwei-Nachweis-Regel (Spec §2.4): acht Zeichen Token sind fuer sich
     * genommen wenig fuer ein Geheimnis, das sieben Tage gilt — sie tragen
     * nur zusammen mit dem Geburtsdatum. Deshalb werden BEIDE Nachweise
     * geprueft, bevor irgendetwas geschrieben wird.
     *
     * Beide Pruefungen laufen VOR der Entscheidung und muenden in DIESELBE
     * Meldung: wer erfaehrt, dass der Token stimmte und nur das Datum falsch
     * war, hat den ersten Nachweis bestaetigt bekommen und muss nur noch das
     * Datum raten. Und ein Tippfehler im Datum verbrennt die Einladung
     * NICHT — verbraucht wird sie erst, wenn das Konto wirklich steht.
     */
    public static function registriere(int $personId, string $tokenKlartext, string $geburtsdatum, string $passwort): void
    {
        $person = self::offeneZeile($personId);

        $tokenGilt = EinladungsToken::istGueltig(
            $person->invite_token_hash,
            $person->invite_expires_at,
            $person->invite_used_at,
            $tokenKlartext,
            self::jetzt(),
            self::pfeffer(),
        );
        $geburtsdatumStimmt = self::geburtsdatumStimmt($personId, $geburtsdatum);

        if (!$tokenGilt || !$geburtsdatumStimmt) {
            throw new InvalidArgumentException('Einladung oder Geburtsdatum stimmen nicht.');
        }

        self::pruefePasswort($passwort);

        DB::table('rec_persons')->where('id', $personId)->update([
            'password_hash'     => Hash::make($passwort),
            'registered_at'     => now(),
            'invite_used_at'    => now(),
            // Der Hash hat seine Aufgabe erfuellt; ein verbrauchtes
            // Geheimnis, das stehen bleibt, ist nur noch Angriffsflaeche.
            // invite_used_at bleibt als Beleg, dass die Einladung eingeloest
            // wurde (und nicht bloss nie eine existierte).
            'invite_token_hash' => null,
            'invite_expires_at' => null,
            'updated_at'        => now(),
        ]);
    }

    /**
     * Prueft Nummer + Passwort. Gibt die Personen-Kennung zurueck oder null.
     *
     * Es wird IMMER ein Passwortvergleich gerechnet — auch wenn es die
     * Nummer gar nicht gibt, auch bei gesperrten, stillgelegten und
     * ausgeschiedenen Konten. Grund ist die Antwortzeit: ohne den
     * Leerlauf-Vergleich verraet die Dauer, ob ein Konto existiert, und dann
     * lassen sich Nummern durchprobieren. Deshalb steht der Vergleich VOR
     * allen Ablehnungsgruenden und nicht hinter einer fruehen Rueckgabe.
     *
     * Der Rueckgabewert nennt nie einen Grund: "falsches Passwort",
     * "gesperrt", "gibt es nicht" und "die Nummer ist mehrdeutig" sind alle
     * null. Wer daraus eine Meldung baut, darf keine unterscheiden.
     *
     * Ruling GD-2: $teamId darf null sein und ist es auf der oeffentlichen
     * Anmeldeseite auch — die hat keinen Team-Kontext. Dann wird ueber alle
     * Teams gesucht, und bei mehr als einem lebenden Treffer scheitert die
     * Anmeldung (fail closed, mit Log-Zeile). Der Parameter bleibt fuer
     * Tests und Kommandos in der Signatur.
     */
    public static function pruefeAnmeldung(?int $teamId, string $nummer, string $passwort): ?int
    {
        // Normalisieren an der Grenze (dieselbe Falle wie Ruling T3-B bei
        // PersonLinker): gespeichert ist E.164, getippt wird "0151 ...".
        $normalisiert = PhoneE164::normalize($nummer);

        // Ruling GD-2: OHNE team_id wird ueber ALLE Teams gesucht — die
        // Anmeldeseite ist oeffentlich und hat keinen Team-Kontext. Ein
        // uebergebenes Team schraenkt weiterhin ein (Tests und Kommandos).
        // "Lebend" heisst: nicht zusammengefuehrt; eine stillgelegte Zeile
        // ist ein Grabstein, keine Person. GESPERRTE zaehlen dagegen MIT —
        // sie auszusortieren, um genau eine uebrig zu behalten, waere schon
        // wieder Raten.
        $kandidaten = $normalisiert === null ? [] : DB::table('rec_persons')
            ->where('phone', $normalisiert)
            ->whereNull('merged_into_person_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->get(['id', 'team_id', 'password_hash', 'locked_at'])
            ->all();

        $person = count($kandidaten) === 1 ? $kandidaten[0] : null;

        $hash = $person->password_hash ?? null;
        $passwortStimmt = Hash::check($passwort, ($hash === null || $hash === '') ? self::LEERLAUF_HASH : $hash);

        // Ruling GD-2, fail closed: die Eindeutigkeit der Nummer gilt nur JE
        // TEAM (unique(team_id, phone)) — ueber Teamgrenzen hinweg kann
        // dieselbe Nummer mehrfach existieren. Bei mehr als einer lebenden
        // Person wird NICHT geraten: bei Identitaet ist Raten die
        // schlechteste aller Moeglichkeiten. Lieber sperrt sich einer aus
        // und ruft HR an, als dass er in einem fremden Konto landet. Die
        // Antwort ist dieselbe wie bei falschem Passwort — der Vergleich
        // oben ist trotzdem gelaufen, sonst verriete die Antwortzeit den
        // Mehrfachtreffer.
        if (count($kandidaten) > 1) {
            Log::warning('recruiting.konto.anmeldung_mehrdeutig', [
                'person_ids' => array_map(fn ($k) => (int) $k->id, $kandidaten),
                'team_ids'   => array_map(fn ($k) => $k->team_id, $kandidaten),
                // NIE die vollstaendige Nummer ins Log (Datenschutz): die
                // letzten vier Stellen genuegen, um den Fall in der Akte
                // wiederzufinden. PhoneE164::suffix() liefert neun Ziffern —
                // fast die ganze Nummer —, deshalb wird hier auf vier
                // gekuerzt.
                'nummer_endet_auf' => substr(PhoneE164::suffix($normalisiert), -4),
            ]);

            return null;
        }

        if ($person === null || !$passwortStimmt) {
            return null;
        }

        // Gesperrt (HR/Datenschutz) meldet sich nie an. Stillgelegte sind
        // schon aus der Kandidatenliste gefallen.
        if ($person->locked_at !== null) {
            return null;
        }

        // Canvas 1793: Konten Ausgeschiedener werden gesperrt, weil Anbieter
        // Handynummern nach Monaten neu vergeben — sonst meldet sich der
        // naechste Inhaber der Nummer in einer fremden Akte an. Eine Person
        // ganz ohne Anstellung faellt in denselben Zweig.
        if (!self::hatAktiveAnstellung((int) $person->id)) {
            return null;
        }

        DB::table('rec_persons')->where('id', $person->id)->update([
            'letzte_anmeldung_at' => now(),
            'updated_at'          => now(),
        ]);

        return (int) $person->id;
    }

    /**
     * Legt einen Einmalcode ab und gibt den Klartext zurueck (Versand macht
     * der Sender). Ein neuer Code ersetzt den laufenden vollstaendig,
     * einschliesslich Versuchszaehler und alter neuer Nummer — sonst haengt
     * an einem Passwort-Code noch der Nummernwechsel von vorgestern.
     *
     * Ob die neue Nummer im Team frei ist, prueft diese Stelle bewusst NICHT
     * doppelt: die Regel lebt in PersonLinker::setzeNummer() und schlaegt
     * beim Einloesen zu. Zwei Pruefwege waeren genau die Doppelung, die
     * dieses Projekt schon mehrfach beseitigt hat.
     */
    public static function erzeugeCode(int $personId, string $zweck, ?string $neueNummer = null): string
    {
        self::offeneZeile($personId);
        self::pruefeZweck($zweck);

        $zielNummer = null;
        if ($zweck === self::ZWECK_NUMMERNWECHSEL) {
            // Der Code geht an die NEUE Nummer — ohne lesbare Nummer gibt es
            // niemanden, der ihn bekommen koennte. Gespeichert wird die
            // E.164-Form, sonst wechselt die Nummer spaeter in einer anderen
            // Schreibweise auf die Anstellungen.
            $zielNummer = PhoneE164::normalize($neueNummer);
            if ($zielNummer === null) {
                throw new InvalidArgumentException(
                    'Ein Nummernwechsel braucht eine lesbare neue Handynummer — an sie geht der Code.',
                );
            }
        }

        ['klartext' => $klartext, 'hash' => $hash] = Einmalcode::erzeuge(self::pfeffer());

        DB::table('rec_persons')->where('id', $personId)->update([
            'code_hash'        => $hash,
            'code_expires_at'  => now()->addMinutes(Einmalcode::GUELTIG_MINUTEN),
            'code_versuche'    => 0,
            'code_zweck'       => $zweck,
            'code_neue_nummer' => $zielNummer,
            'updated_at'       => now(),
        ]);

        return $klartext;
    }

    /**
     * Code einloesen. Wirft bei ungueltigem Code.
     *
     * Fund F13 der Pruefung: Einmalcode hat KEIN benutzt_at wie der
     * Einladungs-Token — die Klasse kennt nur Ablauf und Versuchszaehler.
     * Das Entwerten muss deshalb HIER passieren. Wer es vergisst, baut einen
     * Code, der zehn Minuten lang beliebig oft gilt.
     *
     * Der Zweck wird mitgeprueft (Migrations-Docblock): ein abgefangener
     * Code aus "Passwort zuruecksetzen" darf keinen Nummernwechsel
     * bestaetigen.
     *
     * ACHTUNG, zweiter Nachweis: ein eingeloester Code ALLEIN ist kein
     * Identitaetsnachweis. loeseCodeEin(ZWECK_PASSWORT) gefolgt von
     * setzePasswort() kaeme mit dem Code allein aus — wer ein fremdes
     * Geraet in der Hand hat, uebernaehme damit das Konto. Die
     * Zwei-Nachweis-Regel (Spec §2.4) lebt auf dieser Ebene NICHT; sie
     * gehoert in den Ablauf darueber (Aufgabe 9) und darf dort nicht
     * vergessen werden.
     */
    public static function loeseCodeEin(int $personId, string $zweck, string $codeKlartext): void
    {
        $person = self::offeneZeile($personId);
        self::pruefeZweck($zweck);

        // Ein Zweck-Fehlgriff zaehlt NICHT als Rateversuch: dabei wird der
        // Code gar nicht geprueft, es ist also kein Versuch, ihn zu erraten.
        if ($person->code_zweck !== $zweck) {
            throw new InvalidArgumentException('Der Code gehoert zu einem anderen Vorgang.');
        }

        $versuche = (int) $person->code_versuche;

        $gilt = Einmalcode::istGueltig(
            $person->code_hash,
            $person->code_expires_at,
            $versuche,
            $codeKlartext,
            self::jetzt(),
            self::pfeffer(),
        );

        if (!$gilt) {
            // Ohne diesen Zaehler waeren sechs Ziffern (eine Million
            // Moeglichkeiten) an einem Nachmittag durchprobiert. Gedeckelt
            // bei MAX_VERSUCHE, weil code_versuche ein TINYINT ist und ein
            // ungebremstes Hochzaehlen in den Ueberlauf liefe — ab
            // MAX_VERSUCHE lehnt Einmalcode ohnehin jeden Wert ab.
            if ($versuche < Einmalcode::MAX_VERSUCHE) {
                DB::table('rec_persons')->where('id', $personId)
                    ->increment('code_versuche', 1, ['updated_at' => now()]);
            }

            throw new InvalidArgumentException('Der Code stimmt nicht oder gilt nicht mehr.');
        }

        $neueNummer = $person->code_neue_nummer;

        // Erst entwerten, dann handeln — und bewusst NICHT in einer
        // gemeinsamen Transaktion mit setzeNummer(): scheitert der
        // Nummernwechsel an einer im Team schon vergebenen Nummer, wuerde
        // ein Ruecksetzen den Code wieder gueltig machen, obwohl er
        // nachweislich verwendet wurde. Der Mensch fordert dann einen neuen
        // an; die vergebene Nummer muss ohnehin ein Mensch klaeren.
        DB::table('rec_persons')->where('id', $personId)->update([
            'code_hash'        => null,
            'code_expires_at'  => null,
            'code_versuche'    => 0,
            'code_zweck'       => null,
            'code_neue_nummer' => null,
            'updated_at'       => now(),
        ]);

        if ($zweck === self::ZWECK_NUMMERNWECHSEL && $neueNummer !== null) {
            // Die Regel "die Nummer wandert auf alle Anstellungen" lebt in
            // PersonLinker und nur dort — hier wird sie NICHT nachgebaut.
            PersonLinker::setzeNummer($personId, $neueNummer);
            self::zieheCrmKontakteNach($personId);
        }
    }

    /**
     * Neues Passwort setzen. Wirft, wenn das Passwort die Regeln verletzt —
     * derselbe Massstab wie bei der Registrierung, weil es sonst zwei Wege
     * mit zwei Massstaeben gaebe.
     *
     * ACHTUNG, zweiter Nachweis: diese Methode prueft NICHTS ausser den
     * Passwortregeln. Wer sie aufruft, hat die Berechtigung vorher
     * festzustellen — beim Zuruecksetzen also nicht nur den Einmalcode
     * (loeseCodeEin), sondern den zweiten Nachweis aus Spec §2.4 dazu. Sonst
     * genuegt der Zugriff auf ein fremdes Geraet, um das Konto zu
     * uebernehmen. Der Ablauf liegt eine Ebene hoeher (Aufgabe 9).
     */
    public static function setzePasswort(int $personId, string $passwort): void
    {
        self::offeneZeile($personId);
        self::pruefePasswort($passwort);

        DB::table('rec_persons')->where('id', $personId)->update([
            'password_hash' => Hash::make($passwort),
            'updated_at'    => now(),
        ]);
    }

    // ------------------------------------------------------------------ intern

    /**
     * Ruling GD-5: der Pfeffer wird an EINER Stelle gelesen und von dort an
     * EinladungsToken/Einmalcode weitergereicht. Wuerde ihn jede
     * Aufrufstelle selbst holen, genuegte ein abweichender Schluessel an
     * einer davon, damit ein Geheimnis lautlos nie gilt.
     *
     * Bewusst nicht zwischengespeichert: ein statischer Puffer ueberlebte
     * eine Konfigurationsaenderung im selben Prozess und machte den
     * Unterschied zwischen Erzeugen und Pruefen unsichtbar.
     */
    private static function pfeffer(): string
    {
        $pfeffer = (string) (config('recruiting.konto.pepper') ?? '');

        if ($pfeffer === '') {
            // Faellt in der Konfiguration auf app.key zurueck; ist beides
            // leer, ist das ein Konfigurationsfehler. Still ungepfeffert
            // weiterzurechnen saehe sicher aus und waere es nicht.
            throw new InvalidArgumentException(
                'recruiting.konto.pepper ist leer (auch app.key fehlt) — ohne Pfeffer duerfen weder '
                .'Einladungs-Token noch Einmalcodes erzeugt oder geprueft werden.',
            );
        }

        return $pfeffer;
    }

    /**
     * Die Personen-Zeile, an der ueberhaupt etwas geschehen darf. Gesperrt
     * (locked_at) und stillgelegt (merged_into_person_id) bekommen weder
     * Einladung noch Code noch Passwort — sonst liefe die Einladung ins
     * Leere, und bei einer stillgelegten Zeile entstuende ein zweites Konto
     * fuer einen Menschen, der schon eines hat.
     */
    private static function offeneZeile(int $personId): object
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first();

        if ($person === null) {
            throw new InvalidArgumentException("Person {$personId} existiert nicht.");
        }

        if ($person->locked_at !== null) {
            throw new InvalidArgumentException("Person {$personId} ist gesperrt — kein Konto-Vorgang moeglich.");
        }

        if ($person->merged_into_person_id !== null) {
            throw new InvalidArgumentException(sprintf(
                'Person %d ist stillgelegt (zeigt auf Person %d) — der Konto-Vorgang gehoert an die Zeile, '
                .'an der die Anstellungen jetzt haengen.',
                $personId,
                $person->merged_into_person_id,
            ));
        }

        return $person;
    }

    private static function pruefeZweck(string $zweck): void
    {
        if (!in_array($zweck, self::ZWECKE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unbekannter Code-Zweck "%s" — erlaubt sind: %s.',
                $zweck,
                implode(', ', self::ZWECKE),
            ));
        }
    }

    private static function pruefePasswort(string $passwort): void
    {
        $meldung = PasswortRegeln::pruefe($passwort);

        if ($meldung !== null) {
            throw new InvalidArgumentException($meldung);
        }
    }

    /**
     * Der zweite Nachweis. Das Geburtsdatum haengt an der ANSTELLUNG
     * (rec_employees.birth_date), nicht an der Person — verglichen wird
     * gegen alle Anstellungen dieses Menschen, aktive wie ausgeschiedene:
     * es ist ein Nachweis der Identitaet, nicht der Beschaeftigung. Ob sich
     * jemand danach auch anmelden darf, entscheidet pruefeAnmeldung().
     *
     * Steht nirgends ein Geburtsdatum, gibt es keinen zweiten Nachweis — und
     * dann traegt der achtstellige Token die Anmeldung allein. Also: kein
     * Datum, keine Registrierung.
     */
    private static function geburtsdatumStimmt(int $personId, string $geburtsdatum): bool
    {
        $eingabe = self::alsTag($geburtsdatum);
        if ($eingabe === null) {
            return false;
        }

        $hinterlegt = DB::table('rec_employees')
            ->where('rec_person_id', $personId)
            ->whereNotNull('birth_date')
            ->pluck('birth_date');

        foreach ($hinterlegt as $wert) {
            if (self::alsTag((string) $wert) === $eingabe) {
                return true;
            }
        }

        return false;
    }

    /**
     * Y-m-d aus einem Datumswert. Ein unlesbarer Wert ist ein Datenfehler,
     * kein Nachweis — im Zweifel ungueltig statt einer 500er-Antwort (F11).
     * Der Leerstring wird eigens abgefangen: DateTimeImmutable('') bedeutet
     * JETZT und wuerde aus einer leeren Eingabe stillschweigend das heutige
     * Datum machen.
     */
    private static function alsTag(string $wert): ?string
    {
        if (trim($wert) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($wert))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pflicht des Aufrufers von PersonLinker::setzeNummer() (dessen
     * Docblock, Spec §9.2, Vorfall RG19734): der verknuepfte CRM-Kontakt
     * wird sonst NICHT mitgezogen, weil setzeNummer() bewusst observer-frei
     * schreibt und der RecEmployeePhoneSyncObserver nur auf
     * Eloquent-Speicherungen anspringt. Folge ohne dieses Nachziehen: der
     * Kontakt behaelt die alte Nummer, eingehende WhatsApp-Antworten landen
     * in einem unverknuepften Thread — und der naechste Einmalcode geht ans
     * ALTE Geraet, womit sich der Mensch selbst aussperrt. loeseCodeEin()
     * ist heute der einzige Aufrufer.
     *
     * Scheitert der Abgleich (CRM nicht erreichbar, fehlender Nummerntyp),
     * wird das protokolliert, aber der bereits vollzogene Nummernwechsel
     * NICHT zurueckgedreht: die Nummer steht dann an Person und
     * Anstellungen, nur der Kontakt hinkt hinterher. Das ist der kleinere
     * Schaden — und es trifft je Anstellung, damit ein kaputter Kontakt
     * nicht die uebrigen mitnimmt.
     */
    private static function zieheCrmKontakteNach(int $personId): void
    {
        foreach (RecEmployee::query()->where('rec_person_id', $personId)->get() as $anstellung) {
            try {
                app(ContactPhoneSync::class)->syncEmployee($anstellung);
            } catch (\Throwable $e) {
                Log::warning('recruiting.konto.contact_phone_sync_failed', [
                    'rec_employee_id' => (int) $anstellung->id,
                    'fehler'          => $e->getMessage(),
                ]);
            }
        }
    }

    private static function hatAktiveAnstellung(int $personId): bool
    {
        return DB::table('rec_employees')
            ->where('rec_person_id', $personId)
            ->where('is_active', 1)
            ->exists();
    }

    private static function jetzt(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
