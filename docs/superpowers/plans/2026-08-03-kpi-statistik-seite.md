# KPI-Statistik-Seite (Teil 1: Transition-Log + Kohorten-Tabelle) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phasen-Transition-Log (Observer + Backfill) und die Statistik-Seite `/statistik` mit rekonzilierter Kohorten-Tabelle, KPI-Kacheln und Drill-down bauen.

**Architecture:** Pure Rechenklassen (`CohortAssigner`, `BookingStatusGroups`, `PhaseAdvancedSummaryParser`, `PhaseTransitionTrigger`) ohne Framework-Imports tragen die gesamte Entscheidungslogik und sind mit reinem PHPUnit getestet. Eloquent-Observer schreiben Transitions; eine dünne Livewire-Komponente holt Rohdaten und delegiert an die pure Schicht.

**Tech Stack:** Laravel 11 (Modul platforms-recruiting), Livewire 3, MySQL 8, PHPUnit 11 (pure, ohne Laravel/DB — Modul-Konvention).

**Spec:** `docs/superpowers/specs/2026-08-03-kpi-statistik-seite-design.md` (Stand `dfc1bbf`). Die Analyse-Sektionen (Spec §6.1–6.7) sind bewusst NICHT in diesem Plan — eigener Folgeplan, sie hängen nur von `CohortAssigner` und dem Transition-Log ab.

## Global Constraints

- Tests: NUR reines PHPUnit ohne Laravel/DB. Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` (aus dem Modul-Root). Pure Klassen dürfen KEINE `Illuminate\*`-Imports haben (Autoloader lädt sie ohne Framework).
- Jede neue Eloquent-Query auf Team-Daten braucht `forTeam()`/`where('team_id', …)` — kein globaler Scope vorhanden.
- Einziger stiller Filter überall: `is_test`. Alles andere wird als Zeile/Gruppe sichtbar.
- Transition-Inserts IMMER in try/catch — ein Log-Fehler darf nie den Phasenwechsel brechen.
- Status-Gruppierung referenziert `SeatStandbyPolicy` (`SEAT_FREEING_STATUSES`), keine zweite Wahrheit.
- Kein `rec_phase_id` in `->update([...])` außerhalb `FixApplicantPhase` (Task 6 nagelt das als Test fest).
- Route ohne Sidebar-Eintrag; Commits klein, Prefix `feat(recruiting):`/`test(recruiting):`.
- Umlaute in Commit-Messages als ae/oe/ue (Konvention der bisherigen Commits).

---

### Task 1: Migration + Model `RecPhaseTransition`

**Files:**
- Create: `database/migrations/2026_08_03_000001_create_rec_phase_transitions_table.php`
- Create: `src/Models/RecPhaseTransition.php`

**Interfaces:**
- Produces: Tabelle `rec_phase_transitions`; Model `RecPhaseTransition` (fillable wie unten). Spätere Tasks nutzen `RecPhaseTransition::create([...])`.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_phase_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            // Historie eines geloeschten Bewerbers ist wertlos; Team-Loeschung
            // raeumt so konsistent ab (Spec §5, FK-Loeschverhalten).
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            // nullOnDelete PFLICHT: cascadeOnDelete wuerde die Historie genau in dem
            // Moment loeschen, fuer den der Name-Snapshot gebaut wurde.
            $table->foreignId('rec_position_id')->nullable()->constrained('rec_positions')->nullOnDelete();
            $table->foreignId('from_phase_id')->nullable()->constrained('rec_phases')->nullOnDelete();
            $table->foreignId('to_phase_id')->nullable()->constrained('rec_phases')->nullOnDelete();
            $table->string('from_phase_name')->nullable();
            $table->string('to_phase_name')->nullable();
            $table->string('trigger', 20)->default('unknown');
            $table->string('source', 10)->default('live'); // live|backfill
            // Idempotenz-Schluessel des Backfills (Spec §5)
            $table->foreignId('source_log_id')->nullable()->unique()->constrained('rec_auto_pilot_logs')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['team_id', 'occurred_at']);
            $table->index(['rec_applicant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_phase_transitions');
    }
};
```

- [ ] **Step 2: Model schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

class RecPhaseTransition extends Model
{
    protected $table = 'rec_phase_transitions';

