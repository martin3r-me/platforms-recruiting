# Manuelles Ein-/Umbuchen ab Phase 2 — Umsetzungsplan (Paket 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR kann Bewerber ab der Buchungs-Phase manuell in Schulungstermine ein- und umbuchen, statt wie bisher nur vollständig abgeschlossene Bewerber; eine Buchung nimmt den Bewerber automatisch von Warteliste und Termin-Abos.

**Architecture:** Ein Schalter pro Phase (`rec_phases.allow_manual_booking`) ersetzt den bisherigen Filter `auto_pilot_completed_at IS NOT NULL` im Buchungs-Dialog. Die Kandidaten-Regel zieht in eine eigene Support-Klasse (`ManualBookingCandidates`), damit sie ohne Livewire-Runtime gegen eine echte DB testbar ist. Das Schließen der Warteliste hängt an einem Observer auf `RecInterviewBooking`, damit alle Buchungspfade (HR-Dialog, MCP-Tool, CSV-Sammelbuchung, öffentliches Formular) gleich behandelt werden.

**Tech Stack:** PHP 8.2, Laravel/Eloquent (als Composer-Package ohne eigenes `vendor/`), Livewire 3, Blade, PHPUnit (Suites `Unit` rein + `Integration` mit handgebauter Capsule).

## Global Constraints

- **Keine Änderungen außerhalb von `platforms-recruiting`.** Kein Edit in platforms-core, platform-crm, platforms-hcm ohne ausdrückliche Freigabe.
- **Testlauf:** `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` (beide Suites). Einzelne Klasse: `--filter <TestKlasse>`. **Kein `--order-by=random`** — siehe Kommentar in `phpunit.xml` (statischer `$booted`-Cache von Eloquent).
- **Integrationstests:** Container + Capsule von Hand aufsetzen, **Event-Dispatcher immer setzen**, Bindings am Testende abräumen. Muster: `tests/Integration/SettingsModalToggleWriteTest.php:52-61`.
- **Baseline vor Beginn:** 795 Tests, 2400 Assertions, 0 Fehler (Stand `8eedc5d`).
- **Migrations-Datum:** `2026_08_13_*`. `2026_08_12_000001` und `_000002` sind in main **je doppelt** belegt.
- **Idempotenz in Migrationen:** ein eigener `Schema::hasColumn`-Guard pro DDL-Operation (Muster `2026_08_12_000001_add_type_to_rec_contract_templates.php`).
- **Blade prüfen mit `php tools/blade-check.php <datei>`** — `php -l` prüft `.blade.php` **nicht**. Blade-Kommentare können Markup aus dem Kompilat löschen (`8eedc5d`), deshalb ist der Check Pflicht bei jeder Blade-Änderung.
- **Keine Blade-Fallen:** kein inline `@if` in `x-ui-*`-Attributen, kein inline `@php(...)` ohne `@endphp`, Werte vorher berechnen.
- **Kommentare und UI-Texte auf Deutsch**, Ton wie im Bestand (erklärt das *Warum*, nicht das *Was*).
- **Tabellennamen** (kein `$table` außer wo genannt): `rec_applicants`, `rec_phases`, `rec_contracts`, `rec_postings`, `rec_positions`, Pivot `rec_applicant_posting`, `rec_interview_bookings` (SoftDeletes), `rec_interview_waitlist` (**Singular**, SoftDeletes), `rec_auto_pilot_logs` (`$timestamps = false`).
- **Nicht in diesem Paket:** Verschieben-Button, Stellen-Wechsel beim manuellen Buchen, Ausschreibungs-Auswahl, `applied_at`-Erhalt, öffentlicher Buchungspfad.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_08_13_000001_add_allow_manual_booking_to_rec_phases.php` (neu) | Spalte `allow_manual_booking` auf `rec_phases` |
| `src/Models/RecPhase.php` (ändern) | Spalte in `$fillable` + `$casts` |
| `src/Support/ManualBookingCandidates.php` (neu) | **Die** Kandidaten-Regel als Query-Builder, ohne Livewire |
| `src/Livewire/InterviewBookings/Index.php` (ändern, `availableApplicants()` Z. 236-265) | ruft nur noch durch |
| `resources/views/livewire/interview-bookings/index.blade.php` (ändern, Z. 534-538) | Hinweistext im Buchungs-Modal |
| `src/Observers/RecInterviewBookingWaitlistObserver.php` (neu) | schließt Warteliste + Termin-Abos bei jeder neuen/reaktivierten Buchung, schreibt Log |
| `src/RecruitingServiceProvider.php` (ändern) | Observer registrieren |
| `src/Livewire/Position/Show.php` (ändern) | Checkbox-Zustand laden + speichern |
| `resources/views/livewire/position/show.blade.php` (ändern, Phasen-Block ab Z. 282) | Checkbox + Badge |
| `src/Console/Commands/EnableManualBookingForPhases.php` (neu) | Backfill der fünf Live-Stellen, Dry-Run als Default |
| `tests/Unit/PhaseManualBookingFlagTest.php` (neu) | Flag ist fillable + boolean |
| `tests/Integration/ManualBookingCandidatesTest.php` (neu) | Kandidaten-Regel gegen SQLite |
| `tests/Integration/BookingClosesWaitlistTest.php` (neu) | Observer-Verhalten gegen SQLite |
| `tests/Unit/EnableManualBookingPlannerTest.php` (neu) | Auswahl-Logik des Backfills, rein |

---

### Task 1: Spalte + Model-Anbindung

**Files:**
- Create: `database/migrations/2026_08_13_000001_add_allow_manual_booking_to_rec_phases.php`
- Modify: `src/Models/RecPhase.php:16-27` (`$fillable`, `$casts`)
- Test: `tests/Unit/PhaseManualBookingFlagTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: Spalte `rec_phases.allow_manual_booking` (boolean, NOT NULL, Default `false`); `RecPhase->allow_manual_booking` als `bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPhase;

/**
 * Der Buchungs-Dialog filtert per DB-Query auf allow_manual_booking. Fehlt das
 * Feld in $fillable, speichert die Checkbox auf der Stellen-Seite stillschweigend
 * nichts; fehlt der boolean-Cast, kommt aus MySQL 0/1 und aus SQLite true/false
 * zurueck — beides Fallen, die erst live auffallen.
 */
final class PhaseManualBookingFlagTest extends TestCase
{
    public function test_flag_ist_fillable(): void
    {
        $this->assertContains('allow_manual_booking', (new RecPhase())->getFillable());
    }

    public function test_flag_wird_boolean_gecastet(): void
    {
        $this->assertSame('boolean', (new RecPhase())->getCasts()['allow_manual_booking'] ?? null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseManualBookingFlagTest`
