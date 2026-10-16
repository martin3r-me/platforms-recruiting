# Kommunikation gelesen = erledigt — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Den Erledigt-Knopf abschaffen: offen = ungelesen ohne gültigen Stempel; Button-Klicks stempeln sich selbst; einmaliges Aufräumen über das Archiv-Kommando.

**Architecture:** `InboxQuery` leitet „offen“ aus `is_unread` (Core) UND `rec_conversation_handled` (eigener Stempel) ab. Stempel kommen aus Mehrfachauswahl (`manual`), Archiv-Lauf (`backfill`) und neu aus dem Inbound-Listener bzw. Archiv-Lauf `--quick-replies` (`quick_reply`). Die Livewire-Komponente erledigt beim Öffnen die HR-Weiterleitungen und holt sie bei „ungelesen schließen“ zurück.

**Tech Stack:** PHP 8 / Laravel / Livewire 3, Tests: PHPUnit mit Capsule+SQLite (`meingedeck/vendor/bin/phpunit -c phpunit.xml`).

**Spec:** `docs/superpowers/specs/2026-10-16-kommunikation-gelesen-erledigt-design.md`

## Global Constraints

- Kein Edit in platform-crm / platforms-core. Kein Massen-Update auf `comms_whatsapp_threads`.
- Keine Migration (`handled_reason` ist `string(32)`).
- Quick-Reply = `meta_payload.type === 'button'` ODER (`type === 'interactive'` UND `interactive.type` ∈ {`button_reply`,`list_reply`}).
- Neuer Grund: `RecConversationHandled::REASON_QUICK_REPLY = 'quick_reply'`.
- Dispo-Postfach bleibt unverändert.

## Review Focus

1. Chat mit altem gültigem Stempel wird per „ungelesen schließen“ zurückgelegt → muss offen werden (Stempel löschen). Test in Task 4.
2. Button-Klick, danach getippte Nachricht → offen (Stempel durch neueren Eingang ungültig). Test in Task 2/3.
3. Weiterleitung beim Öffnen erledigt, dann „ungelesen schließen“ → Weiterleitung wieder offen, aber eine schon vorher erledigte bleibt erledigt. Test in Task 4.
4. Archiv-Lauf trifft Thread mit veraltetem Stempel (neuer Eingang danach, >14 Tage alt) → muss neu gestempelt werden (heute ausgeschlossen + insert-Konflikt). Test in Task 5.
5. Gelesener, unbeantworteter, alter Chat in „Alle“ → keine Farbkante/„verpasst“ (Eskalation NONE). Test in Task 3.

---

### Task 1: Quick-Reply-Erkennung

**Files:**
- Create: `src/Services/Comms/QuickReply.php`
- Modify: `src/Models/RecConversationHandled.php` (Konstante)
- Test: `tests/Unit/Comms/QuickReplyTest.php`

**Interfaces:** Produces `QuickReply::isQuickReply(?array $metaPayload): bool`, `RecConversationHandled::REASON_QUICK_REPLY`.

- [ ] Test: `button` → true; `interactive/button_reply` → true; `interactive/list_reply` → true; `text` → false; `interactive/nfm_reply` → false; `null`/`[]` → false.
- [ ] Rot laufen lassen, implementieren (rein, ohne Laravel), grün, commit `feat(recruiting): Kommunikation — Quick-Reply-Erkennung aus meta_payload`.

### Task 2: Listener stempelt Button-Klicks

**Files:**
- Create: `src/Services/Comms/QuickReplyStamper.php`
- Modify: `src/Listeners/HandleWhatsAppInboundForRecruiting.php` (ganz oben in `handle()`, try/catch wie OOO)
- Test: `tests/Integration/QuickReplyStamperTest.php`

**Interfaces:** Produces `QuickReplyStamper::stampIfQuickReply(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): bool` — upsert auf `comms_whatsapp_thread_id`, `handled_at = now()`, `handled_by_user_id = null`, Grund `quick_reply`, `team_id = $thread->team_id`. Nur inbound-Nachrichten.

- [ ] Tests: Button-Nachricht → Stempel; Text → kein Stempel; vorhandener alter manual-Stempel → überschrieben mit quick_reply und neuem handled_at; outbound → kein Stempel.
- [ ] Implementieren, Listener-Aufruf einbauen (Fehler nur loggen), grün, commit.

### Task 3: InboxQuery — offen = ungelesen ohne Stempel

