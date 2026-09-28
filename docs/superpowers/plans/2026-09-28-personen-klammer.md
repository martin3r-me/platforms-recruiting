# Personen-Klammer (Konto Stufe 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Mensch bekommt eine eigene Zeile, auf die seine Anstellungen bei RHEINGEDECK und MA zeigen — statt eines Stempels, den nur Gepaarte tragen.

**Architecture:** Neue Tabelle `rec_persons`, neue Spalte `rec_employees.rec_person_id`, genau ein Schreiber (`PersonLinker`, observer-frei ueber den Query Builder). Die Gruppierungsregel liegt als reiner Planer daneben. `PersonScopeResolver` beantwortet die Frage „welche Anstellungen gehoeren zu diesem Menschen" kuenftig ueber die Spalte und faellt nur fuer noch nicht gefuellte Zeilen auf die alte Telefon-Paarung zurueck — die drei Lesestellen (PortalShell, ProofReader, ProofWriter) bleiben unveraendert.

**Tech Stack:** Laravel 11, Livewire 3, PHPUnit (Unit pur / Integration mit handgebautem Capsule + SQLite), Symfony UuidV7.

**Spec:** `docs/superpowers/specs/2026-09-28-mitarbeiterkonto-canvas68.md` — massgeblich ist **§4.3 (Gate C)**. Die Anmeldung selbst ist **nicht** Teil dieser Stufe.

**Branch:** `feat/ma-konto`, abgezweigt von `feat/ma-portal` @ `0776a80`.

## Global Constraints

Jede Aufgabe traegt diese Anforderungen implizit mit.

- **EIN SCHREIBER.** Nur `PersonLinker` darf `rec_person_id` setzen oder aendern. Kein zweiter Schreibweg, auch nicht „nur schnell im Kommando".
- **OBSERVER-FREI.** Jeder Schreibzugriff auf `rec_employees` in dieser Stufe laeuft ueber den **Query Builder** (`DB::table(...)`), nie ueber Eloquent. Grund: eine Personen-Zuordnung ist keine fachliche Aenderung am Mitarbeiter und darf `zas_changed_at` nicht setzen — sonst spuelt der Backfill den halben Bestand in Michels `updates.csv`. Muster: `PersonPairLinker::stamp`, `ProofWriter`, `SwitchPortalVersion`.
- **UMHAENGEN STATT UEBERSCHREIBEN.** Zusammenlegen heisst, `rec_person_id` umzuzeigen. Es wird nie ein Wert auf einer anderen Zeile ueberschrieben und nie etwas geloescht.
- **STILLLEGEN STATT LOESCHEN.** Verliert eine Personen-Zeile beim Zusammenlegen, bekommt sie `merged_into_person_id` und behaelt ihre Nummer gesperrt (Canvas 68, Eintrag 1793: eine neu vergebene Handynummer darf nie an alte Daten fuehren).
- **`rec_person_id` gehoert NICHT in `RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS`.** Das Feld geht ZAS nichts an.
- **Kommentare auf Deutsch, und sie erklaeren das WARUM**, nicht das WAS. Hausstil: siehe `src/Support/PersonProofScope.php`.
- **Keine typografischen Anfuehrungszeichen in PHP-Kommentaren** — einfache Zeichen.
- **Testlauf** immer vom Worktree-Verzeichnis aus: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`. Ausgangsstand: **2539 Tests, 11941 Assertions, gruen.**
- **Testgattungen:** `tests/Unit` ist **pur** (kein Framework, keine DB, keine Facades). `tests/Integration` baut Container + Capsule + SQLite von Hand auf und ruft `Facade::clearResolvedInstances()` — Vorbild `tests/Integration/ProofReaderTest.php`. Keine neue Gattung erfinden.
- **`php -l`** auf jede geaenderte PHP-Datei.
- **Der Login bleibt in dieser Stufe unveraendert.** Wer das Anmeldeverhalten anfasst, ist ausserhalb des Auftrags.

---

## Dateien

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_09_28_000001_create_rec_persons_table.php` | die Personen-Zeile |
| `database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php` | die Klammer-Spalte |
| `src/Models/RecPerson.php` | Modell + Beziehung zu den Anstellungen |
| `src/Support/PersonGroupPlanner.php` | **reine** Regel: welche Anstellungen teilen sich eine Person, welche Nummer gewinnt, was geht an HR |
| `src/Services/PersonLinker.php` | der **eine** Schreiber (observer-frei) |
| `src/Console/Commands/BackfillPersons.php` | `recruiting:personen-anlegen` |
| `src/Services/PersonScopeResolver.php` | **geaendert**: loest ueber die Spalte auf, faellt zurueck |
| `src/Services/Zas/PersonPairLinker.php` | **geaendert**: ein frisch gepaartes Paar bekommt auch die Personen-Zeile |
| `src/Console/Commands/SeedDemoEmployees.php` | **geaendert**: die beiden Gregors teilen sich eine Person |

---

### Task 1: Tabelle, Spalte und Modell

**Files:**
- Create: `database/migrations/2026_09_28_000001_create_rec_persons_table.php`
- Create: `database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php`
- Create: `src/Models/RecPerson.php`
- Modify: `src/Models/RecEmployee.php` (`$fillable`: `rec_person_id` neben `portal_v2_since`)
- Test: `tests/Integration/RecPersonTest.php`

**Interfaces:**
- Produces: Tabelle `rec_persons` mit Spalten `id, uuid, team_id, phone, password_hash, email, invited_at, registered_at, locked_at, merged_into_person_id, created_at, updated_at`; Spalte `rec_employees.rec_person_id` (nullable, indiziert); Modell `Platform\Recruiting\Models\RecPerson` mit `employees(): HasMany` und `istStillgelegt(): bool`.

