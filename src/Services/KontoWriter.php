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
     * Weg 4 aus Spec §5: Nummer weg UND Passwort vergessen. Der Code geht
     * ebenfalls an die NEUE Nummer — aber sein Einloesen wechselt sie NICHT,
     * es BEANTRAGT den Wechsel (24 Stunden, HR kann stoppen).
     *
     * EIN EIGENER ZWECK UND NICHT ZWECK_NUMMERNWECHSEL, und das ist die
     * ganze Sicherheit dieses Weges: loeseCodeEin() wechselt bei
     * ZWECK_NUMMERNWECHSEL sofort. Ein Code aus Weg 4 mit diesem Zweck
     * uebersprunge also das 24-Stunden-Fenster und damit das Stopp-Recht von
     * HR — und Weg 4 ist der einzige Weg, der ohne Passwort auskommt.
     */
    public const ZWECK_NOTFALL = 'notfall';

    /**
     * Ein getippter Zweck ("passwort_reset") wuerde sonst still einen Code
     * ablegen, den kein Einloesen je trifft: der Mensch wartet dann auf eine
     * Bestaetigung, die nie kommen kann. Deshalb eine geschlossene Liste.
     */
    private const ZWECKE = [
        self::ZWECK_ANMELDUNG,
        self::ZWECK_PASSWORT,
        self::ZWECK_NUMMERNWECHSEL,
        self::ZWECK_NOTFALL,
    ];

    /**
     * Die Zwecke, bei denen der Code an eine NOCH NICHT hinterlegte Nummer
     * geht — und die deshalb eine Zielnummer brauchen.
     *
     * Eine Liste und keine zwei Vergleiche: der Sender muss dieselbe Frage
     * beantworten wie erzeugeCode() ("an welche Nummer geht der Code?"). Wer
     * sie an zwei Stellen ausschreibt, schickt beim naechsten Zweck den Code
     * an die alte Nummer, waehrend der Schreiber die neue erwartet.
     *
     * @var list<string>
     */
    private const ZWECKE_MIT_ZIELNUMMER = [self::ZWECK_NUMMERNWECHSEL, self::ZWECK_NOTFALL];

    /**
     * Wie lange zwischen dem Beweis und der Wirkung eines Notfall-Wechsels
     * liegt — Spec §5, Weg 4: "HR bekommt eine Meldung und kann innerhalb
     * von 24 Stunden stoppen."
     */
    public const WECHSEL_FRIST_STUNDEN = 24;

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
     * Wer steckt hinter diesem Einladungs-Token? Gibt die Personen-Kennung
     * zurueck oder null — und nennt NIE einen Grund.
     *
     * Die Registrierungsseite bekommt aus der Adresse nur den Token; um
     * registriere() aufzurufen, braucht sie die Personen-Kennung. Diese
     * Aufloesung liegt HIER und nicht in der Seite, weil sie den Pfeffer
     * braucht (Ruling GD-5): er wird an genau einer privaten Stelle gelesen.
     * Eine zweite Stelle, die ihn holt, waere genau der Fehler, vor dem der
     * Klassen-Docblock warnt — ein abweichender Schluessel, und das Geheimnis
     * gilt lautlos nie.
     *
     * Warum eine Vollsuche statt eines Index-Treffers auf den Hash: weil sie
     * heute schlicht billig ist — ein HMAC auf acht Zeichen je Zeile, und
     * Kandidaten sind nur Zeilen mit einem gesetzten Einladungs-Hash. Das
     * ist der ganze Grund, kein tieferer. (Berichtigt nach der Pruefung zu
     * Aufgabe 6: hier stand, ein Filter auf den Hash zwaenge die
     * Gueltigkeitsregel ein zweites Mal in die WHERE-Klausel. Das stimmt
     * nicht — man koennte auf den Hash filtern und trotzdem allein mit
     * istGueltig() entscheiden. Ein falsches Argument ist teurer als keines,
     * weil es dem naechsten Leser den billigen Ausweg verbaut.)
     *
     * WER SIE SPAETER BESCHNEIDET, ziehe die Gueltigkeitsregel NICHT in die
     * WHERE-Klausel. "Abgelaufen?" und "verbraucht?" entscheidet
     * ausschliesslich EinladungsToken::istGueltig(), so wie bei registriere()
     * auch; zwei Fassungen derselben Regel driften auseinander, und die
     * Richtung, in die sie hier driften wuerde, heisst: eine Einladung gilt
     * laenger, als sie darf.
     *
     * Die Vorauswahl (gesperrt, stillgelegt) ist dagegen sehr wohl eine
     * ZWEITE FASSUNG einer Regel, naemlich der von offeneZeile(). Das ist
     * gewollt — eine gesperrte oder stillgelegte Person soll aussehen, als
     * gaebe es die Einladung gar nicht, statt erst am Formular zu scheitern
     * — aber es heisst: wer offeneZeile() anfasst, muss diese beiden Zeilen
     * mitnehmen, und umgekehrt.
     *
     * Der Vergleich des Geheimnisses selbst laeuft ueber hash_equals() in
     * istGueltig(), nicht ueber die Datenbank.
     */
    public static function personFuerEinladung(string $tokenKlartext): ?int
    {
        if (trim($tokenKlartext) === '') {
            return null;
        }

        $pfeffer = self::pfeffer();
        $jetzt   = self::jetzt();

        $kandidaten = DB::table('rec_persons')
            ->whereNotNull('invite_token_hash')
            ->whereNull('locked_at')
            ->whereNull('merged_into_person_id')
            ->get(['id', 'invite_token_hash', 'invite_expires_at', 'invite_used_at']);

        foreach ($kandidaten as $person) {
            $gilt = EinladungsToken::istGueltig(
                $person->invite_token_hash,
                $person->invite_expires_at,
                $person->invite_used_at,
                $tokenKlartext,
                $jetzt,
                $pfeffer,
            );

            if ($gilt) {
                return (int) $person->id;
            }
        }

        return null;
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
        $kandidaten = self::lebendeKandidaten($teamId, $normalisiert);

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

        if (!self::darfSichAnmelden($person)) {
            return null;
        }

        DB::table('rec_persons')->where('id', $person->id)->update([
            'letzte_anmeldung_at' => now(),
            'updated_at'          => now(),
        ]);

        return (int) $person->id;
    }

    /**
     * Wer steckt hinter dieser Nummer — und koennte sich damit ueberhaupt
     * anmelden? Gibt die Personen-Kennung zurueck oder null, OHNE Passwort.
     *
     * WOFUER: "Passwort vergessen" (Spec §5, Weg 3) hat kein Passwort — es
     * ist ja das, was fehlt. Die Seite braucht trotzdem eine Personen-Kennung,
     * um einen Code verschicken zu lassen.
     *
     * DASS DAS OHNE PASSWORT GEHT, IST KEIN LOCH: diese Methode oeffnet
     * nichts. Sie sagt nur, an wen ein Code gehen soll — und der geht an die
     * hinterlegte Nummer, also an das Geraet, das die Nummer ohnehin hat. Wer
     * das Ergebnis fuer mehr haelt, baut den Bypass; deshalb heisst sie nicht
     * "personFuerNummer", sondern nennt die Frage, die sie beantwortet.
     *
     * DIESELBE MENGE WIE pruefeAnmeldung(), nur ohne den Passwortvergleich:
     * genau eine lebende Zeile (fail closed bei mehreren, Ruling GD-2), nicht
     * gesperrt, mit aktiver Anstellung (Canvas 1793). Beide fragen ueber
     * lebendeKandidaten() und darfSichAnmelden(), damit die Regel nicht in
     * zwei Fassungen existiert — sonst bekaeme ein ausgeschiedener Mensch
     * Codes fuer ein Konto, in das er nicht mehr hineinkommt.
     *
     * KEIN LEERLAUF-VERGLEICH und keine Log-Zeile beim Mehrfachtreffer: hier
     * wird nichts geoeffnet, es gibt also nichts zu verraten. Die
     * Antwortzeit-Frage stellt sich an der aufrufenden Seite trotzdem, und
     * zwar schaerfer (ein echter Versand geht ueber das Netz zu Meta) —
     * dagegen steht dort die Bremse, nicht hier.
     */
    public static function anmeldefaehigePersonFuerNummer(?int $teamId, string $nummer): ?int
    {
        $kandidaten = self::lebendeKandidaten($teamId, PhoneE164::normalize($nummer));

        if (count($kandidaten) !== 1) {
            return null;
        }

        return self::darfSichAnmelden($kandidaten[0]) ? (int) $kandidaten[0]->id : null;
    }

    /**
     * Geht der Code dieses Zwecks an eine noch NICHT hinterlegte Nummer?
     *
     * Der Sender muss dieselbe Frage beantworten wie erzeugeCode(); deshalb
     * ist sie hier oeffentlich und wird dort nicht zum zweiten Mal
     * ausgeschrieben.
     */
    public static function brauchtZielNummer(string $zweck): bool
    {
        return in_array($zweck, self::ZWECKE_MIT_ZIELNUMMER, true);
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
        if (self::brauchtZielNummer($zweck)) {
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
            $alteNummer = $person->phone === null ? null : (string) $person->phone;

            // Die Regel "die Nummer wandert auf alle Anstellungen" lebt in
            // PersonLinker und nur dort — hier wird sie NICHT nachgebaut.
            PersonLinker::setzeNummer($personId, $neueNummer);
            self::zieheCrmKontakteNach($personId);

            // Ein offener Notfall-Antrag ist damit ueberholt (Begruendung an
            // loescheWechselAntrag): dieser Weg hat das Passwort gesehen,
            // jener nicht.
            self::loescheWechselAntrag($personId);

            // NACH setzeNummer() und nicht davor: scheitert der Wechsel an
            // einer im Team schon vergebenen Nummer, wirft setzeNummer() —
            // und dann darf im Protokoll kein Wechsel stehen, der nie
            // stattgefunden hat.
            self::protokolliereWechsel($personId, $alteNummer, $neueNummer, self::ZWECK_NUMMERNWECHSEL);
        }
    }

    /**
     * Die heute hinterlegte Nummer dieser Person — nur LESEND.
     *
     * Warum das hier steht und nicht als eigene Abfrage in der Seite: der
     * Nummernwechsel raeumt die alte Nummer weg (PersonLinker::setzeNummer
     * ueberschreibt rec_persons.phone), und die Begleitregel aus Spec §5
     * verlangt anschliessend einen Hinweis AN DIE ALTE NUMMER. Wer den
     * schicken will, muss sie sich vorher merken. Eine eigene Abfrage in der
     * Seite waere eine zweite Stelle, die die Kontospalten kennt.
     */
    public static function aktuelleNummer(int $personId): ?string
    {
        $wert = DB::table('rec_persons')->where('id', $personId)->value('phone');

        return $wert === null ? null : (string) $wert;
    }

    /**
     * Weg 3 aus Spec §5: "Passwort vergessen" — Code an die Nummer PLUS
     * Geburtsdatum, dann das neue Passwort.
     *
     * WARUM DIESE METHODE HIER LIEGT UND NICHT IN DER SEITE. Der Docblock von
     * loeseCodeEin() warnt ausdruecklich: loeseCodeEin(ZWECK_PASSWORT)
     * gefolgt von setzePasswort() kaeme mit dem Code ALLEIN aus — wer ein
     * fremdes Geraet in der Hand haelt, uebernaehme damit das Konto. Laege
     * die Zwei-Nachweis-Regel (Spec §2.4) in der Seite, koennte die naechste
     * Seite, das naechste Kommando, der naechste HR-Knopf sie vergessen, und
     * nichts fiele auf. Hier gibt es die schwache Reihenfolge schlicht nicht
     * mehr zu bauen.
     *
     * DIE REIHENFOLGE IST TRAGEND, und zwar in beide Richtungen:
     *
     *  1. Die PASSWORTREGEL zuerst. Sie ist eine Formsache und kein
     *     Rateversuch; stuende sie hinter dem Einloesen, verbrennte ein zu
     *     kurzes Passwort den Code, und der Mensch braeuchte fuer jeden
     *     Tippfehler einen neuen — wovon die Sendedrossel drei je Stunde
     *     zulaesst.
     *  2. Das GEBURTSDATUM vor dem Einloesen, aus demselben Grund wie bei
     *     registriere(): ein Tippfehler im Datum darf den Code nicht
     *     verbrennen. Die Grenze gegen das Durchprobieren des Datums ist
     *     deshalb NICHT dieser Code, sondern eine Bremse in der aufrufenden
     *     Seite — wer diese Methode von woanders ruft, braucht eine eigene.
     *  3. Das EINLOESEN zuletzt. Es entwertet den Code und zaehlt
     *     Fehlversuche; erst danach steht das Passwort.
     *
     * EINE MELDUNG FUER BEIDE NACHWEISE, wie bei registriere(): wer erfaehrt,
     * dass der Code stimmte und nur das Datum falsch war, hat den ersten
     * Nachweis bestaetigt bekommen und muss nur noch das Datum raten.
     */
    public static function setzePasswortMitCode(int $personId, string $codeKlartext, string $geburtsdatum, string $passwort): void
    {
        self::offeneZeile($personId);

        // Formsache zuerst (s. Docblock, Punkt 1).
        self::pruefePasswort($passwort);

        if (!self::geburtsdatumStimmt($personId, $geburtsdatum)) {
            throw new InvalidArgumentException('Der Code oder das Geburtsdatum stimmen nicht.');
        }

        self::loeseCodeEin($personId, self::ZWECK_PASSWORT, $codeKlartext);
        self::setzePasswort($personId, $passwort);
    }

    // ------------------------------------------------------- Weg 4, das Fenster

    /**
     * Der zweite Nachweis von Weg 4: Geburtsdatum UND die letzten vier
     * Ziffern der Ausweisnummer.
     *
     * DER EINZIGE ORT, AN DEM AUSWEISZIFFERN IM KONTO NOCH VORKOMMEN (Spec
     * §5). Geprueft wird mit RecEmployee::verifyPortalAccess() — derselben
     * und einzigen Fassung, die auch der alte Token-Weg benutzt. Eine zweite
     * Fassung waere die Stelle, an der eine von beiden lockerer wird.
     *
     * DASS DIESE METHODE NICHTS OEFFNET, IST IHRE WICHTIGSTE EIGENSCHAFT.
     * Sie gibt bool zurueck, keine Kennung und kein Sitzungsrecht. Ihr
     * einziger Aufrufer ist Weg 4, und der endet mit einem BEANTRAGTEN
     * Nummernwechsel — nicht mit einer Anmeldung und nicht mit einem
     * Passwort. Wer aus ihr eine Abkuerzung ins Konto baut, hebt die
     * Umstellung auf: der Benutzername ist die Handynummer und kein
     * Geheimnis, also waere "Nummer + Geburtsdatum + Ausweisziffern" ein
     * Anmeldeweg ohne Passwort.
     *
     * Gefragt werden ALLE AKTIVEN Anstellungen dieses Menschen: bei einem
     * RG/MA-Paar stehen die Ausweisdaten nicht zwingend an beiden Zeilen.
     * verifyPortalAccess() lehnt eine Zeile ohne Geburtsdatum oder ohne
     * Ausweisnummer von sich aus ab — wer nirgends beides hinterlegt hat,
     * kommt ueber Weg 4 nicht weiter und braucht HR (Weg 5). Das ist die
     * gewollte Richtung.
     *
     * NUR LESEND. Weder hier noch im ganzen Weg wird an rec_employees
     * geschrieben; ein Nachweisversuch darf zas_changed_at nicht setzen.
     */
    public static function ausweisNachweisStimmt(int $personId, string $geburtsdatum, string $ausweisEndziffern): bool
    {
        if (trim($geburtsdatum) === '' || trim($ausweisEndziffern) === '') {
            return false;
        }

        foreach (RecEmployee::query()->where('rec_person_id', $personId)->where('is_active', 1)->get() as $anstellung) {
            if ($anstellung->verifyPortalAccess($geburtsdatum, $ausweisEndziffern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weg 4, Schritt 2: den Code einloesen und den Wechsel BEANTRAGEN.
     *
     * Der Unterschied zu loeseCodeEin(ZWECK_NUMMERNWECHSEL) ist der ganze
     * Zweck dieses Weges: hier wird NICHTS gewechselt. Es entsteht ein
     * Antrag, der nach WECHSEL_FRIST_STUNDEN faellig wird und den HR bis
     * dahin stoppen kann.
     *
     * DIE NEUE NUMMER WIRD VOR DEM EINLOESEN GELESEN: loeseCodeEin() raeumt
     * code_neue_nummer ab (das Entwerten ist seine Aufgabe, Fund F13). Wer
     * sie danach liest, findet null und legt einen Antrag ohne Ziel an.
     *
     * Bei einem falschen Code wirft loeseCodeEin(), und es wird NICHTS
     * geschrieben — auch kein Antrag.
     *
     * Ein ZWEITER Antrag ersetzt den ersten vollstaendig, einschliesslich
     * seiner Frist. Sonst stuenden zwei Ziele nebeneinander, und es
     * entschiede die Reihenfolge des Kommandos, welches gewinnt.
     *
     * @return string 'Y-m-d H:i:s' — ab wann der Wechsel faellig ist
     */
    public static function beantrageNummerwechselMitCode(int $personId, string $codeKlartext): string
    {
        $person = self::offeneZeile($personId);

        $neueNummer = $person->code_neue_nummer === null ? null : (string) $person->code_neue_nummer;

        self::loeseCodeEin($personId, self::ZWECK_NOTFALL, $codeKlartext);

        if ($neueNummer === null) {
            // Kann ueber erzeugeCode() nicht entstehen (dort ist die
            // Zielnummer fuer diesen Zweck Pflicht). Die Wache steht hier,
            // weil ein Antrag ohne Ziel spaeter still nichts taete und der
            // Mensch bis zum Anruf bei HR glaubte, er habe es geschafft.
            throw new InvalidArgumentException(
                "Der Code von Person {$personId} traegt keine neue Nummer — ein Antrag ohne Ziel waere wirkungslos.",
            );
        }

        $wirksamAb = now()->addHours(self::WECHSEL_FRIST_STUNDEN);

        DB::table('rec_persons')->where('id', $personId)->update([
            'wechsel_neue_nummer'  => $neueNummer,
            'wechsel_beantragt_at' => now(),
            'wechsel_wirksam_ab'   => $wirksamAb,
            'wechsel_quelle'       => self::ZWECK_NOTFALL,
            'updated_at'           => now(),
        ]);

        // DIE MELDUNG AN HR (Spec §5, Weg 4). Auf der Stufe `warning` und
        // nicht `info`: dieser Weg kommt ohne Passwort aus, und die 24
        // Stunden sind nur dann ein Stopp-Recht, wenn jemand den Antrag auch
        // SIEHT. Zwischen den Versand-Zeilen auf `info` ginge er unter.
        // Sichtbar wird er ausserdem ueber recruiting:konto-zuruecksetzen
        // --offen; das Log allein ist eine Spur, keine Arbeitsliste.
        Log::warning('recruiting.konto.nummernwechsel_beantragt', [
            'person_id'        => $personId,
            'quelle'           => self::ZWECK_NOTFALL,
            'wirksam_ab'       => $wirksamAb->format('Y-m-d H:i:s'),
            'neu_endet_auf'    => substr($neueNummer, -4),
            'alt_endet_auf'    => $person->phone === null ? null : substr((string) $person->phone, -4),
        ]);

        return $wirksamAb->format('Y-m-d H:i:s');
    }

    /**
     * Weg 5 aus Spec §5: "Gar nichts geht — HR traegt die neue Nummer ein."
     *
     * Gibt die ALTE Nummer zurueck (fuer den Hinweis) oder null, wenn es
     * keine gab.
     *
     * WARUM DAS HIER LIEGT UND NICHT IM KOMMANDO. PersonLinker::setzeNummer()
     * allein genuegt NICHT: sein eigener Docblock verpflichtet jeden
     * Aufrufer, den CRM-Kontakt nachzuziehen (Vorfall RG19734) — sonst
     * behaelt der Kontakt die alte Nummer, und der naechste Einmalcode geht
     * ans ALTE Geraet. Genau das wollte HR ja gerade beheben. Dazu kommen
     * das Protokoll und das Abraeumen eines offenen Notfall-Antrags. Drei
     * Pflichten, die ein Kommando vergessen kann; hier kann es sie nicht.
     *
     * KEIN ZWEITER NACHWEIS AUF DIESER EBENE, und das ist Absicht: den
     * ersten erbringt HR ausserhalb des Systems (der Mensch steht davor oder
     * ruft an), den zweiten das GEBURTSDATUM bei der Registrierung — ohne
     * das kommt niemand durch registriere(). Weg 5 haendigt also kein Konto
     * aus, er stellt nur die Einladung wieder zu. Wer hier je ein Passwort
     * mitsetzt, macht aus dem HR-Knopf einen Generalschluessel.
     */
    public static function setzeNummerDurchHr(int $personId, string $neueNummer): ?string
    {
        $person = self::offeneZeile($personId);

        $neu = PhoneE164::normalize($neueNummer);

        if ($neu === null) {
            throw new InvalidArgumentException(
                "Die Nummer \"{$neueNummer}\" ist nicht lesbar — ohne lesbare Nummer gibt es kein Ziel.",
            );
        }

        $alt = $person->phone === null ? null : (string) $person->phone;

        PersonLinker::setzeNummer($personId, $neu);
        self::zieheCrmKontakteNach($personId);
        self::loescheWechselAntrag($personId);
        self::protokolliereWechsel($personId, $alt, $neu, 'hr');

        return $alt;
    }

    /**
     * Die offenen Antraege — die Arbeitsliste von HR.
     *
     * Ohne Frist-Filter: HR soll auch den sehen, der gleich faellig wird,
     * und den, den das Kommando noch nicht angewendet hat. Sortiert nach
     * Faelligkeit, damit das Dringendste oben steht.
     *
     * @return list<object>
     */
    public static function offeneNummernwechsel(?int $teamId = null): array
    {
        return DB::table('rec_persons')
            ->whereNotNull('wechsel_wirksam_ab')
            ->whereNotNull('wechsel_neue_nummer')
            ->whereNull('merged_into_person_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('wechsel_wirksam_ab')
            ->get(['id', 'team_id', 'phone', 'wechsel_neue_nummer', 'wechsel_beantragt_at', 'wechsel_wirksam_ab', 'wechsel_quelle'])
            ->all();
    }

    /**
     * HR stoppt einen Antrag. Gibt zurueck, ob ueberhaupt einer offen war.
     *
     * Der Antrag wird geloescht und nicht als "gestoppt" markiert: der
     * Schlitz ist ein Schlitz und keine Historie (s. Migration
     * 2026_09_29_000003). Dass gestoppt wurde, steht im Protokoll.
     */
    public static function stoppeNummerwechsel(int $personId): bool
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first();

        if ($person === null || $person->wechsel_wirksam_ab === null) {
            return false;
        }

        self::loescheWechselAntrag($personId);

        Log::warning('recruiting.konto.nummernwechsel_gestoppt', [
            'person_id'     => $personId,
            'neu_endet_auf' => $person->wechsel_neue_nummer === null
                ? null
                : substr((string) $person->wechsel_neue_nummer, -4),
        ]);

        return true;
    }

    /**
     * Wendet einen FAELLIGEN Antrag an. Gibt die alte und die neue Nummer
     * zurueck — oder null, wenn nichts (mehr) faellig war.
     *
     * Die alte Nummer braucht der Aufrufer fuer den Hinweis aus Spec §5; nach
     * setzeNummer() steht sie nirgends mehr.
     *
     * DER ANTRAG WIRD ERST NACH dem Wechsel geloescht. Scheitert
     * setzeNummer() (die Nummer gehoert im Team inzwischen jemand anderem),
     * bleibt er stehen und taucht weiter unter --offen auf — genau richtig,
     * denn diesen Fall muss ein Mensch klaeren (Spec §6.2). Wer erst
     * loescht, verliert ihn still.
     *
     * @return array{alt: ?string, neu: string}|null
     */
    public static function wendeNummernwechselAn(int $personId): ?array
    {
        $person = self::offeneZeile($personId);

        if ($person->wechsel_wirksam_ab === null || $person->wechsel_neue_nummer === null) {
            return null;
        }

        // Verglichen wird ueber Zeitstempel und nicht ueber die Zeichenkette:
        // die Spalte kommt je nach Treiber in verschiedenen Schreibweisen
        // zurueck, und ein Zeichenvergleich waere dann mal richtig und mal
        // still falsch.
        if (strtotime((string) $person->wechsel_wirksam_ab) > strtotime(self::jetzt())) {
            return null;
        }

        $alt = $person->phone === null ? null : (string) $person->phone;
        $neu = (string) $person->wechsel_neue_nummer;

        PersonLinker::setzeNummer($personId, $neu);
        self::zieheCrmKontakteNach($personId);
        self::loescheWechselAntrag($personId);
        self::protokolliereWechsel($personId, $alt, $neu, self::ZWECK_NOTFALL);

        return ['alt' => $alt, 'neu' => $neu];
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

    /**
     * "Jeder Wechsel wird protokolliert" — Begleitregel aus Spec §5, Canvas
     * 1789.
     *
     * OHNE DIE NUMMERN IM KLARTEXT, dieselbe Kuerzung auf vier Stellen wie in
     * pruefeAnmeldung(): ein Protokoll ist genau der Ort, an den man spaeter
     * jemanden schauen laesst, und die letzten vier Stellen genuegen, um den
     * Fall in der Akte wiederzufinden. PhoneE164::suffix() waere hier falsch
     * — er liefert neun Ziffern und damit fast die ganze Nummer.
     *
     * Die Stufe ist `notice` und nicht `info`: ein Nummernwechsel ist der
     * Vorgang, nach dem im Streitfall gesucht wird ("seit wann geht sein
     * Code auf ein anderes Geraet?"). Zwischen den Versand-Zeilen des
     * Einmalcode-Senders, die auf `info` stehen, ginge er unter.
     */
    /**
     * Raeumt den offenen Notfall-Antrag ab.
     *
     * Er wird nicht nur beim Anwenden und beim Stoppen geloescht, sondern
     * auch bei JEDEM anderen vollzogenen Nummernwechsel. Grund: Weg 1 und 2
     * verlangen das Passwort und sind damit der staerkere Nachweis. Bliebe
     * ein Notfall-Antrag daneben stehen, zoege er die Nummer 24 Stunden
     * spaeter still noch einmal weiter — auf das Ziel, das jemand OHNE
     * Passwort eingetragen hat. Genau der Fall, gegen den das Fenster gebaut
     * ist, traete dann hinter dem Ruecken des rechtmaessigen Inhabers ein.
     */
    private static function loescheWechselAntrag(int $personId): void
    {
        DB::table('rec_persons')->where('id', $personId)->update([
            'wechsel_neue_nummer'  => null,
            'wechsel_beantragt_at' => null,
            'wechsel_wirksam_ab'   => null,
            'wechsel_quelle'       => null,
            'updated_at'           => now(),
        ]);
    }

    private static function protokolliereWechsel(int $personId, ?string $alt, string $neu, string $weg): void
    {
        Log::notice('recruiting.konto.nummer_gewechselt', [
            'person_id'     => $personId,
            'weg'           => $weg,
            'alt_endet_auf' => $alt === null ? null : substr($alt, -4),
            'neu_endet_auf' => substr($neu, -4),
        ]);
    }

    /**
     * Die LEBENDEN Zeilen zu einer Nummer.
     *
     * Ruling GD-2: OHNE team_id wird ueber ALLE Teams gesucht — die
     * Anmeldeseite ist oeffentlich und hat keinen Team-Kontext. Ein
     * uebergebenes Team schraenkt weiterhin ein (Tests und Kommandos).
     * "Lebend" heisst: nicht zusammengefuehrt; eine stillgelegte Zeile ist
     * ein Grabstein, keine Person. GESPERRTE zaehlen dagegen MIT — sie
     * auszusortieren, um genau eine uebrig zu behalten, waere schon wieder
     * Raten.
     *
     * Erwartet die BEREITS NORMALISIERTE Nummer (oder null): wer hier einen
     * rohen Text hereingibt, vergleicht "0151 ..." gegen das gespeicherte
     * "+49151 ..." und findet nie etwas.
     *
     * @return list<object>
     */
    private static function lebendeKandidaten(?int $teamId, ?string $normalisiert): array
    {
        if ($normalisiert === null) {
            return [];
        }

        return DB::table('rec_persons')
            ->where('phone', $normalisiert)
            ->whereNull('merged_into_person_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->get(['id', 'team_id', 'password_hash', 'locked_at'])
            ->all();
    }

    /**
     * Darf sich diese Zeile ueberhaupt anmelden — unabhaengig vom Passwort?
     *
     * Gesperrt (HR/Datenschutz) meldet sich nie an; stillgelegte sind schon
     * aus der Kandidatenliste gefallen. Und Canvas 1793: Konten
     * Ausgeschiedener werden gesperrt, weil Anbieter Handynummern nach
     * Monaten neu vergeben — sonst meldet sich der naechste Inhaber der
     * Nummer in einer fremden Akte an. Eine Person ganz ohne Anstellung
     * faellt in denselben Zweig.
     *
     * EINE Fassung fuer pruefeAnmeldung() und
     * anmeldefaehigePersonFuerNummer(): liefe die zweite auseinander,
     * bekaeme ein Ausgeschiedener Einmalcodes fuer ein Konto, in das er nicht
     * mehr hineinkommt.
     */
    private static function darfSichAnmelden(object $person): bool
    {
        return $person->locked_at === null && self::hatAktiveAnstellung((int) $person->id);
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
