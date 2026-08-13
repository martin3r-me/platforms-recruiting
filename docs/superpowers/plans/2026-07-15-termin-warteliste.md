# Termin-Warteliste (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bewerber können sich auf der öffentlichen Buchungsseite für einzelne **ausgebuchte** Termine benachrichtigen lassen; wird dort ein Platz frei (Storno/Umbuchung eines anderen Bewerbers oder Kapazitätserhöhung), bekommen sie einmalig pro Scharfschaltung eine WhatsApp mit dem Buchungslink (Variante A: alle passenden Wartenden gleichzeitig, first come — Re-Arm-Muster wie bei der Ort-Warteliste).

**Architecture:** Additiv auf der bestehenden `rec_interview_waitlist`-Tabelle: nullable `rec_interview_id` (NULL = bestehende Ort-Warteliste, gesetzt = Termin-Warteliste; Bestand bleibt byte-unverändert). Der bestehende Notify-Job bekommt einen Termin-Zweig und einen 24h-Cutoff; ein neuer Observer-Hook auf `RecInterviewBooking` (Storno → Job dispatchen) schließt die heutige Trigger-Lücke — dafür werden die zwei Query-Builder-Storno-Pfade im Public-Form auf Model-Updates umgestellt, damit Events feuern. Volle Termine bleiben in der öffentlichen Auswahl sichtbar (Badge + Glocke). Ein stündlicher Cleanup-Command schließt Termin-Einträge zu abgesagten/vergangenen Terminen (sonst pausieren sie den Auto-Pilot für immer).

**Tech Stack:** PHP 8 / Laravel-Modul `platforms-recruiting`, Livewire 3, reines PHPUnit (Runner aus meingedeck-vendor), MySQL-Migration.

## Global Constraints

- Tests laufen OHNE Laravel/DB: nur `PHPUnit\Framework\TestCase`, keine Model-/Facade-Zugriffe im Test (Repo-Konvention). Migration/Job/Observer/Command sind daher NICHT unit-testbar — Gate ist dort `php -l` + volle Suite grün + Verifikations-Harness in Task 9.
- Test-Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` im Modul-Root `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`.
- Blade: KEINE inline `@php(...)`-Kurzform, immer Block-Form `@php ... @endphp`; Werte vorberechnen.
- Bestandsschutz: KEINE Änderung an bestehenden Wartelisten-Zeilen beim Deploy; alle neuen Spalten nullable; die Ort-Warteliste verhält sich identisch — mit EINER dokumentierten Ausnahme: der neue 24h-Cutoff gilt einheitlich (siehe Bewusste Entscheidungen).
- Kein Edit außerhalb von platforms-recruiting.
- Commit-Messages deutsch, conventional commits mit Scope `feat(recruiting): …`.
- Kern-Invariante Versand: pro Wartelisten-Zeile maximal 1 Benachrichtigung pro Scharfschaltung; der atomare `notified_at`-Claim und der Fehler-Rollback im Job bleiben unverändert bestehen (der Claim-Loop wird nur in eine private Methode extrahiert, nicht verändert).
- Nach Push: meingedeck `composer.lock` bumpen; nach Deploy `queue:restart` (der Notify-Job WIRD geändert — queued Jobs laufen sonst mit altem Code).

---

### Task 0: Branch anlegen

**Files:** keine (nur git)

- [ ] **Step 1: Fetch + Basis prüfen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git log --oneline -1 origin/main
```

- [ ] **Step 2: Branch von origin/main**

```bash
git checkout -b feature/termin-warteliste origin/main
```

---

### Task 1: Migration + Model — `rec_interview_id` an der Warteliste

**Files:**
- Create: `database/migrations/2026_07_15_000001_add_rec_interview_id_to_rec_interview_waitlist.php`
- Modify: `src/Models/RecInterviewWaitlist.php`

**Interfaces:**
- Consumes: bestehende Tabelle `rec_interview_waitlist`, Model `RecInterviewWaitlist`.
- Produces (spätere Tasks verlassen sich exakt darauf): Spalte `rec_interview_id` (nullable FK), Model-Relation `interview(): BelongsTo`, Scopes `scopeOrtBased($query)` (= `whereNull('rec_interview_id')`) und `scopeForInterview($query, int $interviewId)`.

- [ ] **Step 1: Migration schreiben**

Datei `database/migrations/2026_07_15_000001_add_rec_interview_id_to_rec_interview_waitlist.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            // NULL = Ort-Warteliste (Bestand, Verhalten unverändert).
            // Gesetzt = Termin-Warteliste: Bewerber wartet auf einen Platz
            // in genau diesem (vollen) Termin.
            $table->foreignId('rec_interview_id')
                ->nullable()
                ->after('rec_applicant_id')
                ->constrained('rec_interviews')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rec_interview_id');
        });
    }
};
```

- [ ] **Step 2: Model erweitern**

In `src/Models/RecInterviewWaitlist.php`:

(a) Im `$fillable`-Array nach `'rec_applicant_id',` einfügen:

```php
        'rec_interview_id',
```

(b) Nach der bestehenden `applicant()`-Relation einfügen:

```php
    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecInterview::class, 'rec_interview_id');
    }
```

(c) Nach `scopeOpen()` einfügen:

```php
    /**
     * Ort-Warteliste (Bestand): Einträge ohne Termin-Bezug.
     */
    public function scopeOrtBased($query)
    {
        return $query->whereNull('rec_interview_id');
    }

    /**
     * Termin-Warteliste: Einträge, die auf genau diesen Termin warten.
     */
    public function scopeForInterview($query, int $interviewId)
    {
        return $query->where('rec_interview_id', $interviewId);
    }
```

- [ ] **Step 3: Gate**