**Warum die Anmeldespalten schon jetzt entstehen, obwohl Stufe 1 sie nicht benutzt:** Die Tabelle ist in §4.3 vollstaendig festgelegt. Sie jetzt zur Haelfte anzulegen hiesse, in Stufe 2 ein zweites Mal an einer dann bereits befuellten Tabelle zu wandern. Die Spalten sind nullable und werden von nichts gelesen.

- [ ] **Step 1: Migration fuer die Personen-Zeile schreiben**

`database/migrations/2026_09_28_000001_create_rec_persons_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Mensch als eigene Zeile — bisher gab es ihn nur als Stempel.
 *
 * `rec_employees.person_key` markiert seit dem 09.09.2026, welche Datensaetze
 * derselbe Mensch sind. Gesetzt wird er aber NUR beim Paaren: wer eine
 * Anstellung hat, traegt NULL — das ist die Mehrheit. Als Anker fuer ein
 * Konto taugt er deshalb nicht (Spec 2026-09-28, Paragraph 4.3).
 *
 * Diese Zeile bekommt JEDER, auch ohne Konto und auch mit nur einer
 * Anstellung. Die Klammer ist damit von Tag eins vollstaendig.
 *
 * Die Anmeldespalten (phone, password_hash, ...) entstehen hier mit, werden
 * in Stufe 1 aber von nichts gelesen. Sie jetzt wegzulassen hiesse, spaeter
 * ein zweites Mal an einer dann befuellten Tabelle zu wandern.
 *
 * phone ist der spaetere Benutzername und deshalb je Team eindeutig — eine
 * Nummer darf nur an EINEM Konto haengen (Canvas 68, Eintrag 1740). NULL ist
 * erlaubt und mehrfach moeglich: wer keine Nummer hat, bekommt trotzdem eine
 * Personen-Zeile, er bekommt nur kein Konto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_persons')) {
            return;
        }

        Schema::create('rec_persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('phone', 32)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('email')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Gesetzt, wenn diese Zeile beim Zusammenlegen verloren hat. Sie
            // bleibt stehen und ihre Nummer bleibt gesperrt, damit eine neu
            // vergebene Handynummer nicht an alte Daten fuehrt (Canvas 1793).
            $table->unsignedBigInteger('merged_into_person_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_persons');
    }
};
```

- [ ] **Step 2: Migration fuer die Klammer-Spalte schreiben**

`database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Klammer: welche Person steckt hinter dieser Anstellung?
 *
 * Bewusst KEIN Fremdschluessel-Constraint auf Datenbankebene — im Modul ist
 * das durchgaengig so (rec_employee_id in rec_employee_proofs ebenso). Die
 * Zuordnung haelt PersonLinker zusammen, und der ist der einzige Schreiber.
 *
 * NICHT in RELEVANT_EMPLOYEE_FIELDS: Die Spalte geht ZAS nichts an und
 * duerfte keinen Update-Marker setzen. Geschrieben wird ohnehin nur ueber
 * den Query Builder, damit der Backfill nicht den halben Bestand in die
 * updates.csv spuelt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'rec_person_id')) {
                $table->unsignedBigInteger('rec_person_id')->nullable()->after('person_key')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'rec_person_id')) {
                $table->dropColumn('rec_person_id');
            }
        });
    }
};
```

- [ ] **Step 3: Den Integrationstest schreiben (er muss zuerst rot sein)**

`tests/Integration/RecPersonTest.php`. Bau Container, Capsule und SQLite genau nach dem Vorbild `tests/Integration/ProofReaderTest.php` auf (inklusive `Facade::clearResolvedInstances()`), lege die beiden Tabellen von Hand an und pruefe:

```php
public function test_eine_person_traegt_beide_anstellungen(): void
{
    $personId = DB::table('rec_persons')->insertGetId([
        'uuid' => 'p-1', 'team_id' => self::TEAM, 'phone' => '+4915112345678',
        'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
    ]);

    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Doppelt',
         'company' => 'RG', 'rec_person_id' => $personId, 'is_active' => 1],
        ['id' => 2, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Doppelt',
         'company' => 'MA', 'rec_person_id' => $personId, 'is_active' => 1],
    ]);

    $person = RecPerson::query()->find($personId);

    $this->assertSame(
        ['MA', 'RG'],
        $person->employees->pluck('company')->sort()->values()->all(),
        'die Person kennt ihre beiden Anstellungen nicht',
    );
}

public function test_stillgelegte_person_sagt_es_selbst(): void
{
    $sieger = DB::table('rec_persons')->insertGetId([
        'uuid' => 'p-sieger', 'team_id' => self::TEAM,
        'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
    ]);
    $verlierer = DB::table('rec_persons')->insertGetId([
        'uuid' => 'p-verlierer', 'team_id' => self::TEAM, 'merged_into_person_id' => $sieger,
        'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
    ]);

    $this->assertFalse(RecPerson::query()->find($sieger)->istStillgelegt());
    $this->assertTrue(RecPerson::query()->find($verlierer)->istStillgelegt());
}
```

