# FLYNK-Ausschreibungen-Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Veröffentlichte Ausschreibungen (`RecPosting`) als Tasks an den FLYNK-Webhook synchronisieren (publish / update / close / reopen), gesteuert durch ein geplantes Kommando.

**Architecture:** Ein geplantes Kommando `recruiting:flynk-reconcile` (30 Min) fährt pro Lauf: **Analyze → Revalidate(löschen) → Retry → Detect**. Die gesamte Entscheidungs- und Mapping-Logik liegt in **puren, DB-freien** Klassen (`FlynkPostingSyncDecider`, `FlynkPostingPayloadBuilder`, `FlynkResponseMapper`) und ist per PHPUnit unit-getestet. Eine Outbox-Tabelle `rec_posting_flynk_syncs` mit Unique-Index `(rec_posting_id, generation, event_type, seq)` verhindert Doppel-Sends; `content_hash` ist **nicht** Teil der Uniqueness. Der Reconciler, der HTTP-Client und das Kommando sind dünne Glue-Schicht.

**Tech Stack:** PHP 8.4, Laravel (via meingedeck-App), PHPUnit 11.5, Eloquent, `Illuminate\Support\Facades\Http`.

Vollständiger Design-Spec: `docs/superpowers/specs/2026-07-06-flynk-ausschreibungen-sync-design.md` (Rev. 3).

## Global Constraints

- **Namespace:** `Platform\Recruiting\` → `src/`. Services unter `src/Services/Flynk/`, Models unter `src/Models/`, Commands unter `src/Console/Commands/`.
- **Testkonvention:** Modul hat kein eigenes `vendor/` und kann **keine** DB-/Laravel-Feature-Tests. Nur pure `PHPUnit\Framework\TestCase` unter `tests/Unit/` (Namespace `Platform\Recruiting\Tests\Unit`). Glue (Migration, Model, Client, Reconciler, Command) wird **nicht** unit-getestet, sondern per `php -l` + manueller/Artisan-Verifikation abgesichert.
- **Testrunner (aus dem Modulverzeichnis):** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter <TestName>`
- **Event-Typen:** genau `publish` / `update` / `close` (Konstanten in `FlynkEvent`). FLYNK-`type`: publish→`new_section`, update/close→`text_change`.
- **seq-Allokation:** update-`seq` = `MAX(seq der Update-Zeilen der Generation) + 1` (nicht `count+1`) — sonst kollidiert nach einer mittigen `staleRows`-Löschung der neue `seq` mit einem bestehenden und `insertOrIgnore` blockiert die Emission still. publish/close immer `seq = 0`.
- **Fehler-Semantik:** 201 = sent; 401 = Lauf abbrechen, Zeile bleibt `pending` (NICHT permanent); 422 = `failed` + `permanent_failure=true`; 429 = Lauf beenden, Zeile `pending`; 5xx/Netzwerk = transient, Retry bis `max_attempts`.
- **Guard:** Kein Send, wenn `recruiting.flynk.enabled` falsy oder `recruiting.flynk.token` leer.
- **Commits:** Nach jedem Task committen.

## File Structure

**Create:**
- `src/Services/Flynk/FlynkEvent.php` — Event-Typ-Konstanten.
- `src/Services/Flynk/FlynkPostingState.php` — readonly DTO (Eingabe für `decide()`).
- `src/Services/Flynk/FlynkPostingSyncDecider.php` — pure: `decide()`, Row-Summary-Helfer, `buildState()`, `staleRowIds()`, `nextUpdateSeq()`.
- `src/Services/Flynk/FlynkTask.php` — readonly DTO (`payload` + `contentHash`).
- `src/Services/Flynk/FlynkPostingPayloadBuilder.php` — pure: `contentHash()`, `build()`.
- `src/Services/Flynk/FlynkResult.php` — readonly DTO (HTTP-Ergebnis).
- `src/Services/Flynk/FlynkResponseMapper.php` — pure: HTTP-Status → `FlynkResult`.
- `src/Services/Flynk/FlynkClient.php` — dünner Http-Wrapper.
- `src/Services/Flynk/FlynkPostingReconciler.php` — Glue-Orchestrierung.
- `src/Models/RecPostingFlynkSync.php` — Eloquent-Modell der Outbox.
- `src/Console/Commands/FlynkReconcile.php` — `recruiting:flynk-reconcile`.
- `database/migrations/2026_07_06_000001_create_rec_posting_flynk_syncs_table.php`
- `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php`
- `tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php`
- `tests/Unit/Flynk/FlynkResponseMapperTest.php`

**Modify:**
- `config/recruiting.php` — `flynk`-Block ergänzen.
- `src/RecruitingServiceProvider.php` — Command registrieren, `FlynkClient` binden, Schedule-Eintrag.

---

### Task 1: FlynkEvent-Konstanten + Config-Block

**Files:**
- Create: `src/Services/Flynk/FlynkEvent.php`
- Modify: `config/recruiting.php`
- Test: `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php` (angelegt, ein erster Test)

**Interfaces:**
- Produces: `FlynkEvent::PUBLISH`, `FlynkEvent::UPDATE`, `FlynkEvent::CLOSE` (string consts); `FlynkEvent::all(): string[]`.

- [ ] **Step 1: Test schreiben**

Datei `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkEvent;

class FlynkPostingSyncDeciderTest extends TestCase
{
    public function test_flynk_event_constants(): void
    {
        $this->assertSame(['publish', 'update', 'close'], FlynkEvent::all());
        $this->assertSame('publish', FlynkEvent::PUBLISH);
        $this->assertSame('update', FlynkEvent::UPDATE);
        $this->assertSame('close', FlynkEvent::CLOSE);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: FAIL ("Class ... FlynkEvent not found").

- [ ] **Step 3: FlynkEvent implementieren**

Datei `src/Services/Flynk/FlynkEvent.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkEvent
{
    public const PUBLISH = 'publish';
    public const UPDATE = 'update';
    public const CLOSE = 'close';

    /** @return string[] */
    public static function all(): array
    {
        return [self::PUBLISH, self::UPDATE, self::CLOSE];
    }
}
```

- [ ] **Step 4: Config-Block ergänzen**

In `config/recruiting.php` vor der schließenden `];` des Top-Level-Arrays einfügen (analog zum `zas`-Block):

```php
    /*
    |--------------------------------------------------------------------------
    | FLYNK-Sync (Ausschreibungen → Website-Tasks)
    |--------------------------------------------------------------------------
    |
    | Ausgehender Sync veröffentlichter Ausschreibungen an den FLYNK
    | Task-Webhook. Ohne enabled=true + token passiert nichts.
    | Siehe docs/superpowers/specs/2026-07-06-flynk-ausschreibungen-sync-design.md
    */
    'flynk' => [
        'enabled'      => (bool) env('RECRUITING_FLYNK_ENABLED', false),
        'base_url'     => env('RECRUITING_FLYNK_BASE_URL', 'https://flynk.on-forge.com/api'),
        'token'        => env('RECRUITING_FLYNK_TOKEN'),
        'careers_url'  => env('RECRUITING_FLYNK_CAREERS_URL'),
        'timeout'      => (int) env('RECRUITING_FLYNK_TIMEOUT', 10),
        'per_run_cap'  => (int) env('RECRUITING_FLYNK_PER_RUN_CAP', 50),
        'max_attempts' => (int) env('RECRUITING_FLYNK_MAX_ATTEMPTS', 5),
    ],
