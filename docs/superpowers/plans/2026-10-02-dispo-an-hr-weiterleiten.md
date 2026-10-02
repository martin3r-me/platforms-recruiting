# Dispo → HR weiterleiten — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Dispo leitet eingehende Nachrichten eines MA (optional mit Kommentar) an HR weiter. HR sieht sie in `/recruiting/conversations` unter „Weitergeleitet" und schickt dem MA von dort die Erstnachricht `t_com_gen` über die HR-Nummer.

**Architecture:** Eine eigene Tabelle `rec_conversation_forwards` hält die Weiterleitung, mit einer Kopie der Texte. Sie zeigt zuerst nur auf den Dispo-Thread, den HR-Thread bekommt sie erst mit der Erstnachricht. Die Logik liegt in drei Services unter `src/Services/Comms/Forward/`: Weiterleiten, HR-Thread finden und Erstnachricht senden. Dazu kommen zwei pure Helfer (Auswahl, Chip-Zustand). Die Oberfläche: ein Fenster in `Dispo\Conversations\Index` und eine neue Livewire-Komponente `Conversations\Forwards`, die `Inbox` als Ansicht einblendet. CRM und Core werden nur **aufgerufen**, nie geändert.

**Tech Stack:** Laravel/Livewire 3, Eloquent, PHPUnit (Unit pur + Integration mit Capsule/SQLite, kein Testbench), Blade + Tailwind.

**Spec:** `docs/superpowers/specs/2026-10-02-dispo-an-hr-weiterleiten-design.md`

## Global Constraints