    protected $fillable = [
        'team_id', 'rec_applicant_id', 'rec_position_id',
        'from_phase_id', 'to_phase_id', 'from_phase_name', 'to_phase_name',
        'trigger', 'source', 'source_log_id', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
```

- [ ] **Step 3: Syntax-Check + Commit**

Run: `php -l database/migrations/2026_08_03_000001_create_rec_phase_transitions_table.php && php -l src/Models/RecPhaseTransition.php`
Expected: `No syntax errors detected` (2×)

```bash
git add database/migrations/2026_08_03_000001_create_rec_phase_transitions_table.php src/Models/RecPhaseTransition.php
git commit -m "feat(recruiting): rec_phase_transitions Tabelle + Model — Phasen-Historie mit Name-Snapshots"
```

---

### Task 2: Pure Klasse `PhaseTransitionTrigger` (Trigger-Kontext)

**Files:**
- Create: `src/Support/PhaseTransitionTrigger.php`
- Test: `tests/Unit/Statistics/PhaseTransitionTriggerTest.php`

**Interfaces:**
- Produces: `PhaseTransitionTrigger::set(int $applicantId, string $trigger)`, `::consume(int $applicantId): string` (liefert den Wert NUR bei ID-Match, leert IMMER), `::forget(int $applicantId)`, Konstanten `AUTO_ADVANCE|MANUAL|RETURNED|POSITION_SWITCH|FIX|PHASE_DELETED|UNKNOWN`. Task 3 setzt den Kontext an den vier bekannten Stellen (mit try/finally), der Observer konsumiert ihn.

**P1-Hintergrund:** Queue-Worker laufen stundenlang im selben PHP-Prozess. Ein
gesetzter Trigger, dessen Observer nie feuert (rec_phase_id unverändert, Guard
bricht ab, Exception), darf NIE den nächsten Phasenwechsel eines anderen
Bewerbers etikettieren — `auto_advance` vs. `manual` ist die
Auto-Pilot-Durchlaufquote (§6.6), `fix` muss aus den Medianen fliegen.
Deshalb: an die Applicant-ID gebunden, consume leert immer, forget im finally.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

class PhaseTransitionTriggerTest extends TestCase
{
    public function test_default_ist_unknown(): void
    {
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(1));
    }

    public function test_consume_liefert_wert_bei_id_match_und_leert(): void
    {
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::MANUAL);
        $this->assertSame('manual', PhaseTransitionTrigger::consume(7));
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7), 'nach consume geleert');
    }

    public function test_id_mismatch_liefert_unknown_und_leert_trotzdem(): void
    {
        // P1: liegengebliebener Trigger (Observer feuerte nie) darf den
        // naechsten Wechsel eines ANDEREN Bewerbers nicht etikettieren.
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::MANUAL);
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(8));
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7), 'Mismatch leert auch');
    }

    public function test_forget_leert_nur_bei_passender_id(): void
    {
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::RETURNED);
        PhaseTransitionTrigger::forget(9);
        $this->assertSame('returned', PhaseTransitionTrigger::consume(7), 'fremde ID leert nicht');

        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::RETURNED);
        PhaseTransitionTrigger::forget(7);
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7));
    }

    public function test_konstanten_vollstaendig(): void
    {
        $this->assertSame('auto_advance', PhaseTransitionTrigger::AUTO_ADVANCE);
        $this->assertSame('returned', PhaseTransitionTrigger::RETURNED);
        $this->assertSame('position_switch', PhaseTransitionTrigger::POSITION_SWITCH);
        $this->assertSame('fix', PhaseTransitionTrigger::FIX);
        $this->assertSame('phase_deleted', PhaseTransitionTrigger::PHASE_DELETED);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseTransitionTriggerTest`
Expected: FAIL/ERROR (Klasse existiert nicht)

- [ ] **Step 3: Implementierung (KEINE Framework-Imports)**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Transienter Trigger-Kontext fuer den Phase-Observer: die vier bekannten
 * Phasenwechsel-Methoden setzen ihn VOR dem save() (try/finally!); der
 * Observer konsumiert ihn beim Schreiben der Transition. Alle anderen
 * Schreibpfade (LLM-Tool, DirectHire, Reconcile, SyncPhases, ...) laufen als
 * 'unknown' — bewusst, damit keiner still falsch etikettiert wird (Spec §5).
 *
 * An die Applicant-ID gebunden (P1): Queue-Worker leben stundenlang im selben
 * Prozess — ein liegengebliebener Wert darf nie den naechsten Wechsel eines
 * anderen Bewerbers etikettieren. consume() leert deshalb IMMER.
 */
final class PhaseTransitionTrigger
{
    public const AUTO_ADVANCE   = 'auto_advance';
    public const MANUAL         = 'manual';
    public const RETURNED       = 'returned';
    public const POSITION_SWITCH = 'position_switch';
    public const FIX            = 'fix';
    public const PHASE_DELETED  = 'phase_deleted';
    public const UNKNOWN        = 'unknown';

    private static ?int $applicantId = null;
    private static ?string $trigger = null;

    public static function set(int $applicantId, string $trigger): void
    {
        self::$applicantId = $applicantId;
        self::$trigger = $trigger;
    }

    /** Liefert den Trigger nur bei ID-Match — und leert in JEDEM Fall. */
    public static function consume(int $applicantId): string
    {
        $value = (self::$applicantId === $applicantId && self::$trigger !== null)
            ? self::$trigger
            : self::UNKNOWN;
        self::$applicantId = null;
        self::$trigger = null;

        return $value;
    }

    /** Aufraeumen im finally der Setz-Stellen — Exception im save() darf nichts stehen lassen. */
    public static function forget(int $applicantId): void
    {
        if (self::$applicantId === $applicantId) {
            self::$applicantId = null;
            self::$trigger = null;
        }
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseTransitionTriggerTest`
Expected: PASS (alle Tests der Datei grün)

- [ ] **Step 5: Commit**

```bash
git add src/Support/PhaseTransitionTrigger.php tests/Unit/Statistics/PhaseTransitionTriggerTest.php
git commit -m "feat(recruiting): PhaseTransitionTrigger — transienter Trigger-Kontext fuer den Phase-Observer"
```

---

### Task 3: Observer `RecApplicantPhaseObserver` (created + updated) + Registrierung + Trigger-Kontext

**Files:**
- Create: `src/Observers/RecApplicantPhaseObserver.php`
- Modify: `src/RecruitingServiceProvider.php` (Observer registrieren, im `boot()` bei den bestehenden Registrierungen — nach `RecInterviewWaitlistObserver`-Muster suchen: `grep -n "observe" src/RecruitingServiceProvider.php`)
- Modify: `src/Models/RecApplicant.php` — vier `PhaseTransitionTrigger::set(...)`-Zeilen

**Interfaces:**
- Consumes: `PhaseTransitionTrigger::consume()` (Task 2), `RecPhaseTransition::create()` (Task 1)
- Produces: automatische Transitions bei jedem Eloquent-`created`/`updated` mit gesetztem/geändertem `rec_phase_id`

- [ ] **Step 1: Observer schreiben**

```php
<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Schreibt rec_phase_transitions bei jedem Eloquent-Pfad, der rec_phase_id
 * setzt/aendert. Deckt NICHT ab (Spec §5, bekannte Ausnahmen):
 *  1. FixApplicantPhase (Query-Builder) — expliziter Insert im Command
 *  2. DB-Kaskaden (nullOnDelete) — RecPhaseObserver/RecPositionObserver
 */
class RecApplicantPhaseObserver
{
    public function created(RecApplicant $applicant): void
    {
        if ($applicant->rec_phase_id) {
            $this->record($applicant, null, (int) $applicant->rec_phase_id);
        }
    }

    public function updated(RecApplicant $applicant): void
    {
        if (!$applicant->wasChanged('rec_phase_id')) {
            return;
        }
        $from = $applicant->getOriginal('rec_phase_id');
        $this->record($applicant, $from ? (int) $from : null, $applicant->rec_phase_id ? (int) $applicant->rec_phase_id : null);
    }

    private function record(RecApplicant $applicant, ?int $fromId, ?int $toId): void
    {
        try {
            $phases = RecPhase::whereIn('id', array_filter([$fromId, $toId]))->get()->keyBy('id');
            $from = $fromId ? $phases->get($fromId) : null;
            $to = $toId ? $phases->get($toId) : null;

            RecPhaseTransition::create([
                'team_id'          => $applicant->team_id,
                'rec_applicant_id' => $applicant->id,
                'rec_position_id'  => $to?->rec_position_id ?? $from?->rec_position_id,
                'from_phase_id'    => $fromId,
                'to_phase_id'      => $toId,
                'from_phase_name'  => $from?->name,
                'to_phase_name'    => $to?->name,
                'trigger'          => PhaseTransitionTrigger::consume($applicant->id),
                'source'           => 'live',
                'occurred_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Transition-Log darf den Phasenwechsel NIE brechen (Spec §5)
            report($e);
        }
    }
}
```

- [ ] **Step 2: Observer registrieren**

In `src/RecruitingServiceProvider.php` bei den bestehenden `::observe(...)`-Aufrufen (per Grep finden) ergänzen:

```php
\Platform\Recruiting\Models\RecApplicant::observe(\Platform\Recruiting\Observers\RecApplicantPhaseObserver::class);
```

- [ ] **Step 3: Trigger-Kontext an den vier bekannten Stellen setzen (try/finally!)**

In `src/Models/RecApplicant.php` (Import `use Platform\Recruiting\Support\PhaseTransitionTrigger;` oben ergänzen) wird an allen vier Stellen das bare `$this->save()` durch dieses Muster ersetzt — das finally garantiert, dass auch bei Exception im save() kein Wert liegenbleibt (P1):

```php
PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::AUTO_ADVANCE);
try {
    $this->save();
} finally {
    PhaseTransitionTrigger::forget($this->id);
}
```

Die vier Stellen und ihre Trigger:

1. Auto-Advance (bei `$this->rec_phase_id = $nextPhase->id;` um Zeile 472): `AUTO_ADVANCE`
2. `advanceToNextPhase()` (um Zeile 541): `MANUAL`
3. `returnToBookingPhase()` (um Zeile 588): `RETURNED`
4. `switchToPosition()` (um Zeile 1648, das `$this->save()` in Schritt 3 der Methode): `POSITION_SWITCH`

(Zeilennummern per `grep -n "rec_phase_id = " src/Models/RecApplicant.php` verifizieren.)

- [ ] **Step 4: Syntax-Check + bestehende Tests + Commit**

Run: `php -l src/Observers/RecApplicantPhaseObserver.php && php -l src/Models/RecApplicant.php && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: keine Syntaxfehler, komplette Suite grün

```bash
git add src/Observers/RecApplicantPhaseObserver.php src/RecruitingServiceProvider.php src/Models/RecApplicant.php
git commit -m "feat(recruiting): Phase-Observer auf created+updated — schreibt rec_phase_transitions mit Trigger-Kontext"
```

---

### Task 4: Kaskaden-Observer `RecPhaseObserver` + `RecPositionObserver`

**Files:**
- Create: `src/Observers/RecPhaseObserver.php`
- Create: `src/Observers/RecPositionObserver.php`
- Modify: `src/RecruitingServiceProvider.php` (beide registrieren)

**Interfaces:**
- Consumes: `RecPhaseTransition::create()` (Task 1)
- Produces: Transitions `to=NULL, trigger='phase_deleted'` BEVOR DB-Kaskaden `rec_phase_id` nullen

- [ ] **Step 1: RecPhaseObserver schreiben**

```php
<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Model-Events feuern nicht bei DB-Kaskaden (Spec §5). Dieser Observer
 * faengt nur direkt ueber Eloquent geloeschte Einzel-Phasen; die
 * Stellen-Loeschung (Kaskade) faengt RecPositionObserver.
 * Achtung: from_phase_id wird von der nullOnDelete-Kaskade unmittelbar
 * nach dem Insert genullt — der Name-Snapshot bleibt (Spec §5). Tests
 * duerfen fuer diesen Fall NICHT die ID erwarten.
 */
class RecPhaseObserver
{
    /**
     * Doppel-Schreib-Guard: RecPositionObserver ruft deleting() direkt auf;
     * loescht danach noch irgendein Pfad dieselbe Phase via Eloquent, gaebe es
     * zwei Intervall-Enden. Prozessweiter Static ist hier fast immer unkritisch
     * (Phase-IDs sind einmalig) — bekannter Randfall: wird eine Loeschung in
     * einer Transaktion zurueckgerollt und im selben Prozess erneut versucht,
     * ueberspringt der Guard das zweite Schreiben (toleriert, dokumentiert).
     * Gecheckter Sonderfall: DuplicatePosition.php:122 loescht Phasen per
     * Query-Builder-Bulk (phases()->delete(), KEINE Events) — betrifft nur die
     * frisch geklonte Zielstelle ohne Bewerber, keine Transitions noetig.
     *
     * @var array<int,bool>
     */
    private static array $handled = [];

    public function deleting(RecPhase $phase): void
    {
        if (isset(self::$handled[$phase->id])) {
            return;
        }
        self::$handled[$phase->id] = true;

        try {
            RecApplicant::where('rec_phase_id', $phase->id)
                ->select(['id', 'team_id'])
                ->chunkById(200, function ($applicants) use ($phase) {
                    foreach ($applicants as $applicant) {
                        RecPhaseTransition::create([
                            'team_id'          => $applicant->team_id,
                            'rec_applicant_id' => $applicant->id,
                            'rec_position_id'  => $phase->rec_position_id,
                            'from_phase_id'    => $phase->id,
                            'to_phase_id'      => null,
                            'from_phase_name'  => $phase->name,
                            'to_phase_name'    => null,
                            'trigger'          => PhaseTransitionTrigger::PHASE_DELETED,
                            'source'           => 'live',
                            'occurred_at'      => now(),
                        ]);
                    }
                });
        } catch (\Throwable $e) {
            report($e); // Loeschung nie blockieren
        }
    }
}
```

- [ ] **Step 2: RecPositionObserver schreiben**

```php
<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * PFLICHT-Observer (Spec §5): rec_phases.rec_position_id ist cascadeOnDelete —
 * MySQL entfernt die Phasen-Zeilen ohne Eloquent-Event, RecPhaseObserver
 * feuert also NICHT bei Stellen-Loeschung. Prinzip: jede Kaskade, die auf
 * rec_phase_id durchschlaegt, braucht einen Observer an ihrem AUSGANGSPUNKT.
 * (Team-Loeschung ist geprueft und braucht keinen: Bewerber + Transitions
 * kaskadieren konsistent mit.)
 */
class RecPositionObserver
{
    public function deleting(RecPosition $position): void
    {
        $observer = new RecPhaseObserver();
        foreach (RecPhase::where('rec_position_id', $position->id)->get() as $phase) {
            $observer->deleting($phase);
        }
    }
}
```

- [ ] **Step 3: Beide im ServiceProvider registrieren**

```php
\Platform\Recruiting\Models\RecPhase::observe(\Platform\Recruiting\Observers\RecPhaseObserver::class);
\Platform\Recruiting\Models\RecPosition::observe(\Platform\Recruiting\Observers\RecPositionObserver::class);
```

- [ ] **Step 4: Syntax-Check + Commit**

Run: `php -l src/Observers/RecPhaseObserver.php && php -l src/Observers/RecPositionObserver.php`
Expected: keine Syntaxfehler

```bash
git add src/Observers/RecPhaseObserver.php src/Observers/RecPositionObserver.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Kaskaden-Observer RecPhase/RecPosition deleting — Transitions vor DB-Nullung"
```

---

### Task 5: `FixApplicantPhase` — expliziter Transition-Insert (`trigger='fix'`)

**Files:**
- Modify: `src/Console/Commands/FixApplicantPhase.php` (um Zeile 104-107, den `DB::table(...)->update(...)`-Block)

**Interfaces:**
- Consumes: `RecPhaseTransition::create()`, `PhaseTransitionTrigger::FIX`

- [ ] **Step 1: Insert nach dem Query-Builder-Update ergänzen**

Direkt nach dem erfolgreichen `->update(['rec_phase_id' => $firstPhase->id])` (im selben try-Block, VOR `$changed++`):

```php
try {
    \Platform\Recruiting\Models\RecPhaseTransition::create([
        'team_id'          => $applicant->team_id,
        'rec_applicant_id' => $applicant->id,
        'rec_position_id'  => $firstPhase->rec_position_id,
        'from_phase_id'    => null, // Command heilt nur rec_phase_id IS NULL
        'to_phase_id'      => $firstPhase->id,
        'from_phase_name'  => null,
        'to_phase_name'    => $firstPhase->name,
        // Korrektur, kein Phasenwechsel — aus allen Verweildauer-Medianen
        // ausgeschlossen (Spec §5)
        'trigger'          => \Platform\Recruiting\Support\PhaseTransitionTrigger::FIX,
        'source'           => 'live',
        'occurred_at'      => now(),
    ]);
} catch (\Throwable) {
    // Transition-Fehler bricht die Heilung nicht ab
}
```

- [ ] **Step 2: Syntax-Check + Commit**

Run: `php -l src/Console/Commands/FixApplicantPhase.php`
Expected: keine Syntaxfehler

```bash
git add src/Console/Commands/FixApplicantPhase.php
git commit -m "feat(recruiting): FixApplicantPhase schreibt Transition trigger=fix — observer-blinder Query-Builder-Pfad"
```

---

### Task 6: Grep-Invarianten-Test (kein `rec_phase_id` in `->update([...])` außerhalb FixApplicantPhase)

**Files:**
- Test: `tests/Unit/Statistics/PhaseWriteInvariantTest.php`

- [ ] **Step 1: Test schreiben (liest Quelldateien, kein Framework)**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Observer-Vollstaendigkeit fest (Spec §7): Query-Builder-Updates
 * auf rec_phase_id umgehen Model-Events. Einzige erlaubte Stelle ist
 * FixApplicantPhase (dort expliziter Transition-Insert, Task 5).
 *
 * HEURISTIK, kein Beweis: flaggt nur ->update([...rec_phase_id...]) in
 * Dateien, die auch DB::table enthalten. False Negatives moeglich (Update
 * ueber Variable, Query-Builder ohne DB::table-Literal), False Positives
 * bei Model-update() neben unabhaengigem DB::table. Der Test ist ein
 * Stolperdraht fuer den haeufigsten Fehler, kein Ersatz fuer Review.
 */
class PhaseWriteInvariantTest extends TestCase
{
    public function test_kein_rec_phase_id_update_ausserhalb_fix_command(): void
    {
        $srcDir = __DIR__ . '/../../../src';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_ends_with($file->getPathname(), 'FixApplicantPhase.php')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            // ->update([ ... 'rec_phase_id' ... ]) im selben Aufruf (dotall, non-greedy)
            if (preg_match('/->update\s*\(\s*\[[^)]*?rec_phase_id/s', $code)) {
                // Model-Instanz-update() feuert Events — nur DB::table/Query-Builder
                // ist gefaehrlich. Heuristik: Datei muss DB::table enthalten UND
                // das Muster; sonst manuell pruefen.
                if (str_contains($code, 'DB::table')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders,
            'Query-Builder-Update auf rec_phase_id gefunden — Observer wird umgangen. '
            . 'Entweder auf Model-Save umstellen oder expliziten Transition-Insert ergaenzen.');
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseWriteInvariantTest`
Expected: PASS (SyncPhases nutzt Model-`update()`, FixApplicantPhase ist ausgenommen)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Statistics/PhaseWriteInvariantTest.php
git commit -m "test(recruiting): Grep-Invariante — kein Query-Builder-Update auf rec_phase_id ausserhalb FixApplicantPhase"
```

---

### Task 7: Pure Parser `PhaseAdvancedSummaryParser`

**Files:**
- Create: `src/Services/Statistics/PhaseAdvancedSummaryParser.php`
- Test: `tests/Unit/Statistics/PhaseAdvancedSummaryParserTest.php`

**Interfaces:**
- Produces: `PhaseAdvancedSummaryParser::parse(string $summary): ?array` → `['from' => ?string, 'to' => string]` oder `null` (Extraktion fehlgeschlagen). Task 8 (Backfill) konsumiert das.

- [ ] **Step 1: Failing Tests schreiben (die drei realen Formate, Spec §5)**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\PhaseAdvancedSummaryParser;

class PhaseAdvancedSummaryParserTest extends TestCase
{
    public function test_format_a_auto_advance_liefert_from_und_to(): void
    {
        $r = PhaseAdvancedSummaryParser::parse('Phase "Bewerbung" abgeschlossen — weiter zu "Onboarding".');
        $this->assertSame(['from' => 'Bewerbung', 'to' => 'Onboarding'], $r);
    }

    public function test_format_b_manuell_liefert_NUR_to_from_bleibt_null(): void
    {
        // Spec §5: from wird NICHT abgeleitet und NICHT geschrieben —
        // Ableitung passiert beim LESEN aus dem Vorgaenger.
        $r = PhaseAdvancedSummaryParser::parse('Manuell weiter zu Phase "Schulung buchen".');
        $this->assertSame(['from' => null, 'to' => 'Schulung buchen'], $r);
    }

    public function test_anfuehrungszeichen_im_phasennamen(): void
    {
        $r = PhaseAdvancedSummaryParser::parse('Phase "A" abgeschlossen — weiter zu "B (Teil "2")".');
        $this->assertNotNull($r);
        $this->assertSame('B (Teil "2")', $r['to']);
    }

    public function test_unbekanntes_format_liefert_null(): void
    {
        $this->assertNull(PhaseAdvancedSummaryParser::parse('Irgendein anderer Text.'));
        $this->assertNull(PhaseAdvancedSummaryParser::parse(''));
    }
}
```

- [ ] **Step 2: Test laufen lassen — FAIL** (Klasse fehlt)

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseAdvancedSummaryParserTest`

- [ ] **Step 3: Implementierung (pure, keine Framework-Imports)**

```php
<?php

namespace Platform\Recruiting\Services\Statistics;

/**
 * Parst die zwei phase_advanced-Summary-Formate aus rec_auto_pilot_logs
 * (RecApplicant.php:479-483 bzw. :550-554). phase_returned braucht keinen
 * Parser (IDs strukturiert in details).
 */
final class PhaseAdvancedSummaryParser
{
    /** @return array{from: ?string, to: string}|null null = Extraktion fehlgeschlagen */
    public static function parse(string $summary): ?array
    {
        // Format A: Phase "X" abgeschlossen — weiter zu "Y".
        if (preg_match('/^Phase "(.+)" abgeschlossen — weiter zu "(.+)"\.$/su', $summary, $m)) {
            return ['from' => $m[1], 'to' => $m[2]];
        }
        // Format B: Manuell weiter zu Phase "Y".  (from = NULL, Spec §5)
        if (preg_match('/^Manuell weiter zu Phase "(.+)"\.$/su', $summary, $m)) {
            return ['from' => null, 'to' => $m[1]];
        }

        return null;
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PhaseAdvancedSummaryParserTest`
Expected: PASS (alle Tests der Datei grün)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Statistics/PhaseAdvancedSummaryParser.php tests/Unit/Statistics/PhaseAdvancedSummaryParserTest.php
git commit -m "feat(recruiting): PhaseAdvancedSummaryParser — pure Parser fuer die zwei Log-Formate, from=NULL bei Manuell"
```

---

### Task 8: Backfill-Command `recruiting:backfill-phase-transitions`

**Files:**
- Create: `src/Console/Commands/BackfillPhaseTransitions.php`
- Modify: `src/RecruitingServiceProvider.php` (Command registrieren, bei den bestehenden `$this->commands([...])`)

**Interfaces:**
- Consumes: `PhaseAdvancedSummaryParser::parse()`, `RecPhaseTransition`, `source_log_id` UNIQUE
- Produces: idempotenter Backfill; Signature `recruiting:backfill-phase-transitions --team= {--dry-run}`

- [ ] **Step 1: Command schreiben**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Services\Statistics\PhaseAdvancedSummaryParser;

/**
 * Backfill der Phasen-Historie aus rec_auto_pilot_logs (Spec §5).
 * - from wird NIE abgeleitet (nur Format A liefert es woertlich)
 * - Nicht-Treffer landen als to_phase_name mit to_phase_id = NULL
 * - Idempotent via source_log_id UNIQUE
 */
class BackfillPhaseTransitions extends Command
{
    protected $signature = 'recruiting:backfill-phase-transitions --team= {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Backfill rec_phase_transitions aus phase_advanced/phase_returned Logs.';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if (!$teamId) {
            $this->error('--team= ist Pflicht.');
            return Command::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['inserted' => 0, 'skipped_existing' => 0, 'name_only' => 0, 'unparseable' => 0];

        RecAutoPilotLog::query()
            ->whereIn('type', ['phase_advanced', 'phase_returned'])
            ->whereHas('applicant', fn ($q) => $q->where('team_id', $teamId))
            ->with('applicant:id,team_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($dryRun, &$stats) {
                foreach ($logs as $log) {
                    if (RecPhaseTransition::where('source_log_id', $log->id)->exists()) {
                        $stats['skipped_existing']++;
                        continue;
                    }

                    if ($log->type === 'phase_returned') {
                        $fromId = $log->details['from_phase_id'] ?? null;
                        $toId = $log->details['to_phase_id'] ?? null;
                        $names = $this->phaseNames([$fromId, $toId]);
                        $row = [
                            'from_phase_id' => $fromId, 'to_phase_id' => $toId,
                            'from_phase_name' => $names[$fromId] ?? null,
                            'to_phase_name' => $names[$toId] ?? null,
                            'trigger' => 'returned',
                        ];
                    } else {
                        $parsed = PhaseAdvancedSummaryParser::parse((string) $log->summary);
                        if ($parsed === null) {
                            $stats['unparseable']++;
                            continue;
                        }
                        // Match NUR gegen Phasen der Stellen dieses Bewerbers (Spec §5)
                        $candidates = $this->applicantPhaseIdsByName($log->rec_applicant_id);
                        $toId = $candidates[$parsed['to']] ?? null;
                        $fromId = $parsed['from'] !== null ? ($candidates[$parsed['from']] ?? null) : null;
                        if ($toId === null) {
                            $stats['name_only']++; // Nicht-Treffer NICHT wegwerfen (Spec §5)
                        }
                        $row = [
                            'from_phase_id' => $fromId, 'to_phase_id' => $toId,
                            'from_phase_name' => $parsed['from'], 'to_phase_name' => $parsed['to'],
                            'trigger' => str_starts_with((string) $log->summary, 'Manuell') ? 'manual' : 'auto_advance',
                        ];
                    }

                    if (!$dryRun) {
                        RecPhaseTransition::create($row + [
                            'team_id' => $log->applicant->team_id,
                            'rec_applicant_id' => $log->rec_applicant_id,
                            'rec_position_id' => $row['to_phase_id']
                                ? DB::table('rec_phases')->where('id', $row['to_phase_id'])->value('rec_position_id')
                                : null,
                            'source' => 'backfill',
                            'source_log_id' => $log->id,
                            'occurred_at' => $log->created_at,
                        ]);
                    }
                    $stats['inserted']++;
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '')
            . "eingefuegt: {$stats['inserted']}, uebersprungen (existiert): {$stats['skipped_existing']}, "
            . "nur-Name (kein ID-Match): {$stats['name_only']}, unparsebar: {$stats['unparseable']}");

        return Command::SUCCESS;
    }

    /** @return array<int,string> id => name */
    private function phaseNames(array $ids): array
    {
        $ids = array_filter($ids);
        return $ids ? DB::table('rec_phases')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    /** @return array<string,int> name => phase_id (Phasen der Stellen des Bewerbers) */
    private function applicantPhaseIdsByName(int $applicantId): array
    {
        return DB::table('rec_applicant_posting as ap')
            ->join('rec_postings as po', 'po.id', '=', 'ap.rec_posting_id')
            ->join('rec_phases as ph', 'ph.rec_position_id', '=', 'po.rec_position_id')
            ->where('ap.rec_applicant_id', $applicantId)
            ->pluck('ph.id', 'ph.name')
            ->all();
    }
}
```

Hinweis: `RecAutoPilotLog` braucht eine `applicant()`-BelongsTo-Relation — prüfen mit `grep -n "function applicant" src/Models/RecAutoPilotLog.php`; falls sie fehlt, ergänzen:

```php
public function applicant()
{
    return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
}
```

- [ ] **Step 2: Syntax-Check + Registrierung + Commit**

Run: `php -l src/Console/Commands/BackfillPhaseTransitions.php`
Expected: keine Syntaxfehler

```bash
git add src/Console/Commands/BackfillPhaseTransitions.php src/Models/RecAutoPilotLog.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Backfill-Command fuer rec_phase_transitions — idempotent via source_log_id, from nie abgeleitet"
```

---

### Task 9: Pure Klasse `BookingStatusGroups`

**Files:**
- Create: `src/Support/BookingStatusGroups.php`
- Test: `tests/Unit/Statistics/BookingStatusGroupsTest.php`

**Interfaces:**
- Consumes: `SeatStandbyPolicy::SEAT_FREEING_STATUSES` (existiert, pure)
- Produces: `BookingStatusGroups::isKnown(?string): bool`, `::isCohortAssigned(?string): bool`, `::isUnknownActive(?string): bool`, `::rank(?string): ?int`. Task 10 konsumiert alle vier.

- [ ] **Step 1: Failing Tests schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingStatusGroups;

class BookingStatusGroupsTest extends TestCase
{
    public function test_cancelled_ist_nie_kohorten_zugeordnet(): void
    {
        $this->assertFalse(BookingStatusGroups::isCohortAssigned('cancelled'));
    }

    public function test_bekannte_aktive_status_sind_kohorten_zugeordnet(): void
    {
        foreach (['booked', 'registered', 'confirmed', 'attended', 'no_show'] as $s) {
            $this->assertTrue(BookingStatusGroups::isCohortAssigned($s), $s);
        }
    }

    public function test_unbekannter_status_ist_nicht_zugeordnet_sondern_unknown_active(): void
    {
        // Spec §4: unbekannte Werte duerfen NICHT still in die Schulungszeilen
        $this->assertFalse(BookingStatusGroups::isCohortAssigned('weird_value'));
        $this->assertTrue(BookingStatusGroups::isUnknownActive('weird_value'));
        $this->assertFalse(BookingStatusGroups::isUnknownActive('cancelled'), 'freigebend != unknown');
    }

    public function test_rang_modell_kumulativ_no_show_ist_rang_2(): void
    {
        $this->assertSame(1, BookingStatusGroups::rank('booked'));
        $this->assertSame(1, BookingStatusGroups::rank('registered'));
        $this->assertSame(2, BookingStatusGroups::rank('confirmed'));
        $this->assertSame(2, BookingStatusGroups::rank('no_show'), 'Abzweig, keine Stufe 3');
        $this->assertSame(3, BookingStatusGroups::rank('attended'));
        $this->assertNull(BookingStatusGroups::rank('cancelled'));
        $this->assertNull(BookingStatusGroups::rank('weird_value'));
    }
}
```

- [ ] **Step 2: FAIL verifizieren**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter BookingStatusGroupsTest`

- [ ] **Step 3: Implementierung (pure)**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Status-Gruppierung der Statistik-Seite (Spec §4). Referenziert
 * SeatStandbyPolicy als einzige Wahrheit fuers Platz-Freigeben.
 * OFFENE VERZWEIGUNG (Auftrag ②): KNOWN spiegelt die heute dokumentierte
 * Werteliste aus den zwei $validStatuses-Duplikaten; sobald Auftrag ② die
 * zentrale Konstante liefert, referenziert KNOWN diese.
 */
final class BookingStatusGroups
{
    public const KNOWN = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'];

    /** Rang-Modell (Spec §4): kumulativ, no_show = Rang 2 (Abzweig, keine Stufe 3) */
    private const RANK = ['booked' => 1, 'registered' => 1, 'confirmed' => 2, 'no_show' => 2, 'attended' => 3];

    public static function isKnown(?string $status): bool
    {
        return in_array($status, self::KNOWN, true);
    }

    public static function isCohortAssigned(?string $status): bool
    {
        return self::isKnown($status)
            && !in_array($status, SeatStandbyPolicy::SEAT_FREEING_STATUSES, true);
    }

    public static function isUnknownActive(?string $status): bool
    {
        return !self::isKnown($status)
            && !in_array($status, SeatStandbyPolicy::SEAT_FREEING_STATUSES, true);
    }

    public static function rank(?string $status): ?int
    {
        return self::RANK[$status] ?? null;
    }
}
```

- [ ] **Step 4: Tests grün + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter BookingStatusGroupsTest`
Expected: PASS (alle Tests der Datei grün)

```bash
git add src/Support/BookingStatusGroups.php tests/Unit/Statistics/BookingStatusGroupsTest.php
git commit -m "feat(recruiting): BookingStatusGroups — Rang-Modell + Kohorten-Zuordnung auf SeatStandbyPolicy-Basis"
```

---

### Task 10: Pure Klasse `CohortAssigner` (Herzstück)

**Files:**
- Create: `src/Services/Statistics/CohortAssigner.php`
- Test: `tests/Unit/Statistics/CohortAssignerTest.php`

**Interfaces:**
- Consumes: `BookingStatusGroups` (Task 9)
- Produces: `CohortAssigner::assign(array $applicants, array $bookingsByApplicant, array $pivotsByApplicant, ?string $from, ?string $to): array` — Ergebnis-Shape siehe Step 3 Docblock. Task 11 (Livewire) konsumiert exakt dieses Shape.

Input-Formate (plain Arrays, von der Livewire-Schicht befüllt):

```
$applicants: list<array{
  id:int, is_test:bool, applied_at:?string(Y-m-d), duplicate:bool, unrouted:bool,
  import:bool, parked:bool, rejected:bool, hr_desk:bool,
  phase_position_id:?int, phase_name:?string, phase_order:?int,
  enrichment_status:?string, contract_sent:bool, contract_signed:bool,
  applied_to_signed_days:?int
}>
$bookingsByApplicant: array<int, list<array{
  booking_id:int, interview_id:int, status:?string, seat_released:bool,
  starts_at:?string, deleted:bool
}>>
$pivotsByApplicant: array<int, list<array{
  posting_id:int, position_id:int, location:?string, activity:?string
}>>
```

- [ ] **Step 1: Failing Tests schreiben (Präzedenz-Kette + Rekonziliations-Invariante)**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\CohortAssigner;

class CohortAssignerTest extends TestCase
{
    private function applicant(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id, 'is_test' => false, 'applied_at' => '2026-07-01',
            'duplicate' => false, 'unrouted' => false, 'import' => false,
            'parked' => false, 'rejected' => false, 'hr_desk' => false,
            'phase_position_id' => null, 'phase_name' => null, 'phase_order' => null,
            'enrichment_status' => 'enriched', 'contract_sent' => false,
            'contract_signed' => false, 'applied_to_signed_days' => null,
        ];
    }

    private function booking(int $id, array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => $id, 'interview_id' => 10, 'status' => 'booked',
            'seat_released' => false, 'starts_at' => '2026-08-10 09:00:00', 'deleted' => false,
        ];
    }

    public function test_praezedenz_kette_erster_treffer_gewinnt(): void
    {
        $result = (new CohortAssigner())->assign([
            $this->applicant(1, ['is_test' => true, 'duplicate' => true]),   // raus
            $this->applicant(2, ['applied_at' => null, 'duplicate' => true]), // Stufe 2 vor 3
            $this->applicant(3, ['duplicate' => true, 'unrouted' => true]),   // Stufe 3 vor 4
            $this->applicant(4, ['unrouted' => true, 'import' => true]),      // Stufe 4 vor 5
            $this->applicant(5, ['import' => true]),                          // Stufe 5 vor 6 (mit Buchung!)
            $this->applicant(6, ['parked' => true]),                          // Stufe 6 vor 7 (mit Buchung!)
            $this->applicant(7, ['parked' => true]),                          // Stufe 7
            $this->applicant(8),                                              // Stufe 8
        ], [
            5 => [$this->booking(50)],
            6 => [$this->booking(60)],
        ], [], null, null);

        $typeById = [];
        foreach ($result['rows'] as $row) {
            foreach ($row['ids'] as $id) {
                $typeById[$id] = $row['type'];
            }
        }
        $this->assertArrayNotHasKey(1, $typeById, 'is_test ist raus');
        $this->assertSame('ohne_datum', $typeById[2]);
        $this->assertSame('dublette', $typeById[3]);
        $this->assertSame('unrouted', $typeById[4]);
        $this->assertSame('import', $typeById[5], 'Import schlaegt Buchung (Spec Stufe 5)');
        $this->assertSame('schulung', $typeById[6], 'Buchung schlaegt geparkt (Stufe 6 vor 7)');
        $this->assertSame('geparkt', $typeById[7]);
        $this->assertSame('ohne_schulung', $typeById[8]);
    }

    public function test_rekonziliation_jeder_genau_einmal(): void
    {
        $applicants = [];
        foreach (range(1, 30) as $i) {
            $applicants[] = $this->applicant($i, [
                'duplicate' => $i % 5 === 0, 'unrouted' => $i % 7 === 0,
                'import' => $i % 3 === 0, 'parked' => $i % 4 === 0,
                'applied_at' => $i % 11 === 0 ? null : '2026-07-01',
            ]);
        }
        $result = (new CohortAssigner())->assign($applicants, [2 => [$this->booking(1)]], [], null, null);

        $seen = [];
        foreach ($result['rows'] as $row) {
            foreach ($row['ids'] as $id) {
                $this->assertArrayNotHasKey($id, $seen, "Bewerber $id doppelt");
                $seen[$id] = true;
            }
        }
        $this->assertSame(count($result['total_ids']), count($seen), 'Gesamt = Summe der Zeilen');
        $this->assertCount(30, $result['total_ids']);
    }

    public function test_unbekannter_status_eigene_zeile_statt_schulung(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1)],
            [1 => [$this->booking(11, ['status' => 'weird_value'])]],
            [], null, null
        );
        $types = array_column($result['rows'], 'type');
        $this->assertContains('unbekannter_status', $types);
        $this->assertNotContains('schulung', $types);
    }

    public function test_tie_break_neueste_buchung_spaetester_start_dann_kleinste_id(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1)],
            [1 => [
                $this->booking(11, ['interview_id' => 100, 'starts_at' => '2026-08-01 09:00:00']),
                $this->booking(12, ['interview_id' => 200, 'starts_at' => '2026-08-20 09:00:00']),
                $this->booking(13, ['interview_id' => 300, 'starts_at' => '2026-08-20 09:00:00']),
            ]],
            [], null, null
        );
        $schulung = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        // spaetester starts_at gewinnt; bei Gleichstand kleinste Booking-ID → Interview 200
        $this->assertSame('schulung:200', $schulung['key']);
    }

