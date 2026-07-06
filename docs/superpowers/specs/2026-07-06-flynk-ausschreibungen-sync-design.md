# Design: FLYNK-Sync für Ausschreibungen

**Datum:** 2026-07-06
**Status:** Entwurf zur Review
**Scope:** Ausgehende Übertragung veröffentlichter Ausschreibungen (`RecPosting`) an FLYNK via Task-Webhook.

## 1. Problem & Ziel

Ausschreibungen (`RecPosting`) werden im Recruiting-Modul angelegt und veröffentlicht. Sie sollen auf den von FLYNK betreuten Websites als Stellenanzeigen erscheinen. FLYNK bietet dafür **keinen** eigenen „Stellenanzeige anlegen"-Endpunkt — nur einen generischen **Task-Webhook**, über den man der Agentur Website-Aufgaben stellt (neue Seite/Abschnitt, Textänderung, Entfernung).

**Ziel:** Wenn eine Ausschreibung veröffentlicht, inhaltlich geändert oder geschlossen wird, geht automatisch ein passender **Task** an FLYNK, damit die Anzeige auf der Karriereseite veröffentlicht / aktualisiert / entfernt wird.

**Nicht-Ziel:** Kein Rückkanal (FLYNK → Recruiting), kein Datei-Upload (Postings sind reiner Text), keine Echtzeit-Zustellung.

## 2. FLYNK-Task-Webhook (Referenz)

- `POST {base_url}/webhooks/tasks`, `Authorization: Bearer {token}`, `Content-Type: application/json`
- Pflichtfelder: `title` (max 255), `type` (Enum)
- Optional u. a.: `description`, `priority` (`low|normal|high`, default `normal`), `target_url`, `name`, `email`, `meta` (object)
- Erlaubte `type`-Werte (relevant): `new_section`, `text_change` (nicht erlaubt für externe Nutzung: `feature_request`, `change`)
- Antworten: `201` Erfolg (`{id, title, status, message}`), `401` Token, `422` Validierung, `429` Rate-Limit (>60/min pro IP)
- Rate-Limit: 60 Requests/Minute pro IP

## 3. Getroffene Grundsatz-Entscheidungen

1. **Trigger:** Übertragung erfolgt bei **Veröffentlichung** (`status = published`), nicht bei reiner Anlage (Entwürfe gehen nicht raus).
2. **Lebenszyklus:** Voller Sync — **Publish**, inhaltliche **Änderung** und **Schließung** erzeugen je einen Task.
3. **Technik:** Ein einziges **geplantes Kommando** (`recruiting:flynk-reconcile`, alle 30 Min) statt Model-Observer oder Queue-Job. Grund: Wir brauchen ohnehin einen zeitgesteuerten Pfad (Ablauf via `closes_at` erzeugt kein `save()`). Ein einziger Pfad vermeidet Transaktions-Races, Doppel-Sends und implizite Seiteneffekte in Tests/Seedern. Minuten-Latenz ist für Web-Stellenanzeigen unkritisch.
4. **Token:** Global in der Config (`RECRUITING_FLYNK_TOKEN`), ein FLYNK-Projekt für alle Teams. Kein Feature ohne gesetzten Token + `enabled`.
5. **Idempotenz:** Eine Tracking-Tabelle `rec_posting_flynk_syncs` merkt jeden gesendeten Task. Unique-Indizes sind das strukturelle Gate gegen Doppel-Sends; der Kommando-Lauf ist seriell.
6. **Test-Konvention:** Modul testet ohne Laravel/DB (siehe ZAS). Daher: Entscheidungs- und Mapping-Logik als **pure, DB-freie Klassen** herausgeschnitten und unit-getestet; das Kommando/der Reconciler/der HTTP-Client bleiben dünne Glue-Schicht.

## 4. Konfiguration

Neuer Block in `config/recruiting.php` (analog zum bestehenden `zas`-Block):

