# Waeschepakete je Taetigkeit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auf der persoenlichen Einsatz-Seite steht die Kleidung, die fuer die
Taetigkeit dieses Menschen gilt — statt des einen ZAS-Textes, den heute alle
Mitwirkenden einer Veranstaltung gemeinsam lesen.

**Architecture:** Ein flacher Paket-Katalog (Name + Kleidungstext). Eine
Zuordnungstabelle je Veranstaltung mit optionaler Taetigkeit. Ein rein
lesender Resolver bestimmt je Einbuchung genau ein Paket. Beim
Bestaetigungs-Versand wird das Ergebnis an der Einbuchung festgeschrieben.
Ausgewaehlt wird im bestehenden Fenster „Bestätigungen senden".

**Tech Stack:** Laravel 11 / Livewire 3 / Blade, PHPUnit 11 mit handgebautem
Container + Capsule (kein testbench), SQLite im Speicher.

**Spec:** `docs/superpowers/specs/2026-09-30-waeschepakete-je-taetigkeit-design.md`

## Global Constraints

- **Tests laufen ueber die Host-App:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
  (dieses Modul hat kein eigenes `vendor/`).
- **Keine zufaellige Testreihenfolge.** `--order-by=random` ist in diesem Modul
  defekt (siehe Kommentar in `phpunit.xml`). Immer Default-Reihenfolge.
- **Blades nie mit `php -l` pruefen**, sondern `php tools/blade-check.php <datei>`.
- **In der Pflegemaske und im Sende-Fenster keine `x-ui-input-select` +
  `@entangle`** — bekannter Speicher-Bug, siehe Kopfkommentar von
  `src/Livewire/Dispo/Settings.php`. Schlichte `<select wire:model=...>`.
- **Blade-Fallen:** Direktiven nie an Wortzeichen kleben (`da@if` kompiliert
  nicht), Werte in reinem PHP in einem `@php`-Block vorberechnen.
- **`rec_employee_hr_data.linen_package_items` wird nicht angefasst** — das ist
  „Waeschepaket erhalten" und geht an ZAS zurueck.
- **Kein neuer ZAS-Export, kein neues Meta-Template.**
- **Nur Dateien in `platforms-recruiting` aendern.** Core/CRM/HCM sind tabu.
- Commit-Praefix `feat(recruiting):` bzw. `test(recruiting):`, Nachrichten auf
  Deutsch, Umlaute im Fliesstext transliteriert (`ae/oe/ue`).

---

### Task 1: Paket-Katalog — Tabelle, Model, Test-Basis

**Files:**
- Create: `database/migrations/2026_09_30_000001_create_rec_dispo_dress_packages_table.php`
- Create: `src/Models/RecDispoDressPackage.php`
- Create: `tests/Integration/DressTestCase.php`
- Test: `tests/Integration/DispoDressPackageTest.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `RecDispoDressPackage` mit `$fillable = ['uuid','team_id','name','items_text','is_active','sort_order']`,
    Cast `is_active => boolean`, `sort_order => integer`, UUIDv7 im `creating`-Hook,
    Scope `scopeActive(Builder $q): Builder`.
  - `DressTestCase` — abstrakte Basis fuer alle Tests dieses Plans, mit
    `setUpBeforeClass()` (Container + Capsule + Migrationen) und den Helfern
    `event(array $attrs = []): RecDispoEvent`,
    `assignment(RecDispoEvent $e, array $attrs = []): RecDispoAssignment`,
    `package(string $name, string $text): RecDispoDressPackage`.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog der Waeschepakete (Kunde 29.09.2026): ein Paket ist ein Name plus
 * der Kleidungstext, den der Mitarbeiter liest.
 *
 * Bewusst eine eigene Tabelle statt eines Core-Lookups: ein Lookup-Wert ist
 * ein Label, hier gehoert der Text untrennbar dazu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_dress_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id');
            // name: nur interne Auswahlhilfe, der Mitarbeiter sieht ihn nie.
            $table->string('name', 120);
            $table->text('items_text');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'is_active'], 'idx_rec_dispo_dress_team_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_dress_packages');
    }
};
```

- [ ] **Step 2: Model schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein Waeschepaket: Name (intern) + Kleidungstext (das, was der Mitarbeiter
 * auf seiner Einsatz-Seite liest).
 *
 * Deaktivierte Pakete verschwinden nur aus der Auswahl — bestehende
 * Zuordnungen und festgeschriebene Einbuchungen zeigen sie weiter.
 */
class RecDispoDressPackage extends Model
{
    protected $table = 'rec_dispo_dress_packages';

    protected $fillable = ['uuid', 'team_id', 'name', 'items_text', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
```

- [ ] **Step 3: Test-Basis schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Gemeinsame Basis der Waeschepaket-Tests: Container + Capsule von Hand,
 * SQLite im Speicher, Migrationen einmal je Klasse.
 *
 * Der Event-Dispatcher wird gesetzt, BEVOR Modelle booten — sonst fallen die
 * creating-Hooks (UUID) fuer alle spaeteren Testklassen im geteilten Prozess
 * still aus (siehe Kommentar in phpunit.xml).
 */
abstract class DressTestCase extends TestCase
{
    protected const TEAM = 1101;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository([]));

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_30_000001_create_rec_dispo_dress_packages_table.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_events', 'rec_dispo_assignments', 'rec_dispo_dress_packages'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    protected function event(array $attrs = []): RecDispoEvent
    {
        static $n = 0;
        $n++;

        return RecDispoEvent::create(array_merge([
            'einsatz_ref' => 'VA-' . $n,
            'name'        => 'Testveranstaltung ' . $n,
            'dresscode'   => null,
        ], $attrs));
    }

    protected function assignment(RecDispoEvent $event, array $attrs = []): RecDispoAssignment
    {
        static $n = 0;
        $n++;

        return RecDispoAssignment::create(array_merge([
            'ds_ref'             => 'DS-' . $n,
            'rec_dispo_event_id' => $event->id,
            'pnr_raw'            => 'RG' . $n,
            'rec_employee_id'    => 900 + $n,
            'datum'              => '2026-10-01',
            'von'                => '08:00',
            'bis'                => '16:00',
            'status_id'          => RecDispoAssignment::STATUS_AUFTRAG,
            'taetigkeit'         => 'Service',
        ], $attrs));
    }

    protected function package(string $name, string $text): RecDispoDressPackage
    {
        return RecDispoDressPackage::create([
            'team_id'    => self::TEAM,
            'name'       => $name,
            'items_text' => $text,
        ]);
    }
}
```

- [ ] **Step 4: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Paket-Katalog: UUID wird gestempelt, deaktivierte Pakete fallen aus der
 * Auswahl, bleiben aber lesbar.
 */
class DispoDressPackageTest extends DressTestCase
{
    public function test_creating_stamps_a_uuid(): void
    {
        $package = $this->package('Standard schwarz-weiss', 'weisses Hemd; schwarze Hose');

        $this->assertNotEmpty($package->uuid);

        // fresh(): is_active und sort_order stehen als DB-Defaults in der
        // Migration, nicht in den uebergebenen Attributen — das frisch
        // erzeugte Model kennt sie noch nicht.
        $reloaded = $package->fresh();
        $this->assertTrue($reloaded->is_active, 'Neue Pakete sind aktiv.');
        $this->assertSame(0, $reloaded->sort_order);
    }

    public function test_active_scope_hides_deactivated_packages(): void
    {
        $this->package('Aktiv', 'weisses Hemd');
        $alt = $this->package('Ausgemustert', 'gruenes Hemd');
        $alt->update(['is_active' => false]);

        $names = RecDispoDressPackage::query()->active()->pluck('name')->all();

        $this->assertSame(['Aktiv'], $names);
        $this->assertNotNull(
            RecDispoDressPackage::find($alt->id),
            'Deaktiviert heisst nicht geloescht — bestehende Zuordnungen brauchen den Datensatz.'
        );
    }
}
```

- [ ] **Step 5: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressPackageTest`
Expected: FAIL — `Class "Platform\Recruiting\Models\RecDispoDressPackage" not found` bzw. fehlende Migration.

- [ ] **Step 6: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressPackageTest`
Expected: PASS (2 Tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_30_000001_create_rec_dispo_dress_packages_table.php \
        src/Models/RecDispoDressPackage.php \
        tests/Integration/DressTestCase.php tests/Integration/DispoDressPackageTest.php
git commit -m "feat(recruiting): Katalog der Waeschepakete — Tabelle, Model, Test-Basis"
```

---

### Task 2: Zuordnung und Festschreibe-Spalten

**Files:**
- Create: `database/migrations/2026_09_30_000002_create_rec_dispo_event_dress_table.php`
- Create: `database/migrations/2026_09_30_000003_add_dress_fields_to_dispo_tables.php`
- Create: `src/Models/RecDispoEventDress.php`
- Modify: `src/Models/RecDispoAssignment.php` (`$fillable`, `$casts`)
- Modify: `src/Models/RecDispoEvent.php` (`$fillable`, `$casts`)
- Modify: `tests/Integration/DressTestCase.php` (Migrationsliste, `setUp`-Leerliste)
- Test: `tests/Integration/DispoEventDressTest.php`

**Interfaces:**
- Consumes: `RecDispoDressPackage` (Task 1)
- Produces:
  - `RecDispoEventDress` mit Konstante `ALL = ''` (Taetigkeits-Sentinel fuer
    „gilt VA-weit"), `$fillable = ['uuid','rec_dispo_event_id','taetigkeit','rec_dispo_dress_package_id']`,
    Relation `package(): BelongsTo`.
  - `rec_dispo_assignments.rec_dispo_dress_package_id` (nullable int),
    `rec_dispo_assignments.dress_frozen_at` (nullable timestamp, Cast datetime).
  - `rec_dispo_events.hinweis` (text), `rec_dispo_events.dresscode_ack` (text),
    `rec_dispo_events.dresscode_ack_at` (timestamp, Cast datetime).

- [ ] **Step 1: Zuordnungs-Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welches Waeschepaket in welcher Veranstaltung gilt — optional eingeschraenkt
 * auf eine Taetigkeit.
 *
 * `taetigkeit` ist NOT NULL mit Leerstring als Sentinel fuer „gilt VA-weit".
 * Mit NULL waere der Unique-Index wirkungslos (MySQL laesst beliebig viele
 * NULLs zu) und die VA-weite Vorgabe mehrfach anlegbar — die Aufloesung waere
 * dann nicht mehr eindeutig.
 *
 * Der Wert wird so gespeichert, wie er in den Einbuchungen steht: Freitext aus
 * ZAS, keine Normalisierung. Schreibvarianten sind verschiedene Zeilen; das
 * faengt spaeter die Gruppen-Stufe ab, nicht Raten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_event_dress', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('rec_dispo_event_id')
                ->constrained('rec_dispo_events')
                ->cascadeOnDelete();
            $table->string('taetigkeit')->default('');
            $table->unsignedBigInteger('rec_dispo_dress_package_id');
            $table->timestamps();

            $table->unique(['rec_dispo_event_id', 'taetigkeit'], 'uniq_rec_dispo_event_dress');
            $table->index('rec_dispo_dress_package_id', 'idx_rec_dispo_event_dress_package');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_event_dress');
    }
};
```

- [ ] **Step 2: Spalten-Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festschreiben und Hinweistext.
 *
 * An der Einbuchung: das beim Bestaetigungs-Versand aufgeloeste Paket. Aendert
 * jemand spaeter den Paketinhalt, darf sich nicht rueckwirkend aendern, was ein
 * Mitarbeiter bestaetigt hat.
 *
 * An der Veranstaltung: unser eigener Hinweistext (ersetzt den ZAS-Kasten,
 * sobald ein Paket greift) und die Kopie des ZAS-Textes, die beim Setzen
 * bestaetigt wurde — daran sieht die Dispo spaeter, ob ZAS seinen Text
 * seitdem geaendert hat.
 *
 * PFLEGEHINWEIS: Diese Spalten gehoeren NICHT in den Attribut-Satz des
 * Dispo-Import-Planners. Sonst raeumt die naechste Lieferung die Arbeit der
 * Dispo ab (ZasDispoWebexportImporter::updateOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_dispo_dress_package_id')->nullable();
            $table->timestamp('dress_frozen_at')->nullable();
        });

        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->text('hinweis')->nullable();
            $table->text('dresscode_ack')->nullable();
            $table->timestamp('dresscode_ack_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['rec_dispo_dress_package_id', 'dress_frozen_at']);
        });

        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn(['hinweis', 'dresscode_ack', 'dresscode_ack_at']);
        });
    }
};
```

- [ ] **Step 3: Model der Zuordnung schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Zuordnung Waeschepaket → Veranstaltung, optional je Taetigkeit.
 *
 * taetigkeit === self::ALL ('') heisst: gilt fuer alle, die keine eigene
 * Zeile haben.
 */
class RecDispoEventDress extends Model
{
    /** Sentinel: gilt VA-weit (siehe Migration — NOT NULL wegen Unique-Index). */
    public const ALL = '';