- Nichts außerhalb von `platforms-recruiting` ändern (keine Edits in platform-crm/core/integrations).
- Tests laufen aus dem Modulverzeichnis mit `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`.
- Unit-Tests (`tests/Unit`) haben **kein** vendor-Autoload: Pure-Klassen ohne Carbon/Laravel, Zeit als Unix-Timestamp.
- Integration-Tests (`tests/Integration`): handgebauter Container + Capsule SQLite `:memory:`, Migrationen per `require …->up()`, Muster `InboxLastMessageAtTest`. Kein `order-by=random`, Dispatcher setzen.
- Blade wird mit `php tools/blade-check.php <datei>` geprüft, nicht mit `php -l`.
- Blade-Fallen: keine inline-`@if` in `x-ui-*`-Attributen, keine an Wortzeichen geklebten Direktiven, `@php … @endphp` nur in Blockform.
- Vorlage für die Erstnachricht: genau `t_com_gen` („Gespräch starten").
- Keine E-Mail, kein automatischer Versand an den MA über die Dispo-Nummer.
- Texte der Oberfläche auf Deutsch, Umlaute echt (ä/ö/ü), Kommentare im Stil des Moduls (deutsch, ae/oe/ue erlaubt).
- Commit-Footer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`

## Review Focus

1. **Fremde Nachrichten-IDs per Livewire-Aufruf:** `submitForward` lässt sich mit beliebigen IDs aufrufen. IDs aus einem anderen Thread oder ausgehende Nachrichten müssen abgewiesen werden, statt kopiert zu werden. Getestet in Task 3.
2. **Doppelklick auf „Erstnachricht senden":** Ein zweiter Aufruf darf nicht zum zweiten Mal senden. Getestet in Task 4 (`test_zweiter_aufruf_sendet_nicht_erneut`).
3. **HR-Thread schon vorhanden, aber anders formatiert** (`4917…` statt `+4917…`): Er muss wiederverwendet werden, statt einen zweiten Thread anzulegen. Getestet in Task 4 (`test_vorhandener_hr_thread_ohne_plus_wird_wiederverwendet`).
4. **Weiterleitung aus fremdem Team:** HR eines anderen Teams darf sie weder sehen noch bearbeiten. Getestet in Task 4 (`test_open_for_team_zeigt_keine_fremden`) und durch die Team-Prüfung in `Forwards::forwardForTeam`.
5. **Nachricht ohne Text (Bild/Sprachnachricht):** Die Kopie darf nicht leer und unkenntlich sein. Gespeichert wird `media_type`, die Karte zeigt „📎 Bild". Getestet in Task 3 (`test_medien_nachricht_behaelt_typ`).

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php` | Tabelle |
| `src/Models/RecConversationForward.php` | Model + Scopes |
| `src/Services/Comms/Forward/ForwardMessageSelection.php` | pure: welche Nachrichten wählbar sind / Auswahl säubern |
| `src/Services/Comms/Forward/ForwardStatus.php` | pure: Chip-Zustand eines Dispo-Threads |
| `src/Services/Comms/Forward/ConversationForwarder.php` | Weiterleitung anlegen (Kopie) |
| `src/Services/Comms/Forward/ForwardHrThreadLookup.php` | vorhandenen HR-Thread zur Nummer finden |
| `src/Services/Comms/Forward/ForwardFirstContact.php` | Erstnachricht senden + Datensatz fortschreiben |
| `src/Services/Zas/Dispo/DispoThreadDirectory.php` | `messages()` liefert zusätzlich `id` + `ts` |
| `resources/views/livewire/dispo/_messages.blade.php` | Zeilenart `note`, Symbol bei `$forwardable` |
| `src/Livewire/Dispo/Conversations/Index.php` + View | Fenster, Vermerk, Chip |
| `src/Livewire/Conversations/Forwards.php` + View | Reiter „Weitergeleitet" |
| `src/Livewire/Conversations/Inbox.php` + View | Pille, Umschalten, interne Karte |
| `src/Livewire/Sidebar.php` + View | Zähler |

---

### Task 1: Tabelle + Model

**Files:**
- Create: `database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php`
- Create: `src/Models/RecConversationForward.php`
- Test: `tests/Integration/ConversationForwardTableTest.php`

**Interfaces:**
- Produces: `RecConversationForward` mit `$casts` (`messages` → array, `*_at` → datetime), Scopes `openForTeam(int $teamId)`, `forTeam(int $teamId)`, Methode `isOpen(): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Weiterleitung Dispo → HR (Spec 02.10.2026): eigene Tabelle, Kopie der
 * Texte als JSON, offen = done_at leer.
 */
class ConversationForwardTableTest extends TestCase
{
    private const TEAM = 711;

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

        (require dirname(__DIR__, 2) . '/database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php')->up();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
    }

    private function forward(int $teamId, ?string $doneAt = null): RecConversationForward
    {
        return RecConversationForward::create([
            'team_id' => $teamId, 'source_thread_id' => 5, 'phone' => '+4917600000001',
            'display_name' => 'Jonas Stein',
            'messages' => [['message_id' => 1, 'body' => 'Wann kommt das Gehalt?', 'media_type' => null, 'received_at' => '2026-10-01T19:24:00+02:00']],
            'forwarded_at' => '2026-10-02 09:14:00', 'done_at' => $doneAt,
        ]);
    }

    public function test_messages_kommen_als_array_zurueck(): void
    {
        $f = $this->forward(self::TEAM)->fresh();
        $this->assertSame('Wann kommt das Gehalt?', $f->messages[0]['body']);
        $this->assertTrue($f->isOpen());
    }

    public function test_open_for_team_ignoriert_erledigte_und_fremde(): void
    {
        $offen = $this->forward(self::TEAM);
        $this->forward(self::TEAM, '2026-10-02 10:00:00');
        $this->forward(self::TEAM + 1);

        $ids = RecConversationForward::query()->openForTeam(self::TEAM)->pluck('id')->all();
        $this->assertSame([(int) $offen->id], array_map('intval', $ids));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationForwardTableTest`
Expected: FAIL/ERROR, weil die Migrationsdatei fehlt („Failed opening required").

- [ ] **Step 3: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weiterleitungen Dispo → HR (Spec 02.10.2026).
 *
 * Eigene Tabelle, weil der HR-Thread beim Weiterleiten oft noch gar nicht
 * existiert — er entsteht erst mit der Erstnachricht. Die Texte liegen als
 * KOPIE in `messages`, damit die HR-Seite nie in die Dispo-Kanaele greift.
 * Kein Fremdschluessel ueber die Modulgrenze (Threads gehoeren platform-crm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_conversation_forwards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('source_thread_id')->index();
            $table->unsignedBigInteger('rec_employee_id')->nullable();
            $table->string('phone', 32);
            $table->string('display_name', 190);
            $table->json('messages');
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('forwarded_by_user_id')->nullable();
            $table->string('forwarded_by_name', 190)->nullable();
            $table->timestamp('forwarded_at');
            $table->unsignedBigInteger('target_thread_id')->nullable()->index();
            $table->timestamp('first_contact_at')->nullable();
            $table->unsignedBigInteger('first_contact_by_user_id')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedBigInteger('done_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'done_at'], 'idx_rec_conv_fwd_team_done');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_forwards');
    }
};
```

- [ ] **Step 4: Write model**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine an HR weitergeleitete Dispo-Nachricht (oder mehrere). Offen, solange
 * done_at leer ist. target_thread_id ist der HR-Thread — leer, bis HR die
 * Erstnachricht geschickt oder einen offenen Chat uebernommen hat.
 */
class RecConversationForward extends Model
{
    protected $table = 'rec_conversation_forwards';

    protected $fillable = [
        'team_id', 'source_thread_id', 'rec_employee_id', 'phone', 'display_name',
        'messages', 'comment', 'forwarded_by_user_id', 'forwarded_by_name', 'forwarded_at',
        'target_thread_id', 'first_contact_at', 'first_contact_by_user_id', 'last_error',
        'done_at', 'done_by_user_id',
    ];

    protected $casts = [
        'messages' => 'array',
        'forwarded_at' => 'datetime',
        'first_contact_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeOpenForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId)->whereNull('done_at');
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationForwardTableTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php src/Models/RecConversationForward.php tests/Integration/ConversationForwardTableTest.php
git commit -m "feat(recruiting): Tabelle fuer Weiterleitungen Dispo an HR

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Pure Helfer — Auswahl + Chip-Zustand

**Files:**
- Create: `src/Services/Comms/Forward/ForwardMessageSelection.php`
- Create: `src/Services/Comms/Forward/ForwardStatus.php`
- Test: `tests/Unit/ForwardMessageSelectionTest.php`, `tests/Unit/ForwardStatusTest.php`

**Interfaces:**
- Produces:
  - `ForwardMessageSelection::candidates(array $messages, int $clickedId, int $now, int $days = 7): list<int>`. `$messages` ist eine `list<array{id:int, direction:string, kind:string, ts:int}>`. Ergebnis: IDs eingehender Nicht-Vorlagen-Nachrichten aus den letzten `$days` Tagen, **plus** die angeklickte (auch wenn älter), neueste zuerst. Ist die angeklickte keine gültige eingehende Nachricht, kommt `[]` zurück.
  - `ForwardMessageSelection::sanitize(array $requested, array $candidateIds): list<int>`. Behält nur IDs, die Kandidaten sind, ohne Dubletten und in der Reihenfolge der Kandidaten.
  - `ForwardStatus::OPEN = 'open'`, `ForwardStatus::DONE = 'done'`, `ForwardStatus::latest(array $forwards): ?string`. `$forwards` ist eine `list<array{forwarded_at:int, done:bool}>`, die jüngste Weiterleitung gewinnt, leer ergibt `null`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ForwardMessageSelectionTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardMessageSelection;

class ForwardMessageSelectionTest extends TestCase
{
    private const NOW = 1_759_400_000; // fester Zeitpunkt, nie time()

    private function msg(int $id, string $direction, int $daysAgo, string $kind = 'text'): array
    {
        return ['id' => $id, 'direction' => $direction, 'kind' => $kind, 'ts' => self::NOW - $daysAgo * 86400];
    }

    public function test_nur_eingehende_der_letzten_tage_neueste_zuerst(): void
    {
        $messages = [
            $this->msg(1, 'inbound', 10),
            $this->msg(2, 'inbound', 3),
            $this->msg(3, 'outbound', 2),
            $this->msg(4, 'inbound', 0),
        ];

        $this->assertSame([4, 2], ForwardMessageSelection::candidates($messages, 4, self::NOW));
    }

    public function test_angeklickte_alte_nachricht_ist_trotzdem_dabei(): void
    {
        $messages = [$this->msg(1, 'inbound', 30), $this->msg(2, 'inbound', 1)];

        $this->assertSame([2, 1], ForwardMessageSelection::candidates($messages, 1, self::NOW));
    }

    public function test_ausgehende_oder_unbekannte_angeklickte_ergibt_leer(): void
    {
        $messages = [$this->msg(1, 'outbound', 0), $this->msg(2, 'inbound', 0)];

        $this->assertSame([], ForwardMessageSelection::candidates($messages, 1, self::NOW));
        $this->assertSame([], ForwardMessageSelection::candidates($messages, 99, self::NOW));
    }

    public function test_vorlagen_sind_nie_kandidat(): void
    {
        $messages = [$this->msg(1, 'inbound', 0, 'template'), $this->msg(2, 'inbound', 0)];

        $this->assertSame([2], ForwardMessageSelection::candidates($messages, 2, self::NOW));
    }

    public function test_sanitize_wirft_fremde_ids_und_dubletten_raus(): void
    {
        $this->assertSame([4, 2], ForwardMessageSelection::sanitize([2, 99, 4, 2, '4'], [4, 2]));
        $this->assertSame([], ForwardMessageSelection::sanitize([99], [4, 2]));
    }
}
```

`tests/Unit/ForwardStatusTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardStatus;

class ForwardStatusTest extends TestCase
{
    public function test_keine_weiterleitung_kein_chip(): void
    {
        $this->assertNull(ForwardStatus::latest([]));
    }

    public function test_juengste_gewinnt_auch_wenn_aeltere_offen(): void
    {
        $this->assertSame(ForwardStatus::DONE, ForwardStatus::latest([
            ['forwarded_at' => 100, 'done' => false],
            ['forwarded_at' => 200, 'done' => true],
        ]));
        $this->assertSame(ForwardStatus::OPEN, ForwardStatus::latest([
            ['forwarded_at' => 300, 'done' => false],
            ['forwarded_at' => 200, 'done' => true],
        ]));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'ForwardMessageSelectionTest|ForwardStatusTest'`
Expected: ERROR „Class … not found"

- [ ] **Step 3: Implement**

`src/Services/Comms/Forward/ForwardMessageSelection.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Welche Nachrichten im Weiterleiten-Fenster waehlbar sind. Pure (Unix-
 * Timestamps, kein Carbon), damit im Unit-Test ohne vendor pruefbar.
 *
 * Waehlbar: eingehende Nicht-Vorlagen-Nachrichten der letzten Tage — und
 * immer die angeklickte, auch wenn sie aelter ist (das Symbol sitzt an jeder
 * eingehenden Blase des Verlaufs).
 */
final class ForwardMessageSelection
{
    /**
     * @param list<array{id:int, direction:string, kind:string, ts:int}> $messages
     * @return list<int>
     */
    public static function candidates(array $messages, int $clickedId, int $now, int $days = 7): array
    {
        $since = $now - $days * 86400;
        $eligible = array_values(array_filter(
            $messages,
            static fn (array $m) => $m['direction'] === 'inbound' && $m['kind'] !== 'template',
        ));

        $clickedOk = false;
        foreach ($eligible as $m) {
            if ((int) $m['id'] === $clickedId) {
                $clickedOk = true;
                break;
            }
        }
        if (!$clickedOk) {
            return [];
        }

        $picked = array_values(array_filter(
            $eligible,
            static fn (array $m) => (int) $m['id'] === $clickedId || (int) $m['ts'] >= $since,
        ));
        usort($picked, static fn (array $a, array $b) => [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']]);

        return array_map(static fn (array $m) => (int) $m['id'], $picked);
    }

    /**
     * @param array<int, int|string> $requested
     * @param list<int> $candidateIds
     * @return list<int>
     */
    public static function sanitize(array $requested, array $candidateIds): array
    {
        $wanted = array_flip(array_map('intval', $requested));

        return array_values(array_filter($candidateIds, static fn (int $id) => isset($wanted[$id])));
    }
}
```

`src/Services/Comms/Forward/ForwardStatus.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Chip am Dispo-Thread: "bei HR" (offen) oder "HR erledigt". Massgeblich ist
 * die JUENGSTE Weiterleitung des Threads.
 */
final class ForwardStatus
{
    public const OPEN = 'open';
    public const DONE = 'done';

    /** @param list<array{forwarded_at:int, done:bool}> $forwards */
    public static function latest(array $forwards): ?string
    {
        if ($forwards === []) {
            return null;
        }
        usort($forwards, static fn (array $a, array $b) => $b['forwarded_at'] <=> $a['forwarded_at']);

        return $forwards[0]['done'] ? self::DONE : self::OPEN;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'ForwardMessageSelectionTest|ForwardStatusTest'`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/Forward/ForwardMessageSelection.php src/Services/Comms/Forward/ForwardStatus.php tests/Unit/ForwardMessageSelectionTest.php tests/Unit/ForwardStatusTest.php
git commit -m "feat(recruiting): Auswahl und Chip-Zustand fuer HR-Weiterleitung

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Weiterleitung anlegen (`ConversationForwarder`)

**Files:**
- Create: `src/Services/Comms/Forward/ConversationForwarder.php`
- Test: `tests/Integration/ConversationForwarderTest.php`

**Interfaces:**
- Consumes: `RecConversationForward` (Task 1).
- Produces: `ConversationForwarder::forward(int $teamId, CommsWhatsAppThread $thread, array $messageIds, ?string $comment, ?int $employeeId, string $displayName, ?object $user): array{ok: bool, error: ?string, forward: ?RecConversationForward}`.
  - Lädt nur Nachrichten **dieses** Threads mit `direction = 'inbound'` aus `$messageIds`. Gibt es keine, kommt `ok=false` mit dem Fehler „Keine eingehende Nachricht ausgewählt." zurück, und nichts wird gespeichert.
  - Die Kopie ist eine `list<array{message_id:int, body:string, media_type:?string, received_at:string}>` (ISO-8601), chronologisch.
  - Der Kommentar wird getrimmt, ist er leer, wird `null` gespeichert, gekürzt auf 1000 Zeichen.
  - `forwarded_by_name` = `$user->name ?? null`, `forwarded_at` = jetzt.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;

class ConversationForwarderTest extends TestCase
{
    private const TEAM = 712;

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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        foreach ([
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_02_17_200002_add_conversation_thread_id_to_comms_whatsapp_messages.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$own, 'database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php'],
        ] as [$root, $rel]) {
            (require $root . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
        Capsule::table('comms_whatsapp_messages')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
    }

    private function thread(): CommsWhatsAppThread
    {
        $id = (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-' . bin2hex(random_bytes(6)), 'comms_channel_id' => 1,
            'remote_phone_number' => '+4917663854907', 'is_unread' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CommsWhatsAppThread::findOrFail($id);
    }

    private function message(int $threadId, string $direction, string $body, string $at, string $type = 'text'): int
    {
        return (int) Capsule::table('comms_whatsapp_messages')->insertGetId([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => $direction, 'body' => $body,
            'message_type' => $type, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_legt_weiterleitung_mit_kopie_und_kommentar_an(): void
    {
        $t = $this->thread();
        $a = $this->message($t->id, 'inbound', 'Guten Abend', '2026-10-01 19:20:00');
        $b = $this->message($t->id, 'inbound', 'Wann kommt das Gehalt?', '2026-10-01 19:24:00');
        $user = (object) ['id' => 7, 'name' => 'Sebastian'];

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$b, $a], '  Gehalt September fehlt ', 42, 'Jonas Stein', $user);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $f = RecConversationForward::findOrFail($r['forward']->id);
        $this->assertSame([$a, $b], array_column($f->messages, 'message_id'), 'chronologisch');
        $this->assertSame('Wann kommt das Gehalt?', $f->messages[1]['body']);
        $this->assertSame('Gehalt September fehlt', $f->comment);
        $this->assertSame(42, (int) $f->rec_employee_id);
        $this->assertSame('+4917663854907', $f->phone);
        $this->assertSame('Sebastian', $f->forwarded_by_name);
        $this->assertSame(self::TEAM, (int) $f->team_id);
        $this->assertNull($f->done_at);
    }

    public function test_fremde_und_ausgehende_ids_werden_abgewiesen(): void
    {
        $t = $this->thread();
        $other = $this->thread();
        $out = $this->message($t->id, 'outbound', 'ok', '2026-10-01 10:53:00');
        $foreign = $this->message($other->id, 'inbound', 'fremd', '2026-10-01 10:00:00');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$out, $foreign], null, null, '+4917663854907', null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Keine eingehende Nachricht ausgewählt.', $r['error']);
        $this->assertSame(0, RecConversationForward::count());
    }

    public function test_leerer_kommentar_wird_null(): void
    {
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', 'Hallo', '2026-10-01 10:00:00');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], '   ', null, 'X', null);

        $this->assertNull(RecConversationForward::findOrFail($r['forward']->id)->comment);
    }

    public function test_medien_nachricht_behaelt_typ(): void
    {
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', '', '2026-10-01 10:00:00', 'image');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], null, null, 'X', null);

        $this->assertSame('image', RecConversationForward::findOrFail($r['forward']->id)->messages[0]['media_type']);
    }
}
```

> Hinweis für die Umsetzung: Ist `message_type` in der CRM-Messages-Migration nicht nullable oder heißt die Spalte anders, passt den Insert an die Migration an. Der Medientyp wird im Service über `$m->media_display_type` gelesen, falls `hasMedia()` true ist, sonst über `message_type !== 'text'`. Vor dem Schreiben prüfen: `grep -n "function hasMedia\|getMediaDisplayTypeAttribute" -A12 ../platform-crm/src/Models/CommsWhatsAppMessage.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationForwarderTest`
Expected: ERROR „Class … ConversationForwarder not found"

- [ ] **Step 3: Implement**

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Legt eine Weiterleitung Dispo → HR an (Spec 02.10.2026).
 *
 * Sicherheit: die IDs kommen aus einem Livewire-Aufruf und sind damit frei
 * waehlbar. Kopiert wird nur, was zu DIESEM Thread gehoert und eingehend ist.
 * Liefert ok/error statt zu werfen (Muster DispoReplySender).
 */
final class ConversationForwarder
{
    private const COMMENT_MAX = 1000;

    /**
     * @param array<int, int|string> $messageIds
     * @return array{ok: bool, error: ?string, forward: ?RecConversationForward}
     */
    public function forward(
        int $teamId,
        CommsWhatsAppThread $thread,
        array $messageIds,
        ?string $comment,
        ?int $employeeId,
        string $displayName,
        ?object $user,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        $rows = $ids === [] ? collect() : $thread->messages()
            ->whereIn('id', $ids)
            ->where('direction', 'inbound')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return ['ok' => false, 'error' => 'Keine eingehende Nachricht ausgewählt.', 'forward' => null];
        }

        $copy = $rows->map(function ($m) {
            $at = $m->sent_at ?? $m->created_at;
            $hasMedia = method_exists($m, 'hasMedia') && $m->hasMedia();

            return [
                'message_id' => (int) $m->id,
                'body' => (string) ($m->body ?? ''),
                'media_type' => $hasMedia
                    ? (string) $m->media_display_type
                    : ((string) ($m->message_type ?? 'text') !== 'text' ? (string) $m->message_type : null),
                'received_at' => optional($at)->toIso8601String() ?? '',
            ];
        })->values()->all();

        $comment = trim((string) $comment);

        $forward = RecConversationForward::create([
            'team_id' => $teamId,
            'source_thread_id' => (int) $thread->id,
            'rec_employee_id' => $employeeId,
            'phone' => (string) $thread->remote_phone_number,
            'display_name' => mb_substr($displayName !== '' ? $displayName : (string) $thread->remote_phone_number, 0, 190),
            'messages' => $copy,
            'comment' => $comment === '' ? null : mb_substr($comment, 0, self::COMMENT_MAX),
            'forwarded_by_user_id' => isset($user->id) ? (int) $user->id : null,
            'forwarded_by_name' => isset($user->name) ? mb_substr((string) $user->name, 0, 190) : null,
            'forwarded_at' => now(),
        ]);

        return ['ok' => true, 'error' => null, 'forward' => $forward];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationForwarderTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/Forward/ConversationForwarder.php tests/Integration/ConversationForwarderTest.php
git commit -m "feat(recruiting): Dispo-Nachrichten als Kopie an HR weiterleiten

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: HR-Thread finden + Erstnachricht senden

**Files:**
- Create: `src/Services/Comms/Forward/ForwardHrThreadLookup.php`
- Create: `src/Services/Comms/Forward/ForwardFirstContact.php`
- Test: `tests/Integration/ForwardFirstContactTest.php`

**Interfaces:**
- Consumes: `RecConversationForward` (Task 1); bestehend: `RecruitingChannelResolver::channelIds(int)`, `HoldingTemplateSender::resolveTemplate(int $teamId, int $templateId): array{error, template, channel}`, `ApplicantTemplateSender::send(CommsWhatsAppThread, int $templateId, ?int $applicantId, mixed $sender, ?string $firstName): array{ok, error}`, `CommsWhatsAppThread::findOrCreateForPhone(CommsChannel, string)`, `HasThreadContexts::addContext(string, int, ?string)`, `DispoTimeCalculator::isReplyWindowOpen(?DateTimeInterface, DateTimeInterface)`.
- Produces:
  - `ForwardHrThreadLookup::find(int $teamId, string $phone): ?CommsWhatsAppThread`. Sucht auf allen Recruiting-Kanälen nach `remote_phone_number IN ['+'.ziffern, ziffern]`, der Thread mit dem jüngsten Eingang gewinnt.
  - `ForwardFirstContact::TEMPLATE_NAME = 't_com_gen'`
  - `ForwardFirstContact::__construct(HoldingTemplateSender $targets, ApplicantTemplateSender $sender, ForwardHrThreadLookup $lookup)`
  - `ForwardFirstContact::send(RecConversationForward $forward, ?object $user): array{ok: bool, error: ?string}`
  - `ForwardFirstContact::windowOpen(RecConversationForward $forward, ?\DateTimeInterface $now = null): ?CommsWhatsAppThread`. Liefert den HR-Thread, wenn dort das 24h-Fenster offen ist, sonst `null`.
  - `ForwardFirstContact::firstNameFor(RecConversationForward $forward): string`. Liefert `''`, wenn kein MA zugeordnet ist oder der Vorname leer ist.

- [ ] **Step 1: Write the failing test**

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
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;
use Platform\Recruiting\Services\Comms\Forward\ForwardFirstContact;
use Platform\Recruiting\Services\Comms\Forward\ForwardHrThreadLookup;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Erstnachricht aus der Weiterleitung (Spec 02.10.2026). Versand-Attrappe nach
 * Muster ApplicantTemplateSenderAccountScopeTest. HoldingTemplateSender wird
 * mit einem konstruktorlosen WhatsAppMetaService gebaut — resolveTemplate()
 * fasst den Dienst nicht an.
 */
class ForwardFirstContactTest extends TestCase
{
    private const TEAM = 713;
    private const PHONE = '+4917663854907';

    private static int $channelId = 0;
    private static int $templateId = 0;
    private object $stub;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $int = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_03_20_000001_create_comms_thread_contexts_table.php'],
            [$int, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$int, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$root, $rel]) {
            (require $root . '/' . $rel)->up();
        }

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-forward', 'phone_number' => '+49 160 5559713',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559713', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);
        self::$templateId = (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-fwd', 'external_id' => 'ext-fwd', 'whatsapp_account_id' => $accountId, 'user_id' => 1,
            'name' => 't_com_gen', 'language' => 'de', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([[
                'type' => 'BODY', 'text' => 'Hallo {{name}}, hier ist die Personalabteilung.',
                'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Hans']]],
            ]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
        Capsule::table('comms_thread_contexts')->delete();
        Capsule::table('rec_employees')->delete();

        $this->stub = new class {
            public int $calls = 0;
            public string $status = 'sent';
            public array $letzteComponents = [];
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null): object
            {
                $this->calls++;
                $this->letzteComponents = $components;
                return (object) ['id' => 9000 + $this->calls, 'status' => $this->status, 'meta_payload' => ['error' => ['message' => '131026']]];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    private function service(): ForwardFirstContact
    {
        $meta = (new \ReflectionClass(WhatsAppMetaService::class))->newInstanceWithoutConstructor();

        return new ForwardFirstContact(new HoldingTemplateSender($meta), new ApplicantTemplateSender(), new ForwardHrThreadLookup());
    }

    private function forward(?int $employeeId, int $teamId = self::TEAM): RecConversationForward
    {
        return RecConversationForward::create([
            'team_id' => $teamId, 'source_thread_id' => 1, 'rec_employee_id' => $employeeId,
            'phone' => self::PHONE, 'display_name' => 'Jonas Stein',
            'messages' => [['message_id' => 1, 'body' => 'Gehalt?', 'media_type' => null, 'received_at' => '']],
            'forwarded_at' => now(),
        ]);
    }

    private function employee(string $firstName = 'Jonas'): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => $firstName, 'last_name' => 'Stein',
            'personnel_number' => 'MA1', 'is_active' => true,
        ])->id;
    }

    private function hrThread(string $phone, ?string $lastInbound): int
    {
        return (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-' . bin2hex(random_bytes(6)), 'comms_channel_id' => self::$channelId,
            'remote_phone_number' => $phone, 'is_unread' => false, 'last_inbound_at' => $lastInbound,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sendet_vorlage_legt_hr_thread_an_und_schreibt_datensatz_fort(): void
    {
        $f = $this->forward($this->employee());

        $r = $this->service()->send($f, (object) ['id' => 3, 'name' => 'Clara']);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(1, $this->stub->calls);
        $f->refresh();
        $this->assertNotNull($f->first_contact_at);
        $this->assertSame(3, (int) $f->first_contact_by_user_id);
        $thread = CommsWhatsAppThread::findOrFail($f->target_thread_id);
        $this->assertSame(self::$channelId, (int) $thread->comms_channel_id);
        $this->assertSame(self::PHONE, $thread->remote_phone_number);
        $this->assertSame(1, Capsule::table('comms_thread_contexts')
            ->where('thread_id', $thread->id)->where('context_model', (new RecEmployee())->getMorphClass())->count());
        $this->assertStringContainsString('Jonas', json_encode($this->stub->letzteComponents));
    }

    public function test_vorhandener_hr_thread_ohne_plus_wird_wiederverwendet(): void
    {
        $existing = $this->hrThread('4917663854907', null);
        $f = $this->forward($this->employee());

        $this->service()->send($f, null);

        $this->assertSame($existing, (int) $f->fresh()->target_thread_id);
        $this->assertSame(1, Capsule::table('comms_whatsapp_threads')->count());
    }

    public function test_ohne_ma_wird_nicht_gesendet(): void
    {
        $f = $this->forward(null);

        $r = $this->service()->send($f, null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Kein MA zugeordnet – die Vorlage braucht den Vornamen.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
        $this->assertNull($f->fresh()->first_contact_at);
    }

    public function test_abgelehnter_versand_setzt_nichts_und_merkt_fehler(): void
    {
        $this->stub->status = 'failed';
        $f = $this->forward($this->employee());

        $r = $this->service()->send($f, null);

        $this->assertFalse($r['ok']);
        $f->refresh();
        $this->assertNull($f->first_contact_at);
        $this->assertNull($f->target_thread_id);
        $this->assertStringContainsString('131026', (string) $f->last_error);
    }

    public function test_zweiter_aufruf_sendet_nicht_erneut(): void
    {
        $f = $this->forward($this->employee());
        $this->service()->send($f, null);

        $r = $this->service()->send($f->fresh(), null);

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->stub->calls);
    }

    public function test_erledigte_weiterleitung_sendet_nicht(): void
    {
        $f = $this->forward($this->employee());
        $f->update(['done_at' => now()]);

        $this->assertFalse($this->service()->send($f->fresh(), null)['ok']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_window_open_nur_bei_eingang_der_letzten_24h(): void
    {
        $f = $this->forward($this->employee());
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');

        $this->assertNull($this->service()->windowOpen($f, $now));

        $id = $this->hrThread(self::PHONE, '2026-10-02 09:00:00');
        $this->assertSame($id, (int) $this->service()->windowOpen($f, $now)?->id);

        Capsule::table('comms_whatsapp_threads')->where('id', $id)->update(['last_inbound_at' => '2026-09-30 09:00:00']);
        $this->assertNull($this->service()->windowOpen($f, $now));
    }

    public function test_open_for_team_zeigt_keine_fremden(): void
    {
        $this->forward(null, self::TEAM + 1);

        $this->assertSame(0, RecConversationForward::query()->openForTeam(self::TEAM)->count());
    }
}
```

> Hinweis: Hat `rec_employees` weitere Pflichtspalten (NOT NULL ohne Default), ergänzt sie im Insert nach der Migration `2026_05_20_000001_create_rec_employees_table.php`. Prüft außerdem, ob `isReplyWindowOpen` in einer Zeitzone rechnet, die das feste `$now` verfälscht. Falls ja, die Zeitpunkte im Test so wählen, dass sie eindeutig innerhalb bzw. außerhalb der 24 h liegen.

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ForwardFirstContactTest`
Expected: ERROR „Class … ForwardFirstContact not found"

- [ ] **Step 3: Implement lookup**

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Vorhandener HR-Thread zur Nummer eines MA. Meta liefert die Nummer mal als
 * "+49…", mal als nackte wa_id "49…" — verglichen wird deshalb beides, sonst
 * legt findOrCreateForPhone() einen zweiten Thread derselben Person an.
 */
final class ForwardHrThreadLookup
{
    public function find(int $teamId, string $phone): ?CommsWhatsAppThread
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $channelIds = RecruitingChannelResolver::channelIds($teamId);
        if ($digits === '' || $channelIds === []) {
            return null;
        }

        return CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
            ->whereIn('remote_phone_number', ['+' . $digits, $digits])
            ->orderByDesc('last_inbound_at')
            ->orderByDesc('id')
            ->first();
    }
}
```

- [ ] **Step 4: Implement first contact**

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;

/**
 * Erstnachricht aus einer Weiterleitung (Spec 02.10.2026): t_com_gen ueber die
 * HR-Nummer. Kanal kommt aus HoldingTemplateSender::resolveTemplate(),
 * Versand + Kontopruefung + {{name}} aus ApplicantTemplateSender — keine
 * dritte Kopie dieser Regeln.
 *
 * Der neue HR-Thread steht erst in der Chat-Liste, wenn der MA antwortet
 * (InboxQuery filtert auf last_inbound_at) — bis dahin zeigt ihn der Reiter
 * "Weitergeleitet".
 */
final class ForwardFirstContact
{
    public const TEMPLATE_NAME = 't_com_gen';

    public function __construct(
        private readonly HoldingTemplateSender $targets,
        private readonly ApplicantTemplateSender $sender,
        private readonly ForwardHrThreadLookup $lookup,
    ) {}

    /** @return array{ok: bool, error: ?string} */
    public function send(RecConversationForward $forward, ?object $user): array
    {
        if (!$forward->isOpen()) {
            return ['ok' => false, 'error' => 'Diese Weiterleitung ist schon erledigt.'];
        }
        if ($forward->first_contact_at !== null) {
            return ['ok' => false, 'error' => 'Die Erstnachricht wurde schon gesendet.'];
        }

        $firstName = $this->firstNameFor($forward);
        if ($firstName === '') {
            return ['ok' => false, 'error' => 'Kein MA zugeordnet – die Vorlage braucht den Vornamen.'];
        }

        $teamId = (int) $forward->team_id;
        $template = $this->template($teamId);
        if ($template === null) {
            return ['ok' => false, 'error' => 'Vorlage „Gespräch starten" (t_com_gen) ist nicht freigegeben.'];
        }

        $target = $this->targets->resolveTemplate($teamId, (int) $template->id);
        if ($target['error'] !== null || !$target['channel'] instanceof CommsChannel) {
            return ['ok' => false, 'error' => (string) ($target['error'] ?? 'Kein HR-Kanal gefunden.')];
        }

        $digits = preg_replace('/\D+/', '', (string) $forward->phone) ?? '';
        $thread = $this->lookup->find($teamId, (string) $forward->phone)
            ?? CommsWhatsAppThread::findOrCreateForPhone($target['channel'], '+' . $digits);

        $result = $this->sender->send($thread, (int) $template->id, null, $user, $firstName);
        if (!$result['ok']) {
            $forward->update(['last_error' => mb_substr((string) $result['error'], 0, 500)]);

            return ['ok' => false, 'error' => $result['error']];
        }

        // Ab hier ist die WhatsApp raus — der Kontext ist Komfort, kein Erfolgskriterium.
        if ($forward->rec_employee_id) {
            try {
                $thread->addContext((new RecEmployee())->getMorphClass(), (int) $forward->rec_employee_id, 'dispo_forward');
            } catch (\Throwable $e) {
                Log::warning('[DispoForward] Thread-Kontext fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), ['forward_id' => $forward->id]);
            }
        }

        $forward->update([
            'target_thread_id' => (int) $thread->id,
            'first_contact_at' => now(),
            'first_contact_by_user_id' => isset($user->id) ? (int) $user->id : null,
            'last_error' => null,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** HR-Thread mit offenem 24h-Fenster — dann braucht es keine Vorlage. */
    public function windowOpen(RecConversationForward $forward, ?\DateTimeInterface $now = null): ?CommsWhatsAppThread
    {
        $thread = $this->lookup->find((int) $forward->team_id, (string) $forward->phone);
        if ($thread === null || !DispoTimeCalculator::isReplyWindowOpen($thread->last_inbound_at, $now ?? now())) {
            return null;
        }

        return $thread;
    }

    public function firstNameFor(RecConversationForward $forward): string
    {
        if (!$forward->rec_employee_id) {
            return '';
        }

        return trim((string) (RecEmployee::query()->whereKey($forward->rec_employee_id)->value('first_name') ?? ''));
    }

    private function template(int $teamId): ?IntegrationsWhatsAppTemplate
    {
        if (!class_exists(IntegrationsWhatsAppTemplate::class)) {
            return null;
        }
        $accountId = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('auto_pilot_wa_account_id');
        $query = IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->where('name', self::TEMPLATE_NAME);
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderByDesc('id')->first();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ForwardFirstContactTest`
Expected: PASS (8 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Services/Comms/Forward/ForwardHrThreadLookup.php src/Services/Comms/Forward/ForwardFirstContact.php tests/Integration/ForwardFirstContactTest.php
git commit -m "feat(recruiting): Erstnachricht aus HR-Weiterleitung ueber die HR-Nummer

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Dispo-Oberfläche — Symbol, Fenster, Vermerk, Chip

**Files:**
- Modify: `src/Services/Zas/Dispo/DispoThreadDirectory.php:316-329` (`messages()`-Zeilen: `id`, `ts` ergänzen)
- Modify: `resources/views/livewire/dispo/_messages.blade.php` (Zeilenart `note`, Symbol bei `$forwardable`)
- Modify: `src/Livewire/Dispo/Conversations/Index.php` (Properties, `messages()`, `threads()`, neue Methoden)
- Modify: `resources/views/livewire/dispo/conversations/index.blade.php` (Include-Parameter, Fenster, Chip)
- Modify: `tests/Integration/DispoThreadDirectoryTest.php` (`id`/`ts` absichern)

**Interfaces:**
- Consumes: `ForwardMessageSelection::candidates/sanitize`, `ForwardStatus::latest` (Task 2), `ConversationForwarder::forward` (Task 3).
- Produces: `DispoThreadDirectory::messages()` liefert je Zeile zusätzlich `'id' => int` und `'ts' => int` (Unix). Das Partial akzeptiert optional `$forwardable` (bool, Standard `false`) und Zeilen mit `kind === 'note'` (`body`, `time`, `day`, `day_label`).

- [ ] **Step 1: Failing test für `id`/`ts`**

In `tests/Integration/DispoThreadDirectoryTest.php` einen Test ergänzen. Für Thread und Nachricht dieselben Helfer nutzen wie die bestehenden Tests der Datei. Falls es dort keinen Nachrichten-Helfer gibt, direkt in `comms_whatsapp_messages` einfügen:

```php
    public function test_messages_liefern_id_und_zeitstempel(): void
    {
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 999 000001', null, false, '2026-10-01 10:00:00');
        $msgId = (int) Capsule::table('comms_whatsapp_messages')->insertGetId([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'inbound', 'body' => 'Hallo',
            'message_type' => 'text', 'created_at' => '2026-10-01 19:24:00', 'updated_at' => '2026-10-01 19:24:00',
        ]);

        $rows = $this->directory()->messages(CommsWhatsAppThread::findOrFail($threadId), []);

        $this->assertSame($msgId, $rows[0]['id']);
        $this->assertSame((new \DateTimeImmutable('2026-10-01 19:24:00'))->getTimestamp(), $rows[0]['ts']);
    }
```

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter test_messages_liefern_id_und_zeitstempel`
Expected: FAIL (`Undefined array key "id"`)

- [ ] **Step 2: `DispoThreadDirectory::messages()` erweitern**

Im `return [`-Block der Map (ab `'direction' => …`) die zwei Schlüssel an den Anfang setzen:

```php
            return [
                'id'             => (int) $m->id,
                'ts'             => $at ? $at->getTimestamp() : 0,
                'direction'      => (string) $m->direction,
```

Run: dieselbe Test-Zeile. Expected: PASS. Danach die ganze Datei laufen lassen (`--filter DispoThreadDirectoryTest`), Expected: PASS.

- [ ] **Step 3: Partial erweitern**

In `resources/views/livewire/dispo/_messages.blade.php`:

a) Kopfkommentar und `@php`-Block ergänzen:

```blade
{{-- Partial: Nachrichten-Verlauf eines Threads. Erwartet $messages (list) und
     optional $portalUrl (Link zur Einsatz-Seite an Vorlagen-Karten) sowie
     $forwardable (Weiterleiten-Symbol an eingehenden Blasen, nur Dispo-Chat;
     ruft openForward(id) der einbindenden Komponente). Zeilen mit kind "note"
     sind interne Vermerke (grau, mittig). --}}
@php
    $portalUrl = $portalUrl ?? null;
    $forwardable = $forwardable ?? false;
    $lastDay = null;
@endphp
```

b) Direkt nach dem Tagestrenner-`@endif` und **vor** `@if ($message['kind'] === 'template')` eine eigene Verzweigung für Vermerke einfügen. Dazu das bestehende `@if ($message['kind'] === 'template')` in `@elseif` umwandeln:

```blade
    @if (($message['kind'] ?? '') === 'note')
        <div class="my-1 self-center rounded-lg bg-gray-100 px-3 py-1 text-center text-[11.5px] text-gray-500">
            {{ $message['body'] }} · {{ $message['time'] }}
        </div>
    @elseif ($message['kind'] === 'template')
```

c) In der Text-Verzweigung (`@else`) die eingehende Blase mit dem Symbol versehen. Den äußeren `<div class="flex max-w-[85%] flex-col …">` so ersetzen, dass Blase und Symbol in einer Zeile stehen:

```blade
    @else
        @php
            $isInbound = $message['direction'] !== 'outbound';
            $showForward = $forwardable && $isInbound && isset($message['id']);
        @endphp
        <div class="group flex max-w-[85%] flex-col gap-0.5 lg:max-w-[68%] {{ $isInbound ? 'self-start' : 'self-end items-end' }}">
            <div class="flex items-center gap-1.5">
```

Den bestehenden Blasen-`<div class="whitespace-pre-line rounded-2xl …">…</div>` unverändert lassen und **danach**, vor dem schließenden `</div>` der neuen Zeile, einfügen:

```blade
                @if ($showForward)
                    <button type="button" wire:click="openForward({{ (int) $message['id'] }})"
                            title="An HR weiterleiten"
                            class="grid h-7 w-7 shrink-0 place-items-center rounded-full border border-gray-200 bg-white text-gray-500 hover:border-blue-300 hover:text-blue-700 lg:opacity-0 lg:group-hover:opacity-100 lg:focus:opacity-100">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l6 6-6 6"/><path d="M21 11H9a6 6 0 0 0-6 6v2"/></svg>
                    </button>
                @endif
            </div>
```

Die Zeitzeile (`<div class="px-1 text-[11px] …">{{ $message['time'] }}…</div>`) bleibt darunter im äußeren Container. Ergebnis: Am Handy (`< lg`) ist das Symbol immer sichtbar, am Desktop erscheint es beim Drüberfahren.

- [ ] **Step 4: Blade prüfen**

Run: `php tools/blade-check.php resources/views/livewire/dispo/_messages.blade.php`
Expected: OK, keine Fehler.
Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'MessagesPartialIncludeContractTest|SharedPartialContractTest'`
Expected: PASS

- [ ] **Step 5: Komponente erweitern**

In `src/Livewire/Dispo/Conversations/Index.php`:

a) Imports ergänzen:

```php
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;
use Platform\Recruiting\Services\Comms\Forward\ForwardMessageSelection;
use Platform\Recruiting\Services\Comms\Forward\ForwardStatus;
```

b) Properties nach `$sendError`:

```php
    /** Weiterleiten-Fenster: angeklickte Nachricht (null = zu). Auswahl wird serverseitig gesaeubert. */
    public ?int $forwardMessageId = null;
    /** @var list<int> */
    public array $forwardSelection = [];
    public string $forwardComment = '';
    public ?string $forwardError = null;
```

c) `messages()` so ändern, dass die Vermerke einsortiert werden:

```php
    #[Computed]
    public function messages(): array
    {
        $thread = $this->selected;
        if ($thread === null) {
            return [];
        }

        $rows = app(DispoThreadDirectory::class)->messages($thread, $this->templateLabels);

        // Vermerke "an HR weitergeleitet" zeitlich einsortieren (Spec 02.10.2026).
        foreach (RecConversationForward::query()->where('source_thread_id', $thread->id)->get() as $f) {
            $at = $f->forwarded_at;
            $wer = $f->forwarded_by_name ? ' · ' . $f->forwarded_by_name : '';
            $rows[] = [
                'id' => null, 'ts' => $at->getTimestamp(), 'kind' => 'note', 'direction' => 'note',
                'body' => ($f->done_at ? 'An HR weitergeleitet (erledigt)' : 'An HR weitergeleitet') . $wer,
                'time' => $at->format('H:i'), 'day' => $at->format('Y-m-d'),
                'day_label' => DispoThreadDirectory::dayLabel(\Illuminate\Support\Carbon::instance($at)),
                'status' => null, 'media_type' => null, 'attachments' => [],
                'template_label' => null, 'template_buttons' => [], 'at' => $at->format('d.m.Y H:i'),
            ];
        }
        usort($rows, static fn (array $a, array $b) => [$a['ts'], $a['kind'] === 'note' ? 1 : 0] <=> [$b['ts'], $b['kind'] === 'note' ? 1 : 0]);

        return $rows;
    }
```

d) Chip-Zustand in `threads()`: Vor `return $rows->map(…)` die Zustände laden …

```php
        $forwardStates = [];
        $fwdRows = RecConversationForward::query()
            ->whereIn('source_thread_id', $rows->pluck('id')->all())
            ->get(['source_thread_id', 'forwarded_at', 'done_at']);
        foreach ($fwdRows->groupBy('source_thread_id') as $tid => $group) {
            $forwardStates[(int) $tid] = ForwardStatus::latest($group->map(fn ($f) => [
                'forwarded_at' => $f->forwarded_at->getTimestamp(), 'done' => $f->done_at !== null,
            ])->values()->all());
        }
```

… `$forwardStates` in das `use (…)` der Closure aufnehmen und im Rückgabe-Array ergänzen:

```php
                'forward'     => $forwardStates[(int) $t->id] ?? null, // open | done | null
```

e) Neue Methoden (nach `markUnreadAndClose`):

```php
    /** @return list<array{id:int, body:string, media_type:?string, at:string}> Auswahlliste des offenen Fensters */
    #[Computed]
    public function forwardCandidates(): array
    {
        if ($this->forwardMessageId === null) {
            return [];
        }
        $byId = collect($this->messages)->filter(fn ($m) => $m['id'] !== null)->keyBy('id');
        $ids = ForwardMessageSelection::candidates(
            $byId->map(fn ($m) => ['id' => (int) $m['id'], 'direction' => $m['direction'], 'kind' => $m['kind'], 'ts' => (int) $m['ts']])->values()->all(),
            $this->forwardMessageId,
            time(),
        );

        return array_map(fn (int $id) => [
            'id' => $id,
            'body' => (string) $byId[$id]['body'],
            'media_type' => $byId[$id]['media_type'],
            'at' => (string) $byId[$id]['at'],
        ], $ids);
    }

    public function openForward(int $messageId): void
    {
        $this->forwardMessageId = $messageId;
        $this->forwardComment = '';
        $this->forwardError = null;
        unset($this->forwardCandidates);
        if ($this->forwardCandidates === []) {
            $this->forwardMessageId = null;
            $this->sendError = 'Diese Nachricht kann nicht weitergeleitet werden.';
            return;
        }
        $this->forwardSelection = [$messageId];
    }

    public function closeForward(): void
    {
        $this->forwardMessageId = null;
        $this->forwardSelection = [];
        $this->forwardComment = '';
        $this->forwardError = null;
    }

    public function submitForward(): void
    {
        $thread = $this->selected;
        if ($thread === null || $this->forwardMessageId === null) {
            $this->closeForward();
            return;
        }

        $ids = ForwardMessageSelection::sanitize(
            $this->forwardSelection,
            array_column($this->forwardCandidates, 'id'),
        );
        if ($ids === []) {
            $this->forwardError = 'Bitte mindestens eine Nachricht auswählen.';
            return;
        }

        $info = $this->selectedInfo;
        $r = app(ConversationForwarder::class)->forward(
            (int) auth()->user()->currentTeam->id,
            $thread,
            $ids,
            $this->forwardComment,
            $info['employee_id'] ?? null,
            (string) ($info['label'] ?? ''),
            auth()->user(),
        );
        if (!$r['ok']) {
            $this->forwardError = $r['error'];
            return;
        }

        $this->closeForward();
        unset($this->threads, $this->messages);
        $this->dispatch('sidebar-refresh');
    }
```

f) In `back()` und `select()` zusätzlich `$this->closeForward();` aufrufen, damit ein offenes Fenster nicht zum nächsten Chat mitwandert.

- [ ] **Step 6: View der Dispo-Seite**

In `resources/views/livewire/dispo/conversations/index.blade.php`:

a) Include (Zeile ~232) um `'forwardable' => true` ergänzen:

```blade
                        @include('recruiting::livewire.dispo._messages', ['messages' => $messages, 'portalUrl' => $info['portal_url'], 'forwardable' => true])
```

b) Chip in der Thread-Liste. Im Chip-Block nach dem `alte Nummer`-`@endif` einfügen:

```blade
                                    @if ($thread['forward'] === 'open')
                                        <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-violet-700">bei HR</span>
                                    @elseif ($thread['forward'] === 'done')
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-500">HR erledigt</span>
                                    @endif
```

c) Das Fenster als Overlay ans Ende des Thread-Bereichs, vor dessen schließendem `</div>`. Es ist ein reines Tailwind-Overlay ohne `x-ui-modal`, damit keine Attribut-Fallen entstehen:

```blade
                    @if ($forwardMessageId !== null)
                        <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/30 p-0 sm:items-center sm:p-4" wire:key="fwd-{{ $forwardMessageId }}">
                            <div class="flex max-h-[90vh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:max-w-lg sm:rounded-2xl">
                                <div class="border-b border-gray-200 px-4 py-3">
                                    <div class="text-sm font-semibold text-gray-900">An HR weiterleiten</div>
                                    <div class="text-xs text-gray-500">Landet in Kommunikation → Weitergeleitet. Der MA bekommt nichts.</div>
                                </div>
                                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-3">
                                    @foreach ($this->forwardCandidates as $cand)
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-2 text-sm hover:bg-gray-50">
                                            <input type="checkbox" value="{{ $cand['id'] }}" wire:model="forwardSelection" class="mt-0.5 rounded border-gray-300">
                                            <span class="min-w-0">
                                                <span class="block text-[11px] text-gray-400 tabular-nums">{{ $cand['at'] }}</span>
                                                <span class="block whitespace-pre-line text-gray-800">
                                                    @if ($cand['media_type'])
                                                        📎 {{ ucfirst($cand['media_type']) }}
                                                    @endif
                                                    {{ $cand['body'] }}
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                    <textarea wire:model="forwardComment" rows="2" maxlength="1000" placeholder="Kommentar für HR (optional)"
                                              class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                                    @if ($forwardError)
                                        <div class="text-xs font-semibold text-red-600">{{ $forwardError }}</div>
                                    @endif
                                </div>
                                <div class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3">
                                    <button type="button" wire:click="closeForward" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-600 hover:bg-gray-50">Abbrechen</button>
                                    <button type="button" wire:click="submitForward" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">Weiterleiten</button>
                                </div>
                            </div>
                        </div>
                    @endif
```

- [ ] **Step 7: Prüfen**

Run: `php tools/blade-check.php resources/views/livewire/dispo/conversations/index.blade.php`
Expected: OK
Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: Alle Tests grün. Die Zahl der Tests liegt um die neuen über dem Stand vor Task 1.

- [ ] **Step 8: Commit**

```bash
git add src/Services/Zas/Dispo/DispoThreadDirectory.php resources/views/livewire/dispo/_messages.blade.php src/Livewire/Dispo/Conversations/Index.php resources/views/livewire/dispo/conversations/index.blade.php tests/Integration/DispoThreadDirectoryTest.php
git commit -m "feat(recruiting): Weiterleiten-Symbol an Dispo-Nachrichten mit Vermerk und HR-Chip

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: HR-Oberfläche — Reiter „Weitergeleitet", interne Karte, Zähler

**Files:**
- Create: `src/Livewire/Conversations/Forwards.php`
- Create: `resources/views/livewire/conversations/forwards.blade.php`
- Modify: `src/Livewire/Conversations/Inbox.php` (Property `$showForwards`, `toggleForwardsView()`, `#[On('forward-open-thread')]`, `forwardCards()`, `openForwardCount()`)
- Modify: `resources/views/livewire/conversations/inbox.blade.php` (Pille, Umschalten, Karte über dem Verlauf)
- Modify: `src/Livewire/Sidebar.php` + `resources/views/livewire/sidebar.blade.php` (Zähler)

**Interfaces:**
- Consumes: `RecConversationForward::openForTeam` (Task 1), `ForwardFirstContact::send/windowOpen/firstNameFor` (Task 4), `DispoThreadDirectory::messages()` (für den HR-Verlauf in der Detailansicht).
- Produces: Livewire-Alias `recruiting.conversations.forwards` (Auto-Registrierung über `RecruitingServiceProvider::registerLivewireComponents`). Das Event `forward-open-thread` mit `threadId: int` wird von `Inbox` empfangen.

- [ ] **Step 1: Komponente `Forwards`**

```php
<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\Forward\ForwardFirstContact;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * Reiter "Weitergeleitet" der HR-Kommunikation (Spec 02.10.2026): offene
 * Weiterleitungen aus der Dispo, Erstnachricht, Erledigt.
 *
 * Jeder Zugriff laeuft ueber forwardForTeam() — Livewire-Methoden sind mit
 * beliebigen IDs aufrufbar.
 */
class Forwards extends Component
{
    public ?int $selectedId = null;
    public ?string $actionError = null;

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    private function forwardForTeam(int $id): ?RecConversationForward
    {
        return RecConversationForward::query()->forTeam($this->teamId())->whereKey($id)->first();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function rows(): array
    {
        $rows = RecConversationForward::query()->openForTeam($this->teamId())
            ->orderByDesc('forwarded_at')->orderByDesc('id')->get();
        $pnrs = RecEmployee::query()->whereIn('id', $rows->pluck('rec_employee_id')->filter()->all())
            ->pluck('personnel_number', 'id');

        return $rows->map(fn (RecConversationForward $f) => [
            'id' => (int) $f->id,
            'name' => (string) $f->display_name,
            'phone' => (string) $f->phone,
            'pnr' => $f->rec_employee_id ? (string) ($pnrs[$f->rec_employee_id] ?? '') : '',
            'preview' => (string) ($f->messages[count($f->messages) - 1]['body'] ?? ''),
            'forwarded_at' => $f->forwarded_at->format('d.m. H:i'),
            'by' => (string) ($f->forwarded_by_name ?? ''),
            'first_contact' => $f->first_contact_at !== null,
        ])->all();
    }

    #[Computed]
    public function selected(): ?array
    {
        $f = $this->selectedId !== null ? $this->forwardForTeam($this->selectedId) : null;
        if ($f === null) {
            return null;
        }
        $service = app(ForwardFirstContact::class);
        $openThread = $f->first_contact_at === null ? $service->windowOpen($f) : null;
        $target = $f->target_thread_id ? CommsWhatsAppThread::find($f->target_thread_id) : null;

        return [
            'id' => (int) $f->id,
            'name' => (string) $f->display_name,
            'phone' => (string) $f->phone,
            'messages' => (array) $f->messages,
            'comment' => $f->comment,
            'by' => (string) ($f->forwarded_by_name ?? ''),
            'forwarded_at' => $f->forwarded_at->format('d.m.Y H:i'),
            'first_contact_at' => $f->first_contact_at?->format('d.m.Y H:i'),
            'last_error' => $f->last_error,
            'can_send' => $service->firstNameFor($f) !== '',
            'open_thread_id' => $openThread?->id ? (int) $openThread->id : null,
            'hr_messages' => $target ? app(DispoThreadDirectory::class)->messages($target, []) : [],
            'target_listed' => $target !== null && $target->last_inbound_at !== null,
            'target_thread_id' => $target?->id ? (int) $target->id : null,
        ];
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->actionError = null;
        unset($this->selected);
    }

    public function back(): void
    {
        $this->selectedId = null;
        $this->actionError = null;
    }

    public function sendFirstContact(int $id): void
    {
        $this->actionError = null;
        $f = $this->forwardForTeam($id);
        if ($f === null) {
            return;
        }
        $r = app(ForwardFirstContact::class)->send($f, Auth::user());
        if (!$r['ok']) {
            $this->actionError = $r['error'];
        }
        unset($this->rows, $this->selected);
    }

    /** HR-Fenster offen: Chat uebernehmen statt Vorlage. */
    public function openChat(int $id): void
    {
        $f = $this->forwardForTeam($id);
        $thread = $f ? app(ForwardFirstContact::class)->windowOpen($f) : null;
        $threadId = $thread?->id ?? ($f?->target_thread_id);
        if ($f === null || $threadId === null) {
            $this->actionError = 'Kein offener HR-Chat gefunden.';
            return;
        }
        if ($f->target_thread_id === null) {
            $f->update(['target_thread_id' => (int) $threadId]);
        }
        $this->dispatch('forward-open-thread', threadId: (int) $threadId);
    }

    public function markDone(int $id): void
    {
        $f = $this->forwardForTeam($id);
        if ($f !== null && $f->isOpen()) {
            $f->update(['done_at' => now(), 'done_by_user_id' => (int) Auth::id()]);
        }
        $this->selectedId = null;
        unset($this->rows, $this->selected);
        $this->dispatch('sidebar-refresh');
        $this->dispatch('forwards-changed');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.forwards');
    }
}
```

- [ ] **Step 2: View `forwards.blade.php`**

```blade
<div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[360px_1fr]" wire:poll.30s>
    @php
        $rows = $this->rows;
        $sel = $this->selected;
    @endphp

    {{-- Liste --}}
    <div class="{{ $sel ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse ($rows as $row)
                <button type="button" wire:click="select({{ $row['id'] }})"
                        class="block w-full border-b border-gray-100 border-l-[3px] px-4 py-3 text-left hover:bg-gray-50 {{ ($sel['id'] ?? null) === $row['id'] ? 'border-l-blue-600 bg-blue-50/60' : 'border-l-transparent' }}">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold text-gray-900">{{ $row['name'] }}</span>
                        <span class="shrink-0 text-[11px] text-gray-400 tabular-nums">{{ $row['forwarded_at'] }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row['preview'] }}</span>
                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        @if ($row['pnr'] !== '')
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $row['pnr'] }}</span>
                        @endif
                        @if ($row['by'] !== '')
                            <span class="text-[10.5px] text-gray-400">von {{ $row['by'] }}</span>
                        @endif
                        @if ($row['first_contact'])
                            <span class="rounded bg-green-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-green-700">angeschrieben</span>
                        @else
                            <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-violet-700">neu</span>
                        @endif
                    </span>
                </button>
            @empty
                <div class="p-8 text-center text-sm text-gray-500">Keine offenen Weiterleitungen aus der Dispo.</div>
            @endforelse
        </div>
    </div>

    {{-- Detail --}}
    <div class="{{ $sel ? 'flex' : 'hidden lg:flex' }} min-h-0 flex-col bg-gray-50">
        @if ($sel === null)
            <div class="m-auto text-sm text-gray-500">Weiterleitung auswählen.</div>
        @else
            <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
                <button type="button" wire:click="back" class="lg:hidden text-sm text-blue-700">‹ zurück</button>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-gray-900">{{ $sel['name'] }}</div>
                    <div class="text-xs text-gray-500 tabular-nums">{{ $sel['phone'] }}</div>
                </div>
                <button type="button" wire:click="markDone({{ $sel['id'] }})" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Erledigt</button>
            </div>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                @include('recruiting::livewire.conversations._forward-card', ['card' => $sel])

                @if ($sel['hr_messages'] !== [])
                    <div class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Verlauf HR-Nummer</div>
                    <div class="flex flex-col gap-2">
                        @include('recruiting::livewire.dispo._messages', ['messages' => $sel['hr_messages'], 'portalUrl' => null])
                    </div>
                    @if (!$sel['target_listed'])
                        <div class="text-xs text-gray-500">Der Chat erscheint unter „Alle", sobald der MA antwortet.</div>
                    @endif
                @endif
            </div>

            <div class="border-t border-gray-200 bg-white px-4 py-3">
                @if ($sel['last_error'] || $actionError)
                    <div class="mb-2 text-xs font-semibold text-red-600">{{ $actionError ?? $sel['last_error'] }}</div>
                @endif
                @if ($sel['first_contact_at'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Erstnachricht gesendet am {{ $sel['first_contact_at'] }}.</span>
                        @if ($sel['target_listed'])
                            <button type="button" wire:click="openChat({{ $sel['id'] }})" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Chat öffnen</button>
                        @endif
                    </div>
                @elseif ($sel['open_thread_id'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Der MA hat der HR-Nummer in den letzten 24 h geschrieben – du kannst direkt antworten.</span>
                        <button type="button" wire:click="openChat({{ $sel['id'] }})" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Chat öffnen</button>
                    </div>
                @elseif ($sel['can_send'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Schickt „Gespräch starten" über die HR-Nummer.</span>
                        <button type="button" wire:click="sendFirstContact({{ $sel['id'] }})" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Erstnachricht senden</button>
                    </div>
                @else
                    <div class="text-xs text-gray-600">Kein MA zugeordnet – die Vorlage braucht den Vornamen. Bitte telefonisch klären und dann „Erledigt".</div>
                @endif
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 3: Gemeinsames Karten-Partial**

Create: `resources/views/livewire/conversations/_forward-card.blade.php`. Es wird im Reiter **und** über dem HR-Chat benutzt:

```blade
{{-- Partial: interne Karte einer Weiterleitung. Erwartet $card mit
     messages (list{body, media_type, received_at}), comment, by, forwarded_at.
     Der MA sieht diese Karte nie. --}}
<div class="rounded-xl border border-violet-200 bg-violet-50/60 p-3 text-sm">
    <div class="mb-1.5 text-[11px] font-semibold text-violet-700">
        Aus der Dispo weitergeleitet · {{ $card['forwarded_at'] }}
        @if (($card['by'] ?? '') !== '')
            · {{ $card['by'] }}
        @endif
    </div>
    @foreach ($card['messages'] as $fm)
        <div class="mb-1 whitespace-pre-line rounded-lg bg-white px-2.5 py-1.5 text-gray-800 shadow-sm">
            @if (!empty($fm['media_type']))
                📎 {{ ucfirst($fm['media_type']) }}
            @endif
            {{ $fm['body'] }}
        </div>
    @endforeach
    @if (!empty($card['comment']))
        <div class="mt-1.5 text-xs text-gray-700"><span class="font-semibold">Kommentar:</span> {{ $card['comment'] }}</div>
    @endif
</div>
```

- [ ] **Step 4: `Inbox` anbinden**

In `src/Livewire/Conversations/Inbox.php`:

```php
use Livewire\Attributes\On;
use Platform\Recruiting\Models\RecConversationForward;
```

Property nach `$showHandled`:

```php
    /** Reiter "Weitergeleitet" (Spec 02.10.2026) statt Chat-Liste. */
    public bool $showForwards = false;
```

Methoden (nach `toggleHandledView`):

```php
    public function toggleForwardsView(): void
    {
        $this->showForwards = !$this->showForwards;
        $this->selectedThreadId = null;
    }

    #[On('forward-open-thread')]
    public function openForwardThread(int $threadId): void
    {
        if ($this->threadForTeam($threadId) === null) {
            return;
        }
        $this->showForwards = false;
        $this->level = 'all';
        $this->showHandled = false;
        $this->select($threadId);
    }

    #[On('forwards-changed')]
    public function refreshForwardCount(): void
    {
        unset($this->openForwardCount);
    }

    #[Computed]
    public function openForwardCount(): int
    {
        return RecConversationForward::query()->openForTeam($this->teamId())->count();
    }

    /** @return list<array<string, mixed>> Weiterleitungen, die zu diesem HR-Chat gehoeren (interne Karten). */
    #[Computed]
    public function forwardCards(): array
    {
        if ($this->selectedThreadId === null) {
            return [];
        }

        return RecConversationForward::query()->forTeam($this->teamId())
            ->where('target_thread_id', $this->selectedThreadId)
            ->orderBy('forwarded_at')->get()
            ->map(fn (RecConversationForward $f) => [
                'messages' => (array) $f->messages, 'comment' => $f->comment,
                'by' => (string) ($f->forwarded_by_name ?? ''), 'forwarded_at' => $f->forwarded_at->format('d.m.Y H:i'),
            ])->all();
    }
```

In `setLevel()` und `toggleHandledView()` jeweils `$this->showForwards = false;` ergänzen.

- [ ] **Step 5: Inbox-View**

In `resources/views/livewire/conversations/inbox.blade.php`:

a) Nach dem „Erledigt"-Knopf (Zeile ~78) die Pille einfügen:

```blade
                <button type="button" wire:click="toggleForwardsView"
                        class="rounded-full border px-3 py-1 text-xs font-semibold {{ $showForwards ? 'border-gray-900 bg-gray-900 text-white' : 'border-violet-200 bg-white text-violet-700' }}">
                    Weitergeleitet {{ $this->openForwardCount }}
                </button>
```

b) Den Hauptbereich (das Grid, das bei Zeile ~169 mit der Liste beginnt und bis zum Ende des Chat-Bereichs reicht) in eine Weiche fassen:

```blade
    @if ($showForwards)
        <livewire:recruiting.conversations.forwards wire:key="forwards-view" />
    @else
        {{-- bestehendes Grid unverändert --}}
    @endif
```

Dabei das bestehende öffnende `<div class="grid …">` und sein schließendes `</div>` **innerhalb** des `@else` lassen. Vorher die Grenzen mit `grep -n '<div class="grid' resources/views/livewire/conversations/inbox.blade.php` finden.

c) Über dem Verlauf (vor dem `<div … wire:key="msgs-{{ $selectedThreadId }}-…">`, Zeile ~438) die Karten einfügen:

```blade
                @foreach ($this->forwardCards as $card)
                    <div class="px-3 pt-3 lg:px-5">
                        @include('recruiting::livewire.conversations._forward-card', ['card' => $card])
                    </div>
                @endforeach
```

- [ ] **Step 6: Seitenleiste**

In `src/Livewire/Sidebar.php::stats()` im Rückgabe-Array ergänzen:

```php
            'open_forwards' => \Platform\Recruiting\Models\RecConversationForward::query()->openForTeam($teamId)->count(),
```

In `resources/views/livewire/sidebar.blade.php` gibt es den Eintrag „Kommunikation" zweimal (Zeilen ~65 und ~161). In **beiden** wird der Zähler ergänzt. Das `@php`-Blockpaar erweitern um

```blade
                $openForwards = $this->stats['open_forwards'] ?? 0;
```

die Bedingung `@if($escalationConv > 0 || $unreadConv > 0)` zu `@if($escalationConv > 0 || $unreadConv > 0 || $openForwards > 0)` ändern und als erstes Element im `<span class="ml-auto …">` einfügen:

```blade
                    @if($openForwards > 0)
                        <span class="flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-violet-50 text-violet-600" title="aus der Dispo weitergeleitet">{{ $openForwards }}</span>
                    @endif
```

Vorher mit `sed -n 150,180p resources/views/livewire/sidebar.blade.php` prüfen, ob der zweite Eintrag dieselbe Struktur hat. Weicht sie ab, die Änderung sinngemäß übertragen.

- [ ] **Step 7: Prüfen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/forwards.blade.php resources/views/livewire/conversations/_forward-card.blade.php resources/views/livewire/conversations/inbox.blade.php resources/views/livewire/sidebar.blade.php`
(Nimmt das Tool nur eine Datei, nacheinander aufrufen.) Expected: OK
Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: Alle grün. `MessagesPartialIncludeContractTest` findet jetzt auch `forwards.blade.php` und prüft, dass `messages` + `portalUrl` mitgegeben werden.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Conversations/Forwards.php resources/views/livewire/conversations/forwards.blade.php resources/views/livewire/conversations/_forward-card.blade.php src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php src/Livewire/Sidebar.php resources/views/livewire/sidebar.blade.php
git commit -m "feat(recruiting): Reiter Weitergeleitet in der HR-Kommunikation mit Erstnachricht

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Abschluss — Volltest, Review, Übergabe

**Files:** keine neuen. Gegebenenfalls Fixes aus dem Review.

- [ ] **Step 1: Volle Suite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: Alles grün. Anzahl notieren.

- [ ] **Step 2: Alle Views prüfen**

Run: `php tools/blade-check.php`
Expected: keine Fehler.

- [ ] **Step 3: Review vor dem Merge**

Das ist außensichtbar (Versand an MA) und berührt Rechte/Teamgrenzen, deshalb Review-Agent vor dem Merge mit konkretem Auftrag:
- Team-Isolation aller `Forwards`-Methoden, IDs aus Livewire-Aufrufen.
- `submitForward` mit manipulierten `forwardSelection`/`forwardMessageId`.
- Doppelversand der Erstnachricht (zwei schnelle Klicks, zwei Tabs).
- Geteiltes Partial `_messages`: VA-Chat (`dispo/events/show`) und HR-Chat dürfen **kein** Symbol zeigen.
- Mobile: Fenster und Symbol am Handy bedienbar.

Funde selbst nachprüfen, berechtigte fixen und als eigene Commits einchecken.

- [ ] **Step 4: Übergabe an den User**

Branch `feat/dispo-hr-weiterleiten` pushen. Merge erst nach Freigabe (ff auf main). Danach meingedeck-Bump. Deploy-Liste: **`migrate` Pflicht**, `view:clear`, kein `queue:restart`. Sichttests: Symbol am Desktop/Handy, Weiterleiten mit Kommentar, Chip „bei HR", Reiter-Zähler, Erstnachricht an eine Testnummer, Chat erscheint nach Antwort mit Karte, „Erledigt" → Chip „HR erledigt".
