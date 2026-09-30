<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as RateLimiterWerk;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Drawer\Utils as LivewireUtils;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Livewire\Public\KontoAnmelden;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Comms\EinmalcodeSender;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Console\Commands\KontoZuruecksetzen;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Die Wege zurueck ins Konto (Spec §5, Canvas 1789) — was passiert, wenn
 * jemand seine Nummer verliert, sein Passwort vergisst, oder beides.
 *
 * WAS HIER FALSIFIZIERT WIRD, und warum jede Zusage ohne Test still bliebe:
 *
 *  1. DIE ZWEI-NACHWEIS-REGEL. Ein Code allein reicht nie. Der Beleg ist
 *     nicht, dass irgendwo eine Pruefung im Quelltext steht, sondern dass
 *     der richtige Code MIT falschem zweiten Nachweis nichts bewirkt — das
 *     Passwort bleibt, die Nummer bleibt.
 *  2. "PASSWORT VERGESSEN" ANTWORTET IMMER GLEICH. Nicht "aehnlich":
 *     zeichengleich. Geprueft wird deshalb das gerenderte Markup einer
 *     bekannten gegen das einer unbekannten Nummer, Zeichen fuer Zeichen.
 *     Eine Zusicherung auf einzelne Textbausteine haette die Stelle nicht
 *     gesehen, an der sich die beiden in einem Nebensatz unterscheiden.
 *  3. DIE EIGENE BREMSE (Auflage der Aufgabe-8-Pruefung). Die Drossel des
 *     Senders greift erst, wenn die Person GEFUNDEN ist; wer Nummern
 *     durchprobiert, laeuft nie in sie hinein. Geprueft wird deshalb der
 *     Angriff selbst: zwanzig unbekannte Nummern, danach geht auch fuer eine
 *     BEKANNTE nichts mehr raus.
 *  4. RULING GD-12. Die Bremse nimmt REMOTE_ADDR, nicht $request->ip().
 *     Damit dieser Unterschied im Test ueberhaupt HERSTELLBAR ist, traegt
 *     die Anfrage hier vertrauenswuerdige Vermittler ein — so wie der Wirt
 *     es mit trustProxies(at: '*') tut. Ohne das lieferte ip() brav
 *     REMOTE_ADDR, und der Test bliebe gruen, egal welche Quelle die Bremse
 *     nimmt.
 *  5. DIE ALTE NUMMER BEKOMMT IHREN HINWEIS — und er nennt die neue Nummer
 *     nicht. Wer das alte Geraet in der Hand haelt, soll nicht auch noch
 *     erfahren, wohin das Konto gewandert ist.
 *  6. JEDER WECHSEL WIRD PROTOKOLLIERT, ohne die Nummern im Klartext.
 *
 * Der Versand laeuft ueber den ECHTEN EinmalcodeSender gegen eine
 * Meta-Attrappe, die eine Liste genehmigter Vorlagen samt erwarteter
 * Parameternamen kennt und sonst mit status=failed antwortet — so wie Meta
 * es tut. Eine Attrappe, die jeden Vorlagennamen annimmt, prueft nichts
 * (Lehre aus Aufgabe 5).
 *
 * Schema und Aufbau von Hand, Vorbild KontoAnmeldenTest und
 * EinmalcodeSenderTest (Migrationen laufen in dieser Suite nicht) —
 * inklusive Log-Attrappe vor Facade::clearResolvedInstances().
 */
final class KontoZuruecksetzenTest extends TestCase
{
    private const TEAM = 3;

    /** Gregors Nummer in E.164 — so steht sie in rec_persons.phone. */
    private const NUMMER = '+4915111111111';

    /** Dieselbe Nummer, wie ein Mensch sie tippt. */
    private const NUMMER_GETIPPT = '0151 11111111';

    /** Die neue Nummer, auf die gewechselt wird. */
    private const NEUE_NUMMER = '+4917098765432';

    private const NEUE_NUMMER_GETIPPT = '0170 98765432';

    private const PASSWORT = 'ganz-geheim-2026';

    private const NEUES_PASSWORT = 'noch-viel-geheimer-2026';

    private const FALSCHES_PASSWORT = 'ganz-daneben-2026';

    private const GEBURT = '1995-03-14';

    private const FALSCHE_GEBURT = '1995-03-15';

    private const PFEFFER = 'pfeffer-fuer-den-test';

    private const VORLAGE_CODE = 'konto_einmalcode';

    private const VORLAGE_HINWEIS = 'konto_nummer_geaendert';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private const JETZT = '2026-09-29 12:00:00';

    /** Die Adresse, von der die Anfragen kommen. */
    private const IP = '203.0.113.7';

    private Capsule $capsule;

    private CacheRepository $cache;

    private Store $session;

    private ?Container $vorherigerContainer = null;

    private Container $container;

    private string $tmpDir;

    private ?Router $router = null;

    private int $personId;

    private int $anstellungId;

    /** @var object{zeilen: list<array{stufe: string, nachricht: string, daten: array}>} */
    private object $log;

    /** @var object{calls: list<array<string, mixed>>, genehmigt: array<string, list<string>>} */
    private object $meta;

    /** @var object{nachgezogen: list<array{id: int, phone: string}>} */
    private object $crmSync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorherigerContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden
        // (reference_log_facade_test_stub.md). Sie ist hier kein Beiwerk: an
        // ihr haengt die Zusicherung "jeder Wechsel wird protokolliert, ohne
        // die Nummer im Klartext".
        $this->log = new class {
            /** @var list<array{stufe: string, nachricht: string, daten: array}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = [
                    'stufe'     => $m,
                    'nachricht' => (string) ($a[0] ?? ''),
                    'daten'     => (array) ($a[1] ?? []),
                ];
            }
        };
        $container->instance('log', $this->log);

        $container->instance('config', new ConfigRepository([
            'recruiting' => [
                'konto' => [
                    'pepper'        => self::PFEFFER,
                    'code_vorlagen' => [
                        KontoWriter::ZWECK_ANMELDUNG => [
                            'name' => self::VORLAGE_CODE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                        KontoWriter::ZWECK_PASSWORT => [
                            'name' => self::VORLAGE_CODE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                        KontoWriter::ZWECK_NUMMERNWECHSEL => [
                            'name' => self::VORLAGE_CODE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                        KontoWriter::ZWECK_NOTFALL => [
                            'name' => self::VORLAGE_CODE, 'sprache' => 'de', 'platzhalter' => ['code'],
                        ],
                    ],
                    'hinweis_vorlage' => [
                        'name' => self::VORLAGE_HINWEIS, 'sprache' => 'de', 'platzhalter' => [],
                    ],
                ],
                // Der Team-Anker des DispoIdentityResolver — ohne ihn
                // gruppiert er fail closed gar nicht.
                'zas' => ['inbound_team_id' => self::TEAM],
            ],
            'livewire' => ['render_on_redirect' => false],
        ]));

        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);

        // Vier Runden statt zwoelf: bcrypt ist absichtlich langsam.
        $container->instance('hash', new BcryptHasher(['rounds' => 4]));

        // Livewires store() haengt an EINEM DataStore.
        $container->instance(DataStore::class, new DataStore());

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Carbon::setTestNow(Carbon::parse(self::JETZT));

        $this->session = new Store('test', new ArraySessionHandler(60));
        $container->instance('session', $this->session);

        // Die Attrappe zieht die Grenze des Wirts ein: dort faehrt
        // CACHE_STORE=database, und cache.key ist varchar(255) PRIMARY KEY.
        // Ein blanker ArrayStore schluckt jeden Schluessel klaglos — und eine
        // Attrappe, die grosszuegiger ist als der Wirt, prueft nichts.
        $speicher = new class extends ArrayStore {
            public function put($key, $value, $seconds): bool
            {
                if (strlen((string) $key) > 255) {
                    throw new \RuntimeException(
                        'SQLSTATE[22001]: Data too long for column key (' . strlen((string) $key) . ' Zeichen)',
                    );
                }

                return parent::put($key, $value, $seconds);
            }
        };
        $this->cache = new CacheRepository($speicher);
        $container->instance(RateLimiterWerk::class, new RateLimiterWerk($this->cache));

        // Der CRM-Abgleich nach einem Nummernwechsel greift auf CRM-Tabellen
        // zu, die es in dieser handgebauten Capsule nicht gibt.
        //
        // Die Attrappe MERKT SICH, welche Anstellungen nachgezogen wurden —
        // genau das ist die Zusicherung (Vorfall RG19734): ohne den Nachzug
        // behaelt der CRM-Kontakt die alte Nummer, und der naechste
        // Einmalcode geht ans ALTE Geraet. Eine Attrappe, die bloss "ja"
        // sagt, prueft das nicht.
        $this->crmSync = new class extends ContactPhoneSync {
            /** @var list<array{id: int, phone: string}> */
            public array $nachgezogen = [];

            public function syncEmployee(RecEmployee $employee, bool $dryRun = false): array
            {
                $this->nachgezogen[] = ['id' => (int) $employee->id, 'phone' => (string) $employee->phone];

                return ['status' => 'synced', 'contacts' => 1];
            }
        };
        $container->instance(ContactPhoneSync::class, $this->crmSync);

        $this->tabellen();
        $this->echteMigrationen();

        // Der ECHTE Beobachter, nicht seine Abwesenheit: sonst belegte ein
        // Marker-Test nur, dass es in diesem Lauf gar keinen gibt.
        RecEmployeeExportObserver::register();

