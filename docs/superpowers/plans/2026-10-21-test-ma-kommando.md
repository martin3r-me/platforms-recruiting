# Test-MA-Kommando Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `php artisan recruiting:test-ma` erzeugt über den echten Pfad einen Test-MA (`is_test`), der nie an ZAS und nie ins Diensthandy-Telefonbuch geht; `--remove` räumt ihn restlos weg.

**Architecture:** Neues Flag `rec_employees.is_test`, gesetzt in `CreateEmployeeFromApplicantService` aus `rec_applicants.is_test`, gefiltert an allen ZAS-Ausgängen, Dispo-Matchern und der Kontaktliste. Die Anlage-/Lösch-Logik liegt in zwei Services unter `src/Services/TestEmployee/`; das Kommando ist nur Optionen + Ausgabe (Probe-Muster wie `DispoTestVaCommand`).

**Tech Stack:** Laravel (Modul ohne eigenes vendor), PHPUnit über `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (siehe Memory „PHPUnit-Runner“), Integrationstests mit Capsule + SQLite `:memory:`.

**Spec:** `docs/superpowers/specs/2026-10-21-test-ma-kommando-design.md`

## Global Constraints

- Branch `feat/test-ma-kommando`, nur Dateien in `platforms-recruiting` (kein Edit in Core/CRM).
- Migration `database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php`: boolean default false, `after('is_active')`, Index `idx_rec_employees_is_test`; Vorlage `2026_05_08_000001_add_is_test_to_rec_applicants.php`.
- Kein Versand von WhatsApp/Mail, kein `setEuCitizen()` (löst HR-Routing aus), AutoPilot aus.
- Test-E-Mail: `test-ma-<8 Zufallszeichen>@invalid.local`. Default-Name „Test Mitarbeiter“.
- Kommentarstil/Sprache wie Umgebung: deutsche Kommentare, ASCII-Umlaute (ae/oe/ue) in Code-Kommentaren.
- Integrationstests im Muster von `tests/Integration/EmployeeCreationCompanyTest.php` (Container, Dispatcher, Log-Attrappe mit `clearResolvedInstance`, `Model::clearBootedModels()`, alle eigenen Migrationen).

## Review Focus

1. Echter MA neben Test-MA: Jeder Filter muss den echten MA weiter liefern — jeder Filter-Test hat eine Gegenprobe (sonst „grünes Nichts“).
2. `--remove` auf echten MA (`is_test=false`) → verweigert, nichts gelöscht (Task 5).
3. Tippfehler in `--set/--datei/--leer` → Abbruch vor jedem Schreiben, kein halber Datensatz (Task 4).
4. Stelle ohne veröffentlichte Ausschreibung / ohne Owner-Kandidat → klare Meldung, nichts angelegt (Task 4).
5. Bestehender Test-MA bekommt später eine Personalnummer oder Telefon, das mit ZAS-Lieferung kollidiert → Dispo-Matcher ignorieren ihn (Task 2).

---

### Task 1: `is_test` am Mitarbeiter

**Files:**
- Create: `database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php`
- Modify: `src/Models/RecEmployee.php` (fillable + cast `'is_test' => 'boolean'`)
- Modify: `src/Services/CreateEmployeeFromApplicantService.php:69-90` (im `RecEmployee::create`-Array: `'is_test' => (bool) $applicant->is_test`)
- Test: `tests/Integration/EmployeeCreationCompanyTest.php` (zwei neue Testmethoden; dieselbe Fixture)

**Interfaces:**
- Produces: Spalte `rec_employees.is_test` (bool, default false).

- [ ] **Step 1: Failing tests** `testTestBewerberWirdTestMitarbeiter` (Bewerber `is_test=true` → `$employee->is_test === true`) und `testEchterBewerberBleibtEchterMitarbeiter` (`is_test=false` → `false`).
- [ ] **Step 2:** Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EmployeeCreationCompanyTest` → FAIL (Spalte fehlt).
- [ ] **Step 3:** Migration + Model + Service-Zeile.
- [ ] **Step 4:** Lauf → PASS; dann `--filter EmployeeCreation` komplett grün (Query-Zahl in `EmployeeCreationCertificateTest` darf sich nicht ändern).
- [ ] **Step 5: Commit** `feat(recruiting): Test-MA — is_test am Mitarbeiter`

### Task 2: ZAS-Schutz an allen Ausgängen

