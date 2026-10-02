# Dispo → HR weiterleiten, Runde 2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Weiterleitungs-Karte im HR-Chat steht im Verlauf und verschwindet, sobald die Weiterleitung erledigt ist. „Erledigt" im HR-Chat erledigt sie mit. Anhänge sind für HR öffnenbar. Die Detailansicht im Reiter springt ans Ende.

**Architecture:** Zwei Services und ein purer Helfer unter `src/Services/Comms/Forward/`:
- `ForwardCompletion`: Weiterleitungen eines HR-Chats erledigen.
- `ForwardAttachments`: Anhänge frisch zu den gespeicherten `message_id`s holen.
- `ForwardTimeline` (pure): Karten stabil in die Nachrichtenliste einsortieren.

Dazu kommen kleine Eingriffe in `Inbox`, `Forwards`, das geteilte Partial `dispo/_messages` und `_forward-card`. CRM/Core werden nur gelesen.

**Tech Stack:** Laravel/Livewire 3, Eloquent, PHPUnit (Unit pur + Integration mit Capsule/SQLite), Blade + Tailwind.

**Spec:** `docs/superpowers/specs/2026-10-02-dispo-an-hr-weiterleiten-design.md`, Abschnitt „Runde 2". Der Rest der Spec gilt weiter.

## Global Constraints

