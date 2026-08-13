# Schulungsplatz-Standby — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Schulungsplätze von Bewerbern, die der Auto-Pilot aufgegeben hat (`max_reminders_reached`), automatisch als "Standby" freigeben und der Warteliste anbieten — mit kapazitätsgeprüftem Re-Claim, wenn der Bewerber doch zurückkommt.

**Architecture:** Neues Feld `seat_released_at` auf `rec_interview_bookings` (Status bleibt `booked`). Zentrale Platz-Zählung (`seatTaking`-Scope) ersetzt die duplizierte Regel; ein `saving`-Guard im Model erzwingt die Invariante "Status ≠ booked → kein Release-Marker". Release-Trigger im Auto-Pilot-Max-Branch, Re-Claim als Pre-Advance-Guard in `checkAutoPilotCompletion()` mit `FOR UPDATE`-Lock auf der `rec_interviews`-Zeile.

**Tech Stack:** Laravel/Eloquent (Modul `platforms-recruiting`), Livewire, reines PHPUnit (ohne DB) für die Entscheidungslogik.

**Spec:** `docs/superpowers/specs/2026-07-23-schulungsplatz-standby-design.md`

## Global Constraints

- Tests: NUR reines PHPUnit ohne Laravel/DB. Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modul-Root.
- Entscheidungslogik pure-testbar schneiden (Muster: `FirstAiderDateGuard`), Eloquent-Wiring bleibt dünn.
- Status-Änderungen an Buchungen IMMER über Model-Saves (Observer-Konvention), nie Query-Builder-`update()`.
- Neue Public-Texte: Sie/du über denselben Mechanismus wie bestehende Public-Texte (`use_informal_address`).
- Commits: `feat(recruiting): …` / `fix(recruiting): …`, Deutsch, wie Repo-Historie.
- Kein `gh` CLI. Branch von `origin/main` nach `git fetch`.
- Vorbereitung einmalig: `git fetch origin && git checkout -b feat/schulungsplatz-standby origin/main`
- **Tasks strikt sequenziell (1 → 12) ausführen.** Insbesondere: Task 7 stellt den
  Hook auf Model-Saves um und SETZT den saving-Guard aus Task 2 VORAUS — ein
  Zwischenstand mit Model-Save-Hook ohne Guard würde `booked → registered` ohne
  Marker-Clear zulassen (Phantom-Sitz).
