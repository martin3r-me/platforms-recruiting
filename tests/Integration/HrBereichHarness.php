<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Welt fuer den HR-Bereich "Mitarbeiter" (Spec 2026-10-12): alles aus
 * VertragAusAkteHarness (ALLE Modul-Migrationen inkl. der beiden neuen,
 * Core-Extrafelder, url-Stub) plus Sitzung, zaehlender Cache, Log-Rekorder,
 * Auth mit Benutzer-ID, Pfeffer, schneller Hasher, CRM- und Hinweis-Attrappe.
 *
 * Log-Attrappe nach weltAufbauen() neu binden UND Facade-Instanzen leeren
 * (reference_log_facade_test_stub: Container::instance allein reicht nicht).
 */
trait HrBereichHarness
{
    use VertragAusAkteHarness;

    protected Store $sitzung;
    protected CacheRepository $cache;
    protected object $log;
    protected object $crmSync;
    protected object $hinweis;
    protected int $benutzerId = 42;
    private int $laufendeNummer = 1000;

    protected function hrWeltAufbauen(): void
    {
        $this->weltAufbauen();
        $c = Container::getInstance();

        $c->make('config')->set('recruiting.konto.pepper', 'pfeffer-fuer-den-test');
        $c->make('config')->set('recruiting.konto.weg4_aktiv', true);

        $this->log = new class {
            /** @var list<array{stufe:string, nachricht:string, daten:array}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = ['stufe' => $m, 'nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])];
            }
        };
        $c->instance('log', $this->log);

        $this->sitzung = new Store('test', new ArraySessionHandler(60));
        $c->instance('session', $this->sitzung);

        // Zaehlt many()/get(): Spec §3.2 verlangt EINE Cache-Abfrage fuer die Drossel.
        $this->cache = new CacheRepository(new class extends ArrayStore {
            public int $manyAufrufe = 0;
            public int $getAufrufe = 0;

            public function many(array $keys)
            {
                $this->manyAufrufe++;
                $vorher = $this->getAufrufe;
                $ergebnis = parent::many($keys);
                // parent::many() ruft intern get() je Schluessel: Zaehler zuruecksetzen,
                // damit many() als EINE Abfrage zaehlt.
                $this->getAufrufe = $vorher;

                return $ergebnis;
            }

            public function get($key)
            {
                $this->getAufrufe++;

                return parent::get($key);
            }
        });
        $c->instance('cache', $this->cache);
        $c->instance('hash', new BcryptHasher(['rounds' => 4]));

        $this->crmSync = new class extends ContactPhoneSync {
            /** @var list<int> */
            public array $nachgezogen = [];

            public function syncEmployee(RecEmployee $employee, bool $dryRun = false): array
            {
                $this->nachgezogen[] = (int) $employee->id;

                return ['status' => 'synced', 'contacts' => 1];
            }
        };
        $c->instance(ContactPhoneSync::class, $this->crmSync);

        // NummernwechselHinweisSender ist final — Attrappe ohne Vererbung,
        // aufgerufen wird er untypisiert ueber app().
        $this->hinweis = new class {
            /** @var list<array{person:int, alt:string}> */
            public array $gesendet = [];
            public string $status = NummernwechselHinweisSender::STATUS_SENT;

            public function sende(int $personId, string $alteNummer): string
            {
                $this->gesendet[] = ['person' => $personId, 'alt' => $alteNummer];

                return $this->status;
            }
        };
        $c->instance(NummernwechselHinweisSender::class, $this->hinweis);

        $this->authAls($this->team);
        Facade::clearResolvedInstances();
        Carbon::setTestNow('2026-10-12 10:00:00');
    }

    protected function hrWeltAbbauen(): void
    {
        $c = Container::getInstance();
        foreach (['session', 'cache', 'hash', ContactPhoneSync::class, NummernwechselHinweisSender::class, AuthFactory::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->weltAbbauen();
    }

    /** Handelt ab jetzt als HR-Benutzer $benutzerId im Team $teamId. */
    protected function authAls(int $teamId): void
    {
        $stub = new class($teamId, $this->benutzerId) implements AuthFactory {
            public function __construct(private int $teamId, private int $benutzerId) {}
            public function guard($name = null) { return $this; }
            public function user(): object { return (object) ['id' => $this->benutzerId, 'currentTeam' => (object) ['id' => $this->teamId]]; }
            public function check() { return true; }
            public function id() { return $this->benutzerId; }
            public function shouldUse($name) {}
        };
        Container::getInstance()->instance(AuthFactory::class, $stub);
        Facade::clearResolvedInstance('auth');
    }

    /** Eine Anstellung im Team (Vorgabe: MA, aktiv, im neuen Portal). */
    protected function akte(array $set = []): RecEmployee
    {
        $this->laufendeNummer++;

        return $this->anstellung(array_merge([
            'personnel_number' => 'MA' . $this->laufendeNummer,
            'portal_token'     => 'tok-' . $this->laufendeNummer,
            'phone'            => '+4915100' . $this->laufendeNummer,
            'portal_v2_since'  => '2026-10-01 08:00:00',
        ], $set));
    }

    /** Personen-Zeile + Verknuepfung, Query Builder wie PersonLinker. */
    protected function person(array $set, RecEmployee ...$akten): int
    {
        $id = (int) DB::table('rec_persons')->insertGetId(array_merge([
            'uuid'       => 'p-' . bin2hex(random_bytes(6)),
            'team_id'    => $this->team,
            'phone'      => $akten[0]->phone ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $set));
        if ($akten !== []) {
            DB::table('rec_employees')
                ->whereIn('id', array_map(fn (RecEmployee $a) => (int) $a->id, $akten))
                ->update(['rec_person_id' => $id]);
        }

        return $id;
    }

    protected function personZeile(int $id): object
    {
        return DB::table('rec_persons')->where('id', $id)->first();
    }

    protected function portalAuth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    /** @return list<array{stufe:string, nachricht:string, daten:array}> */
    protected function logZeilen(string $nachricht): array
    {
        return array_values(array_filter($this->log->zeilen, fn (array $z) => $z['nachricht'] === $nachricht));
    }

    protected function vermerke(): Collection
    {
        return DB::table('rec_hr_vermerke')->orderBy('id')->get();
    }

    /**
     * Weg 4 ganz an: Server-Riegel UND Team-Schalter (Spec HR-Bereich §6).
     * Der Stundenlauf (wendeNummernwechselAn) fragt beides in der gesperrten
     * Lesung — wer "der Stundenlauf war schneller" nachstellt, braucht es an.
     */
    protected function notfallwegAn(?int $teamId = null): void
    {
        Container::getInstance()->make('config')->set('recruiting.konto.weg4_aktiv', true);
        $this->einstellung('konto_notfall_weg_aktiv', true, $teamId);
    }

    protected function einstellung(string $key, mixed $wert, ?int $teamId = null): void
    {
        $teamId ??= $this->team;
        $zeile = DB::table('rec_applicant_settings')->where('team_id', $teamId)->first();
        $settings = $zeile ? (array) json_decode((string) $zeile->settings, true) : [];
        $settings[$key] = $wert;
        if ($zeile) {
            DB::table('rec_applicant_settings')->where('team_id', $teamId)->update(['settings' => json_encode($settings)]);

            return;
        }
        DB::table('rec_applicant_settings')->insert([
            'team_id' => $teamId, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