- Nichts außerhalb von `platforms-recruiting` ändern (CRM/Core nur lesen).
- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`.
- Unit-Tests ohne vendor-Autoload: Pure-Klassen ohne Carbon/Laravel, Zeit als Unix-Timestamp.
- Integration-Tests: Capsule SQLite `:memory:`, Muster `tests/Integration/ForwardFirstContactTest.php`.
- Blade mit `php tools/blade-check.php <datei>` prüfen. Keine inline-`@if` in `x-ui-*`-Attributen, keine an Wortzeichen geklebten Direktiven, `@php … @endphp` nur in Blockform.
- Die bestehende Reihenfolge der Chat-Nachrichten bleibt unverändert. Karten werden nur einsortiert.
- Anhänge werden nie gespeichert, immer beim Anzeigen frisch geholt (signierte URLs, 60 min).
- Nur offene HR-Weiterleitungen (`openForTeam($team, ForwardTargets::HR)`) erscheinen als Karte im HR-Chat.
- `unmarkHandled` und HR-Antworten öffnen oder erledigen Weiterleitungen **nicht**.
- UI-Texte Deutsch mit echten Umlauten. Commit-Footer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

## Review Focus

1. **Sammel-Abhaken mit fremden IDs:** `markSelectedHandled` erledigt Weiterleitungen nur für die IDs, die `ConversationBulkHandler` tatsächlich gestempelt hat (`$handledIds`), nie für die rohen `$this->selected`. Getestet über `ForwardCompletion` mit Team-Filter (Task 1).
2. **Schon erledigte Weiterleitung:** Ein erneutes Abhaken überschreibt `done_at`/`done_by_user_id` nicht (Task 1, `test_bereits_erledigte_bleiben_unveraendert`).
3. **Unsortierte Nachrichten:** Das Einsortieren darf die Reihenfolge der Bestandszeilen nie ändern, auch wenn deren `ts` nicht monoton ist (Task 2, `test_bestandsreihenfolge_bleibt_auch_bei_unsortierten_ts`).
4. **Anhang ohne URL / gelöschte Datei:** Einträge ohne `url` werden verworfen, die Karte fällt auf das Medien-Label zurück (Task 2, `test_eintraege_ohne_url_fallen_weg`).
5. **VA-Chat und Dispo-Chat:** Der neue `forward`-Zweig im geteilten Partial greift nur bei `kind === 'forward'`, also nur dort, wo `Inbox` solche Zeilen erzeugt (Task 3, blade-check + `MessagesPartialIncludeContractTest`).

---

### Task 1: „Erledigt" im HR-Chat erledigt die Weiterleitung

**Files:**
- Create: `src/Services/Comms/Forward/ForwardCompletion.php`
- Modify: `src/Livewire/Conversations/Inbox.php` (`markHandled`, `markSelectedHandled`)
- Test: `tests/Integration/ForwardCompletionTest.php`

**Interfaces:**
- Produces: `ForwardCompletion::completeForThreads(int $teamId, array $threadIds, ?int $userId): int`. Setzt bei allen **offenen** HR-Weiterleitungen des Teams, deren `target_thread_id` in `$threadIds` liegt, `done_at = now()` und `done_by_user_id = $userId`. Rückgabe ist die Anzahl. IDs ≤ 0 und Dubletten werden ignoriert, leere Liste ergibt 0.

- [ ] **Step 1: Failing test** `tests/Integration/ForwardCompletionTest.php`. Capsule-Setup wie `tests/Integration/ConversationForwardTableTest.php` (nur die Forward-Migration). Fälle:

```php
    private function forward(array $attrs): RecConversationForward
    {
        return RecConversationForward::create(array_merge([
            'team_id' => self::TEAM, 'source_thread_id' => 1, 'phone' => '+491700000001',
            'display_name' => 'X', 'messages' => [], 'forwarded_at' => '2026-10-02 09:00:00',
        ], $attrs));
    }

    public function test_erledigt_offene_hr_weiterleitungen_des_chats(): void
    {
        $a = $this->forward(['target_thread_id' => 50]);
        $b = $this->forward(['target_thread_id' => 50]);
        $n = (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7);
        $this->assertSame(2, $n);
        $this->assertNotNull($a->fresh()->done_at);
        $this->assertSame(7, (int) $b->fresh()->done_by_user_id);
    }

    public function test_fremdes_team_anderes_ziel_anderer_chat_bleiben_offen(): void
    {
        $fremd = $this->forward(['team_id' => self::TEAM + 1, 'target_thread_id' => 50]);
        $ziel = $this->forward(['target' => 'lohn', 'target_thread_id' => 50]);
        $chat = $this->forward(['target_thread_id' => 51]);
        $ohne = $this->forward(['target_thread_id' => null]);
        (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7);
        foreach ([$fremd, $ziel, $chat, $ohne] as $f) {
            $this->assertNull($f->fresh()->done_at);
        }
    }

    public function test_bereits_erledigte_bleiben_unveraendert(): void
    {
        $f = $this->forward(['target_thread_id' => 50, 'done_at' => '2026-10-01 08:00:00', 'done_by_user_id' => 3]);
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7));
        $this->assertSame('2026-10-01 08:00:00', $f->fresh()->done_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, (int) $f->fresh()->done_by_user_id);
    }

    public function test_leere_und_ungueltige_ids(): void
    {
        $this->forward(['target_thread_id' => 50]);
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [], 7));
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [0, -1], 7));
    }
```

- [ ] **Step 2:** Test laufen lassen → ERROR „Class not found".
- [ ] **Step 3: Implement**

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Recruiting\Models\RecConversationForward;

/**
 * "Erledigt" im HR-Chat erledigt auch die offenen Weiterleitungen dieses
 * Chats (Spec Runde 2, 02.10.2026) — sonst haekt HR an zwei Stellen ab.
 * Bereits erledigte bleiben unberuehrt (done_at wird nie ueberschrieben).
 */
final class ForwardCompletion
{
    /** @param array<int, int|string> $threadIds */
    public function completeForThreads(int $teamId, array $threadIds, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $threadIds),
            static fn (int $id) => $id > 0,
        )));
        if ($ids === []) {
            return 0;
        }

        return RecConversationForward::query()
            ->openForTeam($teamId, ForwardTargets::HR)
            ->whereIn('target_thread_id', $ids)
            ->update(['done_at' => now(), 'done_by_user_id' => $userId, 'updated_at' => now()]);
    }
}
```