- **Log-Details-Konvention (KPI-Grundlage):** Jedes neue Standby-Log-Event trägt
  strukturierte `details` mit `booking_id` + `interview_id`; `seat_released`
  IMMER mit `source` (`auto_pilot` | `heal`), `reclaim_failed` IMMER mit `mode`
  (`returned` | `hr_case` | `sibling_cancelled`) — die Code-Blöcke in den Tasks
  sind entsprechend, nichts weglassen. KPI-Definitionen dazu stehen in der Spec
  (Abschnitt „KPI-Definitionen"): Standby-Quote = seat_released(source=auto_pilot)
  ÷ Buchungen; Rückhol-Quote = (seat_reclaimed + seat_reclaimed_override) ÷
  seat_released; Verlust-Quote = reclaim_failed(mode=returned) ÷ seat_released;
  HR-offen = reclaim_failed(mode=hr_case), eigener Eimer. Logs sind Best-Effort
  (try/catch) → Quoten sind Näherung, kein Audit.

---

### Task 1: `SeatStandbyPolicy` (pure Entscheidungslogik)

**Files:**
- Create: `src/Support/SeatStandbyPolicy.php`
- Test: `tests/Unit/SeatStandbyPolicyTest.php`

**Interfaces:**
- Produces (von allen Folge-Tasks konsumiert):
  - `SeatStandbyPolicy::countsAsSeat(?string $status, bool $seatReleased): bool`
  - `SeatStandbyPolicy::shouldRelease(?string $status, bool $seatReleased): bool`
  - `SeatStandbyPolicy::reclaimOutcome(bool $seatReleased, int $takenSeats, ?int $maxParticipants, bool $startsInFuture): string` — Rückgabe: `RECLAIM_UPGRADE | RECLAIM_OK | RECLAIM_FAILED`
  - `SeatStandbyPolicy::mustClearReleaseMarker(?string $status): bool`
  - `SeatStandbyPolicy::statusLabel(?string $status, bool $seatReleased): ?string`
  - Konstante `SeatStandbyPolicy::SEAT_FREEING_STATUSES = ['cancelled']`

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SeatStandbyPolicy;

final class SeatStandbyPolicyTest extends TestCase
{
    public function test_counts_as_seat_status_matrix(): void
    {
        foreach (['booked', 'registered', 'confirmed', 'attended', 'no_show'] as $status) {
            $this->assertTrue(SeatStandbyPolicy::countsAsSeat($status, false), "{$status} ohne Release muss zählen");
        }
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('cancelled', false));
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('cancelled', true));
        // Standby (booked + released) zählt NICHT
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('booked', true));
        // Invariante wird anderswo erzwungen, aber die Zählung bleibt defensiv:
        // released + Nicht-booked zählt ebenfalls nicht.
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('registered', true));
    }

    public function test_should_release_nur_fuer_booked_ohne_bestehendes_release(): void
    {
        $this->assertTrue(SeatStandbyPolicy::shouldRelease('booked', false));
        $this->assertFalse(SeatStandbyPolicy::shouldRelease('booked', true));
        foreach (['registered', 'confirmed', 'attended', 'cancelled', 'no_show', null] as $status) {
            $this->assertFalse(SeatStandbyPolicy::shouldRelease($status, false), var_export($status, true));
        }
    }

    public function test_reclaim_outcome(): void
    {
        // Kein Standby → normales Upgrade, keine Kapazitätsfrage
        $this->assertSame(SeatStandbyPolicy::RECLAIM_UPGRADE, SeatStandbyPolicy::reclaimOutcome(false, 99, 10, true));
        // Standby + Platz frei + Zukunft → Re-Claim
        $this->assertSame(SeatStandbyPolicy::RECLAIM_OK, SeatStandbyPolicy::reclaimOutcome(true, 9, 10, true));
        // Standby + unbegrenzte Kapazität → Re-Claim
        $this->assertSame(SeatStandbyPolicy::RECLAIM_OK, SeatStandbyPolicy::reclaimOutcome(true, 999, null, true));
        // Standby + voll → fehlgeschlagen
        $this->assertSame(SeatStandbyPolicy::RECLAIM_FAILED, SeatStandbyPolicy::reclaimOutcome(true, 10, 10, true));
        // Standby + Termin vergangen → fehlgeschlagen (auch wenn "frei")
        $this->assertSame(SeatStandbyPolicy::RECLAIM_FAILED, SeatStandbyPolicy::reclaimOutcome(true, 0, 10, false));
    }

    public function test_must_clear_release_marker(): void
    {
        $this->assertFalse(SeatStandbyPolicy::mustClearReleaseMarker('booked'));
        foreach (['registered', 'confirmed', 'attended', 'cancelled', 'no_show'] as $status) {
            $this->assertTrue(SeatStandbyPolicy::mustClearReleaseMarker($status), $status);
        }
    }

    public function test_status_label(): void
    {
        $this->assertSame('Standby', SeatStandbyPolicy::statusLabel('booked', true));
        $this->assertNull(SeatStandbyPolicy::statusLabel('booked', false));
        $this->assertNull(SeatStandbyPolicy::statusLabel('registered', true));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter SeatStandbyPolicyTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\SeatStandbyPolicy" not found`

- [ ] **Step 3: Implementierung**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Standby-Modell für Schulungsplätze: Eine Buchung belegt einen Platz,
 * solange der Bewerber aktiv dran ist. Gibt der Auto-Pilot auf
 * (max_reminders_reached) und die Buchung steht noch auf 'booked', wird
 * seat_released_at gesetzt — die Buchung bleibt bestehen ("Standby"),
 * zählt aber nicht mehr gegen max_participants.
 *
 * Pure Entscheidungslogik ohne Framework — testbar per reinem PHPUnit
 * (Muster FirstAiderDateGuard). Das Eloquent-Wiring (Scope, saving-Guard,
 * Labels) delegiert hierher.
 */
final class SeatStandbyPolicy
{
    /** @var list<string> Status, die nie einen Platz belegen. */
    public const SEAT_FREEING_STATUSES = ['cancelled'];

    public const RECLAIM_UPGRADE = 'upgrade'; // kein Standby — normales Upgrade
    public const RECLAIM_OK      = 'reclaim'; // Standby, Platz noch frei
    public const RECLAIM_FAILED  = 'failed';  // Standby, Termin voll oder vergangen

    public static function countsAsSeat(?string $status, bool $seatReleased): bool
    {
        return !in_array($status, self::SEAT_FREEING_STATUSES, true) && !$seatReleased;
    }

    public static function shouldRelease(?string $status, bool $seatReleased): bool
    {
        return $status === 'booked' && !$seatReleased;
    }

    public static function reclaimOutcome(bool $seatReleased, int $takenSeats, ?int $maxParticipants, bool $startsInFuture): string
    {
        if (!$seatReleased) {
            return self::RECLAIM_UPGRADE;
        }
        if (!$startsInFuture) {
            return self::RECLAIM_FAILED;
        }
        if ($maxParticipants !== null && $takenSeats >= $maxParticipants) {
            return self::RECLAIM_FAILED;
        }
        return self::RECLAIM_OK;
    }

    /**
     * Invariante: seat_released_at existiert nur auf status='booked'.
     * Wird als saving-Guard im Model erzwungen.
     */
    public static function mustClearReleaseMarker(?string $status): bool
    {
        return $status !== 'booked';
    }

    public static function statusLabel(?string $status, bool $seatReleased): ?string
    {
        return ($status === 'booked' && $seatReleased) ? 'Standby' : null;
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter SeatStandbyPolicyTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/SeatStandbyPolicy.php tests/Unit/SeatStandbyPolicyTest.php
git commit -m "feat(recruiting): SeatStandbyPolicy — pure Entscheidungslogik fuer Standby-Schulungsplaetze"
```

---

### Task 2: Migration + Model-Wiring (`seat_released_at`, saving-Guard, Scope)

**Files:**
- Create: `database/migrations/2026_07_24_000001_add_seat_released_at_to_rec_interview_bookings.php`
- Modify: `src/Models/RecInterviewBooking.php`
- Modify: `src/Models/RecInterview.php`

**Interfaces:**
- Consumes: `SeatStandbyPolicy` (Task 1)
- Produces:
  - Scope `RecInterviewBooking::seatTaking($query)` — belegt = nicht storniert UND `seat_released_at IS NULL`
  - Accessoren `$booking->takes_seat` (bool), `$booking->is_standby` (bool)
  - `RecInterview::takenSeatsCount(): int`, `RecInterview::hasFreeSeat(): bool`, `RecInterview::standbySeatsCount(): int`
  - saving-Guard: jeder Model-Save mit `status !== 'booked'` löscht `seat_released_at`

- [ ] **Step 1: Migration anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            // Standby-Marker: gesetzt = Buchung existiert weiter (status bleibt
            // 'booked'), belegt aber keinen Platz mehr. Nur auf status='booked'
            // gueltig — Invariante via saving-Guard im Model.
            $table->timestamp('seat_released_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropColumn('seat_released_at');
        });
    }
};
```

- [ ] **Step 2: `RecInterviewBooking` erweitern**

In `$fillable` nach `'reminder_sent_at',` einfügen: `'seat_released_at',`
In `$casts` nach `'reminder_sent_at' => 'datetime',` einfügen: `'seat_released_at' => 'datetime',`
Oben: `use Platform\Recruiting\Support\SeatStandbyPolicy;`

In `booted()` nach dem `creating`-Block ergänzen:

```php
        // Invariante: seat_released_at existiert nur auf status='booked'.
        // Jeder Statuswechsel weg von 'booked' (Upgrade, Storno, HR-Set)
        // raeumt den Marker automatisch ab — egal ueber welchen Pfad.
        static::saving(function (self $model) {
            if (SeatStandbyPolicy::mustClearReleaseMarker($model->status)) {
                $model->seat_released_at = null;
            }
        });
```

Neue Methoden (nach `team()`):

```php
    /**
     * Platz-belegende Buchungen: nicht storniert UND kein Standby.
     * DIE zentrale Zaehlregel — alle Kapazitaets-Checks laufen hierueber.
     */
    public function scopeSeatTaking($query)
    {
        return $query
            ->whereNotIn('status', SeatStandbyPolicy::SEAT_FREEING_STATUSES)
            ->whereNull('seat_released_at');
    }

    public function getTakesSeatAttribute(): bool
    {
        return SeatStandbyPolicy::countsAsSeat($this->status, $this->seat_released_at !== null);
    }

    public function getIsStandbyAttribute(): bool
    {
        return SeatStandbyPolicy::statusLabel($this->status, $this->seat_released_at !== null) !== null;
    }
```

`getStatusLabelAttribute()` — am Anfang einfügen:

```php
        if ($label = SeatStandbyPolicy::statusLabel($this->status, $this->seat_released_at !== null)) {
            return $label;
        }
```

- [ ] **Step 3: `RecInterview` Helper ergänzen** (nach `bookings()`):

```php
    /** Belegte Plaetze nach zentraler Zaehlregel (Standby zaehlt nicht). */
    public function takenSeatsCount(): int
    {
        return $this->bookings()->seatTaking()->count();
    }

    public function hasFreeSeat(): bool
    {
        return !$this->max_participants || $this->takenSeatsCount() < $this->max_participants;
    }

    /** Standby-Buchungen (booked + seat_released_at) — fuer HR-Anzeige. */
    public function standbySeatsCount(): int
    {
        return $this->bookings()
            ->where('status', 'booked')
            ->whereNotNull('seat_released_at')
            ->count();
    }
```

- [ ] **Step 4: Regressionslauf**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS (alle bestehenden Tests grün; Eloquent-Wiring hat keine puren Tests — Logik steckt in Task-1-Policy)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_24_000001_add_seat_released_at_to_rec_interview_bookings.php src/Models/RecInterviewBooking.php src/Models/RecInterview.php
git commit -m "feat(recruiting): seat_released_at + seatTaking-Scope + saving-Guard (Standby-Invariante)"
```

---

### Task 3: Zentrale Zählung an allen Lese-Stellen + Views

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php:195-197` (withCount), `:412-423` (Waitlist-Join-Guard)
- Modify: `src/Jobs/NotifyWaitlistForInterview.php:63-71`
- Modify: `src/Services/WaitlistRearmService.php:31-37`
- Modify: `resources/views/livewire/interview-schedule/index.blade.php:96`
- Modify: `resources/views/livewire/interview-bookings/index.blade.php:98-105`

**Interfaces:**
- Consumes: `seatTaking`-Scope, `takes_seat`/`is_standby`-Accessoren, `takenSeatsCount()` (Task 2)
- Produces: alle "ist voll?"/"wie viele belegt?"-Antworten laufen über die zentrale Regel; `bookings_count` im Public-Widget bedeutet ab jetzt "platz-belegende Buchungen" (Blade `:174/:198` braucht deshalb KEINE Änderung)

- [ ] **Step 1: Public-Widget withCount** (`:195-197`) ersetzen:

```php
            ->withCount(['bookings' => function ($query) {
                $query->seatTaking();
            }])
```

- [ ] **Step 2: Waitlist-Join-Guard** — in `joinInterviewWaitlist()` (`:416-418`) ersetzen:

```php
        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->seatTaking()
            ->count();
```

- [ ] **Step 3: Notify-Job** (`:64-67`) ersetzen:

```php
            $booked = RecInterviewBooking::where('rec_interview_id', $interview->id)
                ->seatTaking()
                ->count();
```

- [ ] **Step 4: RearmService** (`:31-33`) ersetzen:

```php
        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->seatTaking()
            ->count();
```

- [ ] **Step 5: HR-Kalender-View** (`interview-schedule/index.blade.php:96-98`) — aktuell:

```blade
<span class="font-medium">{{ $interview->bookings->whereNotIn('status', ['cancelled'])->count() }}</span>
@if($interview->max_participants)
    <span class="text-[var(--ui-muted)]">/ {{ $interview->max_participants }}</span>
```

ersetzen durch:

```blade
@php
    $takenCount = $interview->bookings->filter->takes_seat->count();
    $standbyCount = $interview->bookings->filter->is_standby->count();
@endphp
<span class="font-medium">{{ $takenCount }}</span>
@if($interview->max_participants)
    <span class="text-[var(--ui-muted)]">/ {{ $interview->max_participants }}</span>
@endif
@if($standbyCount > 0)
    <span class="text-amber-600">(+{{ $standbyCount }} Standby)</span>
@endif
```

(Zuweisungen in Block-Form-`@php` — KEINE Inline-Zuweisung im `@if`; Inline-Formen brechen in diesem Projekt nachweislich Blade-Rendering, siehe Global Constraints. Das schließende `@endif` der bestehenden `max_participants`-Bedingung bleibt.)

- [ ] **Step 6: Buchungsverwaltung-View** (`interview-bookings/index.blade.php:100-105`) — aktuell:

```blade
$activeCount = $this->bookings->whereNotIn('status', ['cancelled'])->count();
$isFull = $activeCount >= $this->interview->max_participants;
```

ersetzen durch:

```blade
$activeCount = $this->bookings->filter->takes_seat->count();
$standbyCount = $this->bookings->filter->is_standby->count();
$isFull = $activeCount >= $this->interview->max_participants;
```

und in der Anzeige-Zeile (`:105`) `{{ $activeCount }} / {{ $this->interview->max_participants }} Plätze belegt` ergänzen um:

```blade
@if($standbyCount > 0) <span class="text-amber-600">(+{{ $standbyCount }} Standby)</span> @endif
```

- [ ] **Step 7: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Livewire/Public/InterviewBooking.php src/Jobs/NotifyWaitlistForInterview.php src/Services/WaitlistRearmService.php resources/views/livewire/interview-schedule/index.blade.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(recruiting): zentrale Platz-Zaehlung (seatTaking) an allen Lese-Stellen + Standby-Anzeige"
```

---

### Task 4: Buchungs-Guards — zentrale Zählung + `rec_interviews`-Zeilensperre

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php:282-337` (`bookInterview`)
- Modify: `src/Livewire/InterviewBookings/Index.php` (`bookApplicant`, Capacity-Block ~`:248-262` + `updateOrCreate` ~`:272-286`)
- Modify: `src/Tools/CreateInterviewBookingTool.php:95-118`
- Modify: `src/Livewire/Applicant/Index.php:545-586` (Bulk-Schulungsbuchung)

**Interfaces:**
- Consumes: `seatTaking`, `takenSeatsCount()` (Task 2)
- Produces: alle vier Buchungs-Erzeugungspfade laufen in `DB::transaction` mit `RecInterview::lockForUpdate()`; jede `updateOrCreate`-Value-Liste enthält `'seat_released_at' => null` (Wiederbelebung alter Standby-/Storno-Zeilen)

- [ ] **Step 1: Public `bookInterview()`** — den Block ab dem Interview-Lookup (`:282`) bis inkl. `updateOrCreate` (`:324`) in eine Transaktion mit Lock umbauen. `use Illuminate\Support\Facades\DB;` ergänzen (falls nicht vorhanden). Ersetzen durch:

```php
        $interview = DB::transaction(function () use ($interviewId) {
            // Zeilensperre auf dem Termin serialisiert ALLE Buchungs-Erzeugungen
            // gegeneinander und gegen den Standby-Re-Claim (Phantom-Insert-sicher —
            // Row-Locks auf Buchungszeilen wuerden neue Inserts nicht stoppen).
            $interview = RecInterview::forTeam($this->teamId)
                ->active()
                ->where('starts_at', '>', now())
                ->whereIn('status', ['planned', 'confirmed'])
                ->lockForUpdate()
                ->find($interviewId);

            if (!$interview) {
                return null;
            }

            // Capacity check — zentrale Zaehlregel (Standby zaehlt nicht)
            if (!$interview->hasFreeSeat()) {
                return false;
            }

            // Status 'booked': Initial-Status fuer eine frische Buchung. Wird beim
            // Phase-3-Hook auf 'registered' hochgestuft. Cancelled-Felder und
            // seat_released_at werden explizit zurueckgesetzt fuer den Fall, dass
            // die Row via updateOrCreate auf einer alten cancelled-/Standby-Buchung
            // landet.
            RecInterviewBooking::updateOrCreate(
                [
                    'rec_interview_id' => $interviewId,
                    'rec_applicant_id' => $this->applicantId,
                ],
                [
                    'status'           => 'booked',
                    'booked_at'        => now(),
                    'team_id'          => $this->teamId,
                    'cancelled_by'     => null,
                    'cancelled_at'     => null,
                    'seat_released_at' => null,
                ],
            );

            return $interview;
        });

        if ($interview === null) {
            return;
        }
        if ($interview === false) {
            unset($this->visibleInterviews);
            return;
        }
```

Die nachfolgenden Zeilen (`maybeSwitchPosition`, Warteliste-fulfill, `unset`, `refreshTerminWording`, `state = 'booked'`) bleiben unverändert dahinter.

- [ ] **Step 2: HR `bookApplicant()`** (`InterviewBookings/Index.php`) — den Abschnitt vom Capacity-Check bis inkl. `updateOrCreate` ersetzen durch (Flash-Messages via Rückgabewert außerhalb der Transaktion; `use Illuminate\Support\Facades\DB;` ergänzen):

```php
        $error = DB::transaction(function () use ($interview) {
            $locked = RecInterview::query()->lockForUpdate()->find($this->interviewId);
            if (!$locked) {
                return 'Termin nicht gefunden.';
            }

            if (!$locked->hasFreeSeat()) {
                return 'Maximale Teilnehmerzahl erreicht!';
            }

            $existing = RecInterviewBooking::where('rec_applicant_id', $this->selectedApplicantId)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($existing) {
                return 'Dieser Kandidat ist bereits in einem Termin gebucht!';
            }

            RecInterviewBooking::updateOrCreate(
                [
                    'rec_interview_id' => $this->interviewId,
                    'rec_applicant_id' => $this->selectedApplicantId,
                ],
                [
                    'status'             => 'booked',
                    'notes'              => $this->bookingNotes ?: null,
                    'booked_at'          => now(),
                    'team_id'            => auth()->user()->currentTeam->id,
                    'created_by_user_id' => auth()->id(),
                    'cancelled_by'       => null,
                    'cancelled_at'       => null,
                    'seat_released_at'   => null,
                ],
            );

            return null;
        });

        if ($error !== null) {
            session()->flash('error', $error);
            return;
        }
```

- [ ] **Step 3: `CreateInterviewBookingTool`** — Max-Teilnehmer-Check + `updateOrCreate` (`:97-118`) ersetzen durch (`use Illuminate\Support\Facades\DB;` ergänzen):

```php
            $booking = DB::transaction(function () use ($interviewId, $applicantId, $arguments, $teamId, $context) {
                $locked = \Platform\Recruiting\Models\RecInterview::query()->lockForUpdate()->find($interviewId);

                if (!$locked) {
                    return 'not_found'; // Termin zwischen Lookup und Lock geloescht — NICHT durchfallen lassen
                }

                if (!$locked->hasFreeSeat()) {
                    return 'full';
                }

                return RecInterviewBooking::updateOrCreate(
                    [
                        'rec_interview_id' => $interviewId,
                        'rec_applicant_id' => $applicantId,
                    ],
                    [
                        'status' => $arguments['status'] ?? 'registered',
                        'notes' => $arguments['notes'] ?? null,
                        'booked_at' => now(),
                        'team_id' => $teamId,
                        'created_by_user_id' => $context->user?->id,
                        'cancelled_by' => null,
                        'cancelled_at' => null,
                        'seat_released_at' => null,
                    ],
                );
            });

            if ($booking === 'not_found') {
                return ToolResult::error('NOT_FOUND', 'Interview-Termin nicht gefunden.');
            }
            if ($booking === 'full') {
                return ToolResult::error('CAPACITY_REACHED', "Maximale Teilnehmerzahl ({$interview->max_participants}) bereits erreicht.");
            }
```

(Die `cancelled_*`-Resets sind ein Bugfix gleicher Klasse wie im Public-Pfad — `updateOrCreate` kann auf einer alten Storno-Zeile landen. `seat_released_at => null` ist bei explizitem `status`-Argument `booked` nötig, sonst erledigt es der saving-Guard.)

- [ ] **Step 4: Bulk-Schulungsbuchung** (`Applicant/Index.php:545-586`) — Capacity-Check (`$remaining`-Berechnung, `:550-563`) und die `create()`-Schleife in `DB::transaction` mit `lockForUpdate()` auf dem Interview wrappen; Zählung via `$interview->takenSeatsCount()`. Die `create()`-Zeilen selbst bleiben unverändert (immer frische Zeilen hinter Existenz-Guard).

- [ ] **Step 5: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Livewire/Public/InterviewBooking.php src/Livewire/InterviewBookings/Index.php src/Tools/CreateInterviewBookingTool.php src/Livewire/Applicant/Index.php
git commit -m "fix(recruiting): Buchungs-Guards mit rec_interviews-Zeilensperre + zentrale Zaehlung (Doppelbuchungs-Race)"
```

---

### Task 5: Release-Trigger im Auto-Pilot + `afterCommit` auf dem Job

**Files:**
- Modify: `src/Console/Commands/ProcessAutoPilotApplicants.php:230-237`
- Modify: `src/Jobs/NotifyWaitlistForInterview.php` (Klassen-Property)

**Interfaces:**
- Consumes: `SeatStandbyPolicy::shouldRelease()` (Task 1)
- Produces: Log-Typ `seat_released`; jeder `NotifyWaitlistForInterview`-Dispatch ist transaktionssicher

- [ ] **Step 1: Job transaktionssicher machen** — in `NotifyWaitlistForInterview` nach `public $timeout = 120;`:

```php
    /**
     * Dispatch erst nach DB-Commit ausfuehren — der Re-Claim/Storno-Pfad
     * dispatcht aus einer FOR-UPDATE-Transaktion heraus (via Observer).
     */
    public $afterCommit = true;
```

- [ ] **Step 2: Release-Trigger** — in `ProcessAutoPilotApplicants`, Max-Branch (`:231-237`), nach `$this->logAutoPilot(... 'max_reminders_reached' ...)` und vor `return;` einfügen:

```php
            $this->releaseSeats($applicant);
```

Neue private Methode (unterhalb von `processApplicant()`), Imports `RecInterviewBooking` + `SeatStandbyPolicy` + `NotifyWaitlistForInterview` ergänzen:

```php
    /**
     * Standby: Auto-Pilot hat aufgegeben — 'booked'-Buchungen geben ihren
     * Platz frei (seat_released_at), bleiben aber bestehen. Der frei
     * gewordene Platz wird sofort der Warteliste angeboten. Idempotent
     * (bereits released wird uebersprungen) — der Max-Branch kann nach
     * Inbound-State-Reset mehrfach feuern.
     */
    private function releaseSeats(RecApplicant $applicant): void
    {
        $bookings = RecInterviewBooking::where('rec_applicant_id', $applicant->id)
            ->where('status', 'booked')
            ->whereNull('seat_released_at')
            ->get();

        foreach ($bookings as $booking) {
            if (!SeatStandbyPolicy::shouldRelease($booking->status, $booking->seat_released_at !== null)) {
                continue;
            }
            $booking->seat_released_at = now();
            $booking->save();

            $this->logAutoPilot($applicant, 'seat_released', "Schulungsplatz freigegeben — keine Reaktion auf Erinnerungen (Buchung #{$booking->id}).", [
                'booking_id'   => $booking->id,
                'interview_id' => $booking->rec_interview_id,
                'source'       => 'auto_pilot',
            ]);
            NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
        }
    }
```

- [ ] **Step 3: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Console/Commands/ProcessAutoPilotApplicants.php src/Jobs/NotifyWaitlistForInterview.php
git commit -m "feat(recruiting): Standby-Release bei max_reminders_reached + afterCommit auf Waitlist-Job"
```

---

### Task 6: `returnToBookingPhase()` — expliziter Rücksprung mit Auto-Pilot-Reset

**Files:**
- Modify: `src/Models/RecApplicant.php` (neue Methode nach `advanceToNextPhase()`)

**Interfaces:**
- Produces: `RecApplicant::returnToBookingPhase(): bool` — setzt Phase auf die nächste frühere `completion_type='booking'`-Phase der gleichen Position zurück, resettet den kompletten Auto-Pilot-Zyklus (inkl. `auto_pilot_state_id = null` — KRITISCH, sonst bleibt der Bewerber wegen des `review_needed`-Ausschlusses in der Auto-Pilot-Query unsichtbar), loggt `phase_returned`

- [ ] **Step 1: Implementierung**

```php
    /**
     * Expliziter Ruecksprung in die Termin-Buchen-Phase — einziger Pfad,
     * der rueckwaerts durch die Phasen geht (fehlgeschlagener Standby-
     * Re-Claim: Termin ist inzwischen voll/vergangen).
     *
     * Spiegelt die Advance-Reset-Semantik (checkAutoPilotCompletion) PLUS
     * auto_pilot_state_id = null: der Bewerber steht in diesem Moment auf
     * review_needed, und die Auto-Pilot-Query schliesst review_needed aus —
     * ohne State-Reset bekaeme er nie das Termin-Template.
     */
    public function returnToBookingPhase(): bool
    {
        $current = $this->phase;
        if (!$current) {
            return false;
        }

        $target = RecPhase::where('rec_position_id', $current->rec_position_id)
            ->where('is_active', true)
            ->where('completion_type', 'booking')
            ->where('order', '<', $current->order)
            ->orderByDesc('order')
            ->first();

        if (!$target) {
            return false;
        }

        $this->rec_phase_id = $target->id;
        $this->auto_pilot_completed_at = null;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = null;
        $this->auto_pilot_state_id = null;
        $this->progress = 0;
        $this->clearExtraFieldDefinitionsCache();
        $this->save();

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'phase_returned',
                'summary' => "Zurück zu Phase \"{$target->name}\" — Schulungsplatz war nicht mehr verfügbar.",
                'details' => ['from_phase_id' => $current->id, 'to_phase_id' => $target->id],
            ]);
        } catch (\Throwable) {}

        return true;
    }
```

- [ ] **Step 2: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Models/RecApplicant.php
git commit -m "feat(recruiting): returnToBookingPhase — Ruecksprung mit vollem AutoPilot-Reset"
```

---

### Task 7: Pre-Advance-Guard (Re-Claim) + Hook auf Model-Saves

**Files:**
- Modify: `src/Models/RecApplicant.php` — `checkAutoPilotCompletion()` (:413-510), `triggerPhaseCompletionHooks()` (:585-599), neue Methode `guardSeatReclaim()`

**Interfaces:**
- Consumes: `SeatStandbyPolicy::reclaimOutcome()` (Task 1), `takenSeatsCount()` (Task 2), `returnToBookingPhase()` (Task 6)
- Produces: Konstanten `RecApplicant::RECLAIM_GUARD_OK|RETURNED|HR_CASE`; Log-Typen `seat_reclaimed`, `reclaim_failed`; Hook macht Model-Saves (Observer sehen das Upgrade)

- [ ] **Step 1: Guard-Methode** (nach `triggerPhaseCompletionHooks()`), Imports `DB`, `RecInterview`, `SeatStandbyPolicy` ergänzen:

```php
    public const RECLAIM_GUARD_OK = 'ok';
    public const RECLAIM_GUARD_RETURNED = 'returned';
    public const RECLAIM_GUARD_HR_CASE = 'hr_case';

    /**
     * Pre-Advance-Guard: Standby-Buchungen muessen ihren Platz zurueckholen,
     * BEVOR die Phase advanced (der Hook selbst feuert erst nach dem
     * persistierten Advance und kann nichts mehr verhindern).
     *
     * Ergebnis:
     *  - OK:       kein Standby oder Platz erfolgreich re-claimt → Advance normal
     *  - RETURNED: Termin voll/vergangen, Buchung storniert, Bewerber zurueck
     *              in der Buchen-Phase → Aufrufer bricht ab (kein Advance)
     *  - HR_CASE:  wie RETURNED, aber Auto-Pilot ist aus (Direkteinstellung) —
     *              Buchung bleibt Standby, HR entscheidet (Log reclaim_failed)
     */
    protected function guardSeatReclaim(?RecPhase $phase): string
    {
        $config = $phase?->completion_config ?? [];
        if (($config['confirm_booking_on_completion'] ?? false) !== true) {
            return self::RECLAIM_GUARD_OK;
        }

        $standbyBookings = $this->interviewBookings()
            ->where('status', 'booked')
            ->whereNotNull('seat_released_at')
            ->get();

        if ($standbyBookings->isEmpty()) {
            return self::RECLAIM_GUARD_OK;
        }

        $failedBookings = [];
        foreach ($standbyBookings as $booking) {
            $outcome = DB::transaction(function () use ($booking) {
                // Zeilensperre auf dem Termin — serialisiert gegen parallele
                // Buchungen (Task 4) und andere Re-Claims.
                // Kein Off-by-one: die eigene Standby-Buchung hat hier noch
                // seat_released_at != null und ist damit NICHT in
                // takenSeatsCount() enthalten (seatTaking = whereNull) —
                // bei taken == max-1 gelingt der Re-Claim korrekt.
                $interview = RecInterview::query()->lockForUpdate()->find($booking->rec_interview_id);

                $result = SeatStandbyPolicy::reclaimOutcome(
                    true,
                    $interview ? $interview->takenSeatsCount() : 0,
                    $interview?->max_participants,
                    (bool) $interview?->starts_at?->isFuture(),
                );

                if ($result === SeatStandbyPolicy::RECLAIM_OK) {
                    // Platz sofort IM Lock konsumieren — das Status-Upgrade
                    // auf 'registered' macht danach der Phase-Hook.
                    $booking->seat_released_at = null;
                    $booking->save();
                }

                return $result;
            });

            if ($outcome === SeatStandbyPolicy::RECLAIM_OK) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'seat_reclaimed',
                        'summary' => "Standby-Platz zurückgeholt (Buchung #{$booking->id}) — Onboarding abgeschlossen.",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id],
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            $failedBookings[] = $booking;
        }

        if ($failedBookings === []) {
            return self::RECLAIM_GUARD_OK;
        }

        // Teilerfolg (Multi-Standby ist via Tool-Zweitbuchung/HR-Status-Revival
        // erreichbar): mindestens ein Platz ist gesichert — der Bewerber wird
        // NICHT zurueckgeworfen. Gescheiterte Geschwister-Buchungen werden in
        // BEIDEN Auto-Pilot-Modi storniert, sonst wuerde der Hook den
        // Standby-Rest kapazitaetsfrei auf 'registered' heben.
        if (count($failedBookings) < $standbyBookings->count()) {
            foreach ($failedBookings as $booking) {
                $booking->status = 'cancelled';
                $booking->cancelled_by = 'system';
                $booking->cancelled_at = now();
                $booking->save();

                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'reclaim_failed',
                        'summary' => "Termin voll/vergangen (Buchung #{$booking->id}) — storniert, anderer Standby-Platz erfolgreich zurückgeholt.",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'sibling_cancelled'],
                    ]);
                } catch (\Throwable) {}
            }
            return self::RECLAIM_GUARD_OK;
        }

        if (!$this->auto_pilot) {
            // Direkteinstellung & Co.: keine Auto-Pilot-Kommunikation moeglich.
            // Buchung bleibt Standby, HR entscheidet (ueberbuchen/umbuchen).
            foreach ($failedBookings as $booking) {
                // Idempotenz: checkAutoPilotCompletion feuert bei JEDEM
                // Public-Form-Save erneut, und die Standby-Buchung bleibt
                // hier bestehen — ohne Guard entsteht pro Save ein neues
                // reclaim_failed-Log. Nur EIN Log pro Release-Fenster
                // (seit seat_released_at).
                $alreadyLogged = RecAutoPilotLog::where('rec_applicant_id', $this->id)
                    ->where('type', 'reclaim_failed')
                    ->when($booking->seat_released_at, fn ($q) => $q->where('created_at', '>=', $booking->seat_released_at))
                    ->exists();
                if ($alreadyLogged) {
                    continue;
                }
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'reclaim_failed',
                        'summary' => "Termin inzwischen voll/vergangen (Buchung #{$booking->id}) — HR-Entscheidung nötig (Auto-Pilot aus).",
                        'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'hr_case'],
                    ]);
                } catch (\Throwable) {}
            }
            return self::RECLAIM_GUARD_HR_CASE;
        }

        // Auto-Pilot-Flow: Buchung stornieren (Observer bietet den Platz ggf.
        // der Warteliste an — no-op bei vollem Termin) + Ruecksprung.
        foreach ($failedBookings as $booking) {
            $booking->status = 'cancelled';
            $booking->cancelled_by = 'system';
            $booking->cancelled_at = now();
            $booking->save(); // saving-Guard raeumt seat_released_at mit ab

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'reclaim_failed',
                    'summary' => "Termin inzwischen voll/vergangen (Buchung #{$booking->id}) — zurück zur Terminwahl.",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'mode' => 'returned'],
                ]);
            } catch (\Throwable) {}
        }

        return $this->returnToBookingPhase()
            ? self::RECLAIM_GUARD_RETURNED
            : self::RECLAIM_GUARD_HR_CASE;
    }
