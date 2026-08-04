# MA-Kontaktbuch — Design

**Datum:** 2026-08-04 (rev. 2 nach Code-Verifikation)
**Modul:** platforms-recruiting
**Ziel:** Eine CRM-Kontaktliste, die automatisch genau die aktiven Mitarbeiter (`RecEmployee`, `is_active = true`) enthält — als Basis für CardDAV-Telefonbuch, Newsletter etc.

## Kontext & Rahmenbedingungen

- `RecEmployee` hängt über die polymorphe `crm_contact_links`-Beziehung (`crmContactLinks()`) an `CrmContact`. Bewerber-Flow garantiert den Link; ZAS-Import (noch) nicht — Import-Fix ist ein **separater** nächster Schritt, nicht Teil dieses Designs.
- **Befüllen** über die öffentliche CRM-API `SubscriptionService::subscribe($list, $contact, 'manual_admin')` (`manual_admin` umgeht DOI). **Entfernen** per Row-Delete `CrmContactListMember::delete()` + `$list->updateMemberCount()` — CRM-eigenes Muster (Präzedenz: `ContactList::removeMember()`, platform-crm `src/Livewire/ContactList/ContactList.php:153`). Grund: Die CardDAV-Auslieferung (`CrmCardDavBackend::visibleContactsQuery()`) filtert **nicht** auf Member-Status; `unsubscribe()` allein ließe Abgemeldete im Telefonbuch.
- **Harte Grenze:** Es wird keine Datei außerhalb von `platforms-recruiting` geändert — kein platform-crm, keine CRM-Migration, kein CRM-Test. Erlaubt ist der Datenzugriff auf `crm_contact_list_members` aus Recruiting-Code (Präzedenz s. o.). Stellt sich beim Bauen heraus, dass etwas eine CRM-Änderung braucht: **STOP, melden, nicht umsetzen.**

## Entscheidungen (mit Sebastian abgestimmt)

| Thema | Entscheidung |
|---|---|
| Scope v1 | Sync-Kern + Observer + Command + Settings-Panel. ZAS-Inline-Linking und Scheduler: später, separat. |
| Listen-Anlage | Settings-Panel in Recruiting-Einstellungen, Button „MA-Kontaktbuch anlegen". Liste: Team-Liste (`owned_by_user_id = null`), `requires_doi = false`. ID landet in `rec_applicant_settings.settings['employee_contact_list_id']`. |
| MA ohne Kontakt-Link | Überspringen, zählen (`skipped_without_contact`), im Panel anzeigen. Kein Kontakt-Anlegen im Sync (Zuständigkeit von Import/Backfill). |
| Verwaltungshoheit / Ist-Menge | Liste ist **vollständig sync-verwaltet**. Ist-Menge = **alle** Member-Zeilen der Liste, unabhängig vom Status. Zeile + aktiver MA + `status != 'subscribed'` → `subscribe()`-Renormalisierung (Report: `normalized`; nötig, weil `globalUnsubscribe()` unsere Zeilen von außen auf `unsubscribed` setzen kann und CardDAV sie trotzdem ausliefert). Zeile ohne zugehörigen aktiven MA → Row-Delete. Newsletter-Versand bleibt trotz Renormalisierung geschützt: `NewsletterService` prüft `CommsUnsubscribe` beim Senden. |
| Morph-Key | Immer über `(new RecEmployee)->getMorphClass()` bzw. die Eloquent-Relation auflösen, **nie** den FQCN als String festschreiben (RecEmployee steht heute nicht in der morphMap; das kann sich ändern). |
| Link-Priorisierung (MA → Kontakt) | Zuerst Links auf Kontakte mit `is_active = true` **und** `owned_by_user_id IS NULL` (= von CardDAV auslieferbar), Tie-Break kleinste `contact_id`. Kein auslieferbarer Kontakt vorhanden → Report `hidden_from_carddav`, zählt **nicht** als synchronisiert. MA mit >1 Link zusätzlich als `ambiguous_multi_link` zählen. |
| Hard-Delete-Guard | `syncAll` bricht ohne Schreiben ab (`status: guard_tripped`), wenn (a) die Soll-Menge bei konfigurierter Liste leer ist (`guardReason: empty_soll`, **nie übersteuerbar**), oder (b) Entfernungen > 25 Zeilen ODER > 50 % der Liste betragen (`guardReason: threshold`, Override per `--force` bzw. zweitem Panel-Klick). Bewusste Konsequenz: Ein Team mit legitim null aktiven MA kann seine Liste über den Sync nicht leeren — Ausweg ist die manuelle Pflege über die CRM-Listen-UI. |
| Kennzeichnung | Recruiting-Panel kennzeichnet die Liste als sync-verwaltet. Im CRM/CardDAV sichtbar über das `description`-Feld (Daten, kein CRM-Code): „⚙️ Automatisch verwaltet durch Recruiting (MA-Kontaktbuch). Manuelle Änderungen werden beim nächsten Sync überschrieben." Ein harter Schutz in der CRM-UI wäre CRM-Logik → optionaler Folgeschritt nach Bestätigung. |
| Definition „aktiv" | `rec_employees.is_active = true`. |
| Settings-Schreibregel | Nur `syncAll()` schreibt den `last_sync`-Zeitstempel (nach erfolgreichem Nicht-Dry-Lauf), `syncEmployee()` **nie** — `settings` ist JSON Read-Modify-Write, häufige Observer-Saves würden parallele Änderungen anderer Keys klobbern. Immer `setSetting()` + explizites `save()`. |