```bash
php -l database/migrations/2026_07_15_000001_add_rec_interview_id_to_rec_interview_waitlist.php
php -l src/Models/RecInterviewWaitlist.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: keine Syntaxfehler, Suite grün (160 Tests).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_15_000001_add_rec_interview_id_to_rec_interview_waitlist.php src/Models/RecInterviewWaitlist.php
git commit -m "feat(recruiting): rec_interview_id an Warteliste — Fundament Termin-Warteliste"
```

---

### Task 2: Planner — `planForInterview()` + Test-Follow-ups (TDD)

**Files:**
- Modify: `src/Services/WaitlistEnrollmentPlanner.php`
- Modify: `tests/Unit/WaitlistEnrollmentPlannerTest.php`

**Interfaces:**
- Consumes: bestehende Klasse aus dem Re-Arm-Feature.
- Produces: `WaitlistEnrollmentPlanner::planForInterview(?array $openEntry): array` — `$openEntry` ist `null` oder `['notified' => bool]`; Rückgabe `['action' => 'noop'|'create'|'rearm']`. Anders als `plan()` gibt es KEINEN Wunschorte-Guard: Termin-Einträge matchen über die Termin-ID, nicht über Orte.

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/WaitlistEnrollmentPlannerTest.php` am Ende der Klasse (vor der schließenden Klammer) einfügen:

```php
    // --- planForInterview: Termin-Warteliste (kein Orte-Guard) ---

    public function test_termin_kein_eintrag_ergibt_create(): void
    {
        $this->assertSame(
            ['action' => 'create'],
            WaitlistEnrollmentPlanner::planForInterview(null)
        );
    }

    public function test_termin_wartender_eintrag_ergibt_noop(): void
    {
        $this->assertSame(
            ['action' => 'noop'],
            WaitlistEnrollmentPlanner::planForInterview(['notified' => false])
        );
    }

    public function test_termin_benachrichtigter_eintrag_ergibt_rearm(): void
    {
        $this->assertSame(
            ['action' => 'rearm'],
            WaitlistEnrollmentPlanner::planForInterview(['notified' => true])
        );
    }

    // --- Follow-ups aus dem Re-Arm-Final-Review ---

    public function test_resolve_behaelt_falsy_skalare_die_nicht_leer_sind(): void
    {
        // 0/'0' sind keine leeren Werte — Verhalten byte-identisch zum
        // alten Inline-Code: sie bleiben erhalten, kein Fallback.
        $this->assertSame([0], WaitlistEnrollmentPlanner::resolveWunschorte(0, 'Köln'));
        $this->assertSame(['0'], WaitlistEnrollmentPlanner::resolveWunschorte('0', 'Köln'));
    }

    public function test_resolve_leerer_string_skalar_faellt_auf_fallback(): void
    {
        $this->assertSame(
            ['Düsseldorf'],
            WaitlistEnrollmentPlanner::resolveWunschorte('', 'Düsseldorf')
        );
    }
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/WaitlistEnrollmentPlannerTest.php
```

Erwartung: FAIL / Error `Call to undefined method ... planForInterview()`. (Die zwei resolve-Follow-ups sind bereits grün — das ist okay, sie dokumentieren Bestandsverhalten.)

- [ ] **Step 3: Implementierung**

In `src/Services/WaitlistEnrollmentPlanner.php` nach der `plan()`-Methode einfügen:

```php
    /**
     * Entscheidung für den Termin-Warteliste-Klick ("Benachrichtige mich,
     * wenn hier ein Platz frei wird"). Anders als plan() ohne Orte-Guard:
     * Termin-Einträge matchen über rec_interview_id, nicht über Wunschorte.
     *
     * @param array{notified: bool}|null $openEntry
     * @return array{action: 'noop'|'create'|'rearm'}
     */
    public static function planForInterview(?array $openEntry): array
    {
        if ($openEntry === null) {
            return ['action' => 'create'];
        }

        return $openEntry['notified']
            ? ['action' => 'rearm']
            : ['action' => 'noop'];
    }
```

- [ ] **Step 4: Tests grün + Gate**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/WaitlistEnrollmentPlannerTest.php
php -l src/Services/WaitlistEnrollmentPlanner.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: 14 Tests grün im Planner-File, Gesamt-Suite grün (165 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/WaitlistEnrollmentPlanner.php tests/Unit/WaitlistEnrollmentPlannerTest.php
git commit -m "feat(recruiting): planForInterview — Entscheidungslogik Termin-Warteliste + Test-Follow-ups"
```

---

### Task 3: Notify-Job — Termin-Zweig + 24h-Cutoff

**Files:**
- Modify: `src/Jobs/NotifyWaitlistForInterview.php`

**Interfaces:**
- Consumes: Scopes `ortBased()`/`forInterview()` aus Task 1.
- Produces: Der Job benachrichtigt (a) Termin-Wartende dieses Termins und (b) wie bisher Ort-Wartende — Ort-Query jetzt explizit `ortBased()`, damit Termin-Einträge nicht fälschlich über `whereJsonContains` mitmatchen. Neuer einheitlicher Cutoff `MIN_LEAD_HOURS = 24`. Der Claim/Send/Rollback-Loop ist UNVERÄNDERT in `notifyEntries()` extrahiert.

- [ ] **Step 1: Job umbauen**

`handle()` und der Loop werden wie folgt ersetzt (kompletter neuer Datei-Inhalt ab `handle()`; Use-Statements und Klassenkopf bleiben, `use Illuminate\Support\Collection;` kommt zu den Imports dazu):

