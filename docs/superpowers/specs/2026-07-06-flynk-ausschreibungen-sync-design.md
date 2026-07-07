# Design: FLYNK-Sync für Ausschreibungen

**Datum:** 2026-07-06
**Status:** Entwurf zur Review (Rev. 3 — Revalidierungs-Reihenfolge + Selbstheilung nach 2. Review-Runde)
**Scope:** Ausgehende Übertragung veröffentlichter Ausschreibungen (`RecPosting`) an FLYNK via Task-Webhook.

## 1. Problem & Ziel

Ausschreibungen (`RecPosting`) werden im Recruiting-Modul angelegt und veröffentlicht. Sie sollen auf den von FLYNK betreuten Websites als Stellenanzeigen erscheinen. FLYNK bietet dafür **keinen** eigenen „Stellenanzeige anlegen"-Endpunkt — nur einen generischen **Task-Webhook**, über den man der Agentur Website-Aufgaben stellt (neue Seite/Abschnitt, Textänderung, Entfernung).

**Ziel:** Wenn eine Ausschreibung veröffentlicht, inhaltlich geändert, geschlossen oder wieder aktiviert wird, geht automatisch ein passender **Task** an FLYNK, damit die Anzeige auf der Karriereseite veröffentlicht / aktualisiert / entfernt wird.

**Nicht-Ziel:** Kein Rückkanal (FLYNK → Recruiting), kein Datei-Upload (Postings sind reiner Text), keine Echtzeit-Zustellung.

## 2. FLYNK-Task-Webhook (Referenz)

- `POST {base_url}/webhooks/tasks`, `Authorization: Bearer {token}`, `Content-Type: application/json`
- Pflichtfelder: `title` (max 255), `type` (Enum)
- Optional u. a.: `description`, `priority` (`low|normal|high`, default `normal`), `target_url`, `name`, `email`, `meta` (object)
- Erlaubte `type`-Werte (relevant): `new_section`, `text_change` (nicht erlaubt extern: `feature_request`, `change`)
- Antworten: `201` Erfolg (`{id, title, status, message}`), `401` Token, `422` Validierung, `429` Rate-Limit (>60/min pro IP)
- Rate-Limit: 60 Requests/Minute pro IP

## 3. Getroffene Grundsatz-Entscheidungen

1. **Trigger:** Übertragung bei **Veröffentlichung** (`status = published`), nicht bei reiner Anlage.
2. **Lebenszyklus:** Voller Sync — **Publish**, inhaltliche **Änderung**, **Schließung** und **Reopen** (Wiederveröffentlichung nach Schließung) erzeugen je einen Task.
3. **Technik:** Ein einziges **geplantes Kommando** (`recruiting:flynk-reconcile`, alle 30 Min), kein Model-Observer, kein Queue-Job. Ein einziger Pfad vermeidet Transaktions-Races, Doppel-Sends und implizite Seiteneffekte in Tests/Seedern; deckt zugleich zeitbasierten Ablauf (`closes_at`) ab.
4. **Token:** Global in der Config (`RECRUITING_FLYNK_TOKEN`), ein FLYNK-Projekt für alle Teams. Kein Feature ohne gesetzten Token + `enabled`.
5. **Idempotenz über Generationen:** Eine Tracking-Tabelle `rec_posting_flynk_syncs` merkt jeden Task. **`content_hash` ist NICHT Teil der Eindeutigkeit** (ein Hash kann zurückspringen und taugt daher nicht als Schlüssel). Eindeutigkeit = `(rec_posting_id, generation, event_type, seq)`. Der Decider ist die Wahrheit darüber, *ob* ein Event fällig ist; die Unique-Constraint verhindert nur, dass dieselbe Emission doppelt zugestellt wird.
6. **Test-Konvention:** Modul testet ohne Laravel/DB (siehe ZAS). Entscheidungs- und Mapping-Logik als **pure, DB-freie Klassen**; Kommando/Reconciler/HTTP-Client bleiben dünne Glue-Schicht.

## 4. Konfiguration

Neuer Block in `config/recruiting.php` (analog zum `zas`-Block):