## Komponenten (alle neu, alle in platforms-recruiting)

### 1. `src/Services/EmployeeContactListSyncService.php` + `SyncReport`

Idempotenter Kern. **Der Service wirft nie** — jeder Ausgang ist ein `SyncReport`; Command und Panel entscheiden selbst, was daraus eine Fehlermeldung wird.

```php
final readonly class SyncReport
{
    public function __construct(
        public int $added,
        public int $removed,
        public int $normalized,
        public int $unchanged,
        public int $skipped_without_contact,
        public int $hidden_from_carddav,
        public int $ambiguous_multi_link,
        public bool $dry_run,
        public string $status, // ok | partial | not_configured | list_missing | guard_tripped
    ) {}
}
```

**Zählwahrheit:** Bei echten Läufen zählen `added`/`normalized`/`removed` die **tatsächlich erfolgreichen Writes** (auch Row-Deletes), nicht das berechnete Diff; nur Dry-Runs zählen aus dem Diff. Schlägt mindestens ein Write fehl (oder weicht die echte Schreibmenge vom Diff ab), ist der Status `partial` statt `ok` — geloggt wird jeder Einzelfehler. Konsequenz aus der Settings-Schreibregel: `last_sync` wird **nur bei `status = ok`** geschrieben, bei `partial` nicht.

Aufbau:

- **Ein privater Soll-Resolver** — die einzige Stelle mit Link-Priorisierung. Liefert für eine MA-Menge die auslieferbaren `contact_id`s (dedupliziert) plus die Zähler `skipped_without_contact` (kein Link), `hidden_from_carddav` (Links vorhanden, keiner auslieferbar), `ambiguous_multi_link` (>1 Link).
- **`computeDiff(array $soll, array $ist): SyncReport`** — **pure Funktion, keine DB.** `$ist` = `contact_id => status` aller Member-Zeilen. Klassifiziert `toAdd` (in Soll, nicht in Ist), `toNormalize` (in beiden, Status ≠ `subscribed`), `toRemove` (in Ist, nicht in Soll), `unchanged` — und wendet den Guard an.
- **`syncAll(int $teamId, bool $dryRun = false, bool $force = false): SyncReport`** — Soll = Resolver über alle aktiven MA des Teams; Ist = alle Member-Zeilen der Liste; Diff; dann Schreiben: `subscribe()` für Add + Normalize, Row-Delete für Remove, abschließend einmal `updateMemberCount()`. `last_sync` nur bei echtem Lauf mit `status: ok`.
- **`syncEmployee(RecEmployee $employee): void`** — ermittelt die `contact_id`s der Links **dieses** MA und ruft **denselben Resolver**, eingeschränkt auf diese `contact_id`s, aber ausgewertet über **alle aktiven MA des Teams** — kein eigenes `whereHas('crmContactLinks')`. Grund: Der Kontakt des deaktivierten MA #1 kann gleichzeitig der gewählte Kontakt des aktiven MA #2 sein — der Deaktivierungs-Save von #1 darf #2s Kontakt nicht aus der Liste kicken.

