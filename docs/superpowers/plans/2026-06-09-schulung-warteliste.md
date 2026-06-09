# Schulung-Warteliste (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bewerber, die in Phase 2 buchen wollen aber für keinen ihrer Wunschorte einen freien Schulungstermin finden, tragen sich aktiv in eine Warteliste ein und werden automatisch per WhatsApp benachrichtigt, sobald ein passender Termin entsteht.

**Architecture:** Eigene Tabelle `rec_interview_waitlist` (eine offene Zeile pro Bewerber, Snapshot der Wunschorte). Eintrag über die öffentliche Buchungsseite (Livewire), wenn `availableInterviews()` leer ist und die Phase `waitlist_enabled=true` hat. Benachrichtigung event-driven: ein Observer auf `RecInterview::saved` dispatcht einen Queued Job, der passende Warter findet, den Versand-Anspruch atomar setzt (`notified_at`) und das bestehende WhatsApp-Template-Verfahren wiederverwendet. Aufräumen über `bookInterview` (→ `fulfilled_at`) und `RecApplicant::saved` (Reject/Park/Inaktiv → `cancelled_at`). HR sieht Zähler pro Ort + Liste.

**Tech Stack:** Laravel (Module `platforms-recruiting`), Livewire, MySQL (JSON-Spalte), Laravel Queue, WhatsApp via `WhatsAppMetaService`. Konventionen: UUID via `Symfony\Component\Uid\UuidV7`, `SoftDeletes`, Scopes `forTeam()/active()`, Settings in `RecApplicantSettings` (JSON). **Keine Modul-Tests** — Verifikation über manuelle Schritte + `php artisan tinker` im Host (meingedeck), wie bei den letzten Features.

---

## Wichtige Referenzen (bestehender Code)

- Buchungs-Logik: `src/Livewire/Public/InterviewBooking.php` — `availableInterviews()` (Z.82), `resolvePositionIdsForApplicant()` (Z.132), `bookInterview()` (Z.181), `maybeSwitchPosition()` (Z.256).
- WA-Versand + Template-Kaskade: `src/Models/RecApplicant.php` — `sendInterviewBookingNotification()` (Z.647), Template-Auflösung Position→Team (Z.652–658).
- Settings: `src/Models/RecApplicantSettings.php` — `DEFAULT_SETTINGS` (Z.21), `getSetting()` (Z.104), `getOrCreateForTeam()` (Z.96). UI: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (Z.365/372).
- Observer-Pattern: `src/Observers/RecApplicantExportObserver.php` (`register()` Z.92, `safelyRun()` Z.149), Registrierung in `src/RecruitingServiceProvider.php` (Z.133).
- Migrations-/Model-Konvention: `database/migrations/2026_04_14_000001_create_rec_interview_tables.php`, `src/Models/RecInterviewBooking.php`.
- Blade-Buchungsseite: `resources/views/livewire/public/interview-booking.blade.php` — Leer-State (Z.200–210), State-Switch `@elseif($state === 'booked')` (Z.234).

---

## File Structure

**Neu:**
- `database/migrations/2026_06_09_000001_create_rec_interview_waitlist_table.php` — Tabelle `rec_interview_waitlist`.
- `src/Models/RecInterviewWaitlist.php` — Eloquent-Model + Scopes.
- `src/Jobs/NotifyWaitlistForInterview.php` — Queued Job, benachrichtigt passende Warter zu einem Interview.
- `src/Observers/RecInterviewWaitlistObserver.php` — `RecInterview::saved` (Slot verfügbar → Job) + `RecApplicant::saved` (Reject/Park/Inaktiv → Waitlist canceln).
- `src/Livewire/Waitlist/Index.php` + `resources/views/livewire/waitlist/index.blade.php` — HR-Sicht (Zähler pro Ort + Liste).

**Geändert:**
- `src/Models/RecApplicantSettings.php` — neuer Default-Key `interview_waitlist_wa_template_id`.
- `src/Models/RecApplicant.php` — `sendInterviewBookingNotification()` zu wiederverwendbarem Kern refaktorieren + neue Methode `sendWaitlistAvailableNotification()`.
- `src/Livewire/Public/InterviewBooking.php` — `waitlistEntry`-Computed, `joinWaitlist()`, `waitlisted`-State, `bookInterview()` setzt `fulfilled_at`.
- `resources/views/livewire/public/interview-booking.blade.php` — Button im Leer-State + `waitlisted`-State.
- `resources/views/livewire/applicant/applicant-settings-modal.blade.php` + `resources/views/livewire/position/show.blade.php` — Template-Auswahl-Feld.
- `src/RecruitingServiceProvider.php` — Observer + Livewire-Komponente + Route registrieren.
- `routes/web.php` (oder die Stelle, wo Recruiting-Routen geladen werden) — Route für die HR-Warteliste.

