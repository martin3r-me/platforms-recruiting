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

    public function test_code_wird_durchgereicht(): void
    {
        // Ohne den Code kann die Ansicht wire:click="oeffneUpload($code)"
        // nicht bauen — die Aufgabe waere anklickbar, aber ohne Ziel.
        $out = PortalShell::dekoriert([$this->zeile(ProofChecklist::FEHLT)]);

        $this->assertSame('ausweis', $out[0]['code']);
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

    // ---- Zusammenfuehrung OffenePunkte -> dekorierte Saetze (Kasten) ----

    private function punkt(string $code, string $label = 'X', string $status = ProofChecklist::FEHLT, bool $ko = false): array
    {
        return ['code' => $code, 'label' => $label, 'status' => $status, 'ko' => $ko];
    }

    public function test_der_kasten_bekommt_farbe_und_satz_aus_der_dekorierten_zeile(): void
    {
        $deko = PortalShell::dekoriert([$this->zeile(ProofChecklist::LAEUFT_AB, '2026-12-01')]);

        $out = PortalShell::punkteMitSaetzen([$this->punkt('ausweis', 'Personalausweis', ProofChecklist::LAEUFT_AB, true)], $deko);

        $this->assertSame('warn', $out[0]['punkt']);
        $this->assertSame('Läuft ab am 01.12.2026', $out[0]['text']);
        // Was OffenePunkte lieferte, bleibt unverfaelscht daneben stehen.
        $this->assertSame('ausweis', $out[0]['code']);
        $this->assertSame('Personalausweis', $out[0]['label']);
        $this->assertTrue($out[0]['ko']);
    }

    public function test_die_zusammenfuehrung_haengt_am_code_nicht_an_der_position(): void
    {
        $deko = PortalShell::dekoriert([
            ['code' => 'a', 'label' => 'A', 'status' => ProofChecklist::OK, 'valid_until' => null, 'offen' => false],
            ['code' => 'b', 'label' => 'B', 'status' => ProofChecklist::FEHLT, 'valid_until' => null, 'offen' => true],
        ]);

        $out = PortalShell::punkteMitSaetzen([$this->punkt('b', 'B')], $deko);

        $this->assertSame('Fehlt noch', $out[0]['text']);
        $this->assertSame('crit', $out[0]['punkt']);
    }

    public function test_ein_punkt_ohne_dekorierte_zeile_bleibt_trotzdem_in_der_liste(): void
    {
        // Ein offener Nachweis darf nie verschwinden, nur weil die zweite
        // Quelle ihn nicht kennt -- ohne Satz, aber mit Klickziel.
        $out = PortalShell::punkteMitSaetzen([$this->punkt('ausweis', 'Ausweis')], []);

        $this->assertCount(1, $out);
        $this->assertSame('ausweis', $out[0]['code']);
        $this->assertSame('crit', $out[0]['punkt']);
        $this->assertSame('', $out[0]['text']);
    }
}
