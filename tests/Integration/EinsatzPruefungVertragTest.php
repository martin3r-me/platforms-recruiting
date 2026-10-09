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

    public function test_rg_buchung_bei_standard_einstellung_ohne_fall(): void
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
        $this->einbuchung($ma, '2026-11-20', 'MA4711');
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
        $this->assertSame(['MA'], RecApplicantSettings::DEFAULT_SETTINGS['contract_check_companies']);
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
