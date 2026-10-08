<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentSpeicher;

/**
 * Gemeinsamer Unterbau der Dokument-Integrationstests: Container, Capsule,
 * ALLE Modul-Migrationen per glob (Muster DispoImportErinnerungsStempelTest —
 * die Welt kommt aus den Migrationen, nicht aus einem handgebauten Schema),
 * ein echter lokaler Speicher im Temp-Ordner.
 */
trait DokumenteHarness
{
    protected string $root;
    protected DokumentSpeicher $speicher;

    protected function harnessAufsetzen(): void
    {
        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $container->instance('log', new class { public function __call($m, $a) {} });

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // Migrationen auf fremde Tabellen koennen hier nicht laufen; welche, haelt MassenzuweisungGeschlosseneWeltTest fest.
            }
        }

        $this->root = sys_get_temp_dir() . '/rec-dok-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $this->speicher = new DokumentSpeicher(new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]), 'test-local');
    }

    protected function harnessAbbauen(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'log', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
    }

    protected function anstellung(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => 3, 'first_name' => 'Anna', 'last_name' => 'Test', 'is_active' => true,
            'portal_v2_since' => '2026-10-01 00:00:00',
        ], $set));
    }
}
