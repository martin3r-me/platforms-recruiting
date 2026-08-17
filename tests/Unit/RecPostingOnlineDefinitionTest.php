<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPosting;

/**
 * DIE EINE Definition von „online“ — veröffentlicht UND aktiv.
 *
 * Warum sie einen eigenen Test hat: sie lag als wörtliche Kopie an zwei Stellen
 * der Statistik-Seite (Zeilen-Flag `posting_closed` und die Kennzeichnung in der
 * Auswahlliste), und die Kopien waren schon auseinandergelaufen — eine
 * ENTWURFS-Ausschreibung galt in der Tabelle als geschlossen und sah in der
 * Filterleiste online aus. Jetzt liest beides RecPosting::isOnline(), und dieser
 * Test hält fest, was die Methode bedeutet.
 *
 * Ohne Datenbank: geprüft wird eine reine Zustandsfrage auf einem gefüllten Model.
 */
final class RecPostingOnlineDefinitionTest extends TestCase
{
    /**
     * setRawAttributes statt fill/forceFill: das SCHREIBEN eines datetime-Casts
     * (closes_at) fragt nach dem Datumsformat der Verbindung, und die gibt es hier
     * bewusst nicht. Roh gesetzt bleibt der Test ohne Datenbank — isOnline() liest
     * ohnehin nur status und is_active.
     */
    private function posting(string $status, bool $active, ?string $closesAt = null): RecPosting
    {
        $posting = new RecPosting();
        $posting->setRawAttributes([
            'status' => $status,
            'is_active' => $active ? 1 : 0,
            'closes_at' => $closesAt,
        ], true);

        return $posting;
    }

    public function test_veroeffentlicht_und_aktiv_ist_online(): void
    {
        $this->assertTrue($this->posting('published', true)->isOnline());
    }

    public function test_alles_andere_ist_nicht_online(): void
    {
        // Die drei Wege aus „online“ heraus, einzeln: Entwurf, geschlossen,
        // deaktiviert. Alle drei sind in der Statistik „geschlossen“ — das exakte
        // Gegenteil von online.
        $this->assertFalse($this->posting('draft', true)->isOnline(), 'Entwurf ist nicht veröffentlicht');
        $this->assertFalse($this->posting('closed', true)->isOnline(), 'status=closed ist nicht online');
        $this->assertFalse($this->posting('published', false)->isOnline(), 'deaktiviert ist nicht online');
        $this->assertFalse($this->posting('draft', false)->isOnline());
    }

    public function test_ein_abgelaufenes_laufzeitende_macht_nicht_offline(): void
    {
        // ABGRENZUNG zu scopeOpen(): dort zählt closes_at mit. Für „online“ nicht —
        // eine abgelaufene, aber noch veröffentlichte Ausschreibung ist im Netz
        // erreichbar, und genau so liest sie der Kunde. Würde diese Zeile kippen,
        // wanderten Bewerbungen ohne Zutun in den Block „Geschlossene
        // Ausschreibungen“.
        $this->assertTrue($this->posting('published', true, '2020-01-01 00:00:00')->isOnline());
    }
}