```

- [ ] **Step 5: Test laufen lassen — muss bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Flynk/FlynkEvent.php config/recruiting.php tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php
git commit -m "feat(flynk): FlynkEvent-Konstanten + Config-Block"
```

---

### Task 2: FlynkPostingState + Decider::decide()

**Files:**
- Create: `src/Services/Flynk/FlynkPostingState.php`
- Create: `src/Services/Flynk/FlynkPostingSyncDecider.php`
- Test: `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php` (erweitern)

**Interfaces:**
- Consumes: `FlynkEvent`.
- Produces:
  - `new FlynkPostingState(bool $isOpen, string $contentHash, int $generation, bool $publishRowExists, bool $publishSent, bool $closeRowExists, ?string $lastDeliverableContentHash)` (alle Properties `public readonly`, benannte Argumente).
  - `FlynkPostingSyncDecider::decide(FlynkPostingState $state): ?string` → `'publish'|'update'|'close'|null`.

- [ ] **Step 1: Failing-Tests schreiben**

In `FlynkPostingSyncDeciderTest.php` ergänzen (Helper + Tests):

```php
    private function state(array $o = []): \Platform\Recruiting\Services\Flynk\FlynkPostingState
    {
        return new \Platform\Recruiting\Services\Flynk\FlynkPostingState(
            isOpen: $o['isOpen'] ?? true,
            contentHash: $o['contentHash'] ?? 'A',
            generation: $o['generation'] ?? 1,
            publishRowExists: $o['publishRowExists'] ?? false,
            publishSent: $o['publishSent'] ?? false,
            closeRowExists: $o['closeRowExists'] ?? false,
            lastDeliverableContentHash: $o['lastDeliverableContentHash'] ?? null,
        );
    }

    public function test_open_without_publish_emits_publish(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state());
        $this->assertSame('publish', $d);
    }

    public function test_open_published_same_hash_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'A', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertNull($d);
    }

    public function test_open_published_changed_hash_emits_update(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertSame('update', $d);
    }

    public function test_hash_rollback_still_emits_update(): void
    {
        // A→B→C→B: contentHash=B, zuletzt geliefert=C ⇒ update (Uniqueness ist seq, nicht Hash)
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'C',
        ]));
        $this->assertSame('update', $d);
    }

    public function test_pending_publish_does_not_emit_second_publish(): void
    {
        // publishRowExists=true (pending), publishSent=false
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => false,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertNull($d); // kein zweiter publish, und kein update ohne publishSent
    }

    public function test_closed_after_publish_emits_close(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => true, 'publishSent' => true,
        ]));
        $this->assertSame('close', $d);
    }

    public function test_never_advertised_then_closed_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => false, 'publishSent' => false,
        ]));
        $this->assertNull($d);
    }

    public function test_already_closed_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => true, 'publishSent' => true,
            'closeRowExists' => true,
        ]));
        $this->assertNull($d);
    }

    public function test_reopen_new_generation_emits_publish(): void
    {
        // Gen 2: publishRowExists(Gen2)=false ⇒ erneuter publish
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => true, 'generation' => 2, 'publishRowExists' => false,
        ]));
        $this->assertSame('publish', $d);
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: FAIL ("Class ... FlynkPostingState not found").

- [ ] **Step 3: FlynkPostingState implementieren**

Datei `src/Services/Flynk/FlynkPostingState.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingState
{
    public function __construct(
        public readonly bool $isOpen,
        public readonly string $contentHash,
        public readonly int $generation,
        public readonly bool $publishRowExists,
        public readonly bool $publishSent,
        public readonly bool $closeRowExists,
        public readonly ?string $lastDeliverableContentHash,
    ) {
    }
}
```

- [ ] **Step 4: Decider::decide() implementieren**

Datei `src/Services/Flynk/FlynkPostingSyncDecider.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingSyncDecider
{
    public static function decide(FlynkPostingState $s): ?string
    {
        if ($s->isOpen && !$s->publishRowExists) {
            return FlynkEvent::PUBLISH;
        }
        if ($s->isOpen && $s->publishSent && $s->contentHash !== $s->lastDeliverableContentHash) {
            return FlynkEvent::UPDATE;
        }
        if (!$s->isOpen && $s->publishSent && !$s->closeRowExists) {
            return FlynkEvent::CLOSE;
        }
        return null;
    }
}
```

- [ ] **Step 5: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Flynk/FlynkPostingState.php src/Services/Flynk/FlynkPostingSyncDecider.php tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php
git commit -m "feat(flynk): Sync-Decider decide() + FlynkPostingState (pure)"
```

---

### Task 3: Decider Row-Summary-Helfer + buildState()

**Files:**
- Modify: `src/Services/Flynk/FlynkPostingSyncDecider.php`
- Test: `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php` (erweitern)

**Interfaces:**
- Row-Shape (überall im Modul verwendet): `array{id:int, generation:int, event_type:string, seq:int, content_hash:string, status:string}`.
- Produces:
  - `FlynkPostingSyncDecider::generation(array $rows): int`
  - `FlynkPostingSyncDecider::publishRowExists(array $rows, int $gen): bool`
  - `FlynkPostingSyncDecider::publishSent(array $rows, int $gen): bool`
  - `FlynkPostingSyncDecider::closeRowExists(array $rows, int $gen): bool`
  - `FlynkPostingSyncDecider::lastDeliverableContentHash(array $rows, int $gen): ?string`
  - `FlynkPostingSyncDecider::buildState(array $rows, bool $isOpen, string $contentHash): FlynkPostingState`

- [ ] **Step 1: Failing-Tests schreiben**

In `FlynkPostingSyncDeciderTest.php` ergänzen:

