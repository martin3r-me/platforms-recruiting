<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\ContractDeclarationPlaceholders;

/**
 * MA-Arbeitsvertrag (Markus, Stand 2026): die Erklaerungen stehen in § 8
 * (kurzfristige Beschaeftigungen) und § 16 (beschaeftigungslose Zeiten),
 * § 17 ist „Verschiedenes“. Die Vorlage traegt dort Platzhalter; die
 * Antworten landen genau dort, ohne eigene Paragraphen-Ueberschrift.
 * Vorlagen ohne Platzhalter (RG) laufen unveraendert vor § 17.
 */
final class ContractDeclarationPlaceholderTest extends TestCase
{
    private const MA = '<h2>§ 8 Erklärung einer kurzfristigen Beschäftigung</h2><p>Frage</p>{{erklaerung_kurzfristig}}<p>Hinweis</p>'
        . '<h2>§ 15 Unfallverhütung</h2><p>…</p>'
        . '<h2>§16 Erklärung über beschäftigungslose Zeiten</h2>{{erklaerung_beschaeftigungslos}}'
        . '<h2>§ 17 Verschiedenes</h2><p>Merkblatt</p>';

    private const RG = '<h2>§ 14 Unfallverhütung</h2><p>Text</p><h2>§ 17 Salvatorische Klausel</h2><p>Ende</p>';

    private function daten(bool $kurz, bool $los): array
    {
        return [
            'par15_has_previous'   => $kurz,
            'par15_entries'        => $kurz ? [['beginn' => '2026-02-01', 'ende' => '2026-02-05', 'arbeitgeber' => 'Event Service', 'tage' => 4]] : [],
            'par16_was_jobseeking' => $los,
            'par16_entries'        => $los ? [['beginn' => '2026-03-01', 'ende' => '2026-03-31', 'arbeitsagentur' => 'Agentur Düsseldorf']] : [],
        ];
    }

    public function test_ma_vorlage_antworten_an_den_platzhaltern_ohne_eigene_paragraphen(): void
    {
        $html = RecContract::embedPreSigningData(self::MA, $this->daten(true, false));

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('§ 15 Angaben', $html, 'kein eingeschobener § 15');
        $this->assertStringNotContainsString('§ 16 Angaben', $html, 'kein eingeschobener § 16');
        $this->assertSame(1, substr_count($html, '§ 15'), 'nur Markus’ § 15 Unfallverhütung');

        // An der richtigen Stelle: die Tabelle steht zwischen § 8 und dem Hinweis, nicht vor § 17.
        $kurz = strpos($html, 'Event Service');
        $this->assertGreaterThan(strpos($html, '§ 8'), $kurz);
        $this->assertLessThan(strpos($html, '<p>Hinweis</p>'), $kurz);
        $nein = strpos($html, 'Nein');
        $this->assertGreaterThan(strpos($html, '§16 Erklärung'), $nein);
        $this->assertLessThan(strpos($html, '§ 17 Verschiedenes'), $nein);
    }

    public function test_ma_vorlage_beide_ja(): void
    {
        $html = RecContract::embedPreSigningData(self::MA, $this->daten(true, true));

        $this->assertStringContainsString('Event Service', $html);
        $this->assertStringContainsString('Agentur Düsseldorf', $html);
        $this->assertLessThan(strpos($html, '§ 17 Verschiedenes'), strpos($html, 'Agentur Düsseldorf'));
    }

    public function test_nur_ein_platzhalter_der_andere_teil_faellt_nicht_still_weg(): void
    {
        // Vorlage mit nur einem Platzhalter: die Antwort dort, der andere Teil
        // nicht verloren — er geht den alten Weg vor § 17, mit Ueberschrift.
        $html = RecContract::embedPreSigningData(
            '<h2>§ 8 Erklärung</h2>{{erklaerung_kurzfristig}}<p>Hinweis</p><h2>§ 17 Verschiedenes</h2>',
            $this->daten(false, true),
        );

        $this->assertStringNotContainsString('{{', $html);
        $nein = strpos($html, 'Nein');
        $this->assertGreaterThan(strpos($html, '§ 8'), $nein);
        $this->assertLessThan(strpos($html, '<p>Hinweis</p>'), $nein, 'kurzfristig steht am Platzhalter');
        $kopf = strpos($html, '§ 16 Angaben zu beschäftigungslosen Zeiten');
        $this->assertNotFalse($kopf);
        $this->assertGreaterThan(strpos($html, '<p>Hinweis</p>'), $kopf);
        $this->assertLessThan(strpos($html, '§ 17 Verschiedenes'), $kopf);
        $this->assertLessThan(strpos($html, '§ 17 Verschiedenes'), strpos($html, 'Agentur Düsseldorf'));
    }

    /** Review 27.10.: „{{ erklaerung_kurzfristig }}“ mit Leerzeichen ist derselbe Platzhalter. */
    public function test_platzhalter_mit_leerzeichen(): void
    {
        $html = RecContract::embedPreSigningData(
            '<h2>§ 8</h2>{{ erklaerung_kurzfristig }}<h2>§ 16</h2>{{erklaerung_beschaeftigungslos  }}<h2>§ 17 Verschiedenes</h2>',
            $this->daten(true, false),
        );

        $this->assertStringNotContainsString('erklaerung_', $html);
        $this->assertStringNotContainsString('§ 15 Angaben', $html);
        $this->assertTrue(ContractDeclarationPlaceholders::vorhanden('<p>{{ erklaerung_beschaeftigungslos }}</p>'));
    }

    /** Harter Stopp vor dem Unterschreiben: auch ein von Formatierung zerstueckelter Rest zaehlt. */
    public function test_offen_erkennt_reste_auch_zerstueckelt(): void
    {
        $this->assertTrue(ContractDeclarationPlaceholders::offen('<p>{{erklaerung_kurzfristig}}</p>'));
        $this->assertTrue(ContractDeclarationPlaceholders::offen('<p>{{<span>erklaerung_</span>kurzfristig}}</p>'));
        $this->assertTrue(ContractDeclarationPlaceholders::offen('<p>{{ erklaerung_beschaeftigungslos }}</p>'));
        $this->assertFalse(ContractDeclarationPlaceholders::offen(RecContract::embedPreSigningData(self::MA, $this->daten(true, true))));
        $this->assertFalse(ContractDeclarationPlaceholders::offen(self::RG));
    }

    public function test_rg_vorlage_ohne_platzhalter_unveraendert(): void
    {
        $html = RecContract::embedPreSigningData(self::RG, $this->daten(true, false));

        $this->assertStringContainsString('§ 15 Angaben zu kurzfristigen Beschäftigungen', $html);
        $this->assertStringContainsString('§ 16 Angaben zu beschäftigungslosen Zeiten', $html);
        $this->assertLessThan(strpos($html, '§ 17'), strpos($html, '§ 15 Angaben'));
    }

    public function test_erkennung(): void
    {
        $this->assertTrue(ContractDeclarationPlaceholders::vorhanden(self::MA));
        $this->assertFalse(ContractDeclarationPlaceholders::vorhanden(self::RG));
    }
}
