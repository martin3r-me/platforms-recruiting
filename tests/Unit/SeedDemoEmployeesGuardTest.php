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
    // obwohl die Nummer zur Laufzeit gewinnt. Der verbinde()-Waechter
    // daneben konnte das laengst richtig (argumente() sammelt ALLE Tokens
    // bis zur Grenze ein) -- derselbe Datei-Commit hatte also zwei
    // verschiedene Antworten auf dieselbe Frage. schluesselWertPaare()
    // unten zieht diese Faehigkeit jetzt in eine gemeinsame Stelle: der
    // GANZE Ausdruck nach '=>' zaehlt, nicht das erste Token. Zugleich wird
    // jeder Array-Schluessel, der nicht aus GENAU EINEM String-Literal
    // besteht (etwa eine Verkettung wie ('ph' . 'one')), nicht mehr still
    // uebersprungen, sondern faellt selbst als eigener Verdachtsfall auf --
    // im Seeder gibt es keinen legitimen Grund fuer einen zusammengesetzten
    // Schluessel, und ein Waechter, der bei Unbekanntem schweigt statt zu
    // melden, ist keiner.
    // -----------------------------------------------------------------

    public function test_im_quelltext_wird_phone_nirgends_auf_etwas_anderes_als_null_gesetzt(): void
    {
        $paare = $this->schluesselWertPaare();
        $this->assertNotEmpty($paare, 'kein Array-Schluessel-Wert-Paar im Quelltext gefunden -- Tokenizer kaputt?');

        $phoneGefunden = false;

        foreach ($paare as $paar) {
            // C2: ein Schluessel aus mehr als einem Token oder aus etwas
            // anderem als einem String-Literal (Verkettung, Variable,
            // Funktionsaufruf, ...) ist selbst der Verdachtsfall -- nicht
            // stillschweigend uebersprungen, sondern ein eigener Fehlschlag.
            if (count($paar['schluessel']) !== 1 || !$this->istStringLiteral($paar['schluessel'][0])) {
                $this->fail(
                    'zusammengesetzter oder unbekannter Array-Schluessel im Quelltext gefunden ('
                    . $this->tokentextZusammen($paar['schluessel'])
                    . ') -- im Seeder gibt es dafuer keinen legitimen Grund, das koennte eine versteckte Telefonnummer sein',
                );
            }

            if ($this->stringLiteralWert($paar['schluessel'][0]) !== 'phone') {
                continue; // ein anderer Schluessel, hier nicht von Interesse
            }

            $phoneGefunden = true;

            // C1: der GANZE Ausdruck nach '=>' zaehlt, nicht nur das erste
            // Token -- 'null ?? <Nummer>' beginnt mit 'null', ist zur
            // Laufzeit aber die Nummer.
            $wertText = $this->tokentextZusammen($paar['wert']);

            $this->assertSame(
                'null',
                $wertText,
                "im Quelltext wird 'phone' auf {$wertText} gesetzt statt auf null -- das waere eine erfundene Telefonnummer",
            );
        }

        $this->assertTrue($phoneGefunden, 'keine phone-Zuweisung im Quelltext gefunden -- Tokenizer kaputt?');
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
     * Liest jedes Schluessel-Wert-Paar ('=>') im Quelltext aus -- ueber
     * einen echten Klammer-Stapel (ein Frame je offener Klammer, mit
     * eigenem Segment-Anfang und eigener '=>'-Position), nicht ueber
     * Zeichen-Ausschluesse. Ein Frame ohne '=>' (z.B. ein normaler
     * Funktionsaufruf oder ein Listen-Eintrag ohne Schluessel) liefert
     * kein Paar -- nur echte 'schluessel => wert'-Konstrukte zaehlen,
     * verschachtelt oder nicht, ein- oder mehrzeilig.
     *
     * @return list<array{schluessel: list<array{0:int,1:string,2:int}|string>, wert: list<array{0:int,1:string,2:int}|string>}>
     */
    private function schluesselWertPaare(): array
    {
        $tokens = $this->bedeutsameTokens();
        $paare = [];
        $stapel = []; // je offener Klammer: ['start' => int, 'pfeil' => int|null]

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '[' || $text === '(' || $text === '{') {
                $stapel[] = ['start' => $i + 1, 'pfeil' => null];
                continue;
            }

            if ($text === ']' || $text === ')' || $text === '}') {
                $frame = array_pop($stapel);
                if ($frame !== null && $frame['pfeil'] !== null) {
                    $paare[] = [
                        'schluessel' => array_slice($tokens, $frame['start'], $frame['pfeil'] - $frame['start']),
                        'wert'       => array_slice($tokens, $frame['pfeil'] + 1, $i - $frame['pfeil'] - 1),
                    ];
                }
                continue;
            }

            if ($stapel === []) {
                continue; // ausserhalb jeder Klammer, fuer Schluessel-Wert-Paare nicht von Interesse
            }

            $oben = count($stapel) - 1;

            if ($text === ',') {
                if ($stapel[$oben]['pfeil'] !== null) {
                    $paare[] = [
                        'schluessel' => array_slice($tokens, $stapel[$oben]['start'], $stapel[$oben]['pfeil'] - $stapel[$oben]['start']),
                        'wert'       => array_slice($tokens, $stapel[$oben]['pfeil'] + 1, $i - $stapel[$oben]['pfeil'] - 1),
                    ];
                }
                $stapel[$oben]['start'] = $i + 1;
                $stapel[$oben]['pfeil'] = null;
                continue;
            }

            if ($stapel[$oben]['pfeil'] === null && $this->tokenIst($token, T_DOUBLE_ARROW)) {
                $stapel[$oben]['pfeil'] = $i;
            }
        }

        return $paare;
    }

    /** @param array{0:int,1:string,2:int}|string|null $token */
    private function istStringLiteral($token): bool
    {
        return is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING;
    }

    /** Anfuehrungszeichen mit abschneiden -- 'phone' und "phone" sind fuer PHP bedeutungsgleich. */
    private function stringLiteralWert(array $token): string
    {
        return substr($token[1], 1, -1);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function tokentextZusammen(array $tokens): string
    {
        return implode(' ', array_map(fn ($t) => is_array($t) ? $t[1] : $t, $tokens));
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