- [ ] **Step 4:** Test → PASS (4 tests).
- [ ] **Step 5: Inbox anbinden.** In `markHandled()` direkt nach `RecConversationHandled::updateOrCreate(...)`:

```php
        app(\Platform\Recruiting\Services\Comms\Forward\ForwardCompletion::class)
            ->completeForThreads($this->teamId(), [$threadId], Auth::id() !== null ? (int) Auth::id() : null);
        unset($this->openForwardCount, $this->forwardCards);
```

In `markSelectedHandled()` direkt nach der Zuweisung von `$handledIds`, mit `$handledIds` und **nicht** mit `$this->selected`:

```php
        app(\Platform\Recruiting\Services\Comms\Forward\ForwardCompletion::class)
            ->completeForThreads($this->teamId(), $handledIds, Auth::id() !== null ? (int) Auth::id() : null);
        unset($this->openForwardCount, $this->forwardCards);
```

- [ ] **Step 6:** Volle Suite, Commit `feat(recruiting): Erledigt im HR-Chat erledigt auch die Weiterleitung`.

---

### Task 2: Einsortieren (pure) + Anhänge frisch holen

**Files:**
- Create: `src/Services/Comms/Forward/ForwardTimeline.php`, `src/Services/Comms/Forward/ForwardAttachments.php`
- Modify: `resources/views/livewire/conversations/_forward-card.blade.php`, `src/Livewire/Conversations/Forwards.php::selected()`
- Test: `tests/Unit/ForwardTimelineTest.php`, `tests/Unit/ForwardAttachmentsMappingTest.php`

**Interfaces:**
- Produces:
  - `ForwardTimeline::insert(array $rows, array $cards): array`. `$rows` und `$cards` sind Listen mit mindestens `ts:int`. Jede Karte (aufsteigend nach `ts`, bei Gleichstand in Eingabereihenfolge) wird **vor der ersten Bestandszeile mit `ts` > Karten-`ts`** eingefügt, die nach der zuletzt eingefügten Karte kommt. Ohne solche Zeile kommt sie ans Ende. Die Reihenfolge der Bestandszeilen bleibt immer unverändert.
  - `ForwardAttachments::map(array $attachments, ?string $mediaType): list<array{url:string, thumbnail:?string, title:string, media_type:?string}>` (pure). Eingabe ist die Ausgabe von `CommsWhatsAppMessage::attachments` (Core `getFileReferencesArray`, Schlüssel `url`, `thumbnail`, `title`). Einträge ohne nicht-leere `url` fallen weg, `title` fällt auf `'Datei'` zurück.
  - `ForwardAttachments::forMessageIds(array $messageIds): array<int, list<…>>`. Lädt `CommsWhatsAppMessage::query()->whereIn('id', …)->get()`, nimmt nur Nachrichten mit `hasMedia()` und liefert `message_id => map($m->attachments, $m->media_display_type)`. Nachrichten ohne Treffer fehlen im Ergebnis. Fehler werden geschluckt (`try/catch` → `[]`), weil die Karte auch ohne Anhang anzeigbar bleiben muss.

- [ ] **Step 1: Failing unit tests**