Expected: FAIL — 2 Fehler (`assertContains` findet den Schlüssel nicht, `assertSame` bekommt `null`).

- [ ] **Step 3: Write minimal implementation**

Migration `database/migrations/2026_08_13_000001_add_allow_manual_booking_to_rec_phases.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schalter "manuelles Einbuchen erlaubt" pro Phase. HR darf Bewerber aus Phasen
 * mit diesem Flag von Hand in Schulungstermine buchen und umbuchen. Vorher hing
 * der Buchungs-Dialog an auto_pilot_completed_at — das war die alte
 * 2-Phasen-Logik, in der Phase 2 die LETZTE Phase war und ihr Abschluss
 * "fertig fuer die Schulung" bedeutete. Bei den heutigen 4-Phasen-Stellen
 * bedeutet derselbe Wert "Vertraege sind raus", also genau die Bewerber, die
 * man NICHT mehr einbuchen will.
 *
 * Bewusst eine echte Spalte und kein Key in auto_pilot_settings/completion_config:
 * der Dialog filtert per DB-Query ueber rec_phases. Ein JSON-Pfad-Vergleich waere
 * dort nicht indexierbar und bei Checkbox-Werten (true vs. "1") fehleranfaellig.
 *
 * NOT NULL mit Default false: ein dritter Zustand "unbekannt" muesste in jeder
 * Query mitgedacht werden. Der Bestand wird durch den Default korrekt zu false;
 * die fuenf Live-Stellen schaltet recruiting:enable-manual-booking scharf.
 *
 * Idempotenz ueber Live-Guards: pro DDL-Operation ein eigener hasColumn-Check
 * (Muster 2026_08_12_000001_add_type_to_rec_contract_templates.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_phases', 'allow_manual_booking')) {
                $table->boolean('allow_manual_booking')->default(false)->after('auto_advance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            if (Schema::hasColumn('rec_phases', 'allow_manual_booking')) {
                $table->dropColumn('allow_manual_booking');
            }
        });
    }
};
```

In `src/Models/RecPhase.php` das Feld in beide Listen aufnehmen:

```php
    protected $fillable = [
        'uuid', 'team_id', 'rec_position_id', 'name', 'order',
        'auto_pilot_settings', 'auto_advance', 'is_active',
        'completion_type', 'completion_config', 'show_in_dashboard',
        'allow_manual_booking',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'auto_pilot_settings' => 'array',
        'auto_advance' => 'boolean',
        'completion_config' => 'array',
        'show_in_dashboard' => 'boolean',
        'allow_manual_booking' => 'boolean',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseManualBookingFlagTest`
Expected: PASS (2 Tests)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_13_000001_add_allow_manual_booking_to_rec_phases.php src/Models/RecPhase.php tests/Unit/PhaseManualBookingFlagTest.php
git commit -m "feat(recruiting): Phasen-Schalter allow_manual_booking als Spalte"
```

---

### Task 2: Kandidaten-Regel als eigene Klasse

**Files:**
- Create: `src/Support/ManualBookingCandidates.php`
- Test: `tests/Integration/ManualBookingCandidatesTest.php`

**Interfaces:**
- Consumes: `rec_phases.allow_manual_booking` aus Task 1
- Produces: `ManualBookingCandidates::query(int $teamId, ?int $positionId = null): \Illuminate\Database\Eloquent\Builder` — Builder auf `RecApplicant`, vom Aufrufer mit `->with(...)->get()` abzuschließen

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ManualBookingCandidates;

/**
 * Die Kandidaten-Regel des Buchungs-Dialogs gegen eine echte DB. Sie ist eine
 * Query — ein reiner Unit-Test koennte nur ihre Uebersetzung nachbauen, nicht
 * ihr Verhalten pruefen. Handgebaute Capsule mit Dispatcher, Muster
 * SettingsModalToggleWriteTest.
 */
final class ManualBookingCandidatesTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance() ?: new Container();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher fallen die creating-Hooks (uuid) der Models aus.
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->integer('rec_phase_id')->nullable();
            $t->string('import_source')->nullable();
            $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_phases', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->integer('rec_position_id');
            $t->string('name');
            $t->integer('order');
            $t->boolean('auto_advance')->default(true);
            $t->boolean('allow_manual_booking')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('completion_type')->default('fields');
            $t->timestamps();
        });

        $schema->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('status')->default('pending');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable();
            $t->string('status')->default('booked');
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        // Phase 1 ohne Schalter, Phase 2 mit.
        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'team_id' => 3, 'rec_position_id' => 8, 'name' => 'Bewerbung', 'order' => 1, 'allow_manual_booking' => false, 'is_active' => true, 'completion_type' => 'fields'],
            ['id' => 2, 'team_id' => 3, 'rec_position_id' => 8, 'name' => 'Schulung buchen', 'order' => 2, 'allow_manual_booking' => true, 'is_active' => true, 'completion_type' => 'booking'],
        ]);
    }

    protected function tearDown(): void
    {
        Capsule::schema()->drop('rec_applicants');
        Capsule::schema()->drop('rec_phases');
        Capsule::schema()->drop('rec_contracts');
        Capsule::schema()->drop('rec_interview_bookings');
        // Sonst zeigt 'db'/'db.schema' aus DIESER Capsule in spaetere Testklassen.
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        parent::tearDown();
    }

    private function applicant(array $overrides = []): int
    {
        return Capsule::table('rec_applicants')->insertGetId(array_merge([
            'team_id' => 3,
            'is_active' => true,
            'rec_phase_id' => 2,
            'import_source' => null,
        ], $overrides));
    }

    private function ids(?int $positionId = null): array
    {
        return ManualBookingCandidates::query(3, $positionId)->pluck('id')->all();
    }

    public function test_bewerber_in_phase_mit_schalter_erscheint(): void
    {
        $id = $this->applicant();
        $this->assertSame([$id], $this->ids());
    }

    public function test_bewerber_in_phase_ohne_schalter_erscheint_nicht(): void
    {
        $this->applicant(['rec_phase_id' => 1]);
        $this->assertSame([], $this->ids());
    }

    public function test_csv_altbestand_ohne_phase_erscheint(): void
    {
        $id = $this->applicant(['rec_phase_id' => null, 'import_source' => 'csv_import']);
        $this->assertSame([$id], $this->ids());
    }

    public function test_bewerber_ohne_phase_und_ohne_import_erscheint_nicht(): void
    {
        $this->applicant(['rec_phase_id' => null]);
        $this->assertSame([], $this->ids());
    }

    public function test_versendeter_vertrag_schliesst_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'sent', 'sent_at' => '2026-08-01 10:00:00',
        ]);
        $this->assertSame([], $this->ids());
    }

    public function test_stornierter_vertrag_schliesst_nicht_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'cancelled', 'sent_at' => '2026-08-01 10:00:00',
        ]);
        $this->assertSame([$id], $this->ids());
    }

    public function test_unversendeter_vertrag_schliesst_nicht_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'pending', 'sent_at' => null,
        ]);
        $this->assertSame([$id], $this->ids());
    }

    public function test_aktive_buchung_schliesst_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'booked',
        ]);
        $this->assertSame([], $this->ids());
    }

    public function test_nicht_erschienen_sperrt_weiterhin(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'no_show',
        ]);
        $this->assertSame([], $this->ids());
    }

    public function test_stornierte_buchung_sperrt_nicht(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'cancelled',
        ]);
        $this->assertSame([$id], $this->ids());
    }

    public function test_inaktiver_bewerber_erscheint_nicht(): void
    {
        $this->applicant(['is_active' => false]);
        $this->assertSame([], $this->ids());
    }

    public function test_fremdes_team_erscheint_nicht(): void
    {
        $this->applicant(['team_id' => 4]);
        $this->assertSame([], $this->ids());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ManualBookingCandidatesTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\ManualBookingCandidates" not found`

