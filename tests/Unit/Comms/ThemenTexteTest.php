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

    public function test_filialknopf_wird_auf_20_zeichen_gekuerzt(): void
    {
        $this->assertSame('Dispo Mönchengladba…', ThemenTexte::filialKnopf('Dispo Mönchengladbach'));
        $this->assertSame('Dispo Köln', ThemenTexte::filialKnopf('Dispo Köln'));
    }
}