```

- [ ] **Step 2: Guard in `checkAutoPilotCompletion()` verdrahten**

Nicht-Auto-Pilot-Zweig (`:422-427`) ersetzen:

```php
        if (!$this->auto_pilot) {
            if ($this->rec_phase_id && $this->isPhaseComplete()) {
                if ($this->guardSeatReclaim($this->phase) !== self::RECLAIM_GUARD_OK) {
                    return; // HR-Fall geloggt — keine Hooks, kein Phantom-Upgrade
                }
                $this->triggerPhaseCompletionHooks($this->phase);
            }
            return;
        }
```

Auto-Pilot-Zweig — direkt nach `if (!$this->isPhaseComplete()) { return; }` (`:434-436`) einfügen (VOR dem CRM-Sync):

```php
        // Standby-Re-Claim MUSS vor dem Advance passieren — bei Fehlschlag
        // wurde der Bewerber bereits zurueck in die Buchen-Phase gesetzt.
        if ($this->guardSeatReclaim($this->phase) !== self::RECLAIM_GUARD_OK) {
            return;
        }
```

- [ ] **Step 3: Hook auf Model-Saves umstellen** — in `triggerPhaseCompletionHooks()` den Block `:585-599` ersetzen:

```php
        if (($config['confirm_booking_on_completion'] ?? false) === true) {
            // Per Model-Save statt Bulk-Update: Observer (Re-Arm bei "wieder
            // voll") sehen das Upgrade, und der saving-Guard raeumt
            // seat_released_at ab (Invariante, deckt auch den manuellen
            // HR-Advance via advanceToNextPhase ab = bewusste Uebersteuerung).
            $bookings = $this->interviewBookings()->where('status', 'booked')->get();
            foreach ($bookings as $booking) {
                $booking->status = 'registered';
                $booking->save();
            }

            if ($bookings->isNotEmpty()) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->id,
                        'type' => 'booking_confirmed',
                        'summary' => "Schulungs-Buchung registriert durch Abschluss von Phase \"{$completedPhase->name}\".",
                    ]);
                } catch (\Throwable) {}
            }
        }
