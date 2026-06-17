# WhatsApp-Template-Kosten-Übersicht Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein read-only Dashboard im Recruiting-Modul, das die geschätzten Kosten erfolgreich zugestellter WhatsApp-Templates anzeigt — mit Filtern (Zeitraum, manuell/automatisch) und einer Aufschlüsselung pro Template.

**Architecture:** Drei Schichten, alle neu im Recruiting-Modul, rein lesend: (1) ein reines DTO `WhatsAppCostReport`, das aus aggregierten Zeilen die Kennzahlen + Kosten rechnet (pure, unit-testbar); (2) ein `WhatsAppCostReportService`, der genau eine Aggregat-Query über die crm-Tabellen `comms_whatsapp_messages ⋈ comms_whatsapp_threads` ausführt und das DTO baut; (3) eine dünne Livewire-Seite + Blade-View, die den Service aufruft und rendert. Kein Write, keine Migration, keine Änderung am crm-Modul.

**Tech Stack:** PHP 8, Laravel, Livewire 3, PHPUnit, Orchestra Testbench (für DB-Test mit SQLite :memory:), Tailwind/`x-ui-*`-Komponenten.

## Global Constraints

- **Read-only:** Kein Schreiben und keine Schema-/Code-Änderung im crm-Modul. Zugriff auf `comms_whatsapp_messages` / `comms_whatsapp_threads` ausschließlich lesend.
- **"Erfolgreich zugestellt"** = `comms_whatsapp_messages.status IN ('delivered','read')` UND `direction = 'outbound'`.
- **Team-Scope** über Join `comms_whatsapp_messages.comms_whatsapp_thread_id → comms_whatsapp_threads.id`, gefiltert auf `comms_whatsapp_threads.team_id`. (`comms_whatsapp_messages` hat **kein** `team_id`.)
- **Manuell vs. automatisch:** `sent_by_user_id IS NOT NULL` = manuell, `NULL` = automatisch (System).
- **Zeitraum** filtert auf `delivered_at`.
- **Preis** kommt aus `config('recruiting.whatsapp_costs.price_per_delivered_template')` (Default `0.055`), Währung aus `config('recruiting.whatsapp_costs.currency')` (Default `'EUR'`). Nicht hardcoden.
- **Beträge im UI** stets als **"geschätzte Kosten"** beschriften.
- **Namespaces:** Recruiting-Code unter `Platform\Recruiting\...`; crm-Modelle sind `Platform\Crm\Models\CommsWhatsAppMessage` / `CommsWhatsAppThread` (im Service via Query Builder auf Tabellennamen, nicht zwingend über die Modelle).
- **Aktuelles Team** im Livewire-Kontext: `auth()->user()->currentTeam->id`.

---

### Task 1: Reines Kosten-DTO (`WhatsAppCostReport` + `TemplateCost`)

Die gesamte Rechen-/Aggregationslogik (Summen, manuell/automatisch-Split, Template-Breakdown, Sortierung, Kostenrechnung) als reine Klasse ohne DB — analog zu `tests/Unit/OwnerResolverTest.php`.

**Files:**
- Create: `src/Services/WhatsAppCost/TemplateCost.php`
- Create: `src/Services/WhatsAppCost/WhatsAppCostReport.php`
- Test: `tests/Unit/WhatsAppCostReportTest.php`

**Interfaces:**
- Produces:
  - `final class TemplateCost { public function __construct(public readonly string $templateName, public readonly int $count, public readonly float $cost) {} }`
  - `WhatsAppCostReport::fromRows(array $rows, float $pricePerTemplate, string $currency): WhatsAppCostReport`
    - `$rows`: `array<int, array{template_name: ?string, is_manual: bool, count: int}>`
    - Properties (alle `public readonly`): `int $totalCount`, `float $totalCost`, `int $manualCount`, `float $manualCost`, `int $automaticCount`, `float $automaticCost`, `TemplateCost[] $templates` (absteigend nach `count`), `string $currency`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/WhatsAppCostReportTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReport;

class WhatsAppCostReportTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['template_name' => 'reminder_de',  'is_manual' => false, 'count' => 10],
            ['template_name' => 'reminder_de',  'is_manual' => true,  'count' => 2],
            ['template_name' => 'booking_de',   'is_manual' => false, 'count' => 5],
            ['template_name' => null,           'is_manual' => true,  'count' => 1],
        ];
    }

    public function test_totals_split_and_cost(): void
    {
        $r = WhatsAppCostReport::fromRows($this->rows(), 0.055, 'EUR');

        $this->assertSame(18, $r->totalCount);
        $this->assertSame(3, $r->manualCount);
        $this->assertSame(15, $r->automaticCount);
        $this->assertSame(round(18 * 0.055, 2), $r->totalCost);
        $this->assertSame(round(3 * 0.055, 2), $r->manualCost);
        $this->assertSame(round(15 * 0.055, 2), $r->automaticCost);
        $this->assertSame('EUR', $r->currency);
    }

    public function test_breakdown_grouped_by_template_and_sorted_desc(): void
    {
        $r = WhatsAppCostReport::fromRows($this->rows(), 0.055, 'EUR');

        $this->assertCount(3, $r->templates);
        $this->assertSame('reminder_de', $r->templates[0]->templateName);
        $this->assertSame(12, $r->templates[0]->count); // 10 + 2 zusammengefasst
        $this->assertSame(round(12 * 0.055, 2), $r->templates[0]->cost);
        $this->assertSame('booking_de', $r->templates[1]->count === 5 ? 'booking_de' : 'X');
        $this->assertSame('(ohne Template)', $r->templates[2]->templateName);
    }

    public function test_empty_rows_yield_zeroes(): void
    {
        $r = WhatsAppCostReport::fromRows([], 0.055, 'EUR');

        $this->assertSame(0, $r->totalCount);
        $this->assertSame(0.0, $r->totalCost);
        $this->assertSame([], $r->templates);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/WhatsAppCostReportTest.php`
Expected: FAIL — `Class "Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReport" not found`.

- [ ] **Step 3: Write `TemplateCost`**

Create `src/Services/WhatsAppCost/TemplateCost.php`:

```php
<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

final class TemplateCost
{
    public function __construct(
        public readonly string $templateName,
        public readonly int $count,
        public readonly float $cost,
    ) {}
}
```

- [ ] **Step 4: Write `WhatsAppCostReport`**

Create `src/Services/WhatsAppCost/WhatsAppCostReport.php`:

```php
<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

final class WhatsAppCostReport
{
    /** @param TemplateCost[] $templates */
    public function __construct(
        public readonly int $totalCount,
        public readonly float $totalCost,
        public readonly int $manualCount,
        public readonly float $manualCost,
        public readonly int $automaticCount,
        public readonly float $automaticCost,
        public readonly array $templates,
        public readonly string $currency,
    ) {}

    /**
     * @param array<int, array{template_name: ?string, is_manual: bool, count: int}> $rows
     */
    public static function fromRows(array $rows, float $pricePerTemplate, string $currency): self
    {
        $manualCount = 0;
        $automaticCount = 0;
        $perTemplate = []; // name => count

        foreach ($rows as $row) {
            $count = (int) $row['count'];
            if ($row['is_manual']) {
                $manualCount += $count;
            } else {
                $automaticCount += $count;
            }
            $name = $row['template_name'] ?? '(ohne Template)';
            $perTemplate[$name] = ($perTemplate[$name] ?? 0) + $count;
        }

        $cost = static fn (int $n): float => round($n * $pricePerTemplate, 2);

        $templates = [];
        foreach ($perTemplate as $name => $count) {
            $templates[] = new TemplateCost($name, $count, $cost($count));
        }
        usort($templates, static fn (TemplateCost $a, TemplateCost $b) => $b->count <=> $a->count);

        $totalCount = $manualCount + $automaticCount;

        return new self(
            totalCount: $totalCount,
            totalCost: $cost($totalCount),
            manualCount: $manualCount,
            manualCost: $cost($manualCount),
            automaticCount: $automaticCount,
            automaticCost: $cost($automaticCount),
            templates: $templates,
            currency: $currency,
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/WhatsAppCostReportTest.php`
Expected: PASS (3 Tests, grün).

- [ ] **Step 6: Commit**

```bash
git add src/Services/WhatsAppCost/TemplateCost.php src/Services/WhatsAppCost/WhatsAppCostReport.php tests/Unit/WhatsAppCostReportTest.php
git commit -m "feat(whatsapp-kosten): reines Kosten-DTO mit Split und Template-Breakdown"
```

---

### Task 2: Config-Eintrag + `WhatsAppCostReportService` (DB-Query)

Fügt den Preis-Config-Block hinzu und baut den Service, der genau eine Aggregat-Query ausführt und das DTO aus Task 1 befüllt.

> **Entscheidung 2026-06-17 (Test-Strategie geändert):** Der ursprünglich geplante SQLite-`:memory:`-Feature-Test ist **entfallen**. Das Recruiting-Modul ist ein Composer-Package ohne eigenes Laravel-Bootstrap in der Test-Suite (`tests/bootstrap.php` lädt nur reines PHP, kein `config()`/`DB`-Facade) und `orchestra/testbench` ist nirgends verfügbar. Ein DB-Test hätte eine neue, schwergewichtige Test-Abhängigkeit + ein neues Test-Paradigma erfordert — bewusst abgelehnt, um der Modul-Konvention treu zu bleiben. **Stattdessen:** Service-Code bleibt; die Query-Korrektheit (Team-Isolation, Status-/Richtungs-Filter, Zeitraum, manuell/automatisch) wird **manuell in der Host-App `demo.bhgdigital.de`** gegen die echte (MySQL-)DB verifiziert. Die reinen Rechen-/Aggregationsregeln sind weiterhin per Unit-Test (Task 1) abgedeckt.

**Files:**
- Modify: `config/recruiting.php` (neuer Top-Level-Key `whatsapp_costs`, neben `billables`)
- Create: `src/Services/WhatsAppCost/WhatsAppCostReportService.php`
- Test: ~~`tests/Feature/WhatsAppCostReportServiceTest.php`~~ (entfallen, siehe Entscheidung oben — Query wird manuell in der Host-App verifiziert)

**Interfaces:**
- Consumes: `WhatsAppCostReport::fromRows(...)` (Task 1).
- Produces:
  - `WhatsAppCostReportService::build(int $teamId, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to, string $typeFilter = 'all'): WhatsAppCostReport`
  - `$typeFilter` ∈ `{'all','manual','automatic'}`.

- [ ] **Step 1: Add config block**

In `config/recruiting.php`, **nach** dem `'billables' => [ ... ],`-Block (vor `'zas'`), einfügen:

```php
    'whatsapp_costs' => [
        // Meta Utility-Template an DE-Empfänger, direkt über Cloud API. Stand 04/2026.
        // Bei Meta-Ratenänderung hier anpassen (kein Hardcoding im Code).
        'price_per_delivered_template' => 0.055,
        'currency' => 'EUR',
    ],
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/WhatsAppCostReportServiceTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReportService;

class WhatsAppCostReportServiceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('recruiting.whatsapp_costs.price_per_delivered_template', 0.055);
        $app['config']->set('recruiting.whatsapp_costs.currency', 'EUR');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('comms_whatsapp_threads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
        });

        Schema::create('comms_whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('comms_whatsapp_thread_id');
            $t->string('direction')->default('outbound');
            $t->string('template_name')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('sent_by_user_id')->nullable();
            $t->timestamp('delivered_at')->nullable();
        });

        // Thread 1 → Team 1, Thread 2 → Team 2
        DB::table('comms_whatsapp_threads')->insert([
            ['id' => 1, 'team_id' => 1],
            ['id' => 2, 'team_id' => 2],
        ]);
    }

    private function msg(array $attrs): void
    {
        DB::table('comms_whatsapp_messages')->insert(array_merge([
            'comms_whatsapp_thread_id' => 1,
            'direction' => 'outbound',
            'template_name' => 'reminder_de',
            'status' => 'delivered',
            'sent_by_user_id' => null,
            'delivered_at' => '2026-06-10 12:00:00',
        ], $attrs));
    }

    private function service(): WhatsAppCostReportService
    {
        return new WhatsAppCostReportService();
    }

    private function juneRange(): array
    {
        return [Carbon::parse('2026-06-01 00:00:00'), Carbon::parse('2026-06-30 23:59:59')];
    }

    public function test_counts_only_delivered_outbound_for_team(): void
    {
        $this->msg(['status' => 'delivered']);                 // zählt
        $this->msg(['status' => 'read']);                      // zählt
        $this->msg(['status' => 'sent']);                      // zählt NICHT
        $this->msg(['status' => 'failed']);                    // zählt NICHT
        $this->msg(['direction' => 'inbound', 'status' => 'delivered']); // zählt NICHT
        $this->msg(['comms_whatsapp_thread_id' => 2]);         // fremdes Team, zählt NICHT

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(2, $report->totalCount);
    }

    public function test_manual_vs_automatic_split(): void
    {
        $this->msg(['sent_by_user_id' => null]);  // automatisch
        $this->msg(['sent_by_user_id' => null]);  // automatisch
        $this->msg(['sent_by_user_id' => 42]);    // manuell

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(1, $report->manualCount);
        $this->assertSame(2, $report->automaticCount);
    }

    public function test_date_range_excludes_outside(): void
    {
        $this->msg(['delivered_at' => '2026-06-15 09:00:00']); // drin
        $this->msg(['delivered_at' => '2026-05-31 23:59:59']); // raus
        $this->msg(['delivered_at' => '2026-07-01 00:00:01']); // raus

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(1, $report->totalCount);
    }

    public function test_type_filter_manual_only(): void
    {
        $this->msg(['sent_by_user_id' => null]);
        $this->msg(['sent_by_user_id' => 42]);

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to, 'manual');

        $this->assertSame(1, $report->totalCount);
        $this->assertSame(1, $report->manualCount);
        $this->assertSame(0, $report->automaticCount);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/WhatsAppCostReportServiceTest.php`
Expected: FAIL — `Class "Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReportService" not found`.

> Hinweis falls Testbench im Recruiting-Modul noch nicht für Feature-Tests verdrahtet ist: sicherstellen, dass `orchestra/testbench` als dev-Dependency verfügbar ist und die PHPUnit-Konfiguration ein `Feature`-Suite-Verzeichnis (`tests/Feature`) kennt. Muster: `platforms-core/tests/TestCase.php`.

- [ ] **Step 4: Write the service**

Create `src/Services/WhatsAppCost/WhatsAppCostReportService.php`:

```php
<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class WhatsAppCostReportService
{
    public function build(
        int $teamId,
        CarbonInterface $from,
        CarbonInterface $to,
        string $typeFilter = 'all',
    ): WhatsAppCostReport {
        $query = DB::table('comms_whatsapp_messages as m')
            ->join('comms_whatsapp_threads as t', 'm.comms_whatsapp_thread_id', '=', 't.id')
            ->where('t.team_id', $teamId)
            ->where('m.direction', 'outbound')
            ->whereIn('m.status', ['delivered', 'read'])
            ->whereBetween('m.delivered_at', [$from, $to]);

        if ($typeFilter === 'manual') {
            $query->whereNotNull('m.sent_by_user_id');
        } elseif ($typeFilter === 'automatic') {
            $query->whereNull('m.sent_by_user_id');
        }

        $rows = $query
            ->selectRaw('m.template_name as template_name')
            ->selectRaw('(m.sent_by_user_id is not null) as is_manual')
            ->selectRaw('count(*) as count')
            ->groupByRaw('m.template_name, (m.sent_by_user_id is not null)')
            ->get()
            ->map(fn ($r) => [
                'template_name' => $r->template_name,
                'is_manual' => (bool) $r->is_manual,
                'count' => (int) $r->count,
            ])
            ->all();

        $price = (float) config('recruiting.whatsapp_costs.price_per_delivered_template', 0.055);
        $currency = (string) config('recruiting.whatsapp_costs.currency', 'EUR');

        return WhatsAppCostReport::fromRows($rows, $price, $currency);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/WhatsAppCostReportServiceTest.php`
Expected: PASS (4 Tests, grün).

- [ ] **Step 6: Commit**

```bash
git add config/recruiting.php src/Services/WhatsAppCost/WhatsAppCostReportService.php tests/Feature/WhatsAppCostReportServiceTest.php
git commit -m "feat(whatsapp-kosten): Aggregat-Service ueber comms-Tabellen + Preis-Config"
```

---

### Task 3: Livewire-Seite, Route und Seitenleisten-Eintrag

Dünne Full-Page-Livewire-Komponente, die Filter-State hält und den Service aufruft. Plus Route-Registrierung und Sidebar-Eintrag, damit die Seite erreichbar ist.

**Files:**
- Create: `src/Livewire/WhatsAppCosts/Index.php`
- Modify: `routes/web.php` (neue Route nach den bestehenden Dashboard-Routen)
- Modify: `config/recruiting.php` (Sidebar-Eintrag in der `Recruiting`-Gruppe)

**Interfaces:**
- Consumes: `WhatsAppCostReportService::build(...)` (Task 2).
- Produces: Livewire-Komponente `Platform\Recruiting\Livewire\WhatsAppCosts\Index` mit public props `string $from`, `string $to`, `string $type` und `#[Computed] report()`.

- [ ] **Step 1: Write the Livewire component**

Create `src/Livewire/WhatsAppCosts/Index.php`:

```php
<?php

namespace Platform\Recruiting\Livewire\WhatsAppCosts;

use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReport;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReportService;

class Index extends Component
{
    public string $from = '';
    public string $to = '';
    public string $type = 'all'; // all | manual | automatic

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
    }

    #[Computed]
    public function report(): WhatsAppCostReport
    {
        $teamId = auth()->user()->currentTeam->id;

        return app(WhatsAppCostReportService::class)->build(
            $teamId,
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $this->type,
        );
    }

    public function render()
    {
        return view('recruiting::livewire.whatsapp-costs.index')
            ->layout('platform::layouts.app');
    }
}
```

- [ ] **Step 2: Register the route**

In `routes/web.php`, nach den bestehenden Dashboard-Routen einfügen:

```php
Route::get('/whatsapp-costs', \Platform\Recruiting\Livewire\WhatsAppCosts\Index::class)
    ->name('recruiting.whatsapp-costs.index');
```

- [ ] **Step 3: Add the sidebar entry**

In `config/recruiting.php`, innerhalb der `Recruiting`-Gruppe (`sidebar` → `items`), als letzten Eintrag ergänzen:

```php
['label' => 'WhatsApp-Kosten', 'route' => 'recruiting.whatsapp-costs.index', 'icon' => 'heroicon-o-banknotes'],
```

- [ ] **Step 4: Verify route is wired (no view yet → expected error)**

Run: `php artisan route:list --name=whatsapp-costs`
Expected: Eine Zeile `recruiting.whatsapp-costs.index` → `...Livewire\WhatsAppCosts\Index`.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/WhatsAppCosts/Index.php routes/web.php config/recruiting.php
git commit -m "feat(whatsapp-kosten): Livewire-Seite, Route und Sidebar-Eintrag"
```

---

### Task 4: Blade-View (Kennzahlen, Filter, Breakdown)

Rendert die Kennzahlen-Karten, die Filter (Zeitraum + Typ) und die Template-Tabelle. Beträge als "geschätzte Kosten" beschriftet.

**Files:**
- Create: `resources/views/livewire/whatsapp-costs/index.blade.php`

**Interfaces:**
- Consumes: `$this->report` (Computed) vom Typ `WhatsAppCostReport` (Task 1/3).

> **Blade-Hinweis (Modul-Konvention):** Keine Inline-`@if`/`??`-Ausdrücke direkt in `x-ui-*`-Attribute schreiben — vorher in einem `@php`-Block vorberechnen. Es existieren nur `x-ui-input-text` / `x-ui-input-select` / `x-ui-input-textarea` (kein `x-ui-input-date`/`-number`); für Datumsfelder native `<input type="date">` verwenden.

- [ ] **Step 1: Write the view**

Create `resources/views/livewire/whatsapp-costs/index.blade.php`:

```blade
<div class="p-6 space-y-6">
    @php
        $report = $this->report;
        $fmt = fn (float $v) => number_format($v, 2, ',', '.') . ' ' . $report->currency;
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">WhatsApp-Kosten</h1>
        <span class="text-sm text-gray-500">Geschätzte Kosten zugestellter Templates</span>
    </div>

    {{-- Filter --}}
    <div class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-4">
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Von</span>
            <input type="date" wire:model.live="from" class="rounded border-gray-300">
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Bis</span>
            <input type="date" wire:model.live="to" class="rounded border-gray-300">
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Typ</span>
            <select wire:model.live="type" class="rounded border-gray-300">
                <option value="all">Alle</option>
                <option value="manual">Manuell</option>
                <option value="automatic">Automatisch (System)</option>
            </select>
        </label>
    </div>

    {{-- Kennzahlen --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Zugestellte Templates</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->totalCount }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Geschätzte Kosten gesamt</div>
            <div class="mt-1 text-2xl font-semibold">{{ $fmt($report->totalCost) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">davon manuell</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->manualCount }}</div>
            <div class="text-sm text-gray-500">{{ $fmt($report->manualCost) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">davon automatisch (System)</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->automaticCount }}</div>
            <div class="text-sm text-gray-500">{{ $fmt($report->automaticCost) }}</div>
        </div>
    </div>

    {{-- Breakdown --}}
    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3 font-medium">Aufschlüsselung pro Template</div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Template</th>
                    <th class="px-4 py-2 font-medium text-right">Anzahl</th>
                    <th class="px-4 py-2 font-medium text-right">Geschätzte Kosten</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report->templates as $tpl)
                    <tr class="border-t border-gray-50">
                        <td class="px-4 py-2">{{ $tpl->templateName }}</td>
                        <td class="px-4 py-2 text-right">{{ $tpl->count }}</td>
                        <td class="px-4 py-2 text-right">{{ $fmt($tpl->cost) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-gray-400">
                            Keine zugestellten Templates im gewählten Zeitraum.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400">
        Kosten sind geschätzt (Anzahl × konfigurierter Preis je Template). Utility-Templates
        innerhalb eines offenen 24-Stunden-Service-Fensters sind bei Meta kostenfrei und
        können hier leicht überschätzt werden.
    </p>
</div>
```

- [ ] **Step 2: Manual verification (kein automatisierter View-Test)**

Da das Modul bislang keine Livewire-Feature-Test-Infrastruktur (Browser/Render) hat und die Komponente ein dünner Pass-Through ist, wird die Seite manuell verifiziert:

1. App starten und als Nutzer mit aktivem Team einloggen.
2. Seitenleiste → **"WhatsApp-Kosten"** öffnen (`/recruiting/whatsapp-costs`).
3. Prüfen: Kennzahlen + Tabelle rendern ohne Fehler; Default-Zeitraum = laufender Monat.
4. Zeitraum/Typ-Filter ändern → Werte aktualisieren sich live.

Expected: Seite lädt fehlerfrei; Zahlen plausibel zu den Sends des Teams; Filter wirken.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/whatsapp-costs/index.blade.php
git commit -m "feat(whatsapp-kosten): Dashboard-View mit Kennzahlen, Filtern und Breakdown"
```

---

## Self-Review

**Spec coverage:**
- Datenquelle/Scope (delivered+outbound, Team-Join, alle Team-Sends) → Task 2 (Service + Constraints).
- Kostenberechnung (Config 0,055 €, konfigurierbar, "geschätzt") → Task 1 (Math), Task 2 (Config), Task 4 (Label).
- manuell/automatisch über `sent_by_user_id` → Task 1 (Split), Task 2 (Filter/Test), Task 4 (Beschriftung "Automatisch (System)").
- UI: Seitenleisten-Punkt "WhatsApp-Kosten", Kennzahlen, Zeitraum/Typ-Filter, Template-Breakdown → Task 3 + Task 4.
- Testing (Team-Isolation, Status-Filter, Split, Zeitraum, Breakdown, Kostenrechnung) → Task 1 (Unit) + Task 2 (Feature).
- Nicht-Ziele (kein crm-Write, keine Migration, kein feiner Quellen-Split, keine pricing_analytics-API) → eingehalten; keine Tasks, die crm verändern.

**Placeholder scan:** Kein TBD/TODO; jeder Code-Schritt enthält vollständigen Code und exakte Befehle.

**Type consistency:** `WhatsAppCostReport::fromRows(array, float, string)` und die Properties (`totalCount`, `totalCost`, `manualCount`, `manualCost`, `automaticCount`, `automaticCost`, `templates`, `currency`) sind in Task 1 definiert und in Task 2/3/4 identisch verwendet. `WhatsAppCostReportService::build(int, CarbonInterface, CarbonInterface, string)` ist in Task 2 definiert und in Task 3 identisch aufgerufen. `TemplateCost`-Properties (`templateName`, `count`, `cost`) konsistent in Task 1 und Task 4.