    public function test_zeitraumfilter_mit_null_ausnahme(): void
    {
        $result = (new CohortAssigner())->assign([
            $this->applicant(1, ['applied_at' => '2026-06-01']), // vor Zeitraum → raus
            $this->applicant(2, ['applied_at' => '2026-07-15']), // drin
            $this->applicant(3, ['applied_at' => null]),          // NULL faellt NIE still raus
        ], [], [], '2026-07-01', '2026-07-31');

        $this->assertSame([2, 3], $result['total_ids']);
    }

    public function test_leerstring_datum_verhaelt_sich_wie_null(): void
    {
        // P6: Livewire liefert '' fuer geleerte Datumsfelder — darf die
        // Tabelle nicht leeren ("Von" gesetzt, "Bis" geleert).
        $a = [$this->applicant(1, ['applied_at' => '2026-07-05'])];
        $withEmpty = (new CohortAssigner())->assign($a, [], [], '2026-07-01', '');
        $withNull = (new CohortAssigner())->assign($a, [], [], '2026-07-01', null);
        $this->assertSame($withNull['total_ids'], $withEmpty['total_ids']);
        $this->assertSame([1], $withEmpty['total_ids']);
    }

    public function test_gruppen_fallbacks_und_hr_desk_marker(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1, ['hr_desk' => true])],
            [1 => [$this->booking(11)]],
            [], null, null
        );
        $schulung = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        $this->assertSame([1], $schulung['hr_desk_ids'], 'HR-Desk ist Marker, kein Zeilentyp');
        $this->assertSame('ohne Ausschreibung', $schulung['group']['ort'], 'Gruppen-Fallback Fall 3');
    }

    public function test_funnel_spalten_kumulativ(): void
    {
        $result = (new CohortAssigner())->assign(
            [
                $this->applicant(1), $this->applicant(2), $this->applicant(3),
                $this->applicant(4, ['contract_signed' => true, 'contract_sent' => true, 'applied_to_signed_days' => 12]),
            ],
            [
                1 => [$this->booking(11, ['status' => 'booked'])],
                2 => [$this->booking(12, ['status' => 'confirmed'])],
                3 => [$this->booking(13, ['status' => 'no_show'])],
                4 => [$this->booking(14, ['status' => 'attended'])],
            ],
            [], null, null
        );
        $row = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        $this->assertSame([1, 2, 3, 4], $row['columns']['gebucht'], 'Rang>=1: alle');
        $this->assertSame([2, 3, 4], $row['columns']['bestaetigt'], 'Rang>=2 inkl. no_show');
        $this->assertSame([4], $row['columns']['teilgenommen'], 'Rang>=3 OHNE no_show');
        $this->assertSame([3], $row['columns']['no_show']);
        $this->assertSame([4], $row['columns']['unterschrieben']);
        $this->assertSame([12], $row['tth_days'], 'tth haengt an der Zeile (P5)');
    }
}
```

- [ ] **Step 2: FAIL verifizieren**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CohortAssignerTest`