    protected $table = 'rec_dispo_event_dress';

    protected $fillable = ['uuid', 'rec_dispo_event_id', 'taetigkeit', 'rec_dispo_dress_package_id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(RecDispoDressPackage::class, 'rec_dispo_dress_package_id');
    }
}
```

- [ ] **Step 4: Fillable und Casts ergaenzen**

In `src/Models/RecDispoAssignment.php` im `$fillable`-Array hinter
`'source_meta',` einfuegen:

```php
        'rec_dispo_dress_package_id', 'dress_frozen_at',
```

Und — falls die Klasse noch kein `$casts` hat, direkt hinter `$fillable`
anlegen, sonst ergaenzen:

```php
    protected $casts = [
        'dress_frozen_at' => 'datetime',
    ];
```

**Achtung:** `RecDispoAssignment` castet `datum` bereits an anderer Stelle
(`$assignment->datum->format(...)` in `EmployeeAssignments`). Ein vorhandenes
`$casts`-Array nur ERGAENZEN, nie ersetzen.

In `src/Models/RecDispoEvent.php` im `$fillable` hinter `'source_meta',`:

```php
        'hinweis', 'dresscode_ack', 'dresscode_ack_at',
```

und im bestehenden `$casts`:

```php
        'dresscode_ack_at' => 'datetime',
```

- [ ] **Step 5: Test-Basis um die neuen Migrationen erweitern**

In `tests/Integration/DressTestCase.php` die Migrationsliste hinten ergaenzen:

```php
            'database/migrations/2026_09_30_000002_create_rec_dispo_event_dress_table.php',
            'database/migrations/2026_09_30_000003_add_dress_fields_to_dispo_tables.php',
```

und die Leerliste in `setUp()`:

```php
        foreach (['rec_dispo_events', 'rec_dispo_assignments', 'rec_dispo_dress_packages', 'rec_dispo_event_dress'] as $t) {
```

- [ ] **Step 6: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Zuordnungstabelle: je VA hoechstens eine Zeile pro Taetigkeit, und der
 * Leerstring ist die VA-weite Vorgabe.
 */
class DispoEventDressTest extends DressTestCase
{
    public function test_va_wide_row_uses_the_empty_string_sentinel(): void
    {
        $event = $this->event();
        $package = $this->package('Standard', 'weisses Hemd');

        $row = RecDispoEventDress::create([
            'rec_dispo_event_id'         => $event->id,
            'taetigkeit'                 => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $package->id,
        ]);

        $this->assertSame('', $row->taetigkeit);
        $this->assertNotEmpty($row->uuid);
        $this->assertSame('Standard', $row->package->name);
    }

    public function test_the_same_taetigkeit_cannot_be_assigned_twice_in_one_event(): void
    {
        $event = $this->event();
        $a = $this->package('A', 'weisses Hemd');
        $b = $this->package('B', 'schwarzes Hemd');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $a->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $b->id,
        ]);
    }

    public function test_new_columns_exist_and_default_to_null(): void
    {
        $event = $this->event();
        $assignment = $this->assignment($event);

        $this->assertNull($assignment->rec_dispo_dress_package_id);
        $this->assertNull($assignment->dress_frozen_at);
        $this->assertNull(Capsule::table('rec_dispo_events')->where('id', $event->id)->value('hinweis'));
        $this->assertNull(Capsule::table('rec_dispo_events')->where('id', $event->id)->value('dresscode_ack_at'));
    }
}
```

- [ ] **Step 7: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoEventDressTest`
Expected: FAIL — Tabelle `rec_dispo_event_dress` fehlt.

- [ ] **Step 8: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoEventDressTest`
Expected: PASS (3 Tests)

- [ ] **Step 9: Bestehende Dispo-Tests gegenpruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Integration`
Expected: PASS — die Model-Aenderungen duerfen nichts kippen.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_09_30_000002_create_rec_dispo_event_dress_table.php \
        database/migrations/2026_09_30_000003_add_dress_fields_to_dispo_tables.php \
        src/Models/RecDispoEventDress.php src/Models/RecDispoAssignment.php src/Models/RecDispoEvent.php \
        tests/Integration/DressTestCase.php tests/Integration/DispoEventDressTest.php
git commit -m "feat(recruiting): Zuordnung Paket zu VA und Taetigkeit plus Festschreibe-Spalten"
```

---

### Task 3: Resolver — welches Paket gilt

**Files:**
- Create: `src/Services/Zas/Dispo/DispoDressResolver.php`
- Test: `tests/Integration/DispoDressResolverTest.php`

**Interfaces:**
- Consumes: `RecDispoDressPackage`, `RecDispoEventDress` (Tasks 1–2)
- Produces:
  - `DispoDressResolver::forAssignments(iterable $assignments): array` —
    Map `assignment_id => ?RecDispoDressPackage`.
  - `DispoDressResolver::freeze(array $assignmentIds): int` — schreibt das
    aufgeloeste Paket + `dress_frozen_at` an die Einbuchungen, gibt die Anzahl
    der gestempelten Zeilen zurueck. Bereits gestempelte bleiben unberuehrt.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;

/**
 * Vorrang: festgeschrieben → Taetigkeit → VA-weit → nichts.
 */
class DispoDressResolverTest extends DressTestCase
{
    public function test_taetigkeit_beats_va_wide(): void
    {
        $event = $this->event();
        $standard = $this->package('Standard', 'weisses Hemd');
        $logistik = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $standard->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);

        $service = $this->assignment($event, ['taetigkeit' => 'Service']);
        $logi    = $this->assignment($event, ['taetigkeit' => 'Logistik']);

        $result = (new DispoDressResolver())->forAssignments([$service, $logi]);

        $this->assertSame('Standard', $result[$service->id]->name);
        $this->assertSame('Logistik', $result[$logi->id]->name);
    }

    public function test_without_any_row_there_is_no_package(): void
    {
        $event = $this->event();
        $assignment = $this->assignment($event);

        $result = (new DispoDressResolver())->forAssignments([$assignment]);

        $this->assertNull($result[$assignment->id]);
    }

    public function test_frozen_package_wins_over_later_changes(): void
    {
        $event = $this->event();
        $alt = $this->package('Alt', 'altes Hemd');
        $neu = $this->package('Neu', 'neues Hemd');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $alt->id,
        ]);
        $assignment = $this->assignment($event);

