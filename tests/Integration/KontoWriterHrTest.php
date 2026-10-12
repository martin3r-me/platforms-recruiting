<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\KontoWriter;

final class KontoWriterHrTest extends TestCase
{
    use HrBereichHarness;

    private const ALT = '+4915111111111';
    private const NEU = '+4917098765432';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    /** Person mit RG+MA-Akte und offenem Notfall-Antrag; wirksam = in 23 Stunden. */
    private function mitAntrag(array $person = [], string $wirksam = '2026-10-13 09:00:00'): int
    {
        $ma = $this->akte(['phone' => self::ALT]);
        $rg = $this->akte(['phone' => self::ALT, 'company' => 'RG']);

        return $this->person(array_merge([
            'phone' => self::ALT,
            'wechsel_neue_nummer' => self::NEU,
            'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => $wirksam,
            'wechsel_quelle' => 'notfall',
        ], $person), $ma, $rg);
    }

    public function test_freigeben_wechselt_sofort_an_person_und_allen_akten(): void
    {
        $id = $this->mitAntrag();
        $vorher = DB::table('rec_employees')->where('rec_person_id', $id)->pluck('updated_at', 'id')->all();

        $ergebnis = KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00');

        $this->assertSame(['alt' => self::ALT, 'neu' => self::NEU], $ergebnis, 'Faelligkeit wird bewusst nicht geprueft');
        $this->assertSame(self::NEU, $this->personZeile($id)->phone);
        $this->assertSame([self::NEU], DB::table('rec_employees')->where('rec_person_id', $id)->distinct()->pluck('phone')->all());
        $this->assertNull($this->personZeile($id)->wechsel_neue_nummer, 'Antrag weg');
        $this->assertCount(2, $this->crmSync->nachgezogen, 'CRM-Nachzug je Akte (Vorfall RG19734)');
        $protokoll = $this->logZeilen('recruiting.konto.nummer_gewechselt');
        $this->assertSame('hr_freigabe', $protokoll[0]['daten']['weg']);
        $this->assertSame($vorher, DB::table('rec_employees')->where('rec_person_id', $id)->pluck('updated_at', 'id')->all(), 'kein updated_at an rec_employees');
    }