- [ ] **Step 3: Write minimal implementation**

`src/Support/ManualBookingCandidates.php`:

```php
<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Wer erscheint im Buchungs-Dialog (InterviewBookings\Index) als Kandidat?
 *
 * Eigene Klasse, damit die Regel ohne Livewire-Runtime gegen eine echte DB
 * testbar ist (tests/Integration/ManualBookingCandidatesTest) — der Dialog
 * selbst ruft nur noch durch.
 *
 * Drei Bedingungen, mit UND verknuepft:
 *
 *  1. Die Phase des Bewerbers erlaubt manuelles Einbuchen
 *     (allow_manual_booking), ODER es ist ein CSV-Altbestands-Import:
 *     import_source gesetzt UND keine Phase — genau so legt
 *     ImportApplicantsCsvService sie an (auto_pilot=false, keine
 *     rec_phase_id). Solche Importe sollen wie bisher in jede Schulung
 *     buchbar bleiben.
 *
 *  2. Es sind noch keine Vertraege versendet. Vertragsversand, MA-Anlage und
 *     MA-Portal-Link passieren in einem Zug (ContractDispatchService:34-86) —
 *     ab da ist der Bewerber durch und darf nicht mehr umgebucht werden. Dies
 *     ist bewusst der einzige Punkt, an dem das Paket etwas WEGNIMMT: vorher
 *     waren genau diese Bewerber die einzigen buchbaren.
 *
 *  3. Keine nicht-stornierte Buchung — unveraendert zur bisherigen Logik.
 *     'teilgenommen' und 'nicht erschienen' sperren also weiter; Umbuchen
 *     heisst absagen und im Zieltermin neu buchen.
 */
final class ManualBookingCandidates
{
    public static function query(int $teamId, ?int $positionId = null): Builder
    {
        // Ueber ALLE Termine hinweg, nicht nur den aktuellen: ein Bewerber
        // darf nie in zwei Schulungen gleichzeitig stehen.
        $bookedIds = RecInterviewBooking::query()
            ->whereNotIn('status', ['cancelled'])
            ->pluck('rec_applicant_id');

        $query = RecApplicant::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotIn('id', $bookedIds)
            ->where(function (Builder $q) {
                $q->whereHas('phase', function ($p) {
                    $p->where('allow_manual_booking', true);
                })->orWhere(function (Builder $legacy) {
                    $legacy->whereNull('rec_phase_id')->whereNotNull('import_source');
                });
            })
            ->whereDoesntHave('contracts', function ($c) {
                $c->whereNotIn('status', ['cancelled'])->whereNotNull('sent_at');
            });

        if ($positionId !== null) {
            // Stellen-Filter mit Bypass fuer Importierte: Legacy-CSV-Importe
            // haben keine Postings/Positions — sie sollen aber in jede
            // Schulung buchbar sein, unabhaengig von der Termin-Stelle.
            $query->where(function (Builder $q) use ($positionId) {
                $q->whereHas('postings', function ($pq) use ($positionId) {
                    $pq->whereHas('position', function ($p) use ($positionId) {
                        $p->where('rec_positions.id', $positionId);
                    });
                })->orWhereNotNull('import_source');
            });
        }

        return $query;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ManualBookingCandidatesTest`
Expected: PASS (12 Tests)

- [ ] **Step 5: Gesamtsuite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, keine neuen Fehler gegenüber der Baseline (795 + 14).

- [ ] **Step 6: Commit**

```bash
git add src/Support/ManualBookingCandidates.php tests/Integration/ManualBookingCandidatesTest.php
git commit -m "feat(recruiting): Kandidaten-Regel fuer manuelles Buchen als testbare Query"
```

