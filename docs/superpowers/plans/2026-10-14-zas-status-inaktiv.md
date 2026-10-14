# ZAS-Status „inaktiv“ — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ZAS-Status `inaktiv` deaktiviert Mitarbeiter (auch neu angelegte), GO/MA reaktiviert nur ZAS-verursachte Inaktivität; Jobs anbieten zeigt Inaktive getrennt und markiert Status MA.

**Architecture:** Ein reiner Normalisierer `ZasStatus`, Erweiterung des bestehenden Importers in seinen zwei Pfaden (Treffer / Neuanlage) ohne neuen Schreibweg, und zwei kleine Erweiterungen in den Jobs-anbieten-Bausteinen.

**Tech Stack:** Laravel 11, Livewire 3, PHPUnit mit Capsule/SQLite (echte Migrationen).

**Spec:** `docs/superpowers/specs/2026-10-14-zas-status-inaktiv-design.md`

## Global Constraints

- Worktree `.claude/worktrees/zas-inaktiv`, Branch `feat/zas-status-inaktiv` ab origin/main `5c1b81b2`. Nur platforms-recruiting.
- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`; Blade: `php tools/blade-check.php <datei>`.
- Kein Echo nach ZAS: `zas_changed_at` darf durch den Import nicht gesetzt werden (bestehende Marker-Wiederherstellung in `syncMatchedFields`/`createEmployee` beibehalten).
- Status-Werte gespeichert immer großgeschrieben: `GO`, `MA`, `INAKTIV`.
- Kundentexte Du-neutral, keine Technik. Commit-Trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Keine Migration.

## Review Focus

1. Eine HR-Deaktivierung von Hand (is_active false, Status GO) darf durch eine GO-Zeile von ZAS NICHT reaktiviert werden.
2. Ein reiner Status-Wechsel (GO → INAKTIV) darf nicht als `skipped/exists` durchrutschen.
3. Unbekannte Personalnummer mit INAKTIV wird inaktiv angelegt und löst keine Dubletten-Meldung aus; mit GO/MA bleibt alles wie heute.
4. Reaktivierung/Deaktivierung setzt `zas_changed_at` nicht (sonst Update-Export-Flut an ZAS).
5. Jobs anbieten: Inaktive tauchen weder als Empfänger noch in „N Mitarbeiter“ auf, nur in der getrennten grauen Zahl.

---

### Task 1: Import — Status normalisieren, deaktivieren, reaktivieren, inaktiv anlegen

**Files:**
- Create: `src/Services/Zas/ZasStatus.php`
- Modify: `src/Services/Zas/ZasInboundRowMapper.php` (~Zeile 297–300: `mb_strtoupper` → `ZasStatus::normalize`, unbekannter Wert → Warnung)
- Modify: `src/Services/Zas/ZasInboundEmployeeImporter.php` (Treffer-Pfad ~95–160, `syncMatchedFields` ~342, Neuanlage ~162–210, `createEmployee` ~480, Bericht ~217)
- Modify: `src/Livewire/Employees/Show.php:807` (Optionen `['GO', 'MA', 'INAKTIV']`)
- Test: `tests/Unit/Zas/ZasStatusTest.php` (neu), `tests/Integration/ZasInboundInaktivTest.php` (neu, Aufbau 1:1 nach `tests/Integration/ZasInboundStatusSyncTest.php`)

**Interfaces:**
- Produces: `ZasStatus::normalize(?string $raw): ?string` (`'GO'|'MA'|'INAKTIV'|null|<UPPER unbekannt>`), `ZasStatus::INAKTIV = 'INAKTIV'`, `ZasStatus::istBekannt(string $s): bool`. Importer-Bericht bekommt Schlüssel `deactivated` (list<{employee_id, personnel_number}>), `reactivated` (dto.), `created_inactive` (int); Einträge in `created` tragen bei Inaktiv-Anlage `'inactive' => true`.

- [ ] **Step 1: Unit-Test `ZasStatusTest`**
  - `normalize('go')` → `'GO'`, `' MA '` → `'MA'`, `'inaktiv'`/`'INAKTIV'`/`'Inaktive'` → `'INAKTIV'`, `''`/`null`/`'   '` → `null`, `'xy'` → `'XY'`; `istBekannt('XY')` false, `istBekannt('INAKTIV')` true.
- [ ] **Step 2: RED laufen lassen, dann `ZasStatus` schreiben (final class, rein), GREEN.**
- [ ] **Step 3: RowMapper** — `$hr['export_status'] = ZasStatus::normalize($status)`; wenn das Ergebnis nicht bekannt ist: `$warnings[] = "Status '{$status}' unbekannt — unveraendert uebernommen"`. Bestehende Tests des Mappers müssen grün bleiben (dort wird 'go' → 'GO' erwartet).
- [ ] **Step 4: Integrationstest `ZasInboundInaktivTest` (RED)** — Fälle (jeweils Zeilen wie in `ZasInboundStatusSyncTest` gebaut, Import nicht im Dry-Run):
  1. Bestand aktiv, Status GO → Zeile `Status=inaktiv` ⇒ `is_active` false, `export_status` INAKTIV, Bericht `deactivated` enthält die id, `zas_changed_at` bleibt null.
  2. Bestand inaktiv mit `export_status` INAKTIV → Zeile `Status=GO` ⇒ wieder aktiv, Bericht `reactivated`.
  3. Bestand inaktiv mit `export_status` GO (HR hat deaktiviert) → Zeile `Status=GO` ⇒ bleibt inaktiv, nicht in `reactivated`.
  4. Unbekannte PersNr, `Status=inaktiv` ⇒ angelegt mit `is_active` false, `export_status` INAKTIV, `created_inactive` = 1, `created[0]['inactive']` true, `suspected` leer, obwohl ein Bestands-MA mit gleicher Telefonnummer existiert (Dubletten-Prüfung übersprungen).
  5. Unbekannte PersNr, `Status=GO` ⇒ wie bisher aktiv angelegt (Regression), Dubletten-Prüfung läuft (mit gleicher Telefonnummer → `suspected` nicht leer).
  6. Bestand aktiv GO, Zeile `Status=GO`, sonst nichts geändert ⇒ `skipped` mit reason exists (Regression: kein falscher Wechsel).
  Die Kontaktlisten-Synchronisation wird im Test nicht geprüft (CRM-Tabellen fehlen); der Aufruf muss in try/catch liegen, damit der Test ohne CRM grün bleibt.
- [ ] **Step 5: Importer umbauen**
  - Treffer-Pfad: nach `$changes = $this->statusSyncChanges(...)` bestimmen
    `$neuerStatus = $mapped['hr']['export_status'] ?? null; $alterStatus = $existing->hrData?->export_status; $aktivWechsel = null;`
    `if ($neuerStatus === ZasStatus::INAKTIV && $existing->is_active) $aktivWechsel = false;`
    `elseif (in_array($neuerStatus, ['GO','MA'], true) && !$existing->is_active && $alterStatus === ZasStatus::INAKTIV) $aktivWechsel = true;`
    In die „nichts geändert → skipped“-Bedingung `&& $aktivWechsel === null` aufnehmen. `is_active` in `$changedFields` aufnehmen, wenn gesetzt.
  - `syncMatchedFields(..., ?bool $aktiv = null)`: wenn `$aktiv !== null`, `$employeeUpdate['is_active'] = $aktiv` (gleiches direktes Update, Marker bleibt). Nach der Transaktion (außerhalb, in try/catch mit `Log::warning`) `app(\Platform\Recruiting\Services\EmployeeContactListSyncService::class)->syncEmployee($existing->fresh())`.
  - Bericht: bei `$aktivWechsel === false` Eintrag in `$deactivated`, bei true in `$reactivated`.
  - Neuanlage: `$inaktiv = ($mapped['hr']['export_status'] ?? null) === ZasStatus::INAKTIV;` Dubletten-Prüfung nur `if (!$inaktiv)`. `createEmployee($mapped, $teamId, $inbound->id, aktiv: !$inaktiv)` setzt `is_active` entsprechend. `$created[]`-Eintrag bekommt `'inactive' => $inaktiv`; Zähler `$createdInactive++`.
  - Dry-Run spiegelt dieselben Entscheidungen (`would_deactivate`/`would_reactivate`-Einträge), ohne zu schreiben.
  - `compact(...)` um `deactivated`, `reactivated`, `created_inactive` erweitern; Status-Berechnung zählt sie als Erfolg.
- [ ] **Step 6: Show.php Optionen um `INAKTIV` ergänzen.**
- [ ] **Step 7: GREEN, volle Suite, Commit** `feat(recruiting): ZAS-Status inaktiv — Import deaktiviert, legt Inaktive inaktiv an, reaktiviert nur ZAS-Inaktive`

---

### Task 2: Jobs anbieten — Inaktive getrennt zählen, Status MA kennzeichnen

**Files:**
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobKandidaten.php` (`blockOptionen()` + `laden()`: `hrData:id,rec_employee_id,dispo_taetigkeiten,export_status`, Kandidat `ohne_vertrag`)
- Modify: `src/Services/Zas/Dispo/Jobs/KostenstellenBloecke.php` nur falls `optionen()` die Zählung übernehmen muss (bevorzugt: zweite Zählung in `blockOptionen` über dieselbe Block-Funktion)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobZahlen.php` (`ohne_vertrag`-Zahl der Empfänger)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobZusagenInfo.php` (`ohne_vertrag` je Person)
- Modify: `resources/views/livewire/dispo/jobs/create.blade.php` (Mitarbeiterfiliale + Zahlen-Kasten), `resources/views/livewire/dispo/jobs/show.blade.php` (Neue-Welle-Bloecke + Zusagen-Chip)
- Test: `tests/Integration/DispoJobKandidatenTest.php` (erweitern), `tests/Integration/DispoJobZusagenInfoTest.php` (erweitern), Wiring-Asserts in `tests/Unit/DispoJobsRunde2WiringTest.php`

