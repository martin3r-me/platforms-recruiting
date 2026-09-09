<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ZasPnrLookup;

/**
 * „Kam der Mitarbeiter mal in einer Lieferung mit und wurde abgelehnt?"
 *
 * Diese Frage war bisher nur ueber die laravel.log beantwortbar. Der Bericht
 * jedes Imports liegt aber als JSON in rec_zas_inbound_files.notes — mit
 * Personalnummer und Grund je Zeile. Genau das liest outcomeFromNotes() aus.
 *
 * Der Unterschied zaehlt: „ABGEWIESEN" heisst, die Zeile war da und wir haben
 * sie nicht verarbeitet (unser Problem, nachlaufbar). „nichts gefunden" heisst,
 * ZAS hat die Nummer nie geschickt (Frage an ZAS).
 */
class ZasPnrOutcomeFromNotesTest extends TestCase
{
    private function notes(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function test_abgewiesene_zeile_wird_mit_grund_gemeldet(): void
    {
        $notes = $this->notes([
            'created' => [],
            'failed' => [
                ['personnel_number' => 'MA17042', 'reason' => 'Zeile 3: Spaltenzahl weicht ab'],
            ],
        ]);

        $this->assertSame(
            'ABGEWIESEN: Zeile 3: Spaltenzahl weicht ab',
            ZasPnrLookup::outcomeFromNotes($notes, ['MA17042'])
        );
    }

    /** Eine wegen Ueberlaenge komplett abgewiesene Lieferung hat kein failed[]. */
    public function test_komplett_abgewiesene_lieferung_wird_erkannt(): void
    {
        $notes = $this->notes(['rejected' => 'zu viele Zeilen (1200 > 1000)']);

        $this->assertSame(
            'ABGEWIESEN (ganze Lieferung): zu viele Zeilen (1200 > 1000)',
            ZasPnrLookup::outcomeFromNotes($notes, ['MA17042'])
        );
    }

    public function test_erfolgreiche_verarbeitung_wird_benannt(): void
    {
        $created = $this->notes(['created' => [['personnel_number' => 'RG17745']]]);
        $updated = $this->notes(['updated' => [['personnel_number' => 'RG17745', 'changed' => ['personnel_number']]]]);
        $skipped = $this->notes(['skipped' => [['personnel_number' => 'RG17745', 'reason' => 'exists']]]);

        $this->assertSame('angelegt', ZasPnrLookup::outcomeFromNotes($created, ['RG17745']));
        $this->assertSame('aktualisiert', ZasPnrLookup::outcomeFromNotes($updated, ['RG17745']));
        $this->assertSame('uebersprungen: exists', ZasPnrLookup::outcomeFromNotes($skipped, ['RG17745']));
    }

    /** Der Fehler gewinnt: er ist die Auskunft, auf die es ankommt. */
    public function test_failed_gewinnt_vor_den_uebrigen_toepfen(): void
    {
        $notes = $this->notes([
            'skipped' => [['personnel_number' => 'MA124', 'reason' => 'exists']],
            'failed' => [['personnel_number' => 'MA124', 'reason' => 'ZasPersonalNr fehlt']],
        ]);

        $this->assertStringStartsWith('ABGEWIESEN', ZasPnrLookup::outcomeFromNotes($notes, ['MA124']));
    }

    public function test_andere_nummer_in_derselben_lieferung_faellt_nicht_darauf(): void
    {
        $notes = $this->notes(['failed' => [['personnel_number' => 'MA99999', 'reason' => 'kaputt']]]);

        $this->assertNull(ZasPnrLookup::outcomeFromNotes($notes, ['MA17042']));
    }

    /** Alle Schreibweisen der Nummer treffen — Kurzform inklusive. */
    public function test_jede_uebergebene_schreibweise_trifft(): void
    {
        $notes = $this->notes(['created' => [['personnel_number' => 'MA124']]]);

        $this->assertSame('angelegt', ZasPnrLookup::outcomeFromNotes($notes, ['MA1000000124', 'MA124']));
    }

    /**
     * Eine Zeile ohne Personalnummer (der Import weist sie ab, weil ihm der
     * Dubletten-Schluessel fehlt) ist nicht zuordenbar — dann darf die Auskunft
     * nicht raten.
     */
    public function test_zeile_ohne_nummer_ist_nicht_zuordenbar(): void
    {
        $notes = $this->notes(['failed' => [['personnel_number' => null, 'reason' => 'ZasPersonalNr fehlt']]]);

        $this->assertNull(ZasPnrLookup::outcomeFromNotes($notes, ['MA17042']));
    }

    public function test_leere_und_kaputte_notizen_sind_kein_fehler(): void
    {
        $this->assertNull(ZasPnrLookup::outcomeFromNotes(null, ['MA1']));
        $this->assertNull(ZasPnrLookup::outcomeFromNotes('', ['MA1']));
        $this->assertNull(ZasPnrLookup::outcomeFromNotes('kein json', ['MA1']));
    }
}
