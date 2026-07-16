# Design: Recruiting-Dashboard Performance

**Datum:** 2026-07-16
**Status:** Entwurf (Review ausstehend)
**Betroffene Dateien:** `src/Livewire/Dashboard/Dashboard.php`, `resources/views/livewire/dashboard/dashboard.blade.php`

## Problem

`/recruiting` lädt langsam — sowohl der erste Seitenaufruf als auch jede Interaktion
(Filter, Park-Button, …). Bei aktuell ~275 Bewerbern im Dashboard entstehen pro Render
grob 1000+ Queries:

1. **`getWhatsAppStatus()` pro Bewerberkarte** — je eine `CommsWhatsAppThread`-Query
   (Dashboard.php, 4 Aufrufstellen in der View: Inbox, Phasen-Boards, No-Phase, Abgeschlossen).
2. **`getExtraFieldCounts()` pro Bewerberkarte** — 3–6 Queries: Definitions-Auflösung
   pro Instanz, `extraFieldValues()` wird trotz Eager-Loading frisch geladen
   (Core-Trait `HasExtraFields::getExtraFieldsWithLabels`), Lookup-Felder laden
   `CoreLookup` nach.
3. **Stats-Pool doppelt** — `positionStats` und `activityStats` rufen beide
   `statsApplicantPool()` auf → der komplette Team-Bewerberpool inkl.
   `postings.position`, `interviewBookings`, `contracts` wird zweimal geladen.
4. **`wire:poll.15s="refreshDashboard"`** — alle 15 s wird das komplette Dashboard
   serverseitig neu berechnet, pro offenem Browser-Tab.
5. **`completedApplicants` unbegrenzt** — alle jemals abgeschlossenen Bewerber werden
   mit 6 Eager-Loads geladen und gerendert; die Liste wächst unbegrenzt.

## Rahmenbedingungen

- **Keine Funktionseinbußen.** Gleiche Zahlen, gleiche Ampeln, gleiche Update-Latenz
  (≤ 15 s). Einzige sichtbare Änderung: „Abgeschlossen" ist initial eingeklappt (Punkt 5,
  vom User explizit gewünscht).
- **Alle Änderungen bleiben in `platforms-recruiting`.** Core (`HasExtraFields`) und
  CRM (`CommsWhatsAppThread`) werden nur gelesen, nicht editiert.
- Tests als reines PHPUnit ohne Laravel/DB (Repo-Konvention) → neue Logik als pure,
  injizierbare Bausteine schneiden.

## Lösung

### 1. WhatsApp-Status batchen

Neues Computed `whatsAppWindowMap`: **eine** Query über die IDs aller in diesem Render
sichtbaren Bewerber —

```
CommsWhatsAppThread
  whereIn(context_model, [Morph-Alias, FQCN])   // beide Schreibweisen, wie bisher
  whereIn(context_model_id, $ids)
  groupBy(context_model_id) → MAX(last_inbound_at)
```

`getWhatsAppStatus($applicant)` bezieht `window_open` aus der Map:
`max_last_inbound_at > now()->subHours(24)` — semantisch identisch zu
`orderByDesc(last_inbound_at)->first()` + `isWindowOpen()` (NULL-only-Threads ⇒ beide
Wege `false`). Telefon-/Opt-in-Logik bleibt unverändert (läuft auf eager-geladenen
`crmContactLinks.contact.phoneNumbers`).

Die Fenster-Entscheidung (`Timestamp-Map + Cutoff → open/closed`) wird als pure Klasse
implementiert (z. B. `Dashboard/WhatsAppWindowResolver`), damit sie ohne DB testbar ist.

Die ID-Menge der Map umfasst alle in diesem Render sichtbaren Listen: Inbox,
NeedsReview, Phasen-Boards, No-Phase — und **bei `showCompleted = true` auch die
Completed-IDs** (die Abgeschlossen-Karten rendern die WhatsApp-Ampel, aber keinen
Extra-Feld-Badge; sie brauchen also die Map, nicht den Counts-Batch).

**Effekt:** ~275 Queries → 1 pro Render.

### 2. Extra-Feld-Zähler batchen

Der Badge braucht nur `gefüllt/gesamt`. Beobachtungen:

- Die Definitions-Menge eines Bewerbers hängt nur an `rec_phase_id`
  (`extraFieldParents()` = Phasen bis inkl. aktueller; Import-Sonderfall = alle
  aktiven Team-Phasen).
- Die Werte (`extraFieldValues`) sind in allen Dashboard-Listen bereits eager-geladen.

Neuer Batch-Resolver (z. B. `Dashboard/ExtraFieldCountsBatch`):