```php
'flynk' => [
    'enabled'     => (bool) env('RECRUITING_FLYNK_ENABLED', false),
    'base_url'    => env('RECRUITING_FLYNK_BASE_URL', 'https://flynk.on-forge.com/api'),
    'token'       => env('RECRUITING_FLYNK_TOKEN'),        // Bearer-Token (Service Credentials → Webhook)
    'careers_url' => env('RECRUITING_FLYNK_CAREERS_URL'),  // optional → task.target_url
    'timeout'     => (int) env('RECRUITING_FLYNK_TIMEOUT', 10),
    'per_run_cap' => (int) env('RECRUITING_FLYNK_PER_RUN_CAP', 50), // Sends/Lauf (Rate-Limit-Schutz)
    'max_attempts'=> (int) env('RECRUITING_FLYNK_MAX_ATTEMPTS', 5),  // Retry-Grenze transienter Fehler
],
```

**Guard:** Ist `enabled = false` oder `token` leer → das Kommando beendet sich sofort mit Log-Info und tut nichts. Damit ist das Feature in Lokal/Test/Staging standardmäßig aus.

## 5. Datenmodell

### Migration: `rec_posting_flynk_syncs`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | bigint PK | |
| `rec_posting_id` | FK → `rec_postings` (cascade) | Betroffene Ausschreibung |
| `event_type` | enum/string `publish\|update\|close` | Art des Tasks |
| `content_hash` | string(64), default `''` | SHA-256 des versendeten Inhalts bei publish/update; `''` bei close. Die publish/update-Zeile ist die Quelle für `lastSentContentHash` im Decider. |
| `flynk_task_id` | string, nullable | Von FLYNK zurückgegebene Task-ID (nach Erfolg) |
| `status` | string `pending\|sent\|failed` | Zustellstatus |
| `http_status` | int, nullable | Letzter HTTP-Status |
| `attempts` | unsignedInt, default 0 | Zahl der Zustellversuche |
| `permanent_failure` | bool, default false | `true` bei 401/422 (kein Auto-Retry) |
| `last_error` | text, nullable | Fehlerdetails (gekürzte FLYNK-Antwort) |
| `sent_at` | timestamp, nullable | Zeitpunkt der erfolgreichen Zustellung |
| `created_at` / `updated_at` | timestamps | |

**Unique-Index (das Doppel-Send-Gate):** ein kombinierter, DB-agnostischer Index

- `unique (rec_posting_id, event_type, content_hash)`

Damit gilt automatisch: **publish** genau einmal (der Decider emittiert publish nur solange keine publish-Zeile existiert), **update** genau einmal pro echter Inhaltsänderung (neuer Hash → neue Zeile), **close** genau einmal (`content_hash = ''`). Kein partieller Index nötig; `content_hash` ist `''` statt NULL, damit der Unique-Index über alle Zeilen greift.

Modell: `RecPostingFlynkSync` (Platform\Recruiting\Models), `belongsTo(RecPosting)`.

## 6. Komponenten

```
recruiting:flynk-reconcile   (Scheduler alle 30 Min · guard: enabled + token)
   │
   ▼
FlynkPostingReconciler::run()
   │  A. Retry-Pass: nicht-'sent' & nicht-permanent & attempts<max → erneut senden
   │  B. Detect-Pass: Kandidaten-Postings laden, je Posting Outbox-Summary bilden
   │       → FlynkPostingSyncDecider::decide(state, summary)  [PURE]
   │       → je fälligem Event: Zeile claimen (insertOrIgnore), senden
   │  Gesamt-Cap: per_run_cap Sends/Lauf
   ▼
FlynkPostingPayloadBuilder::build(posting, event) [PURE] → {payload, content_hash}
   ▼
FlynkClient::createTask(payload) → Ergebnis (201/4xx/5xx/exception)
   ▼
Zeile finalisieren: status, http_status, flynk_task_id, attempts++, last_error
```