`tests/Unit/ForwardTimelineTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardTimeline;

class ForwardTimelineTest extends TestCase
{
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $r) => $r['id'], $rows);
    }

    public function test_ohne_karten_unveraendert(): void
    {
        $rows = [['id' => 'a', 'ts' => 10], ['id' => 'b', 'ts' => 20]];
        $this->assertSame($rows, ForwardTimeline::insert($rows, []));
    }

    public function test_karte_landet_nach_gleichzeitigen_und_vor_spaeteren(): void
    {
        $rows = [['id' => 'a', 'ts' => 10], ['id' => 'b', 'ts' => 20], ['id' => 'c', 'ts' => 30]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K', 'ts' => 20]]);
        $this->assertSame(['a', 'b', 'K', 'c'], self::ids($out));
    }

    public function test_spaete_karte_ans_ende_mehrere_karten_sortiert(): void
    {
        $rows = [['id' => 'a', 'ts' => 10]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K2', 'ts' => 99], ['id' => 'K1', 'ts' => 5]]);
        $this->assertSame(['K1', 'a', 'K2'], self::ids($out));
    }

    public function test_bestandsreihenfolge_bleibt_auch_bei_unsortierten_ts(): void
    {
        $rows = [['id' => 'a', 'ts' => 30], ['id' => 'b', 'ts' => 10], ['id' => 'c', 'ts' => 40]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K', 'ts' => 20]]);
        $bestand = array_values(array_filter(self::ids($out), static fn ($id) => $id !== 'K'));
        $this->assertSame(['a', 'b', 'c'], $bestand);
        $this->assertCount(4, $out);
    }
}
```

`tests/Unit/ForwardAttachmentsMappingTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardAttachments;

class ForwardAttachmentsMappingTest extends TestCase
{
    public function test_mappt_url_vorschau_titel(): void
    {
        $out = ForwardAttachments::map([
            ['url' => 'https://x/a', 'thumbnail' => 'https://x/t', 'title' => 'Lohnzettel.pdf'],
        ], 'document');
        $this->assertSame([['url' => 'https://x/a', 'thumbnail' => 'https://x/t', 'title' => 'Lohnzettel.pdf', 'media_type' => 'document']], $out);
    }

    public function test_eintraege_ohne_url_fallen_weg(): void
    {
        $out = ForwardAttachments::map([['url' => null, 'title' => 'weg'], ['url' => '', 'title' => 'leer'], ['url' => 'https://x/b']], 'image');
        $this->assertCount(1, $out);
        $this->assertSame('Datei', $out[0]['title']);
        $this->assertNull($out[0]['thumbnail']);
    }
}
```

> Hinweis: `ForwardAttachments` importiert per `use` CRM-Klassen. In Unit-Tests ohne vendor-Autoload schadet das nicht, solange `map()` sie nicht berührt (PHP löst `use` erst beim Gebrauch auf). `ForwardMessageSelection`/`ForwardMediaLabelTest` folgen demselben Muster. Falls der Test trotzdem scheitert, `map()` in eine eigene pure Klasse `ForwardAttachmentMap` auslagern und das im Report begründen.

- [ ] **Step 2:** Tests → ERROR „Class not found".
- [ ] **Step 3: Implement**

`src/Services/Comms/Forward/ForwardTimeline.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Sortiert Weiterleitungs-Karten in eine Nachrichtenliste ein, ohne die
 * Bestandszeilen umzusortieren (Spec Runde 2: die Reihenfolge des Chats
 * bleibt, wie sie ist). Pure, Zeit als Unix-Timestamp.
 */
final class ForwardTimeline
{
    /**
     * @param list<array<string, mixed>> $rows  Bestandszeilen mit 'ts'
     * @param list<array<string, mixed>> $cards Karten mit 'ts'
     * @return list<array<string, mixed>>
     */
    public static function insert(array $rows, array $cards): array
    {
        if ($cards === []) {
            return $rows;
        }
        $order = array_keys($cards);
        usort($order, static fn (int $a, int $b) => [$cards[$a]['ts'], $a] <=> [$cards[$b]['ts'], $b]);

        $out = [];
        $i = 0;
        $n = count($rows);
        foreach ($order as $k) {
            while ($i < $n && (int) $rows[$i]['ts'] <= (int) $cards[$k]['ts']) {
                $out[] = $rows[$i++];
            }
            $out[] = $cards[$k];
        }
        while ($i < $n) {
            $out[] = $rows[$i++];
        }

        return $out;
    }
}
```

