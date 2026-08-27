<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonNameMatch;

/**
 * Alle Fixtures stammen aus dem echten Dry-Run vom 2026-08-27
 * (recruiting:zas-crm-contact-backfill --imported-only, 634 MA).
 *
 * Die drei Fehlmatches unten waren KEINE Softwarefehler der Match-Kaskade:
 * die E-Mail stimmte jeweils exakt und war am Kontakt primaer + aktiv. Falsch
 * war der Mensch dahinter — der MA-Stammsatz aus ZAS trug die Adresse eines
 * anderen. Nur ein Namensabgleich faengt das ab.
 */
class PersonNameMatchTest extends TestCase
{
    /**
     * Die drei belegten Fehlmatches. Ohne Guard wuerde der Backfill hier
     * verlinken — mit der Folge, dass eine WhatsApp-Portal-Einladung samt
     * Login-Hinweisen an die falsche Person geht.
     *
     * @dataProvider fehlmatches
     */
    public function test_fremde_person_matcht_nicht(string $emplFirst, string $emplLast, string $contact): void
    {
        $this->assertFalse(PersonNameMatch::plausible($emplFirst, $emplLast, $contact));
    }

    public static function fehlmatches(): array
    {
        return [
            // MA #126 trug daniel.roesberg@rheingedeck.de — die Firmenadresse
            // eines Kollegen. Daniel selbst ist MA #142 mit privater Adresse.
            'Osselmann/Roesberg' => ['Nina', 'Osselmann', 'Daniel Roesberg'],
            // MA #404 trug Teamvario@vianobis.de, eine Sammeladresse. Bewerber
            // #453 (Bandar Matrouk Alanaze) nutzt dieselbe.
            'MomohWarri/Alanaze' => ['Sonia', 'Momoh Warri', 'Bandar Matrouk Alanaze'],
            // Kontakt #1091 heisst Mohammed Ali, traegt aber Muneebs Gmail —
            // und eine andere Telefonnummer.
            'Ahmed/Ali'          => ['Muneeb', 'Ahmed', 'Mohammed Ali'],
        ];
    }

    /**
     * Gegenprobe: die sauberen Treffer desselben Laufs muessen weiter
     * durchgehen. Ein Guard, der die 126 korrekten Verlinkungen mitreisst,
     * waere schlimmer als das Problem.
     *
     * @dataProvider treffer
     */
    public function test_dieselbe_person_matcht(string $emplFirst, string $emplLast, string $contact): void
    {
        $this->assertTrue(PersonNameMatch::plausible($emplFirst, $emplLast, $contact));
    }

    public static function treffer(): array
    {
        return [
            'exakt'                 => ['Ida', 'Kräuter', 'Ida Kräuter'],
            'kleinschreibung'       => ['Jomart', 'katt', 'Jomart Katt'],
            'diakritika'            => ['Hüseyin', 'Ceker', 'Huseyin Çeker'],
            'namensteil fehlt'      => ['Bao Duy Ngoc', 'Nguyen', 'Bao Nguyen'],
            'reihenfolge getauscht' => ['Muhammad Kashif', 'Bilal', 'Muhammad Bilal'],
            'kosename'              => ['Mohammad Ghanam', 'Aleissa', 'Mo Aleissa'],
            'doppelter vorname'     => ['Luisa', 'Wolf', 'Luisamarie Wolf'],
            'tippfehler im kontakt' => ['Lars Zend', 'Abdull', 'Lars Zend Abdulll'],
            'nachname verdoppelt'   => ['Sheran', 'Navamgnanam', 'Sheran Sheran'],
            'zusammengeschrieben'   => ['Maxima', 'Wamser', 'Unbekannt Maximawamser'],
            'mail im kontaktnamen'  => ['Hamza', 'Himmit', 'Hamza Himmithamza429'],
            'platzhalter nachname'  => ['Louis Revelino', 'Arlanda', 'Louis Unbekannt'],
        ];
    }

    /**
     * Ein Kontakt ohne verwertbaren Namen kann den Guard nicht bestehen —
     * aber er darf auch nicht still als "passt" durchgehen. Solche Faelle
     * gehoeren auf die Handarbeitsliste, nicht in eine stille Verknuepfung.
     */
    public function test_namenloser_kontakt_ist_nicht_plausibel(): void
    {
        // Kontakt #1203 aus dem Lauf: "Unbekannt +4915208331495"
        $this->assertFalse(PersonNameMatch::plausible('Kevin Onyebuchi', 'Obiochirigwe', 'Unbekannt +4915208331495'));
        $this->assertFalse(PersonNameMatch::plausible('Nina', 'Osselmann', ''));
        $this->assertFalse(PersonNameMatch::plausible('Nina', 'Osselmann', '   '));
    }

    /**
     * Ohne Namen am MA gibt es nichts zu vergleichen. decide() faengt das
     * schon vorher ab (MA #617 hatte gar keinen Namen), der Guard darf aber
     * nicht seinerseits true liefern.
     */
    public function test_namenloser_mitarbeiter_ist_nicht_plausibel(): void
    {
        $this->assertFalse(PersonNameMatch::plausible('', '', 'Daniel Roesberg'));
    }

    /**
     * Sehr kurze Tokens duerfen nicht matchen — sonst verbindet ein "de",
     * "al" oder "van" beliebige Menschen miteinander.
     */
    public function test_kurze_namenspartikel_stiften_keinen_treffer(): void
    {
        $this->assertFalse(PersonNameMatch::plausible('Marcel', 'de Blois', 'Ali de Vries'));
    }
}
