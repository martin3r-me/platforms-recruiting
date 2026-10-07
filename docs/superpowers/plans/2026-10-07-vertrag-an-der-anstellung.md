# Vertrag an der Anstellung (Stufe 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Arbeitsvertrag traegt die Anstellung (`rec_contracts.rec_employee_id`), zu der er gehoert; Vorlagen tragen Firma und Taetigkeit; MA-Akte und MA-Portal zeigen nur die Vertraege der eigenen Anstellung; ein Backfill zieht den Bestand nach.

**Architecture:** Eine Migration (drei Spalten), zwei Beziehungen, EINE Zuordnungsregel (`RecContractTemplate::giltFuerAnstellung()` als einziges Firmen-Praedikat, `anstellungFuer()` darauf aufgebaut), ein Hook bei der MA-Anlage (Query Builder auf `rec_contracts`), Anker-Kopie bei der Neuausstellung, Anker-Setzung an den drei Anlagepfaden fuer Bestandsmitarbeiter, Anzeige-Umstellung, Backfill-Kommando. Keine Eloquent-Schreibung auf `rec_employees` in Backfill oder Hook.

**Tech Stack:** Laravel 11 / Eloquent, Livewire 3, PHPUnit 11 (Suite ohne Laravel-Bootstrap: Container + Capsule von Hand, echte Migrationen per glob). Runner: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (vom Modul-Root aus).

**Spec:** `docs/superpowers/specs/2026-10-07-vertrag-an-der-anstellung-design.md` (committed a99b2cb2 auf main). Executors lesen Spec UND Plan.

**Branch:** `feat/vertrag-anstellung` (abgezweigt von origin/main = 38b01651 + Spec-Commit). Alle Commits auf diesem Branch. Nichts ausserhalb `platforms-recruiting` anfassen; `platforms-recruiting-portal` nicht beruehren.

**Baseline (gemessen 07.10.2026 vor Task 1):** `Tests: 2427, Assertions: 11114, PHPUnit Deprecations: 3` — OK.

## Global Constraints

- Schema (Spec §3.1, woertlich): `rec_contracts.rec_employee_id unsignedBigInteger, nullable, index` · `rec_contract_templates.company string(10), NOT NULL, default 'RG'` · `rec_contract_templates.taetigkeit string(50), nullable`. **Kein Fremdschluessel-Constraint** auf `rec_employee_id`.
- Beziehungen (§3.2): `RecEmployee::contracts()` hasMany(`RecContract`, `rec_employee_id`) · `RecContract::employee()` belongsTo(`RecEmployee`, `rec_employee_id`). `RecApplicant::contracts()` bleibt unveraendert.
- Die Firmen-Regel „Anstellung mit `company` == Vorlage.`company`" lebt an **genau einer Stelle**: `RecContractTemplate::giltFuerAnstellung(RecEmployee): bool`. `anstellungFuer()` und der MA-Anlage-Hook benutzen sie; nirgends `'RG'` als Zuordnungs-Literal. Der Firmen-Default einer neuen Vorlage kommt aus `config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX)` — dieselbe Quelle wie `CreateEmployeeFromApplicantService:92`.
- Schreibwege in den Bestand: **nur Query Builder** (`DB::table('rec_contracts')->...->update()`), nie `RecContract::save()` im Hook/Backfill (der `RecContract::saved`-Hook schreibt auf HR-Daten und loest den ZAS-Marker aus), **nie** eine Eloquent-Schreibung auf `rec_employees`.
- Tests: festes Testdatum in der Vergangenheit (`Carbon::setTestNow('2026-09-15 10:00:00')`), nie `now()` ohne Testuhr. Jede der zwoelf Zusicherungen aus §3.7 hat eine dokumentierte Mutation (rein → rot, raus → gruen); der Implementierer fuehrt sie aus und schreibt das Ergebnis in die Commit-Message. Umfangs-Tests in **beide** Richtungen (RG sieht ihn, MA nicht).
- Nicht Teil des Pakets (§4): Auswahlregel, „Vertrag zuweisen aus der MA-Akte", Laufzeit-Spalte, Vormerkung umhaengen. Nicht anfassen.
- Bestehende Tests zu `Employees/Show` und `EmployeePortal`: migrieren, nicht loeschen. **Vorflug-Befund (07.10.):** `grep -rln "signedContracts\|openContracts\|->contracts()" tests | xargs grep -ln "Employees\\\\Show\|EmployeePortal"` liefert **keine Datei** — auf main gibt es keinen Test, der diese Methoden mit Vertraegen aufruft. Zusicherungen vorher: 0. Es gibt also nichts zu migrieren; die neuen Tests (Task 7) sind die ersten. Diese Zaehlung steht so im Abschlussbericht.
- Commit-Messages enden mit `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Kein Merge, kein Bump ohne Freigabe.

## Spec-Entscheidungen, die der Plan trifft (fuer den Abschlussbericht)

1. **`mehrdeutig` (zwei Anstellungen derselben Firma):** Spec §3.3 d) sagt „kleinste Kennung + Log", §3.7 Test 6 sagt „Backfill laesst `mehrdeutig` stehen und meldet". Aufloesung: die Regel (`anstellungFuer`) liefert den Befund `mehrdeutig` mit den Kandidaten aufsteigend nach `id`. Der **Live-Pfad d)** nimmt den ersten Kandidaten und schreibt `Log::warning` (Spec d) woertlich). Der **Backfill** laesst NULL stehen und zaehlt `mehrdeutig` (Test 6 woertlich). Ein Code, zwei dokumentierte Verwendungen.
2. **Firmen-Beschriftung:** Der ZAS-Export liefert nur den Code (`Firma => $employee->company`); eine Namensquelle fuer die MA-GmbH gibt es nirgends im Repo. Beschriftung ueber `config('recruiting.zas.company_labels')` = `['RG' => 'RheinGedeck']`, unbekannter Code → der Code selbst. Den MA-Namen traegt der User nach.
3. **„Anzeige" in der MA-Akte** umfasst alle vier Leser in `Employees/Show.php`: `signedContracts()` (335), `openContracts()` (380), `openReissueModal()` (435), `reissueContract()` (494). Nur 335 umzustellen liesse die Liste „Offene Vertraege" und den Ersetzen-Dialog weiter RG-Vertraege in der MA-Akte zeigen.
4. **`taetigkeit` nicht leer bei `AV-`:** Pruefung im Vorlagen-Formular und in den MCP-Tools, NICHT als Model-Hook — `CreateArbeitsvertragVariants`, `CopyHcmContractTemplates` und bestehende Tests legen `AV-`-Vorlagen ohne Taetigkeit an; Schritt 0 des Backfills fuellt den Bestand.
5. **Query-Zaehler** `EmployeeCreationCertificateTest::QUERIES_VOR_DEM_HOOK/QUERIES_SCHALTER_AUS` (23/24) steigen um die Abfrage des Hooks; der Idempotenz-Pfad (`QUERIES_ZWEITER_AUFRUF = 1`) bleibt bei 1, weil der Hook NACH der Idempotenz-Rueckgabe liegt.

## Review Focus

Fuenf Eingaben, die die Spec impliziert, aber kein §3.7-Test prueft — jede bekommt ihren Test in der besitzenden Task:

1. **Vorlage ohne gesetzte Firma** (per Eloquent angelegt, `company` nicht uebergeben): die Regel muss mit dem konfigurierten Praefix arbeiten, nicht mit NULL — sonst passt sie zu keiner Anstellung. → Task 1, `testNeueVorlageBekommtDieFirmaAusDerKonfiguration`.
2. **Mitarbeiter ohne `company`** (Altbestand vor 26.08., ZAS-Zeile ohne Praefix): darf nie als Treffer gelten (`NULL == NULL` waere ein Treffer). → Task 2, `test_anstellung_ohne_firma_ist_nie_ein_treffer`.
3. **Idempotenter zweiter Aufruf der MA-Anlage** (`ZasReExportByBookingDate` ruft `createOrUpdate()` ueber Listen): der Hook darf dort nicht laufen und nichts kosten. → Task 3, Konstante `QUERIES_ZWEITER_AUFRUF` bleibt 1 (bestehender Test) + `test_zweiter_aufruf_haengt_nichts_um`.
4. **Verwaister Anker** (`rec_employee_id` zeigt auf eine geloeschte `rec_employees`-Zeile, `recruiting:delete-employee`): kein FK, also muss der Bericht ihn nennen. → Task 8, Zaehler `verwaist` + `test_bericht_nennt_verwaiste_anker`.
5. **Stornierte Vertraege** im Backfill: auch sie bekommen den Anker (Archiv gehoert zur Anstellung), die Anzeige filtert `cancelled` weiterhin selbst. → Task 8, `test_backfill_haengt_auch_stornierte_an`; Task 7 prueft, dass die Akte stornierte weiterhin nicht zeigt.

## File Structure

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php` | neu — drei Spalten, hasColumn-Guards wie 2026_08_12_000001 |
| `src/Models/RecContract.php` | `rec_employee_id` fillable, `employee()` |
| `src/Models/RecContractTemplate.php` | `company`/`taetigkeit` fillable, Firmen-Default im creating-Hook, Sperre im updating-Hook, `giltFuerAnstellung()`, `anstellungFuer()` |
| `src/Models/RecEmployee.php` | `contracts()` |
| `src/Support/AnstellungsZuordnung.php` | neu — Ergebnisobjekt der Regel (Befund + Kandidaten) |
| `src/Support/VorlagenMerkmale.php` | neu — Beschriftung Firma/Taetigkeit (nur Anzeige) |
| `src/Services/ContractAnchorService.php` | neu — Hook a): `anAnstellungHaengen(RecEmployee): int` |
| `src/Services/CreateEmployeeFromApplicantService.php` | Hook-Aufruf nach `RecEmployee::create` |
| `src/Services/ReissueContractService.php` | Anker-Kopie in `createSuccessor` |
| `src/Services/SendContractsService.php`, `src/Livewire/Applicant/Show.php`, `src/Tools/CreateContractTool.php` | Pfad d): `rec_employee_id` aus der Regel |
| `src/Livewire/Employees/Show.php`, `resources/views/livewire/employees/show.blade.php` | Anzeige ueber `$employee->contracts`, Merkmale-Text |
| `src/Livewire/Public/EmployeePortal.php` | Anzeige ueber `$employee->contracts` |
| `src/Livewire/ContractTemplates/Index.php`, `resources/views/livewire/contract-templates/index.blade.php` | Felder Firma/Taetigkeit, Sperre nach Gebrauch |
| `src/Tools/CreateContractTemplateTool.php`, `src/Tools/UpdateContractTemplateTool.php` | Felder durchreichen |
| `src/Console/Commands/VertraegeAnAnstellung.php`, `src/RecruitingServiceProvider.php` | Backfill + Registrierung |
| `config/recruiting.php` | `zas.company_labels` |
| `tests/Support/TestSchema.php` | Spalten nachziehen + `contracts()` |
| `tests/Integration/VertragAnDerAnstellungTest.php` | neu — Tests 1, 2, 3, 8, 9, 10 (echte Migrationen) |
| `tests/Integration/VertraegeAnAnstellungCommandTest.php` | neu — Tests 5, 6, 7, 12 + Review-Focus 4/5 |
| `tests/Integration/ContractTemplateTypeInvariantsTest.php` | Test 11 + Review-Focus 1 |
| `tests/Integration/ReissueContractTest.php` | Test 4 |
| `tests/Integration/VertragAnDerAnstellungWiringTest.php` | neu — Quelltext-Zusicherungen (DirectHire-Reihenfolge, Anlagepfade, Formular) |
| `tests/Unit/AnstellungsZuordnungTest.php`, `tests/Unit/VorlagenMerkmaleTest.php` | neu — reines PHP |

---

### Task 1: Schema, Beziehungen, Firmen-Default

**Files:**
- Create: `database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php`
- Modify: `src/Models/RecContract.php` (`$fillable` Z. 22–37, Beziehungen ab Z. 97)
- Modify: `src/Models/RecContractTemplate.php` (`$fillable` Z. 77–91, `booted()` Z. 100–125, Beziehungen ab Z. 127)
- Modify: `src/Models/RecEmployee.php` (Beziehungen ab Z. 191)
- Modify: `tests/Support/TestSchema.php`
- Test: `tests/Integration/ContractTemplateTypeInvariantsTest.php`

