<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\EmployerDeclaration;

/**
 * DIE ZUSAGE DIESES UMBAUS (Entscheidung 25.09.2026): Die
 * Arbeitgeber-Erklaerung wird beim Unterschreiben erfasst, aber NICHT in das
 * Vertragsdokument gerendert. Markus hat eine verbindliche Auswahl verlangt,
 * keine Aenderung am Vertragstext.
 *
 * Das ist nicht nur Zurueckhaltung: An der Vertrags-Darstellung haengt die
 * offene Altlast, dass "Felder -> Speichern" an unterschriebenen Vertraegen
 * neu rendert. Was wir dort nicht einfuegen, kann diese Altlast auch nicht
 * verschlimmern.
 *
 * Gemessen wird das Ergebnis, nicht der Quelltext: derselbe Vertrag, einmal
 * mit und einmal ohne Erklaerung in den Daten, muss Zeichen fuer Zeichen
 * gleich herauskommen.
 */
final class ContractDocumentUntouchedTest extends TestCase
{
    private const CONTENT = '<h2>§ 14 Schriftform</h2><p>Text</p><h2>§ 17 Schluss</h2><p>Ende</p>';

    private function par1516Data(): array
    {
        return [
            'par15_has_previous'   => true,
            'par15_entries'        => [
                ['beginn' => '2026-01-05', 'ende' => '2026-01-10', 'arbeitgeber' => 'Event Service', 'tage' => 4],
            ],
            'par16_was_jobseeking' => false,
            'par16_entries'        => [],
        ];
    }

    public function test_die_erklaerung_veraendert_das_dokument_nicht(): void
    {
        $ohne = RecContract::embedPreSigningData(self::CONTENT, $this->par1516Data());

        $mit = RecContract::embedPreSigningData(self::CONTENT, array_merge($this->par1516Data(), [
            EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_SECONDARY,
            EmployerDeclaration::KEY_OTHER => 'Musterkantine GmbH',
        ]));

        $this->assertSame($ohne, $mit, 'Die Arbeitgeber-Erklaerung darf im Vertragstext nicht auftauchen.');
    }

    public function test_weder_rolle_noch_arbeitgebername_stehen_im_text(): void
    {
        $html = RecContract::embedPreSigningData(self::CONTENT, array_merge($this->par1516Data(), [
            EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_SECONDARY,
            EmployerDeclaration::KEY_OTHER => 'Musterkantine GmbH',
        ]));

        $this->assertStringNotContainsString('Musterkantine', $html);
        $this->assertStringNotContainsString('Hauptarbeitgeber', $html);
        $this->assertStringNotContainsString('Nebenarbeitgeber', $html);
    }

    public function test_paragraf_15_und_16_werden_weiterhin_gerendert(): void
    {
        // Gegenprobe: der Test darf nicht deshalb gruen sein, weil gar
        // nichts mehr eingebaut wird.
        $html = RecContract::embedPreSigningData(self::CONTENT, $this->par1516Data());

        $this->assertStringContainsString('§ 15', $html);
        $this->assertStringContainsString('§ 16', $html);
        $this->assertStringContainsString('Event Service', $html);
    }
}
