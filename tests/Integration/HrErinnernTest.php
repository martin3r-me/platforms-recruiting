<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Console\Commands\SendProofReminders;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\ProofReminderSender;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\VertragErinnerungsPause;

final class HrErinnernTest extends TestCase
{
    use HrBereichHarness;

    private object $aufgaben;
    private object $nachweis;
    private object $dokument;
    private object $vertrag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();

        $this->aufgaben = new class {
            public array $versandt = [];
            public string $status = AufgabenSender::STATUS_SENT;
            public function sende(RecEmployee $e, array $stand, string $anlass): string
            {
                $this->versandt[] = ['ma' => (int) $e->id, 'anlass' => $anlass];

                return $this->status;
            }
            public function letzteNachrichtId(): ?int { return 77; }
        };
        $c->instance(AufgabenSender::class, $this->aufgaben);

        $c->instance(OffenePunkte::class, new class extends OffenePunkte {
            public array $punkte = [['code' => 'ausweis', 'label' => 'Ausweis', 'status' => 'fehlt', 'ko' => true]];
            public function fuer(RecEmployee $employee, ?string $heute = null): array
            {
                return ['punkte' => $this->punkte, 'einsatz' => null, 'gesperrt' => false];
            }
        });

        $this->nachweis = new class {
            public array $gesendet = [];
            public string $status = ProofReminderSender::STATUS_SENT;
            public function send(RecEmployee $ma, array $eintrag): array
            {
                $this->gesendet[] = $eintrag;

                return ['status' => $this->status, 'error' => null];
            }
        };
        $c->instance(ProofReminderSender::class, $this->nachweis);

        $this->dokument = new class extends DokumentService {
            public array $gesendet = [];
            public function erneutSenden(RecDocumentRecipient $empfaenger): string
            {
                $this->gesendet[] = (int) $empfaenger->id;
                DB::table('rec_document_recipients')->where('id', $empfaenger->id)->update(['notified_at' => now()]);

                return 'sent';
            }
        };
        $c->instance(DokumentService::class, $this->dokument);

