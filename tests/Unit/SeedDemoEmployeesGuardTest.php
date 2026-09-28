<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\SeedDemoEmployees;

/**
 * Der Waechter des Testdaten-Kommandos. Er ist die einzige Bremse zwischen
 * „erfundene Menschen anlegen" und dem echten Bestand — und es gibt bewusst
 * keinen Schalter, der ihn uebergeht.
 *
 * ZWEI RIEGEL seit dem 26.09.2026 (Schlussfix F7): der Wirt-Vergleich hing an
 * einem MARKENNAMEN. Er greift heute (die Produktion laeuft unter
 * mitarbeiter.rheingedeck.de), faellt aber lautlos aus, sobald eine
 * Produktion unter einer anderen Adresse steht. Die Umgebung zaehlt jetzt
 * mit; der Namensvergleich bleibt daneben und faengt den umgekehrten Fall
 * (Produktionsadresse mit falsch gesetztem APP_ENV).
 */
class SeedDemoEmployeesGuardTest extends TestCase
{
    public function test_produktion_ist_gesperrt(): void
    {
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de'));
    }

    public function test_auch_jede_andere_rheingedeck_adresse_ist_gesperrt(): void
    {
        // Falls jemand spaeter eine zweite Produktionsadresse aufsetzt, soll
        // sie nicht versehentlich durchrutschen.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://Portal.RheinGedeck.de/recruiting'));
    }

    public function test_demo_darf(): void
    {
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de'));
    }

    public function test_lokal_darf(): void
    {
        $this->assertFalse(SeedDemoEmployees::istProduktion('http://localhost:8000'));
    }

    public function test_leere_adresse_gilt_als_produktion(): void
    {
        // Im Zweifel nein. Eine fehlende APP_URL darf nicht die Tuer oeffnen.
        $this->assertTrue(SeedDemoEmployees::istProduktion(''));
        $this->assertTrue(SeedDemoEmployees::istProduktion(null));
        $this->assertTrue(SeedDemoEmployees::istProduktion('kein-gueltiger-wert'));
    }

    // -----------------------------------------------------------------
    // F7 -- der zweite Riegel: die Umgebung
    // -----------------------------------------------------------------

    public function test_produktions_umgebung_ist_gesperrt_auch_unter_fremder_adresse(): void
    {
        // Der Fall, den der Markenname nicht faengt: eine Produktion, die
        // nicht "rheingedeck" heisst.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://portal.beispielkunde.de', 'production'));
        $this->assertTrue(SeedDemoEmployees::istProduktion('http://localhost:8000', 'PRODUCTION'));
    }

    public function test_der_markenname_bleibt_der_zweite_riegel(): void
    {
        // Umgekehrter Fall: Produktionsadresse, aber APP_ENV steht falsch.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de', 'local'));
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de', 'staging'));
    }

    public function test_andere_umgebungen_oeffnen_nichts_von_selbst(): void
    {
        // Die Umgebung kann nur SPERREN, nie erlauben -- und eine
        // unbekannte Umgebung (null) aendert gar nichts.
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', 'staging'));
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', null));
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', ''));
        $this->assertTrue(SeedDemoEmployees::istProduktion('', 'local'));
    }

    // -----------------------------------------------------------------
    // Aufgabe 7 -- die Fall-Liste selbst
    //
    // Ruling P1: SeedDemoEmployees::faelle() ist oeffentlich und statisch --
    // genau derselbe Weg wie istProduktion() oben: kein Container, keine
    // Facade, keine Reflection. Deshalb wird sie hier wie istProduktion()
    // direkt ueber den Klassennamen aufgerufen, nicht ueber eine zweite
    // Zugriffsart in dieser Testklasse.
    // -----------------------------------------------------------------

    public function test_die_beiden_gregors_tragen_denselben_marker(): void
    {
        $faelle = SeedDemoEmployees::faelle();

        $gregors = array_values(array_filter(
            $faelle,
            fn ($f) => str_starts_with($f['token'], 'demo-zwei-firmen'),
        ));

        $this->assertCount(2, $gregors);
        $this->assertSame(
            $gregors[0]['spalten']['person_key'],
            $gregors[1]['spalten']['person_key'],
            'ohne gemeinsamen Marker fuehrt der Fall nichts vor',
        );
        $this->assertNotSame(
            $gregors[0]['spalten']['company'],
            $gregors[1]['spalten']['company'],
            'zwei Datensaetze derselben Firma waeren eine Dublette, keine zweite Anstellung',
        );
    }

