<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;

class ZeitSchalterTest extends ZeitTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!Capsule::schema()->hasTable('rec_applicant_settings')) {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php')->up();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_applicant_settings')->delete();
        Container::getInstance()->instance('config', new ConfigRepository([]));
    }

    private function team(?int $id = self::TEAM): void
    {
        Container::getInstance()->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => $id]]]));
    }

    private function setting(array $werte, int $team = self::TEAM): void
    {
        Capsule::table('rec_applicant_settings')->insert(['team_id' => $team, 'settings' => json_encode($werte)]);
    }

    public function test_ohne_team_konfiguration_ist_aus(): void
    {
        $this->assertSame(0, (new ZeitSchalter())->teamId());
        $this->assertFalse((new ZeitSchalter())->aktiv());
    }

    public function test_team_aus_config(): void
    {
        $this->team();
        $this->assertSame(self::TEAM, (new ZeitSchalter())->teamId());
    }

    public function test_ohne_setting_ist_aus(): void
    {
        $this->team();
        $this->assertFalse((new ZeitSchalter())->aktiv());
        $this->assertSame(0, Capsule::table('rec_applicant_settings')->count(), 'Lesen legt keine Zeile an');
    }

    public function test_mit_setting_true_ist_an(): void
    {
        $this->team();
        $this->setting([ZeitSchalter::SETTING_AKTIV => true]);
        $this->assertTrue((new ZeitSchalter())->aktiv());
    }

    public function test_setting_false_ist_aus(): void
    {
        $this->team();
        $this->setting([ZeitSchalter::SETTING_AKTIV => false]);
        $this->assertFalse((new ZeitSchalter())->aktiv());
    }

    public function test_setting_eines_anderen_teams_zaehlt_nicht(): void
    {
        $this->team();
        $this->setting([ZeitSchalter::SETTING_AKTIV => true], self::TEAM + 1);
        $this->assertFalse((new ZeitSchalter())->aktiv());
    }

    public function test_fehler_beim_lesen_ist_aus(): void
    {
        $this->team();
        Capsule::schema()->drop('rec_applicant_settings');
        try {
            $this->assertFalse((new ZeitSchalter())->aktiv());
        } finally {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php')->up();
        }
    }

    public function test_darf_entsperren_ohne_gross_klein(): void
    {
        $this->team();
        $this->setting([ZeitSchalter::SETTING_ENTSPERREN => ['chef@rg.de', 'Anna@RG.de']]);
        $s = new ZeitSchalter();
        $this->assertTrue($s->darfEntsperren((object) ['email' => 'CHEF@rg.de']));
        $this->assertTrue($s->darfEntsperren((object) ['email' => ' anna@rg.de ']));
        $this->assertFalse($s->darfEntsperren((object) ['email' => 'dritter@rg.de']));
        $this->assertFalse($s->darfEntsperren(null));
        $this->assertFalse($s->darfEntsperren((object) ['email' => '']));
    }

    public function test_leere_liste_erlaubt_niemandem(): void
    {
        $this->team();
        $this->setting([ZeitSchalter::SETTING_ENTSPERREN => []]);
        $this->assertFalse((new ZeitSchalter())->darfEntsperren((object) ['email' => 'chef@rg.de']));
        $this->assertFalse((new ZeitSchalter())->darfEntsperren((object) ['email' => '']));
    }

    public function test_schluessel_namen(): void
    {
        $this->assertSame('zeit_stempeln_aktiv', ZeitSchalter::SETTING_AKTIV);
        $this->assertSame('zeit_entsperren_emails', ZeitSchalter::SETTING_ENTSPERREN);
    }

    /** Vertrag: ApplicantSettingsModal::save() uebernimmt beide Schluessel aus dem gespeicherten Stand. */
    public function test_settings_modal_uebernimmt_beide_schluessel_aus_dem_gespeicherten_stand(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Applicant/ApplicantSettingsModal.php');
        $this->assertMatchesRegularExpression(
            '/foreach\s*\(\s*\[[^\]]*ZeitSchalter::SETTING_AKTIV[^\]]*ZeitSchalter::SETTING_ENTSPERREN[^\]]*\]\s*as\s*\$schluessel\s*\)/s',
            $src
        );
    }
}
