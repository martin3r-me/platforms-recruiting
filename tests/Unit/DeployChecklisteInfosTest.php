<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Schlussreview I-3: die Deploy-Checkliste von feat/ma-konto muss die
 * Infos-Migration fuehren (Tabelle 4.3, --pretend, Zahl, migrate:status-grep)
 * und darf keinen eigenen Zweig feat/portal-infos mehr verlangen.
 */
final class DeployChecklisteInfosTest extends TestCase
{
    private const MIGRATION = '2026_10_16_000001_add_info_fields_to_rec_documents';

    private function liste(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/meingedeck/deploy-checkliste-ma-konto.md');
    }

    public function test_tabelle_4_3_fuehrt_alle_migrationen_und_die_zahl_stimmt(): void
    {
        $liste = $this->liste();
        $this->assertSame(1, preg_match('~### 4\.3 .*?(?=\n### 4\.4)~s', $liste, $abschnitt));
        preg_match_all('~^\| `(\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+)` \|~m', $abschnitt[0], $m);
        $this->assertContains(self::MIGRATION, $m[1]);
        foreach ($m[1] as $name) {
            $this->assertFileExists(dirname(__DIR__, 2) . "/database/migrations/{$name}.php");
        }
        $zahl = count($m[1]);
        $this->assertSame(19, $zahl);
        $this->assertStringContainsString("alle {$zahl} „Ran\"", $abschnitt[0]);
        $this->assertStringContainsString("Keine der {$zahl} ist", $abschnitt[0]);
        $this->assertStringContainsString("`migrate`: {$zahl} Migrationen des Zweigs", $liste);

        $this->assertSame(1, preg_match("~migrate:status \| grep -E '([^']+)'~", $abschnitt[0], $grep));
        foreach ($m[1] as $name) {
            $this->assertMatchesRegularExpression('~' . $grep[1] . '~', $name, "migrate:status-grep trifft {$name} nicht");
        }
    }

    public function test_pretend_nennt_die_infos_migration_und_kein_eigener_zweig(): void
    {
        $liste = $this->liste();
        $this->assertMatchesRegularExpression('~migrate --pretend[^\n]*`2026_10_16_000001`~', $liste);
        $this->assertStringNotContainsString('feat/portal-infos', $liste);
    }
}