```php
    private function row(array $o): array
    {
        return [
            'id' => $o['id'] ?? 1,
            'generation' => $o['generation'] ?? 1,
            'event_type' => $o['event_type'],
            'seq' => $o['seq'] ?? 0,
            'content_hash' => $o['content_hash'] ?? '',
            'status' => $o['status'] ?? 'sent',
        ];
    }

    public function test_generation_counts_sent_closes_plus_one(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $this->assertSame(1, $D::generation([]));
        $this->assertSame(2, $D::generation([
            $this->row(['event_type' => 'close', 'status' => 'sent', 'generation' => 1]),
        ]));
        // ein pending close zählt NICHT
        $this->assertSame(1, $D::generation([
            $this->row(['event_type' => 'close', 'status' => 'pending', 'generation' => 1]),
        ]));
    }

    public function test_publish_predicates_are_generation_scoped(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [$this->row(['event_type' => 'publish', 'status' => 'pending', 'generation' => 2, 'seq' => 0])];
        $this->assertTrue($D::publishRowExists($rows, 2));
        $this->assertFalse($D::publishRowExists($rows, 1));
        $this->assertFalse($D::publishSent($rows, 2)); // pending, nicht sent
    }

    public function test_last_deliverable_hash_excludes_failed(): void
    {
        // publish A sent, update B failed ⇒ deliverable = A (failed ausgeschlossen ⇒ Selbstheilung)
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'failed', 'id' => 2]),
        ];
        $this->assertSame('A', $D::lastDeliverableContentHash($rows, 1));
    }

    public function test_last_deliverable_hash_uses_highest_seq(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'sent', 'id' => 2]),
        ];
        $this->assertSame('B', $D::lastDeliverableContentHash($rows, 1));
    }

    public function test_build_state_then_failed_update_heals(): void
    {
        // publish A sent + update B failed, aktueller Inhalt B, offen ⇒ decide = update (heilt)
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'failed', 'id' => 2]),
        ];
        $state = $D::buildState($rows, true, 'B');
        $this->assertSame('A', $state->lastDeliverableContentHash);
        $this->assertTrue($state->publishSent);
        $this->assertSame('update', $D::decide($state));
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: FAIL ("Call to undefined method ... generation()").

- [ ] **Step 3: Helfer implementieren**

In `src/Services/Flynk/FlynkPostingSyncDecider.php` innerhalb der Klasse ergänzen:

```php
    /** @param array<int,array{event_type:string,status:string,generation:int,seq:int,content_hash:string}> $rows */
    public static function generation(array $rows): int
    {
        $sentCloses = 0;
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::CLOSE && $r['status'] === 'sent') {
                $sentCloses++;
            }
        }
        return $sentCloses + 1;
    }

    public static function publishRowExists(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH && (int) $r['generation'] === $gen) {
                return true;
            }
        }
        return false;
    }

    public static function publishSent(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH && (int) $r['generation'] === $gen && $r['status'] === 'sent') {
                return true;
            }
        }
        return false;
    }

    public static function closeRowExists(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::CLOSE && (int) $r['generation'] === $gen) {
                return true;
            }
        }
        return false;
    }

    public static function lastDeliverableContentHash(array $rows, int $gen): ?string
    {
        $best = null;
        $bestSeq = -1;
        foreach ($rows as $r) {
            if ((int) $r['generation'] !== $gen) {
                continue;
            }
            if (!in_array($r['event_type'], [FlynkEvent::PUBLISH, FlynkEvent::UPDATE], true)) {
                continue;
            }
            if (!in_array($r['status'], ['pending', 'sent'], true)) {
                continue;
            }
            if ((int) $r['seq'] > $bestSeq) {
                $bestSeq = (int) $r['seq'];
                $best = (string) $r['content_hash'];
            }
        }
        return $best;
    }

    public static function buildState(array $rows, bool $isOpen, string $contentHash): FlynkPostingState
    {
        $gen = self::generation($rows);

        return new FlynkPostingState(
            isOpen: $isOpen,
            contentHash: $contentHash,
            generation: $gen,
            publishRowExists: self::publishRowExists($rows, $gen),
            publishSent: self::publishSent($rows, $gen),
            closeRowExists: self::closeRowExists($rows, $gen),
            lastDeliverableContentHash: self::lastDeliverableContentHash($rows, $gen),
        );
    }
```

- [ ] **Step 4: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: PASS (15 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Flynk/FlynkPostingSyncDecider.php tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php
git commit -m "feat(flynk): Row-Summary-Helfer + buildState (failed-heal)"
```

---

### Task 4: Decider::staleRowIds() (Revalidierung)

**Files:**
- Modify: `src/Services/Flynk/FlynkPostingSyncDecider.php`
- Test: `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php` (erweitern)

**Interfaces:**
- Produces: `FlynkPostingSyncDecider::staleRowIds(bool $isOpen, array $undelivered): int[]` — `$undelivered` = Liste `array{id:int, event_type:string}` (nur pending/failed-Zeilen). Rückgabe: IDs, die gelöscht werden müssen.

- [ ] **Step 1: Failing-Tests schreiben**

In `FlynkPostingSyncDeciderTest.php` ergänzen:

```php
    public function test_stale_publish_when_closed(): void
    {
        // publish pending, Posting inzwischen geschlossen ⇒ stale
        $ids = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::staleRowIds(false, [
            ['id' => 7, 'event_type' => 'publish'],
        ]);
        $this->assertSame([7], $ids);
    }

    public function test_stale_close_when_reopened(): void
    {
        // close pending, Posting wieder offen ⇒ stale
        $ids = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::staleRowIds(true, [
            ['id' => 9, 'event_type' => 'close'],
        ]);
        $this->assertSame([9], $ids);
    }

    public function test_not_stale_when_consistent(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        // publish+offen, close+geschlossen ⇒ nichts stale
        $this->assertSame([], $D::staleRowIds(true, [['id' => 1, 'event_type' => 'publish']]));
        $this->assertSame([], $D::staleRowIds(false, [['id' => 2, 'event_type' => 'close']]));
        // update+geschlossen ⇒ stale
        $this->assertSame([3], $D::staleRowIds(false, [['id' => 3, 'event_type' => 'update']]));
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: FAIL ("Call to undefined method ... staleRowIds()").

- [ ] **Step 3: staleRowIds() implementieren**

In `src/Services/Flynk/FlynkPostingSyncDecider.php` ergänzen:

```php
    /**
     * @param array<int,array{id:int,event_type:string}> $undelivered  nur pending/failed-Zeilen der Generation
     * @return int[]
     */
    public static function staleRowIds(bool $isOpen, array $undelivered): array
    {
        $stale = [];
        foreach ($undelivered as $r) {
            $due = match ($r['event_type']) {
                FlynkEvent::PUBLISH, FlynkEvent::UPDATE => $isOpen,
                FlynkEvent::CLOSE => !$isOpen,
                default => true,
            };
            if (!$due) {
                $stale[] = (int) $r['id'];
            }
        }
        return $stale;
    }