```php
    /**
     * Keine Benachrichtigung mehr, wenn der Termin in weniger als
     * MIN_LEAD_HOURS beginnt — eine Push um 22 Uhr für eine Schulung am
     * nächsten Morgen bringt niemanden mehr in den Termin.
     */
    public const MIN_LEAD_HOURS = 24;

    public function handle(): void
    {
        $interview = RecInterview::with('position')->find($this->interviewId);
        if (!$interview || !$interview->is_active) {
            return;
        }
        if (!in_array($interview->status, ['planned', 'confirmed'], true)) {
            return;
        }
        if (!$interview->starts_at || $interview->starts_at->lt(now()->addHours(self::MIN_LEAD_HOURS))) {
            return;
        }

        // Kapazität: nur benachrichtigen, wenn wirklich noch Platz ist.
        if ($interview->max_participants) {
            $booked = RecInterviewBooking::where('rec_interview_id', $interview->id)
                ->whereNotIn('status', ['cancelled'])
                ->count();
            if ($booked >= $interview->max_participants) {
                return;
            }
        }

        // 1) Termin-Wartende: warten auf genau diesen Termin.
        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->forInterview($interview->id)
                ->with('applicant')
                ->get()
        );

        // 2) Ort-Wartende (Bestand): explizit ortBased(), damit Termin-
        //    Einträge nicht über ihren Wunschorte-Snapshot mitmatchen.
        $ort = $interview->position?->beschaftigungsort_lookup_value;
        if (empty($ort)) {
            return;
        }

        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->ortBased()
                ->whereJsonContains('wunschorte', $ort)
                ->with('applicant')
                ->get()
        );
    }

    private function notifyEntries(Collection $entries): void
    {
        $entries->each(function (RecInterviewWaitlist $entry) {
            // Atomarer Versand-Anspruch: nur wenn diese Zeile noch
            // notified_at IS NULL hat, gewinnt genau dieser Lauf.
            $claimed = RecInterviewWaitlist::where('id', $entry->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => now()]);

            if ($claimed !== 1) {
                return; // anderer Job war schneller
            }

            // Versand. Schlägt er fehl (z.B. Template/Account nicht
            // konfiguriert, transienter WA-Fehler), geben wir den
            // notified_at-Anspruch WIEDER FREI — sonst wäre der Bewerber
            // dauerhaft als "benachrichtigt" markiert ohne je eine
            // Nachricht erhalten zu haben, und ein späterer Termin würde
            // ihn wegen der "nur 1x"-Regel nie mehr erreichen.
            $applicant = $entry->applicant;
            $sent = $applicant && $applicant->is_active
                && $applicant->sendWaitlistAvailableNotification();

            if (!$sent) {
                // Nur der atomare Gewinner erreicht diesen Pfad, daher
                // ist ein Reset per ID konfliktfrei. fulfilled_at-Guard,
                // damit eine zwischenzeitliche Buchung nicht angefasst wird.
                RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('fulfilled_at')
                    ->update(['notified_at' => null]);
            }
        });
    }
```

Wichtig: Der Body von `notifyEntries()` ist der heutige `->each(...)`-Loop UNVERÄNDERT (Zeilen 67-96 alt) — nur extrahiert. Der alte Guard `$interview->starts_at->isPast()` geht im neuen `lt(now()->addHours(...))` auf (Vergangenheit ist immer < now+24h).

- [ ] **Step 2: Gate**

```bash
php -l src/Jobs/NotifyWaitlistForInterview.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 3: Commit**

```bash
git add src/Jobs/NotifyWaitlistForInterview.php
git commit -m "feat(recruiting): Notify-Job — Termin-Wartende + 24h-Cutoff, Ort-Zweig explizit ortBased"
```

---

### Task 4: Storno-Trigger — Model-Events + Observer-Hook

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php` (nur `cancelAndRebook()` und `cancelSchulung()`, Schritt 2)
- Modify: `src/Observers/RecInterviewWaitlistObserver.php`

**Interfaces:**
- Consumes: `NotifyWaitlistForInterview` (Job re-validiert Kapazität/Cutoff selbst — Über-Dispatch ist safe).
- Produces: JEDER Storno-Pfad (Public-Form-Absage, Umbuchung, WhatsApp-"Nein", HR-Statuswechsel, MCP-Tool) dispatcht den Notify-Job für den betroffenen Termin. Die drei Model-Update-Pfade (`ReminderResponseHandler.php:110`, `InterviewBookings/Index.php:291`, `UpdateInterviewBookingTool.php:84`) feuern Events schon heute; die zwei Query-Builder-Pfade im Public-Form werden auf Model-Updates umgestellt.

- [ ] **Step 1: `cancelAndRebook()` auf Model-Updates umstellen**

In `src/Livewire/Public/InterviewBooking.php` ersetzen:

```php
        // Cancel ALL non-cancelled bookings for this applicant (not just the first one)
        // cancelled_by='applicant' weil Bewerber aktiv umbucht (kein HR-Eingriff)
        RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->update([
                'status'        => 'cancelled',
                'cancelled_by'  => 'applicant',
                'cancelled_at'  => now(),
            ]);
```

durch:

```php
        // Cancel ALL non-cancelled bookings for this applicant (not just the first one)
        // cancelled_by='applicant' weil Bewerber aktiv umbucht (kein HR-Eingriff).
        // Model-Updates (kein Query-Builder), damit der Waitlist-Observer den
        // frei werdenden Platz mitbekommt.
        RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->get()
            ->each->update([
                'status'        => 'cancelled',
                'cancelled_by'  => 'applicant',
                'cancelled_at'  => now(),
            ]);
```

- [ ] **Step 2: `cancelSchulung()` auf Model-Updates umstellen**

Dort existiert die Collection `$activeBookings` bereits (wird für die HR-Notes geladen). Ersetzen:

```php
        // 2) Alle aktiven Buchungen cancellen mit Quellen-Info
        RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->update([
                'status'        => 'cancelled',
                'cancelled_by'  => 'applicant',
                'cancelled_at'  => now(),
            ]);
```

durch:

```php
        // 2) Alle aktiven Buchungen cancellen mit Quellen-Info — als
        //    Model-Updates, damit der Waitlist-Observer den frei werdenden
        //    Platz mitbekommt.
        $activeBookings->each->update([
            'status'        => 'cancelled',
            'cancelled_by'  => 'applicant',
            'cancelled_at'  => now(),
        ]);
```

