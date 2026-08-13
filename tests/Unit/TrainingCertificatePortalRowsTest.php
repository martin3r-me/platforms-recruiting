<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificatePortalRows;

/**
 * Die Zertifikat-Zeile der beiden Portal-Listen und der Zustand, der aus der
 * Zeilenmenge folgt.
 *
 * Warum das ueberhaupt eine eigene Klasse ist und nicht zweimal inline in den
 * Livewire-Komponenten steht: Livewire-Komponenten sind in diesem Modul NICHT
 * instanziierbar (kein Laravel-Bootstrap, kein testbench). Alles, was in
 * EmployeePortal/ApplicantPortal bleibt, ist damit nur per Sichtpruefung
 * abzudecken. Die zwei Eigenschaften, an denen dieser Task haengt — die Form
 * der Zeile und die Mitzaehlung der Zertifikate im Zustand — liegen deshalb
 * hier, in reinem PHP.
 */
class TrainingCertificatePortalRowsTest extends TestCase
{
    /**
     * Die Schluessel der Vertragszeile, in ihrer Reihenfolge.
     *
     * Quelle: EmployeePortal::contracts() (`src/Livewire/Public/EmployeePortal.php`,
     * map()-Rueckgabe) und ApplicantPortal::mount() — dort identisch. Die
     * Zertifikat-Zeile MUSS dieselbe Form haben, weil beide Blades ueber
     * dieselbe Liste laufen und $c['sign_url'], $c['pdf_url'], $c['signed_at']
     * unbedingt lesen. Ein fehlender Schluessel ist im Betrieb eine
     * "Undefined array key"-Warnung mitten in der Vertragsliste.
     */
    private const VERTRAGSZEILEN_SCHLUESSEL = [
        'id',
        'display_name',
        'status',
        'signed_at',
        'completed_at',
        'sign_url',
        'pdf_url',
    ];

    public function testDieZeileTraegtDieWerteDerSpec(): void
    {
        $row = TrainingCertificatePortalRows::row(7, '2026-08-12 09:30:00', 'https://example.test/recruiting/zertifikat/abc');

        $this->assertSame([
            'id'           => 'cert-7',
            'display_name' => 'Teilnahme-Zertifikat',
            'status'       => 'issued',
            'signed_at'    => '2026-08-12 09:30:00',
            'completed_at' => '2026-08-12 09:30:00',
            'sign_url'     => null,
            'pdf_url'      => 'https://example.test/recruiting/zertifikat/abc',
        ], $row);
    }

    public function testDieZeileHatDieSchluesselDerVertragszeile(): void
    {
        $row = TrainingCertificatePortalRows::row(1, null, 'https://example.test/x');

        $this->assertSame(self::VERTRAGSZEILEN_SCHLUESSEL, array_keys($row));
    }

    /**
     * status='issued' und sign_url=null sind die Traeger von Pflicht 3: der
     * Unterschreiben-Button der beiden Blades verlangt 'sent'/'in_progress',
     * bleibt also von allein weg. Gemessen wird das an der Blade selbst
     * (PortalCertificateBadgeTest); hier steht nur, dass die Zeile die
     * Voraussetzung liefert.
     */
    public function testKeinSignUrlUndEinEigenerStatus(): void
    {
        $row = TrainingCertificatePortalRows::row(3, '2026-08-12', 'https://example.test/x');

        $this->assertNull($row['sign_url']);
        $this->assertSame(TrainingCertificatePortalRows::STATUS, $row['status']);
        $this->assertNotSame('completed', $row['status']);
        $this->assertNotSame('sent', $row['status']);
    }

    /**
     * Die id ist praefixiert, weil Vertrags- und Zertifikat-IDs beide bei 1
     * anfangen und in EINER Liste landen.
     */
    public function testDieIdIstPraefixiertUndKollidiertNichtMitVertragsIds(): void
    {
        $cert = TrainingCertificatePortalRows::row(4, null, 'https://example.test/x');
        $vertragsId = 4;

        $this->assertSame('cert-4', $cert['id']);
        $this->assertNotSame($vertragsId, $cert['id']);
    }

