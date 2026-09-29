<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\EinmalcodeSender;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Support\PasswortRegeln;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Die Anmeldeseite des Mitarbeiterkontos (Canvas 68, Spec 2.1).
 *
 * Handynummer und Passwort, sonst nichts. Die Entscheidung "wer ist das?"
 * faellt in PortalAuth::anmeldenMitNummer(); diese Seite ist die Oberflaeche
 * davor und traegt nur, was zur SEITE gehoert und nicht zur Anmeldung:
 * das Weiterleitungsziel, den Haken "angemeldet bleiben" und das Oeffnen der
 * Portal-Sitzung.
 *
 * DAS ALTE VERFAHREN STEHT HIER NICHT DANEBEN. Geburtsdatum plus
 * Ausweis-Endziffern bleiben im Token-Portal erreichbar, solange die
 * Umstellung laeuft — aber NICHT auf dieser Seite. Der Grund ist zwingend:
 * der Benutzername ist die Handynummer und damit kein Geheimnis. Waere das
 * alte Verfahren hier als zweiter Weg offen, kaeme jeder, der eine Nummer
 * kennt, ueber die Nebentuer hinein, und die Umstellung machte das Portal
 * unsicherer als vorher. Deshalb gibt es auf dieser Seite kein Feld fuer
 * Geburtsdatum und keines fuer Ausweisziffern.
 *
 * DIE PERSONEN-/ANSTELLUNGS-FALLE (Befund der Aufgabe-5-Pruefung).
 * anmeldenMitNummer() liefert eine PERSONEN-Kennung (rec_persons.id),
 * PortalAuth::sessionKey() erwartet eine ANSTELLUNGS-Kennung
 * (rec_employees.id). Beide sind int, nichts im Typ haelt sie auseinander.
 * Wer die eine in die andere steckt, oeffnet mit einem Konto-Nachweis eine
 * FREMDE Portal-Sitzung — naemlich die der Anstellung, deren Nummer zufaellig
 * gleich der Personen-Kennung ist. Deshalb loest anstellungenVon() die Person
 * ausdruecklich in ihre Anstellungen auf, und nur deren Kennungen erreichen
 * sessionKey().
 *
 * OFFEN, BEWUSST NICHT GEBAUT (Ruling GD-10): "Angemeldet bleiben". Spec 2.1
 * nennt den Haken fuer das eigene Handy, und er stand hier schon einmal — er
 * setzte einen Merker in die Sitzung und bewirkte damit NICHTS. Eine
 * Verlaengerung braucht eine Middleware im Wirt, die die Lebensdauer dieser
 * Sitzung heraufsetzt; das liegt ausserhalb dieses Moduls und wartet auf eine
 * Freigabe. Bis dahin steht der Haken bewusst nicht da: ein
 * Kontrollkaestchen, das nichts tut, ist ein Versprechen, das nicht gehalten
 * wird — der Mensch hakt es an, fliegt nach zwei Stunden heraus und ruft HR
 * an. Was dabei NIE kommen darf, ist ein dauerhaftes Geheimnis im Browser
 * (ein "remember me"-Token): das waere ein zweiter Anmeldeweg ohne Passwort.
 *
 * DIE BREMSE liegt nicht an dieser Route, sondern in PortalAuth: fuenf
 * Versuche je Nummer und fuenfzehn Minuten, dazu dreissig Fehlversuche je
 * Stunde und IP (Ruling GD-11). Eine Route-Drossel haette hier wenig Wert,
 * weil die Anmeldeversuche ueber /livewire/update laufen und die GET-Adresse
 * /konto gar nicht wieder anfassen — sie ersetzt aber auch keine der beiden.
 *
 * Sicherheit: dieselbe Lehre wie aus dem Auth-Bypass vom 19.08.2026 — alles,
 * was ueber Identitaet oder Zustand entscheidet, ist #[Locked]. $wire.set
 * kommt daran nicht vorbei.
 *
 * ------------------------------------------------------------------------
 * DIE WEGE ZURUECK INS KONTO (Spec §5, Canvas 1789, Aufgabe 9)
 * ------------------------------------------------------------------------
 *
 * Wer seine Nummer verliert, sein Passwort vergisst oder beides, kommt ueber
 * diese Seite zurueck. JEDER WEG BRAUCHT ZWEI NACHWEISE — ein Code allein
 * reicht nie, denn ein Code beweist nur, dass jemand ein Geraet in der Hand
 * hat, und Anbieter geben Handynummern nach Monaten neu aus (Spec §6.1).
 *
 *  - WEG 1+2, "Nummer wechseln": alte Nummer + Passwort, dann ein Code an die
 *    NEUE Nummer. Die beiden Zeilen der Spec-Tabelle unterscheiden sich nur
 *    darin, WO der Mensch startet (angemeldet oder nicht) — die Nachweise
 *    sind woertlich dieselben (Passwort, dann Code an die neue Nummer).
 *    Deshalb tragen sie hier EINEN Ablauf. Er lehnt sich ausdruecklich nicht
 *    an die Sitzung an: die Seite ist oeffentlich, und ein Ablauf, der die
 *    Sitzung als Nachweis nimmt, waere der schwaechere der beiden.
 *  - WEG 3, "Passwort vergessen": Code an die hinterlegte Nummer, und beim
 *    Einloesen zusaetzlich das Geburtsdatum. Die Antwort auf die Anforderung
 *    ist IMMER dieselbe, auch fuer eine Nummer, die es gar nicht gibt.
 *  - WEG 4, "Nummer weg UND Passwort vergessen": alte Nummer + Geburtsdatum
 *    + Ausweisziffern, dann ein Code an die neue Nummer. Der Wechsel wird
 *    erst nach 24 Stunden wirksam; HR kann in dieser Zeit stoppen.
 *  - WEG 5 liegt NICHT hier, sondern bei HR (recruiting:konto-zuruecksetzen).
 *
 * WEG 4 IST KEIN ZWEITER ANMELDEWEG, und das ist die wichtigste Eigenschaft
 * dieser Seite. Ausweisziffern kommen im ganzen Konto nur dort vor, und sie
 * oeffnen nichts: der Weg endet mit einem BEANTRAGTEN Nummernwechsel, nicht
 * mit einer Sitzung und nicht mit einem Passwort. Wer ihn durchlaeuft, muss
 * danach immer noch Weg 3 gehen. Waere das alte Verfahren (Geburtsdatum plus
 * Ausweisziffern) hier eine Abkuerzung ins Konto, kaeme jeder, der eine
 * Nummer kennt, ueber die Nebentuer hinein — und die Umstellung machte das
 * Portal unsicherer als vorher.
 *
 * DIE EIGENE BREMSE (Auflage der Aufgabe-8-Pruefung). EinmalcodeSender::sende()
 * nimmt eine PERSONEN-Kennung; seine Drossel greift also erst, wenn die
 * Nummer GEFUNDEN wurde. Wer Nummern durchprobiert, laeuft nie in sie hinein.
 * Deshalb bremst diese Seite selbst, je Adresse der Verbindung
 * (MAX_ANFRAGEN_JE_IP) — und zwar nach dem Muster aus PortalAuth, mit
 * REMOTE_ADDR und ausdruecklich NICHT mit $request->ip() (Ruling GD-12,
 * Begruendung an ipSchluessel()).
 *
 * KEIN STATUS WIRD UNTERSCHEIDBAR ANGEZEIGT. sende() liefert 'sent',
 * 'gedrosselt' oder 'failed'; alle drei sehen fuer den Menschen gleich aus —
 * auch 'failed', denn dahinter steckt unter anderem ein gesperrtes Konto. Und
 * eine unbekannte Nummer antwortet genauso. Der Rueckgabewert wird hier
 * deshalb gar nicht erst ausgewertet; er ist fuer das Protokoll da.
 */
