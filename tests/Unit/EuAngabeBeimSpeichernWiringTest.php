<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fall Mina (28.10.2026): HR korrigiert „EU-Bürger“ in der Bewerbung. Bisher
 * lief der Abgleich in den Rechtsstatus nur beim Phasenabschluss — die
 * Korrektur blieb wirkungslos. Vertrag an der Quelle (Livewire-Render
 * braucht den App-Boot, Muster SharedPartialContractTest); das Verhalten
 * danach deckt VersandVormerkenTest::test_nachtraeglich_nein_* ab.
 */
class EuAngabeBeimSpeichernWiringTest extends TestCase
{
    public function test_bewerbung_speichern_gleicht_die_eu_angabe_ab(): void
    {
        $show = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Applicant/Show.php');
        $speichern = substr($show, (int) strpos($show, 'public function save(): void'));
        $speichern = substr($speichern, 0, (int) strpos($speichern, "\n    }\n"));

        $extra = strpos($speichern, '$this->saveExtraFieldValues($this->applicant);');
        $abgleich = strpos($speichern, '->syncEuCitizenFromExtraField()');
        $this->assertNotFalse($extra);
        $this->assertNotFalse($abgleich, 'save() gleicht die EU-Angabe ab');
        $this->assertGreaterThan($extra, $abgleich, 'erst speichern, dann abgleichen');

        $model = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Models/RecApplicant.php');
        $this->assertStringContainsString('public function syncEuCitizenFromExtraField(): void', $model);
    }
}