```

- [ ] **Step 4: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Models/RecApplicant.php
git commit -m "feat(recruiting): Pre-Advance-Guard fuer Standby-Re-Claim + Hook-Upgrade per Model-Save"
```

---

### Task 8: t05-Reminder-Ausschluss, ReminderResponseHandler-Skip + HR-Override-Log

**Files:**
- Modify: `src/Console/Commands/SendInterviewReminders.php:45-49`
- Modify: `src/Services/ReminderResponseHandler.php:56-59`
- Modify: `src/Livewire/InterviewBookings/Index.php` (`updateStatus`, ~`:300-325`)
- Modify: `src/Tools/UpdateInterviewBookingTool.php` (Status-Update-Zweig)

**Interfaces:**
- Consumes: Spalte `seat_released_at`, Accessor `is_standby` (Task 2)
- Produces: Standby-Buchungen erhalten keine Teilnahme-Bestätigungsfrage und können nicht per "Ja"-Reply an der Kapazitätsprüfung vorbei auf `confirmed` springen; manuelles HR-Hochstufen einer Standby-Buchung wird als bewusste Übersteuerung geloggt (`seat_reclaimed_override`)

- [ ] **Step 1: Reminder-Empfänger filtern** (`SendInterviewReminders.php:45-49`):

```php
            $bookings = $interview->bookings()
                ->whereNull('reminder_sent_at')
                ->where('status', '!=', 'cancelled')
                // Standby hat keinen garantierten Platz — keine Teilnahme-
                // Bestaetigungsfrage; der Weg zurueck fuehrt uebers Onboarding
                // (kapazitaetsgeprüfter Re-Claim).
                ->whereNull('seat_released_at')
                ->with(['applicant.crmContactLinks.contact.phoneNumbers', 'applicant.legalStatus'])
                ->get();
```

