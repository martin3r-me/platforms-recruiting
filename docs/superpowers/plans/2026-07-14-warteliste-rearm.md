# Warteliste Re-Arm Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bewerber, deren Wartelisten-Eintrag bereits eine Benachrichtigung ausgelöst hat (`notified_at` gesetzt), können sich auf der öffentlichen Buchungsseite per Klick erneut "scharf schalten" — statt wie heute stumm auf der Liste zu bleiben.

**Architecture:** Die Entscheidungslogik (noop / create / rearm) wird als pure, statische Klasse `WaitlistEnrollmentPlanner` geschnitten und rein mit PHPUnit getestet (Repo-Konvention: keine Laravel-/DB-Tests). `joinWaitlist()` in der Livewire-Komponente ruft nur noch den Planner und führt dessen Entscheidung aus. Das Blade der Empty-Box bekommt drei statt zwei Zustände. Versand-Mechanik (Job, Observer, atomarer Claim) bleibt komplett unangetastet; es gibt keine Migration und keine Änderung an Bestandsdaten.

**Tech Stack:** PHP 8 / Laravel-Modul `platforms-recruiting`, Livewire 3, reines PHPUnit (Runner aus meingedeck-vendor).

## Global Constraints

- Tests laufen OHNE Laravel/DB: nur `PHPUnit\Framework\TestCase`, keine Model-/Facade-Zugriffe im Test (Repo-Konvention).
- Test-Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` (Modul hat kein eigenes vendor/), ausgeführt im Modul-Root `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`.
- Blade: KEINE inline `@php(...)`-Kurzform, immer Block-Form `@php ... @endphp`; Werte vorberechnen statt inline-`@if`-Attribut-Tricks (bekannte x-ui-/Parser-Pitfalls).
- Keine Migration, keine neue Spalte, kein Zugriff auf fremde Wartelisten-Einträge — nur die Zeile des klickenden Bewerbers wird angefasst.
- Kein Edit außerhalb von `platforms-recruiting`.
- Commit-Messages im Repo-Stil: deutsch, conventional commits mit Scope, z.B. `feat(recruiting): …`.
- Nach Push: meingedeck `composer.lock` bumpen (sonst nicht live) — Schritt im letzten Task.

---

### Task 0: Branch anlegen

**Files:** keine (nur git)

- [ ] **Step 1: Fetch + Basis prüfen** (Repo-Regel: vor jedem Feature-Branch fetchen)

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git log --oneline -1 origin/main
```

- [ ] **Step 2: Branch von origin/main**

```bash
git checkout -b feature/warteliste-rearm origin/main
```

Erwartung: neuer Branch `feature/warteliste-rearm`, Basis = origin/main (NICHT vom aktuell ausgecheckten `feature/posting-ref-code` abzweigen).

---

### Task 1: Pure Entscheidungslogik `WaitlistEnrollmentPlanner` (TDD)

**Files:**
- Create: `src/Services/WaitlistEnrollmentPlanner.php`
- Test: `tests/Unit/WaitlistEnrollmentPlannerTest.php`

**Interfaces:**
- Consumes: nichts (pure Klasse, keine Dependencies)
- Produces:
  - `WaitlistEnrollmentPlanner::resolveWunschorte(mixed $extraField, ?string $fallbackOrt): array` — normalisierte Ort-Liste
  - `WaitlistEnrollmentPlanner::plan(?array $openEntry, array $resolvedWunschorte): array` — `$openEntry` ist `null` oder `['notified' => bool, 'wunschorte' => array]`; Rückgabe `['action' => 'noop'|'create'|'rearm', 'wunschorte' => array]`. Task 2 verlässt sich exakt auf diese Signaturen.

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Unit/WaitlistEnrollmentPlannerTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\WaitlistEnrollmentPlanner;

class WaitlistEnrollmentPlannerTest extends TestCase
{
    // --- resolveWunschorte ---

