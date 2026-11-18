<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoPersonenwechsel;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoConfirmationExport;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;
use Platform\Recruiting\Services\Zeit\DispoZeiten;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitAkteur;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitRegeln;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/** Disk-Ersatz fuer Storage::disk(...)->get(...). */
class PersonenwechselDisk
{
    /** @var array<string,string> */
    public static array $dateien = [];

    public function disk(?string $name = null): self
    {
        return $this;
    }

    public function get(string $pfad): string
    {
        return self::$dateien[$pfad] ?? '';
    }
}

class PersonenwechselLog
{
    /** @var list<array{string, array}> */
    public static array $warnungen = [];
    /** @var list<array{string, array}> */
    public static array $infos = [];

    public function error(string $m, array $c = []): void {}

    public function warning(string $m, array $c = []): void
    {
        self::$warnungen[] = [$m, $c];
    }

    public function info(string $m, array $c = []): void
    {
        self::$infos[] = [$m, $c];
    }
}

/**
 * Identitaets-Attrappe: employee_id => CRM-Kontakte. Wie das Original filtert
 * contactIdsByEmployee NICHT auf is_active — ein inaktiver RG-Datensatz
 * gehoert weiter zur Person.
 */
class PersonenwechselIdentitaet extends DispoIdentityResolver
{
    /** @param array<int, list<int>> $kontakte */
    public function __construct(private array $kontakte = []) {}

    public function contactIdsByEmployee(array $employeeIds): array
    {
        return array_intersect_key($this->kontakte, array_flip(array_map('intval', $employeeIds)));
    }

    public function groupsFor(array $employeeIds): array
    {
        throw new \LogicException('groupsFor filtert auf aktive Datensaetze und darf hier nicht entscheiden.');
    }
}

/**
 * Markus, Mail 13.10.2026: "In ZAS wird nur der Name getauscht, keine neue
 * Zeile angelegt" — die DS-ID bleibt, die Personalnummer wechselt. Die neue
 * Person darf nichts von der alten erben, und die alte darf ihre Absage nicht
 * verlieren.
 */
class ZasDispoPersonenwechselImportTest extends TestCase
{
    private const TEAM = 1102;

