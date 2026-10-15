<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\AnstellungsRegeln;
use Platform\Recruiting\Services\PortalProfileWriter;

/**
 * Kundenentscheidung Markus 12.10.2026 (E7/E8) + Generalprobe 13.10.:
 * Steuerklasse, Haupt-/Nebenarbeitgeber und Krankenkasse gelten je
 * Anstellung; zwischen den Anstellungen eines Menschen gilt
 *   - hoechstens eine ist Hauptarbeitgeber,
 *   - hoechstens eine hat eine Steuerklasse != 6.
 * Die Regel sitzt in AnstellungsRegeln und laeuft ueber den Beobachter am
 * Eloquent-Speichern (Portal, HR-Akte, Vertragsunterschrift).
 *
 * Mutationsprobe: den Aufruf in RecEmployeeExportObserver
 * ('rec_employee.updated.anstellungsregeln') entfernen -> hier rot.
 */
class AnstellungsRegelnTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(true, [
            '2026_05_21_000007_add_fiktion_file_ids_to_rec_employees',
            '2026_05_21_000010_add_schulbescheinigung_file_id_to_rec_employees',
            '2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees',
            '2026_08_06_000001_add_erstbescheinigung_file_id_to_rec_employees',
            '2026_08_24_000004_add_portal_lock_to_rec_employees',
            '2026_08_26_000002_add_company_to_rec_employees',
            '2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees',
            '2026_09_23_000001_add_nationality_to_rec_employees',
            '2026_09_24_000001_add_portal_v2_since_to_rec_employees',
        ]);
    }

    protected function setUp(): void
    {
        Container::getInstance()->forgetInstance(AnstellungsRegeln::class);
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_persons')->delete();
        Capsule::table('rec_applicant_settings')->delete();
    }

    /** @return array{0:int,1:int} [rg, ma] */
    private function paar(array $rg = [], array $ma = []): array
    {
        $p = $this->person();

        return [
            $this->akte(array_merge(['rec_person_id' => $p, 'company' => 'RG'], $rg)),
            $this->akte(array_merge(['rec_person_id' => $p, 'company' => 'MA'], $ma)),
        ];
    }

    // ---- E8 Hauptarbeitgeber ------------------------------------------------

    public function test_haupt_an_rg_setzt_ma_auf_neben(): void
    {
        [$rg, $ma] = $this->paar([], ['is_main_employer' => 1]);
        RecEmployee::find($rg)->update(['is_main_employer' => true]);
        $this->assertSame(1, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer);
    }

    public function test_haupt_an_rg_setzt_auch_eine_unbeantwortete_ma_auf_neben(): void
    {
        [$rg, $ma] = $this->paar();
        RecEmployee::find($rg)->update(['is_main_employer' => true]);
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer);
        $this->assertNotNull($this->zeile($ma)->is_main_employer);
    }

    public function test_haupt_an_ma_setzt_rg_auf_neben(): void
    {
        [$rg, $ma] = $this->paar(['is_main_employer' => 1]);
        RecEmployee::find($ma)->update(['is_main_employer' => true]);
        $this->assertSame(0, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
    }

    public function test_beide_neben_bleibt_und_der_andere_arbeitgeber_gilt_fuer_beide(): void
    {
        [$rg, $ma] = $this->paar([], ['is_main_employer' => 0, 'other_employer' => null]);
        RecEmployee::find($rg)->update(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']);
        $this->assertSame(0, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame('Mueller GmbH', $this->zeile($ma)->other_employer);
    }

    public function test_anderer_arbeitgeber_nie_an_eine_schwester_die_selbst_haupt_ist(): void
    {
        [$rg, $ma] = $this->paar([], ['is_main_employer' => 1, 'other_employer' => null]);
        RecEmployee::find($rg)->update(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
        $this->assertNull($this->zeile($ma)->other_employer);
        $this->assertNull($this->zeile($ma)->zas_changed_at, 'nichts geaendert, kein Marker');
    }

    public function test_neben_setzen_aendert_die_schwester_nicht(): void
    {
        [$rg, $ma] = $this->paar(['is_main_employer' => 1], ['is_main_employer' => 0, 'other_employer' => 'RheinGedeck']);
        RecEmployee::find($rg)->update(['is_main_employer' => false]);
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer, 'beide Neben ist erlaubt');
        $this->assertSame('RheinGedeck', $this->zeile($ma)->other_employer, 'ohne anderen Arbeitgeber an der Quelle bleibt die Schwester');
    }

    public function test_einzelne_anstellung_ohne_schwester_bleibt_unveraendert(): void
    {
        $rg = $this->akte(['company' => 'RG']);
        $emp = RecEmployee::find($rg);
        $emp->update(['is_main_employer' => true, 'tax_class' => '1']);
        $this->assertSame(1, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame('1', (string) $this->zeile($rg)->tax_class);
        $this->assertSame([], $emp->anstellungsFolgen);
    }

    public function test_inaktive_schwester_wird_nicht_angefasst(): void
    {
        [$rg, $ma] = $this->paar([], ['is_main_employer' => 1, 'tax_class' => '1', 'is_active' => 0]);
        RecEmployee::find($rg)->update(['is_main_employer' => true, 'tax_class' => '1']);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame('1', (string) $this->zeile($ma)->tax_class);
    }

    /**
     * Review K1: die Gegenrichtung — eine BEENDETE Anstellung als Quelle
     * loest nichts aus. HR korrigiert an der beendeten RG-Akte historisch
     * Haupt / Klasse 1; die laufende MA-Anstellung bleibt, ohne Marker.
     */
    public function test_inaktive_quelle_loest_nichts_aus(): void
    {
        [$rg, $ma] = $this->paar(['is_active' => 0], ['is_main_employer' => 1, 'tax_class' => '1']);
        $emp = RecEmployee::find($rg);
        $emp->update(['is_main_employer' => true, 'tax_class' => '1']);

        $this->assertSame(1, (int) $this->zeile($rg)->is_main_employer, 'Vorflug: die Quelle selbst ist gespeichert');
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame('1', (string) $this->zeile($ma)->tax_class);
        $this->assertNull($this->zeile($ma)->zas_changed_at, 'keine Folge, kein Marker');
        $this->assertSame([], $emp->anstellungsFolgen);
    }

    // ---- E7 Steuerklasse ----------------------------------------------------

    public function test_klasse_1_an_rg_gibt_ma_klasse_6(): void
    {
        [$rg, $ma] = $this->paar(['tax_class' => '6'], ['tax_class' => '1']);
        RecEmployee::find($rg)->update(['tax_class' => '1']);
        $this->assertSame('1', (string) $this->zeile($rg)->tax_class);
        $this->assertSame('6', (string) $this->zeile($ma)->tax_class);
    }

    public function test_klasse_6_an_rg_laesst_ma_unveraendert(): void
    {
        [$rg, $ma] = $this->paar(['tax_class' => '1'], ['tax_class' => '3']);
        RecEmployee::find($rg)->update(['tax_class' => '6']);
        $this->assertSame('3', (string) $this->zeile($ma)->tax_class);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    public function test_leere_klasse_aendert_nichts(): void
    {
        [$rg, $ma] = $this->paar(['tax_class' => '1'], ['tax_class' => '3']);
        RecEmployee::find($rg)->update(['tax_class' => null]);
        $this->assertSame('3', (string) $this->zeile($ma)->tax_class);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    // ---- Krankenkasse ---------------------------------------------------------

    public function test_krankenkasse_an_rg_aendert_ma_nicht(): void
    {
        [$rg, $ma] = $this->paar(['health_insurance' => 'aok'], ['health_insurance' => 'knappschaft']);
        RecEmployee::find($rg)->update(['health_insurance' => 'tk']);
        $this->assertSame('knappschaft', $this->zeile($ma)->health_insurance);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    // ---- ZAS-Marker + Lohn ------------------------------------------------------

    public function test_schwester_bekommt_zas_marker_und_lohn_eintrag(): void
    {
        $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['tax_class', 'is_main_employer']]);
        [$rg, $ma] = $this->paar(['tax_class' => '6', 'is_main_employer' => 0], ['tax_class' => '1', 'is_main_employer' => 1]);

        RecEmployee::find($rg)->update(['tax_class' => '1', 'is_main_employer' => true]);

        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'echte Datenaenderung: ZAS braucht die Werte je Gesellschaft');
        $lohn = array_column($this->lohn($ma), 'new', 'field');
        $this->assertSame('6', (string) $lohn['tax_class']);
        $this->assertArrayHasKey('is_main_employer', $lohn);
    }

    public function test_ergebnis_liegt_an_der_instanz_und_ergibt_den_hr_hinweis(): void
    {
        [$rg, $ma] = $this->paar(['tax_class' => '6'], ['tax_class' => '1', 'is_main_employer' => 1]);
        $emp = RecEmployee::find($rg);
        $emp->update(['tax_class' => '1', 'is_main_employer' => true]);

        $this->assertCount(1, $emp->anstellungsFolgen);
        $this->assertSame($ma, $emp->anstellungsFolgen[0]['id']);
        $this->assertSame(
            'Anstellung MA wurde auf Steuerklasse 6 / Nebenarbeitgeber gesetzt.',
            AnstellungsRegeln::hinweisHr($emp->anstellungsFolgen),
        );
    }

    // ---- Portal: Antwort je Anstellung --------------------------------------------

    public function test_portal_fragt_je_anstellung_und_die_regel_greift_sichtbar(): void
    {
        [$rg, $ma] = $this->paar(['portal_v2_since' => '2026-10-01 00:00:00'], ['portal_v2_since' => '2026-10-01 00:00:00']);
        $writer = new PortalProfileWriter();

        $r1 = $writer->speichere(RecEmployee::find($rg), ['is_main_employer' => '1'], 'Arbeitgeber', true);
        $this->assertTrue($r1['ok'], (string) $r1['fehler']);
        $this->assertSame(1, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame('Gespeichert. Für deine Anstellung bei MA gilt jetzt: Nebenarbeitgeber.', $r1['meldung']);

        // Dieselbe Frage an der MA-Anstellung getrennt beantworten.
        $r2 = $writer->speichere(RecEmployee::find($ma), ['is_main_employer' => '1'], 'Arbeitgeber', false);
        $this->assertTrue($r2['ok'], (string) $r2['fehler']);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame(0, (int) $this->zeile($rg)->is_main_employer);
        $this->assertSame('Gespeichert. Für Ihre Anstellung bei RG gilt jetzt: Nebenarbeitgeber.', $r2['meldung']);
    }

    public function test_portal_ohne_folge_meldet_nur_gespeichert(): void
    {
        [$rg] = $this->paar([], ['is_main_employer' => 0, 'other_employer' => 'X']);
        $r = (new PortalProfileWriter())->speichere(RecEmployee::find($rg), ['is_main_employer' => '1'], 'Arbeitgeber', true);
        $this->assertSame('Gespeichert.', $r['meldung']);
    }

    public function test_frage_nennt_die_gesellschaft_der_anstellung(): void
    {
        [$rg, $ma] = $this->paar();
        $this->assertSame('Ist Rheingedeck der Hauptarbeitgeber?', RecEmployee::find($rg)->editableFieldGroups()['Arbeitgeber']['is_main_employer']['label']);
        $this->assertSame('Ist MA der Hauptarbeitgeber?', RecEmployee::find($ma)->editableFieldGroups()['Arbeitgeber']['is_main_employer']['label']);
    }

    // ---- Neue Anstellung (Nachrunde 12.10.) --------------------------------------

    public function test_neue_anstellung_bekommt_neben_und_klasse_6_ohne_lohn(): void
    {
        $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['tax_class', 'is_main_employer']]);
        [$rg, $ma] = $this->paar(['is_main_employer' => 1, 'tax_class' => '1', 'health_insurance' => 'aok'],
            ['is_main_employer' => 1, 'tax_class' => '3', 'health_insurance' => 'knappschaft']);

        $folgen = (new AnstellungsRegeln())->beiNeuerAnstellung($ma);

        $this->assertSame(['is_main_employer', 'tax_class'], array_keys($folgen[0]['aenderungen']));
        $this->assertSame(0, (int) $this->zeile($ma)->is_main_employer);
        $this->assertSame('6', (string) $this->zeile($ma)->tax_class);
        $this->assertSame('knappschaft', $this->zeile($ma)->health_insurance);
        $this->assertNotNull($this->zeile($ma)->zas_changed_at);
        $this->assertSame([], $this->lohn($ma), 'Anlage, keine Aenderung');
        $this->assertSame(1, (int) $this->zeile($rg)->is_main_employer, 'vorhandene bleibt');
        $this->assertNull($this->zeile($rg)->zas_changed_at);
    }

    public function test_neue_anstellung_anderer_arbeitgeber_nur_ins_leere_feld(): void
    {
        [, $ma] = $this->paar(['is_main_employer' => 0, 'other_employer' => 'Mueller GmbH'], ['other_employer' => null]);
        (new AnstellungsRegeln())->beiNeuerAnstellung($ma);
        $this->assertSame('Mueller GmbH', $this->zeile($ma)->other_employer);

        [, $ma2] = $this->paar(['is_main_employer' => 0, 'other_employer' => 'Mueller GmbH'], ['other_employer' => 'Schulz AG']);
        (new AnstellungsRegeln())->beiNeuerAnstellung($ma2);
        $this->assertSame('Schulz AG', $this->zeile($ma2)->other_employer);
    }

    public function test_neue_anstellung_ohne_schwester_oder_ohne_vorgabe_bleibt(): void
    {
        $allein = $this->akte(['company' => 'MA', 'tax_class' => '1', 'is_main_employer' => 1]);
        $this->assertSame([], (new AnstellungsRegeln())->beiNeuerAnstellung($allein));

        [, $ma] = $this->paar(['tax_class' => '6', 'is_main_employer' => null], ['tax_class' => '1', 'is_main_employer' => 1]);
        $this->assertSame([], (new AnstellungsRegeln())->beiNeuerAnstellung($ma));
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    // ---- Umfang: nur die Anstellungen DIESES Menschen (Review W2) ----------------

    /**
     * Ein FREMDER Mensch im selben Team mit aktiver Anstellung, Haupt und
     * Klasse 1 — genau die Werte, die die Regel umschriebe, wenn sie ueber
     * die eigene Person hinausgriffe.
     */
    private function fremder(): int
    {
        return $this->akte([
            'rec_person_id'    => $this->person(),
            'company'          => 'MA',
            'is_main_employer' => 1,
            'tax_class'        => '1',
        ]);
    }

    private function assertFremderUnberuehrt(int $fremder): void
    {
        $z = $this->zeile($fremder);
        $this->assertSame(1, (int) $z->is_main_employer, 'fremder Mensch: Haupt bleibt');
        $this->assertSame('1', (string) $z->tax_class, 'fremder Mensch: Klasse bleibt');
        $this->assertNull($z->zas_changed_at, 'fremder Mensch: kein ZAS-Marker');
        $this->assertSame([], $this->lohn($fremder), 'fremder Mensch: kein Lohn-Eintrag');
    }

    /**
     * Review W2 (Mutation M24): die Regel beim Speichern trifft die
     * Schwester — und NUR sie. Vorher legte kein Test einen zweiten Menschen
     * an; "trifft alle aktiven Anstellungen" blieb gruen.
     */
    public function test_speichern_laesst_einen_fremden_menschen_unberuehrt(): void
    {
        $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['tax_class', 'is_main_employer']]);
        $fremder = $this->fremder();
        [$rg, $ma] = $this->paar(['tax_class' => '6', 'is_main_employer' => 0], ['tax_class' => '1', 'is_main_employer' => 1]);

        $emp = RecEmployee::find($rg);
        $emp->update(['tax_class' => '1', 'is_main_employer' => true]);

        $this->assertSame('6', (string) $this->zeile($ma)->tax_class, 'Vorflug: die Schwester bekommt die Folge');
        $this->assertSame([$ma], array_column($emp->anstellungsFolgen, 'id'), 'Folgen nur an der Schwester');
        $this->assertFremderUnberuehrt($fremder);
    }

    /**
     * Review W2, dieselbe Probe fuer die Neuanlage: die Vorgabe kommt nur
     * aus den Anstellungen DIESES Menschen. Seine RG-Akte ist Neben/6 und
     * gibt nichts vor; Haupt/1 hat nur der Fremde — die neue Anstellung
     * bleibt deshalb, wie sie ist.
     */
    public function test_neue_anstellung_laesst_einen_fremden_menschen_unberuehrt(): void
    {
        $fremder = $this->fremder();
        [, $ma] = $this->paar(['tax_class' => '6', 'is_main_employer' => 0], ['tax_class' => '1', 'is_main_employer' => 1]);

        $this->assertSame([], (new AnstellungsRegeln())->beiNeuerAnstellung($ma));

        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer, 'keine Vorgabe vom Fremden');
        $this->assertSame('1', (string) $this->zeile($ma)->tax_class);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
        $this->assertFremderUnberuehrt($fremder);
    }

    public function test_verstoesse_zaehlen_nur_aktive_anstellungen(): void
    {
        $this->assertSame(['haupt', 'steuerklasse'], AnstellungsRegeln::verstoesse([
            ['is_active' => 1, 'is_main_employer' => 1, 'tax_class' => '1'],
            ['is_active' => 1, 'is_main_employer' => true, 'tax_class' => '3'],
        ]));
        $this->assertSame([], AnstellungsRegeln::verstoesse([
            ['is_active' => 1, 'is_main_employer' => 1, 'tax_class' => '1'],
            ['is_active' => 0, 'is_main_employer' => 1, 'tax_class' => '1'],
        ]));
        $this->assertSame([], AnstellungsRegeln::verstoesse([
            ['is_active' => 1, 'is_main_employer' => 1, 'tax_class' => '1'],
            ['is_active' => 1, 'is_main_employer' => 0, 'tax_class' => '6'],
        ]));
    }

    public function test_frage_nennt_mit_labels_beide_gesellschaften_voll(): void
    {
        $config = Container::getInstance()->make('config');
        $config->set('recruiting.zas.company_labels', ['RG' => 'RheinGedeck GmbH', 'MA' => 'RheinGedeck Lease | MA']);
        try {
            [$rg, $ma] = $this->paar();
            $this->assertSame('Ist RheinGedeck GmbH der Hauptarbeitgeber?', RecEmployee::find($rg)->editableFieldGroups()['Arbeitgeber']['is_main_employer']['label']);
            $this->assertSame('Ist RheinGedeck Lease | MA der Hauptarbeitgeber?', RecEmployee::find($ma)->editableFieldGroups()['Arbeitgeber']['is_main_employer']['label']);
        } finally {
            $config->set('recruiting.zas.company_labels', null);
        }
    }
}
