<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Altes Portal (EmployeePortal), Spec HR-Bereich §9.6: ein zurueckgesetztes
 * Konto beendet auch einen schon offenen Tab — jede schreibende Aktion fragt
 * PortalAuth::sitzungGueltig(), nicht nur den #[Locked]-$state.
 */
final class EmployeePortalSitzungTest extends TestCase
{
    use HrBereichHarness;

    private object $dateien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();

        $this->dateien = new class {
            /** @var list<int> */
            public array $hochgeladen = [];
            private int $naechste = 5000;

            public function uploadForContext($file, string $typ, int $id, array $meta): array
            {
                $this->hochgeladen[] = $id;

                return ['id' => ++$this->naechste];
            }
        };
        Container::getInstance()->instance(ContextFileService::class, $this->dateien);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(ContextFileService::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    /** Ein Mensch im ALTEN Portal, angemeldet (Sitzungswert von heute), mit Personen-Zeile. */
    private function offenesPortal(): array
    {
        $ma = $this->akte([
            'portal_v2_since'  => null,
            'iban'             => 'DE89370400440532013000',
            'nationality'      => 'deutsch',
            'is_main_employer' => true,
        ]);
        $personId = $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());

        $portal = new EmployeePortal();
        $portal->mount((string) $ma->portal_token);
        $this->assertSame('verified', $portal->state, 'Vorbedingung: angemeldet');

        return [$ma, $personId, $portal];
    }

    private function zuruecksetzen(int $personId): void
    {
        \Carbon\Carbon::setTestNow('2026-10-12 10:05:00');
        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);
    }

    public function test_speichern_nach_dem_zuruecksetzen_schreibt_nichts(): void
    {
        [$ma, $personId, $portal] = $this->offenesPortal();

        // Gegenprobe: mit lebender Sitzung schreibt saveAll.
        $portal->fieldValues['city'] = 'Koeln';
        $portal->saveAll();
        $this->assertSame('Koeln', DB::table('rec_employees')->where('id', $ma->id)->value('city'));

        $this->zuruecksetzen($personId);
        $portal->fieldValues['iban'] = 'DE02120300000000202051';
        $portal->saveAll();

        $this->assertSame('DE89370400440532013000', DB::table('rec_employees')->where('id', $ma->id)->value('iban'));
        $this->assertSame('unverified', $portal->state);
        $this->assertSame([], $portal->fieldValues);
    }

    public function test_hochladen_nach_dem_zuruecksetzen_schreibt_nichts(): void
    {
        [$ma, $personId, $portal] = $this->offenesPortal();

        // Gegenprobe: mit lebender Sitzung geht der Upload durch.
        $portal->uploadIdentityFront = new \stdClass();
        $portal->updatedUploadIdentityFront();
        $this->assertSame([(int) $ma->id], $this->dateien->hochgeladen);
        $vorher = DB::table('rec_employees')->where('id', $ma->id)->value('identity_card_front_file_id');
        $this->assertNotNull($vorher);

        $this->zuruecksetzen($personId);
        $portal->uploadIdentityFront = new \stdClass();
        $portal->updatedUploadIdentityFront();

        $this->assertSame([(int) $ma->id], $this->dateien->hochgeladen, 'kein zweiter Upload');
        $this->assertSame($vorher, DB::table('rec_employees')->where('id', $ma->id)->value('identity_card_front_file_id'));
        $this->assertSame('unverified', $portal->state);
        $this->assertSame([], $portal->fieldValues);
    }

    public function test_sitzungsschluessel_kommt_aus_portal_auth(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Public/EmployeePortal.php');
        $this->assertStringNotContainsString('"employee_portal_verified:', $code, 'kein zweites Schluesselformat');
        $this->assertStringContainsString('PortalAuth::sessionKey(', $code);
    }
}