```

- [ ] **Step 4: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: PASS (18 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Flynk/FlynkPostingSyncDecider.php tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php
git commit -m "feat(flynk): staleRowIds() Revalidierung (pure)"
```

---

### Task 5: Decider::nextUpdateSeq() — Lücken-tolerante seq-Allokation

**Files:**
- Modify: `src/Services/Flynk/FlynkPostingSyncDecider.php`
- Test: `tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php` (erweitern)

**Interfaces:**
- Produces: `FlynkPostingSyncDecider::nextUpdateSeq(int[] $updateSeqsInGeneration): int`.

**Warum kritisch:** update-`seq` MUSS `MAX+1` sein, nicht `count+1`. Nach einer mittigen `staleRows`-Löschung entsteht eine Lücke (z. B. `[1,3]`, weil `2` gelöscht wurde). `count+1 = 3` würde mit der bestehenden seq-3-Zeile kollidieren → `insertOrIgnore` blockiert die Emission still. `MAX+1 = 4` ist kollisionsfrei.

- [ ] **Step 1: Failing-Tests schreiben**

In `FlynkPostingSyncDeciderTest.php` ergänzen:

```php
    public function test_next_update_seq_starts_at_one(): void
    {
        $this->assertSame(1, \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::nextUpdateSeq([]));
    }

    public function test_next_update_seq_is_max_plus_one(): void
    {
        $this->assertSame(3, \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::nextUpdateSeq([1, 2]));
    }

    public function test_next_update_seq_tolerates_gap_from_deletion(): void
    {
        // Lücke: seq 2 wurde per staleRows gelöscht ⇒ nächste MUSS 4 sein, nicht 3 (count+1)
        $this->assertSame(4, \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::nextUpdateSeq([1, 3]));
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: FAIL ("Call to undefined method ... nextUpdateSeq()").

- [ ] **Step 3: nextUpdateSeq() implementieren**

In `src/Services/Flynk/FlynkPostingSyncDecider.php` ergänzen:

```php
    /** @param int[] $updateSeqsInGeneration */
    public static function nextUpdateSeq(array $updateSeqsInGeneration): int
    {
        return $updateSeqsInGeneration === [] ? 1 : (max($updateSeqsInGeneration) + 1);
    }
