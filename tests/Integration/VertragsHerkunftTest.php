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

    public function test_merker_nur_mit_zuschlagsfeld(): void
    {
        [, $rg, $t, $v] = $this->akteVertragMitBewerbung();
        $this->assertTrue(VertragsHerkunft::ausAkte($v->fresh()));

        $alt = $this->vertragAn($rg, $t, [], ['vertragsbeginn' => '2026-10-01']);
        $this->assertFalse(VertragsHerkunft::ausAkte($alt->fresh()), 'Bewerbungsweg ohne Zuschlagsfeld');

        $leer = $this->vertragAn($rg, $t, [], ['zuschlag' => '  ']);
        $this->assertFalse(VertragsHerkunft::ausAkte($leer->fresh()));
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
