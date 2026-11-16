<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkEvent;
use Platform\Recruiting\Services\Flynk\FlynkMultipart;
use Platform\Recruiting\Services\Flynk\FlynkPostingPayloadBuilder;
use Platform\Recruiting\Services\Flynk\FlynkPostingState;
use Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider;
use Platform\Recruiting\Services\Flynk\FlynkResponseMapper;
use Platform\Recruiting\Services\Flynk\PostingAnzeigeStore;

/**
 * Stellenanzeige als Datei + Startdatum (16.11.2026) — die reinen Regeln.
 */
class FlynkAnzeigeDateiUnitTest extends TestCase
{
    // ---- Formularfelder fuer den Datei-Versand ----

    public function test_meta_wird_zu_meta_klammer_feldern_und_null_entfaellt(): void
    {
        $flat = FlynkMultipart::flatten([
            'title' => 'Stellenanzeige: X',
            'type' => 'new_section',
            'meta' => ['ref_code' => 'RG-FZCV', 'team_id' => 6, 'activity' => null, 'closes_at' => '2026-11-19'],
        ]);

        $this->assertSame([
            'title' => 'Stellenanzeige: X',
            'type' => 'new_section',
            'meta[ref_code]' => 'RG-FZCV',
            'meta[team_id]' => '6',
            'meta[closes_at]' => '2026-11-19',
        ], $flat);
    }

    // ---- Inhalts-Hash ----

