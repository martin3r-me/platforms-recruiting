<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Die Portal-Vorlagen stehen in den Team-Einstellungen
 * (Einstellungen → Kommunikation → Mitarbeiterportal), NICHT mehr in der
 * Config. Dieser Waechter haelt fest, dass die alten Bloecke weg bleiben —
 * sonst gaebe es wieder zwei Wahrheiten, und ein .env-Eintrag saehe aus, als
 * wirke er.
 *
 * Gelesen wird die AUSGELIEFERTE Datei, nicht eine Test-Konfiguration.
 */
class VorlagenConfigWaechterTest extends TestCase
{
    private const DATEI = __DIR__ . '/../../config/recruiting.php';

    public function test_die_alten_vorlagen_bloecke_sind_weg(): void
    {
        $config = new ConfigRepository(['recruiting' => $this->ladeDatei()]);

        $this->assertNull($config->get('recruiting.konto.code_vorlagen'));
        $this->assertNull($config->get('recruiting.konto.hinweis_vorlage'));
        $this->assertNull($config->get('recruiting.aufgaben'));

        // Was bleibt (Spec §6).
        $this->assertIsArray($config->get('recruiting.konto.code_vorlagen_alt'));
        $this->assertFalse($config->get('recruiting.konto.weg4_aktiv'));
        $this->assertTrue($config->has('recruiting.konto.pepper'));
    }

    public function test_die_datei_nennt_keine_vorlagen_env_schluessel(): void
    {
        $text = (string) file_get_contents(self::DATEI);

        $this->assertStringNotContainsString('RECRUITING_KONTO_VORLAGE_', $text);
        $this->assertStringNotContainsString('RECRUITING_AUFGABEN_VORLAGE_', $text);
    }

    /** Die Datei ruft config('app.key') auf — dafuer kurz ein Repository binden. */
    private function ladeDatei(): array
    {
        $container = Container::getInstance();
        $vorher = $container->bound('config') ? $container->make('config') : null;
        $container->instance('config', new ConfigRepository(['app' => ['key' => 'test-key']]));

        try {
            return require self::DATEI;
        } finally {
            if ($vorher !== null) {
                $container->instance('config', $vorher);
            } else {
                $container->forgetInstance('config');
            }
        }
    }
}