```

- [ ] **Step 4: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingSyncDeciderTest`
Expected: PASS (21 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Flynk/FlynkPostingSyncDecider.php tests/Unit/Flynk/FlynkPostingSyncDeciderTest.php
git commit -m "feat(flynk): nextUpdateSeq() MAX+1, lueckentolerant"
```

---

### Task 6: FlynkPostingPayloadBuilder + FlynkTask

**Files:**
- Create: `src/Services/Flynk/FlynkTask.php`
- Create: `src/Services/Flynk/FlynkPostingPayloadBuilder.php`
- Test: `tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php`

**Interfaces:**
- Consumes: `FlynkEvent`.
- Produces:
  - `new FlynkTask(array $payload, string $contentHash)` (`public readonly`).
  - `FlynkPostingPayloadBuilder::contentHash(?string $title, ?string $description, ?string $activity): string`
  - `FlynkPostingPayloadBuilder::build(array $posting, string $event, ?string $careersUrl): FlynkTask` — `$posting` = `array{uuid:string, title:?string, description:?string, activity:?string, position_title:?string, team_id:?int, generation:int, closes_at:?string}`.

- [ ] **Step 1: Failing-Tests schreiben**

Datei `tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkPostingPayloadBuilder as B;

class FlynkPostingPayloadBuilderTest extends TestCase
{
    private function posting(array $o = []): array
    {
        return array_merge([
            'uuid' => 'u-1', 'title' => 'Koch', 'description' => 'Tolle Stelle',
            'activity' => 'Küche', 'position_title' => 'Koch Köln', 'team_id' => 5,
            'generation' => 1, 'closes_at' => null,
        ], $o);
    }

    public function test_publish_maps_to_new_section(): void
    {
        $t = B::build($this->posting(), 'publish', null);
        $this->assertSame('new_section', $t->payload['type']);
        $this->assertSame('Stellenanzeige: Koch', $t->payload['title']);
        $this->assertSame('normal', $t->payload['priority']);
        $this->assertStringContainsString('veröffentlichen', $t->payload['description']);
    }

    public function test_update_maps_to_text_change(): void
    {
        $t = B::build($this->posting(), 'update', null);
        $this->assertSame('text_change', $t->payload['type']);
        $this->assertStringContainsString('aktualisieren', $t->payload['title']);
    }

    public function test_close_maps_to_text_change_and_empty_hash(): void
    {
        $t = B::build($this->posting(), 'close', null);
        $this->assertSame('text_change', $t->payload['type']);
        $this->assertStringContainsString('entfernen', $t->payload['title']);
        $this->assertStringContainsString('beendet', $t->payload['description']);
        $this->assertSame('', $t->contentHash);
    }

    public function test_activity_appears_in_visible_description(): void
    {
        $t = B::build($this->posting(['activity' => 'Küche']), 'publish', null);
        $this->assertStringContainsString('Küche', $t->payload['description']);
    }

    public function test_content_hash_stable_and_change_sensitive(): void
    {
        $h1 = B::contentHash('a', 'b', 'c');
        $this->assertSame($h1, B::contentHash('a', 'b', 'c'));
        $this->assertNotSame($h1, B::contentHash('a', 'b', 'd'));
        $this->assertNotSame($h1, B::contentHash('x', 'b', 'c'));
    }

    public function test_target_url_only_when_careers_url_set(): void
    {
        $this->assertArrayNotHasKey('target_url', B::build($this->posting(), 'publish', null)->payload);
        $with = B::build($this->posting(), 'publish', 'https://x.de/karriere')->payload;
        $this->assertSame('https://x.de/karriere', $with['target_url']);
    }

    public function test_meta_contains_context(): void
    {
        $meta = B::build($this->posting(['generation' => 2]), 'publish', null)->payload['meta'];
        $this->assertSame('u-1', $meta['posting_uuid']);
        $this->assertSame(5, $meta['team_id']);
        $this->assertSame(2, $meta['generation']);
        $this->assertSame('publish', $meta['event']);
    }

    public function test_title_truncated_to_255(): void
    {
        $t = B::build($this->posting(['title' => str_repeat('x', 400)]), 'publish', null);
        $this->assertLessThanOrEqual(255, mb_strlen($t->payload['title']));
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingPayloadBuilderTest`
Expected: FAIL ("Class ... FlynkPostingPayloadBuilder not found").

- [ ] **Step 3: FlynkTask implementieren**

Datei `src/Services/Flynk/FlynkTask.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkTask
{
    public function __construct(
        public readonly array $payload,
        public readonly string $contentHash,
    ) {
    }
}
```

- [ ] **Step 4: FlynkPostingPayloadBuilder implementieren**

Datei `src/Services/Flynk/FlynkPostingPayloadBuilder.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingPayloadBuilder
{
    public static function contentHash(?string $title, ?string $description, ?string $activity): string
    {
        return hash(
            'sha256',
            trim((string) $title) . "\n" . trim((string) $description) . "\n" . trim((string) $activity)
        );
    }

    public static function build(array $posting, string $event, ?string $careersUrl): FlynkTask
    {
        $title = (string) ($posting['title'] ?? '');
        $description = (string) ($posting['description'] ?? '');
        $activity = trim((string) ($posting['activity'] ?? ''));
        $activityLine = $activity !== '' ? "\nTätigkeit: {$activity}" : '';

        [$taskTitle, $taskType, $taskDescription] = match ($event) {
            FlynkEvent::PUBLISH => [
                "Stellenanzeige: {$title}",
                'new_section',
                $description . $activityLine . "\n\nBitte als Stellenanzeige auf der Karriereseite veröffentlichen.",
            ],
            FlynkEvent::UPDATE => [
                "Stellenanzeige aktualisieren: {$title}",
                'text_change',
                $description . $activityLine . "\n\nBestehende Anzeige mit diesem Stand aktualisieren.",
            ],
            FlynkEvent::CLOSE => [
                "Stellenanzeige entfernen: {$title}",
                'text_change',
                'Diese Stellenanzeige ist beendet — bitte von der Karriereseite entfernen.',
            ],
        };

        $payload = [
            'title' => mb_substr($taskTitle, 0, 255),
            'type' => $taskType,
            'description' => $taskDescription,
            'priority' => 'normal',
            'meta' => [
                'posting_uuid' => $posting['uuid'] ?? null,
                'position_title' => $posting['position_title'] ?? null,
                'activity' => $posting['activity'] ?? null,
                'team_id' => $posting['team_id'] ?? null,
                'generation' => $posting['generation'] ?? null,
                'closes_at' => $posting['closes_at'] ?? null,
                'event' => $event,
            ],
        ];

        if ($careersUrl !== null && $careersUrl !== '') {
            $payload['target_url'] = $careersUrl;
        }

        $contentHash = $event === FlynkEvent::CLOSE
            ? ''
            : self::contentHash($title, $description, $activity);

        return new FlynkTask($payload, $contentHash);
    }
}
```

- [ ] **Step 5: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingPayloadBuilderTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Flynk/FlynkTask.php src/Services/Flynk/FlynkPostingPayloadBuilder.php tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php
git commit -m "feat(flynk): PayloadBuilder + FlynkTask (pure, Hash↔Payload gekoppelt)"
```

---

### Task 7: FlynkResponseMapper + FlynkResult

**Files:**
- Create: `src/Services/Flynk/FlynkResult.php`
- Create: `src/Services/Flynk/FlynkResponseMapper.php`
- Test: `tests/Unit/Flynk/FlynkResponseMapperTest.php`

**Interfaces:**
- Produces:
  - `new FlynkResult(bool $ok, ?int $httpStatus, ?string $taskId, bool $permanent, bool $rateLimited, bool $unauthorized, ?string $error)` (`public readonly`).
  - `FlynkResponseMapper::map(?int $httpStatus, ?array $body, bool $connectionFailed = false): FlynkResult`.

- [ ] **Step 1: Failing-Tests schreiben**

Datei `tests/Unit/Flynk/FlynkResponseMapperTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkResponseMapper as M;

class FlynkResponseMapperTest extends TestCase
{
    public function test_201_is_success_with_task_id(): void
    {
        $r = M::map(201, ['id' => 'abc', 'status' => 'Open']);
        $this->assertTrue($r->ok);
        $this->assertSame('abc', $r->taskId);
        $this->assertFalse($r->permanent);
    }

    public function test_401_is_unauthorized_not_permanent(): void
    {
        $r = M::map(401, null);
        $this->assertFalse($r->ok);
        $this->assertTrue($r->unauthorized);
        $this->assertFalse($r->permanent);
    }

    public function test_422_is_permanent(): void
    {
        $r = M::map(422, ['message' => 'Validation failed']);
        $this->assertFalse($r->ok);
        $this->assertTrue($r->permanent);
        $this->assertFalse($r->unauthorized);
    }

    public function test_429_is_rate_limited_not_permanent(): void
    {
        $r = M::map(429, null);
        $this->assertTrue($r->rateLimited);
        $this->assertFalse($r->permanent);
        $this->assertFalse($r->ok);
    }

    public function test_500_is_transient(): void
    {
        $r = M::map(500, null);
        $this->assertFalse($r->ok);
        $this->assertFalse($r->permanent);
        $this->assertFalse($r->rateLimited);
        $this->assertFalse($r->unauthorized);
    }

    public function test_connection_failure_is_transient(): void
    {
        $r = M::map(null, null, connectionFailed: true);
        $this->assertFalse($r->ok);
        $this->assertFalse($r->permanent);
        $this->assertSame('connection_failed', $r->error);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkResponseMapperTest`
Expected: FAIL ("Class ... FlynkResponseMapper not found").

- [ ] **Step 3: FlynkResult implementieren**

Datei `src/Services/Flynk/FlynkResult.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly ?string $taskId,
        public readonly bool $permanent,
        public readonly bool $rateLimited,
        public readonly bool $unauthorized,
        public readonly ?string $error,
    ) {
    }
}
```

- [ ] **Step 4: FlynkResponseMapper implementieren**

Datei `src/Services/Flynk/FlynkResponseMapper.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResponseMapper
{
    /** @param array<string,mixed>|null $body */
    public static function map(?int $httpStatus, ?array $body, bool $connectionFailed = false): FlynkResult
    {
        if ($connectionFailed) {
            return new FlynkResult(false, null, null, false, false, false, 'connection_failed');
        }
        if ($httpStatus === 201) {
            $taskId = isset($body['id']) ? (string) $body['id'] : null;
            return new FlynkResult(true, 201, $taskId, false, false, false, null);
        }
        if ($httpStatus === 401) {
            return new FlynkResult(false, 401, null, false, false, true, 'unauthorized');
        }
        if ($httpStatus === 422) {
            return new FlynkResult(false, 422, null, true, false, false, self::stringify($body));
        }
        if ($httpStatus === 429) {
            return new FlynkResult(false, 429, null, false, true, false, 'rate_limited');
        }

        // 5xx und alles andere → transient
        return new FlynkResult(false, $httpStatus, null, false, false, false, self::stringify($body));
    }

    private static function stringify(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 1000);
    }
}
```

- [ ] **Step 5: Tests laufen lassen — müssen bestehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkResponseMapperTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Flynk/FlynkResult.php src/Services/Flynk/FlynkResponseMapper.php tests/Unit/Flynk/FlynkResponseMapperTest.php
git commit -m "feat(flynk): ResponseMapper + FlynkResult (pure Fehler-Mapping)"
```

---

### Task 8: Migration + Model RecPostingFlynkSync

**Files:**
- Create: `database/migrations/2026_07_06_000001_create_rec_posting_flynk_syncs_table.php`
- Create: `src/Models/RecPostingFlynkSync.php`

**Interfaces:**
- Produces: Tabelle `rec_posting_flynk_syncs` mit Unique `(rec_posting_id, generation, event_type, seq)`; Modell `RecPostingFlynkSync` mit `posting()`-Relation.

*(Kein Unit-Test — DB-Schicht, per `php -l` und Migration-Lauf in meingedeck verifiziert.)*

- [ ] **Step 1: Migration schreiben**

Datei `database/migrations/2026_07_06_000001_create_rec_posting_flynk_syncs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_posting_flynk_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->string('event_type', 16);
            $table->unsignedInteger('seq')->default(0);
            $table->string('content_hash', 64)->default('');
            $table->string('flynk_task_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('permanent_failure')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rec_posting_id', 'generation', 'event_type', 'seq'],
                'rec_posting_flynk_unique'
            );
            $table->index(['rec_posting_id', 'status'], 'rec_posting_flynk_posting_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_posting_flynk_syncs');
    }
};
```

- [ ] **Step 2: Model schreiben**

Datei `src/Models/RecPostingFlynkSync.php`:

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

class RecPostingFlynkSync extends Model
{
    protected $table = 'rec_posting_flynk_syncs';

    protected $fillable = [
        'rec_posting_id', 'generation', 'event_type', 'seq', 'content_hash',
        'flynk_task_id', 'status', 'http_status', 'attempts', 'permanent_failure',
        'last_error', 'sent_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'seq' => 'integer',
        'attempts' => 'integer',
        'http_status' => 'integer',
        'permanent_failure' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function posting()
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }
}
```