```php
'flynk' => [
    'enabled'     => (bool) env('RECRUITING_FLYNK_ENABLED', false),
    'base_url'    => env('RECRUITING_FLYNK_BASE_URL', 'https://flynk.on-forge.com/api'),
    'token'       => env('RECRUITING_FLYNK_TOKEN'),        // Bearer (Service Credentials → Webhook)
    'careers_url' => env('RECRUITING_FLYNK_CAREERS_URL'),  // optional → task.target_url
    'timeout'     => (int) env('RECRUITING_FLYNK_TIMEOUT', 10),
    'per_run_cap' => (int) env('RECRUITING_FLYNK_PER_RUN_CAP', 50),
    'max_attempts'=> (int) env('RECRUITING_FLYNK_MAX_ATTEMPTS', 5),
],
```

**Guard:** `enabled = false` oder leerer `token` → Kommando beendet sich sofort mit Log-Info, tut nichts. Feature in Lokal/Test/Staging standardmäßig aus.

## 5. Datenmodell

### Migration: `rec_posting_flynk_syncs`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | bigint PK | |
| `rec_posting_id` | FK → `rec_postings` (cascade) | Betroffene Ausschreibung |
| `generation` | unsignedInt, default 1 | Lifecycle-Zyklus. Reopen nach gesendetem close → +1. |
| `event_type` | string `publish\|update\|close` | Art des Tasks |
| `seq` | unsignedInt, default 0 | publish/close = `0`; update = fortlaufend ab `1` pro (posting, generation). |
| `content_hash` | string(64), default `''` | SHA-256 des gesendeten Inhalts bei publish/update; `''` bei close. **Nur Vergleichs-/Datenfeld, nicht Teil der Uniqueness.** |
| `flynk_task_id` | string, nullable | Von FLYNK zurückgegebene Task-ID (nach Erfolg) |
| `status` | string `pending\|sent\|failed` | Zustellstatus. Kein `cancelled`: inkonsistent gewordene, **nicht zugestellte** Zeilen werden gelöscht (siehe 6.3), nicht markiert — sonst blockieren sie ihren Unique-Slot. `sent`-Zeilen werden nie gelöscht. |
| `http_status` | int, nullable | Letzter HTTP-Status |
| `attempts` | unsignedInt, default 0 | Zustellversuche |
| `permanent_failure` | bool, default false | `true` **nur** bei 422 (Datenfehler der Zeile) |
| `last_error` | text, nullable | Fehlerdetails (gekürzt) |
| `sent_at` | timestamp, nullable | Zeitpunkt erfolgreicher Zustellung |
| `created_at` / `updated_at` | timestamps | |

**Unique-Index (Doppel-Send-Gate):** `unique (rec_posting_id, generation, event_type, seq)` — DB-agnostisch, kein partieller Index.
- publish/close: `seq = 0` → genau einmal pro (Posting, Generation).
- update: `seq` inkrementiert → jede Emission distinct, kein Hash-Kollisionsproblem (fixt Hash-Rücksprung).

Modell: `RecPostingFlynkSync` (`Platform\Recruiting\Models`), `belongsTo(RecPosting)`.

## 6. Komponenten

```
recruiting:flynk-reconcile   (Scheduler 30 Min · guard: enabled + token)
   │
   ▼
FlynkPostingReconciler::run()   — EINE „ist das noch fällig?"-Wahrheit für alle Pässe
   │  0. Analyze:     Kandidaten laden; Sync-Zeilen aller Kandidaten in EINEM
   │                  whereIn(posting_id)-Query, in Memory nach posting_id gruppieren (kein N+1);
   │                  je Posting aktuellen Zustand (isOpen, contentHash) + Generation G bilden
   │  1. Revalidate:  FlynkPostingSyncDecider::staleRows(state, undeliveredRows) [PURE]
   │                  → inkonsistente nicht-zugestellte Zeilen LÖSCHEN (siehe 6.4), gelogged
   │  2. Retry-Pass:  verbliebene Zeilen status IN (pending,failed) & !permanent & attempts<max
   │                  → erneut senden (gehören AUSSCHLIESSLICH dem Retry-Pass)
   │  3. Detect-Pass: FlynkPostingSyncDecider::decide(state)  [PURE]
   │                  → je Event: Zeile claimen via insertOrIgnore;
   │                    NUR bei frischem Insert (affectedRows === 1) senden
   │  Gesamt-Cap: per_run_cap Sends/Lauf (über Retry + Detect)
   ▼
FlynkPostingPayloadBuilder::build(posting, event) [PURE] → {payload, content_hash}
   ▼
FlynkClient::createTask(payload) → Ergebnis
   ▼
Zeile finalisieren: status, http_status, flynk_task_id, attempts++, last_error, sent_at
```