- [ ] **Step 3: Observer-Hook ergänzen**

In `src/Observers/RecInterviewWaitlistObserver.php`:

(a) Import ergänzen (bei den Use-Statements):

```php
use Platform\Recruiting\Models\RecInterviewBooking;
```

(b) In `register()`, nach dem bestehenden `RecInterview::saved(...)`-Block einfügen:

```php
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                // Storno gibt ggf. einen Platz frei → Warteliste anstoßen.
                // Der Job re-validiert Kapazität/Status/Cutoff selbst;
                // Über-Dispatch ist dank notified_at-Claim safe.
                if (!$booking->wasChanged('status') || $booking->status !== 'cancelled') {
                    return;
                }
                if (!$booking->rec_interview_id) {
                    return;
                }
                NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
            }, 'rec_interview_booking.saved.waitlist', $booking->id);
        });
```

- [ ] **Step 4: Gate**

```bash
php -l src/Livewire/Public/InterviewBooking.php
php -l src/Observers/RecInterviewWaitlistObserver.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php src/Observers/RecInterviewWaitlistObserver.php
git commit -m "feat(recruiting): Storno-Trigger — Buchungs-Storno benachrichtigt Warteliste des Termins"
```

---

### Task 5: Public-Komponente — volle Termine sichtbar + `joinInterviewWaitlist()`

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php`

**Interfaces:**
- Consumes: `planForInterview()` (Task 2), Scopes (Task 1), `interviewWaitlistEntries` wird von Task 6 (Blade) konsumiert.
- Produces:
  - Computed `visibleInterviews(): array` — Nachfolger von `availableInterviews()`: identische Query, aber volle Termine werden NICHT mehr rausgefiltert (Zeit-/Status-/Aktiv-Filter unverändert). Nach diesem Task existiert der Name `availableInterviews` NIRGENDS mehr (Blade wird in Task 6 nachgezogen — dazwischen ist der Branch nicht lauffähig, deshalb Tasks 5+6 direkt nacheinander committen).
  - Computed `interviewWaitlistEntries(): array` — offene Termin-Einträge des Bewerbers, keyed by `rec_interview_id`.
  - `waitlistEntry()` liefert nur noch Ort-Einträge (`ortBased()`).
  - Action `joinInterviewWaitlist(int $interviewId): void`.

- [ ] **Step 1: `waitlistEntry()` auf Ort-Einträge einschränken**

Im Body von `waitlistEntry()` die Query ersetzen:

```php
        return RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->open()
            ->first();
```

durch:

```php
        // Nur Ort-Einträge: Termin-Einträge (rec_interview_id gesetzt)
        // haben eigene UI-Zustände an der Termin-Karte und dürfen die
        // Empty-Box nicht als "steht auf der Warteliste" erscheinen lassen.
        return RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->ortBased()
            ->open()
            ->first();
```

- [ ] **Step 2: `availableInterviews()` → `visibleInterviews()` (voller Filter entfällt)**

Die komplette Methode ersetzen durch:

```php
    #[Computed]
    public function visibleInterviews(): array
    {
        if (!$this->applicantId) {
            return [];
        }

        $applicant = RecApplicant::with('postings.position', 'phase')->find($this->applicantId);
        if (!$applicant) {
            return [];
        }

        $positionIds = $this->resolvePositionIdsForApplicant($applicant);

        if (empty($positionIds)) {
            return [];
        }

        // Volle Termine bleiben sichtbar (Badge "Ausgebucht" + Termin-
        // Warteliste-Glocke im Blade) — deshalb KEIN Kapazitäts-Filter
        // mehr. Zeit-/Status-/Aktiv-Filter unverändert: vergangene oder
        // abgesagte Termine sieht ein Bewerber weiterhin nie.
        return RecInterview::forTeam($this->teamId)
            ->with('position')
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->whereIn('rec_position_id', $positionIds)
            ->withCount(['bookings' => function ($query) {
                $query->whereNotIn('status', ['cancelled']);
            }])
            ->get()
            ->sortBy('starts_at')
            ->values()
            ->all();
    }
```

- [ ] **Step 3: Alle `availableInterviews`-Referenzen in der Komponente umbenennen**

Vier `unset(...)`-Stellen in `InterviewBooking.php` (in `bookInterview()` Kapazitäts-Abbruch, `bookInterview()` Erfolgs-Ende, `cancelAndRebook()`, `cancelSchulung()`): jeweils `$this->availableInterviews` → `$this->visibleInterviews`. Danach prüfen:

```bash
grep -rn "availableInterviews" src/
```

Erwartung: KEINE Treffer mehr in `src/` (das Blade folgt in Task 6).

- [ ] **Step 4: Computed `interviewWaitlistEntries()` einfügen** (nach `waitlistEntry()`):

```php
    /**
     * Offene Termin-Warteliste-Einträge des Bewerbers, keyed by
     * rec_interview_id — fürs Blade (Glocken-Zustand pro Termin-Karte).
     */
    #[Computed]
    public function interviewWaitlistEntries(): array
    {
        if (!$this->applicantId) {
            return [];
        }

        return RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->whereNotNull('rec_interview_id')
            ->open()
            ->get()
            ->keyBy('rec_interview_id')
            ->all();
    }
