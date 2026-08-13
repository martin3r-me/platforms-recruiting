# Automatischer Referenz-Code (RG-XXXX) für Ausschreibungen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jede neu erstellte Ausschreibung (`RecPosting`) bekommt automatisch einen eindeutigen `RG-XXXX`-Referenz-Code, der im Flynk-Payload (Rheingedeck-Karriereseite) mitgeschickt und in der Posting-UI prominent angezeigt wird — damit eingehende Bewerbungen deterministisch (Stufe 1) statt per LLM (Stufe 2) zugeordnet werden.

**Architecture:** Die Code-Generierung wird aus `DirectHireSetupService::createRefCode()` in einen neuen, idempotenten `PostingRefCodeService` extrahiert und über einen `RecPosting::created`-Model-Hook (gleiches Muster wie der bestehende `creating`-UUID-Hook) an ALLE vier Erstellungspfade gehängt (Livewire `Posting/Index`, `CreatePostingTool`, `BulkCreatePostingsTool`, `DirectHireSetupService`). Der Flynk-Payload-Builder nimmt den Code in Beschreibungstext, Meta und Content-Hash auf — Hash-Erweiterung nur bei vorhandenem Code, damit Bestands-Postings ohne Code keinen Update-Sturm auslösen. Die Inbound-/Matching-Seite (`RefCodeParser`, `ApplicationMatchingService` Stufe 1) bleibt unverändert — sie kann den Code bereits verarbeiten.

**Tech Stack:** Laravel/Eloquent (Model-Hooks), Livewire 3, reines PHPUnit für Unit-Tests.

## Global Constraints

- **Kein Backfill**: bestehende Ausschreibungen bekommen KEINEN Code (explizite Nutzer-Entscheidung). Nur neue Postings ab Merge.
- **Test-Konvention des Moduls**: nur reines PHPUnit ohne Laravel-Bootstrap/DB (kein testbench). DB-gebundene Services werden NICHT unit-getestet; testbar ist nur pure Logik (hier: `FlynkPostingPayloadBuilder`).
- **Test-Runner**: `cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
- **Hash-Kompatibilität**: `FlynkPostingPayloadBuilder::contentHash(...)` MUSS für Postings ohne Ref-Code byte-identisch zum heutigen Format bleiben (sonst Update-Welle an die Agentur für alle Bestands-Postings).
- **Hash-Konsistenz**: Der Ref-Code fließt in BEIDE `contentHash`-Aufrufstellen ein (`build()` intern UND `FlynkPostingReconciler::run()` Detect-Pass) — sonst Endlos-Update-Schleife.
- **Kein neues ID-Format**: ausschließlich der bestehende `RG-XXXX`-Code (`RefCodeParser`), gespeichert als `RecPostingExternalRef` unter der Source-Platform „Referenz-Code" (`ref_parser='ref_code'`).
- Commits auf einem Feature-Branch; vorher `git fetch` und Basis == `origin/main` prüfen.

---

### Task 0: Feature-Branch anlegen

**Files:** keine

- [ ] **Step 1: Fetch + Branch**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git checkout -b feature/posting-ref-code origin/main
```

Expected: neuer Branch `feature/posting-ref-code` auf Stand `origin/main`.

---

### Task 1: `PostingRefCodeService` extrahieren (aus DirectHireSetupService)

**Files:**
- Create: `src/Services/PostingRefCodeService.php`
- Modify: `src/Services/DirectHireSetupService.php` (Zeilen 141-174: `createRefCode()` entfernen, Aufruf delegieren)

**Interfaces:**
- Consumes: `RefCodeParser::generate(): string` (existiert, `src/Services/RefParsers/RefCodeParser.php:23`), Models `RecPosting`, `RecPostingExternalRef`, `RecSourcePlatform`.
- Produces: `PostingRefCodeService::ensure(RecPosting $posting): string` (idempotent — gibt bestehenden Code zurück oder erzeugt einen) und `PostingRefCodeService::codeFor(RecPosting $posting): ?string` (reiner Lookup, erzeugt nichts). Task 2, 3b und 4 bauen darauf auf.

**Hinweis Tests:** Service ist DB-gebunden → per Modul-Konvention kein Unit-Test. Die Logik ist 1:1 aus dem produktiv laufenden `DirectHireSetupService::createRefCode()` extrahiert (Verhaltensparität statt Neubau); Verifikation via Code-Review + Task-6-Smoke.

