<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersTexte;

/**
 * Wortlaut 1:1 nach Markus' Folien 1–3 („…_kommentiert_MA_SH_MA II.pptx“, 28.10.):
 * die Seite zeigt genau den Portal-Text der Folie, nichts gekuerzt.
 */
final class JobOffersTexteTest extends TestCase
{
    public function testVorrang(): void
    {
        $this->assertSame('dringend', JobOffersTexte::anlassVorrang(['vier_wochen', 'dringend', null]));
        $this->assertSame('naechste_woche', JobOffersTexte::anlassVorrang(['vier_wochen', 'naechste_woche']));
        $this->assertNull(JobOffersTexte::anlassVorrang([null, 'xy']));
    }

    public function testFolie1DringendWortlaut(): void
    {
        $t = JobOffersTexte::fuer('dringend', 'Lena', 'Do 30.10., 12:30 Uhr');
        $this->assertSame('Hey Lena, wir zählen auf dich!', $t['kicker']);
        $this->assertSame('Für diese Veranstaltung brauchen wir dringend Unterstützung.', $t['satz']);
        $this->assertSame('Bitte antworte bis Do 30.10., 12:30 Uhr, ob du dabei bist. Wenn nicht, gib uns bitte Bescheid.', $t['antwort']);
        $this->assertNull($t['fussnote']);

        $this->assertSame('Hey, wir zählen auf dich!', JobOffersTexte::fuer('dringend', '', null)['kicker'], 'ohne Vornamen kein doppeltes Komma');
        $this->assertNull(JobOffersTexte::fuer('dringend', 'Lena', null)['antwort'], 'ohne geplante Welle gibt es keine Frist');
    }

    public function testFolie2WocheWortlaut(): void
    {
        $t = JobOffersTexte::fuer('naechste_woche', 'Lena', 'morgen 08:00 Uhr');
        $this->assertSame('Hier brauchen wir deine Unterstützung!', $t['kicker']);
        $this->assertSame('Schau dir die offenen Einsätze für nächste Woche an und melde dich für alle Tage, an denen du dabei sein kannst.', $t['satz']);
        $this->assertSame('Eine Meldung ist noch keine bestätigte Einteilung.', $t['fussnote']);
        $this->assertSame('Bitte antworte bis morgen 08:00 Uhr', $t['antwort'], 'Frist-Zeile wie bisher, der Folien-Nachsatz gehoert nur zu Dringend');
    }

    public function testFolie3VierWochenWortlaut(): void
    {
        $t = JobOffersTexte::fuer('vier_wochen', 'Lena', null);
        $this->assertSame('Sichere dir frühzeitig deine Einsätze!', $t['kicker']);
        $this->assertSame('Wer zuerst reagiert, hat bessere Chancen auf passende Schichten – und damit auf die Stunden und den Verdienst, die du dir wünschst. Schau jetzt rein und melde dich für deine Wunschtermine.', $t['satz']);
        $this->assertSame('Keine Garantie auf eine Einteilung.', $t['fussnote']);
        $this->assertNull($t['antwort']);
    }

    public function testOhneAnlass(): void
    {
        $t = JobOffersTexte::fuer(null, 'Lena', 'heute 12:30 Uhr');
        $this->assertSame('Wir suchen Verstärkung', $t['kicker'], 'alte Anfragen ohne Anlass');
        $this->assertNull($t['satz']);
        $this->assertSame('Bitte antworte bis heute 12:30 Uhr', $t['antwort']);
    }

    public function testAntwortenBis(): void
    {
        $jetzt = Carbon::parse('2026-10-27 09:00:00');
        $this->assertSame('heute 12:30 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-27 12:30:00'), $jetzt));
        $this->assertSame('morgen 08:00 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-28 08:00:00'), $jetzt));
        $this->assertSame('Fr 30.10., 12:30 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-30 12:30:00'), $jetzt));
    }
}