- [ ] **Step 3: Syntax prüfen**

Run: `php -l database/migrations/2026_07_06_000001_create_rec_posting_flynk_syncs_table.php && php -l src/Models/RecPostingFlynkSync.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 4: Migration in meingedeck laufen lassen**

Run: `cd ../../../meingedeck && php artisan migrate && cd -`
Expected: Migration `..._create_rec_posting_flynk_syncs_table` läuft ohne Fehler (Tabelle angelegt).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_06_000001_create_rec_posting_flynk_syncs_table.php src/Models/RecPostingFlynkSync.php
git commit -m "feat(flynk): Migration + Modell rec_posting_flynk_syncs"
```

---

### Task 9: FlynkClient + Provider-Binding

**Files:**
- Create: `src/Services/Flynk/FlynkClient.php`
- Modify: `src/RecruitingServiceProvider.php` (Binding in `register()`)

**Interfaces:**
- Consumes: `FlynkResponseMapper`, `FlynkResult`.
- Produces: `FlynkClient::createTask(array $payload): FlynkResult`. Konstruktor: `(string $baseUrl, string $token, int $timeout)`.

*(Kein Unit-Test — Http-Facade nicht ohne Laravel-Harness testbar; Mapping ist bereits in Task 7 pur getestet.)*

- [ ] **Step 1: FlynkClient schreiben**

Datei `src/Services/Flynk/FlynkClient.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Http;

class FlynkClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
    ) {
    }

    public function createTask(array $payload): FlynkResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withToken($this->token)
                ->asJson()
                ->acceptJson()
                ->post('/webhooks/tasks', $payload);
        } catch (\Throwable) {
            return FlynkResponseMapper::map(null, null, connectionFailed: true);
        }

        $body = null;
        try {
            $decoded = $response->json();
            $body = is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            $body = null;
        }

        return FlynkResponseMapper::map($response->status(), $body);
    }
}
```

- [ ] **Step 2: Provider-Binding ergänzen**

In `src/RecruitingServiceProvider.php`, in der `register()`-Methode nach dem bestehenden `ZasSignedUrlGenerator`-Singleton einfügen:

```php
        $this->app->singleton(
            \Platform\Recruiting\Services\Flynk\FlynkClient::class,
            fn ($app) => new \Platform\Recruiting\Services\Flynk\FlynkClient(
                baseUrl: (string) config('recruiting.flynk.base_url', 'https://flynk.on-forge.com/api'),
                token:   (string) config('recruiting.flynk.token', ''),
                timeout: (int) config('recruiting.flynk.timeout', 10),
            )
        );
```

- [ ] **Step 3: Syntax prüfen**

Run: `php -l src/Services/Flynk/FlynkClient.php && php -l src/RecruitingServiceProvider.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 4: Commit**

```bash
git add src/Services/Flynk/FlynkClient.php src/RecruitingServiceProvider.php
git commit -m "feat(flynk): FlynkClient (Http-Wrapper) + Provider-Binding"
```

---

### Task 10: FlynkPostingReconciler (Orchestrierung)

**Files:**
- Create: `src/Services/Flynk/FlynkPostingReconciler.php`

**Interfaces:**
- Consumes: `FlynkClient`, `FlynkPostingSyncDecider`, `FlynkPostingPayloadBuilder`, `FlynkEvent`, `RecPosting`, `RecPostingFlynkSync`.
- Produces: `FlynkPostingReconciler::run(): array` (Summary `['sent','retried','stale_deleted','failed','permanent','skipped']`). Konstruktor: `(FlynkClient $client)`.

*(Kein Unit-Test — DB-Glue. Verifikation über den Command-Smoke in Task 11 + manuelle Prüfung gegen FLYNK-Staging. Alle Verzweigungslogik-Bausteine sind in Tasks 2–7 pur getestet.)*

- [ ] **Step 1: Reconciler schreiben**

Datei `src/Services/Flynk/FlynkPostingReconciler.php`:

```php
<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingFlynkSync;

class FlynkPostingReconciler
{
    public function __construct(private readonly FlynkClient $client)
    {
    }