`src/Services/Comms/Forward/ForwardAttachments.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppMessage;

/**
 * Anhaenge weitergeleiteter Nachrichten — immer FRISCH zur message_id, nie
 * gespeichert: die Datei-URLs sind signiert und laufen nach 60 Minuten ab
 * (Core ContextFile::getUrlAttribute). Zugriff wie im Dispo-Chat.
 */
final class ForwardAttachments
{
    /**
     * @param array<int, mixed> $attachments Ausgabe von CommsWhatsAppMessage::attachments
     * @return list<array{url:string, thumbnail:?string, title:string, media_type:?string}>
     */
    public static function map(array $attachments, ?string $mediaType): array
    {
        $out = [];
        foreach ($attachments as $att) {
            $url = is_array($att) ? (string) ($att['url'] ?? '') : '';
            if ($url === '') {
                continue;
            }
            $thumb = (string) ($att['thumbnail'] ?? '');
            $title = trim((string) ($att['title'] ?? ''));
            $out[] = [
                'url' => $url,
                'thumbnail' => $thumb !== '' ? $thumb : null,
                'title' => $title !== '' ? $title : 'Datei',
                'media_type' => $mediaType,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, int|string> $messageIds
     * @return array<int, list<array{url:string, thumbnail:?string, title:string, media_type:?string}>>
     */
    public function forMessageIds(array $messageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $result = [];
            foreach (CommsWhatsAppMessage::query()->whereIn('id', $ids)->get() as $m) {
                if (!method_exists($m, 'hasMedia') || !$m->hasMedia()) {
                    continue;
                }
                $mapped = self::map((array) ($m->attachments ?? []), (string) $m->media_display_type);
                if ($mapped !== []) {
                    $result[(int) $m->id] = $mapped;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
```

- [ ] **Step 4:** Unit-Tests → PASS (6 tests).
- [ ] **Step 5: Karte zeigt Anhänge.** In `_forward-card.blade.php`:
  - Kopfkommentar um `attachments` (optional, `message_id => list{url, thumbnail, title, media_type}`) ergänzen.
  - Im `@foreach ($card['messages'] as $fm)` den `@php`-Block erweitern um `$fmAtts = $card['attachments'][(int) ($fm['message_id'] ?? 0)] ?? [];`.
  - Die Zeile `📎 {{ …mediaLabel(...) }}` ersetzen: gibt es `$fmAtts`, je Anhang bei `media_type` in `['image', 'sticker']` ein `<a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="block py-0.5"><img src="{{ $att['thumbnail'] ?? $att['url'] }}" alt="{{ $att['title'] }}" class="max-h-40 max-w-full rounded-lg object-cover" loading="lazy"></a>`, sonst ein Link `<a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="flex items-center gap-1.5 py-0.5 text-[13px] font-medium text-blue-700 underline">📄 {{ $att['title'] }}</a>`. Ohne `$fmAtts`, aber mit `media_type`, bleibt die bisherige Zeile `📎 {{ mediaLabel }}`.
  - Blockform-Direktiven, nichts an Wortzeichen geklebt.
- [ ] **Step 6: `Forwards::selected()`** ergänzt im Rückgabe-Array `'attachments' => app(ForwardAttachments::class)->forMessageIds(array_column((array) $f->messages, 'message_id')),` (Import ergänzen).
- [ ] **Step 7:** `php tools/blade-check.php resources/views/livewire/conversations/_forward-card.blade.php`, volle Suite, Commit `feat(recruiting): Weiterleitungskarte zeigt Anhaenge zum Oeffnen`.

---