    public function test_ohne_datei_bleibt_der_bisherige_hash(): void
    {
        $alt = hash('sha256', "T\nD\nA\nRG-1");

        $this->assertSame($alt, FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1'));
        $this->assertSame($alt, FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1', null));
        $this->assertSame($alt, FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1', ''));
    }

    public function test_datei_aendert_den_hash_und_ein_dateitausch_nochmal(): void
    {
        $ohne = FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1');
        $mitA = FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1', 'aaa');
        $mitB = FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1', 'bbb');

        $this->assertNotSame($ohne, $mitA);
        $this->assertNotSame($mitA, $mitB);
    }

    public function test_build_nimmt_die_datei_in_hash_text_und_meta(): void
    {
        $posting = [
            'uuid' => 'u-1', 'title' => 'T', 'description' => 'D', 'activity' => 'A', 'ref_code' => 'RG-1',
            'published_at' => '2026-10-23', 'closes_at' => '2026-11-16',
            'anzeige_hash' => 'aaa', 'anzeige_filename' => 'anzeige.pdf',
        ];

        $task = FlynkPostingPayloadBuilder::build($posting, FlynkEvent::PUBLISH, null);

        $this->assertSame(FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1', 'aaa'), $task->contentHash);
        $this->assertStringContainsString('liegt als Datei bei: anzeige.pdf', $task->payload['description']);
        $this->assertStringContainsString('Referenz-Code: RG-1', $task->payload['description']);
        $this->assertSame('2026-10-23', $task->payload['meta']['published_at']);
        $this->assertSame('2026-11-16', $task->payload['meta']['closes_at']);
    }

    public function test_build_ohne_datei_erwaehnt_keine_datei(): void
    {
        $task = FlynkPostingPayloadBuilder::build(
            ['title' => 'T', 'description' => 'D', 'activity' => 'A', 'ref_code' => 'RG-1'],
            FlynkEvent::PUBLISH,
            null
        );

        $this->assertStringNotContainsString('Datei', $task->payload['description']);
        $this->assertSame(FlynkPostingPayloadBuilder::contentHash('T', 'D', 'A', 'RG-1'), $task->contentHash);
    }

    // ---- Startdatum ----

    private function state(array $o = []): FlynkPostingState
    {
        return new FlynkPostingState(
            isOpen: $o['isOpen'] ?? true,
            contentHash: $o['contentHash'] ?? 'A',
            generation: 1,
            publishRowExists: $o['publishRowExists'] ?? false,
            publishSent: $o['publishSent'] ?? false,
            closeRowExists: $o['closeRowExists'] ?? false,
            lastDeliverableContentHash: $o['lastDeliverableContentHash'] ?? null,
            hasStarted: $o['hasStarted'] ?? true,
        );
    }

    public function test_vor_dem_startdatum_geht_keine_veroeffentlichung_raus(): void
    {
        $this->assertNull(FlynkPostingSyncDecider::decide($this->state(['hasStarted' => false])));
        $this->assertSame('publish', FlynkPostingSyncDecider::decide($this->state(['hasStarted' => true])));
    }

    public function test_startdatum_bremst_nur_die_erste_meldung_nicht_update_und_close(): void
    {
        $gemeldet = ['publishRowExists' => true, 'publishSent' => true, 'hasStarted' => false];

        $this->assertSame('update', FlynkPostingSyncDecider::decide(
            $this->state($gemeldet + ['contentHash' => 'B', 'lastDeliverableContentHash' => 'A'])
        ));
        $this->assertSame('close', FlynkPostingSyncDecider::decide($this->state($gemeldet + ['isOpen' => false])));
        // und vor allem: ein Startdatum in der Zukunft entfernt keine gemeldete Anzeige
        $this->assertNull(FlynkPostingSyncDecider::decide(
            $this->state($gemeldet + ['lastDeliverableContentHash' => 'A'])
        ));
    }

    public function test_haengende_veroeffentlichung_ist_vor_dem_startdatum_stale(): void
    {
        $rows = [['id' => 7, 'event_type' => 'publish'], ['id' => 8, 'event_type' => 'update']];

        $this->assertSame([7], FlynkPostingSyncDecider::staleRowIds(true, $rows, false));
        $this->assertSame([], FlynkPostingSyncDecider::staleRowIds(true, $rows, true));
        $this->assertSame([], FlynkPostingSyncDecider::staleRowIds(true, $rows), 'Default = gestartet, Bestand unveraendert');
    }

    // ---- Antwort von FLYNK ----

    public function test_201_mit_skipped_files_meldet_ein_dateiproblem(): void
    {
        $r = FlynkResponseMapper::map(201, [
            'id' => 5, 'attachments' => [], 'skipped_files' => [['name' => 'a.pdf', 'reason' => 'too large']],
        ], fileSent: true);

        $this->assertTrue($r->ok);
        $this->assertSame('5', $r->taskId);
        $this->assertNotNull($r->fileProblem);
        $this->assertStringContainsString('too large', $r->fileProblem);
    }

    public function test_201_mit_leeren_attachments_meldet_ein_dateiproblem(): void
    {
        $r = FlynkResponseMapper::map(201, ['id' => 5, 'attachments' => []], fileSent: true);

        $this->assertNotNull($r->fileProblem);
    }

    public function test_201_mit_angekommener_datei_ist_sauber(): void
    {
        $r = FlynkResponseMapper::map(201, [
            'id' => 5, 'attachments' => [['name' => 'a.pdf']], 'skipped_files' => [],
        ], fileSent: true);

        $this->assertTrue($r->ok);
        $this->assertNull($r->fileProblem);
    }

    public function test_ohne_mitgeschickte_datei_gibt_es_nie_ein_dateiproblem(): void
    {
        $ohneSchluessel = FlynkResponseMapper::map(201, ['id' => 5], fileSent: true);
        $jsonMeldung = FlynkResponseMapper::map(201, ['id' => 5, 'attachments' => []]);

        $this->assertNull($ohneSchluessel->fileProblem, 'fehlen beide Schluessel, wird nicht geraten');
        $this->assertNull($jsonMeldung->fileProblem, 'reine Text-Meldung hat keine Datei, die fehlen koennte');
    }

    public function test_413_und_415_sind_endgueltig_wie_422(): void
    {
        foreach ([413, 415, 422] as $status) {
            $r = FlynkResponseMapper::map($status, null, fileSent: true);
            $this->assertFalse($r->ok);
            $this->assertTrue($r->permanent, "HTTP {$status}");
        }
        $this->assertFalse(FlynkResponseMapper::map(503, null)->permanent, '5xx bleibt voruebergehend');
    }

    // ---- Kein Dauerfeuer nach endgueltigem Fehlschlag ----

    public function test_endgueltig_gescheiterter_inhalt_wird_nicht_erneut_gemeldet_voruebergehender_schon(): void
    {
        $basis = ['generation' => 1, 'event_type' => 'update', 'seq' => 1, 'content_hash' => 'B'];
        $publish = ['generation' => 1, 'event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent'];

        $endgueltig = [$publish, $basis + ['status' => 'failed', 'permanent_failure' => true]];
        $voruebergehend = [$publish, $basis + ['status' => 'failed', 'permanent_failure' => false]];
        $ohneSchluessel = [$publish, $basis + ['status' => 'failed']];

        $this->assertSame('B', FlynkPostingSyncDecider::lastDeliverableContentHash($endgueltig, 1));
        $this->assertNull(FlynkPostingSyncDecider::decide(FlynkPostingSyncDecider::buildState($endgueltig, true, 'B')));
        $this->assertSame('update', FlynkPostingSyncDecider::decide(FlynkPostingSyncDecider::buildState($endgueltig, true, 'C')), 'geaenderter Inhalt geht raus');

        $this->assertSame('A', FlynkPostingSyncDecider::lastDeliverableContentHash($voruebergehend, 1));
        $this->assertSame('update', FlynkPostingSyncDecider::decide(FlynkPostingSyncDecider::buildState($voruebergehend, true, 'B')), 'Selbstheilung wie bisher');
        $this->assertSame('A', FlynkPostingSyncDecider::lastDeliverableContentHash($ohneSchluessel, 1));
    }

    public function test_tote_veroeffentlichung_wird_nur_bei_geaendertem_inhalt_freigegeben(): void
    {
        $row = ['id' => 3, 'generation' => 1, 'event_type' => 'publish', 'content_hash' => 'A', 'status' => 'failed', 'permanent_failure' => true];

        $this->assertSame([3], FlynkPostingSyncDecider::deadPublishRowIds([$row], 1, 'B'));
        $this->assertSame([], FlynkPostingSyncDecider::deadPublishRowIds([$row], 1, 'A'), 'gleicher Inhalt: bleibt liegen');
        $this->assertSame([], FlynkPostingSyncDecider::deadPublishRowIds([['permanent_failure' => false] + $row], 1, 'B'), 'voruebergehend: Sache des Retry');
        $this->assertSame([], FlynkPostingSyncDecider::deadPublishRowIds([['status' => 'sent'] + $row], 1, 'B'));
        $this->assertSame([], FlynkPostingSyncDecider::deadPublishRowIds([['event_type' => 'update'] + $row], 1, 'B'));
        $this->assertSame([], FlynkPostingSyncDecider::deadPublishRowIds([$row], 2, 'B'), 'andere Generation');
    }

    // ---- Formular ----

    public function test_veroeffentlichen_behaelt_ein_startdatum_in_der_zukunft(): void
    {
        $jetzt = \Illuminate\Support\Carbon::parse('2026-11-02 10:00:00');
        $f = fn (?string $d) => \Platform\Recruiting\Livewire\Posting\Show::startdatumBeimVeroeffentlichen($d, $jetzt)->format('Y-m-d H:i');

        $this->assertSame('2026-11-20 00:00', $f('2026-11-20'), 'vorab geplant bleibt stehen');
        $this->assertSame('2026-11-02 10:00', $f(null));
        $this->assertSame('2026-11-02 10:00', $f(''));
        $this->assertSame('2026-11-02 10:00', $f('2026-11-02'), 'heute = jetzt');
        $this->assertSame('2026-11-02 10:00', $f('2026-10-01'), 'altes Datum einer frueheren Runde wird ersetzt');
    }

    public function test_warnung_an_der_ausschreibung_nur_fuer_den_heutigen_stand(): void
    {
        $f = [\Platform\Recruiting\Livewire\Posting\Show::class, 'flynkProblemAus'];

        $this->assertSame(['art' => 'datei', 'text' => 'Datei abgelehnt'], $f('sent', 'Datei abgelehnt', 'H', 'H'));
        $this->assertSame(['art' => 'fehler', 'text' => 'kaputt'], $f('failed', 'kaputt', 'H', 'H'));
        $this->assertNull($f('sent', null, 'H', 'H'), 'sauber zugestellt');
        $this->assertNull($f('sent', 'Datei abgelehnt', 'ALT', 'H'), 'inzwischen neue Datei: Warnung ist ueberholt');
        $this->assertNull($f(null, null, null, 'H'), 'noch nie gemeldet');
    }

    // ---- Grenzen der Datei ----

    public function test_nur_pdf_und_docx_bis_zur_grenze(): void
    {
        $this->assertNull(PostingAnzeigeStore::rejectionReason('Anzeige.PDF', 1000));
        $this->assertNull(PostingAnzeigeStore::rejectionReason('anzeige.docx', PostingAnzeigeStore::MAX_BYTES));
        $this->assertNotNull(PostingAnzeigeStore::rejectionReason('anzeige.png', 1000));
        $this->assertNotNull(PostingAnzeigeStore::rejectionReason('anzeige.doc', 1000));
        $this->assertNotNull(PostingAnzeigeStore::rejectionReason('anzeige', 1000));
        $this->assertNotNull(PostingAnzeigeStore::rejectionReason('anzeige.pdf', 0));
        $this->assertNotNull(PostingAnzeigeStore::rejectionReason('anzeige.pdf', PostingAnzeigeStore::MAX_BYTES + 1));
    }
}
