<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\EinladungSender;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class HrKontoAktionenTest extends TestCase
{
    use HrBereichHarness;

    private const ALT = '+4915111111111';
    private const NEU = '+4917098765432';
    private const NOTIZ = 'Mit der Person telefoniert.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(DispoIdentityResolver::class);
        Container::getInstance()->forgetInstance(EinladungSender::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function aktionen(): HrKontoAktionen
    {
        return new HrKontoAktionen($this->portalAuth());
    }

    /** @return array{0:int, 1:\Platform\Recruiting\Models\RecEmployee, 2:\Platform\Recruiting\Models\RecEmployee} */
    private function menschMitAntrag(int $team = 7, string $wirksam = '2026-10-13 09:00:00'): array
    {
        $this->team = $team;
        $ma = $this->akte(['phone' => self::ALT, 'team_id' => $team]);
        $rg = $this->akte(['phone' => self::ALT, 'team_id' => $team, 'company' => 'RG']);
        $id = $this->person([
            'team_id' => $team, 'phone' => self::ALT,
            'wechsel_neue_nummer' => self::NEU, 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => $wirksam, 'wechsel_quelle' => 'notfall',
        ], $ma, $rg);
        $this->team = 7;

        return [$id, $ma, $rg];
    }

    /** Review Focus 2 (Dienst-Ebene): jede Aktion mit Person/Akte aus Team 8. */
    public function test_fremdes_team_wird_bei_jeder_aktion_abgewiesen(): void
    {
        [$fremd, $fremdeAkte] = $this->menschMitAntrag(8);
        DB::table('rec_persons')->where('id', $fremd)->update(['locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00']);
        DB::table('rec_employees')->where('id', $fremdeAkte->id)->update(['portal_locked_at' => now()]);
        $a = $this->aktionen();

        foreach ([
            $a->stoppeAntrag($fremd, self::NOTIZ),
            $a->gibAntragFrei($fremd, '2026-10-12 09:00:00', self::NOTIZ),
            $a->entsperreKonto($fremd, self::NOTIZ),
            $a->loeseBremse($fremd, self::NOTIZ),
            $a->hebeNotfallSperreAuf($fremd, self::NOTIZ),
            $a->entsperrePortal($fremdeAkte->id, self::NOTIZ),
            $a->ladeNeuEin($fremdeAkte->id, self::NOTIZ),
            $a->setzeKontoZurueck($fremdeAkte->id, self::NOTIZ),
            $a->kontaktiert($fremd, null, self::NOTIZ),
            $a->kontaktiert(null, $fremdeAkte->id, self::NOTIZ),
            $a->erinnereErneut($fremd, null, 'aufgaben'),
        ] as $nr => $ergebnis) {
            $this->assertSame(HrAktionErgebnis::FEHLER, $ergebnis->stufe, "Aktion {$nr}");
            $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $ergebnis->meldung, "Aktion {$nr}: keine Unterscheidung fremd/nicht vorhanden");
        }
        $zeile = $this->personZeile($fremd);
        $this->assertSame(self::NEU, $zeile->wechsel_neue_nummer);
        $this->assertNotNull($zeile->locked_at);
        $this->assertNotNull(DB::table('rec_employees')->where('id', $fremdeAkte->id)->value('portal_locked_at'));
        $this->assertCount(0, $this->vermerke());
    }

    public function test_person_im_eigenen_team_aber_ohne_akte_im_team_ist_nicht_gefunden(): void
    {
        $akteAnderswo = $this->akte(['team_id' => 8, 'phone' => self::ALT]);
        $id = $this->person(['team_id' => 7, 'phone' => self::ALT, 'locked_at' => now()], $akteAnderswo);

        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->entsperreKonto($id, self::NOTIZ)->meldung);
    }

    /** Person gehoert Team 8, ihre Akte liegt in Team 7: Team 7 darf sie nicht anfassen (Person-Team-Filter, nicht nur Akten-Team). */
    public function test_person_eines_fremden_teams_mit_akte_im_eigenen_team_ist_nicht_gefunden(): void
    {
        $akteHier = $this->akte(['team_id' => 7, 'phone' => self::ALT]);
        $id = $this->person(['team_id' => 8, 'phone' => self::ALT, 'locked_at' => now()], $akteHier);

        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->entsperreKonto($id, self::NOTIZ)->meldung);
        $this->assertNotNull($this->personZeile($id)->locked_at);
        $this->assertCount(0, $this->vermerke());
    }

    public function test_notiz_pflicht_5_bis_500_zeichen(): void
    {
        [$id] = $this->menschMitAntrag();
        foreach (['', '    ', 'kurz', str_repeat('x', 501)] as $notiz) {
            $e = $this->aktionen()->stoppeAntrag($id, $notiz);
            $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe, var_export($notiz, true));
        }
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer);
        $this->assertCount(0, $this->vermerke());
        $this->assertTrue($this->aktionen()->stoppeAntrag($id, '  12345  ')->istOk(), 'genau 5 nach trim');
    }

    public function test_stoppen_sperrt_den_notfallweg_vermerkt_und_laesst_rec_employees_in_ruhe(): void
    {
        [$id] = $this->menschMitAntrag();
        $vorher = DB::table('rec_employees')->pluck('updated_at', 'id')->all();

        $e = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertSame('Antrag gestoppt. Die Nummer bleibt …1111. Der Notfallweg ist für diese Person bis 19.10.2026 gesperrt.', $e->meldung);
        $this->assertSame('2026-10-19 10:00:00', (string) $this->personZeile($id)->notfall_gesperrt_bis);
        $v = $this->vermerke()->sole();
        $this->assertSame(['antrag_gestoppt', 'nummernwechsel', self::NOTIZ, 42, 'ok'], [$v->art, $v->bezug, $v->notiz, (int) $v->user_id, $v->ergebnis]);
        $this->assertSame($vorher, DB::table('rec_employees')->pluck('updated_at', 'id')->all());
        $log = $this->logZeilen('recruiting.hr.konto_aktion')[0]['daten'];
        $this->assertSame(['team_id', 'person_id', 'employee_id', 'art', 'user_id', 'ergebnis'], array_keys($log));
        $this->assertStringNotContainsString(self::NOTIZ, json_encode($this->log->zeilen), 'Notiz nie ins Log');
    }

    /** Review Focus 1: der Stundenlauf war schneller. */
    public function test_stopp_nach_angewendetem_antrag_meldet_schon_erledigt_ohne_vermerk(): void
    {
        [$id] = $this->menschMitAntrag(7, '2026-10-12 08:00:00');
        $this->notfallwegAn(7);
        KontoWriter::wendeNummernwechselAn($id);

        $e = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertSame('Der Antrag war schon erledigt (angewendet, freigegeben oder gestoppt). Die Nummer steht jetzt auf …5432.', $e->meldung);
        $this->assertCount(0, $this->vermerke(), 'Vermerk nur bei Wirkung');
        $this->assertNull($this->personZeile($id)->notfall_gesperrt_bis);
    }

    public function test_doppelklick_auf_stoppen_ein_vermerk(): void
    {
        [$id] = $this->menschMitAntrag();
        $this->aktionen()->stoppeAntrag($id, self::NOTIZ);
        $zweiter = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe);
        $this->assertCount(1, $this->vermerke());
    }

    public function test_freigeben_wechselt_nachzug_hinweis_vermerk(): void
    {
        [$id, $ma, $rg] = $this->menschMitAntrag();

        $e = $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertStringContainsString('…5432', $e->meldung);
        $this->assertSame(self::NEU, $this->personZeile($id)->phone);
        $this->assertSame([self::NEU], DB::table('rec_employees')->whereIn('id', [$ma->id, $rg->id])->distinct()->pluck('phone')->all());
        $this->assertCount(2, $this->crmSync->nachgezogen);
        $this->assertSame([['person' => $id, 'alt' => self::ALT]], $this->hinweis->gesendet);
        $this->assertNull($this->personZeile($id)->wechsel_neue_nummer);
        $this->assertSame(['antrag_freigegeben', 'sent'], [$this->vermerke()->sole()->art, $this->vermerke()->sole()->ergebnis]);
    }

    /** Review Focus 1: Freigabe, dann der Stundenlauf. */
    public function test_freigabe_dann_stundenlauf_kein_zweiter_wechsel_kein_zweiter_hinweis(): void
    {
        [$id] = $this->menschMitAntrag(7, '2026-10-12 08:00:00');
        $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);

        $this->assertNull(KontoWriter::wendeNummernwechselAn($id));
        $this->assertCount(1, $this->hinweis->gesendet);
        $this->assertCount(1, $this->logZeilen('recruiting.konto.nummer_gewechselt'));
    }

    public function test_freigeben_abgewiesen_bei_anderem_beantragt_at_anderer_quelle_vergebener_nummer(): void
    {
        [$id] = $this->menschMitAntrag();
        $e = $this->aktionen()->gibAntragFrei($id, '2026-10-12 08:59:59', self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('inzwischen geändert', $e->meldung);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'kuenftig']);
        $this->assertSame(HrAktionErgebnis::FEHLER, $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ)->stufe);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'notfall']);
        $this->person(['phone' => self::NEU], $this->akte(['phone' => self::NEU]));
        $vergeben = $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::FEHLER, $vergeben->stufe);
        $this->assertStringNotContainsString(self::NEU, $vergeben->meldung, 'keine volle Nummer');
        $this->assertStringContainsString('…5432', $vergeben->meldung);
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer, 'Antrag bleibt');
        $this->assertCount(0, $this->vermerke());
        $this->assertStringNotContainsString(self::NEU, json_encode($this->log->zeilen), 'auch im Log keine volle Nummer');
    }

    public function test_einladung_neu_zeigt_link_und_code_nur_im_ergebnis(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([], $ma);

        $erste = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);
        $zweite = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);

        $this->assertTrue($zweite->istOk());
        $this->assertSame(8, strlen((string) $zweite->einladungsCode));
        $this->assertStringContainsString((string) $zweite->einladungsCode, (string) $zweite->einladungsLink);
        $this->assertNull(KontoWriter::personFuerEinladung((string) $erste->einladungsCode), 'die zweite entwertet die erste');
        $this->assertSame($id, KontoWriter::personFuerEinladung((string) $zweite->einladungsCode));
        $this->assertSame(['einladung_neu', 'einladung_neu'], $this->vermerke()->pluck('art')->all(), 'zwei Klicks, zwei Vermerke (gewollt)');
    }

    public function test_einladungs_klartext_steht_in_keinem_log_und_keinem_vermerk(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $this->person([], $ma);

        $code = (string) $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ)->einladungsCode;

        $this->assertNotSame('', $code);
        $this->assertStringNotContainsString($code, json_encode($this->log->zeilen));
        $this->assertStringNotContainsString($code, json_encode($this->vermerke()->all()));
    }

    public function test_einladung_und_zuruecksetzen_mit_riegel_und_passenden_zustaenden(): void
    {
        $ohneDatum = $this->akte(['phone' => self::ALT, 'birth_date' => null]);
        $this->person([], $ohneDatum);
        $e = $this->aktionen()->ladeNeuEin($ohneDatum->id, self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe);
        $this->assertStringContainsString('Geburtsdatum', $e->meldung);

        $ohnePerson = $this->akte(['phone' => '+4915122222222']);
        $this->assertStringContainsString('keine Personen-Zeile', $this->aktionen()->ladeNeuEin($ohnePerson->id, self::NOTIZ)->meldung);

        $registriert = $this->akte(['phone' => '+4915133333333', 'birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('x-geheim-2026'), 'registered_at' => now()], $registriert);
        $this->assertStringContainsString('Konto zurücksetzen', $this->aktionen()->ladeNeuEin($registriert->id, self::NOTIZ)->meldung);

        $nichtRegistriert = $this->akte(['phone' => '+4915144444444', 'birth_date' => '1990-05-17']);
        $this->person([], $nichtRegistriert);
        $this->assertStringContainsString('Einladung neu', $this->aktionen()->setzeKontoZurueck($nichtRegistriert->id, self::NOTIZ)->meldung);
        $this->assertCount(0, $this->vermerke());
    }

    // ------------------------------------------------------------ Einladung per WhatsApp (19.10.2026)

    /** Sender-Attrappe mit festem Status; merkt sich Person und Code. */
    private function einladungSender(string $status): object
    {
        $sender = new class($status) extends EinladungSender {
            /** @var list<array{person:int, code:string}> */
            public array $aufrufe = [];
            public function __construct(private string $status) {}
            public function sende(int $personId, string $klartext): string
            {
                $this->aufrufe[] = ['person' => $personId, 'code' => $klartext];

                return $this->status;
            }
        };
        Container::getInstance()->instance(EinladungSender::class, $sender);

        return $sender;
    }

    public function test_einladung_mit_vorlage_wird_verschickt_und_link_bleibt_als_rueckfall(): void
    {
        $sender = $this->einladungSender(EinladungSender::STATUS_GESENDET);
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([], $ma);

        $e = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::OK, $e->stufe);
        $this->assertStringContainsString(HrKontoAktionen::EINLADUNG_GESENDET, $e->meldung);
        $this->assertSame('Einladung per WhatsApp verschickt (gilt 30 Tage).', HrKontoAktionen::EINLADUNG_GESENDET);
        $this->assertSame([['person' => $id, 'code' => (string) $e->einladungsCode]], $sender->aufrufe, 'der angezeigte Code ist der verschickte');
        $this->assertNotNull($e->einladungsLink, 'Link/Code weiterhin angezeigt');
        $this->assertSame($id, KontoWriter::personFuerEinladung((string) $e->einladungsCode));
    }

    /** Ohne Vorlage wie bisher — echter Sender, kein Feld gesetzt. */
    public function test_einladung_ohne_vorlage_zeigt_nur_an_und_sagt_es(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $this->person([], $ma);

        $e = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::OK, $e->stufe);
        $this->assertStringContainsString('Keine Vorlage eingestellt — bitte selbst weitergeben.', $e->meldung);
        $this->assertNotNull($e->einladungsCode);
        $this->assertSame(EinladungSender::STATUS_NICHT_KONFIGURIERT, $this->logZeilen('recruiting.konto.einladung_versand')[0]['daten']['status']);
    }

    public function test_einladung_fehlschlag_ist_gelb_mit_grund_und_code(): void
    {
        $this->einladungSender(EinladungSender::STATUS_VORLAGE_UNTAUGLICH);
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $this->person([], $ma);

        $e = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('Nicht per WhatsApp verschickt: Vorlage passt nicht', $e->meldung);
        $this->assertStringContainsString('bitte selbst weitergeben', $e->meldung);
        $this->assertNotNull($e->einladungsCode);
        $this->assertSame('einladung_neu', $this->vermerke()->sole()->art, 'Einladung ist trotzdem erstellt');
    }

    public function test_zuruecksetzen_verschickt_die_neue_einladung_ebenfalls(): void
    {
        $sender = $this->einladungSender(EinladungSender::STATUS_GESENDET);
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00'], $ma);

        $e = $this->aktionen()->setzeKontoZurueck($ma->id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertStringContainsString(HrKontoAktionen::EINLADUNG_GESENDET, $e->meldung);
        $this->assertSame([['person' => $id, 'code' => (string) $e->einladungsCode]], $sender->aufrufe);
    }

    /** Pilot-Sperre (Sebastian 19.10.2026): ohne neues Portal keine Einladung — nichts erzeugt, nichts verschickt, kein Vermerk. */
    public function test_ohne_neues_portal_keine_einladung(): void
    {
        $sender = $this->einladungSender(EinladungSender::STATUS_GESENDET);
        $neu = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17', 'portal_v2_since' => null]);
        $idNeu = $this->person([], $neu);

        $e = $this->aktionen()->ladeNeuEin($neu->id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe);
        $this->assertSame(HrKontoAktionen::KEIN_NEUES_PORTAL_TEXT, $e->meldung);
        $this->assertStringContainsString('kein neues Portal (portal_v2_since fehlt — erst recruiting:portal-umstellen)', $e->meldung, 'Wortlaut wie im Kommando');
        $this->assertNull($e->einladungsCode);
        $this->assertNull($this->personZeile($idNeu)->invite_token_hash);
        $this->assertSame([], $sender->aufrufe);
        $this->assertCount(0, $this->vermerke());
    }

    /**
     * Controller-Ruling 19.10.2026: Zuruecksetzen ist eine Sicherheitsaktion
     * und bleibt fuer Nicht-Piloten moeglich — Passwort und Sitzungen fallen,
     * eine offene alte Einladung auch, aber es entsteht KEINE neue und es
     * geht nichts raus. Auch die Einladungs-Riegel (hier: kein Geburtsdatum)
     * halten das Zuruecksetzen dann nicht auf.
     */
    public function test_zuruecksetzen_ohne_neues_portal_ohne_einladung(): void
    {
        $sender = $this->einladungSender(EinladungSender::STATUS_GESENDET);
        $reg = $this->akte(['phone' => self::NEU, 'birth_date' => null, 'portal_v2_since' => null]);
        $id = $this->person([
            'password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00',
            'invite_token_hash' => str_repeat('a', 64), 'invite_expires_at' => '2026-10-15 10:00:00',
        ], $reg);
        $this->sitzung->put(PortalAuth::sessionKey($reg->id), true);

        $e = $this->aktionen()->setzeKontoZurueck($reg->id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::OK, $e->stufe, $e->meldung);
        $this->assertStringContainsString('Konto zurückgesetzt', $e->meldung);
        $this->assertStringContainsString(HrKontoAktionen::ZURUECKSETZEN_OHNE_EINLADUNG, $e->meldung);
        $this->assertNull($e->einladungsCode);
        $zeile = $this->personZeile($id);
        $this->assertNull($zeile->password_hash, 'Passwort weg');
        $this->assertNotNull($zeile->sitzungen_ungueltig_ab, 'Sitzungen enden');
        $this->assertFalse(PortalAuth::sitzungGueltig($reg->id));
        $this->assertNull($zeile->invite_token_hash, 'alte Einladung entwertet, keine neue');
        $this->assertSame([], $sender->aufrufe);
        $v = $this->vermerke()->sole();
        $this->assertSame(['konto_zurueckgesetzt', 'ohne Einladung'], [$v->art, $v->ergebnis]);
    }

    /** Review Focus 3 (Dienst-Ebene). */
    public function test_zuruecksetzen_beendet_laufende_sitzungen_aller_akten(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $rg = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17', 'company' => 'RG']);
        $id = $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00'], $ma, $rg);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);
        $this->sitzung->put(PortalAuth::sessionKey($rg->id), '2026-10-12T09:59:00+00:00');

        $e = $this->aktionen()->setzeKontoZurueck($ma->id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertNotNull($e->einladungsCode);
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::ALT, 'ganz-geheim-2026'));
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id), 'Altwert true stirbt');
        $this->assertFalse(PortalAuth::sitzungGueltig($rg->id), 'Schwesterakte stirbt mit');
        $this->assertSame('konto_zurueckgesetzt', $this->vermerke()->sole()->art);
        $this->assertSame($ma->id, (int) $this->vermerke()->sole()->rec_employee_id, 'Akte, aus der gehandelt wurde');
        $this->assertSame($id, (int) $this->vermerke()->sole()->rec_person_id);
    }

    public function test_entsperren_konto_bremse_notfallsperre_portal_je_einmal_wirksam(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: x']);
        $id = $this->person(['locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00'], $ma);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, self::ALT, 'falsch-' . $i);
        }
        Container::getInstance()->instance(DispoIdentityResolver::class, new class extends DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        $a = $this->aktionen();

        $this->assertTrue($a->entsperreKonto($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->loeseBremse($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->hebeNotfallSperreAuf($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->entsperrePortal($ma->id, self::NOTIZ)->istOk());

        foreach ([$a->entsperreKonto($id, self::NOTIZ), $a->loeseBremse($id, self::NOTIZ), $a->hebeNotfallSperreAuf($id, self::NOTIZ), $a->entsperrePortal($ma->id, self::NOTIZ)] as $zweiter) {
            $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe, 'Doppelklick: keine Wirkung, kein Vermerk');
        }
        $this->assertSame(['konto_entsperrt', 'bremse_geloest', 'notfallsperre_aufgehoben', 'portal_entsperrt'], $this->vermerke()->pluck('art')->all());
        $this->assertSame([], $this->portalAuth()->gebremsteNummern([self::ALT]));
        $this->assertNotNull($this->personZeile($id)->sitzungen_ungueltig_ab, 'Notfallsperre aufheben beendet Sitzungen (Spec §9.6)');
        $this->assertNull(DB::table('rec_employees')->where('id', $ma->id)->value('zas_changed_at'));
    }

    public function test_kontaktiert_ruht_n_tage_aus_der_einstellung(): void
    {
        $ma = $this->akte();
        $id = $this->person([], $ma);
        $ohnePerson = $this->akte(['phone' => '+4915155555555']);

        $this->assertTrue($this->aktionen()->kontaktiert($id, null, self::NOTIZ)->istOk());
        $this->einstellung('hr_kontaktiert_ruhe_tage', 5);
        $this->assertTrue($this->aktionen()->kontaktiert(null, $ohnePerson->id, self::NOTIZ)->istOk());

        [$erster, $zweiter] = $this->vermerke()->all();
        $this->assertSame('2026-10-15 10:00:00', (string) $erster->ruhe_bis);
        $this->assertSame('2026-10-17 10:00:00', (string) $zweiter->ruhe_bis);
        $this->assertNull($zweiter->rec_person_id);
        $this->assertSame($ohnePerson->id, (int) $zweiter->rec_employee_id);
    }

    public function test_kontaktiert_ueber_eine_akte_mit_person_landet_an_der_person(): void
    {
        $ma = $this->akte();
        $id = $this->person([], $ma);

        $this->aktionen()->kontaktiert(null, $ma->id, self::NOTIZ);

        $this->assertSame($id, (int) $this->vermerke()->sole()->rec_person_id, 'ein Mensch = eine Zeile');
    }

    /**
     * Ruling K5: Person in Team 8, Anstellung in Team 7 — gehandelt aus Team 7.
     * Faellt das ->where('team_id') in personImTeam() weg, kaeme die Person
     * ueber die Akte durch; dieser Test wird dann rot.
     */
    public function test_person_aus_fremdem_team_mit_akte_im_eigenen_team_ist_nicht_gefunden(): void
    {
        $akte = $this->akte(['team_id' => 7, 'phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([
            'team_id' => 8, 'phone' => self::ALT, 'locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00',
            'wechsel_neue_nummer' => self::NEU, 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall',
        ], $akte);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, self::ALT, 'falsch-' . $i);
        }
        $a = $this->aktionen();

        foreach ([
            $a->stoppeAntrag($id, self::NOTIZ),
            $a->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ),
            $a->entsperreKonto($id, self::NOTIZ),
            $a->loeseBremse($id, self::NOTIZ),
            $a->hebeNotfallSperreAuf($id, self::NOTIZ),
            $a->ladeNeuEin($akte->id, self::NOTIZ),
            $a->setzeKontoZurueck($akte->id, self::NOTIZ),
            $a->kontaktiert($id, null, self::NOTIZ),
            $a->erinnereErneut($id, null, 'aufgaben'),
        ] as $nr => $ergebnis) {
            $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $ergebnis->meldung, "Aktion {$nr}");
        }
        $zeile = $this->personZeile($id);
        $this->assertSame(self::NEU, $zeile->wechsel_neue_nummer);
        $this->assertNotNull($zeile->locked_at);
        $this->assertNotNull($zeile->notfall_gesperrt_bis);
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern([self::ALT]), 'Bremse bleibt');
        $this->assertCount(0, $this->vermerke());

        // Die Akte selbst liegt im Team: Portal entsperren wirkt, der Vermerk
        // bleibt aber an der Akte und nie an der teamfremden Person.
        DB::table('rec_employees')->where('id', $akte->id)->update(['portal_locked_at' => now()]);
        Container::getInstance()->instance(DispoIdentityResolver::class, new class extends DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        $this->assertTrue($a->entsperrePortal($akte->id, self::NOTIZ)->istOk());
        $v = $this->vermerke()->sole();
        $this->assertNull($v->rec_person_id, 'nicht an Person ' . $id);
        $this->assertSame($akte->id, (int) $v->rec_employee_id);
    }

    /** Ruling: die Bremse haengt an der Nummer und gilt in jedem Team — fremde Nummer wird nicht geloest. */
    public function test_bremse_einer_fremden_nummer_bleibt_unberuehrt(): void
    {
        $fremdeAkte = $this->akte(['team_id' => 8, 'phone' => self::NEU]);
        $fremd = $this->person(['team_id' => 8, 'phone' => self::NEU], $fremdeAkte);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, self::NEU, 'falsch-' . $i);
        }
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern([self::NEU]), 'Vorbedingung: gebremst');

        $e = $this->aktionen()->loeseBremse($fremd, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $e->meldung);
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern([self::NEU]), 'Bremse in Team 8 bleibt');
        $this->assertCount(0, $this->vermerke());
    }

    public function test_notiz_pflicht_auch_fuer_entsperren_und_zuruecksetzen(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17', 'portal_locked_at' => '2026-10-10 16:00:00']);
        $id = $this->person(['locked_at' => now(), 'password_hash' => Hash::make('x-geheim-2026'), 'registered_at' => now()], $ma);
        $a = $this->aktionen();

        foreach ([
            $a->entsperreKonto($id, 'kurz'),
            $a->loeseBremse($id, ''),
            $a->hebeNotfallSperreAuf($id, '   '),
            $a->entsperrePortal($ma->id, 'kurz'),
            $a->setzeKontoZurueck($ma->id, ''),
            $a->ladeNeuEin($ma->id, ''),
            $a->kontaktiert($id, null, ''),
        ] as $nr => $ergebnis) {
            $this->assertSame(HrAktionErgebnis::FEHLER, $ergebnis->stufe, "Aktion {$nr}");
        }
        $this->assertNotNull($this->personZeile($id)->locked_at);
        $this->assertNotNull(DB::table('rec_employees')->where('id', $ma->id)->value('portal_locked_at'));
        $this->assertCount(0, $this->vermerke());
    }

    /** Task 3: Zuruecksetzen raeumt einen offenen Antrag ab — das steht im Vermerk (allgemein, nicht "Notfall"). */
    public function test_zuruecksetzen_mit_offenem_antrag_vermerkt_das_entfernen(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([
            'password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00',
            'wechsel_neue_nummer' => self::NEU, 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'kuenftig',
        ], $ma);

        $e = $this->aktionen()->setzeKontoZurueck($ma->id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertStringContainsString('offener Nummernwechsel-Antrag entfernt', $e->meldung);
        $this->assertSame('offener Nummernwechsel-Antrag entfernt', $this->vermerke()->sole()->ergebnis);
        $this->assertNull($this->personZeile($id)->wechsel_neue_nummer);
        $this->assertStringNotContainsString((string) $e->einladungsCode, json_encode($this->log->zeilen));
        $this->assertStringNotContainsString((string) $e->einladungsCode, json_encode($this->vermerke()->all()));
    }

    public function test_zuruecksetzen_ohne_antrag_vermerkt_ok(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00'], $ma);

        $e = $this->aktionen()->setzeKontoZurueck($ma->id, self::NOTIZ);

        $this->assertStringNotContainsString('Antrag', $e->meldung);
        $this->assertSame('ok', $this->vermerke()->sole()->ergebnis);
    }

    /** Task 3 carry: die Ausnahme aus PersonLinker::setzeNummer traegt die volle Nummer — nie ins Log. */
    public function test_freigabe_fehlschlag_legt_keinen_ausnahmetext_ins_log(): void
    {
        [$id] = $this->menschMitAntrag();
        $this->person(['phone' => self::NEU], $this->akte(['phone' => self::NEU]));

        $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);

        $zeile = $this->logZeilen('recruiting.konto.hr_nummernwechsel_fehler')[0]['daten'] ?? null;
        $this->assertNotNull($zeile);
        $this->assertSame('5432', $zeile['nummer_endet_auf']);
        $alles = json_encode($this->log->zeilen);
        $this->assertStringNotContainsString('gehoert', $alles, 'kein Ausnahmetext');
        $this->assertStringNotContainsString('98765432', $alles);
    }

    /** Ruhe an einer Akte, deren Person in einem anderen Team liegt: bleibt an der Akte, nie an der fremden Person. */
    public function test_kontaktiert_ueber_akte_mit_fremder_person_bleibt_an_der_akte(): void
    {
        $akte = $this->akte(['team_id' => 7]);
        $fremd = $this->person(['team_id' => 8], $akte);

        $this->assertTrue($this->aktionen()->kontaktiert(null, $akte->id, self::NOTIZ)->istOk());

        $v = $this->vermerke()->sole();
        $this->assertNull($v->rec_person_id, 'nicht an Person ' . $fremd);
        $this->assertSame($akte->id, (int) $v->rec_employee_id);
    }

    /** Fix-Runde 1, Minor 5: stillgelegte Zeile — nur "Antrag stoppen" geht. */
    public function test_stillgelegte_person_nur_stoppen(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $ziel = $this->person([], $akte);
        $tot = $this->person([
            'phone' => self::ALT, 'merged_into_person_id' => $ziel,
            'locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00',
            'aufgaben_signatur' => 'alt', 'aufgaben_gemeldet_at' => '2026-10-01 10:00:00',
            'wechsel_neue_nummer' => self::NEU, 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall',
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, self::ALT, 'falsch-' . $i);
        }
        $a = $this->aktionen();

        foreach ([
            $a->gibAntragFrei($tot, '2026-10-12 09:00:00', self::NOTIZ),
            $a->entsperreKonto($tot, self::NOTIZ),
            $a->loeseBremse($tot, self::NOTIZ),
            $a->hebeNotfallSperreAuf($tot, self::NOTIZ),
            $a->kontaktiert($tot, null, self::NOTIZ),
            $a->erinnereErneut($tot, null, 'aufgaben'),
        ] as $nr => $ergebnis) {
            $this->assertSame(HrAktionErgebnis::FEHLER, $ergebnis->stufe, "Aktion {$nr}");
            $this->assertSame(HrKontoAktionen::STILLGELEGT, $ergebnis->meldung, "Aktion {$nr}");
        }
        $zeile = $this->personZeile($tot);
        $this->assertNotNull($zeile->locked_at);
        $this->assertNotNull($zeile->notfall_gesperrt_bis);
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern([self::ALT]));
        $this->assertCount(0, $this->vermerke());

        $this->assertTrue($a->stoppeAntrag($tot, self::NOTIZ)->istOk(), 'Stoppen bleibt erlaubt');
        $this->assertNull($this->personZeile($tot)->wechsel_neue_nummer);
        $this->assertSame(['antrag_gestoppt'], $this->vermerke()->pluck('art')->all());
    }

    /** Fix-Runde 1, Minor 7: Unicode-Leerraum (NBSP, Zero-Width, BOM, Em-Space) zaehlt nicht als Notiz. */
    public function test_notiz_aus_unicode_leerraum_ist_leer(): void
    {
        foreach ([
            str_repeat("\u{00A0}", 5),
            str_repeat("\u{200B}", 5),
            "\u{FEFF}\u{2003}\u{3000}\u{200C}\u{200D}\u{2009}",
            "ab\u{00A0}\u{200B}\u{200B}\u{200B}",
        ] as $notiz) {
            $this->assertNotNull(HrKontoAktionen::notizFehler($notiz), bin2hex($notiz));
        }
        $this->assertNull(HrKontoAktionen::notizFehler(str_repeat("\u{200B}", 3), false), 'optional: leer bleibt leer');

        [$id] = $this->menschMitAntrag();
        $this->assertSame(HrAktionErgebnis::FEHLER, $this->aktionen()->stoppeAntrag($id, str_repeat("\u{00A0}", 6))->stufe);
        $this->assertTrue($this->aktionen()->stoppeAntrag($id, "\u{00A0}\u{FEFF}" . self::NOTIZ . "\u{200B} ")->istOk());
        $this->assertSame(self::NOTIZ, $this->vermerke()->sole()->notiz, 'gespeichert ohne Rand-Leerraum');
    }

    /** Fix-Runde 1, Minor 8: die Dispo-Gruppe darf ueber das Team hinausreichen, entsperrt wird nur im Team. */
    public function test_portal_entsperren_nur_im_eigenen_team(): void
    {
        $ma = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $fremd = $this->akte(['team_id' => 8, 'portal_locked_at' => '2026-10-10 16:00:00']);
        Container::getInstance()->instance(DispoIdentityResolver::class, new class((int) $ma->id, (int) $fremd->id) extends DispoIdentityResolver {
            public function __construct(private int $a, private int $b) {}
            public function groupFor(int $employeeId): array { return [$this->a, $this->b]; }
        });

        $e = $this->aktionen()->entsperrePortal($ma->id, self::NOTIZ);

        $this->assertSame('Portal entsperrt.', $e->meldung);
        $this->assertNull(DB::table('rec_employees')->where('id', $ma->id)->value('portal_locked_at'));
        $this->assertNotNull(DB::table('rec_employees')->where('id', $fremd->id)->value('portal_locked_at'), 'Team 8 bleibt gesperrt');
    }

    /** Fix-Runde 1, Minor 6: nur die Nummern-Weigerung wird als Konflikt erklaert; ein DB-Fehler fliegt weiter. */
    public function test_freigabe_wirft_fremde_fehler_weiter(): void
    {
        [$id] = $this->menschMitAntrag();
        DB::statement("CREATE TRIGGER kaputt BEFORE UPDATE ON rec_persons BEGIN SELECT RAISE(ABORT, 'kaputt'); END");

        try {
            $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);
            $this->fail('DB-Fehler darf nicht als Nummernkonflikt verschluckt werden');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::statement('DROP TRIGGER kaputt');
        }
        $this->assertSame([], $this->logZeilen('recruiting.konto.hr_nummernwechsel_fehler'));
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer);
    }
}