---

### Task 3: Dialog auf die neue Regel umstellen

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php:236-265` (`availableApplicants()`)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php:534-538` (Hinweistext)

**Interfaces:**
- Consumes: `ManualBookingCandidates::query()` aus Task 2
- Produces: unveränderte Signatur `availableApplicants()` → Collection von `RecApplicant` mit `crmContactLinks.contact`

- [ ] **Step 1: `availableApplicants()` ersetzen**

Der Rumpf von Zeile 238 bis 264 wird zu:

```php
        return ManualBookingCandidates::query(
            auth()->user()->currentTeam->id,
            $this->interview->rec_position_id,
        )
            ->with(['crmContactLinks.contact'])
            ->get();
```

Import oben ergänzen:

```php
use Platform\Recruiting\Support\ManualBookingCandidates;
```

**Wichtig:** `rec_position_id` kann `null` sein (Termin ohne Stelle) — genau das erwartet der zweite Parameter, der Stellen-Filter entfällt dann wie bisher.

- [ ] **Step 2: Hinweistext im Modal anpassen**

`resources/views/livewire/interview-bookings/index.blade.php`, Zeile 534-538. Alt:

```blade
                    Es werden abgeschlossene Bewerber für die Stelle <strong>{{ $this->interview->position?->title }}</strong> angezeigt — sowie alle importierten Bewerber (Altbestand, ohne Stellen-Bindung).
```

Neu:

```blade
                    Es werden Bewerber für die Stelle <strong>{{ $this->interview->position?->title }}</strong> angezeigt, deren Phase manuelles Einbuchen erlaubt und für die noch keine Verträge versendet wurden — sowie alle importierten Bewerber (Altbestand, ohne Stellen-Bindung).
```

- [ ] **Step 3: Blade prüfen**

Run: `php tools/blade-check.php resources/views/livewire/interview-bookings/index.blade.php`
Expected: kein Fehler.

- [ ] **Step 4: Gesamtsuite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, unverändert zur Baseline + Task 1/2.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(recruiting): Buchungs-Dialog filtert auf Phasen-Schalter statt auf abgeschlossen"
```

---

### Task 4: Warteliste beim Buchen schließen

**Files:**
- Create: `src/Observers/RecInterviewBookingWaitlistObserver.php`
- Modify: `src/RecruitingServiceProvider.php` (Observer registrieren, neben den bestehenden)
- Test: `tests/Integration/BookingClosesWaitlistTest.php`

**Interfaces:**
- Consumes: nichts aus vorherigen Tasks
- Produces: `RecInterviewBookingWaitlistObserver::register(): void` — statische Registrierung wie bei den übrigen Observern des Moduls

**Warum ein Observer und nicht der Dialog:** es gibt vier Pfade, die Buchungen anlegen — HR-Dialog (`InterviewBookings\Index::book()`), MCP-Tool (`CreateInterviewBookingTool`), CSV-Sammelbuchung (`Applicant\Index::bookImportedIntoInterview()`) und das öffentliche Formular (`Public\InterviewBooking`). Nur letzteres schließt die Warteliste heute selbst (`Public/InterviewBooking.php:337-340`). Am Model hängt die Regel für alle vier.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Observers\RecInterviewBookingWaitlistObserver;

/**
 * Eine Buchung nimmt den Bewerber von der Warteliste — Ort-Eintrag UND
 * Termin-Abo, egal fuer welchen Termin. Gleiche Semantik, die der oeffentliche
 * Pfad schon hat (Public/InterviewBooking.php:337-340); der Observer zieht sie
 * auf alle Buchungspfade.
 */
final class BookingClosesWaitlistTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance() ?: new Container();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Pflicht: ohne Dispatcher feuert der Observer nicht und die
        // uuid-creating-Hooks der Models fallen aus.
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id');
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->string('status')->default('booked');
            $t->boolean('is_active')->default(true);
            $t->timestamp('booked_at')->nullable();
            $t->timestamp('seat_released_at')->nullable();
            $t->string('cancelled_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_interview_waitlist', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable();
            $t->integer('team_id')->nullable();
            $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type');
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        RecInterviewBookingWaitlistObserver::register();
    }

    protected function tearDown(): void
    {
        // Listener abraeumen, sonst sehen spaetere Testklassen im geteilten
        // Prozess unsere Observer-Registrierung.
        RecInterviewBooking::flushEventListeners();
        Capsule::schema()->drop('rec_interview_bookings');
        Capsule::schema()->drop('rec_interview_waitlist');
        Capsule::schema()->drop('rec_auto_pilot_logs');
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        parent::tearDown();
    }

    private function warteliste(array $overrides = []): int
    {
        return Capsule::table('rec_interview_waitlist')->insertGetId(array_merge([
            'rec_applicant_id' => 42,
            'rec_interview_id' => null,
            'armed' => false,
            'enrolled_at' => '2026-08-01 09:00:00',
        ], $overrides));
    }

    private function offen(int $id): bool
    {
        $row = Capsule::table('rec_interview_waitlist')->where('id', $id)->first();
        return $row->fulfilled_at === null && $row->cancelled_at === null;
    }

    public function test_ort_eintrag_wird_geschlossen(): void
    {
        $eintrag = $this->warteliste();

        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => 1,
        ]);

        $this->assertFalse($this->offen($eintrag));
    }

    public function test_termin_abo_wird_auch_geschlossen(): void
    {
        $abo = $this->warteliste(['rec_interview_id' => 99, 'armed' => true]);

        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => 1,
        ]);

        $this->assertFalse($this->offen($abo));
    }

    public function test_fremder_bewerber_bleibt_unberuehrt(): void
    {
        $fremd = $this->warteliste(['rec_applicant_id' => 43]);

        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => 1,
        ]);

        $this->assertTrue($this->offen($fremd));
    }

    public function test_stornierte_buchung_schliesst_nichts(): void
    {
        $eintrag = $this->warteliste();

        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'cancelled', 'created_by_user_id' => 1,
        ]);

        $this->assertTrue($this->offen($eintrag));
    }

    public function test_reaktivierte_buchung_schliesst_ebenfalls(): void
    {
        // Der HR-Dialog nutzt updateOrCreate und kann eine alte stornierte
        // Zeile wiederbeleben — dann ist wasRecentlyCreated false.
        $buchung = RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'cancelled', 'created_by_user_id' => 1,
        ]);
        $eintrag = $this->warteliste();

        $buchung->status = 'booked';
        $buchung->save();

        $this->assertFalse($this->offen($eintrag));
    }

    public function test_log_unterscheidet_hr_von_selbstbuchung(): void
    {
        $this->warteliste();
        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => 1,
        ]);

        $this->warteliste(['rec_applicant_id' => 44]);
        RecInterviewBooking::create([
            'rec_applicant_id' => 44, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => null,
        ]);

        $hr = Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 42)->first();
        $selbst = Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 44)->first();

        $this->assertSame('waitlist_closed', $hr->type);
        $this->assertStringContainsString('HR', $hr->summary);
        $this->assertStringNotContainsString('HR', $selbst->summary);
    }

    public function test_ohne_offene_eintraege_kein_log(): void
    {
        RecInterviewBooking::create([
            'rec_applicant_id' => 42, 'rec_interview_id' => 7,
            'status' => 'booked', 'created_by_user_id' => 1,
        ]);

        $this->assertSame(0, Capsule::table('rec_auto_pilot_logs')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter BookingClosesWaitlistTest`