```

- [ ] **Step 5: `joinInterviewWaitlist()` einfügen** (nach `joinWaitlist()`):

```php
    public function joinInterviewWaitlist(int $interviewId): void
    {
        $applicant = RecApplicant::with(['phase', 'postings.position'])->find($this->applicantId);
        if (!$applicant || !$this->waitlistEnabled) {
            return;
        }

        // Gleiche Server-Validierung wie bookInterview(): Team, aktiv,
        // Zukunft, buchbarer Status. Zusätzlich: nur für VOLLE Termine —
        // ist noch Platz, soll der Bewerber buchen statt warten.
        $interview = RecInterview::forTeam($this->teamId)
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->find($interviewId);

        if (!$interview || !$interview->max_participants) {
            return;
        }

        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        if ($booked < $interview->max_participants) {
            unset($this->visibleInterviews);
            return;
        }

        $entry = $this->interviewWaitlistEntries[$interviewId] ?? null;
        $plan = WaitlistEnrollmentPlanner::planForInterview(
            $entry ? ['notified' => $entry->notified_at !== null] : null
        );

        if ($plan['action'] === 'create') {
            // Wunschorte-Snapshot nur als HR-Info — das Matching läuft
            // über rec_interview_id, deshalb ist auch [] okay.
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'rec_interview_id' => $interviewId,
                'team_id'          => $applicant->team_id,
                'wunschorte'       => WaitlistEnrollmentPlanner::resolveWunschorte(
                    $applicant->getExtraField('beschaftigungsort'),
                    $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
                ),
                'enrolled_at'      => now(),
            ]);
        } elseif ($plan['action'] === 'rearm') {
            $entry->update(['notified_at' => null]);
        }

        unset($this->interviewWaitlistEntries);
    }
```

- [ ] **Step 6: Cache-Busts ergänzen**

In `bookInterview()` (Erfolgs-Ende) und `cancelSchulung()` das jeweilige `unset(...)` um `$this->interviewWaitlistEntries` erweitern, z.B.:

```php
        unset($this->existingBooking, $this->visibleInterviews, $this->waitlistEntry, $this->interviewWaitlistEntries);
```

(Die `->open()->update(['fulfilled_at' => ...])`- bzw. `cancelled_at`-Updates in beiden Methoden erfassen Termin-Einträge bereits mit — `open()` ist typ-agnostisch. Das ist gewollt: wer bucht oder absagt, wartet nirgendwo mehr.)

- [ ] **Step 7: Gate**

```bash
php -l src/Livewire/Public/InterviewBooking.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php
git commit -m "feat(recruiting): volle Termine sichtbar + joinInterviewWaitlist (Server-Seite)"
```

---

### Task 6: Blade — Termin-Karte mit Ausgebucht-Badge + Glocke

**Files:**
- Modify: `resources/views/livewire/public/interview-booking.blade.php`

**Interfaces:**
- Consumes: `visibleInterviews`, `interviewWaitlistEntries`, `joinInterviewWaitlist` aus Task 5.
- Produces: reine View-Änderung. Die Empty-Box (Ort-Warteliste) bleibt UNVERÄNDERT — sie greift jetzt automatisch nur noch, wenn GAR KEINE zukünftigen Termine existieren (weil volle Termine die Liste nicht mehr leeren).

- [ ] **Step 1: Listen-Referenzen umbenennen**

Zeile 89: `@if(count($this->availableInterviews) > 0)` → `@if(count($this->visibleInterviews) > 0)`
Zeile 91: `collect($this->availableInterviews)` → `collect($this->visibleInterviews)`

- [ ] **Step 2: Plätze-Zeile — "Ausgebucht" statt "0 Plätze frei"**

Den Block ersetzen:

```blade
                                        {{-- Available spots --}}
                                        @if($interview->max_participants)
                                            @php $freeSpots = $interview->max_participants - $interview->bookings_count; @endphp
                                            <div class="flex items-center gap-2 text-sm {{ $freeSpots <= 2 ? 'text-amber-600' : 'text-gray-600' }}">
```

durch:

```blade
                                        {{-- Available spots --}}
                                        @if($interview->max_participants)
                                            @php
                                                $freeSpots = $interview->max_participants - $interview->bookings_count;
                                            @endphp
                                            <div class="flex items-center gap-2 text-sm {{ $freeSpots <= 2 ? 'text-amber-600' : 'text-gray-600' }}">
```

und darin die Anzeige-Zeile:

```blade
                                                <span>
                                                    {{ $freeSpots }} {{ $freeSpots === 1 ? 'Platz' : 'Plätze' }} frei
                                                </span>
```

durch:

```blade
                                                <span>
                                                    @if($freeSpots <= 0)
                                                        Ausgebucht
                                                    @else
                                                        {{ $freeSpots }} {{ $freeSpots === 1 ? 'Platz' : 'Plätze' }} frei
                                                    @endif
                                                </span>
```

- [ ] **Step 3: Button-Bereich der Termin-Karte — drei Varianten**

Den kompletten Button-Container ersetzen:

```blade
                                <div class="flex-shrink-0 pt-1">
                                    <button
                                        wire:click="bookInterview({{ $interview->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="bookInterview({{ $interview->id }})"
                                        class="applicant-btn-primary whitespace-nowrap"
                                    >
                                        <span wire:loading.remove wire:target="bookInterview({{ $interview->id }})">Buchen</span>
                                        <span wire:loading wire:target="bookInterview({{ $interview->id }})" class="inline-flex items-center gap-2">
                                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        </span>
                                    </button>
                                </div>
