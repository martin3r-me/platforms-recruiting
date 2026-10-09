<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContract;

/**
 * Spec Vertrag aus der Akte §2.3 — Personalisierung mit der Anstellung als
 * zweiter Quelle. Der Bewerbungsweg (personalizeContent) bleibt
 * byteidentisch: der erste Test laeuft VOR dem Umbau gruen und danach immer
 * noch (zusaetzlich zu PlaceholderResolutionPinTest).
 */
final class PersonalisierungAnstellungTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    public function test_bewerbungsweg_rendert_byteidentisch(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 1.5);
        $t = $this->vorlage('AV-default', 'RG', [
            'content' => '<p>{{vorname}} {{nachname}}|{{ort}}|{{zuschlag}}|{{beginn}}|{{lohn}}|{{txt}}|{{heute}}|{{neu}}</p>',
            'field_mappings' => [
                'vorname'  => 'contact.first_name',
                'nachname' => 'contact.last_name',
                'ort'      => 'contact.address.city',
                'zuschlag' => 'applicant.zuschlag',
                'beginn'   => 'contract.extra_field.vertragsbeginn',
                'lohn'     => 'settings.minimum_wage_hourly',
                'txt'      => 'text:Hallo',
                'heute'    => 'meta.datum_heute',
                'neu'      => 'employee.first_name',
            ],
        ]);
        $v = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $t->id, 'team_id' => $this->team,
            'status' => 'pending', 'personalized_content' => '',
        ]);
        $v->setExtraField('vertragsbeginn', '2026-11-01');

        $this->assertSame(
            '<p>Max Muster||1,50|2026-11-01|13,90|Hallo|09.10.2026|</p>',
            $t->personalizeContent($b, $v),
            'employee.* gibt es im Bewerbungsweg nicht — leer wie jedes unbekannte Praefix'
        );
    }

    /**
     * Schlussreview I1: applicant.zuschlag liest auch im Bewerbungsweg zuerst
     * das Vertragsfeld. Altvertraege ohne Feld bleiben byteidentisch (Test
     * oben + PlaceholderResolutionPinTest Fall 8). Probe: Vorrang in
     * personalize() entfernen → 1,50 statt 0,60, rot.
     */
    public function test_bewerbungsweg_nimmt_den_zuschlag_aus_dem_vertragsfeld(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 1.5);
        $t = $this->vorlage('AV-default', 'RG', ['content' => '<p>{{z}}</p>', 'field_mappings' => ['z' => 'applicant.zuschlag']]);
        $mit = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $t->id, 'team_id' => $this->team,
            'status' => 'sent', 'personalized_content' => '',
        ]);
        $mit->setExtraField('zuschlag', '0,60');
        $ohne = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $t->id, 'team_id' => $this->team,
            'status' => 'sent', 'personalized_content' => '',
        ]);

        $this->assertSame('<p>0,60</p>', $t->personalizeContent($b, $mit), 'Vertragsfeld gewinnt');
        $this->assertSame('<p>1,50</p>', $t->personalizeContent($b, $ohne), 'ohne Feld: Bewerber wie bisher');
        $this->assertSame('<p>1,50</p>', $t->personalizeContent($b), 'ohne Vertrag: Bewerber wie bisher');
    }

    /** Probe: mitAnstellung()-Aufruf in personalize() entfernen → hier leerer Name, rot. */
    public function test_ohne_bewerbung_kommen_name_und_adresse_aus_der_anstellung(): void
    {
        $ma = $this->anstellung();
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{vorname}} {{nachname}}, {{strasse}} {{nr}}, {{plz}} {{ort}}, geb. {{geb}}, {{mail}}</p>',
            'field_mappings' => [
                'vorname' => 'contact.first_name', 'nachname' => 'contact.last_name',
                'strasse' => 'contact.address.street', 'nr' => 'contact.address.house_number',
                // Live-Vorlagen mappen postal_code (PlaceholderResolutionPinTest), die Anstellung heisst zip.
                'plz' => 'contact.address.postal_code', 'ort' => 'contact.address.city',
                'geb' => 'contact.birth_date', 'mail' => 'contact.email',
            ],
        ]);
        $v = $this->vertragAn($ma, $t, ['status' => 'pending', 'sent_at' => null]);

        $this->assertNull($v->rec_applicant_id, 'Vorflug: dieser Vertrag hat keine Bewerbung');
        $this->assertSame(
            '<p>Mia Muster, Ringstraße 5, 40210 Düsseldorf, geb. 03.04.2001, mia@example.org</p>',
            $t->personalizeFuerAnstellung($ma, $v)
        );
    }

    public function test_zuschlag_kommt_aus_dem_vertragsfeld_und_faellt_auf_den_bewerber_zurueck(): void
    {
        $t = $this->vorlage('AV-MA-LOG', 'MA', ['content' => '<p>{{z}}</p>', 'field_mappings' => ['z' => 'applicant.zuschlag']]);

        $ohne = $this->anstellung();
        $v1 = $this->vertragAn($ohne, $t, [], ['zuschlag' => '0,60']);
        $this->assertSame('<p>0,60</p>', $t->personalizeFuerAnstellung($ohne, $v1), 'ohne Bewerbung: Vertragsfeld');

        $mit = $this->verknuepfen($this->anstellung(['personnel_number' => 'MA4712']), $this->bewerberMitKontakt('Max', 'Muster', 1.5));
        $v2 = $this->vertragAn($mit, $t);
        $this->assertSame('<p>1,50</p>', $t->personalizeFuerAnstellung($mit, $v2), 'Feld leer → Bewerber');

        $v3 = $this->vertragAn($mit, $t, [], ['zuschlag' => '0,60']);
        $this->assertSame('<p>0,60</p>', $t->personalizeFuerAnstellung($mit, $v3), 'Feld gewinnt vor dem Bewerber');
    }

    public function test_spalten_extrafelder_und_gesperrte_spalten_ohne_bewerbung(): void
    {
        $ma = $this->anstellung(['iban' => 'DE02120300000000202051', 'birth_place' => 'Köln']);
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{iban}}|{{ort}}|{{geb}}|{{tok}}|{{rel}}</p>',
            'field_mappings' => [
                'iban' => 'applicant.iban',
                'ort'  => 'applicant.extra_field.geburtsort',
                'geb'  => 'employee.birth_place',
                'tok'  => 'employee.portal_token',
                'rel'  => 'employee.applicant',
            ],
        ]);
        $v = $this->vertragAn($ma, $t);

        $this->assertSame('<p>DE02120300000000202051||Köln||</p>', $t->personalizeFuerAnstellung($ma, $v),
            'Bewerber-Extrafeld ohne Bewerbung leer (Spec §2.3); Portal-Token und Relationen nie im Vertrag');
    }

    public function test_mit_bewerbung_fuellt_die_anstellung_nur_leere_werte(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $ma = $this->verknuepfen($this->anstellung(), $b);
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{vorname}} {{ort}}</p>',
            'field_mappings' => ['vorname' => 'contact.first_name', 'ort' => 'contact.address.city'],
        ]);
        $v = $this->vertragAn($ma, $t);

        $this->assertSame('<p>Max Düsseldorf</p>', $t->personalizeFuerAnstellung($ma, $v),
            'CRM-Kontakt gewinnt (Max statt Mia); Adresse fehlt dort → Anstellung');
    }
}
