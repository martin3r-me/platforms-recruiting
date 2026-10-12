<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/dump-mitarbeiter-pseudonym.php — der Pseudonym-Dump fuer die
 * Generalprobe auf der Demo. Das Skript wird wie im Betrieb als eigener
 * Prozess gestartet (PHP_BINARY), gegen den Mini-Bestand in
 * tests/Fixtures/dump/: drei MA (zwei mit derselben Nummer RG353, einer mit
 * der langen ZAS-Form MA1000000878), drei Einsaetze (RG353, gekuerztes
 * MA878, Platzhalter RG14), ein Vertrag mit Inhalt, ein Bewerber mit
 * E-Mail-Spalte, ein HR-Fall mit Notiz, zwei Zusatzfelder + ein fremdes.
 * rec_employee_hr_data.csv fehlt dort absichtlich.
 *
 * Geprueft wird, was das Skript verspricht: nichts Echtes in der SQL,
 * Abgleich trifft wie auf prod, fail closed bei Unbekanntem.
 */
final class DumpPseudonymTest extends TestCase
{
    private const SKRIPT  = __DIR__ . '/../../tools/dump-mitarbeiter-pseudonym.php';
    private const FIXTURE = __DIR__ . '/../Fixtures/dump';

    /** @var list<string> */
    private array $tempOrdner = [];

    protected function tearDown(): void
    {
        foreach ($this->tempOrdner as $ordner) {
            array_map('unlink', glob($ordner . '/*') ?: []);
            @rmdir($ordner);
        }
    }

    /** @return array{0:int, 1:string, 2:string} exit, stdout, stderr */
    private function lauf(string ...$argumente): array
    {
        $befehl = array_merge([PHP_BINARY, self::SKRIPT], $argumente);
        $prozess = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        $aus = stream_get_contents($rohre[1]);
        $fehler = stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);