- [ ] **Step 4: Test laufen lassen, Rot bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter RecPersonTest`
Erwartet: FAIL — `Class "Platform\Recruiting\Models\RecPerson" not found`.

- [ ] **Step 5: Das Modell schreiben**

`src/Models/RecPerson.php`:

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein Mensch. Seine Anstellungen zeigen auf ihn, nicht umgekehrt.
 *
 * Was zur PERSON gehoert (Nummer, spaeter Stammdaten und Nachweise), steht
 * hier einmal. Was zur ANSTELLUNG gehoert (Personalnummer, Vertrag,
 * Einsaetze, Lohn), bleibt an rec_employees — es sind arbeitsrechtlich zwei
 * Arbeitgeber (Spec 2026-09-28, Paragraph 4).
 *
 * In Stufe 1 traegt diese Zeile nur die Klammer. Die Anmeldespalten sind
 * angelegt, aber leer; das Konto kommt in Stufe 2.
 *
 * Geschrieben wird sie ausschliesslich ueber PersonLinker.
 */
class RecPerson extends Model
{
    protected $table = 'rec_persons';

    protected $fillable = [
        'uuid', 'team_id', 'phone', 'password_hash', 'email',
        'invited_at', 'registered_at', 'locked_at', 'merged_into_person_id',
    ];

    protected $casts = [
        'invited_at'    => 'datetime',
        'registered_at' => 'datetime',
        'locked_at'     => 'datetime',
    ];

    protected $hidden = ['password_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $person) {
            if (empty($person->uuid)) {
                $person->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function employees(): HasMany
    {
        return $this->hasMany(RecEmployee::class, 'rec_person_id');
    }

    /**
     * Beim Zusammenlegen verloren — die Zeile bleibt stehen, damit ihre
     * Nummer gesperrt bleibt und nichts verloren geht.
     */
    public function istStillgelegt(): bool
    {
        return $this->merged_into_person_id !== null;
    }
}
```

- [ ] **Step 6: `rec_person_id` in `$fillable` von `RecEmployee` ergaenzen**

In `src/Models/RecEmployee.php` die Zeile `'portal_v2_since',` um eine weitere ergaenzen:

```php
        'portal_v2_since',
        'rec_person_id',
```

- [ ] **Step 7: Tests laufen lassen, Gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Erwartet: PASS, Gesamtzahl 2539 + die neuen Tests.

- [ ] **Step 8: `php -l` auf alle neuen und geaenderten PHP-Dateien**

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_28_000001_create_rec_persons_table.php \
        database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php \
        src/Models/RecPerson.php src/Models/RecEmployee.php \
        tests/Integration/RecPersonTest.php
git commit -m "feat(recruiting): der Mensch bekommt eine eigene Zeile, auf die seine Anstellungen zeigen"
```

---

### Task 2: Die Gruppierungsregel als reiner Planer

**Files:**
- Create: `src/Support/PersonGroupPlanner.php`
- Test: `tests/Unit/PersonGroupPlannerTest.php`

**Interfaces:**
- Produces:
  ```php
  /**
   * @param  list<array{id:int, person_key:?string, phone:?string, updated_at:?string}>  $employees
   * @return array{gruppen: list<array{ids: list<int>, phone: ?string, phone_uneinig: bool}>}
   */
  public static function plan(array $employees): array
  ```
- Jede Gruppe wird **genau eine** Personen-Zeile. `ids` ist aufsteigend sortiert, die Gruppen sind nach ihrer kleinsten Kennung sortiert (damit zwei Laeufe dieselbe Reihenfolge ergeben).

**Regeln (aus Spec §4.3, Abschnitt „Backfill"):**
1. Datensaetze mit **demselben nicht-leeren `person_key`** teilen sich eine Gruppe.
2. Ein leerer `person_key` gruppiert **nie** — jeder solche Datensatz ist seine eigene Gruppe. (Zwei Leute ohne Marker sind kein Beleg fuer irgendetwas — dasselbe Prinzip wie in `PersonProofScope`.)
3. Die Nummer der Gruppe ist die des Datensatzes mit dem **juengsten `updated_at`**, unter denen mit nicht-leerer Nummer (Canvas: „der zuletzt geaenderte Wert gewinnt"). Bei gleichem Zeitstempel gewinnt die kleinere `id` — damit der Lauf wiederholbar ist.
4. `phone_uneinig` ist `true`, wenn die Gruppe **mehr als eine verschiedene** nicht-leere Nummer traegt. Verglichen wird ueber `PhoneE164::suffix()`, damit `+49 152 …` und `0152 …` nicht faelschlich als Streit zaehlen.

- [ ] **Step 1: Den Test schreiben**

`tests/Unit/PersonGroupPlannerTest.php` — reiner Unit-Test, kein Framework:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonGroupPlanner;

final class PersonGroupPlannerTest extends TestCase
{
    /** @return array{id:int, person_key:?string, phone:?string, updated_at:?string} */
    private function ma(int $id, ?string $key, ?string $phone, ?string $updated = '2026-01-01 00:00:00'): array
    {
        return ['id' => $id, 'person_key' => $key, 'phone' => $phone, 'updated_at' => $updated];
    }

    public function test_gleicher_marker_wird_eine_gruppe(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915112345678'),
            $this->ma(2, 'p-1', '+4915112345678'),
        ]);

        $this->assertCount(1, $r['gruppen']);
        $this->assertSame([1, 2], $r['gruppen'][0]['ids']);
    }

    public function test_ohne_marker_ist_jeder_seine_eigene_gruppe(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, null, '+4915112345678'),
            $this->ma(2, '', '+4915112345678'),
        ]);

        $this->assertCount(2, $r['gruppen']);
        $this->assertSame([1], $r['gruppen'][0]['ids']);
        $this->assertSame([2], $r['gruppen'][1]['ids']);
    }

    public function test_juengste_nummer_gewinnt(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915111111111', '2026-01-01 00:00:00'),
            $this->ma(2, 'p-1', '+4915122222222', '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915122222222', $r['gruppen'][0]['phone']);
        $this->assertTrue($r['gruppen'][0]['phone_uneinig'], 'zwei verschiedene Nummern muessen an HR gemeldet werden');
    }

    public function test_leere_nummer_gewinnt_nie(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915111111111', '2026-01-01 00:00:00'),
            $this->ma(2, 'p-1', null, '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915111111111', $r['gruppen'][0]['phone']);
        $this->assertFalse($r['gruppen'][0]['phone_uneinig'], 'eine fehlende Nummer ist kein Streit');
    }

    public function test_dieselbe_nummer_anders_geschrieben_ist_kein_streit(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+49 152 12345678'),
            $this->ma(2, 'p-1', '015212345678'),
        ]);

        $this->assertFalse($r['gruppen'][0]['phone_uneinig']);
    }

    public function test_gleicher_zeitstempel_kleinere_kennung_gewinnt(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(2, 'p-1', '+4915122222222', '2026-06-01 00:00:00'),
            $this->ma(1, 'p-1', '+4915111111111', '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915111111111', $r['gruppen'][0]['phone'], 'der Lauf muss wiederholbar sein');
    }

    public function test_gruppen_sind_nach_kleinster_kennung_sortiert(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(9, 'p-b', null),
            $this->ma(3, 'p-a', null),
        ]);

        $this->assertSame([3], $r['gruppen'][0]['ids']);
        $this->assertSame([9], $r['gruppen'][1]['ids']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonGroupPlannerTest`
