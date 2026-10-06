<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Der Kampagnen-Bereich ist nur im Scope „Ohne Termin“ erreichbar — die
 * anderen Drill-Downs (gebucht, unterschrieben, Termin-Teilnehmer) zeigen ihn
 * nicht. Geprueft ohne Container (new Index(), Muster FremdeFilialeReasonTextTest).
 *
 * campaignEnabled() verlangt seit dem Fix-Review DREI Locked-Properties, nicht
 * eine: $drillScopeName MUSS 'type_all' sein, $drillScopeType MUSS
 * 'ohne_schulung' sein, UND $drillHasSet MUSS false sein. Grund: das
 * Drill-Token ist unsigniertes Base64-JSON ohne Signatur — ein manipuliertes
 * Token wie {"scope":"all","type":"ohne_schulung"} darf die Kampagne NICHT
 * ueber die ganze Kohorte oeffnen, und ein Token wie
 * {"scope":"type_all","type":"ohne_schulung","set":"unknown_origin"} darf sie
 * NICHT ueber eine der drei beiseite gelegten Mengen oeffnen (drill() loest
 * $rows unabhaengig von 'scope' ueber ein vorhandenes 'set' auf). Der einzige
 * Token in der View, der type => 'ohne_schulung' setzt, ist die Kachel
 * index.blade.php:215 mit scope 'type_all' und OHNE 'set' — genau diese
 * Kombination ist also die einzige, die durchgelassen werden darf.
 */
final class CampaignModalStateTest extends TestCase
{
    public function testKampagneNurImScopeOhneSchulung(): void
    {
        $c = new Index();
        $c->drillIds = [1, 2];

        $c->drillScopeName = 'type_all';
        $c->drillScopeType = 'ohne_schulung';
        $c->drillHasSet = false;
        $this->assertTrue($c->campaignEnabled());

        $c->drillScopeType = 'schulung';
        $this->assertFalse($c->campaignEnabled());

        $c->drillScopeType = 'ohne_schulung';
        $c->drillIds = [];
        $this->assertFalse($c->campaignEnabled(), 'Leere Auswahl → kein Button.');
    }

    /**
     * Ohne diese zweite Sperre wuerde ein gecraftetes Token mit scope 'all'
     * (die Gesamt-Kachel) oder scope 'closed'/'unreachable'/'unknown_origin'
     * (die Beiseite-gelegten Mengen) die Kampagne oeffnen, sobald es zusaetzlich
     * type => 'ohne_schulung' traegt — beides sind Mengen, die die Kachel
     * „Ohne Termin" NICHT ist.
     */
    public function testKampagneNichtUeberAndereScopesTrotzPassendemType(): void
    {
        $c = new Index();
        $c->drillIds = [1, 2];
        $c->drillScopeType = 'ohne_schulung';

        $c->drillScopeName = 'all';
        $this->assertFalse($c->campaignEnabled(), 'scope "all" + type "ohne_schulung" darf NICHT reichen.');

        $c->drillScopeName = 'posting';
        $this->assertFalse($c->campaignEnabled());
    }

    /**
     * Kampagne „Schulung voll" (05.10.2026): zweiter legitimer Einstieg ist
     * die Pille „N ohne Termin" an einem ausgebuchten Termin in Tabelle 2 —
     * Scope 'posting_type' mit type 'ohne_schulung', ohne 'set'. Nur dort gilt
     * der Modus „nur Buchungsphase" (Template-A-Zeilen gesperrt); die Kachel
     * „Ohne Termin" bleibt, wie sie war.
     */
    public function testKampagneAuchUeberDiePilleAmAusgebuchtenTermin(): void
    {
        $c = new Index();
        $c->drillIds = [1, 2];
        $c->drillScopeName = 'posting_type';
        $c->drillScopeType = 'ohne_schulung';
        $c->drillHasSet = false;

        $this->assertTrue($c->campaignEnabled());
        $this->assertTrue($c->campaignNurBuchung());

        $c->drillScopeType = 'schulung';
        $this->assertFalse($c->campaignEnabled(), 'posting_type mit anderem Typ: kein Versand an Teilnehmer');

        $c->drillScopeType = 'ohne_schulung';
        $c->drillHasSet = true;
        $this->assertFalse($c->campaignEnabled(), "'set' redirigiert die ID-Aufloesung — bleibt gesperrt");

        $c->drillHasSet = false;
        $c->drillScopeName = 'type_all';
        $this->assertTrue($c->campaignEnabled());
        $this->assertFalse($c->campaignNurBuchung(), 'Kachel „Ohne Termin": beide Templates wie bisher');
    }