### 2. `src/Observers/RecEmployeeContactListObserver.php`

- Nur `updated` auf `RecEmployee` (wenn `is_active` oder `employment_ended_at` dirty). **Kein `created`-Hook:** strukturell tot — `crm_contact_links.linkable_id` braucht die Employee-ID, ein Link kann erst *nach* dem `created`-Event existieren. Konsequenz und Regel: siehe Benannte Lücken.
- Ruft `syncEmployee()` in `try/catch` mit `Log::error` — CRM-Fehler dürfen den MA-Save nie kippen (Muster: `IncomingApplicationService`).
- Registrierung in `RecruitingServiceProvider::boot()` neben den bestehenden Observern.
- **Kein `deleted`-Hook:** `recruiting:delete-employee` löscht die `CrmContactLink`-Zeilen *vor* dem `forceDelete` — beim `deleted`-Event ist der Kontakt nicht mehr auflösbar (→ Benannte Lücken).

### 3. `src/Console/Commands/SyncEmployeeContactList.php`

- Signatur: `recruiting:sync-employee-contact-list {--team=} {--dry-run} {--force}`.
- Ohne `--team`: alle Teams mit konfigurierter Liste.
- Gibt den Report tabellarisch aus (Muster: `ZasCrmContactBackfill`); `status`-Mapping: `not_configured` → Hinweis (Exit 0), `list_missing`/`guard_tripped` → Fehlermeldung (Exit ≠ 0, bei Guard mit `--force`-Hinweis), `partial` → Warnung mit Log-Hinweis und Exit ≠ 0.
- Initial nicht im Scheduler; Nachtrag (a) nach Task 4 hängt ihn stündlich ein (ohne `--force`), s. Benannte Lücken.

### 4. Settings-Panel (Recruiting-Einstellungen, Livewire)

- Kein Kontaktbuch konfiguriert → Erklärtext + Button „MA-Kontaktbuch anlegen":
  - erzeugt `CrmContactList` (`name: „Aktive Mitarbeiter"`, description s. o., `team_id`, `is_active = true`, `requires_doi = false`, `owned_by_user_id = null`),
  - speichert die ID in den Settings,
  - stößt initialen Voll-Sync an.
- Konfiguriert → Statuskarte: Badge „sync-verwaltet", Listenname, Mitgliederzahl, Zähler aus dem letzten Lauf (`skipped_without_contact`, `hidden_from_carddav`, `ambiguous_multi_link`, mit Hinweis auf Backfill/manuelle Zuordnung), letzter Sync-Zeitpunkt.
- Button „Jetzt synchronisieren" ist **zweistufig**: erster Klick macht einen Dry-Run und zeigt „würde N entfernen, M hinzufügen — ausführen?"; zweiter Klick führt aus und wirkt bei `guard_tripped` als Force-Override.
- Liste gelöscht/deaktiviert → Warnung „Liste fehlt" + Button „Neu anlegen".

## Datenfluss

```
RecEmployee gespeichert (is_active/employment_ended_at geändert)
  → Observer → syncEmployee() → Resolver (Team-weit, auf betroffene contact_ids beschränkt)
      → subscribe() | CrmContactListMember::delete() + updateMemberCount()
Voll-Sync (Panel zweistufig oder Artisan-Command)
  → syncAll() → Soll/Ist-Diff (computeDiff, pure) → Guard → Writes → SyncReport
```

Erstbefüllung = erster Voll-Sync; kein separater Backfill.

## Fehlerbehandlung

- Service wirft nie; `not_configured` / `list_missing` / `guard_tripped` / `partial` sind Report-Stati, keine Exceptions. `partial` = mindestens ein Write fehlgeschlagen; Command reagiert mit Warnung + Log-Hinweis + Exit-Code ≠ 0, Panel mit gelber (nicht grüner) Statusmeldung.
- Observer: try/catch + `Log::error` als zweite Verteidigungslinie, niemals Exception nach außen.
- Command/Panel übersetzen Report-Stati in Meldungen (s. Komponente 3/4).
- `subscribe()` ist verifiziert idempotent (no-op bei `subscribed`, Reaktivierung bei `unsubscribed`, mit `manual_admin` ohne DOI — `SubscriptionService.php:21-97`).

