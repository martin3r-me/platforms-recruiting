<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Arbeitserlaubnis;

/**
 * Die harte Sperre: wer darf nicht arbeiten? Geprueft wird die Grenze in
 * beide Richtungen — welcher Nachweis sperrt (nur die rechtliche Grundlage)
 * und welcher Zustand sperrt (nur fehlend und abgelaufen).
 *
 * Die Statuswerte stehen hier bewusst ausgeschrieben und nicht als
 * ProofChecklist-Konstante: sonst laesen Code und Test dieselbe Konstante,
 * und eine Umbenennung des Wertes bliebe unbemerkt.
 */
final class ArbeitserlaubnisTest extends TestCase
{
    public function test_ein_abgelaufener_aufenthaltstitel_sperrt(): void
    {
        $this->assertTrue(Arbeitserlaubnis::istGesperrt([
            ['code' => 'aufenthaltstitel', 'status' => 'abgelaufen'],
            ['code' => 'ausweis',          'status' => 'ok'],
        ]));
    }

    public function test_eine_fehlende_arbeitsgenehmigung_sperrt(): void
    {
        $this->assertTrue(Arbeitserlaubnis::istGesperrt([
            ['code' => 'arbeitsgenehmigung', 'status' => 'fehlt'],
        ]));
    }

    public function test_ein_fehlender_ausweis_sperrt_nicht(): void
    {
        // Folie 15: arbeiten trotz unvollstaendiger Akte. Nur die rechtliche
        // Grundlage sperrt, nicht jedes fehlende Papier.
        $this->assertFalse(Arbeitserlaubnis::istGesperrt([
            ['code' => 'ausweis', 'status' => 'fehlt'],
        ]));
    }

    public function test_ein_bald_ablaufender_titel_sperrt_nicht(): void
    {
        // Sonst sperrt die Vorlaufzeit Menschen, die alles richtig gemacht haben.
        $this->assertFalse(Arbeitserlaubnis::istGesperrt([
            ['code' => 'aufenthaltstitel', 'status' => 'laeuft_ab'],
        ]));
    }

    public function test_ein_fehlender_nationalpass_sperrt_nicht(): void
    {
        // Folie 14 nennt Aufenthaltstitel und Arbeitserlaubnis, nicht jedes
        // Papier der Gruppe nicht_eu.
        $this->assertFalse(Arbeitserlaubnis::istGesperrt([
            ['code' => 'nationalpass', 'status' => 'fehlt'],
        ]));
    }

    /**
     * Zusatz zum Brief, nachgemessen: ohne diesen Fall ueberlebt die Mutation,
     * die ProofChecklist::OK in die sperrenden Zustaende aufnimmt — sie wuerde
     * jeden Menschen mit gueltigem Titel sperren, und die Suite bliebe gruen.
     * Kein Testfall des Briefs traegt einen K.-o.-Code im Zustand ok.
     */
    public function test_ein_gueltiger_aufenthaltstitel_sperrt_nicht(): void
    {
        $this->assertFalse(Arbeitserlaubnis::istGesperrt([
            ['code' => 'aufenthaltstitel',   'status' => 'ok'],
            ['code' => 'arbeitsgenehmigung', 'status' => 'ok'],
        ]));
    }

    public function test_die_gruende_nennen_die_betroffenen_codes(): void
    {
        $gruende = Arbeitserlaubnis::gruende([
            ['code' => 'aufenthaltstitel',   'status' => 'abgelaufen'],
            ['code' => 'arbeitsgenehmigung', 'status' => 'fehlt'],
            ['code' => 'ausweis',            'status' => 'fehlt'],
        ]);

        $this->assertSame(['aufenthaltstitel', 'arbeitsgenehmigung'], $gruende);
    }
}
