<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\SearchJump;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Sprung-Suche in der MA-Detailseite: "anderen Mitarbeiter finden, ohne eine
 * Ebene zurueck". Die Suche IST eine Query — deshalb Integrationstest gegen
 * echtes SQLite, Muster EmployeeMaSinceFilterTest.
 */
final class EmployeeSearchJumpTest extends TestCase
{
    private const TEAM = 7;
    private const OTHER_TEAM = 8;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher fallen die creating-Hooks (uuid) der Models aus.
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->capsule->schema()->dropAllTables();
        parent::tearDown();
    }

    private function employee(array $attributes = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'    => self::TEAM,
            'first_name' => 'Jeton',
            'last_name'  => 'Qafleshi',
            'email'      => 'jeton@example.test',
            'phone'      => '+4917612345',
            'is_active'  => true,
        ], $attributes));
    }

    /** @return list<string> */
    private function search(string $needle, ?int $currentId = null): array
    {
        return SearchJump::matches(self::TEAM, $needle, $currentId)
            ->pluck('last_name')
            ->all();
    }

    public function test_unter_zwei_zeichen_sucht_nicht(): void
    {
        $this->employee(['last_name' => 'Qafleshi']);

        $this->assertSame([], $this->search(''));
        $this->assertSame([], $this->search('Q'));
        $this->assertSame([], $this->search('  q  '));
        $this->assertSame(['Qafleshi'], $this->search('qa'));
    }

    public function test_findet_ueber_vorname_nachname_email_und_telefon(): void
    {
        $this->employee(['first_name' => 'Bernd',  'last_name' => 'Vorname',  'email' => 'a@x.test', 'phone' => '111']);
        $this->employee(['first_name' => 'A',      'last_name' => 'Nachname', 'email' => 'b@x.test', 'phone' => '222']);
        $this->employee(['first_name' => 'C',      'last_name' => 'Mail',     'email' => 'zielperson@x.test', 'phone' => '333']);
        $this->employee(['first_name' => 'D',      'last_name' => 'Telefon',  'email' => 'd@x.test', 'phone' => '+4917699988']);

        $this->assertSame(['Vorname'],  $this->search('bern'));
        $this->assertSame(['Nachname'], $this->search('nachn'));
        $this->assertSame(['Mail'],     $this->search('zielperson'));
        $this->assertSame(['Telefon'],  $this->search('99988'));
    }

    public function test_fremdes_team_bleibt_unsichtbar(): void
    {
        $this->employee(['last_name' => 'Eigen']);
        $this->employee(['last_name' => 'Fremd', 'team_id' => self::OTHER_TEAM]);

        $this->assertSame(['Eigen'], $this->search('e'. 'igen'));
        $this->assertSame([], $this->search('fremd'));
    }

    public function test_offener_mitarbeiter_faellt_aus_den_treffern(): void
    {
        $offen  = $this->employee(['last_name' => 'Offen']);
        $andere = $this->employee(['last_name' => 'Offenbach']);

        $this->assertSame(['Offenbach'], $this->search('offen', $offen->id));
        $this->assertSame(['Offen', 'Offenbach'], $this->search('offen', null));
        $this->assertSame(['Offen'], $this->search('offen', $andere->id));
    }

    public function test_inaktive_werden_gefunden(): void
    {
        $this->employee(['last_name' => 'Ausgeschieden', 'is_active' => false]);

        $this->assertSame(['Ausgeschieden'], $this->search('ausgesch'));
    }

    public function test_treffer_sind_auf_acht_begrenzt_die_gesamtzahl_bleibt_wahr(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->employee(['last_name' => 'Sammel' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $this->assertCount(8, SearchJump::matches(self::TEAM, 'sammel', null));
        $this->assertSame(11, SearchJump::countMatches(self::TEAM, 'sammel', null));
        $this->assertSame(0, SearchJump::countMatches(self::TEAM, 'q', null));
    }

    public function test_sortierung_nach_nachname_dann_vorname(): void
    {
        $this->employee(['first_name' => 'Zoe',  'last_name' => 'Sortier']);
        $this->employee(['first_name' => 'Anna', 'last_name' => 'Sortier']);
        $this->employee(['first_name' => 'Mike', 'last_name' => 'Aortier']);

        $this->assertSame(
            ['Aortier Mike', 'Sortier Anna', 'Sortier Zoe'],
            SearchJump::matches(self::TEAM, 'ortier', null)
                ->map(fn ($e) => $e->last_name . ' ' . $e->first_name)
                ->all()
        );
    }
}
