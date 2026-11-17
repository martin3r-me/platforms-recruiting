<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Middleware\RecruitingAccessGate;
use Platform\Recruiting\Support\EingeschraenktesKontoTeam;
use Platform\Recruiting\Support\RecruitingRouteGate;

/**
 * Vorfall event@, 16.11.2026: Ein Klick im Team-Menue der Bewerten-Seite
 * stellte das Sammelkonto auf sein persoenliches Team — dort ist Recruiting
 * nicht freigeschaltet, alle Nutzer des Kontos sahen "403 Keine Berechtigung".
 * Eingeschraenkte Konten werden deshalb aufs Anker-Team zurueckgestellt.
 */
class EingeschraenktesKontoTeamTest extends TestCase
{
    private const ANKER = 3;

    protected function setUp(): void
    {
        $container = Container::getInstance();
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => self::ANKER]]]));
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    }

    public function test_falsches_team_und_mitglied_im_anker_wird_zurueckgestellt(): void
    {
        $this->assertSame(3, EingeschraenktesKontoTeam::ziel(true, 14, 3, fn () => true));
        $this->assertSame(3, EingeschraenktesKontoTeam::ziel(true, null, 3, fn () => true), 'ohne Team');
    }

    public function test_nichts_zu_tun(): void
    {
        $nie = function (): bool {
            $this->fail('Mitgliedschaft darf hier nicht geprueft werden');
        };
        $this->assertNull(EingeschraenktesKontoTeam::ziel(false, 14, 3, $nie), 'normaler Nutzer wird nie angefasst');
        $this->assertNull(EingeschraenktesKontoTeam::ziel(true, 3, 3, $nie), 'steht schon richtig: keine Abfrage');
        $this->assertNull(EingeschraenktesKontoTeam::ziel(true, 14, 0, $nie), 'ohne Anker-Team');
        $this->assertNull(EingeschraenktesKontoTeam::ziel(true, 14, 3, fn () => false), 'kein Mitglied im Anker-Team');
    }

    public function test_nur_aufrufe_ins_recruiting_und_die_landeseite(): void
    {
        $this->assertTrue(RecruitingRouteGate::betrifft('recruiting.dispo.events.index'));
        $this->assertTrue(RecruitingRouteGate::betrifft('recruiting.training-review.index'));
        $this->assertTrue(RecruitingRouteGate::betrifft('platform.dashboard'));
        $this->assertFalse(RecruitingRouteGate::betrifft('organization.dashboard'), 'andere Module in anderen Teams bleiben unberuehrt');
        $this->assertFalse(RecruitingRouteGate::betrifft('logout'));
    }

    public function test_middleware_stellt_das_konto_um_und_laesst_normale_in_ruhe(): void
    {
        $konto = $this->konto(teamId: 14, mitglied: true);
        $this->rufe($konto, true);
        $this->assertSame(3, $konto->current_team_id);
        $this->assertSame(1, $konto->gespeichert);
        $this->assertSame(['currentTeamRelation'], $konto->verworfen, 'sonst prueft der Core gegen das alte Team');

        $richtig = $this->konto(teamId: 3, mitglied: true);
        $this->rufe($richtig, true);
        $this->assertSame(0, $richtig->gespeichert);
        $this->assertSame(0, $richtig->abfragen, 'steht richtig: keine Abfrage je Aufruf');

        $normal = $this->konto(teamId: 14, mitglied: true);
        $this->rufe($normal, false);
        $this->assertSame(14, $normal->current_team_id);
        $this->assertSame(0, $normal->gespeichert);

        $fremd = $this->konto(teamId: 14, mitglied: false);
        $this->rufe($fremd, true);
        $this->assertSame(14, $fremd->current_team_id, 'kein Mitglied im Anker-Team: nichts umstellen');
    }

    public function test_fehler_beim_umstellen_bricht_den_aufruf_nicht(): void
    {
        $konto = $this->konto(teamId: 14, mitglied: true, wirft: true);
        $this->rufe($konto, true);
        $this->assertSame(14, $konto->current_team_id);
        $this->rufe(null, true);
        $this->addToAssertionCount(1);
    }

    /** Reihenfolge an der Quelle: erst zurueckstellen, dann umleiten — und nur fuer betroffene Routen. */
    public function test_verdrahtung_in_der_middleware(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Middleware/RecruitingAccessGate.php');
        $stellen = strpos($src, '$this->teamZurueckstellen($user, ($eventOnly || $trainingLeader) && RecruitingRouteGate::betrifft($name));');
        $umleiten = strpos($src, 'RecruitingRouteGate::redirectTo(');
        $this->assertNotFalse($stellen);
        $this->assertLessThan($umleiten, $stellen);
    }

    private function rufe(?object $konto, bool $eingeschraenkt): void
    {
        $m = new \ReflectionMethod(RecruitingAccessGate::class, 'teamZurueckstellen');
        $m->setAccessible(true);
        $m->invoke(new RecruitingAccessGate(), $konto, $eingeschraenkt);
    }

    private function konto(int $teamId, bool $mitglied, bool $wirft = false): object
    {
        return new class($teamId, $mitglied, $wirft) {
            public int $gespeichert = 0;
            public int $abfragen = 0;
            public array $verworfen = [];
            public ?int $current_team_id;

            public function __construct(int $teamId, private bool $mitglied, private bool $wirft)
            {
                $this->current_team_id = $teamId;
            }

            public function teams(): object
            {
                $this->abfragen++;
                $mitglied = $this->mitglied;

                return new class($mitglied) {
                    public function __construct(private bool $mitglied) {}

                    public function whereKey(int $id): self
                    {
                        return $this;
                    }

                    public function exists(): bool
                    {
                        return $this->mitglied;
                    }
                };
            }

            public function forceFill(array $werte): self
            {
                if ($this->wirft) {
                    throw new \RuntimeException('Datenbank weg');
                }
                $this->current_team_id = $werte['current_team_id'];

                return $this;
            }

            public function save(): bool
            {
                $this->gespeichert++;

                return true;
            }

            public function unsetRelation(string $name): self
            {
                $this->verworfen[] = $name;

                return $this;
            }

            public function getKey(): int
            {
                return 13;
            }
        };
    }
}