---

## Task 1: Migration `rec_interview_waitlist`

**Files:**
- Create: `database/migrations/2026_06_09_000001_create_rec_interview_waitlist_table.php`

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
        Schema::create('rec_interview_waitlist', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            // Snapshot der bestätigten beschaftigungsort-Werte beim Eintrag.
            $table->json('wunschorte');
            $table->dateTime('enrolled_at')->useCurrent();
            // notified_at: "nur 1x"-Guard. NULL = noch nie benachrichtigt.
            $table->dateTime('notified_at')->nullable();
            // fulfilled_at: Bewerber hat gebucht. cancelled_at: Reject/Park/Abmeldung.
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Eine "offene" Zeile pro Bewerber wird im Model/Code geführt
            // (fulfilled_at & cancelled_at = NULL). Index unterstützt das Finden.
            $table->index(['team_id', 'fulfilled_at', 'cancelled_at']);
            $table->index('rec_applicant_id');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_interview_waitlist');
    }
};
```

- [ ] **Step 2: Migration prüfen (im Host meingedeck)**

Run: `php artisan migrate --pretend` (zeigt das SQL ohne auszuführen) bzw. `php artisan migrate` in der Host-App.
Expected: Tabelle `rec_interview_waitlist` wird erzeugt, keine Fehler.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_09_000001_create_rec_interview_waitlist_table.php
git commit -m "feat(waitlist): Migration rec_interview_waitlist"
```

---

## Task 2: Model `RecInterviewWaitlist`

**Files:**
- Create: `src/Models/RecInterviewWaitlist.php`

- [ ] **Step 1: Model anlegen**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

class RecInterviewWaitlist extends Model
{
    use SoftDeletes;

    protected $table = 'rec_interview_waitlist';

    protected $fillable = [
        'uuid',
        'rec_applicant_id',
        'wunschorte',
        'enrolled_at',
        'notified_at',
        'fulfilled_at',
        'cancelled_at',
        'team_id',
        'created_by_user_id',
        'owned_by_user_id',
    ];

    protected $casts = [
        'wunschorte'   => 'array',
        'enrolled_at'  => 'datetime',
        'notified_at'  => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Offene Warteliste-Einträge: weder gebucht noch storniert.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('fulfilled_at')->whereNull('cancelled_at');
    }
}
```

- [ ] **Step 2: Sanity-Check (im Host, tinker)**

Run: `php artisan tinker`
```php
\Platform\Recruiting\Models\RecInterviewWaitlist::query()->open()->count();
```
Expected: `0` (Tabelle leer, Query baut korrekt, kein SQL-Fehler).

- [ ] **Step 3: Commit**

```bash
git add src/Models/RecInterviewWaitlist.php
git commit -m "feat(waitlist): RecInterviewWaitlist Model"
```

---

## Task 3: Settings-Key `interview_waitlist_wa_template_id`

**Files:**
- Modify: `src/Models/RecApplicantSettings.php:36`
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php:~365`
- Modify: `resources/views/livewire/position/show.blade.php` (Position-Override)

- [ ] **Step 1: Default-Key ergänzen**

In `src/Models/RecApplicantSettings.php`, direkt nach Zeile 36 (`'interview_booking_wa_template_id' => null,`) einfügen:

```php
        'interview_waitlist_wa_template_id' => null,
```

- [ ] **Step 2: Auswahl-Feld im Settings-Modal ergänzen**

In `resources/views/livewire/applicant/applicant-settings-modal.blade.php` den vorhandenen Block für `interview_booking_wa_template_id` (um Zeile 360–375) als Vorlage nehmen und direkt darunter einen identisch aufgebauten Block für `interview_waitlist_wa_template_id` einfügen. Label: „WhatsApp-Template: Termin frei geworden (Warteliste)". Die `name`/`wire:model`-Attribute müssen `settings.interview_waitlist_wa_template_id` lauten; die Template-Optionen-Schleife unverändert übernehmen.