**Interfaces:**
- Consumes: Task 1 (`export_status` INAKTIV gespeichert, `is_active` false).
- Produces: `blockOptionen()` liefert je Eintrag zusätzlich `inaktiv:int`; Kandidat (`laden()['kandidaten'][]`) zusätzlich `ohne_vertrag:bool`; `DispoJobZahlen::...` Ergebnis zusätzlich `ohne_vertrag:int`; `DispoJobZusagenInfo::laden()` je Person zusätzlich `ohne_vertrag:bool`.
- Regel `ohne_vertrag`: über alle Datensätze der Person (Identitätsgruppe) — true, wenn KEINER `export_status = 'GO'` hat und mindestens einer `'MA'`; Datensätze ohne hrData zählen nicht.

- [ ] **Step 1: Tests erweitern (RED)**
  - KandidatenTest: ein inaktiver MA im Block 200 erscheint nicht in `laden()['kandidaten']`; `blockOptionen()` für Block 200 liefert `count` nur der aktiven und `inaktiv` = 1.
  - KandidatenTest: Gruppe aus RG (Status MA) + MA-Firma (Status GO) → `ohne_vertrag` false; nur MA → true; ohne hrData → false.
  - ZusagenInfoTest: `ohne_vertrag` analog für eine Zusage.
  - DispoJobZahlen: zwei Empfänger, einer `ohne_vertrag` → `ohne_vertrag` = 1 (Test in der vorhandenen Zahlen-/Planner-Testdatei, falls vorhanden; sonst in KandidatenTest über `DispoJobZahlen::ausDaten`).