| Klasse | Verantwortung | Abhängigkeiten | Test |
|---|---|---|---|
| `FlynkPostingSyncDecider` | Aus Posting-Zustand + Outbox-Summary die fälligen Events ableiten. | keine (plain data) | **pure unit** |
| `FlynkPostingPayloadBuilder` | Event + Posting-Daten → FLYNK-Payload **und** `content_hash`. | keine (plain data) | **pure unit** |
| `FlynkClient` | HTTP-Wrapper um `POST /webhooks/tasks`, Auth-Header, Timeout, Ergebnis-/Fehler-Mapping. | `Illuminate\Http\Client` | dünn |
| `FlynkPostingReconciler` | Kandidaten & Outbox laden, Decider rufen, Zeilen claimen/finalisieren, Cap durchsetzen. | DB, Client, Decider, Builder | Integration (meingedeck-Runner) |
| `FlynkSyncCommand` (`recruiting:flynk-reconcile`) | Guard, Reconciler starten, Zusammenfassung loggen. | Reconciler | dünn |

### 6.1 `FlynkPostingSyncDecider` (pure)

Eingaben (plain data pro Posting):
- `isOpen` = `status === 'published' && is_active === true && (closes_at === null || closes_at > jetzt)`
- `contentHash` (aktuell, vom Builder)
- Outbox-Summary: `hasPublishSent` (bool), `lastSentContentHash` (string|null, letzter erfolgreicher publish/update-Hash), `hasCloseSent` (bool)

Regeln (Reihenfolge):
1. `isOpen && !hasPublishSent` → **`publish`**
2. `isOpen && hasPublishSent && contentHash !== lastSentContentHash` → **`update`**
3. `!isOpen && hasPublishSent && !hasCloseSent` → **`close`**
4. sonst → **kein Event**

Nie publish-sent + jetzt geschlossen → nichts (nie beworben). Der Rückgabewert ist eine Liste (i. d. R. 0 oder 1 Event).

### 6.2 `FlynkPostingPayloadBuilder` (pure)

`content_hash` = `sha256( trim(title) . "\n" . trim(description) . "\n" . trim(activity) )`.

Mapping pro Event:

| Event | `type` | `title` | `description` | `priority` |
|---|---|---|---|---|
| publish | `new_section` | `Stellenanzeige: {title}` | `{description}` + „Bitte als Stellenanzeige auf der Karriereseite veröffentlichen." | `normal` |
| update | `text_change` | `Stellenanzeige aktualisieren: {title}` | `{description}` + „Bestehende Anzeige bitte mit diesem Stand aktualisieren." | `normal` |
| close | `text_change` | `Stellenanzeige entfernen: {title}` | „Diese Stellenanzeige ist beendet — bitte von der Karriereseite entfernen." | `normal` |

Gemeinsam:
- `target_url` = `config('recruiting.flynk.careers_url')`, nur wenn gesetzt.
- `meta` = `{ posting_uuid, position_title, activity, team_id, closes_at (ISO|null), event }`.
- Kein `files[]` (immer JSON).

### 6.3 `FlynkClient`

- `createTask(array $payload): FlynkResult` — `Http::baseUrl(base_url)->timeout(timeout)->withToken(token)->asJson()->post('/webhooks/tasks', $payload)`.
- Ergebnis-Objekt: `{ ok: bool, httpStatus: int|null, taskId: ?string, permanent: bool, error: ?string }`.
- Mapping siehe Fehlerbehandlung.

### 6.4 Reconciler-Kandidatenabfrage

- Für publish/update: alle `RecPosting` mit `status = 'published'` (Team-übergreifend; Feature ist global getokent).
- Für close: alle `RecPosting`, die eine `sent`-Publish-Sync-Zeile besitzen, aktuell **nicht** `isOpen` sind und **keine** `sent`-Close-Zeile haben. (Deckt `is_active=false`, `status=closed` **und** abgelaufenes `closes_at` gemeinsam ab.)

## 7. Fehlerbehandlung