> Konkret: den kompletten `<div>…</div>` des Booking-Template-Felds kopieren, beide Vorkommen von `interview_booking_wa_template_id` durch `interview_waitlist_wa_template_id` ersetzen und den Label-Text anpassen.

- [ ] **Step 3: Position-Override ergänzen**

In `resources/views/livewire/position/show.blade.php` analog: dort wo `interview_booking_wa_template_id` als Position-AutoPilot-Override gepflegt wird, denselben Feld-Block für `interview_waitlist_wa_template_id` ergänzen (Override-Logik schreibt nach `position.auto_pilot_settings`). Falls dort kein Booking-Template-Override existiert, diesen Step überspringen — der Team-Default reicht (Kaskade fällt zurück).

- [ ] **Step 4: Verifikation (im Host, UI)**

Settings-Modal im Bewerber-Bereich öffnen → AutoPilot-Tab. Neues Feld „Termin frei geworden (Warteliste)" ist sichtbar, lässt sich auf ein Template setzen und speichern. Erneut öffnen → Wert bleibt erhalten.

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicantSettings.php resources/views/livewire/applicant/applicant-settings-modal.blade.php resources/views/livewire/position/show.blade.php
git commit -m "feat(waitlist): Settings-Key interview_waitlist_wa_template_id + UI"
```

---

## Task 4: WA-Versand refaktorieren + `sendWaitlistAvailableNotification()`

Ziel: Der bestehende `sendInterviewBookingNotification()` (RecApplicant.php:647) löst hart `interview_booking_wa_template_id` auf. Wir ziehen den Versand-Kern in eine private Methode, die den Settings-Key als Parameter nimmt, und bauen darauf zwei dünne öffentliche Methoden auf. DRY — kein duplizierter 160-Zeilen-Block.

**Files:**
- Modify: `src/Models/RecApplicant.php:647-813`

- [ ] **Step 1: Versand-Kern extrahieren**

`sendInterviewBookingNotification()` (Z.647–813) so umbauen, dass der gesamte Body in eine neue private Methode `sendBookingLinkWhatsApp(string $templateSettingKey, string $logType, string $logSummary): bool` wandert. Die einzigen Änderungen im verschobenen Body:

- Zeile 657–658 ersetzen:
  ```php
  $templateId = $positionSettings[$templateSettingKey]
      ?? $teamSettings->getSetting($templateSettingKey);
  ```
- Den AutoPilotLog-Block (Z.795–799) ersetzen durch:
  ```php
  RecAutoPilotLog::create([
      'rec_applicant_id' => $this->id,
      'type' => $logType,
      'summary' => $logSummary,
  ]);
  ```

- [ ] **Step 2: Öffentliche Methoden als dünne Wrapper**

`sendInterviewBookingNotification()` wird zu:

```php
public function sendInterviewBookingNotification(): bool
{
    return $this->sendBookingLinkWhatsApp(
        'interview_booking_wa_template_id',
        'interview_booking_sent',
        'Interview-Buchungslink per WhatsApp gesendet.'
    );
}
```

Neue Methode direkt darunter:

```php
/**
 * Schickt den Buchungslink erneut, wenn ein Schulungstermin frei
 * geworden ist (Warteliste). Nutzt ein eigenes Template
 * (interview_waitlist_wa_template_id) mit anderem Wording, aber
 * denselben Link-Token wie der reguläre Buchungs-Versand.
 */
public function sendWaitlistAvailableNotification(): bool
{
    return $this->sendBookingLinkWhatsApp(
        'interview_waitlist_wa_template_id',
        'waitlist_slot_available_sent',
        'Warteliste: Benachrichtigung "Termin frei geworden" per WhatsApp gesendet.'
    );
}
```

- [ ] **Step 3: Verifikation (im Host, tinker)**

Run: `php artisan tinker`
```php
$a = \Platform\Recruiting\Models\RecApplicant::find(<ID eines Test-Bewerbers>);
$a->sendInterviewBookingNotification(); // muss sich exakt wie vorher verhalten
```
Expected: `true`/`false` wie zuvor (kein Verhaltensbruch beim bestehenden Versand). Bei `false` Settings prüfen (Template/Account gesetzt?).

- [ ] **Step 4: Commit**

```bash
git add src/Models/RecApplicant.php
git commit -m "refactor(waitlist): WA-Versand-Kern extrahieren + sendWaitlistAvailableNotification"
```

---

## Task 5: Queued Job `NotifyWaitlistForInterview`

**Files:**
- Create: `src/Jobs/NotifyWaitlistForInterview.php`

- [ ] **Step 1: Job anlegen**

```php
<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Benachrichtigt wartende Bewerber, dass für einen ihrer Wunschorte ein
 * Schulungstermin frei geworden ist. Wird vom RecInterviewWaitlistObserver
 * dispatcht, sobald ein RecInterview in einen verfügbaren Zustand übergeht.
 *
 * "Nur 1x"-Regel: pro Warteliste-Zeile wird der Versand-Anspruch atomar
 * über notified_at gesetzt. Nur wer den Anspruch gewinnt (1 affected row),
 * bekommt die Nachricht — auch bei parallelen Jobs/Workern wasserdicht.
 */