## Tests

Integrationstests nach dem Muster `tests/Integration/DuplicateMatchQueryTest.php` (Capsule + SQLite in-memory, handgebautes Schema, Auth-Stub, Event-Dispatcher im Container). Zwei Punkte explizit:

- `RecruitingServiceProvider::boot()` läuft im Test **nicht** → Observer manuell per `RecEmployee::observe(...)` registrieren.
- Das handgebaute Schema findet **keine NOT-NULL-Drift** gegenüber echten Migrationen — bekannte Grenze dieses Testansatzes, keine Absicherung dagegen.

Fälle:

1. `computeDiff` als **Unit-Tests in der Unit-Suite** (pure Funktion, kein Eloquent): Add/Normalize/Remove/Unchanged-Klassifikation, Guard leer-Soll, Guard >25, Guard >50 %, Force-Override, dry_run.
2. `syncAll` (Integration): aktiver MA mit Link → Mitglied; inaktiver MA → Zeile weg; MA ohne Link → `skipped_without_contact`; MA nur mit nicht-auslieferbarem Kontakt (inaktiv oder owned) → `hidden_from_carddav`, kein Member; MA mit 2 Links → auslieferbarer gewinnt, `ambiguous_multi_link` gezählt; von außen auf `unsubscribed` gesetzte Zeile eines aktiven MA → `normalized`; manuell hinzugefügter Fremdkontakt → Zeile weg; zweiter Lauf → 0 Änderungen.
3. **Invariante als Assertion nach jedem `syncAll`-Test:** Die Liste enthält ausschließlich Zeilen mit `status = 'subscribed'`, und `member_count` == Zeilenzahl.
4. Observer (Integration, manuell registriert): `is_active`-Flip ändert Mitgliedschaft; geteilter Kontakt zweier MA bleibt beim Deaktivieren von MA #1 erhalten; Update eines unbeteiligten Feldes löst nichts aus; CRM-Exception kippt den Save nicht.
5. Command: `--dry-run` schreibt nichts; Report-/Statusausgabe korrekt.
6. **Panel bleibt unverifiziert bis zum Live-Klick** — wird nicht als getestet ausgewiesen.

## Vor dem ersten Sync auf der Instanz

Diese Queries auf der Zielinstanz laufen lassen — sie sagen, ob `ambiguous_multi_link` und `hidden_from_carddav` real Treffer haben und ob die Guard-Schwelle passt:

```sql
-- 0) Morph-Key kontrollieren (erwartet: FQCN, da RecEmployee nicht in der morphMap)
SELECT DISTINCT linkable_type FROM crm_contact_links;

-- 1) MA mit >1 Kontakt-Link (ambiguous_multi_link-Kandidaten)
SELECT linkable_id, COUNT(*) c FROM crm_contact_links
WHERE linkable_type LIKE 'Platform%RecEmployee'
GROUP BY linkable_id HAVING c > 1;

-- 2) Kontakte an >1 MA (geteilte Kontakte, relevant für Observer-Fall)
SELECT contact_id, COUNT(*) c FROM crm_contact_links
WHERE linkable_type LIKE 'Platform%RecEmployee'
GROUP BY contact_id HAVING c > 1;

-- 3) Aktive MA, deren Kontakte nicht CardDAV-auslieferbar sind (hidden_from_carddav)
SELECT e.id, e.last_name, c.id AS contact_id, c.is_active, c.owned_by_user_id
FROM rec_employees e
JOIN crm_contact_links l ON l.linkable_type LIKE 'Platform%RecEmployee' AND l.linkable_id = e.id
JOIN crm_contacts c ON c.id = l.contact_id
WHERE e.is_active = 1 AND (c.is_active = 0 OR c.owned_by_user_id IS NOT NULL);
```

## Benannte Lücken & Folgeschritte

