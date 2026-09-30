<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NummernSchwaerzung;

/**
 * Der Schwaerzer, durch den jede fremde Ausnahmemeldung geht, bevor sie ins
 * Log kommt (Schlusspruefung B4).
 *
 * Geprueft wird an den zwei Texten, die es wirklich gibt — nicht an
 * erfundenen: der Ausnahme von PersonLinker::setzeNummer() (sie setzt die
 * normalisierte Nummer woertlich ein) und der Meldung einer
 * QueryException, die die SQL samt eingesetzter Werte traegt (dieses
 * Projekt hatte den Fall live: SQLSTATE 22001 bei zu langem Feldwert).
 *
 * Die Gegenprobe ist genauso wichtig wie die Probe: ein Schwaerzer, der
 * ALLES schwaerzt, ist gruen und macht das Log unbrauchbar — Personen- und
 * Team-Kennungen muessen stehen bleiben, sonst laesst sich ein Fall nicht
 * mehr wiederfinden.
 */
final class NummernSchwaerzungTest extends TestCase
{
    public function test_eine_volle_rufnummer_wird_auf_vier_stellen_gekuerzt(): void
    {
        $gekuerzt = NummernSchwaerzung::anwenden(
            'Die Nummer +4915112345678 gehoert im Team 3 bereits Person 42 — eine Nummer darf nur an EINEM Konto haengen.',
        );

        $this->assertStringNotContainsString('4915112345678', $gekuerzt, 'die volle Nummer steht noch im Text');
        $this->assertStringContainsString('...5678', $gekuerzt, 'die letzten vier Stellen muessen bleiben');
    }

    public function test_kurze_kennungen_bleiben_stehen(): void
    {
        $text = 'Die Nummer +4915112345678 gehoert im Team 3 bereits Person 42 — eine Nummer darf nur an EINEM Konto haengen.';

        $gekuerzt = NummernSchwaerzung::anwenden($text);

        $this->assertStringContainsString('Team 3', $gekuerzt, 'die Team-Kennung gehoert ins Log');
        $this->assertStringContainsString('Person 42', $gekuerzt, 'die Personen-Kennung gehoert ins Log');
    }

    /**
     * Der Fall, den dieses Projekt schon live hatte: die Meldung einer
     * QueryException traegt die SQL mit den eingesetzten Werten.
     */
    public function test_eine_nummer_in_einer_sql_meldung_wird_ebenfalls_gekuerzt(): void
    {
        $gekuerzt = NummernSchwaerzung::anwenden(
            'SQLSTATE[22001]: String data, right truncated (Connection: mysql, '
            .'SQL: update `rec_employees` set `phone` = +4915198765432 where `id` = 17)',
        );

        $this->assertStringNotContainsString('4915198765432', $gekuerzt);
        $this->assertStringContainsString('...5432', $gekuerzt);
        $this->assertStringContainsString('`id` = 17', $gekuerzt, 'die Anstellungs-Kennung gehoert ins Log');
    }

    /**
     * SECHS Stellen bleiben, SIEBEN werden gekuerzt — die Grenze von beiden
     * Seiten. Nur eine der beiden Proben liesse eine Grenze von drei oder von
     * zwanzig durchgehen.
     */
    public function test_die_grenze_liegt_bei_sieben_stellen(): void
    {
        $this->assertSame(
            'Personalnummer 123456 ist unbekannt.',
            NummernSchwaerzung::anwenden('Personalnummer 123456 ist unbekannt.'),
            'sechs Stellen sind keine Rufnummer und bleiben',
        );

        $this->assertSame(
            'Personalnummer ...4567 ist unbekannt.',
            NummernSchwaerzung::anwenden('Personalnummer 1234567 ist unbekannt.'),
            'sieben Stellen werden gekuerzt',
        );
    }

    /** Mehrere Nummern in einem Text — nicht nur die erste. */
    public function test_jede_nummer_im_text_wird_gekuerzt(): void
    {
        $gekuerzt = NummernSchwaerzung::anwenden('von +4915111111111 auf 015122222222 gewechselt');

        $this->assertStringNotContainsString('4915111111111', $gekuerzt);
        $this->assertStringNotContainsString('015122222222', $gekuerzt);
        $this->assertSame('von ...1111 auf ...2222 gewechselt', $gekuerzt);
    }

    /** Ein Text ohne Nummer kommt unveraendert zurueck — und nicht leer. */
    public function test_ein_text_ohne_nummer_bleibt_wie_er_ist(): void
    {
        $text = 'Person 42 ist gesperrt — kein Konto-Vorgang moeglich.';

        $this->assertSame($text, NummernSchwaerzung::anwenden($text));
    }
}
