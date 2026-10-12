<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PortalAuthBremseTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function fuenfFehlversuche(string $nummer): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, $nummer, 'falsch-falsch-' . $i);
        }
    }

    public function test_gebremste_nummern_mit_frei_ab_in_einer_abfrage(): void
    {
        $this->fuenfFehlversuche('0151 11111111');
        $store = $this->cache->getStore();
        $store->manyAufrufe = 0;
        $store->getAufrufe = 0;

        $gebremst = $this->portalAuth()->gebremsteNummern(['+4915111111111', '+4915122222222']);

        $this->assertSame(['+4915111111111'], array_keys($gebremst), 'Normalisierung: getippt "0151…", gefragt "+49151…"');
        $this->assertSame('2026-10-12T10:15:00+00:00', $gebremst['+4915111111111']);
        $this->assertSame(1, $store->manyAufrufe, 'EIN many() fuer alle Nummern (Spec §3.2)');
        $this->assertSame(0, $store->getAufrufe, 'keine Einzelabfrage je Nummer');
        $this->assertSame([], $this->portalAuth()->gebremsteNummern([]));
    }

    public function test_bremse_loesen_laesst_die_ip_bremse_stehen(): void
    {
        $this->fuenfFehlversuche('+4915111111111');
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern(['+4915111111111']));
        $ipVorher = $this->ipSchluesselWerte();

        $this->portalAuth()->loeseNummernBremse('0151 11111111');

        $this->assertSame([], $this->portalAuth()->gebremsteNummern(['+4915111111111']));
        $this->assertSame($ipVorher, $this->ipSchluesselWerte(), 'IP-Zaehler unberuehrt');
        $this->assertNotSame([], $ipVorher, 'Anker: es gab einen IP-Zaehler');
    }

    public function test_altwert_true_aus_der_zeit_vor_dem_umbau_gilt_als_gebremst_ohne_zeit(): void
    {
        // Vor diesem Umbau stand "true" im Sperr-Schluessel; solche Werte leben
        // nach dem Deploy noch bis zu 15 Minuten weiter.
        $auth = $this->portalAuth();
        $schluessel = (new \ReflectionMethod($auth, 'nummernSchluessel'))->invoke($auth, '+4915111111111');
        $sperre = (new \ReflectionMethod($auth, 'lockoutKey'))->invoke($auth, $schluessel);
        $this->cache->put($sperre, true, 900);

        $this->assertSame(['+4915111111111' => null], $auth->gebremsteNummern(['+4915111111111']));
        $this->assertTrue($auth->isRateLimited($schluessel), 'Altwert sperrt weiter');
    }

    /** @return array<string, mixed> */
    private function ipSchluesselWerte(): array
    {
        $speicher = (new \ReflectionProperty($this->cache->getStore(), 'storage'))->getValue($this->cache->getStore());

        return array_filter($speicher, fn ($k) => str_contains((string) $k, ':ip:'), ARRAY_FILTER_USE_KEY);
    }
}