- [ ] **Step 2: Response-Handler** — in `ReminderResponseHandler.php` an der Buchungs-Suche (`:59`, `->whereIn('status', ['booked', 'registered'])`) direkt danach ergänzen:

```php
            ->whereNull('seat_released_at')
```

(Alt-Fall: "Ja" auf einen VOR dem Release verschickten Reminder darf kein `confirmed` ohne Kapazitätsprüfung erzeugen; die Nachricht läuft dann als normaler Inbound weiter.)

- [ ] **Step 3: HR-Override-Log in `updateStatus()`** (`InterviewBookings/Index.php`) — vor dem `$booking->update($updates);` einfügen (Import `RecAutoPilotLog` ergänzen; der saving-Guard aus Task 2 löscht den Marker, hier kommt nur die Sichtbarkeit dazu):

```php
        // Standby-Buchung wird manuell hochgestuft = bewusste HR-Uebersteuerung
        // (kein Kapazitaetsblock, aber nachvollziehbar im AutoPilot-Log).
        if ($booking->is_standby && !in_array($status, ['booked', 'cancelled'], true)) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type' => 'seat_reclaimed_override',
                    'summary' => "Standby-Buchung #{$booking->id} manuell auf '{$status}' gesetzt — Platz bewusst konsumiert (HR).",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'status' => $status],
                ]);
            } catch (\Throwable) {}
        }
```