    public function run(): array
    {
        $cap = (int) config('recruiting.flynk.per_run_cap', 50);
        $maxAttempts = (int) config('recruiting.flynk.max_attempts', 5);
        $careersUrl = config('recruiting.flynk.careers_url');
        $careersUrl = is_string($careersUrl) && $careersUrl !== '' ? $careersUrl : null;

        $summary = [
            'sent' => 0, 'retried' => 0, 'stale_deleted' => 0,
            'failed' => 0, 'permanent' => 0, 'skipped' => 0,
        ];
        $sends = 0;
        $abort = false; // 401 → Lauf hart abbrechen
        $stop = false;  // 429 → Lauf höflich beenden

        $publishedIds = RecPosting::query()->where('status', 'published')->pluck('id')->all();
        $syncedIds = RecPostingFlynkSync::query()->distinct()->pluck('rec_posting_id')->all();
        $candidateIds = array_values(array_unique(array_merge($publishedIds, $syncedIds)));
        if ($candidateIds === []) {
            return $summary;
        }

        $postings = RecPosting::query()->with('position:id,title')
            ->whereIn('id', $candidateIds)->get()->keyBy('id');
        $rowsByPosting = RecPostingFlynkSync::query()
            ->whereIn('rec_posting_id', $candidateIds)->get()->groupBy('rec_posting_id');

        // ---- 0/1. Analyze + Revalidate (stale löschen) ----
        $context = [];
        foreach ($postings as $pid => $p) {
            $isOpen = $p->status === 'published' && (bool) $p->is_active
                && ($p->closes_at === null || $p->closes_at->isFuture());

            $rows = $rowsByPosting->get($pid, collect())->map(fn ($r) => [
                'id' => (int) $r->id,
                'generation' => (int) $r->generation,
                'event_type' => $r->event_type,
                'seq' => (int) $r->seq,
                'content_hash' => (string) $r->content_hash,
                'status' => $r->status,
                'attempts' => (int) $r->attempts,
                'permanent_failure' => (bool) $r->permanent_failure,
            ])->all();

            $undelivered = array_values(array_filter(
                $rows,
                fn ($r) => in_array($r['status'], ['pending', 'failed'], true)
            ));
            $staleIds = FlynkPostingSyncDecider::staleRowIds($isOpen, $undelivered);
            if ($staleIds !== []) {
                RecPostingFlynkSync::query()->whereIn('id', $staleIds)->delete();
                $summary['stale_deleted'] += count($staleIds);
                Log::info('flynk: stale sync rows deleted', ['posting_id' => $pid, 'ids' => $staleIds]);
                $rows = array_values(array_filter($rows, fn ($r) => !in_array($r['id'], $staleIds, true)));
            }

            $context[$pid] = ['posting' => $p, 'isOpen' => $isOpen, 'rows' => $rows];
        }

        // ---- 2. Retry-Pass (Vorrang für hängende Zustellungen) ----
        foreach ($context as $pid => $ctx) {
            if ($abort || $stop || $sends >= $cap) {
                break;
            }
            foreach ($ctx['rows'] as $i => $row) {
                if ($abort || $stop || $sends >= $cap) {
                    break;
                }
                if (!in_array($row['status'], ['pending', 'failed'], true) || $row['permanent_failure']) {
                    continue;
                }
                if ($row['attempts'] >= $maxAttempts) {
                    $summary['skipped']++;
                    continue;
                }

                $model = RecPostingFlynkSync::find($row['id']);
                if ($model === null) {
                    continue;
                }
                $task = FlynkPostingPayloadBuilder::build(
                    $this->postingData($ctx['posting'], $row['generation']),
                    $row['event_type'],
                    $careersUrl
                );
                $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary);
                $sends++;
                $summary['retried']++;
                $context[$pid]['rows'][$i]['status'] = $model->status; // in-memory für Detect-Pass syncen
                if ($outcome === 'abort') {
                    $abort = true;
                    break;
                }
                if ($outcome === 'stop') {
                    $stop = true;
                    break;
                }
            }
        }

        // ---- 3. Detect-Pass ----
        foreach ($context as $pid => $ctx) {
            if ($abort || $stop || $sends >= $cap) {
                break;
            }
            $p = $ctx['posting'];
            $rows = $ctx['rows'];

            $contentHash = FlynkPostingPayloadBuilder::contentHash($p->title, $p->description, $p->activity);
            $state = FlynkPostingSyncDecider::buildState($rows, $ctx['isOpen'], $contentHash);
            $event = FlynkPostingSyncDecider::decide($state);
            if ($event === null) {
                continue;
            }

            $gen = $state->generation;
            $seq = $event === FlynkEvent::UPDATE
                ? FlynkPostingSyncDecider::nextUpdateSeq($this->updateSeqs($rows, $gen))
                : 0;

            $task = FlynkPostingPayloadBuilder::build($this->postingData($p, $gen), $event, $careersUrl);

            // Claim: nur bei frischem Insert senden (affectedRows === 1)
            $inserted = RecPostingFlynkSync::query()->insertOrIgnore([
                'rec_posting_id' => $pid,
                'generation' => $gen,
                'event_type' => $event,
                'seq' => $seq,
                'content_hash' => $task->contentHash,
                'status' => 'pending',
                'attempts' => 0,
                'permanent_failure' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                $summary['skipped']++;
                continue;
            }

            $model = RecPostingFlynkSync::query()
                ->where('rec_posting_id', $pid)->where('generation', $gen)
                ->where('event_type', $event)->where('seq', $seq)->first();
            if ($model === null) {
                continue;
            }

            $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary);
            $sends++;
            if ($outcome === 'abort') {
                $abort = true;
                break;
            }
            if ($outcome === 'stop') {
                $stop = true;
                break;
            }
        }

        if ($abort) {
            Log::error('flynk: Lauf abgebrochen (401) — RECRUITING_FLYNK_TOKEN prüfen');
        } elseif ($stop) {
            Log::info('flynk: Lauf beendet (429 Rate-Limit) — Rest im nächsten Lauf');
        }

