<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tests\Support\TestSchema;

/**
 * §B8: ein saving-Hook, zwei Invarianten. Zwoelf "keiner"-Zeilen der
 * Guard-Landkarte haengen an der Praefix-Zusicherung — deshalb Pflichttest.
 *
 * Isolation, zwei getrennte Probleme, zwei getrennte Fixe — beide erst
 * durch den VOLLEN Suite-Lauf sichtbar geworden, nicht durch den
 * gefilterten Lauf dieser Klasse allein:
 *
 * 1. Zeilen-Leichen ZWISCHEN Testmethoden dieser Klasse. setUpBeforeClass()
 *    laeuft nur einmal pro Klasse, alle Testmethoden teilen sich danach
 *    dieselbe Verbindung/Tabelle. testScopesTrennenDieTypen() zaehlt exakt
 *    1 Vertrag und 1 Zertifikat — nur deterministisch, wenn die Tabelle zu
 *    Beginn JEDER Methode leer ist. Fix: Truncate in setUp().
 *
 * 2. Eloquents Model-Boot-Cache ist PROZESSWEIT statisch (Model::$booted),
 *    nicht pro Testklasse. ContractPdfRegressionTest laeuft alphabetisch
 *    VOR dieser Klasse und instanziiert per `new RecContractTemplate(...)`
 *    (ohne eigene Capsule/Dispatcher) — das boot(t) die Modelklasse bereits
 *    EINMAL fuer den ganzen PHPUnit-Prozess, zu einem Zeitpunkt, an dem gar
 *    kein Event-Dispatcher gesetzt ist. static::creating()/static::saving()
 *    registrieren ihre Callbacks dabei auf GAR KEINEM Dispatcher (die
 *    Registrierung ist ein No-Op ohne Dispatcher) und werden danach NIE
 *    MEHR registriert, weil booted() dank des statischen Caches kein
 *    zweites Mal laeuft — auch nicht in dieser Klasse mit ihrem eigenen,
 *    frischen Dispatcher. Beobachtetes Symptom im vollen Lauf: die
 *    uuid-Generierung aus dem BESTEHENDEN creating-Hook feuert nicht mehr,
 *    INSERT liefert "NOT NULL constraint failed: rec_contract_templates.uuid".
 *
 *    Die eigentliche Reparatur sitzt beim VERURSACHER:
 *    ContractPdfRegressionTest::tearDownAfterClass() raeumt mit
 *    Model::clearBootedModels() hinter sich auf, weil es die Klasse ist, die
 *    Modelle ohne Dispatcher bootet. Der Model::clearBootedModels()-Aufruf
 *    HIER in setUpBeforeClass() ist bewusst zusaetzliche, defensive
 *    Absicherung, keine Reparatur: diese Klasse soll nicht davon abhaengen,
 *    dass jede andere Testklasse im Prozess sich brav verhaelt — weder bei
 *    einer kuenftigen Klasse zwischen Verursacher und hier, noch bei
 *    --order-by=random (phpunit.xml setzt keine executionOrder).
 */
class ContractTemplateTypeInvariantsTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Defensive Absicherung, nicht die Reparatur (siehe Klassen-Docblock
        // Punkt 2 — die eigentliche Reparatur sitzt in
        // ContractPdfRegressionTest::tearDownAfterClass()). Erzwingt ein
        // Re-Boot aller Model-Klassen gegen DIESEN Dispatcher, falls eine
        // frueher laufende Testklasse dieselbe Model-Klasse bereits ohne
        // Dispatcher gebootet und sich NICHT selbst aufgeraeumt hat.
        Model::clearBootedModels();

        TestSchema::contractTemplates($capsule->schema());
    }

    protected function setUp(): void
    {
        Capsule::table('rec_contract_templates')->delete();
    }

    private function make(array $attrs): RecContractTemplate
    {
        return RecContractTemplate::create(array_merge([
            'name' => 'Test',
            'team_id' => self::TEAM,
        ], $attrs));
    }

    public function testBestandsvorlageBleibtVertragMitSignatur(): void
    {
        $t = $this->make(['code' => 'AV-010', 'requires_signature' => true]);

        $this->assertSame('contract', $t->type);
        $this->assertTrue($t->requires_signature);
    }

    public function testZertifikatErzwingtSignaturFalse(): void
    {
        $t = $this->make([
            'code' => 'ZERT-BASIS',
            'type' => 'certificate',
            'requires_signature' => true,
        ]);

        $this->assertFalse($t->requires_signature);
    }

    public function testZertifikatOhnePraefixWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['code' => 'AV-ZERT', 'type' => 'certificate']);
    }

    public function testZertifikatOhneCodeWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['code' => null, 'type' => 'certificate']);
    }

    public function testNachtraeglicherTypwechselGreiftEbenfalls(): void
    {
        $t = $this->make(['code' => 'ZERT-UMBAU', 'requires_signature' => true]);
        $this->assertTrue($t->requires_signature);

        $t->type = 'certificate';
        $t->save();

        $this->assertFalse($t->fresh()->requires_signature);
    }

    public function testScopesTrennenDieTypen(): void
    {
        $this->make(['code' => 'AV-060']);
        $this->make(['code' => 'ZERT-SERVICE', 'type' => 'certificate']);

        $this->assertSame(1, RecContractTemplate::query()->contracts()->count());
        $this->assertSame(1, RecContractTemplate::query()->certificates()->count());
    }

    /**
     * Belegt den Unterschied zwischen $attributes-Default und einem
     * creating-Hook: eine ungespeicherte Instanz (kein save(), keine DB,
     * kein Hook feuert) muss 'type' trotzdem als 'contract' lesen — genau
     * das Verhalten, das tests/Integration/ContractPdfRegressionTest.php
     * an Zeile 161 und 182 mit `new RecContractTemplate([...])` bereits
     * nutzt (dort bislang nur fuer 'code').
     */
    public function testUngespeicherteInstanzHatTypeDefaultContract(): void
    {
        $t = new RecContractTemplate(['code' => 'AV-010']);

        $this->assertSame('contract', $t->type);
    }
}