**Files:**
- Modify: `src/Services/Comms/InboxQuery.php`, `src/Services/Comms/InboxFilter.php`
- Test: `tests/Integration/InboxQueryCompletenessTest.php` (anpassen), neuer Test `tests/Integration/InboxGelesenErledigtTest.php`

Regeln:
- `scored()`: `'open' => (bool)$thread->is_unread && !ConversationHandledState::isHandled(...)`; Eskalation nur für offene berechnen, sonst `ConversationEscalation::compute(null, null, $now)` (= NONE). `is_unread` in der Zeile = `open`.
- `countsFromScored()`: `total/applicants/employees` über alle Zeilen; `unread` + Ampel nur offene. Schlüssel `handled` entfällt.
- `matches()`: Zweig `handled` raus; `level='all'` = alle; `unread` = offen; Ampelstufe = offen UND Stufe.
- `rowForThread()` gleich behandeln.
- `InboxFilter::$handled` entfernen.

- [ ] Neuer Test: gelesener alter Chat erscheint unter „Alle“ mit LEVEL_NONE, nicht unter `missed`; ungelesener alter Chat unter `missed`; ungelesen + gültiger Stempel → nicht offen; ungelesen + Stempel älter als Eingang → offen; Zähler-Satz exakt.
- [ ] Bestandstest umstellen (Fixtures `is_unread=false` → für die Eskalations-Aussagen `true` setzen; Erledigt-Filter-Test entfernen; Zählersatz neu).
- [ ] Alle Inbox*-Tests grün, commit.

### Task 4: Komponente + Ansicht

**Files:**
- Modify: `src/Services/Comms/Forward/ForwardCompletion.php` (zwei Methoden dazu), `src/Livewire/Conversations/Inbox.php`, `resources/views/livewire/conversations/inbox.blade.php`
- Test: `tests/Integration/ForwardCompletionTest.php` (erweitern)

**Interfaces:**
- `ForwardCompletion::completeForThreadIds(int $teamId, int $threadId, ?int $userId): list<int>` — IDs der jetzt erledigten.
- `ForwardCompletion::reopen(int $teamId, int $threadId, array $forwardIds, ?int $userId): int` — nur IDs aus der Liste, `target_thread_id = $threadId`, Team, `done_by_user_id = $userId`.

Komponente:
- `#[Locked] public array $openedForwardIds = [];`
- `select()`: nach `markAsRead()` → `$this->openedForwardIds = completeForThreadIds(...)`, `unset($this->openForwardCount, $this->forwardCards)`.
- `markUnreadAndClose()`: `markAsUnread()`, Stempel des Threads löschen (team-scharf), `reopen(..., $this->openedForwardIds, Auth::id())`, `$this->openedForwardIds = []`.
- `markHandled()`, `unmarkHandled()`, `toggleHandledView()`, `$showHandled` entfernen (inkl. aller Zuweisungen).
- `markSelectedHandled()` bleibt, Meldetext „als gelesen markiert“.
- Blade: Pille „Erledigt“ raus, Knöpfe „Erledigt“/„zurückholen“ raus, Leerzustand `$showHandled` raus, Bulk-Knopf „als gelesen“.

- [ ] Tests ForwardCompletion: completeForThreadIds liefert IDs; reopen öffnet nur gemerkte, nicht vorher erledigte, nicht fremdes Team.
- [ ] Umsetzen, `tools/blade-check.php` auf die View, Suite grün, commit.

### Task 5: Archiv-Kommando

**Files:**
- Modify: `src/Console/Commands/ArchiveOldConversations.php`
- Test: `tests/Integration/ArchiveOldConversationsCommandTest.php` (erweitern)

- `planFor()`: nur Threads mit GÜLTIGEM Stempel ausschließen (`ConversationHandledState::isHandled`), veraltete werden neu gestempelt.
- `stamp()`: `upsert` auf `comms_whatsapp_thread_id` statt `insert`; Grund als Parameter.
- Neue Option `--quick-replies`: statt Altersgrenze die Threads, deren letzte eingehende Nachricht (`MAX(id)` je Thread, direction inbound) `QuickReply::isQuickReply` ist; Grund `quick_reply`. `--older-than` wird dann ignoriert.

- [ ] Tests: veralteter Stempel → im Plan; Quick-Reply-Thread → im Plan, Thread mit Button und danach Text → nicht; dry-run schreibt nichts.
- [ ] Umsetzen, grün, commit.

### Abschluss

- [ ] Volle Suite (`grep -q '^OK'`), Review-Agent über den Branch, Funde prüfen, Spec-Deploy-Abschnitt aktuell.
