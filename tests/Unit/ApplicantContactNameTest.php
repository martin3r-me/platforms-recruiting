<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ApplicantContactName;

class ApplicantContactNameTest extends TestCase
{
    private function candidate(int $id, ?string $first, ?string $last, ?string $full = null): array
    {
        return ['contact_id' => $id, 'first_name' => $first, 'last_name' => $last, 'full_name' => $full];
    }

    public function test_kleinste_contact_id_gewinnt_unabhaengig_von_der_reihenfolge(): void
    {
        // crmContactLinks ist ein morphMany OHNE Ordering (Spec F11) — ->first()
        // ist nicht deterministisch. Ohne feste Wahl kann sich die Sortierung
        // der Liste zwischen zwei Renderings aendern.
        $a = $this->candidate(77, 'Anna', 'Zimmermann');
        $b = $this->candidate(12, 'Bernd', 'Achterberg');

        $this->assertSame(12, ApplicantContactName::pick([$a, $b])['contact_id']);
        $this->assertSame(12, ApplicantContactName::pick([$b, $a])['contact_id']);
    }

    public function test_ohne_kandidaten_null(): void
    {
        $this->assertNull(ApplicantContactName::pick([]));
    }

    public function test_anzeige_ist_nachname_komma_vorname(): void
    {
        $this->assertSame(
            'Achterberg, Bernd',
            ApplicantContactName::display([$this->candidate(1, 'Bernd', 'Achterberg')]),
        );
    }

    public function test_anzeige_faellt_auf_full_name_zurueck_wenn_teile_fehlen(): void
    {
        $this->assertSame(
            'Laith Kanjo Allahham',
            ApplicantContactName::display([$this->candidate(1, null, null, 'Laith Kanjo Allahham')]),
        );
    }

    public function test_anzeige_nutzt_vorhandenen_teil_wenn_nur_einer_fehlt(): void
    {
        $this->assertSame('Achterberg', ApplicantContactName::display([$this->candidate(1, null, 'Achterberg')]));
        $this->assertSame('Bernd', ApplicantContactName::display([$this->candidate(1, 'Bernd', null)]));
    }

    public function test_anzeige_ohne_jede_quelle_ist_unbekannt(): void
    {
        $this->assertSame('Unbekannt', ApplicantContactName::display([]));
        $this->assertSame('Unbekannt', ApplicantContactName::display([$this->candidate(1, null, null, null)]));
        $this->assertSame('Unbekannt', ApplicantContactName::display([$this->candidate(1, '  ', '  ', '  ')]));
    }

    public function test_sortierschluessel_entspricht_der_anzeige_in_kleinschreibung(): void
    {
        // Anzeige und Sortierung MUESSEN aus derselben Quelle kommen, sonst sieht
        // die Liste fuer den Nutzer unsortiert aus (Spec §3).
        $c = [$this->candidate(1, 'Bernd', 'Achterberg')];
        $this->assertSame('achterberg, bernd', ApplicantContactName::sortKey($c));
    }

    public function test_leerfaelle_sortieren_ans_ende_und_kippen_nicht(): void
    {
        $ohne = ApplicantContactName::sortKey([]);
        $mit  = ApplicantContactName::sortKey([$this->candidate(1, 'Anna', 'Zimmermann')]);

        $this->assertGreaterThan(0, strcmp($ohne, $mit), 'Bewerber ohne Namen muessen hinter benannte sortieren.');
    }

    public function test_sortierung_einer_liste_ist_alphabetisch(): void
    {
        $rows = [
            [$this->candidate(3, 'Anna', 'Zimmermann')],
            [$this->candidate(1, 'Bernd', 'Achterberg')],
            [],
            [$this->candidate(2, 'Clara', 'Meyer')],
        ];

        usort($rows, fn ($a, $b) => strcmp(ApplicantContactName::sortKey($a), ApplicantContactName::sortKey($b)));

        $this->assertSame([
            'Achterberg, Bernd',
            'Meyer, Clara',
            'Zimmermann, Anna',
            'Unbekannt',
        ], array_map(fn ($r) => ApplicantContactName::display($r), $rows));
    }
}