class KontoAnmelden extends Component
{
    /**
     * EINE Meldung fuer jeden Fehlschlag.
     *
     * PortalAuth unterscheidet 'falsch' (falsches Passwort, unbekannte
     * Nummer, mehrdeutige Nummer) und 'gesperrt' (Drossel oder
     * Dispo-Eskalationsstufe 3). Nach aussen darf dieser Unterschied NICHT
     * sichtbar werden: wer die Nummer eines anderen kennt, erfuehre sonst an
     * der Meldung, dass es dazu ein Konto gibt und in welchem Zustand es ist.
     * Die Nummer ist der Benutzername und kein Geheimnis — sie steht in jedem
     * Telefonbuch der Kollegen.
     *
     * Der Hinweis auf spaeter steht deshalb IN derselben Meldung und nicht in
     * einer zweiten: die Drossel ist der einzige Fehlschlag, der von selbst
     * vergeht, und ohne den Satz sucht ein Gesperrter den Fehler bei sich.
     * Gesagt wird er allen gleichermassen.
     */
    public const MELDUNG = 'Das hat nicht geklappt. Bitte prüfen Sie Handynummer und Passwort '
        . 'und versuchen Sie es in einigen Minuten noch einmal.';

    /**
     * EINE Meldung fuer jeden Fehlschlag auf den Wegen zurueck.
     *
     * Sie deckt zusammen ab: falscher Code, abgelaufener Code, falsches
     * Geburtsdatum, falsche Ausweisziffern, unbekannte Nummer, gesperrtes
     * Konto, eine Nummer, die im Team schon jemandem gehoert, und die
     * Bremsen. Genau das ist der Zweck — jede Aufspaltung waere eine Auskunft
     * darueber, welcher der beiden Nachweise gestimmt hat, und dann muss nur
     * noch der andere geraten werden.
     *
     * Sie nennt KEINE Felder beim Namen ("prüfen Sie Ihr Geburtsdatum" waere
     * schon die Auskunft, dass der Code stimmte). "Ihre Eingaben" trifft jeden
     * dieser Faelle gleichermassen.
     */
    public const MELDUNG_ZURUECK = 'Das hat nicht geklappt. Bitte prüfen Sie Ihre Eingaben '
        . 'und versuchen Sie es in einigen Minuten noch einmal.';

    /**
     * Wie viele Anforderungen eines Einmalcodes ueber die Rueckwege je
     * Verbindungsadresse und Stunde — die EIGENE Bremse dieser Seite (Auflage
     * der Aufgabe-8-Pruefung).
     *
     * SIE ZAEHLT JEDEN VERSUCH, nicht nur die fehlgeschlagenen, und das ist
     * der Unterschied zu den Zaehlern in PortalAuth. Dort laesst sich
     * "fehlgeschlagen" feststellen, ohne etwas zu verraten. Hier nicht: ob
     * eine Nummer bekannt ist, ist genau die Auskunft, die diese Seite nicht
     * geben darf — eine Bremse, die nur bei Unbekannten zaehlt, waere selbst
     * das Orakel.
     *
     * ZWANZIG, und die Zahl ist gerechnet, nicht geraten: Nummern
     * durchzuprobieren braucht Tausende von Versuchen, zwanzig je Stunde
     * machen daraus Wochen. Ein echter Mensch braucht einen, im schlechten
     * Fall zwei. Der Abstand dazwischen ist der Spielraum fuer ein Buero, in
     * dem mehrere hinter derselben Adresse sitzen — und sie liegt niedriger
     * als MAX_IP_ATTEMPTS in PortalAuth (dreissig), weil dort nur
     * FEHLVERSUCHE zaehlen und hier jeder Versuch.
     */
    public const MAX_ANFRAGEN_JE_IP = 20;

    /** Eine Stunde, passend zu MAX_ANFRAGEN_JE_IP, an genau einer Stelle. */
    public const IP_FENSTER_SEKUNDEN = 3600;

    /**
     * Fehlversuche am ZWEITEN Nachweis (Geburtsdatum, Ausweisziffern) je
     * Vorgang und Stunde.
     *
     * WARUM TRAGEND: der Einmalcode ist gegen Raten durch Einmalcode::
     * MAX_VERSUCHE gedeckelt, der zweite Nachweis durch gar nichts. Ein
     * plausibler Geburtsjahrgang-Bereich sind rund 25.000 Moeglichkeiten —
     * an einem Nachmittag durch, wenn niemand mitzaehlt. Dieselbe Zahl und
     * dieselbe Begruendung wie bei KontoAnlegen::MAX_VERSUCHE; dort ist es
     * derselbe Nachweis.
     */
    public const MAX_FEHLVERSUCHE = 5;

    /** Eine Stunde. Lang genug, dass Durchprobieren sich nicht lohnt. */
    public const FEHLVERSUCH_FENSTER_SEKUNDEN = 3600;

    /**
     * Die Routen, auf die nach der Anmeldung gesprungen werden darf.
     *
     * POSITIVLISTE, keine Sperrliste: alles, was hier nicht steht, wird
     * verworfen. Ein Weiterleiter, der jede Adresse annimmt, ist eine
     * Einladung — ein Link auf /konto?weiter=https://fremde.seite sieht fuer
     * den Menschen aus wie unsere Anmeldung und schickt ihn danach weiter.
     *
     * Beide Eintraege sind die Ziele aus Spec 2.2: die WhatsApp-Knoepfe
     * (Dokument, Erinnerung) fuehren auf das Portal, die Einsatz-Seite ist
     * die dort benannte Ausnahme (Spec 7).
     *
     * WER HIER ETWAS ERGAENZT, muss zieltAufEigenenToken() mitnehmen: dort
     * wird verlangt, dass ein Pfadsegment des Ziels einer der eigenen
     * portal_token ist. Eine Route OHNE Token in der Adresse liefe sauber
     * durch erlaubtesZiel(), scheiterte dort still und landete auf der
     * Huelle — kein Fehler, kein Log, nur ein Ziel, das nie ankommt.
     * Festgenagelt ist das in einem Waechter-Test, der fuer jeden Eintrag
     * ein {token} in der Route-Adresse verlangt; eine Warnung, die niemand
     * liest, ist keine.
     */
    public const ZIEL_ROUTEN = [
        'recruiting.public.portal-shell',
        'recruiting.public.employee-assignments',
    ];

    /**
     * Der Zustand der Seite. #[Locked] — genau diese Art Eigenschaft war der
     * Bypass vom 19.08.2026 ($wire.set state=verified).
     *
     * 'formular'       die Anmeldung (Nummer + Passwort)
     * 'ohne-ziel'      angemeldet, aber nichts freigeschaltet
     * 'nummer'         Weg 1+2, Schritt 1: alte Nummer, Passwort, neue Nummer
     * 'nummer-code'    Weg 1+2, Schritt 2: der Code von der neuen Nummer
     * 'vergessen'      Weg 3, Schritt 1: die Nummer
     * 'vergessen-code' Weg 3, Schritt 2: Code, Geburtsdatum, neues Passwort
     * 'notfall'        Weg 4, Schritt 1: Nummer, Geburtsdatum, Ausweisziffern,
     *                  neue Nummer
     * 'notfall-code'   Weg 4, Schritt 2: der Code von der neuen Nummer
     * 'fertig'         geschafft; WAS geschafft wurde, sagt $fertigGrund
     *
     * OHNE DIESE SPERRE waere die ganze Aufgabe hinfaellig: ein
     * $wire.set('state', 'nummer-code') aus dem Anmeldeformular heraus
     * uebersprunge den Passwortnachweis von Schritt 1. Dass $personId
     * ebenfalls gesperrt ist, faengt es ein zweites Mal ab — beide Sperren
     * sind noetig, keine steht fuer die andere ein.
     */
    #[Locked] public string $state = 'formular';