- [ ] **Step 4: Gleiches Log in `UpdateInterviewBookingTool`** — im Status-Update-Zweig vor dem Speichern denselben Block einfügen (identischer Code, `$status` entsprechend der dortigen Variablen, z. B. `$arguments['status']`).

- [ ] **Step 5: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Console/Commands/SendInterviewReminders.php src/Services/ReminderResponseHandler.php src/Livewire/InterviewBookings/Index.php src/Tools/UpdateInterviewBookingTool.php
git commit -m "fix(recruiting): Standby von t05-Reminder + Ja-Reply ausschliessen; HR-Override-Log"
```

---

### Task 9: Ablehnen/Parken storniert aktive Zukunfts-Buchungen

**Files:**
- Modify: `src/Observers/RecInterviewWaitlistObserver.php:84-100` (Applicant-saved-Hook)

**Interfaces:**
- Consumes: Observer-Storno-Pfad (bestehend) → `NotifyWaitlistForInterview` (jetzt `afterCommit`, Task 5)
- Produces: Bewerber, die aus dem Flow fallen, blockieren keine Plätze mehr

- [ ] **Step 1: Im `droppedOut`-Block** — nach dem bestehenden Warteliste-Storno (`:96-98`) ergänzen (Import `RecInterviewBooking` ist in der Datei vorhanden):

```php
                // Aktive Buchungen an ZUKUENFTIGEN Terminen mitstornieren —
                // der Booking-saved-Observer oben bietet den Platz dann der
                // Warteliste an. Vergangene Termine (attended/no_show-Historie)
                // bleiben unangetastet.
                RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                    ->whereIn('status', ['booked', 'registered', 'confirmed'])
                    ->whereHas('interview', fn ($q) => $q->where('starts_at', '>', now()))
                    ->get()
                    ->each(function (RecInterviewBooking $booking): void {
                        $booking->status = 'cancelled';
                        $booking->cancelled_by = 'system';
                        $booking->cancelled_at = now();
                        $booking->save();
                    });