Expected: FAIL — `Class "…\Observers\RecInterviewBookingWaitlistObserver" not found`

- [ ] **Step 3: Write minimal implementation**

`src/Observers/RecInterviewBookingWaitlistObserver.php`:

```php
<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Wer einen Termin hat, braucht keinen zweiten: eine neue oder wieder
 * aktivierte Buchung schliesst alle offenen Warteliste-Eintraege des
 * Bewerbers — Ort-Eintrag UND Termin-Abos, unabhaengig vom Termin.
 *
 * Das ist genau die Semantik, die der oeffentliche Pfad schon hat
 * (Public/InterviewBooking.php:337-340, ->open() ohne Termin-Filter). Hier
 * hängt sie am Model, damit HR-Dialog, MCP-Tool und CSV-Sammelbuchung nicht
 * jeder für sich daran denken muessen. Ohne das bekommt ein manuell gebuchter
 * Bewerber spaeter noch eine "Termin frei geworden"-WhatsApp: der
 * Versand-Guard prueft fulfilled_at, nicht ob eine Buchung existiert
 * (NotifyWaitlistForInterview.php:177-180).
 *
 * fulfilled_at und nicht cancelled_at: der Bewerber HAT einen Termin bekommen.
 * cancelled_at heisst im Datenmodell "hat sich abgemeldet".
 *
 * Bewusst pauschal: auch ein Termin-Abo fuer einen ANDEREN (vollen) Termin
 * fliegt raus. Wer wirklich umsteigen will, kann sich ueber seinen Portal-Link
 * neu eintragen. Bei manueller Buchung ist es eine bewusste HR-Entscheidung —
 * deshalb der Log-Eintrag, damit es in drei Wochen nicht wie ein Bug aussieht.
 *
 * Body in safelyRun(): ein Bug hier darf nie einen regulaeren Save kaputt
 * machen (gleiches Prinzip wie RecInterviewBookingComplianceObserver).
 */
class RecInterviewBookingWaitlistObserver
{
    public static function register(): void
    {
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                // Nur beim Entstehen oder bei einem Statuswechsel — sonst
                // laeuft die Query bei jedem Feld-Update mit.
                if (!$booking->wasRecentlyCreated && !$booking->wasChanged('status')) {
                    return;
                }

                if ($booking->status === 'cancelled') {
                    return;
                }

                $offene = RecInterviewWaitlist::where('rec_applicant_id', $booking->rec_applicant_id)
                    ->open()
                    ->get();

                if ($offene->isEmpty()) {
                    return;
                }

                RecInterviewWaitlist::whereIn('id', $offene->pluck('id'))
                    ->update(['fulfilled_at' => now()]);

                // created_by_user_id trennt die Pfade: der oeffentliche
                // Buchungspfad setzt es nicht (Public/InterviewBooking.php:308-321),
                // HR-Dialog, MCP-Tool und Sammelbuchung setzen es.
                $durchHr = $booking->created_by_user_id !== null;
                $anzahl = $offene->count();
                $abos = $offene->whereNotNull('rec_interview_id')->count();

                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type'             => 'waitlist_closed',
                    'summary'          => $durchHr
                        ? "Warteliste geschlossen ({$anzahl} Eintrag/Einträge, davon {$abos} Termin-Abo) — manuelle Buchung durch HR (Buchung #{$booking->id})."
                        : "Warteliste geschlossen ({$anzahl} Eintrag/Einträge, davon {$abos} Termin-Abo) — Bewerber hat selbst gebucht (Buchung #{$booking->id}).",
                    'details'          => [
                        'booking_id'   => $booking->id,
                        'interview_id' => $booking->rec_interview_id,
                        'entry_ids'    => $offene->pluck('id')->all(),
                        'by_hr'        => $durchHr,
                    ],
                ]);
            }, 'rec_interview_booking.saved.waitlist', $booking->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, ?int $id = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            try {
                Log::warning("[{$context}] fehlgeschlagen", ['id' => $id, 'error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }
    }
}
```

Registrierung in `src/RecruitingServiceProvider.php` direkt neben der bestehenden Observer-Registrierung ergänzen:

```php
        \Platform\Recruiting\Observers\RecInterviewBookingWaitlistObserver::register();
```

**Hinweis für den Implementierer:** vor dem Schreiben `grep -n "Observer::register" src/RecruitingServiceProvider.php` laufen lassen und die neue Zeile in denselben Block einfügen (die Datei hat in main gerade eine Zeile für ein Dispo-Command bekommen — nicht danebenschreiben).