    private ?PersonenwechselIdentitaet $identitaet = null;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $container->instance('filesystem', new PersonenwechselDisk());
        $container->instance('log', new PersonenwechselLog());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => 'RG']],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('filesystem');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        PersonenwechselDisk::$dateien = [];
        $this->identitaet = null;
        foreach (['rec_employees', 'rec_zas_dispo_inbound_files', 'rec_dispo_events', 'rec_dispo_assignments',
            'rec_dispo_decline_checks', 'rec_dispo_job_offer_shifts', 'rec_zeit_eintraege', 'rec_zeit_verlauf'] as $t) {
            Capsule::table($t)->delete();
        }
        PersonenwechselLog::$warnungen = [];
        PersonenwechselLog::$infos = [];
        // Damit app(Stempeluhr::class) ohne Service-Provider aufloest.
        Container::getInstance()->instance(ZeitRegeln::class, $this->zeitRegeln());
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        $container->forgetInstance(ZeitRegeln::class);
        $container->forgetInstance(Stempeluhr::class);
    }

    public function test_swapped_person_starts_fresh_and_the_old_decline_stays_with_the_old_person(): void
    {
        $anna = $this->employee('RG1001');
        $ben  = $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));

        RecDispoAssignment::where('ds_ref', '830363')->update([
            'reminder_sent_at' => now(), 'declined_at' => now(), 'declined_reason' => 'krank',
            'declined_portal_locked' => true, 'declined_hr_at' => now(),
            'individual_note' => 'Bitte Schluessel mitbringen', 'escalation_1_at' => now(),
        ]);

        $summary = $this->importiere($this->zeile('RG2002'));

        $this->assertSame(1, $summary['personenwechsel']);
        $this->assertSame(['830363: RG1001 -> RG2002'], $summary['personenwechsel_liste']);

        $live = RecDispoAssignment::where('ds_ref', '830363')->first();
        $this->assertSame($ben->id, (int) $live->rec_employee_id);
        $this->assertSame('RG2002', $live->pnr_raw);
        $this->assertNull($live->missing_since);
        foreach (['reminder_sent_at', 'declined_at', 'declined_reason', 'declined_hr_at', 'individual_note', 'escalation_1_at'] as $spalte) {
            $this->assertNull($live->getAttribute($spalte), "{$spalte} gehoert der alten Person");
        }
        $this->assertFalse((bool) $live->declined_portal_locked);

        $alt = RecDispoAssignment::where('ds_ref', '830363~alt1')->first();
        $this->assertNotNull($alt, 'Die alte Besetzung wird abgelegt.');
        $this->assertSame($anna->id, (int) $alt->rec_employee_id);
        $this->assertSame('RG1001', $alt->pnr_raw);
        $this->assertNotNull($alt->missing_since, 'Abgelegt = in ZAS verschwunden.');
        $this->assertNotNull($alt->declined_hr_at, 'Die HR-Liste behaelt die Absage.');
        $this->assertTrue((bool) $alt->declined_portal_locked, 'Die Portal-Sperre bleibt der alten Person zugeordnet.');
        $this->assertSame('Bitte Schluessel mitbringen', $alt->individual_note);
        $this->assertSame('830363', $alt->source_meta['personenwechsel']['original_ds_ref'] ?? null);
        $this->assertSame('RG2002', $alt->source_meta['personenwechsel']['nachfolger_pnr'] ?? null);
        $this->assertNotSame($live->uuid, $alt->uuid);
    }

    public function test_the_old_confirmation_is_neither_inherited_nor_reported_back_to_zas(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update([
            'confirmed_at' => now(), 'confirmed_datum' => '2027-05-19', 'confirmed_von' => '10:30', 'confirmed_bis' => '21:15',
            'reminder_sent_at' => now(),
        ]);
        $this->assertCount(1, (new ZasDispoConfirmationExport())->rows(), 'Testannahme: vorher wird die Bestaetigung gemeldet.');

        $summary = $this->importiere($this->zeile('RG2002', von: '11:00'));

        $live = RecDispoAssignment::where('ds_ref', '830363')->first();
        $this->assertNull($live->confirmed_at);
        $this->assertNull($live->confirmed_datum);
        $this->assertNull($live->reconfirm_required_at, 'Kein "neue Zeiten" fuer jemanden, der nie bestaetigt hat.');
        $this->assertSame(0, $summary['reconfirm_marked']);

        $alt = RecDispoAssignment::where('ds_ref', '830363~alt1')->first();
        $this->assertNull($alt->confirmed_at, 'Sonst meldete der Export eine erfundene DS-ID an ZAS.');
        $this->assertNotNull($alt->source_meta['personenwechsel']['war_bestaetigt_am'] ?? null);
        $this->assertSame([], (new ZasDispoConfirmationExport())->rows());
    }

    public function test_filling_an_untouched_row_resets_nothing_and_archives_nothing(): void
    {
        $ben = $this->employee('RG2002');
        $this->importiere($this->zeile('RG0'));

        $summary = $this->importiere($this->zeile('RG2002'));

        $this->assertSame(1, $summary['personenwechsel']);
        $this->assertSame(1, RecDispoAssignment::count(), 'Ohne Spuren keine Ablage.');
        $this->assertSame($ben->id, (int) RecDispoAssignment::first()->rec_employee_id);
    }

    public function test_another_number_form_of_the_same_person_is_no_swap(): void
    {
        $anna = $this->employee('RG1001');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['confirmed_at' => now(), 'reminder_sent_at' => now()]);

        $summary = $this->importiere($this->zeile('1001'));

        $this->assertSame(0, $summary['personenwechsel']);
        $live = RecDispoAssignment::where('ds_ref', '830363')->first();
        $this->assertNotNull($live->confirmed_at);
        $this->assertSame($anna->id, (int) $live->rec_employee_id);
        $this->assertSame(1, RecDispoAssignment::count());
    }

    public function test_rg_and_ma_record_of_one_person_is_no_swap(): void
    {
        $rg = $this->employee('RG1001');
        $ma = $this->employee('MA5005');
        $rg->update(['is_active' => false]); // nach dem Wechsel zu MA deaktiviert
        $this->identitaet = new PersonenwechselIdentitaet([$rg->id => [77], $ma->id => [77]]);
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now()]);

        $summary = $this->importiere($this->zeile('MA5005'));

        $this->assertSame(0, $summary['personenwechsel']);
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', '830363')->value('declined_at'));
        $this->assertSame(1, RecDispoAssignment::count());
    }

    public function test_rg_and_ma_paired_only_by_person_key_is_no_swap(): void
    {
        $rg = $this->employee('RG1001');
        $ma = $this->employee('MA5005');
        Capsule::table('rec_employees')->whereIn('id', [$rg->id, $ma->id])->update(['person_key' => 'p-1']);
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['confirmed_at' => now()]);

        $summary = $this->importiere($this->zeile('MA5005'));

        $this->assertSame(0, $summary['personenwechsel']);
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', '830363')->value('confirmed_at'));
    }

    public function test_an_older_file_processed_later_does_not_swap_the_person_back(): void
    {
        $this->employee('RG1001');
        $ben = $this->employee('RG2002');
        $alteDatei = $this->importiereDatei($this->zeile('RG1001'));
        $this->importiere($this->zeile('RG2002'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['confirmed_at' => now(), 'reminder_sent_at' => now()]);

        $summary = $this->importer()->import($alteDatei);

        $this->assertSame(0, $summary['personenwechsel']);
        $this->assertSame(1, $summary['personenwechsel_veraltet']);
        $live = RecDispoAssignment::where('ds_ref', '830363')->first();
        $this->assertSame('RG2002', $live->pnr_raw);
        $this->assertSame($ben->id, (int) $live->rec_employee_id);
        $this->assertNotNull($live->confirmed_at, 'Die Bestaetigung aus der neueren Lieferung bleibt.');
        $this->assertSame(0, RecDispoAssignment::where('ds_ref', 'like', '%~alt%')->count());        $this->assertSame([], $summary['unmatched_pnrs'], 'Die verworfene Nummer ist nie geschrieben worden.');
        $this->assertSame(1, $summary['matched']);
    }

    public function test_an_older_file_with_an_unknown_number_does_not_report_it_as_unmatched(): void
    {
        $this->employee('RG2002');
        $alteDatei = $this->importiereDatei($this->zeile('RG9999'));
        $this->importiere($this->zeile('RG2002'));

        $summary = $this->importer()->import($alteDatei);
        $vorschau = $this->importer()->import($alteDatei, true);

        $this->assertSame(1, $summary['personenwechsel_veraltet']);
        $this->assertSame([], $summary['unmatched_pnrs']);
        $this->assertSame(0, $summary['unmatched']);
        $this->assertSame([], $vorschau['unmatched_pnrs'], 'Die Vorschau zaehlt wie der Lauf.');
    }

    public function test_references_by_row_id_move_to_the_archive_row(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        $live = RecDispoAssignment::where('ds_ref', '830363')->first();
        // Keine eigenen Spuren an der Zeile: nur die Verweise erzwingen die Ablage.
        Capsule::table('rec_dispo_decline_checks')->insert([
            'team_id' => self::TEAM, 'rec_dispo_event_id' => $live->rec_dispo_event_id, 'rec_employee_id' => $live->rec_employee_id,
            'comms_whatsapp_message_id' => 1, 'outcome' => 'decline', 'assignment_ids' => json_encode([$live->id, 999]),
            'review_status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('rec_dispo_job_offer_shifts')->insert([
            'offer_id' => 1, 'shift_id' => 1, 'accepted_at' => now(), 'zas_assignment_id' => $live->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importiere($this->zeile('RG2002'));

        $ablage = RecDispoAssignment::where('ds_ref', '830363~alt1')->first();
        $this->assertNotNull($ablage, 'Verweise allein reichen fuer eine Ablage.');
        $this->assertSame([$ablage->id, 999], json_decode(Capsule::table('rec_dispo_decline_checks')->value('assignment_ids'), true));
        $this->assertSame($ablage->id, (int) Capsule::table('rec_dispo_job_offer_shifts')->value('zas_assignment_id'));
    }

    public function test_an_already_missing_row_keeps_its_original_missing_date_in_the_archive(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now(), 'missing_since' => '2027-01-01 08:00:00']);

        $this->importiere($this->zeile('RG2002'));

        $this->assertSame('2027-01-01 08:00:00', RecDispoAssignment::where('ds_ref', '830363~alt1')->first()->missing_since->toDateTimeString());
    }

    public function test_every_assignment_column_is_either_reset_or_deliberately_kept(): void
    {
        $bewusstBehalten = [
            'id', 'uuid', 'ds_ref', 'rec_dispo_event_id', 'pnr_raw', 'rec_employee_id',
            'datum', 'von', 'bis', 'status_id', 'taetigkeit', 'last_seen_at', 'missing_since',
            'source_meta', 'created_at', 'updated_at',
        ];
        $spalten = Capsule::schema()->getColumnListing('rec_dispo_assignments');

        $ungeklaert = array_values(array_diff($spalten, $bewusstBehalten, array_keys(DispoPersonenwechsel::RESET)));

        $this->assertSame([], $ungeklaert, 'Neue Spalte an der Einbuchung: gehoert sie der Person (RESET) oder dem Einsatz?');
    }

    public function test_an_old_row_unmatched_at_the_time_is_rematched_before_judging(): void
    {
        // Beim ersten Import fehlte die Person noch im Stamm.
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['reminder_sent_at' => now()]);
        $this->employee('RG1001');

        $summary = $this->importiere($this->zeile('1001'));

        $this->assertSame(0, $summary['personenwechsel']);
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', '830363')->value('reminder_sent_at'));
    }

    public function test_a_second_swap_gets_its_own_archive_row(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->employee('RG3003');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now()]);
        $this->importiere($this->zeile('RG2002'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now()]);

        $this->importiere($this->zeile('RG3003'));

        $this->assertSame('RG1001', RecDispoAssignment::where('ds_ref', '830363~alt1')->value('pnr_raw'));
        $this->assertSame('RG2002', RecDispoAssignment::where('ds_ref', '830363~alt2')->value('pnr_raw'));
        $this->assertSame('RG3003', RecDispoAssignment::where('ds_ref', '830363')->value('pnr_raw'));
    }

    public function test_a_repeated_delivery_leaves_the_archive_row_alone(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now()]);
        $this->importiere($this->zeile('RG2002'));
        $abgelegtAm = RecDispoAssignment::where('ds_ref', '830363~alt1')->value('missing_since');

        $summary = $this->importiere($this->zeile('RG2002'));

        $this->assertSame(0, $summary['personenwechsel']);
        $this->assertSame(0, $summary['missing_marked']);
        $this->assertSame((string) $abgelegtAm, (string) RecDispoAssignment::where('ds_ref', '830363~alt1')->value('missing_since'));
        $this->assertSame(2, RecDispoAssignment::count());
    }

    public function test_dry_run_counts_the_swap_but_writes_nothing(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['declined_at' => now()]);

        $summary = $this->importiere($this->zeile('RG2002'), dryRun: true);

        $this->assertSame(1, $summary['personenwechsel']);
        $this->assertSame(1, RecDispoAssignment::count());
        $this->assertSame('RG1001', RecDispoAssignment::where('ds_ref', '830363')->value('pnr_raw'));
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', '830363')->value('declined_at'));
    }

    // ---- Zeiterfassung (Spec 2026-11-16 §2.2/§7.5, Schluss-Review W1–W3, K2) ----

    public function test_anstellungswechsel_derselben_person_zieht_den_zeitdatensatz_mit(): void
    {
        [$rg, $ma] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $this->assertSame('ok', $this->webapp()->jetztKommen($this->live(), $this->dispo()));

        $summary = $this->importiere($this->zeile('MA5005'));

        $this->assertSame(0, $summary['personenwechsel']);
        $live = $this->live();
        $this->assertSame($ma->id, (int) $live->rec_employee_id);
        $this->assertSame(1, ZeitEintrag::count(), 'ein Datensatz je Mensch und Einsatz');
        $e = ZeitEintrag::first();
        $this->assertSame($ma->id, (int) $e->person_id);
        $this->assertSame((int) $live->id, (int) $e->bezug_id);
        $this->assertNotNull($e->kommen_at);

        $umzug = ZeitVerlauf::where('feld', 'person_id')->get();
        $this->assertCount(1, $umzug);
        $this->assertSame([(string) $rg->id, (string) $ma->id, 'umzug', 'system', null, 'ZAS-Import'],
            [$umzug[0]->alt, $umzug[0]->neu, $umzug[0]->art, $umzug[0]->akteur_typ, $umzug[0]->akteur_id, $umzug[0]->akteur_name]);

        // Die Disposition sieht den Stempel und kann keinen zweiten Datensatz anlegen.
        $karte = $this->webapp()->fuerEvent((int) $live->rec_dispo_event_id);
        $this->assertSame('eingecheckt', $this->webapp()->zelle($karte[(int) $live->id] ?? null)['zustand']);
        $this->assertSame('schon', $this->webapp()->jetztKommen($live, $this->dispo()));
        $this->assertSame(1, ZeitEintrag::count());
        $this->assertSame([], PersonenwechselLog::$warnungen);
    }

    public function test_anstellungswechsel_nur_ueber_person_key_zieht_ebenfalls_mit(): void
    {
        $rg = $this->employee('RG1001');
        $ma = $this->employee('MA5005');
        Capsule::table('rec_employees')->whereIn('id', [$rg->id, $ma->id])->update(['person_key' => 'p-1']);
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());

        $this->importiere($this->zeile('MA5005'));

        $this->assertSame([$ma->id], ZeitEintrag::pluck('person_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_anstellungswechsel_hin_und_zurueck_bleibt_ein_datensatz(): void
    {
        [$rg] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());

        $this->importiere($this->zeile('MA5005'));
        $this->importiere($this->zeile('RG1001'));

        $this->assertSame([$rg->id], ZeitEintrag::pluck('person_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, ZeitVerlauf::where('art', 'umzug')->count());
    }

    public function test_anstellungswechsel_mit_vorhandenem_ziel_aendert_nichts_und_protokolliert(): void
    {
        [$rg, $ma] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $live = $this->live();
        $alt = $this->zeitEintrag((int) $live->id, $rg->id, ['kommen_at' => '2027-05-19 08:00:00']);
        $ziel = $this->zeitEintrag((int) $live->id, $ma->id, ['kommen_at' => '2027-05-19 09:00:00']);

        $this->importiere($this->zeile('MA5005'));

        $this->assertSame($rg->id, (int) $alt->fresh()->person_id);
        $this->assertSame($ma->id, (int) $ziel->fresh()->person_id);
        $this->assertSame('2027-05-19 09:00:00', $ziel->fresh()->kommen_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, ZeitVerlauf::count());
        $this->assertCount(1, PersonenwechselLog::$infos);
        $this->assertSame('zeit_person_umschreiben_ziel_existiert', PersonenwechselLog::$infos[0][0]);
        $this->assertSame(['assignment_id' => (int) $live->id, 'von_person_id' => $rg->id, 'nach_person_id' => $ma->id], PersonenwechselLog::$infos[0][1]);
    }

    public function test_anstellungswechsel_nimmt_auch_einen_gesperrten_datensatz_mit(): void
    {
        [$rg, $ma] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $e = $this->zeitEintrag((int) $this->live()->id, $rg->id, [
            'kommen_at' => '2027-05-19 08:00:00', 'gehen_at' => '2027-05-19 19:00:00',
            'gesperrt_at' => '2027-05-19 20:00:00', 'gesperrt_grund' => 'erledigt',
        ]);

        $this->importiere($this->zeile('MA5005'));

        $e = $e->fresh();
        $this->assertSame($ma->id, (int) $e->person_id);
        $this->assertTrue($e->istGesperrt());
        $this->assertSame('2027-05-19 19:00:00', $e->gehen_at->format('Y-m-d H:i:s'));
    }

    public function test_gleiche_nummer_aber_anderer_datensatz_derselben_person_zieht_mit(): void
    {
        $alt = $this->employee('RG1001');
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());
        // Der Stamm loest dieselbe Nummer inzwischen auf einen anderen Datensatz desselben Menschen auf.
        $alt->update(['personnel_number' => 'RG9001', 'portal_token' => 'tok-alt', 'is_active' => false]);
        $neu = $this->employee('RG1001');
        Capsule::table('rec_employees')->whereIn('id', [$alt->id, $neu->id])->update(['person_key' => 'p-7']);

        $this->importiere($this->zeile('RG1001'));

        $this->assertSame($neu->id, (int) $this->live()->rec_employee_id);
        $this->assertSame([$neu->id], ZeitEintrag::pluck('person_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_gleiche_nummer_fremder_datensatz_schreibt_die_zeit_nicht_um(): void
    {
        $alt = $this->employee('RG1001');
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());
        $alt->update(['personnel_number' => 'RG9001', 'portal_token' => 'tok-alt', 'is_active' => false]);
        $this->employee('RG1001'); // kein gemeinsamer Schluessel, kein gemeinsamer Kontakt

        $this->importiere($this->zeile('RG1001'));

        $this->assertSame([$alt->id], ZeitEintrag::pluck('person_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, ZeitVerlauf::where('art', 'umzug')->count());

        // Review 16.11., F4: der Rest ist auffindbar — eine Protokoll-Zeile mit beiden Personen.
        $zeilen = array_values(array_filter(PersonenwechselLog::$warnungen, fn ($w) => $w[0] === 'dispo_zeile_auf_fremden_datensatz_umgehaengt'));
        $this->assertCount(1, $zeilen);
        $this->assertSame($alt->id, $zeilen[0][1]['von_person_id']);
        $this->assertSame((int) $this->live()->rec_employee_id, $zeilen[0][1]['nach_person_id']);
        $this->assertSame($this->live()->id, $zeilen[0][1]['assignment_id']);
    }

    public function test_anstellungswechsel_ohne_zeit_tabelle_laeuft_der_import(): void
    {
        [, $ma] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        Container::getInstance()->instance(Stempeluhr::class, new class($this->zeitRegeln()) extends Stempeluhr {
            public function personUmschreiben(string $bezugTyp, int $bezugId, string $personTyp, int $vonPersonId, int $nachPersonId, ZeitAkteur $akteur): string
            {
                throw new \LogicException('ohne Tabelle darf die Stempeluhr nicht gerufen werden');
            }
        });

        Capsule::schema()->rename('rec_zeit_eintraege', 'rec_zeit_eintraege_weg');
        try {
            $this->importiere($this->zeile('MA5005'));
        } finally {
            Capsule::schema()->rename('rec_zeit_eintraege_weg', 'rec_zeit_eintraege');
        }

        $this->assertSame($ma->id, (int) $this->live()->rec_employee_id);
        $this->assertSame([], PersonenwechselLog::$warnungen);
    }

    public function test_fehler_beim_umschreiben_bricht_den_import_nicht_ab(): void
    {
        [$rg, $ma] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());
        $this->stempeluhrWirft(new \RuntimeException('Spalte fehlt'));

        $this->importiere($this->zeile('MA5005'));

        $this->assertSame($ma->id, (int) $this->live()->rec_employee_id, 'Lieferung ist angewendet');
        $this->assertSame($rg->id, (int) ZeitEintrag::first()->person_id);
        $this->assertCount(1, PersonenwechselLog::$warnungen);
        $this->assertSame('zeit_person_umschreiben_fehlgeschlagen', PersonenwechselLog::$warnungen[0][0]);
        $this->assertSame('Spalte fehlt', PersonenwechselLog::$warnungen[0][1]['fehler']);
    }

    public function test_deadlock_beim_umschreiben_laesst_den_import_scheitern_statt_halb_weiterzulaufen(): void
    {
        [$rg] = $this->rgUndMa();
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());
        $this->stempeluhrWirft(new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));

        $summary = $this->importer()->import($this->importiereDatei($this->zeile('MA5005'), nurAnlegen: true));

        $this->assertTrue($summary['rolled_back'] ?? false);
        $this->assertNotSame([], $summary['errors']);
        $this->assertSame($rg->id, (int) $this->live()->rec_employee_id, 'nichts halb angewendet');
        $this->assertSame([], PersonenwechselLog::$warnungen, 'nicht als harmlose Warnung verschluckt');
    }

    public function test_personenwechsel_an_einer_nur_gestempelten_zeile_legt_die_alte_besetzung_ab(): void
    {
        $anna = $this->employee('RG1001');
        $ben  = $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        // Nur stellvertretend gestempelt: kein Versand, keine Bestaetigung, keine Notiz.
        $this->assertSame('ok', $this->webapp()->jetztKommen($this->live(), $this->dispo()));
        $this->assertFalse(DispoPersonenwechsel::hatSpuren($this->live()));

        $summary = $this->importiere($this->zeile('RG2002'));

        $this->assertSame(1, $summary['personenwechsel']);
        $ablage = RecDispoAssignment::where('ds_ref', '830363~alt1')->first();
        $this->assertNotNull($ablage, 'ein Stempel ist eine Spur: die alte Besetzung wird abgelegt');
        $live = $this->live();
        $this->assertSame($ben->id, (int) $live->rec_employee_id);
        $e = ZeitEintrag::first();
        $this->assertSame(1, ZeitEintrag::count());
        $this->assertSame([(int) $ablage->id, $anna->id], [(int) $e->bezug_id, (int) $e->person_id]);
        $this->assertSame(0, ZeitEintrag::where('bezug_id', $live->id)->count(), 'Live-Zeile ist leer');
        $this->assertSame([(int) $ablage->id], array_keys($this->webapp()->fuerEvent((int) $live->rec_dispo_event_id)), 'in der Webapp an der Ablage-Zeile auffindbar');
    }

    public function test_personenwechsel_ohne_zeit_tabelle_laeuft_der_import(): void
    {
        $this->employee('RG1001');
        $ben = $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        RecDispoAssignment::where('ds_ref', '830363')->update(['confirmed_at' => now()]);

        Capsule::schema()->rename('rec_zeit_eintraege', 'rec_zeit_eintraege_weg');
        try {
            $summary = $this->importiere($this->zeile('RG2002'));
        } finally {
            Capsule::schema()->rename('rec_zeit_eintraege_weg', 'rec_zeit_eintraege');
        }

        $this->assertSame(1, $summary['personenwechsel']);
        $this->assertSame($ben->id, (int) $this->live()->rec_employee_id);
        $this->assertSame(1, RecDispoAssignment::where('ds_ref', '830363~alt1')->count());
        $this->assertSame([], PersonenwechselLog::$warnungen);
    }

    public function test_zeitdatensatz_einer_fremden_person_an_der_zeile_ist_keine_spur(): void
    {
        $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        $this->zeitEintrag((int) $this->live()->id, 424242, ['kommen_at' => '2027-05-19 08:00:00']);

        $this->importiere($this->zeile('RG2002'));

        $this->assertSame(1, RecDispoAssignment::count(), 'der Rest einer anderen Person loest keine Ablage aus');
    }

    public function test_deadlock_beim_umhaengen_in_die_ablage_laesst_den_import_scheitern(): void
    {
        $anna = $this->employee('RG1001');
        $this->employee('RG2002');
        $this->importiere($this->zeile('RG1001'));
        $this->webapp()->jetztKommen($this->live(), $this->dispo());
        $this->stempeluhrWirft(new \RuntimeException('Lock wait timeout exceeded; try restarting transaction'));

        $summary = $this->importer()->import($this->importiereDatei($this->zeile('RG2002'), nurAnlegen: true));

        $this->assertTrue($summary['rolled_back'] ?? false);
        $this->assertSame($anna->id, (int) $this->live()->rec_employee_id);
        $this->assertSame(1, RecDispoAssignment::count(), 'auch die Ablage ist zurueckgerollt');
        $this->assertSame([], PersonenwechselLog::$warnungen);
    }

    // ---------------------------------------------------------------

    /** @return array{0: RecEmployee, 1: RecEmployee} RG- und MA-Datensatz eines Menschen (gemeinsamer Kontakt). */
    private function rgUndMa(): array
    {
        $rg = $this->employee('RG1001');
        $ma = $this->employee('MA5005');
        $this->identitaet = new PersonenwechselIdentitaet([$rg->id => [77], $ma->id => [77]]);

        return [$rg, $ma];
    }

    private function live(): RecDispoAssignment
    {
        return RecDispoAssignment::where('ds_ref', '830363')->firstOrFail();
    }

    private function dispo(): ZeitAkteur
    {
        return new ZeitAkteur('user', 7, 'Dispo Test');
    }

    private function webapp(): DispoZeiten
    {
        $schalter = new class extends ZeitSchalter {
            public function aktiv(): bool
            {
                return true;
            }

            public function teamId(): int
            {
                return 1102;
            }
        };

        return new DispoZeiten(new Stempeluhr($this->zeitRegeln()), $schalter, $this->zeitRegeln());
    }

    private function zeitEintrag(int $assignmentId, int $person, array $felder = []): ZeitEintrag
    {
        return ZeitEintrag::create(array_merge([
            'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => $person,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $assignmentId, 'tag' => '2027-05-19',
        ], $felder));
    }

    /** Jeder Schreibweg der Stempeluhr, den der Import nutzt, wirft diesen Fehler. */
    private function stempeluhrWirft(\Throwable $fehler): void
    {
        Container::getInstance()->instance(Stempeluhr::class, new class($this->zeitRegeln(), $fehler) extends Stempeluhr {
            public function __construct(ZeitRegeln $regeln, private \Throwable $fehler)
            {
                parent::__construct($regeln);
            }

            public function personUmschreiben(string $bezugTyp, int $bezugId, string $personTyp, int $vonPersonId, int $nachPersonId, ZeitAkteur $akteur): string
            {
                throw $this->fehler;
            }

            public function umhaengen(string $bezugTyp, int $vonBezugId, int $nachBezugId, string $personTyp, int $personId): void
            {
                throw $this->fehler;
            }
        });
    }

    private function zeitRegeln(): ZeitRegeln
    {
        return new class implements ZeitRegeln {
            public function fruehestesKommen(ZeitBezug $b): ?\Carbon\CarbonImmutable
            {
                return $b->sollVon?->subMinutes(60);
            }

            public function spaetestesKommen(ZeitBezug $b): ?\Carbon\CarbonImmutable
            {
                return null;
            }

            public function spaetestesStempeln(ZeitBezug $b): ?\Carbon\CarbonImmutable
            {
                return $b->sollBis?->addMinutes(60);
            }

            public function runde(\DateTimeInterface $zeit): \Carbon\CarbonImmutable
            {
                return \Carbon\CarbonImmutable::instance($zeit);
            }

            public function pauseMinuten(int $anwesendMinuten): int
            {
                return 0;
            }
        };
    }

    private function employee(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
    }

    /** Eine Einbuchungszeile in der Zukunft, DS-ID 830363, Status Auftrag. */
    private function zeile(string $pnr, string $von = '10:30'): string
    {
        return "{Dispo}\r\n19.05.2027;BHG. BROICHCATERING GMBH;{$pnr};RG19077;1;RG13450;830363;{$von};21:15;1;0;Servicekräfte;;27,49;\r\n";
    }

    private function importiere(string $inhalt, bool $dryRun = false): array
    {
        $summary = $this->importer()->import($this->importiereDatei($inhalt, nurAnlegen: true), $dryRun);
        $this->assertSame([], $summary['errors'], 'Import selbst muss sauber laufen.');

        return $summary;
    }

    /** Legt die Rohdatei an; ohne nurAnlegen wird sie auch gleich importiert. */
    private function importiereDatei(string $inhalt, bool $nurAnlegen = false): RecZasDispoInboundFile
    {
        $pfad = 'zas-dispo-inbound/test-' . uniqid() . '.csv';
        PersonenwechselDisk::$dateien[$pfad] = $inhalt;

        $file = RecZasDispoInboundFile::create([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'source' => 'test', 'original_filename' => 'test.csv',
            'disk' => 'local', 'stored_path' => $pfad,
            'mime_type' => 'text/csv', 'size_bytes' => strlen($inhalt),
            'parse_status' => 'viewable',
        ]);

        if (!$nurAnlegen) {
            $this->importer()->import($file);
        }

        return $file;
    }

    private function importer(): ZasDispoWebexportImporter
    {
        return new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
            personenwechsel: new DispoPersonenwechsel($this->identitaet ?? new PersonenwechselIdentitaet()),
        );
    }

    private static function runMigrations(): void
    {
        $own  = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own,  'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own,  'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own,  'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own,  'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own,  'database/migrations/2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php'],
            [$own,  'database/migrations/2026_08_12_000003_add_processed_at_to_rec_zas_dispo_inbound_files.php'],
            [$own,  'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own,  'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own,  'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php'],
            [$own,  'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_27_000002_add_escalation_override_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_28_000001_add_escalation_date_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000002_add_escalation_plan_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000003_add_reminder_sent_to_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000004_add_manual_confirm_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_08_000001_add_late_marker_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_30_000003_add_dress_fields_to_dispo_tables.php'],
            [$own,  'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php'],
            [$own,  'database/migrations/2026_10_08_000003_create_rec_dispo_decline_checks_table.php'],
            [$own,  'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own,  'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own,  'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$own,  'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php'],
            [$own,  'database/migrations/2026_11_16_000001_create_rec_zeit_eintraege_table.php'],
            [$own,  'database/migrations/2026_11_16_000002_create_rec_zeit_verlauf_table.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }

        // Spalte aus 2026_10_01_000001_add_trigger_state — die Migration selbst
        // braucht rec_persons, das es hier nicht gibt; die Spalte reicht.
        Capsule::schema()->table('rec_dispo_assignments', function ($t) {
            $t->timestamp('aufgaben_erinnert_at')->nullable();
        });
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