    public function test_resolve_filtert_leere_werte_und_reindexiert(): void
    {
        $this->assertSame(
            ['Köln', 'Bonn'],
            WaitlistEnrollmentPlanner::resolveWunschorte(['Köln', null, '', 'Bonn'], null)
        );
    }

    public function test_resolve_wrappt_skalar(): void
    {
        $this->assertSame(
            ['Köln'],
            WaitlistEnrollmentPlanner::resolveWunschorte('Köln', null)
        );
    }

    public function test_resolve_faellt_auf_primaeren_ort_zurueck(): void
    {
        $this->assertSame(
            ['Düsseldorf'],
            WaitlistEnrollmentPlanner::resolveWunschorte(null, 'Düsseldorf')
        );
        $this->assertSame(
            ['Düsseldorf'],
            WaitlistEnrollmentPlanner::resolveWunschorte(['', null], 'Düsseldorf')
        );
    }

    public function test_resolve_leer_ohne_fallback(): void
    {
        $this->assertSame([], WaitlistEnrollmentPlanner::resolveWunschorte(null, null));
        $this->assertSame([], WaitlistEnrollmentPlanner::resolveWunschorte([], ''));
    }

    // --- plan: kein offener Eintrag ---

    public function test_kein_eintrag_mit_orten_ergibt_create(): void
    {
        $this->assertSame(
            ['action' => 'create', 'wunschorte' => ['Köln']],
            WaitlistEnrollmentPlanner::plan(null, ['Köln'])
        );
    }

    public function test_kein_eintrag_ohne_orte_ergibt_noop(): void
    {
        // Kein matchbarer Ort → kein stiller Geister-Eintrag (heutiges Verhalten).
        $this->assertSame(
            ['action' => 'noop', 'wunschorte' => []],
            WaitlistEnrollmentPlanner::plan(null, [])
        );
    }

    // --- plan: offener Eintrag, noch nicht benachrichtigt ---

