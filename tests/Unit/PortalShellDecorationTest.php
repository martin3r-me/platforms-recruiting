<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Support\ProofChecklist;

/**
 * Die Uebersetzung Status → Farbe und Satz. Sie entscheidet, was der Mensch
 * im Portal als dringend sieht — deshalb geprueft und nicht in Blade versteckt.
 */
class PortalShellDecorationTest extends TestCase
{
    private function zeile(string $status, ?string $bis = null, string $label = 'Personalausweis'): array
    {
        return [
            'code' => 'ausweis', 'label' => $label, 'status' => $status,
            'valid_until' => $bis, 'offen' => $status !== ProofChecklist::OK,
        ];
    }

    public function test_abgelaufen_ist_rot_und_nennt_das_datum(): void
    {
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::ABGELAUFEN, '2026-01-31')]);

        $this->assertSame('crit', $out[0]['punkt']);
        $this->assertSame('Abgelaufen am 31.01.2026', $out[0]['text']);
        $this->assertTrue($out[0]['offen']);
    }

    public function test_fehlend_ist_rot_denn_ohne_nachweis_geht_kein_einsatz(): void
    {
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::FEHLT)]);

        $this->assertSame('crit', $out[0]['punkt']);
        $this->assertSame('Fehlt noch', $out[0]['text']);
    }

    public function test_laeuft_ab_ist_gelb_und_nennt_das_datum(): void
    {
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::LAEUFT_AB, '2026-12-01')]);

        $this->assertSame('warn', $out[0]['punkt']);
        $this->assertSame('Läuft ab am 01.12.2026', $out[0]['text']);
    }

    public function test_in_ordnung_mit_datum_nennt_die_gueltigkeit(): void
    {
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::OK, '2030-06-30')]);

        $this->assertSame('ok', $out[0]['punkt']);
        $this->assertSame('Gültig bis 30.06.2030', $out[0]['text']);
        $this->assertFalse($out[0]['offen']);
    }

    public function test_in_ordnung_ohne_datum_sagt_nur_liegt_vor(): void
    {
        // Selfie und Krankenkassen-Nachweis laufen nicht ab — dort waere ein
        // erfundenes Datum schlimmer als keines.
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::OK, null, 'Selfie')]);

        $this->assertSame('ok', $out[0]['punkt']);
        $this->assertSame('Liegt vor', $out[0]['text']);
    }

    public function test_leere_liste_bleibt_leer(): void
    {
        $this->assertSame([], PortalShell::dekoriert([]));
    }

    public function test_reihenfolge_bleibt_wie_sie_kommt(): void
    {
        // Sortiert wird in ProofChecklist. Hier darf nichts umgestellt werden,
        // sonst stuende die dringendste Zeile nicht mehr oben.
        $out = PortalShell::dekoriert([
            $this->zeile(ProofChecklist::ABGELAUFEN, '2026-01-31', 'A'),
            $this->zeile(ProofChecklist::FEHLT, null, 'B'),
            $this->zeile(ProofChecklist::OK, null, 'C'),
        ]);

        $this->assertSame(['A', 'B', 'C'], array_column($out, 'label'));
    }
}
