<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\LohnrelevanteFelder;

/** Sebastian 26.10.2026: was fuer die Lohnabrechnung fehlen darf — eine Liste. */
final class LohnrelevanteFelderTest extends TestCase
{
    private function meta(array $felder): array
    {
        $m = [];
        foreach ($felder as $f) {
            $m[$f] = ['label' => strtoupper($f)];
        }
        $m['other_employer'] = ['label' => 'ANDERER', 'required_if' => ['is_main_employer' => false]];

        return $m;
    }

    public function test_freiwillige_felder_zaehlen_nicht(): void
    {
        $meta = $this->meta(['religion', 'marital_status', 'bic', 'iban']);
        $fehlt = LohnrelevanteFelder::fehlend($meta, ['religion' => null, 'marital_status' => null, 'bic' => null, 'iban' => 'DE02']);

        $this->assertSame([], $fehlt);
    }

    public function test_iban_fehlt(): void
    {
        $fehlt = LohnrelevanteFelder::fehlend($this->meta(['iban']), ['iban' => '']);

        $this->assertSame(['iban' => 'IBAN'], $fehlt);
    }

    public function test_geburtsangaben_nur_ohne_sv_nummer(): void
    {
        $meta = $this->meta(['gender', 'birth_place', 'sozialversicherungsnummer']);

        $this->assertSame([], LohnrelevanteFelder::fehlend($meta, ['gender' => null, 'birth_place' => null, 'sozialversicherungsnummer' => '12 345678 A 123']));
        $this->assertSame(
            ['sozialversicherungsnummer', 'gender', 'birth_place'],
            array_keys(LohnrelevanteFelder::fehlend($meta, ['gender' => null, 'birth_place' => null, 'sozialversicherungsnummer' => null])),
        );
    }

    public function test_anderer_arbeitgeber_nur_wenn_nicht_hauptarbeitgeber(): void
    {
        $meta = $this->meta(['is_main_employer']);

        $this->assertSame([], LohnrelevanteFelder::fehlend($meta, ['is_main_employer' => true, 'other_employer' => null]));
        $this->assertSame(['other_employer' => 'ANDERER'], LohnrelevanteFelder::fehlend($meta, ['is_main_employer' => false, 'other_employer' => null]));
    }

    public function test_null_kinder_ist_eine_angabe(): void
    {
        $this->assertSame([], LohnrelevanteFelder::fehlend($this->meta(['number_of_children']), ['number_of_children' => 0]));
    }

    public function test_feld_ausserhalb_des_katalogs_wird_uebergangen(): void
    {
        // Nicht im Profil (z. B. Gruppe nicht sichtbar) → nicht verlangt.
        $this->assertSame([], LohnrelevanteFelder::fehlend([], ['iban' => null]));
    }
}