- [ ] **Step 4: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter BookingClosesWaitlistTest`
Expected: PASS (7 Tests)

- [ ] **Step 5: Gesamtsuite — Reihenfolge-Falle prüfen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS. Wenn hier Fehler in FREMDEN Testklassen auftauchen, ist der `flushEventListeners()` im `tearDown` nicht angekommen — nicht die fremden Tests anpassen, sondern hier aufräumen.

- [ ] **Step 6: Commit**

```bash
git add src/Observers/RecInterviewBookingWaitlistObserver.php src/RecruitingServiceProvider.php tests/Integration/BookingClosesWaitlistTest.php
git commit -m "feat(recruiting): Buchung nimmt Bewerber von Warteliste und Termin-Abos"
```

---

### Task 5: Checkbox auf der Stellen-Seite

**Files:**
- Modify: `src/Livewire/Position/Show.php` (mount + save)
- Modify: `resources/views/livewire/position/show.blade.php` (Phasen-Block ab Z. 282)

**Interfaces:**
- Consumes: `RecPhase->allow_manual_booking` aus Task 1
- Produces: HR kann den Schalter pro Phase selbst setzen

**Warum `wire:model` und kein `@entangle`:** Checkboxen laufen über `wire:model` und waren von dem Entangle-Defekt nie betroffen; `Position/Show` füllt seine Arrays ohnehin in `mount()` und ist damit unbetroffen (belegt in Commit `4957d70`).

- [ ] **Step 1: Zustand laden**

In `src/Livewire/Position/Show.php` eine eigene Property neben den Settings-Arrays (die Spalte ist kein JSON-Setting, deshalb nicht in `phaseAutoPilotSettings` mischen):

```php
    /** @var array<int, bool> phase_id => Schalter "manuelles Einbuchen erlaubt" */
    public array $phaseAllowManualBooking = [];
```

In `mount()`, in der bestehenden Phasen-Schleife:

```php
        foreach ($this->position->phases as $phase) {
            $this->phaseAutoPilotSettings[$phase->id] = ($phase->auto_pilot_settings ?? []) + $this->phaseSettingsDefaults();
            $this->phaseAllowManualBooking[$phase->id] = (bool) $phase->allow_manual_booking;
        }
```

- [ ] **Step 2: Zustand speichern**

In `save()`, in der bestehenden Phasen-Schleife (nach `$phase->auto_pilot_settings = ...`, vor `$phase->save()`):

```php
            if (array_key_exists($phaseId, $this->phaseAllowManualBooking)) {
                $phase->allow_manual_booking = (bool) $this->phaseAllowManualBooking[$phaseId];
            }
```

- [ ] **Step 3: Checkbox + Badge im Blade**

Im Phasen-Kopf (nach dem `Stellen-Wechsel`-Badge, um Zeile 300) ein Badge ergänzen:

```blade
                                @if($phase->allow_manual_booking)
                                    <x-ui-badge variant="secondary" size="xs">HR-Buchung</x-ui-badge>
                                @endif
```

Und als eigene Zeile im Phasen-Block, direkt vor dem `{{-- WA Template Overrides --}}`-Abschnitt:

```blade
                            <div class="pt-4 border-t border-[var(--ui-border)]/30">
                                <x-ui-input-checkbox
                                    model="phaseAllowManualBooking.{{ $phase->id }}"
                                    name="phaseAllowManualBooking.{{ $phase->id }}"
                                    label="Manuelles Einbuchen erlaubt"
                                    wire:model.live="phaseAllowManualBooking.{{ $phase->id }}"
                                />
                                <p class="mt-1 text-xs text-[var(--ui-muted)]">
                                    Bewerber in dieser Phase erscheinen im Buchungs-Dialog eines Schulungstermins und können von HR ein- und umgebucht werden — solange noch keine Verträge versendet sind.
                                </p>
                            </div>
```

- [ ] **Step 4: Blade prüfen**

Run: `php tools/blade-check.php resources/views/livewire/position/show.blade.php`
Expected: kein Fehler.

- [ ] **Step 5: Gesamtsuite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Position/Show.php resources/views/livewire/position/show.blade.php
git commit -m "feat(recruiting): Schalter fuer manuelles Einbuchen pro Phase im Stellen-Backend"
```

---

### Task 6: Backfill der fünf Live-Stellen

**Files:**
- Create: `src/Console/Commands/EnableManualBookingForPhases.php`
- Create: `src/Support/ManualBookingBackfillPlanner.php`
- Modify: `src/RecruitingServiceProvider.php` (Command registrieren)
- Test: `tests/Unit/EnableManualBookingPlannerTest.php`

**Interfaces:**
- Consumes: `rec_phases.allow_manual_booking` aus Task 1
- Produces: `ManualBookingBackfillPlanner::selectPhaseIds(array $phases, int $fromOrder): array` — `$phases` ist eine Liste von `['id' => int, 'order' => int, 'is_active' => bool, 'allow_manual_booking' => bool]`, Rückgabe die IDs, die umgeschaltet werden müssen

**Warum ein Command und keine Daten-Migration:** die Stellen-IDs sind Live-Daten (8, 9, 10, 11, 16 im Team RHEINGEDECK-HR). In einer Migration wären sie für immer festgenagelt und in jeder anderen Installation falsch. Der Command ist wiederholbar, hat einen Dry-Run und dokumentiert sich selbst.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ManualBookingBackfillPlanner;

/**
 * Auswahl-Logik des Backfills, ohne DB: ab welcher Ordnungszahl, was ist schon
 * gesetzt, was ist inaktiv. Der Command selbst tut danach nur noch ein Update.
 */
final class EnableManualBookingPlannerTest extends TestCase
{
    private function phase(int $id, int $order, bool $active = true, bool $flag = false): array
    {
        return ['id' => $id, 'order' => $order, 'is_active' => $active, 'allow_manual_booking' => $flag];
    }

