<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Services\OffenePunkte;

final class HrMitarbeiterListenTest extends TestCase
{
    use HrBereichHarness;

    private object $punkte;
    private bool $gescheitert = false;
    private int $einsatzNr = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $this->punkte = new class extends OffenePunkte {
            public array $liste = [['code' => 'ausweis', 'label' => 'Personalausweis', 'status' => 'fehlt', 'ko' => true]];
            public int $aufrufe = 0;
            public function fuer(RecEmployee $employee, ?string $heute = null): array
            {
                $this->aufrufe++;

                return ['punkte' => $this->liste, 'einsatz' => null, 'gesperrt' => false];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function listen(): HrMitarbeiterListen
    {
        return new HrMitarbeiterListen($this->portalAuth(), $this->punkte, fn (int $id) => $this->gescheitert);
    }

    private function einsatz(RecEmployee $a, string $datum, int $status = 1): void
    {
        $this->einsatzNr++;
        DB::table('rec_dispo_assignments')->insert([
            'uuid' => 'as-' . $this->einsatzNr, 'ds_ref' => 'DS' . $this->einsatzNr, 'rec_dispo_event_id' => 1,
            'pnr_raw' => 'MA' . $a->id, 'rec_employee_id' => $a->id, 'datum' => $datum, 'status_id' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nachweis(RecEmployee $a, array $set = []): int
    {
        return (int) DB::table('rec_employee_proofs')->insertGetId(array_merge([
            'uuid' => 'pr-' . bin2hex(random_bytes(4)), 'team_id' => $a->team_id, 'rec_employee_id' => $a->id,
            'proof_type_code' => 'ausweis', 'valid_until' => '2026-10-20', 'version' => 1,
            'reminded_at' => '2026-10-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ], $set));
    }

    private function erinnert(int $personId, string $am = '2026-10-07 10:00:00'): void
    {
        DB::table('rec_persons')->where('id', $personId)->update(['aufgaben_signatur' => 'sig', 'aufgaben_gemeldet_at' => $am, 'aufgaben_nachricht_id' => 5]);
    }

    /** Review Focus 4. */
    public function test_rg_und_ma_derselben_person_sind_eine_zeile_in_allen_listen(): void
    {
        $ma = $this->akte(['phone' => '+4915111111111', 'first_name' => 'Rosa', 'last_name' => 'Rot']);
        $rg = $this->akte(['phone' => '+4915111111111', 'first_name' => 'Rosa', 'last_name' => 'Rot', 'company' => 'RG']);
        $id = $this->person([
            'wechsel_neue_nummer' => '+4917000000001', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall',
            'locked_at' => now(),
        ], $ma, $rg);
        DB::table('rec_employees')->whereIn('id', [$ma->id, $rg->id])->update(['portal_locked_at' => '2026-10-10 16:00:00']);
        $this->erinnert($id);
        $this->nachweis($rg);
        $l = $this->listen();

        foreach (['antraege' => $l->antraege($this->team), 'gesperrt' => $l->gesperrt($this->team), 'erinnert' => $l->erinnert($this->team), 'pilot' => $l->pilot($this->team)['zeilen']] as $liste => $zeilen) {
            $this->assertCount(1, $zeilen, $liste);
            $this->assertSame(['MA', 'RG'], array_column($zeilen[0]['akten'], 'firma'), $liste);
            $this->assertSame('Rosa Rot', $zeilen[0]['name'], $liste);
        }
        $this->assertSame(['aufgaben', 'nachweis'], array_column($l->erinnert($this->team)[0]['chips'], 'quelle'), 'zwei Chips, eine Zeile');
    }

    public function test_akte_ohne_personen_zeile_ist_eine_eigene_zeile(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);

        $zeilen = $this->listen()->gesperrt($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame('e:' . $a->id, $zeilen[0]['schluessel']);
        $this->assertNull($zeilen[0]['person_id']);
    }

    public function test_team_b_mit_derselben_nummer_erscheint_nirgends_und_wird_nicht_genannt(): void
    {
        $eigen = $this->akte(['phone' => '+4915111111111']);
        $this->person([], $eigen);
        $fremd = $this->akte(['team_id' => 8, 'phone' => '+4915111111111', 'first_name' => 'Bert', 'last_name' => 'Fremd',
            'portal_locked_at' => '2026-10-10 16:00:00']);
        $fremdId = $this->person(['team_id' => 8, 'locked_at' => now(), 'wechsel_neue_nummer' => '+4917000000002',
            'wechsel_beantragt_at' => '2026-10-12 09:00:00', 'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $fremd);
        $this->erinnert($fremdId);
        $this->nachweis($fremd);
        $l = $this->listen();

        $this->assertSame([], $l->antraege($this->team));
        $this->assertSame([], $l->erinnert($this->team));
        $gesperrt = $l->gesperrt($this->team);
        $this->assertCount(1, $gesperrt, 'nur die eigene Person, mit Chip "mehrdeutig"');
        $this->assertSame(['mehrdeutig'], array_column($gesperrt[0]['gruende'], 'code'));
        $json = json_encode($gesperrt);
        $this->assertStringNotContainsString('Bert', $json);
        // Rohtexte statt json_encode: "—" (Gedankenstrich) enthielte sonst die Ziffer 2.
        $this->assertDoesNotMatchRegularExpression('/\b' . $fremdId . '\b/', implode(' | ', array_column($gesperrt[0]['gruende'], 'text')));
        $this->assertStringNotContainsString('15111111111', json_encode($gesperrt), 'keine volle Nummer');
        $this->assertCount(1, $l->pilot($this->team)['zeilen']);
    }

    public function test_antraege_gesperrt_stillgelegt_und_notfallweg_aus(): void
    {
        $g = $this->akte();
        $gesperrt = $this->person(['locked_at' => now(), 'wechsel_neue_nummer' => '+4917000000003', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 08:00:00', 'wechsel_quelle' => 'notfall'], $g);
        $ziel = $this->akte(['first_name' => 'Zora']);
        $zielId = $this->person([], $ziel);
        $still = $this->person(['phone' => '+4915199999999', 'merged_into_person_id' => $zielId, 'wechsel_neue_nummer' => '+4917000000004',
            'wechsel_beantragt_at' => '2026-10-12 09:00:00', 'wechsel_wirksam_ab' => '2026-10-12 09:30:00', 'wechsel_quelle' => 'notfall']);
        $o = $this->akte();
        $offen = $this->person(['wechsel_neue_nummer' => '+4917000000005', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $o);

        $zeilen = collect($this->listen()->antraege($this->team))->keyBy('person_id');

        $this->assertSame('gesperrt, wird nie angewendet', $zeilen[$gesperrt]['zustand']);
        $this->assertFalse($zeilen[$gesperrt]['kann_freigeben']);
        $this->assertSame('stillgelegt, wird nie angewendet', $zeilen[$still]['zustand']);
        $this->assertSame('Zora Muster', $zeilen[$still]['name'], 'Name von der Zielzeile');
        $this->assertFalse($zeilen[$still]['kann_freigeben']);
        $this->assertSame('Notfallweg aus, wartet auf HR', $zeilen[$offen]['zustand'], 'Team-Schalter steht auf Vorgabe aus');
        $this->assertTrue($zeilen[$offen]['kann_freigeben']);
        $this->assertSame('…0005', $zeilen[$offen]['neu']);
        $this->assertSame('13.10., 09:00 (noch 23 Std.)', $zeilen[$offen]['wirksam']);
        $this->assertSame('Nummer + Passwort verloren', $zeilen[$offen]['quelle_text']);
        $this->assertSame(array_keys($zeilen->all()), [$still, $gesperrt, $offen], 'sortiert nach Faelligkeit');

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->assertSame('offen', collect($this->listen()->antraege($this->team))->keyBy('person_id')[$offen]['zustand']);
    }

    public function test_gesperrt_je_grund_und_dispo_an_inaktiver_akte_nicht(): void
    {
        $inaktiv = $this->akte(['is_active' => false, 'portal_locked_at' => '2026-10-10 16:00:00']);
        $this->person([], $inaktiv);
        $aktiv = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: X']);
        $notfall = $this->akte();
        $notfallId = $this->person(['notfall_gesperrt_bis' => '2026-10-19 10:00:00'], $notfall);
        $abgelaufen = $this->akte();
        $this->person(['notfall_gesperrt_bis' => '2026-10-11 10:00:00'], $abgelaufen);

        $zeilen = collect($this->listen()->gesperrt($this->team))->keyBy('employee_id');

        $this->assertFalse($zeilen->has($inaktiv->id), 'GD-9: Dispo-Sperre an beendeter Anstellung nicht gelistet');
        $this->assertSame('dispo', $zeilen[$aktiv->id]['gruende'][0]['code']);
        $this->assertSame($aktiv->id, $zeilen[$aktiv->id]['gruende'][0]['employee_id']);
        $this->assertSame('notfall', $zeilen[$notfall->id]['gruende'][0]['code']);
        $this->assertSame($notfallId, $zeilen[$notfall->id]['person_id']);
        $this->assertFalse($zeilen->has($abgelaufen->id));
    }

    public function test_drossel_in_einer_cache_abfrage_und_ohne_bremse_gar_keine(): void
    {
        foreach (['+4915110000001', '+4915110000002', '+4915110000003'] as $n) {
            $this->person([], $this->akte(['phone' => $n]));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, '+4915110000002', 'falsch-' . $i);
        }
        $store = $this->cache->getStore();
        $store->manyAufrufe = 0;
        $store->getAufrufe = 0;

        $zeilen = $this->listen()->gesperrt($this->team);

        $this->assertSame(1, $store->manyAufrufe);
        $this->assertSame(0, $store->getAufrufe);
        $this->assertCount(1, $zeilen);
        $this->assertSame('bremse', $zeilen[0]['gruende'][0]['code']);

        $store->manyAufrufe = 0;
        $this->assertSame([], $this->listen()->gesperrt($this->team, '', false));
        $this->assertSame(0, $store->manyAufrufe, 'Abzeichen-Pfad fragt den Cache nicht');
    }

    public function test_erinnert_grenze_genau_x_tage(): void
    {
        $genau = $this->person([], $this->akte(['first_name' => 'Genau']));
        $this->erinnert($genau, '2026-10-07 10:00:00');
        $knapp = $this->person([], $this->akte(['first_name' => 'Knapp']));
        $this->erinnert($knapp, '2026-10-07 10:00:01');

        $namen = array_column($this->listen()->erinnert($this->team), 'name');

        $this->assertSame(['Genau Muster'], $namen, 'X = 5: genau 5 Tage ja, eine Sekunde weniger nein');

        $this->einstellung('hr_erinnert_tage', 6);
        $this->assertSame([], $this->listen()->erinnert($this->team));
    }

    public function test_erinnert_quellen_fallen_weg_wenn_erledigt(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        DB::table('rec_persons')->where('id', $id)->update(['aufgaben_signatur' => null, 'aufgaben_gemeldet_at' => '2026-10-01 10:00:00']);
        $this->nachweis($a, ['superseded_at' => now()]);
        $this->nachweis($a, ['valid_until' => '2027-06-01']);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-1', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'sign',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        DB::table('rec_document_recipients')->insert(['team_id' => $this->team, 'uuid' => 'r-1', 'rec_document_id' => $dok,
            'rec_employee_id' => $a->id, 'notified_at' => '2026-10-01 10:00:00', 'signed_at' => '2026-10-02 10:00:00']);
        $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00']);

        $this->assertSame([], $this->listen()->erinnert($this->team), 'Signatur geraeumt, neuere Version, noch nicht faellig, unterschrieben, Vertrag nicht aus der Akte');

        $this->erinnert($id);
        $this->punkte->liste = [];
        $this->assertSame([], $this->listen()->erinnert($this->team), 'Live-Liste leer: Zeile verschwindet');
    }

    public function test_dokument_und_akte_vertrag_sind_chips(): void
    {
        $a = $this->akte();
        $this->person([], $a);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-2', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'acknowledge',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        DB::table('rec_document_recipients')->insert(['team_id' => $this->team, 'uuid' => 'r-2', 'rec_document_id' => $dok,
            'rec_employee_id' => $a->id, 'notified_at' => '2026-10-01 10:00:00']);
        $v = $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $chips = $this->listen()->erinnert($this->team)[0]['chips'];

        $this->assertSame(['dokument', 'vertrag'], array_column($chips, 'quelle'));
        $this->assertSame('vertrag:' . $v->id, $chips[1]['bezug']);
        $this->assertSame('Dokument: Hausordnung · erinnert vor 11 Tagen', $chips[0]['text']);
        $this->assertTrue($chips[0]['kann_erinnern']);
    }

    public function test_sortiert_nach_naechstem_einsatz_ohne_einsatz_ans_ende(): void
    {
        foreach (['Ohne' => null, 'Spaet' => '2026-10-15', 'Frueh' => '2026-10-13'] as $name => $datum) {
            $a = $this->akte(['first_name' => $name]);
            $this->erinnert($this->person([], $a));
            if ($datum !== null) {
                $this->einsatz($a, $datum);
            }
        }
        $storno = $this->akte(['first_name' => 'Storno']);
        $this->erinnert($this->person([], $storno));
        $this->einsatz($storno, '2026-10-12', 3);

        $zeilen = $this->listen()->erinnert($this->team);

        $this->assertSame(['Frueh Muster', 'Spaet Muster', 'Ohne Muster', 'Storno Muster'], array_column($zeilen, 'name'), 'nur Auftraege zaehlen (EinsatzBezug::naechster)');
        $this->assertSame('2026-10-13', $zeilen[0]['naechster_einsatz']);
        $this->assertNull($zeilen[2]['naechster_einsatz']);
    }

    public function test_kontaktiert_ruht_n_tage_und_kommt_zurueck(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        $this->einsatz($a, '2026-10-30');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $this->assertSame([], $this->listen()->erinnert($this->team));
        $ruhend = $this->listen()->erinnert($this->team, '', true);
        $this->assertTrue($ruhend[0]['ruht']);
        $this->assertSame('2026-10-15 10:00:00', $ruhend[0]['ruhe_bis']);

        Carbon::setTestNow('2026-10-15 10:00:01');
        $this->assertCount(1, $this->listen()->erinnert($this->team), 'nach N Tagen wieder da');
    }

    /** Review Focus 5. */
    public function test_kontaktiert_ruht_nicht_wenn_der_naechste_einsatz_in_die_ruhezeit_faellt(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        $this->einsatz($a, '2026-10-13');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $zeilen = $this->listen()->erinnert($this->team);

        $this->assertCount(1, $zeilen, 'Einsatz morgen: die Zeile bleibt sichtbar');
        $this->assertFalse($zeilen[0]['ruht']);
        $this->assertSame('kontaktiert — ruht nicht, weil der nächste Einsatz (13.10.2026) vor dem Ruhe-Ende liegt', $zeilen[0]['ruhe_hinweis']);
    }

    /** Review Focus 5, Grenze: Einsatz genau am letzten Ruhetag ruht ebenfalls nicht. */
    public function test_kontaktiert_ruht_nicht_wenn_der_einsatz_genau_am_ruhe_ende_liegt(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        $this->einsatz($a, '2026-10-15');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $zeilen = $this->listen()->erinnert($this->team);

        $this->assertCount(1, $zeilen, 'Einsatz am 15.10., Ruhe bis 15.10.: sichtbar');
        $this->assertFalse($zeilen[0]['ruht']);
        $this->assertNotNull($zeilen[0]['ruhe_hinweis']);
    }

    /** Ruling R3: Vertrags-Chip liest die Pause aus VertragErinnerungsPause (sent_at ODER letzte erfolgreiche HR-Erinnerung). */
    public function test_vertrag_chip_pausiert_nach_erfolgreicher_hr_erinnerung(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $v = $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);
        RecHrVermerk::anfuegen($this->team, $id, null, 'erneut_erinnert', null, 'vertrag:' . $v->id, 'failed', null, 42);

        $chip = $this->listen()->erinnert($this->team)[0]['chips'][0];
        $this->assertTrue($chip['kann_erinnern'], 'gescheiterte Erinnerung startet keine Pause');
        $this->assertSame('2026-10-08', $chip['pause_bis']);

        RecHrVermerk::anfuegen($this->team, $id, null, 'erneut_erinnert', null, 'vertrag:' . $v->id, 'sent', null, 42);

        $chip = $this->listen()->erinnert($this->team)[0]['chips'][0];
        $this->assertFalse($chip['kann_erinnern']);
        $this->assertSame('2026-10-19', $chip['pause_bis']);
        $this->assertSame('erneut erinnern ab 19.10.', $chip['grau_text']);
    }

    public function test_nicht_zugestellte_aufgaben_nachricht_ist_rot(): void
    {
        $id = $this->person([], $this->akte());
        $this->erinnert($id);
        $this->gescheitert = true;

        $chip = $this->listen()->erinnert($this->team)[0]['chips'][0];

        $this->assertTrue($chip['rot']);
        $this->assertSame('Personalausweis', $chip['tooltip']);
    }

    public function test_pilot_filterzahlen_stimmen_mit_den_zeilen(): void
    {
        $this->person([], $this->akte(['first_name' => 'A']));
        $this->person(['invited_at' => '2026-10-11 10:00:00', 'invite_expires_at' => '2026-10-18 10:00:00'], $this->akte(['first_name' => 'B']));
        $this->person(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => '2026-10-12 10:00:00'], $this->akte(['first_name' => 'C']));
        $this->person(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00'], $this->akte(['first_name' => 'D']));
        $this->person(['password_hash' => 'x', 'registered_at' => '2026-09-05 10:00:00', 'letzte_anmeldung_at' => '2026-09-20 10:00:00'], $this->akte(['first_name' => 'E']));
        $ohne = $this->akte(['first_name' => 'F', 'portal_verified_at' => '2026-10-10 08:00:00', 'portal_last_seen_at' => '2026-10-11 08:00:00']);
        $this->akte(['first_name' => 'G', 'portal_v2_since' => null]);
        $l = $this->listen();

        $alle = $l->pilot($this->team);

        $this->assertSame(['alle' => 6, 'nicht_eingeladen' => 2, 'eingeladen' => 1, 'abgelaufen' => 1, 'nie_angemeldet' => 1, 'lange_nicht' => 1], $alle['zahlen']);
        foreach ($alle['zahlen'] as $filter => $zahl) {
            $this->assertCount($zahl, $l->pilot($this->team, '', $filter)['zeilen'], $filter);
        }
        $f = collect($alle['zeilen'])->firstWhere('employee_id', $ohne->id);
        $this->assertTrue($f['ohne_person']);
        $this->assertSame('11.10.2026, 08:00', $f['altes_portal']);
        $this->assertSame('01.10.2026', $f['pilot_seit']);
        $this->assertStringEndsWith('?tab=stammdaten#konto', $f['link']);
    }

    public function test_abzeichen_zaehlt_antraege_und_gesperrte_ohne_drossel(): void
    {
        $this->person(['wechsel_neue_nummer' => '+4917000000009', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $this->akte());
        $this->person(['locked_at' => now()], $this->akte());
        $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $gebremst = $this->akte(['phone' => '+4915120000000']);
        $this->person([], $gebremst);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, '+4915120000000', 'falsch-' . $i);
        }

        $this->assertSame(3, $this->listen()->abzeichen($this->team));
    }

    public function test_namenssuche(): void
    {
        $this->person(['locked_at' => now()], $this->akte(['first_name' => 'Jörg', 'last_name' => 'Äpfel']));
        $this->person(['locked_at' => now()], $this->akte(['first_name' => 'Ina', 'last_name' => 'Birne']));

        $this->assertSame(['Jörg Äpfel'], array_column($this->listen()->gesperrt($this->team, 'äpf'), 'name'));
    }

    // ------------------------------------------------------------ Fix-Runde 1

    /** I2: ruhende Zeilen kosten keine Live-Pruefung (OffenePunkte nur fuer angezeigte Zeilen). */
    public function test_ruhende_zeile_ruft_offene_punkte_nicht_auf(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $this->assertSame([], $this->listen()->erinnert($this->team));
        $this->assertSame(0, $this->punkte->aufrufe, 'ruht: keine Live-Pruefung');

        $this->assertCount(1, $this->listen()->erinnert($this->team, '', true));
        $this->assertSame(1, $this->punkte->aufrufe, 'ruhende zeigen: dann schon');
    }

    /** I3: wer keine aktive Anstellung mehr hat, erscheint nicht unter "Erinnert". */
    public function test_ausgeschiedene_person_ohne_aktive_akte_hat_keinen_aufgaben_chip(): void
    {
        $id = $this->person([], $this->akte(['is_active' => false]));
        $this->erinnert($id);

        $this->assertSame([], $this->listen()->erinnert($this->team));
        $this->assertSame(0, $this->punkte->aufrufe);
    }

    /** M1: stillgelegte Zeile und Zielzeile mit je einem Antrag — zwei Zeilen, zwei Schluessel. */
    public function test_stillgelegter_und_ziel_antrag_haben_verschiedene_schluessel(): void
    {
        $ziel = $this->akte(['first_name' => 'Zora']);
        $zielId = $this->person(['wechsel_neue_nummer' => '+4917000000011', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $ziel);
        $still = $this->person(['phone' => '+4915199999998', 'merged_into_person_id' => $zielId, 'wechsel_neue_nummer' => '+4917000000012',
            'wechsel_beantragt_at' => '2026-10-12 09:00:00', 'wechsel_wirksam_ab' => '2026-10-12 09:30:00', 'wechsel_quelle' => 'notfall']);

        $zeilen = collect($this->listen()->antraege($this->team))->keyBy('person_id');

        $this->assertCount(2, $zeilen);
        $this->assertSame('p:' . $zielId, $zeilen[$zielId]['schluessel']);
        $this->assertSame('p:' . $still, $zeilen[$still]['schluessel']);
        $this->assertSame('stillgelegt, wird nie angewendet', $zeilen[$still]['zustand']);
        $this->assertSame('Zora Muster', $zeilen[$still]['name']);
    }

    /** M2: Antrag ohne Akte im Team verschwindet nicht — sichtbar, stoppbar, im Abzeichen. */
    public function test_antrag_ohne_akte_im_team_bleibt_sichtbar_und_zaehlt(): void
    {
        $id = $this->person(['phone' => '+4915199999997', 'wechsel_neue_nummer' => '+4917000000013', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall']);

        $zeilen = $this->listen()->antraege($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame($id, $zeilen[0]['person_id']);
        $this->assertSame('p:' . $id, $zeilen[0]['schluessel']);
        $this->assertSame('Person #' . $id, $zeilen[0]['name']);
        $this->assertSame([], $zeilen[0]['akten']);
        $this->assertNull($zeilen[0]['employee_id']);
        $this->assertSame('keine Akte im Team', $zeilen[0]['zustand']);
        $this->assertFalse($zeilen[0]['kann_freigeben']);
        $this->assertSame(1, $this->listen()->abzeichen($this->team));

        $stopp = (new HrKontoAktionen($this->portalAuth()))->stoppeAntrag($id, 'Kein Mitarbeiter mehr.');
        $this->assertTrue($stopp->istOk(), 'Stoppen ist immer erlaubt — auch ohne Akte im Team');
        $this->assertSame([], $this->listen()->antraege($this->team));
    }

    /** M2: Stopp-Vermerk und Notfallsperre einer spaeter stillgelegten bzw. aktenlosen Person bleiben sichtbar. */
    public function test_gestoppte_ohne_akte_oder_stillgelegt_bleiben_sichtbar(): void
    {
        $ziel = $this->akte(['first_name' => 'Zora']);
        $zielId = $this->person([], $ziel);
        $still = $this->person(['phone' => '+4915199999996', 'merged_into_person_id' => $zielId, 'notfall_gesperrt_bis' => '2026-10-19 10:00:00']);
        RecHrVermerk::anfuegen($this->team, $still, null, 'antrag_gestoppt', 'Klang nach Betrug.', null, null, null, 42);
        $ohne = $this->person(['phone' => '+4915199999995', 'notfall_gesperrt_bis' => '2026-10-19 10:00:00']);

        $g = $this->listen()->gestoppte($this->team);

        $this->assertSame(['Zora Muster'], array_column($g['gestoppt'], 'name'), 'Stopp an stillgelegter Zeile: Name der Zielzeile');
        $sperren = collect($g['notfallsperren'])->keyBy('person_id');
        $this->assertSame('Zora Muster', $sperren[$still]['name']);
        $this->assertSame('Person #' . $ohne, $sperren[$ohne]['name']);
        $this->assertSame([], $sperren[$ohne]['akten']);
    }

    /** M3: Akte mit teamfremder Person zaehlt als eigene Akte — Ruhe greift, Pilot behaelt die Zeile. */
    public function test_akte_mit_teamfremder_person_wird_ueber_die_akte_gefuehrt(): void
    {
        $a = $this->akte(['first_name' => 'Fred']);
        $fremd = $this->person(['team_id' => 8, 'phone' => '+4915199999994', 'locked_at' => now()], $a);
        $this->nachweis($a);
        RecHrVermerk::anfuegen($this->team, null, $a->id, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);
        $l = $this->listen();

        $this->assertSame([], $l->erinnert($this->team), 'Ruhe am e:-Schluessel greift');
        $ruhend = $l->erinnert($this->team, '', true);
        $this->assertSame('e:' . $a->id, $ruhend[0]['schluessel']);
        $this->assertNull($ruhend[0]['person_id']);

        $pilot = $l->pilot($this->team);
        $this->assertSame(['e:' . $a->id], array_column($pilot['zeilen'], 'schluessel'));
        $this->assertTrue($pilot['zeilen'][0]['ohne_person']);
        $this->assertSame(1, $pilot['zahlen']['alle']);
        $this->assertSame([], $l->gesperrt($this->team), 'fremde Kontosperre wird nicht gelesen');
        $this->assertStringNotContainsString((string) '"person_id":' . $fremd, json_encode($ruhend));
    }

    /** M3: Akte an stillgelegter Person (Datenrest) — ebenfalls ueber die Akte. */
    public function test_akte_an_stillgelegter_person_wird_ueber_die_akte_gefuehrt(): void
    {
        $zielId = $this->person([], $this->akte());
        $a = $this->akte(['first_name' => 'Rest']);
        $this->person(['phone' => '+4915199999993', 'merged_into_person_id' => $zielId], $a);

        $zeile = collect($this->listen()->pilot($this->team)['zeilen'])->firstWhere('employee_id', $a->id);

        $this->assertNotNull($zeile);
        $this->assertSame('e:' . $a->id, $zeile['schluessel']);
    }

    /** Ruling zu I3: auch Dokument- und Vertrags-Chips an einer beendeten Anstellung erscheinen nicht. */
    public function test_dokument_und_vertrag_an_inaktiver_akte_sind_keine_chips(): void
    {
        $a = $this->akte(['is_active' => false]);
        $this->person([], $a);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-9', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'acknowledge',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        DB::table('rec_document_recipients')->insert(['team_id' => $this->team, 'uuid' => 'r-9', 'rec_document_id' => $dok,
            'rec_employee_id' => $a->id, 'notified_at' => '2026-10-01 10:00:00']);
        $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $this->assertSame([], $this->listen()->erinnert($this->team));
        $this->assertSame([], $this->listen()->erinnert($this->team, '', true));
    }
}