    /**
     * Das Weiterleitungsziel, bereits geprueft (s. erlaubtesZiel()).
     *
     * #[Locked], obwohl der Mensch es ueber die Adresse ohnehin selbst
     * bestimmt: gepruft wird EINMAL, in mount(). Ohne die Sperre setzte
     * $wire.set nach der Pruefung einen beliebigen Wert, und die Positivliste
     * waere Zierat.
     */
    #[Locked] public string $weiter = '';

    /**
     * Die Anstellungen, fuer die diese Anmeldung eine Sitzung geoeffnet hat.
     *
     * Gebraucht wird sie nur von abmelden(): der Zustand 'ohne-ziel' bleibt
     * stehen, und ohne diese Liste wuesste die Seite nicht, welche
     * Sitzungsschluessel sie wieder wegnehmen soll.
     *
     * #[Locked] — aber NICHT aus dem Grund, der hier zuerst stand. "Fremde
     * Sitzungen schliessen" geht damit nicht: session()->forget() arbeitet
     * auf der Sitzung DIESER Anfrage, also der des Angreifers selbst.
     *
     * Der echte Grund ist der umgekehrte: mit einer geleerten Liste wird
     * "Abmelden" zur ATTRAPPE. Die Seite sieht abgemeldet aus, die
     * Sitzungsschluessel bleiben stehen — und auf einem geteilten Geraet
     * kommt der Naechste, der die Seite oeffnet, einfach hinein.
     */
    #[Locked] public array $geoeffnet = [];

    /**
     * Die Eingaben — bewusst NICHT gesperrt, sie kommen ja vom Menschen. Ihre
     * Sicherheit sitzt nicht in der Unveraenderlichkeit, sondern darin, dass
     * anmelden() bei JEDEM Aufruf Nummer und Passwort erneut pruefen laesst.
     */
    public string $nummer = '';
    public string $passwort = '';

    /**
     * WESSEN Vorgang hier laeuft — gesetzt erst, NACHDEM der erste Nachweis
     * erbracht ist (Passwort in Weg 1+2, die Nummer in Weg 3).
     *
     * #[Locked], und hier haengt mehr daran als anderswo: mit einer frei
     * setzbaren Personen-Kennung liesse sich im zweiten Schritt eine FREMDE
     * Person einsetzen — der eigene Code, das fremde Konto. Das ist der
     * Auth-Bypass vom 19.08.2026 in seiner teuersten Form.
     *
     * Bleibt in Weg 3 ausdruecklich null, wenn die Nummer unbekannt ist. Der
     * zweite Schritt scheitert dann mit derselben Meldung wie ein falscher
     * Code — sonst waere der Unterschied die Auskunft, dass es die Nummer
     * nicht gibt.
     */
    #[Locked] public ?int $personId = null;

    /**
     * Was im Zustand 'fertig' geschafft wurde: 'nummer' oder 'passwort'.
     *
     * #[Locked], obwohl es nur einen Text auswaehlt: es ist ein Teil des
     * Zustands, und die geschlossene Welt des Waechter-Tests verlangt eine
     * Entscheidung je Feld. Frei setzbar zeigte es einem Menschen "Ihre
     * Nummer wurde geaendert", ohne dass etwas geaendert wurde — eine Seite,
     * die luegt, ist schlimmer als eine, die nichts sagt.
     */
    #[Locked] public string $fertigGrund = '';

    /**
     * Ab wann der beantragte Nummernwechsel wirksam wird — nur eine Anzeige,
     * fertig formatiert.
     *
     * #[Locked], weil sie zum Zustand gehoert: frei setzbar zeigte sie eine
     * Frist, die nicht in der Datenbank steht, und der Mensch richtete sich
     * danach.
     */
    #[Locked] public string $wirksamAb = '';

    /**
     * Die Eingaben der Rueckwege — bewusst NICHT gesperrt, sie kommen ja vom
     * Menschen. Ihre Sicherheit sitzt darin, dass jeder Schritt seine
     * Nachweise erneut pruefen laesst, und zwar im EINEN Schreiber.
     */
    public string $neueNummer = '';
    public string $code = '';
    public string $geburtsdatum = '';
    public string $neuesPasswort = '';
    public string $neuesPasswortWiederholung = '';

    /**
     * Die letzten Ziffern der Ausweisnummer — NUR in Weg 4, und dort nur als
     * zweiter Nachweis neben dem Geburtsdatum (Spec §5).
     *
     * Sie oeffnen nichts: Weg 4 endet mit einem BEANTRAGTEN Nummernwechsel,
     * nicht mit einer Sitzung und nicht mit einem Passwort. Wer daraus eine
     * Abkuerzung ins Konto baut, hebt die Umstellung auf.
     */
    public string $ausweis = '';

    public string $fehler = '';

    public function mount(Request $anfrage): void
    {
        // VERWORFEN, nicht bereinigt: ein fremdes Ziel wird zu einer leeren
        // Zeichenkette und faellt damit auf die eigene Startseite zurueck.
        // Wer es stattdessen "sauberrechnet" (Host abschneiden, Schema
        // entfernen), baut sich aus einer fremden Adresse eine eigene
        // zusammen und trifft am Ende doch etwas, das er nicht treffen
        // sollte.
        $roh = $anfrage->query('weiter', '');

        // NUR eine Zeichenkette kommt in Frage. "?weiter[]=a" liefert ein
        // Array, und jede Umwandlung davon nach string wirft "Array to string
        // conversion" — auf dem Wirt eine 500er-Antwort, von jedem beliebig
        // oft ausloesbar (Fund F2 der Pruefung).
        //
        // NICHT ueber $anfrage->string('weiter'): das ruft Str::of() auf, und
        // dessen Stringable-Bauer wandelt ebenfalls nach string um — nachge-
        // messen mit dieser Laravel-Fassung, die Warnung faellt dort genauso.
        // Die Frage nach dem Typ ist die einzige Fassung, die haelt.
        $this->weiter = self::erlaubtesZiel(is_string($roh) ? $roh : '');
    }