**Hinweis Kollisions-Race:** Die do…while-Schleife ist Best-Effort; das harte Backstop ist die DB-Unique-Constraint `rec_posting_ext_refs_source_ref_unique` auf `(rec_source_platform_id, external_ref)` (Migration `rec_posting_external_refs`, Zeile 25). Ein gleichzeitiger Doppel-Insert endet als Exception, nie als Duplikat — gleiche Charakteristik wie der heutige DirectHire-Code.

- [ ] **Step 1: Service anlegen**

Kompletter Inhalt von `src/Services/PostingRefCodeService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

/**
 * Erzeugt/liest den RG-XXXX-Referenz-Code einer Ausschreibung.
 * Der Code lebt als RecPostingExternalRef unter der synthetischen
 * Source-Platform "Referenz-Code" (ref_parser='ref_code') und wird
 * in Matching-Stufe 1 quellen-unabhängig aufgelöst.
 */
class PostingRefCodeService
{
    /** Sentinel: matcht absichtlich NIE einen echten Absender — die Code-Stufe im Matching ist quellen-unabhängig. */
    private const SENTINEL_PATTERN = '@@referenz-code-niemals-absender@@';

    public const SOURCE_NAME = 'Referenz-Code';

    /** Idempotent: liefert den bestehenden Code oder erzeugt genau einen neuen. */
    public function ensure(RecPosting $posting): string
    {
        $existing = $this->codeFor($posting);
        if ($existing !== null) {
            return $existing;
        }

        $source = $this->sourcePlatform((int) $posting->team_id);

        do {
            $code = RefCodeParser::generate();
        } while (RecPostingExternalRef::query()
            ->where('rec_source_platform_id', $source->id)
            ->where('external_ref', $code)
            ->exists());

        RecPostingExternalRef::create([
            'rec_posting_id' => $posting->id,
            'rec_source_platform_id' => $source->id,
            'external_ref' => $code,
            'team_id' => $posting->team_id,
        ]);

        return $code;
    }

    /**
     * Reiner Lookup ohne Seiteneffekte (für UI/Anzeige).
     * Auflösung über ref_parser='ref_code' — dieselbe Semantik wie
     * Matching-Stufe 1b (ApplicationMatchingService.php:58) und
     * FlynkPostingReconciler::refCodeOf(). Der Name SOURCE_NAME ist
     * nur Natural Key fürs Anlegen, nie fürs Auflösen.
     */
    public function codeFor(RecPosting $posting): ?string
    {
        return RecPostingExternalRef::query()
            ->where('rec_posting_id', $posting->id)
            ->whereHas('sourcePlatform', fn ($q) => $q->where('ref_parser', 'ref_code'))
            ->value('external_ref');
    }

    private function sourcePlatform(int $teamId): RecSourcePlatform
    {
        $source = RecSourcePlatform::firstOrCreate(
            ['team_id' => $teamId, 'name' => self::SOURCE_NAME],
            ['match_pattern' => self::SENTINEL_PATTERN, 'ref_parser' => 'ref_code', 'is_active' => true, 'priority' => 999],
        );
        if ($source->ref_parser !== 'ref_code') {
            $source->update(['ref_parser' => 'ref_code']);
        }

        return $source;
    }
}
```

- [ ] **Step 2: DirectHireSetupService delegieren**

In `src/Services/DirectHireSetupService.php`:

(a) Zeile 144 ersetzen:

```php
// vorher:
$refCode = $this->createRefCode($posting, $input['team_id']);
// nachher:
$refCode = app(PostingRefCodeService::class)->ensure($posting);
```

(b) Die komplette private Methode `createRefCode()` (Zeilen 153-174) **löschen**.

(c) Import ergänzen: `use Platform\Recruiting\Services\PostingRefCodeService;` — und prüfen, ob `RefCodeParser` / `RecPostingExternalRef` / `RecSourcePlatform` in der Datei noch anderweitig genutzt werden; wenn nein, die nun toten `use`-Zeilen entfernen.

- [ ] **Step 3: Syntax-Check + bestehende Tests grün**

```bash
php -l src/Services/PostingRefCodeService.php && php -l src/Services/DirectHireSetupService.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected` (2×), alle Tests PASS (keine Bestandstests betroffen).

- [ ] **Step 4: Commit**

```bash
git add src/Services/PostingRefCodeService.php src/Services/DirectHireSetupService.php
git commit -m "refactor(recruiting): RG-Code-Erzeugung in PostingRefCodeService extrahieren"
```

