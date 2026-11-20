<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAbsagen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobGedeckt;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusageAustragen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZusage;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchonZugesagt;

/**
 * Zusage austragen (Spec 2026-11-20, Markus 20.11.2026 „so bitte umsetzen“):
 * Platz wird frei, Person bekommt die Schicht nicht noch einmal, zaehlt als
 * Absage „ausgetragen durch Dispo“, Schwestern kommen mit, rueckgaengig nur
 * solange der Platz frei ist.
 */
final class DispoJobZusageAustragenTest extends TestCase
{
    private static int $mitarbeiter = 100;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php',
            'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php',
            'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php',
            'database/migrations/2026_10_29_000002_add_dispoposten_id_to_rec_dispo_job_shifts.php',
            'database/migrations/2026_10_30_000001_add_zas_exported_at_to_rec_dispo_job_offer_shifts.php',
            'database/migrations/2026_11_20_000001_add_ausgetragen_to_rec_dispo_job_offer_shifts.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
        // Nur fuer die Namen in der Absagen-Liste je Schicht.
        $capsule->getConnection()->getSchemaBuilder()->create('rec_employees', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    private function anfrage(string $modus = RecDispoJobRequest::MODUS_ZUERST, int $gesucht = 1, int $event = 1): array
    {
        $r = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => now()->toDateString(), 'zeitraum_bis' => now()->addDays(30)->toDateString(),
            'modus' => $modus, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $s = $r->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => now()->addDays(3)->toDateString(), 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Service', 'gesucht' => $gesucht]);

        return [$r, $s];
    }

    private function zusage(RecDispoJobRequest $r, RecDispoJobShift $s, ?int $ma = null, array $mehr = []): RecDispoJobOfferShift
    {
        $ma ??= ++self::$mitarbeiter;
        $offer = RecDispoJobOffer::create(['wave_id' => 1, 'request_id' => $r->id, 'rec_employee_id' => $ma, 'anchor_employee_id' => $ma, 'sent_at' => now()]);

        return RecDispoJobOfferShift::create($mehr + ['offer_id' => $offer->id, 'shift_id' => $s->id, 'accepted_at' => now()->subHour()]);
    }

    public function test_austragen_macht_platz_frei_und_sperrt_erneute_zusage(): void
    {
        [$r, $s] = $this->anfrage();
        $z = $this->zusage($r, $s);
        $ma = (int) $z->offer->rec_employee_id;
        DispoJobGedeckt::nachzaehlen($r, $s->fresh());
        $this->assertNotNull($s->fresh()->closed_at, 'zuerst: 1 von 1 → voll');
        $this->assertSame([], DispoJobGedeckt::offeneSchichten($r));

        $erg = DispoJobZusageAustragen::ausfuehren($r, (int) $z->id, 7);

        $this->assertSame(DispoJobZusageAustragen::OK, $erg['ergebnis']);
        $this->assertFalse($erg['in_zas']);
        $z->refresh();
        $this->assertNull($z->accepted_at);
        $this->assertNotNull($z->ausgetragen_zusage_at);
        $this->assertSame(DispoJobZusageAustragen::GRUND, $z->declined_reason);
        $this->assertSame(7, $z->ausgetragen_by_user_id);
        $this->assertNull($s->fresh()->closed_at, 'Platz wieder frei');
        $this->assertCount(1, DispoJobGedeckt::offeneSchichten($r), 'naechste Welle bietet ihn wieder an');

        $this->assertSame('weg', JobOffersZusage::ausfuehren((int) $z->id, [$ma]), 'nicht noch einmal zusagbar');
        $this->assertNull($z->fresh()->accepted_at);

        $this->assertSame(1, DispoJobAbsagen::anzahl([$ma], DispoJobAbsagen::seit()), 'zaehlt im Absagen-Zaehler');
        $this->assertSame('ausgetragen durch Dispo', DispoJobAbsagen::grundLabel($z->declined_reason));
        $this->assertSame('krank', DispoJobAbsagen::grundLabel('krank'));
        $jeSchicht = DispoJobAbsagen::jeSchichten([(int) $s->id]);
        $this->assertTrue($jeSchicht[(int) $s->id][0]['ausgetragen']);

        $this->assertSame(DispoJobZusageAustragen::WEG, DispoJobZusageAustragen::ausfuehren($r, (int) $z->id, 7)['ergebnis'], 'zweimal geht nicht');
    }

    public function test_fremde_anfrage_und_kann_nicht_zeilen_bleiben_unberuehrt(): void
    {
        [$r, $s] = $this->anfrage();
        [$fremd] = $this->anfrage();
        $z = $this->zusage($r, $s);
        $this->assertSame(DispoJobZusageAustragen::WEG, DispoJobZusageAustragen::ausfuehren($fremd, (int) $z->id, 1)['ergebnis']);
        $this->assertNotNull($z->fresh()->accepted_at);

        $kannNicht = $this->zusage($r, $s, null, ['accepted_at' => null, 'declined_at' => now(), 'declined_reason' => 'krank']);
        $this->assertSame(DispoJobZusageAustragen::WEG, DispoJobZusageAustragen::zuruecknehmen($r, (int) $kannNicht->id), '„Kann nicht“ ist keine Austragung');
        $this->assertNull($kannNicht->fresh()->accepted_at);
    }

    public function test_in_zas_hinweis_und_zeile_zaehlt_nicht_mehr_als_zusage(): void
    {
        [$r, $s] = $this->anfrage();
        $z = $this->zusage($r, $s, null, ['zas_exported_at' => now()->subMinutes(5), 'zas_entered_at' => now()->subMinutes(5)]);
        $erg = DispoJobZusageAustragen::ausfuehren($r, (int) $z->id, 1);
        $this->assertTrue($erg['in_zas'], 'Hinweis: in ZAS auch austragen');

        $offen = RecDispoJobOfferShift::query()->whereNotNull('accepted_at')->whereNull('declined_at')->whereKey($z->id)->exists();
        $this->assertFalse($offen, 'Export und Zaehler sehen nur accepted_at — die Zusage ist dort weg');
    }

    public function test_schwester_in_anderer_laufender_anfrage_wird_mit_ausgetragen_und_kommt_mit_zurueck(): void
    {
        [$a, $sa] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 2, 5);
        [$b, $sb] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 2, 5);
        $ma = ++self::$mitarbeiter;
        $za = $this->zusage($a, $sa, $ma);
        $zb = $this->zusage($b, $sb, $ma);