    public function anmelden(PortalAuth $auth): void
    {
        $this->fehler = '';

        // Leere Eingaben kosten keinen Versuch. Ueber die Oberflaeche sind sie
        // durch `required` kaum zu treffen, ueber $wire.call('anmelden') aber
        // trivial. Diese Meldung verraet nichts: sie haengt an der EIGENEN
        // Eingabe und nicht daran, was gespeichert ist.
        if (trim($this->nummer) === '' || $this->passwort === '') {
            $this->fehler = 'Bitte geben Sie Handynummer und Passwort ein.';

            // Auch hier leeren (Fund Q3): dieser Zweig kehrt zurueck, BEVOR
            // das Leeren weiter unten kommt — ein getipptes Passwort neben
            // einer vergessenen Nummer faehrt sonst im Livewire-Schnappschuss
            // weiter mit.
            $this->passwort = '';

            return;
        }

        // Ruling GD-2: KEIN Team. Die Seite ist oeffentlich und hat keinen
        // Team-Kontext; die Suche laeuft ueber alle Teams und scheitert bei
        // mehr als einer lebenden Person mit derselben Antwort wie ein
        // falsches Passwort. Wer hier ein Team einsetzt, erfindet einen
        // Kontext, den die Seite nicht hat — und sperrt damit jeden aus, der
        // nicht in diesem Team steht.
        $ergebnis = $auth->anmeldenMitNummer(null, $this->nummer, $this->passwort);

        // Das Passwort hat nach dem Versuch auf der Seite nichts mehr zu
        // suchen — es faehrt sonst im Livewire-Schnappschuss weiter mit.
        $this->passwort = '';

        if ($ergebnis['status'] !== PortalAuth::OK || $ergebnis['personId'] === null) {
            // 'falsch' und 'gesperrt' muenden hier bewusst in DIESELBE
            // Meldung (s. MELDUNG).
            $this->fehler = self::MELDUNG;

            return;
        }

        $anstellungen = self::anstellungenVon($ergebnis['personId']);

        if ($anstellungen === []) {
            // HEUTE UNERREICHBAR, und bewusst ohne Test (Fund Q1):
            // KontoWriter::pruefeAnmeldung() prueft dieselbe Menge
            // (hatAktiveAnstellung, is_active = 1) und laesst niemanden ohne
            // aktive Anstellung durch. Scharf wird dieser Zweig, sobald die
            // beiden Mengen auseinanderlaufen — etwa wenn dort ein Team-
            // oder Firmen-Schnitt hinzukommt, hier aber nicht, oder wenn
            // zwischen der Pruefung und dieser Zeile die letzte Anstellung
            // beendet wird. Dann greift fail closed: ohne Anstellung gaebe es
            // keinen Sitzungsschluessel, und eine "erfolgreiche" Anmeldung
            // ohne Sitzung waere eine Seite, die stumm nichts tut.
            $this->fehler = self::MELDUNG;

            return;
        }

        // HIER liegt die Falle, vor der der Klassen-Docblock warnt: in
        // sessionKey() gehoert eine ANSTELLUNGS-Kennung, niemals die
        // Personen-Kennung aus $ergebnis.
        $kennungen = [];
        foreach ($anstellungen as $anstellung) {
            $kennungen[] = (int) $anstellung->id;
            session()->put(PortalAuth::sessionKey((int) $anstellung->id), true);
        }
        $this->geoeffnet = $kennungen;

        // DENSELBEN STEMPEL wie der Token-Weg (PortalShell::verify): an ihm
        // haengt die Frage "wer nutzt das Portal ueberhaupt?", mit der die
        // Groesse der Umstellung bestimmt wird. Er stand bisher nur im
        // Token-Weg; die Weiterleitung auf die Huelle setzt ihn NICHT nach,
        // weil dort der Mount-Pfad mit bestehender Sitzung ohne Stempel auf
        // 'verified' springt. Ohne diese Zeile bliebe die Spalte fuer jeden
        // leer, der ueber das Konto hereinkommt.
        //
        // UEBER DEN QUERY BUILDER, damit kein Modell-Ereignis und damit kein
        // Export-Marker entsteht: eine Anmeldung ist keine fachliche
        // Aenderung und hat in der ZAS-Schlange nichts verloren.
        DB::table('rec_employees')->whereIn('id', $kennungen)
            ->update(['portal_verified_at' => now()]);

        $ziel = $this->weiter;

        // Das mitgegebene Ziel muss auf einen EIGENEN Token zeigen.
        //
        // Ohne diese Frage landete der frisch Angemeldete auf UNSERER Domain
        // vor der Anmeldemaske eines fremden Tokens, die nach Geburtsdatum
        // und Ausweis-Endziffern fragt — eine gute Phishing-Kulisse, auch
        // ohne dass Daten abfliessen. Nebenbei liesse sich so der
        // Versuchszaehler eines beliebigen fremden Tokens leerlaufen.
        //
        // KEINE KOPPLUNG an die Parameter der Zielrouten: gefragt wird nur,
        // ob irgendein Pfadsegment einer der eigenen portal_token ist. Die
        // Auflage fuer kuenftige Eintraege in ZIEL_ROUTEN steht dort, wo sie
        // gelesen wird — im Docblock der Liste —, und ein Waechter-Test haelt
        // sie fest.
        if ($ziel !== '' && !self::zieltAufEigenenToken($ziel, $anstellungen)) {
            $ziel = '';
        }

        $ziel = $ziel !== '' ? $ziel : self::startseiteFuer($anstellungen);

        if ($ziel === null) {
            // Angemeldet, aber es gibt nichts zu oeffnen: die Anstellung ist
            // noch nicht auf das neue Portal umgestellt (portal_v2_since).
            // Das darf der Angemeldete erfahren — er hat sein Passwort ja
            // gerade bewiesen.
            $this->state = 'ohne-ziel';

            return;
        }

        $this->redirect($ziel);
    }

    /**
     * Abmelden aus dem Zustand 'ohne-ziel' (Fund Q4).
     *
     * Ohne diesen Weg waere der Zustand eine Sackgasse: angemeldet, kein Weg
     * weiter, und nicht einmal die Moeglichkeit, von vorn anzufangen — etwa
     * weil jemand die Nummer eines Kollegen getippt hat oder weil das Geraet
     * geteilt wird.
     *
     * Geschlossen werden genau die Sitzungen, die DIESE Anmeldung geoeffnet
     * hat. Eine neue Abfrage ueber die Person waere eine zweite Wahrheit und
     * traefe im Zweifel andere Zeilen als das Oeffnen.
     */
    public function abmelden(): void
    {
        foreach ($this->geoeffnet as $kennung) {
            session()->forget(PortalAuth::sessionKey((int) $kennung));
        }

        $this->geoeffnet = [];
        $this->zurAnmeldung();
    }

    // ----------------------------------------------- Die Wege zurueck: Navigation

    /**
     * Zurueck auf die Anmeldung — und dabei wird ALLES abgeraeumt.
     *
     * Insbesondere $personId: eine stehengebliebene Kennung aus einem
     * abgebrochenen Vorgang waere im naechsten genau der erste Nachweis, den
     * niemand mehr erbracht hat. Und die Geheimnisse (Passwort, Code) haben
     * nach einem Wechsel des Zustands auf der Seite nichts mehr zu suchen —
     * sie fuehren sonst im Livewire-Schnappschuss weiter mit.
     */
    public function zurAnmeldung(): void
    {
        $this->state = 'formular';
        $this->personId = null;
        $this->fertigGrund = '';
        $this->nummer = '';
        $this->neueNummer = '';
        $this->geburtsdatum = '';
        $this->ausweis = '';
        $this->wirksamAb = '';
        $this->fehler = '';
        $this->leereGeheimnisse();
    }

    /** Weg 1+2: die Nummer wechseln, mit dem Passwort als erstem Nachweis. */
    public function zumNummernwechsel(): void
    {
        $this->zurAnmeldung();
        $this->state = 'nummer';
    }

    /** Weg 3: Passwort vergessen. */
    public function zumPasswortVergessen(): void
    {
        $this->zurAnmeldung();
        $this->state = 'vergessen';
    }

    /** Weg 4: Nummer weg UND Passwort vergessen. */
    public function zumNotfall(): void
    {
        $this->zurAnmeldung();
        $this->state = 'notfall';
    }

    // ------------------------------------------- Weg 1+2: die Nummer wechseln