---

### Task 2: Auto-Generierung via `RecPosting::created`-Hook

**Files:**
- Modify: `src/Models/RecPosting.php:23-33` (`booted()`)

**Interfaces:**
- Consumes: `PostingRefCodeService::ensure(RecPosting): string` (Task 1).
- Produces: JEDES neu erstellte Posting (Livewire `Posting/Index::createPosting()`, `CreatePostingTool`, `BulkCreatePostingsTool`, `DirectHireSetupService`) hat unmittelbar nach `create()` einen Ref-Code als `RecPostingExternalRef`. Task 3b/4 dürfen sich darauf verlassen, dass neue Postings einen Code haben (Bestands-Postings weiterhin nicht!).

**Hinweis:** Der Hook feuert auch innerhalb der DirectHire-Transaktion — der dortige explizite `ensure()`-Aufruf (Task 1) ist dank Idempotenz ein reiner Read und liefert denselben Code zurück. Kein Doppel-Code möglich.

- [ ] **Step 1: Hook ergänzen**

In `src/Models/RecPosting.php`, `booted()` erweitern (nach dem bestehenden `creating`-Block):

```php
protected static function booted(): void
{
    static::creating(function (self $model) {
        if (empty($model->uuid)) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());
            $model->uuid = $uuid;
        }
    });

    // Jede neue Ausschreibung bekommt sofort einen RG-Code — Grundlage
    // für die deterministische Zuordnung (Matching Stufe 1) statt LLM.
    static::created(function (self $model) {
        app(\Platform\Recruiting\Services\PostingRefCodeService::class)->ensure($model);
    });
}
```

- [ ] **Step 2: Syntax-Check + Tests**

```bash
php -l src/Models/RecPosting.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, alle Tests PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Models/RecPosting.php
git commit -m "feat(recruiting): RG-Referenz-Code automatisch bei jeder neuen Ausschreibung erzeugen"
```

---

### Task 3: Ref-Code im Flynk-Payload (Builder, TDD)

**Files:**
- Modify: `src/Services/Flynk/FlynkPostingPayloadBuilder.php`
- Test: `tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php`

**Interfaces:**
- Consumes: nichts Neues — Builder bleibt pure-static; bekommt den Code als neuen Array-Key `$posting['ref_code']` (nullable string).
- Produces: `contentHash(?string $title, ?string $description, ?string $activity, ?string $refCode = null): string` (4. Parameter optional, Default `null` ⇒ Hash byte-identisch zu heute). `build()` liest `$posting['ref_code']`, hängt bei publish/update eine sichtbare Code-Zeile an die Task-Beschreibung, setzt `meta.ref_code` und rechnet den Code in den Hash ein. Task 4 verdrahtet den Reconciler damit.

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php` ergänzen (Stil der Datei: Helper `$this->posting([...])`, Alias `B`):

```php
public function test_ref_code_appears_in_description_and_meta(): void
{
    $t = B::build($this->posting(['ref_code' => 'RG-AB23']), 'publish', null);
    $this->assertStringContainsString('Referenz-Code: RG-AB23', $t->payload['description']);
    $this->assertSame('RG-AB23', $t->payload['meta']['ref_code']);
}

public function test_ref_code_line_also_on_update_but_not_on_close(): void
{
    $u = B::build($this->posting(['ref_code' => 'RG-AB23']), 'update', null);
    $this->assertStringContainsString('Referenz-Code: RG-AB23', $u->payload['description']);

    $c = B::build($this->posting(['ref_code' => 'RG-AB23']), 'close', null);
    $this->assertStringNotContainsString('RG-AB23', $c->payload['description']);
}

public function test_content_hash_without_ref_code_stays_legacy_compatible(): void
{
    // Bestands-Postings ohne Code dürfen KEINEN neuen Hash bekommen (kein Update-Sturm).
    $legacy = hash('sha256', "a\nb\nc");
    $this->assertSame($legacy, B::contentHash('a', 'b', 'c'));
    $this->assertSame($legacy, B::contentHash('a', 'b', 'c', null));
    $this->assertSame($legacy, B::contentHash('a', 'b', 'c', ''));
}

public function test_content_hash_changes_with_ref_code(): void
{
    $this->assertNotSame(B::contentHash('a', 'b', 'c'), B::contentHash('a', 'b', 'c', 'RG-AB23'));
}