### Task 3: Karte im Verlauf des HR-Chats + Runterscrollen im Reiter

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php` (`forwardCards`, `messages`)
- Modify: `resources/views/livewire/dispo/_messages.blade.php` (Zweig `kind === 'forward'`)
- Modify: `resources/views/livewire/conversations/inbox.blade.php` (angeheftete Karten entfernen)
- Modify: `resources/views/livewire/conversations/forwards.blade.php` (Auto-Scroll)

**Interfaces:**
- Consumes: `ForwardTimeline::insert`, `ForwardAttachments::forMessageIds` (Task 2).

- [ ] **Step 1: `Inbox::forwardCards()`**: nur **offene** HR-Weiterleitungen, also `RecConversationForward::query()->openForTeam($this->teamId(), ForwardTargets::HR)->where('target_thread_id', $this->selectedThreadId)->orderBy('forwarded_at')->orderBy('id')->get()`. Je Karte kommen zu den bisherigen Feldern hinzu: `'ts' => $f->forwarded_at->getTimestamp()`, `'at' => $f->forwarded_at` (für Tag/Uhrzeit) und `'attachments' => app(ForwardAttachments::class)->forMessageIds(array_column((array) $f->messages, 'message_id'))`.
- [ ] **Step 2: `Inbox::messages()`**:

```php
        $rows = app(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::class)
            ->messages($thread, []);

        // Spec Runde 2: offene Weiterleitungen stehen IM Verlauf (zum Zeitpunkt
        // der Weiterleitung) statt fest darueber — sie scrollen mit, und erledigte
        // verschwinden. Bestandsreihenfolge bleibt (ForwardTimeline).
        $cards = array_map(static function (array $c) {
            $at = \Illuminate\Support\Carbon::instance($c['at']);
            return [
                'id' => null, 'ts' => $c['ts'], 'kind' => 'forward', 'direction' => 'note', 'card' => $c,
                'time' => $at->format('H:i'), 'day' => $at->format('Y-m-d'),
                'day_label' => \Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::dayLabel($at),
                'status' => null, 'media_type' => null, 'attachments' => [], 'body' => '',
                'template_label' => null, 'template_buttons' => [], 'at' => $at->format('d.m.Y H:i'),
            ];
        }, $this->forwardCards);

        return \Platform\Recruiting\Services\Comms\Forward\ForwardTimeline::insert($rows, $cards);
```

  Vorher prüfen, ob `$this->messages` in `Inbox` noch woanders als im Blade genutzt wird (`grep -n 'this->messages' src/Livewire/Conversations/Inbox.php`). Falls ja und die Nutzung zählt Nachrichten oder liest `direction`, dort die `forward`-Zeilen herausfiltern und im Report nennen.
- [ ] **Step 3: Partial** `dispo/_messages.blade.php`: direkt nach dem `note`-Zweig einen weiteren Zweig einfügen:

```blade
    @elseif (($message['kind'] ?? '') === 'forward')
        <div class="w-full">
            @include('recruiting::livewire.conversations._forward-card', ['card' => $message['card']])
        </div>
```

  Kopfkommentar des Partials ergänzen: Zeilen mit `kind "forward"` sind interne Weiterleitungs-Karten (nur HR-Chat).
- [ ] **Step 4: `inbox.blade.php`**: den Block `@foreach ($this->forwardCards as $card) … @endforeach` über dem Verlauf ersatzlos entfernen.
- [ ] **Step 5: `forwards.blade.php`**: der Scroll-Container der Detailansicht (`<div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">`) bekommt `wire:key="fwd-detail-{{ $sel['id'] }}-{{ count($sel['hr_messages']) }}" x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"`.
- [ ] **Step 6:** `php tools/blade-check.php` auf alle drei geänderten Blade-Dateien, volle Suite (inkl. `MessagesPartialIncludeContractTest`, `SharedPartialContractTest`), Commit `feat(recruiting): Weiterleitungskarte im Verlauf des HR-Chats, Reiter springt ans Ende`.

---

### Task 4: Abschluss

- [ ] Volle Suite + `php tools/blade-check.php` (alle Views).
- [ ] Whole-Branch-Review (stärkstes Modell) gegen Spec „Runde 2" + Review Focus.
- [ ] Danach Freigabe beim User einholen: ff-Merge auf main, Push, meingedeck-Bump. Deploy **ohne** migrate (keine Schemaänderung), `view:clear`.