**Interfaces:**
- Produces: `RecEmployee::contracts(): HasMany`, `RecContract::employee(): BelongsTo`, Spalten `rec_contracts.rec_employee_id`, `rec_contract_templates.company`, `rec_contract_templates.taetigkeit`; `TestSchema::contracts(Builder $schema): void`.
- Produces: `RecContractTemplate` traegt nach `create()` immer eine Firma (Default aus `config('recruiting.zas.company_prefix', \Platform\Recruiting\Support\ZasPersonnelNumber::DEFAULT_PREFIX)`).

- [ ] **Step 1: Failing tests schreiben** — in `tests/Integration/ContractTemplateTypeInvariantsTest.php` hinter `testZertifikatOhneCodeWirft()` einfuegen. Vorher in `setUpBeforeClass()` die Config um den Praefix erweitern: `['activity-log' => ['events' => []], 'recruiting' => ['zas' => ['company_prefix' => 'RG']]]`.

```php
    /**
     * Review-Focus 1: eine ueber Eloquent angelegte Vorlage ohne company
     * traegt am OBJEKT schon die konfigurierte Firma — nicht erst nach einem
     * refresh() ueber den Spalten-Default. Sonst sieht die Zuordnungsregel
     * NULL und passt zu keiner Anstellung.
     */
    public function testNeueVorlageBekommtDieFirmaAusDerKonfiguration(): void
    {
        $t = $this->make(['code' => 'AV-default']);

        $this->assertSame('RG', $t->company, 'Firma muss am Objekt stehen, nicht nur in der Spalte.');
        $this->assertSame('RG', $t->fresh()->company);
        $this->assertNull($t->taetigkeit, 'Taetigkeit wird nicht geraten.');

        config()->set('recruiting.zas.company_prefix', 'MA');
        try {
            $this->assertSame('MA', $this->make(['code' => 'AV-MA-LOG'])->company,
                'Falsifikator gegen ein fest verdrahtetes RG.');
        } finally {
            config()->set('recruiting.zas.company_prefix', 'RG');
        }
    }

    public function testGesetzteFirmaBleibtBeimAnlegenStehen(): void
    {
        $this->assertSame('MA', $this->make(['code' => 'AV-MA-LOG', 'company' => 'MA'])->fresh()->company);
    }
```