- [ ] **Step 3: Implementierung (pure, keine Framework-Imports)**

```php
<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Support\BookingStatusGroups;

/**
 * Herzstueck der Statistik-Seite (Spec §4): Praezedenz-Kette (Zeilentyp) und
 * Zuordnungsregel (Gruppe) leben KOMPLETT hier — nicht in SQL —, damit die
 * Rekonziliations-Invariante pure-testbar ist. Liefert pro Zelle ID-Mengen;
 * die Anzeige ist count() davon, das Drill-down laedt exakt diese IDs.
 *
 * Ergebnis-Shape:
 * [
 *   'total_ids' => list<int>,
 *   'rows' => list<array{
 *     type: string,   // ohne_datum|dublette|unrouted|import|schulung|
 *                     // unbekannter_status|geparkt|abgesagt|ohne_schulung
 *     key: string,    // z.B. "schulung:42", "ohne_schulung:2|Onboarding"
 *     group: array{ort:string, taetigkeit:string},
 *     ids: list<int>,
 *     hr_desk_ids: list<int>,
 *     columns: array{kontaktiert:list<int>, gebucht:list<int>, bestaetigt:list<int>,
 *                    teilgenommen:list<int>, standby:list<int>, no_show:list<int>,
 *                    vertrag_verschickt:list<int>, unterschrieben:list<int>},
 *     tth_days: list<int>,  // Eingang→Unterschrift DIESER Zeile (P5: Kacheln
 *                           // aggregieren ueber dieselben gefilterten Zeilen)
 *   }>,
 * ]
 */
final class CohortAssigner
{
    public function assign(
        array $applicants,
        array $bookingsByApplicant,
        array $pivotsByApplicant,
        ?string $from,
        ?string $to,
    ): array {
        // P6: geleerte Livewire-Datumsfelder liefern '' statt null — und
        // '2026-07-05' > '' ist WAHR, die Tabelle waere komplett leer.
        // Pure Klasse mit oeffentlichem Kontrakt traut dem Aufrufer nicht.
        $from = ($from === '') ? null : $from;
        $to = ($to === '') ? null : $to;

        $rows = [];
        $totalIds = [];

        foreach ($applicants as $a) {
            // Stufe 1: is_test — einziger stiller Filter (Spec §4)
            if ($a['is_test']) {
                continue;
            }
            // Zeitraum ist Filter — mit NULL-Ausnahme (Stufe 2 faengt NULL)
            if ($a['applied_at'] !== null) {
                if (($from !== null && $a['applied_at'] < $from)
                    || ($to !== null && $a['applied_at'] > $to)) {
                    continue;
                }
            }

            $totalIds[] = $a['id'];

            [$type, $key, $booking] = $this->rowTypeFor($a, $bookingsByApplicant[$a['id']] ?? []);
            $group = $this->groupFor($a, $pivotsByApplicant[$a['id']] ?? []);

            $rowKey = $type . '|' . $key . '|' . $group['ort'] . '|' . $group['taetigkeit'];
            if (!isset($rows[$rowKey])) {
                $rows[$rowKey] = [
                    'type' => $type, 'key' => $key, 'group' => $group,
                    'ids' => [], 'hr_desk_ids' => [], 'tth_days' => [],
                    'columns' => [
                        'kontaktiert' => [], 'gebucht' => [], 'bestaetigt' => [],
                        'teilgenommen' => [], 'standby' => [], 'no_show' => [],
                        'vertrag_verschickt' => [], 'unterschrieben' => [],
                    ],
                ];
            }
            $row = &$rows[$rowKey];
            $row['ids'][] = $a['id'];
            if ($a['hr_desk']) {
                $row['hr_desk_ids'][] = $a['id']; // Marker, kein Zeilentyp (Spec §4)
            }

            if ($a['enrichment_status'] !== null && $a['enrichment_status'] !== 'no_contact') {
                $row['columns']['kontaktiert'][] = $a['id'];
            }
            if ($type === 'schulung' && $booking !== null) {
                $rank = BookingStatusGroups::rank($booking['status']);
                if ($rank >= 1) { $row['columns']['gebucht'][] = $a['id']; }
                if ($rank >= 2) { $row['columns']['bestaetigt'][] = $a['id']; }
                if ($rank >= 3) { $row['columns']['teilgenommen'][] = $a['id']; }
                if ($booking['status'] === 'no_show') { $row['columns']['no_show'][] = $a['id']; }
                if ($booking['status'] === 'booked' && $booking['seat_released']) {
                    $row['columns']['standby'][] = $a['id'];
                }
            }
            if ($a['contract_sent']) { $row['columns']['vertrag_verschickt'][] = $a['id']; }
            if ($a['contract_signed']) {
                $row['columns']['unterschrieben'][] = $a['id'];
                if ($a['applied_to_signed_days'] !== null) {
                    $row['tth_days'][] = $a['applied_to_signed_days']; // P5: pro Zeile
                }
            }
            unset($row);
        }

        return ['total_ids' => $totalIds, 'rows' => array_values($rows)];
    }

    /** @return array{0:string,1:string,2:?array} [type, key, gewinnende Buchung|null] */
    private function rowTypeFor(array $a, array $bookings): array
    {
        if ($a['applied_at'] === null) { return ['ohne_datum', '-', null]; }   // Stufe 2
        if ($a['duplicate']) { return ['dublette', '-', null]; }               // Stufe 3
        if ($a['unrouted']) { return ['unrouted', '-', null]; }                // Stufe 4
        if ($a['import']) { return ['import', '-', null]; }                    // Stufe 5: Import schlaegt Buchung

        // Stufe 6: neueste kohorten-relevante Buchung. Tie-Break (Senior-Rule):
        // spaetester starts_at, bei Gleichstand kleinste Booking-ID.
        $candidates = array_values(array_filter($bookings, fn ($b) => !$b['deleted']
            && (BookingStatusGroups::isCohortAssigned($b['status'])
                || BookingStatusGroups::isUnknownActive($b['status']))));
        if ($candidates !== []) {
            usort($candidates, function ($x, $y) {
                $cmp = strcmp((string) $y['starts_at'], (string) $x['starts_at']);
                return $cmp !== 0 ? $cmp : ($x['booking_id'] <=> $y['booking_id']);
            });
            $winner = $candidates[0];
            if (BookingStatusGroups::isCohortAssigned($winner['status'])) {
                return ['schulung', 'schulung:' . $winner['interview_id'], $winner];
            }
            return ['unbekannter_status', '-', $winner]; // sichtbar, nie verschluckt
        }

        if ($a['parked']) { return ['geparkt', '-', null]; }                   // Stufe 7
        if ($a['rejected']) { return ['abgesagt', '-', null]; }
        // Stufe 8: nach aktueller Phase aufgeschluesselt
        $phaseKey = ($a['phase_order'] ?? '-') . '|' . ($a['phase_name'] ?? 'ohne Phase');
        return ['ohne_schulung', 'ohne_schulung:' . $phaseKey, null];
    }

    /** Zuordnungsregel (Spec §4, fuenf Faelle) → Gruppe, nie Zeilentyp */
    private function groupFor(array $a, array $pivots): array
    {
        if ($pivots === []) {
            return ['ort' => 'ohne Ausschreibung', 'taetigkeit' => 'ohne Ausschreibung']; // Fall 3
        }
        // Fall 1: Pivot passt zur Position von rec_phase_id
        $match = null;
        foreach ($pivots as $p) {
            if ($a['phase_position_id'] !== null && $p['position_id'] === $a['phase_position_id']) {
                $match = $p;
                break;
            }
        }
        // Fall 2: keine passt → kleinste posting_id (Kennzeichnung macht die UI via 'uneindeutig')
        if ($match === null) {
            usort($pivots, fn ($x, $y) => $x['posting_id'] <=> $y['posting_id']);
            $match = $pivots[0];
        }
        return [
            'ort' => ($match['location'] !== null && $match['location'] !== '') ? $match['location'] : 'ohne Ort',
            'taetigkeit' => ($match['activity'] !== null && $match['activity'] !== '') ? $match['activity'] : 'ohne Tätigkeit',
        ];
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CohortAssignerTest`
Expected: PASS (alle Tests der Datei grün)