        return [proc_close($prozess), (string) $aus, (string) $fehler];
    }

    /** Kopie des Fixtures, in der einzelne Dateien veraendert werden duerfen. */
    private function kopie(): string
    {
        $ziel = sys_get_temp_dir() . '/dump-test-' . bin2hex(random_bytes(4));
        mkdir($ziel);
        foreach (glob(self::FIXTURE . '/*.csv') as $datei) {
            copy($datei, $ziel . '/' . basename($datei));
        }
        $this->tempOrdner[] = $ziel;

        return $ziel;
    }

    public function test_ordner_weg_laeuft_und_laesst_nichts_echtes_durch(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . self::FIXTURE, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        foreach ([
            // Namen (MA), auch der Geburtsname
            'Zebulon', 'Quackenbusch', 'Philippa', 'Wurzelbacher', 'Stinnesbeck',
            // E-Mail, Telefon, IBAN, Adresse
            'privat-mail', '2345678', '9876543', 'DE8937', 'DE0212', 'Hirschgasse',
            // Freitexte: unbekannte MA-Spalte, HR-Notiz, Vertragsnotiz, Bewertung,
            // Bewerber-Notiz, Einsatz-Notiz, Event-Ansprechpartner/-Anfahrt/-Meta
            'Fluxkompensator', 'Gurkensalat', 'Telefonat mit Mutter', 'Sehr hoeflich',
            'Kennt Herrn', 'kommt spaeter', 'Wiedehopf', '5554443', 'Schuhe',
            // Vertragsinhalt + Unterschriftsbild
            'Arbeitsvertrag zwischen Zebulon', 'UNTERSCHRIFTBILD', 'base64',
            // Tokens und echte uuids (Signatur- und Portal-Links)
            'tok-echt', 'pub-tok-echt', '-echt', '9b1d2c3e',
        ] as $echt) {
            $this->assertStringNotContainsString($echt, $sql, "'{$echt}' steht in der SQL");
        }

        // Vorlagentext ist kein Personenbezug und bleibt.
        $this->assertStringContainsString('Arbeitsvertrag zwischen {{first_name}} {{last_name}}', $sql);
        // Eventdaten bleiben.
        $this->assertStringContainsString("'Messe Sommerfest'", $sql);
        // Bewerber: auto_pilot ist auf prod 1 und wird 0.
        $this->assertMatchesRegularExpression("/\\('501', '00000000-0000-4000-8000-000000000501', '6', NULL, '60', NULL, '2026-01-10', '1', '0',/", $sql);
        // Zielteam an jeder team_id-Tabelle.
        $this->assertStringContainsString("'6', 'non_eu_citizen', 'open', NULL", $sql);
        $this->assertStringContainsString("'Alina', 'Adler'", $sql);
    }

    public function test_personalnummer_behaelt_praefix_und_trifft_wie_auf_prod(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . self::FIXTURE, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        // Zwei MA mit RG353 bekommen DIESELBE Ersatznummer, Praefix bleibt.
        $this->assertSame(3, substr_count($sql, "'RG9000000'"), 'beide RG353 → RG9000000, dazu der Einsatz');
        // Lange ZAS-Form: 1000000000 + Ersatz der kurzen Form …
        $this->assertStringContainsString("'MA1009000001'", $sql);
        // … und der gekuerzte Einsatz MA878 trifft ihn weiterhin.
        $this->assertStringContainsString("'880012', '7', 'MA9000001', '2'", $sql);
        $this->assertStringContainsString("'880011', '7', 'RG9000000', '3'", $sql);
        // Platzhalter bleibt.
        $this->assertStringContainsString("'880013', '7', 'RG14', NULL", $sql);
        foreach (["'RG353'", "'MA878'", 'MA1000000878'] as $echt) {
            $this->assertStringNotContainsString($echt, $sql);
        }
        $this->assertStringContainsString('Einsaetze mit MA vorher/nachher: 2/2, Abgleich-Treffer vorher/nachher: 2/2', $meldung);
    }

    public function test_unbekannte_einsatznummer_wird_dump_u(): void
    {
        $ordner = $this->kopie();
        file_put_contents($ordner . '/rec_dispo_assignments.csv',
            "14,as-uuid-echt-4,880014,7,RG777,,2026-10-22,08:00,16:00,0,Service,,,,\n", FILE_APPEND);

        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . $ordner, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        $this->assertStringContainsString("'880014', '7', 'DUMP-U1', NULL", $sql);
        $this->assertStringNotContainsString('RG777', $sql);
    }

    public function test_zusatzfelder_nur_die_vier_und_delete_nur_fuer_vertraege(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . self::FIXTURE, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        $this->assertStringNotContainsString('lieblingsfarbe', $sql);
        $this->assertStringNotContainsString('Mauve', $sql);
        $this->assertStringContainsString("d.name = 'vertragsbeginn' ORDER BY d.id LIMIT 1), 'rec_contract', 31, '2026-02-01'", $sql);
        $this->assertStringContainsString("d.name = 'zuschlag' ORDER BY d.id LIMIT 1), 'rec_contract', 31, '0,60'", $sql);
        $this->assertStringContainsString('1 fremdes Feld', $meldung);

        preg_match_all('/^DELETE .*$/m', $sql, $m);
        $this->assertSame(
            ["DELETE FROM core_extra_field_values WHERE fieldable_type IN ('rec_contract', 'Platform\\\\Recruiting\\\\Models\\\\RecContract');"],
            $m[0]
        );
        // Reihenfolge: Zusatzfelder nach den Tabellen, vor FK=1.
        $this->assertLessThan(strpos($sql, 'DELETE FROM'), strpos($sql, 'TRUNCATE TABLE rec_hr_desk_cases'));
        $this->assertLessThan(strpos($sql, 'SET FOREIGN_KEY_CHECKS = 1'), strpos($sql, 'INSERT INTO core_extra_field_values'));
    }

    public function test_fehlende_optionale_datei_wird_uebersprungen_ohne_truncate(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . self::FIXTURE, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        $this->assertStringNotContainsString('rec_employee_hr_data', $sql);
        $this->assertStringContainsString('rec_employee_hr_data.csv fehlt', $meldung);
        $this->assertStringContainsString('TRUNCATE TABLE rec_dispo_assignments;', $sql);
    }

    public function test_unbekannte_spalte_wird_geleert_und_gemeldet(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . self::FIXTURE, '--team=6');

        $this->assertSame(0, $exit, $meldung);
        $this->assertStringNotContainsString('geheim_notiz', $sql);
        $this->assertMatchesRegularExpression('/UNBEKANNTE SPALTEN.*geheim_notiz/', $meldung);
        // Die E-Mail-Spalte am Bewerber gibt es im Schema nicht → unbekannt.
        $this->assertMatchesRegularExpression('/rec_applicants .*\n.*UNBEKANNTE SPALTEN.*email/', $meldung);
    }

    public function test_echter_name_in_freigegebener_spalte_bricht_ab_ohne_sql(): void
    {
        $ordner = $this->kopie();
        $csv = file_get_contents($ordner . '/rec_employees.csv');
        // portal_locked_reason ist freigegeben — wenn HR dort einen Namen
        // eingetippt hat, muss die Selbstpruefung ihn finden.
        $csv = str_replace("+49 160 9876543,Lindenallee 3,DE02120300000000202051,MA1000000878,MA,1,,,",
            "+49 160 9876543,Lindenallee 3,DE02120300000000202051,MA1000000878,MA,1,,Gesperrt wegen Quackenbusch,", $csv);
        file_put_contents($ordner . '/rec_employees.csv', $csv);

        [$exit, $sql, $meldung] = $this->lauf('--ordner=' . $ordner, '--team=6');

        $this->assertSame(1, $exit);
        $this->assertSame('', $sql, 'bei Fehler darf keine SQL entstehen');
        $this->assertStringContainsString('Echter Name in rec_employees.portal_locked_reason (id 2)', $meldung);
        $this->assertStringNotContainsString('Quackenbusch', $meldung, 'die Meldung nennt den Namen nicht');
    }

    public function test_alter_csv_weg_bleibt(): void
    {
        [$exit, $sql, $meldung] = $this->lauf('--csv=' . self::FIXTURE . '/rec_employees.csv', '--team=6');

        $this->assertSame(0, $exit, $meldung);
        $this->assertStringContainsString('TRUNCATE TABLE rec_employees;', $sql);
        $this->assertSame(1, substr_count($sql, 'TRUNCATE'));
        $this->assertStringNotContainsString('core_extra_field_values', $sql);
        // Ohne Bewerber-Datei zeigt rec_applicant_id ins Leere → NULL.
        $this->assertStringContainsString("('1', '00000000-0000-4000-8000-000000000001', '6', NULL,", $sql);
    }

    public function test_ohne_team_oder_ohne_quelle_abbruch(): void
    {
        [$exit, $sql] = $this->lauf('--ordner=' . self::FIXTURE);
        $this->assertSame(1, $exit);
        $this->assertSame('', $sql);

        [$exit, $sql] = $this->lauf('--team=6');
        $this->assertSame(1, $exit);
        $this->assertSame('', $sql);
    }
}