Erwartet: FAIL — `Class "Platform\Recruiting\Support\PersonGroupPlanner" not found`.

- [ ] **Step 3: Den Planer schreiben**

`src/Support/PersonGroupPlanner.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Anstellungen teilen sich eine Personen-Zeile?
 *
 * Der Backfill darf nicht raten. Zusammengezogen wird NUR bei hartem Beleg:
 * derselbe nicht-leere person_key. Ein leerer Marker gruppiert nie — zwei
 * Datensaetze ohne Marker sind kein Beleg fuer denselben Menschen (dasselbe
 * Prinzip wie in PersonProofScope: im Zweifel weniger zusammenfassen).
 *
 * Die Nummer der Gruppe entscheidet der juengste Datensatz mit Nummer
 * (Canvas 68: „der zuletzt geaenderte Wert gewinnt"). Bei gleichem
 * Zeitstempel gewinnt die kleinere Kennung, damit zwei Laeufe dasselbe
 * Ergebnis liefern — ein Backfill, der beim Wiederholen andere Nummern
 * setzt, waere nicht pruefbar.
 *
 * phone_uneinig meldet Gruppen, in denen zwei VERSCHIEDENE Nummern stehen.
 * Verglichen wird ueber PhoneE164::suffix(), sonst zaehlte „+49 152 …" gegen
 * „0152 …" als Streit, obwohl es dieselbe Nummer ist.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PersonGroupPlanner
{
    /**
     * @param  list<array{id:int, person_key:?string, phone:?string, updated_at:?string}>  $employees
     * @return array{gruppen: list<array{ids: list<int>, phone: ?string, phone_uneinig: bool}>}
     */
    public static function plan(array $employees): array
    {
        $roh = [];
        foreach ($employees as $mitarbeiter) {
            $key = trim((string) ($mitarbeiter['person_key'] ?? ''));
            // Leerer Marker: eigene Gruppe. Der Schluessel muss trotzdem
            // eindeutig sein, sonst faenden sich alle Markerlosen zusammen.
            $schluessel = $key === '' ? 'allein:' . (int) $mitarbeiter['id'] : 'marker:' . $key;
            $roh[$schluessel][] = $mitarbeiter;
        }

        $gruppen = [];
        foreach ($roh as $gruppe) {
            usort($gruppe, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

            $gruppen[] = [
                'ids' => array_map(fn ($m) => (int) $m['id'], $gruppe),
                'phone' => self::juengsteNummer($gruppe),
                'phone_uneinig' => self::uneinig($gruppe),
            ];
        }

        usort($gruppen, fn ($a, $b) => $a['ids'][0] <=> $b['ids'][0]);

        return ['gruppen' => $gruppen];
    }

    /** @param list<array{id:int, phone:?string, updated_at:?string}> $gruppe */
    private static function juengsteNummer(array $gruppe): ?string
    {
        $mitNummer = array_values(array_filter(
            $gruppe,
            fn ($m) => trim((string) ($m['phone'] ?? '')) !== '',
        ));
        if ($mitNummer === []) {
            return null;
        }

        usort($mitNummer, function ($a, $b) {
            $zeit = strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));

            return $zeit !== 0 ? $zeit : ((int) $a['id'] <=> (int) $b['id']);
        });

        return trim((string) $mitNummer[0]['phone']);
    }

    /** @param list<array{phone:?string}> $gruppe */
    private static function uneinig(array $gruppe): bool
    {
        $suffixe = [];
        foreach ($gruppe as $mitarbeiter) {
            $suffix = PhoneE164::suffix($mitarbeiter['phone'] ?? null);
            if ($suffix !== '') {
                $suffixe[$suffix] = true;
            }
        }

        return count($suffixe) > 1;
    }
}
```