    /**
     * Schritt 1: alte Nummer + Passwort + neue Nummer -> Code an die NEUE.
     *
     * DER PASSWORTNACHWEIS LAEUFT UEBER PortalAuth, nicht ueber eine eigene
     * Abfrage. Damit gelten hier dieselben Bremsen wie an der Anmeldung
     * (fuenf Versuche je Nummer, dreissig Fehlversuche je Adresse und Stunde)
     * und dieselbe Dispo-Sperre. Eine zweite Fassung des Passwortnachweises
     * waere genau die Stelle, an der die eine Bremse repariert und die andere
     * vergessen wird.
     *
     * WARUM HIER KEINE EIGENE IP-BREMSE STEHT, anders als bei Weg 3: dieser
     * Ablauf verschickt erst NACH einem richtigen Passwort. Wer Nummern
     * durchprobiert, kommt ueber den Fehlschlag nicht hinaus — und der zaehlt
     * bereits in PortalAuth.
     *
     * DIE NEUE NUMMER WIRD HIER NICHT DARAUF GEPRUEFT, ob sie im Team schon
     * jemandem gehoert. Diese Regel lebt in PersonLinker::setzeNummer() und
     * schlaegt beim Einloesen zu; ein zweiter Pruefweg waere die Doppelung,
     * die dieses Projekt schon mehrfach beseitigt hat. Der Preis steht bei
     * nummerBestaetigen().
     */
    public function nummerAnfordern(PortalAuth $auth, EinmalcodeSender $sender): void
    {
        if ($this->state !== 'nummer') {
            return;
        }

        $this->fehler = '';

        // Leere Eingaben kosten keinen Versuch. Diese Meldung verraet nichts:
        // sie haengt an der EIGENEN Eingabe und nicht daran, was gespeichert
        // ist.
        if (trim($this->nummer) === '' || $this->passwort === '' || trim($this->neueNummer) === '') {
            $this->fehler = 'Bitte füllen Sie alle drei Felder aus.';
            $this->leereGeheimnisse();

            return;
        }

        // Normalisiert VOR dem Versand: an diese Form geht der Code, und in
        // dieser Form wird sie spaeter geschrieben. Eine unlesbare Nummer
        // faellt hier heraus und nicht erst im Sender — dort haette sie
        // bereits den laufenden Code entwertet.
        $neu = PhoneE164::normalize($this->neueNummer);

        if ($neu === null) {
            $this->fehler = 'Diese neue Handynummer können wir nicht lesen. Bitte prüfen Sie die Schreibweise.';
            $this->leereGeheimnisse();

            return;
        }

        $ergebnis = $auth->anmeldenMitNummer(null, $this->nummer, $this->passwort);

        // Das Passwort hat nach dem Versuch auf der Seite nichts mehr zu
        // suchen.
        $this->passwort = '';

        if ($ergebnis['status'] !== PortalAuth::OK || $ergebnis['personId'] === null) {
            $this->fehler = self::MELDUNG;

            return;
        }

        $this->personId = $ergebnis['personId'];

        // DER RUECKGABEWERT WIRD NICHT AUSGEWERTET (s. Klassen-Docblock):
        // 'sent', 'gedrosselt' und 'failed' sehen fuer den Menschen gleich
        // aus. Hier ist der Nachweis zwar schon erbracht, aber eine
        // Unterscheidung an dieser Stelle waere trotzdem eine Auskunft ueber
        // den Zustand des Kontos ("gesperrt") an jemanden, der bloss das
        // Passwort kennt.
        $sender->sende($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, $neu);

        $this->state = 'nummer-code';
    }

    /**
     * Schritt 2: der Code von der neuen Nummer — und damit der zweite
     * Nachweis.
     *
     * DIE ALTE NUMMER WIRD VORHER GEMERKT. loeseCodeEin() zieht
     * PersonLinker::setzeNummer() nach, und danach steht sie nirgends mehr;
     * der Hinweis aus Spec §5 ("die alte Nummer bekommt einmalig einen
     * Hinweis") ginge sonst an das neue Geraet, das ihn nicht braucht.
     *
     * EINE IM TEAM SCHON VERGEBENE NEUE NUMMER endet hier in derselben
     * Meldung wie ein falscher Code, und der Code ist dabei verbraucht
     * (loeseCodeEin entwertet, bevor PersonLinker wirft — dessen Docblock
     * erklaert, warum das kein Versehen ist). Das ist bewusst so: die
     * Ausnahme nennt die fremde Personen-Kennung, und "diese Nummer gehoert
     * schon einem Konto" ist genau die Auskunft, die diese Seite niemandem
     * geben darf. Der Mensch fordert einen neuen Code an; den Fall muss
     * ohnehin HR klaeren (Spec §6.2).
     */
    public function nummerBestaetigen(NummernwechselHinweisSender $hinweis): void
    {
        if ($this->state !== 'nummer-code' || $this->personId === null) {
            return;
        }

        $this->fehler = '';

        if (trim($this->code) === '') {
            $this->fehler = 'Bitte geben Sie den Code ein, den wir Ihnen geschickt haben.';

            return;
        }

        $alteNummer = KontoWriter::aktuelleNummer($this->personId);

        try {
            KontoWriter::loeseCodeEin($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, trim($this->code));
        } catch (InvalidArgumentException) {
            // Die Ausnahme wird NICHT angezeigt und NICHT protokolliert: sie
            // benennt, woran es lag.
            $this->code = '';
            $this->fehler = self::MELDUNG_ZURUECK;

            return;
        }

        $this->code = '';

        if ($alteNummer !== null) {
            // "sofern noch zustellbar" laesst sich vorher nicht feststellen —
            // der Sender versucht es und protokolliert das Ergebnis. Ein
            // Fehlschlag dreht den Wechsel NICHT zurueck; er ist vollzogen.
            $hinweis->sende($this->personId, $alteNummer);
        }

        $this->fertigGrund = 'nummer';
        $this->state = 'fertig';
    }

    // ----------------------------------------------- Weg 3: Passwort vergessen

    /**
     * Schritt 1: die Nummer — und sonst nichts.
     *
     * DIE ANTWORT IST IMMER DIESELBE (Spec §5, Begleitregel; Canvas 1789):
     * bekannte Nummer, unbekannte Nummer, gesperrtes Konto, gedrosselter
     * Versand, ausgefallene Meta-Vorlage — die Seite geht in JEDEM Fall in
     * denselben Zustand mit demselben Text. Sonst liessen sich Nummern
     * durchprobieren, und wer eine findet, weiss, dass dahinter ein Mensch
     * mit Ausweiskopie und Bankverbindung steht.
     *
     * WARUM DER CODE OHNE ZWEITEN NACHWEIS RAUSGEHT: er geht an die
     * hinterlegte Nummer, also an das Geraet, das sie ohnehin hat — er
     * erreicht niemanden, der nicht schon Zugriff darauf hat. Der zweite
     * Nachweis (Geburtsdatum) steht im naechsten Schritt, dort, wo das
     * Passwort wirklich gesetzt wird. Genau das ist das Gegenmittel gegen
     * Spec §6.1: der neue Inhaber einer neu vergebenen Nummer bekommt den
     * Code und kommt trotzdem nicht weiter.
     *
     * HIER STEHT DIE EIGENE BREMSE (Auflage der Aufgabe-8-Pruefung): die
     * Drossel des Senders greift erst, wenn die Person gefunden ist, und
     * schuetzt deshalb genau den Fall nicht, um den es hier geht.
     */
    public function passwortCodeAnfordern(EinmalcodeSender $sender): void
    {
        if ($this->state !== 'vergessen') {
            return;
        }

        $this->fehler = '';

        if (trim($this->nummer) === '') {
            $this->fehler = 'Bitte geben Sie Ihre Handynummer ein.';

            return;
        }

        // Die Bremse zaehlt VOR dem Nachschlagen: wuerde sie danach zaehlen,
        // haette jeder Versuch die Datenbank schon angefasst — und genau
        // diese Kosten soll sie deckeln.
        if ($this->darfAnfordern()) {
            // Dieselbe Menge, ueber die auch die Anmeldung entscheidet
            // (KontoWriter). Eine eigene Abfrage hier waere eine zweite
            // Fassung derselben Regel.
            $this->personId = KontoWriter::anmeldefaehigePersonFuerNummer(null, $this->nummer);

            if ($this->personId !== null) {
                $sender->sende($this->personId, KontoWriter::ZWECK_PASSWORT);
            }
        }

        // IMMER derselbe Ausgang. Auch wenn nichts verschickt wurde.
        $this->state = 'vergessen-code';
    }