public function test_build_hash_equals_content_hash_with_ref_code(): void
{
    // Konsistenz-Garantie für den Reconciler-Detect-Pass (beide Hash-Quellen identisch).
    $t = B::build($this->posting(['ref_code' => 'RG-AB23']), 'publish', null);
    $this->assertSame(B::contentHash('Koch', 'Tolle Stelle', 'Küche', 'RG-AB23'), $t->contentHash);
}

public function test_missing_ref_code_key_behaves_like_today(): void
{
    $t = B::build($this->posting(), 'publish', null);
    $this->assertStringNotContainsString('Referenz-Code', $t->payload['description']);
    $this->assertNull($t->payload['meta']['ref_code']);
    $this->assertSame(B::contentHash('Koch', 'Tolle Stelle', 'Küche'), $t->contentHash);
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingPayloadBuilderTest
```

Expected: FAIL — u.a. `Undefined array key "ref_code"` bzw. Assertion-Fehler (Code-Zeile fehlt).

- [ ] **Step 3: Builder implementieren**

`src/Services/Flynk/FlynkPostingPayloadBuilder.php` — beide Methoden anpassen:

```php
public static function contentHash(?string $title, ?string $description, ?string $activity, ?string $refCode = null): string
{
    $base = trim((string) $title) . "\n" . trim((string) $description) . "\n" . trim((string) $activity);
    // Nur bei vorhandenem Code anhängen — Bestands-Postings ohne Code
    // behalten ihren Legacy-Hash (sonst Update-Welle an die Agentur).
    $refCode = trim((string) $refCode);
    if ($refCode !== '') {
        $base .= "\n" . $refCode;
    }

    return hash('sha256', $base);
}

public static function build(array $posting, string $event, ?string $careersUrl): FlynkTask
{
    $title = (string) ($posting['title'] ?? '');
    $description = (string) ($posting['description'] ?? '');
    $activity = trim((string) ($posting['activity'] ?? ''));
    $activityLine = $activity !== '' ? "\nTätigkeit: {$activity}" : '';
    $refCode = trim((string) ($posting['ref_code'] ?? ''));
    $refCodeLine = $refCode !== ''
        ? "\n\nReferenz-Code: {$refCode} — bitte gut sichtbar in der Anzeige aufführen (dient der automatischen Zuordnung eingehender Bewerbungen)."
        : '';

    [$taskTitle, $taskType, $taskDescription] = match ($event) {
        FlynkEvent::PUBLISH => [
            "Stellenanzeige: {$title}",
            'new_section',
            $description . $activityLine . $refCodeLine . "\n\nBitte als Stellenanzeige auf der Karriereseite veröffentlichen.",
        ],
        FlynkEvent::UPDATE => [
            "Stellenanzeige aktualisieren: {$title}",
            'text_change',
            $description . $activityLine . $refCodeLine . "\n\nBestehende Anzeige mit diesem Stand aktualisieren.",
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
            'ref_code' => $refCode !== '' ? $refCode : null,
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
        : self::contentHash($title, $description, $activity, $refCode);

    return new FlynkTask($payload, $contentHash);
}
```

- [ ] **Step 4: Tests grün**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FlynkPostingPayloadBuilderTest
```

Expected: PASS (alle, inkl. der 8 Bestandstests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Flynk/FlynkPostingPayloadBuilder.php tests/Unit/Flynk/FlynkPostingPayloadBuilderTest.php
git commit -m "feat(recruiting): RG-Referenz-Code in Flynk-Payload (Beschreibung, Meta, Content-Hash)"
```

---

### Task 4: Reconciler verdrahten (Hash-Konsistenz!)

**Files:**
- Modify: `src/Services/Flynk/FlynkPostingReconciler.php` (Zeilen 37-38 Eager-Load, Zeile 125 Detect-Hash, Zeilen 235-247 `postingData()`)

**Interfaces:**
- Consumes: `FlynkPostingPayloadBuilder::contentHash(..., ?string $refCode)` und `$posting['ref_code']` (Task 3); Relation `RecPosting::externalRefs()` → `RecPostingExternalRef::sourcePlatform` (existiert, wird in `Posting/Show` bereits so eager-geloadet).
- Produces: Reconciler sendet für Postings mit Code publish/update-Tasks inkl. Code; Detect-Pass-Hash und Build-Hash sind identisch (keine Endlos-Updates). Für Bestands-Postings ohne Code ändert sich exakt nichts.

**KRITISCH:** `contentHash` wird an zwei Stellen berechnet — in `build()` (Task 3) und im Detect-Pass (Zeile 125). Beide MÜSSEN denselben `refCode` bekommen, sonst erkennt der Decider dauerhaft eine Änderung und schickt der Agentur bei jedem 30-Minuten-Lauf einen Update-Task.

- [ ] **Step 1: Reconciler anpassen**

(a) Zeile 37 — Eager-Load erweitern:

```php
$postings = RecPosting::query()->with(['position:id,title', 'externalRefs.sourcePlatform'])
    ->whereIn('id', $candidateIds)->get()->keyBy('id');
```

(b) Private Helper-Methode ergänzen (neben `postingData()`):

```php
private function refCodeOf(RecPosting $p): ?string
{
    return $p->externalRefs
        ->first(fn ($r) => $r->sourcePlatform?->ref_parser === 'ref_code')
        ?->external_ref;
}
```

(c) `postingData()` (Zeile 235) um den Key erweitern:

```php
private function postingData(RecPosting $p, int $generation): array
{
    return [
        'uuid' => $p->uuid,
        'title' => $p->title,
        'description' => $p->description,
        'activity' => $p->activity,
        'ref_code' => $this->refCodeOf($p),
        'position_title' => $p->position?->title,
        'team_id' => $p->team_id,
        'generation' => $generation,
        'closes_at' => $p->closes_at?->toIso8601String(),
    ];
}
```

(d) Detect-Pass Zeile 125 — denselben Code in den Vergleichs-Hash geben:

```php
$contentHash = FlynkPostingPayloadBuilder::contentHash($p->title, $p->description, $p->activity, $this->refCodeOf($p));
```

- [ ] **Step 2: Syntax-Check + kompletter Testlauf**

```bash
php -l src/Services/Flynk/FlynkPostingReconciler.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, alle Tests PASS (Reconciler selbst ist DB-gebunden und hat per Konvention keine Unit-Tests; der pure `FlynkPostingSyncDecider` ist unverändert).

- [ ] **Step 3: Commit**

```bash
git add src/Services/Flynk/FlynkPostingReconciler.php
git commit -m "feat(recruiting): Flynk-Reconciler reicht RG-Code durch (Build- und Detect-Hash konsistent)"
```

---

### Task 5: Code sichtbar machen (Posting-UI)

**Files:**
- Modify: `src/Livewire/Posting/Show.php` (Computed-Property)
- Modify: `resources/views/livewire/posting/show.blade.php` (Panel „Externe Referenzen", ab Zeile 188)
- Modify: `src/Livewire/Posting/Index.php:69-87` (`createPosting()` — Flash-Message mit Code)

**Interfaces:**
- Consumes: `PostingRefCodeService::codeFor(RecPosting): ?string` (Task 1).
- Produces: nur UI; keine nachgelagerten Abhängigkeiten.

**Hinweis:** Der Code taucht als External-Ref („Referenz-Code") ohnehin in der bestehenden Ref-Liste des Panels auf — dieser Task macht ihn zusätzlich als hervorgehobenen Handlungshinweis sichtbar, damit HR ihn beim manuellen Einstellen auf Stepstone/Indeed mitnimmt. Blade-Pitfall-Regel des Repos beachten: keine Inline-`@if` in `x-ui-*`-Attributen — hier nur plain HTML, unkritisch.

- [ ] **Step 1: Computed-Property in Show.php**

Import ergänzen: `use Platform\Recruiting\Services\PostingRefCodeService;` — dann neben den bestehenden `#[Computed]`-Methoden:

```php
#[Computed]
public function refCode(): ?string
{
    return app(PostingRefCodeService::class)->codeFor($this->posting);
}
```

- [ ] **Step 2: Hinweis-Block im Blade**

In `resources/views/livewire/posting/show.blade.php` direkt nach der Panel-Öffnung (Zeile 188, vor dem `@if($posting->externalRefs->count() > 0)`):

```blade
@php($refCode = $this->refCode)
@if($refCode)
    <div class="mb-4 rounded-lg border border-[var(--ui-border)] p-3">
        <div class="text-xs text-[var(--ui-muted)]">Referenz-Code für Anzeigen (Stepstone, Indeed, Karriereseite)</div>
        <div class="text-lg font-mono font-semibold">{{ $refCode }}</div>
        <div class="mt-1 text-xs text-[var(--ui-muted)]">
            Diesen Code sichtbar in jede Anzeige aufnehmen (idealerweise ins Referenznummer-Feld des Portals oder in den Titel).
            Bewerbungen, die den Code enthalten, werden automatisch dieser Ausschreibung zugeordnet — ohne LLM-Zuordnung.
        </div>
    </div>
@endif
```

- [ ] **Step 3: Flash-Message beim Erstellen (Index.php)**

In `src/Livewire/Posting/Index.php::createPosting()` — Import `use Platform\Recruiting\Services\PostingRefCodeService;` ergänzen und den Create-Block anpassen:

```php
$posting = RecPosting::create([
    'rec_position_id' => $this->rec_position_id,
    'title' => $this->title,
    'description' => $this->description,
    'activity' => $this->activity ?: null,
    'team_id' => auth()->user()->currentTeam->id,
    'created_by_user_id' => auth()->id(),
    'status' => 'draft',
    'is_active' => true,
]);

$refCode = app(PostingRefCodeService::class)->codeFor($posting);

$this->resetForm();
$this->modalShow = false;
session()->flash('message', $refCode
    ? "Ausschreibung erfolgreich erstellt. Referenz-Code für Anzeigen: {$refCode}"
    : 'Ausschreibung erfolgreich erstellt.');
```

- [ ] **Step 4: Syntax-Check + Tests**

```bash
php -l src/Livewire/Posting/Show.php && php -l src/Livewire/Posting/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected` (2×), alle Tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Posting/Show.php src/Livewire/Posting/Index.php resources/views/livewire/posting/show.blade.php
git commit -m "feat(recruiting): RG-Referenz-Code prominent in Posting-UI anzeigen"
```

---

### Task 6: End-to-End-Smoke + Abschluss

**Files:** keine (Verifikation)

- [ ] **Step 1: Kompletter Testlauf**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: PASS, 0 Failures.

- [ ] **Step 2: Manueller Smoke in der lokalen App (meingedeck)**

1. Neue Ausschreibung über die Posting-UI anlegen → Detailseite öffnen → im Panel „Externe Referenzen" muss der hervorgehobene `RG-XXXX`-Block UND ein Listeneintrag unter Quelle „Referenz-Code" erscheinen.
2. Eine Direkteinstellung mit `intake_mode='code'` anlegen → es darf genau EIN Code existieren (kein Duplikat durch Hook + expliziten Aufruf); der im UI angezeigte Code muss dem External-Ref entsprechen.
3. `php artisan recruiting:flynk-reconcile` (bzw. den Command-Namen aus dem Scheduler) für ein Team mit dem neuen Posting laufen lassen → in `rec_posting_flynk_syncs` entsteht ein publish-Task; Payload-Beschreibung enthält „Referenz-Code: RG-…". Danach den Command ERNEUT laufen lassen → es darf KEIN weiterer update-Task für dasselbe Posting entstehen (Hash-Konsistenz-Beweis).
4. Bestands-Posting ohne Code: Reconciler-Doppellauf erzeugt ebenfalls keine neuen Tasks (Legacy-Hash unverändert).

- [ ] **Step 3: Branch abschließen**

Gemäß `superpowers:finishing-a-development-branch` — Merge/PR-Entscheidung beim User. Erinnerung aus den Projekt-Regeln: nach Push des Moduls muss die `composer.lock` in meingedeck gebumpt werden, sonst ist der Stand nicht live; nach Deploy `queue:restart` (Reconciler/Jobs laufen sonst mit altem Code).

---

## Bewusst NICHT im Scope (Nutzer-Entscheidung / organisatorisch)

- **Kein Backfill** für bestehende Ausschreibungen.
- **Kein automatischer Portal-Export** (Stepstone/Indeed bleiben manuell) — HR trägt den Code beim Einstellen ein; der UI-Hinweis (Task 5) stützt das.
- **Verifikation pro Portal**, dass der Code aus der Anzeige in der Bewerbungs-Benachrichtigungsmail ankommt (Testbewerbung) — organisatorisch, nach Rollout.
- **Absprache mit der Website-Agentur**, dass der Code auf der Karriereseite sichtbar bleibt bzw. das Bewerbungsformular ihn (oder die bestehende `Posting-Ref`-Zeile) mitschickt.
- Keine Änderung an `ApplicationMatchingService`/`RefCodeParser` — Stufe 1 verarbeitet den Code bereits.