        $this->vertrag = new class extends VertragHinweisSender {
            public array $gesendet = [];
            public function sende(RecEmployee $employee): string
            {
                $this->gesendet[] = (int) $employee->id;

                return 'sent';
            }
        };
        $c->instance(VertragHinweisSender::class, $this->vertrag);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([AufgabenSender::class, OffenePunkte::class, ProofReminderSender::class, DokumentService::class, VertragHinweisSender::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function aktionen(): HrKontoAktionen
    {
        return new HrKontoAktionen($this->portalAuth());
    }

    private function erinnertePerson(string $gemeldet = '2026-10-04 10:00:00'): int
    {
        $ma = $this->akte();

        return $this->person(['aufgaben_signatur' => 'alt', 'aufgaben_gemeldet_at' => $gemeldet], $ma);
    }

    public function test_aufgaben_ueber_den_bestehenden_sender_mit_stempel(): void
    {
        $id = $this->erinnertePerson();

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertTrue($e->istOk());
        // Markus 12.10.2026: HR „Erneut erinnern" schickt die Aufgaben-Vorlage 'neu'.
        $this->assertSame('neu', $this->aufgaben->versandt[0]['anlass']);
        $zeile = $this->personZeile($id);
        $this->assertSame('2026-10-12 10:00:00', (string) $zeile->aufgaben_gemeldet_at);
        $this->assertSame(77, (int) $zeile->aufgaben_nachricht_id);
        $v = $this->vermerke()->sole();
        $this->assertSame(['erneut_erinnert', 'aufgaben', 'sent', null], [$v->art, $v->bezug, $v->ergebnis, $v->notiz], 'Notiz optional');
    }

    /**
     * Nachtrag 13.10.2026: „Erneut erinnern" schreibt auch die gemeldeten
     * CODES. Sonst stuenden noch die der vorigen Meldung an der Person, und
     * die Einsatz-Pruefung hielte einen hier gerade gemeldeten Punkt fuer
     * neu und meldete ihn ein zweites Mal.
     */
    public function test_aufgaben_erneut_erinnern_aktualisiert_die_codes(): void
    {
        $id = $this->erinnertePerson();
        DB::table('rec_persons')->where('id', $id)->update(['aufgaben_codes' => '["selfie"]']);

        $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame('["ausweis"]', $this->personZeile($id)->aufgaben_codes);
    }

    public function test_aufgaben_fehlschlag_laesst_die_codes_stehen(): void
    {
        $id = $this->erinnertePerson();
        DB::table('rec_persons')->where('id', $id)->update(['aufgaben_codes' => '["selfie"]']);
        $this->aufgaben->status = AufgabenSender::STATUS_FAILED;

        $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame('["selfie"]', $this->personZeile($id)->aufgaben_codes, 'wie die Signatur: nur bei Erfolg');
    }

    public function test_aufgaben_fehlschlag_stempelt_trotzdem_nicht_konfiguriert_nicht(): void
    {
        $fehl = $this->erinnertePerson();
        $this->aufgaben->status = AufgabenSender::STATUS_FAILED;
        $e = $this->aktionen()->erinnereErneut($fehl, null, 'aufgaben');
        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertSame('2026-10-12 10:00:00', (string) $this->personZeile($fehl)->aufgaben_gemeldet_at, 'ET-23: auch nach Fehlschlag');

        $ohneVorlage = $this->erinnertePerson();
        $this->aufgaben->status = AufgabenSender::STATUS_NICHT_KONFIGURIERT;
        $e = $this->aktionen()->erinnereErneut($ohneVorlage, null, 'aufgaben');
        $this->assertStringContainsString('Vorlage fehlt', $e->meldung);
        $this->assertSame('2026-10-04 10:00:00', (string) $this->personZeile($ohneVorlage)->aufgaben_gemeldet_at, 'F1: kein Stempel');
        $this->assertSame(['failed', 'nicht_konfiguriert'], $this->vermerke()->pluck('ergebnis')->all(), 'jeder Versuch im Vermerk');
    }

    public function test_doppelklick_und_pause_ein_versand(): void
    {
        $id = $this->erinnertePerson();
        $this->aktionen()->erinnereErneut($id, null, 'aufgaben');
        $zweiter = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe);
        $this->assertStringContainsString('ab 19.10.2026', $zweiter->meldung);
        $this->assertCount(1, $this->aufgaben->versandt);

        $frisch = $this->erinnertePerson('2026-10-07 10:00:00');
        $this->assertStringContainsString('ab 14.10.2026', $this->aktionen()->erinnereErneut($frisch, null, 'aufgaben')->meldung, 'Spec offene Frage 2: Pause wird respektiert');
    }

    public function test_aufgaben_respektiert_die_laufsperre_der_einsatz_pruefung(): void
    {
        $id = $this->erinnertePerson();
        $sperre = Cache::lock(EinsatzPruefung::LAUF_SPERRE, 60);
        $this->assertTrue($sperre->get());

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('Einsatz-Prüfung', $e->meldung);
        $this->assertCount(0, $this->aufgaben->versandt);
        $sperre->release();
    }

    public function test_ohne_neues_portal_kein_versand(): void
    {
        $ma = $this->akte(['portal_v2_since' => null]);
        $id = $this->person(['aufgaben_signatur' => 'alt', 'aufgaben_gemeldet_at' => '2026-10-04 10:00:00'], $ma);

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertStringContainsString('nicht im neuen Portal', $e->meldung);
        $this->assertCount(0, $this->aufgaben->versandt);
    }

    public function test_nachweis_stempelt_nur_nach_erfolg(): void
    {
        $ma = $this->akte();
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-1', 'team_id' => $this->team, 'rec_employee_id' => $ma->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->nachweis->status = ProofReminderSender::STATUS_NOT_CONFIGURED;
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->stufe);
        $this->assertSame('2026-10-01 10:00:00', (string) DB::table('rec_employee_proofs')->where('id', $proof)->value('reminded_at'));

        $this->nachweis->status = ProofReminderSender::STATUS_SENT;
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->istOk());
        $this->assertSame('2026-10-12 10:00:00', (string) DB::table('rec_employee_proofs')->where('id', $proof)->value('reminded_at'));
        $this->assertSame(['proof_id' => $proof, 'rec_employee_id' => $ma->id, 'code' => 'ausweis', 'valid_until' => '2026-10-20'], $this->nachweis->gesendet[1]);
    }

    public function test_dokument_und_vertrag_ueber_ihre_sender_mit_pause(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-1', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'sign',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        $empf = (int) DB::table('rec_document_recipients')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'r-1', 'rec_document_id' => $dok, 'rec_employee_id' => $ma->id, 'notified_at' => '2026-10-01 10:00:00',
        ]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'dokument:' . $empf)->istOk());
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'vertrag:' . $v->id)->istOk());
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'dokument:' . $empf)->stufe);
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'vertrag:' . $v->id)->stufe, 'Pause ab letztem erneut_erinnert');
        $this->assertSame([$empf], $this->dokument->gesendet);
        $this->assertSame([$ma->id], $this->vertrag->gesendet);
    }

    public function test_bezug_aus_fremdem_team_und_unsinniger_bezug_sind_nicht_gefunden(): void
    {
        $fremd = $this->akte(['team_id' => 8]);
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-f', 'team_id' => 8, 'rec_employee_id' => $fremd->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
        ]);

        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->erinnereErneut(null, $fremd->id, 'nachweis:' . $proof)->meldung);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->erinnereErneut(null, null, 'irgendwas:1')->meldung);
        $this->assertSame([], $this->nachweis->gesendet);
    }

    /** Ruling R3: die Vertrags-Pause steht an EINER Stelle (auch fuer die Listen). */
    public function test_vertrags_pause_aus_einer_stelle(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $v1 = $this->vertragAn($ma, $this->vorlage('AV-1'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);
        $v2 = $this->vertragAn($ma, $this->vorlage('AV-2'), ['sent_at' => '2026-09-01 10:00:00'], ['herkunft' => 'akte']);
        \Platform\Recruiting\Models\RecHrVermerk::anfuegen($this->team, null, $ma->id, 'erneut_erinnert', null, 'vertrag:' . $v2->id, 'failed');
        \Platform\Recruiting\Models\RecHrVermerk::anfuegen(8, null, $ma->id, 'erneut_erinnert', null, 'vertrag:' . $v1->id, 'sent');

        $this->assertSame([], VertragErinnerungsPause::letzteErinnerungen($this->team, [$v1->id, $v2->id]), 'nur sent, nur eigenes Team');
        $this->assertSame('2026-10-01 10:00:00', VertragErinnerungsPause::stempel($v1->sent_at, null));
        $this->assertSame('2026-10-05 08:00:00', VertragErinnerungsPause::stempel($v1->sent_at, '2026-10-05 08:00:00'));
        $this->assertNull(VertragErinnerungsPause::stempel(null, null));

        \Platform\Recruiting\Models\RecHrVermerk::anfuegen($this->team, null, $ma->id, 'erneut_erinnert', null, 'vertrag:' . $v2->id, 'sent');
        $this->assertSame([$v2->id => '2026-10-12 10:00:00'], VertragErinnerungsPause::letzteErinnerungen($this->team, [$v1->id, $v2->id]));
    }

    /**
     * Fix-Runde 1, Important 1: der Stundenlauf stempelt, waehrend HR auf die
     * Laufsperre wartet. Die Pause muss UNTER der Sperre gelesen werden —
     * sonst geht eine zweite Nachricht raus.
     */
    public function test_aufgaben_pause_wird_unter_der_laufsperre_gelesen(): void
    {
        $id = $this->erinnertePerson();
        $store = new class($id) extends \Illuminate\Cache\ArrayStore {
            public function __construct(private int $personId)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null)
            {
                if ($name === EinsatzPruefung::LAUF_SPERRE) {
                    // Der Stundenlauf war schneller: verschickt und gestempelt.
                    DB::table('rec_persons')->where('id', $this->personId)->update(['aufgaben_gemeldet_at' => '2026-10-12 09:59:00']);
                }

                return parent::lock($name, $seconds, $owner);
            }
        };
        Container::getInstance()->instance('cache', new \Illuminate\Cache\Repository($store));
        Facade::clearResolvedInstance('cache');

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('ab 19.10.2026', $e->meldung);
        $this->assertCount(0, $this->aufgaben->versandt, 'kein Doppelversand');
        $this->assertCount(0, $this->vermerke());
    }

    /** Fix-Runde 1, Minor 3: Nachweis-Erinnerung respektiert die Sperre von recruiting:nachweise-erinnern. */
    public function test_nachweis_respektiert_die_laufsperre_des_crons(): void
    {
        $ma = $this->akte();
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-l', 'team_id' => $this->team, 'rec_employee_id' => $ma->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
        ]);
        $sperre = Cache::lock(SendProofReminders::LOCK_KEY, 60);
        $this->assertTrue($sperre->get());

        $e = $this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertSame([], $this->nachweis->gesendet);
        $sperre->release();
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->istOk());
    }

    /** Fix-Runde 1, Minor 3: Dokument/Vertrag laufen unter einer Sperre je Bezug — ein paralleler Klick wartet nicht, er weicht. */
    public function test_dokument_und_vertrag_unter_sperre_je_bezug(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-2', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'sign',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        $empf = (int) DB::table('rec_document_recipients')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'r-2', 'rec_document_id' => $dok, 'rec_employee_id' => $ma->id, 'notified_at' => '2026-10-01 10:00:00',
        ]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-L'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        foreach (['dokument:' . $empf, 'vertrag:' . $v->id] as $bezug) {
            $sperre = Cache::lock(HrKontoAktionen::erinnernSperre($bezug), 60);
            $this->assertTrue($sperre->get());
            $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, $bezug)->stufe, $bezug);
            $sperre->release();
        }
        $this->assertSame([], $this->dokument->gesendet);
        $this->assertSame([], $this->vertrag->gesendet);
        $this->assertCount(0, $this->vermerke());
    }

    /** Fix-Runde 1, Minor 4: Vermerk nie an einer teamfremden Person, auch nicht ueber den Bezug. */
    public function test_erinnern_ueber_akte_mit_fremder_person_vermerkt_nur_an_der_akte(): void
    {
        $ma = $this->akte();
        $this->person(['team_id' => 8], $ma);
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-x', 'team_id' => $this->team, 'rec_employee_id' => $ma->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
        ]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-X'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->istOk());
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'vertrag:' . $v->id)->istOk());

        $this->assertSame([null, null], $this->vermerke()->pluck('rec_person_id')->all());
        $this->assertSame([$ma->id, $ma->id], $this->vermerke()->pluck('rec_employee_id')->map(fn ($x) => (int) $x)->all());
    }
}