**Files (je `->where('is_test', false)`, bei DB::table `rec_employees.is_test`):**
- `src/Http/Controllers/ZasEmployeeInitialExportController.php:96` (`fetchInitialEmployees`)
- `src/Http/Controllers/ZasEmployeeUpdateExportController.php:96` (`fetchChangedEmployees`)
- `src/Observers/RecEmployeeExportObserver.php:188` (cost_center-Bulk) und `:214` (`markEmployeeId`)
- `src/Http/Controllers/ZasEmployeeFileController.php:54` (Test-MA → 404)
- `src/Console/Commands/ZasEmployeeExportBackfill.php:48-50`, `src/Console/Commands/ZasReExportByBookingDate.php:80-81, 140-141`
- `src/Services/Zas/Dispo/DispoIdentityResolver.php:64, :124` (`activeIds`-Queries)
- `src/Services/Zas/Dispo/DispoEmployeeDirectory.php:18-23, :34-39`
- `src/Services/Zas/Dispo/ZasDispoConfirmationExport.php:30` — `whereNot(fn ($q) => $q->whereIn('rec_employee_id', RecEmployee::query()->select('id')->where('is_test', true)))`; Buchungen mit `rec_employee_id = NULL` bleiben drin.
- Builder-Urteil (Spec): `ZasEmployeeMatcher`, `ZasInboundDuplicateFinder`, `ZasEmployeeContactLinker`, `ZasDispoTaetigkeitSync`, `PersonPairLinker` — mitfiltern, wo ein Test-MA sonst Ziel eines Inbound-Matches werden kann; im Commit begründen.
- Test: `tests/Integration/ZasTestEmployeeExclusionTest.php` (neu)

**Interfaces:**
- Consumes: Task 1 Spalte.
- Für Tests: `fetchInitialEmployees`/`fetchChangedEmployees` sind `protected` → Test-Subklasse mit Public-Wrapper (anonyme Klasse), wie in bestehenden Unit-Exporttests.

- [ ] **Step 1: Failing tests** (je echter MA `E` + Test-MA `T`, gleiche Felder):
  - `testInitialExportLaesstTestMaWeg`: IDs == [E].
  - `testUpdateExportLaesstTestMaWeg`: beide mit `zas_initial_exported_at` + `zas_changed_at` → [E].
  - `testObserverMarkiertTestMaNicht`: Feld an T ändern (save) → `zas_changed_at` bleibt null; an E → gesetzt.
  - `testKostenstellenwechselMarkiertTestMaNicht`: Position-cost_center ändern → nur E markiert.
  - `testDispoVerzeichnisKenntTestMaNicht`: beide mit PNr → `map()`/`activeIds()` ohne T.
  - `testBestaetigungsExportLaesstTestMaWeg`: bestätigte Assignments für E, T und eine ohne `rec_employee_id` → T fehlt, die anderen zwei da.
- [ ] **Step 2:** Lauf `--filter ZasTestEmployeeExclusionTest` → FAIL.
- [ ] **Step 3:** Filter einbauen.
- [ ] **Step 4:** Lauf → PASS; danach volle Suite (`phpunit -c phpunit.xml`) grün.
- [ ] **Step 5: Commit** `feat(recruiting): Test-MA — nie an ZAS (Exporte, Observer, Dispo, Bestaetigungen)`

### Task 3: Nicht ins Diensthandy-Telefonbuch

**Files:**
- Modify: `src/Services/EmployeeContactListSyncService.php:291-293` (`resolveDesired`: `->where('is_test', false)`)
- Test: `tests/Integration/EmployeeContactListSyncTest.php` (neue Methode, bestehende Fixture)

- [ ] **Step 1: Failing test** `testTestMaKommtNichtInDieListe`: E und T aktiv mit Kontakt → `syncAll` abonniert nur E-Kontakt; T-Kontakt vorher Mitglied → nach Sync entfernt.
- [ ] **Step 2:** Lauf → FAIL. **Step 3:** Filter. **Step 4:** Lauf → PASS.
- [ ] **Step 5: Commit** `feat(recruiting): Test-MA — nicht in „Aktive Mitarbeiter"`

### Task 4: Anlage-Service