    public function test_freigeben_nur_mit_dem_gesehenen_beantragt_at_und_nur_notfall_und_nur_offen(): void
    {
        $id = $this->mitAntrag();
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:01'));
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'kuenftig']);
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'));

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'notfall', 'locked_at' => now()]);
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'));
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);
        $this->assertNotNull($this->personZeile($id)->wechsel_neue_nummer, 'Antrag bleibt');
    }

    public function test_freigeben_mit_vergebener_zielnummer_wirft_und_der_antrag_bleibt(): void
    {
        $id = $this->mitAntrag();
        $this->person(['phone' => self::NEU], $this->akte(['phone' => self::NEU]));

        try {
            KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00');
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer);
    }

    /** Spec §5.2: nach der Freigabe findet der Stundenlauf nichts mehr. */
    public function test_nach_freigabe_oder_stopp_wendet_der_stundenlauf_nichts_an(): void
    {
        $frei = $this->mitAntrag([], '2026-10-12 08:00:00');
        KontoWriter::gibNummernwechselFrei($frei, '2026-10-12 09:00:00');
        $this->assertNull(KontoWriter::wendeNummernwechselAn($frei));

        $gestoppt = $this->mitAntrag(['phone' => '+4915122222222'], '2026-10-12 08:00:00');
        DB::table('rec_employees')->where('rec_person_id', $gestoppt)->update(['phone' => '+4915122222222']);
        $this->assertTrue(KontoWriter::stoppeNummerwechsel($gestoppt));
        $this->assertNull(KontoWriter::wendeNummernwechselAn($gestoppt));
        $this->assertSame('+4915122222222', $this->personZeile($gestoppt)->phone);

        $angewendet = $this->mitAntrag(['phone' => '+4915133333333', 'wechsel_neue_nummer' => '+4917011111111'], '2026-10-12 08:00:00');
        $this->notfallwegAn();
        $this->assertNotNull(KontoWriter::wendeNummernwechselAn($angewendet));
        $this->assertFalse(KontoWriter::stoppeNummerwechsel($angewendet), 'schon angewendet: false, keine Sperre');
        $this->assertNull($this->personZeile($angewendet)->notfall_gesperrt_bis);
    }

    /** Review Focus 1 (Mechanik): dieselbe Zeilensperre fuer alle drei Wege. */
    public function test_alle_drei_wege_lesen_die_zeile_gesperrt_in_einer_transaktion(): void
    {
        $this->assertTrue(KontoWriter::sperrAbfrage(1)->lock === true, 'ohne lockForUpdate ueberholt der Stundenlauf HR');

        $ebenen = [];
        DB::connection()->listen(function ($abfrage) use (&$ebenen) {
            if (str_starts_with(strtolower(ltrim($abfrage->sql)), 'select') && str_contains($abfrage->sql, 'rec_persons')) {
                $ebenen[] = $abfrage->connection->transactionLevel();
            }
        });

        foreach ([
            fn (int $id) => KontoWriter::stoppeNummerwechsel($id),
            fn (int $id) => KontoWriter::wendeNummernwechselAn($id),
            fn (int $id) => KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'),
        ] as $nr => $weg) {
            $ebenen = [];
            $id = $this->mitAntrag(['phone' => '+49151444444' . $nr, 'wechsel_neue_nummer' => '+49170555555' . $nr], '2026-10-12 08:00:00');
            $weg($id);
            $this->assertNotEmpty($ebenen);
            $this->assertGreaterThanOrEqual(1, $ebenen[0], "Weg {$nr} liest die Zeile ausserhalb einer Transaktion");
        }
    }

    public function test_hr_zuruecksetzen_nimmt_passwort_und_code_und_laedt_neu_ein(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ganz-geheim-2026'),
            'registered_at' => '2026-10-01 10:00:00',
            'code_hash' => str_repeat('a', 64), 'code_expires_at' => '2026-10-12 10:10:00',
            'code_zweck' => 'passwort', 'code_versuche' => 3,
        ], $ma);

        $ergebnis = KontoWriter::setzeKontoZurueckDurchHr($id);
        $token = $ergebnis['token'];
        $this->assertFalse($ergebnis['antrag_entfernt'], 'kein Antrag offen');

        $zeile = $this->personZeile($id);
        $this->assertNull($zeile->password_hash);
        $this->assertNull($zeile->code_hash);
        $this->assertNull($zeile->code_zweck);
        $this->assertSame(0, (int) $zeile->code_versuche);
        $this->assertSame('2026-10-01 10:00:00', (string) $zeile->registered_at, 'registered_at bleibt als Beleg');
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::ALT, 'ganz-geheim-2026'), 'altes Passwort scheitert sofort');
        $this->assertSame($id, KontoWriter::personFuerEinladung($token));

        KontoWriter::registriere($id, $token, '1990-05-17', 'ein-neues-passwort-2026');
        $this->assertSame($id, KontoWriter::pruefeAnmeldung(null, self::ALT, 'ein-neues-passwort-2026'));
    }

    public function test_entsperren_und_notfallsperre_aufheben_nur_mit_wirkung(): void
    {
        $id = $this->mitAntrag();
        $this->assertFalse(KontoWriter::entsperreKontoDurchHr($id), 'nicht gesperrt: keine Wirkung');
        DB::table('rec_persons')->where('id', $id)->update(['locked_at' => now()]);
        $this->assertTrue(KontoWriter::entsperreKontoDurchHr($id));
        $this->assertNull($this->personZeile($id)->locked_at);

        $this->assertFalse(KontoWriter::hebeNotfallSperreAuf($id));
        DB::table('rec_persons')->where('id', $id)->update(['notfall_gesperrt_bis' => '2026-10-11 00:00:00']);
        $this->assertFalse(KontoWriter::hebeNotfallSperreAuf($id), 'abgelaufene Sperre: keine Wirkung');
        DB::table('rec_persons')->where('id', $id)->update(['notfall_gesperrt_bis' => '2026-10-19 00:00:00']);
        $this->assertTrue(KontoWriter::hebeNotfallSperreAuf($id));
        $this->assertNull($this->personZeile($id)->notfall_gesperrt_bis);

        $still = $this->person(['phone' => null, 'merged_into_person_id' => $id, 'locked_at' => now()]);
        $this->assertFalse(KontoWriter::entsperreKontoDurchHr($still), 'stillgelegt: keine Wirkung');
    }

    public function test_notfallweg_an_braucht_server_riegel_und_team_schalter(): void
    {
        $id = $this->mitAntrag();
        $config = \Illuminate\Container\Container::getInstance()->make('config');

        $config->set('recruiting.konto.weg4_aktiv', true);
        $this->assertFalse(KontoWriter::notfallWegAn($id), 'Team aus (Vorgabe)');

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->assertTrue(KontoWriter::notfallWegAn($id));

        $config->set('recruiting.konto.weg4_aktiv', false);
        $this->assertFalse(KontoWriter::notfallWegAn($id), 'Server-Riegel ist der Not-Aus');

        $config->set('recruiting.konto.weg4_aktiv', true);
        $ohneTeam = $this->person(['team_id' => null, 'phone' => '+4915166666666']);
        $this->assertFalse(KontoWriter::notfallWegAn($ohneTeam));
    }

    /** Controller-Ruling: Wettlauf festgenagelt — Stopp nach Anwenden, Freigabe nach Stopp, doppelte Freigabe. */
    public function test_wettlauf_zweiter_weg_findet_nichts_mehr(): void
    {
        $angewendet = $this->mitAntrag([], '2026-10-12 08:00:00');
        $this->notfallwegAn();
        $this->assertNotNull(KontoWriter::wendeNummernwechselAn($angewendet));
        $this->assertFalse(KontoWriter::stoppeNummerwechsel($angewendet), 'Stopp nach Anwenden: nichts mehr da');
        $this->assertNull($this->personZeile($angewendet)->notfall_gesperrt_bis);
        $this->assertSame(self::NEU, $this->personZeile($angewendet)->phone);

        $gestoppt = $this->mitAntrag(['phone' => '+4915177777777', 'wechsel_neue_nummer' => '+4917077777777']);
        $this->assertTrue(KontoWriter::stoppeNummerwechsel($gestoppt));
        $this->assertNull(KontoWriter::gibNummernwechselFrei($gestoppt, '2026-10-12 09:00:00'), 'Freigabe nach Stopp');
        $this->assertSame('+4915177777777', $this->personZeile($gestoppt)->phone);

        $doppelt = $this->mitAntrag(['phone' => '+4915188888888', 'wechsel_neue_nummer' => '+4917088888888']);
        $this->assertNotNull(KontoWriter::gibNummernwechselFrei($doppelt, '2026-10-12 09:00:00'));
        $this->assertNull(KontoWriter::gibNummernwechselFrei($doppelt, '2026-10-12 09:00:00'), 'doppelte Freigabe');
        $this->assertCount(1, array_filter(
            $this->logZeilen('recruiting.konto.nummer_gewechselt'),
            fn (array $z) => ($z['daten']['weg'] ?? null) === 'hr_freigabe',
        ), 'nur EIN Wechsel protokolliert');
    }

    /** Controller-Ruling: welche HR-Aktion laufende Portal-Sitzungen beendet. */
    public function test_sitzungsgrenze_nur_bei_zuruecksetzen_und_notfallsperre_aufheben(): void
    {
        $grenze = fn (int $id) => $this->personZeile($id)->sitzungen_ungueltig_ab;

        $frei = $this->mitAntrag();
        KontoWriter::gibNummernwechselFrei($frei, '2026-10-12 09:00:00');
        $this->assertNull($grenze($frei), 'Freigabe: nein');

        $gesperrt = $this->mitAntrag(['phone' => '+4915199999999', 'wechsel_neue_nummer' => '+4917099999999', 'locked_at' => now()]);
        $this->assertTrue(KontoWriter::entsperreKontoDurchHr($gesperrt));
        $this->assertNull($grenze($gesperrt), 'Entsperren: nein');

        DB::table('rec_persons')->where('id', $gesperrt)->update(['notfall_gesperrt_bis' => '2026-10-19 00:00:00']);
        $this->assertTrue(KontoWriter::hebeNotfallSperreAuf($gesperrt));
        $this->assertNotNull($grenze($gesperrt), 'Notfallsperre aufheben: ja');
        $this->assertSame(strtotime('2026-10-12 10:00:00'), strtotime((string) $grenze($gesperrt)));

        $zurueck = $this->person(['phone' => '+4915100000001'], $this->akte(['phone' => '+4915100000001']));
        KontoWriter::setzeKontoZurueckDurchHr($zurueck);
        $this->assertSame(strtotime('2026-10-12 10:00:00'), strtotime((string) $grenze($zurueck)), 'Zuruecksetzen: ja');
    }

    /** Geheimnisse: kein Token, kein Klartext-Passwort im Log. */
    public function test_zuruecksetzen_schreibt_den_token_in_kein_log(): void
    {
        $id = $this->person(['phone' => '+4915100000002'], $this->akte(['phone' => '+4915100000002']));
        $token = KontoWriter::setzeKontoZurueckDurchHr($id)['token'];

        $this->assertStringNotContainsString($token, json_encode($this->log->zeilen));
    }

    /** Fix-Runde 1: scheitert die Einladung, bleibt das Konto ganz (keine halbe Ruecksetzung). */
    public function test_zuruecksetzen_ist_eine_einheit_scheitert_die_einladung_bleibt_alles(): void
    {
        $hash = \Illuminate\Support\Facades\Hash::make('ganz-geheim-2026');
        $id = $this->mitAntrag([
            'password_hash' => $hash,
            'code_hash' => str_repeat('a', 64), 'code_zweck' => 'passwort', 'code_versuche' => 3,
        ]);

        DB::connection()->listen(function ($abfrage) {
            if (str_contains($abfrage->sql, 'invite_token_hash') && str_starts_with(strtolower(ltrim($abfrage->sql)), 'update')) {
                throw new \RuntimeException('Einladung scheitert');
            }
        });

        try {
            KontoWriter::setzeKontoZurueckDurchHr($id);
            $this->fail('erwartet RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Einladung scheitert', $e->getMessage());
        }

        $zeile = $this->personZeile($id);
        $this->assertSame($hash, $zeile->password_hash, 'Passwort unveraendert');
        $this->assertSame(str_repeat('a', 64), $zeile->code_hash, 'Code unveraendert');
        $this->assertSame(3, (int) $zeile->code_versuche);
        $this->assertNull($zeile->sitzungen_ungueltig_ab, 'Sitzungsgrenze unveraendert');
        $this->assertSame(self::NEU, $zeile->wechsel_neue_nummer, 'Antrag unveraendert');
    }

    /** Fix-Runde 1: Vorbedingung (Pfeffer) vor jedem Schreiben. */
    public function test_zuruecksetzen_ohne_pfeffer_schreibt_nichts(): void
    {
        $hash = \Illuminate\Support\Facades\Hash::make('ganz-geheim-2026');
        $id = $this->person(['phone' => '+4915100000003', 'password_hash' => $hash], $this->akte(['phone' => '+4915100000003']));
        $config = \Illuminate\Container\Container::getInstance()->make('config');
        $config->set('recruiting.konto.pepper', '');
        $config->set('app.key', '');

        try {
            KontoWriter::setzeKontoZurueckDurchHr($id);
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($hash, $this->personZeile($id)->password_hash);
        $this->assertNull($this->personZeile($id)->sitzungen_ungueltig_ab);
    }

    /** Fix-Runde 1: Zuruecksetzen bei Verdacht auf Uebernahme raeumt den offenen Notfall-Antrag ab. */
    public function test_zuruecksetzen_raeumt_offenen_antrag_ab_und_meldet_es(): void
    {
        $id = $this->mitAntrag([], '2026-10-12 08:00:00');

        $ergebnis = KontoWriter::setzeKontoZurueckDurchHr($id);

        $this->assertTrue($ergebnis['antrag_entfernt']);
        $zeile = $this->personZeile($id);
        $this->assertNull($zeile->wechsel_neue_nummer);
        $this->assertNull($zeile->wechsel_beantragt_at);
        $this->assertNull($zeile->wechsel_wirksam_ab);
        $this->assertNull($zeile->wechsel_quelle);
        $this->assertNull(KontoWriter::wendeNummernwechselAn($id), 'der Stundenlauf findet nichts mehr');
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);
        $this->assertStringNotContainsString($ergebnis['token'], json_encode($this->log->zeilen));
    }
}