- [ ] **Step 4: Tests laufen lassen, Gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`

- [ ] **Step 5: `php -l` und Commit**

```bash
git add src/Support/PersonGroupPlanner.php tests/Unit/PersonGroupPlannerTest.php
git commit -m "feat(recruiting): die Regel, welche Anstellungen sich eine Person teilen — harter Marker oder gar nicht"
```

---

### Task 3: `PersonLinker` — der eine Schreiber

**Files:**
- Create: `src/Services/PersonLinker.php`
- Test: `tests/Integration/PersonLinkerTest.php`

**Interfaces:**
- Consumes: `RecPerson` (Task 1).
- Produces:
  ```php
  /** Legt die Personen-Zeile an (falls noetig) und haengt die Anstellungen daran. Gibt rec_persons.id zurueck. */
  public static function verbinde(array $employeeIds, ?int $teamId, ?string $phone): int;

  /** Haengt eine Anstellung an eine ANDERE Person — der Weg zurueck fuer HR. Gibt die neue rec_persons.id zurueck. */
  public static function loese(int $employeeId): int;

  /** Legt die Verlierer-Zeile stumm und haengt ihre Anstellungen an den Sieger. */
  public static function fuehreZusammen(int $siegerId, int $verliererId): void;

  /** Schreibt die Nummer an die Person UND auf alle ihre Anstellungen (Spec 4.3, Regel 5). */
  public static function setzeNummer(int $personId, ?string $phone): void;
  ```

**Verhalten im Einzelnen:**

- `verbinde()`: Traegt eine der genannten Anstellungen bereits eine `rec_person_id`, wird **diese** Zeile benutzt — es entsteht keine zweite. Tragen zwei verschiedene Anstellungen **verschiedene** Personen, wirft die Methode eine `InvalidArgumentException`; das ist der Zusammenlege-Fall und gehoert zu `fuehreZusammen()`, nicht hierher. Sonst wird eine neue Zeile angelegt.
- `fuehreZusammen()`: setzt `merged_into_person_id` auf der Verlierer-Zeile, **leert deren Anmeldung** (`password_hash = null`, `registered_at = null`), **behaelt aber deren `phone`** — die Nummer bleibt damit durch den Eindeutigkeits-Index gesperrt. Anschliessend zeigen alle Anstellungen des Verlierers auf den Sieger.
- `setzeNummer()`: schreibt die Nummer an die Person und an **alle** ihre Anstellungen, in einer Transaktion. Ohne das entsteht genau die Drift, die die dritte Vorflug-Zahl zaehlt (Spec §9.2).
- **Alle Schreibzugriffe auf `rec_employees` ueber `DB::table(...)`**, nie ueber Eloquent.

- [ ] **Step 1: Den Test schreiben**

`tests/Integration/PersonLinkerTest.php`, Aufbau wie `ProofReaderTest`. Pflichtfaelle:

```php
public function test_verbinden_legt_eine_zeile_an_und_haengt_beide_an(): void
{
    $personId = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

    $this->assertSame(1, (int) DB::table('rec_persons')->count());
    $this->assertSame(
        [$personId, $personId],
        DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all(),
    );
}

public function test_verbinden_benutzt_eine_vorhandene_zeile_statt_einer_zweiten(): void
{
    $erst = PersonLinker::verbinde([1], self::TEAM, '+4915112345678');
    $zweit = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

    $this->assertSame($erst, $zweit);
    $this->assertSame(1, (int) DB::table('rec_persons')->count());
}

public function test_verbinden_weigert_sich_bei_zwei_verschiedenen_personen(): void
{
    $a = PersonLinker::verbinde([1], self::TEAM, null);
    $b = PersonLinker::verbinde([2], self::TEAM, null);
    $this->assertNotSame($a, $b);

    $this->expectException(\InvalidArgumentException::class);
    PersonLinker::verbinde([1, 2], self::TEAM, null);
}

public function test_verbinden_setzt_keinen_zas_marker(): void
{
    DB::table('rec_employees')->where('id', 1)->update(['zas_changed_at' => null]);

    PersonLinker::verbinde([1], self::TEAM, '+4915112345678');

    $this->assertNull(
        DB::table('rec_employees')->where('id', 1)->value('zas_changed_at'),
        'die Zuordnung darf niemanden in die updates.csv spuelen',
    );
}

public function test_zusammenfuehren_legt_still_statt_zu_loeschen(): void
{
    $sieger = PersonLinker::verbinde([1], self::TEAM, '+4915111111111');
    $verlierer = PersonLinker::verbinde([2], self::TEAM, '+4915122222222');
    DB::table('rec_persons')->where('id', $verlierer)
        ->update(['password_hash' => 'geheim', 'registered_at' => '2026-09-01 10:00:00']);

    PersonLinker::fuehreZusammen($sieger, $verlierer);

    $zeile = DB::table('rec_persons')->where('id', $verlierer)->first();
    $this->assertSame($sieger, (int) $zeile->merged_into_person_id);
    $this->assertNull($zeile->password_hash, 'die Anmeldung der Verlierer-Zeile muss weg sein');
    $this->assertNull($zeile->registered_at);
    $this->assertSame('+4915122222222', $zeile->phone, 'die Nummer bleibt gesperrt (Canvas 1793)');

    $this->assertSame(
        [$sieger, $sieger],
        DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all(),
    );
}