1. **Eine Vorab-Query:** gibt es Definitionen mit `context_type = RecApplicant` und
   `context_id IN ($ids)` (bewerber-instanzspezifisch)? Nur solche Bewerber (praktisch:
   keine) fallen auf den bisherigen Einzelpfad `getExtraFieldsWithLabels()` zurück.
2. **Definitionsliste einmal pro distinkter Phase** auflösen — über die bestehende
   Core-Methode `getExtraFieldDefinitions()` an einem Repräsentanten-Bewerber pro
   Phase (keine Duplikation der Merge-/Dedup-Logik aus Core). Import-Bewerber
   (`import_source IS NOT NULL`) bilden eine eigene Gruppe.
3. **„gefüllt" in-memory zählen:** pro Bewerber die eager-geladenen `extraFieldValues`
   per `definition_id` gegen die Definitionsliste der Gruppe matchen;
   Füll-Kriterium unverändert (`typed_value` nicht `null`/`''`/`[]`/`'[]'`).

**Achtung, Voraussetzung:** `typed_value` greift intern auf `$this->definition?->type`
zu (CoreExtraFieldValue.php:103). Die Listen-Eager-Loads müssen deshalb von
`extraFieldValues` auf **`extraFieldValues.definition`** umgestellt werden (eine
zusätzliche Query pro Liste) — sonst lazy-loadet jeder Wert seine Definition einzeln
und wir handeln uns einen neuen N+1 ein.

Das `always_show_in_form`-Stripping aus dem RecApplicant-Override betrifft nur
`options`, nicht die Zählung — der Counts-Pfad kann es ignorieren.

Die Zähl-Logik (Definitionsliste + Werte-Map → `['filled' => x, 'total' => y]`) wird
pure und ohne Eloquent-Abhängigkeit geschnitten (Arrays rein, Arrays raus) → PHPUnit.

**Effekt:** ~275 × 4 Queries → ~5–15 (eine pro distinkter Phase + Hilfsqueries).

### 3. Stats-Pool einmal laden

`statsApplicantPool()` wird `#[Computed]` → innerhalb eines Requests einmal geladen;
`positionStats` und `activityStats` rechnen auf demselben Pool. `refreshDashboard()`
nimmt den Pool in die unset-Liste auf. Identische Zahlen, halbe Ladezeit für den
Stats-Block.

### 4. Dirty-Check-Poll statt Voll-Refresh

`wire:poll.15s` ruft künftig `checkForUpdates()` statt `refreshDashboard()`:

1. Change-Token berechnen (billig):
   - `MAX(updated_at)` + `COUNT(*)` auf `rec_applicants` (team-scoped),
   - dito `rec_interview_bookings`, `rec_contracts`, `comms_whatsapp_threads`,
   - Enrichment-Processing-Cache-Keys (`enrichment:processing:{id}`) der aktuellen
     Inbox-Bewerber-IDs — damit der Spinner weiterhin binnen 15 s erscheint/verschwindet,
   - grober Stunden-Zeitbucket (`Y-m-d H`) — damit rein zeitabhängige Anzeigen
     (24h-WhatsApp-Fenster, Stuck-Schwellen) spätestens stündlich nachziehen.
2. Token identisch zum gespeicherten (`public string $changeToken`) → **`$this->skipRender()`**
   (Poll kostet ~5 Mini-Queries — 4× MAX/COUNT + Inbox-IDs — plus Cache-Lookups).
   Zwingend, nicht optional: Livewire 3 (hier v3.8.1) führt `render()` bei *jedem*
   Request aus — ohne `skipRender()` würde die View alle Computeds neu auswerten
   und der Dirty-Check wäre wirkungslos.
3. Token geändert → **`$this->changeToken` auf den neuen Wert setzen** und dann
   `refreshDashboard()` wie heute (normaler Re-Render). Ohne das Update würde
   jeder Folge-Poll ewig einen Refresh triggern.

**Token-Lifecycle:** `mount()` setzt einen Baseline-Token (sonst feuert der erste
Poll einen — harmlosen, aber unnötigen — Voll-Refresh). Der Token ist eine
`public string`-Property und überlebt damit Livewire-Roundtrips.

Der Token-Bau selbst darf keine schweren Computeds anfassen: die Inbox-IDs kommen
aus einer eigenen ID-only-Query (`applicantBaseQuery()`-Klon + `->pluck('id')`),
nicht aus `$this->inboxApplicants`.

Der Token-Bau (Inputs → Hash-String) wird als pure Klasse geschnitten
(z. B. `Dashboard/DashboardChangeToken`) → PHPUnit.

**Bewusst akzeptierte Randfälle (dokumentiert):**