    /**
     * Schritt 2: Code + Geburtsdatum + neues Passwort.
     *
     * DIE ZWEI NACHWEISE PRUEFT DER SCHREIBER, nicht diese Seite:
     * KontoWriter::setzePasswortMitCode() verlangt beide und setzt das
     * Passwort nur, wenn beide stimmen. Hier steht nur, was zur SEITE
     * gehoert — die Passwort-Wiederholung, die Bremse und die eine Meldung.
     *
     * DIE PASSWORT-VORPRUEFUNG STEHT VOR DER BREMSE UND VOR DEM SCHREIBER,
     * aus demselben Grund wie bei KontoAnlegen: ein zu kurzes Passwort ist
     * eine Formsache und kein Rateversuch. Ohne sie sperrte sich aus, wer
     * fuenfmal ein zu kurzes Passwort tippt — und der Code waere jedes Mal
     * verbrannt.
     */
    public function passwortSetzen(): void
    {
        if ($this->state !== 'vergessen-code') {
            return;
        }

        $this->fehler = '';

        if (trim($this->code) === '' || trim($this->geburtsdatum) === '' || $this->neuesPasswort === '') {
            $this->fehler = 'Bitte füllen Sie alle Felder aus.';
            $this->leereGeheimnisse();

            return;
        }

        if ($this->neuesPasswort !== $this->neuesPasswortWiederholung) {
            $this->fehler = 'Die beiden Passwörter sind nicht gleich. Bitte tippen Sie sie noch einmal.';
            $this->leereGeheimnisse();

            return;
        }

        // Geprueft wird mit DERSELBEN Klasse, die der Schreiber benutzt —
        // kein zweiter Massstab.
        $passwortMeldung = PasswortRegeln::pruefe($this->neuesPasswort);

        if ($passwortMeldung !== null) {
            $this->fehler = $passwortMeldung;
            $this->leereGeheimnisse();

            return;
        }

        // Unbekannte Nummer ($personId === null) und ausgeschoepfte Bremse
        // muenden in DIESELBE Meldung wie ein falscher Code. Der Zweig fuer
        // die unbekannte Nummer ist die zweite Haelfte der Begleitregel aus
        // Schritt 1: ohne ihn endete der Weg fuer eine unbekannte Nummer
        // sichtbar anders.
        if ($this->personId === null || RateLimiter::tooManyAttempts(self::nachweisSchluessel($this->personId), self::MAX_FEHLVERSUCHE)) {
            $this->fehler = self::MELDUNG_ZURUECK;
            $this->leereGeheimnisse();

            return;
        }

        try {
            KontoWriter::setzePasswortMitCode(
                $this->personId,
                trim($this->code),
                trim($this->geburtsdatum),
                $this->neuesPasswort,
            );
        } catch (InvalidArgumentException) {
            RateLimiter::hit(self::nachweisSchluessel($this->personId), self::FEHLVERSUCH_FENSTER_SEKUNDEN);

            $this->fehler = self::MELDUNG_ZURUECK;
            $this->leereGeheimnisse();

            return;
        }

        RateLimiter::clear(self::nachweisSchluessel($this->personId));

        $this->leereGeheimnisse();
        $this->geburtsdatum = '';
        $this->fertigGrund = 'passwort';
        $this->state = 'fertig';
    }

    // ------------------------------- Weg 4: Nummer weg UND Passwort vergessen

    /**
     * Schritt 1: alte Nummer + Geburtsdatum + Ausweisziffern + neue Nummer.
     *
     * DIE BEIDEN NACHWEISE STEHEN HIER UND NICHT IM ZWEITEN SCHRITT, anders
     * als bei Weg 3 — und der Unterschied ist zwingend: der Code geht an eine
     * Nummer, die der Anfordernde SELBST eingetippt hat. Ohne Nachweis vorher
     * koennte jeder eine Vorlagennachricht an eine beliebige fremde Nummer
     * ausloesen, auf unsere Rechnung und unter unserem Absender. Bei Weg 3
     * geht der Code an die HINTERLEGTE Nummer; dort stellt sich die Frage
     * nicht.
     *
     * DIE ANTWORT IST TROTZDEM IMMER DIESELBE. Stimmen die Nachweise nicht,
     * wird nichts verschickt — aber die Seite geht in denselben Zustand mit
     * demselben Text. Sonst waeren die Ausweisziffern ein Orakel: wer eine
     * Nummer kennt, koennte ausprobieren, welches Geburtsdatum dazu passt.
     *
     * ZWEI BREMSEN, und sie zaehlen Verschiedenes:
     *  - Die IP-Bremse zaehlt JEDEN Versuch (wie bei Weg 3) und deckelt das
     *    Durchprobieren von NUMMERN.
     *  - Die Nachweis-Bremse zaehlt nur FEHLVERSUCHE je Person und deckelt
     *    das Durchprobieren von Geburtsdatum und Ausweisziffern. Sie darf
     *    hier auf Fehlversuche zaehlen, ohne etwas zu verraten: ihr
     *    Schluessel entsteht erst, NACHDEM die Nummer gefunden wurde, und
     *    nach aussen aendert sie an der Antwort nichts.
     *
     * GEBURTSDATUM UND AUSWEISZIFFERN WERDEN DANACH GELEERT. Sie sind der
     * sensibelste Teil dieser Seite und haben im Schnappschuss des zweiten
     * Schritts nichts mehr zu suchen.
     */
    public function notfallAnfordern(EinmalcodeSender $sender): void
    {
        if ($this->state !== 'notfall') {
            return;
        }

        $this->fehler = '';

        if (trim($this->nummer) === '' || trim($this->geburtsdatum) === ''
            || trim($this->ausweis) === '' || trim($this->neueNummer) === '') {
            $this->fehler = 'Bitte füllen Sie alle Felder aus.';

            return;
        }

        $neu = PhoneE164::normalize($this->neueNummer);

        if ($neu === null) {
            $this->fehler = 'Diese neue Handynummer können wir nicht lesen. Bitte prüfen Sie die Schreibweise.';

            return;
        }

        if ($this->darfAnfordern()) {
            $personId = KontoWriter::anmeldefaehigePersonFuerNummer(null, $this->nummer);

            if ($personId !== null && !RateLimiter::tooManyAttempts(self::nachweisSchluessel($personId), self::MAX_FEHLVERSUCHE)) {
                if (KontoWriter::ausweisNachweisStimmt($personId, trim($this->geburtsdatum), trim($this->ausweis))) {
                    RateLimiter::clear(self::nachweisSchluessel($personId));

                    $this->personId = $personId;
                    $sender->sende($personId, KontoWriter::ZWECK_NOTFALL, $neu);
                } else {
                    RateLimiter::hit(self::nachweisSchluessel($personId), self::FEHLVERSUCH_FENSTER_SEKUNDEN);
                }
            }
        }

        $this->geburtsdatum = '';
        $this->ausweis = '';

        // IMMER derselbe Ausgang. Auch wenn nichts verschickt wurde.
        $this->state = 'notfall-code';
    }

