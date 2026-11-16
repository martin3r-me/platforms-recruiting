<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\QueryException;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

class ZeitEintragTest extends ZeitTestCase
{
    private function eintrag(array $attrs = []): ZeitEintrag
    {
        return ZeitEintrag::create(array_merge([
            'person_typ' => 'rec_employee',
            'person_id'  => 901,
            'bezug_typ'  => 'rec_dispo_assignment',
            'bezug_id'   => 1,
            'tag'        => '2026-10-01',
        ], $attrs));
    }

    public function test_anlegen_setzt_uuid(): void
    {
        $e = $this->eintrag();
        $this->assertNotEmpty($e->uuid);
        $this->assertSame(36, strlen($e->uuid));
    }

    public function test_zweiter_datensatz_mit_gleichem_schluessel_wirft(): void
    {
        $this->eintrag();
        $this->expectException(QueryException::class);
        $this->eintrag();
    }

    public function test_zustand_alle_vier(): void
    {
        $e = $this->eintrag();
        $this->assertSame('offen', $e->zustand());
        $this->assertFalse($e->istGesperrt());

        $e->kommen_at = '2026-10-01 14:00:00';
        $this->assertSame('eingecheckt', $e->zustand());

        $e->gehen_at = '2026-10-01 22:00:00';
        $this->assertSame('ausgecheckt', $e->zustand());

        $e->gesperrt_at = '2026-10-02 08:00:00';
        $this->assertSame('erledigt', $e->zustand());
        $this->assertTrue($e->istGesperrt());
    }

    public function test_finde_trifft_nur_den_eigenen_schluessel(): void
    {
        $e = $this->eintrag();
        $this->eintrag(['person_id' => 902]);
        $this->eintrag(['bezug_id' => 2]);

        $this->assertSame($e->id, ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', 1)?->id);
        $this->assertNull(ZeitEintrag::finde('rec_employee', 903, 'rec_dispo_assignment', 1));
        $this->assertNull(ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', 3));
        $this->assertNull(ZeitEintrag::finde('rec_person', 901, 'rec_dispo_assignment', 1));
    }

    public function test_verlauf_neueste_zuerst_ohne_updated_at(): void
    {
        $e = $this->eintrag();
        foreach (['a', 'b'] as $neu) {
            ZeitVerlauf::create([
                'rec_zeit_eintrag_id' => $e->id, 'feld' => 'bemerkung', 'neu' => $neu,
                'art' => 'korrektur', 'akteur_typ' => 'user', 'akteur_id' => 7, 'akteur_name' => 'Dispo Test',
            ]);
        }
        $verlauf = $e->verlauf()->get();
        $this->assertSame(['b', 'a'], $verlauf->pluck('neu')->all());
        $this->assertNotNull($verlauf->first()->created_at);
        $this->assertArrayNotHasKey('updated_at', $verlauf->first()->getAttributes());
    }
}