- Änderungen, die *ausschließlich* eine Pivot-Tabelle berühren, ohne
  `rec_applicants.updated_at` anzufassen, triggern das Token nicht. Die bekannten
  Flows (Posting-Zuweisung via `reconcilePositionState`, Parken, Enrichment,
  Auto-Pilot) schreiben alle auf `rec_applicants`.
- Rein zeitgetriebene Anzeigen aktualisieren ohne Datenänderung erst mit dem
  Stunden-Bucket statt nach 15 s.
- Umbenennen einer Stelle in/aus dem Legacy-Marker (" bis ") ändert die
  Mode-Scoping-Sichtbarkeit ohne `rec_applicants`-Write — andere Tabs ziehen
  erst mit dem Stunden-Bucket nach (seltene Admin-Aktion).

**Effekt:** ~95 % der Polls (nichts geändert) kosten fast nichts; Update-Latenz
bleibt ≤ 15 s.

### 5. „Abgeschlossen" eingeklappt + Lazy-Load

- Sektion rendert initial nur die Kopfzeile mit Zähler-Badge
  („Abgeschlossen (N)" — billiger `COUNT` als eigenes Computed `completedCount`).
- `completedCount` und `completedApplicants` leiten sich aus **einer** privaten
  `completedQuery()`-Methode ab (Count = `->count()`, Liste = `->with(...)->limit(...)`)
  — Badge-Zahl und Liste können per Konstruktion nicht divergieren.
- **Eager-Loads der Completed-Liste entschlacken:** Der Completed-Block im Blade
  (verifiziert 2026-07-16) rendert die WhatsApp-Ampel, aber keinen Extra-Feld-Badge
  und liest `extraFieldValues` nirgends → der Eager-Load `extraFieldValues` fliegt
  aus der Completed-Query raus (und wird dort auch NICHT auf `.definition`
  umgestellt). Die Completed-IDs wandern dafür bei `showCompleted = true` in die
  `whatsAppWindowMap` (siehe Sektion 1).
- Klick setzt `public bool $showCompleted = true` → erst dann rendert die View die
  Liste und die `completedApplicants`-Query läuft (Livewire-Computeds sind lazy —
  ungerenderte Sektion = keine Query).
- Liste lädt in Häppchen: erste 25 (sortiert wie bisher `created_at desc`),
  „Mehr laden"-Button erhöht `public int $completedLimit` um 25.
- `refreshDashboard()`/Dirty-Check berechnen die Liste nur neu, wenn `showCompleted`
  aktiv ist (unset ist harmlos — Query läuft nur bei Render der offenen Sektion).

**Einzige sichtbare Änderung des gesamten Designs:** ein Klick zum Aufklappen.

## Bewusst NICHT im Scope

- Eigene Unterseite für Abgeschlossene (nachrüstbar, falls Such-/Filterbedarf entsteht)
- Aufteilen des Dashboards in mehrere Livewire-Components
- Caching von Stats über Request-Grenzen hinweg
- Änderungen an Core-Trait `HasExtraFields` (z. B. Eager-Load-Nutzung) — separates
  Thema, bräuchte Freigabe für Core

## Tests & Verifikation

**PHPUnit (pure, ohne DB):**
- `WhatsAppWindowResolver`: Timestamp-Map + Cutoff → open/closed (inkl. NULL-Fälle)
- `ExtraFieldCountsBatch`-Zähllogik: Definitionsliste + Werte → filled/total
  (leere Werte-Varianten `null`/`''`/`[]`/`'[]'`)
- `DashboardChangeToken`: gleiche Inputs → gleiches Token; jede Input-Änderung
  (Timestamps, Counts, Enriching-IDs, Zeitbucket) → anderes Token

**Live-Verifikation (es gibt kein Staging):**
- DB-nahe Logik vorab im SQLite-Harness abdecken
- Vorher/Nachher = erster Live-Klick nach dem Forge-Deploy:
  identische Badge-Zahlen, WhatsApp-Ampeln, Stats-Tabellen, Zähler
- Query-Count pro Seitenaufruf live vergleichen (Debugbar/Query-Log)
- Poll-Verhalten: Änderung durch zweiten User erscheint binnen 15 s;
  Ruhezustand erzeugt keine schweren Queries (skipRender greift)
- Abgeschlossen: Badge korrekt, Aufklappen lädt 25, „Mehr laden" paginiert

## Erwarteter Effekt

- Erster Seitenaufbau: von grob 1000+ Queries auf ~30–40
- Jede Interaktion (Filter, Parken, …): gleicher Faktor, da Livewire pro Request
  alles neu rechnet
- Polls im Ruhezustand: nahezu kostenlos statt Voll-Render alle 15 s pro Tab
