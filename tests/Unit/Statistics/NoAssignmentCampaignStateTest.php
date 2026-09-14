<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Die beiden reinen Stuecke des Sammelversands „ohne Einsatz" — ohne Container
 * geprueft (Muster CampaignModalStateTest).
 *
 * Die Empfaengermenge ist bewusst eine STATISCHE Ableitung aus der Personen-
 * liste des Modals und kein Client-Wert: die Auswahl im Browser darf den Kreis
 * nur verkleinern, nie erweitern. Wer im Topf „im Einsatz" oder „nicht
 * pruefbar" steht, kann deshalb gar nicht erst adressiert werden.
 */
final class NoAssignmentCampaignStateTest extends TestCase
{
    /** @return list<array{id:int, topf:?string}> */
    private static function personen(): array
    {
        return [
            ['id' => 208, 'topf' => 'ohne_einsatz'],
            ['id' => 204, 'topf' => 'im_einsatz'],
            ['id' => 211, 'topf' => 'einsatz_unpruefbar'],
            ['id' => 201, 'topf' => null],
            ['id' => 209, 'topf' => 'ohne_einsatz'],
        ];
    }

    public function testNurDerTopfOhneEinsatzIstEmpfaenger(): void
    {
        $this->assertSame([208, 209], Index::ohneEinsatzIds(self::personen()));
    }

    public function testLeereListeGibtKeineEmpfaenger(): void
    {
        $this->assertSame([], Index::ohneEinsatzIds([]));
    }

    /** @return array<string, array{0:bool,1:bool,2:int,3:?int,4:?string}> */
    public static function startErrorFaelle(): array
    {
        return [
            'nicht verfuegbar (falscher Chip)' => [false, false, 3, 88, 'Sammelversand nicht verfügbar.'],
            'laeuft bereits' => [true, true, 3, 88, 'Versand läuft bereits.'],
            'niemand ausgewaehlt' => [true, false, 0, 88, 'Niemand ausgewählt.'],
            'kein Template' => [true, false, 3, null, 'Kein Template gewählt.'],
            'Template-ID 0 zaehlt wie keins' => [true, false, 3, 0, 'Kein Template gewählt.'],
            'Happy Path' => [true, false, 3, 88, null],
        ];
    }

    #[DataProvider('startErrorFaelle')]
    public function testStartError(bool $enabled, bool $alreadyStarted, int $selected, ?int $templateId, ?string $expected): void
    {
        $this->assertSame($expected, Index::noAssignmentStartError($enabled, $alreadyStarted, $selected, $templateId));
    }
}