    /**
     * Der Kern der Auflage „ApplicantPortal muss Zertifikate mitzaehlen":
     * ein abgelehnter Nicht-EU-Bewerber hat typischerweise KEINE Vertraege.
     */
    public function testEinZertifikatOhneVertraegeMachtDasPortalNichtLeer(): void
    {
        [$rows, $state] = TrainingCertificatePortalRows::appendWithState(
            [],
            [TrainingCertificatePortalRows::row(9, '2026-08-12', 'https://example.test/x')]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('ready', $state);
        $this->assertSame(TrainingCertificatePortalRows::STATE_READY, $state);
    }

    public function testOhneJedeZeileBleibtDasPortalLeer(): void
    {
        [$rows, $state] = TrainingCertificatePortalRows::appendWithState([], []);

        $this->assertSame([], $rows);
        $this->assertSame('empty', $state);
    }

    public function testVertraegeAlleinMachenDasPortalBereit(): void
    {
        [$rows, $state] = TrainingCertificatePortalRows::appendWithState(
            [['id' => 1, 'display_name' => 'Arbeitsvertrag']],
            []
        );

        $this->assertCount(1, $rows);
        $this->assertSame('ready', $state);
    }

    /** Zertifikate stehen NACH den Vertragszeilen (Spec: „Nach den Vertragszeilen"). */
    public function testZertifikateStehenHinterDenVertraegen(): void
    {
        $vertrag = ['id' => 1, 'display_name' => 'Arbeitsvertrag'];
        $zert    = TrainingCertificatePortalRows::row(9, '2026-08-12', 'https://example.test/x');

        [$rows] = TrainingCertificatePortalRows::appendWithState([$vertrag], [$zert]);

        $this->assertSame([$vertrag, $zert], $rows);
        $this->assertSame([$vertrag, $zert], TrainingCertificatePortalRows::append([$vertrag], [$zert]));
    }

    /**
     * append() und appendWithState() duerfen nicht auseinanderlaufen — die
     * beiden Portale benutzen je eine der zwei Methoden.
     */
    public function testBeideAnhaengeMethodenLiefernDieselbeListe(): void
    {
        $vertraege = [['id' => 1], ['id' => 2]];
        $zerts     = [TrainingCertificatePortalRows::row(9, null, 'https://example.test/x')];

        [$mitZustand] = TrainingCertificatePortalRows::appendWithState($vertraege, $zerts);

        $this->assertSame(TrainingCertificatePortalRows::append($vertraege, $zerts), $mitZustand);
    }

    /**
     * Die Liste ist neu indiziert: die Vertragszeilen kommen aus einem
     * ->filter() auf einer Eloquent-Collection. Ohne values() bzw. ohne
     * Neu-Indizierung haette array_merge Luecken zu fuellen und die Blade
     * liefe ueber ein Array mit Loechern.
     */
    public function testDieListeIstEineLueckenloseListe(): void
    {
        $vertraege = [3 => ['id' => 3], 7 => ['id' => 7]];

        [$rows] = TrainingCertificatePortalRows::appendWithState(
            $vertraege,
            [TrainingCertificatePortalRows::row(9, null, 'https://example.test/x')]
        );

        $this->assertSame([0, 1, 2], array_keys($rows));
    }

    /**
     * Dasselbe Dokument heisst im HR-Schreibtisch schon „Teilnahme-Zertifikat"
     * (Checkbox-Label, Task 11). Zwei Namen fuer ein Dokument waeren ein
     * Support-Fall: HR haekelt „Teilnahme-Zertifikat" an, der Bewerber sucht
     * im Portal etwas anderes.
     */
    public function testDerAnzeigenameStimmtMitDemHrSchreibtischUeberein(): void
    {
        $hrDesk = file_get_contents(__DIR__ . '/../../resources/views/livewire/hr-desk/index.blade.php');

        $this->assertNotFalse($hrDesk, 'hr-desk/index.blade.php nicht lesbar');
        $this->assertStringContainsString(
            TrainingCertificatePortalRows::DISPLAY_NAME,
            $hrDesk,
            'Der Anzeigename der Portal-Zeile kommt im HR-Schreibtisch nicht vor'
        );
    }
}