        return $summary;
    }

    /** @return 'ok'|'abort'|'stop' */
    private function dispatch(RecPostingFlynkSync $model, array $payload, int $maxAttempts, array &$summary): string
    {
        $result = $this->client->createTask($payload);
        $model->attempts = $model->attempts + 1;
        $model->http_status = $result->httpStatus;

        if ($result->ok) {
            $model->status = 'sent';
            $model->flynk_task_id = $result->taskId;
            $model->sent_at = now();
            $model->last_error = null;
            $model->save();
            $summary['sent']++;
            return 'ok';
        }

        if ($result->unauthorized) {
            // NICHT permanent — globales Token-Problem, Zeile bleibt retrybar.
            $model->last_error = 'unauthorized (401)';
            $model->save();
            return 'abort';
        }

        if ($result->rateLimited) {
            $model->last_error = 'rate_limited (429)';
            $model->save();
            return 'stop';
        }

        if ($result->permanent) {
            $model->status = 'failed';
            $model->permanent_failure = true;
            $model->last_error = $result->error;
            $model->save();
            $summary['permanent']++;
            return 'ok';
        }

        // transient (5xx / Netzwerk)
        $model->status = $model->attempts >= $maxAttempts ? 'failed' : 'pending';
        $model->last_error = $result->error;
        $model->save();
        $summary['failed']++;
        return 'ok';
    }

    private function postingData(RecPosting $p, int $generation): array
    {
        return [
            'uuid' => $p->uuid,
            'title' => $p->title,
            'description' => $p->description,
            'activity' => $p->activity,
            'position_title' => $p->position?->title,
            'team_id' => $p->team_id,
            'generation' => $generation,
            'closes_at' => $p->closes_at?->toIso8601String(),
        ];
    }

    /** @return int[] */
    private function updateSeqs(array $rows, int $gen): array
    {
        $seqs = [];
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::UPDATE && (int) $r['generation'] === $gen) {
                $seqs[] = (int) $r['seq'];
            }
        }
        return $seqs;
    }
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l src/Services/Flynk/FlynkPostingReconciler.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Services/Flynk/FlynkPostingReconciler.php
git commit -m "feat(flynk): Reconciler (Analyze→Revalidate→Retry→Detect)"
```

---

### Task 11: Command + Registrierung + Schedule

**Files:**
- Create: `src/Console/Commands/FlynkReconcile.php`
- Modify: `src/RecruitingServiceProvider.php` (Command in `$this->commands([...])`, Schedule in `registerSchedule()`)

**Interfaces:**
- Consumes: `FlynkPostingReconciler`.
- Produces: Artisan-Command `recruiting:flynk-reconcile`.

*(Kein Unit-Test — verifiziert per Artisan-Smoke: bei enabled=false kehrt der Command sofort zurück.)*

- [ ] **Step 1: Command schreiben**

Datei `src/Console/Commands/FlynkReconcile.php`:

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\Flynk\FlynkPostingReconciler;

class FlynkReconcile extends Command
{
    protected $signature = 'recruiting:flynk-reconcile';

    protected $description = 'Synchronisiert veröffentlichte Ausschreibungen als Tasks nach FLYNK.';

    public function handle(FlynkPostingReconciler $reconciler): int
    {
        if (!config('recruiting.flynk.enabled') || !config('recruiting.flynk.token')) {
            $this->info('FLYNK-Sync deaktiviert (enabled=false oder Token fehlt).');
            return Command::SUCCESS;
        }

        $s = $reconciler->run();
        $this->info(sprintf(
            'FLYNK-Sync: sent=%d retried=%d stale_deleted=%d failed=%d permanent=%d skipped=%d',
            $s['sent'], $s['retried'], $s['stale_deleted'], $s['failed'], $s['permanent'], $s['skipped']
        ));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Command registrieren**

In `src/RecruitingServiceProvider.php` im `$this->commands([...])`-Array (Task 1-nahe Liste) eine Zeile ergänzen:

```php
                \Platform\Recruiting\Console\Commands\FlynkReconcile::class,
```

- [ ] **Step 3: Schedule ergänzen**

In `src/RecruitingServiceProvider.php` in `registerSchedule()` nach dem letzten bestehenden `Schedule::command(...)`-Block ergänzen:

```php
        Schedule::command('recruiting:flynk-reconcile')
            ->everyThirtyMinutes()
            ->withoutOverlapping(15)
            ->runInBackground();
```

- [ ] **Step 4: Syntax prüfen**

Run: `php -l src/Console/Commands/FlynkReconcile.php && php -l src/RecruitingServiceProvider.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 5: Artisan-Smoke (Command registriert + Guard greift)**

Run: `cd ../../../meingedeck && php artisan recruiting:flynk-reconcile && cd -`
Expected: Ausgabe „FLYNK-Sync deaktiviert (enabled=false oder Token fehlt)." (Default-Config → kein Send, sauberer Exit 0).

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/FlynkReconcile.php src/RecruitingServiceProvider.php
git commit -m "feat(flynk): Command recruiting:flynk-reconcile + 30-Min-Schedule"
```

---

### Task 12: Volllauf aller Unit-Tests

**Files:** (keine)

- [ ] **Step 1: Gesamte Unit-Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS — alle bestehenden Tests plus die neuen Flynk-Tests (Decider 21, PayloadBuilder 8, ResponseMapper 6) grün, keine Regressionen.

- [ ] **Step 2: Abschluss**

Falls grün: Feature ist implementiert. Rollout laut Spec §9 (`RECRUITING_FLYNK_ENABLED=true` + `RECRUITING_FLYNK_TOKEN` + optional `RECRUITING_FLYNK_CAREERS_URL` in der Zielumgebung; `meingedeck` composer.lock bumpen).

---

## Self-Review

**Spec-Coverage (Rev. 3):**
- §3 Trigger/Lebenszyklus (publish/update/close/reopen) → Decider Tasks 2–5, Reconciler Task 10. ✓
- §4 Config → Task 1. ✓
- §5 Datenmodell + Unique-Index + `content_hash` nicht in Uniqueness → Task 8; seq-Allokation MAX+1 → Task 5. ✓
- §6.1 Generation & Prädikate (inkl. `lastDeliverableContentHash` pending+sent) → Task 3. ✓
- §6.2 decide() → Task 2. ✓
- §6.3 staleRows() Revalidierung → Task 4; Anwendung (löschen vor Retry) → Task 10. ✓
- §6.4 PayloadBuilder (Hash↔Payload, activity sichtbar) → Task 6. ✓
- §6.5 Kandidaten & Batch-Laden (kein N+1) → Task 10 (`whereIn`+`groupBy`). ✓
- §6.6 FlynkClient → Task 9. ✓
- §7 Fehlerbehandlung (201/401/422/429/5xx, Reihenfolge Revalidate→Retry→Detect, frischer-Insert-Gate, per-run-cap) → Task 7 (Mapping) + Task 10 (Anwendung). ✓
- §8 Teststrategie → Tasks 2–7 (pure), Task 12 (Volllauf). ✓
- §9 Scheduler/Betrieb → Task 11. ✓

**Placeholder-Scan:** Keine TBD/TODO; jeder Code-Step enthält vollständigen Code. ✓

**Typ-Konsistenz:** Row-Shape `{id,generation,event_type,seq,content_hash,status}` einheitlich in Tasks 3/4/10; `FlynkEvent`-Konstanten überall; `FlynkResult`-/`FlynkTask`-/`FlynkPostingState`-Signaturen stimmen zwischen Erzeuger (Tasks 2/6/7) und Consumer (Task 10) überein. ✓