```

durch:

```blade
                                <div class="flex-shrink-0 pt-1">
                                    @php
                                        $isFull = $interview->max_participants
                                            && $interview->bookings_count >= $interview->max_participants;
                                        $terminEntry = $isFull ? ($this->interviewWaitlistEntries[$interview->id] ?? null) : null;
                                        $terminWaiting = $terminEntry && !$terminEntry->notified_at;
                                    @endphp
                                    @if(!$isFull)
                                        <button
                                            wire:click="bookInterview({{ $interview->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="bookInterview({{ $interview->id }})"
                                            class="applicant-btn-primary whitespace-nowrap"
                                        >
                                            <span wire:loading.remove wire:target="bookInterview({{ $interview->id }})">Buchen</span>
                                            <span wire:loading wire:target="bookInterview({{ $interview->id }})" class="inline-flex items-center gap-2">
                                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            </span>
                                        </button>
                                    @elseif($this->waitlistEnabled && $terminWaiting)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full bg-blue-50 text-blue-700 text-xs font-semibold whitespace-nowrap">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Wir melden uns bei einem freien Platz
                                        </span>
                                    @elseif($this->waitlistEnabled)
                                        <button
                                            wire:click="joinInterviewWaitlist({{ $interview->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="joinInterviewWaitlist({{ $interview->id }})"
                                            class="applicant-btn-primary whitespace-nowrap"
                                        >
                                            <span wire:loading.remove wire:target="joinInterviewWaitlist({{ $interview->id }})">Platz frei? Benachrichtige mich</span>
                                            <span wire:loading wire:target="joinInterviewWaitlist({{ $interview->id }})">Wird eingetragen…</span>
                                        </button>
                                    @else
                                        <span class="inline-flex items-center px-3 py-2 rounded-full bg-gray-100 text-gray-500 text-xs font-semibold whitespace-nowrap">
                                            Ausgebucht
                                        </span>
                                    @endif
                                </div>
```

(Der `$terminEntry && notified`-Fall — schon benachrichtigt, aber wieder voll — landet bewusst im zweiten `@elseif`: Button wieder sichtbar = Re-Arm per Klick, identisches Muster wie die Ort-Warteliste.)

- [ ] **Step 4: Gate**

```bash
grep -rn "availableInterviews" resources/ src/
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: grep KEINE Treffer; Suite grün. Zusätzlich Sichtprüfung: `@php` Block-Form, Direktiven balanciert.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/public/interview-booking.blade.php
git commit -m "feat(recruiting): Termin-Karte — Ausgebucht-Badge + Termin-Warteliste-Glocke"
```

---

### Task 7: Cleanup-Command — tote Termin-Einträge schließen

**Files:**
- Create: `src/Console/Commands/CleanupInterviewWaitlist.php`
- Modify: `src/RecruitingServiceProvider.php` (Command-Registrierung + Schedule)

**Interfaces:**
- Consumes: Scopes aus Task 1.
- Produces: Command `recruiting:cleanup-interview-waitlist`, stündlich geplant. Schließt offene TERMIN-Einträge (nie Ort-Einträge!), deren Termin abgesagt/inaktiv/vergangen ist — sonst pausieren sie den Auto-Pilot des Bewerbers dauerhaft (`ProcessAutoPilotApplicants` prüft nur `open()`).

- [ ] **Step 1: Command schreiben**

Datei `src/Console/Commands/CleanupInterviewWaitlist.php`:

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Schließt offene Termin-Warteliste-Einträge, deren Termin nicht mehr
 * stattfinden kann (abgesagt, deaktiviert oder in der Vergangenheit).
 *
 * Wichtig, weil offene Einträge den Auto-Pilot des Bewerbers pausieren
 * (ProcessAutoPilotApplicants) — ohne Cleanup würde ein Eintrag auf einen
 * abgesagten Termin den Bewerber dauerhaft stummschalten.
 *
 * Ort-Einträge (rec_interview_id NULL) werden NIE angefasst — die haben
 * keinen Termin-Bezug, der ablaufen könnte.
 */
class CleanupInterviewWaitlist extends Command
{
    protected $signature = 'recruiting:cleanup-interview-waitlist';

    protected $description = 'Schließt offene Termin-Warteliste-Einträge zu abgesagten/vergangenen Terminen';

    public function handle(): int
    {
        $closed = RecInterviewWaitlist::query()
            ->open()
            ->whereNotNull('rec_interview_id')
            ->whereHas('interview', function ($query) {
                $query->where(function ($query) {
                    $query->where('is_active', false)
                        ->orWhereNotIn('status', ['planned', 'confirmed'])
                        ->orWhere('starts_at', '<=', now());
                });
            })
            ->update(['cancelled_at' => now()]);

        $this->info("{$closed} Termin-Warteliste-Einträge geschlossen.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrieren + Schedulen**

In `src/RecruitingServiceProvider.php`:

(a) Im `$this->commands([...])`-Array ergänzen:

```php
                \Platform\Recruiting\Console\Commands\CleanupInterviewWaitlist::class,
```

(b) In `registerSchedule()` nach dem `flynk-reconcile`-Block ergänzen:

```php
        Schedule::command('recruiting:cleanup-interview-waitlist')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();
```

- [ ] **Step 3: Gate**

```bash
php -l src/Console/Commands/CleanupInterviewWaitlist.php
php -l src/RecruitingServiceProvider.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 4: Commit**

```bash
git add src/Console/Commands/CleanupInterviewWaitlist.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Cleanup-Command für tote Termin-Warteliste-Einträge (stündlich)"
```

---

### Task 8: HR-Sicht — Termin-Bezug anzeigen

**Files:**
- Modify: `src/Livewire/Waitlist/Index.php`
- Modify: `resources/views/livewire/waitlist/index.blade.php`
- Modify: `src/Tools/ListWaitlistTool.php`

**Interfaces:**
- Consumes: Relation `interview()` aus Task 1.
- Produces: HR-Liste und MCP-Tool zeigen bei Termin-Einträgen den Termin (Titel + Datum); der Orte-Zähler zählt nur noch Ort-Einträge (Termin-Wartende würden die Ort-Nachfrage verfälschen).

- [ ] **Step 1: `Waitlist/Index.php` anpassen**

(a) In `countsByOrt()` die Query-Zeile ersetzen:

```php
        RecInterviewWaitlist::forTeam($this->teamId())->open()->get()
```

durch:

```php
        // Nur Ort-Einträge: Termin-Wartende warten auf einen konkreten
        // Termin, nicht auf "irgendeinen Termin am Ort" — sie würden die
        // Ort-Nachfrage-Zähler verfälschen.
        RecInterviewWaitlist::forTeam($this->teamId())->open()->ortBased()->get()
```

(b) In `entries()` das Eager-Loading erweitern:

