<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Comms\ThemenTexte;

final class ThemenTexteTest extends TestCase
{
    public function test_drei_knoepfe_mit_festen_ids_und_titel_hoechstens_20_zeichen(): void
    {
        $k = ThemenTexte::knoepfe();
        $this->assertSame(['topic_dispo', 'topic_lohn', 'topic_hr'], array_column($k, 'id'));
        $this->assertSame(['Disposition', 'Lohn & Gehalt', 'HR allgemein'], array_column($k, 'title'));
        foreach ($k as $knopf) {
            $this->assertLessThanOrEqual(ThemenTexte::MAX_BUTTON, mb_strlen($knopf['title']));
        }
    }

    public function test_frage_in_du_und_sie_form_und_mit_abwesenheitsvorspann(): void
    {
        $this->assertStringContainsString('Worum geht es', ThemenTexte::frage(true, null, null));
        $this->assertStringContainsString('du', ThemenTexte::frage(true, null, null));
        $this->assertStringContainsString('Sie', ThemenTexte::frage(false, null, null));
        $mit = ThemenTexte::frage(true, null, 'Wir sind bis 20.10. nicht erreichbar.');
        $this->assertStringStartsWith('Wir sind bis 20.10. nicht erreichbar.', $mit);
        $this->assertStringContainsString('Worum geht es', $mit);
    }

    public function test_team_text_ueberschreibt_die_vorgabe(): void
    {
        $this->assertSame('Hallo! Was brauchst du?', ThemenTexte::frage(true, 'Hallo! Was brauchst du?', null));
    }

    public function test_lohn_offen_nennt_die_punkte(): void
    {
        $t = ThemenTexte::lohnOffen(true, null, ['Ausweis', 'Bankverbindung']);
        $this->assertStringContainsString('Ausweis', $t);
        $this->assertStringContainsString('Bankverbindung', $t);
        $this->assertStringContainsString('Abrechnung', $t);
    }

    public function test_filialknopf_laesst_das_praefix_dispo_weg(): void
    {
        // 26.10.2026: „Dispo Düsseldorf & …“ — die Frage sagt schon „Disposition“.
        $this->assertSame('Mönchengladbach', ThemenTexte::filialKnopf('Dispo Mönchengladbach'));
        $this->assertSame('Düsseldorf & Neuss', ThemenTexte::filialKnopf('Dispo Düsseldorf & Neuss'));
        $this->assertSame('Köln', ThemenTexte::filialKnopf('Disposition Köln'));
        $this->assertSame('Köln', ThemenTexte::filialKnopf('Köln'));
        $this->assertSame('Dispo', ThemenTexte::filialKnopf('Dispo'));
    }

    public function test_filialknopf_wird_auf_20_zeichen_gekuerzt(): void
    {
        $this->assertSame(str_repeat('a', 19) . '…', ThemenTexte::filialKnopf('Dispo ' . str_repeat('a', 25)));
    }

    public function test_listen_titel_der_filiale_ohne_praefix(): void
    {
        $this->assertSame('Düsseldorf & Neuss', ThemenTexte::filialTitel('Dispo Düsseldorf & Neuss'));
    }

    public function test_listen_titel_wird_auf_24_zeichen_gekuerzt(): void
    {
        $this->assertSame('Köln', ThemenTexte::listenTitel('Köln'));
        $this->assertSame(str_repeat('a', 24), ThemenTexte::listenTitel(str_repeat('a', 24)));
        $gekuerzt = ThemenTexte::listenTitel(str_repeat('ä', 30));
        $this->assertSame(str_repeat('ä', 23) . '…', $gekuerzt);
        $this->assertSame(ThemenTexte::MAX_LIST, mb_strlen($gekuerzt));
    }
}