```

- [ ] **Step 2: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Observers/RecInterviewWaitlistObserver.php
git commit -m "fix(recruiting): Ablehnen/Parken storniert aktive Zukunfts-Buchungen (Platz zurueck an Warteliste)"
```

---

### Task 10: Heal-/Backfill-Command `recruiting:release-stale-seats`

**Files:**
- Create: `src/Console/Commands/ReleaseStaleSeats.php`
- Modify: `src/RecruitingServiceProvider.php` (Command-Registrierung, Liste bei `:23-32`)

**Interfaces:**
- Consumes: `SeatStandbyPolicy::shouldRelease()` (Task 1), Log-Typ `seat_released` (Task 5)
- Produces: idempotenter Command für Alt-Fälle (`review_needed` vor Deploy, z. B. Bewerber #2388) und als wiederholbarer Heal

- [ ] **Step 1: Command**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Support\SeatStandbyPolicy;

/**
 * Backfill + Heal fuer das Standby-Modell: Der Live-Trigger (ProcessAutoPilot-
 * Applicants, max_reminders_reached) feuert nur beim UEBERGANG — Bewerber, die
 * schon vor dem Deploy auf review_needed standen, werden von der Auto-Pilot-
 * Query ausgeschlossen und durchlaufen ihn nie. Dieser Command gibt deren
 * Plaetze nachtraeglich frei. Idempotent (seat_released_at-Guard), beliebig
 * wiederholbar — deckt auch per MCP-Tool direkt gesetzte States ab.
 */
class ReleaseStaleSeats extends Command
{
    protected $signature = 'recruiting:release-stale-seats {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Gibt Schulungsplaetze aufgegebener Bewerber (review_needed, Buchung booked) als Standby frei.';

    public function handle(): int
    {
        $reviewNeededId = RecAutoPilotState::where('code', 'review_needed')->whereNull('team_id')->value('id');
        if (!$reviewNeededId) {
            $this->error('AutoPilot-State review_needed nicht gefunden.');
            return Command::FAILURE;
        }

        $bookings = RecInterviewBooking::query()
            ->where('status', 'booked')
            ->whereNull('seat_released_at')
            ->whereHas('applicant', fn ($q) => $q
                ->where('auto_pilot', true)
                ->where('auto_pilot_state_id', $reviewNeededId))
            ->whereHas('interview', fn ($q) => $q->where('starts_at', '>', now()))
            ->get();

        if ($this->option('dry-run')) {
            $this->info("Dry-Run: {$bookings->count()} Platz/Plaetze wuerden freigegeben.");
            return Command::SUCCESS;
        }

        $released = 0;
        foreach ($bookings as $booking) {
            if (!SeatStandbyPolicy::shouldRelease($booking->status, $booking->seat_released_at !== null)) {
                continue;
            }
            $booking->seat_released_at = now();
            $booking->save();
            $released++;

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type' => 'seat_released',
                    'summary' => "Schulungsplatz freigegeben (Heal-Command) — Buchung #{$booking->id}.",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'source' => 'heal'],
                ]);
            } catch (\Throwable) {}

            NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
        }

        $this->info("{$released} Platz/Plaetze freigegeben.");
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrieren** — in `src/RecruitingServiceProvider.php` in die Command-Liste (bei `SendInterviewReminders::class`) einfügen:

