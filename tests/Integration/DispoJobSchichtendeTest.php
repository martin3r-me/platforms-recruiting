<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\DispoZusagenEntdoppelnCommand;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasAbgleich;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasStand;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusageVerschieben;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZusage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Markus 06.11. (Anfrage 16): 17–23 und 17–03 derselben VA galten als eine
 * Schicht — eine Zusage landete auf beiden, die Seite zeigte nur eine.
 * Dazu: Verschieben einer Zusage, Abgleich mit ZAS nach Schicht, Bereinigung.
 */
final class DispoJobSchichtendeTest extends TestCase
{
    private const TEAM = 7;

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
        Facade::setFacadeApplication($container);
        // inbound_team_id 0: Identitaets-Gruppe = nur die Person selbst (fail-closed), reicht hier.
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => 0]]]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_assignments', 'rec_dispo_events', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function tag(): string
    {
        return now()->addDays(7)->toDateString();
    }

    private function anfrage(): RecDispoJobRequest
    {
        return RecDispoJobRequest::create([
            'team_id' => self::TEAM, 'filial_nr' => 100, 'zeitraum_von' => self::tag(), 'zeitraum_bis' => self::tag(),
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'anlass' => 'naechste_woche', 'status' => RecDispoJobRequest::STATUS_LAEUFT,
        ]);
    }

    private function schicht(RecDispoJobRequest $r, string $von, string $bis, int $event = 1750, ?string $datum = null, int $gesucht = 30, ?string $posten = null): RecDispoJobShift
    {
        static $n = 0;
        $n++;

        return $r->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum ?? self::tag(), 'von' => $von, 'bis' => $bis, 'taetigkeit' => 'Servicekräfte', 'gesucht' => $gesucht, 'dispoposten_id' => $posten ?? ('1251' . $n)]);
    }

    private function person(string $pnr = 'RG1'): RecEmployee
    {
        return RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Anne', 'last_name' => 'Manga', 'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true]);
    }

    /** @param list<RecDispoJobShift> $schichten @return array<int, RecDispoJobOfferShift> shift_id => Zeile */
    private function angebot(RecDispoJobRequest $r, RecEmployee $e, array $schichten): array
    {
        $wave = $r->waves()->firstOrCreate(['nr' => 1], ['ma_bloecke' => [100], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false]);
        $offer = RecDispoJobOffer::create(['wave_id' => $wave->id, 'request_id' => $r->id, 'rec_employee_id' => $e->id, 'anchor_employee_id' => $e->id, 'sent_at' => now()]);
        $out = [];
        foreach ($schichten as $s) {
            $out[(int) $s->id] = $offer->shifts()->create(['shift_id' => $s->id]);
        }

        return $out;
    }

    private function einbuchung(RecEmployee $e, string $von, string $bis, int $event = 1750): RecDispoAssignment
    {
        static $n = 0;
        $n++;

        return RecDispoAssignment::create([
            'ds_ref' => 'DS' . $n, 'rec_dispo_event_id' => $event, 'pnr_raw' => $e->personnel_number, 'rec_employee_id' => $e->id,
            'datum' => self::tag(), 'von' => $von, 'bis' => $bis, 'taetigkeit' => 'Servicekräfte', 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);
    }

    // ---------------------------------------------------------------- Zusage

    public function test_zusage_auf_17_23_landet_nicht_auf_17_03(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s03 = $this->schicht($r, '17:00', '03:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s03]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os[$s23->id]->id, [$e->id]));

        $this->assertNotNull($os[$s23->id]->fresh()->accepted_at);
        $this->assertNull($os[$s03->id]->fresh()->accepted_at, 'anderes Ende = andere Schicht, keine Mit-Zusage');
        $this->assertSame('tag_belegt', JobOffersZusage::ausfuehren($os[$s03->id]->id, [$e->id]), 'einmal pro Tag gilt weiter');
    }

    public function test_dieselbe_schicht_in_zwei_anfragen_wird_weiterhin_mitzugesagt(): void
    {
        $r1 = $this->anfrage();
        $r2 = $this->anfrage();
        $a = $this->schicht($r1, '17:00', '23:00');
        $b = $this->schicht($r2, '17:00', '23:00');
        $e = $this->person();
        $osA = $this->angebot($r1, $e, [$a])[$a->id];
        $osB = $this->angebot($r2, $e, [$b])[$b->id];

        $this->assertSame('ok', JobOffersZusage::ausfuehren($osA->id, [$e->id]));

        $this->assertNotNull($osB->fresh()->accepted_at, 'Spec §9: gleiche Schicht (inkl. Ende) aus anderer Anfrage sagt mit zu');
    }

    // -------------------------------------------------------------- Abgleich

    public function test_abgleich_verknuepft_nur_die_schicht_mit_passender_zeit(): void
    {
        $r = $this->anfrage();
        $s = $this->schicht($r, '17:30', '04:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s])[$s->id];
        $os->update(['accepted_at' => now()]);

        $this->einbuchung($e, '17:00', '03:00');                 // Alsaghir-Fall: in ZAS auf 17–03
        (new DispoJobZasAbgleich())->run();
        $this->assertNull($os->fresh()->zas_assignment_id, 'andere Zeit = nicht diese Schicht');

        $passt = $this->einbuchung($e, '17:30', '04:00');
        (new DispoJobZasAbgleich())->run();
        $this->assertSame($passt->id, $os->fresh()->zas_assignment_id);
    }

    public function test_abgleich_ohne_zeiten_auf_einer_seite_matcht_wie_bisher(): void
    {
        $r = $this->anfrage();
        $s = $r->shifts()->create(['rec_dispo_event_id' => 1750, 'datum' => self::tag(), 'von' => '17:00', 'taetigkeit' => 'Servicekräfte', 'gesucht' => 5]);
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s])[$s->id];
        $os->update(['accepted_at' => now()]);
        $a = RecDispoAssignment::create(['ds_ref' => 'DSX', 'rec_dispo_event_id' => 1750, 'pnr_raw' => 'RG1', 'rec_employee_id' => $e->id, 'datum' => self::tag(), 'status_id' => RecDispoAssignment::STATUS_AUFTRAG]);

        (new DispoJobZasAbgleich())->run();

        $this->assertSame($a->id, $os->fresh()->zas_assignment_id);
        $this->assertSame('17:00', DispoJobZasAbgleich::uhr('17:00:00'));
        $this->assertSame('07:00', DispoJobZasAbgleich::uhr('7:00'));
        $this->assertSame('', DispoJobZasAbgleich::uhr(null));
    }

    public function test_zas_stand_nennt_die_andere_schicht(): void
    {
        $r = $this->anfrage();
        $s04 = $this->schicht($r, '17:30', '04:00');
        $s03 = $this->schicht($r, '17:00', '03:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s04, $s03]);
        $os[$s04->id]->update(['accepted_at' => now()]);
        $os[$s03->id]->update(['accepted_at' => now()]);
        $this->einbuchung($e, '17:00', '03:00');

        $zeilen = RecDispoJobOfferShift::query()->with('offer')->whereIn('id', [$os[$s04->id]->id, $os[$s03->id]->id])->get();
        $stand = DispoJobZasStand::andereSchicht($zeilen, $r->shifts()->get());

        $this->assertSame([$os[$s04->id]->id => '17:00–03:00'], $stand, 'nur die Zeile, deren Schicht ZAS anders fuehrt');
    }

    public function test_zas_stand_auch_bei_altem_falsch_verknuepftem_haken(): void
    {
        $r = $this->anfrage();
        $s04 = $this->schicht($r, '17:30', '04:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s04])[$s04->id];
        $alt = $this->einbuchung($e, '17:00', '03:00');
        $os->update(['accepted_at' => now(), 'zas_assignment_id' => $alt->id]); // Stand vor dem Fix

        $stand = DispoJobZasStand::andereSchicht(RecDispoJobOfferShift::query()->with('offer')->whereKey($os->id)->get(), $r->shifts()->get());

        $this->assertSame([$os->id => '17:00–03:00'], $stand);
    }

    public function test_zas_stand_schweigt_wenn_die_person_auf_dieser_schicht_steht_trotz_alter_verknuepfung(): void
    {
        $r = $this->anfrage();
        $s04 = $this->schicht($r, '17:30', '04:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s04])[$s04->id];
        $alt = $this->einbuchung($e, '17:00', '03:00');
        $this->einbuchung($e, '17:30', '04:00');                   // Sheran hat in ZAS nachgebucht
        $os->update(['accepted_at' => now(), 'zas_assignment_id' => $alt->id]);

        $stand = DispoJobZasStand::andereSchicht(RecDispoJobOfferShift::query()->with('offer')->whereKey($os->id)->get(), $r->shifts()->get());

        $this->assertSame([], $stand, 'passende Einbuchung gewinnt gegen die alte Verknuepfung');
    }

    // ----------------------------------------------------------- Verschieben

    public function test_verschieben_nimmt_die_zusage_mit_ihrem_zeitpunkt_mit(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s01 = $this->schicht($r, '16:00', '01:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s01]);
        $zeit = now()->subHours(3);
        $os[$s23->id]->update(['accepted_at' => $zeit]);

        $this->assertSame(DispoJobZusageVerschieben::OK, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id));

        $quelle = $os[$s23->id]->fresh();
        $ziel = $os[$s01->id]->fresh();
        $this->assertNull($quelle->accepted_at);
        $this->assertSame($zeit->format('Y-m-d H:i:s'), $ziel->accepted_at->format('Y-m-d H:i:s'), 'Zeitpunkt der Zusage wandert mit');
        $this->assertNull($ziel->zas_entered_at);

        $this->assertSame(DispoJobZusageVerschieben::WEG, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id), 'Quelle ist keine Zusage mehr');
    }

    /** Review 06.11. Fund 6: Hand-Haken heisst „steht in ZAS“ — dann nur dort umbuchen. */
    public function test_verschieben_verweigert_bei_hand_haken(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s01 = $this->schicht($r, '16:00', '01:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s01]);
        $os[$s23->id]->update(['accepted_at' => now(), 'zas_entered_at' => now(), 'zas_entered_by_user_id' => 9]);

        $this->assertSame(DispoJobZusageVerschieben::IN_ZAS, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id));
        $this->assertNotNull($os[$s23->id]->fresh()->accepted_at);
        $this->assertNotNull($os[$s23->id]->fresh()->zas_entered_at, 'Haken bleibt');
    }

    /** Review 06.11. Fund 7: eine Absage auf der Zielschicht wird nicht ueberschrieben. */
    public function test_verschieben_verweigert_auf_abgesagte_zielschicht(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s01 = $this->schicht($r, '16:00', '01:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s01]);
        $os[$s23->id]->update(['accepted_at' => now()]);
        $os[$s01->id]->update(['declined_at' => now(), 'declined_reason' => 'krank']);

        $this->assertSame(DispoJobZusageVerschieben::ABGESAGT, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id));
        $this->assertSame('krank', $os[$s01->id]->fresh()->declined_reason);
        $this->assertNotNull($os[$s23->id]->fresh()->accepted_at);
    }

    /** Review 06.11. Fund 1: Mit-Zusage (§9) in einer anderen Anfrage bleibt sonst als zweite Zusage am Tag stehen. */
    public function test_verschieben_verweigert_wenn_dieselbe_schicht_in_anderer_anfrage_mitzugesagt_ist(): void
    {
        $r1 = $this->anfrage();
        $r2 = $this->anfrage();
        $a23 = $this->schicht($r1, '17:00', '23:00');
        $a01 = $this->schicht($r1, '16:00', '01:00');
        $b23 = $this->schicht($r2, '17:00', '23:00');
        $e = $this->person();
        $osA = $this->angebot($r1, $e, [$a23, $a01]);
        $osB = $this->angebot($r2, $e, [$b23])[$b23->id];

        $this->assertSame('ok', JobOffersZusage::ausfuehren($osA[$a23->id]->id, [$e->id]));
        $this->assertNotNull($osB->fresh()->accepted_at, 'Schwester mitzugesagt');

        $this->assertSame(DispoJobZusageVerschieben::SCHWESTER, DispoJobZusageVerschieben::ausfuehren($r1, $osA[$a23->id]->id, $a01->id));
        $this->assertNotNull($osA[$a23->id]->fresh()->accepted_at, 'nichts veraendert');

        $r2->update(['status' => 'abgeschlossen', 'closed_at' => now()]);
        $this->assertSame(DispoJobZusageVerschieben::OK, DispoJobZusageVerschieben::ausfuehren($r1, $osA[$a23->id]->id, $a01->id), 'abgeschlossene Anfrage zaehlt nicht mehr');
    }

    /** Review 06.11. Fund 2: Modus „zuerst“ — Ziel schliesst bei voll, Quelle oeffnet wieder; volle Ziele werden verweigert. */
    public function test_verschieben_zieht_die_vollzaehlung_im_modus_zuerst_nach(): void
    {
        $r = $this->anfrage();
        $r->update(['modus' => RecDispoJobRequest::MODUS_ZUERST]);
        $r = $r->fresh();
        $s23 = $this->schicht($r, '17:00', '23:00', gesucht: 1);
        $s01 = $this->schicht($r, '16:00', '01:00', gesucht: 1);
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s01]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os[$s23->id]->id, [$e->id]));
        $this->assertNotNull($s23->fresh()->closed_at, 'gesucht 1 erreicht: zu');

        $this->assertSame(DispoJobZusageVerschieben::OK, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id));
        $this->assertNull($s23->fresh()->closed_at, 'Platz frei: Quelle wieder offen');
        $this->assertNotNull($s01->fresh()->closed_at, 'Ziel voll: zu');

        $zweite = $this->person('RG2');
        $os2 = $this->angebot($r, $zweite, [$s23, $s01]);
        $os2[$s23->id]->update(['accepted_at' => now()]);
        $this->assertSame(DispoJobZusageVerschieben::VOLL, DispoJobZusageVerschieben::ausfuehren($r, $os2[$s23->id]->id, $s01->id), 'volles Ziel wird nicht ueberbucht');

        $s01->update(['closed_at' => null, 'gedeckt_manuell_at' => now()]);
        $this->assertSame(DispoJobZusageVerschieben::VOLL, DispoJobZusageVerschieben::ausfuehren($r, $os2[$s23->id]->id, $s01->id), 'von Hand gedeckt ebenso');
    }

    /** Review 06.11. Fund 3: Posten mit mehreren Zeitvarianten — ZAS bucht in Variante 2, die Schicht traegt Variante 1. */
    public function test_abgleich_erkennt_jede_zeitvariante_des_postens(): void
    {
        $r = $this->anfrage();
        $s = $this->schicht($r, '08:30', '16:45', posten: '120988');
        \Platform\Recruiting\Models\RecDispoBedarf::create([
            'rec_dispo_event_id' => 1750, 'dispoposten_id' => '120988', 'datum' => self::tag(), 'von' => '08:30', 'bis' => '16:45',
            'zeiten' => [['von' => '08:30', 'bis' => '16:45'], ['von' => '08:45', 'bis' => '16:45']], 'taetigkeit' => 'Servicekräfte', 'anzahl' => 2, 'status_id' => 1,
        ]);
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s])[$s->id];
        $os->update(['accepted_at' => now()]);
        $variante2 = $this->einbuchung($e, '08:45', '16:45');

        (new DispoJobZasAbgleich())->run();

        $this->assertSame($variante2->id, $os->fresh()->zas_assignment_id);
        $stand = DispoJobZasStand::andereSchicht(RecDispoJobOfferShift::query()->with('offer')->whereKey($os->id)->get(), $r->shifts()->get());
        $this->assertSame([], $stand, 'Variante des Postens ist dieselbe Schicht');
    }

    public function test_verschieben_legt_die_angebotszeile_an_wenn_die_person_die_zielschicht_nicht_hatte(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s01 = $this->schicht($r, '16:00', '01:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23])[$s23->id];
        $os->update(['accepted_at' => now()]);

        $this->assertSame(DispoJobZusageVerschieben::OK, DispoJobZusageVerschieben::ausfuehren($r, $os->id, $s01->id));

        $neu = RecDispoJobOfferShift::query()->where('offer_id', $os->offer_id)->where('shift_id', $s01->id)->first();
        $this->assertNotNull($neu);
        $this->assertNotNull($neu->accepted_at);
    }

    public function test_verschieben_verweigert_in_zas_fremden_tag_und_schon_zugesagt(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s01 = $this->schicht($r, '16:00', '01:00');
        $anderer = $this->schicht($r, '10:00', '19:00', datum: now()->addDays(8)->toDateString());
        $fremdeVa = $this->schicht($r, '10:00', '19:00', event: 1751);
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s01, $anderer, $fremdeVa]);
        $os[$s23->id]->update(['accepted_at' => now()]);

        $this->assertSame(DispoJobZusageVerschieben::ZIEL, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $anderer->id));
        $this->assertSame(DispoJobZusageVerschieben::ZIEL, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $fremdeVa->id));
        $this->assertSame(DispoJobZusageVerschieben::ZIEL, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s23->id), 'auf sich selbst');

        $os[$s01->id]->update(['accepted_at' => now()]);
        $this->assertSame(DispoJobZusageVerschieben::SCHON, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id));
        $os[$s01->id]->update(['accepted_at' => null]);

        $os[$s23->id]->update(['zas_exported_at' => now()]);
        $this->assertSame(DispoJobZusageVerschieben::IN_ZAS, DispoJobZusageVerschieben::ausfuehren($r, $os[$s23->id]->id, $s01->id), 'an ZAS uebergeben: nur dort umbuchen');
        $this->assertNotNull($os[$s23->id]->fresh()->accepted_at, 'nichts veraendert');

        // Fremde Anfrage: Zeile gehoert nicht dazu.
        $fremd = $this->anfrage();
        $this->assertSame(DispoJobZusageVerschieben::WEG, DispoJobZusageVerschieben::ausfuehren($fremd, $os[$s23->id]->id, $s01->id));

        $ziele = DispoJobZusageVerschieben::ziele($s23, $r->shifts()->get());
        $this->assertSame([$s01->id], array_column($ziele, 'id'), 'nur dieselbe VA am selben Tag');
        $this->assertSame('16:00–01:00 Servicekräfte', $ziele[0]['label']);
    }

    // ------------------------------------------------------------ Bereinigung

    /** @return array{0:int, 1:string} */
    private function befehl(array $args): array
    {
        $cmd = new class extends DispoZusagenEntdoppelnCommand {
            public function einrichten(ArrayInput $in, OutputStyle $out): void
            {
                $this->input = $in;
                $this->output = $out;
            }
        };
        $in = new ArrayInput($args, $cmd->getDefinition());
        $buffer = new BufferedOutput();
        $cmd->einrichten($in, new OutputStyle($in, $buffer));

        return [$cmd->handle(), $buffer->fetch()];
    }

    public function test_entdoppeln_behaelt_die_zeile_in_zas_sonst_die_gewaehlte_schicht(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s03 = $this->schicht($r, '17:00', '03:00');
        $s01 = $this->schicht($r, '16:00', '01:00');

        $inZas = $this->person('RG1');       // Sheran hat sie in ZAS auf 17–03 eingetragen
        $a = $this->angebot($r, $inZas, [$s23, $s03]);
        $a[$s23->id]->update(['accepted_at' => now()]);
        $a[$s03->id]->update(['accepted_at' => now(), 'zas_entered_at' => now()]);

        $nurLink = $this->person('RG2');     // hat nur „17–23“ gesehen
        $b = $this->angebot($r, $nurLink, [$s23, $s03]);
        $b[$s23->id]->update(['accepted_at' => now()]);
        $b[$s03->id]->update(['accepted_at' => now()]);

        $einfach = $this->person('RG3');     // echte zweite Zusage an anderem Beginn: kein Doppel
        $c = $this->angebot($r, $einfach, [$s23, $s01]);
        $c[$s23->id]->update(['accepted_at' => now()]);
        $c[$s01->id]->update(['accepted_at' => now()]);

        [$code, $out] = $this->befehl(['requestId' => (string) $r->id, '--behalten' => (string) $s23->id]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 Zusagen zu entfernen, 0 Personen ohne Entscheidung', $out);
        $this->assertStringContainsString('Vorschau', $out);
        $this->assertNotNull($a[$s23->id]->fresh()->accepted_at, 'Vorschau schreibt nichts');

        [$code, $out] = $this->befehl(['requestId' => (string) $r->id, '--behalten' => (string) $s23->id, '--ausfuehren' => true]);
        $this->assertSame(0, $code);
        $this->assertNull($a[$s23->id]->fresh()->accepted_at, 'in ZAS ist 17–03, also faellt 17–23');
        $this->assertNotNull($a[$s03->id]->fresh()->accepted_at);
        $this->assertNotNull($b[$s23->id]->fresh()->accepted_at, 'ohne ZAS bleibt --behalten');
        $this->assertNull($b[$s03->id]->fresh()->accepted_at);
        $this->assertNotNull($c[$s23->id]->fresh()->accepted_at, 'anderer Beginn: kein Doppel');
        $this->assertNotNull($c[$s01->id]->fresh()->accepted_at);
    }

    /** Prod 06.11.: beide Zeilen an derselben ZAS-Buchung (alter Abgleich nach Tag) — nur die passende bleibt. */
    public function test_entdoppeln_loest_die_falsche_verknuepfung_wenn_beide_zeilen_auf_dieselbe_buchung_zeigen(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s03 = $this->schicht($r, '17:00', '03:00');
        $e = $this->person();
        $buchung = $this->einbuchung($e, '17:00', '23:00');
        $os = $this->angebot($r, $e, [$s23, $s03]);
        $os[$s23->id]->update(['accepted_at' => now(), 'zas_entered_at' => now(), 'zas_assignment_id' => $buchung->id]);
        $os[$s03->id]->update(['accepted_at' => now(), 'zas_assignment_id' => $buchung->id]);

        [$code, $out] = $this->befehl(['requestId' => (string) $r->id, '--ausfuehren' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 Zusagen entfernt', $out);
        $falsch = $os[$s03->id]->fresh();
        $this->assertNull($falsch->accepted_at);
        $this->assertNull($falsch->zas_assignment_id, 'Verknuepfung mit geloest');
        $richtig = $os[$s23->id]->fresh();
        $this->assertNotNull($richtig->accepted_at);
        $this->assertSame($buchung->id, $richtig->zas_assignment_id);
    }

    public function test_entdoppeln_ohne_entscheidung_laesst_alles_stehen(): void
    {
        $r = $this->anfrage();
        $s23 = $this->schicht($r, '17:00', '23:00');
        $s03 = $this->schicht($r, '17:00', '03:00');
        $e = $this->person();
        $os = $this->angebot($r, $e, [$s23, $s03]);
        $os[$s23->id]->update(['accepted_at' => now()]);
        $os[$s03->id]->update(['accepted_at' => now()]);

        [$code, $out] = $this->befehl(['requestId' => (string) $r->id, '--ausfuehren' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 Personen ohne Entscheidung', $out);
        $this->assertNotNull($os[$s23->id]->fresh()->accepted_at);
        $this->assertNotNull($os[$s03->id]->fresh()->accepted_at);

        [$code] = $this->befehl(['requestId' => (string) $r->id, '--behalten' => '999999']);
        $this->assertSame(1, $code, 'fremde Schicht als --behalten wird abgelehnt');
    }
}