class NotifyWaitlistForInterview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    public function __construct(private int $interviewId) {}

    public function handle(): void
    {
        $interview = RecInterview::with('position')->find($this->interviewId);
        if (!$interview || !$interview->is_active) {
            return;
        }
        if (!in_array($interview->status, ['planned', 'confirmed'], true)) {
            return;
        }
        if (!$interview->starts_at || $interview->starts_at->isPast()) {
            return;
        }

        // Kapazität: nur benachrichtigen, wenn wirklich noch Platz ist.
        if ($interview->max_participants) {
            $booked = \Platform\Recruiting\Models\RecInterviewBooking::where('rec_interview_id', $interview->id)
                ->whereNotIn('status', ['cancelled'])
                ->count();
            if ($booked >= $interview->max_participants) {
                return;
            }
        }

        $ort = $interview->position?->beschaftigungsort_lookup_value;
        if (empty($ort)) {
            return;
        }

        RecInterviewWaitlist::query()
            ->forTeam($interview->team_id)
            ->open()
            ->whereNull('notified_at')
            ->whereJsonContains('wunschorte', $ort)
            ->with('applicant')
            ->get()
            ->each(function (RecInterviewWaitlist $entry) {
                // Atomarer Versand-Anspruch: nur wenn diese Zeile noch
                // notified_at IS NULL hat, gewinnt genau dieser Lauf.
                $claimed = RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('notified_at')
                    ->update(['notified_at' => DB::raw('NOW()')]);

                if ($claimed !== 1) {
                    return; // anderer Job war schneller
                }

                $applicant = $entry->applicant;
                if ($applicant && $applicant->is_active) {
                    $applicant->sendWaitlistAvailableNotification();
                }
            });
    }
}
```

- [ ] **Step 2: Verifikation (im Host, tinker)**

Run: `php artisan tinker`
```php
// Test-Warteliste-Zeile anlegen (Bewerber mit Wunschort 'koeln'):
\Platform\Recruiting\Models\RecInterviewWaitlist::create([
    'rec_applicant_id' => <ID>, 'team_id' => <TEAM>,
    'wunschorte' => ['koeln'], 'enrolled_at' => now(),
]);
// Job synchron ausführen für ein Köln-Interview:
(new \Platform\Recruiting\Jobs\NotifyWaitlistForInterview(<INTERVIEW_ID_KOELN>))->handle();
// Prüfen: notified_at gesetzt?
\Platform\Recruiting\Models\RecInterviewWaitlist::latest('id')->first()->notified_at;
```
Expected: `notified_at` ist gesetzt; ein zweiter `handle()`-Lauf setzt nichts erneut und sendet nicht erneut.

- [ ] **Step 3: Commit**

```bash
git add src/Jobs/NotifyWaitlistForInterview.php
git commit -m "feat(waitlist): NotifyWaitlistForInterview Queued Job"
```

---

## Task 6: Observer `RecInterviewWaitlistObserver`

**Files:**
- Create: `src/Observers/RecInterviewWaitlistObserver.php`
- Modify: `src/RecruitingServiceProvider.php:134`

- [ ] **Step 1: Observer anlegen**

```php
<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Verdrahtet die Schulung-Warteliste mit dem Lebenszyklus:
 *  - RecInterview wird verfügbar  → Job benachrichtigt passende Warter
 *  - Bewerber rejected/parked/inaktiv → offene Warteliste-Zeile canceln
 *
 * Alle Bodies in safelyRun() — ein Bug hier darf nie einen regulären
 * Save kaputt machen (gleiches Prinzip wie RecApplicantExportObserver).
 */