```php
                \Platform\Recruiting\Console\Commands\ReleaseStaleSeats::class,
```

- [ ] **Step 3: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Console/Commands/ReleaseStaleSeats.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): recruiting:release-stale-seats — Backfill/Heal fuer Alt-Faelle"
```

---

### Task 11: Public-Formular-Hinweis bei fehlgeschlagenem Re-Claim

**Files:**
- Modify: `src/Models/RecApplicant.php` — `renderPublicFormCompletionExtras()` (:667-679)

**Interfaces:**
- Consumes: Rücksprung-Zustand aus Task 7 (Bewerber in `completion_type='booking'`-Phase, keine aktive Buchung); Buchungsseiten-URL-Muster `url('/recruiting/interviews/' . $this->public_token)` (wie `Applicant/Show.php:167`)
- Produces: Statt stillem `null` sieht der Rückkehrer nach dem Form-Save eine Erklärung + Link zur Terminauswahl

- [ ] **Step 1: Branch einbauen** — in `renderPublicFormCompletionExtras()`, den Block

```php
        if (!$booking?->interview) {
            return null;
        }
```

ersetzen durch:

```php
        if (!$booking?->interview) {
            // Fehlgeschlagener Standby-Re-Claim: Bewerber wurde zurueck in die
            // Buchen-Phase gesetzt (Buchung storniert). Statt stillem null eine
            // Erklaerung + Link zur Terminauswahl rendern.
            $this->loadMissing('phase');
            $hasActiveBooking = $this->interviewBookings()
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($this->phase?->completion_type === 'booking' && !$hasActiveBooking) {
                $url = url('/recruiting/interviews/' . $this->public_token);
                $text = $this->usesInformalAddress()
                    ? 'Danke, deine Angaben sind vollständig! Dein ursprünglicher Schulungstermin ist leider inzwischen voll geworden — bitte wähle einen neuen Termin.'
                    : 'Danke, Ihre Angaben sind vollständig! Ihr ursprünglicher Schulungstermin ist leider inzwischen voll geworden — bitte wählen Sie einen neuen Termin.';

                return '<div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">'
                    . e($text)
                    . ' <a class="font-semibold underline" href="' . e($url) . '">Neuen Termin wählen</a>'
                    . '</div>';
            }

            return null;
        }
```

- [ ] **Step 2: `usesInformalAddress()`-Helper** — ZUERST prüfen, ob es schon einen gibt: `grep -rn "use_informal_address" src/`. Existiert ein Helper auf `RecApplicant`/Team/Settings, diesen verwenden und Step-1-Code entsprechend anpassen. Falls nicht, privaten Helper auf `RecApplicant` ergänzen — dabei die Quelle des Settings aus dem Grep-Treffer der Public-Views übernehmen (erwartet: Team-Settings-Kaskade):

```php
    /** Sie/du fuer Public-Texte — gleiche Quelle wie die Public-Views. */
    private function usesInformalAddress(): bool
    {
        return (bool) (RecApplicantSettings::getOrCreateForTeam($this->team_id)->use_informal_address ?? true);
    }
```

Box-Markup an die umliegenden Boxen derselben Methode angleichen (Klassen der bestehenden Bestätigungsbox übernehmen, Farbton amber statt grün).

- [ ] **Step 3: Regressionslauf + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → PASS

```bash
git add src/Models/RecApplicant.php
git commit -m "feat(recruiting): Public-Hinweis nach fehlgeschlagenem Standby-Re-Claim (Termin voll, neu waehlen)"
```

---

### Task 12: Gesamt-Regression + Abschluss

- [ ] **Step 1: Kompletter Testlauf**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, keine Failures/Errors

- [ ] **Step 2: Diff-Review gegen Spec**

`git diff origin/main --stat` — jede Datei muss einem Spec-Baustein zuordenbar sein; insbesondere prüfen: keine verbliebene `whereNotIn('status', ['cancelled'])`-Zählung an Platz-zählenden Stellen (`grep -rn "whereNotIn('status', \['cancelled'\])" src/ resources/` — verbleibende Treffer müssen Buchungs-Existenz-Checks sein: Phase-Completion, Ein-Buchungs-Guard, Dashboard-Funnel, `is_rebooked`, Reminder-Query in `SendInterviewReminders` mit `!=`).

- [ ] **Step 3: Nach Freigabe (nicht Teil dieses Plans, Workflow-Erinnerung)**

Finale Reihenfolge (Zwei-Stufen-Deploy, weil Forge NACH dem Code-Swap migriert —
der neue `seatTaking`-Scope würde sonst `seat_released_at` referenzieren, bevor
die Spalte existiert → 500er-Fenster auf der Public-Buchungsseite, die via
WhatsApp-Links rund um die Uhr Traffic hat):

1. **Push 1 — nur die Migration** (Spalte ist für Alt-Code unsichtbar/
   rückwärtskompatibel) + meingedeck-Bump + Deploy + `migrate`.
   Nach Push 1 läuft NUR die Migration — kein Command, nichts anderes
   (`release-stale-seats` braucht `seatTaking` + `SeatStandbyPolicy`, die erst
   mit Push 2 live sind).
2. **Push 2 — Rest des Branches** + meingedeck-Bump + Deploy + `migrate`
   (no-op, Sicherheitsnetz). ff-Merge auf `main`, kein PR/gh CLI.
3. **`queue:restart`** — Job-Klasse `NotifyWaitlistForInterview` geändert
   (`$afterCommit`); Worker müssen neuen Code laden, BEVOR der Heal-Command
   Notify-Jobs dispatcht.
4. **`php artisan recruiting:release-stale-seats --dry-run`** → Zahl prüfen
   (erwartet: ~8–12) → ohne `--dry-run` scharf ausführen.
5. **Live-Check:** Bewerber #2388 steht als "Standby" in Termin 39, der Platz
   ist wieder buchbar; erwartete WhatsApp-Welle: ~2 (Termin-Abos auf Termin 39).