    public function test_kein_demo_fall_traegt_eine_telefonnummer(): void
    {
        // Bremse: dieser Test faellt um, sobald irgendein kuenftiger Fall
        // 'phone' in seine Spalten schreibt -- etwa als naheliegende, aber
        // falsche "Reparatur" der Zwei-Firmen-Paarung. Er iteriert ALLE
        // Faelle, nicht nur die heute existierenden zwei Gregors.
        //
        // Diese Bremse sieht nur die STRUKTUR, die faelle() zurueckgibt --
        // eine Nummer, die stattdessen direkt an der Schreibstelle im
        // Insert-Array oder als Argument an PersonLinker::verbinde()
        // haengt, ist fuer sie unsichtbar (Pruefer-Befund I1, Fixrunde 1:
        // 'phone' => null testweise auf eine Nummer geaendert, dieser Test
        // blieb gruen). Die beiden Quelltext-Tests unten schliessen genau
        // diese Luecke.
        foreach (SeedDemoEmployees::faelle() as $fall) {
            $this->assertArrayNotHasKey(
                'phone',
                $fall['spalten'],
                "Fall {$fall['token']} setzt eine Telefonnummer — eine erfundene Nummer koennte einem echten Menschen gehoeren",
            );
        }
    }

    // -----------------------------------------------------------------
    // I1 (Fixrunde 1, Pruefer-Befund): Quelltext-Waechter, Muster
    // PortalShellProfilBladeTest. Der Struktur-Test oben prueft nur, was
    // faelle() zurueckgibt -- eine Nummer, die jemand direkt an der
    // Schreibstelle im Kommando hartkodiert (Insert-Array oder drittes
    // Argument an PersonLinker::verbinde()), sieht er nicht. Genau das ist
    // die naheliegende falsche "Reparatur": die Paarung geht nicht, also
    // sucht jemand die Stelle, an der geschrieben wird, und traegt dort
    // eine Nummer ein. Diese beiden Tests durchsuchen deshalb den
    // QUELLTEXT der Datei selbst, nicht nur die von faelle() gebaute
    // Struktur.
    //
    // FIXRUNDE 2 (N1/N2, Pruefer-Befund): die urspruengliche Fassung
    // benutzte reguleare Ausdruecke und hatte zwei Luecken -- ein Regex,
    // der woertlich '...' (einfache Anfuehrungszeichen) verlangte, liess
    // sich durch "..." umgehen (in PHP bedeutungsgleich); ein zweiter, der
    // Zeilenumbrueche ausschloss (um nicht in die Klassenkommentar-
    // Erwaehnung "PersonLinker::verbinde()" hineinzulaufen), schlug bei
    // einem harmlos mehrzeilig umgebrochenen echten Aufruf faelschlich an.
    // Beide Luecken sind zwei Seiten desselben Problems: ein regulaerer
    // Ausdruck kennt keine PHP-Syntax, nur Zeichenfolgen. token_get_all()
    // schon -- Anfuehrungszeichen-Stil ist fuer den Tokenizer unsichtbar
    // (beide liefern T_CONSTANT_ENCAPSED_STRING), und ein Kommentar ist ein
    // einziges T_COMMENT/T_DOC_COMMENT-Token ohne eigene PersonLinker-/
    // verbinde-/Klammer-Tokens darin -- er kann dem Aufruf-Scanner also gar
    // nicht mehr in die Quere kommen, ohne dass man das eigens ausschliessen
    // muesste. Echte Klammertiefe statt Zeichen-Ausschluss macht ausserdem
    // mehrzeilige Aufrufe erkennbar. token_get_all() ist reines PHP (keine
    // Ausfuehrung des Kommandos, kein Framework, keine Datenbank) und bleibt
    // damit im Rahmen von "tests/Unit bleibt pur".
    //
    // FIXRUNDE 3 (C1/C2, Pruefer-Befund): der phone-Waechter aus Fixrunde 2
    // verglich nur das EINE Token direkt nach '=>' mit 'null' -- 'null ??
    // <Nummer>' beginnt mit dem Token 'null' und rutschte deshalb durch,
    // obwohl die Nummer zur Laufzeit gewinnt. Die Antwort darauf war ein
    // Klammer-Stapel, der jedes 'schluessel => wert'-Paar der Datei
    // aufloeste.
    //
    // FIXRUNDE 4: dieser Stapel ist wieder raus. Er hat die zwei Luecken
    // geschlossen und sich dabei drei FEHLALARME bei voellig harmlosem Code
    // eingehandelt -- ein numerischer Array-Schluessel ([0 => 'erste']) ist
    // kein String-Literal und galt damit als "zusammengesetzter Schluessel";
    // ein match-Ausdruck und eine Arrow-Function (fn ($f) => ...) benutzen
    // '=>' ausserhalb jedes Arrays, ihre '{' bzw. '(' sah der Stapel aber
    // als Array-Rahmen. Das ist kein Zufall, sondern die Bauart: wer die
    // STRUKTUR von PHP nachbaut, bekommt mit jedem Sprachkonstrukt, das
    // '=>' benutzt, einen neuen Sonderfall. Und ein Waechter, der grundlos
    // anschlaegt, wird beim dritten Fehlalarm ausgebaut -- dann ist die
    // Bremse ganz weg, was schlechter ist als ein exotisches Schlupfloch.
    //
    // Deshalb prueft der Waechter jetzt die Eigenschaft, die wirklich
    // gemeint ist, und nur sie: IN DIESER DATEI STEHT NIRGENDS EINE
    // TELEFONNUMMER. Nicht "der Schluessel 'phone' traegt null", sondern:
    // kein Zeichenketten-Literal der Datei sieht aus wie eine Nummer. Das
    // schaut Struktur gar nicht erst an und ist damit von sich aus
    // gleichgueltig gegen Anfuehrungszeichen-Stil, Verkettung, '??',
    // benannte Argumente, die Art des Schluessels, match, fn und alles
    // Weitere, was PHP noch bekommen wird -- und es faengt zusaetzlich eine
    // Nummer, die gar nicht an einem 'phone'-Schluessel haengt (Konstante,
    // Ausgabe-Text, Vorgabewert eines Arguments).
    //
    // BEWUSST GRUEN: eine Nummer in einem KOMMENTAR. Kommentare sind eigene
    // Tokens (T_COMMENT/T_DOC_COMMENT) und werden hier gar nicht erst
    // eingesammelt. Das ist kein Versehen: ein Kommentar wird nirgends
    // hingeschrieben, und der Klassenkommentar des Seeders erklaert genau
    // diese Regel. Wer sie dort mit einem Beispiel belegen will, muss das
    // duerfen -- ein Waechter, der die Begruendung seiner eigenen Existenz
    // rot faerbt, ist der naechste Fehlalarm.
    // -----------------------------------------------------------------