- [ ] **Step 5: Komplette Suite + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alles grün

```bash
git add src/Services/Statistics/CohortAssigner.php tests/Unit/Statistics/CohortAssignerTest.php
git commit -m "feat(recruiting): CohortAssigner — Praezedenz-Kette, Zuordnungsregel, ID-Mengen, Rekonziliations-Invariante"
```

---

### Task 11: Livewire-Seite `/statistik` (Route + Komponente + View)

**Files:**
- Create: `src/Livewire/Statistics/Index.php`
- Create: `resources/views/livewire/statistics/index.blade.php`
- Modify: `routes/web.php` (nach den Dashboard-Routen)

**Interfaces:**
- Consumes: `CohortAssigner::assign()` (Ergebnis-Shape aus Task 10), `RecApplicant`-Scopes
- Produces: Route `recruiting.statistics.index` unter `/recruiting/statistik` (via ModuleRouter-Gruppe: Auth + Modul-Permission automatisch, KEIN Sidebar-Eintrag)

- [ ] **Step 1: Route ergänzen** (in `routes/web.php` nach den Dashboard-Routen)

```php
// Statistik (V1 nur per Direkt-URL, kein Sidebar-Eintrag — Spec §1 Rollout)
Route::get('/statistik', \Platform\Recruiting\Livewire\Statistics\Index::class)
    ->name('recruiting.statistics.index');
```

