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

    /**
     * „alle" darf die schon Angeschriebenen NICHT wieder einsammeln. Sonst
     * haengt genau an diesem einen Klick der Fall, den die Vorauswahl
     * verhindern soll: HR schickt Montag an 30 Leute, Dienstag stehen 5 neue
     * im Topf, HR klickt „alle" — und die 30 bekommen dieselbe Nachricht
     * „wir haben nichts mehr von dir gehoert" ein zweites Mal. Wer sie
     * bewusst erneut anschreiben will, setzt den Haken von Hand; waehlbar
     * bleiben sie dafuer.
     */
    public function testAlleUeberspringtBereitsAngeschriebeneUndGesperrte(): void
    {
        $rows = [
            1 => ['selectable' => true, 'checked' => true],    // frisch
            2 => ['selectable' => true, 'checked' => false],   // schon angeschrieben
            3 => ['selectable' => false, 'checked' => false],  // keine Nummer / im Einsatz
        ];

        $this->assertSame([1 => true, 2 => false, 3 => false], Index::selectAllState($rows, true));
    }

    public function testKeineNimmtAlleHaken(): void
    {
        $rows = [1 => ['selectable' => true, 'checked' => true], 2 => ['selectable' => true, 'checked' => false]];

        $this->assertSame([1 => false, 2 => false], Index::selectAllState($rows, false));
    }

    /** @return array<string, array{0:bool,1:bool,2:int,3:?int,4:?string,5:?string}> */
    public static function startErrorFaelle(): array
    {
        return [
            'nicht verfuegbar (falscher Chip)' => [false, false, 3, 88, null, 'Sammelversand nicht verfügbar.'],
            'laeuft bereits' => [true, true, 3, 88, null, 'Versand läuft bereits.'],
            'niemand ausgewaehlt' => [true, false, 0, 88, null, 'Niemand ausgewählt.'],
            'kein Template' => [true, false, 3, null, null, 'Kein Template gewählt.'],
            'Template-ID 0 zaehlt wie keins' => [true, false, 3, 0, null, 'Kein Template gewählt.'],
            // Vorabpruefung: das Template taugt nicht fuer diesen Versandweg.
            // Sie kommt ZULETZT — erst muss ueberhaupt eins gewaehlt sein.
            'Template passt nicht' => [true, false, 3, 88, 'Template „x“ hat einen URL-Button mit Variable.', 'Template „x“ hat einen URL-Button mit Variable.'],
            'Happy Path' => [true, false, 3, 88, null, null],
        ];
    }

    #[DataProvider('startErrorFaelle')]
    public function testStartError(bool $enabled, bool $alreadyStarted, int $selected, ?int $templateId, ?string $templateError, ?string $expected): void
    {
        $this->assertSame($expected, Index::noAssignmentStartError($enabled, $alreadyStarted, $selected, $templateId, $templateError));
    }
}