    public function test_waehlt_phasen_ab_der_grenze(): void
    {
        $phasen = [
            $this->phase(1, 1),
            $this->phase(2, 2),
            $this->phase(3, 3),
            $this->phase(4, 4),
        ];

        $this->assertSame([2, 3, 4], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_ueberspringt_bereits_gesetzte(): void
    {
        $phasen = [$this->phase(2, 2, true, true), $this->phase(3, 3)];

        $this->assertSame([3], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_ueberspringt_inaktive_phasen(): void
    {
        $phasen = [$this->phase(2, 2), $this->phase(5, 5, false)];

        $this->assertSame([2], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_leere_liste_bleibt_leer(): void
    {
        $this->assertSame([], ManualBookingBackfillPlanner::selectPhaseIds([], 2));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EnableManualBookingPlannerTest`
Expected: FAIL — `Class "…\Support\ManualBookingBackfillPlanner" not found`

- [ ] **Step 3: Write minimal implementation**

`src/Support/ManualBookingBackfillPlanner.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Phasen schaltet der Backfill scharf? Reine Auswahl, damit die Regel
 * ohne DB testbar ist (Muster EmployeeBackfillPlanner).
 *
 * Bereits gesetzte Phasen bleiben aussen vor, damit der Command idempotent
 * ist und im Dry-Run ehrlich zaehlt. Inaktive Phasen werden nie geschaltet:
 * sie tauchen im Dialog ohnehin nicht auf, und ein Flag auf einer stillgelegten
 * Phase ist eine Falle beim spaeteren Reaktivieren.
 */
final class ManualBookingBackfillPlanner
{
    /**
     * @param list<array{id:int,order:int,is_active:bool,allow_manual_booking:bool}> $phases
     * @return list<int>
     */
    public static function selectPhaseIds(array $phases, int $fromOrder): array
    {
        $ids = [];

        foreach ($phases as $phase) {
            if (!($phase['is_active'] ?? false)) {
                continue;
            }
            if (($phase['order'] ?? 0) < $fromOrder) {
                continue;
            }
            if ($phase['allow_manual_booking'] ?? false) {
                continue;
            }
            $ids[] = (int) $phase['id'];
        }

        return $ids;
    }
}
```

`src/Console/Commands/EnableManualBookingForPhases.php`:

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Support\ManualBookingBackfillPlanner;

/**
 * Schaltet den Phasen-Schalter "manuelles Einbuchen erlaubt" fuer bestehende
 * Stellen scharf. Default ist ein Dry-Run — erst --apply schreibt.
 *
 * Live-Aufruf fuer Rheingedeck (Team 3), Phasen ab Ordnungszahl 2:
 *   php artisan recruiting:enable-manual-booking --position=8,9,10,11,16 --apply
 *
 * Die Stellen sind: 8 Duesseldorf allgemein, 9 Koeln allgemein,
 * 10 Bonn allgemein, 11 Moenchengladbach allgemein, 16 Duesseldorf - Messe.
 * Alle vier Phasen-Schnitte sind identisch (1 Bewerbung, 2 Schulung buchen,
 * 3 Onboarding, 4 Schulung & Vertraege versenden), P2 ist die Buchungs-Phase.
 */
class EnableManualBookingForPhases extends Command
{
    protected $signature = 'recruiting:enable-manual-booking
                            {--position= : Kommaliste von Stellen-IDs (Pflicht)}
                            {--from-order=2 : Ab welcher Phasen-Ordnungszahl}
                            {--apply : Schreiben statt nur anzeigen}';

    protected $description = 'Setzt allow_manual_booking auf den Phasen der genannten Stellen (Dry-Run ohne --apply).';

    public function handle(): int
    {
        $ids = collect(explode(',', (string) $this->option('position')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('--position ist Pflicht, z.B. --position=8,9,10,11,16');
            return Command::FAILURE;
        }

        $fromOrder = (int) $this->option('from-order');
        $apply = (bool) $this->option('apply');
        $gesamt = 0;

        foreach ($ids as $positionId) {
            $phases = RecPhase::where('rec_position_id', $positionId)
                ->orderBy('order')
                ->get(['id', 'name', 'order', 'is_active', 'allow_manual_booking']);

            if ($phases->isEmpty()) {
                $this->warn("Stelle {$positionId}: keine Phasen gefunden — übersprungen.");
                continue;
            }

            $auswahl = ManualBookingBackfillPlanner::selectPhaseIds(
                $phases->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'order' => (int) $p->order,
                    'is_active' => (bool) $p->is_active,
                    'allow_manual_booking' => (bool) $p->allow_manual_booking,
                ])->all(),
                $fromOrder,
            );

            $namen = $phases->whereIn('id', $auswahl)
                ->map(fn ($p) => "P{$p->order} {$p->name}")
                ->implode(', ');

            $this->line("Stelle {$positionId}: " . (count($auswahl) ? $namen : 'nichts zu tun'));

            if ($apply && $auswahl) {
                RecPhase::whereIn('id', $auswahl)->update(['allow_manual_booking' => true]);
            }
            $gesamt += count($auswahl);
        }

        $this->info($apply
            ? "{$gesamt} Phase(n) geschaltet."
            : "{$gesamt} Phase(n) wären betroffen — mit --apply ausführen.");

        return Command::SUCCESS;
    }
}
```

Command in `src/RecruitingServiceProvider.php` im bestehenden `commands([...])`-Block registrieren:

```php
                \Platform\Recruiting\Console\Commands\EnableManualBookingForPhases::class,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EnableManualBookingPlannerTest`
Expected: PASS (4 Tests)

- [ ] **Step 5: Syntax der neuen PHP-Dateien prüfen**

Run: `php -l src/Console/Commands/EnableManualBookingForPhases.php` und `php -l src/Support/ManualBookingBackfillPlanner.php`
Expected: `No syntax errors detected` (beide)

- [ ] **Step 6: Gesamtsuite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/EnableManualBookingForPhases.php src/Support/ManualBookingBackfillPlanner.php src/RecruitingServiceProvider.php tests/Unit/EnableManualBookingPlannerTest.php
git commit -m "feat(recruiting): Backfill-Command fuer den Phasen-Schalter mit Dry-Run"
```

---

## Deploy und Live-Prüfung (nach dem Merge, nicht Teil der Tasks)

1. Merge auf `main` (fast-forward, keine PRs per CLI in diesem Projekt).
2. **`meingedeck` bumpen** (`composer.lock`) — ohne Bump ist nichts live.
3. Forge-Deploy, dann `php artisan migrate` (die Spalte).
4. `php artisan recruiting:enable-manual-booking --position=8,9,10,11,16 --dry-run` lesen, danach denselben Aufruf **ohne** `--dry-run`.

   **Schritt 3 und 4 gehören in dasselbe Deploy-Fenster.** Zwischen Migration und
   Backfill steht der Schalter überall auf `false` — der Buchungs-Dialog zeigt in
   diesem Zeitraum nur CSV-Altbestand, HR kann also kurzzeitig gar nicht manuell
   buchen. Nicht über Nacht liegen lassen.
5. **`php artisan queue:restart`** — der neue Observer läuft auch in Queued Jobs, die sonst mit altem Code weiterlaufen.
6. Sichtprüfungen live:
   - Stellen-Seite Düsseldorf allgemein: Checkbox an P2/P3/P4 gesetzt, Badge „HR-Buchung" sichtbar, Speichern hält.
   - Schulungstermin öffnen → „Kandidat buchen": Bewerber aus „Schulung buchen" erscheinen, Hinweistext passt.
   - Ein Bewerber mit versendetem Vertrag erscheint **nicht**.
   - Bewerber mit offener Warteliste manuell buchen → Warteliste-Eintrag ist „gebucht", Log-Eintrag `waitlist_closed` mit „HR" am Bewerber.
   - Umbuchen: Buchung absagen, im Zieltermin neu buchen — Bewerber erscheint in der Liste.

## Abweichungen bei der Umsetzung

- **Task 6 nutzt `--dry-run` statt `--apply`.** Der Plan hatte Dry-Run als
  Default vorgesehen; die beiden Bestands-Backfills des Moduls
  (`recruiting:backfill-employee-fields`, `recruiting:dispo-reprocess`) schreiben
  aber per Default und haben ein `--dry-run`. Ein Command, der ohne Extra-Flag
  nichts tut, würde hier einmal laufen, erfolgreich aussehen und nichts geändert
  haben. Deploy-Schritt 4 ist entsprechend angepasst.
- **Task 3 übergibt `rec_position_id ?: null`.** Im Plan stand der rohe Wert. Der
  alte Filter hing an einer Truthiness-Prüfung — eine 0 muss weiterhin „keine
  Stelle" heißen und nicht „Stelle 0" (leere Liste).
- **Zwei Tests mehr als geplant:** `test_stellen_filter_laesst_nur_passende_stelle_durch`
  und `test_importierte_umgehen_den_stellen_filter` (Task 2, deckt den
  Bypass ab, den die alte Implementierung nur als Kommentar hatte) sowie
  `test_bereits_erfuellter_eintrag_wird_nicht_neu_gestempelt` (Task 4).

## Review-Runde 1 (Tasks 1–3, high)

Neun Befunde, acht umgesetzt, einer erledigt sich durch die späteren Tasks:

- **Altbestand hätte verschwinden können** — die Import-Bedingung fragte
  zusätzlich nach fehlender Phase. Ein CSV-Import startet phasenlos, bleibt es
  aber nicht: `reconcilePositionState()` setzt ihn bei Posting-Verknüpfung auf
  die erste Phase (`RecApplicant.php:1966` fasst „Phase fehlt" ausdrücklich mit).
  Jetzt reicht `import_source`.
- **Drei implizite Ausschlüsse nachgezogen** — `is_parked`, `is_on_hr_desk`,
  `duplicate_of_applicant_id`. Die hatte der alte `auto_pilot_completed_at`-Filter
  gratis miterledigt; alle drei Zustände lassen `is_active` auf true.
- **`book()` prüft die Regel jetzt im Lock nach** — vorher nur
  `exists:rec_applicants`, also kein Schutz gegen ein offen gebliebenes Modal
  und keine Team-Prüfung.
- **Stillgelegte Phasen zählen nicht mehr** — der Backfill überspringt inaktive
  Phasen, die Query prüfte es nicht.
- **`DuplicatePosition` klont den Schalter mit** — sonst hätte eine geklonte
  Stelle ohne Fehlermeldung einen leeren Buchungs-Dialog.
- **`UpdatePhaseTool` kann den Schalter setzen** — fehlte in der Feld-Whitelist,
  der MCP-Pfad wäre still ins Nichts gelaufen.
- **Vertrags-Prädikat hat eine Quelle** — `RecContract::scopeSent()`, benutzt von
  `hasAnyContractSent()` und der Kandidaten-Query. Zwei Kopien wären beim ersten
  neuen Status auseinandergelaufen.
- **Unbegrenzte IN-Liste weg** — `whereDoesntHave('interviewBookings')` statt
  `pluck()` über alle Buchungen aller Teams; Stellen-Filter ohne Join auf
  `rec_positions` (FK mit `cascadeOnDelete`, verwaiste Postings gibt es nicht).
- **Hinweistext für Termine ohne Stelle** — die Liste ist dort
  stellenübergreifend, das stand nirgends.
- *Erledigt:* der Befund „Migration verweist auf einen Command, den es nicht
  gibt" bezog sich auf den Review-Stand (Tasks 1–3); Command und Checkbox sind
  mit Tasks 5/6 gelandet. Die Deploy-Kopplung steht jetzt oben ausdrücklich drin.

## Bekannte Nebenwirkungen (dem Kunden gesagt, kein Bug)

- Ein manuell gebuchter Platz ist nicht garantiert: reagiert der Bewerber im Onboarding nicht auf die Erinnerungen, gibt der Auto-Pilot den Platz frei (`releaseSeats`, Standby) und benachrichtigt die Warteliste.
- Wer nach P3 gebucht wird, bleibt auf Status „Gebucht" — der Hook, der auf „Registriert" hochstuft, hängt am Abschluss von P3 und feuert nicht rückwirkend. HR zieht den Status bei Bedarf selbst nach.
- Die Stelle wird beim manuellen Buchen **nicht** umgehängt (`switch_position_on_booking` greift nur im öffentlichen Pfad). Das ist Paket 2.