public function test_loesen_gibt_der_anstellung_eine_neue_person(): void
{
    $gemeinsam = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

    $neu = PersonLinker::loese(2);

    $this->assertNotSame($gemeinsam, $neu);
    $this->assertSame($gemeinsam, (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
    $this->assertSame($neu, (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'));
}

public function test_nummer_wandert_auf_alle_anstellungen(): void
{
    $personId = PersonLinker::verbinde([1, 2], self::TEAM, '+4915111111111');

    PersonLinker::setzeNummer($personId, '+4915199999999');

    $this->assertSame('+4915199999999', DB::table('rec_persons')->where('id', $personId)->value('phone'));
    $this->assertSame(
        ['+4915199999999', '+4915199999999'],
        DB::table('rec_employees')->orderBy('id')->pluck('phone')->all(),
        'sonst kommt der Einmalcode auf einer anderen Nummer an als die Anmeldung',
    );
}
```

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonLinkerTest`

- [ ] **Step 3: `PersonLinker` schreiben**

`src/Services/PersonLinker.php` — Kopf-Docblock muss die drei tragenden Gruende nennen (ein Schreiber, observer-frei, umhaengen statt ueberschreiben). Schreibe alle vier Methoden so, dass sie die Tests aus Step 1 erfuellen. Jeder Schreibzugriff auf `rec_employees` laeuft ueber `DB::table('rec_employees')`; `fuehreZusammen()` und `setzeNummer()` laufen in `DB::transaction(...)`.

- [ ] **Step 4: Tests laufen lassen, Gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`

- [ ] **Step 5: `php -l` und Commit**

```bash
git add src/Services/PersonLinker.php tests/Integration/PersonLinkerTest.php
git commit -m "feat(recruiting): PersonLinker ist der einzige Schreiber der Personen-Zuordnung — umhaengen statt ueberschreiben, observer-frei"
```

---

### Task 4: Backfill-Kommando

**Files:**
- Create: `src/Console/Commands/BackfillPersons.php`
- Modify: `src/RecruitingServiceProvider.php` (Registrierung hinter `EmployeeEdgeCases::class`)
- Test: `tests/Integration/BackfillPersonsTest.php`

**Interfaces:**
- Consumes: `PersonGroupPlanner::plan()` (Task 2), `PersonLinker::verbinde()` (Task 3).

```
recruiting:personen-anlegen
    {--team= : Nur Mitarbeiter dieses Teams}
    {--dry-run : Nur zaehlen, nichts schreiben}
```

**Verhalten:**
- Betrachtet **alle** Mitarbeiter, auch inaktive — sonst bekommt ein Rueckkehrer eine zweite Person (Spec §4.3, „Backfill").
- Datensaetze, die schon eine `rec_person_id` tragen, werden uebersprungen. Das Kommando ist damit **wiederholbar**.
- Gibt am Ende aus: wie viele Personen angelegt, wie viele Anstellungen verbunden, und **wie viele Gruppen `phone_uneinig` sind** — das ist die HR-Liste.
- Mit `--dry-run` wird nichts geschrieben und dieselbe Uebersicht gezeigt.

- [ ] **Step 1: Den Test schreiben**

`tests/Integration/BackfillPersonsTest.php`. Rufe das Kommando nicht ueber die Artisan-Fassade auf (die gibt es in dieser Testgattung nicht), sondern instanziiere es und rufe `handle()` — Vorbild: die vorhandenen Kommando-Tests im Modul (`tests/Integration/SendProofRemindersTest.php`, `tests/Integration/SwitchPortalVersionTest.php`). Uebernimm von dort auch, wie die Option-Werte gesetzt und die Ausgabe abgegriffen werden.

```php
private function lauf(bool $dryRun = false): BackfillPersons
{
    // Aufbau wie im Vorbild: Kommando bauen, Optionen setzen, handle() rufen.
    // Die Ausgabe wird ueber einen BufferedOutput abgegriffen.
}

public function test_jeder_bekommt_eine_person_auch_inaktive(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915122222222', 'is_active' => 0, 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    $this->lauf();

    $this->assertSame(2, (int) DB::table('rec_persons')->count(), 'ein Rueckkehrer braucht seine alte Person, nicht eine zweite');
    $this->assertSame(0, (int) DB::table('rec_employees')->whereNull('rec_person_id')->count());
}

public function test_gepaarte_teilen_sich_eine(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    $this->lauf();

    $this->assertSame(1, (int) DB::table('rec_persons')->count());
    $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
    $this->assertSame($ids[0], $ids[1]);
}

public function test_zweiter_lauf_legt_nichts_neu_an(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    $this->lauf();
    $ersteId = (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id');
    $this->lauf();

    $this->assertSame(1, (int) DB::table('rec_persons')->count(), 'das Kommando muss wiederholbar sein');
    $this->assertSame($ersteId, (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
}

public function test_dry_run_schreibt_nichts(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    $this->lauf(dryRun: true);

    $this->assertSame(0, (int) DB::table('rec_persons')->count());
    $this->assertNull(DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
}

public function test_uneinige_nummern_werden_gemeldet(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
    ]);

    $ausgabe = $this->laufMitAusgabe();

    $this->assertStringContainsString('1', $ausgabe, 'die HR-Liste muss die uneinige Gruppe nennen');
    $this->assertSame(
        '+4915122222222',
        DB::table('rec_persons')->value('phone'),
        'der zuletzt geaenderte Wert gewinnt (Canvas 68)',
    );
}

public function test_backfill_setzt_keinen_zas_marker(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00', 'zas_changed_at' => null],
    ]);

    $this->lauf();

    $this->assertSame(
        0,
        (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(),
        'ein Backfill ueber den ganzen Bestand darf niemanden in die updates.csv spuelen',
    );
}
```

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

- [ ] **Step 3: Das Kommando schreiben**

- [ ] **Step 4: Im ServiceProvider registrieren**

In `src/RecruitingServiceProvider.php` hinter `\Platform\Recruiting\Console\Commands\EmployeeEdgeCases::class,` ergaenzen:

```php
                \Platform\Recruiting\Console\Commands\BackfillPersons::class,
```

- [ ] **Step 5: Tests laufen lassen, Gruen bestaetigen**

- [ ] **Step 6: `php -l` und Commit**

```bash
git add src/Console/Commands/BackfillPersons.php src/RecruitingServiceProvider.php \
        tests/Integration/BackfillPersonsTest.php
git commit -m "feat(recruiting): Backfill legt jedem Menschen seine Personen-Zeile an — wiederholbar, inaktive inklusive"
```

---

### Task 5: `PersonScopeResolver` loest ueber die Spalte auf

**Files:**
- Modify: `src/Services/PersonScopeResolver.php`
- Test: `tests/Integration/PersonScopeResolverTest.php` (neu)

**Interfaces:**
- Consumes: `rec_employees.rec_person_id` (Task 1).
- Produces: unveraenderte Signatur `forEmployee(RecEmployee $employee): array{ids: list<int>, abweichend: list<int>}`.

**Das ist der Kern der Stufe.** Die drei Lesestellen (`PortalShell:1111`, `ProofReader:27`, `ProofWriter:98`) rufen alle diese eine Methode und bekommen eine Liste von Anstellungs-Kennungen. Nur die **Herkunft** der Liste aendert sich:

1. Traegt der Mitarbeiter eine `rec_person_id`: Es zaehlen **alle** Anstellungen mit derselben `rec_person_id`. `abweichend` ist dann **leer** — die Zuordnung ist entschieden, es gibt keinen Zweifelsfall mehr.
2. Traegt er keine (noch nicht gebackfillt): unveraendert der alte Weg ueber `person_key` + `PersonProofScope`.

**Nach dem Backfill ist Zweig 2 tot und wird entfernt — aber nicht in dieser Aufgabe.** Solange er lebt, muss ein Test beide Zweige abdecken.

- [ ] **Step 1: Den Test schreiben**

```php
public function test_mit_personen_zeile_zaehlen_alle_anstellungen_dieser_person(): void
{
    $personId = DB::table('rec_persons')->insertGetId([
        'uuid' => 'p-1', 'team_id' => self::TEAM,
        'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
    ]);
    DB::table('rec_employees')->insert([
        // Bewusst OHNE Telefonnummern: genau der Demo-Fall, an dem der alte Weg scheitert.
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => $personId, 'phone' => null, 'is_active' => 1],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => $personId, 'phone' => null, 'is_active' => 1],
    ]);

    $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

    $this->assertSame([1, 2], $r['ids'], 'die Spalte entscheidet, nicht die Telefonnummer');
    $this->assertSame([], $r['abweichend'], 'eine entschiedene Zuordnung kennt keinen Zweifelsfall mehr');
}

public function test_ohne_personen_zeile_gilt_weiter_der_alte_weg(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => '+4915112345678', 'is_active' => 1],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => '+4915112345678', 'is_active' => 1],
    ]);

    $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

    $this->assertSame([1, 2], $r['ids'], 'noch nicht gebackfillte Zeilen muessen weiter funktionieren');
}