class RecInterviewWaitlistObserver
{
    public static function register(): void
    {
        RecInterview::saved(static function (RecInterview $interview): void {
            self::safelyRun(function () use ($interview): void {
                // Nur dispatchen, wenn der Slot gerade verfügbar IST und
                // entweder neu angelegt wurde oder ein verfügbarkeits-
                // relevantes Feld sich geändert hat. Der Job re-validiert
                // alles selbst — Über-Dispatch ist dank notified_at safe.
                $isAvailable = $interview->is_active
                    && in_array($interview->status, ['planned', 'confirmed'], true)
                    && $interview->starts_at
                    && $interview->starts_at->isFuture();

                if (!$isAvailable) {
                    return;
                }

                $relevantChange = $interview->wasRecentlyCreated
                    || $interview->wasChanged(['is_active', 'status', 'starts_at', 'max_participants']);

                if (!$relevantChange) {
                    return;
                }

                NotifyWaitlistForInterview::dispatch($interview->id);
            }, 'rec_interview.saved.waitlist', $interview->id);
        });

        RecApplicant::saved(static function (RecApplicant $applicant): void {
            self::safelyRun(function () use ($applicant): void {
                // Bewerber fällt aus dem Flow → offene Warteliste-Zeile
                // canceln, damit Zähler/Liste sauber bleiben.
                $droppedOut = ($applicant->wasChanged('rejected_at') && $applicant->rejected_at)
                    || ($applicant->wasChanged('is_parked') && $applicant->is_parked)
                    || ($applicant->wasChanged('is_active') && !$applicant->is_active);

                if (!$droppedOut) {
                    return;
                }

                RecInterviewWaitlist::where('rec_applicant_id', $applicant->id)
                    ->open()
                    ->update(['cancelled_at' => now()]);
            }, 'rec_applicant.saved.waitlist', $applicant->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Waitlist-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Observer registrieren**

In `src/RecruitingServiceProvider.php` direkt nach Zeile 134 (`RecEmployeeExportObserver::register();`) einfügen:

```php
        \Platform\Recruiting\Observers\RecInterviewWaitlistObserver::register();
```

- [ ] **Step 3: Verifikation (im Host, tinker)**

Run: `php artisan tinker`
```php
// Warteliste-Zeile für Köln-Bewerber anlegen, notified_at NULL.
// Neuen Köln-Termin anlegen oder reaktivieren → Observer dispatcht Job.
// Bei QUEUE_CONNECTION=sync läuft der Job sofort:
$i = \Platform\Recruiting\Models\RecInterview::find(<KOELN_INTERVIEW_ID>);
$i->is_active = false; $i->save();
$i->is_active = true;  $i->save(); // Übergang in verfügbar → Job feuert
// notified_at der offenen Köln-Zeile sollte jetzt gesetzt sein.
```
Expected: Offene Köln-Warteliste-Zeile bekommt `notified_at`. Reject eines wartenden Bewerbers → seine offene Zeile bekommt `cancelled_at`.

- [ ] **Step 4: Commit**

```bash
git add src/Observers/RecInterviewWaitlistObserver.php src/RecruitingServiceProvider.php
git commit -m "feat(waitlist): Observer verdrahtet Slot-verfuegbar + Bewerber-Dropout"
```

---

## Task 7: Eintrag in die Warteliste (Bewerber-Seite)

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php`
- Modify: `resources/views/livewire/public/interview-booking.blade.php`

- [ ] **Step 1: Computed `waitlistEntry` + `waitlistEnabled` hinzufügen**

In `src/Livewire/Public/InterviewBooking.php` nach dem `existingBooking`-Computed (Z.79) einfügen:

```php
    #[Computed]
    public function waitlistEnabled(): bool
    {
        if (!$this->applicantId) {
            return false;
        }
        $applicant = RecApplicant::with('phase')->find($this->applicantId);
        $config = $applicant?->phase?->completion_config ?? [];
        return ($config['waitlist_enabled'] ?? false) === true;
    }

    #[Computed]
    public function waitlistEntry(): ?\Platform\Recruiting\Models\RecInterviewWaitlist
    {
        if (!$this->applicantId) {
            return null;
        }
        return \Platform\Recruiting\Models\RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->open()
            ->first();
    }
```

- [ ] **Step 2: `mount()` um waitlisted-State erweitern**

In `mount()` den Block ab Zeile 61 ersetzen:

```php
        if ($this->existingBooking) {
            $this->state = 'booked';
        } elseif ($this->waitlistEntry) {
            $this->state = 'waitlisted';
        } else {
            $this->state = 'selection';
        }
```

- [ ] **Step 3: `joinWaitlist()` Action hinzufügen**

Neue Methode (z.B. nach `bookInterview()`):

```php
    public function joinWaitlist(): void
    {
        $applicant = RecApplicant::with('phase')->find($this->applicantId);
        if (!$applicant || !$this->waitlistEnabled) {
            return;
        }

        // Schon eingetragen? Dann nur State setzen (idempotent).
        if ($this->waitlistEntry) {
            $this->state = 'waitlisted';
            return;
        }

        // Snapshot der bestätigten Wunschorte — gleiche Quelle wie
        // resolvePositionIdsForApplicant() (beschaftigungsort-Extra-Field).
        $wunschOrte = $applicant->getExtraField('beschaftigungsort') ?? [];
        if (!is_array($wunschOrte)) {
            $wunschOrte = [$wunschOrte];
        }
        $wunschOrte = array_values(array_filter($wunschOrte, fn ($v) => $v !== null && $v !== ''));

        \Platform\Recruiting\Models\RecInterviewWaitlist::create([
            'rec_applicant_id' => $applicant->id,
            'team_id'          => $this->teamId,
            'wunschorte'       => $wunschOrte,
            'enrolled_at'      => now(),
        ]);

        unset($this->waitlistEntry);
        $this->state = 'waitlisted';
    }
```

- [ ] **Step 4: `bookInterview()` schließt offene Warteliste-Zeile**

In `bookInterview()` direkt nach dem `maybeSwitchPosition($applicant, $interview);`-Aufruf (Z.243) einfügen:

```php
        // Bucht der Bewerber, ist seine Warteliste-Anfrage erfüllt.
        \Platform\Recruiting\Models\RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->open()
            ->update(['fulfilled_at' => now()]);
```

Und am Ende von `bookInterview()` (Z.245) das `unset` ergänzen:

```php
        unset($this->existingBooking, $this->availableInterviews, $this->waitlistEntry);
```

- [ ] **Step 5: Blade — Button im Leer-State**

In `resources/views/livewire/public/interview-booking.blade.php` den Leer-State-Block (Z.200–209) ersetzen durch eine Version, die bei aktivierter Warteliste den Eintrag-Button zeigt:

```blade
            @else
                <div class="applicant-card w-full max-w-md mx-auto p-10 text-center">
                    <div class="w-20 h-20 rounded-full bg-gray-50 flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900 mb-3">Keine freien Termine</h2>
                    @if($this->waitlistEnabled)
                        <p class="text-gray-500 text-lg mb-6">Aktuell sind keine freien Termine verfügbar. Trag dich ein und wir benachrichtigen dich automatisch, sobald ein Termin frei wird.</p>
                        <button
                            type="button"
                            wire:click="joinWaitlist"
                            wire:loading.attr="disabled"
                            class="applicant-btn-primary whitespace-nowrap"
                        >
                            <span wire:loading.remove wire:target="joinWaitlist">Benachrichtigt mich, sobald ein Termin frei wird</span>
                            <span wire:loading wire:target="joinWaitlist">Wird eingetragen…</span>
                        </button>
                    @else
                        <p class="text-gray-500 text-lg">Aktuell sind keine freien Termine verfügbar. Bitte versuchen Sie es später erneut.</p>
                    @endif
                </div>
            @endif
```

- [ ] **Step 6: Blade — `waitlisted`-State**

Direkt vor `{{-- Booked --}}` / `@elseif($state === 'booked')` (Z.234) einfügen:

```blade
    {{-- Waitlisted --}}
    @elseif($state === 'waitlisted')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Du stehst auf der Warteliste</h1>
                <p class="text-gray-500 text-lg">Sobald in einem deiner Wunsch-Standorte ein Termin frei wird, melden wir uns automatisch per WhatsApp mit dem Buchungslink.</p>
            </div>
        </div>
```

- [ ] **Step 7: Verifikation (im Host, UI)**

1. Phase 2 eines Test-Bewerbers auf `completion_config.waitlist_enabled = true` setzen, alle Termine seiner Wunschorte voll/inaktiv machen.
2. Buchungslink öffnen → „Keine freien Termine" + Button erscheint.
3. Button klicken → `waitlisted`-State; DB-Zeile in `rec_interview_waitlist` mit korrektem `wunschorte`-Snapshot.
4. Seite neu laden → direkt `waitlisted`-State (Revisit).
5. `waitlist_enabled` aus → nur der alte „später erneut versuchen"-Text, kein Button.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php resources/views/livewire/public/interview-booking.blade.php
git commit -m "feat(waitlist): Eintrag + waitlisted-State auf der Buchungsseite"
```

---

## Task 8: HR-Sicht — Zähler pro Ort + Liste

**Files:**
- Create: `src/Livewire/Waitlist/Index.php`
- Create: `resources/views/livewire/waitlist/index.blade.php`
- Modify: `src/RecruitingServiceProvider.php` (Livewire-Komponente registrieren)
- Modify: Recruiting-Routen-Datei (Route + Nav)

- [ ] **Step 1: Livewire-Komponente anlegen**

```php
<?php

namespace Platform\Recruiting\Livewire\Waitlist;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecInterviewWaitlist;

class Index extends Component
{
    public ?string $selectedOrt = null;

    private function teamId(): int
    {
        return auth()->user()->currentTeam->id;
    }

    /**
     * Zähler pro Ort: ein Wartender zählt in jedem seiner Wunschorte.
     */
    #[Computed]
    public function countsByOrt(): array
    {
        $counts = [];
        RecInterviewWaitlist::forTeam($this->teamId())->open()->get()
            ->each(function (RecInterviewWaitlist $entry) use (&$counts) {
                foreach (($entry->wunschorte ?? []) as $ort) {
                    $counts[$ort] = ($counts[$ort] ?? 0) + 1;
                }
            });
        arsort($counts);
        return $counts;
    }

    #[Computed]
    public function entries()
    {
        $query = RecInterviewWaitlist::forTeam($this->teamId())->open()
            ->with('applicant')
            ->orderBy('enrolled_at');

        if ($this->selectedOrt) {
            $query->whereJsonContains('wunschorte', $this->selectedOrt);
        }

        return $query->get();
    }

    public function selectOrt(?string $ort): void
    {
        $this->selectedOrt = $ort;
    }

    public function render()
    {
        return view('recruiting::livewire.waitlist.index');
    }
}
```

- [ ] **Step 2: Blade-View anlegen**

```blade
<div class="p-6">
    <h1 class="text-xl font-bold mb-4">Warteliste Schulungstermine</h1>

    {{-- Zähler pro Ort --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <button wire:click="selectOrt(null)"
                class="px-3 py-1.5 rounded-full text-sm font-semibold {{ $selectedOrt === null ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            Alle
        </button>
        @foreach($this->countsByOrt as $ort => $count)
            <button wire:click="selectOrt('{{ $ort }}')"
                    class="px-3 py-1.5 rounded-full text-sm font-semibold {{ $selectedOrt === $ort ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                {{ $ort }} <span class="opacity-70">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    {{-- Liste der Wartenden --}}
    <div class="border rounded-lg divide-y">
        @forelse($this->entries as $entry)
            <div class="flex items-center justify-between px-4 py-3">
                <div>
                    <div class="font-medium text-gray-900">{{ $entry->applicant?->getContact()?->full_name ?? 'Bewerber #'.$entry->rec_applicant_id }}</div>
                    <div class="text-sm text-gray-500">
                        Wunschorte: {{ implode(', ', $entry->wunschorte ?? []) }} · seit {{ $entry->enrolled_at?->format('d.m.Y') }}
                    </div>
                </div>
                <div class="text-sm">
                    @if($entry->notified_at)
                        <span class="text-emerald-600">benachrichtigt {{ $entry->notified_at->format('d.m.Y') }}</span>
                    @else
                        <span class="text-gray-400">wartet</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-gray-400">Keine Wartenden.</div>
        @endforelse
    </div>
</div>
```

- [ ] **Step 3: Komponente + Route registrieren**

In `src/RecruitingServiceProvider.php` in `registerLivewireComponents()` die neue Komponente nach dem Muster der bestehenden Registrierungen ergänzen (z.B. `Livewire::component('recruiting.waitlist.index', \Platform\Recruiting\Livewire\Waitlist\Index::class);` — exakte Syntax aus der vorhandenen Methode übernehmen).

In der Recruiting-Web-Routen-Datei (die in `boot()` via `loadRoutesFrom` geladen wird) eine Route nach dem Muster der bestehenden HR-Seiten (z.B. `InterviewSchedule/Index`) ergänzen, die auf die neue Komponente zeigt, plus einen Nav-Eintrag im Recruiting-Menü an der Stelle, wo „Schulungstermine" verlinkt ist.

> Exakte Datei/Pattern beim Implementieren aus `InterviewSchedule/Index` ableiten (gleiche Registrierungs- und Routing-Konvention).

- [ ] **Step 4: Verifikation (im Host, UI)**

Mehrere Test-Warteliste-Zeilen mit unterschiedlichen Wunschorten anlegen. HR-Warteliste-Seite öffnen:
- Zähler pro Ort stimmen (ein Bewerber mit 3 Orten zählt in allen drei).
- Klick auf einen Ort filtert die Liste.
- Spalte zeigt korrekt „wartet" vs. „benachrichtigt".

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Waitlist/Index.php resources/views/livewire/waitlist/index.blade.php src/RecruitingServiceProvider.php routes/
git commit -m "feat(waitlist): HR-Sicht Zaehler pro Ort + Liste"
```

---

## Task 9: WhatsApp-Template anlegen (Konfiguration, kein Code)

**Files:** keine — Konfiguration im Host/Meta Business.

- [ ] **Step 1: Template anlegen**

Ein neues WhatsApp-Template (Meta) anlegen mit Wording „Es ist ein Schulungstermin frei geworden — jetzt buchen" und einem **URL-Button** (gleicher Token-Mechanismus wie das bestehende Buchungs-Template; der Versand-Code befüllt den Button-Parameter mit dem PublicFormLink-Token).

- [ ] **Step 2: Template in den Bewerber-Einstellungen verknüpfen**

Settings-Modal (AutoPilot-Tab) → Feld „Termin frei geworden (Warteliste)" → neues Template wählen, speichern. Optional pro Stelle überschreiben.

- [ ] **Step 3: End-to-End-Verifikation (im Host)**

1. Test-Bewerber auf Warteliste (Köln).
2. Köln-Termin anlegen → Observer → Job → WhatsApp „Termin frei geworden" mit Buchungslink kommt an.
3. Link öffnen → Köln-Termin buchbar → Buchung vollzieht den Stellenwechsel; Warteliste-Zeile bekommt `fulfilled_at`.
4. Zweiten Köln-Termin anlegen → **keine** zweite Nachricht (notified_at-Guard).

---

## Self-Review (gegen Spec geprüft)

- **Datenmodell** (Spec §1) → Task 1 + 2. Alle Felder abgedeckt, `open()`-Scope = „eine offene Zeile".
- **Eintrag Bewerber-Seite** (Spec §2) → Task 7. Button nur bei `waitlist_enabled`, Snapshot aus `beschaftigungsort`, Revisit zeigt `waitlisted`.
- **WA-Template + Settings** (Spec §3) → Task 3 (Settings-Key + UI) + Task 4 (Versand) + Task 9 (Template-Anlage). Kaskade Position→Team wie `interview_booking_wa_template_id`.
- **Event-driven Trigger** (Spec §4) → Task 5 (Job, atomarer notified_at-Anspruch) + Task 6 (Observer, kein Polling). „Nur 1x" über `whereNull('notified_at')` + atomares Update.
- **Lebenszyklus** (Spec §5) → Task 7 Step 4 (`fulfilled_at` beim Buchen) + Task 6 (`cancelled_at` bei Reject/Park/Inaktiv).
- **HR-Sicht** (Spec §6) → Task 8. Zähler pro Ort (Multi-Ort-Zählung) + filterbare Liste.
- **Aktivierung als Phasen-Setting** (Spec §0) → `waitlist_enabled` in Task 7 Step 1 abgefragt.
- **Tradeoff „nur 1x"** (Spec) → bewusst so umgesetzt; keine Re-Notify-Logik (YAGNI).

Keine Platzhalter, keine offenen TBDs. Typen/Methodennamen konsistent (`open()`, `sendWaitlistAvailableNotification()`, `joinWaitlist()`, `waitlistEntry`, `waitlistEnabled`, `NotifyWaitlistForInterview`).