    public function test_wartender_eintrag_bleibt_unangetastet(): void
    {
        $this->assertSame(
            ['action' => 'noop', 'wunschorte' => []],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => false, 'wunschorte' => ['Köln']],
                ['Köln', 'Bonn']
            )
        );
    }

    // --- plan: offener Eintrag, bereits benachrichtigt → Re-Arm ---

    public function test_benachrichtigter_eintrag_wird_rearmed_mit_frischem_snapshot(): void
    {
        $this->assertSame(
            ['action' => 'rearm', 'wunschorte' => ['Bonn']],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => true, 'wunschorte' => ['Köln']],
                ['Bonn']
            )
        );
    }

    public function test_rearm_behaelt_alten_snapshot_wenn_aufloesung_leer(): void
    {
        // Wunschorte inzwischen nicht mehr auflösbar → alten (matchbaren)
        // Snapshot behalten statt ihn zu leeren.
        $this->assertSame(
            ['action' => 'rearm', 'wunschorte' => ['Köln']],
            WaitlistEnrollmentPlanner::plan(
                ['notified' => true, 'wunschorte' => ['Köln']],
                []
            )
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/WaitlistEnrollmentPlannerTest.php
```

Erwartung: FAIL / Error `Class "Platform\Recruiting\Services\WaitlistEnrollmentPlanner" not found`.

- [ ] **Step 3: Implementierung schreiben**

Datei `src/Services/WaitlistEnrollmentPlanner.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/**
 * Pure Entscheidungslogik für den "Benachrichtige mich"-Klick auf der
 * öffentlichen Buchungsseite (Schulung-Warteliste).
 *
 * Regeln:
 *  - kein offener Eintrag + matchbare Orte  → create
 *  - kein offener Eintrag + keine Orte      → noop (kein Geister-Eintrag)
 *  - offener Eintrag, noch nicht benachrichtigt → noop (wartet bereits)
 *  - offener Eintrag, bereits benachrichtigt    → rearm (notified_at wieder
 *    auf NULL; Wunschorte-Snapshot auffrischen, sofern die neue Auflösung
 *    nicht leer ist — sonst den alten matchbaren Snapshot behalten)
 *
 * Bewusst ohne Laravel-Dependencies, damit rein PHPUnit-testbar
 * (Repo-Konvention: keine DB-/Feature-Tests im Modul).
 */
class WaitlistEnrollmentPlanner
{
    /**
     * Normalisiert das beschaftigungsort-Extra-Field zu einer sauberen
     * Ort-Liste; ohne gepflegte Wunschorte fällt sie auf den Ort der
     * primären Stelle zurück, damit die Zeile per whereJsonContains
     * matchbar bleibt.
     */
    public static function resolveWunschorte(mixed $extraField, ?string $fallbackOrt): array
    {
        $orte = is_array($extraField) ? $extraField : [$extraField];
        $orte = array_values(array_filter($orte, fn ($v) => $v !== null && $v !== ''));

        if (empty($orte) && !empty($fallbackOrt)) {
            $orte = [$fallbackOrt];
        }

        return $orte;
    }

    /**
     * @param array{notified: bool, wunschorte: array}|null $openEntry
     * @return array{action: 'noop'|'create'|'rearm', wunschorte: array}
     */
    public static function plan(?array $openEntry, array $resolvedWunschorte): array
    {
        if ($openEntry === null) {
            return empty($resolvedWunschorte)
                ? ['action' => 'noop', 'wunschorte' => []]
                : ['action' => 'create', 'wunschorte' => $resolvedWunschorte];
        }

        if (!$openEntry['notified']) {
            return ['action' => 'noop', 'wunschorte' => []];
        }

        $wunschorte = empty($resolvedWunschorte)
            ? $openEntry['wunschorte']
            : $resolvedWunschorte;

        return ['action' => 'rearm', 'wunschorte' => $wunschorte];
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/WaitlistEnrollmentPlannerTest.php
```

Erwartung: `OK (9 tests, 11 assertions)` (Zahlen ±, alle grün).

- [ ] **Step 5: Commit**

```bash
git add src/Services/WaitlistEnrollmentPlanner.php tests/Unit/WaitlistEnrollmentPlannerTest.php
git commit -m "feat(recruiting): WaitlistEnrollmentPlanner — pure Re-Arm-Entscheidungslogik"
```

---

### Task 2: `joinWaitlist()` auf den Planner umstellen (Re-Arm-Pfad)

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php:277-323` (Methode `joinWaitlist()`)

**Interfaces:**
- Consumes: `WaitlistEnrollmentPlanner::resolveWunschorte()` und `::plan()` aus Task 1 (exakte Signaturen siehe dort); Computed `$this->waitlistEntry` (offener Eintrag oder null, existiert bereits).
- Produces: `joinWaitlist()` legt bei `create` wie bisher eine Zeile an, setzt bei `rearm` auf DERSELBEN Zeile `notified_at = null` und frischt `wunschorte` auf, tut bei `noop` nichts. Task 3 (Blade) verlässt sich darauf, dass `joinWaitlist` in allen drei Fällen aufrufbar ist und `waitlistEntry` danach frisch gecacht wird.

- [ ] **Step 1: Import ergänzen**

In `src/Livewire/Public/InterviewBooking.php` bei den Use-Statements (nach Zeile 14, alphabetisch bei den Services):

```php
use Platform\Recruiting\Services\WaitlistEnrollmentPlanner;
```

- [ ] **Step 2: `joinWaitlist()` ersetzen**

Die komplette Methode (heute Zeile 277-323) durch diese Fassung ersetzen:

```php
    public function joinWaitlist(): void
    {
        $applicant = RecApplicant::with(['phase', 'postings.position'])->find($this->applicantId);
        if (!$applicant || !$this->waitlistEnabled) {
            return;
        }

        // Snapshot der bestätigten Wunschorte — gleiche Quelle wie
        // resolvePositionIdsForApplicant() (beschaftigungsort-Extra-Field),
        // Fallback auf den Ort der primären Stelle.
        $wunschOrte = WaitlistEnrollmentPlanner::resolveWunschorte(
            $applicant->getExtraField('beschaftigungsort'),
            $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
        );

        $entry = $this->waitlistEntry;
        $plan = WaitlistEnrollmentPlanner::plan(
            $entry ? [
                'notified'   => $entry->notified_at !== null,
                'wunschorte' => $entry->wunschorte ?? [],
            ] : null,
            $wunschOrte,
        );

        if ($plan['action'] === 'create') {
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'team_id'          => $applicant->team_id,
                'wunschorte'       => $plan['wunschorte'],
                'enrolled_at'      => now(),
            ]);
        } elseif ($plan['action'] === 'rearm') {
            // Verbrauchten Eintrag wieder scharf schalten: nur notified_at
            // und Snapshot — enrolled_at bleibt das ursprüngliche
            // Eintragedatum ("wartet seit" für HR).
            $entry->update([
                'notified_at' => null,
                'wunschorte'  => $plan['wunschorte'],
            ]);
        }

        // State bleibt 'selection'; die Empty-Box rendert aus dem frischen
        // waitlistEntry den passenden Zustand.
        unset($this->waitlistEntry);
    }
```

- [ ] **Step 3: Syntax-Check + gesamte Unit-Suite**

```bash
php -l src/Livewire/Public/InterviewBooking.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: `No syntax errors detected` und komplette Suite grün.

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php
git commit -m "feat(recruiting): joinWaitlist re-armt verbrauchte Warteliste-Einträge per Klick"
```

---

### Task 3: Empty-Box im Blade — drei Zustände

**Files:**
- Modify: `resources/views/livewire/public/interview-booking.blade.php:200-234` (Empty-Box im `@else`-Zweig)

**Interfaces:**
- Consumes: Computed `$this->waitlistEnabled`, `$this->waitlistEntry` (mit `notified_at`), Action `joinWaitlist` aus Task 2.
- Produces: reine View-Änderung, nichts Nachgelagertes.

- [ ] **Step 1: Empty-Box ersetzen**

Den Block ab Zeile 200 (`@else` mit `@php $onWaitlist = ...` bis zum zugehörigen `@endif` in Zeile 234 inkl. schließendem `</div>`) durch folgende Fassung ersetzen. Drei Zustände: wartend / benachrichtigt-aber-wieder-voll / noch nie eingetragen. Werte vorberechnet im `@php`-Block (Block-Form, bekannte Blade-Pitfalls):

```blade
            @else
                @php
                    $waitlistEntry = $this->waitlistEnabled ? $this->waitlistEntry : null;
                    $isWaiting     = $waitlistEntry && !$waitlistEntry->notified_at;
                    $wasNotified   = $waitlistEntry && $waitlistEntry->notified_at;
                @endphp
                <div class="applicant-card w-full max-w-md mx-auto p-10 text-center">
                    <div class="w-20 h-20 rounded-full {{ $isWaiting ? 'bg-blue-50' : 'bg-gray-50' }} flex items-center justify-center mx-auto mb-6">
                        @if($isWaiting)
                            <svg class="w-10 h-10 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        @else
                            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        @endif
                    </div>
                    @if($isWaiting)
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Du stehst auf der Warteliste</h2>
                        <p class="text-gray-500 text-lg">Sobald in einem deiner Wunsch-Standorte ein Termin frei wird, melden wir uns automatisch per WhatsApp mit dem Buchungslink.</p>
                    @elseif($this->waitlistEnabled)
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Keine freien Termine</h2>
                        @if($wasNotified)
                            <p class="text-gray-500 text-lg mb-6">Der letzte Termin war leider schon voll. Trag dich erneut ein und wir benachrichtigen dich automatisch, sobald wieder ein Termin frei wird.</p>
                        @else
                            <p class="text-gray-500 text-lg mb-6">Aktuell sind keine freien Termine verfügbar. Trag dich ein und wir benachrichtigen dich automatisch, sobald ein Termin frei wird.</p>
                        @endif
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
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Keine freien Termine</h2>
                        <p class="text-gray-500 text-lg">Aktuell sind keine freien Termine verfügbar. Bitte versuchen Sie es später erneut.</p>
                    @endif
                </div>
            @endif
```

Wichtig: Der `@elseif($this->waitlistEnabled)`-Zweig greift jetzt in ZWEI Fällen — (a) noch nie eingetragen, (b) eingetragen aber bereits benachrichtigt (`$wasNotified`). Nur der Hinweistext unterscheidet sich; der Klick löst in beiden Fällen `joinWaitlist` aus, das dank Task 2 create bzw. rearm macht.

- [ ] **Step 2: Blade-Kompilierung prüfen + Suite**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: Suite grün. Zusätzlich Sichtprüfung des Diffs: `@php`-Block in Block-Form, `@if/@elseif/@else/@endif` balanciert.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/public/interview-booking.blade.php
git commit -m "feat(recruiting): Empty-Box zeigt Re-Arm-Button nach verbrauchter Benachrichtigung"
```

---

### Task 4: End-to-End-Verifikation + Auslieferung

**Files:** keine neuen (nur Verifikation + git/Deploy-Schritte)

- [ ] **Step 1: Manueller Flow-Test in der lokalen Umgebung (meingedeck)**

Szenario mit einem Test-Bewerber (Phase mit `completion_config.waitlist_enabled = true`, kein freier Termin an seinen Wunschorten):

1. Buchungsseite öffnen → Button "Benachrichtigt mich…" sichtbar → Klick → Zustand "Du stehst auf der Warteliste"; DB: neue Zeile, `notified_at NULL`.
2. In der DB `notified_at` der Zeile auf `NOW()` setzen (simuliert verschickte Benachrichtigung).
3. Seite neu laden → Text "Der letzte Termin war leider schon voll…" + Button wieder sichtbar.
4. Klick → DB: DIESELBE Zeile (gleiche id), `notified_at` wieder NULL, `enrolled_at` unverändert; Seite zeigt wieder "Du stehst auf der Warteliste".
5. Gegenprobe Bestand: eine fremde Wartelisten-Zeile bleibt in allen Schritten unberührt.

- [ ] **Step 2: Gesamte Unit-Suite final**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: alles grün.

- [ ] **Step 3: Push + PR**

```bash
git push -u origin feature/warteliste-rearm
gh pr create --title "feat(recruiting): Warteliste Re-Arm — erneutes Eintragen nach Benachrichtigung" --fill
```

- [ ] **Step 4: Nach Merge/Release — meingedeck bumpen + Queue-Worker**

Repo-Regeln für die Auslieferung:
1. meingedeck `composer.lock` bumpen (sonst ist der Modul-Stand nicht live).
2. Nach Deploy `queue:restart` auf Forge nicht nötig für dieses Feature (kein Job geändert), aber unschädlich; die Livewire-/Blade-Änderung greift direkt.

---

## Bewusste Entscheidungen (nicht vergessen bei Review)

- `enrolled_at` bleibt beim Re-Arm unverändert — ursprüngliches Eintragedatum ist die HR-relevante Info.
- Kein `notified_count`: bewusst weggelassen (YAGNI); kommt ggf. mit der Termin-Warteliste (separates Vorhaben, siehe Ideensammlung).
- Leere Wunschorte-Auflösung beim Re-Arm → alter Snapshot bleibt; beim Create → weiterhin kein Geister-Eintrag (noop).
- Versand-Job, Observer, `UpdateWaitlistTool` (`reset_notification` = manuelles Pendant) bleiben unangetastet.
- Eine-Zeile-Invariante pro Bewerber ist rein applikativ (kein DB-Unique-Constraint; Migration hat nur Indizes, dokumentiert im Migrations-Kommentar). Bestehende, durch dieses Feature NICHT verschärfte Eigenschaft: Ein Doppelklick-Race könnte theoretisch zwei offene Zeilen anlegen — idempotent-unschädlich dank atomarem `notified_at`-Claim beim Versand; `wire:loading.attr="disabled"` dämpft zusätzlich. Kein Handlungsbedarf in diesem Plan.