**Files:**
- Create: `src/Services/TestEmployee/TestEmployeeProfiles.php` — `final class`, `public static function values(string $profil): array` (Feldname → Wert, inkl. `'_files' => list<string>` der Datei-Feldnamen, `'_eu' => bool`); `public const PROFILES = ['eu','nicht-eu','student','schueler']`.
- Create: `src/Services/TestEmployee/TestEmployeeCreator.php`
- Create: `src/Services/TestEmployee/PlaceholderImage.php` — `public static function png(string $label): string` (GD, 600×400 weiß, schwarzer Text; Bytes).
- Test: `tests/Integration/TestEmployeeCreatorTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  ```php
  public function create(
      int $positionId, string $profil, string $name, ?string $phone,
      array $set, array $files, array $leer, ?int $ownerId
  ): TestEmployeeResult   // readonly: applicantId, employeeId, contactId, portalUrl(?string), filled(list<string>), empty(list<string>)
  ```
  Wirft `\InvalidArgumentException` mit deutscher Meldung bei: unbekanntem Profil, unbekanntem Feldnamen in `$set/$files/$leer`, fehlender veröffentlichter Ausschreibung, keinem Owner, `$files`-Pfad nicht lesbar. Alle Prüfungen **vor** dem ersten Schreiben.

Ablauf (Spec „Ablauf Anlegen“):
1. Position laden; Zielphase = erste aktive Phase mit `completion_config['creates_employee_on_completion'] === true` (aufsteigend nach `order`), sonst letzte aktive Phase.
2. Feldkatalog = `CoreExtraFieldDefinition` mit `context_type=RecPhase::class`, `context_id` ∈ aktive Phasen der Stelle mit `order <= Zielphase.order`.
3. Validierung (s. o.). Owner = `$ownerId` ?? `OwnerResolver::resolve(null, position.owned_by_user_id, settings default_contact_user_id, team.user_id)` (wie `IncomingApplicationService.php:216-221`).
4. In `DB::transaction`: CrmContact (+ E-Mail, + Telefon falls gesetzt, Muster `src/Tools/CreateApplicantTool.php:113-176`) → `RecApplicant::create(['team_id', 'is_test'=>true, 'auto_pilot'=>false, 'owned_by_user_id'=>$owner, 'created_by_user_id'=>$owner])` → `linkContact` → `anzeigeVerknuepfen(veröffentlichte Ausschreibung)` → `rec_phase_id` = Zielphase → `clearExtraFieldDefinitionsCache()`.
5. Werte je Feld: `$leer` gewinnt; dann `$files[name]` / `$set[name]`; dann Profilwert; Datei-Felder nur, wenn im Profil `_files` oder in `$files`; sonst nur wenn effektiv Pflicht (`is_mandatory || is_required || options.required_in_phase_orders` enthält Zielphase.order) Platzhalter je Typ: text/textarea/regex „Test“, number 1, date heute+2 Jahre (`Y-m-d`), boolean false, select/lookup erster Optionswert, phone `$phone` oder leer, email die Test-Mail, address leer. Pflicht-Datei ohne Profilwert → Platzhalterbild.
6. Dateien: `ContextFileService::uploadForContext()` mit Kontext `RecApplicant::class`/applicant id (Muster `ZasEmployeeFileStore.php:36-60`), Platzhalter aus `PlaceholderImage::png("TEST – <Feldname>")`.
7. Rechtsstatus: `RecApplicantLegalStatus::updateOrCreate(['rec_applicant_id'=>…], ['team_id', 'is_eu_citizen'=>profil._eu, 'nationalpass_file_id'/'immatrikulation_file_id' aus gesetzten Feldern])` — **nicht** `setEuCitizen()`.
8. `app(CreateEmployeeFromApplicantService::class)->createOrUpdate($applicant, $owner)`.

Profilwerte (gemeinsam): vorname/nachname aus `$name`, geburtsdatum `1995-01-01`, geburtsort Düsseldorf, geburtsland DE, strasse Teststraße, hausnummer 1, plz 40210, stadt Düsseldorf, land DE, iban `DE89370400440532013000`, bic `COBADEFFXXX`, geldinstitut Testbank, steuer_id `12345678995`, sozialversicherungsnummer passend zum Geburtsdatum (`65010195T00` + berechnete Prüfziffer), email Test-Mail. Profil-spezifisch laut Spec-Tabelle; `ich_bin`, `geschlecht`, `familienstand`, `krankenkasse` nur setzen, wenn der Wert in den Optionen der Definition existiert, sonst erster Optionswert. Nicht-EU-Dateifelder: `nationalpass`, `zusatzblatt`, `visumsblatt` + Felder aus `src/Support/NonEuDocumentMapping.php`.

- [ ] **Step 1: Failing tests** (Fixture: Stelle mit Phase 1 (Text-Felder vorname, nachname, iban, nationalitaet), Phase 2 mit `creates_employee_on_completion` (Datei selfie_upload, nationalpass, Pflicht-Text `lieblingsfarbe`), Phase 3 (Text `nachher`); veröffentlichte Ausschreibung; `ContextFileService` per Container-Attrappe, die IDs hochzählt):
  - `testLegtTestMaUeberDenEchtenPfadAn`: MA `is_test`, `first_name`='Test', iban gemappt, `selfie_file_id` gesetzt, Bewerber `is_test` + Phase 2.
  - `testEuProfilBekommtKeineNichtEuDateien`: `nationalpass` leer, `is_eu_citizen` true.
  - `testNichtEuProfil`: `nationalpass` gesetzt, `is_eu_citizen` false, kein HR-Fall (keine Zeile in HR-Fall-Tabelle / Routing-Attrappe nicht aufgerufen).
  - `testPflichtfeldOhneProfilwertBekommtPlatzhalter`: `lieblingsfarbe`='Test'; `nachher` (Phase 3) leer.
  - `testLeerGewinnt` und `testSetUeberschreibt`.
  - `testUnbekanntesFeldBrichtVorJedemSchreibenAb`: Exception, 0 Bewerber, 0 Kontakte.
  - `testOhneAusschreibungBrichtAb`, `testOhneOwnerBrichtAb`.
- [ ] **Step 2:** Lauf → FAIL. **Step 3:** Implementieren. **Step 4:** Lauf → PASS.
- [ ] **Step 5: Commit** `feat(recruiting): Test-MA — Anlage ueber den echten Pfad`

### Task 5: Lösch-Service

**Files:**
- Create: `src/Services/TestEmployee/TestEmployeeRemover.php` — `public function remove(int $employeeId): array` (Zähler je Tabelle; leeres Array = nichts zu tun). Wirft `\InvalidArgumentException`, wenn MA existiert und `is_test=false`.
- Modify (optional, wenn sauber): Lösch-Kern aus `src/Console/Commands/DeleteEmployee.php:105-135` in eine wiederverwendbare Methode ziehen; sonst Logik nachbauen.
- Test: `tests/Integration/TestEmployeeRemoverTest.php`

Löscht in einer Transaktion: was `DeleteEmployee` löscht (inkl. CrmContact) **plus** RecApplicantLegalStatus, ContextFiles von Bewerber und MA (Datensatz + Datei über `ContextFileService`-Löschmethode), RecAutoPilotLog des Bewerbers, Kontaktlisten-Mitgliedschaft, Schulungszertifikat.

- [ ] **Step 1: Failing tests** (Fixture über `TestEmployeeCreator` aus Task 4):
  - `testEntferntAllesRestlos`: danach 0 Zeilen in rec_employees, rec_applicants, crm_contacts, crm_contact_links, core_extra_field_values, rec_applicant_legal_statuses, context_files für diese IDs.
  - `testVerweigertEchtenMa`: echter MA → Exception, MA noch da.
  - `testZweiterAufrufIstNoop`: leeres Array.
- [ ] **Step 2–4:** FAIL → implementieren → PASS.
- [ ] **Step 5: Commit** `feat(recruiting): Test-MA — restlos entfernen`

### Task 6: Kommando

**Files:**
- Create: `src/Console/Commands/CreateTestEmployee.php`, Signatur:
  ```
  recruiting:test-ma
      {--stelle=} {--profil=eu} {--name=Test Mitarbeiter} {--phone=}
      {--set=*} {--datei=*} {--leer=} {--owner=}
      {--remove=} {--list}
  ```
- Modify: `src/RecruitingServiceProvider.php` (neben `DispoTestVaCommand`, :77)
- Test: `tests/Unit/CreateTestEmployeeOptionsTest.php`

**Interfaces:**
- Consumes: `TestEmployeeCreator::create`, `TestEmployeeRemover::remove`.
- Produces: `public static function parsePairs(array $raw): array` (`["a=b","c=d"]` → `['a'=>'b','c'=>'d']`; Eintrag ohne `=` → `InvalidArgumentException`), `public static function parseList(?string $raw): array`.

`handle()`: `--list` → Tabelle aller `is_test`-MA (ID, Name, Telefon, angelegt). `--remove` → Remover, Zähler ausgeben. Sonst `--stelle` Pflicht → Creator; Ausgabe: Bewerber-/MA-/Kontakt-ID, Portal-Link `route('recruiting.public.employee-portal', ['token' => $employee->portal_token])`, befüllte/leere Felder. Exceptions → `$this->error()` + `FAILURE`.

- [ ] **Step 1: Failing tests** `testParsePairs`, `testParsePairsOhneGleichheitszeichenWirft`, `testParseListTrimmtUndIgnoriertLeere`.
- [ ] **Step 2–4:** FAIL → implementieren → PASS; volle Suite grün.
- [ ] **Step 5:** `php tools/blade-check.php` entfällt (kein Blade). Kommando-Registrierung per `grep -n CreateTestEmployee src/RecruitingServiceProvider.php` prüfen.
- [ ] **Step 6: Commit** `feat(recruiting): recruiting:test-ma`

### Abschluss

- Review des ganzen Branches (Memory „Prüf-Agent vor dem Merge“: berührt ZAS-Ausgänge).
- Nach Freigabe: ff-Merge auf main, Push, Bump meingedeck, Deploy **mit migrate**.
