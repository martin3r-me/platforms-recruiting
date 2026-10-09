<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\OffenePunkte;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Spec Vertrag aus der Akte §3, Test 9; Review-Focus 1-3. Echte Migrationen
 * (VertragAusAkteHarness), Sender und Cache als Attrappe, ohne --welle —
 * es geht nichts raus, die Pruefung laeuft trotzdem vollstaendig.
 */
final class EinsatzPruefungVertragTest extends TestCase
{
    use VertragAusAkteHarness;

    private int $n = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        $c = Container::getInstance();
        $c->instance('cache', $this->cacheAttrappe());
        $c->instance(AufgabenSender::class, new class {
            public function sende(RecEmployee $e, array $stand, string $anlass): string { return 'sent'; }
            public function letzteNachrichtId(): ?int { return null; }
        });
        // Standard ist AUS (Schlussreview I2); diese Klasse prueft die Regeln
        // bei eingeschaltetem MA-Check.
        $this->einstellung(['MA']);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('cache');
        $c->forgetInstance(AufgabenSender::class);
        $this->weltAbbauen();
        parent::tearDown();
    }

    // ---- Grundregeln ---------------------------------------------------

    public function test_ma_buchung_ohne_vertrag_oeffnet_genau_einen_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711', ['taetigkeit' => 'Service'], 'Messe Düsseldorf');

        $erste = $this->laufe('2026-10-09');
        $this->laufe('2026-10-10');

        $faelle = $this->vertragsFaelle();
        $this->assertCount(1, $faelle, 'zweiter Lauf: kein zweiter Fall');
        $fall = $faelle->first();
        $this->assertSame($ma->id, (int) $fall->rec_employee_id);
        $this->assertNull($fall->rec_applicant_id);
        $this->assertSame($this->team, (int) $fall->team_id);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $fall->status);
        $this->assertSame('MA-Einsatz am 20.10.2026 (Messe Düsseldorf, Service) — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.', $fall->notes);
        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 1 ohne Vertrag, 1 Fälle neu, 0 Fälle geschlossen.', $erste);
        $this->assertNotContains(RecHrDeskCase::REASON_CONTRACT_MISSING, RecHrDeskCase::CONTRACT_BLOCKING_REASONS);
    }

    public function test_zwei_buchungen_ein_fall_mit_dem_fruehesten_tag(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-27', 'MA4711');
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $this->laufe('2026-10-09');

        $this->assertCount(1, $this->vertragsFaelle());
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', $this->vertragsFaelle()->first()->notes);
    }

    public function test_rg_buchung_bei_nur_ma_eingeschaltet_ohne_fall(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG14']);
        $this->einbuchung($rg, '2026-10-20', 'RG14');

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    public function test_einstellung_steuert_die_gesellschaften(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG14']);
        $this->einbuchung($rg, '2026-10-20', 'RG14');
        $ma = $this->maAnstellung(['personnel_number' => 'MA15']);
        $this->einbuchung($ma, '2026-10-20', 'MA15');

        $this->einstellung([]);
        $this->laufe('2026-10-09');
        $this->assertCount(0, $this->vertragsFaelle(), 'beide aus = Pruefung laeuft nicht');

        $this->einstellung(['RG']);
        $this->laufe('2026-10-10');
        $this->assertSame([$rg->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_versendeter_vertrag_ist_kein_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    public function test_unterschriebener_vertrag_schliesst_den_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');
        $this->assertCount(1, $this->vertragsFaelle());

        $v = $this->unterschrieben($ma, '2026-10-01', '2026-10-31');
        $ausgabe = $this->laufe('2026-10-10');

        $fall = RecHrDeskCase::query()->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)->first();
        $this->assertSame(RecHrDeskCase::STATUS_APPROVED, $fall->status);
        $this->assertNotNull($fall->resolved_at);
        $this->assertSame('Automatisch: Arbeitsvertrag unterschrieben (#' . $v->id . ')', $fall->resolution_notes);
        $this->assertStringContainsString('0 Fälle neu, 1 Fälle geschlossen.', $ausgabe);
    }

    /** Probe: Schliessen auch bei "unterwegs" → rot. §3.4: nur ALLE Buchungen unterschrieben gedeckt. */
    public function test_nur_teilweise_unterschrieben_laesst_den_fall_offen(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->einbuchung($ma, '2026-11-05', 'MA4711');   // im 30-Tage-Horizont
        $this->laufe('2026-10-09');
        $this->assertCount(1, $this->vertragsFaelle());

        $this->unterschrieben($ma, '2026-10-01', '2026-10-31');
        $this->vertragAn($ma, $this->vorlage('AV-MA-NOV'), [], ['vertragsbeginn' => '2026-11-01', 'vertragsende' => '2026-11-30']);
        $ausgabe = $this->laufe('2026-10-10');

        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $this->vertragsFaelle()->first()->status, 'November nur unterwegs');
        $this->assertStringContainsString('2 Buchungen geprüft, 0 ohne Vertrag, 0 Fälle neu, 0 Fälle geschlossen.', $ausgabe);
    }

    public function test_verschwundene_buchung_laesst_den_fall_offen(): void
    {
        $ma = $this->maAnstellung();
        $id = $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');

        DB::table('rec_dispo_assignments')->where('id', $id)->update(['missing_since' => '2026-10-09 12:00:00']);
        $this->unterschrieben($ma, '2026-11-01', '2026-11-30');
        $this->laufe('2026-10-10');

        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $this->vertragsFaelle()->first()->status, 'kein stiller Abbau');
    }

    public function test_trockenlauf_schreibt_nichts_meldet_aber(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $ausgabe = $this->laufe('2026-10-09', ['--dry-run' => true]);

        $this->assertCount(0, $this->vertragsFaelle());
        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 1 ohne Vertrag, 1 Fälle neu, 0 Fälle geschlossen.', $ausgabe);
    }

    /** Probe: den Check hinter den $punkte === []-Kurzschluss schieben → rot. */
    public function test_person_ohne_andere_offene_punkte_wird_trotzdem_geprueft(): void
    {
        $ma = $this->maAnstellung();
        $this->alleNachweiseErbringen($ma);
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $this->laufe('2026-10-09');

        $this->assertCount(1, $this->vertragsFaelle());
    }

    /** Probe: try/catch um den Vertragscheck entfernen → der Mensch faellt komplett aus, rot. */
    public function test_fehler_im_vertragscheck_kostet_nicht_den_rest_der_pruefung(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        DB::statement('ALTER TABLE rec_hr_desk_cases RENAME TO rec_hr_desk_cases_weg');

        try {
            $ausgabe = $this->laufe('2026-10-09');
        } finally {
            DB::statement('ALTER TABLE rec_hr_desk_cases_weg RENAME TO rec_hr_desk_cases');
        }

        $this->assertStringContainsString('MA #' . $ma->id . ': Vertragsprüfung abgebrochen', $ausgabe);
        $this->assertStringContainsString('1 Mensch(en) mit Abbruch', $ausgabe, 'der Fehler faerbt den Lauf weiter');
        // Der Rest lief: ohne Personen-Zeile wird dieser Mensch erst NACH dem Check gezaehlt.
        $this->assertStringContainsString('Uebersprungen, weil ohne Personen-Zeile: 1', $ausgabe);
    }

    /**
     * Review Task 8, Fund 1: RG+MA geprueft, nur eine RG-Zeile. Der RG-Befund
     * (gedeckt) darf den Fall des MA-Befunds (ungedeckt) nicht schliessen —
     * sonst schliesst und oeffnet jeder Lauf ihn neu.
     * Probe: Schliessen je Befund statt je Anstellung → rot.
     */
    public function test_gedeckte_andere_gesellschaft_schliesst_den_fall_nicht(): void
    {
        $this->einstellung(['RG', 'MA']);
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG77']);
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-15', 'RG77');
        $this->einbuchung($rg, '2026-10-20', 'MA77');

        $erste = $this->laufe('2026-10-09');
        $zweite = $this->laufe('2026-10-10');

        $this->assertStringContainsString('1 Fälle neu, 0 Fälle geschlossen.', $erste);
        $this->assertStringContainsString('2 Buchungen geprüft, 1 ohne Vertrag, 0 Fälle neu, 0 Fälle geschlossen.', $zweite);
        $faelle = $this->vertragsFaelle();
        $this->assertCount(1, $faelle);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $faelle->first()->status);
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', $faelle->first()->notes);
    }

    /** Fruehester ungedeckter Tag ueber alle Gesellschaften einer Anstellung, mit deren Firma in der Notiz. */
    public function test_ein_fall_je_anstellung_mit_fruehestem_tag_ueber_gesellschaften(): void
    {
        $this->einstellung(['RG', 'MA']);
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG78']);
        $this->einbuchung($rg, '2026-10-25', 'RG78');
        $this->einbuchung($rg, '2026-10-20', 'MA78');

        $ausgabe = $this->laufe('2026-10-09');

        $this->assertCount(1, $this->vertragsFaelle());
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', $this->vertragsFaelle()->first()->notes);
        $this->assertStringContainsString('2 Buchungen geprüft, 2 ohne Vertrag, 1 Fälle neu', $ausgabe);
    }

    // ---- Schlussreview I2 / T8-1 ------------------------------------------

    /** Probe: Standard wieder ['MA'] → ein Fall, rot. */
    public function test_ohne_einstellung_ist_die_pruefung_aus(): void
    {
        DB::table('rec_applicant_settings')->where('team_id', $this->team)->delete();
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $ausgabe = $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
        $this->assertStringContainsString('Vertragsprüfung: 0 Buchungen geprüft', $ausgabe);
    }

    /** Probe: Horizont entfernen → der Einsatz in 31 Tagen oeffnet einen Fall, rot. */
    public function test_nur_buchungen_der_naechsten_30_tage(): void
    {
        $fern = $this->maAnstellung(['personnel_number' => 'MA31']);
        $this->einbuchung($fern, '2026-11-09', 'MA31');   // heute + 31
        $nah = $this->maAnstellung(['personnel_number' => 'MA30']);
        $this->einbuchung($nah, '2026-11-08', 'MA30');    // heute + 30

        $ausgabe = $this->laufe('2026-10-09');

        $this->assertSame([$nah->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 1 ohne Vertrag, 1 Fälle neu', $ausgabe);
        $this->assertSame(30, \Platform\Recruiting\Services\VertragsPruefung::HORIZONT_TAGE);
    }

    /**
     * Von HR geschlossen (resolved_by_user_id) = bleibt zu, solange der
     * frueheste ungedeckte Einsatztag nicht NACH dem Tag des geschlossenen
     * Falls liegt. Probe: Pruefung auf HR-geschlossene Faelle entfernen → rot.
     */
    public function test_von_hr_geschlossener_fall_kommt_fuer_denselben_tag_nicht_wieder(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');
        $this->hrSchliesst($this->vertragsFaelle()->first());

        $ausgabe = $this->laufe('2026-10-10');
        $this->assertCount(1, $this->vertragsFaelle(), 'derselbe Tag: kein neuer Fall');
        $this->assertStringContainsString('1 ohne Vertrag, 0 Fälle neu', $ausgabe);

        // Ein spaeterer Einsatz, solange der 20.10. noch der frueheste ungedeckte ist: weiter zu.
        $this->einbuchung($ma, '2026-10-27', 'MA4711');
        $this->laufe('2026-10-11');
        $this->assertCount(1, $this->vertragsFaelle(), 'frueheste Luecke unveraendert');

        // Ein frueherer neuer Einsatz liegt nicht NACH dem geschlossenen Tag: weiter zu.
        $this->einbuchung($ma, '2026-10-15', 'MA4711');
        $this->laufe('2026-10-12');
        $this->assertCount(1, $this->vertragsFaelle());
    }

    public function test_von_hr_geschlossener_fall_oeffnet_neu_fuer_einen_spaeteren_tag(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->einbuchung($ma, '2026-10-27', 'MA4711');
        $this->laufe('2026-10-09');
        $this->hrSchliesst($this->vertragsFaelle()->first());

        // Der 20.10. ist vorbei; der 27.10. ist der neue frueheste ungedeckte Tag.
        $this->laufe('2026-10-21');

        $faelle = $this->vertragsFaelle();
        $this->assertCount(2, $faelle);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $faelle->last()->status);
        $this->assertStringStartsWith('MA-Einsatz am 27.10.2026', $faelle->last()->notes);
    }

    public function test_automatisch_geschlossener_fall_sperrt_nicht(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');
        $fall = $this->vertragsFaelle()->first();
        $fall->update(['status' => RecHrDeskCase::STATUS_APPROVED, 'resolved_at' => now(), 'resolution_notes' => 'Automatisch: x']);

        $this->laufe('2026-10-10');

        $this->assertCount(2, $this->vertragsFaelle(), 'ohne resolved_by_user_id kein HR-Abschluss');
    }

    /**
     * Ledger T8-1: Fall an der gebuchten RG-Zeile; dann entsteht die
     * MA-Zeile derselben Person. Der alte Fall schliesst sich (verschoben),
     * der neue haengt an der MA-Zeile. Probe: Verschieben entfernen → zwei
     * offene Faelle, rot.
     */
    public function test_fall_wandert_mit_wenn_die_gepruefte_anstellung_wechselt(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG353']);
        $this->einbuchung($rg, '2026-10-20', 'MA353');
        $this->laufe('2026-10-09');
        $alt = $this->vertragsFaelle()->first();
        $this->assertSame($rg->id, (int) $alt->rec_employee_id, 'Vorflug: ohne MA-Zeile an der gebuchten Zeile');

        $ma = $this->maAnstellung(['personnel_number' => 'MA353']);
        $this->personVerbinden($rg, $ma);
        $ausgabe = $this->laufe('2026-10-10');

        $alt = $alt->fresh();
        $this->assertSame(RecHrDeskCase::STATUS_APPROVED, $alt->status);
        $this->assertNotNull($alt->resolved_at);
        $this->assertNull($alt->resolved_by_user_id);
        $this->assertSame('Automatisch: verschoben auf Akte #' . $ma->id, $alt->resolution_notes);
        $offen = $this->vertragsFaelle()->where('status', RecHrDeskCase::STATUS_OPEN);
        $this->assertSame([$ma->id], $offen->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->values()->all());
        $this->assertStringContainsString('1 Fälle neu, 1 Fälle geschlossen.', $ausgabe);
    }

    public function test_fall_einer_anderen_gesellschaft_wandert_nicht(): void
    {
        $this->einstellung(['RG', 'MA']);
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG354']);
        $ma = $this->maAnstellung(['personnel_number' => 'MA354']);
        $this->personVerbinden($rg, $ma);
        $this->einbuchung($rg, '2026-10-20', 'RG354');
        $this->einbuchung($ma, '2026-10-21', 'MA354');

        $this->laufe('2026-10-09');
        $this->laufe('2026-10-10');

        $this->assertCount(2, $this->vertragsFaelle()->where('status', RecHrDeskCase::STATUS_OPEN), 'RG-Fall bleibt an der RG-Zeile');
    }

    /** Probe: Waechter "Zeile wird selbst noch geprueft" entfernen → ein Fall schliesst und oeffnet je Lauf, rot. */
    public function test_zwei_gebuchte_zeilen_ohne_ma_akte_flattern_nicht(): void
    {
        $a = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG356']);
        $b = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG357']);
        $this->personVerbinden($a, $b);
        $this->einbuchung($a, '2026-10-20', 'MA356');
        $this->einbuchung($b, '2026-10-21', 'MA357');

        $this->laufe('2026-10-09');
        $zweite = $this->laufe('2026-10-10');

        $this->assertCount(2, $this->vertragsFaelle());
        $this->assertCount(2, $this->vertragsFaelle()->where('status', RecHrDeskCase::STATUS_OPEN));
        $this->assertStringContainsString('0 Fälle neu, 0 Fälle geschlossen.', $zweite);
    }

    /**
     * Schlussreview N3: RG+MA geprueft. Alter MA-Fall an der RG-Zeile (keine
     * MA-Zeile); die RG-Zeile hat selbst eine RG-Buchung. Dann entsteht die
     * MA-Zeile. Der MA-Fall wandert, obwohl die RG-Zeile noch (fuer RG)
     * geprueft wird — und an der RG-Zeile entsteht der RG-Fall.
     * Probe: Waechter wieder nur auf die Anstellung → zwei MA-Faelle, rot.
     */
    public function test_alter_ma_fall_an_der_rg_zeile_wandert_auch_wenn_rg_dort_geprueft_wird(): void
    {
        $this->einstellung(['RG', 'MA']);
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG358']);
        $this->einbuchung($rg, '2026-10-20', 'MA358');
        $this->einbuchung($rg, '2026-10-22', 'RG358');
        $this->laufe('2026-10-09');
        $alt = $this->vertragsFaelle()->first();
        $this->assertCount(1, $this->vertragsFaelle(), 'Vorflug: ein Fall je Anstellung');
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', (string) $alt->notes, 'Vorflug: fruehester Tag = MA');

        $ma = $this->maAnstellung(['personnel_number' => 'MA358']);
        $this->personVerbinden($rg, $ma);
        $this->laufe('2026-10-10');

        $alt = $alt->fresh();
        $this->assertSame(RecHrDeskCase::STATUS_APPROVED, $alt->status);
        $this->assertSame('Automatisch: verschoben auf Akte #' . $ma->id, $alt->resolution_notes);
        $offen = $this->vertragsFaelle()->where('status', RecHrDeskCase::STATUS_OPEN)->values();
        $this->assertSame([$rg->id, $ma->id], $offen->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertStringStartsWith('RG-Einsatz am 22.10.2026', (string) $offen->firstWhere('rec_employee_id', $rg->id)->notes);
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', (string) $offen->firstWhere('rec_employee_id', $ma->id)->notes);

        $dritte = $this->laufe('2026-10-11');
        $this->assertStringContainsString('0 Fälle neu, 0 Fälle geschlossen.', $dritte, 'kein Flattern');
    }

    /**
     * N3, zweite Haelfte: die RG-Buchung ist unterschrieben gedeckt. Der alte
     * MA-Fall an der RG-Zeile schliesst NICHT mit "Arbeitsvertrag
     * unterschrieben" (das war der RG-Vertrag), sondern wandert.
     */
    public function test_alter_ma_fall_schliesst_nicht_mit_dem_rg_vertrag(): void
    {
        $this->einstellung(['RG', 'MA']);
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG359']);
        $this->einbuchung($rg, '2026-10-20', 'MA359');
        $this->laufe('2026-10-09');
        $alt = $this->vertragsFaelle()->first();

        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-22', 'RG359');
        $ma = $this->maAnstellung(['personnel_number' => 'MA359']);
        $this->personVerbinden($rg, $ma);
        $this->laufe('2026-10-10');

        $this->assertSame('Automatisch: verschoben auf Akte #' . $ma->id, $alt->fresh()->resolution_notes);
        $this->assertSame([$ma->id], $this->vertragsFaelle()->where('status', RecHrDeskCase::STATUS_OPEN)->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->values()->all());
    }

    public function test_trockenlauf_verschiebt_nichts(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG355']);
        $this->einbuchung($rg, '2026-10-20', 'MA355');
        $this->laufe('2026-10-09');
        $ma = $this->maAnstellung(['personnel_number' => 'MA355']);
        $this->personVerbinden($rg, $ma);

        $ausgabe = $this->laufe('2026-10-10', ['--dry-run' => true]);

        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $this->vertragsFaelle()->first()->status);
        $this->assertStringContainsString('1 Fälle neu, 1 Fälle geschlossen.', $ausgabe);
    }

    // ---- Review-Focus ----------------------------------------------------

    /** Review-Focus 1. */
    public function test_altvertrag_ohne_laufzeit_erzeugt_keinen_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->vertragAn($ma, $this->vorlage('AV-MA-ALT'), ['status' => 'completed', 'signed_at' => '2024-05-02 12:00:00', 'completed_at' => '2024-05-02 12:00:00']);

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    /** Review-Focus 2: Firma aus dem Praefix der gekuerzten Nummer, Akte ohne Firma. */
    public function test_gekuerzte_ma_nummer_ohne_firma_an_der_akte(): void
    {
        $ma = $this->maAnstellung(['company' => null, 'personnel_number' => 'MA1000000878']);
        $this->einbuchung($ma, '2026-10-20', 'MA878');

        $this->laufe('2026-10-09');

        $this->assertSame([$ma->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all());
    }

    /** Probe: Firmenfilter in VertragsZeilen entfernen → der RG-AV deckt, kein Fall, rot. */
    public function test_rg_av_deckt_keinen_ma_einsatz(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG77']);
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-20', 'MA77');   // MA-Buchung, aber keine MA-Akte

        $this->laufe('2026-10-09');

        $this->assertSame([$rg->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all(),
            'Ohne MA-Akte haengt der Fall an der gebuchten Zeile — gedeckt hat der RG-Vertrag nicht');
    }

    /** Review-Focus 3. Probe: Ziel immer die gebuchte Zeile (`$anstellungen->first(...)` streichen) → Fall an RG, rot. */
    public function test_buchung_an_der_rg_zeile_prueft_die_ma_anstellung(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG353']);
        $ma = $this->maAnstellung(['personnel_number' => 'MA353']);
        $this->personVerbinden($rg, $ma);
        // Ein falsch angehaengter RG-AV an der RG-Zeile, Laufzeit deckt den Tag.
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-20', 'MA353');

        $this->laufe('2026-10-09');

        $this->assertSame([$ma->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all(),
            'Der Fall gehoert an die MA-Zeile, der RG-AV deckt keinen MA-Einsatz');
    }

    // ---- Firmenvergleich (Controller-Vorgabe: beide Seiten strtoupper(trim())) ----

    /** Probe: Normalisierung in giltFuerAnstellung zurueckdrehen → rot. */
    public function test_vorlage_gilt_fuer_anstellung_ohne_ruecksicht_auf_schreibweise(): void
    {
        $vorlage = $this->vorlage('AV-MA-GROSS', 'MA');
        $this->assertTrue($vorlage->giltFuerAnstellung($this->maAnstellung(['company' => ' ma'])));
        $this->assertTrue($this->vorlage('AV-MA-KLEIN', 'ma ')->giltFuerAnstellung($this->maAnstellung(['company' => 'MA'])));
        $this->assertFalse($vorlage->giltFuerAnstellung($this->maAnstellung(['company' => 'RG'])));
        $this->assertFalse($vorlage->giltFuerAnstellung($this->maAnstellung(['company' => ' '])), 'leer bleibt nie ein Treffer');
    }

    public function test_klein_geschriebene_firma_an_akte_und_vorlage_deckt(): void
    {
        $ma = $this->maAnstellung(['company' => 'ma', 'personnel_number' => '4711']);
        $this->vertragAn($ma, $this->vorlage('AV-MA-KLEIN', 'Ma '), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);
        $this->einbuchung($ma, '2026-10-20', '4711');   // ohne Praefix: Firma der Akte zaehlt

        $ausgabe = $this->laufe('2026-10-09');

        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 0 ohne Vertrag', $ausgabe);
        $this->assertCount(0, $this->vertragsFaelle());
    }

    // ---- Schreibtisch-Vorflug (Controller-Vorgabe) -------------------------

    public function test_knopf_nur_wenn_die_akte_zur_firma_der_notiz_passt(): void
    {
        $notiz = \Platform\Recruiting\Support\VertragsVorbelegung::notiz('2026-10-20', 'Messe', 'Service', 'MA');

        $this->assertSame('MA', \Platform\Recruiting\Support\VertragsVorbelegung::firmaAusNotiz($notiz));
        $this->assertTrue(\Platform\Recruiting\Support\VertragsVorbelegung::akteDecktNotiz($notiz, 'MA'));
        $this->assertTrue(\Platform\Recruiting\Support\VertragsVorbelegung::akteDecktNotiz($notiz, ' ma'));
        $this->assertFalse(\Platform\Recruiting\Support\VertragsVorbelegung::akteDecktNotiz($notiz, 'RG'));
        $this->assertFalse(\Platform\Recruiting\Support\VertragsVorbelegung::akteDecktNotiz($notiz, null));
        $this->assertFalse(\Platform\Recruiting\Support\VertragsVorbelegung::akteDecktNotiz('Freitext ohne Einsatz', 'MA'));
        $this->assertNull(\Platform\Recruiting\Support\VertragsVorbelegung::firmaAusNotiz(null));
    }

    // ---- Verdrahtung -----------------------------------------------------

    public function test_einstellung_und_schreibtisch_sind_verdrahtet(): void
    {
        $this->assertSame([], RecApplicantSettings::DEFAULT_SETTINGS['contract_check_companies'], 'Standard aus (Schlussreview I2)');
        $this->assertSame('Vertrag fehlt für Einsatz', RecHrDeskCase::REASON_LABELS[RecHrDeskCase::REASON_CONTRACT_MISSING]);

        $modal = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $this->assertSame(2, substr_count($modal, 'wire:model="settings.contract_check_companies"'));
        $this->assertStringContainsString('Vertragsprüfung bei Einsätzen', $modal);

        $desk = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/hr-desk/index.blade.php');
        $this->assertStringContainsString('REASON_CONTRACT_MISSING', $desk);
        $this->assertStringContainsString('VertragsVorbelegung::einsatztagAusNotiz', $desk);
        $this->assertStringContainsString('Vertrag erstellen', $desk);
        $this->assertStringContainsString('VertragsVorbelegung::akteDecktNotiz', $desk);
        $this->assertStringContainsString('Erst Firma der Akte', $desk);
        $this->assertStringContainsString('Einsatztag/Gesellschaft in der Notiz nicht lesbar', $desk);
        $this->assertStringNotContainsString("?? 'MA'", $desk, 'unlesbare Notiz faellt nicht still auf MA zurueck');
        $this->assertStringContainsString('dark:', $desk);
    }

    // ---- Fixtures --------------------------------------------------------

    private function maAnstellung(array $set = []): RecEmployee
    {
        return $this->anstellung(array_merge([
            'personnel_number' => 'MA4711', 'portal_v2_since' => '2026-09-24 00:00:00',
            'phone' => '+49151' . str_pad((string) $this->n++, 8, '0', STR_PAD_LEFT),
        ], $set));
    }

    private function unterschrieben(RecEmployee $a, string $beginn, string $ende): \Platform\Recruiting\Models\RecContract
    {
        return $this->vertragAn($a, $this->vorlage('AV-MA-' . $this->n++), ['status' => 'completed', 'signed_at' => '2026-10-09 08:00:00', 'completed_at' => '2026-10-09 08:00:00'],
            ['vertragsbeginn' => $beginn, 'vertragsende' => $ende]);
    }

    private function einbuchung(RecEmployee $a, string $datum, string $pnr, array $set = [], ?string $event = null): int
    {
        $eventId = (int) DB::table('rec_dispo_events')->insertGetId([
            'uuid' => 'ev-' . $this->n++, 'einsatz_ref' => 'EV-' . $this->n, 'name' => $event,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);

        return (int) DB::table('rec_dispo_assignments')->insertGetId(array_merge([
            'uuid' => 'as-' . $this->n++, 'ds_ref' => 'DS-' . $this->n, 'rec_dispo_event_id' => $eventId,
            'pnr_raw' => $pnr, 'rec_employee_id' => $a->id, 'datum' => $datum, 'status_id' => 1,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $set));
    }

    private function einstellung(array $firmen): void
    {
        DB::table('rec_applicant_settings')->where('team_id', $this->team)->delete();
        DB::table('rec_applicant_settings')->insert(['team_id' => $this->team, 'settings' => json_encode(['contract_check_companies' => $firmen])]);
    }

    private function hrSchliesst(RecHrDeskCase $fall): void
    {
        $userId = (int) DB::table('users')->insertGetId(['name' => 'HR', 'email' => 'hr' . $this->n++ . '@example.org', 'password' => 'x']);
        $fall->update([
            'status' => RecHrDeskCase::STATUS_APPROVED, 'resolved_at' => now(),
            'resolved_by_user_id' => $userId, 'resolution_notes' => 'Papiervertrag liegt vor',
        ]);
    }

    private function vertragsFaelle()
    {
        return RecHrDeskCase::query()->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)->orderBy('id')->get();
    }

    /** Wie EinsatzPruefungTest::alleNachweiseErbringen() — die Liste kommt aus OffenePunkte selbst. */
    private function alleNachweiseErbringen(RecEmployee $a): void
    {
        foreach ((new OffenePunkte())->fuer($a, '2026-10-09')['punkte'] as $punkt) {
            DB::table('rec_employee_proofs')->insert([
                'uuid' => 'pf-' . $this->n++, 'team_id' => $this->team, 'rec_employee_id' => $a->id,
                'proof_type_code' => $punkt['code'], 'valid_until' => '2030-12-31',
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
            ]);
        }
        $this->assertSame([], (new OffenePunkte())->fuer($a, '2026-10-09')['punkte'], 'Vorflug: wirklich nichts mehr offen');
    }

    private function laufe(string $datum, array $optionen = []): string
    {
        Carbon::setTestNow($datum . ' 09:00:00');
        $command = new EinsatzPruefung();
        $command->setLaravel(new EinsatzPruefungVertragFakeLaravel());
        $output = new BufferedOutput();
        $command->run(new ArrayInput($optionen, $command->getDefinition()), $output);

        return $output->fetch();
    }

    private function cacheAttrappe(): object
    {
        return new class {
            public array $gehalten = [];
            public function lock(string $name, int $sekunden = 0): object
            {
                return new class($this, $name) {
                    public function __construct(private object $speicher, private string $name) {}
                    public function get(): bool
                    {
                        if (isset($this->speicher->gehalten[$this->name])) {
                            return false;
                        }
                        $this->speicher->gehalten[$this->name] = true;
                        return true;
                    }
                    public function release(): bool
                    {
                        unset($this->speicher->gehalten[$this->name]);
                        return true;
                    }
                };
            }
        };
    }
}

final class EinsatzPruefungVertragFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