        $this->kanalAufsetzen();

        $this->personId = $this->person(self::NUMMER, 'p-gregor');
        $this->anstellungId = $this->anstellung($this->personId, 'tok-gregor');

        $this->tmpDir = sys_get_temp_dir() . '/konto-zurueck-blade-' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->vorherigerContainer);
        $this->router = null;

        // setTrustedProxies ist PROZESSWEIT statisch. Bleibt der Eintrag
        // stehen, liest jede spaetere Testklasse ihre Adresse ploetzlich aus
        // einer Kopfzeile — ein Schaden, der nur im Gesamtlauf auffaellt.
        Request::setTrustedProxies([], 0);

        foreach (glob($this->tmpDir . '/*.php') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ----------------------------------------------------------------- Aufbau

    private function tabellen(): void
    {
        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();

            // Deckungsgleich mit 2026_09_29_000001_add_konto_felder_to_rec_persons.php.
            $t->string('invite_token_hash', 64)->nullable();
            $t->timestamp('invite_expires_at')->nullable();
            $t->timestamp('invite_used_at')->nullable();
            $t->string('code_hash', 64)->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->unsignedTinyInteger('code_versuche')->default(0);
            $t->string('code_zweck', 20)->nullable();
            $t->string('code_neue_nummer', 32)->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();

            // Deckungsgleich mit 2026_09_29_000003_add_nummernwechsel_zu_rec_persons.php.
            $t->string('wechsel_neue_nummer', 32)->nullable();
            $t->timestamp('wechsel_beantragt_at')->nullable();
            $t->timestamp('wechsel_wirksam_ab')->nullable();
            $t->string('wechsel_quelle', 20)->nullable();

            $t->timestamps();

            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('uuid', 64)->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('identity_card_number')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        // Die Dispo-Identitaet haengt am CRM-Kontakt — der
        // DispoIdentityResolver liest diese Tabelle.
        $this->capsule->schema()->create('crm_contact_links', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('contact_id')->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->integer('linkable_id')->nullable();
            $t->string('linkable_type')->nullable();
            $t->timestamps();
        });
    }

    /**
     * comms_channels, integrations_whatsapp_accounts und
     * rec_applicant_settings aus den ECHTEN Migrationen — ihre Form gehoert
     * nicht diesem Modul.
     */
    private function echteMigrationen(): void
    {
        $eigen        = dirname(__DIR__, 2);
        $crm          = $this->paketWurzel(CommsChannel::class);
        $integrations = $this->paketWurzel(IntegrationsWhatsAppTemplate::class);

        $dateien = [
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
        ];

        foreach ($dateien as [$wurzel, $relativ]) {
            $migration = require $wurzel . '/' . $relativ;
            $migration->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        return dirname((new \ReflectionClass($klasse))->getFileName(), 3);
    }

    /** WABA-Konto, Kanal und Team-Einstellung — der Weg, den RecruitingChannelResolver geht. */
    private function kanalAufsetzen(): void
    {
        $kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid'         => 'acc-konto-zurueck',
            'phone_number' => '+49 160 5552001',
            'title'        => 'Recruiting-WABA',
            'active'       => true,
            'user_id'      => 1,
        ]);

        DB::table('comms_channels')->insert([
            'team_id'           => self::TEAM,
            'name'              => 'Recruiting WhatsApp',
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552001',
            'is_active'         => true,
            'meta'              => json_encode(['integrations_whatsapp_account_id' => $kontoId]),
            'created_at'        => self::ANGEFASST,
            'updated_at'        => self::ANGEFASST,
        ]);

        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(['auto_pilot_wa_account_id' => $kontoId]),
        ]);
    }

    /**
     * Die Meta-Attrappe.
     *
     * Sie kennt eine Liste genehmigter Vorlagen samt erwarteter
     * Parameternamen und antwortet sonst mit status=failed — so wie Meta es
     * tut. Eine Attrappe, die jeden Vorlagennamen und jeden Platzhalter
     * annimmt, prueft nichts.
     */
    private function metaAttrappe(): object
    {
        return new class(self::VORLAGE_CODE, self::VORLAGE_HINWEIS) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            /** @var array<string, list<string>> Vorlagenname => erwartete Parameternamen in Reihenfolge */
            public array $genehmigt = [];

            public function __construct(string $code, string $hinweis)
            {
                $this->genehmigt = [$code => ['code'], $hinweis => []];
            }

            public function sendTemplate(
                $channel,
                string $to,
                string $templateName,
                array $components = [],
                string $languageCode = 'de',
                $sender = null,
                bool $isAutoReply = false,
            ) {
                $namen = [];
                $werte = [];
                foreach ($components as $component) {
                    foreach ($component['parameters'] ?? [] as $parameter) {
                        $namen[] = (string) ($parameter['parameter_name'] ?? '');
                        $werte[] = (string) ($parameter['text'] ?? '');
                    }
                }

                $this->calls[] = [
                    'to'           => $to,
                    'templateName' => $templateName,
                    'namen'        => $namen,
                    'werte'        => $werte,
                    'components'   => $components,
                ];

                $erwartet = $this->genehmigt[$templateName] ?? null;

                if ($erwartet === null || $erwartet !== $namen) {
                    return (object) [
                        'status'       => 'failed',
                        'meta_payload' => ['error' => ['message' => 'Template not approved']],
                    ];
                }

                return (object) ['status' => 'sent', 'meta_payload' => []];
            }
        };
    }

    // ----------------------------------------------------------------- Hilfen

    private function person(?string $nummer, string $uuid, array $attr = []): int
    {
        return (int) DB::table('rec_persons')->insertGetId(array_merge([
            'uuid'          => $uuid,
            'team_id'       => self::TEAM,
            'phone'         => $nummer,
            'password_hash' => Hash::make(self::PASSWORT),
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ], $attr));
    }

    private function anstellung(int $personId, string $token, array $attr = []): int
    {
        return (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id'              => self::TEAM,
            'portal_token'         => $token,
            'first_name'           => 'Gregor',
            'last_name'            => 'Erste',
            'birth_date'           => self::GEBURT,
            'identity_card_number' => 'L01X00T47',
            'rec_person_id'        => $personId,
            'company'              => 'RG',
            'phone'                => self::NUMMER,
            'is_active'            => 1,
            'portal_v2_since'      => self::ANGEFASST,
            'created_at'           => self::ANGEFASST,
            'updated_at'           => self::ANGEFASST,
        ], $attr));
    }

    private function zeile(?int $personId = null): object
    {
        return DB::table('rec_persons')->where('id', $personId ?? $this->personId)->first();
    }

    /**
     * WESSEN Vorgang sich die Seite serverseitig gemerkt hat.
     *
     * Ueber die Sitzung, nicht ueber eine Eigenschaft — genau das ist seit
     * dem Schnappschuss-Fund die Zusicherung. Der Schluessel wird hier
     * ABSICHTLICH nachgetippt: liest der Test ihn aus der Klasse, prueft er
     * nur sich selbst.
     */
    private function gemerktePerson(): ?int
    {
        return $this->session->get('recruiting.konto.rueckweg.person');
    }

    /**
     * DER SCHNAPPSCHUSS, den Livewire ins HTML schreibt.
     *
     * Gefragt wird DIESELBE Funktion, die HandleComponents::
     * dehydrateProperties() benutzt — kein Nachbau. Ein Nachbau waere hier
     * besonders verfuehrerisch und besonders falsch: die ganze Zusage haengt
     * daran, dass wirklich NICHTS ausgeliefert wird, was Auskunft gibt.
     *
     * @return array<string, mixed>
     */
    private function schnappschuss(KontoAnmelden $seite): array
    {
        return LivewireUtils::getPublicPropertiesDefinedOnSubclass($seite);
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    private function sender(): EinmalcodeSender
    {
        return new EinmalcodeSender($this->cache);
    }

    private function hinweisSender(): NummernwechselHinweisSender
    {
        return new NummernwechselHinweisSender();
    }

    /**
     * Die Seite, so wie sie nach dem Aufruf von /konto dasteht.
     *
     * Die Anfrage traegt vertrauenswuerdige Vermittler ein — genau das, was
     * das TrustProxies-Werk bei at: '*' tut (meingedeck/bootstrap/app.php).
     * Ohne das waere diese Attrappe GROSSZUEGIGER als der Wirt: ip() lieferte
     * dann brav REMOTE_ADDR, und der GD-12-Test bliebe gruen, egal welche
     * Quelle die Bremse nimmt.
     */
    private function seite(string $ip = self::IP, ?string $gefaelschteKopfzeile = null): KontoAnmelden
    {
        $this->router();

        $server = ['REMOTE_ADDR' => $ip];
        if ($gefaelschteKopfzeile !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $gefaelschteKopfzeile;
        }

        $anfrage = Request::create('/konto', 'GET', [], [], [], $server);
        $anfrage->setTrustedProxies([$ip], Request::HEADER_X_FORWARDED_FOR);

        $this->container->instance('request', $anfrage);

        $seite = new KontoAnmelden();
        $seite->mount($anfrage);

        return $seite;
    }

    /** Der Klartext-Code aus der zuletzt verschickten Nachricht — so wie der Mensch ihn liest. */
    private function codeAusDerNachricht(): string
    {
        $letzte = $this->meta->calls[count($this->meta->calls) - 1] ?? null;

        $this->assertNotNull($letzte, 'Es wurde gar keine Nachricht verschickt.');
        $this->assertSame(self::VORLAGE_CODE, $letzte['templateName']);

        return $letzte['werte'][0] ?? '';
    }

    /** @return list<array{stufe: string, nachricht: string, daten: array}> */
    private function logZeilen(string $nachricht): array
    {
        return array_values(array_filter(
            $this->log->zeilen,
            static fn (array $zeile): bool => $zeile['nachricht'] === $nachricht,
        ));
    }

    private function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $router = new Router(new Dispatcher($this->container), $this->container);
        $this->container->instance('router', $router);
        $this->router = $router;

        require dirname(__DIR__, 2) . '/routes/public.php';

        $router->getRoutes()->refreshNameLookups();

        $this->container->instance('url', new UrlGenerator(
            $router->getRoutes(),
            Request::create('http://localhost/konto', 'GET'),
        ));

        return $router;
    }

    private function blade(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/public/konto-anmelden.blade.php',
        );
    }

    /**
     * Das GANZE Blade wird mit dem echten BladeCompiler uebersetzt und
     * ausgefuehrt — nicht bloss nach Zeichenketten durchsucht. Ein
     * Quelltext-Waechter haette den Fall nicht gesehen, in dem eine Direktive
     * still nicht kompiliert und der falsche Zweig rendert.
     */
    private function rendere(KontoAnmelden $seite): string
    {
        $this->router();

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/konto-anmelden-' . md5($seite->state . $seite->fertigGrund) . '.php';
        file_put_contents($datei, $compiler->compileString($this->blade()));

        $variablen = get_object_vars($seite);
        $variablen['__datei'] = $datei;

        $lauf = function (array $__v): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };

        return \Closure::bind($lauf, $seite, KontoAnmelden::class)($variablen);
    }

    // ========================================================= Weg 1 und Weg 2

    /**
     * Der Gewinn gegenueber einem reinen Code-Login (Spec §5, Weg 2):
     * "Wer die SIM verliert, kommt trotzdem selbst rein."
     *
     * Zwei Nachweise: das Passwort auf der ALTEN Nummer, dann der Code an die
     * NEUE. Die Nummer wandert dabei auf Person UND Anstellungen — steht sie
     * nur an einer von beiden, geht der naechste Code wieder ans alte Geraet.
     */
    public function test_alte_nummer_und_passwort_wechseln_auf_die_neue(): void
    {
        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $this->assertSame('nummer-code', $seite->state);
        $this->assertSame('', $seite->fehler);

        $seite->code = $this->codeAusDerNachricht();
        $seite->nummerBestaetigen($this->hinweisSender());

        $this->assertSame('fertig', $seite->state, $seite->fehler);
        $this->assertSame('nummer', $seite->fertigGrund);
        $this->assertSame(self::NEUE_NUMMER, $this->zeile()->phone);
        $this->assertSame(
            self::NEUE_NUMMER,
            DB::table('rec_employees')->where('id', $this->anstellungId)->value('phone'),
            'die Nummer muss auch an der Anstellung stehen, sonst geht der naechste Code ans alte Geraet',
        );
    }

    /**
     * Der Code geht an die NEUE Nummer und NICHT an die alte.
     *
     * Ginge er an die alte, waere Weg 2 sinnlos: wer die SIM verloren hat,
     * bekaeme den Bestaetigungscode auf genau dem Geraet, das er nicht mehr
     * hat.
     */
    public function test_der_code_geht_an_die_neue_nummer(): void
    {
        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $this->assertCount(1, $this->meta->calls);
        $this->assertSame(self::NEUE_NUMMER, $this->meta->calls[0]['to']);
    }

    /** Ohne das Passwort gibt es keinen Code — und keine Personen-Kennung auf der Seite. */
    public function test_ohne_das_richtige_passwort_geht_nichts_raus(): void
    {
        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::FALSCHES_PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertSame('nummer', $seite->state);
        $this->assertNull($this->gemerktePerson());
        $this->assertSame(KontoAnmelden::MELDUNG, $seite->fehler);
        $this->assertNull($this->zeile()->code_hash, 'ohne Passwort darf gar kein Code entstehen');
    }

    /**
     * Eine unlesbare neue Nummer faellt VOR dem Sender heraus.
     *
     * Der Grund ist nicht Schoenheit: KontoWriter::erzeugeCode() ueberschreibt
     * den Code, der noch unterwegs ist. Faellt die unlesbare Nummer erst dort
     * auf, hat der Versuch dem Menschen den Code entwertet, den er gerade im
     * Daumen hat — und keinen neuen verschickt.
     */
    public function test_eine_unlesbare_neue_nummer_entwertet_den_laufenden_code_nicht(): void
    {
        KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_PASSWORT);
        $vorher = $this->zeile()->code_hash;
        $this->assertNotNull($vorher);

        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = 'keine Nummer';
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $this->assertSame('nummer', $seite->state);
        $this->assertSame([], $this->meta->calls);
        $this->assertSame($vorher, $this->zeile()->code_hash);
    }

    /** Ein falscher Code wechselt nichts — der zweite Nachweis traegt. */
    public function test_ein_falscher_code_wechselt_die_nummer_nicht(): void
    {
        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $seite->code = '000000';
        $seite->nummerBestaetigen($this->hinweisSender());

        $this->assertSame('nummer-code', $seite->state);
        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
    }

    /**
     * Der zweite Schritt laeuft nicht ohne den ersten.
     *
     * Das ist die Haelfte, die der #[Locked]-Waechter NICHT abdeckt: er
     * verhindert, dass der Browser $state und $personId setzt — diese
     * Zusicherung verhindert, dass ein Aufruf im falschen Zustand trotzdem
     * etwas tut.
     */
    public function test_ohne_den_ersten_schritt_bewirkt_der_zweite_nichts(): void
    {
        $klartext = KontoWriter::erzeugeCode($this->personId, KontoWriter::ZWECK_NUMMERNWECHSEL, self::NEUE_NUMMER);

        $seite = $this->seite();
        $seite->code = $klartext;
        $seite->nummerBestaetigen($this->hinweisSender());

        $this->assertSame('formular', $seite->state);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
    }

    /**
     * Begleitregel aus Spec §5: "Die alte Nummer bekommt einmalig einen
     * Hinweis, dass die Nummer geaendert wurde, sofern noch zustellbar."
     *
     * Sie ist die einzige Warnung, die ein Mensch bekommt, dem jemand das
     * Konto umgehaengt hat — auf dem Geraet, das er noch in der Hand haelt.
     */
    public function test_die_alte_nummer_bekommt_ihren_hinweis(): void
    {
        $this->wechsleDieNummer();

        $hinweise = array_values(array_filter(
            $this->meta->calls,
            fn (array $call): bool => $call['templateName'] === self::VORLAGE_HINWEIS,
        ));

        $this->assertCount(1, $hinweise, 'genau einer, und zwar an die ALTE Nummer');
        $this->assertSame(self::NUMMER, $hinweise[0]['to']);
    }

    /**
     * Der Hinweis nennt die NEUE Nummer nicht.
     *
     * Wer das alte Geraet in der Hand haelt — im schlechten Fall derjenige,
     * der das Konto gerade uebernommen hat —, soll nicht auch noch erfahren,
     * wohin es gewandert ist.
     */
    public function test_der_hinweis_nennt_die_neue_nummer_nicht(): void
    {
        $this->wechsleDieNummer();

        foreach ($this->meta->calls as $call) {
            if ($call['templateName'] !== self::VORLAGE_HINWEIS) {
                continue;
            }

            foreach ($call['werte'] as $wert) {
                $this->assertStringNotContainsString('98765432', $wert);
                $this->assertStringNotContainsString(self::NEUE_NUMMER, $wert);
            }
        }
    }

    /**
     * Begleitregel aus Spec §5: "Jeder Wechsel wird protokolliert" — und zwar
     * OHNE die Nummern im Klartext. Ein Protokoll ist genau der Ort, an den
     * man spaeter jemanden schauen laesst.
     */
    public function test_der_wechsel_steht_im_protokoll_ohne_die_nummern(): void
    {
        $this->wechsleDieNummer();

        $zeilen = $this->logZeilen('recruiting.konto.nummer_gewechselt');

        $this->assertCount(1, $zeilen, 'ein vollzogener Wechsel, eine Zeile');
        $this->assertSame($this->personId, $zeilen[0]['daten']['person_id']);
        $this->assertSame('1111', $zeilen[0]['daten']['alt_endet_auf']);
        $this->assertSame('5432', $zeilen[0]['daten']['neu_endet_auf']);

        // Und nirgends im ganzen Protokoll die vollstaendigen Nummern.
        $alles = json_encode($this->log->zeilen);
        $this->assertStringNotContainsString(self::NUMMER, $alles);
        $this->assertStringNotContainsString(self::NEUE_NUMMER, $alles);
    }

    /**
     * Eine Nummer, die im Team schon jemandem gehoert, endet in DERSELBEN
     * Meldung wie ein falscher Code.
     *
     * PersonLinker::setzeNummer() wirft dabei eine Ausnahme, die die fremde
     * Personen-Kennung nennt. Waere sie sichtbar, waere sie die Auskunft
     * "zu dieser Nummer gibt es schon ein Konto" — an jemanden, der bloss
     * eine Nummer getippt hat.
     */
    public function test_eine_fremde_nummer_meldet_nur_allgemein(): void
    {
        $this->person(self::NEUE_NUMMER, 'p-fremd');

        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $seite->code = $this->codeAusDerNachricht();
        $seite->nummerBestaetigen($this->hinweisSender());

        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertStringNotContainsString('Person', $seite->fehler);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
        $this->assertSame([], $this->logZeilen('recruiting.konto.nummer_gewechselt'));
    }

    // ================================================================== Weg 3

    /**
     * Weg 3 (Spec §5): "Passwort vergessen" — Code an die Nummer PLUS
     * Geburtsdatum, dann das neue Passwort.
     *
     * Der Beleg ist nicht der Zustand der Seite, sondern die Anmeldung: mit
     * dem neuen Passwort geht es, mit dem alten nicht mehr.
     */
    public function test_code_und_geburtsdatum_setzen_das_neue_passwort(): void
    {
        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame('vergessen-code', $seite->state);

        $seite->code = $this->codeAusDerNachricht();
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('fertig', $seite->state, $seite->fehler);
        $this->assertSame('passwort', $seite->fertigGrund);
        $this->assertSame($this->personId, KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::NEUES_PASSWORT));
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::PASSWORT));
    }

    /**
     * DIE BEGLEITREGEL: "Passwort vergessen antwortet immer gleich, egal ob
     * die Nummer im System ist" — sonst kann man Nummern durchprobieren.
     *
     * Geprueft wird ZEICHENGLEICHHEIT des gerenderten Markups, nicht die
     * Aehnlichkeit einzelner Textbausteine: genau in einem Nebensatz ("wir
     * haben Ihnen einen Code geschickt" gegen "falls wir die Nummer kennen")
     * entstuende der Unterschied, und eine Zusicherung auf Bausteine haette
     * ihn nicht gesehen.
     */
    public function test_eine_unbekannte_nummer_antwortet_zeichengleich(): void
    {
        $bekannt = $this->seite();
        $bekannt->zumPasswortVergessen();
        $bekannt->nummer = self::NUMMER_GETIPPT;
        $bekannt->passwortCodeAnfordern($this->sender());

        $unbekannt = $this->seite();
        $unbekannt->zumPasswortVergessen();
        $unbekannt->nummer = '0151 99999999';
        $unbekannt->passwortCodeAnfordern($this->sender());

        $this->assertSame($bekannt->state, $unbekannt->state);
        $this->assertSame($bekannt->fehler, $unbekannt->fehler);
        $this->assertSame(
            $this->rendere($bekannt),
            $this->rendere($unbekannt),
            'die Antwort auf eine unbekannte Nummer muss zeichengleich sein mit der auf eine bekannte',
        );
    }

    /** Fuer eine unbekannte Nummer geht nichts raus — und es kostet uns nichts. */
    public function test_eine_unbekannte_nummer_verschickt_nichts(): void
    {
        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = '0151 99999999';
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertNull($this->gemerktePerson());
    }

    /**
     * Canvas 1793: Konten Ausgeschiedener sind gesperrt, weil Anbieter
     * Handynummern nach Monaten neu vergeben. Der neue Inhaber darf ueber
     * "Passwort vergessen" nicht einmal einen Code ausloesen — er kaeme zwar
     * ohnehin nicht hinein, aber jede Nachricht kostet uns Geld und traegt
     * den Namen des fremden Menschen.
     */
    public function test_ein_ausgeschiedener_bekommt_keinen_code(): void
    {
        DB::table('rec_employees')->where('id', $this->anstellungId)->update(['is_active' => 0]);

        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertSame('vergessen-code', $seite->state, 'die Antwort bleibt trotzdem dieselbe');
    }

    /** Ein gesperrtes Konto ebenso — und ohne dass die Seite es verraet. */
    public function test_ein_gesperrtes_konto_bekommt_keinen_code(): void
    {
        DB::table('rec_persons')->where('id', $this->personId)->update(['locked_at' => self::ANGEFASST]);

        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertSame('vergessen-code', $seite->state);
    }

    /**
     * Der zweite Schritt einer UNBEKANNTEN Nummer scheitert genauso wie ein
     * falscher Code — leise, mit derselben Meldung.
     *
     * NACHGETRAGEN NACH EINER UEBERLEBENDEN MUTATION: die Wache gegen eine
     * leere Personen-Kennung war ungedeckt. Ohne sie geht null an einen
     * int-Parameter, das ist ein TypeError und KEINE
     * InvalidArgumentException — er liefe also am catch vorbei und ergaebe
     * eine 500er-Antwort. Und genau die waere das Orakel, das die
     * Begleitregel vermeiden soll: "Fehlerseite" hiesse "diese Nummer kennen
     * wir nicht".
     */
    public function test_eine_unbekannte_nummer_scheitert_im_zweiten_schritt_wie_ein_falscher_code(): void
    {
        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = '0151 99999999';
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame('vergessen-code', $seite->state);
        $this->assertNull($this->gemerktePerson());

        $seite->code = '123456';
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('vergessen-code', $seite->state);
        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
    }

    /**
     * Der ZWEITE Nachweis traegt: der richtige Code mit falschem Geburtsdatum
     * setzt kein Passwort.
     *
     * Das ist das Gegenmittel gegen Spec §6.1 — der neue Inhaber einer neu
     * vergebenen Nummer bekommt den Code und kommt trotzdem nicht weiter.
     */
    public function test_der_richtige_code_mit_falschem_geburtsdatum_setzt_kein_passwort(): void
    {
        $seite = $this->vergessenBisZumCode();

        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('vergessen-code', $seite->state);
        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::NEUES_PASSWORT));
        $this->assertSame($this->personId, KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::PASSWORT));
    }

    /**
     * Ein Tippfehler im Geburtsdatum verbrennt den Code NICHT — dieselbe
     * Entscheidung wie bei der Registrierung. Die Grenze gegen das
     * Durchprobieren ist der Zaehler, nicht der Code.
     */
    public function test_ein_tippfehler_im_geburtsdatum_verbrennt_den_code_nicht(): void
    {
        $seite = $this->vergessenBisZumCode();
        $code = $seite->code;

        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        // Derselbe Code, diesmal mit dem richtigen Datum.
        $seite->code = $code;
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('fertig', $seite->state, $seite->fehler);
        $this->assertSame($this->personId, KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::NEUES_PASSWORT));
    }

    /**
     * Nach fuenf falschen Geburtsdaten ist Schluss — auch mit dem richtigen.
     *
     * DIE ZAHL STEHT HIER AUSGESCHRIEBEN und wird nicht aus der Konstante
     * gelesen: Code und Test lesen sonst dieselbe Zahl, und eine Mutation der
     * Konstante bliebe unbemerkt.
     */
    public function test_nach_fuenf_falschen_geburtsdaten_ist_schluss(): void
    {
        $seite = $this->vergessenBisZumCode();
        $code = $seite->code;

        for ($i = 0; $i < 5; $i++) {
            $seite->code = $code;
            $seite->geburtsdatum = self::FALSCHE_GEBURT;
            $seite->neuesPasswort = self::NEUES_PASSWORT;
            $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
            $seite->passwortSetzen();
        }

        $seite->code = $code;
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('vergessen-code', $seite->state);
        $this->assertNull(
            KontoWriter::pruefeAnmeldung(null, self::NUMMER, self::NEUES_PASSWORT),
            'nach fuenf Fehlversuchen darf auch das richtige Geburtsdatum nichts mehr setzen',
        );
    }

    /**
     * Ein zu kurzes Passwort ist eine Formsache und KEIN Rateversuch.
     *
     * Ohne diese Trennung sperrte sich aus, wer fuenfmal ein zu kurzes
     * Passwort tippt — und der Code waere jedes Mal verbrannt.
     */
    public function test_ein_zu_kurzes_passwort_zaehlt_nicht_als_rateversuch(): void
    {
        $seite = $this->vergessenBisZumCode();
        $code = $seite->code;

        for ($i = 0; $i < 6; $i++) {
            $seite->code = $code;
            $seite->geburtsdatum = self::GEBURT;
            $seite->neuesPasswort = 'kurz';
            $seite->neuesPasswortWiederholung = 'kurz';
            $seite->passwortSetzen();

            $this->assertSame('vergessen-code', $seite->state);
            $this->assertNotSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler, 'die Passwortregel darf gesagt werden');
        }

        $seite->code = $code;
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('fertig', $seite->state, $seite->fehler);
    }

    /**
     * DIE EIGENE BREMSE (Auflage der Aufgabe-8-Pruefung).
     *
     * EinmalcodeSender::sende() nimmt eine PERSONEN-Kennung; seine Drossel
     * greift also erst, wenn die Nummer GEFUNDEN wurde. Wer Nummern
     * durchprobiert, laeuft nie in sie hinein — genau dieser Angriff wird
     * hier gefahren: zwanzig unbekannte Nummern von derselben Adresse,
     * danach geht auch fuer eine BEKANNTE nichts mehr raus.
     *
     * ZWANZIG STEHT AUSGESCHRIEBEN und wird nicht aus der Konstante gelesen.
     */
    public function test_zwanzig_unbekannte_nummern_bremsen_die_einundzwanzigste(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $seite = $this->seite();
            $seite->zumPasswortVergessen();
            $seite->nummer = '+4915190' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $seite->passwortCodeAnfordern($this->sender());
        }

        $this->assertSame([], $this->meta->calls, 'unbekannte Nummern verschicken nichts');

        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls, 'die Bremse muss auch die bekannte Nummer treffen');
        $this->assertSame('vergessen-code', $seite->state, 'die Antwort bleibt trotzdem dieselbe');
        $this->assertNull($this->zeile()->code_hash);
    }

    /**
     * Ruling GD-12: eine gefaelschte Kopfzeile verschiebt den Zaehler NICHT.
     *
     * Der Wirt setzt trustProxies(at: '*'), damit ist $request->ip() vom
     * Anfragenden frei bestimmbar. Eine Bremse darauf waere schlimmer als gar
     * keine: ein neuer Kopfzeilen-Wert je Anfrage gaebe einen frischen
     * Zaehler. Hier traegt jede Anfrage eine ANDERE Kopfzeile und dieselbe
     * Verbindungsadresse — die Bremse muss trotzdem greifen.
     */
    public function test_eine_gefaelschte_kopfzeile_schuettelt_die_bremse_nicht_ab(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $seite = $this->seite(self::IP, '198.51.100.' . $i);
            $seite->zumPasswortVergessen();
            $seite->nummer = '+4915190' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $seite->passwortCodeAnfordern($this->sender());
        }

        $seite = $this->seite(self::IP, '198.51.100.250');
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertNull($this->zeile()->code_hash);
    }

    /**
     * Und die Gegenprobe: eine ANDERE Verbindungsadresse hat ihren eigenen
     * Zaehler.
     *
     * Ohne diese Zusicherung koennte die Bremse schlicht alles blockieren,
     * und der Test darueber bliebe trotzdem gruen.
     */
    public function test_eine_andere_adresse_hat_ihren_eigenen_zaehler(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $seite = $this->seite();
            $seite->zumPasswortVergessen();
            $seite->nummer = '+4915190' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $seite->passwortCodeAnfordern($this->sender());
        }

        $seite = $this->seite('198.51.100.9');
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertCount(1, $this->meta->calls);
        $this->assertSame(self::NUMMER, $this->meta->calls[0]['to']);
    }

    /** Das neue Passwort steht nicht im Klartext in der Datenbank. */
    public function test_das_neue_passwort_steht_nicht_im_klartext(): void
    {
        $seite = $this->vergessenBisZumCode();
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $zeile = (array) $this->zeile();

        foreach ($zeile as $spalte => $wert) {
            $this->assertStringNotContainsString(
                self::NEUES_PASSWORT,
                (string) $wert,
                "Das Passwort steht im Klartext in rec_persons.{$spalte}",
            );
        }
    }

    /** Und es faehrt nach dem Vorgang nicht im Schnappschuss der Seite weiter mit. */
    public function test_das_neue_passwort_bleibt_nicht_auf_der_seite_stehen(): void
    {
        $seite = $this->vergessenBisZumCode();
        $seite->geburtsdatum = self::GEBURT;
        $seite->neuesPasswort = self::NEUES_PASSWORT;
        $seite->neuesPasswortWiederholung = self::NEUES_PASSWORT;
        $seite->passwortSetzen();

        $this->assertSame('', $seite->neuesPasswort);
        $this->assertSame('', $seite->neuesPasswortWiederholung);
        $this->assertSame('', $seite->code);
        $this->assertStringNotContainsString(self::NEUES_PASSWORT, $this->rendere($seite));
    }

    /**
     * Zurueck zur Anmeldung raeumt die Personen-Kennung ab.
     *
     * Eine stehengebliebene Kennung aus einem abgebrochenen Vorgang waere im
     * naechsten genau der erste Nachweis, den niemand mehr erbracht hat.
     */
    public function test_zurueck_zur_anmeldung_raeumt_die_personen_kennung_ab(): void
    {
        $seite = $this->vergessenBisZumCode();
        $this->assertSame($this->personId, $this->gemerktePerson());

        $seite->zurAnmeldung();

        $this->assertNull($this->gemerktePerson());
        $this->assertSame('formular', $seite->state);
        $this->assertSame('', $seite->code);
    }

    // ================================================================== Weg 4

    /**
     * Weg 4 (Spec §5): Nummer weg UND Passwort vergessen. Geburtsdatum +
     * Ausweisziffern, Code an die neue Nummer — und der Wechsel wird erst
     * nach 24 Stunden wirksam.
     *
     * DIE VIERUNDZWANZIG STEHEN HIER AUSGESCHRIEBEN und werden nicht aus
     * KontoWriter::WECHSEL_FRIST_STUNDEN gelesen: Code und Test laesen sonst
     * dieselbe Zahl, und eine Mutation der Konstante bliebe unbemerkt.
     */
    public function test_weg4_beantragt_den_wechsel_und_vollzieht_ihn_nicht(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        $this->assertSame('fertig', $seite->state, $seite->fehler);
        $this->assertSame('notfall', $seite->fertigGrund);

        $zeile = $this->zeile();

        $this->assertSame(self::NUMMER, $zeile->phone, 'die Nummer darf sich JETZT noch nicht geaendert haben');
        $this->assertSame(self::NEUE_NUMMER, $zeile->wechsel_neue_nummer);
        $this->assertSame(KontoWriter::ZWECK_NOTFALL, $zeile->wechsel_quelle);
        $this->assertSame(
            Carbon::parse(self::JETZT)->addHours(24)->format('Y-m-d H:i:s'),
            Carbon::parse($zeile->wechsel_wirksam_ab)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * WEG 4 IST KEIN ZWEITER ANMELDEWEG — die wichtigste Zusicherung dieser
     * Aufgabe.
     *
     * Ausweisziffern kommen im ganzen Konto nur hier vor, und sie oeffnen
     * nichts: keine Sitzung, kein Passwort, nicht einmal die Nummer (die
     * wandert erst nach der Frist). Waere das alte Verfahren hier eine
     * Abkuerzung, kaeme jeder, der eine Nummer kennt, ueber die Nebentuer
     * hinein — und die Umstellung machte das Portal unsicherer als vorher.
     */
    public function test_weg4_oeffnet_weder_sitzung_noch_passwort(): void
    {
        $vorher = $this->zeile()->password_hash;

        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        $this->assertSame($vorher, $this->zeile()->password_hash, 'Weg 4 setzt KEIN Passwort');
        $this->assertSame([], $seite->geoeffnet);

        // GEFRAGT WIRD NACH DEN PORTAL-SCHLUESSELN, nicht nach einer leeren
        // Sitzung: die Seite legt dort inzwischen selbst etwas ab (die
        // laufende Personen-Kennung, seit sie nicht mehr im Schnappschuss
        // steht). "Die Sitzung ist leer" haette also ab jetzt das Falsche
        // gemessen — und waere, als es noch stimmte, bloss zufaellig gruen
        // gewesen.
        $portalSchluessel = array_filter(
            array_keys($this->session->all()),
            static fn (string $schluessel): bool => str_starts_with($schluessel, 'employee_portal_'),
        );

        $this->assertSame([], $portalSchluessel, 'Weg 4 oeffnet KEINE Portal-Sitzung');
        $this->assertFalse($this->session->has(PortalAuth::sessionKey($this->anstellungId)));
        $this->assertNull($this->zeile()->letzte_anmeldung_at, 'Weg 4 ist keine Anmeldung');
    }

    /** Der Code geht an die NEUE Nummer — an die alte kommt der Mensch ja nicht mehr. */
    public function test_der_notfall_code_geht_an_die_neue_nummer(): void
    {
        $this->notfallBisZumCode();

        $this->assertCount(1, $this->meta->calls);
        $this->assertSame(self::NEUE_NUMMER, $this->meta->calls[0]['to']);
    }

    /**
     * Falsche Ausweisziffern verschicken nichts — und antworten zeichengleich.
     *
     * Ohne die Zeichengleichheit waeren die Ausweisziffern ein Orakel: wer
     * eine Nummer kennt, probierte aus, welche vier Ziffern dazu passen.
     */
    public function test_falsche_ausweisziffern_antworten_zeichengleich(): void
    {
        $richtig = $this->notfallBisZumCode();
        $vorherigeCalls = count($this->meta->calls);

        $falsch = $this->seite();
        $falsch->zumNotfall();
        $falsch->nummer = self::NUMMER_GETIPPT;
        $falsch->geburtsdatum = self::GEBURT;
        $falsch->ausweis = '9999';
        $falsch->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $falsch->notfallAnfordern($this->sender());

        $this->assertCount($vorherigeCalls, $this->meta->calls, 'falsche Ziffern verschicken nichts');
        $this->assertSame($richtig->state, $falsch->state);

        // OHNE VORHERIGES GLEICHMACHEN: hier wird nichts an den beiden
        // Seiten zurechtgerueckt, bevor verglichen wird. Was sich
        // unterscheidet, muss sich im Markup zeigen duerfen.
        $this->assertSame($this->rendere($richtig), $this->rendere($falsch));
    }

    /** Dasselbe fuer ein falsches Geburtsdatum. */
    public function test_ein_falsches_geburtsdatum_verschickt_in_weg4_nichts(): void
    {
        $seite = $this->seite();
        $seite->zumNotfall();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->ausweis = '0T47';
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->notfallAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertSame('notfall-code', $seite->state);
        $this->assertNull($this->gemerktePerson());
    }

    /**
     * Geburtsdatum und Ausweisziffern bleiben nach dem ersten Schritt nicht
     * auf der Seite stehen — sie sind der sensibelste Teil dieser Seite und
     * haben im Schnappschuss des zweiten Schritts nichts zu suchen.
     */
    public function test_die_nachweise_bleiben_nicht_auf_der_seite_stehen(): void
    {
        $seite = $this->notfallBisZumCode();

        $this->assertSame('', $seite->geburtsdatum);
        $this->assertSame('', $seite->ausweis);

        $html = $this->rendere($seite);

        $this->assertStringNotContainsString('0T47', $html);
        $this->assertStringNotContainsString(self::GEBURT, $html);
    }

    /**
     * Nach fuenf falschen Nachweisen geht auch mit den richtigen nichts mehr.
     *
     * Ohne diese Bremse waere Weg 4 der bequemste Angriff des ganzen Kontos:
     * ein plausibler Geburtsjahrgang-Bereich sind rund 25.000 Moeglichkeiten,
     * vier Ausweisziffern rund 1,7 Millionen — beides zusammen bleibt gross,
     * aber der ERSTE Nachweis allein ist an einem Nachmittag durch, und
     * danach zaehlt nur noch der zweite.
     *
     * FUENF STEHT AUSGESCHRIEBEN und wird nicht aus der Konstante gelesen.
     */
    public function test_nach_fuenf_falschen_nachweisen_geht_auch_mit_den_richtigen_nichts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $seite = $this->seite();
            $seite->zumNotfall();
            $seite->nummer = self::NUMMER_GETIPPT;
            $seite->geburtsdatum = self::FALSCHE_GEBURT;
            $seite->ausweis = '0T47';
            $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
            $seite->notfallAnfordern($this->sender());
        }

        $seite = $this->seite();
        $seite->zumNotfall();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->geburtsdatum = self::GEBURT;
        $seite->ausweis = '0T47';
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->notfallAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls);
        $this->assertNull($this->gemerktePerson());
        $this->assertSame('notfall-code', $seite->state, 'die Antwort bleibt trotzdem dieselbe');
    }

    /** Ein falscher Code legt keinen Antrag an. */
    public function test_ohne_gueltigen_code_entsteht_kein_antrag(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->code = '000000';
        $seite->notfallBestaetigen();

        $this->assertSame('notfall-code', $seite->state);
        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertNull($this->zeile()->wechsel_wirksam_ab);
    }

    /**
     * Wessen Nachweise nicht gestimmt haben, scheitert im zweiten Schritt
     * genauso wie jemand mit falschem Code — leise, mit derselben Meldung.
     *
     * Das ist die zweite Haelfte der Zusage aus Schritt 1: ohne diesen Zweig
     * saehe der Weg fuer die beiden sichtbar verschieden aus.
     */
    public function test_ohne_stimmende_nachweise_scheitert_der_zweite_schritt_wie_ein_falscher_code(): void
    {
        $seite = $this->seite();
        $seite->zumNotfall();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->ausweis = '0T47';
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->notfallAnfordern($this->sender());

        $seite->code = '123456';
        $seite->notfallBestaetigen();

        $this->assertSame('notfall-code', $seite->state);
        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertNull($this->zeile()->wechsel_wirksam_ab);
    }

    /**
     * DAS FENSTER: vor Ablauf der Frist bewirkt das Anwenden nichts, danach
     * wandert die Nummer.
     *
     * VIERUNDZWANZIG STUNDEN STEHEN AUSGESCHRIEBEN, und zwar von beiden
     * Seiten: dreiundzwanzig Stunden und neunundfuenfzig Minuten sind noch zu
     * frueh, vierundzwanzig Stunden und eine Minute sind faellig. Nur eine
     * der beiden Proben liesse eine Frist von einer Stunde oder von einer
     * Woche gruen durchgehen.
     */
    public function test_der_antrag_wird_erst_nach_vierundzwanzig_stunden_wirksam(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(23)->addMinutes(59));
        $this->assertNull(KontoWriter::wendeNummernwechselAn($this->personId), 'noch nicht faellig');
        $this->assertSame(self::NUMMER, $this->zeile()->phone);

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(24)->addMinutes(1));
        $ergebnis = KontoWriter::wendeNummernwechselAn($this->personId);

        $this->assertSame(['alt' => self::NUMMER, 'neu' => self::NEUE_NUMMER], $ergebnis);
        $this->assertSame(self::NEUE_NUMMER, $this->zeile()->phone);
        $this->assertSame(
            self::NEUE_NUMMER,
            DB::table('rec_employees')->where('id', $this->anstellungId)->value('phone'),
        );
        $this->assertNull($this->zeile()->wechsel_wirksam_ab, 'der angewendete Antrag ist weg');
    }

    /**
     * HR kann stoppen — Spec §5, Weg 4: "HR bekommt eine Meldung und kann
     * innerhalb von 24 Stunden stoppen."
     *
     * Der Beleg ist nicht, dass stoppeNummerwechsel() true zurueckgibt,
     * sondern dass der Wechsel NACH Ablauf der Frist immer noch nicht
     * passiert.
     */
    public function test_hr_kann_den_antrag_innerhalb_der_frist_stoppen(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(2));
        $this->assertTrue(KontoWriter::stoppeNummerwechsel($this->personId));

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(48));
        $this->assertNull(KontoWriter::wendeNummernwechselAn($this->personId));
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
    }

    /** Ohne offenen Antrag hat HR nichts zu stoppen. */
    public function test_ohne_antrag_gibt_es_nichts_zu_stoppen(): void
    {
        $this->assertFalse(KontoWriter::stoppeNummerwechsel($this->personId));
    }

    /**
     * Ein passwortgedeckter Wechsel (Weg 1+2) raeumt einen offenen
     * Notfall-Antrag ab.
     *
     * Bliebe er stehen, zoege er die Nummer 24 Stunden spaeter still noch
     * einmal weiter — auf das Ziel, das jemand OHNE Passwort eingetragen
     * hat. Genau der Fall, gegen den das Fenster gebaut ist, traete dann
     * hinter dem Ruecken des rechtmaessigen Inhabers ein.
     */
    public function test_ein_passwortgedeckter_wechsel_raeumt_den_notfall_antrag_ab(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();
        $this->assertNotNull($this->zeile()->wechsel_wirksam_ab);

        // Der rechtmaessige Inhaber wechselt selbst — auf eine DRITTE Nummer.
        $eigen = $this->seite();
        $eigen->zumNummernwechsel();
        $eigen->nummer = self::NUMMER_GETIPPT;
        $eigen->passwort = self::PASSWORT;
        $eigen->neueNummer = '0160 12341234';
        $eigen->nummerAnfordern($this->auth(), $this->sender());
        $eigen->code = $this->codeAusDerNachricht();
        $eigen->nummerBestaetigen($this->hinweisSender());

        $this->assertSame('+4916012341234', $this->zeile()->phone);
        $this->assertNull($this->zeile()->wechsel_wirksam_ab, 'der Notfall-Antrag ist ueberholt');

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(48));
        $this->assertNull(KontoWriter::wendeNummernwechselAn($this->personId));
        $this->assertSame('+4916012341234', $this->zeile()->phone);
    }

    /**
     * Die Meldung an HR steht im Protokoll — auf `warning`, nicht auf `info`.
     *
     * Die 24 Stunden sind nur dann ein Stopp-Recht, wenn jemand den Antrag
     * auch SIEHT. Zwischen den Versand-Zeilen auf `info` ginge er unter.
     */
    public function test_der_antrag_steht_als_warnung_im_protokoll(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        $zeilen = $this->logZeilen('recruiting.konto.nummernwechsel_beantragt');

        $this->assertCount(1, $zeilen);
        $this->assertSame('warning', $zeilen[0]['stufe']);
        $this->assertSame($this->personId, $zeilen[0]['daten']['person_id']);
        $this->assertSame('5432', $zeilen[0]['daten']['neu_endet_auf']);

        $alles = json_encode($this->log->zeilen);
        $this->assertStringNotContainsString(self::NUMMER, $alles);
        $this->assertStringNotContainsString(self::NEUE_NUMMER, $alles);
    }

    /** Und HR findet ihn in der Arbeitsliste wieder. */
    public function test_der_offene_antrag_steht_in_der_liste(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        $offen = KontoWriter::offeneNummernwechsel();

        $this->assertCount(1, $offen);
        $this->assertSame($this->personId, (int) $offen[0]->id);
        $this->assertSame(self::NEUE_NUMMER, $offen[0]->wechsel_neue_nummer);

        KontoWriter::stoppeNummerwechsel($this->personId);

        $this->assertSame([], KontoWriter::offeneNummernwechsel());
    }

    /**
     * Der angewendete Wechsel wird protokolliert wie jeder andere — mit
     * seinem Weg, damit spaeter erkennbar ist, dass er OHNE Passwort
     * zustande kam.
     */
    public function test_der_angewendete_wechsel_nennt_seinen_weg(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(25));
        KontoWriter::wendeNummernwechselAn($this->personId);

        $zeilen = $this->logZeilen('recruiting.konto.nummer_gewechselt');

        $this->assertCount(1, $zeilen);
        $this->assertSame(KontoWriter::ZWECK_NOTFALL, $zeilen[0]['daten']['weg']);
    }

    // ============================================== Weg 5: der HR-Knopf

    /**
     * Weg 5 (Spec §5): "Gar nichts geht. HR traegt die neue Nummer im HCM
     * ein, Einladung geht neu raus."
     *
     * Der Beleg ist nicht die Ausgabe, sondern dass die Nummer an Person UND
     * Anstellung steht und der ausgegebene Code wirklich eine Einladung
     * oeffnet.
     */
    public function test_weg5_setzt_die_nummer_und_erzeugt_eine_neue_einladung(): void
    {
        [$code, $ausgabe] = $this->kommando([
            '--person' => (string) $this->personId,
            '--nummer' => self::NEUE_NUMMER_GETIPPT,
        ]);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertSame(self::NEUE_NUMMER, $this->zeile()->phone);
        $this->assertSame(
            self::NEUE_NUMMER,
            DB::table('rec_employees')->where('id', $this->anstellungId)->value('phone'),
        );

        $this->assertSame(1, preg_match('/Einladungscode ([A-Z0-9]{8})/', $ausgabe, $treffer), $ausgabe);
        $this->assertSame(
            $this->personId,
            KontoWriter::personFuerEinladung($treffer[1]),
            'der ausgegebene Code muss wirklich eine Einladung oeffnen',
        );
    }

    /**
     * Weg 5 haendigt KEIN Konto aus.
     *
     * Der erste Nachweis liegt ausserhalb des Systems (HR erkennt den
     * Menschen), der zweite ist das GEBURTSDATUM bei der Registrierung. Wer
     * hier ein Passwort mitsetzte, machte aus dem HR-Knopf einen
     * Generalschluessel.
     */
    public function test_weg5_setzt_kein_passwort(): void
    {
        $vorher = $this->zeile()->password_hash;

        $this->kommando(['--person' => (string) $this->personId, '--nummer' => self::NEUE_NUMMER_GETIPPT]);

        $this->assertSame($vorher, $this->zeile()->password_hash);
    }

    /** Auch bei Weg 5 bekommt die alte Nummer ihren Hinweis. */
    public function test_weg5_benachrichtigt_die_alte_nummer(): void
    {
        $this->kommando(['--person' => (string) $this->personId, '--nummer' => self::NEUE_NUMMER_GETIPPT]);

        $hinweise = array_values(array_filter(
            $this->meta->calls,
            fn (array $call): bool => $call['templateName'] === self::VORLAGE_HINWEIS,
        ));

        $this->assertCount(1, $hinweise);
        $this->assertSame(self::NUMMER, $hinweise[0]['to']);
    }

    /**
     * Weg 5 zieht den CRM-Kontakt nach.
     *
     * PersonLinker::setzeNummer() schreibt bewusst observer-frei, der
     * RecEmployeePhoneSyncObserver springt also NICHT an (Vorfall RG19734).
     * Ohne den Nachzug behaelt der Kontakt die alte Nummer — und der
     * naechste Einmalcode geht ans ALTE Geraet, also genau dorthin, wo der
     * Mensch ihn nicht lesen kann. Das ist der Fehler, den HR mit diesem
     * Knopf gerade beheben wollte.
     *
     * NACHGETRAGEN NACH EINER UEBERLEBENDEN MUTATION: der Nachzug liess sich
     * aus setzeNummerDurchHr() entfernen, ohne dass ein Test rot wurde.
     */
    public function test_weg5_zieht_den_crm_kontakt_nach(): void
    {
        $this->kommando(['--person' => (string) $this->personId, '--nummer' => self::NEUE_NUMMER_GETIPPT]);

        $this->assertSame(
            [['id' => $this->anstellungId, 'phone' => self::NEUE_NUMMER]],
            $this->crmSync->nachgezogen,
        );
    }

    /** Und der angewendete Notfall-Antrag ebenso. */
    public function test_der_angewendete_antrag_zieht_den_crm_kontakt_nach(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();
        $this->crmSync->nachgezogen = [];

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(25));
        $this->kommando(['--faellig' => true]);

        $this->assertSame(
            [['id' => $this->anstellungId, 'phone' => self::NEUE_NUMMER]],
            $this->crmSync->nachgezogen,
        );
    }

    public function test_weg5_braucht_beide_angaben(): void
    {
        [$code, $ausgabe] = $this->kommando(['--person' => (string) $this->personId]);

        $this->assertSame(1, $code);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
        $this->assertStringContainsString('BEIDE', $ausgabe);
    }

    public function test_der_probelauf_aendert_nichts(): void
    {
        $this->kommando([
            '--person'  => (string) $this->personId,
            '--nummer'  => self::NEUE_NUMMER_GETIPPT,
            '--dry-run' => true,
        ]);

        $this->assertSame(self::NUMMER, $this->zeile()->phone);
        $this->assertNull($this->zeile()->invite_token_hash);
        $this->assertSame([], $this->meta->calls);
    }

    /**
     * Die Ausgabe nennt Kennungen und die letzten vier Stellen — keine Namen
     * und keine vollstaendigen Nummern (Muster:
     * recruiting:mitarbeiter-grenzfaelle).
     */
    public function test_die_ausgabe_nennt_keine_namen_und_keine_vollen_nummern(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        [, $ausgabe] = $this->kommando(['--offen' => true]);

        $this->assertStringContainsString((string) $this->personId, $ausgabe);
        $this->assertStringContainsString('5432', $ausgabe);
        $this->assertStringNotContainsString('Gregor', $ausgabe);
        $this->assertStringNotContainsString(self::NUMMER, $ausgabe);
        $this->assertStringNotContainsString(self::NEUE_NUMMER, $ausgabe);
    }

    public function test_hr_stoppt_ueber_das_kommando(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        [$code] = $this->kommando(['--stopp' => (string) $this->personId]);

        $this->assertSame(0, $code);

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(48));
        $this->kommando(['--faellig' => true]);

        $this->assertSame(self::NUMMER, $this->zeile()->phone);
    }

    /** Ohne offenen Antrag ist das Stoppen kein Fehler, sondern eine Auskunft. */
    public function test_stoppen_ohne_antrag_ist_kein_fehler(): void
    {
        [$code, $ausgabe] = $this->kommando(['--stopp' => (string) $this->personId]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('kein offener Antrag', $ausgabe);
    }

    /**
     * --faellig wendet NUR an, was faellig ist.
     *
     * Ohne diese Zusicherung koennte der Lauf jeden Antrag sofort anwenden —
     * und das 24-Stunden-Fenster waere eine Zeile Dokumentation ohne
     * Wirkung.
     */
    public function test_faellig_wendet_nur_an_was_faellig_ist(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        $this->kommando(['--faellig' => true]);
        $this->assertSame(self::NUMMER, $this->zeile()->phone, 'heute ist noch nichts faellig');

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(25));
        [$code, $ausgabe] = $this->kommando(['--faellig' => true]);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertSame(self::NEUE_NUMMER, $this->zeile()->phone);
        $this->assertSame([], KontoWriter::offeneNummernwechsel());
    }

    /**
     * Ein gescheiterter Antrag bleibt stehen und haelt die uebrigen nicht
     * auf.
     *
     * Der Fehlschlag ist echt und nicht gestellt: die Zielnummer gehoert im
     * Team inzwischen jemand anderem, und PersonLinker::setzeNummer()
     * weigert sich dort ausdruecklich. Wer den Antrag in diesem Fall
     * loescht, verliert ihn still — dabei muss genau ihn ein Mensch klaeren
     * (Spec §6.2).
     */
    public function test_ein_gescheiterter_antrag_bleibt_stehen(): void
    {
        $seite = $this->notfallBisZumCode();
        $seite->notfallBestaetigen();

        // Jemand anderes belegt die Zielnummer, bevor die Frist ablaeuft.
        $this->person(self::NEUE_NUMMER, 'p-dazwischen');

        Carbon::setTestNow(Carbon::parse(self::JETZT)->addHours(25));
        [$code, $ausgabe] = $this->kommando(['--faellig' => true]);

        $this->assertSame(1, $code, $ausgabe);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
        $this->assertCount(1, KontoWriter::offeneNummernwechsel(), 'der Fall gehoert zu HR, nicht in den Papierkorb');
    }

    public function test_genau_eine_aufgabe_je_aufruf(): void
    {
        [$code, $ausgabe] = $this->kommando(['--offen' => true, '--faellig' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('genau eines', $ausgabe);
    }

    public function test_ohne_aufgabe_passiert_nichts(): void
    {
        [$code] = $this->kommando([]);

        $this->assertSame(1, $code);
    }

    /**
     * Das Kommando ist im ServiceProvider eingetragen.
     *
     * Ohne diese Zeile gibt es die Datei, aber kein Kommando — und der
     * Zeitplan ruft ins Leere, waehrend jeder Test hier gruen bleibt.
     */
    public function test_das_kommando_ist_registriert(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');

        $this->assertStringContainsString('Commands\\KontoZuruecksetzen::class', $quelle);
    }

    /**
     * Und --faellig steht im Zeitplan.
     *
     * DAS IST DIE ZEILE, OHNE DIE WEG 4 NICHT FUNKTIONIERT: der beantragte
     * Wechsel wird erst 24 Stunden spaeter faellig, und dann ist niemand
     * mehr da, der ihn ausloest — die Person kommt ja gerade nicht in ihr
     * Konto. Ohne Zeitplan-Eintrag bliebe jeder Antrag liegen, und jeder
     * Test dieser Klasse bliebe trotzdem gruen, weil sie das Anwenden selbst
     * ausloesen.
     *
     * Geprueft wird der Quelltext des ServiceProviders und nicht der
     * laufende Zeitplan: diese Suite bootet den Wirt nicht (kein Laravel,
     * kein Scheduler). Das ist die schwaechere Form — sie faengt das
     * VERGESSEN, nicht einen falschen Rhythmus.
     */
    public function test_die_faelligen_antraege_stehen_im_zeitplan(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');

        $this->assertStringContainsString("Schedule::command('recruiting:konto-zuruecksetzen --faellig')", $quelle);
    }

    // ====================================================== Der Schnappschuss

    /**
     * DIE BEGLEITREGEL AUF DER EBENE, AUF DER SIE ENTSCHIEDEN WIRD.
     *
     * Livewire dehydriert JEDE oeffentliche Eigenschaft und schreibt sie als
     * wire:snapshot ins HTML. #[Locked] verhindert das SETZEN durch den
     * Browser — nicht das AUSLIEFERN. Stuende die Personen-Kennung dort,
     * saehe man nach "Nummer tippen, Quelltext ansehen" exakt und kostenlos,
     * ob es zu dieser Nummer ein Konto gibt.
     *
     * Der Markup-Vergleich weiter oben kann das NICHT belegen: der
     * Schnappschuss steht nicht im Blade. Deshalb dieser Test — und er fragt
     * DIESELBE Funktion, die HandleComponents::dehydrateProperties()
     * benutzt, statt sie nachzubauen.
     */
    public function test_der_schnappschuss_verraet_nicht_ob_es_die_nummer_gibt(): void
    {
        $bekannt = $this->seite();
        $bekannt->zumPasswortVergessen();
        $bekannt->nummer = self::NUMMER_GETIPPT;
        $bekannt->passwortCodeAnfordern($this->sender());

        $unbekannt = $this->seite();
        $unbekannt->zumPasswortVergessen();
        $unbekannt->nummer = '0151 99999999';
        $unbekannt->passwortCodeAnfordern($this->sender());

        // Die getippte Nummer ist die EIGENE Eingabe des Menschen und steht
        // in beiden Faellen im Schnappschuss — sie verraet ihm nichts, was
        // er nicht gerade selbst geschrieben hat. Alles andere muss gleich
        // sein.
        $a = $this->schnappschuss($bekannt);
        $b = $this->schnappschuss($unbekannt);
        unset($a['nummer'], $b['nummer']);

        $this->assertSame($a, $b, 'Ein Feld des wire:snapshot unterscheidet bekannte von unbekannter Nummer.');
    }

    /**
     * Dasselbe fuer Weg 4, und dort ist es schaerfer: die Kennung wuerde nur
     * bei STIMMENDEN Ausweisziffern gesetzt. Ein Unterschied im
     * Schnappschuss saegte also nicht bloss "diese Nummer gibt es", sondern
     * "Geburtsdatum und Ausweisziffern waren richtig".
     */
    public function test_der_schnappschuss_verraet_nicht_ob_die_ausweisziffern_stimmten(): void
    {
        $richtig = $this->seite();
        $richtig->zumNotfall();
        $richtig->nummer = self::NUMMER_GETIPPT;
        $richtig->geburtsdatum = self::GEBURT;
        $richtig->ausweis = '0T47';
        $richtig->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $richtig->notfallAnfordern($this->sender());

        $falsch = $this->seite();
        $falsch->zumNotfall();
        $falsch->nummer = self::NUMMER_GETIPPT;
        $falsch->geburtsdatum = self::GEBURT;
        $falsch->ausweis = '9999';
        $falsch->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $falsch->notfallAnfordern($this->sender());

        $this->assertSame(
            $this->schnappschuss($richtig),
            $this->schnappschuss($falsch),
            'Ein Feld des wire:snapshot verraet, ob die Ausweisziffern gestimmt haben.',
        );
    }

    /**
     * Und der Grund, warum die beiden Tests ueberhaupt etwas beweisen: die
     * Kennung IST vorhanden, sie steht nur woanders.
     *
     * Ohne diese Zusicherung koennte die Seite die Kennung schlicht gar
     * nicht mehr fuehren — beide Schnappschuesse waeren gleich, beide Wege
     * kaputt, und die Tests darueber blieben gruen.
     */
    public function test_die_kennung_liegt_in_der_sitzung_und_nicht_im_schnappschuss(): void
    {
        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());

        $this->assertSame($this->personId, $this->gemerktePerson());

        foreach ($this->schnappschuss($seite) as $feld => $wert) {
            $this->assertNotSame(
                $this->personId,
                $wert,
                "Die Personen-Kennung steht im wire:snapshot-Feld \"{$feld}\".",
            );
        }
    }

    // ================================================================= Das Blade

    public function test_die_anmeldung_zeigt_beide_wege_zurueck(): void
    {
        $html = $this->rendere($this->seite());

        $this->assertStringContainsString('wire:click="zumPasswortVergessen"', $html);
        $this->assertStringContainsString('wire:click="zumNummernwechsel"', $html);
        $this->assertStringContainsString('wire:click="zumNotfall"', $html);
    }

    /**
     * Das ALTE VERFAHREN steht im Anmeldeformular weiterhin nicht daneben —
     * auch jetzt nicht, wo das Geburtsdatum an anderer Stelle vorkommt.
     *
     * Geprueft wird das GERENDERTE Markup des Anmeldezustands, nicht die
     * Datei: das Geburtsdatum darf es geben, nur nicht dort.
     */
    public function test_das_anmeldeformular_bleibt_ohne_geburtsdatum(): void
    {
        $html = $this->rendere($this->seite());

        $this->assertStringNotContainsString('wire:model="geburtsdatum"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    public function test_jeder_zustand_kompiliert_und_rendert(): void
    {
        foreach ([
            'formular', 'ohne-ziel', 'nummer', 'nummer-code',
            'vergessen', 'vergessen-code', 'notfall', 'notfall-code',
        ] as $zustand) {
            $seite = $this->seite();
            $this->setzeZustand($seite, $zustand);

            // Das Wortzeichen im Kopf steht in JEDEM Zustand - und es ist im
            // Markup zerlegt ("Rhein<span>Gedeck</span>"), deshalb wird die
            // Klasse gefragt und nicht der Name.
            $this->assertStringContainsString('class="wordmark"', $this->rendere($seite), "Zustand {$zustand}");
        }

        foreach (['nummer', 'passwort', 'notfall'] as $grund) {
            $seite = $this->seite();
            $this->setzeZustand($seite, 'fertig');
            $this->setzeFertigGrund($seite, $grund);

            $this->assertStringContainsString('wire:click="zurAnmeldung"', $this->rendere($seite));
        }
    }

    /**
     * Das Kommando laufen lassen.
     *
     * @param  array<string, string|bool>  $optionen
     * @return array{0: int, 1: string}  Rueckgabewert und Ausgabe
     */
    private function kommando(array $optionen): array
    {
        $command = new KontoZuruecksetzen();
        $command->setLaravel(new KontoZuruecksetzenFakeLaravel());

        $input = new ArrayInput($optionen, $command->getDefinition());
        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    // ----------------------------------------------------------------- Ablaeufe

    /** Weg 1+2 vollstaendig — fuer die Zusicherungen, die danach ansetzen. */
    private function wechsleDieNummer(): KontoAnmelden
    {
        $seite = $this->seite();
        $seite->zumNummernwechsel();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->nummerAnfordern($this->auth(), $this->sender());

        $seite->code = $this->codeAusDerNachricht();
        $seite->nummerBestaetigen($this->hinweisSender());

        return $seite;
    }

    /** Weg 4 bis zu dem Punkt, an dem der Code auf der Seite steht. */
    private function notfallBisZumCode(): KontoAnmelden
    {
        $seite = $this->seite();
        $seite->zumNotfall();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->geburtsdatum = self::GEBURT;
        // Die letzten vier Ziffern von 'L01X00T47'.
        $seite->ausweis = '0T47';
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->notfallAnfordern($this->sender());
        $seite->code = $this->codeAusDerNachricht();

        return $seite;
    }

    /** Weg 3 bis zu dem Punkt, an dem der Code auf der Seite steht. */
    private function vergessenBisZumCode(): KontoAnmelden
    {
        $seite = $this->seite();
        $seite->zumPasswortVergessen();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwortCodeAnfordern($this->sender());
        $seite->code = $this->codeAusDerNachricht();

        return $seite;
    }

    /**
     * $state ist #[Locked] und hat keinen Setzer — genau das ist der Schutz.
     * Fuer die Blade-Zusicherungen wird er deshalb ueber Reflexion gesetzt;
     * das ist KEIN Weg, der dem Browser offenstuende.
     */
    private function setzeZustand(KontoAnmelden $seite, string $zustand): void
    {
        (new \ReflectionProperty(KontoAnmelden::class, 'state'))->setValue($seite, $zustand);
    }

    private function setzeFertigGrund(KontoAnmelden $seite, string $grund): void
    {
        (new \ReflectionProperty(KontoAnmelden::class, 'fertigGrund'))->setValue($seite, $grund);
    }
}

/**
 * Nur so viel Laravel, wie Illuminate\Console\Command::run() braucht
 * (Muster: BackfillPersonsFakeLaravel): runningUnitTests(), sonst nichts.
 */
final class KontoZuruecksetzenFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