- **Regel Link-Anlage (Observer sieht sie nicht):** Wer einen `CrmContactLink` für einen `RecEmployee` anlegt, muss danach selbst `syncEmployee()` aufrufen — das `created`-Event des MA feuert strukturell immer *vor* der Link-Anlage und der Observer reagiert nur auf `updated`. Betrifft heute **beide** Pfade: die **Bewerber-Übernahme** (`mirrorCrmContactLinks` — ein neu übernommener MA landet bis dahin erst beim nächsten Voll-Sync im Kontaktbuch; wird durch Nachtrag (b) geschlossen) und den **ZAS-Inline-Link-Fix** (Folgeschritt, muss die Regel von Anfang an einhalten).
- **Ein-MA-Team-Lücke (echtes Produktionsverhalten):** Wird der einzige aktive MA eines Teams inaktiv, ist die Soll-Menge leer → `empty_soll`-Guard greift (bewusst nicht force-übersteuerbar) → die Member-Zeile **bleibt bestehen und ist über den Sync nicht entfernbar**. Ausweg: manuelle Pflege über die CRM-Listen-UI.
- **Hard-Delete-Lücke (bewusst offen):** `recruiting:delete-employee` löscht die `CrmContactLink`-Zeilen vor dem `forceDelete` des MA — ein so gelöschter MA **bleibt bis zum nächsten Voll-/Scheduler-Sync im Telefonbuch**. Fix-Option (nicht Teil dieses Plans): `contact_id`s im Command **vor** dem Löschen der Link-Zeilen auflösen und nach der Transaction gezielt auf diese IDs syncen.

**In v1 gezogen (Nachträge nach Task 4, je eigener Commit + Review):**

- **(a) Scheduler-Eintrag:** `recruiting:sync-employee-contact-list` ohne `--force` periodisch (stündlich) — die Konvergenz-Garantie für alle obigen Lücken.
- **(b) Bewerber-Übernahme:** `syncEmployee()` direkt nach `mirrorCrmContactLinks()` in `CreateEmployeeFromApplicantService`, try/catch + `Log::error`, Savepoint-isoliert (inneres `DB::transaction`) — darf die Übernahme niemals kippen. Getrennt, weil es die Einstellungs-Strecke berührt (eigenes Review + Live-Klick).
  **Design-Notiz (Transaktions-Trade-off, korrigierte Begründung):** Der Sync läuft bewusst **innerhalb** der Übernahme-Transaktion. Vorteil: rollback-konsistent — rollt die Übernahme zurück, verschwinden die CRM-Writes mit. Preis: Row-Locks auf `crm_contact_list_members`/`crm_contact_lists` bleiben bis zum Commit der Übernahme bestehen. `DB::afterCommit` wäre **ebenfalls rollback-sicher** (feuert bei Rollback gar nicht) und dazu lockfrei — es wurde also *nicht* wegen des Rollback-Verhaltens verworfen, sondern der Lock-Preis wurde zugunsten der In-Transaction-Konsistenz akzeptiert. Bei realer Lock-Contention ist `afterCommit` der designierte Ausweg.

**Befund-Korrektur aus dem Final-Review:** Die Behauptung, `--team=abc` erzeuge via `getOrCreateForTeam(0)` eine Junk-Settings-Zeile mit Exit 0, war **falsch** — `rec_applicant_settings.team_id` hat eine FK auf `teams` (`...->constrained('teams')->cascadeOnDelete()`), der Insert wäre eine FK-Violation (QueryException, Exit ≠ 0) gewesen. Der Fast-Follow (ctype_digit-Validierung, Team-ID ≥ 1, Abbruch vor jedem DB-Zugriff) ist trotzdem umgesetzt: saubere Fehlermeldung statt Stacktrace.

**Weiter außerhalb des Scopes:**

- ZAS-Import legt CRM-Link inline an (nächster Schritt, separates Design; Regel Link-Anlage beachten).
- Harter Schreibschutz / Badge für sync-verwaltete Listen in der CRM-UI (CRM-Änderung → Bestätigung nötig).
- Sichtbarmachen der „mehrdeutig"-Fälle als HR-Desk-Case.