```php
            ->with('applicant')
```

durch:

```php
            ->with(['applicant', 'interview'])
```

- [ ] **Step 2: HR-Blade — Termin-Zeile ergänzen**

In `resources/views/livewire/waitlist/index.blade.php` nach der Wunschorte-Zeile (`Wunschorte: {{ implode(', ', $entry->wunschorte ?? []) }} · seit {{ $entry->enrolled_at?->format('d.m.Y') }}`) im selben Info-Container ergänzen:

```blade
                                @if($entry->rec_interview_id)
                                    <div class="text-xs text-[var(--ui-muted)]">
                                        Wartet auf Termin: {{ $entry->interview?->title ?? '#'.$entry->rec_interview_id }}
                                        @if($entry->interview?->starts_at)
                                            am {{ $entry->interview->starts_at->format('d.m.Y H:i') }}
                                        @endif
                                    </div>
                                @endif
```

(Exakte Einfüge-Stelle: direkt nach dem schließenden Element der "Wunschorte:"-Zeile, innerhalb desselben Eltern-`<div>`; CSS-Klassen an die umgebenden Zeilen angleichen, falls dort andere Muted-Klassen verwendet werden.)

- [ ] **Step 3: `ListWaitlistTool.php` erweitern**

(a) Eager-Loading:

```php
            $query = RecInterviewWaitlist::forTeam($teamId)
                ->with('applicant')
                ->orderByDesc('id');
```

durch:

```php
            $query = RecInterviewWaitlist::forTeam($teamId)
                ->with(['applicant', 'interview'])
                ->orderByDesc('id');
```

(b) Im `map()`-Array nach `'wunschorte' => $entry->wunschorte,` ergänzen:

```php
                    'rec_interview_id' => $entry->rec_interview_id,
                    'interview' => $entry->rec_interview_id ? [
                        'title'     => $entry->interview?->title,
                        'starts_at' => $entry->interview?->starts_at?->toIso8601String(),
                    ] : null,
                    'typ' => $entry->rec_interview_id ? 'termin' : 'ort',
```

(c) Die `getDescription()`-Beschreibung des Tools um einen Satz ergänzen (am Ende des bestehenden Strings): ` Einträge mit rec_interview_id sind Termin-Warteliste (warten auf einen konkreten vollen Termin), ohne = Ort-Warteliste.`

- [ ] **Step 4: Gate**

```bash
php -l src/Livewire/Waitlist/Index.php
php -l src/Tools/ListWaitlistTool.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Waitlist/Index.php resources/views/livewire/waitlist/index.blade.php src/Tools/ListWaitlistTool.php
git commit -m "feat(recruiting): HR-Warteliste zeigt Termin-Bezug; Orte-Zähler nur Ort-Einträge"
```

---

### Task 9: End-to-End-Verifikation + Auslieferung

**Files:** keine neuen (Verifikation + git)

- [ ] **Step 1: Verifikations-Harness** (lokale App erwartet NICHT lauffähig — keine .env; dann Harness wie beim Re-Arm-Feature, ehrlich berichten, was NICHT verifizierbar ist)

1. **Blade-Compile-Check** beider geänderter Views (`interview-booking.blade.php`, `waitlist/index.blade.php`): `BladeCompiler::compileString()` + `php -l` auf dem Kompilat.
2. **SQLite-:memory:-Smoke** mit echten Klassen (Planner + Model + Scopes): Tabelle inkl. neuer Spalte anlegen (Migration nachbauen), dann:
   - Termin-Eintrag create → Zeile mit `rec_interview_id`, `notified_at` NULL.
   - `planForInterview`-Zyklus: create → notified simulieren → rearm auf DERSELBEN Zeile (`enrolled_at` unverändert) → noop bei wartendem Eintrag.
   - Scope-Semantik: `ortBased()` findet den Ort-Eintrag, nicht den Termin-Eintrag; `forInterview(X)` genau umgekehrt; `open()` weiterhin typ-agnostisch (Buchung erfüllt beide).
   - Cleanup-Query-Logik: Termin-Eintrag zu vergangenem/abgesagtem/inaktivem Interview wird geschlossen; Ort-Eintrag und Eintrag zu zukünftigem aktivem Termin bleiben offen (Interview-Tabelle minimal nachbauen).
   - Job-Guard-Logik als Query-Nachbau: Ort-Query mit `ortBased()` matcht Termin-Einträge NICHT mehr über `whereJsonContains`.
3. **Volle Unit-Suite** final: erwartet 165 grün.
4. **git sanity:** erwartete Commits (Task 1-8) vorhanden, kein uncommitted Diff an tracked Files.

Explizit als "nicht lokal verifizierbar" berichten: Browser-Flow, visueller Render der Termin-Karten, WhatsApp-Versand, Livewire-Hydration, echtes Observer-Event-Feuern (nur logisch geprüft), Scheduler-Lauf des Cleanup-Commands.

- [ ] **Step 2: Trigger-Sicherheits-Verifikation VOR dem Push** (gegen den Code, nicht nur logisch — kann ein Punkt nicht sauber bestätigt werden: STOPP, zurück an den User):

1. Migration/Deploy dispatcht NICHTS: Branch-Diff enthält keinen Backfill und keinen Model-Touch an bestehenden Bookings/Interviews; die Migration ist reine DDL. Niemand, der auf der bestehenden Ort-Warteliste sitzt, wird durch das Deploy getriggert.
2. `grep -rn "NotifyWaitlistForInterview::dispatch" src/` → Treffer sind AUSSCHLIESSLICH die zwei Observer-Hooks (`RecInterview::saved`, `RecInterviewBooking::saved`) — beide rein reaktiv auf einen konkreten Termin. Kein Boot-, Deploy- oder Scheduler-Pfad iteriert über alle offenen Einträge. Der Cleanup-Command dispatcht nicht und fasst ausschließlich Termin-Einträge an (`whereNotNull('rec_interview_id')`).
3. Der 24h-Cutoff wirkt auf Bestands-Ort-Wartende ausschließlich als WENIGER Benachrichtigungen (Guard im Job, kein neuer Trigger) — gewollte Verhaltensänderung, kein Nebeneffekt.

