<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zeit\Waesche\WaescheArtikel;
use Platform\Recruiting\Services\Zeit\Waesche\WaescheRechner;

/** Markus' Wäsche-Regeln (Folie 16 + Antworten 20.11.2026) als Rechnung. */
class WaescheRechnerTest extends TestCase
{
    private function p(string $key, int $anzahl, int $zurueck): array
    {
        return ['artikel' => WaescheArtikel::fest()[$key], 'anzahl' => $anzahl, 'zurueck' => $zurueck];
    }

    public function test_hemd_zurueck_kostet_die_reinigung_nicht_zurueck_den_preis(): void
    {
        $this->assertSame(400, WaescheRechner::rechne([$this->p('hemd', 1, 1)])['abzugCent']);
        $this->assertSame(2500, WaescheRechner::rechne([$this->p('hemd', 1, 0)])['abzugCent']);
        $this->assertSame(400, WaescheRechner::rechne([$this->p('schuerze', 1, 1)])['abzugCent']);
        $this->assertSame(1500, WaescheRechner::rechne([$this->p('schuerze', 1, 0)])['abzugCent']);
    }

    public function test_jacke_weste_zurueck_kostet_den_mitarbeiter_nichts_der_kunde_zahlt_jedes_ausgegebene_teil(): void
    {
        $zurueck = WaescheRechner::rechne([$this->p('jacke', 1, 1), $this->p('weste', 2, 2)]);
        $this->assertSame(0, $zurueck['abzugCent']);
        $this->assertSame(['jacke' => 1, 'weste' => 2], $zurueck['kunde']);

        $weg = WaescheRechner::rechne([$this->p('weste', 1, 0)]);
        $this->assertSame(3000, $weg['abzugCent'], 'nicht zurück: 30 € in den Lohn');
        $this->assertSame(['weste' => 1], $weg['kunde'], 'Leihgebühr an den Kunden trotzdem (Markus 20.11.)');
    }

    public function test_beispiel_aus_der_mail_hemd_zurueck_weste_nicht(): void
    {
        $r = WaescheRechner::rechne([$this->p('hemd', 1, 1), $this->p('weste', 1, 0)]);
        $this->assertSame(3400, $r['abzugCent']);
        $this->assertSame('34,00', WaescheRechner::euro($r['abzugCent']));
        $this->assertSame(['Weste'], $r['fehlt']);
        $this->assertSame(['Hemd', 'Weste'], $r['ausgegeben']);
        $this->assertFalse($r['allesZurueck']);
    }

    public function test_sonstiges_nur_bei_nicht_rueckgabe_der_eingetragene_preis(): void
    {
        $hose = WaescheArtikel::sonstiges('Hose', 1999);
        $this->assertSame(0, WaescheRechner::rechne([['artikel' => $hose, 'anzahl' => 1, 'zurueck' => 1]])['abzugCent']);
        $r = WaescheRechner::rechne([['artikel' => $hose, 'anzahl' => 2, 'zurueck' => 1]]);
        $this->assertSame(1999, $r['abzugCent']);
        $this->assertSame([], $r['kunde'], 'Sonstiges geht nicht automatisch an den Kunden');
    }

    public function test_teil_rueckgabe_und_grenzen(): void
    {
        $r = WaescheRechner::rechne([$this->p('hemd', 3, 2)]);
        $this->assertSame(2 * 400 + 2500, $r['abzugCent']);
        $this->assertSame(['Hemd'], $r['fehlt']);

        $this->assertSame(400, WaescheRechner::rechne([$this->p('hemd', 1, 5)])['abzugCent'], 'mehr zurück als ausgegeben zählt als alles zurück');
        $this->assertSame(2500, WaescheRechner::rechne([$this->p('hemd', 1, -3)])['abzugCent'], 'negativ zählt als nichts zurück');
        $leer = WaescheRechner::rechne([$this->p('hemd', 0, 0)]);
        $this->assertSame(0, $leer['abzugCent']);
        $this->assertFalse($leer['allesZurueck'], 'ohne Wäsche ist nichts „zurück“');
        $this->assertTrue(WaescheRechner::rechne([$this->p('hemd', 2, 2)])['allesZurueck']);
    }
}