    /**
     * Review 05.10.: die Pille hinterliess ihr „freie Termine"-Template in der
     * Kachel. Die Vorbelegung ist jetzt modusbewusst: Moduswechsel → Default
     * des neuen Modus, gleicher Modus → Auswahl von HR bleibt.
     */
    public function testTemplateBVorbelegungFolgtDemModus(): void
    {
        $full = 11; $booking = 22; $hr = 33;

        // erstes Oeffnen (kein voriger Modus)
        $this->assertSame($full, Index::campaignTemplateBFor('pille', '', null, $full, $booking));
        $this->assertSame($booking, Index::campaignTemplateBFor('pille', '', null, null, $booking), 'Rueckfall auf B ohne eigenes Template');
        $this->assertSame($booking, Index::campaignTemplateBFor('kachel', '', null, $full, $booking), 'Kachel kennt das Pillen-Template nicht');

        // gleicher Modus: HR-Auswahl bleibt
        $this->assertSame($hr, Index::campaignTemplateBFor('pille', 'pille', $hr, $full, $booking));
        $this->assertSame($hr, Index::campaignTemplateBFor('kachel', 'kachel', $hr, $full, $booking));

        // Moduswechsel: alte Auswahl wird verworfen
        $this->assertSame($booking, Index::campaignTemplateBFor('kachel', 'pille', $full, $full, $booking), 'Pille → Kachel: nicht das Pillen-Template behalten');
        $this->assertSame($full, Index::campaignTemplateBFor('pille', 'kachel', $booking, $full, $booking), 'Kachel → Pille: eigenes Template');

        $this->assertNull(Index::campaignTemplateBFor('kachel', '', null, null, null), 'nichts gesetzt → nichts vorbelegt');
    }

    /**
     * UX-Paket 06.10.: die Bestaetigung vor dem Senden sagt in HR-Sprache, wer
     * was bekommt, und dass danach nichts von selbst nachkommt.
     */
    public function testBestaetigungstextNenntGruppenUndKeineErinnerungen(): void
    {
        $text = Index::campaignConfirmText(['A' => 9, 'B' => 30, 'total' => 39], false);

        $this->assertStringStartsWith('WhatsApp jetzt an 39 Personen senden?', $text);
        $this->assertStringContainsString('9 × „Angaben ergänzen“ (Link zum Formular)', $text);
        $this->assertStringContainsString('30 × „Termine ansehen“ (Link zur Terminauswahl)', $text);
        $this->assertStringContainsString('keine automatischen Erinnerungen', $text);

        $pille = Index::campaignConfirmText(['A' => 0, 'B' => 1, 'total' => 1], true);
        $this->assertStringStartsWith('WhatsApp jetzt an 1 Person senden?', $pille);
        $this->assertStringNotContainsString('Angaben ergänzen', $pille, 'im Modus „Schulung voll“ gibt es nur die Terminauswahl');
    }

    public function testDefaultsDerProperties(): void
    {
        $c = new Index();
        $this->assertSame('', $c->drillScopeType);
        $this->assertSame('', $c->drillScopeName);
        $this->assertFalse($c->drillHasSet);
        $this->assertSame([], $c->campaignSelection);
        $this->assertNull($c->campaignUuid);
        $this->assertSame('', $c->campaignError);
        $this->assertNull($c->campaignAnlassInterviewId);
        $this->assertNull($c->campaignAnlassPostingId);
        $this->assertSame('', $c->campaignTemplateMode);
    }

    /**
     * Residualbefund aus dem zweiten Re-Review: drill() loest $rows anhand von
     * match ($spec['set'] ?? null) auf — UNABHAENGIG von 'scope'. Ein Token wie
     * {"scope":"type_all","type":"ohne_schulung","set":"unknown_origin"}
     * besteht scope/type-Pruefung, zeigt IDs aber gegen eine der drei
     * beiseite gelegten Mengen (closed/unreachable/unknown_origin) — eine
     * Population, die die Kachel "Ohne Termin" nie zeigt. drillHasSet faengt
     * genau das ab.
     */
    public function testKampagneNichtMitSetImToken(): void
    {
        $c = new Index();
        $c->drillIds = [1];
        $c->drillScopeName = 'type_all';
        $c->drillScopeType = 'ohne_schulung';
        $c->drillHasSet = true;

        $this->assertFalse($c->campaignEnabled(), 'Ein set-Schluessel im Token darf die Kampagne NICHT freischalten.');
    }

    /** @return array<string, array{0:bool,1:bool,2:array{A:int,B:int,total:int},3:?int,4:?int,5:?string}> */
    public static function startErrorFaelle(): array
    {
        $counts = ['A' => 2, 'B' => 1, 'total' => 3];
        $nullCounts = ['A' => 0, 'B' => 0, 'total' => 0];

        return [
            'Kampagne nicht verfuegbar (nicht enabled)' => [false, false, $counts, 5, 6, 'Kampagne nicht verfügbar.'],
            'Kampagne laeuft bereits' => [true, true, $counts, 5, 6, 'Kampagne läuft bereits.'],
            'Niemand ausgewaehlt' => [true, false, $nullCounts, 5, 6, 'Niemand ausgewählt.'],
            'Template A fehlt' => [true, false, $counts, null, 6, 'Für 2 Personen fehlt die Nachricht „Angaben ergänzen“ — Vorlage wählen.'],
            'Template B fehlt' => [true, false, $counts, 5, null, 'Für 1 Personen fehlt die Nachricht „Termine ansehen“ — Vorlage wählen.'],
            'Happy Path' => [true, false, $counts, 5, 6, null],
        ];
    }

    #[DataProvider('startErrorFaelle')]
    public function testCampaignStartError(bool $enabled, bool $alreadyStarted, array $counts, ?int $templateA, ?int $templateB, ?string $expected): void
    {
        $this->assertSame($expected, Index::campaignStartError($enabled, $alreadyStarted, $counts, $templateA, $templateB));
    }
}
