<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Applicant\Show as BewerberAkte;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Services\VertragAusAkte;
use Platform\Recruiting\Services\VertragsAngaben;
use Platform\Recruiting\Support\VertragsHerkunft;
use Platform\Recruiting\Tools\RePersonalizeContractsTool;
use ReflectionMethod;

/**
 * Schlussreview I1: Ein Vertrag aus der Akte an einer RG-Zeile aus dem Funnel
 * traegt eine Bewerbung (rec_applicant_id). Neu-Rendern ueber den
 * Bewerberweg ("Felder" auf der Bewerberseite, MCP-Werkzeug) las den Zuschlag
 * aus rec_applicants.zuschlag — der (evtl. unterschriebene) Vertragstext
 * wechselte still von 0,60 auf 0,30. Der Herkunftsmerker steht an EINER
 * Stelle: VertragsHerkunft::ausAkte().
 */
final class VertragsHerkunftTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        $c = Container::getInstance();
        $c->instance(VertragHinweisSender::class, new class extends VertragHinweisSender {
            public function sende(RecEmployee $employee): string { return 'altes_portal'; }
        });
        $c->instance('session', new class {
            public function flash($k, $v = true): void {}
        });
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance(VertragHinweisSender::class);
        $c->forgetInstance('session');
        $this->weltAbbauen();
        parent::tearDown();
    }

    /** Bewerber mit 0,30, Akte-Vertrag mit 0,60; Ort fehlt im CRM-Kontakt → Anstellung. */
    private function akteVertragMitBewerbung(): array
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 0.3);
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG3']), $b);
        $t = $this->vorlage('AV-default', 'RG', [
            'content' => '<p>{{vorname}}|{{z}}|{{ort}}</p>',
            'field_mappings' => ['vorname' => 'contact.first_name', 'z' => 'applicant.zuschlag', 'ort' => 'contact.address.city'],
        ]);
        $v = (new VertragAusAkte())->erstellen($rg, $t, new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);

        $this->assertSame($b->id, (int) $v->rec_applicant_id, 'Vorflug: Akte-Vertrag traegt die Bewerbung');
        $this->assertSame('<p>Max|0,60|Düsseldorf</p>', $v->personalized_content, 'Vorflug: so steht er im Portal');

        return [$b, $rg, $t, $v];
    }

    public function test_merker_nur_mit_herkunftsfeld(): void
    {
        [, $rg, $t, $v] = $this->akteVertragMitBewerbung();
        $this->assertSame('akte', $v->fresh()->getExtraField('herkunft'), 'VertragAusAkte setzt den Merker');
        $this->assertTrue(VertragsHerkunft::ausAkte($v->fresh()));

        $alt = $this->vertragAn($rg, $t, [], ['vertragsbeginn' => '2026-10-01']);
        $this->assertFalse(VertragsHerkunft::ausAkte($alt->fresh()), 'Bewerbungsweg ohne Merker');

        $nurZuschlag = $this->vertragAn($rg, $t, [], ['zuschlag' => '0,60']);
        $this->assertFalse(VertragsHerkunft::ausAkte($nurZuschlag->fresh()), 'Schlussreview N1: Zuschlagsfeld allein ist kein Merker');
    }

    /**
     * Schlussreview N1: eine Live-Vorlage des Bewerbungswegs mappt
     * contract.extra_field.zuschlag — ihre Vertraege tragen den Zuschlag am
     * Vertrag. Sie sind NICHT "aus der Akte": kein offener Punkt, und das
     * Neu-Rendern bleibt byte-identisch auf dem alten Bewerber-Pfad (kein
     * Anstellungs-Rueckfall fuer den Ort).
     * Probe: ausAkte() wieder auf "zuschlag nicht leer" → rot.
     */
    public function test_bewerbungsvorlage_mit_vertragsfeld_zuschlag_ist_nicht_aus_der_akte(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 0.3);
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG3']), $b);
        $t = $this->vorlage('AV-ZF', 'RG', [
            'content' => '<p>{{z}}|{{ort}}</p>',
            'field_mappings' => ['z' => 'contract.extra_field.zuschlag', 'ort' => 'contact.address.city'],
        ]);
        $v = $this->vertragAn($rg, $t, ['sent_at' => '2026-05-02 10:00:00'], ['vertragsbeginn' => '2026-05-01', 'zuschlag' => '0,60']);
        $v = $v->fresh(['contractTemplate', 'applicant', 'employee']);

        $this->assertFalse(VertragsHerkunft::ausAkte($v));
        $this->assertSame([], app(\Platform\Recruiting\Services\VertragLeser::class)->offenePunkte($rg->fresh()), 'kein offener Punkt');

        $alterPfad = $t->personalizeContent($b->fresh(), $v);
        $this->assertSame('<p>0,60|</p>', $alterPfad, 'Vorflug: alter Pfad, Ort ohne Anstellungs-Rueckfall');
        $this->assertSame($alterPfad, VertragsHerkunft::neuRendern($v), 'byte-identisch zum alten Pfad');
    }

    /** Ohne Felddefinition waere setExtraField() still — kein unmarkierter Akte-Vertrag. */
    public function test_ohne_herkunftsfeld_entsteht_kein_akte_vertrag(): void
    {
        \Illuminate\Support\Facades\DB::table('core_extra_field_definitions')->where('name', 'herkunft')->delete();
        $this->extraFieldCacheLeeren();
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG3']);
        $t = $this->vorlage('AV-default', 'RG');

        try {
            (new VertragAusAkte())->erstellen($rg, $t, new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
            $this->fail('erwartet: DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('seed-rec-contract-extra-fields', $e->getMessage());
        }
        $this->assertSame(0, RecContract::query()->count(), 'Transaktion zurueckgerollt');
    }

    /** Der Merker steht nicht im "Felder"-Dialog und ueberlebt das Speichern. */
    public function test_felder_dialog_zeigt_den_merker_nicht(): void
    {
        [$b, , , $v] = $this->akteVertragMitBewerbung();

        $seite = new BewerberAkte();
        $seite->applicant = $b;
        $seite->openContractFields($v->id);

        $this->assertNotContains('herkunft', array_column($seite->contractFieldDefinitions, 'name'));
        $this->assertContains('zuschlag', array_column($seite->contractFieldDefinitions, 'name'), 'Vorflug: andere Felder da');
        $seite->saveContractFields();
        $this->assertTrue(VertragsHerkunft::ausAkte($v->fresh()));
    }

    /**
     * Schlussreview N2: "Neu ausstellen" eines offenen Akte-Vertrags. Der
     * Nachfolger bleibt "aus der Akte" (Merker + Zuschlag am Vertrag), die
     * Bewerbung bleibt unberuehrt, die Notiz nennt den alten Vertragswert.
     * Probe: createSuccessor ohne Akte-Zweig → rot (Bewerber 0,80, kein Merker).
     */
    public function test_neu_ausstellen_offen_behaelt_herkunft_und_laesst_die_bewerbung(): void
    {
        [$b, , , $v] = $this->akteVertragMitBewerbung();

        $neu = (new \Platform\Recruiting\Services\ReissueContractService())->reissueOpen($v->fresh(), 0.8)['contract']->fresh();

        $this->assertEqualsWithDelta(0.3, (float) $b->fresh()->zuschlag, 0.0001, 'rec_applicants.zuschlag unberuehrt');
        $this->assertTrue(VertragsHerkunft::ausAkte($neu), 'Merker wandert mit');
        $this->assertSame('0,80', $neu->getExtraField('zuschlag'));
        $this->assertSame('2026-10-31', $neu->getExtraField('vertragsende'), 'Laufzeit wandert mit');
        $this->assertSame('<p>Max|0,80|Düsseldorf</p>', $neu->personalized_content, 'Text ueber die Anstellung');
        $this->assertStringContainsString('Zuschlag 0,60 → 0,80', (string) $neu->notes, 'alter Wert aus dem Vertrag, nicht 0,30');
        $this->assertSame('cancelled', $v->fresh()->status);
        $this->assertSame(['vertrag:' . $neu->id], array_column(app(\Platform\Recruiting\Services\VertragLeser::class)->offenePunkte($neu->employee), 'code'));
    }

    /** N2, unterschriebener Akte-Vertrag + Erhoehung: Lohnmeldung an der Vertrags-Anstellung mit 0,60 → 0,80. */
    public function test_neu_ausstellen_unterschrieben_meldet_den_vertragswert(): void
    {
        [$b, $rg, , $v] = $this->akteVertragMitBewerbung();
        $v->forceFill(['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00'])->save();

        $r = (new \Platform\Recruiting\Services\ReissueContractService())->reissue($v->fresh(), 0.8, \Platform\Recruiting\Services\ReissueContractService::REASON_RAISE);

        $this->assertTrue($r['payroll_reported']);
        $this->assertEqualsWithDelta(0.3, (float) $b->fresh()->zuschlag, 0.0001);
        $this->assertTrue(VertragsHerkunft::ausAkte($r['contract']->fresh()));
        $eintrag = json_decode((string) \Illuminate\Support\Facades\DB::table('rec_employees')->where('id', $rg->id)->value('payroll_data_changed_fields'), true);
        $this->assertSame('0,60', $eintrag[0]['old']);
        $this->assertSame('0,80', $eintrag[0]['new']);
    }

    /** Probe: neuRendern() im Werkzeug wieder nur ueber personalizeContent → rot. */
    public function test_werkzeug_rendert_akte_vertrag_mit_bewerbung_ueber_die_anstellung(): void
    {
        [, , , $v] = $this->akteVertragMitBewerbung();

        $m = new ReflectionMethod(RePersonalizeContractsTool::class, 'neuRendern');
        $m->setAccessible(true);

        $this->assertSame('<p>Max|0,60|Düsseldorf</p>', $m->invoke(new RePersonalizeContractsTool(), $v->fresh(['contractTemplate', 'applicant'])));
    }

    /** Probe: saveContractFields() wieder ueber personalizeContent → rot (0,30, Ort leer). */
    public function test_felder_speichern_auf_der_bewerberseite_behaelt_den_zuschlag(): void
    {
        [$b, , , $v] = $this->akteVertragMitBewerbung();

        $seite = new BewerberAkte();
        $seite->applicant = $b;
        $seite->openContractFields($v->id);
        $seite->contractFieldValues['vertragsende'] = '2026-10-30';
        $seite->saveContractFields();

        $neu = $v->fresh();
        $this->assertSame('2026-10-30', $neu->getExtraField('vertragsende'), 'Vorflug: gespeichert');
        $this->assertSame('<p>Max|0,60|Düsseldorf</p>', $neu->personalized_content);
    }

    public function test_bewerbungsweg_bleibt_fuer_altvertraege(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 0.3);
        $t = $this->vorlage('AV-default', 'RG', [
            'content' => '<p>{{z}}|{{ort}}</p>',
            'field_mappings' => ['z' => 'applicant.zuschlag', 'ort' => 'contact.address.city'],
        ]);
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG3']), $b);
        $v = $this->vertragAn($rg, $t);

        $m = new ReflectionMethod(RePersonalizeContractsTool::class, 'neuRendern');
        $m->setAccessible(true);

        $this->assertSame('<p>0,30|</p>', $m->invoke(new RePersonalizeContractsTool(), $v->fresh(['contractTemplate', 'applicant'])),
            'ohne Zuschlagsfeld: Bewerber-Zuschlag, kein Anstellungs-Rueckfall (wie bisher)');
    }
}
