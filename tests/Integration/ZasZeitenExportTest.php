<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\ZasZeitenExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;

/** Log-Attrappe: der Abruf schreibt eine Logzeile je Uebergabe. */
class ZeitenExportLog
{
    /** @var list<array{0:string,1:array}> */
    public static array $zeilen = [];
    public function info(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
    public function warning(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
    public function error(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
}

/**
 * Zeiten an ZAS (Olaf 19./20.11.2026): Format byteweise wie Olafs Beispiel
 * `Zeiterfassung.txt` (UTF-8 ohne BOM, CRLF, Kopf `…Wzurueck|;` + neue Spalten),
 * Auswahl nur erledigter Eintraege, jeder genau einmal, „erfasstvon“.
 */
class ZasZeitenExportTest extends ZeitTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('log');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_employees')->delete();
        ZeitenExportLog::$zeilen = [];
        Container::getInstance()->instance('log', new ZeitenExportLog());
        Facade::clearResolvedInstance('log');
    }

    private function ma(int $id, ?string $pnr, string $vorname = 'Vor', string $nachname = 'Name', bool $test = false): int
    {
        Capsule::table('rec_employees')->insert([
            'id' => $id, 'uuid' => 'u-' . $id, 'team_id' => self::TEAM, 'first_name' => $vorname, 'last_name' => $nachname,
            'is_active' => true, 'personnel_number' => $pnr, 'is_test' => $test, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Zeiten in UTC (Host); November = MEZ, also Berlin = UTC + 1. */
    private function eintrag(RecDispoAssignment $a, array $felder = [], ?array $buchung = null): int
    {
        $id = (int) Capsule::table('rec_zeit_eintraege')->insertGetId(array_merge([
            'uuid' => uniqid('z', true), 'team_id' => self::TEAM,
            'person_typ' => 'rec_employee', 'person_id' => $a->rec_employee_id,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $a->id,
            'tag' => '2026-11-13',
            'kommen_at' => '2026-11-13 16:30:00', 'gehen_at' => '2026-11-13 23:00:00',
            'pause_minuten' => null, 'bemerkung' => null,
            'gesperrt_at' => '2026-11-14 09:00:00', 'gesperrt_grund' => 'erledigt',
            'created_at' => now(), 'updated_at' => now(),
        ], $felder));
        $buchung ??= ['akteur_typ' => 'rec_employee', 'akteur_id' => $a->rec_employee_id, 'akteur_name' => 'Selbst'];
        foreach (['kommen_at', 'gehen_at'] as $feld) {
            Capsule::table('rec_zeit_verlauf')->insert($buchung + [
                'rec_zeit_eintrag_id' => $id, 'feld' => $feld, 'alt' => null, 'neu' => 'x', 'art' => 'selbst', 'created_at' => now(),
            ]);
        }

        return $id;
    }

    private function va(string $ref = 'RG20523'): RecDispoEvent
    {
        return $this->event(['einsatz_ref' => $ref]);
    }

    private function export(): ZasZeitenExport
    {
        return new ZasZeitenExport();
    }

    public function test_datei_byteweise_wie_olafs_beispiel(): void
    {
        $va = $this->va();
        $a = $this->assignment($va, ['ds_ref' => '936181', 'rec_employee_id' => $this->ma(217, 'RG217'), 'datum' => '2026-11-13']);
        $this->eintrag($a, ['bemerkung' => "kam; spaeter\nwegen\u{2028}Bahn Wagen 3|"]);

        $x = $this->export();
        $csv = $x->csv($x->ermitteln(), new ZasCsvBuilder());

        $kopf = "PNr;VID;EDatum;VDatum;Start;Ende;Pause;Stunden;Fahrzeit;ZASID;erfasstvon;Bemerkung;Wäsche;Wgross;abgesagt;verspaetet;Wzurueck;WAbzug;WKunde;Wfehlt|;\r\n";
        $zeile = "RG217;RG20523;14.11.2026;13.11.2026;17:30;00:00;0,5;6,00;;936181;RG217;kam, spaeter wegen Bahn Wagen 3;;;;;;;;;|;\r\n";
        $this->assertSame($kopf . $zeile, $csv);
        $this->assertStringStartsNotWith(ZasCsvBuilder::BOM, $csv, 'wie das alte Portal: ohne BOM');
    }

    public function test_pause_und_stunden_als_dezimalstunden(): void
    {
        $this->assertSame('0,5', ZasZeitenExport::dezimal(30, false));
        $this->assertSame('0,75', ZasZeitenExport::dezimal(45, false));
        $this->assertSame('1', ZasZeitenExport::dezimal(60, false));
        $this->assertSame('4,75', ZasZeitenExport::dezimal(285, true));
        $this->assertSame('6,00', ZasZeitenExport::dezimal(360, true));

        $va = $this->va();
        $kurz = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $gesetzt = $this->assignment($va, ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $this->eintrag($kurz, ['kommen_at' => '2026-11-13 16:30:00', 'gehen_at' => '2026-11-13 21:15:00']); // 4:45 ohne Pause
        $this->eintrag($gesetzt, ['pause_minuten' => 45]); // 6:30 anwesend, Pause von Hand 45

        $zeilen = collect($this->export()->ermitteln())->keyBy('PNr');
        $this->assertSame('', $zeilen['RG1']['Pause'], 'Pause leer bei 0, wie in Olafs Beispiel');
        $this->assertSame('4,75', $zeilen['RG1']['Stunden']);
        $this->assertSame('0,75', $zeilen['RG2']['Pause'], 'gesetzte Pause gewinnt');
        $this->assertSame('5,75', $zeilen['RG2']['Stunden']);
    }

    public function test_nur_erledigte_ausgecheckte_nicht_uebergebene_eintraege(): void
    {
        $va = $this->va();
        $gut = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $offen = $this->assignment($va, ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $andererGrund = $this->assignment($va, ['rec_employee_id' => $this->ma(3, 'RG3')]);
        $ohneGehen = $this->assignment($va, ['rec_employee_id' => $this->ma(4, 'RG4')]);
        $schonDraussen = $this->assignment($va, ['rec_employee_id' => $this->ma(5, 'RG5')]);
        $testperson = $this->assignment($va, ['rec_employee_id' => $this->ma(6, 'RG6', test: true)]);
        $ohnePraefix = $this->assignment($va, ['rec_employee_id' => $this->ma(7, '1234')]);
        $ohneDs = $this->assignment($va, ['rec_employee_id' => $this->ma(8, 'RG8'), 'ds_ref' => '']);
        $umgehaengt = $this->assignment($va, ['rec_employee_id' => $this->ma(9, 'RG9')]);
        $ohneVid = $this->assignment($this->va(''), ['rec_employee_id' => $this->ma(10, 'RG10')]);

        $this->eintrag($gut);
        $this->eintrag($offen, ['gesperrt_at' => null, 'gesperrt_grund' => null]);
        $this->eintrag($andererGrund, ['gesperrt_grund' => 'anderes']);
        $this->eintrag($ohneGehen, ['gehen_at' => null]);
        $this->eintrag($schonDraussen, ['zas_exported_at' => '2026-11-14 10:00:00']);
        $this->eintrag($testperson);
        $this->eintrag($ohnePraefix);
        $this->eintrag($ohneDs);
        $this->eintrag($umgehaengt, ['person_id' => $this->ma(11, 'RG11')]); // Zeit gehoert einer anderen Person als die Einbuchung
        $this->eintrag($ohneVid);

        $this->assertSame(['RG1'], array_column($this->export()->ermitteln(), 'PNr'));
    }

    public function test_einsatz_filter(): void
    {
        $a = $this->assignment($this->va('RG100'), ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $b = $this->assignment($this->va('RG200'), ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $this->eintrag($a);
        $this->eintrag($b);

        $this->assertSame(['RG2'], array_column($this->export()->ermitteln('RG200'), 'PNr'));
        $this->assertCount(2, $this->export()->ermitteln());
    }

    public function test_jeder_eintrag_genau_einmal_und_trockenlauf_markiert_nichts(): void
    {
        $a = $this->assignment($this->va(), ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $id = $this->eintrag($a);
        $x = $this->export();

        $x->ermitteln(); // Trockenlauf = nur ermitteln
        $this->assertNull(Capsule::table('rec_zeit_eintraege')->where('id', $id)->value('zas_exported_at'));

        $erst = $x->beanspruchen($x->ermitteln());
        $this->assertCount(1, $erst);
        $this->assertNotNull(Capsule::table('rec_zeit_eintraege')->where('id', $id)->value('zas_exported_at'));
        $this->assertSame([], $x->ermitteln(), 'zweiter Abruf liefert nichts mehr');

        // Zwei Abrufe mit demselben Stand: nur einer bekommt die Zeile
        $b = $this->assignment($this->va('RG9'), ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $this->eintrag($b);
        $stand = $x->ermitteln();
        $this->assertCount(1, $x->beanspruchen($stand));
        $this->assertCount(0, $x->beanspruchen($stand));
    }

    public function test_inzwischen_wieder_geoeffnet_wird_nicht_geliefert(): void
    {
        $a = $this->assignment($this->va(), ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $id = $this->eintrag($a);
        $x = $this->export();
        $stand = $x->ermitteln();

        Capsule::table('rec_zeit_eintraege')->where('id', $id)->update(['gesperrt_at' => null, 'gesperrt_grund' => null]);

        $this->assertSame([], $x->beanspruchen($stand));
        $this->assertNull(Capsule::table('rec_zeit_eintraege')->where('id', $id)->value('zas_exported_at'));
    }

    public function test_inzwischen_geoeffnet_korrigiert_und_neu_erledigt_liefert_nicht_die_alten_werte(): void
    {
        $a = $this->assignment($this->va(), ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $id = $this->eintrag($a);
        $x = $this->export();
        $stand = $x->ermitteln();

        Capsule::table('rec_zeit_eintraege')->where('id', $id)->update(['gehen_at' => '2026-11-13 22:00:00', 'gesperrt_at' => '2026-11-14 11:00:00']);

        $this->assertSame([], $x->beanspruchen($stand), 'alter Stand wird nicht geliefert');
        $neu = $x->beanspruchen($x->ermitteln());
        $this->assertSame('23:00', $neu[0]['Ende'], 'der naechste Abruf liefert die korrigierten Werte');
    }

    public function test_edatum_ist_der_ortstag_der_sperre(): void
    {
        $a = $this->assignment($this->va(), ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $this->eintrag($a, ['gesperrt_at' => '2026-11-14 23:30:00']); // 00:30 am 15.11. in Berlin

        $this->assertSame('15.11.2026', $this->export()->ermitteln()[0]['EDatum']);
    }

    public function test_erfasstvon(): void
    {
        $va = $this->va();
        $tl = $this->assignment($va, ['rec_employee_id' => $this->ma(50, 'RG50', 'Anna', 'Leiter'), 'taetigkeit' => 'Teamleiter']); // „teamleit…“
        $selbst = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $perTl = $this->assignment($va, ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $perTlUmgekehrt = $this->assignment($va, ['rec_employee_id' => $this->ma(3, 'RG3')]);
        $buero = $this->assignment($va, ['rec_employee_id' => $this->ma(4, 'RG4')]);
        $unbekannt = $this->assignment($va, ['rec_employee_id' => $this->ma(5, 'RG5')]);

        $this->eintrag($tl);
        $this->eintrag($selbst);
        $this->eintrag($perTl, [], ['akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'Anna Leiter (Eventzugang)']);
        $this->eintrag($perTlUmgekehrt, [], ['akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'leiter,  anna (Eventzugang)']);
        $this->eintrag($buero, [], ['akteur_typ' => 'user', 'akteur_id' => 8, 'akteur_name' => 'Clara Buero']);
        $this->eintrag($unbekannt, [], ['akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'Fremder Name (Eventzugang)']);

        $z = collect($this->export()->ermitteln())->keyBy('PNr');
        $this->assertSame('RG1', $z['RG1']['erfasstvon'], 'selbst gestempelt');
        $this->assertSame('RG50', $z['RG2']['erfasstvon'], 'Teamleiter am Eventzugang');
        $this->assertSame('RG50', $z['RG3']['erfasstvon'], 'Name in anderer Reihenfolge');
        $this->assertSame('', $z['RG4']['erfasstvon'], 'Buero: leer (Olaf: darf leer sein)');
        $this->assertSame('', $z['RG5']['erfasstvon'], 'Name passt zu niemandem an der VA');
    }

    public function test_waesche_spalten_abzug_und_fehlende_teile(): void
    {
        $va = $this->va();
        $mit = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $ohne = $this->assignment($va, ['rec_employee_id' => $this->ma(2, 'RG2')]);
        $this->eintrag($mit);
        $this->eintrag($ohne);
        foreach ([['hemd', 'Hemd', 2500, 400, false, 1, 1], ['weste', 'Weste', 3000, 0, true, 1, 0], ['sonstiges', 'Hose | lang', 2000, 0, false, 1, 0]] as [$k, $n, $p, $r, $l, $anz, $zur]) {
            Capsule::table('rec_zeit_waesche')->insert([
                'team_id' => self::TEAM, 'rec_dispo_assignment_id' => $mit->id, 'rec_employee_id' => $mit->rec_employee_id,
                'artikel_key' => $k, 'bezeichnung' => $n, 'preis_cent' => $p, 'reinigung_lohn_cent' => $r, 'leihe_kunde' => $l,
                'anzahl' => $anz, 'zurueck' => $zur, 'ausgegeben_von' => 'TL', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $x = $this->export();
        $zeilen = collect($x->ermitteln())->keyBy('PNr');
        $this->assertSame('54,00', $zeilen['RG1']['WAbzug'], 'Hemd zurück 4 + Weste weg 30 + Hose weg 20');
        $this->assertSame('Weste|Hose lang', $zeilen['RG1']['Wfehlt']);
        $this->assertSame('', $zeilen['RG2']['WAbzug'], 'ohne Wäsche leer');
        $this->assertSame('', $zeilen['RG2']['Wfehlt']);

        $csv = $x->csv([$zeilen['RG1']], new ZasCsvBuilder());
        $this->assertStringEndsWith(';;;;;;54,00;;Weste|Hose lang;|;' . "\r\n", $csv, 'Wäsche/Wgross/abgesagt/verspaetet/Wzurueck/WKunde bleiben leer, bis Olaf antwortet');
    }

    public function test_erfasstvon_bei_zwei_gleichnamigen_bleibt_leer(): void
    {
        $va = $this->va();
        $this->assignment($va, ['rec_employee_id' => $this->ma(50, 'RG50', 'Anna', 'Leiter'), 'taetigkeit' => 'TL']);
        $this->assignment($va, ['rec_employee_id' => $this->ma(51, 'RG51', 'Anna', 'Leiter'), 'taetigkeit' => 'Teamleitung']);
        $a = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $this->eintrag($a, [], ['akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'Anna Leiter (Eventzugang)']);

        $this->assertSame('', $this->export()->ermitteln()[0]['erfasstvon']);
    }

    public function test_erfasstvon_nur_gegen_aktive_teamleiter(): void
    {
        $va = $this->va();
        $this->assignment($va, ['rec_employee_id' => $this->ma(50, 'RG50', 'Anna', 'Leiter'), 'taetigkeit' => 'TL']);
        $this->assignment($va, ['rec_employee_id' => $this->ma(51, 'RG51', 'Anna', 'Leiter'), 'taetigkeit' => 'TL', 'declined_at' => '2026-11-12 10:00:00']);
        $this->assignment($va, ['rec_employee_id' => $this->ma(52, 'RG52', 'Anna', 'Leiter'), 'taetigkeit' => 'Service']);
        $a = $this->assignment($va, ['rec_employee_id' => $this->ma(1, 'RG1')]);
        $this->eintrag($a, [], ['akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'Anna Leiter (Eventzugang)']);

        $this->assertSame('RG50', $this->export()->ermitteln()[0]['erfasstvon'], 'abgesagte Namensvetterin und Service-Kraft zählen nicht');
    }
}
