<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\LoginNummer;

/**
 * Sebastian 22.10.2026: aendert HR die Handynummer in der Akte, wandert die
 * Login-Nummer der Person mit — aber nur ohne Konto. Mit Konto aendert der
 * Mensch sie selbst (Nummernwechsel per Code). Eine Nummer, die schon einer
 * anderen Person gehoert, bleibt stehen.
 */
final class LoginNummerTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(false);
    }

    private function personMit(?string $phone, bool $konto = false): int
    {
        $p = $this->person();
        Capsule::table('rec_persons')->where('id', $p)->update(['phone' => $phone, 'password_hash' => $konto ? 'hash' : null]);

        return $p;
    }

    public function test_ohne_konto_wandert_die_login_nummer_mit(): void
    {
        $p = $this->personMit('+4915111111111');
        $akte = $this->akte(['rec_person_id' => $p, 'phone' => '+49 151 22222222']);

        $status = LoginNummer::nachAktenAenderung(RecEmployee::find($akte));

        $this->assertSame(LoginNummer::GESETZT, $status);
        $this->assertSame('+4915122222222', Capsule::table('rec_persons')->where('id', $p)->value('phone'));
    }

    public function test_mit_konto_bleibt_die_login_nummer(): void
    {
        $p = $this->personMit('+4915111111111', konto: true);
        $akte = $this->akte(['rec_person_id' => $p, 'phone' => '+4915122222222']);

        $this->assertSame(LoginNummer::MIT_KONTO, LoginNummer::nachAktenAenderung(RecEmployee::find($akte)));
        $this->assertSame('+4915111111111', Capsule::table('rec_persons')->where('id', $p)->value('phone'));
    }

    public function test_vergebene_nummer_bleibt_stehen(): void
    {
        $this->personMit('+4915122222222');
        $p = $this->personMit('+4915111111111');
        $akte = $this->akte(['rec_person_id' => $p, 'phone' => '+4915122222222']);

        $this->assertSame(LoginNummer::BELEGT, LoginNummer::nachAktenAenderung(RecEmployee::find($akte)));
        $this->assertSame('+4915111111111', Capsule::table('rec_persons')->where('id', $p)->value('phone'));
    }

    public function test_die_schwester_akte_und_der_marker_bleiben_unberuehrt(): void
    {
        $p = $this->personMit('+4915111111111');
        $rg = $this->akte(['rec_person_id' => $p, 'phone' => '+4915122222222']);
        $ma = $this->akte(['rec_person_id' => $p, 'phone' => '+4915111111111']);

        LoginNummer::nachAktenAenderung(RecEmployee::find($rg));

        $this->assertSame('+4915111111111', $this->zeile($ma)->phone, 'nur die Login-Nummer, keine stille Aenderung der MA-Akte');
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    public function test_leere_nummer_aendert_nichts(): void
    {
        $p = $this->personMit('+4915111111111');
        $akte = $this->akte(['rec_person_id' => $p, 'phone' => null]);

        $this->assertSame(LoginNummer::OHNE, LoginNummer::nachAktenAenderung(RecEmployee::find($akte)));
        $this->assertSame('+4915111111111', Capsule::table('rec_persons')->where('id', $p)->value('phone'));
    }
}