| Klasse | Verantwortung | Abhängigkeiten | Test |
|---|---|---|---|
| `FlynkPostingSyncDecider` | `decide()`: fälliges Event aus Zustand + Summary. `staleRows()`: welche nicht-zugestellten Zeilen sind gegen den aktuellen Zustand inkonsistent (Revalidierung). | keine (plain data) | **pure unit** |
| `FlynkPostingPayloadBuilder` | Event + Posting-Daten → Payload **und** `content_hash`. | keine (plain data) | **pure unit** |
| `FlynkClient` | HTTP-Wrapper um `POST /webhooks/tasks`, Auth, Timeout, Ergebnis-/Fehler-Mapping. | `Illuminate\Http\Client` | dünn (`Http::fake()`) |
| `FlynkPostingReconciler` | Kandidaten & Outbox (gebündelt) laden, Generation berechnen, Decider rufen, Zeilen claimen/finalisieren, Cap. | DB, Client, Decider, Builder | Integration |
| `FlynkSyncCommand` (`recruiting:flynk-reconcile`) | Guard, Reconciler starten, Zusammenfassung loggen. | Reconciler | dünn |

### 6.1 Generation & Prädikate

Pro Posting, aus dessen verbliebenen Sync-Zeilen (inkonsistente nicht-zugestellte sind in Schritt 1 bereits gelöscht):

- **`generation` G** = (Anzahl `sent` close-Zeilen) + 1. Reopen erhöht G erst, nachdem der close tatsächlich zugestellt wurde. Ein gelöschter (nie `sent`) close verändert G nicht.
- **`publishRowExists(G)`** = es existiert eine publish-Zeile in Generation G (jeder Status). → unterdrückt erneute publish-Emission (fixt Doppel-publish).
- **`publishSent(G)`** = es existiert eine publish-Zeile in G mit `status = sent`. → Voraussetzung für update/close (eine nie zugestellte Anzeige kann man nicht aktualisieren/entfernen).
- **`closeRowExists(G)`** = close-Zeile in G (jeder Status).
- **`lastDeliverableContentHash(G)`** = `content_hash` der jüngsten publish/update-Zeile in G mit `status IN (pending, sent)` oder `null`. → **`failed` ausgeschlossen:** eine erschöpft-gescheiterte update fällt auf den letzten in-flight/zugestellten Hash zurück → frische Emission → Selbstheilung (Review-Punkt: failed-update). `pending` bleibt drin → eine laufende update wird nicht doppelt emittiert.

> Zwei getrennte publish-Prädikate sind Absicht: `publishRowExists` (Emission unterdrücken) ≠ `publishSent` (Folge-Events erlauben). Ein einzelnes Feld für beide war die Ursache der Review-Punkte 2 und 4.

### 6.2 `FlynkPostingSyncDecider::decide()` (pure)

Eingabe (plain data): `isOpen`, `contentHash`, `generation`, `publishRowExists`, `publishSent`, `closeRowExists`, `lastDeliverableContentHash`.

`isOpen` = `status === 'published' && is_active === true && (closes_at === null || closes_at > jetzt)`.

Regeln (Reihenfolge, gibt genau 0–1 Event zurück):
1. `isOpen && !publishRowExists` → **`publish`** (seq 0)
2. `isOpen && publishSent && contentHash !== lastDeliverableContentHash` → **`update`** (seq = nächste)
3. `!isOpen && publishSent && !closeRowExists` → **`close`** (seq 0)
4. sonst → **kein Event**

Nie beworben + geschlossen (`!publishSent`) → nichts. Reopen nach zugestelltem close → G+1, `publishRowExists(G+1)`=false → Regel 1 → erneuter publish.

### 6.3 `FlynkPostingSyncDecider::staleRows()` (pure) — Revalidierung

Läuft **vor** Retry- und Detect-Pass, damit dieselbe Fälligkeits-Wahrheit für alle Pässe gilt (fixt: Retry-Pass sendet blind eine zwischenzeitlich obsolet gewordene Zeile). Eingabe: `isOpen` + Liste der nicht-zugestellten Zeilen (`pending`/`failed`) der Generation. Eine nicht-zugestellte Zeile ist **stale** (→ löschen), wenn sie dem aktuellen Zustand widerspricht:

| Zeilentyp | fällig gdw. | stale wenn |
|---|---|---|
| `publish` | `isOpen` | `!isOpen` (Posting inzwischen geschlossen → nie beworben, nichts zu tun) |
| `update` | `isOpen` | `!isOpen` |
| `close` | `!isOpen` | `isOpen` (Reopen vor Zustellung → Anzeige war nie weg) |

Stale-Zeilen werden **gelöscht** (nicht `cancelled`), damit ihr Unique-Slot `(posting, G, event, seq)` frei wird und dasselbe Event in derselben Generation erneut emittiert werden kann. Sie haben FLYNK nie erreicht — für den Audit zählen nur `sent`-Zeilen; die Löschung wird geloggt.

Getraced: **publish pending → Posting geschlossen** → publish stale → gelöscht, nichts gesendet (respektiert „nie beworben + geschlossen → nichts"). **close pending → Reopen** → close stale → gelöscht, nie `sent` → G bleibt → kein Re-publish → Anzeige bleibt korrekt live.

### 6.4 `FlynkPostingPayloadBuilder` (pure)

**Sichtbarer Inhalt = `title` + `description` + `activity`.** `activity` (Tätigkeits-Tag, max 60) wird in den sichtbaren `description`-Text aufgenommen, damit Hash und ausgelieferter Anzeigentext deckungsgleich sind.

`content_hash` = `sha256( trim(title) . "\n" . trim(description) . "\n" . trim(activity) )` — exakt die Felder, die auch im Payload sichtbar landen (Hash ↔ Payload per Konstruktion gekoppelt; fixt Review-Punkt 7).

Mapping pro Event:

| Event | `type` | `title` | `description` (sichtbar) | `priority` |
|---|---|---|---|---|
| publish | `new_section` | `Stellenanzeige: {title}` | `{description}` + „Tätigkeit: {activity}" + „Bitte als Stellenanzeige auf der Karriereseite veröffentlichen." | `normal` |
| update | `text_change` | `Stellenanzeige aktualisieren: {title}` | `{description}` + „Tätigkeit: {activity}" + „Bestehende Anzeige mit diesem Stand aktualisieren." | `normal` |
| close | `text_change` | `Stellenanzeige entfernen: {title}` | „Diese Stellenanzeige ist beendet — bitte von der Karriereseite entfernen." | `normal` |

Gemeinsam:
- `target_url` = `config('recruiting.flynk.careers_url')`, nur wenn gesetzt.
- `meta` = `{ posting_uuid, position_title, activity, team_id, generation, closes_at (ISO|null), event }` (Kontext für die Agentur; nicht Teil des Hashes).
- Kein `files[]` (immer JSON).

### 6.5 Reconciler-Kandidaten & Batch-Laden

- Kandidaten: alle `RecPosting` mit `status = 'published'` **plus** alle mit mindestens einer `sent` publish-Zeile, die aktuell nicht `isOpen` sind und noch keinen `sent` close in aktueller Generation haben.
- **Kein N+1:** Sync-Zeilen aller Kandidaten in **einem** `whereIn('rec_posting_id', $ids)`-Query laden, in Memory nach `rec_posting_id` gruppieren, daraus je Posting Generation + Prädikate bilden.

### 6.6 `FlynkClient`

- `createTask(array $payload): FlynkResult` — `Http::baseUrl(base_url)->timeout(timeout)->withToken(token)->asJson()->post('/webhooks/tasks', $payload)`.
- `FlynkResult`: `{ ok, httpStatus, taskId, permanent, error, rateLimited, unauthorized }`.

## 7. Fehlerbehandlung

| Antwort | Einordnung | Reaktion |
|---|---|---|
| `201` | Erfolg | `status=sent`, `flynk_task_id`, `sent_at` |
| `401` | globales Token-Problem, **nicht** Datenfehler der Zeile | Lauf **abbrechen**; betroffene Zeile bleibt **`pending`** (nicht permanent!); laut ins Log (Config-/Deploy-Problem). Nach Token-Fix wird die Zeile normal weiter versucht. |
| `422` | Payload-/Datenfehler der Zeile | Zeile `failed` + `permanent_failure=true`; kein Auto-Retry; Details in `last_error` |
| `429` | Rate-Limit | Lauf **beenden**; Zeile bleibt `pending`; Folgelauf retryt |
| `5xx` / Netzwerk / Timeout | transient | Zeile `pending`→`failed` bei Erschöpfung; `attempts++`; Retry bis `max_attempts`, danach `failed` (nicht permanent, manuell auslösbar) |

- **Per-Run-Cap** (`per_run_cap`, default 50) hält jeden Lauf unter 60/min.
- Reihenfolge: **Revalidate (löschen) → Retry-Pass → Detect-Pass.** Die Revalidierung (6.3) läuft vor dem Retry, damit der Retry keine zwischenzeitlich obsolet gewordene Zeile blind sendet. Detect-Pass sendet **ausschließlich bei frischem Insert** (`affectedRows === 1`); verbliebene nicht-`sent`-Zeilen gehören allein dem Retry-Pass → kein Doppel-Send im selben Lauf (fixt Review-Punkt 5).
- Log-Zusammenfassung pro Lauf: gesendet / retry / stale-gelöscht / fehlgeschlagen / permanent / übersprungen.

## 8. Teststrategie

Pure Unit-Tests (`meingedeck/vendor/bin/phpunit -c phpunit.xml`):

**`FlynkPostingSyncDeciderTest`**
- offen + kein publish → `publish`
- offen + publish sent + Hash gleich → nichts
- offen + publish sent + Hash geändert → `update`
- **Hash-Rücksprung** A→B→C→B → `update` (Emission trotz früher gesendetem B-Hash — Uniqueness ist seq, nicht Hash) *(P1)*
- **publish pending + Inhalt geändert** → **kein** `update` (`publishSent`=false) *(P2/P5)*
- **publish pending vorhanden** → **kein** zweiter `publish` (`publishRowExists`=true) *(P2)*
- geschlossen + publish sent + kein close → `close`
- abgelaufenes `closes_at` + publish sent → `close`
- nie beworben + geschlossen → nichts
- close bereits (sent) → nichts
- **Reopen:** close sent (Gen 1), dann isOpen → `publish` in Gen 2 *(P4)*
- **failed-update heilt:** publish+update-A sent, update-B `failed` (erschöpft), isOpen, contentHash=B → `update` erneut (B ≠ `lastDeliverableContentHash`=A, weil failed ausgeschlossen)

**`FlynkPostingSyncDecider::staleRows()`Test** (Revalidierung)
- **publish pending → Posting geschlossen** (`!isOpen`) → publish ist stale (→ löschen); danach kein Event, nichts gesendet *(P1-Rest, „nie beworben+geschlossen→nichts")*
- **close pending → Reopen** (`isOpen`) → close ist stale (→ löschen); G unverändert, kein Re-publish *(P1-Rest)*
- update pending + `!isOpen` → stale; publish pending + `isOpen` → nicht stale; close pending + `!isOpen` → nicht stale

**`FlynkPostingPayloadBuilderTest`**
- korrekte `type`/`title`/`description`/`priority` je Event
- `activity` erscheint im sichtbaren `description` *(P7)*
- `content_hash` stabil/änderungssensitiv über title/description/activity *(P7)*
- `target_url` nur bei gesetzter `careers_url`; `meta`-Inhalt inkl. `generation`

**Dünn (`Http::fake()`):** `FlynkClient`-Fehler-Mapping (201/401/422/429/5xx). Reconciler/Command: End-to-End manuell gegen FLYNK-Staging (kein DB-Feature-Test im Modul, Konvention).

## 9. Scheduler & Betrieb

- Scheduler-Eintrag in `meingedeck`: `recruiting:flynk-reconcile` alle 30 Min, `withoutOverlapping()`.
- Nach Modul-Push `meingedeck` composer.lock bumpen. Kein `queue:restart` nötig (kein Queue-Job); Scheduler-Eintrag muss in `meingedeck` vorhanden sein.
- Rollout: `RECRUITING_FLYNK_TOKEN` + `RECRUITING_FLYNK_ENABLED=true` (+ optional `RECRUITING_FLYNK_CAREERS_URL`) in der Zielumgebung. Ohne diese Variablen inaktiv.

## 10. Bewusst ausgeklammert (YAGNI)

- Kein Rückkanal / Statusabgleich, was FLYNK mit dem Task macht.
- Kein Datei-Upload (Postings sind Text).
- `new_section` als publish-`type` (Eintrag in Karriereseiten-Sektion). Eigene Unterseite pro Anzeige (`new_page`) wäre triviale Builder-Änderung.
- Mehrmandantenfähigkeit (Token pro Team) nicht umgesetzt — später als Team-Config nachziehbar, ohne Kernmodell zu ändern.