- [ ] **Step 2: Livewire-Komponente schreiben (dünn: Queries + Mapping + Delegation)**

```php
<?php

namespace Platform\Recruiting\Livewire\Statistics;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Services\Statistics\CohortAssigner;

class Index extends Component
{
    public ?string $filterFrom = null;
    public ?string $filterTo = null;

    // P6: geleerte x-ui-input-date liefern '' — auf null normalisieren,
    // damit SQL-when() und Assigner dieselbe Menge sehen
    public function updatedFilterFrom($value): void
    {
        $this->filterFrom = $value ?: null;
    }

    public function updatedFilterTo($value): void
    {
        $this->filterTo = $value ?: null;
    }
    public ?string $ortFilter = null;
    public ?string $activityFilter = null;
    public ?int $postingFilter = null;
    public ?int $sourcePlatformFilter = null;

    /** @var list<int> IDs fuer das Drill-down-Modal */
    public array $drillIds = [];
    public string $drillLabel = '';

    #[Computed]
    public function cohort(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        // P2: Vorfilter spiegeln die PHP-Logik verlustfrei (is_test = Stufe 1,
        // Zeitraum mit NULL-Ausnahme = Stufe 2, Posting-/Quellen-Filter =
        // Mengeneinschraenkung P3) — Rekonziliation unveraendert, aber die
        // Query laedt nie das ganze Team (Query-Budget ist Abnahmekriterium §2).
        // Falls Q10 grosse Zahlen zeigt: chunkById(500) + assign() pro Chunk
        // fuettern — der Assigner akkumuliert zeilenweise, ist also streamfaehig.
        $applicants = RecApplicant::forTeam($teamId)
            ->where('is_test', false)
            ->when($this->filterFrom || $this->filterTo, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereNull('applied_at')
                ->orWhere(fn ($q3) => $q3
                    ->when($this->filterFrom, fn ($q4) => $q4->where('applied_at', '>=', $this->filterFrom))
                    ->when($this->filterTo, fn ($q4) => $q4->where('applied_at', '<=', $this->filterTo)))))
            // P3: Ausschreibungs-Filter schraenkt die BEWERBER-Menge ein (Spec §4),
            // nicht nur die Pivot-Liste — sonst fuellt sich "ohne Ausschreibung"
            // mit dem gesamten Rest des Teams.
            ->when($this->postingFilter, fn ($q) => $q->whereHas('postings',
                fn ($p) => $p->where('rec_postings.id', $this->postingFilter)))
            ->when($this->sourcePlatformFilter, fn ($q) => $q->where('source_platform_id', $this->sourcePlatformFilter))
            // OPTIONAL, erst wenn Q10 grosse Zahlen zeigt: Superset-Vorfilter Ort.
            // Schliesst nie eine Zeile aus, die sonst ueberlebt haette — eine Zeile
            // mit konkreter Gruppe "Essen" setzt eine Pivot-Zeile mit
            // position.location = 'Essen' voraus; die Fallback-Werte "ohne Ort"/
            // "ohne Ausschreibung" sind nie gleich einer konkreten Auswahl.
            // ->when($this->ortFilter, fn ($q) => $q->whereHas('postings.position',
            //     fn ($p) => $p->where('location', $this->ortFilter)))
            ->with([
                'postings.position',
                // kein withTrashed(): der Assigner verwirft deleted ohnehin —
                // SoftDeleted gar nicht erst laden
                'interviewBookings' => fn ($q) => $q->with('interview:id,starts_at,location'),
                // P4 verifiziert: rec_contracts.status ist string(30) NOT NULL
                // default 'pending' (Migration 2026_04_15_100000) → '!=' ist
                // NULL-safe. Dashboard zaehlt heute ungefiltert (bumpStatRow:421);
                // der cancelled-Ausschluss hier ist Spec §4 (heute wirkungslos,
                // aber zukunftssicher).
                'contracts' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'phase:id,name,order,rec_position_id',
            ])
            ->get();

        $rows = [];
        $bookings = [];
        $pivots = [];
        foreach ($applicants as $a) {
            $signed = $a->contracts->whereNotNull('signed_at')->sortBy('signed_at')->first();
            $rows[] = [
                'id' => $a->id,
                'is_test' => (bool) $a->is_test,
                'applied_at' => $a->applied_at?->toDateString(),
                'duplicate' => $a->duplicate_of_applicant_id !== null,
                'unrouted' => (bool) $a->is_unrouted,
                'import' => $a->import_source !== null,
                'parked' => (bool) $a->is_parked,
                'rejected' => $a->rejected_at !== null,
                'hr_desk' => (bool) $a->is_on_hr_desk,
                'phase_position_id' => $a->phase?->rec_position_id,
                'phase_name' => $a->phase?->name,
                'phase_order' => $a->phase?->order,
                'enrichment_status' => $a->enrichment_status,
                'contract_sent' => $a->contracts->whereNotNull('sent_at')->isNotEmpty(),
                'contract_signed' => $signed !== null,
                'applied_to_signed_days' => ($signed && $a->applied_at)
                    ? max(0, $a->applied_at->startOfDay()->diffInDays($signed->signed_at->startOfDay()))
                    : null,
            ];
            $bookings[$a->id] = $a->interviewBookings->map(fn ($b) => [
                'booking_id' => $b->id,
                'interview_id' => $b->rec_interview_id,
                'status' => $b->status,
                'seat_released' => $b->seat_released_at !== null,
                'starts_at' => $b->interview?->starts_at?->toDateTimeString(),
                // heute identisch mit false (kein withTrashed) — aber
                // selbstkorrigierend, falls die Relation je geaendert wird
                'deleted' => $b->deleted_at !== null,
            ])->all();
            $pivots[$a->id] = $a->postings
                ->filter(fn ($p) => $this->postingFilter === null || $p->id === $this->postingFilter)
                ->map(fn ($p) => [
                    'posting_id' => $p->id,
                    'position_id' => $p->rec_position_id,
                    'location' => $p->position?->location,
                    'activity' => $p->activity,
                ])->all();
        }

        $result = (new CohortAssigner())->assign($rows, $bookings, $pivots, $this->filterFrom, $this->filterTo);

        // Ort-/Taetigkeits-Filter wirken auf die GRUPPE (nach dem Assign, damit
        // die Rekonziliation innerhalb der Auswahl geschlossen bleibt)
        if ($this->ortFilter !== null || $this->activityFilter !== null) {
            $result['rows'] = array_values(array_filter($result['rows'], fn ($r) =>
                ($this->ortFilter === null || $r['group']['ort'] === $this->ortFilter)
                && ($this->activityFilter === null || $r['group']['taetigkeit'] === $this->activityFilter)));
            $result['total_ids'] = array_merge(...array_map(fn ($r) => $r['ids'], $result['rows']) ?: [[]]);
        }

        return $result;
    }

    #[Computed]
    public function interviewMeta(): array
    {
        $ids = [];
        foreach ($this->cohort['rows'] as $row) {
            if ($row['type'] === 'schulung') {
                $ids[] = (int) substr($row['key'], strlen('schulung:'));
            }
        }
        return RecInterview::with('interviewType:id,name')
            ->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($i) => [$i->id => [
                'starts_at' => $i->starts_at,
                'location' => $i->location, // nur Info-Spalte (Spec §3)
                'type' => $i->interviewType?->name ?? 'ohne Terminart',
                'max' => $i->max_participants,
                'seat_taking' => $i->takenSeatsCount(),
            ]])->all();
    }

    #[Computed]
    public function tiles(): array
    {
        $c = $this->cohort; // Kacheln lesen NUR aus dem Kohorten-Ergebnis (Spec §3)
        // KEIN array_unique: die Zeilen sind per Rekonziliations-Invariante
        // disjunkt — unique wuerde eine Verletzung maskieren statt aufdecken.
        $sum = fn (string $col) => array_sum(array_map(fn ($r) => count($r['columns'][$col]), $c['rows']));
        $total = count($c['total_ids']);
        $signed = $sum('unterschrieben');
        // P5: tth pro Zeile aggregiert → folgt automatisch jedem Zeilen-Filter
        // (Ort/Taetigkeit), Kachel und Tabelle koennen sich nicht widersprechen
        $tth = array_merge(...array_map(fn ($r) => $r['tth_days'], $c['rows']) ?: [[]]);
        sort($tth);
        $n = count($tth);
        return [
            'bewerbungen' => $total,
            'gebucht' => $sum('gebucht'),
            'unterschrieben' => $signed,
            'conversion' => $total > 0 ? (int) round($signed / $total * 100) : 0,
            'tth_median' => $n > 0
                ? ($n % 2 === 0
                    ? (int) round(($tth[$n / 2 - 1] + $tth[$n / 2]) / 2)
                    : $tth[intdiv($n, 2)])
                : null,
        ];
    }

    public function drill(string $rowKey, string $column, string $label): void
    {
        foreach ($this->cohort['rows'] as $row) {
            if ($row['type'] . '|' . $row['key'] . '|' . $row['group']['ort'] . '|' . $row['group']['taetigkeit'] !== $rowKey) {
                continue;
            }
            $this->drillIds = $column === 'ids' ? $row['ids'] : ($row['columns'][$column] ?? []);
            $this->drillLabel = $label;
            return;
        }
    }

    #[Computed]
    public function drillApplicants()
    {
        if ($this->drillIds === []) {
            return collect();
        }
        return RecApplicant::whereIn('id', $this->drillIds)
            ->with('crmContactLinks.contact')->get();
    }

    public function render()
    {
        return view('platforms-recruiting::livewire.statistics.index')
            ->layout('platform::layouts.app');
    }
}
```