public function test_ohne_personen_zeile_und_ohne_nummer_bleibt_der_zweifelsfall(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => null, 'is_active' => 1],
        ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => null, 'is_active' => 1],
    ]);

    $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

    $this->assertSame([1], $r['ids'], 'zwei leere Nummern bestaetigen sich nicht (PersonProofScope)');
    $this->assertSame([2], $r['abweichend']);
}
```

Der letzte Fall ist der Demo-Gregor von heute: gleicher `person_key`, keine Telefonnummern, deshalb `abweichend` statt `ids`. Der erste Test ist **dasselbe Datenbild mit gesetzter Personen-Zeile** und muss `[1, 2]` liefern — das nebeneinander ist der Beleg, dass der Umbau genau die Luecke schliesst, die heute klafft.

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

- [ ] **Step 3: `forEmployee()` umbauen**

Der neue Zweig zuerst, der alte als `return` dahinter. Docblock ergaenzen: **warum** die Spalte gewinnt (Identitaet gehoert in eine Spalte, nicht in eine Suchabfrage) und **dass** der alte Zweig nach dem Backfill entfernt wird.

- [ ] **Step 4: Tests laufen lassen, Gruen bestaetigen**

Alle vorhandenen `ProofReaderTest`- und `ProofWriterTest`-Faelle muessen unveraendert gruen bleiben — sie setzen keine `rec_person_id` und laufen damit weiter durch Zweig 2.

- [ ] **Step 5: `php -l` und Commit**

```bash
git add src/Services/PersonScopeResolver.php tests/Integration/PersonScopeResolverTest.php
git commit -m "feat(recruiting): wer zu wem gehoert steht jetzt in einer Spalte statt in einer Suchabfrage"
```

---

### Task 6: Ein frisch gepaartes Paar bekommt auch die Personen-Zeile

**Files:**
- Modify: `src/Services/Zas/PersonPairLinker.php` (`stamp()`)
- Test: `tests/Integration/PersonPairLinkerPersonTest.php` (neu)

**Interfaces:**
- Consumes: `PersonLinker::verbinde()` (Task 3).

**Warum:** Ohne diese Aufgabe bekommt jedes nach dem Backfill neu gepaarte Paar zwar einen `person_key`, aber keine Personen-Zeile — und faellt damit in den alten Zweig zurueck, den wir gerade loswerden wollen. `stamp()` ist bereits der eine Schreiber des Markers; er wird jetzt zusaetzlich zum Ausloeser der Verbindung.

- [ ] **Step 1: Den Test schreiben**

```php
public function test_stempeln_verbindet_auch_die_person(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
    ]);

    PersonPairLinker::stamp([1, 2], null);

    $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
    $this->assertNotSame(0, $ids[0], 'ein frisch gepaartes Paar ohne Personen-Zeile fiele in den alten Zweig zurueck');
    $this->assertSame($ids[0], $ids[1]);
    $this->assertSame(1, (int) DB::table('rec_persons')->count());
}

