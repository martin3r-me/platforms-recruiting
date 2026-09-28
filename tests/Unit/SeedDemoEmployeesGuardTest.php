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
    // -----------------------------------------------------------------

    public function test_im_quelltext_wird_phone_nirgends_auf_etwas_anderes_als_null_gesetzt(): void
    {
        $quelltext = $this->quelltext();

        preg_match_all('/\'phone\'\s*=>\s*([^,)\]]+)/', $quelltext, $treffer);

        $this->assertNotEmpty($treffer[1], 'keine phone-Zuweisung im Quelltext gefunden -- Regex kaputt?');

        foreach ($treffer[1] as $wert) {
            $this->assertSame(
                'null',
                trim($wert),
                "im Quelltext wird 'phone' auf {$wert} gesetzt statt auf null -- das waere eine erfundene Telefonnummer",
            );
        }
    }

    public function test_person_linker_wird_im_quelltext_nie_mit_einer_nummer_aufgerufen(): void
    {
        $quelltext = $this->quelltext();

        // [^()\n]+ verlangt mindestens EIN Zeichen zwischen echten Klammern
        // auf DERSELBEN Zeile -- die Klassenkommentar-Erwaehnung
        // "PersonLinker::verbinde()" (leere Klammern) kann dadurch gar
        // nicht matchen, und der Match kann nicht ueber Klammern oder
        // Zeilenenden hinaus in spaeteren Quelltext auslaufen (das war der
        // erste Versuch dieses Tests: ohne diese beiden Ausschluesse fraess
        // sich das Muster quer durch die halbe Datei bis zur naechsten
        // schliessenden Klammer).
        preg_match_all('/PersonLinker::verbinde\(([^()\n]+)\)/', $quelltext, $treffer);

        $this->assertNotEmpty(
            $treffer[1],
            'kein PersonLinker::verbinde()-Aufruf im Quelltext gefunden -- Regex kaputt?',
        );

        foreach ($treffer[1] as $argumentListe) {
            $argumente = array_map('trim', explode(',', $argumentListe));
            $drittesArgument = $argumente[2] ?? null;

            $this->assertSame(
                'null',
                $drittesArgument,
                "PersonLinker::verbinde() wird mit einem dritten Argument aufgerufen, das nicht null ist ({$argumentListe}) -- das waere eine erfundene Telefonnummer",
            );
        }
    }

    private function quelltext(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/SeedDemoEmployees.php');
    }
}