    public function test_im_quelltext_steht_nirgends_eine_telefonnummer(): void
    {
        // Erst der Waechter ueber sich selbst. Ein stumpf gewordener
        // Ausdruck (ein Tippfehler im Muster, eine zu hohe Schwelle) faerbt
        // sonst die ganze Datei gruen, ohne dass es jemandem auffiele --
        // und diese Bremse hat keinen zweiten Waechter hinter sich.
        foreach ([
            '+491701234567',        // international, ohne Trennzeichen
            '+49 170 1234567',      // international mit Leerzeichen
            '+49 (0) 170 1234567',  // international mit Klammer-Null
            '+49-211-1234567',      // international mit Bindestrichen
            '0170 1234567',         // national mit Leerzeichen
            '0170-1234567',         // national mit Bindestrich
            '01701234567',          // national, ohne Trennzeichen
            '(0211) 123456',        // Vorwahl in Klammern
            '0211/1234567',         // Vorwahl mit Schraegstrich
            '0049 170 1234567',     // Auslandspraefix ausgeschrieben
        ] as $nummer) {
            $this->assertNotNull(
                $this->siehtAusWieTelefonnummer($nummer),
                "die Nummern-Form erkennt {$nummer} nicht mehr -- damit bewacht dieser Test nichts",
            );
        }

        // Und die Gegenprobe an den ECHTEN Werten, die heute in der Datei
        // stehen: Geburtsdatum, Ausweisnummer, Personalnummern-Praefix,
        // Token-Namen, Datumsangaben im Ausgabetext. Faellt einer davon
        // durch, ist der Waechter zu scharf und fliegt beim naechsten
        // Fehlalarm raus.
        foreach ([
            '1990-05-17',                   // GEBURTSDATUM
            'L01X00T4711',                  // AUSWEIS
            'DEMO-',                        // PRAEFIX
            '   (17.05.1990)',              // Datum im Ausgabetext
            '  Ausweis-Endziffern  4711',   // Ausweis-Endziffern im Ausgabetext
            'demo-person-gregor',           // person_key
            'demo-zwei-firmen-ma',          // Portal-Token
            '2026-09-28 12:00:00',          // Zeitstempel, falls je einer dazukommt
            '01.02.2026',                   // deutsches Datum mit fuehrender Null
        ] as $harmlos) {
            $this->assertNull(
                $this->siehtAusWieTelefonnummer($harmlos),
                "die Nummern-Form haelt {$harmlos} faelschlich fuer eine Telefonnummer",
            );
        }

        $literale = $this->zeichenkettenLiterale();
        $this->assertNotEmpty($literale, 'kein einziges Zeichenketten-Literal im Quelltext gefunden -- Tokenizer kaputt?');

        foreach ($literale as $literal) {
            $verdacht = $this->siehtAusWieTelefonnummer($literal);

            $this->assertNull(
                $verdacht,
                "im Quelltext steht das Literal {$literal} -- darin sieht {$verdacht} aus wie eine Telefonnummer. "
                . 'Dieses Kommando legt ERFUNDENE Menschen an, und eine erfundene Nummer kann die echte Nummer eines '
                . 'Fremden sein; dann geht eine WhatsApp an ihn. Deshalb steht hier keine. Die Paarung der beiden '
                . 'Gregors laeuft ueber person_key und PersonLinker::verbinde(), NICHT ueber die Nummer -- wer sie '
                . 'hier eintraegt, um die Paarung zu reparieren, repariert die falsche Stelle.',
            );
        }
    }