    /**
     * Schritt 2: der Code von der neuen Nummer — und damit der Antrag.
     *
     * HIER WIRD NICHTS GEWECHSELT UND NICHTS GEOEFFNET. Der Wechsel ist
     * beantragt und wird nach KontoWriter::WECHSEL_FRIST_STUNDEN faellig;
     * bis dahin kann HR ihn stoppen (recruiting:konto-zuruecksetzen
     * --stopp=<id>). Wer diesen Weg gegangen ist, hat danach immer noch kein
     * Passwort — er muss anschliessend Weg 3 gehen. Genau das macht Weg 4 zu
     * keinem zweiten Anmeldeweg.
     *
     * EINE LEERE PERSONEN-KENNUNG endet in DERSELBEN Meldung wie ein
     * falscher Code, und dieser Zweig ist tragend: er ist die zweite Haelfte
     * der Zusage aus Schritt 1. Ohne ihn saehe der Weg fuer jemanden, dessen
     * Nachweise nicht stimmten, sichtbar anders aus als fuer jemanden mit
     * falschem Code.
     */
    public function notfallBestaetigen(): void
    {
        if ($this->state !== 'notfall-code') {
            return;
        }

        $this->fehler = '';

        if (trim($this->code) === '') {
            $this->fehler = 'Bitte geben Sie den Code ein, den wir Ihnen geschickt haben.';

            return;
        }

        if ($this->personId === null) {
            $this->code = '';
            $this->fehler = self::MELDUNG_ZURUECK;

            return;
        }

        try {
            $wirksamAb = KontoWriter::beantrageNummerwechselMitCode($this->personId, trim($this->code));
        } catch (InvalidArgumentException) {
            $this->code = '';
            $this->fehler = self::MELDUNG_ZURUECK;

            return;
        }

        $this->code = '';
        $this->wirksamAb = Carbon::parse($wirksamAb)->format('d.m.Y, H:i') . ' Uhr';
        $this->fertigGrund = 'notfall';
        $this->state = 'fertig';
    }

    public function render()
    {
        return view('recruiting::livewire.public.konto-anmelden')
            ->layout('recruiting::layouts.portal', [
                'title' => 'Anmelden · RheinGedeck',
            ]);
    }

    // ------------------------------------------------------------------ intern

    /**
     * Raeumt die Geheimnisse von der Seite.
     *
     * Passwort und Einmalcode fahren sonst im Livewire-Schnappschuss weiter
     * mit — bei JEDEM folgenden Aufruf, auch dem, der bloss ein Feld
     * aktualisiert. An EINER Stelle, weil es sonst genau der Zweig vergisst,
     * der frueh zurueckkehrt (Fund Q3 der Aufgabe-7-Pruefung, damals in
     * anmelden()).
     */
    private function leereGeheimnisse(): void
    {
        $this->passwort = '';
        $this->code = '';
        $this->neuesPasswort = '';
        $this->neuesPasswortWiederholung = '';
    }

    /**
     * Darf von dieser Adresse noch ein Code angefordert werden — und wenn
     * ja, zaehle diesen Versuch mit.
     *
     * Fragen und Zaehlen in EINER Methode, damit es keinen Aufrufer geben
     * kann, der fragt und das Zaehlen vergisst; genau so waere die Bremse
     * eine, die nicht bremst.
     */
    private function darfAnfordern(): bool
    {
        $schluessel = self::ipSchluessel();

        if (RateLimiter::tooManyAttempts($schluessel, self::MAX_ANFRAGEN_JE_IP)) {
            return false;
        }

        RateLimiter::hit($schluessel, self::IP_FENSTER_SEKUNDEN);

        return true;
    }

    /**
     * Der Bremsschluessel der Adresse, von der die Anfrage kommt.
     *
     * NICHT $request->ip(), UND DAS IST DER GANZE PUNKT (Ruling GD-12,
     * ausfuehrlich begruendet in PortalAuth::ipSchluessel()). Der Wirt setzt
     * trustProxies(at: '*'); damit liest Laravel die Adresse aus der Kopfzeile
     * X-Forwarded-For, und die schreibt der Anfragende selbst. Eine Bremse
     * darauf waere schlimmer als gar keine: ein neuer Kopfzeilen-Wert je
     * Anfrage gaebe einen frischen Zaehler (sie bremste also nicht), und
     * zugleich sperrte ein Fremder mit der Adresse eines Bueros in der
     * Kopfzeile genau dieses Buero aus.
     *
     * Genommen wird REMOTE_ADDR, die Adresse der TCP-Verbindung. Sie steht
     * fest, bevor irgendeine Kopfzeile gelesen wird.
     *
     * GEHASHT: eine IP ist ein personenbezogenes Datum und hat im Klartext
     * nichts in der Cache-Tabelle zu suchen, und ein Hash ist immer gleich
     * lang (cache.key ist varchar(255) PRIMARY KEY).
     *
     * OHNE ANFRAGE faellt alles auf EINEN Schluessel. Das ist gewollt und
     * harmlos: ueber diesen Weg fordert niemand von der Konsole einen Code
     * an, und ein gemeinsamer Zaehler ist strenger als gar keiner.
     */
    private static function ipSchluessel(): string
    {
        $anfrage = app()->bound('request') ? app('request') : null;
        $ip = $anfrage instanceof Request ? (string) $anfrage->server->get('REMOTE_ADDR', '') : '';

        return 'konto-zurueck:ip:' . hash('xxh128', $ip);
    }

    /**
     * Der Bremsschluessel fuer den zweiten Nachweis.
     *
     * AN DER PERSON, nicht an der getippten Nummer: an dieser Stelle ist die
     * Person schon aufgeloest, und der Zaehler soll genau den Vorgang
     * bremsen, an dem geraten wird. Die Kennung ist eine laufende Zahl ohne
     * Personenbezug und braucht keinen Hash; gedeckelt ist die
     * Schluessellaenge damit trotzdem (derselbe Gedanke wie beim
     * Personen-Schluessel im Einmalcode-Sender).
     */
    private static function nachweisSchluessel(int $personId): string
    {
        return 'konto-zurueck:nachweis:' . $personId;
    }

    /**
     * Die Anstellungen DIESER Person.
     *
     * Deckungsgleich mit KontoWriter::hatAktiveAnstellung() — dieselbe Menge,
     * die dort ueber "darf sich ueberhaupt anmelden?" entscheidet. Eine
     * andere Menge hier hiesse: jemand kommt durch die Anmeldung und bekommt
     * trotzdem keine Sitzung, oder umgekehrt.
     *
     * Die Sitzung wird fuer ALLE aktiven Anstellungen geoeffnet, nicht nur
     * fuer eine: das Konto gehoert der Person, und wer zwei Anstellungen hat
     * (RG und MA), soll nicht bei der zweiten wieder vor einer Anmeldung
     * stehen. Das ist keine Verschraenkung zweier Menschen, sondern die
     * Personen-Klammer — die Zeilen haengen ueber rec_person_id ausdruecklich
     * an derselben Person.
     *
     * @return list<object>
     */
    private static function anstellungenVon(int $personId): array
    {
        return RecEmployee::query()
            ->where('rec_person_id', $personId)
            ->where('is_active', 1)
            ->orderBy('id')
            ->get(['id', 'portal_token', 'portal_v2_since'])
            ->all();
    }

