<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Ueberschreib-Regel fuer den ZAS-BESTAND (Entscheidung 23.09.2026).
 *
 * Der Bestand wird in ZAS gepflegt, unser Stand blieb aber auf dem Tag der
 * Anlage stehen (meist 25.08./02.09.), weil der Import Treffer nur beim Status
 * anfasste. Jetzt: bei ZAS-Bestand (aus einer Lieferung entstanden, keine
 * Bewerbung) uebernimmt der Import jeden GELIEFERTEN, abweichenden Wert.
 *
 * Nicht betroffen: Mitarbeiter aus unserem Funnel und Mischfaelle (Lieferung
 * + spaeter verknuepfte Bewerbung). Nie ueberschrieben: Telefon (wird fuer
 * ALLE bei uns gepflegt — Dispo/WhatsApp), Land (Default 'de'), Ausweisnummer
 * (Portal-Login), Personalnummer und Firma (nur Nachtrag).
 * Eine leere Zelle loescht nichts. Keine Rueckkopplung: nichts davon markiert
 * den Mitarbeiter fuer den Rueck-Export an ZAS.
 */
class ZasInboundOverwriteTest extends TestCase
{
    private const TEAM = 7;

    private static ConfigRepository $config;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        self::$config = new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['inbound_team_id' => self::TEAM, 'inbound_overwrite_zas_owned' => true]],
        ]);
        $container->instance('config', self::$config);

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());
        Model::unguard();

        $schema = Capsule::schema();
        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            foreach (['first_name', 'last_name', 'birth_name', 'birth_place', 'identity_card_number', 'phone', 'email',
                      'street', 'house_number', 'zip', 'city', 'country_code', 'bank_institute', 'iban', 'bic',
                      'account_holder', 'tax_class', 'steuer_id', 'sozialversicherungsnummer', 'health_insurance',
                      'gender', 'marital_status', 'religion', 'employment_type', 'nationality', 'birth_country',
                      'drivers_license_class', 'recruited_by_personnel_number', 'shirt_size', 'cost_center',
                      'personnel_number', 'company'] as $col) {
                $t->string($col)->nullable();
            }
            foreach (['birth_date', 'identity_card_valid_until', 'residence_permit_valid_until', 'work_permit_valid_until',
                      'school_certificate_valid_until', 'infection_protection_first_issued_at', 'employed_since',
                      'first_aider_valid_until', 'infection_protection_instructed_at', 'infection_protection_valid_until'] as $col) {
                $t->date($col)->nullable();
            }
            foreach (['number_of_children', 'pants_size', 'shoe_size'] as $col) {
                $t->integer($col)->nullable();
            }
            foreach (['has_car', 'is_eu_citizen', 'is_first_aider', 'is_safety_officer', 'has_infection_protection_certificate'] as $col) {
                $t->boolean($col)->nullable();
            }
            $t->integer('rec_applicant_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('zas_changed_at')->nullable();
            $t->dateTime('zas_initial_exported_at')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_employee_hr_data', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_employee_id');
            $t->integer('team_id')->nullable();
            $t->string('export_status')->nullable();
            $t->date('status_ma_since')->nullable();
            $t->date('contract_sent_date')->nullable();
            $t->date('contract_signed_at')->nullable();
            $t->date('contract_end_date')->nullable();
            $t->string('employment_classification')->nullable();
            $t->timestamps();
        });

        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        // Sonst schreibt die naechste Testklasse ueber die gecachte DB-Facade
        // in diese (geloeschte) Verbindung.
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        self::$config->set('recruiting.zas.inbound_overwrite_zas_owned', true);
        Capsule::table('rec_employee_hr_data')->delete();
        Capsule::table('rec_employees')->delete();
    }


    private function importer(): ZasInboundEmployeeImporter
    {
        $lookups = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return match ($lookupName) {
                    'geburtsland' => [['value' => 'de', 'label' => 'Deutschland'], ['value' => 'tr', 'label' => 'Türkei']],
                    'krankenkasse' => [['value' => 'tk', 'label' => 'Techniker Krankenkasse'], ['value' => 'aok', 'label' => 'AOK']],
                    default => [],
                };
            }
        };
        return new ZasInboundEmployeeImporter(
            new ZasInboundRowMapper($lookups),
            new ZasInboundDuplicateFinder(),
        );
    }

    /**
     * @param 'zas'|'hcm'|'misch' $origin
     */
    private function makeEmployee(string $origin = 'zas', array $attributes = [], array $hr = [], ?string $marker = null): RecEmployee
    {
        $employee = RecEmployee::create(array_merge([
            'team_id'                 => self::TEAM,
            'first_name'              => 'Alt',
            'last_name'               => 'Bestand',
            'personnel_number'        => 'RG4711',
            'company'                 => 'RG',
            'street'                  => 'Altstrasse',
            'phone'                   => '+4917612345678',
            'identity_card_number'    => 'L01X00T47',
            'country_code'            => 'at',
            'health_insurance'        => 'aok',
            'nationality'             => 'de',
            'is_active'               => true,
            'rec_zas_inbound_file_id' => $origin === 'hcm' ? null : 55,
            'rec_applicant_id'        => $origin === 'zas' ? null : 900,
        ], $attributes));
        RecEmployeeHrData::create(array_merge([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => 'GO',
        ], $hr));
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => $marker]);

        return $employee->fresh();
    }

    private function row(array $overrides = []): array
    {
        return array_merge(['ZasPersonalNr' => 'RG4711', 'Status' => 'GO'], $overrides);
    }

    private function run_(array $row, bool $dryRun = false): array
    {
        return $this->importer()->import([$row], (object) ['id' => 99], $dryRun);
    }

    private function hr(RecEmployee $e): RecEmployeeHrData
    {
        return RecEmployeeHrData::where('rec_employee_id', $e->id)->firstOrFail();
    }

    public function test_zas_bestand_uebernimmt_gelieferte_werte(): void
    {
        $e = $this->makeEmployee();

        $report = $this->run_($this->row([
            'Vorname'              => 'Neu',
            'Strasse'              => 'Neustrasse',
            'IBAN'                 => 'DE02120300000000202051',
            'Geburtsdatum'         => '01.02.2000',
            'PKW'                  => 'Ja',
            'KinderAnzahl'         => '2',
            'Krankenkasse'         => 'Techniker Krankenkasse',
            'FolgeBescheinigungAm' => '14.03.2025',
            'InfekBeschVorhanden'  => 'Ja',
            'AufenthaltGenehmigungErforderlich' => 'Nein',
            'BefristetBis'         => '31.12.2026',
            'VertragZurueckAm'     => '02.01.2026',
        ]));

        $f = $e->fresh();
        $this->assertSame('Neu', $f->first_name);
        $this->assertSame('Neustrasse', $f->street);
        $this->assertSame('DE02120300000000202051', $f->iban);
        $this->assertSame('2000-02-01', $f->birth_date->format('Y-m-d'));
        $this->assertTrue($f->has_car);
        $this->assertSame(2, $f->number_of_children);
        $this->assertSame('tk', $f->health_insurance);
        $this->assertSame('2025-03-14', $f->infection_protection_instructed_at->format('Y-m-d'));
        $this->assertTrue($f->has_infection_protection_certificate);
        $this->assertTrue($f->is_eu_citizen, 'Nein + deutsche Staatsangehoerigkeit (Bestand) → EU');
        $this->assertSame('2026-12-31', $this->hr($e)->contract_end_date->format('Y-m-d'));
        $this->assertSame('2026-01-02', $this->hr($e)->contract_signed_at->format('Y-m-d'));

        $this->assertCount(1, $report['updated']);
        $changed = $report['updated'][0]['changed'];
        foreach (['first_name', 'street', 'iban', 'birth_date', 'health_insurance', 'contract_end_date'] as $field) {
            $this->assertContains($field, $changed, $field);
        }
    }

    public function test_leere_zelle_loescht_nichts(): void
    {
        $e = $this->makeEmployee();

        $this->run_($this->row(['Strasse' => '', 'Vorname' => '']));

        $this->assertSame('Altstrasse', $e->fresh()->street);
        $this->assertSame('Alt', $e->fresh()->first_name);
    }

    public function test_geschuetzte_felder_bleiben_stehen(): void
    {
        $e = $this->makeEmployee();

        $this->run_($this->row(['AusweisNr' => 'NEU123456', 'Land' => 'de']));

        $f = $e->fresh();
        $this->assertSame('L01X00T47', $f->identity_card_number, 'Ausweisnummer = Portal-Login, nie ueberschreiben');
        $this->assertSame('at', $f->country_code, 'Land nie ueberschreiben (Import-Default de)');
        $this->assertSame('RG4711', $f->personnel_number);
        $this->assertSame('RG', $f->company);
    }

    public function test_funnel_mitarbeiter_werden_nicht_ueberschrieben(): void
    {
        $e = $this->makeEmployee('hcm');

        $this->run_($this->row(['Vorname' => 'Neu', 'Strasse' => 'Neustrasse']));

        $this->assertSame('Alt', $e->fresh()->first_name);
        $this->assertSame('Altstrasse', $e->fresh()->street);
    }

    public function test_mischfaelle_werden_nicht_ueberschrieben(): void
    {
        $e = $this->makeEmployee('misch');

        $this->run_($this->row(['Vorname' => 'Neu']));

        $this->assertSame('Alt', $e->fresh()->first_name);
    }

    public function test_schalter_aus_ueberschreibt_nichts(): void
    {
        self::$config->set('recruiting.zas.inbound_overwrite_zas_owned', false);
        $e = $this->makeEmployee();

        $this->run_($this->row(['Vorname' => 'Neu']));

        $this->assertSame('Alt', $e->fresh()->first_name);
    }

    public function test_auswahlwert_ohne_treffer_ueberschreibt_keinen_code(): void
    {
        $e = $this->makeEmployee('zas', ['nationality' => 'tr', 'marital_status' => null]);

        $this->run_($this->row(['Nation' => 'Syrisch', 'Krankenkasse' => 'Irgendeine BKK']));

        $f = $e->fresh();
        $this->assertSame('tr', $f->nationality, 'Freitext darf keinen Code ersetzen');
        $this->assertSame('aok', $f->health_insurance);
    }

    public function test_auswahlwert_ohne_treffer_fuellt_leeres_feld(): void
    {
        $e = $this->makeEmployee('zas', ['health_insurance' => null]);

        $this->run_($this->row(['Krankenkasse' => 'Irgendeine BKK']));

        $this->assertSame('Irgendeine BKK', $e->fresh()->health_insurance);
    }

    public function test_kein_echo_nach_zas(): void
    {
        $e = $this->makeEmployee();

        $this->run_($this->row(['Vorname' => 'Neu', 'BefristetBis' => '31.12.2026']));

        $this->assertNull(Capsule::table('rec_employees')->where('id', $e->id)->value('zas_changed_at'));
    }

    public function test_vorhandener_marker_bleibt_erhalten(): void
    {
        $e = $this->makeEmployee('zas', [], [], '2026-09-20 10:00:00');

        $this->run_($this->row(['Vorname' => 'Neu']));

        $this->assertSame('2026-09-20 10:00:00', Capsule::table('rec_employees')->where('id', $e->id)->value('zas_changed_at'));
    }

    public function test_telefon_wird_nie_ueberschrieben(): void
    {
        // Telefon wird fuer ALLE Mitarbeiter bei uns gepflegt (Dispo, WhatsApp).
        $e = $this->makeEmployee();

        $report = $this->run_($this->row(['Telefon' => '0176 99999999']));

        $this->assertSame('+4917612345678', $e->fresh()->phone);
        $this->assertSame('exists', $report['skipped'][0]['reason'] ?? null);
    }

    public function test_keine_rueckkopplung_auch_bei_vielen_feldern(): void
    {
        // Stammdaten (direkt geschrieben) UND Vertragsdaten (ueber Eloquent,
        // loest den Export-Observer aus) — der Marker muss danach leer sein.
        $e = $this->makeEmployee();

        $this->run_($this->row([
            'Vorname' => 'Neu', 'Strasse' => 'Neustrasse', 'IBAN' => 'DE02120300000000202051',
            'Krankenkasse' => 'Techniker Krankenkasse', 'PKW' => 'Ja', 'AusweisBis' => '01.01.2030',
            'FolgeBescheinigungAm' => '14.03.2025', 'AufenthaltGenehmigungErforderlich' => 'Ja',
            'BefristetBis' => '31.12.2026', 'VertragVersendetAm' => '01.12.2025', 'Status' => 'MA', 'StatusMASeit' => '01.09.2026',
        ]));

        $this->assertSame('Neu', $e->fresh()->first_name, 'Vorbedingung: es wurde geschrieben');
        $this->assertNull(Capsule::table('rec_employees')->where('id', $e->id)->value('zas_changed_at'));
    }

    public function test_probelauf_meldet_aenderungen_ohne_zu_schreiben(): void
    {
        $e = $this->makeEmployee();

        $report = $this->run_($this->row(['Vorname' => 'Neu', 'Strasse' => 'Neustrasse']), true);

        $this->assertSame('Alt', $e->fresh()->first_name);
        $this->assertSame('Altstrasse', $e->fresh()->street);
        $this->assertTrue($report['updated'][0]['would_update']);
        $this->assertContains('first_name', $report['updated'][0]['changed']);
        $this->assertContains('street', $report['updated'][0]['changed']);
    }

    public function test_steuer_id_mit_leerzeichen_ist_keine_aenderung_und_wird_bereinigt(): void
    {
        // Direktes Schreiben umgeht Eloquent — die Leerraum-Bereinigung
        // (Mutator, 23.09.2026) muss trotzdem greifen.
        $e = $this->makeEmployee('zas', ['steuer_id' => '12345678901']);

        $report = $this->run_($this->row(['SteuerID' => '12 345 678 901']));
        $this->assertSame('exists', $report['skipped'][0]['reason'] ?? null);

        $this->run_($this->row(['SteuerID' => '98 765 432 109']));
        $this->assertSame('98765432109', $e->fresh()->steuer_id);
    }

    public function test_eu_status_aus_gespeicherter_nation_wenn_zeile_keine_nation_hat(): void
    {
        $e = $this->makeEmployee('zas', ['nationality' => 'tr']);

        $this->run_($this->row(['AufenthaltGenehmigungErforderlich' => 'Nein']));

        $this->assertNull($e->fresh()->is_eu_citizen, 'Nein + tuerkische Staatsangehoerigkeit bleibt offen');
    }

    public function test_nachlauf_zaehlt_die_geaenderten_felder(): void
    {
        // Grundlage fuer die Entscheidung vor dem Einschalten: welche Felder
        // wuerden sich wie oft aendern — ohne Personendaten.
        $this->makeEmployee('zas', ['personnel_number' => 'RG1', 'street' => 'A']);
        $this->makeEmployee('zas', ['personnel_number' => 'RG2', 'street' => 'B']);
        $csv = "ZasPersonalNr;Status;Strasse;Vorname\nRG1;GO;Neu;Alt\nRG2;GO;Neu;Anders\n";

        $command = new \Platform\Recruiting\Console\Commands\ZasInboundReprocess(
            $this->importer(),
            new \Platform\Recruiting\Services\Zas\ZasInboundCsvParser(),
        );
        $summary = $command->reprocess(
            new \Platform\Recruiting\Models\RecZasInboundFile(['processed_at' => now()]),
            $csv,
            true,
            100,
        );

        $this->assertSame(['street' => 2, 'first_name' => 1], $summary['field_counts']);
    }

    public function test_unveraenderte_zeile_bleibt_uebersprungen(): void
    {
        $e = $this->makeEmployee();

        $report = $this->run_($this->row(['Vorname' => 'Alt', 'Strasse' => 'Altstrasse']));

        $this->assertSame([], $report['updated']);
        $this->assertSame('exists', $report['skipped'][0]['reason']);
    }
}