Hinweis Layout/View-Namespace: exakt so übernehmen wie in `src/Livewire/Dashboard/Dashboard.php::render()` (per `grep -n "render" src/Livewire/Dashboard/Dashboard.php` nachschlagen und identisch verwenden — nicht raten).

- [ ] **Step 3: Blade-View schreiben**

Kompakte, funktionale V1 — Filterleiste, Kacheln, gruppierte Tabelle, Modal. An bestehende `x-ui-*`-Komponenten anlehnen (Vorbild: `resources/views/livewire/dashboard/dashboard.blade.php`, Panels/Badges). Kernstruktur:

```blade
<div class="space-y-6 p-6">
    {{-- Filterleiste --}}
    <x-ui-panel title="Statistik" subtitle="Rekonzilierte Kohorten-Sicht — jede Zahl klickbar">
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <x-ui-input-date name="filterFrom" label="Von" wire:model.live="filterFrom" />
            <x-ui-input-date name="filterTo" label="Bis" wire:model.live="filterTo" />
            <x-ui-input-select name="ortFilter" label="Ort" wire:model.live="ortFilter" :options="$this->ortOptions" :nullable="true" />
            <x-ui-input-select name="activityFilter" label="Tätigkeit" wire:model.live="activityFilter" :options="$this->activityOptions" :nullable="true" />
            <x-ui-input-select name="postingFilter" label="Ausschreibung" wire:model.live="postingFilter" :options="$this->postingOptions" :nullable="true" />
            <x-ui-input-select name="sourcePlatformFilter" label="Quelle" wire:model.live="sourcePlatformFilter" :options="$this->sourceOptions" :nullable="true" />
        </div>
    </x-ui-panel>

    {{-- KPI-Kacheln (lesen NUR aus dem Kohorten-Ergebnis) --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        @foreach ([['Bewerbungen', $this->tiles['bewerbungen']], ['In Schulung gebucht', $this->tiles['gebucht']],
                   ['Unterschriften', $this->tiles['unterschrieben']], ['Conversion', $this->tiles['conversion'] . ' %'],
                   ['Time-to-Hire (Median)', $this->tiles['tth_median'] !== null ? $this->tiles['tth_median'] . ' Tage' : '–']] as [$label, $value])
            <x-ui-panel><div class="text-2xl font-semibold">{{ $value }}</div><div class="text-sm text-gray-500">{{ $label }}</div></x-ui-panel>
        @endforeach
    </div>

    {{-- Kohorten-Tabelle: Gruppen Ort → Tätigkeit, Zeilen nach Präzedenz-Kette --}}
    {{-- Gruppierung im Blade: rows nach group.ort, group.taetigkeit sortieren/gruppieren; --}}
    {{-- pro Zeile Zellen als <button wire:click="drill('{{ $rowKey }}', 'gebucht', '...')">{{ count(...) }}</button> --}}
    {{-- Schulungszeilen: Datum+Terminart+Ort aus $this->interviewMeta; Kapazitaet als --}}
    {{-- ZWEI Spalten "Kohorte" / "Termin gesamt" (seat_taking/max, >100% erlaubt, Spec §4) --}}
    {{-- Gesamt-Zeile je Ort-Gruppe + Gesamt unten = Addition der Zeilen --}}

    {{-- Drill-down-Modal --}}
    @if ($drillIds !== [])
        <x-ui-modal wire:model="drillLabel" title="{{ $drillLabel }} ({{ count($drillIds) }})">
            <ul class="divide-y">
                @foreach ($this->drillApplicants as $applicant)
                    <li class="py-2">
                        <a href="{{ route('recruiting.applicants.show', $applicant) }}" class="text-blue-600 hover:underline">
                            {{ $applicant->crmContactLinks->first()?->contact?->full_name ?? "Bewerber #{$applicant->id}" }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui-modal>
    @endif
</div>
```