        $erg = DispoJobZusageAustragen::ausfuehren($a, (int) $za->id, 1);
        $this->assertSame(2, $erg['anzahl']);
        $this->assertNull($zb->fresh()->accepted_at, 'sonst ginge die Buchung ueber die Schwester an ZAS');
        $this->assertSame(DispoJobZusageAustragen::GRUND, $zb->fresh()->declined_reason);

        $this->assertSame(DispoJobZusageAustragen::OK, DispoJobZusageAustragen::zuruecknehmen($a, (int) $za->id));
        $this->assertNotNull($za->fresh()->accepted_at);
        $this->assertNotNull($zb->fresh()->accepted_at, 'Schwester kommt mit zurueck');
        $this->assertNull($za->fresh()->declined_at);
        $this->assertNull($za->fresh()->ausgetragen_by_user_id);
        $this->assertSame(0, DispoJobAbsagen::anzahl([$ma], DispoJobAbsagen::seit()), 'rueckgaengig zaehlt nicht mehr');
    }

    public function test_rueckgaengig_nur_solange_platz_frei(): void
    {
        [$r, $s] = $this->anfrage(RecDispoJobRequest::MODUS_ZUERST, 1, 8);
        $z = $this->zusage($r, $s);
        DispoJobZusageAustragen::ausfuehren($r, (int) $z->id, 1);
        $nachfolger = $this->zusage($r, $s);
        DispoJobGedeckt::nachzaehlen($r, $s->fresh());

        $this->assertSame(DispoJobZusageAustragen::VOLL, DispoJobZusageAustragen::zuruecknehmen($r, (int) $z->id));
        $this->assertNull($z->fresh()->accepted_at);

        DispoJobZusageAustragen::ausfuehren($r, (int) $nachfolger->id, 1);
        DispoJobGedeckt::markieren($r, (int) $s->id);
        $this->assertSame(DispoJobZusageAustragen::VOLL, DispoJobZusageAustragen::zuruecknehmen($r, (int) $z->id), 'von Hand gedeckt');

        DispoJobGedeckt::oeffnen($r, (int) $s->id);
        $this->assertSame(DispoJobZusageAustragen::OK, DispoJobZusageAustragen::zuruecknehmen($r, (int) $z->id));
        $this->assertNotNull($s->fresh()->closed_at, 'zuerst: wieder voll');
    }

    public function test_rueckgaengig_scheitert_wenn_tag_inzwischen_anders_zugesagt(): void
    {
        [$r, $s] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 3, 9);
        $ma = ++self::$mitarbeiter;
        $z = $this->zusage($r, $s, $ma);
        DispoJobZusageAustragen::ausfuehren($r, (int) $z->id, 1);

        $andere = $r->shifts()->create(['rec_dispo_event_id' => 9, 'datum' => $s->datum->toDateString(), 'von' => '08:00', 'bis' => '12:00', 'taetigkeit' => 'Spüler', 'gesucht' => 3]);
        RecDispoJobOfferShift::create(['offer_id' => $z->offer_id, 'shift_id' => $andere->id, 'accepted_at' => now()]);

        $this->assertSame(DispoJobZusageAustragen::TAG, DispoJobZusageAustragen::zuruecknehmen($r, (int) $z->id));
    }

    public function test_wegen_absage_traegt_verknuepfte_zusagen_aus(): void
    {
        [$r, $s] = $this->anfrage(RecDispoJobRequest::MODUS_ZUERST, 2, 11);
        $verknuepft = $this->zusage($r, $s, null, ['zas_assignment_id' => 9001]);
        $andere = $this->zusage($r, $s, null, ['zas_assignment_id' => 9002]);

        $this->assertSame(1, DispoJobZusageAustragen::wegenAbsage([9001], 5));
        $this->assertNull($verknuepft->fresh()->accepted_at);
        $this->assertSame(DispoJobZusageAustragen::NOTIZ_ABSAGE, $verknuepft->fresh()->declined_note);
        $this->assertNotNull($andere->fresh()->accepted_at);
        $this->assertSame(0, DispoJobZusageAustragen::wegenAbsage([9001], 5), 'zweite Absage aendert nichts');
        $this->assertSame(0, DispoJobZusageAustragen::wegenAbsage([], 5));
    }

    /** Review 20.11. I1: scheitert die Hauptzeile, bleibt auch die Schwester ausgetragen. */
    public function test_rueckgaengig_alles_oder_nichts_mit_schwester(): void
    {
        [$a, $sa] = $this->anfrage(RecDispoJobRequest::MODUS_ZUERST, 1, 21);
        [$b, $sb] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 3, 21);
        $ma = ++self::$mitarbeiter;
        $za = $this->zusage($a, $sa, $ma);
        $zb = $this->zusage($b, $sb, $ma);
        DispoJobZusageAustragen::ausfuehren($a, (int) $za->id, 1);
        $this->zusage($a, $sa); // Q sagt in A zu → A wieder voll

        $this->assertSame(DispoJobZusageAustragen::VOLL, DispoJobZusageAustragen::zuruecknehmen($a, (int) $za->id));
        $this->assertNull($za->fresh()->accepted_at);
        $this->assertNull($zb->fresh()->accepted_at, 'Schwester darf nicht still zurueckkommen');
    }

    /** Review 20.11. I2: ausgetragen gilt ueber Anfragen hinweg — nicht zusagbar, nicht in Wellen. */
    public function test_ausgetragene_schicht_auch_aus_anderer_anfrage_nicht_zusagbar_und_nicht_im_versand(): void
    {
        [$a, $sa] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 3, 31);
        $ma = ++self::$mitarbeiter;
        $za = $this->zusage($a, $sa, $ma);
        DispoJobZusageAustragen::ausfuehren($a, (int) $za->id, 1);

        [$c, $sc] = $this->anfrage(RecDispoJobRequest::MODUS_SAMMELN, 3, 31);
        $neu = $this->zusage($c, $sc, $ma, ['accepted_at' => null]);
        $this->assertSame('weg', JobOffersZusage::ausfuehren((int) $neu->id, [$ma]));
        $this->assertNull($neu->fresh()->accepted_at);

        $versand = DispoJobSchonZugesagt::fuerVersand(3);
        $this->assertNotEmpty($versand[$ma] ?? [], 'Wellen ueberspringen die Person fuer diese Schicht');
        $this->assertArrayNotHasKey($ma, DispoJobSchonZugesagt::laden(3), 'die reine Zusagen-Liste bleibt unveraendert');

        $andererMa = ++self::$mitarbeiter;
        $offen = $this->zusage($c, $sc, $andererMa, ['accepted_at' => null]);
        $this->assertSame('ok', JobOffersZusage::ausfuehren((int) $offen->id, [$andererMa]), 'andere Personen sind nicht betroffen');
    }
}