    public function test_person_linker_wird_im_quelltext_nie_mit_einer_nummer_aufgerufen(): void
    {
        $tokens = $this->bedeutsameTokens();
        $aufrufe = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'PersonLinker') {
                continue;
            }
            if (!$this->tokenIst($tokens[$i + 1] ?? null, T_DOUBLE_COLON)) {
                continue;
            }

            $methode = $tokens[$i + 2] ?? null;
            if (!is_array($methode) || $methode[0] !== T_STRING || $methode[1] !== 'verbinde') {
                continue;
            }

            if (($tokens[$i + 3] ?? null) !== '(') {
                continue; // Erwaehnung ohne Aufruf, z.B. im Klassenkommentar
            }

            $aufrufe[] = $this->argumente($tokens, $i + 4);
        }

        $this->assertNotEmpty(
            $aufrufe,
            'kein PersonLinker::verbinde()-Aufruf im Quelltext gefunden -- Tokenizer kaputt?',
        );

        foreach ($aufrufe as $argumente) {
            $drittesArgument = $argumente[2] ?? null;

            $this->assertSame(
                'null',
                $drittesArgument,
                'PersonLinker::verbinde() wird mit einem dritten Argument aufgerufen, das nicht null ist ('
                . implode(', ', $argumente) . ') -- das waere eine erfundene Telefonnummer',
            );
        }
    }

    /**
     * Liest die Argumente eines Funktionsaufrufs ab der Position direkt
     * NACH der oeffnenden Klammer -- ueber echte Klammertiefe statt ueber
     * Zeichen-Ausschluesse, damit ein mehrzeilig umgebrochener Aufruf
     * genauso erkannt wird wie ein einzeiliger und eine abschliessende
     * Komma (trailing comma) kein leeres viertes Argument erzeugt.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private function argumente(array $tokens, int $start): array
    {
        $argumente = [];
        $aktuell = '';
        $tiefe = 1;

        for ($i = $start; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $tiefe++;
            } elseif ($text === ')') {
                $tiefe--;
                if ($tiefe === 0) {
                    break;
                }
            }

            if ($tiefe === 1 && $text === ',') {
                $argumente[] = trim($aktuell);
                $aktuell = '';
                continue;
            }

            $aktuell .= $text;
        }

        $rest = trim($aktuell);
        if ($rest !== '') {
            $argumente[] = $rest;
        }

        return $argumente;
    }

    /** @param array{0:int,1:string,2:int}|string|null $token */
    private function tokenIst($token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    /**
     * Jedes Zeichenketten-Literal der Datei, mit Anfuehrungszeichen, damit
     * die Fehlermeldung zeigen kann, was woertlich dasteht.
     *
     * Fuer den Tokenizer sind 'x' und "x" dasselbe Token, der
     * Anfuehrungszeichen-Stil ist hier also von vornherein kein Thema. Die
     * Teilstuecke einer Zeichenkette mit eingesetzten Variablen
     * ("... {$wirt} ...") kommen als T_ENCAPSED_AND_WHITESPACE und zaehlen
     * mit -- eine Nummer darin waere sonst unsichtbar. Kommentare sind
     * eigene Tokens und stehen bewusst NICHT in dieser Liste (Begruendung
     * im Block oben).
     *
     * @return list<string>
     */
    private function zeichenkettenLiterale(): array
    {
        $literale = [];

        foreach (token_get_all($this->quelltext()) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literale[] = $token[1];
            }
        }

        return $literale;
    }

    /**
     * Die Nummern-Form. Liefert die verdaechtige Stelle zurueck oder null.
     *
     * Gesucht wird jede Folge aus Ziffern und den ueblichen Trennzeichen
     * (Leerzeichen, Bindestrich, Schraegstrich, Klammern, Punkt, Plus). Als
     * Telefonnummer gilt eine solche Folge, wenn BEIDES zutrifft:
     *
     *  1. Sie beginnt -- nach Abzug der Trennzeichen -- mit '+' oder '0'.
     *     Jede Telefonnummer traegt vorn entweder die Landesvorwahl (+49,
     *     0049) oder die nationale Verkehrsausscheidungsziffer (0170...,
     *     0211...). Ein Datum, eine Ausweisnummer, eine Jahreszahl tun das
     *     nicht: '1990-05-17' faengt mit 1 an, '(17.05.1990)' mit 1,
     *     '4711' mit 4.
     *  2. Sie enthaelt mindestens NEUN Ziffern. Acht ist genau die Groesse
     *     eines Datums (TTMMJJJJ / JJJJMMTT) und der Ziffern in
     *     'L01X00T4711' -- ein deutsches Datum mit fuehrender Null
     *     ('01.02.2026') erfuellt sonst auch Bedingung 1. Eine echte
     *     Nummer liegt darueber: national elf Ziffern (0170 1234567),
     *     international zwoelf bis dreizehn.
     *
     * Beide Bedingungen zusammen lassen alles durch, was heute in der Datei
     * steht, und fangen die deutsche wie die internationale Schreibweise,
     * mit und ohne Leerzeichen, Bindestriche oder Klammern -- geprueft im
     * Test oben an beiden Listen.
     *
     * Bewusst NICHT gefangen: eine Nummer ohne fuehrende 0 oder + und eine
     * Zeichenkette, die viele kleine Zahlen mit Leerzeichen aneinanderreiht
     * und mit 0 beginnt. Das erste ist keine uebliche Schreibweise, das
     * zweite kommt hier nicht vor -- und beides waere nur ueber Regeln zu
     * haben, die wieder bei harmlosem Code anschlagen. Gegen jemanden, der
     * diesen Waechter kennt und bewusst umgehen will, ist er ohnehin nicht
     * gebaut; er steht gegen die gutgemeinte Falsch-Reparatur.
     */
    private function siehtAusWieTelefonnummer(string $text): ?string
    {
        if (!preg_match_all('#[0-9+][0-9+ ()/.\-]*#', $text, $treffer)) {
            return null;
        }

        foreach ($treffer[0] as $folge) {
            $kern = preg_replace('#[ ()/.\-]#', '', $folge);

            if ($kern === '' || ($kern[0] !== '+' && $kern[0] !== '0')) {
                continue; // beginnt nicht wie eine Telefonnummer
            }

            if (strlen(preg_replace('#\D#', '', $kern)) < 9) {
                continue; // zu kurz fuer eine Telefonnummer, Groesse eines Datums
            }

            return rtrim($folge, " ()/.-");
        }

        return null;
    }

    /**
     * Alle Tokens der Datei OHNE Whitespace und Kommentare -- ein Kommentar
     * ist im Tokenizer ein einziges T_COMMENT/T_DOC_COMMENT-Token ohne
     * eigene Tokens fuer seinen Inhalt, kann also nach diesem Filter gar
     * nicht mehr als "PersonLinker", "::", "verbinde" oder "(" erscheinen.
     *
     * @return list<array{0:int,1:string,2:int}|string>
     */
    private function bedeutsameTokens(): array
    {
        $tokens = token_get_all($this->quelltext());

        return array_values(array_filter(
            $tokens,
            fn ($token) => !(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));
    }

    private function quelltext(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/SeedDemoEmployees.php');
    }
}