Der Tabellen-Block ist bewusst als Kommentar-Gerüst skizziert — beim Bauen die konkrete Markup-Struktur der Dashboard-Tabelle (`dashboard.blade.php`, „Übersicht nach Stelle") als Vorlage nehmen und 1:1 auf die Kohorten-Zeilen übertragen. Filter-Options-Computeds (`ortOptions` etc.) in der Komponente ergänzen: DISTINCT über `rec_positions.location` / `rec_postings.activity` / aktive Postings / `rec_source_platforms`, jeweils `forTeam`.

WICHTIG (Memory-Pitfalls): `x-ui-input-date` NIE per `wire:model` an einen datetime-Cast binden (String-Property nutzen — hier sind `filterFrom/filterTo` bereits Strings ✓); keine inline `@php(...)`-Ausdrücke, immer Block-Form; keine `:required`-??-Fallbacks an `x-ui-*`.

- [ ] **Step 4: Manuelle Verifikation (kein Unit-Test möglich — Livewire+DB)**

Es gibt KEIN Staging — der reale letzte Sichttest ist der erste Klick auf der Live-Seite nach dem Forge-Deploy:
1. `php artisan migrate` → Tabelle `rec_phase_transitions` existiert
2. `/recruiting/statistik` aufrufen → Seite lädt, Kacheln + Tabelle gefüllt
3. Gesamt-Zahl == Summe aller Zeilen (Stichprobe, Rekonziliation)
4. Eine Zahl anklicken → Modal zeigt exakt so viele Personen
5. Sidebar zeigt KEINEN Statistik-Eintrag

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/index.blade.php routes/web.php
git commit -m "feat(recruiting): Statistik-Seite /statistik — Kohorten-Tabelle, KPI-Kacheln, Drill-down (ohne Sidebar-Eintrag)"
```

---

### Task 12: Deploy

**Files:** keine (Ops-Checkliste, Spec §8 — Reihenfolge ist verbindlich)

- [ ] **Step 1:** Modul pushen — EIN Push, so jetzt auch in Spec §8 festgelegt (die Seite ist nicht öffentlich verlinkt, `migrate` läuft im Deploy-Script; das Zwei-Push-Ritual schützt öffentliche Dauerverkehrs-Seiten). Bekanntes Fenster: zwischen Symlink-Switch und `migrate` schreibt der Observer auf eine noch nicht existierende Tabelle — try/catch fängt es, es fehlen nur die ersten Transitions.
- [ ] **Step 2:** meingedeck `composer.lock` bumpen + pushen (Pflicht nach jedem Modul-Push)
- [ ] **Step 3:** Forge-Deploy abwarten → `php artisan migrate` läuft im Deploy
- [ ] **Step 4:** **`php artisan queue:restart`** — RecApplicant-Code läuft in Queue-Jobs; alte Worker schreiben sonst keine Transitions
- [ ] **Step 5:** Backfill: erst `php artisan recruiting:backfill-phase-transitions --team=<ID> --dry-run`, Zahlen prüfen (inserted / name_only / unparseable), dann ohne `--dry-run`
- [ ] **Step 6:** Live-Verifikation Schritt 1 (Spec §1 Abnahmekriterium): mit der Seite erklären, wie 96 und 153 zustande kamen; Ergebnis an den Reviewer

---

## Self-Review (durchgeführt)

- **Spec-Coverage:** §2 Architektur → Tasks 9–11; §3 Seitenaufbau/Filter → Task 11; §4 Kette/Rang/Gruppen/Drill-down/Kapazität → Tasks 9, 10, 11; §5 Transition-Log komplett → Tasks 1–8; §7 Tests → Tasks 2, 6, 7, 9, 10; §8 Deploy → Task 12. NICHT in diesem Plan: §6 Analyse-Sektionen (eigener Folgeplan, oben deklariert) sowie Right-Censoring/Tooltips aus §6-Fußnoten, die an den Sektionen hängen. Die Kapazitäts-Doppelspalte ist in Task 11 Step 3 als verbindlicher Kommentar-Anker enthalten.
- **Platzhalter:** Task 11 Step 3 enthält bewusst ein Markup-Gerüst mit konkreter Vorlagen-Referenz (Dashboard-Tabelle) statt vollständigem Tabellen-HTML — alle Logik-Bausteine (Zellen = drill-Buttons auf rowKey+Spalte, Meta aus interviewMeta, zwei Kapazitätsspalten) sind benannt. Alle PHP-Blöcke sind vollständig.
- **Typ-Konsistenz:** Ergebnis-Shape von `CohortAssigner::assign()` (Task 10 Docblock) == Konsum in Task 11 (`cohort['rows']`, `columns`, `key`-Format `schulung:<id>`); `BookingStatusGroups`-Signaturen (Task 9) == Nutzung in Task 10; `PhaseTransitionTrigger`-Konstanten (Task 2) == Nutzung in Tasks 3–5.

---

### Task 13: Right-Censoring + Spalten-Tooltips (nachgetragen, as built)

Nachgetragen nach Review-Entscheidung: die zwei §6-Fußnoten Right-Censoring
und Definitions-Tooltips sind Spalten/Darstellung der Kohorten-Tabelle und
gehören zu Teil 1 (Spec-Ablage unter §6 war ein Ablage-, kein Scope-Signal).

**Files:**
- Modify: `src/Services/Statistics/CohortAssigner.php` — pro Zeile `offen_ids`
  (= ids − unterschrieben − no_show, NUR für laufende Typen schulung/
  ohne_schulung, sonst leere Menge) und `max_applied_at` (jüngste Bewerbung
  der Zeile — konservativer Censoring-Anker)
- Modify: `src/Services/Statistics/CohortViewModel.php` — `isCensored(?string
  $maxAppliedAt, string $todayYmd, ?int $tthMedian): bool` (strikt „jünger
  als", kein Median → true, Rollover-Guard per Round-Trip-Prüfung),
  `maxAppliedAt(array $rows): ?string` (Aggregat = max über Zeilen),
  `conversionOf(array $rows): ?int` (null bei 0 Bewerbungen — nie „0 %" für
  „keine Daten")
- Modify: `src/Livewire/Statistics/Index.php` — `isCensored()`/`censorNote()`
  (EINE Textquelle für Kachel und Tabellenzelle; `today` reist als
  Y-m-d-String in die pure Klasse)
- Modify: Blades — Spalte „noch offen" (klickbar, gleiche idsOf/drill-
  Mechanik; „–" auf ausgeschlossenen Buckets), Conversion-%-Spalte für alle
  Zeilenkontexte (colspan-konsistent), Grau+kursiv+Tooltip bei Zensur (Zelle
  UND Kachel), Definitions-Tooltips an allen Spaltenköpfen (Kontaktiert =
  Anreicherungs-Proxy; Bestätigt = registered zählt nicht + Snapshot-Satz)
- Tests: `CohortAssignerTest` (+offen-Mengen inkl. Doppel-Mitgliedschaft,
  max_applied_at), `CohortViewModelTest` (+isCensored-Grenzfälle: Median
  null/0, Alter == Median, Zukunfts-/Rollover-/unlesbares Datum,
  conversionOf null-vs-0, fail-closed resolveIds)

Commits: 39a5066 (Grundausbau), e4cf079 (Kachel graut mit, conversionOf →
ViewModel), + Folge-Commit der Review-Runde (max-Anker, offen nur laufende
Typen, fail-closed, Kapazitäts-Einheiten). Rulings in Spec §4/§6.