| FLYNK-Antwort | Einordnung | Reaktion |
|---|---|---|
| `201` | Erfolg | `status=sent`, `flynk_task_id`, `sent_at` setzen |
| `401` | Config-Fehler (Token) | Lauf **abbrechen** (alle weiteren Sends sinnlos), Zeile `failed` + `permanent_failure=true`, `error` loggen (laut ruf: Deploy-/Config-Problem) |
| `422` | Payload-/Datenfehler | Zeile `failed` + `permanent_failure=true`, kein Auto-Retry (braucht Datenkorrektur); Details in `last_error` |
| `429` | Rate-Limit | Lauf **beenden** (Limit respektieren), Zeile bleibt `pending`, nächster Lauf retryt |
| `5xx` / Netzwerk / Timeout | transient | Zeile `pending`/`failed`, `attempts++`; Retry im nächsten Lauf bis `max_attempts`, danach `failed` (nicht permanent, manuell auslösbar) |

- **Per-Run-Cap** (`per_run_cap`, default 50) hält jeden Lauf klar unter dem 60/min-Limit; Rest wird im Folgelauf abgearbeitet.
- Retry-Pass läuft **vor** dem Detect-Pass, damit hängende Zustellungen Vorrang haben.
- Zusammenfassung pro Lauf ins Log: gesendet / fehlgeschlagen / übersprungen / permanent-failed.

## 8. Teststrategie

Pure Unit-Tests (ohne DB, via `meingedeck/vendor/bin/phpunit -c phpunit.xml`):

**`FlynkPostingSyncDeciderTest`**
- Offen + kein Publish → `publish`
- Offen + Publish gesendet + Hash gleich → kein Event
- Offen + Publish gesendet + Hash geändert → `update`
- Geschlossen (is_active=false) + Publish gesendet + kein Close → `close`
- Abgelaufenes `closes_at` + Publish gesendet → `close`
- Nie beworben + geschlossen → kein Event
- Close bereits gesendet → kein Event

**`FlynkPostingPayloadBuilderTest`**
- publish/update/close → korrekte `type`/`title`/`description`/`priority`
- `content_hash` stabil bei gleichem Inhalt, unterschiedlich bei geändertem Titel/Text/activity
- `target_url` nur bei gesetzter `careers_url`
- `meta` enthält posting_uuid, team_id, event

**Dünne Glue** (`FlynkClient`, `FlynkPostingReconciler`, Command): kein DB-/Feature-Test im Modul (Konvention). Verifikation manuell gegen die FLYNK-Staging-URL bzw. per `Http::fake()`-Smoke-Test, falls im meingedeck-Runner möglich. Fehler-Mapping des Clients ist über `Http::fake()` unit-artig prüfbar.

## 9. Scheduler & Betrieb

- Scheduler-Eintrag (in `meingedeck` Kernel bzw. Modul-Registrierung): `recruiting:flynk-reconcile` alle 30 Minuten, `withoutOverlapping()`.
- **Deploy-Hinweise (aus Modul-Konventionen):** nach Modul-Push `meingedeck` composer.lock bumpen; nach Deploy `queue:restart` ist hier nicht nötig (kein Queue-Job), aber der Scheduler-Eintrag muss in `meingedeck` vorhanden sein.
- Rollout: `RECRUITING_FLYNK_TOKEN` + `RECRUITING_FLYNK_ENABLED=true` (+ optional `RECRUITING_FLYNK_CAREERS_URL`) in der Ziel-Umgebung setzen. Ohne diese Variablen ist das Feature inaktiv.

## 10. Offene Punkte / bewusst ausgeklammert (YAGNI)

- Kein Rückkanal / Statusabgleich, was FLYNK mit dem Task macht.
- Kein Datei-Upload (Postings sind Text).
- `new_section` als publish-`type` gewählt (Anzeige als Eintrag in einer Karriereseiten-Sektion). Falls die Agentur pro Anzeige eine eigene Unterseite will → `new_page`; triviale Änderung im Builder.
- Mehrmandantenfähigkeit (Token pro Team) bewusst nicht umgesetzt — kann später als `flynk_project_token` an Team/Config nachgezogen werden, ohne das Kernmodell zu ändern.