        $this->assertSame(1, (new DispoDressResolver())->freeze([$assignment->id]));

        // Die Dispo haengt jetzt ein anderes Paket an die VA.
        RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)
            ->update(['rec_dispo_dress_package_id' => $neu->id]);

        $result = (new DispoDressResolver())->forAssignments([$assignment->fresh()]);
        $this->assertSame('Alt', $result[$assignment->id]->name, 'Bestaetigt bleibt bestaetigt.');
    }

    public function test_freeze_skips_assignments_without_a_package_and_does_not_restamp(): void
    {
        $event = $this->event();
        $ohne = $this->assignment($event);

        $this->assertSame(0, (new DispoDressResolver())->freeze([$ohne->id]));

        $paket = $this->package('Standard', 'weisses Hemd');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);

        $this->assertSame(1, (new DispoDressResolver())->freeze([$ohne->id]));
        $stamp = RecDispoAssignment::find($ohne->id)->dress_frozen_at;
        $this->assertNotNull($stamp);

        $this->assertSame(0, (new DispoDressResolver())->freeze([$ohne->id]), 'Zweiter Lauf stempelt nicht neu.');
    }

    public function test_deactivated_package_still_resolves(): void
    {
        $event = $this->event();
        $paket = $this->package('Ausgemustert', 'gruenes Hemd');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $paket->update(['is_active' => false]);
        $assignment = $this->assignment($event);

        $result = (new DispoDressResolver())->forAssignments([$assignment]);

        $this->assertSame('Ausgemustert', $result[$assignment->id]->name,
            'Deaktivieren nimmt nur aus der Auswahl, es zieht nichts zurueck.');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressResolverTest`
Expected: FAIL — `Class "…\DispoDressResolver" not found`

- [ ] **Step 3: Resolver schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Bestimmt je Einbuchung genau ein Waeschepaket.
 *
 * Vorrang, das erste Ergebnis gewinnt:
 *   1. an der Einbuchung festgeschrieben (Versandzeitpunkt)
 *   2. Zeile der Veranstaltung mit genau dieser Taetigkeit
 *   3. Zeile der Veranstaltung mit dem VA-weiten Sentinel ('')
 *   4. keins
 *
 * (Stufe 2 des Vorhabens schiebt zwischen 3 und 4 den Gruppen-Default.)
 *
 * forAssignments() ist rein lesend — die Einsatz-Seite darf es gefahrlos
 * aufrufen. Geschrieben wird ausschliesslich in freeze().
 */
class DispoDressResolver
{
    /**
     * @param  iterable<RecDispoAssignment> $assignments
     * @return array<int, ?RecDispoDressPackage> Map assignment_id => Paket|null
     */
    public function forAssignments(iterable $assignments): array
    {
        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[] = $assignment;
        }
        if ($rows === []) {
            return [];
        }

        $eventIds  = array_values(array_unique(array_map(fn ($a) => (int) $a->rec_dispo_event_id, $rows)));
        $frozenIds = array_values(array_filter(array_map(fn ($a) => $a->rec_dispo_dress_package_id !== null
            ? (int) $a->rec_dispo_dress_package_id
            : null, $rows)));

        // Zuordnungen der betroffenen VAs: [event_id][taetigkeit] => package_id
        $map = [];
        foreach (RecDispoEventDress::query()->whereIn('rec_dispo_event_id', $eventIds)->get() as $row) {
            $map[(int) $row->rec_dispo_event_id][(string) $row->taetigkeit] = (int) $row->rec_dispo_dress_package_id;
        }

        $wanted = $frozenIds;
        foreach ($map as $byTaetigkeit) {
            foreach ($byTaetigkeit as $packageId) {
                $wanted[] = $packageId;
            }
        }

        // Bewusst OHNE active()-Filter: ein ausgemustertes Paket, das an einer
        // VA haengt oder festgeschrieben wurde, muss weiter angezeigt werden.
        $packages = $wanted === []
            ? collect()
            : RecDispoDressPackage::query()->whereIn('id', array_unique($wanted))->get()->keyBy('id');

        $out = [];
        foreach ($rows as $assignment) {
            $id = (int) $assignment->id;

            if ($assignment->rec_dispo_dress_package_id !== null) {
                $out[$id] = $packages[(int) $assignment->rec_dispo_dress_package_id] ?? null;
                continue;
            }

            $byTaetigkeit = $map[(int) $assignment->rec_dispo_event_id] ?? [];
            $taetigkeit   = trim((string) $assignment->taetigkeit);
            $packageId    = $byTaetigkeit[$taetigkeit]
                ?? $byTaetigkeit[RecDispoEventDress::ALL]
                ?? null;

            $out[$id] = $packageId !== null ? ($packages[$packageId] ?? null) : null;
        }

        return $out;
    }

    /**
     * Schreibt das aufgeloeste Paket an die Einbuchungen fest (Versandzeitpunkt).
     *
     * Bereits gestempelte Einbuchungen bleiben unberuehrt: was jemand bestaetigt
     * hat, darf sich durch einen zweiten Versand nicht aendern.
     *
     * @param  list<int> $assignmentIds
     * @return int Anzahl gestempelter Zeilen
     */
    public function freeze(array $assignmentIds): int
    {
        if ($assignmentIds === []) {
            return 0;
        }

        $assignments = RecDispoAssignment::query()
            ->whereIn('id', $assignmentIds)
            ->whereNull('dress_frozen_at')
            ->get();

        if ($assignments->isEmpty()) {
            return 0;
        }

        $resolved = $this->forAssignments($assignments);

        // Nach Paket gruppieren, damit aus n Einbuchungen wenige Updates werden.
        $byPackage = [];
        foreach ($resolved as $assignmentId => $package) {
            if ($package === null) {
                continue;
            }
            $byPackage[(int) $package->id][] = $assignmentId;
        }

        $stamped = 0;
        $now = now();
        foreach ($byPackage as $packageId => $ids) {
            $stamped += RecDispoAssignment::query()
                ->whereIn('id', $ids)
                ->update(['rec_dispo_dress_package_id' => $packageId, 'dress_frozen_at' => $now]);
        }

        return $stamped;
    }
}
```

- [ ] **Step 4: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressResolverTest`
Expected: PASS (5 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/DispoDressResolver.php tests/Integration/DispoDressResolverTest.php
git commit -m "feat(recruiting): Resolver bestimmt je Einbuchung genau ein Waeschepaket"
```

---

### Task 4: Anzeige-Logik der Einsatz-Seite (rein, ohne DB)

**Files:**
- Create: `src/Support/DressPanels.php`
- Test: `tests/Unit/DressPanelsTest.php`

**Interfaces:**
- Consumes: nichts (reine Funktion)
- Produces:
  - `DressPanels::build(array $days, ?string $zasText, ?string $hinweis): array`
    mit Rueckgabe
    `array{group: ?array{heading:string,text:string}, perDay: array<int|string, array{heading:string,text:string}>, hinweis: ?string}`.
    `$days` ist eine Map `tagKey => ?string` (Paket-Text des Tages, `null` =
    kein Paket).

**Regeln (aus der Spec):**
- Wirksamer Text eines Tages = Paket-Text, sonst der ZAS-Text.
- Ueberschrift „Deine Kleidung", wenn **alle** Tage ein Paket haben; sonst
  „Kleidung / Infos" (so sieht die Seite heute aus, wenn nichts gesetzt ist).
- Sind alle Tage wirksam gleich → **ein** Kasten im VA-Kopf (`group`), `perDay`
  bleibt leer. Sonst umgekehrt.
- Der Hinweistext wird nur sichtbar, wenn mindestens ein Tag ein Paket hat.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DressPanels;

/**
 * Welcher Kleidungs-Kasten auf der Einsatz-Seite steht — ein gemeinsamer im
 * VA-Kopf oder einer je Tag.
 */
class DressPanelsTest extends TestCase
{
    public function test_without_packages_the_zas_text_stays_as_today(): void
    {
        $r = DressPanels::build([1 => null, 2 => null], 'Bitte schwarze Hose', 'Hinweis');

        $this->assertSame(['heading' => 'Kleidung / Infos', 'text' => 'Bitte schwarze Hose'], $r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertNull($r['hinweis'], 'Ohne Paket bleibt die Seite unveraendert.');
    }

    public function test_one_package_for_all_days_replaces_the_zas_text(): void
    {
        $r = DressPanels::build([1 => 'Hoodie; Sicherheitsschuhe', 2 => 'Hoodie; Sicherheitsschuhe'], 'Bitte schwarze Hose', 'Ausweis mitnehmen');

        $this->assertSame(['heading' => 'Deine Kleidung', 'text' => 'Hoodie; Sicherheitsschuhe'], $r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertSame('Ausweis mitnehmen', $r['hinweis']);
    }

    public function test_different_packages_per_day_move_the_panel_into_the_day_row(): void
    {
        $r = DressPanels::build([1 => 'weisses Hemd', 2 => 'Hoodie'], 'Bitte schwarze Hose', null);

        $this->assertNull($r['group']);
        $this->assertSame([
            1 => ['heading' => 'Deine Kleidung', 'text' => 'weisses Hemd'],
            2 => ['heading' => 'Deine Kleidung', 'text' => 'Hoodie'],
        ], $r['perDay']);
    }

    public function test_a_day_without_package_keeps_the_zas_text(): void
    {
        $r = DressPanels::build([1 => 'Hoodie', 2 => null], 'Bitte schwarze Hose', null);

        $this->assertNull($r['group']);
        $this->assertSame([
            1 => ['heading' => 'Deine Kleidung', 'text' => 'Hoodie'],
            2 => ['heading' => 'Kleidung / Infos', 'text' => 'Bitte schwarze Hose'],
        ], $r['perDay'], 'Ein Tag ohne Paket verliert den ZAS-Text nicht.');
    }

    public function test_empty_texts_produce_no_panel_at_all(): void
    {
        $r = DressPanels::build([1 => null], '   ', '  ');

        $this->assertNull($r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertNull($r['hinweis']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DressPanelsTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\DressPanels" not found`

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet, welcher Kleidungs-Kasten auf der Einsatz-Seite steht.
 *
 * Reine Funktion ohne DB und ohne Blade — die Regel ist die fehleranfaellige
 * Stelle (ein Tag Service, ein Tag Logistik), nicht das Rendern.
 *
 * Ein Tag ohne eigenes Paket faellt bewusst auf den ZAS-Text zurueck: sonst
 * verloere er jede Kleidungsangabe, sobald irgendein anderer Tag ein Paket
 * bekommt.
 */
class DressPanels
{
    public const HEADING_PACKAGE = 'Deine Kleidung';
    public const HEADING_ZAS     = 'Kleidung / Infos';

    /**
     * @param  array<int|string, ?string> $days   tagKey => Paket-Text (null = kein Paket)
     * @return array{group: ?array{heading:string,text:string}, perDay: array<int|string, array{heading:string,text:string}>, hinweis: ?string}
     */
    public static function build(array $days, ?string $zasText, ?string $hinweis): array
    {
        $zas = self::clean($zasText);

        $panels = [];
        $anyPackage = false;
        $allPackages = $days !== [];

        foreach ($days as $key => $packageText) {
            $package = self::clean($packageText);
            if ($package !== null) {
                $anyPackage = true;
            } else {
                $allPackages = false;
            }

            $text = $package ?? $zas;
            if ($text === null) {
                continue;
            }
            $panels[$key] = [
                'heading' => $package !== null ? self::HEADING_PACKAGE : self::HEADING_ZAS,
                'text'    => $text,
            ];
        }

        $result = [
            'group'   => null,
            'perDay'  => [],
            'hinweis' => $anyPackage ? self::clean($hinweis) : null,
        ];

        if ($panels === []) {
            return $result;
        }

        $texts = array_column($panels, 'text');
        if (count(array_unique($texts)) === 1 && count($panels) === count($days)) {
            $result['group'] = [
                'heading' => $allPackages ? self::HEADING_PACKAGE : self::HEADING_ZAS,
                'text'    => $texts[0],
            ];

            return $result;
        }

        $result['perDay'] = $panels;

        return $result;
    }

    private static function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
```

- [ ] **Step 4: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DressPanelsTest`
Expected: PASS (5 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/DressPanels.php tests/Unit/DressPanelsTest.php
git commit -m "feat(recruiting): Regel fuer den Kleidungs-Kasten der Einsatz-Seite"
```

---

### Task 5: Einsatz-Seite zeigt das Paket

**Files:**
- Modify: `src/Livewire/Public/EmployeeAssignments.php:100-175` (`eventGroups()`)
- Modify: `resources/views/livewire/public/employee-assignments.blade.php:275-330`
- Test: `tests/Integration/DispoDressOnAssignmentPageTest.php`

**Interfaces:**
- Consumes: `DispoDressResolver::forAssignments()` (Task 3), `DressPanels::build()` (Task 4)
- Produces: `eventGroups()` liefert je Gruppe zusaetzlich
  `'dress_group' => ?array{heading:string,text:string}`,
  `'dress_hinweis' => ?string` und je Tag `'dress' => ?array{heading:string,text:string}`.
  Der bisherige Schluessel `'kleidung'` entfaellt **nicht**, wird aber nur noch
  vom Panel-Aufbau gelesen.

- [ ] **Step 1: Verdrahtungstest schreiben**

Dieser Test hat bewusst **keine Rot-Phase**: Resolver (Task 3) und Regel
(Task 4) stehen schon, er prueft ihr Zusammenspiel in genau der Reihenfolge,
in der `eventGroups()` sie gleich aufruft. Er ist die Absicherung dagegen,
dass eine spaetere Aenderung an einem der beiden die Seite kippt.

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;
use Platform\Recruiting\Support\DressPanels;

/**
 * Zusammenspiel Resolver + Panel-Regel, so wie eventGroups() es verdrahtet:
 * mit Paket verschwindet der ZAS-Text, ohne Paket bleibt die Seite wie heute.
 */
class DispoDressOnAssignmentPageTest extends DressTestCase
{
    public function test_package_replaces_the_zas_text_for_the_whole_event(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'Ausweis mitnehmen']);
        $paket = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_PACKAGE, 'text' => 'Hoodie; Sicherheitsschuhe'],
            $panels['group']
        );
        $this->assertSame('Ausweis mitnehmen', $panels['hinweis']);
    }

    public function test_service_and_logistics_on_different_days_split_the_panel(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd']);
        $service  = $this->package('Standard', 'weisses Hemd; schwarze Hose');
        $logistik = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $service->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01', 'taetigkeit' => 'Service']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02', 'taetigkeit' => 'Logistik']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertNull($panels['group']);
        $this->assertSame('weisses Hemd; schwarze Hose', $panels['perDay'][$tag1->id]['text']);
        $this->assertSame('Hoodie; Sicherheitsschuhe', $panels['perDay'][$tag2->id]['text']);
    }

    public function test_without_any_package_nothing_changes(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'wird nicht gezeigt']);
        $tag = $this->assignment($event);

        $panels = $this->panelsFor([$tag], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_ZAS, 'text' => 'Bitte folgende Kleidung: weisses Hemd'],
            $panels['group']
        );
        $this->assertNull($panels['hinweis']);
    }

    /** @param list<\Platform\Recruiting\Models\RecDispoAssignment> $assignments */
    private function panelsFor(array $assignments, ?string $zas, ?string $hinweis): array
    {
        $packages = (new DispoDressResolver())->forAssignments($assignments);
        $days = [];
        foreach ($assignments as $a) {
            $days[$a->id] = $packages[$a->id]?->items_text;
        }

        return DressPanels::build($days, $zas, $hinweis);
    }
}
```

- [ ] **Step 2: Test laufen lassen, gruen erwartet**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressOnAssignmentPageTest`
Expected: PASS (3 Tests). Faellt er rot aus, fehlt `hinweis` im `$fillable`
von `RecDispoEvent` (Task 2, Step 4) — dort nachziehen, nicht hier.

- [ ] **Step 3: `eventGroups()` verdrahten**

In `src/Livewire/Public/EmployeeAssignments.php` oben ergaenzen:

```php
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;
use Platform\Recruiting\Support\DressPanels;
```

Direkt nach `$leadsByEvent = $this->teamLeadsByEvent(...)` einfuegen:

```php
        // Waeschepakete je Einbuchung — ein Aufruf fuer alle Tage, nicht je Zeile.
        $dressByAssignment = app(DispoDressResolver::class)->forAssignments($assignments);
```

Im `$groups[$key] ??= [...]`-Block hinter `'kleidung' => $event->dresscode,`
ergaenzen:

```php
                'hinweis'       => $event->hinweis,
                'dress_group'   => null,
                'dress_hinweis' => null,
```

Im Tages-Array hinter `'taetigkeit' => $assignment->taetigkeit,` ergaenzen:

```php
                'assignment_id'  => $assignment->id,
                'dress'          => null,
                'dress_text'     => $dressByAssignment[$assignment->id]?->items_text,
```

Und **nach** der `foreach`-Schleife ueber `$assignments`, bevor `$groups`
zurueckgegeben wird, die Panels berechnen:

```php
        foreach ($groups as $key => $group) {
            $days = [];
            foreach ($group['days'] as $day) {
                $days[$day['assignment_id']] = $day['dress_text'];
            }

            $panels = DressPanels::build($days, $group['kleidung'], $group['hinweis']);

            $groups[$key]['dress_group']   = $panels['group'];
            $groups[$key]['dress_hinweis'] = $panels['hinweis'];
            foreach ($groups[$key]['days'] as $i => $day) {
                $groups[$key]['days'][$i]['dress'] = $panels['perDay'][$day['assignment_id']] ?? null;
            }
        }
```

- [ ] **Step 4: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressOnAssignmentPageTest`
Expected: PASS (3 Tests)

- [ ] **Step 5: Blade anpassen**

In `resources/views/livewire/public/employee-assignments.blade.php`:

Die Zeile, die heute `$hasPanel` berechnet (um Zeile 200), ersetzen durch:

```blade
                $hasPanel = !empty($group['adresse']) || !empty($group['zusatz_ort'])
                    || !empty($group['dress_group']) || !empty($group['dress_hinweis']);
```

Den Kleidungs-Kasten im Panel-Block ersetzen:

```blade
                            @if ($group['dress_group'])
                                <div class="panel"><div class="h">{{ $group['dress_group']['heading'] }}</div><div class="b">{{ $group['dress_group']['text'] }}</div></div>
                            @endif
                            @if ($group['dress_hinweis'])
                                <div class="panel"><div class="h">Hinweise zur Veranstaltung</div><div class="b">{{ $group['dress_hinweis'] }}</div></div>
                            @endif
```

Und in der Tageszeile, direkt hinter dem `@if ($day['individual_note'])`-Block
(innerhalb von `@foreach ($days as $day)`), den Tages-Kasten einfuegen:

```blade
                            @if ($day['dress'])
                                <div class="hint">
                                    <div class="h">{{ $day['dress']['heading'] }} · {{ $labelDate($day['datum']) }}</div>
                                    <div class="b">{{ $day['dress']['text'] }}</div>
                                </div>
                            @endif
```

**Achtung (bekannte Blade-Falle):** Direktiven nie an Wortzeichen kleben und
keine inline-`@php`-Kurzform verwenden — beides kompiliert still falsch.

- [ ] **Step 6: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/public/employee-assignments.blade.php`
Expected: keine Fehlermeldung

- [ ] **Step 7: Gesamte Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS — besonders `DispoPortalConfirmTest` und `DispoIndividualNoteTest`.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Public/EmployeeAssignments.php \
        resources/views/livewire/public/employee-assignments.blade.php \
        tests/Integration/DispoDressOnAssignmentPageTest.php
git commit -m "feat(recruiting): Einsatz-Seite zeigt das Waeschepaket statt des ZAS-Textes"
```

---

### Task 6: Auswahl im Sende-Fenster

**Files:**
- Modify: `src/Livewire/Dispo/Events/Show.php` (Properties, `openSendModal()`, `doSendConfirmations()`)
- Create: `resources/views/livewire/dispo/events/_dress-fields.blade.php`
- Modify: `resources/views/livewire/dispo/events/show.blade.php` (Einbindung im Sende-Fenster)
- Test: `tests/Integration/DispoDressSendFormTest.php`

**Interfaces:**
- Consumes: `RecDispoEventDress`, `RecDispoDressPackage`
- Produces: auf `Show`
  - `public string $dressAll = '';` — Paket-ID als String, `''` = keins
  - `public array $dressByTaetigkeit = [];` — `taetigkeit => Paket-ID als String`
  - `public string $eventHinweis = '';`
  - `public bool $dressAck = false;`
  - `#[Computed] public function dressPackages(): array` — `id => name`, nur aktive
  - `#[Computed] public function eventTaetigkeiten(): array` — `list<string>`
  - `#[Computed] public function dressTexts(): array` — `Paket-ID als String => items_text`,
    Grundlage der Vorschau unter jeder Auswahl
  - `public function copyZasToHinweis(): void` — kopiert den ZAS-Text in das
    Hinweisfeld, ohne vorhandenen Inhalt zu verwerfen
  - `private function loadDressForm(): void`
  - `private function persistDress(RecDispoEvent $event): void`
  - `public static function dressNeedsAck(array $chosen, ?string $zasText, bool $acked): bool`
    (statisch und oeffentlich, damit der Riegel ohne Livewire testbar ist)

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Livewire\Dispo\Events\Show;

/**
 * Riegel im Sende-Fenster: wer ein Paket setzt, waehrend in ZAS noch Text
 * steht, muss ihn einmal gesehen haben. Rund ein Drittel dieser Texte traegt
 * Organisatorisches (Ansprechpartner, "Ausweis mitnehmen") — das darf nicht
 * stillschweigend verschwinden.
 */
class DispoDressSendFormTest extends DressTestCase
{
    public function test_no_package_chosen_means_no_gate(): void
    {
        $this->assertFalse(Show::dressNeedsAck([], 'Bitte folgende Kleidung: weisses Hemd', false));
        $this->assertFalse(Show::dressNeedsAck(['', ''], 'Bitte folgende Kleidung: weisses Hemd', false));
    }

    public function test_package_with_empty_zas_text_means_no_gate(): void
    {
        $this->assertFalse(Show::dressNeedsAck(['7'], '   ', false));
        $this->assertFalse(Show::dressNeedsAck(['7'], null, false));
    }

    public function test_package_with_zas_text_needs_acknowledgement(): void
    {
        $this->assertTrue(Show::dressNeedsAck(['7'], 'Ansprechpartner: Tristan anrufen', false));
        $this->assertFalse(Show::dressNeedsAck(['7'], 'Ansprechpartner: Tristan anrufen', true),
            'Einmal bestaetigt reicht.');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressSendFormTest`
Expected: FAIL — `Call to undefined method …Show::dressNeedsAck()`

- [ ] **Step 3: Riegel-Regel implementieren**

In `src/Livewire/Dispo/Events/Show.php` ergaenzen:

```php
    /**
     * Braucht dieser Versand die Bestaetigung „ZAS-Text gesehen"?
     *
     * Ja, sobald irgendein Paket gesetzt ist UND in ZAS noch Text steht — denn
     * ab dann verschwindet dieser Text von der Einsatz-Seite. Statisch, damit
     * die Regel ohne Livewire-Aufbau testbar bleibt.
     *
     * @param list<string> $chosen Paket-IDs als String, '' = keins
     */
    public static function dressNeedsAck(array $chosen, ?string $zasText, bool $acked): bool
    {
        if ($acked) {
            return false;
        }
        if (trim((string) $zasText) === '') {
            return false;
        }

        foreach ($chosen as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressSendFormTest`
Expected: PASS (3 Tests)

- [ ] **Step 5: Formular-Zustand in `Show` ergaenzen**

Properties zu den uebrigen Sende-Fenster-Properties stellen:

```php
    /** Paket-Auswahl im Sende-Fenster: '' = keins. Strings, weil Selects Strings liefern. */
    public string $dressAll = '';
    /** taetigkeit => Paket-ID als String */
    public array $dressByTaetigkeit = [];
    public string $eventHinweis = '';
    /** Haken „ZAS-Text gesehen" — Gegenstueck zu dressNeedsAck(). */
    public bool $dressAck = false;
```

Dazu die beiden Computed-Listen:

```php
    /** @return array<int,string> aktive Pakete als id => name */
    #[Computed]
    public function dressPackages(): array
    {
        // settingsTeamId() ist die vorhandene Team-Regel dieser Komponente —
        // dieselbe, nach der die Pflegemaske und der Seeder schreiben.
        return \Platform\Recruiting\Models\RecDispoDressPackage::query()
            ->where('team_id', $this->settingsTeamId())
            ->active()
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Paket-Texte fuer die Vorschau unter jeder Auswahl — Schluessel als
     * String, weil die Selects Strings liefern.
     *
     * @return array<string,string>
     */
    #[Computed]
    public function dressTexts(): array
    {
        $out = [];
        $query = \Platform\Recruiting\Models\RecDispoDressPackage::query()
            ->where('team_id', $this->settingsTeamId())
            ->active();
        foreach ($query->get() as $package) {
            $out[(string) $package->id] = (string) $package->items_text;
        }

        return $out;
    }

    /**
     * Uebernimmt den ZAS-Text in unser Hinweisfeld. Haengt an, statt zu
     * ersetzen — ein bereits getippter Hinweis darf nicht verlorengehen.
     */
    public function copyZasToHinweis(): void
    {
        $zas = trim((string) ($this->event->dresscode ?? ''));
        if ($zas === '') {
            return;
        }

        $current = trim($this->eventHinweis);
        $this->eventHinweis = $current === '' ? $zas : ($current . "\n\n" . $zas);
    }

    /**
     * Taetigkeiten, die in DIESER VA vorkommen — aus den Einbuchungen, nicht
     * aus einem Katalog. Freitext aus ZAS, deshalb nur trimmen und sortieren.
     *
     * @return list<string>
     */
    #[Computed]
    public function eventTaetigkeiten(): array
    {
        $values = $this->event->assignments
            ->map(fn ($a) => trim((string) $a->taetigkeit))
            ->filter(fn (string $t) => $t !== '')
            ->unique()
            ->values()
            ->all();
        sort($values);

        return $values;
    }
```

- [ ] **Step 6: Laden und Speichern implementieren**

```php
    private function loadDressForm(): void
    {
        $event = $this->event;
        $rows = \Platform\Recruiting\Models\RecDispoEventDress::query()
            ->where('rec_dispo_event_id', $event->id)
            ->get();

        $this->dressAll = '';
        $this->dressByTaetigkeit = [];
        foreach ($rows as $row) {
            $id = (string) $row->rec_dispo_dress_package_id;
            if ((string) $row->taetigkeit === \Platform\Recruiting\Models\RecDispoEventDress::ALL) {
                $this->dressAll = $id;
                continue;
            }
            $this->dressByTaetigkeit[(string) $row->taetigkeit] = $id;
        }

        foreach ($this->eventTaetigkeiten as $taetigkeit) {
            $this->dressByTaetigkeit[$taetigkeit] ??= '';
        }

        $this->eventHinweis = (string) ($event->hinweis ?? '');
        // Ein bereits bestaetigter Text zaehlt nur, solange ZAS ihn nicht
        // geaendert hat — sonst muss die Dispo erneut hinsehen.
        $this->dressAck = $event->dresscode_ack_at !== null
            && trim((string) $event->dresscode_ack) === trim((string) $event->dresscode);
    }

    private function persistDress(\Platform\Recruiting\Models\RecDispoEvent $event): void
    {
        $wanted = $this->dressByTaetigkeit;
        $wanted[\Platform\Recruiting\Models\RecDispoEventDress::ALL] = $this->dressAll;

        foreach ($wanted as $taetigkeit => $packageId) {
            $key = ['rec_dispo_event_id' => $event->id, 'taetigkeit' => (string) $taetigkeit];

            if (trim((string) $packageId) === '') {
                \Platform\Recruiting\Models\RecDispoEventDress::query()->where($key)->delete();
                continue;
            }

            \Platform\Recruiting\Models\RecDispoEventDress::updateOrCreate(
                $key,
                ['rec_dispo_dress_package_id' => (int) $packageId]
            );
        }
    }
```

In `openSendModal()` vor `$this->showSendModal = true;` ergaenzen:

```php
        $this->loadDressForm();
```

- [ ] **Step 7: Riegel und Speichern in den Versand haengen**

In `doSendConfirmations()` **vor** dem `$event->update([...])` einfuegen:

```php
        $event = RecDispoEvent::findOrFail($this->eventId);

        $chosen = array_values($this->dressByTaetigkeit);
        $chosen[] = $this->dressAll;
        if (self::dressNeedsAck($chosen, $event->dresscode, $this->dressAck)) {
            $this->addError('dressAck', 'Bitte einmal bestätigen, dass der bisherige Kleidungstext aus ZAS gesehen wurde — er verschwindet für die Empfänger.');
            return;
        }
```

**Hinweis:** `$event` wird in der Methode bereits geladen — die vorhandene
Zeile `$event = RecDispoEvent::findOrFail($this->eventId);` nicht doppeln,
sondern den Block dahinter setzen.

Das bestehende `$event->update([...])` um die neuen Felder erweitern:

```php
        $event->update([
            'vorlauf_minuten' => (int) $this->vorlaufMinuten,
            'ansprechpartner' => DispoContactResolver::toStore($this->ansprechpartner, $this->teamLeads),
            'hinweis'         => trim($this->eventHinweis) === '' ? null : trim($this->eventHinweis),
            'dresscode_ack'   => $this->dressAck ? $event->dresscode : $event->dresscode_ack,
            'dresscode_ack_at' => $this->dressAck ? now() : $event->dresscode_ack_at,
        ]);

        $this->persistDress($event);
```

- [ ] **Step 8: Blade-Partial schreiben**

`resources/views/livewire/dispo/events/_dress-fields.blade.php`:

```blade
{{-- Waeschepakete: Auswahl je Taetigkeit, daneben der Text, der dadurch
     fuer die Empfaenger verschwindet. Schlichte Selects mit wire:model —
     x-ui-input-select + @entangle verliert die Auswahl beim Speichern. --}}
@php
    $dressOptions = $this->dressPackages;
    $dressTaetigkeiten = $this->eventTaetigkeiten;
    $zasText = trim((string) ($this->event->dresscode ?? ''));
@endphp
<div class="rounded-lg border border-gray-200 p-3 text-sm space-y-3">
    <div class="font-medium text-gray-700">Kleidung</div>

    @if (count($dressOptions) === 0)
        <p class="text-xs text-gray-500">Noch keine Wäschepakete angelegt (Disposition → Wäschepakete).</p>
    @else
        {{-- Vorschau: unter jeder Auswahl steht der Text, den der Mitarbeiter
             lesen wird. wire:model.live, damit sie der Auswahl sofort folgt. --}}
        @php $dressTexts = $this->dressTexts; @endphp

        <label class="block">
            <span class="mb-1 block text-xs text-gray-600">Alle übrigen</span>
            <select wire:model.live="dressAll" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">— kein Paket —</option>
                @foreach ($dressOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            @php $vorschauAll = $dressTexts[$dressAll] ?? null; @endphp
            @if ($vorschauAll)
                <span class="mt-1 block text-xs text-gray-500">Mitarbeiter liest: {{ $vorschauAll }}</span>
            @endif
        </label>

        @foreach ($dressTaetigkeiten as $taetigkeit)
            @php $vorschauTag = $dressTexts[$dressByTaetigkeit[$taetigkeit] ?? ''] ?? null; @endphp
            <label class="block">
                <span class="mb-1 block text-xs text-gray-600">{{ $taetigkeit }}</span>
                <select wire:model.live="dressByTaetigkeit.{{ $taetigkeit }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">— wie alle übrigen —</option>
                    @foreach ($dressOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @if ($vorschauTag)
                    <span class="mt-1 block text-xs text-gray-500">Mitarbeiter liest: {{ $vorschauTag }}</span>
                @endif
            </label>
        @endforeach

        @if ($zasText !== '')
            <div class="rounded bg-amber-50 p-2">
                <div class="text-xs font-medium text-amber-800">Bisheriger Text aus ZAS — verschwindet für alle mit Paket</div>
                <div class="mt-1 whitespace-pre-line text-xs text-amber-900">{{ $zasText }}</div>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-xs text-amber-900">
                        <input type="checkbox" wire:model.live="dressAck" class="rounded border-gray-300">
                        Gesehen — Wichtiges habe ich in den Hinweis übernommen
                    </label>
                    <button type="button" wire:click="copyZasToHinweis" class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-900 hover:bg-amber-100">
                        Text in den Hinweis übernehmen
                    </button>
                </div>
                @error('dressAck') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="block">
            <span class="mb-1 block text-xs text-gray-600">Hinweise zur Veranstaltung <span class="text-gray-400">(steht auf der Einsatz-Seite unter der Kleidung)</span></span>
            <textarea wire:model="eventHinweis" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        </label>
    @endif
</div>
```

- [ ] **Step 9: Partial einbinden**

In `resources/views/livewire/dispo/events/show.blade.php` direkt hinter dem
Block mit dem Ansprechpartner-Feld einfuegen.

**Achtung, es gibt zwei Einbindungen von `_contact-field`.** Die richtige ist
die INNERHALB von `@if ($showSendModal)` (Bestätigungen senden, aktuell um
Zeile 506) — nicht die zweite weiter unten (aktuell um Zeile 838), die zu
einem anderen Fenster gehoert. Vor dem Einfuegen mit
`grep -n "_contact-field" resources/views/livewire/dispo/events/show.blade.php`
die aktuellen Zeilen pruefen; die Datei bewegt sich.

```blade
                    @include('recruiting::livewire.dispo.events._dress-fields')
```

- [ ] **Step 10: Blades pruefen**

Run: `php tools/blade-check.php resources/views/livewire/dispo/events/_dress-fields.blade.php && php tools/blade-check.php resources/views/livewire/dispo/events/show.blade.php`
Expected: keine Fehlermeldung

- [ ] **Step 11: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add src/Livewire/Dispo/Events/Show.php \
        resources/views/livewire/dispo/events/_dress-fields.blade.php \
        resources/views/livewire/dispo/events/show.blade.php \
        tests/Integration/DispoDressSendFormTest.php
git commit -m "feat(recruiting): Paketauswahl je Taetigkeit im Sende-Fenster, mit Riegel"
```

---

### Task 7: Festschreiben beim Versand

**Files:**
- Modify: `src/Services/Zas/Dispo/DispoConfirmationSender.php` (Konstruktor, Stempel-Stelle)
- Test: `tests/Integration/DispoDressFreezeOnSendTest.php`

**Interfaces:**
- Consumes: `DispoDressResolver::freeze()` (Task 3)
- Produces: keine neue oeffentliche Signatur — der Sender bekommt den Resolver
  als Konstruktor-Default (`private DispoDressResolver $dress = new DispoDressResolver()`),
  damit alle bestehenden Aufrufstellen unveraendert bleiben (gleiches Muster
  wie `ZasInboundEmployeeImporter`).

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;

/**
 * Was beim Versand galt, bleibt an der Einbuchung stehen — auch wenn jemand
 * das Paket spaeter umhaengt. Geprueft wird die Schreiboperation selbst; der
 * Versandweg (Meta) bleibt aussen vor.
 */
class DispoDressFreezeOnSendTest extends DressTestCase
{
    public function test_freeze_stamps_each_day_with_its_own_package(): void
    {
        $event = $this->event();
        $service  = $this->package('Standard', 'weisses Hemd');
        $logistik = $this->package('Logistik', 'Hoodie');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $service->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);
        $tag1 = $this->assignment($event, ['taetigkeit' => 'Service']);
        $tag2 = $this->assignment($event, ['taetigkeit' => 'Logistik']);

        $stamped = (new DispoDressResolver())->freeze([$tag1->id, $tag2->id]);

        $this->assertSame(2, $stamped);
        $this->assertSame($service->id, RecDispoAssignment::find($tag1->id)->rec_dispo_dress_package_id);
        $this->assertSame($logistik->id, RecDispoAssignment::find($tag2->id)->rec_dispo_dress_package_id,
            'Ein Versand, zwei Taetigkeiten, zwei Pakete.');
    }
}
```

- [ ] **Step 2: Test laufen lassen, gruen erwartet**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoDressFreezeOnSendTest`
Expected: PASS — die Logik stammt aus Task 3; dieser Test haelt das Verhalten
fuer die Versand-Verdrahtung fest.

- [ ] **Step 3: Sender verdrahten**

In `src/Services/Zas/Dispo/DispoConfirmationSender.php` den Konstruktor
erweitern:

```php
    public function __construct(
        private DispoEmployeeGateway $gateway,
        // Default, damit Controller/Command/Tests unveraendert bleiben.
        private DispoDressResolver $dress = new DispoDressResolver(),
    ) {}
```

Direkt hinter dem bestehenden Stempel-Update (`->update($stamp);`) ergaenzen:

```php
                // Waeschepaket festschreiben: was der Mitarbeiter jetzt
                // bestaetigt, darf sich durch spaetere Aenderungen am Paket
                // nicht rueckwirkend aendern. Je Einbuchung, nicht je
                // Empfaenger — die Taetigkeit kann pro Tag abweichen.
                $this->dress->freeze($recipient['assignment_ids']);
```

- [ ] **Step 4: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS — besonders `DispoConfirmationSenderChannelTest`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/DispoConfirmationSender.php tests/Integration/DispoDressFreezeOnSendTest.php
git commit -m "feat(recruiting): Versand schreibt das Waeschepaket an der Einbuchung fest"
```

---

### Task 8: Pflegemaske und Startbestand

**Files:**
- Create: `src/Livewire/Dispo/DressPackages.php`
- Create: `resources/views/livewire/dispo/dress-packages.blade.php`
- Create: `src/Console/Commands/DispoSeedDressPackages.php`
- Modify: `routes/web.php:122` (neue Route neben `dispo-settings`)
- Modify: `resources/views/livewire/sidebar.blade.php:150-170` (Menuepunkt)
- Modify: `src/RecruitingServiceProvider.php` (Kommando registrieren — die
  bestehende `commands([...])`-Liste ergaenzen, nicht ersetzen)
- Test: `tests/Integration/DispoSeedDressPackagesTest.php`

**Interfaces:**
- Consumes: `RecDispoDressPackage` (Task 1)
- Produces:
  - Route `recruiting.dispo.dress-packages` auf `/dispo-dress-packages`
  - Kommando `recruiting:dispo-seed-dress-packages {--dry-run}`
  - `DispoSeedDressPackages::PACKAGES` — `list<array{name:string, items:string}>`,
    der Startbestand aus Markus' Liste

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Console\Commands\DispoSeedDressPackages;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Startbestand: Markus' Liste vom 29.09.2026, Trenner vereinheitlicht, die
 * doppelte Schuerze in "Standard schwarz-schwarz" entfernt (Tippfehler,
 * Kundenentscheid 30.09.).
 */
class DispoSeedDressPackagesTest extends DressTestCase
{
    public function test_the_catalogue_carries_markus_eleven_packages(): void
    {
        $this->assertCount(11, DispoSeedDressPackages::PACKAGES);

        $names = array_column(DispoSeedDressPackages::PACKAGES, 'name');
        $this->assertContains('Standard schwarz-weiß', $names);
        $this->assertContains('Logistik', $names);
        $this->assertContains('Lanxess Arena', $names);
    }

    public function test_no_package_lists_the_same_item_twice(): void
    {
        foreach (DispoSeedDressPackages::PACKAGES as $package) {
            $items = array_map('trim', explode(';', $package['items']));
            $normalised = array_map('mb_strtolower', $items);

            $this->assertSame(
                count($normalised),
                count(array_unique($normalised)),
                "Doppelter Eintrag in Paket „{$package['name']}\u{201c}"
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $created = DispoSeedDressPackages::seed(self::TEAM);
        $this->assertSame(11, $created);
        $this->assertSame(11, RecDispoDressPackage::query()->count());

        $again = DispoSeedDressPackages::seed(self::TEAM);
        $this->assertSame(0, $again, 'Zweiter Lauf legt nichts erneut an.');
        $this->assertSame(11, RecDispoDressPackage::query()->count());
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoSeedDressPackagesTest`
Expected: FAIL — Klasse `DispoSeedDressPackages` fehlt.

- [ ] **Step 3: Kommando schreiben**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Legt den Startbestand der Waeschepakete an (Liste Markus Ammerer,
 * 29.09.2026).
 *
 * Zwei bewusste Eingriffe in die gelieferte Liste:
 *   - Trenner vereinheitlicht auf Semikolon (geliefert war Komma/Semikolon
 *     gemischt). Inhalte unveraendert.
 *   - "Standard schwarz-schwarz" nannte die schwarze Schuerze zweimal —
 *     Tippfehler, vom Kunden am 30.09. bestaetigt.
 *
 * Idempotent ueber den Namen: ein zweiter Lauf legt nichts erneut an und
 * ueberschreibt nichts (spaetere Pflege passiert in der Maske).
 */
class DispoSeedDressPackages extends Command
{
    protected $signature = 'recruiting:dispo-seed-dress-packages {--dry-run : nur zeigen, was angelegt wuerde}';

    protected $description = 'Legt den Startbestand der Waeschepakete an (idempotent)';

    /** @var list<array{name:string, items:string}> */
    public const PACKAGES = [
        ['name' => 'Standard schwarz-weiß', 'items' => 'weißes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Standard schwarz-schwarz', 'items' => 'schwarzes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Logistik', 'items' => 'schwarzer Hoodie; schwarzer Pulli; schwarzes T-Shirt; schwarze Hose; Sicherheitsschuhe; keine Jogginghose'],
        ['name' => 'Jeans Outfit', 'items' => 'weißes Hemd; Jeans Hose (ohne Löcher); saubere schwarze oder weiße Sneaker; schwarze Schürze'],
        ['name' => 'Spieltag Borussia Mönchengladbach', 'items' => 'schwarze Hose; schwarze Schuhe oder Pumas'],
        ['name' => 'Borussia Mönchengladbach Sonderveranstaltung', 'items' => 'weißes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Catering Borussia MGL Spieltag', 'items' => 'blaue Jeans (ohne Löcher); weißes Hemd/Bluse; schwarze Schuhe oder Pumas'],
        ['name' => 'Bonn Broich', 'items' => 'schwarze Stoffhose; schwarze Schuhe; schwarze Socken; weißes Hemd/Bluse'],
        ['name' => 'Aufbaukleidung Bonn', 'items' => 'zivil; dunkles Oberteil; feste Schuhe'],
        ['name' => 'Messe DUS-Hallen', 'items' => 'Hemd via Kunden; schwarze Hose; schwarze Schuhe; Schürze via Kunden'],
        ['name' => 'Lanxess Arena', 'items' => 'schwarze Stoffhose; schwarze Schuhe; schwarze Socken; Oberbekleidung vom Kunden'],
    ];

    /** @return int Anzahl neu angelegter Pakete */
    public static function seed(int $teamId): int
    {
        $created = 0;
        foreach (self::PACKAGES as $i => $package) {
            $exists = RecDispoDressPackage::query()
                ->where('team_id', $teamId)
                ->where('name', $package['name'])
                ->exists();
            if ($exists) {
                continue;
            }

            RecDispoDressPackage::create([
                'team_id'    => $teamId,
                'name'       => $package['name'],
                'items_text' => $package['items'],
                'sort_order' => $i,
            ]);
            $created++;
        }

        return $created;
    }

    public function handle(): int
    {
        $teamId = (int) config('recruiting.zas.inbound_team_id');
        if ($teamId === 0) {
            $this->error('recruiting.zas.inbound_team_id ist nicht gesetzt.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach (self::PACKAGES as $package) {
                $this->line($package['name'] . ' — ' . $package['items']);
            }
            $this->info(count(self::PACKAGES) . ' Pakete wuerden geprueft (Team ' . $teamId . ').');

            return self::SUCCESS;
        }

        $this->info(self::seed($teamId) . ' Pakete angelegt (Team ' . $teamId . ').');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Test laufen lassen, gruen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoSeedDressPackagesTest`
Expected: PASS (3 Tests)

- [ ] **Step 5: Pflegemaske schreiben**

`src/Livewire/Dispo/DressPackages.php`:

```php
<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Disposition → Wäschepakete: Katalog pflegen.
 *
 * PITFALL-AUFLAGE (wie Dispo\Settings): schlichte Inputs mit wire:model und
 * explizitem Speichern — NICHT x-ui-input-select + @entangle.
 *
 * Geloescht wird nie, nur deaktiviert: bestehende Zuordnungen und
 * festgeschriebene Einbuchungen brauchen den Datensatz weiter.
 */
class DressPackages extends Component
{
    public string $name = '';
    public string $itemsText = '';
    public ?int $editingId = null;
    public bool $saved = false;

    #[Computed]
    public function packages(): \Illuminate\Support\Collection
    {
        return RecDispoDressPackage::query()
            ->where('team_id', $this->teamId())
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    public function edit(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $this->editingId = $package->id;
        $this->name = (string) $package->name;
        $this->itemsText = (string) $package->items_text;
        $this->saved = false;
    }

    public function cancel(): void
    {
        $this->reset(['name', 'itemsText', 'editingId', 'saved']);
    }

    public function save(): void
    {
        $this->validate([
            'name'      => 'required|string|max:120',
            'itemsText' => 'required|string|max:2000',
        ], [], ['name' => 'Name', 'itemsText' => 'Kleidung']);

        if ($this->editingId !== null) {
            RecDispoDressPackage::query()
                ->where('team_id', $this->teamId())
                ->findOrFail($this->editingId)
                ->update(['name' => trim($this->name), 'items_text' => trim($this->itemsText)]);
        } else {
            RecDispoDressPackage::create([
                'team_id'    => $this->teamId(),
                'name'       => trim($this->name),
                'items_text' => trim($this->itemsText),
                'sort_order' => (int) RecDispoDressPackage::query()->where('team_id', $this->teamId())->max('sort_order') + 1,
            ]);
        }

        unset($this->packages);
        $this->reset(['name', 'itemsText', 'editingId']);
        $this->saved = true;
    }

    public function toggleActive(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $package->update(['is_active' => !$package->is_active]);
        unset($this->packages);
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.dress-packages')
            ->layout('platform::layouts.app');
    }
}
```

**Vor dem Schreiben pruefen:** `->layout(...)` muss dem Muster von
`src/Livewire/Dispo/Settings.php::render()` entsprechen — dort abschauen und
exakt uebernehmen.

- [ ] **Step 6: Blade der Pflegemaske schreiben**

`resources/views/livewire/dispo/dress-packages.blade.php`:

```blade
<div class="p-6 space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Wäschepakete</h1>
        <p class="mt-1 text-sm text-gray-500">Der Inhalt steht wortwörtlich auf der Einsatz-Seite des Mitarbeiters. Den Namen sieht er nie — er ist nur eure Auswahlhilfe.</p>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 space-y-3">
        <div class="font-medium text-gray-700">{{ $editingId ? 'Paket bearbeiten' : 'Neues Paket' }}</div>
        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Name (intern)</span>
            <input type="text" wire:model="name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </label>
        @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Kleidung (mit Semikolon trennen)</span>
            <textarea wire:model="itemsText" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        </label>
        @error('itemsText') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="flex items-center gap-2">
            <button wire:click="save" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
            @if ($editingId)
                <button wire:click="cancel" class="rounded px-3 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
            @endif
            @if ($saved)
                <span class="text-xs text-green-600">✓ gespeichert</span>
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Kleidung</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->packages as $package)
                    <tr class="{{ $package->is_active ? '' : 'text-gray-400' }}">
                        <td class="px-4 py-2 font-medium">{{ $package->name }}</td>
                        <td class="px-4 py-2">{{ $package->items_text }}</td>
                        <td class="px-4 py-2">{{ $package->is_active ? 'aktiv' : 'ausgemustert' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button wire:click="edit({{ $package->id }})" class="text-xs text-blue-600 hover:underline">bearbeiten</button>
                            <button wire:click="toggleActive({{ $package->id }})" class="ml-3 text-xs text-gray-500 hover:underline">{{ $package->is_active ? 'ausmustern' : 'wieder aktivieren' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Noch keine Pakete angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 7: Route, Menuepunkt und Kommando registrieren**

In `routes/web.php` hinter der Zeile mit `dispo-settings`:

```php
Route::get('/dispo-dress-packages', \Platform\Recruiting\Livewire\Dispo\DressPackages::class)
    ->name('recruiting.dispo.dress-packages');
```

In `resources/views/livewire/sidebar.blade.php` im Abschnitt Disposition einen
Eintrag nach dem Muster der Nachbarzeilen ergaenzen (Beschriftung
„Wäschepakete", Route `recruiting.dispo.dress-packages`) — die vorhandene
Zeile fuer „ZAS-Eingang" (um Zeile 168) als Vorlage kopieren, Icon und
Aktiv-Markierung uebernehmen.

In `src/RecruitingServiceProvider.php` die bestehende `commands([...])`-Liste
um `DispoSeedDressPackages::class` ergaenzen.

- [ ] **Step 8: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/dispo/dress-packages.blade.php && php tools/blade-check.php resources/views/livewire/sidebar.blade.php`
Expected: keine Fehlermeldung

- [ ] **Step 9: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add src/Livewire/Dispo/DressPackages.php \
        resources/views/livewire/dispo/dress-packages.blade.php \
        src/Console/Commands/DispoSeedDressPackages.php \
        src/RecruitingServiceProvider.php routes/web.php \
        resources/views/livewire/sidebar.blade.php \
        tests/Integration/DispoSeedDressPackagesTest.php
git commit -m "feat(recruiting): Pflegemaske fuer Waeschepakete plus Startbestand als Kommando"
```

---

## Nach dem Plan

- Review anfordern (`superpowers:requesting-code-review`).
- Merge nach Freigabe als Fast-Forward auf `main` (kein `gh`, keine PRs).
- **Bump meingedeck** — sonst ist nichts live.
- Forge-Deploy mit `migrate` und `view:clear`. Kein `queue:restart` noetig
  (der Versand laeuft synchron im Request), schadet aber nicht.
- Auf prod: `php artisan recruiting:dispo-seed-dress-packages --dry-run`,
  dann ohne Flag.
- Sichttest: echte VA mit zwei Taetigkeiten, je ein Paket setzen, senden,
  Einsatz-Seite mit echtem Token oeffnen — einmal mit gleichem Paket an allen
  Tagen (ein Kasten oben), einmal mit verschiedenen (Kasten je Tag).