- [ ] **Step 2: Rot sehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'ContractTemplateTypeInvariantsTest'`
Expected: FAIL — `company` ist NULL (Spalte fehlt im TestSchema / kein Hook).

- [ ] **Step 3: Migration anlegen** — `database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertrag an der Anstellung (Spec 2026-10-07, §3.1).
 *
 * rec_contracts.rec_employee_id: die Anstellung (rec_employees), zu der der
 * Vertrag gehoert. Nullbar — im Bewerberstadium gibt es noch keine. BEWUSST
 * KEIN Fremdschluessel: eine rec_employees-Zeile kann aus dem ZAS-Bestand neu
 * entstehen oder ersetzt werden, der Vertrag ueberlebt das; nullOnDelete
 * wiederholte das change()/MySQL-Thema von 2026_10_01_000002 auf feat/ma-konto.
 * Verwaiste Verweise nennt recruiting:vertraege-an-anstellung.
 *
 * rec_contract_templates.company: fuer welche GmbH ein Vertrag aus dieser
 * Vorlage gilt — Werte wie rec_employees.company (RG/MA). Default 'RG' fuellt
 * den Bestand (Kunde 05.10.: alle vorhandenen Vertraege sind RG-Vertraege).
 * rec_contract_templates.taetigkeit: freier Schluessel ('eventmitarbeiter'),
 * NULL bei Belehrung/Zusatzvereinbarung. Schritt 0 des Backfills setzt ihn.
 *
 * Guards pro DDL-Operation wie in 2026_08_12_000001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contracts', 'rec_employee_id')) {
                $table->unsignedBigInteger('rec_employee_id')->nullable()->after('rec_applicant_id');
            }
            if (!Schema::hasIndex('rec_contracts', 'rec_contracts_rec_employee_id_index')) {
                $table->index('rec_employee_id');
            }
        });

        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contract_templates', 'company')) {
                $table->string('company', 10)->default('RG')->after('type');
            }
            if (!Schema::hasColumn('rec_contract_templates', 'taetigkeit')) {
                $table->string('taetigkeit', 50)->nullable()->after('company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            if (Schema::hasIndex('rec_contracts', 'rec_contracts_rec_employee_id_index')) {
                $table->dropIndex('rec_contracts_rec_employee_id_index');
            }
            if (Schema::hasColumn('rec_contracts', 'rec_employee_id')) {
                $table->dropColumn('rec_employee_id');
            }
        });
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            foreach (['taetigkeit', 'company'] as $column) {
                if (Schema::hasColumn('rec_contract_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
```

- [ ] **Step 4: Modelle** — `RecContract::$fillable`: `'rec_employee_id'` direkt hinter `'rec_applicant_id'`. Beziehung hinter `applicant()`:

```php
    /**
     * Die Anstellung, zu der dieser Vertrag gehoert (Spec Vertrag an der
     * Anstellung §3.2). NULL im Bewerberstadium — gesetzt bei der MA-Anlage,
     * kopiert bei der Neuausstellung, gefuellt vom Backfill.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }
```

`RecEmployee` — `use Illuminate\Database\Eloquent\Relations\HasMany;` ergaenzen, hinter `applicant()`:

```php
    /**
     * Vertraege DIESER Anstellung — nicht des Bewerbers. Ein Mensch kann zwei
     * Anstellungen haben (RG und MA); die Akte und das Portal lesen hier.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(RecContract::class, 'rec_employee_id');
    }
```

`RecContractTemplate::$fillable`: `'company', 'taetigkeit'` hinter `'type'`. Im `creating`-Hook hinter der uuid-Vergabe:

```php
            // Firma: dieselbe Quelle wie die MA-Anlage
            // (CreateEmployeeFromApplicantService) — eine Vorlage ohne Firma
            // passt zu keiner Anstellung, also bekommt sie die eigene.
            if ($model->company === null || $model->company === '') {
                $model->company = (string) config(
                    'recruiting.zas.company_prefix',
                    \Platform\Recruiting\Support\ZasPersonnelNumber::DEFAULT_PREFIX
                );
            }
```

- [ ] **Step 5: TestSchema nachziehen** — in `contractTemplates()` hinter `type`: `$t->string('company', 10)->default('RG'); $t->string('taetigkeit', 50)->nullable();`. Docblock-Liste der abgebildeten Migrationen um `2026_10_07_000002_add_employee_anchor_to_contracts.php` ergaenzen. Neue Methode (Abbild von `2026_04_15_100000` + `2026_08_21_000002` + dieser Migration, FK als unsignedBigInteger wie dort begruendet):

```php
    /** rec_contracts wie die Basis-Migration plus superseded_by_contract_id und rec_employee_id. */
    public static function contracts(Builder $schema): void
    {
        if ($schema->hasTable('rec_contracts')) {
            return;
        }

        $schema->create('rec_contracts', function ($t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->unsignedBigInteger('rec_applicant_id');
            $t->unsignedBigInteger('rec_employee_id')->nullable();
            $t->unsignedBigInteger('rec_contract_template_id');
            $t->unsignedBigInteger('team_id');
            $t->string('status', 30)->default('pending');
            $t->longText('personalized_content')->nullable();
            $t->text('signature_data')->nullable();
            $t->json('pre_signing_data')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('superseded_by_contract_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
    }
```

- [ ] **Step 6: Gruen sehen + volle Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'ContractTemplateTypeInvariantsTest'` → PASS.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK (2427 + 2 Tests). Vorflug (07.10.): die fuenf Tests mit handgebautem `rec_contract_templates`-Schema (`BackfillEmployerDeclarationTest`, `SignedDayBudgetTest`, `SignedEmployerDeclarationTest`, `ReportSignedWithoutEmployeeQueriesTest`, `BackfillShortTermDayBudgetTest`) schreiben nur per Query Builder und sind vom creating-Hook nicht betroffen; alle Tests mit `RecContractTemplate::create()` laden echte Migrationen oder `TestSchema`. Faellt trotzdem etwas um: Spalte im Handschema ergaenzen, nichts loeschen.

- [ ] **Step 7: Mutation** — den `company`-Block im creating-Hook auskommentieren → `testNeueVorlageBekommtDieFirmaAusDerKonfiguration` rot (Objekt NULL). Zurueck → gruen. Ergebnis in die Commit-Message.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php src/Models/RecContract.php src/Models/RecContractTemplate.php src/Models/RecEmployee.php tests/Support/TestSchema.php tests/Integration/ContractTemplateTypeInvariantsTest.php
git commit -m "feat(recruiting): Anker rec_employee_id am Vertrag, Firma/Taetigkeit an der Vorlage (Schema + Beziehungen)"
```

### Task 2: Die eine Zuordnungsregel

**Files:**
- Create: `src/Support/AnstellungsZuordnung.php`
- Modify: `src/Models/RecContractTemplate.php` (hinter `scopeForTeam()`)
- Test: `tests/Unit/AnstellungsZuordnungTest.php`, `tests/Integration/VertragAnDerAnstellungTest.php` (neu, Harness hier anlegen)

**Interfaces:**
- Produces: `RecContractTemplate::giltFuerAnstellung(RecEmployee $anstellung): bool` — das EINZIGE Firmen-Praedikat.
- Produces: `RecContractTemplate::anstellungFuer(RecApplicant $applicant): AnstellungsZuordnung`.
- Produces: `AnstellungsZuordnung` mit `public readonly string $befund` (eine der Konstanten `ZUGEORDNET`, `MEHRDEUTIG`, `FIRMA_FEHLT`, `OHNE_ANSTELLUNG`), `public readonly array $kandidatenIds` (aufsteigend), `anstellungId(): ?int` (nur bei `ZUGEORDNET` ≠ null), `ersterKandidatId(): ?int` (bei `ZUGEORDNET` und `MEHRDEUTIG`).

- [ ] **Step 1: Unit-Test** `tests/Unit/AnstellungsZuordnungTest.php` (reines PHP, kein Container):

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\AnstellungsZuordnung;

class AnstellungsZuordnungTest extends TestCase
{
    public function test_genau_eine_passende_ist_zugeordnet(): void
    {
        $z = AnstellungsZuordnung::aus([17], hatAndereAnstellungen: false);
        $this->assertSame(AnstellungsZuordnung::ZUGEORDNET, $z->befund);
        $this->assertSame(17, $z->anstellungId());
        $this->assertSame(17, $z->ersterKandidatId());
    }

    public function test_mehrere_passende_sind_mehrdeutig_und_aufsteigend(): void
    {
        $z = AnstellungsZuordnung::aus([23, 4], hatAndereAnstellungen: false);
        $this->assertSame(AnstellungsZuordnung::MEHRDEUTIG, $z->befund);
        $this->assertNull($z->anstellungId(), 'mehrdeutig ordnet nichts zu');
        $this->assertSame(4, $z->ersterKandidatId());
        $this->assertSame([4, 23], $z->kandidatenIds);
    }

    public function test_keine_passende_aber_andere_ist_firma_fehlt(): void
    {
        $z = AnstellungsZuordnung::aus([], hatAndereAnstellungen: true);
        $this->assertSame(AnstellungsZuordnung::FIRMA_FEHLT, $z->befund);
        $this->assertNull($z->anstellungId());
        $this->assertNull($z->ersterKandidatId());
    }

    public function test_gar_keine_anstellung(): void
    {
        $this->assertSame(AnstellungsZuordnung::OHNE_ANSTELLUNG, AnstellungsZuordnung::aus([], false)->befund);
    }
}
```

- [ ] **Step 2: Rot** — `--filter AnstellungsZuordnungTest` → Klasse fehlt.

- [ ] **Step 3: Wertobjekt** `src/Support/AnstellungsZuordnung.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Ergebnis der Zuordnungsregel Vorlage → Anstellung (Spec §3.3 d / §3.5).
 * Reines PHP, damit die Befunde ohne Datenbank pruefbar sind.
 */
final class AnstellungsZuordnung
{
    public const ZUGEORDNET = 'zugeordnet';
    public const MEHRDEUTIG = 'mehrdeutig';
    public const FIRMA_FEHLT = 'firma_fehlt';
    public const OHNE_ANSTELLUNG = 'ohne_anstellung';

    /** @param list<int> $kandidatenIds aufsteigend */
    private function __construct(
        public readonly string $befund,
        public readonly array $kandidatenIds,
    ) {}

    /** @param list<int> $passendeIds Anstellungen mit der Firma der Vorlage */
    public static function aus(array $passendeIds, bool $hatAndereAnstellungen): self
    {
        $ids = array_values(array_map('intval', $passendeIds));
        sort($ids);

        return match (true) {
            count($ids) === 1       => new self(self::ZUGEORDNET, $ids),
            count($ids) > 1         => new self(self::MEHRDEUTIG, $ids),
            $hatAndereAnstellungen  => new self(self::FIRMA_FEHLT, []),
            default                 => new self(self::OHNE_ANSTELLUNG, []),
        };
    }

    /** Nur bei eindeutiger Zuordnung. */
    public function anstellungId(): ?int
    {
        return $this->befund === self::ZUGEORDNET ? $this->kandidatenIds[0] : null;
    }

    /** Kleinste Kennung — auch bei mehrdeutig (Spec §3.3 d: Live-Pfad nimmt sie und loggt). */
    public function ersterKandidatId(): ?int
    {
        return $this->kandidatenIds[0] ?? null;
    }
}
```

- [ ] **Step 4: Regel am Modell** — `RecContractTemplate`, `use Platform\Recruiting\Support\AnstellungsZuordnung;` hinzu, Methoden hinter `scopeForTeam()`:

```php
    /**
     * DAS Firmen-Praedikat — die einzige Stelle, an der „Vorlage gehoert zu
     * Anstellung" entschieden wird (Spec §3.3 d, §3.5: derselbe Code). Eine
     * Anstellung ohne Firma (Altbestand, ZAS-Zeile ohne Praefix) ist nie ein
     * Treffer: NULL == NULL waere einer.
     */
    public function giltFuerAnstellung(RecEmployee $anstellung): bool
    {
        $firma = (string) $anstellung->company;

        return $firma !== '' && $firma === (string) $this->company;
    }

    /**
     * Welche Anstellung des Bewerbers bekommt einen Vertrag aus dieser Vorlage?
     * Kandidaten aufsteigend nach id; was der Aufrufer mit `mehrdeutig` macht,
     * entscheidet er (Live-Pfad: erster + Log, Backfill: NULL + Bericht).
     * Nicht $applicant->employee (hasOne, bei zwei Anstellungen beliebig).
     */
    public function anstellungFuer(RecApplicant $applicant): AnstellungsZuordnung
    {
        $alle = $applicant->employees()->orderBy('id')->get(['id', 'company']);
        $passende = $alle->filter(fn (RecEmployee $e) => $this->giltFuerAnstellung($e));

        return AnstellungsZuordnung::aus(
            $passende->pluck('id')->all(),
            $alle->count() > $passende->count(),
        );
    }
```

- [ ] **Step 5: Integrations-Harness anlegen** — `tests/Integration/VertragAnDerAnstellungTest.php`. Aufbau woertlich wie `EmployeeCreationCompanyTest` (Container, Config mit `recruiting.zas.company_prefix = 'RG'` **und** `recruiting.zas.inbound_team_id = self::TEAM` **und** `recruiting.zas.company_labels = ['RG' => 'RheinGedeck']`, Dispatcher, log-Attrappe, Auth-Attrappe, Capsule, `Model::clearBootedModels()`, Facade, `runRealMigrations()` + `packageRootOf()` + `leereExtraFieldCache()` kopieren). Drei Ergaenzungen:
  1. `runRealMigrations()`: zusaetzlich `$core . '/database/migrations/2026_02_23_000001_create_core_public_form_links_table.php'` (fuer `getOrCreatePublicFormLink()` in Task 7). Vorher mit `ls` pruefen, dass die Datei dort liegt; `packageRootOf(\Platform\Core\Models\User::class)` liefert den Core-Pfad.
  2. Auth-Attrappe wie `EmployeeActivityLogTest` (User mit `currentTeam->id = self::TEAM` **und** `check()`/`id()` fuer `CrmContactLink::creating`): eine Klasse, die `AuthFactory` implementiert, `guard()` liefert `$this`, `user()` liefert das Objekt, `check()` false, `id()` null. Binden unter `\Illuminate\Contracts\Auth\Factory::class` UND Alias `'auth'`.
  3. url-Attrappe wie `DirectHireGroupingCompletenessTest:449` (`$container->instance('url', new class { public function route($name, $parameters = [], $absolute = true): string { return '/' . $name . '/' . http_build_query($parameters); } });`).
  `setUp()`: `Carbon::setTestNow('2026-09-15 10:00:00')`; `tearDown()`: `Carbon::setTestNow()`. Fixture-Helfer:

```php
    private function bewerber(string $nachname): RecApplicant  // wie EmployeeCreationCompanyTest::bewerber, plus 'zuschlag' => 1.0
    private function vorlage(string $code, string $company = 'RG', ?string $taetigkeit = null): RecContractTemplate
    {
        return RecContractTemplate::create([
            'team_id' => self::TEAM, 'name' => $code, 'code' => $code,
            'company' => $company, 'taetigkeit' => $taetigkeit, 'is_active' => true,
            'content' => '<p>Vertrag</p>', 'field_mappings' => [],
        ]);
    }
    private function vertrag(RecApplicant $a, RecContractTemplate $t, array $attrs = []): RecContract
    {
        return RecContract::create(array_merge([
            'rec_applicant_id' => $a->id, 'rec_contract_template_id' => $t->id, 'team_id' => self::TEAM,
            'status' => 'completed', 'sent_at' => '2026-09-01 09:00:00',
            'signed_at' => '2026-09-02 09:00:00', 'completed_at' => '2026-09-02 09:00:00',
            'personalized_content' => '<p>Vertrag</p>',
        ], $attrs));
    }
    /** Zweite Anstellung „aus ZAS": ohne Bewerbung, mit Lieferungs-Kennung, Firma aus dem Praefix. */
    private function zasAnstellung(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Zas', 'last_name' => 'Person',
            'personnel_number' => $pnr, 'company' => ZasPersonnelNumber::prefixOf($pnr),
            'rec_zas_inbound_file_id' => 1, 'is_active' => true,
        ]);
    }
    private function verknuepfen(RecEmployee $e, RecApplicant $a): void  // wie report-signed-without-employee --link
    {
        DB::table('rec_employees')->where('id', $e->id)->update(['rec_applicant_id' => $a->id]);
    }
```

  Erster Test (Review-Focus 2 + Regel gegen echte Tabellen):

```php
    public function test_regel_waehlt_die_firmengleiche_anstellung_und_nie_eine_ohne_firma(): void
    {
        $a = $this->bewerber('Regel');
        $ma = $this->zasAnstellung('MA100');  $this->verknuepfen($ma, $a);   // kleinere id
        $rg = $this->zasAnstellung('RG100');  $this->verknuepfen($rg, $a);
        $ohne = RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Ohne', 'last_name' => 'Firma', 'rec_applicant_id' => $a->id, 'is_active' => true]);
        $this->assertNull($ohne->fresh()->company, 'Vorflug: diese Anstellung hat keine Firma');

        $this->assertSame($rg->id, $this->vorlage('AV-A')->anstellungFuer($a)->anstellungId());
        $this->assertSame($ma->id, $this->vorlage('AV-B', 'MA')->anstellungFuer($a)->anstellungId());

        $fremd = $this->vorlage('AV-C', 'XX');
        $this->assertSame(AnstellungsZuordnung::FIRMA_FEHLT, $fremd->anstellungFuer($a)->befund);

        $this->assertSame(AnstellungsZuordnung::OHNE_ANSTELLUNG, $this->vorlage('AV-D')->anstellungFuer($this->bewerber('Leer'))->befund);
    }
```

- [ ] **Step 6: Gruen** — `--filter 'AnstellungsZuordnungTest|VertragAnDerAnstellungTest'` → PASS.

- [ ] **Step 7: Mutationen** — (a) in `giltFuerAnstellung` den `$firma !== ''`-Teil entfernen und `company` der Vorlage auf NULL-Vergleich lassen → `$ohne` wuerde bei einer Vorlage ohne Firma treffen; dafuer im Test zusaetzlich pruefen: `$this->vorlage('AV-E')->forceFill(['company' => null])` → `anstellungFuer($a)->befund === FIRMA_FEHLT`. Rot ohne den Guard, gruen mit. (b) `orderBy('id')` entfernen → `test_mehrere_passende_sind_mehrdeutig_und_aufsteigend` bleibt gruen (sortiert im Wertobjekt) — das ist gewollt, die Sortierung lebt im Wertobjekt. Dokumentieren.

- [ ] **Step 8: Commit**

```bash
git add src/Support/AnstellungsZuordnung.php src/Models/RecContractTemplate.php tests/Unit/AnstellungsZuordnungTest.php tests/Integration/VertragAnDerAnstellungTest.php
git commit -m "feat(recruiting): Zuordnungsregel Vorlage -> Anstellung (eine Stelle, Firmen-Praedikat)"
```

### Task 3: Hook bei der MA-Anlage (Spec §3.3 a/b/e) — Tests 1, 3, 10

**Files:**
- Create: `src/Services/ContractAnchorService.php`
- Modify: `src/Services/CreateEmployeeFromApplicantService.php` (nach `$this->mirrorCrmContactLinks(...)`, Z. 107)
- Modify: `tests/Integration/EmployeeCreationCertificateTest.php` (Konstanten Z. 131–132)
- Test: `tests/Integration/VertragAnDerAnstellungTest.php`, `tests/Integration/VertragAnDerAnstellungWiringTest.php` (neu)

**Interfaces:**
- Consumes: `RecContractTemplate::giltFuerAnstellung(RecEmployee)` (Task 2).
- Produces: `ContractAnchorService::anAnstellungHaengen(RecEmployee $anstellung): int` — Anzahl der umgehaengten Vertraege; nur Vertraege des Bewerbers (`rec_applicant_id = $anstellung->rec_applicant_id`) mit `rec_employee_id IS NULL`, deren Vorlage `giltFuerAnstellung($anstellung)` erfuellt; Schreibweg `DB::table('rec_contracts')->whereIn('id', ...)->update(['rec_employee_id' => ..., 'updated_at' => now()])`. Alle Arten (AV, IFSG, AT), auch stornierte.

- [ ] **Step 1: Failing tests** in `VertragAnDerAnstellungTest`:

```php
    /** §3.7 Test 1 — Mutation: Hook-Aufruf in CreateEmployeeFromApplicantService entfernen → rot. */
    public function test_ma_anlage_zieht_alle_vertraege_nach(): void
    {
        $a = $this->bewerber('Anlage');
        $av   = $this->vertrag($a, $this->vorlage('AV-default'));
        $ifsg = $this->vertrag($a, $this->vorlage('IFSG'));
        $at   = $this->vertrag($a, $this->vorlage('AT-140'), ['status' => 'sent', 'signed_at' => null, 'completed_at' => null]);

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);

        foreach ([$av, $ifsg, $at] as $c) {
            $this->assertSame($employee->id, (int) $c->fresh()->rec_employee_id, "Vertrag #{$c->id} haengt nicht an der neuen Anstellung");
        }
        $this->assertSame(3, $employee->contracts()->count());
    }

    /** §3.7 Test 10 — Mutation: Firmenfilter (giltFuerAnstellung) im Hook entfernen → rot. */
    public function test_ma_anlage_haengt_nur_firmengleiche_vertraege_an(): void
    {
        $a = $this->bewerber('Firma');
        $rgVertrag = $this->vertrag($a, $this->vorlage('AV-default', 'RG'));
        $maVertrag = $this->vertrag($a, $this->vorlage('AV-MA-LOG', 'MA', 'logistiker'));

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);

        $this->assertSame('RG', $employee->fresh()->company, 'Vorflug: die Anlage ist eine RG-Anstellung');
        $this->assertSame($employee->id, (int) $rgVertrag->fresh()->rec_employee_id);
        $this->assertNull($maVertrag->fresh()->rec_employee_id, 'Ein MA-Vertrag darf nicht an eine RG-Anstellung rutschen.');
    }

    /**
     * §3.7 Test 3 — die ZAS-Anstellung bekommt nichts. Reihenfolge wie im
     * Bestand: der MA-Datensatz kommt zuerst aus ZAS (kleinere id), wird VOR der
     * Phase-4-Anlage an den Bewerber verknuepft (--link), dann laeuft die Anlage.
     * Die Anlage ist idempotent und liefert die MA-Anstellung zurueck — der
     * RG-Vertrag muss trotzdem NULL bleiben.
     * Mutation: Hook „am Bewerber" — in createOrUpdate VOR die Idempotenz-
     * Rueckgabe ziehen und als
     *   DB::table('rec_contracts')->where('rec_applicant_id', $applicant->id)->whereNull('rec_employee_id')
     *       ->update(['rec_employee_id' => $applicant->employee?->id])
     * schreiben → rot (der RG-Vertrag landet an MA).
     */
    public function test_zas_anstellung_bekommt_nichts(): void
    {
        $a = $this->bewerber('Zas');
        $av = $this->vertrag($a, $this->vorlage('AV-default'));
        $ma = $this->zasAnstellung('MA353');
        $this->verknuepfen($ma, $a);

        $zurueck = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);

        $this->assertSame($ma->id, $zurueck->id, 'Vorflug: die Anlage ist idempotent und liefert die verknuepfte MA-Anstellung');
        $this->assertNull($av->fresh()->rec_employee_id, 'RG-Vertrag in der MA-Akte — genau der Fund aus §1');
        $this->assertSame(0, $ma->contracts()->count());
    }

    /** Review-Focus 3 — Mutation: Hook vor die Idempotenz-Rueckgabe → rot (und QUERIES_ZWEITER_AUFRUF kippt). */
    public function test_zweiter_aufruf_haengt_nichts_um(): void
    {
        $a = $this->bewerber('Zweimal');
        $this->vertrag($a, $this->vorlage('AV-default'));
        $rg = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);
        $spaeter = $this->vertrag($a, $this->vorlage('AT-140'));   // nach der Anlage, ohne Anker (Pfad d ist hier nicht beteiligt)

        (new CreateEmployeeFromApplicantService())->createOrUpdate($a);

        $this->assertNull($spaeter->fresh()->rec_employee_id, 'Der idempotente Pfad haengt nichts um.');
        $this->assertSame(1, $rg->contracts()->count());
    }
```

  Quelltext-Test fuer die Direkteinstellung (Spec §3.3 b) — `tests/Integration/VertragAnDerAnstellungWiringTest.php`, Muster `PortalCertificateWiringTest::methodSource()`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\DirectHire\Index as DirectHire;
use ReflectionClass;
use ReflectionMethod;

/**
 * Quelltext-Zusicherungen fuer Stellen, die in dieser Suite nicht ausfuehrbar
 * sind (Livewire-Lebenszyklus, MCP-ToolContext). Schmal und benannt — der
 * Verhaltensnachweis liegt in VertragAnDerAnstellungTest.
 */
class VertragAnDerAnstellungWiringTest extends TestCase
{
    /** Spec §3.3 b: Direkteinstellung legt den Vertrag an, DANN den Mitarbeiter — sonst sieht der Hook keinen Vertrag. Mutation: Reihenfolge tauschen → rot. */
    public function test_direkteinstellung_legt_den_vertrag_vor_dem_mitarbeiter_an(): void
    {
        $src = $this->methodSource(DirectHire::class, 'createEmployeeWithContract');
        $vertrag = strpos($src, 'RecContract::create(');
        $anlage  = strpos($src, '->createOrUpdate(');
        $this->assertNotFalse($vertrag);
        $this->assertNotFalse($anlage);
        $this->assertLessThan($anlage, $vertrag, 'Der Vertrag muss VOR createOrUpdate() entstehen.');
    }

    private function methodSource(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);
        $lines = file($r->getFileName());
        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }
}
```

- [ ] **Step 2: Rot** — `--filter 'VertragAnDerAnstellungTest'`: Tests 1 und 10 rot (Anker bleibt NULL); Test 3 und „zweiter Aufruf" sind vor dem Hook strukturell gruen — das ist in Ordnung, ihre Mutation wird in Step 5 ausgefuehrt. Wiring-Test gruen (Reihenfolge stimmt heute, Z. 428 vor Z. 450).

- [ ] **Step 3: Dienst** `src/Services/ContractAnchorService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Haengt die Vertraege eines Bewerbers an seine frisch angelegte Anstellung
 * (Spec Vertrag an der Anstellung §3.3 a). Alle Arten — AV, IFSG, AT —, nur
 * mit leerem Anker, nur wenn die Vorlage die Firma der Anstellung traegt
 * (RecContractTemplate::giltFuerAnstellung, die eine Stelle).
 *
 * Query Builder, nicht RecContract::save(): der saved-Hook des Vertrags
 * schreibt beim Signieren auf die HR-Daten und wuerde hier den ZAS-Marker
 * stempeln (RecEmployeeExportObserver auf RecEmployeeHrData::saved).
 */
class ContractAnchorService
{
    public function anAnstellungHaengen(RecEmployee $anstellung): int
    {
        if ($anstellung->rec_applicant_id === null) {
            return 0;
        }

        $ids = RecContract::query()
            ->where('rec_applicant_id', $anstellung->rec_applicant_id)
            ->whereNull('rec_employee_id')
            ->with('contractTemplate')
            ->get(['id', 'rec_contract_template_id'])
            ->filter(fn (RecContract $c) => $c->contractTemplate?->giltFuerAnstellung($anstellung) === true)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return DB::table('rec_contracts')
            ->whereIn('id', $ids)
            ->update(['rec_employee_id' => $anstellung->id, 'updated_at' => now()]);
    }
}
```

- [ ] **Step 4: Hook-Aufruf** in `CreateEmployeeFromApplicantService::createOrUpdate()`, innerhalb der Transaktion, direkt nach `$this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);`:

```php
            // Vertraege an die neue Anstellung haengen (Spec Vertrag an der
            // Anstellung §3.3 a). HINTER der Idempotenz-Rueckgabe oben: der
            // zweite Aufruf (ZasReExportByBookingDate ueber Listen) darf weder
            // kosten noch umhaengen. Alle Arten, nur firmengleiche Vorlagen.
            (new \Platform\Recruiting\Services\ContractAnchorService())->anAnstellungHaengen($employee);
```

  Docblock der Klasse unter „Side-Effects" ergaenzen: `- Haengt die Vertraege des Bewerbers an die neue Anstellung (ContractAnchorService)`.

- [ ] **Step 5: Gruen + Zaehler** — `--filter 'VertragAnDerAnstellungTest|VertragAnDerAnstellungWiringTest'` → PASS. Dann `--filter EmployeeCreationCertificateTest`: die Query-Zaehler kippen (Hook = 1 Select auf `rec_contracts`; bei Treffern kaeme 1 Select `rec_contract_templates` + 1 Update dazu, in jener Fixture gibt es keine Vertraege → +1). `QUERIES_VOR_DEM_HOOK` 23→24, `QUERIES_SCHALTER_AUS` 24→25, `QUERIES_ZWEITER_AUFRUF` **bleibt 1** (sonst liegt der Hook falsch). Docblock-Nachtrag dort im Stil der bestehenden („07.10.2026: beide Zahlen um eins erhoeht — Anker-Hook …"). Gemessene Zahlen in die Commit-Message.

- [ ] **Step 6: Mutationen ausfuehren** — (1) Hook-Aufruf auskommentieren → Test 1 rot. (10) im Dienst den `->filter(...)` entfernen → Test 10 rot. (3) Hook „am Bewerber" vor die Idempotenz-Rueckgabe (Code im Test-Docblock) → Test 3 rot UND `QUERIES_ZWEITER_AUFRUF` rot. (b) im DirectHire-Quelltext die beiden Bloecke tauschen → Wiring rot. Jeweils zuruecknehmen, gruen.

- [ ] **Step 7: Volle Suite + Commit**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Services/ContractAnchorService.php src/Services/CreateEmployeeFromApplicantService.php tests/Integration/EmployeeCreationCertificateTest.php tests/Integration/VertragAnDerAnstellungTest.php tests/Integration/VertragAnDerAnstellungWiringTest.php
git commit -m "feat(recruiting): MA-Anlage haengt firmengleiche Vertraege an die neue Anstellung"
```

### Task 4: Neuausstellung kopiert den Anker (Spec §3.3 c) — Test 4

**Files:**
- Modify: `src/Services/ReissueContractService.php` (`createSuccessor()` Z. 232–249, Aufrufe in `reissue()` ~Z. 110 und `reissueOpen()` ~Z. 188)
- Test: `tests/Integration/ReissueContractTest.php`

**Interfaces:**
- Consumes: `signedAvFixture()`, `openAvFixture()`, `makeEmployee(RecApplicant): int` (bestehende Helfer der Testklasse — vorher lesen, Z. 425 ff.).
- Produces: `createSuccessor(..., ?int $recEmployeeId)` — neuer letzter Parameter; der Nachfolger traegt `rec_employee_id` des Vorgaengers.

- [ ] **Step 1: Failing tests** hinter FALL 21 einfuegen:

```php
    /**
     * FALL 22 — der Nachfolger bleibt an derselben Anstellung (Spec Vertrag an
     * der Anstellung §3.3 c). Ohne die Kopie verloere jeder neu ausgestellte
     * Vertrag seine Anstellung und verschwaende aus der MA-Akte.
     * Mutation: 'rec_employee_id' aus dem create()-Array in createSuccessor()
     * entfernen → rot.
     */
    public function test_neuausstellung_kopiert_die_anstellung(): void
    {
        [$applicant, , $old] = $this->signedAvFixture(0.60);
        $employeeId = $this->makeEmployee($applicant);
        DB::table('rec_contracts')->where('id', $old->id)->update(['rec_employee_id' => $employeeId]);

        $new = (new ReissueContractService())->reissue(
            RecContract::find($old->id), 1.60, ReissueContractService::REASON_CORRECTION
        )['contract'];

        $this->assertSame($employeeId, (int) $new->fresh()->rec_employee_id);
    }

    /** FALL 23 — gilt vor der Unterschrift genauso. */
    public function test_neuausstellung_vor_unterschrift_kopiert_die_anstellung(): void
    {
        [$applicant, , $open] = $this->openAvFixture(0.60);
        $employeeId = $this->makeEmployee($applicant);
        DB::table('rec_contracts')->where('id', $open->id)->update(['rec_employee_id' => $employeeId]);

        $new = (new ReissueContractService())->reissueOpen(RecContract::find($open->id), 1.60)['contract'];

        $this->assertSame($employeeId, (int) $new->fresh()->rec_employee_id);
    }

    /** FALL 24 — ein Vorgaenger ohne Anstellung ergibt einen Nachfolger ohne Anstellung, kein Raten. */
    public function test_neuausstellung_ohne_anstellung_bleibt_ohne(): void
    {
        [, , $old] = $this->signedAvFixture(0.60);
        $new = (new ReissueContractService())->reissue($old, 1.60, ReissueContractService::REASON_CORRECTION)['contract'];
        $this->assertNull($new->fresh()->rec_employee_id);
    }
```

  Falls `makeEmployee()` die Spalte `company` nicht setzt: nicht noetig — die Kopie fragt keine Firma, sie kopiert.

- [ ] **Step 2: Rot** — `--filter 'ReissueContractTest'`: FALL 22/23 rot.

- [ ] **Step 3: Kopie** — `createSuccessor()` bekommt `?int $recEmployeeId = null` als letzten Parameter; im `RecContract::create([...])` hinter `'rec_applicant_id'`: `'rec_employee_id' => $recEmployeeId,`. Beide Aufrufer uebergeben `$old->rec_employee_id !== null ? (int) $old->rec_employee_id : null` bzw. `$open->...`. Docblock von `createSuccessor` um einen Satz: „Kopiert die Anstellung des Vorgaengers (Spec Vertrag an der Anstellung §3.3 c) — der Nachfolger gehoert zu derselben GmbH."

- [ ] **Step 4: Gruen, Mutation (Kopie entfernen → 22/23 rot), Commit**

```bash
git add src/Services/ReissueContractService.php tests/Integration/ReissueContractTest.php
git commit -m "feat(recruiting): Neuausstellung kopiert die Anstellung des Vorgaengers"
```

### Task 5: Vertrag fuer einen Bewerber, der schon Mitarbeiter ist (Spec §3.3 d) — Test 9

**Files:**
- Modify: `src/Services/SendContractsService.php` (drei `RecContract::create([...])` Z. 111, 133, 167)
- Modify: `src/Livewire/Applicant/Show.php` (`createSingleContract()` Z. 800–812)
- Modify: `src/Tools/CreateContractTool.php` (`RecContract::create` Z. 100)
- Test: `tests/Integration/VertragAnDerAnstellungTest.php`, `tests/Integration/VertragAnDerAnstellungWiringTest.php`

**Interfaces:**
- Consumes: `RecContractTemplate::anstellungFuer(RecApplicant): AnstellungsZuordnung` (Task 2).
- Produces: `RecContractTemplate::ankerFuerNeuenVertrag(RecApplicant $applicant): ?int` — Live-Fassung der Regel fuer Pfad d): `zugeordnet` → id; `mehrdeutig` → `ersterKandidatId()` + `Log::warning('[Vertrag an der Anstellung] mehrdeutig', [...ids...])`; sonst null. Alle drei Anlagepfade schreiben `'rec_employee_id' => $template->ankerFuerNeuenVertrag($applicant)`.

- [ ] **Step 1: Failing tests** in `VertragAnDerAnstellungTest`. Der Dienst `SendContractsService::send()` ist der einzige in der Suite ausfuehrbare Pfad d). Sein Versand-Riegel (`versandBereitschaft()`) wird uebersprungen, sobald `hasAnyContractSent()` wahr ist — deshalb traegt die Fixture einen bereits versendeten IFSG; der Dienst legt dann den AV neu an (Z. 111) und nutzt den IFSG wieder. `contract_template_id` und `zuschlag` muessen gesetzt sein; `skipNotification: true`; `checkAutoPilotCompletion()` steigt bei `rec_phase_id = NULL` aus (Z. 909).

```php
    /**
     * §3.7 Test 9 — Pfad d) ueber SendContractsService.
     * Mutation: 'rec_employee_id' aus dem AV-create() in SendContractsService:111 entfernen → rot.
     */
    public function test_vertrag_fuer_bestandsmitarbeiter_traegt_dessen_anstellung(): void
    {
        $av   = $this->vorlage('AV-default');
        $ifsg = $this->vorlage('IFSG');

        $a = $this->bewerber('Bestand');
        $this->vertrag($a, $ifsg, ['status' => 'sent', 'signed_at' => null, 'completed_at' => null]);   // oeffnet den Riegel
        $rg = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);
        $a = $a->fresh();
        $a->contract_template_id = $av->id;
        $a->zuschlag = 1.0;
        $a->save();

        $result = (new SendContractsService())->send($a, null, null, true);

        $this->assertSame(1, $result['created'], 'Vorflug: der AV ist in diesem Lauf neu entstanden');
        $this->assertSame($rg->id, (int) $result['av_contract']->rec_employee_id);
    }

    public function test_vertrag_fuer_bewerber_ohne_anstellung_bleibt_ohne_anker(): void
    {
        $av   = $this->vorlage('AV-default');
        $this->vorlage('IFSG');
        $a = $this->bewerber('Niemand');
        $this->vertrag($a, $this->vorlage('AT-140'), ['status' => 'sent', 'signed_at' => null, 'completed_at' => null]);
        $a->contract_template_id = $av->id;
        $a->zuschlag = 1.0;
        $a->save();

        $result = (new SendContractsService())->send($a, null, null, true);

        $this->assertNull($result['av_contract']->rec_employee_id);
        $this->assertNull($result['ifsg_contract']->rec_employee_id);
    }

    /**
     * Zwei Anstellungen, der Vertrag geht an die firmengleiche — und nicht an
     * $applicant->employee (hasOne = kleinste rowid, hier die MA-Zeile).
     * Mutation: ankerFuerNeuenVertrag() auf `$applicant->employee?->id` → rot.
     */
    public function test_neuer_vertrag_waehlt_die_firmengleiche_anstellung(): void
    {
        $av = $this->vorlage('AV-default');
        $this->vorlage('IFSG');
        $a = $this->bewerber('Zwei');
        $ma = $this->zasAnstellung('MA777'); $this->verknuepfen($ma, $a);          // kleinere id
        $rg = $this->zasAnstellung('RG777'); $this->verknuepfen($rg, $a);
        $this->vertrag($a, $this->vorlage('AT-140'), ['status' => 'sent', 'signed_at' => null, 'completed_at' => null]);
        $a->contract_template_id = $av->id; $a->zuschlag = 1.0; $a->save();

        $result = (new SendContractsService())->send($a, null, null, true);

        $this->assertSame($rg->id, (int) $result['av_contract']->rec_employee_id, 'RG-Vorlage → RG-Anstellung');
        $this->assertSame(0, $ma->contracts()->count(), 'MA bekommt nichts');
    }
```

  Wiring-Tests fuer die zwei nicht ausfuehrbaren Pfade (in `VertragAnDerAnstellungWiringTest`):

```php
    /** Spec §3.3 d: alle Anlagepfade rufen die eine Regel. Mutation: Aufruf an einer Stelle entfernen → rot. */
    public function test_alle_anlagepfade_setzen_den_anker_ueber_die_regel(): void
    {
        $erwartet = [
            [\Platform\Recruiting\Livewire\Applicant\Show::class, 'createSingleContract', 1],
            [\Platform\Recruiting\Tools\CreateContractTool::class, 'execute', 1],
            [\Platform\Recruiting\Services\SendContractsService::class, 'send', 3],
        ];
        foreach ($erwartet as [$class, $method, $anzahl]) {
            $src = $this->methodSource($class, $method);
            $this->assertSame($anzahl, substr_count($src, '->ankerFuerNeuenVertrag('), "{$class}::{$method}");
            $this->assertSame($anzahl, substr_count($src, "'rec_employee_id'"), "{$class}::{$method} schreibt den Anker nicht an jeder create()-Stelle");
        }
    }
```

- [ ] **Step 2: Rot** — `--filter 'VertragAnDerAnstellungTest|VertragAnDerAnstellungWiringTest'`.

- [ ] **Step 3: Live-Fassung der Regel** in `RecContractTemplate` hinter `anstellungFuer()` (`use Illuminate\Support\Facades\Log;`):

```php
    /**
     * Anker fuer einen Vertrag, der JETZT fuer diesen Bewerber entsteht (Spec
     * §3.3 d): die firmengleiche Anstellung; keine → NULL; mehrere → die mit
     * der kleinsten Kennung und ein Log-Eintrag. Alle Anlagepfade rufen das
     * hier — nicht viermal ausgeschrieben.
     */
    public function ankerFuerNeuenVertrag(RecApplicant $applicant): ?int
    {
        $zuordnung = $this->anstellungFuer($applicant);

        if ($zuordnung->befund === AnstellungsZuordnung::MEHRDEUTIG) {
            Log::warning('[Vertrag an der Anstellung] Bewerber hat mehrere Anstellungen der Firma der Vorlage — kleinste Kennung genommen', [
                'applicant_id' => $applicant->id,
                'template_id'  => $this->id,
                'company'      => $this->company,
                'employee_ids' => $zuordnung->kandidatenIds,
            ]);
        }

        return $zuordnung->ersterKandidatId();
    }
```

- [ ] **Step 4: Drei Pfade** — in jedem `RecContract::create([...])`-Array hinter `'rec_applicant_id'`:
  - `SendContractsService` Z. 111: `'rec_employee_id' => $avTemplate->ankerFuerNeuenVertrag($applicant),` · Z. 133: `$ifsgTemplate->ankerFuerNeuenVertrag($applicant)` · Z. 167: `$additionalTemplate->ankerFuerNeuenVertrag($applicant)`.
  - `Applicant/Show::createSingleContract()`: `'rec_employee_id' => $template->ankerFuerNeuenVertrag($this->applicant),`.
  - `CreateContractTool::execute()`: `'rec_employee_id' => $template->ankerFuerNeuenVertrag($applicant),`.
  `ReissueContractService` bleibt bei der Kopie (Task 4) — kein Regel-Aufruf dort, das ist Absicht (Spec c).

- [ ] **Step 5: Gruen, Mutationen** (Z. 111 Anker raus → Test 9 rot; `ankerFuerNeuenVertrag` auf `$applicant->employee?->id` → „firmengleich" rot; Aufruf in `CreateContractTool` raus → Wiring rot). Volle Suite. Commit:

```bash
git add src/Models/RecContractTemplate.php src/Services/SendContractsService.php src/Livewire/Applicant/Show.php src/Tools/CreateContractTool.php tests/Integration/VertragAnDerAnstellungTest.php tests/Integration/VertragAnDerAnstellungWiringTest.php
git commit -m "feat(recruiting): neue Vertraege fuer Bestandsmitarbeiter tragen die firmengleiche Anstellung"
```

### Task 6: Vorlagen-Firma nach Gebrauch gesperrt, Formular und MCP-Tools — Test 11

**Files:**
- Modify: `src/Models/RecContractTemplate.php` (`booted()`)
- Modify: `src/Livewire/ContractTemplates/Index.php`, `resources/views/livewire/contract-templates/index.blade.php` (Z. 163–166, Tabelle Z. 31)
- Modify: `src/Tools/CreateContractTemplateTool.php` (Schema Z. 30–60, create Z. 87–98), `src/Tools/UpdateContractTemplateTool.php` (Schema Z. 36–60, `$fields` Z. 96)
- Test: `tests/Integration/ContractTemplateTypeInvariantsTest.php`, `tests/Integration/VertragAnDerAnstellungWiringTest.php`

**Interfaces:**
- Produces: `RecContractTemplate::updating`-Hook wirft `\LogicException`, wenn `company` geaendert wird und `contracts()->exists()`. `taetigkeit` ist frei aenderbar.

- [ ] **Step 1: Failing test** in `ContractTemplateTypeInvariantsTest` — `setUpBeforeClass()` zusaetzlich `TestSchema::contracts($capsule->schema());`, `setUp()` zusaetzlich `Capsule::table('rec_contracts')->delete();`:

```php
    /**
     * §3.7 Test 11 — Model-Hook, nicht nur Formular: UpdateContractTemplateTool
     * schreibt am Formular vorbei. Mutation: updating-Hook entfernen → rot.
     */
    public function testFirmaIstNachDemErstenVertragGesperrt(): void
    {
        $t = $this->make(['code' => 'AV-default', 'company' => 'RG']);
        $t->update(['company' => 'MA']);
        $this->assertSame('MA', $t->fresh()->company, 'Ohne Vertrag ist die Firma aenderbar.');

        \Platform\Recruiting\Models\RecContract::create([
            'rec_applicant_id' => 1, 'rec_contract_template_id' => $t->id, 'team_id' => self::TEAM, 'status' => 'pending',
        ]);

        $t->update(['taetigkeit' => 'servicekraft']);
        $this->assertSame('servicekraft', $t->fresh()->taetigkeit, 'Die Taetigkeit bleibt frei.');

        $this->expectException(\LogicException::class);
        $t->update(['company' => 'RG']);
    }
```

- [ ] **Step 2: Rot.** — Hinweis: `RecContract::creating` braucht den Dispatcher (uuid) — die Klasse hat ihn (setEventDispatcher in setUpBeforeClass).

- [ ] **Step 3: Hook** in `RecContractTemplate::booted()` hinter dem `saving`-Hook:

```php
        // Firma ist nach dem ersten Vertrag aus dieser Vorlage unveraenderlich
        // (Spec Vertrag an der Anstellung §3.1): die Vertraege haengen ueber
        // die Vorlage an ihrer GmbH. Wer eine andere Firma will, legt eine
        // neue Vorlage an. Exception statt stiller Korrektur — auch die
        // MCP-Tools schreiben hier durch.
        static::updating(function (self $model) {
            if ($model->isDirty('company') && $model->contracts()->exists()) {
                throw new \LogicException(
                    "Vorlage #{$model->id} ({$model->code}) hat bereits Vertraege erzeugt — "
                    . 'die Firma ist gesperrt. Fuer eine andere Firma eine neue Vorlage anlegen.'
                );
            }
        });
```

- [ ] **Step 4: Formular** `ContractTemplates/Index.php`: Properties `public $company = ''; public $taetigkeit = '';`, Rules `'company' => 'required|string|max:10', 'taetigkeit' => 'nullable|string|max:50'`; `openEditModal()` laedt beide (`$this->company = (string) $m->company; $this->taetigkeit = (string) ($m->taetigkeit ?? '');`); `resetForm()` setzt `company` auf `(string) config('recruiting.zas.company_prefix', \Platform\Recruiting\Support\ZasPersonnelNumber::DEFAULT_PREFIX)` und `taetigkeit` auf `''`; `save()`: vor `$this->validate()` die AV-Pflicht:

```php
        if (str_starts_with(trim((string) $this->code), 'AV-') && trim((string) $this->taetigkeit) === '') {
            $this->addError('taetigkeit', 'Arbeitsvertrags-Vorlagen (AV-) brauchen eine Taetigkeit, z. B. eventmitarbeiter.');
            return;
        }
```

  `$data` bekommt `'company' => strtoupper(trim($this->company))` und `'taetigkeit' => trim($this->taetigkeit) !== '' ? strtolower(trim($this->taetigkeit)) : null`. Beim Bearbeiten: `company` nur in `$data`, wenn `!$this->companyLocked`; `#[Computed] public function companyLocked(): bool { return $this->editingId ? RecContractTemplate::findOrFail($this->editingId)->contracts()->exists() : false; }`. Den `\LogicException` aus dem Hook in `save()` fangen → `$this->addError('company', $e->getMessage()); return;`.

  View: hinter dem Code-Feld (Z. 164):

```blade
                <x-ui-input-text name="company" label="Firma (RG/MA) *" wire:model="company" :disabled="$this->companyLocked" />
                @if($this->companyLocked)
                    <p class="text-xs text-[var(--ui-muted)] -mt-2">Gesperrt: aus dieser Vorlage wurden bereits Vertraege erzeugt.</p>
                @endif
                <x-ui-input-text name="taetigkeit" label="Taetigkeit (bei AV- Pflicht, z. B. eventmitarbeiter)" wire:model="taetigkeit" />
```

  Belegt (07.10.): `meingedeck/vendor/martin3r/platform-ui-tailwind/resources/views/components/form/input-text.blade.php` Z. 56 rendert `{{ $attributes->merge([...]) }}` auf das `<input>` — `:disabled="$this->companyLocked"` kommt durch (Laravel laesst `false`-Attribute weg). Kein natives Input noetig. Keine `@if` inline in Attributen, keine `::`-Fallbacks (Memory: Blade-Komponenten-Fallen). Tabelle (Z. 31) bekommt hinter dem Code eine Spalte `{{ $item->company }}@if($item->taetigkeit) · {{ $item->taetigkeit }}@endif` — Block-Form, nicht an ein Wortzeichen geklebt.
  Blade pruefen: `php tools/blade-check.php resources/views/livewire/contract-templates/index.blade.php` (Memory: `php -l` prueft bei Blade nichts).

- [ ] **Step 5: MCP-Tools** — Create: Schema-Properties `company` (`string`, „Optional: Firma RG/MA. Default: eigene Firma.") und `taetigkeit` (`string`, „Pflicht bei AV-: z. B. eventmitarbeiter"); im `create([...])`: `'company' => isset($arguments['company']) ? strtoupper(trim((string) $arguments['company'])) : null, 'taetigkeit' => isset($arguments['taetigkeit']) ? strtolower(trim((string) $arguments['taetigkeit'])) : null,` (NULL → creating-Hook setzt den Default). AV-Pflicht wie im Formular als `ToolResult::error('VALIDATION_ERROR', ...)`. Update: `$fields` um `'company', 'taetigkeit'`; `$template->save()` in `try` — `\LogicException` → `ToolResult::error('VALIDATION_ERROR', $e->getMessage())` (vor dem generischen `\Throwable`-Fang).

- [ ] **Step 6: Wiring-Test** (in `VertragAnDerAnstellungWiringTest`):

```php
    public function test_vorlagen_formular_und_tools_kennen_firma_und_taetigkeit(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/contract-templates/index.blade.php');
        $this->assertStringContainsString('wire:model="company"', $view);
        $this->assertStringContainsString('wire:model="taetigkeit"', $view);
        foreach ([\Platform\Recruiting\Tools\CreateContractTemplateTool::class, \Platform\Recruiting\Tools\UpdateContractTemplateTool::class] as $tool) {
            $src = file_get_contents((new ReflectionClass($tool))->getFileName());
            $this->assertStringContainsString("'company'", $src, $tool);
            $this->assertStringContainsString("'taetigkeit'", $src, $tool);
        }
    }
```

- [ ] **Step 7: Gruen, Mutation (updating-Hook raus → Test 11 rot), volle Suite, Commit**

```bash
git add src/Models/RecContractTemplate.php src/Livewire/ContractTemplates/Index.php resources/views/livewire/contract-templates/index.blade.php src/Tools/CreateContractTemplateTool.php src/Tools/UpdateContractTemplateTool.php tests/Integration/ContractTemplateTypeInvariantsTest.php tests/Integration/VertragAnDerAnstellungWiringTest.php
git commit -m "feat(recruiting): Vorlage traegt Firma und Taetigkeit, Firma nach Gebrauch gesperrt"
```

### Task 7: Anzeige — MA-Akte und Portal lesen die Anstellung (Spec §3.4) — Tests 2, 8

**Files:**
- Create: `src/Support/VorlagenMerkmale.php`
- Modify: `config/recruiting.php` (im `zas`-Block hinter `company_prefix`)
- Modify: `src/Livewire/Employees/Show.php` (`employee()` Z. 288–294, `signedContracts()` Z. 328–360, `openContracts()` Z. 373–400, `openReissueModal()` Z. 435, `reissueContract()` Z. 494)
- Modify: `resources/views/livewire/employees/show.blade.php` (Z. 435, 473)
- Modify: `src/Livewire/Public/EmployeePortal.php` (`employee()` Z. 629–636, `contracts()` Z. 639–672)
- Test: `tests/Unit/VorlagenMerkmaleTest.php`, `tests/Integration/VertragAnDerAnstellungTest.php`

**Interfaces:**
- Produces: `VorlagenMerkmale::firma(?string $company): string` (Label aus `config('recruiting.zas.company_labels', [])`, sonst der Code, leer bleibt leer), `VorlagenMerkmale::taetigkeit(?string): string` (`eventmitarbeiter` → `Eventmitarbeiter`, sonst `ucfirst`, leer bleibt leer), `VorlagenMerkmale::zeile(?string $company, ?string $taetigkeit): string` (`RheinGedeck · Eventmitarbeiter`, Teile ohne Wert fallen weg).
- Produces: `Show::signedContracts()`/`openContracts()`-Zeilen bekommen den Schluessel `'merkmale'`.
- **Kein Uebergangs-Rueckfall** auf `applicant->contracts` (Spec §3.4).

- [ ] **Step 1: Unit-Test** `tests/Unit/VorlagenMerkmaleTest.php` — reines PHP; die Klasse nimmt die Labels als Parameter, damit kein Container noetig ist:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VorlagenMerkmale;

class VorlagenMerkmaleTest extends TestCase
{
    private const LABELS = ['RG' => 'RheinGedeck'];

    public function test_zeile_mit_beiden_merkmalen(): void
    {
        $this->assertSame('RheinGedeck · Eventmitarbeiter', VorlagenMerkmale::zeile('RG', 'eventmitarbeiter', self::LABELS));
    }

    public function test_unbekannte_firma_zeigt_den_code_und_leer_bleibt_leer(): void
    {
        $this->assertSame('MA', VorlagenMerkmale::zeile('MA', null, self::LABELS));
        $this->assertSame('', VorlagenMerkmale::zeile(null, null, self::LABELS));
        $this->assertSame('Logistiker', VorlagenMerkmale::zeile('', 'logistiker', self::LABELS));
    }
}
```

- [ ] **Step 2: Rot.**

- [ ] **Step 3: Support-Klasse + Config**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Beschriftung der Vorlagen-Merkmale fuer die MA-Akte (Spec §3.4) — nur
 * Anzeige, keine Logik. Firma: Label aus recruiting.zas.company_labels,
 * sonst der Code selbst (die MA-GmbH hat im Repo keine Namensquelle; der
 * ZAS-Export fuehrt nur den Code). Leer bleibt leer.
 */
final class VorlagenMerkmale
{
    /** @param array<string,string>|null $labels null = aus der Config lesen */
    public static function zeile(?string $company, ?string $taetigkeit, ?array $labels = null): string
    {
        return implode(' · ', array_filter([self::firma($company, $labels), self::taetigkeit($taetigkeit)], fn ($s) => $s !== ''));
    }

    public static function firma(?string $company, ?array $labels = null): string
    {
        $code = trim((string) $company);
        if ($code === '') {
            return '';
        }
        $labels ??= (array) config('recruiting.zas.company_labels', []);

        return (string) ($labels[$code] ?? $code);
    }

    public static function taetigkeit(?string $taetigkeit): string
    {
        $t = trim((string) $taetigkeit);

        return $t === '' ? '' : mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
    }
}
```

  `config/recruiting.php`, hinter `'company_prefix' => ...`:

```php
        // Anzeige-Beschriftung der Firmen in der MA-Akte (Vertrag an der
        // Anstellung §3.4). Nur Labels — die Zuordnung laeuft ueber den Code.
        // Unbekannter Code zeigt sich selbst. Name der zweiten GmbH nachtragen.
        'company_labels'         => ['RG' => 'RheinGedeck'],
```

- [ ] **Step 4: Failing Integrationstests** (Tests 2 und 8, beide Richtungen; `use Platform\Recruiting\Livewire\Employees\Show; use Platform\Recruiting\Livewire\Public\EmployeePortal;`):

```php
    /** @return array{0: RecApplicant, 1: RecEmployee, 2: RecEmployee, 3: RecContract} RG-Anstellung mit AV, MA-Anstellung ohne */
    private function zweiAnstellungenMitRgVertrag(): array
    {
        $a = $this->bewerber('Doppelt');
        $av = $this->vertrag($a, $this->vorlage('AV-default', 'RG', 'eventmitarbeiter'));
        $this->vertrag($a, $this->vorlage('AV-alt'), ['status' => 'cancelled']);   // Review-Focus 5: storniert bleibt unsichtbar
        $rg = (new CreateEmployeeFromApplicantService())->createOrUpdate($a);
        $ma = $this->zasAnstellung('MA18232');
        $this->verknuepfen($ma, $a);
        $this->assertSame($rg->id, (int) $av->fresh()->rec_employee_id, 'Vorflug: AV haengt an RG');
        return [$a, $rg, $ma, $av];
    }

    private function akte(RecEmployee $e): Show
    {
        $show = new Show();
        $show->employeeId = $e->id;
        return $show;
    }

    private function portal(RecEmployee $e): EmployeePortal
    {
        $portal = new EmployeePortal();
        $portal->state = 'verified';
        $portal->employeeId = $e->id;
        return $portal;
    }

    /** §3.7 Test 2 — Mutation: Show::signedContracts() zurueck auf $emp->applicant->contracts → rot (MA-Richtung). */
    public function test_ma_akte_zeigt_nur_die_vertraege_der_eigenen_anstellung(): void
    {
        [, $rg, $ma, $av] = $this->zweiAnstellungenMitRgVertrag();

        $rgZeilen = $this->akte($rg)->signedContracts();
        $this->assertCount(1, $rgZeilen, 'RG sieht ihren Vertrag');
        $this->assertSame($av->id, $rgZeilen[0]['id']);
        $this->assertSame('RheinGedeck · Eventmitarbeiter', $rgZeilen[0]['merkmale']);
        $this->assertStringContainsString('recruiting.public.contract-pdf', $rgZeilen[0]['pdf_url'], 'Link weiter ueber den Bewerber-Token');

        $this->assertSame([], $this->akte($ma)->signedContracts(), 'MA sieht den RG-Vertrag NICHT — der Fund aus §1');
        $this->assertSame([], $this->akte($ma)->openContracts());
    }

    /** Offene Vertraege und der Ersetzen-Dialog lesen dieselbe Menge. Mutation: openContracts() zurueck auf applicant → rot. */
    public function test_offene_vertraege_der_ma_akte_gehoeren_zur_anstellung(): void
    {
        [$a, $rg, $ma] = $this->zweiAnstellungenMitRgVertrag();
        $offen = $this->vertrag($a, $this->vorlage('AT-140'), ['status' => 'sent', 'signed_at' => null, 'completed_at' => null, 'rec_employee_id' => $rg->id]);

        $this->assertSame([$offen->id], array_column($this->akte($rg)->openContracts(), 'id'));
        $this->assertSame([], $this->akte($ma)->openContracts());

        $akteMa = $this->akte($ma);
        $akteMa->openReissueModal($offen->id);
        $this->assertSame('Vertrag nicht gefunden.', $akteMa->flashError, 'Die MA-Akte darf einen RG-Vertrag nicht ersetzen koennen.');
    }

    /** §3.7 Test 8 — Mutation: EmployeePortal::contracts() zurueck auf $employee->applicant->contracts → rot. */
    public function test_portal_zeigt_nur_eigene_vertraege(): void
    {
        [, $rg, $ma, $av] = $this->zweiAnstellungenMitRgVertrag();

        $rgZeilen = $this->portal($rg)->contracts();
        $this->assertSame([$av->id], array_column($rgZeilen, 'id'), 'RG sieht ihren Vertrag (storniert bleibt weg)');
        $this->assertSame([], $this->portal($ma)->contracts(), 'MA sieht ihn nicht');
    }
```

  Vorflug zu `openReissueModal()`: die Methode liest `$emp->applicant->zuschlag` und `getExtraField()` nur NACH dem Fund — fuer die MA-Richtung reicht die Fehlermeldung. `flashError` ist `public` (Z. 436 setzt es) — pruefen, sonst `$akteMa->flashError` ueber Reflection lesen.

- [ ] **Step 5: Rot** — beide MA-Richtungen rot (die Akte zeigt den RG-Vertrag), RG-Richtung gruen.

- [ ] **Step 6: Show umstellen**
  - `employee()`: `RecEmployee::with(['position', 'applicant', 'contracts.contractTemplate'])`.
  - `signedContracts()`: `$emp->applicant` bleibt die Token-Quelle; Menge: `$emp->contracts`. Docblock: „Vertraege DIESER Anstellung (Spec Vertrag an der Anstellung §3.4) — der Token gehoert dem Menschen, die Menge der Anstellung. Kein Rueckfall auf den Bewerber: der zeigte bei zwei Anstellungen den RG-Vertrag in der MA-Akte." Zeile ergaenzen: `'merkmale' => \Platform\Recruiting\Support\VorlagenMerkmale::zeile($c->contractTemplate?->company, $c->contractTemplate?->taetigkeit),`.
  - `openContracts()`: `$emp->contracts` + `'merkmale'` genauso.
  - `openReissueModal()` Z. 435 und `reissueContract()` Z. 494: `$emp?->contracts->firstWhere('id', ...)`.
  - Blade Z. 435 hinter `display_name`: 
    ```blade
                                    @if($c['merkmale'] !== '')
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $c['merkmale'] }}</span>
                                    @endif
    ```
    Z. 473 (offene) analog mit `$oc['merkmale']`. `php tools/blade-check.php resources/views/livewire/employees/show.blade.php`.

- [ ] **Step 7: Portal umstellen** — `employee()`: `RecEmployee::with(['applicant', 'contracts.contractTemplate'])`; `contracts()`: `$employee->contracts` statt `$employee->applicant->contracts`, Guard `if (!$employee || !$employee->applicant) return [];` bleibt (Token). Docblock-Satz wie in Show. `TrainingCertificatePortalRows::append(...)` und `certificateRows((int) $employee->applicant->id)` unveraendert (PortalCertificateWiringTest prueft das).

- [ ] **Step 8: Gruen, Mutationen** (signedContracts/openContracts/contracts() je einzeln zurueck auf den Bewerber → jeweils die MA-Richtung rot), volle Suite, Commit:

```bash
git add src/Support/VorlagenMerkmale.php config/recruiting.php src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php src/Livewire/Public/EmployeePortal.php tests/Unit/VorlagenMerkmaleTest.php tests/Integration/VertragAnDerAnstellungTest.php
git commit -m "feat(recruiting): MA-Akte und Portal zeigen nur die Vertraege der eigenen Anstellung"
```

### Task 8: Backfill `recruiting:vertraege-an-anstellung` (Spec §3.5) — Tests 5, 6, 7, 12

**Files:**
- Create: `src/Console/Commands/VertraegeAnAnstellung.php`
- Modify: `src/RecruitingServiceProvider.php` (Command-Liste, hinter `DispoUnfreezeDress::class`)
- Test: `tests/Integration/VertraegeAnAnstellungCommandTest.php`

**Interfaces:**
- Consumes: `RecContractTemplate::anstellungFuer(RecApplicant): AnstellungsZuordnung` (Task 2) — Backfill-Regel ist derselbe Code.
- Produces: `VertraegeAnAnstellung::backfill(bool $dryRun, ?int $teamId, callable $out): array{vorlagen_typisiert:int, zugeordnet:int, mehrdeutig:int, firma_fehlt:int, ohne_anstellung:int, verwaist:int}` — wie `BackfillEmployerDeclaration::backfill()` direkt testbar, ohne `handle()`.
- Signature: `recruiting:vertraege-an-anstellung {--dry-run} {--team=}`; Rueckgabe `FAILURE` nur bei `\Throwable` aus der Datenbank.

- [ ] **Step 1: Harness + failing tests** — `tests/Integration/VertraegeAnAnstellungCommandTest.php`, Aufbau wie `BackfillEmployeeCompanyTest` (Config `company_prefix = 'RG'`, log-Attrappe, Dispatcher, Capsule, Facade, `Model::unguard()`, `Model::clearBootedModels()`), aber Schema aus den ECHTEN eigenen Migrationen per glob (wie `VersandVormerkenTest::runRealMigrations()`, nur die eigenen Dateien plus die zwei Core-Extra-Field-Migrationen; `teams`/`users`/`hcm_job_titles`/`comms_channels` als leere Tabellen wie dort). `RecEmployeeExportObserver::register()` **einmal** in `setUpBeforeClass()` — der echte Observer ist die Falle fuer Test 7. `setUp()`: `Carbon::setTestNow('2026-09-15 10:00:00')`, Tabellen `rec_contracts`, `rec_contract_templates`, `rec_employees`, `rec_employee_hr_data`, `rec_applicants` leeren. Helfer (alle per Query Builder, damit kein Hook laeuft und `created_at`/`updated_at`/`zas_changed_at` fest gesetzt sind):

```php
    private const TEAM = 9;
    private const T0 = '2026-09-01 08:00:00';

    private function applicant(): int
    {
        return (int) Capsule::table('rec_applicants')->insertGetId(['uuid' => 'a-' . uniqid(), 'team_id' => self::TEAM, 'is_active' => 0, 'created_at' => self::T0, 'updated_at' => self::T0]);
    }
    private function employee(int $applicantId, ?string $company, array $extra = []): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId(array_merge([
            'uuid' => 'e-' . uniqid(), 'portal_token' => 't-' . uniqid(), 'team_id' => self::TEAM,
            'rec_applicant_id' => $applicantId, 'company' => $company, 'is_active' => 1,
            'zas_changed_at' => null, 'created_at' => self::T0, 'updated_at' => self::T0,
        ], $extra));
    }
    private function template(string $code, string $company = 'RG', ?string $taetigkeit = null): int
    {
        return (int) Capsule::table('rec_contract_templates')->insertGetId(['uuid' => 'v-' . uniqid(), 'team_id' => self::TEAM, 'name' => $code, 'code' => $code, 'company' => $company, 'taetigkeit' => $taetigkeit, 'is_active' => 1, 'created_at' => self::T0, 'updated_at' => self::T0]);
    }
    private function contract(int $applicantId, int $templateId, array $extra = []): int
    {
        return (int) Capsule::table('rec_contracts')->insertGetId(array_merge([
            'uuid' => 'c-' . uniqid(), 'team_id' => self::TEAM, 'rec_applicant_id' => $applicantId,
            'rec_contract_template_id' => $templateId, 'status' => 'completed',
            'sent_at' => self::T0, 'signed_at' => '2026-09-02 08:00:00', 'completed_at' => '2026-09-02 08:00:00',
            'created_at' => self::T0, 'updated_at' => self::T0,
        ], $extra));
    }
    private function anker(int $contractId): ?int
    {
        $v = Capsule::table('rec_contracts')->where('id', $contractId)->value('rec_employee_id');
        return $v === null ? null : (int) $v;
    }
    /** @return array{vorlagen_typisiert:int, zugeordnet:int, mehrdeutig:int, firma_fehlt:int, ohne_anstellung:int, verwaist:int} */
    private function lauf(bool $dryRun = false): array
    {
        $this->meldungen = [];
        return (new VertraegeAnAnstellung())->backfill($dryRun, null, function (string $type, string $text): void { $this->meldungen[] = $text; });
    }
```

```php
    /**
     * §3.7 Test 5 — die Firma der Vorlage entscheidet. MA hat die kleinere
     * Kennung, damit ein first() ohne Firmenfilter strukturell rot wird.
     * Mutation A: Firmenfilter raus (first() ueber alle Anstellungen) → rot.
     * Mutation B: 'RG' statt $template->company → Gegenprobe rot.
     */
    public function test_backfill_folgt_der_firma_der_vorlage(): void
    {
        $a  = $this->applicant();
        $ma = $this->employee($a, 'MA');
        $rg = $this->employee($a, 'RG');
        $rgVertrag = $this->contract($a, $this->template('AV-default', 'RG'));
        $maVertrag = $this->contract($a, $this->template('AV-MA-LOG', 'MA', 'logistiker'));

        $counts = $this->lauf();

        $this->assertSame($rg, $this->anker($rgVertrag));
        $this->assertSame($ma, $this->anker($maVertrag), 'Gegenprobe: MA-Vorlage → MA-Anstellung');
        $this->assertSame(2, $counts['zugeordnet']);
    }

    /** §3.7 Test 6 — Mutation: bei mehrdeutig trotzdem ersterKandidatId() schreiben → rot. */
    public function test_backfill_laesst_mehrdeutig_stehen_und_meldet(): void
    {
        $a = $this->applicant();
        $e1 = $this->employee($a, 'RG');
        $e2 = $this->employee($a, 'RG');
        $c = $this->contract($a, $this->template('AV-default'));

        $counts = $this->lauf();

        $this->assertNull($this->anker($c));
        $this->assertSame(1, $counts['mehrdeutig']);
        $this->assertSame(0, $counts['zugeordnet']);
        $this->assertStringContainsString("#{$c}", implode("\n", $this->meldungen));
        $this->assertStringContainsString("{$e1}", implode("\n", $this->meldungen));
        $this->assertStringContainsString("{$e2}", implode("\n", $this->meldungen));
    }

    public function test_backfill_firma_fehlt_bleibt_beim_bewerber(): void
    {
        $a = $this->applicant();
        $this->employee($a, 'MA');                        // einzige Anstellung, falsche Firma
        $c = $this->contract($a, $this->template('AV-default', 'RG'));

        $counts = $this->lauf();

        $this->assertNull($this->anker($c), 'RG-Vertrag an der MA-Akte waere der Fehler im Kleinen.');
        $this->assertSame(1, $counts['firma_fehlt']);
    }

    public function test_backfill_ohne_anstellung_und_idempotent_und_trockenlauf(): void
    {
        $a = $this->applicant();
        $c = $this->contract($a, $this->template('AV-default'));
        $this->assertSame(1, $this->lauf()['ohne_anstellung']);
        $this->assertNull($this->anker($c));

        $b = $this->applicant();
        $rg = $this->employee($b, 'RG');
        $d = $this->contract($b, $this->template('IFSG'));
        $this->assertSame(1, $this->lauf(dryRun: true)['zugeordnet'], 'Trockenlauf zaehlt, was er taete');
        $this->assertNull($this->anker($d), 'Trockenlauf schreibt nichts');
        $this->lauf();
        $this->assertSame($rg, $this->anker($d));
        $this->assertSame(0, $this->lauf()['zugeordnet'], 'Zweiter Lauf findet nichts mehr');
    }

    /**
     * §3.7 Test 7 — observer-frei. VORFLUG im selben Test: der echte Observer
     * ist scharf — ein Eloquent-Save eines signierten Vertrags setzt den
     * ZAS-Marker ueber RecContract::saved → hrData.contract_signed_at →
     * RecEmployeeHrData::saved. Erst danach die eigentliche Messung.
     * Mutation: im Backfill `RecContract::find($id)->update(['rec_employee_id' => ...])`
     * statt DB::table → rot (Marker gesetzt).
     */
    public function test_backfill_setzt_keinen_zas_marker(): void
    {
        $a = $this->applicant();
        $rg = $this->employee($a, 'RG');
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => 'h-' . uniqid(), 'rec_employee_id' => $rg, 'team_id' => self::TEAM, 'created_at' => self::T0, 'updated_at' => self::T0]);
        $c = $this->contract($a, $this->template('AV-default'));

        // Vorflug: die Falle ist scharf.
        \Platform\Recruiting\Models\RecContract::find($c)->update(['notes' => 'vorflug']);
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $rg)->value('zas_changed_at'), 'Vorflug: Eloquent-Save am signierten Vertrag MUSS den Marker setzen, sonst prueft dieser Test nichts.');
        Capsule::table('rec_employees')->where('id', $rg)->update(['zas_changed_at' => null, 'updated_at' => self::T0]);
        Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $rg)->update(['contract_signed_at' => null, 'updated_at' => self::T0]);

        $this->lauf();

        $this->assertSame($rg, $this->anker($c));
        $row = Capsule::table('rec_employees')->where('id', $rg)->first();
        $this->assertNull($row->zas_changed_at, 'Kein ZAS-Marker durch das Backfill (Vorfall 02.09.2026).');
        $this->assertSame(self::T0, (string) $row->updated_at);
    }

    /** §3.7 Test 12 — Mutation: Praefix-Filter `code LIKE 'AV-%'` entfernen → rot (IFSG/AT-140 bekommen die Taetigkeit). */
    public function test_schritt_0_typisiert_nur_av_vorlagen(): void
    {
        $av   = $this->template('AV-default');
        $av2  = $this->template('AV-060');
        $at   = $this->template('AT-140');
        $ifsg = $this->template('IFSG');
        $fix  = $this->template('AV-MA-LOG', 'MA', 'logistiker');

        $counts = $this->lauf();

        $t = fn (int $id) => Capsule::table('rec_contract_templates')->where('id', $id)->value('taetigkeit');
        $this->assertSame('eventmitarbeiter', $t($av));
        $this->assertSame('eventmitarbeiter', $t($av2));
        $this->assertNull($t($at));
        $this->assertNull($t($ifsg));
        $this->assertSame('logistiker', $t($fix), 'Gesetzte Werte bleiben.');
        $this->assertSame(2, $counts['vorlagen_typisiert']);
        $this->assertSame(0, $this->lauf()['vorlagen_typisiert'], 'idempotent');
    }

    /** Review-Focus 4 — Mutation: verwaist-Zaehlung entfernen → rot. */
    public function test_bericht_nennt_verwaiste_anker(): void
    {
        $a = $this->applicant();
        $c = $this->contract($a, $this->template('AV-default'), ['rec_employee_id' => 999999]);

        $counts = $this->lauf();

        $this->assertSame(1, $counts['verwaist']);
        $this->assertSame(999999, $this->anker($c), 'Der Bericht aendert verwaiste Anker nicht — das ist Handarbeit.');
        $this->assertStringContainsString("#{$c}", implode("\n", $this->meldungen));
    }

    /** Review-Focus 5 — stornierte Vertraege gehoeren auch zur Anstellung (Archiv). Mutation: `whereNotIn('status', ['cancelled'])` einbauen → rot. */
    public function test_backfill_haengt_auch_stornierte_an(): void
    {
        $a = $this->applicant();
        $rg = $this->employee($a, 'RG');
        $c = $this->contract($a, $this->template('AV-default'), ['status' => 'cancelled']);
        $this->lauf();
        $this->assertSame($rg, $this->anker($c));
    }
```

  Hinweis zum Vorflug in Test 7: `RecContract::saved` liest `$applicant->employee` ueber Eloquent → die Tabelle `rec_applicants` muss die Zeile enthalten (sie tut es) und `RecEmployee::ensureHrData()` die HR-Zeile finden (eingefuegt). Schlaegt der Vorflug fehl, ist zuerst das Harness zu reparieren, nicht die Zusicherung zu lockern.

- [ ] **Step 2: Rot** (Klasse fehlt).

- [ ] **Step 3: Kommando**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Support\AnstellungsZuordnung;

/**
 * Haengt Bestandsvertraege an ihre Anstellung (Spec Vertrag an der Anstellung
 * §3.5). Schritt 0 typisiert die AV-Vorlagen (taetigkeit = eventmitarbeiter,
 * Annahme 1 der Spec), Schritt 1 setzt rec_contracts.rec_employee_id nach
 * DERSELBEN Regel wie der Live-Pfad (RecContractTemplate::anstellungFuer).
 *
 * Idempotent (nur rec_employee_id IS NULL), trockenlauf-faehig, OBSERVER-FREI:
 * Query Builder auf rec_contracts, nie RecContract::save() (der saved-Hook
 * schreibt auf die HR-Daten und stempelt den ZAS-Marker), nie eine
 * Eloquent-Schreibung auf rec_employees (Vorfall 02.09.2026).
 *
 * `mehrdeutig` (zwei Anstellungen derselben Firma) bleibt NULL und wird
 * gemeldet — Handarbeit mit Blick auf den Menschen; `firma_fehlt` ebenso:
 * ein RG-Vertrag an der MA-Akte waere der Fehler im Kleinen. Bericht nennt
 * Kennungen, nie Namen. FAILURE nur bei Datenbankfehlern.
 *
 * Deploy-Reihenfolge (§3.6): migrate → report-signed-without-employee
 * --backfill-links --skip-tests → dieses Kommando --dry-run → dieses Kommando.
 *
 * Aufruf:
 *   php artisan recruiting:vertraege-an-anstellung --dry-run
 *   php artisan recruiting:vertraege-an-anstellung
 *   php artisan recruiting:vertraege-an-anstellung --team=3
 */
class VertraegeAnAnstellung extends Command
{
    protected $signature = 'recruiting:vertraege-an-anstellung
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur Vertraege/Vorlagen dieses Teams (Default: alle)}';

    protected $description = 'Haengt Bestandsvertraege an ihre Anstellung und typisiert AV-Vorlagen (observer-frei)';

    public const TAETIGKEIT_AV = 'eventmitarbeiter';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $team   = $this->option('team') !== null ? (int) $this->option('team') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Es wird nichts geschrieben.');
        }

        try {
            $counts = $this->backfill($dryRun, $team, function (string $type, string $text): void {
                match ($type) {
                    'warn'  => $this->warn($text),
                    default => $this->line($text),
                };
            });
        } catch (\Throwable $e) {
            $this->error('Abgebrochen: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — Vorlagen typisiert: %d · zugeordnet: %d · mehrdeutig: %d · firma_fehlt: %d · ohne_anstellung: %d · verwaist: %d',
            $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT',
            $counts['vorlagen_typisiert'], $counts['zugeordnet'], $counts['mehrdeutig'],
            $counts['firma_fehlt'], $counts['ohne_anstellung'], $counts['verwaist'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(string,string):void $out
     * @return array{vorlagen_typisiert:int, zugeordnet:int, mehrdeutig:int, firma_fehlt:int, ohne_anstellung:int, verwaist:int}
     */
    public function backfill(bool $dryRun, ?int $teamId, callable $out): array
    {
        $counts = ['vorlagen_typisiert' => 0, 'zugeordnet' => 0, 'mehrdeutig' => 0, 'firma_fehlt' => 0, 'ohne_anstellung' => 0, 'verwaist' => 0];

        // Schritt 0 — AV-Vorlagen typisieren. Praefix-Filter ist die Regel:
        // Belehrung (IFSG) und Zusatzvereinbarung (AT-) sind keine Taetigkeit.
        $vorlagen = DB::table('rec_contract_templates')
            ->whereNull('deleted_at')
            ->where('code', 'like', 'AV-%')
            ->whereNull('taetigkeit')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'code', 'company']);
        foreach ($vorlagen as $v) {
            $counts['vorlagen_typisiert']++;
            $out('line', sprintf('Vorlage #%d %s: company=%s taetigkeit=%s', $v->id, $v->code, $v->company, self::TAETIGKEIT_AV));
            if (!$dryRun) {
                DB::table('rec_contract_templates')->where('id', $v->id)->update(['taetigkeit' => self::TAETIGKEIT_AV]);
            }
        }

        // Verwaiste Anker nur melden — kein FK, also sieht es sonst niemand.
        $verwaist = DB::table('rec_contracts as c')
            ->whereNotNull('c.rec_employee_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rec_employees as e')->whereColumn('e.id', 'c.rec_employee_id'))
            ->when($teamId !== null, fn ($q) => $q->where('c.team_id', $teamId))
            ->orderBy('c.id')
            ->get(['c.id', 'c.rec_employee_id']);
        foreach ($verwaist as $row) {
            $counts['verwaist']++;
            $out('warn', sprintf('Vertrag #%d: Anker rec_employee_id=%d zeigt auf keine Anstellung mehr (verwaist)', $row->id, $row->rec_employee_id));
        }

        // Schritt 1 — Vertraege ohne Anker, nach der einen Regel.
        $offene = DB::table('rec_contracts')
            ->whereNull('rec_employee_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'rec_applicant_id', 'rec_contract_template_id']);

        $vorlagenCache = [];
        $bewerberCache = [];
        foreach ($offene as $row) {
            $template = $vorlagenCache[$row->rec_contract_template_id] ??= RecContractTemplate::withTrashed()->find($row->rec_contract_template_id);
            $applicant = $bewerberCache[$row->rec_applicant_id] ??= RecApplicant::find($row->rec_applicant_id);
            if ($template === null || $applicant === null) {
                $counts['ohne_anstellung']++;
                $out('warn', sprintf('Vertrag #%d: Vorlage oder Bewerber fehlt — uebersprungen', $row->id));
                continue;
            }

            $z = $template->anstellungFuer($applicant);
            switch ($z->befund) {
                case AnstellungsZuordnung::ZUGEORDNET:
                    $counts['zugeordnet']++;
                    $out('line', sprintf('Vertrag #%d (%s, %s) → Anstellung #%d', $row->id, $template->code, $template->company, $z->anstellungId()));
                    if (!$dryRun) {
                        DB::table('rec_contracts')->where('id', $row->id)->whereNull('rec_employee_id')
                            ->update(['rec_employee_id' => $z->anstellungId(), 'updated_at' => now()]);
                    }
                    break;
                case AnstellungsZuordnung::MEHRDEUTIG:
                    $counts['mehrdeutig']++;
                    $out('warn', sprintf('Vertrag #%d (%s, %s): mehrere Anstellungen der Firma — Kandidaten %s — NULL gelassen', $row->id, $template->code, $template->company, implode(', ', $z->kandidatenIds)));
                    break;
                case AnstellungsZuordnung::FIRMA_FEHLT:
                    $counts['firma_fehlt']++;
                    $out('warn', sprintf('Vertrag #%d (%s, %s): Bewerber #%d hat nur Anstellungen anderer Firmen — NULL gelassen', $row->id, $template->code, $template->company, $applicant->id));
                    break;
                default:
                    $counts['ohne_anstellung']++;
                    break;
            }
        }

        return $counts;
    }
}
```

  Registrierung in `RecruitingServiceProvider`: `\Platform\Recruiting\Console\Commands\VertraegeAnAnstellung::class,` hinter `DispoUnfreezeDress::class`.

- [ ] **Step 4: Gruen** — `--filter VertraegeAnAnstellungCommandTest`. Dann ein Beleg, dass der Dienst in Task 3 denselben Praedikat-Code benutzt wie das Backfill: `grep -n "giltFuerAnstellung\|anstellungFuer" src -r` → genau drei Nutzer (ContractAnchorService, RecContractTemplate::anstellungFuer/ankerFuerNeuenVertrag, VertraegeAnAnstellung). Keine `'RG'`-Literale in den geaenderten Dateien: `grep -n "'RG'" src/Services/ContractAnchorService.php src/Console/Commands/VertraegeAnAnstellung.php src/Models/RecContractTemplate.php` → leer (die Migration darf den Default tragen).

- [ ] **Step 5: Mutationen** 5A/5B/6/7/12 + Review-Focus 4/5 wie in den Docblocks, jeweils rot/gruen protokollieren.

- [ ] **Step 6: Volle Suite, Commit**

```bash
git add src/Console/Commands/VertraegeAnAnstellung.php src/RecruitingServiceProvider.php tests/Integration/VertraegeAnAnstellungCommandTest.php
git commit -m "feat(recruiting): Backfill vertraege-an-anstellung (Vorlagen typisieren, Anker setzen, observer-frei)"
```

### Task 9: Abschluss — Nachweise sammeln

**Files:** keine Codeaenderung (nur, was Review-Funde verlangen).

- [ ] **Step 1: Volle Suite dreimal in Default-Reihenfolge** (`phpunit.xml`: kein `order-by=random`): `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK; Testzahl und Assertions notieren (Baseline 2427 / 11114).
- [ ] **Step 2: Zusicherungs-Zaehlung** — Tests, die `Employees/Show` oder `EmployeePortal` mit Vertraegen aufrufen: vorher 0 (Vorflug oben), nachher die Methoden in `VertragAnDerAnstellungTest` (Zahl der `assert*` dort per `grep -c "assert" tests/Integration/VertragAnDerAnstellungTest.php`).
- [ ] **Step 3: Mutations-Protokoll** — eine Tabelle (Test-Nr. aus §3.7 → Testmethode → Mutation → rot/gruen) aus den Commit-Messages zusammentragen; sie geht in den Abschlussbericht.
- [ ] **Step 4: Blade-Checks** — `php tools/blade-check.php` fuer `employees/show.blade.php` und `contract-templates/index.blade.php`.
- [ ] **Step 5: Grep-Belege** — keine Eloquent-Schreibung auf `rec_employees` in neuen Dateien (`grep -n "RecEmployee::\|->save()\|->update(" src/Services/ContractAnchorService.php src/Console/Commands/VertraegeAnAnstellung.php` → nur `DB::table('rec_contracts'/'rec_contract_templates')`), kein Edit ausserhalb `platforms-recruiting` (`git -C ../../.. status --short` in Core/CRM/HCM leer), `platforms-recruiting-portal` unberuehrt.