- [ ] **Step 2: Implementieren** — eine zusätzliche gruppierte Query für die Inaktiven je Block (gleiche Kostenstellen-Logik wie `blockOptionen`, `is_active = false`); `ohne_vertrag` beim Zusammenführen der Gruppe in `laden()`; `DispoJobZahlen` zählt Empfänger mit `ohne_vertrag`; `DispoJobZusagenInfo` liest `export_status` aus hrData der Gruppe.
- [ ] **Step 3: Views**
  - Mitarbeiterfiliale (create Schritt 3 und show „Neue Welle“): hinter „· N Mitarbeiter“ ein graues `· X inaktiv (werden nicht angeschrieben)`, nur wenn X > 0.
  - Zahlen-Kasten (create) und Neue-Welle-Zahlen (show): unter „Sendet an N Personen“ grau `davon M mit Status MA (brauchen neuen Vertrag)`, nur wenn M > 0.
  - Zusagen-Tabelle (show): Chip `Status MA – braucht neuen Vertrag` (amber, klein) hinter dem Namen, wenn `ohne_vertrag`.
  - blade-check auf beide Views.
- [ ] **Step 4: GREEN, volle Suite, Commit** `feat(recruiting): Jobs anbieten — Inaktive getrennt gezaehlt, Status MA gekennzeichnet`

---

## Abschluss
Whole-branch-Review (Fokus: Review-Focus-Liste), Freigabe S. Haustein, Merge, Bump, Deploy (`view:clear`), dann Mail an Olaf abschicken.