- [ ] **Step 3: Push**

```bash
git push -u origin feature/termin-warteliste
```

DANACH STOPP — Merge/Bump/Deploy sind ein separater Durchlauf nach User-Freigabe.

- [ ] **Step 4: Nach User-Freigabe (separater Durchlauf, ff-Merge-Workflow, kein PR — gh fehlt):**

1. `git checkout main && git pull --ff-only && git merge --ff-only feature/termin-warteliste && git push origin main`
2. meingedeck bumpen: `composer update martin3r/platform-recruiting --no-scripts --no-install`, Lock-Referenz prüfen, committen als `chore(deps): bump platform-recruiting → Termin-Warteliste`, pushen.
3. **Nach Deploy: `queue:restart` auf Forge — PFLICHT**, der Notify-Job wurde geändert (queued Jobs laufen sonst mit altem Code). Migration läuft beim Deploy (`migrate`).

- [ ] **Step 5: Live-Smoke Observer-Pfad (NACH Forge-Deploy — in diesem Durchlauf NICHT abhakbar):** Auf einem vollen Termin mit mindestens einem Termin-Wartenden eine Buchung stornieren und prüfen, dass die WhatsApp beim Wartenden ankommt. Das ist der einzige End-to-End-Beweis für den Storno-Observer-/Event-Pfad.

- [ ] **Step 6: Meta-Template-Wording-Check (Pre-Go-Live, macht der User selbst — in diesem Durchlauf NICHT abhakbar):** Template `interview_waitlist_wa_template_id` liest sich generisch — nennt keinen Wunschort und kein Datum.

---

## Bewusste Entscheidungen (Review-relevant)

- **Variante A (User-Entscheidung):** Bei freiem Platz werden ALLE passenden Wartenden gleichzeitig einmalig benachrichtigt, first come beim Buchen. Kein FIFO, keine Reservierung — das wäre Variante B (verworfen für jetzt; hängt mit dem nicht existierenden 72h-Verfall zusammen).
- **24h-Cutoff gilt einheitlich** — auch für die bestehende Ort-Warteliste. Das ist eine bewusste Verhaltensänderung: bisher wurde bis unmittelbar vor Terminbeginn benachrichtigt. Konstante `MIN_LEAD_HOURS = 24`, nicht konfigurierbar (YAGNI).
- **Storno-Trigger über Model-Events:** Die zwei Query-Builder-Storno-Pfade im Public-Form werden auf Model-Updates umgestellt (N Einzel-Updates statt 1 Bulk — bei 1-2 aktiven Buchungen pro Bewerber irrelevant). Damit fängt EIN Observer-Hook alle fünf Storno-Pfade. Über-Dispatch ist safe (Job re-validiert alles).
- **`open()` bleibt typ-agnostisch:** Buchung erfüllt / Absage & Bewerber-Dropout schließen ALLE offenen Einträge (Ort + Termin). Wer bucht oder aussteigt, wartet nirgendwo mehr — gewollt.
- **Auto-Pilot-Pause gilt auch für Termin-Einträge** (ProcessAutoPilotApplicants prüft `open()` typ-agnostisch): Wer bewusst auf einen vollen Termin wartet, bekommt keine "bitte Termin wählen"-Reminder. Gewollt; der Cleanup-Command (Task 7) verhindert, dass tote Termin-Einträge die Pause endlos halten.
- **Ein Bewerber kann parallel** einen Ort-Eintrag UND mehrere Termin-Einträge haben (max. einer pro Termin via planForInterview-noop). Keine DB-Unique-Constraint — gleiche applikative Invariante wie bisher, Doppelklick-Race idempotent-unschädlich dank atomarem Versand-Claim.
- **Termin-Warteliste ist wie die Ort-Warteliste am Phasen-Flag `waitlist_enabled` gated** — kein neues Flag.
- **`availableInterviews` → `visibleInterviews` Rename:** Der alte Name lügt, sobald volle Termine mitkommen. Tasks 5+6 müssen direkt nacheinander laufen (zwischen den Commits referenziert das Blade kurz einen alten Namen — deshalb das grep-Gate in beiden Tasks).
- **Wunschorte-Snapshot auch an Termin-Einträgen** (nur HR-Info, `[]` erlaubt) — Matching läuft ausschließlich über `rec_interview_id`.
- **`UpdateWaitlistTool` bleibt unverändert:** `reset_notification`/`cancel` wirken auf alle offenen Einträge des Bewerbers — für den HR-Zweck (Fehlversand heilen / Bewerber rausnehmen) weiterhin richtig.
- **WhatsApp-Text bleibt generisch (V1-Einschränkung):** Das Meta-Template `interview_waitlist_wa_template_id` wird für Ort- UND Termin-Wartende wiederverwendet; der Parameter-Resolver in `sendBookingLinkWhatsApp()` füllt nur den Vornamen, die Nachricht kann den konkreten Termin NICHT nennen. Vor Go-Live prüfen, dass das Meta-Template-Wording generisch formuliert ist ("es ist ein Termin frei geworden"). Termin-spezifisches Template = Follow-up (bräuchte Parameter-Erweiterung im Versand-Kern).
- **`cancelSchulung()`-Snapshot-Semantik (Task 4):** `$activeBookings->each->update()` storniert den beim Laden eingesammelten Stand statt (wie der alte Bulk) das WHERE zum Update-Zeitpunkt neu zu evaluieren. Das Fenster ist Millisekunden innerhalb eines Requests und nur der Bewerber selbst könnte dazwischen buchen (durch hasActive-Guard verhindert) — akzeptiert.