public function test_stempeln_benutzt_eine_schon_vorhandene_personen_zeile(): void
{
    $personId = PersonLinker::verbinde([1], self::TEAM, '+4915111111111');
    DB::table('rec_employees')->insert([
        ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
    ]);

    PersonPairLinker::stamp([1, 2], null);

    $this->assertSame(1, (int) DB::table('rec_persons')->count(), 'es darf keine zweite Zeile entstehen');
    $this->assertSame($personId, (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'));
}

public function test_stempeln_setzt_weiterhin_keinen_zas_marker(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00', 'zas_changed_at' => null],
        ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00', 'zas_changed_at' => null],
    ]);

    PersonPairLinker::stamp([1, 2], null);

    $this->assertSame(0, (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count());
}

public function test_die_juengste_nummer_landet_an_der_person(): void
{
    DB::table('rec_employees')->insert([
        ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
    ]);

    PersonPairLinker::stamp([1, 2], null);

    $this->assertSame(
        '+4915122222222',
        DB::table('rec_persons')->value('phone'),
        'dieselbe Regel wie im Backfill — sie darf nicht zweimal verschieden existieren',
    );
}
```

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

- [ ] **Step 3: `stamp()` ergaenzen**

Nach dem Setzen des `person_key` zusaetzlich `PersonLinker::verbinde($employeeIds, $teamId, $phone)` aufrufen.

`teamId` und `phone` kommen aus den betroffenen Datensaetzen. Fuer die Nummer **nicht** selbst eine Regel schreiben, sondern `PersonGroupPlanner::plan()` mit genau diesen Zeilen aufrufen und `gruppen[0]['phone']` nehmen — die Regel „der zuletzt geaenderte Wert gewinnt, bei Gleichstand die kleinere Kennung" darf nicht an zwei Stellen verschieden existieren. Dafuer muss die Abfrage `id`, `person_key`, `phone` **und `updated_at`** lesen.

Traegt die Gruppe mehr als ein `team_id`, nimm das des kleinsten `id` und vermerke es im Docblock als bewusste Wahl — Anstellungen derselben Person liegen im selben Team, ein Auseinanderfallen waere ein Datenfehler und kein Fall fuer stille Heilung.

- [ ] **Step 4: Tests laufen lassen, Gruen bestaetigen**

- [ ] **Step 5: `php -l` und Commit**

```bash
git add src/Services/Zas/PersonPairLinker.php tests/Integration/PersonPairLinkerPersonTest.php
git commit -m "feat(recruiting): wer neu gepaart wird, bekommt sofort seine Personen-Zeile"
```

---

### Task 7: Die Demo zeigt endlich, was sie behauptet

**Files:**
- Modify: `src/Console/Commands/SeedDemoEmployees.php`
- Test: `tests/Unit/SeedDemoEmployeesGuardTest.php` (vorhanden — ergaenzen)

**Interfaces:**
- Consumes: `PersonLinker::verbinde()` (Task 3).

**Warum:** Der Demo-Fall `demo-zwei-firmen` / `demo-zwei-firmen-ma` behauptet in seiner eigenen Beschreibung *„Dieselbe Person bei RG UND MA — Profil zeigt beide, Nachweise gelten fuer beide"*. Das stimmt heute **nicht**: die beiden Gregors tragen denselben `person_key`, aber **keine Telefonnummern** (bewusste Vorgabe: eine erfundene Nummer koennte einem echten Menschen gehoeren). Die alte Laufzeit-Paarung verlangt uebereinstimmende Nummern, zwei leere bestaetigen sich nicht — also paart sie nicht, und der Fall fuehrt nichts vor.

Mit der Personen-Zeile braucht es dafuer **keine Nummer mehr**. Die Vorgabe „keine Telefonnummern im Seeder" bleibt unangetastet.

- [ ] **Step 1: Den Test ergaenzen**

Im vorhandenen `tests/Unit/SeedDemoEmployeesGuardTest.php` ergaenzen. Der zweite Test ist ein **Waechter**: er muss rot werden, wenn jemand spaeter eine Nummer einbaut, um die Paarung „zu reparieren".

```php
public function test_die_beiden_gregors_tragen_denselben_marker(): void
{
    $faelle = $this->faelle();   // vorhandener Zugriff auf die Fall-Liste des Seeders

    $gregors = array_values(array_filter(
        $faelle,
        fn ($f) => str_starts_with($f['token'], 'demo-zwei-firmen'),
    ));

    $this->assertCount(2, $gregors);
    $this->assertSame(
        $gregors[0]['spalten']['person_key'],
        $gregors[1]['spalten']['person_key'],
        'ohne gemeinsamen Marker fuehrt der Fall nichts vor',
    );
    $this->assertNotSame(
        $gregors[0]['spalten']['company'],
        $gregors[1]['spalten']['company'],
        'zwei Datensaetze derselben Firma waeren eine Dublette, keine zweite Anstellung',
    );
}

public function test_kein_demo_fall_traegt_eine_telefonnummer(): void
{
    foreach ($this->faelle() as $fall) {
        $this->assertArrayNotHasKey(
            'phone',
            $fall['spalten'],
            "Fall {$fall['token']} setzt eine Telefonnummer — eine erfundene Nummer koennte einem echten Menschen gehoeren",
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot bestaetigen**

- [ ] **Step 3: Den Seeder ergaenzen**

Nach dem Anlegen aller Faelle die Gregor-Datensaetze ueber `PersonLinker::verbinde()` an eine gemeinsame Personen-Zeile haengen, `phone` dabei `null`. Alle uebrigen Demo-Leute bekommen je eine eigene Zeile. Beim `--loeschen` die angelegten Personen-Zeilen mit entfernen.

- [ ] **Step 4: Tests laufen lassen, Gruen bestaetigen**

- [ ] **Step 5: `php -l` und Commit**

```bash
git add src/Console/Commands/SeedDemoEmployees.php tests/Unit/SeedDemoEmployeesGuardTest.php
git commit -m "feat(recruiting): der Demo-Fall mit zwei Firmen fuehrt endlich vor, was er behauptet — ohne erfundene Telefonnummern"
```

---

## Abnahme dieser Stufe

Nach Task 7 muss gelten:

1. `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` ist gruen, Gesamtzahl >= 2539 plus die neuen Tests.
2. `php artisan migrate` legt beide Strukturen an; `php artisan recruiting:personen-anlegen --dry-run` laeuft ohne Schreibzugriff durch und nennt drei Zahlen.
3. Ein zweiter Lauf von `recruiting:personen-anlegen` legt **nichts** neu an.
4. Auf der Demo: `demo-zwei-firmen` und `demo-zwei-firmen-ma` zeigen **beide Anstellungen**, und der Ausweis, der nur an der RG-Anstellung haengt, erscheint auch auf der MA-Seite.
5. Kein Mitarbeiter hat durch den Backfill ein `zas_changed_at` bekommen. **Vor und nach dem Lauf zaehlen** (`SELECT COUNT(*) FROM rec_employees WHERE zas_changed_at IS NOT NULL`) — die Zahl muss gleich sein.

## Was diese Stufe NICHT tut

- Keine Anmeldung ueber Handynummer, kein Passwort, keine Registrierung — das ist Stufe 2.
- Kein Umzug von Stammdaten oder Nachweisen an die Person — das ist eine spaetere Migration.
- Kein Entfernen des alten Zweigs in `PersonScopeResolver` — erst wenn der Backfill auf prod gelaufen ist.
- Keine Einladungen, keine Meta-Vorlagen — das ist Stufe 3.