    /**
     * Traegt das Ziel einen Token, der DIESEM Menschen gehoert?
     *
     * Verglichen werden ganze Pfadsegmente, nicht Teilzeichenketten: sonst
     * genuegte ein Ziel, in dem der eigene Token irgendwo vorkommt
     * ("/einsaetze/tok-fremd?x=tok-eigen"), und die Frage waere umgangen.
     *
     * @param list<object> $anstellungen
     */
    private static function zieltAufEigenenToken(string $ziel, array $anstellungen): bool
    {
        $eigene = [];
        foreach ($anstellungen as $anstellung) {
            $token = (string) ($anstellung->portal_token ?? '');

            if ($token !== '') {
                $eigene[] = $token;
            }
        }

        if ($eigene === []) {
            return false;
        }

        $pfad = (string) (parse_url($ziel, PHP_URL_PATH) ?: '');
        $segmente = array_map('rawurldecode', explode('/', $pfad));

        return array_intersect($segmente, $eigene) !== [];
    }

    /**
     * Wohin es ohne mitgegebenes Ziel geht: das neue Portal der ersten
     * Anstellung, die dorthin umgestellt ist.
     *
     * Die Reihenfolge ist die Kennung (orderBy id) und damit fest — ohne eine
     * feste Reihenfolge landete derselbe Mensch mal in der einen, mal in der
     * anderen Anstellung, je nachdem, wie die Datenbank die Zeilen liefert.
     * Ohne Token gibt es keine Adresse, ohne portal_v2_since antwortet die
     * Huelle mit 404 — beides faellt hier heraus, statt hinterher als leere
     * Seite aufzuschlagen.
     *
     * @param list<object> $anstellungen
     */
    private static function startseiteFuer(array $anstellungen): ?string
    {
        foreach ($anstellungen as $anstellung) {
            $token = (string) ($anstellung->portal_token ?? '');

            if ($token !== '' && $anstellung->portal_v2_since !== null) {
                return route('recruiting.public.portal-shell', ['token' => $token]);
            }
        }

        return null;
    }

    /**
     * Das mitgefuehrte Weiterleitungsziel — oder eine leere Zeichenkette.
     *
     * Spec 2.2, woertlich aus Canvas 68: "WhatsApp-Knoepfe fuehren zur
     * Login-Seite und nach dem Passwort direkt zum Ziel. Ein Link meldet nie
     * von selbst an." Das Ziel faehrt also in der Adresse mit — und genau
     * deshalb muss es geprueft werden.
     *
     * Geprueft wird in zwei Stufen, und beide sind noetig:
     *
     *  1. STRUKTUR: ein eigenes Ziel hat weder Schema noch Host. Diese Stufe
     *     steht VOR dem Nachschlagen, weil Request::create() aus
     *     "https://fremde.seite/einsaetze/x" klaglos einen Treffer auf
     *     /einsaetze/{token} macht — der Pfad passt ja. Ohne diese Stufe
     *     kaeme eine fremde Adresse durch die Positivliste.
     *  2. POSITIVLISTE: der Pfad muss eine EIGENE Route treffen, und deren
     *     Name muss in ZIEL_ROUTEN stehen. Nachgeschlagen wird im echten
     *     Routenverzeichnis und nicht an einer nachgebauten Pfadliste — eine
     *     zweite Fassung der Adressen driftet beim naechsten Umbau ab.
     *
     * Was diese Pruefung NICHT leistet: sie sagt nicht, ob der Token im Ziel
     * dem Angemeldeten gehoert. Das muss sie auch nicht — beide Zielseiten
     * entscheiden selbst ueber ihren Token (die Huelle ueber den
     * Sitzungsschluessel ihrer eigenen Anstellung, die Einsatz-Seite ueber
     * den Token selbst). Ein untergeschobener fremder Token fuehrt dort also
     * auf die Anmeldung und nicht in fremde Daten.
     */
    private static function erlaubtesZiel(string $eingabe): string
    {
        $roh = trim($eingabe);

        if ($roh === '') {
            return '';
        }

        // Steuerzeichen gehoeren in keine Adresse. Sie sind das Werkzeug, mit
        // dem eine Pruefung ueberlistet wird (ein Zeilenumbruch beendet fuer
        // manchen Leser die Zeichenkette, fuer den naechsten nicht).
        if (preg_match('/[\x00-\x1F\x7F]/', $roh) === 1) {
            return '';
        }

        // Rueckwaerts-Schraegstriche: Browser lesen sie wie Schraegstriche,
        // parse_url nicht. "/\fremde.seite" waere hier ein harmloser Pfad und
        // im Browser ein fremder Host.
        //
        // BERICHTIGT NACH DER MUTATION: diese Zeile faengt heute KEINEN Fall,
        // den nicht schon etwas anderes faengt — Symfony lehnt einen
        // Rueckwaerts-Schraegstrich in Request::create() selbst ab
        // ("A URI cannot contain a backslash"), und das ergibt unten im
        // try/catch dasselbe Ergebnis. Sie bleibt trotzdem stehen, weil die
        // Abwehr sonst an einem Fremdverhalten haengt, das niemand hier
        // nachliest; ein Test kann sie aber nicht gruen halten. Wer sie
        // entfernt, faellt in keinem Lauf auf.
        if (str_contains($roh, '\\')) {
            return '';
        }

        // Schemarelativ ("//fremde.seite/x") — der Browser liest "fremde.seite"
        // als Host, obwohl kein Schema dasteht.
        //
        // BERICHTIGT NACH DER MUTATION: diese Zeile und der Host-Eintrag in
        // der Liste unten decken DENSELBEN Fall ab; nimmt man eine von beiden
        // weg, wird kein Test rot, nimmt man beide weg, faellt die Abwehr.
        // Der urspruengliche Grund ("sie faengt auch '///x', wo parse_url
        // keinen Host findet") stimmt nicht: bei "///x" gibt parse_url
        // schlicht false zurueck, und das faengt die Wache darunter. Beide
        // bleiben stehen, weil jede fuer sich naheliegend ist und die
        // doppelte Abwehr nichts kostet.
        if (str_starts_with($roh, '//')) {
            return '';
        }

        $teile = parse_url($roh);

        if ($teile === false) {
            return '';
        }

        // Ein eigenes Ziel traegt NICHTS davon. Mit Schema oder Host waere es
        // eine fremde Adresse, mit Benutzer oder Kennwort eine Adresse, die
        // dem Menschen etwas anderes zeigt, als sie anspringt.
        foreach (['scheme', 'host', 'port', 'user', 'pass'] as $fremd) {
            if (isset($teile[$fremd])) {
                return '';
            }
        }

        $pfad = (string) ($teile['path'] ?? '');

        if ($pfad === '' || !str_starts_with($pfad, '/')) {
            return '';
        }

        try {
            $treffer = app('router')->getRoutes()->match(Request::create($pfad, 'GET'));
        } catch (\Throwable) {
            // Kein Treffer, falsche Methode, kaputter Pfad: alles dasselbe —
            // kein Ziel.
            return '';
        }

        if (!in_array((string) $treffer->getName(), self::ZIEL_ROUTEN, true)) {
            return '';
        }

        return $roh;
    }
}
