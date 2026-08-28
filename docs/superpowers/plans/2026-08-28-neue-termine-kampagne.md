# Kampagne „Neue Termine“ — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR informiert aus dem Statistik-Modal „Ohne Termin“ mit einem Klick alle passenden Bewerber per WhatsApp über neue Schulungstermine — phasenrichtiges Template, danach läuft der Auto-Pilot normal weiter.

**Architecture:** Eine pure Segmentregel (`CampaignSegment`) entscheidet pro Bewerber Template A/B, Vorauswahl und Badges. Ein Loader bündelt die Eloquent-Daten dafür. Ein Sender verschickt ein Template mit Personen-Token im URL-Button nach dem Muster `TrainingCertificateWhatsAppDelivery`. Ein Queue-Job iteriert die Auswahl, loggt, re-armt den Auto-Pilot und schließt Ort-Wartelisten-Einträge; Fortschritt liegt im Cache und wird vom Modal gepollt. Vorab ein Ein-Zeilen-Fix: der Auto-Pilot-Status wird beim Auto-Advance zurückgesetzt.

**Tech Stack:** PHP 8.2, Laravel 11 (Livewire 3, Queue, Cache), Eloquent; Tests: reines PHPUnit (`tests/Unit` pur, `tests/Integration` mit handgebautem Container + Capsule/SQLite — kein testbench). Blade-Prüfung nur mit `tools/blade-check.php`.

**Spec:** `docs/superpowers/specs/2026-08-28-neue-termine-kampagne-design.md`

## Global Constraints

- Test-Runner: `cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && ../../meingedeck/vendor/bin/phpunit -c phpunit.xml <Pfad>` (kein eigenes `vendor/`; Pfad zu meingedeck ggf. anpassen — `ls ../../meingedeck/vendor/bin/phpunit`).
- Integration-Tests: Container + Capsule von Hand, `setEventDispatcher` Pflicht (uuid-Hooks), kein `orderBy random`. Muster: `tests/Integration/BookingClosesWaitlistTest.php`.
- `RecAutoPilotLog.type` ist `varchar(30)` — alle neuen Log-Typen ≤ 30 Zeichen: `campaign_sent`, `autopilot_rearmed`, `waitlist_replaced`.
- `RecAutoPilotLog` hat `timestamps = false`, `created_at` per DB-Default (`useCurrent`). In Test-Schemata `->useCurrent()` setzen; wo das Datum gelesen wird (14-Tage-Regel), `created_at` explizit setzen.
- Blade: keine inline `@if` in Komponenten-Attributen, `@php(...)`-Einzeiler vermeiden, Direktiven nie an Wortzeichen kleben; Werte vorberechnen. Prüfen mit `php tools/blade-check.php <datei>`.
- Kein Edit außerhalb `platforms-recruiting`.
- Commits auf `main` verboten: Branch `feat/neue-termine-kampagne` (vorher `git fetch origin && git status` — Basis muss `origin/main` sein).
- Commit-Messages deutsch, Präfix `feat(recruiting):` / `fix(recruiting):` / `test(recruiting):`, Trailer `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Deploy-Hinweis (nicht Teil des Plans): neuer Job → `queue:restart` Pflicht; danach meingedeck `composer.lock` bumpen.

---

## Dateistruktur

| Datei | Verantwortung |
|---|---|
| `src/Models/RecApplicant.php` (Modify) | `resetAutoPilotCycle()` (Task 1), `rearmAutoPilot()` (Task 5) |
| `src/Support/CampaignSegment.php` (Create) | Pure Regel: Buchungsschritt, Template A/B, Vorauswahl, Badges, Auswahl-Schnitt |
| `src/Models/RecApplicantSettings.php` (Modify) | zwei Default-Keys |
| `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (Modify) | zwei Selects |
| `src/Services/Comms/HoldingTemplateSender.php` (Modify) | `resolveTemplate(teamId, templateId)` |
| `src/Services/Campaign/NewDatesCampaignRecipients.php` (Create) | Eloquent → Segment-Eingabe, gebündelte Queries |
| `src/Services/Campaign/NewDatesCampaignSender.php` (Create) | ein Template an einen Bewerber, Token im URL-Button, Log |
| `src/Jobs/SendNewDatesCampaign.php` (Create) | Schleife, Fortschritt im Cache, Re-Arm, Warteliste schließen |
| `src/Livewire/Statistics/Index.php` (Modify) | Auswahl-State, Kampagnen-Start, Fortschritt |
| `resources/views/livewire/statistics/index.blade.php` (Modify) | Modal: Checkboxen, Badges, Fußzeile |
| `tests/Unit/AutoPilotCycleResetTest.php` (Create) | Task 1 |
| `tests/Unit/CampaignSegmentTest.php` (Create) | Task 2 |
| `tests/Unit/CampaignSettingsKeysTest.php` (Create) | Task 3 |
| `tests/Integration/HoldingTemplateSenderResolveTargetTest.php` (Modify) | Task 4 |
| `tests/Integration/RearmAutoPilotTest.php` (Create) | Task 5 |
| `tests/Integration/NewDatesCampaignRecipientsTest.php` (Create) | Task 6 |
| `tests/Integration/NewDatesCampaignSenderTest.php` (Create) | Task 7 |
| `tests/Integration/SendNewDatesCampaignJobTest.php` (Create) | Task 8 |

---

### Task 0: Branch

- [ ] **Step 1: Fetch + Branch**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git status --short   # muss leer sein (docs/-Dateien aus der Spec-Phase ggf. vorher committen, siehe Step 2)
git checkout -b feat/neue-termine-kampagne origin/main
```

- [ ] **Step 2: Spec, Plan und Tickets committen**

```bash
git add docs/superpowers/specs/2026-08-28-neue-termine-kampagne-design.md docs/superpowers/plans/2026-08-28-neue-termine-kampagne.md docs/tickets/2026-08-28-autopilot-silent-log-flood.md docs/tickets/2026-08-28-ort-warteliste-pausiert-dauerhaft.md
git commit -m "docs(recruiting): Spec + Plan Kampagne Neue Termine, zwei Folgetickets

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 1: Vorab-Fix — Auto-Pilot-Status beim Auto-Advance zurücksetzen

**Files:**
- Modify: `src/Models/RecApplicant.php:546-563` (Advance-Zweig in `checkAutoPilotCompletion()`)
- Test: `tests/Unit/AutoPilotCycleResetTest.php`

**Interfaces:**
- Produces: `RecApplicant::resetAutoPilotCycle(): void` — setzt `auto_pilot_completed_at = null`, `auto_pilot_reminder_count = 0`, `auto_pilot_last_reminder_at = null`, `progress = 0`, `auto_pilot_state_id = null`. Speichert NICHT.

Hintergrund (Spec §5.1): `review_needed` überlebte den Phasen-Aufstieg, weil der Advance-Zweig den Status nicht anfasste; die Auto-Pilot-Query schließt `review_needed` aus. `advanceToNextPhase()` (HR-manuell) und `returnToBookingPhase()` bleiben unverändert.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php
// tests/Unit/AutoPilotCycleResetTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Spec §5.1: Der Zyklus-Reset beim Auto-Advance muss den Auto-Pilot-Status
 * mit zuruecksetzen. Vorher blieb `review_needed` stehen, und die Auto-Pilot-
 * Query (ProcessAutoPilotApplicants::633) hat den Bewerber in der neuen Phase
 * nie wieder gesehen — der Erstkontakt der Folgephase kam nie.
 *
 * Reiner Modell-Test ohne DB: die Methode setzt nur Attribute.
 */
final class AutoPilotCycleResetTest extends TestCase
{
    public function testResetLoeschtAuchDenStatus(): void
    {
        $a = new RecApplicant();
        $a->auto_pilot_state_id = 5;          // review_needed
        $a->auto_pilot_completed_at = '2026-08-01 10:00:00';
        $a->auto_pilot_reminder_count = 2;
        $a->auto_pilot_last_reminder_at = '2026-08-02 10:00:00';
        $a->progress = 100;

        $a->resetAutoPilotCycle();

        $this->assertNull($a->auto_pilot_state_id, 'Status muss null sein, sonst bleibt review_needed kleben.');
        $this->assertNull($a->auto_pilot_completed_at);
        $this->assertSame(0, $a->auto_pilot_reminder_count);
        $this->assertNull($a->auto_pilot_last_reminder_at);
        $this->assertSame(0, $a->progress);
    }

    /**
     * Der Advance-Zweig muss die Methode benutzen — sonst ist der Fix nur eine
     * ungenutzte Methode. Quelltext-Probe, weil checkAutoPilotCompletion() ohne
     * volle DB (Extra-Felder, Phasen, Gates) nicht ausfuehrbar ist.
     */
    public function testAdvanceZweigRuftDenResetAuf(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Models/RecApplicant.php');
        $start = strpos($src, 'if ($nextPhase && $phase?->auto_advance) {');
        $this->assertNotFalse($start, 'Advance-Zweig nicht gefunden — Test anpassen.');
        $block = substr($src, $start, 900);

        $this->assertStringContainsString('$this->resetAutoPilotCycle();', $block);
        $this->assertStringNotContainsString('$this->auto_pilot_reminder_count = 0;', $block, 'Die fuenf Einzelzeilen sind durch den Aufruf ersetzt.');
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/AutoPilotCycleResetTest.php`
Expected: FAIL — `Call to undefined method ... resetAutoPilotCycle()`

- [ ] **Step 3: Methode einfügen und Advance-Zweig umstellen**

In `src/Models/RecApplicant.php` direkt VOR `public function checkAutoPilotCompletion(): void` einfügen:

```php
    /**
     * Neuer Auto-Pilot-Zyklus nach einem Auto-Advance (Bewerber hat die Phase
     * selbst abgeschlossen). Setzt Zaehler, Timer, Fortschritt UND den Status
     * zurueck.
     *
     * Der Status ist der entscheidende Teil: `review_needed` (Erinnerungen
     * ausgeschoepft) ueberlebte den Aufstieg bisher, und die Auto-Pilot-Query
     * schliesst diesen Status aus (ProcessAutoPilotApplicants::633). Wer spaet
     * reagierte und aufstieg, bekam den Erstkontakt der neuen Phase nie —
     * dasselbe Loch, das returnToBookingPhase() fuer seinen Pfad schon stopft.
     * null statt waiting_for_applicant: der naechste Lauf setzt waiting beim
     * Erstkontakt selbst (ProcessAutoPilotApplicants::217), wie beim
     * Inbound-Reset (HandleWhatsAppInboundForRecruiting::189).
     *
     * Speichert NICHT — der Aufrufer setzt rec_phase_id und speichert.
     * Nicht fuer advanceToNextPhase() (HR-manuell): dort soll ein bewusst
     * weitergeschobener, abgeschriebener Bewerber keinen Versand ausloesen.
     */
    public function resetAutoPilotCycle(): void
    {
        $this->auto_pilot_completed_at = null;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = null;
        $this->progress = 0;
        $this->auto_pilot_state_id = null;
    }
```

Im Advance-Zweig (`if ($nextPhase && $phase?->auto_advance) {`) die fünf Zeilen

```php
            $this->rec_phase_id = $nextPhase->id;
            $this->auto_pilot_completed_at = null;
            $this->auto_pilot_reminder_count = 0;
            $this->auto_pilot_last_reminder_at = null;
            $this->progress = 0;
```

ersetzen durch

```php
            $this->rec_phase_id = $nextPhase->id;
            $this->resetAutoPilotCycle();
```

(`$this->clearExtraFieldDefinitionsCache();` und alles danach bleibt.)

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/AutoPilotCycleResetTest.php`
Expected: PASS (2 tests)

Run zusätzlich die ganze Suite, weil RecApplicant zentral ist:
`../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle grün (Stand vor dem Plan: ~1010 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicant.php tests/Unit/AutoPilotCycleResetTest.php
git commit -m "fix(recruiting): Auto-Pilot-Status wird beim Auto-Advance zurueckgesetzt — review_needed klebte an der Folgephase

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `CampaignSegment` — pure Regel

**Files:**
- Create: `src/Support/CampaignSegment.php`
- Test: `tests/Unit/CampaignSegmentTest.php`

**Interfaces:**
- Produces:
  - `CampaignSegment::TEMPLATE_FORM = 'A'`, `CampaignSegment::TEMPLATE_BOOKING = 'B'`, `CampaignSegment::RECENT_CAMPAIGN_DAYS = 14`
  - `CampaignSegment::bookingOrder(array $phases): int` — `$phases`: `list<array{order:int, completion_type:?string, completion_config:?array, is_active:bool}>`
  - `CampaignSegment::classify(array $in): array{template:string, selectable:bool, checked:bool, badges:list<string>}` — `$in`: `array{phase_order:?int, booking_order:int, has_phone:bool, has_active_booking:bool, on_hr_desk:bool, last_campaign_at:?string, now:string, cancelled_bookings:list<array{cancelled_by:?string, cancelled_at:?string}>, waitlist:?array{enrolled_at:?string, notified_at:?string}}` (Datumsstrings `Y-m-d H:i:s`)
  - `CampaignSegment::selectedIds(array $selection, array $drillIds, array $selectableIds): list<int>` — `$selection`: `array<int|string,bool>`

- [ ] **Step 1: Failing Test schreiben**

```php
<?php
// tests/Unit/CampaignSegmentTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Spec §5.2 als Tabellen-Test. Die Regel ist positions-agnostisch: sie kennt
 * nur die relative Lage zum Buchungsschritt, keine MGL-Phasen-IDs.
 */
final class CampaignSegmentTest extends TestCase
{
    /** MGL: P1 fields, P2 booking, P3 fields, P4 contract_sent. */
    private const MGL = [
        ['order' => 1, 'completion_type' => 'fields', 'completion_config' => null, 'is_active' => true],
        ['order' => 2, 'completion_type' => 'booking', 'completion_config' => ['waitlist_enabled' => true], 'is_active' => true],
        ['order' => 3, 'completion_type' => 'fields', 'completion_config' => ['confirm_booking_on_completion' => true], 'is_active' => true],
        ['order' => 4, 'completion_type' => 'contract_sent', 'completion_config' => ['creates_employee_on_completion' => true], 'is_active' => true],
    ];

    /** Legacy (Position 1): zwei fields-Phasen, Buchungslink am Ende von P2. */
    private const LEGACY = [
        ['order' => 1, 'completion_type' => 'fields', 'completion_config' => null, 'is_active' => true],
        ['order' => 2, 'completion_type' => 'fields', 'completion_config' => ['send_booking_notification_on_completion' => true], 'is_active' => true],
    ];

    private function input(array $override = []): array
    {
        return array_merge([
            'phase_order' => 2,
            'booking_order' => 2,
            'has_phone' => true,
            'has_active_booking' => false,
            'on_hr_desk' => false,
            'last_campaign_at' => null,
            'now' => '2026-08-28 12:00:00',
            'cancelled_bookings' => [],
            'waitlist' => null,
        ], $override);
    }

    public function testBuchungsschrittMgl(): void
    {
        $this->assertSame(2, CampaignSegment::bookingOrder(self::MGL));
    }

    public function testBuchungsschrittLegacyIstNachDerSendePhase(): void
    {
        $this->assertSame(3, CampaignSegment::bookingOrder(self::LEGACY));
    }

    public function testBuchungsschrittOhnePhasenIstEins(): void
    {
        $this->assertSame(1, CampaignSegment::bookingOrder([]));
    }

    public function testInaktivePhasenZaehlenNicht(): void
    {
        $phases = self::MGL;
        $phases[1]['is_active'] = false; // booking-Phase aus
        // Kein booking, kein send-Flag → letzte aktive Phase + 1 = 5
        $this->assertSame(5, CampaignSegment::bookingOrder($phases));
    }

    /** @return array<string, array{array, array}> */
    public static function faelle(): array
    {
        return [
            'P1 → A, angehakt, Badge unvollstaendig' => [
                ['phase_order' => 1],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'P2 → B, angehakt, kein Badge' => [
                ['phase_order' => 2],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => []],
            ],
            'P3 mit HR-Storno → B, angehakt, Badge' => [
                ['phase_order' => 3, 'cancelled_bookings' => [['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-26 17:35:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Storniert am 26.08.2026 (HR)']],
            ],
            'P3 mit Selbst-Storno → B, angehakt, Badge' => [
                ['phase_order' => 3, 'cancelled_bookings' => [['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-25 09:00:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Storniert am 25.08.2026 (Bewerber)']],
            ],
            'P4 Selbst-Storno → B, ABGEHAKT' => [
                ['phase_order' => 4, 'cancelled_bookings' => [['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-26 12:50:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin selbst storniert am 26.08.2026']],
            ],
            'P4 HR-Storno → B, ABGEHAKT' => [
                ['phase_order' => 4, 'cancelled_bookings' => [['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-20 08:00:00']]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['HR-Storno am 20.08.2026']],
            ],
            'P4 ohne Storno-Info → B, abgehakt, generisches Badge' => [
                ['phase_order' => 4],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin storniert']],
            ],
            'juengster Storno gewinnt' => [
                ['phase_order' => 4, 'cancelled_bookings' => [
                    ['cancelled_by' => 'hr', 'cancelled_at' => '2026-08-01 08:00:00'],
                    ['cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-19 08:00:00'],
                ]],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['Termin selbst storniert am 19.08.2026']],
            ],
            'kein Telefon → nicht waehlbar' => [
                ['phase_order' => 2, 'has_phone' => false],
                ['template' => 'B', 'selectable' => false, 'checked' => false, 'badges' => ['kein Telefon']],
            ],
            'inzwischen gebucht → nicht waehlbar' => [
                ['phase_order' => 2, 'has_active_booking' => true],
                ['template' => 'B', 'selectable' => false, 'checked' => false, 'badges' => ['hat inzwischen gebucht']],
            ],
            'HR-Schreibtisch → abgehakt' => [
                ['phase_order' => 2, 'on_hr_desk' => true],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['HR-Schreibtisch']],
            ],
            'Kampagne vor 3 Tagen → abgehakt' => [
                ['phase_order' => 2, 'last_campaign_at' => '2026-08-25 10:00:00'],
                ['template' => 'B', 'selectable' => true, 'checked' => false, 'badges' => ['angeschrieben am 25.08.2026']],
            ],
            'Kampagne vor 15 Tagen → angehakt, Badge bleibt' => [
                ['phase_order' => 2, 'last_campaign_at' => '2026-08-13 10:00:00'],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['angeschrieben am 13.08.2026']],
            ],
            'Warteliste → Badge, Default bleibt' => [
                ['phase_order' => 2, 'waitlist' => ['enrolled_at' => '2026-07-10 09:00:00', 'notified_at' => '2026-07-15 09:00:00']],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Warteliste seit 10.07.2026, benachrichtigt am 15.07.2026']],
            ],
            'Warteliste ohne Benachrichtigung' => [
                ['phase_order' => 2, 'waitlist' => ['enrolled_at' => '2026-08-27 09:00:00', 'notified_at' => null]],
                ['template' => 'B', 'selectable' => true, 'checked' => true, 'badges' => ['Warteliste seit 27.08.2026']],
            ],
            'ohne Phase → wie P1' => [
                ['phase_order' => null],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'Legacy P2 (booking_order 3) → A' => [
                ['phase_order' => 2, 'booking_order' => 3],
                ['template' => 'A', 'selectable' => true, 'checked' => true, 'badges' => ['Bewerbung unvollständig']],
            ],
            'Badge-Reihenfolge: Phase, dann Ueberlagerungen' => [
                ['phase_order' => 1, 'on_hr_desk' => true, 'last_campaign_at' => '2026-08-27 10:00:00'],
                ['template' => 'A', 'selectable' => true, 'checked' => false, 'badges' => ['Bewerbung unvollständig', 'HR-Schreibtisch', 'angeschrieben am 27.08.2026']],
            ],
        ];
    }

    /** @dataProvider faelle */
    public function testKlassifikation(array $override, array $expected): void
    {
        $this->assertSame($expected, CampaignSegment::classify($this->input($override)));
    }

    public function testAuswahlSchnittNurKohorteUndWaehlbar(): void
    {
        $selection = ['1' => true, 2 => true, 3 => false, 4 => true, 99 => true];
        $drillIds = [1, 2, 3, 4];
        $selectable = [1, 2, 3];

        $this->assertSame([1, 2], CampaignSegment::selectedIds($selection, $drillIds, $selectable));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/CampaignSegmentTest.php`
Expected: FAIL — `Class "Platform\Recruiting\Support\CampaignSegment" not found`

- [ ] **Step 3: Klasse schreiben**

```php
<?php
// src/Support/CampaignSegment.php

namespace Platform\Recruiting\Support;

/**
 * Kampagne „Neue Termine“ (Spec §5.2): entscheidet pro Bewerber, welches
 * Template rausgeht, ob er vorausgewaehlt ist und welche Badges HR sieht.
 *
 * Pure Entscheidungslogik ohne Framework (Muster SeatStandbyPolicy). Die
 * Eloquent-Seite (NewDatesCampaignRecipients) baut die Eingabe, diese Klasse
 * kennt weder Modelle noch MGL-Phasen-IDs — nur die Lage relativ zum
 * Buchungsschritt der Stelle. Damit gilt dieselbe Regel fuer jede Filiale.
 */
final class CampaignSegment
{
    /** Template A: Bewerbung vervollstaendigen (URL-Button → /form/{token}). */
    public const TEMPLATE_FORM = 'A';

    /** Template B: Terminauswahl (URL-Button → /recruiting/interviews/{token}). */
    public const TEMPLATE_BOOKING = 'B';

    /** Wer in diesem Fenster schon eine Kampagne bekam, ist default abgehakt. */
    public const RECENT_CAMPAIGN_DAYS = 14;

    /**
     * Ordnungszahl des Buchungsschritts der Stelle.
     *  - erste aktive Phase mit completion_type 'booking' → deren order
     *  - sonst erste aktive Phase mit completion_config.send_booking_notification_on_completion → order + 1
     *    (Legacy-Stellen: Buchungslink kommt am Ende dieser Phase)
     *  - sonst letzte aktive Phase + 1
     *  - keine Phasen → 1 (alles gilt als „vor dem Buchungsschritt“ = Template A)
     *
     * @param list<array{order:int, completion_type:?string, completion_config:?array, is_active:bool}> $phases
     */
    public static function bookingOrder(array $phases): int
    {
        $active = array_values(array_filter($phases, fn (array $p) => ($p['is_active'] ?? true) === true));
        usort($active, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach ($active as $p) {
            if (($p['completion_type'] ?? null) === 'booking') {
                return (int) $p['order'];
            }
        }
        foreach ($active as $p) {
            if ((($p['completion_config'] ?? [])['send_booking_notification_on_completion'] ?? false) === true) {
                return (int) $p['order'] + 1;
            }
        }
        if ($active === []) {
            return 1;
        }

        return (int) end($active)['order'] + 1;
    }

    /**
     * @param array{
     *   phase_order:?int, booking_order:int, has_phone:bool, has_active_booking:bool,
     *   on_hr_desk:bool, last_campaign_at:?string, now:string,
     *   cancelled_bookings:list<array{cancelled_by:?string, cancelled_at:?string}>,
     *   waitlist:?array{enrolled_at:?string, notified_at:?string}
     * } $in
     * @return array{template:string, selectable:bool, checked:bool, badges:list<string>}
     */
    public static function classify(array $in): array
    {
        $order = (int) ($in['phase_order'] ?? 0);
        $bookingOrder = (int) $in['booking_order'];
        $badges = [];
        $checked = true;
        $selectable = true;

        if ($order < $bookingOrder) {
            $template = self::TEMPLATE_FORM;
            $badges[] = 'Bewerbung unvollständig';
        } else {
            $template = self::TEMPLATE_BOOKING;
            $storno = self::juengsterStorno($in['cancelled_bookings'] ?? []);
            if ($order === $bookingOrder + 1 && $storno !== null) {
                $badges[] = 'Storniert am ' . self::datum($storno['cancelled_at'])
                    . ' (' . self::akteur($storno['cancelled_by']) . ')';
            } elseif ($order >= $bookingOrder + 2) {
                // Zwei Phasen hinter dem Buchungsschritt (MGL: P4) — Daten
                // komplett, Buchung weg. Zu 73 % selbst storniert (Analyse
                // 28.08.), deshalb sichtbar, aber nicht vorausgewaehlt.
                $checked = false;
                if ($storno === null) {
                    $badges[] = 'Termin storniert';
                } elseif ($storno['cancelled_by'] === 'applicant') {
                    $badges[] = 'Termin selbst storniert am ' . self::datum($storno['cancelled_at']);
                } else {
                    $badges[] = 'HR-Storno am ' . self::datum($storno['cancelled_at']);
                }
            }
        }

        // Ueberlagerungen — Reihenfolge ist die Anzeige-Reihenfolge.
        if (($in['has_phone'] ?? false) !== true) {
            $selectable = false;
            $checked = false;
            $badges[] = 'kein Telefon';
        }
        if (($in['has_active_booking'] ?? false) === true) {
            $selectable = false;
            $checked = false;
            $badges[] = 'hat inzwischen gebucht';
        }
        if (($in['on_hr_desk'] ?? false) === true) {
            $checked = false;
            $badges[] = 'HR-Schreibtisch';
        }
        if (!empty($in['last_campaign_at'])) {
            $last = new \DateTimeImmutable($in['last_campaign_at']);
            $now = new \DateTimeImmutable($in['now']);
            if ($last > $now->modify('-' . self::RECENT_CAMPAIGN_DAYS . ' days')) {
                $checked = false;
            }
            $badges[] = 'angeschrieben am ' . self::datum($in['last_campaign_at']);
        }
        if (!empty($in['waitlist'])) {
            $text = 'Warteliste seit ' . self::datum($in['waitlist']['enrolled_at'] ?? null);
            if (!empty($in['waitlist']['notified_at'])) {
                $text .= ', benachrichtigt am ' . self::datum($in['waitlist']['notified_at']);
            }
            $badges[] = $text;
        }

        return [
            'template' => $template,
            'selectable' => $selectable,
            'checked' => $checked,
            'badges' => $badges,
        ];
    }

    /**
     * Schnitt aus Client-Auswahl, Kohorte und waehlbaren Zeilen. Der Client
     * darf nur ankreuzen, was das Modal zeigt UND was waehlbar ist — alles
     * andere wird still verworfen (Muster resolveIdsFromClient: Eingabe von
     * draussen heisst leere Menge, nicht Fehler).
     *
     * @param array<int|string,bool> $selection
     * @param list<int> $drillIds
     * @param list<int> $selectableIds
     * @return list<int>
     */
    public static function selectedIds(array $selection, array $drillIds, array $selectableIds): array
    {
        $allowed = array_intersect(array_map('intval', $drillIds), array_map('intval', $selectableIds));
        $out = [];
        foreach ($selection as $id => $on) {
            if ($on === true && in_array((int) $id, $allowed, true)) {
                $out[] = (int) $id;
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @param list<array{cancelled_by:?string, cancelled_at:?string}> $stornos */
    private static function juengsterStorno(array $stornos): ?array
    {
        $best = null;
        foreach ($stornos as $s) {
            if ($best === null || (string) ($s['cancelled_at'] ?? '') > (string) ($best['cancelled_at'] ?? '')) {
                $best = $s;
            }
        }

        return $best;
    }

    private static function akteur(?string $cancelledBy): string
    {
        return match ($cancelledBy) {
            'applicant' => 'Bewerber',
            'hr' => 'HR',
            'system' => 'System',
            default => 'unbekannt',
        };
    }

    private static function datum(?string $ymdHis): string
    {
        if ($ymdHis === null || $ymdHis === '') {
            return '–';
        }

        return (new \DateTimeImmutable($ymdHis))->format('d.m.Y');
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/CampaignSegmentTest.php`
Expected: PASS (alle Datensätze grün). Bei Abweichung im Badge-Text: die Erwartung im Test ist die Spezifikation — Code anpassen, nicht den Test.

- [ ] **Step 5: Commit**

```bash
git add src/Support/CampaignSegment.php tests/Unit/CampaignSegmentTest.php
git commit -m "feat(recruiting): CampaignSegment — pure Regel fuer Template A/B, Vorauswahl und Badges der Kampagne Neue Termine

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Settings-Keys + Selects im Einstellungs-Modal

**Files:**
- Modify: `src/Models/RecApplicantSettings.php` (Konstante `DEFAULT_SETTINGS`, nach `interview_waitlist_termin_wa_template_id`)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (nach dem Block `interview_waitlist_termin_wa_template_id`, ca. Zeile 525–540)
- Test: `tests/Unit/CampaignSettingsKeysTest.php`

**Interfaces:**
- Produces: Settings-Keys `campaign_form_wa_template_id`, `campaign_booking_wa_template_id` (beide `null` Default), gelesen über `RecApplicantSettings::getSetting()`.

- [ ] **Step 1: Failing Test**

```php
<?php
// tests/Unit/CampaignSettingsKeysTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die beiden Kampagnen-Templates haben Default-Keys, damit getSetting() sie
 * kennt und das Einstellungs-Modal sie anbietet. Ohne Default-Eintrag liefert
 * getSetting() zwar auch null — aber der Key waere nirgends dokumentiert.
 */
final class CampaignSettingsKeysTest extends TestCase
{
    public function testKeysSindInDenDefaults(): void
    {
        $this->assertArrayHasKey('campaign_form_wa_template_id', RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertArrayHasKey('campaign_booking_wa_template_id', RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS['campaign_form_wa_template_id']);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS['campaign_booking_wa_template_id']);
    }

    public function testModalBietetBeideSelects(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $this->assertStringContainsString('settings.campaign_form_wa_template_id', $blade);
        $this->assertStringContainsString('settings.campaign_booking_wa_template_id', $blade);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/CampaignSettingsKeysTest.php`
Expected: FAIL — `Failed asserting that an array has the key 'campaign_form_wa_template_id'`

- [ ] **Step 3: Defaults ergänzen**

In `src/Models/RecApplicantSettings.php` direkt nach der Zeile `'interview_waitlist_termin_wa_template_id' => null,` einfügen:

```php
        // Kampagne „Neue Termine“ (Statistik → Ohne Termin). Zwei Templates,
        // weil die Zielgruppe in zwei Lagen sitzt: vor dem Buchungsschritt
        // (Formular-Link) und ab dem Buchungsschritt (Terminauswahl-Link).
        // Beide brauchen einen dynamischen URL-Button an Position 0; der
        // Personen-Token wird vom Sender eingesetzt. Ohne Wert kann HR im
        // Modal ein beliebiges approved Template waehlen.
        'campaign_form_wa_template_id' => null,
        'campaign_booking_wa_template_id' => null,
```

- [ ] **Step 4: Selects ins Modal**

In `resources/views/livewire/applicant/applicant-settings-modal.blade.php` nach dem `@endif` des Blocks „Termin-Warteliste Template“ einfügen (gleiches Muster wie Zeile 509–523):

```blade
                    {{-- Kampagne „Neue Termine“ — Template A: Bewerbung vervollständigen (Formular-Link) --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            :value="$settings['campaign_form_wa_template_id'] ?? null"
                            name="settings.campaign_form_wa_template_id"
                            label="WhatsApp Template — Neue Termine, Bewerbung vervollständigen (Kampagne A)"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model.live="settings.campaign_form_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Geht aus der Statistik („Ohne Termin“) an Bewerber, deren Bewerbung noch unvollständig ist. URL-Button muss auf <code>/form/{{ '{{1}}' }}</code> zeigen.</p>
                    @endif

                    {{-- Kampagne „Neue Termine“ — Template B: Terminauswahl (Buchungs-Link) --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            :value="$settings['campaign_booking_wa_template_id'] ?? null"
                            name="settings.campaign_booking_wa_template_id"
                            label="WhatsApp Template — Neue Termine, Terminauswahl (Kampagne B)"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model.live="settings.campaign_booking_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Geht aus der Statistik („Ohne Termin“) an Bewerber, die nur noch einen Termin brauchen. URL-Button muss auf <code>/recruiting/interviews/{{ '{{1}}' }}</code> zeigen.</p>
                    @endif
```

Blade prüfen: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php` → keine Fehler.

- [ ] **Step 5: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/CampaignSettingsKeysTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecApplicantSettings.php resources/views/livewire/applicant/applicant-settings-modal.blade.php tests/Unit/CampaignSettingsKeysTest.php
git commit -m "feat(recruiting): Settings-Keys + Selects fuer die zwei Kampagnen-Templates (Neue Termine A/B)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: `HoldingTemplateSender::resolveTemplate()` — Kanal zu einem Template per ID

**Files:**
- Modify: `src/Services/Comms/HoldingTemplateSender.php:119-166`
- Test: `tests/Integration/HoldingTemplateSenderResolveTargetTest.php` (bestehende Klasse, neue Methoden)

**Interfaces:**
- Consumes: bestehendes `resolveConfig(int $teamId, string $settingsKey)` (privat, Signatur bleibt — `testResolveConfigBleibtPrivat` prüft das).
- Produces: `HoldingTemplateSender::resolveTemplate(int $teamId, int $templateId): array{error:?string, template:?IntegrationsWhatsAppTemplate, channel:?CommsChannel}`

Warum hier: die Kampagne wählt das Template per ID (Modal-Override), nicht per Settings-Key. Die Kette Template → Account → Kanal existiert schon in `resolveConfig()`; sie wird in einen privaten Helfer gezogen, den beide Wege nutzen.

- [ ] **Step 1: Failing Tests in die bestehende Klasse einfügen** (vor `runRealMigrations()`)

```php
    public function testResolveTemplateLiefertKanalZurTemplateId(): void
    {
        $target = $this->sender()->resolveTemplate(self::TEAM, self::$templateId);

        $this->assertNull($target['error']);
        $this->assertInstanceOf(IntegrationsWhatsAppTemplate::class, $target['template']);
        $this->assertSame(self::$templateId, (int) $target['template']->id);
        $this->assertInstanceOf(CommsChannel::class, $target['channel']);
        $this->assertSame(self::ACCOUNT_NUMMER, $target['channel']->sender_identifier);
    }

    public function testResolveTemplateUnbekannteIdMeldetFehler(): void
    {
        $target = $this->sender()->resolveTemplate(self::TEAM, 999999);

        $this->assertSame('Template nicht gefunden oder bei Meta nicht genehmigt.', $target['error']);
        $this->assertNull($target['template']);
        $this->assertNull($target['channel']);
    }

    public function testResolveTemplateOhneKanalMeldetFehler(): void
    {
        Capsule::table('comms_channels')->update(['is_active' => false]);

        $target = $this->sender()->resolveTemplate(self::TEAM, self::$templateId);

        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $target['error']);
    }
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/HoldingTemplateSenderResolveTargetTest.php`
Expected: 3 FAIL — `Call to undefined method ... resolveTemplate()`; die bestehenden 4 Tests grün.

- [ ] **Step 3: Implementieren**

In `HoldingTemplateSender.php` nach `resolveTarget()` einfügen:

```php
    /**
     * Wie resolveTarget(), aber das Template kommt per ID statt per Settings-Key
     * — fuer Wege, auf denen HR das Template im Dialog waehlt (Kampagne „Neue
     * Termine“). Account/Kanal werden identisch aufgeloest.
     *
     * @return array{error: ?string, template: ?IntegrationsWhatsAppTemplate, channel: ?CommsChannel}
     */
    public function resolveTemplate(int $teamId, int $templateId): array
    {
        $fail = fn (string $msg) => ['error' => $msg, 'template' => null, 'channel' => null];

        if (!class_exists(IntegrationsWhatsAppTemplate::class)) {
            return $fail('WhatsApp-Integration nicht verfügbar.');
        }

        $template = $templateId > 0 ? IntegrationsWhatsAppTemplate::find($templateId) : null;
        if (!$template || $template->status !== 'APPROVED') {
            return $fail('Template nicht gefunden oder bei Meta nicht genehmigt.');
        }

        return $this->resolveChannelFor(RecApplicantSettings::getOrCreateForTeam($teamId), $template);
    }
```

`resolveConfig()` umbauen: alles ab `$accountId = $settings->getSetting('auto_pilot_wa_account_id') ...` bis `return ['error' => null, ...]` in den neuen privaten Helfer verschieben; `resolveConfig()` endet mit `return $this->resolveChannelFor($settings, $template);`.

```php
    /**
     * Account → Kanal zu einem bereits aufgeloesten Template. Gemeinsamer Teil
     * von resolveConfig() (Settings-Key) und resolveTemplate() (ID).
     *
     * @return array{error: ?string, template: ?IntegrationsWhatsAppTemplate, channel: ?CommsChannel}
     */
    private function resolveChannelFor(RecApplicantSettings $settings, IntegrationsWhatsAppTemplate $template): array
    {
        $fail = fn (string $msg) => ['error' => $msg, 'template' => null, 'channel' => null];

        $accountId = $settings->getSetting('auto_pilot_wa_account_id') ?: $template->whatsapp_account_id;
        if (!$accountId || !class_exists(IntegrationsWhatsAppAccount::class)) {
            return $fail('Kein WhatsApp-Account konfiguriert.');
        }

        $account = IntegrationsWhatsAppAccount::find($accountId);
        if (!$account || !$account->active) {
            return $fail('WhatsApp-Account nicht aktiv.');
        }

        $channel = CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();

        if (!$channel) {
            return $fail('Kein aktiver WhatsApp-Kanal für den Account.');
        }

        return ['error' => null, 'template' => $template, 'channel' => $channel];
    }
```

Die Fehlertexte müssen zeichengleich bleiben (bestehende Tests und `TrainingCertificateWhatsAppDelivery` hängen daran).

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/HoldingTemplateSenderResolveTargetTest.php tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php`
Expected: PASS (alle, inkl. `testResolveConfigBleibtPrivat`).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/HoldingTemplateSender.php tests/Integration/HoldingTemplateSenderResolveTargetTest.php
git commit -m "feat(recruiting): HoldingTemplateSender::resolveTemplate — Kanal-Aufloesung zu einer Template-ID (gemeinsamer Helfer mit resolveConfig)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: `RecApplicant::rearmAutoPilot()`

**Files:**
- Modify: `src/Models/RecApplicant.php` (nach `resetAutoPilotCycle()` aus Task 1)
- Test: `tests/Integration/RearmAutoPilotTest.php`

**Interfaces:**
- Consumes: `RecAutoPilotState` (code `waiting_for_applicant`, `team_id IS NULL`), `RecAutoPilotLog`.
- Produces: `RecApplicant::rearmAutoPilot(string $reason): bool` — `false` wenn `auto_pilot = false` (nichts geändert); sonst Status `waiting_for_applicant`, `auto_pilot_reminder_count = 0`, `auto_pilot_last_reminder_at = now()`, `save()`, Log `autopilot_rearmed`, `true`.

Semantik (Spec §4): Kampagne = Erstkontakt des neuen Zyklus. `last_reminder_at = now()` heißt: der nächste Auto-Pilot-Lauf geht in den Erinnerungs-Zweig, die erste Erinnerung kommt frühestens nach dem Intervall, Zähler 0 → volle Anzahl Erinnerungen, dann wieder `review_needed`.

- [ ] **Step 1: Failing Test**

```php
<?php
// tests/Integration/RearmAutoPilotTest.php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;

/**
 * Spec §5.4 Schritt 5: Nach einer Kampagnen-WhatsApp laeuft der Auto-Pilot
 * wieder — Status waiting, Zaehler 0, Timer = jetzt (Kampagne ist der
 * Erstkontakt des neuen Zyklus). Direkteinstellungen (auto_pilot=false)
 * bleiben unberuehrt.
 */
final class RearmAutoPilotTest extends TestCase
{
    private Capsule $capsule;
    private int $waitingId;
    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $schema = $this->capsule->schema();
        $schema->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('public_token')->nullable();
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('auto_pilot')->default(true);
            $t->integer('auto_pilot_state_id')->nullable();
            $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable();
            $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0);
            $t->integer('rec_phase_id')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_auto_pilot_states', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('team_id')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 30);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        $this->waitingId = (int) RecAutoPilotState::create(['code' => 'waiting_for_applicant', 'name' => 'Wartet'])->id;
        $this->reviewId = (int) RecAutoPilotState::create(['code' => 'review_needed', 'name' => 'Prüfung'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(bool $autoPilot = true): RecApplicant
    {
        return RecApplicant::forceCreate([
            'team_id' => 3,
            'auto_pilot' => $autoPilot,
            'auto_pilot_state_id' => $this->reviewId,
            'auto_pilot_reminder_count' => 2,
            'auto_pilot_last_reminder_at' => '2026-08-10 09:26:00',
        ]);
    }

    public function testRearmSetztStatusZaehlerUndTimer(): void
    {
        $a = $this->applicant();

        $this->assertTrue($a->rearmAutoPilot('Kampagne Neue Termine'));

        $a->refresh();
        $this->assertSame($this->waitingId, (int) $a->auto_pilot_state_id);
        $this->assertSame(0, (int) $a->auto_pilot_reminder_count);
        $this->assertSame('2026-08-28 12:00:00', $a->auto_pilot_last_reminder_at->format('Y-m-d H:i:s'));

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', 'autopilot_rearmed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Kampagne Neue Termine', (string) $log->summary);
    }

    public function testDirekteinstellungBleibtUnberuehrt(): void
    {
        $a = $this->applicant(autoPilot: false);

        $this->assertFalse($a->rearmAutoPilot('Kampagne Neue Termine'));

        $a->refresh();
        $this->assertSame($this->reviewId, (int) $a->auto_pilot_state_id);
        $this->assertSame(2, (int) $a->auto_pilot_reminder_count);
        $this->assertSame(0, RecAutoPilotLog::count());
    }
}
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/RearmAutoPilotTest.php`
Expected: FAIL — `Call to undefined method ... rearmAutoPilot()`

Hinweis: Wirft `RecApplicant::forceCreate` wegen eines Observers/Facade (z. B. `Str`, `Log`), den Fehler anschauen — bestehende Tests (`ManualBookingCandidatesTest`) legen RecApplicant mit Capsule an; ggf. `Facade::setFacadeApplication($container)` + Log-Attrappe wie in `HoldingTemplateSenderResolveTargetTest::setUpBeforeClass` ergänzen (Memory: Log-Attrappe braucht `clearResolvedInstance`).

- [ ] **Step 3: Implementieren** (in `RecApplicant.php` direkt nach `resetAutoPilotCycle()`)

```php
    /**
     * Auto-Pilot nach einem manuellen Anstoss (Kampagne „Neue Termine“) wieder
     * scharf schalten: der Anstoss zaehlt als Erstkontakt des neuen Zyklus.
     *
     *  - Status waiting_for_applicant (die Query nimmt ihn wieder auf)
     *  - Zaehler 0 → volle Anzahl Erinnerungen steht noch aus
     *  - last_reminder_at = jetzt → der naechste Lauf geht in den
     *    Erinnerungs-Zweig, die erste Erinnerung kommt fruehestens nach dem
     *    Intervall. Ohne diesen Timer wuerde der naechste Lauf sofort den
     *    Erstkontakt der Phase schicken — direkt hinter der Kampagne.
     *
     * false = Direkteinstellung (auto_pilot aus): nichts angefasst, kein Log.
     */
    public function rearmAutoPilot(string $reason): bool
    {
        if (!$this->auto_pilot) {
            return false;
        }

        $waitingId = RecAutoPilotState::where('code', 'waiting_for_applicant')
            ->whereNull('team_id')
            ->value('id');

        $this->auto_pilot_state_id = $waitingId;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = now();
        $this->save();

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'autopilot_rearmed',
                'summary' => 'Auto-Pilot wieder scharf: ' . $reason . '.',
            ]);
        } catch (\Throwable) {
            // Log darf den Re-Arm nicht rueckgaengig machen.
        }

        return true;
    }
```

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/RearmAutoPilotTest.php`
Expected: PASS (2)

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicant.php tests/Integration/RearmAutoPilotTest.php
git commit -m "feat(recruiting): RecApplicant::rearmAutoPilot — Kampagne zaehlt als Erstkontakt, Auto-Pilot laeuft danach weiter

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: `NewDatesCampaignRecipients` — Eloquent → Segment-Eingabe

**Files:**
- Create: `src/Services/Campaign/NewDatesCampaignRecipients.php`
- Test: `tests/Integration/NewDatesCampaignRecipientsTest.php`

**Interfaces:**
- Consumes: `CampaignSegment::bookingOrder()`, `CampaignSegment::classify()` (Task 2); Modelle `RecApplicant` (Relationen `phase`, `position`, `postings.position`, `interviewBookings`, `crmContactLinks.contact.phoneNumbers`; Methoden `primaryPosition()`, `primaryContactPhone()`), `RecPosition::phases()`, `RecInterviewWaitlist` (Scopes `ortBased()`, `open()`), `RecAutoPilotLog`.
- Produces: `NewDatesCampaignRecipients::load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array<int, array{applicant_id:int, name:string, applied_at:?string, phase:string, template:string, selectable:bool, checked:bool, badges:list<string>}>` — Schlüssel = applicant_id, Reihenfolge wie `$applicantIds`. IDs außerhalb des Teams fehlen im Ergebnis.

- [ ] **Step 1: Failing Test**

```php
<?php
// tests/Integration/NewDatesCampaignRecipientsTest.php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;

/**
 * Der Loader ist die einzige Eloquent-Seite der Segmentregel. Geprueft wird
 * hier NICHT die Regel (CampaignSegmentTest), sondern dass die Eingabe richtig
 * aus den Tabellen zusammenkommt: Telefon ueber den CRM-Kontakt, Buchungen
 * inkl. Storno-Akteur, offener Ort-Wartelisten-Eintrag, letzte Kampagne,
 * Phasen der Stelle — und dass Team-fremde IDs fehlen.
 */
final class NewDatesCampaignRecipientsTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->boolean('is_on_hr_desk')->default(false); $t->integer('rec_phase_id')->nullable();
            $t->integer('rec_position_id')->nullable(); $t->date('applied_at')->nullable();
            $t->integer('auto_pilot_state_id')->nullable(); $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable(); $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0); $t->timestamps();
        });
        $s->create('rec_positions', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id'); $t->string('title');
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $s->create('rec_phases', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id'); $t->integer('rec_position_id');
            $t->string('name'); $t->integer('order'); $t->boolean('is_active')->default(true);
            $t->boolean('auto_advance')->default(true); $t->string('completion_type')->default('fields');
            $t->text('completion_config')->nullable(); $t->text('auto_pilot_settings')->nullable(); $t->timestamps();
        });
        $s->create('rec_interview_bookings', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id'); $t->integer('rec_interview_id');
            $t->integer('team_id')->nullable(); $t->string('status')->default('booked'); $t->boolean('is_active')->default(true);
            $t->timestamp('booked_at')->nullable(); $t->timestamp('seat_released_at')->nullable();
            $t->string('cancelled_by')->nullable(); $t->timestamp('cancelled_at')->nullable();
            $t->integer('created_by_user_id')->nullable(); $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_interview_waitlist', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable(); $t->integer('team_id')->nullable(); $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable(); $t->timestamp('enrolled_at')->nullable(); $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        // CRM-Kontakt-Kette wie sie primaryContactPhone() liest.
        $s->create('rec_applicant_contact_links', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->integer('contact_id'); $t->timestamps();
        });
        $s->create('crm_contacts', function ($t) {
            $t->increments('id'); $t->string('first_name')->nullable(); $t->string('last_name')->nullable();
            $t->string('full_name')->nullable(); $t->string('display_name')->nullable(); $t->timestamps();
        });
        $s->create('crm_phone_numbers', function ($t) {
            $t->increments('id'); $t->string('phoneable_type')->nullable(); $t->integer('phoneable_id')->nullable();
            $t->integer('contact_id')->nullable(); $t->string('raw_input')->nullable(); $t->string('international')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_primary')->default(false); $t->timestamps();
        });

        Capsule::table('rec_positions')->insert(['id' => 11, 'team_id' => 3, 'title' => 'MGL allgemein']);
        Capsule::table('rec_phases')->insert([
            ['id' => 40, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields'],
            ['id' => 41, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Schulung buchen', 'order' => 2, 'completion_type' => 'booking'],
            ['id' => 42, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Onboarding', 'order' => 3, 'completion_type' => 'fields'],
            ['id' => 43, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Verträge', 'order' => 4, 'completion_type' => 'contract_sent'],
        ]);
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id, int $phaseId, bool $phone = true, bool $hrDesk = false): void
    {
        Capsule::table('rec_applicants')->insert([
            'id' => $id, 'team_id' => 3, 'rec_phase_id' => $phaseId, 'rec_position_id' => 11,
            'applied_at' => '2026-07-15', 'is_on_hr_desk' => $hrDesk,
        ]);
        Capsule::table('crm_contacts')->insert(['id' => 1000 + $id, 'first_name' => 'Test', 'last_name' => 'Nr' . $id, 'full_name' => 'Test Nr' . $id]);
        Capsule::table('rec_applicant_contact_links')->insert(['rec_applicant_id' => $id, 'contact_id' => 1000 + $id]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert([
                'phoneable_type' => 'crm_contact', 'phoneable_id' => 1000 + $id, 'contact_id' => 1000 + $id,
                'raw_input' => '0176' . $id, 'international' => '+49176' . $id, 'is_active' => true, 'is_primary' => true,
            ]);
        }
    }

    public function testBaugruppenKommenZusammen(): void
    {
        $this->applicant(1, 40);                      // P1
        $this->applicant(2, 41);                      // P2 + Warteliste
        $this->applicant(3, 42);                      // P3 mit HR-Storno
        $this->applicant(4, 43);                      // P4 mit Selbst-Storno
        $this->applicant(5, 41, phone: false);        // kein Telefon
        $this->applicant(6, 41, hrDesk: true);        // HR-Desk + juengste Kampagne
        $this->applicant(7, 41);                      // hat aktive Buchung

        Capsule::table('rec_interview_waitlist')->insert(['rec_applicant_id' => 2, 'team_id' => 3, 'wunschorte' => '["moenchengladbach"]', 'enrolled_at' => '2026-07-10 09:00:00', 'notified_at' => '2026-07-15 09:00:00']);
        Capsule::table('rec_interview_bookings')->insert([
            ['rec_applicant_id' => 3, 'rec_interview_id' => 45, 'status' => 'cancelled', 'cancelled_by' => 'hr', 'cancelled_at' => '2026-08-26 17:35:00'],
            ['rec_applicant_id' => 4, 'rec_interview_id' => 49, 'status' => 'cancelled', 'cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-26 12:50:00'],
            ['rec_applicant_id' => 7, 'rec_interview_id' => 86, 'status' => 'booked'],
        ]);
        Capsule::table('rec_auto_pilot_logs')->insert([
            ['rec_applicant_id' => 6, 'type' => 'campaign_sent', 'summary' => 'x', 'created_at' => '2026-08-20 10:00:00'],
            ['rec_applicant_id' => 6, 'type' => 'campaign_sent', 'summary' => 'x', 'created_at' => '2026-08-27 10:00:00'],
        ]);

        $rows = (new NewDatesCampaignRecipients())->load(3, [1, 2, 3, 4, 5, 6, 7, 999], new \DateTimeImmutable('2026-08-28 12:00:00'));

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($rows), 'Reihenfolge wie angefragt, 999 (fremd/fehlt) faellt raus.');

        $this->assertSame('A', $rows[1]['template']);
        $this->assertSame('Bewerbung', $rows[1]['phase']);
        $this->assertSame('Test Nr1', $rows[1]['name']);
        $this->assertSame('2026-07-15', $rows[1]['applied_at']);

        $this->assertSame('B', $rows[2]['template']);
        $this->assertContains('Warteliste seit 10.07.2026, benachrichtigt am 15.07.2026', $rows[2]['badges']);
        $this->assertTrue($rows[2]['checked']);

        $this->assertContains('Storniert am 26.08.2026 (HR)', $rows[3]['badges']);
        $this->assertTrue($rows[3]['checked']);

        $this->assertFalse($rows[4]['checked']);
        $this->assertContains('Termin selbst storniert am 26.08.2026', $rows[4]['badges']);

        $this->assertFalse($rows[5]['selectable']);
        $this->assertContains('kein Telefon', $rows[5]['badges']);

        $this->assertFalse($rows[6]['checked']);
        $this->assertContains('HR-Schreibtisch', $rows[6]['badges']);
        $this->assertContains('angeschrieben am 27.08.2026', $rows[6]['badges'], 'juengste Kampagne, nicht die aeltere');

        $this->assertFalse($rows[7]['selectable']);
        $this->assertContains('hat inzwischen gebucht', $rows[7]['badges']);
    }

    public function testLeereEingabeLeeresErgebnis(): void
    {
        $this->assertSame([], (new NewDatesCampaignRecipients())->load(3, [], new \DateTimeImmutable('2026-08-28')));
    }
}
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/NewDatesCampaignRecipientsTest.php`
Expected: FAIL — Klasse nicht gefunden.

Beim ersten grünen Versuch kann das Schema der CRM-Tabellen abweichen (Spaltennamen von `crm_phone_numbers`/`rec_applicant_contact_links`). Dann in `platform-crm` bzw. der Relation `RecApplicant::crmContactLinks()` nachsehen und das TEST-Schema anpassen — nicht den Loader.

- [ ] **Step 3: Loader schreiben**

```php
<?php
// src/Services/Campaign/NewDatesCampaignRecipients.php

namespace Platform\Recruiting\Services\Campaign;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Baut aus der Kohorten-ID-Liste des Statistik-Modals die Zeilen fuer die
 * Kampagne „Neue Termine“: Anzeige-Daten plus das Ergebnis der Segmentregel.
 *
 * Gebuendelte Queries (eine pro Tabelle), nicht pro Zeile — auf der
 * Statistik-Seite ist das Query-Budget Abnahmekriterium. Ergebnis ist nach
 * applicant_id geschluesselt, Reihenfolge wie angefragt; Team-fremde oder
 * fehlende IDs tauchen nicht auf (forTeam ist das aeussere Schloss, wie in
 * Statistics\Index::drillApplicants).
 */
final class NewDatesCampaignRecipients
{
    public const LOG_TYPE_CAMPAIGN = 'campaign_sent';

    /**
     * @param list<int> $applicantIds
     * @return array<int, array{applicant_id:int, name:string, applied_at:?string, phase:string, template:string, selectable:bool, checked:bool, badges:list<string>}>
     */
    public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array
    {
        $ids = array_values(array_unique(array_map('intval', $applicantIds)));
        if ($ids === []) {
            return [];
        }

        $applicants = RecApplicant::forTeam($teamId)
            ->whereIn('id', $ids)
            ->with([
                'phase',
                'position.phases',
                'postings.position.phases',
                'interviewBookings',
                'crmContactLinks.contact.phoneNumbers',
            ])
            ->get()
            ->keyBy('id');

        $waitlist = RecInterviewWaitlist::query()
            ->whereIn('rec_applicant_id', $ids)
            ->ortBased()
            ->open()
            ->orderBy('id')
            ->get()
            ->groupBy('rec_applicant_id');

        $lastCampaign = RecAutoPilotLog::query()
            ->whereIn('rec_applicant_id', $ids)
            ->where('type', self::LOG_TYPE_CAMPAIGN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('rec_applicant_id')
            ->map(fn ($logs) => $logs->first());

        $rows = [];
        foreach ($ids as $id) {
            $a = $applicants->get($id);
            if ($a === null) {
                continue;
            }

            $phases = $a->primaryPosition()?->phases
                ?->map(fn ($p) => [
                    'order' => (int) $p->order,
                    'completion_type' => $p->completion_type,
                    'completion_config' => $p->completion_config,
                    'is_active' => (bool) $p->is_active,
                ])->values()->all() ?? [];

            $cancelled = $a->interviewBookings
                ->where('status', 'cancelled')
                ->map(fn ($b) => [
                    'cancelled_by' => $b->cancelled_by,
                    'cancelled_at' => $b->cancelled_at?->format('Y-m-d H:i:s'),
                ])->values()->all();

            $wl = $waitlist->get($id)?->first();
            $lc = $lastCampaign->get($id);

            $segment = CampaignSegment::classify([
                'phase_order' => $a->phase?->order,
                'booking_order' => CampaignSegment::bookingOrder($phases),
                'has_phone' => $a->primaryContactPhone() !== null,
                'has_active_booking' => $a->interviewBookings->where('status', '!=', 'cancelled')->isNotEmpty(),
                'on_hr_desk' => (bool) $a->is_on_hr_desk,
                'last_campaign_at' => $lc?->created_at?->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'cancelled_bookings' => $cancelled,
                'waitlist' => $wl === null ? null : [
                    'enrolled_at' => $wl->enrolled_at?->format('Y-m-d H:i:s'),
                    'notified_at' => $wl->notified_at?->format('Y-m-d H:i:s'),
                ],
            ]);

            $rows[$id] = [
                'applicant_id' => (int) $a->id,
                'name' => (string) ($a->crmContactLinks->first()?->contact?->full_name ?: ('Bewerber #' . $a->id)),
                'applied_at' => $a->applied_at?->format('Y-m-d'),
                'phase' => (string) ($a->phase?->name ?? 'ohne Phase'),
            ] + $segment;
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/NewDatesCampaignRecipientsTest.php`
Expected: PASS (2). Bei Schema-Fehlern der CRM-Tabellen: Relationen in `RecApplicant::crmContactLinks()` und `CrmContact::phoneNumbers()` nachlesen und das Test-Schema angleichen.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Campaign/NewDatesCampaignRecipients.php tests/Integration/NewDatesCampaignRecipientsTest.php
git commit -m "feat(recruiting): NewDatesCampaignRecipients — gebuendelte Ladung der Kampagnen-Zeilen aus der Statistik-Kohorte

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: `NewDatesCampaignSender` — ein Template an einen Bewerber

**Files:**
- Create: `src/Services/Campaign/NewDatesCampaignSender.php`
- Test: `tests/Integration/NewDatesCampaignSenderTest.php`

**Interfaces:**
- Consumes: `HoldingTemplateSender::resolveTemplate()` (Task 4), `HoldingTemplateComponents::build()` / `::hasEmptyRequiredParam()`, `WhatsAppTemplateUrlButtons::hasDynamicAt()`, `RecApplicant::primaryContactPhone()`, `RecApplicant::getOrCreatePublicFormLink()->token`, `WhatsAppMetaService::sendTemplate()` via `app()`.
- Produces:
  - `NewDatesCampaignSender::__construct(HoldingTemplateSender $sender, ?\Closure $tokenResolver = null)` — `$tokenResolver(RecApplicant): string`, Default `getOrCreatePublicFormLink()->token`.
  - `NewDatesCampaignSender::send(RecApplicant $applicant, int $templateId, string $segment, string $campaignUuid, ?int $sentByUserId): array{status:string, error:?string}`
  - Konstanten `STATUS_SENT`, `STATUS_NO_PHONE`, `STATUS_NOT_CONFIGURED`, `STATUS_TEMPLATE_WITHOUT_URL_BUTTON`, `STATUS_FAILED`, `URL_BUTTON_INDEX = 0`, `LOG_TYPE = 'campaign_sent'`.

- [ ] **Step 1: Failing Test**

```php
<?php
// tests/Integration/NewDatesCampaignSenderTest.php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Der Sender wird gegen eine Attrappe des HoldingTemplateSender (Kanal-
 * Aufloesung) und eine Attrappe des WhatsAppMetaService geprueft — die echte
 * Aufloesung ist in HoldingTemplateSenderResolveTargetTest belegt.
 *
 * Geprueft: Body ohne Variablen bleibt leer, {{name}} wird zum Vornamen, der
 * URL-Button traegt den Personen-Token, das Log traegt Kampagne + Segment, und
 * Fehlerwege liefern den richtigen Status ohne Log.
 */
final class NewDatesCampaignSenderTest extends TestCase
{
    private Capsule $capsule;
    private object $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->integer('rec_phase_id')->nullable(); $t->integer('rec_position_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        $s->create('rec_applicant_contact_links', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->integer('contact_id'); $t->timestamps();
        });
        $s->create('crm_contacts', function ($t) {
            $t->increments('id'); $t->string('first_name')->nullable(); $t->string('last_name')->nullable();
            $t->string('full_name')->nullable(); $t->string('display_name')->nullable(); $t->timestamps();
        });
        $s->create('crm_phone_numbers', function ($t) {
            $t->increments('id'); $t->string('phoneable_type')->nullable(); $t->integer('phoneable_id')->nullable();
            $t->integer('contact_id')->nullable(); $t->string('raw_input')->nullable(); $t->string('international')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_primary')->default(false); $t->timestamps();
        });
        // Extra-Felder liest der Vorname-Fallback nicht — wir nehmen den CRM-Vornamen.

        $this->meta = new class {
            public array $calls = [];
            public bool $throw = false;
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                if ($this->throw) {
                    throw new \RuntimeException('Meta 131026');
                }
                $this->calls[] = compact('to', 'templateName', 'components', 'languageCode');
                return (object) ['id' => count($this->calls), 'thread' => null];
            }
        };
        $container->instance(WhatsAppMetaService::class, $this->meta);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(bool $phone = true): RecApplicant
    {
        $a = RecApplicant::forceCreate(['team_id' => 3]);
        Capsule::table('crm_contacts')->insert(['id' => 500, 'first_name' => 'Lea', 'last_name' => 'Paulsen', 'full_name' => 'Lea Paulsen']);
        Capsule::table('rec_applicant_contact_links')->insert(['rec_applicant_id' => $a->id, 'contact_id' => 500]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert(['phoneable_type' => 'crm_contact', 'phoneable_id' => 500, 'contact_id' => 500, 'raw_input' => '0176', 'international' => '+4917672283401', 'is_active' => true, 'is_primary' => true]);
        }
        return $a->fresh();
    }

    /** @param array<string,mixed> $template Attribute des Templates (components etc.) */
    private function sender(array $template, ?string $resolveError = null): NewDatesCampaignSender
    {
        $tpl = new IntegrationsWhatsAppTemplate(array_merge(['name' => 'neue_termine_b', 'language' => 'de', 'status' => 'APPROVED'], $template));
        $tpl->id = 77;
        $channel = (object) ['id' => 9, 'sender_identifier' => '+49100'];
        $holding = new class($tpl, $channel, $resolveError) extends HoldingTemplateSender {
            public function __construct(private $tpl, private $channel, private ?string $err) {}
            public function resolveTemplate(int $teamId, int $templateId): array
            {
                return $this->err !== null
                    ? ['error' => $this->err, 'template' => null, 'channel' => null]
                    : ['error' => null, 'template' => $this->tpl, 'channel' => $this->channel];
            }
        };
        return new NewDatesCampaignSender($holding, fn (RecApplicant $a) => 'tok' . $a->id);
    }

    private const BUTTON_B = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Termine ansehen', 'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/interviews/{{1}}']]];

    public function testOhneBodyVariablenNurButtonMitToken(): void
    {
        $a = $this->applicant();
        $sender = $this->sender(['components' => [['type' => 'BODY', 'text' => 'Huhu, es sind neue Termine online!'], self::BUTTON_B]]);

        $r = $sender->send($a, 77, 'B', 'uuid-1', 42);

        $this->assertSame(NewDatesCampaignSender::STATUS_SENT, $r['status']);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame('+4917672283401', $call['to']);
        $this->assertSame('neue_termine_b', $call['templateName']);
        $this->assertSame([[
            'type' => 'button', 'sub_type' => 'url', 'index' => 0,
            'parameters' => [['type' => 'text', 'text' => 'tok' . $a->id]],
        ]], $call['components'], 'Kein Body-Component, wenn das Template keine Variablen hat.');

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', 'campaign_sent')->first();
        $this->assertNotNull($log);
        $this->assertSame('uuid-1', $log->details['campaign']);
        $this->assertSame('B', $log->details['segment']);
        $this->assertSame('neue_termine_b', $log->details['template']);
        $this->assertSame(42, $log->details['sent_by']);
    }

    public function testNameVariableWirdZumVornamen(): void
    {
        $a = $this->applicant();
        $sender = $this->sender(['components' => [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, neue Termine!', 'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Max']]]],
            self::BUTTON_B,
        ]]);

        $r = $sender->send($a, 77, 'B', 'uuid-2', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_SENT, $r['status']);
        $body = $this->meta->calls[0]['components'][0];
        $this->assertSame('body', $body['type']);
        $this->assertSame('Lea', $body['parameters'][0]['text']);
        $this->assertSame('name', $body['parameters'][0]['parameter_name']);
        $this->assertSame('button', $this->meta->calls[0]['components'][1]['type']);
    }

    public function testOhneTelefonKeinVersandKeinLog(): void
    {
        $a = $this->applicant(phone: false);
        $r = $this->sender(['components' => [self::BUTTON_B]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_NO_PHONE, $r['status']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    public function testTemplateOhneDynamischenButtonWirdVerweigert(): void
    {
        $a = $this->applicant();
        $r = $this->sender(['components' => [['type' => 'BODY', 'text' => 'x'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://rheingedeck.de/fest']]]]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_TEMPLATE_WITHOUT_URL_BUTTON, $r['status']);
        $this->assertCount(0, $this->meta->calls);
    }

    public function testAufloesungsFehlerWirdDurchgereicht(): void
    {
        $a = $this->applicant();
        $r = $this->sender(['components' => [self::BUTTON_B]], 'Kein aktiver WhatsApp-Kanal für den Account.')->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_NOT_CONFIGURED, $r['status']);
        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $r['error']);
    }

    public function testMetaFehlerIstFailedOhneLog(): void
    {
        $a = $this->applicant();
        $this->meta->throw = true;
        $r = $this->sender(['components' => [self::BUTTON_B]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_FAILED, $r['status']);
        $this->assertStringContainsString('Meta 131026', (string) $r['error']);
        $this->assertSame(0, RecAutoPilotLog::where('type', 'campaign_sent')->count());
        $this->assertSame(1, RecAutoPilotLog::where('type', 'error')->count(), 'Fehler wird als error-Log festgehalten.');
    }
}
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/NewDatesCampaignSenderTest.php`
Expected: FAIL — Klasse nicht gefunden.

- [ ] **Step 3: Sender schreiben**

```php
<?php
// src/Services/Campaign/NewDatesCampaignSender.php

namespace Platform\Recruiting\Services\Campaign;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Kampagne „Neue Termine“: EIN Template an EINEN Bewerber, der Personen-Token
 * im dynamischen URL-Button an Position 0.
 *
 * Muster TrainingCertificateWhatsAppDelivery: Template + Kanal kommen aus dem
 * HoldingTemplateSender (lesend), den Body baut HoldingTemplateComponents
 * (Aufruf, keine Erweiterung), gesendet wird direkt ueber
 * WhatsAppMetaService::sendTemplate(). Der Guard „dynamischer Button an
 * Position 0“ ist die SENDEBEDINGUNG — ohne Variable im Button gaebe es keinen
 * Link, und eine Kampagne ohne Link ist Spam.
 *
 * Der Token ist derselbe fuer /form/ und /recruiting/interviews/ — welche
 * Seite sich oeffnet, entscheidet allein die Basis-URL im bei Meta genehmigten
 * Template. Der Sender kennt den Unterschied A/B nur fuers Log.
 */
final class NewDatesCampaignSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button';
    public const STATUS_FAILED = 'failed';

    public const URL_BUTTON_INDEX = 0;
    public const LOG_TYPE = 'campaign_sent';

    private \Closure $tokenResolver;

    /**
     * @param \Closure(RecApplicant):string|null $tokenResolver Default: kanonischer
     *        Public-Token des Bewerbers (CorePublicFormLink). Injizierbar, damit
     *        Tests ohne Core-Tabellen laufen.
     */
    public function __construct(
        private readonly HoldingTemplateSender $sender,
        ?\Closure $tokenResolver = null,
    ) {
        $this->tokenResolver = $tokenResolver
            ?? fn (RecApplicant $a): string => (string) $a->getOrCreatePublicFormLink()->token;
    }

    /**
     * @param string $segment CampaignSegment::TEMPLATE_FORM|TEMPLATE_BOOKING — nur fuers Log
     * @return array{status:string, error:?string}
     */
    public function send(RecApplicant $applicant, int $templateId, string $segment, string $campaignUuid, ?int $sentByUserId): array
    {
        $phone = $applicant->primaryContactPhone();
        if ($phone === null) {
            return ['status' => self::STATUS_NO_PHONE, 'error' => 'Keine Telefonnummer am Kontakt.'];
        }

        $target = $this->sender->resolveTemplate((int) $applicant->team_id, $templateId);
        if ($target['error'] !== null) {
            return ['status' => self::STATUS_NOT_CONFIGURED, 'error' => $target['error']];
        }
        $template = $target['template'];
        $components = $template->components ?? [];

        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($components, self::URL_BUTTON_INDEX)) {
            return [
                'status' => self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
                'error' => 'Template „' . $template->name . '“ hat keinen dynamischen URL-Button an Position 0 — ohne ihn kein Link.',
            ];
        }

        $token = ($this->tokenResolver)($applicant);
        if (trim($token) === '') {
            return ['status' => self::STATUS_FAILED, 'error' => 'Kein Public-Token für den Bewerber.'];
        }

        $sendComponents = HoldingTemplateComponents::build($components, $this->firstName($applicant));
        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return ['status' => self::STATUS_FAILED, 'error' => 'Leerer Pflicht-Parameter im Body (meist der Vorname).'];
        }
        $sendComponents[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => self::URL_BUTTON_INDEX,
            'parameters' => [['type' => 'text', 'text' => $token]],
        ];

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $target['channel'],
                to: $phone,
                templateName: (string) $template->name,
                components: $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            $this->log($applicant, 'error', 'Kampagne „Neue Termine“: Versand fehlgeschlagen — ' . $e->getMessage(), [
                'campaign' => $campaignUuid, 'template' => (string) $template->name, 'segment' => $segment,
            ]);

            return ['status' => self::STATUS_FAILED, 'error' => $e->getMessage()];
        }

        // Ab hier ist die WhatsApp RAUS — Buchhaltung darf den Erfolg nicht
        // mehr kippen (Muster RecApplicant::sendBookingLinkWhatsApp).
        try {
            if ($thread = $message->thread ?? null) {
                $thread->addContext($applicant->getMorphClass(), $applicant->id, 'campaign');
            }
        } catch (\Throwable $e) {
            Log::warning('[NewDatesCampaign] Thread-Kontext fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), ['applicant_id' => $applicant->id]);
        }

        $this->log($applicant, self::LOG_TYPE, 'Kampagne „Neue Termine“ gesendet (Template ' . $segment . ': ' . $template->name . ').', [
            'campaign' => $campaignUuid,
            'template' => (string) $template->name,
            'segment' => $segment,
            'phase_id' => $applicant->rec_phase_id,
            'sent_by' => $sentByUserId,
        ]);

        return ['status' => self::STATUS_SENT, 'error' => null];
    }

    private function firstName(RecApplicant $applicant): string
    {
        $applicant->loadMissing('crmContactLinks.contact');
        $contact = $applicant->crmContactLinks->sortBy('contact_id')->first()?->contact;
        $name = trim((string) ($contact?->first_name ?? ''));

        return $name !== '' ? $name : 'Bewerber/in';
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details): void
    {
        try {
            $log = new RecAutoPilotLog([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => $summary,
                'details' => $details,
            ]);
            $log->created_at = now();
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('[NewDatesCampaign] Log fehlgeschlagen: ' . $e->getMessage(), ['applicant_id' => $applicant->id, 'type' => $type]);
        }
    }
}
```

Falls `Log::warning` im Test eine `ReflectionException`/Facade-Fehler wirft: Log-Attrappe in `setUp()` binden (Memory „Log-Attrappe braucht clearResolvedInstance“): `$container->instance('log', new class { public function __call($m, $a) {} });` vor `Facade::clearResolvedInstances()`.

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/NewDatesCampaignSenderTest.php`
Expected: PASS (6)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Campaign/NewDatesCampaignSender.php tests/Integration/NewDatesCampaignSenderTest.php
git commit -m "feat(recruiting): NewDatesCampaignSender — Kampagnen-Template mit Personen-Token im URL-Button, Log campaign_sent

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Job `SendNewDatesCampaign`

**Files:**
- Create: `src/Jobs/SendNewDatesCampaign.php`
- Test: `tests/Integration/SendNewDatesCampaignJobTest.php`

**Interfaces:**
- Consumes: `NewDatesCampaignRecipients::load()` (Task 6), `NewDatesCampaignSender::send()` (Task 7), `RecApplicant::rearmAutoPilot()` (Task 5), `RecInterviewWaitlist` Scopes, `RecAutoPilotLog`.
- Produces:
  - `new SendNewDatesCampaign(string $campaignUuid, int $teamId, ?int $userId, array $applicantIds, ?int $templateAId, ?int $templateBId)`
  - `SendNewDatesCampaign::cacheKey(string $uuid): string` → `'recruiting:campaign:' . $uuid`
  - `SendNewDatesCampaign::initialProgress(int $total): array{total:int, sent:int, failed:int, skipped:int, done:bool, errors:list<string>}`
  - `handle(\Illuminate\Contracts\Cache\Repository $cache, NewDatesCampaignRecipients $recipients, NewDatesCampaignSender $sender): void`

- [ ] **Step 1: Failing Test**

```php
<?php
// tests/Integration/SendNewDatesCampaignJobTest.php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\SendNewDatesCampaign;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;

/**
 * Der Job orchestriert: Re-Check → Template nach Segment → Senden → Re-Arm →
 * Ort-Warteliste schliessen → Fortschritt. Sender und Loader sind Attrappen
 * (ihre Tests stehen daneben); hier zaehlt die Reihenfolge und dass ein
 * Fehlschlag den Zustand der Person NICHT anfasst.
 */
final class SendNewDatesCampaignJobTest extends TestCase
{
    private Capsule $capsule;
    private Repository $cache;
    private int $waitingId;
    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00');
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();
        $this->cache = new Repository(new ArrayStore());

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->integer('auto_pilot_state_id')->nullable(); $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable(); $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0); $t->integer('rec_phase_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_states', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('code'); $t->string('name');
            $t->text('description')->nullable(); $t->boolean('is_active')->default(true); $t->integer('team_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        $s->create('rec_interview_waitlist', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable(); $t->integer('team_id')->nullable(); $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable(); $t->timestamp('enrolled_at')->nullable(); $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
        });
        $this->waitingId = (int) RecAutoPilotState::create(['code' => 'waiting_for_applicant', 'name' => 'Wartet'])->id;
        $this->reviewId = (int) RecAutoPilotState::create(['code' => 'review_needed', 'name' => 'Prüfung'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id): RecApplicant
    {
        return RecApplicant::forceCreate(['id' => $id, 'team_id' => 3, 'auto_pilot_state_id' => $this->reviewId, 'auto_pilot_reminder_count' => 2]);
    }

    /** @param array<int, array> $rows  @param array<int, string> $statusById */
    private function run(array $rows, array $statusById, ?int $a = 10, ?int $b = 20): array
    {
        $recipients = new class($rows) extends NewDatesCampaignRecipients {
            public function __construct(private array $rows) {}
            public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array
            {
                return array_intersect_key($this->rows, array_flip($applicantIds));
            }
        };
        $sender = new class($statusById) extends NewDatesCampaignSender {
            public array $calls = [];
            public function __construct(private array $statusById) {}
            public function send(RecApplicant $applicant, int $templateId, string $segment, string $campaignUuid, ?int $sentByUserId): array
            {
                $this->calls[] = ['id' => $applicant->id, 'template' => $templateId, 'segment' => $segment];
                $status = $this->statusById[$applicant->id] ?? NewDatesCampaignSender::STATUS_SENT;
                return ['status' => $status, 'error' => $status === 'sent' ? null : 'Fehler ' . $applicant->id];
            }
        };

        $job = new SendNewDatesCampaign('uuid-x', 3, 42, array_keys($rows), $a, $b);
        $this->cache->put(SendNewDatesCampaign::cacheKey('uuid-x'), SendNewDatesCampaign::initialProgress(count($rows)), 86400);
        $job->handle($this->cache, $recipients, $sender);

        return ['calls' => $sender->calls, 'progress' => $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-x'))];
    }

    private function row(int $id, string $template, bool $selectable = true): array
    {
        return ['applicant_id' => $id, 'name' => 'N' . $id, 'applied_at' => null, 'phase' => 'P', 'template' => $template, 'selectable' => $selectable, 'checked' => true, 'badges' => []];
    }

    public function testSegmentWaehltDasTemplateUndErfolgReArmtUndSchliesstWarteliste(): void
    {
        $this->applicant(1); $this->applicant(2);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 2, 'team_id' => 3, 'wunschorte' => ['moenchengladbach'], 'enrolled_at' => now(), 'notified_at' => now()]);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 2, 'team_id' => 3, 'rec_interview_id' => 86, 'armed' => true, 'enrolled_at' => now()]); // Termin-Abo bleibt

        $r = $this->run([1 => $this->row(1, 'A'), 2 => $this->row(2, 'B')], []);

        $this->assertSame([['id' => 1, 'template' => 10, 'segment' => 'A'], ['id' => 2, 'template' => 20, 'segment' => 'B']], $r['calls']);
        $this->assertSame(2, $r['progress']['sent']);
        $this->assertTrue($r['progress']['done']);

        $a1 = RecApplicant::find(1);
        $this->assertSame($this->waitingId, (int) $a1->auto_pilot_state_id, 'Re-Arm nach Erfolg');
        $this->assertSame(0, (int) $a1->auto_pilot_reminder_count);

        $this->assertNotNull(RecInterviewWaitlist::where('rec_applicant_id', 2)->whereNull('rec_interview_id')->value('cancelled_at'), 'Ort-Eintrag geschlossen');
        $this->assertNull(RecInterviewWaitlist::where('rec_applicant_id', 2)->where('rec_interview_id', 86)->value('cancelled_at'), 'Termin-Abo nicht angefasst');
        $this->assertSame(1, RecAutoPilotLog::where('rec_applicant_id', 2)->where('type', 'waitlist_replaced')->count());
    }

    public function testFehlschlagLaesstZustandStehen(): void
    {
        $this->applicant(3);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 3, 'team_id' => 3, 'wunschorte' => ['moenchengladbach'], 'enrolled_at' => now()]);

        $r = $this->run([3 => $this->row(3, 'B')], [3 => NewDatesCampaignSender::STATUS_FAILED]);

        $this->assertSame(1, $r['progress']['failed']);
        $this->assertSame(['N3: Fehler 3'], $r['progress']['errors']);
        $this->assertSame($this->reviewId, (int) RecApplicant::find(3)->auto_pilot_state_id, 'kein Re-Arm');
        $this->assertNull(RecInterviewWaitlist::where('rec_applicant_id', 3)->value('cancelled_at'), 'Warteliste offen');
    }

    public function testNichtWaehlbareUndFehlendeTemplatesWerdenUebersprungen(): void
    {
        $this->applicant(4); $this->applicant(5); $this->applicant(6);

        $r = $this->run(
            [4 => $this->row(4, 'B', selectable: false), 5 => $this->row(5, 'A'), 6 => $this->row(6, 'B')],
            [],
            a: null, // Template A fehlt → 5 wird uebersprungen
        );

        $this->assertSame([['id' => 6, 'template' => 20, 'segment' => 'B']], $r['calls']);
        $this->assertSame(2, $r['progress']['skipped']);
        $this->assertSame(1, $r['progress']['sent']);
        $this->assertSame($this->reviewId, (int) RecApplicant::find(5)->auto_pilot_state_id, 'uebersprungen = nicht angefasst');
    }

    public function testFortschrittZaehltAuchOhneCacheEintrag(): void
    {
        $this->applicant(7);
        $recipients = new class extends NewDatesCampaignRecipients {
            public function __construct() {}
            public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array { return []; }
        };
        $sender = new class extends NewDatesCampaignSender { public function __construct() {} };

        (new SendNewDatesCampaign('uuid-y', 3, null, [7], 10, 20))->handle($this->cache, $recipients, $sender);

        $p = $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-y'));
        $this->assertTrue($p['done']);
        $this->assertSame(1, $p['skipped'], 'ID ohne Zeile (Team-fremd/geloescht) zaehlt als uebersprungen.');
    }
}
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/SendNewDatesCampaignJobTest.php`
Expected: FAIL — Klasse nicht gefunden.

- [ ] **Step 3: Job schreiben**

```php
<?php
// src/Jobs/SendNewDatesCampaign.php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Kampagne „Neue Termine“ (Spec §5.4). Pro Bewerber, in dieser Reihenfolge:
 *  1. Re-Check ueber den Loader (Stand kann sich seit dem Oeffnen des Modals
 *     geaendert haben: inzwischen gebucht, Telefon weg, Team-fremd)
 *  2. Template nach Segment (A = Formular, B = Terminauswahl)
 *  3. Senden
 *  4. Re-Arm des Auto-Piloten — NUR bei Erfolg
 *  5. Offene Ort-Wartelisten-Eintraege schliessen — NUR bei Erfolg
 *  6. Fortschritt im Cache
 *
 * Ein Fehlschlag laesst den Zustand der Person unangetastet: sie wurde nicht
 * erreicht, also bleibt sie, wie sie war. Fortschritt liegt unter cacheKey()
 * und wird vom Statistik-Modal gepollt.
 */
class SendNewDatesCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public const CACHE_TTL_SECONDS = 86400;
    public const MAX_ERRORS_KEPT = 20;

    /**
     * @param list<int> $applicantIds bereits gegen Kohorte und Waehlbarkeit geschnitten (CampaignSegment::selectedIds)
     */
    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $teamId,
        public readonly ?int $userId,
        public readonly array $applicantIds,
        public readonly ?int $templateAId,
        public readonly ?int $templateBId,
    ) {
    }

    public static function cacheKey(string $uuid): string
    {
        return 'recruiting:campaign:' . $uuid;
    }

    /** @return array{total:int, sent:int, failed:int, skipped:int, done:bool, errors:list<string>} */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => false, 'errors' => []];
    }

    public function handle(Cache $cache, NewDatesCampaignRecipients $recipients, NewDatesCampaignSender $sender): void
    {
        $key = self::cacheKey($this->campaignUuid);
        $progress = $cache->get($key) ?? self::initialProgress(count($this->applicantIds));
        $now = new \DateTimeImmutable();

        $rows = $recipients->load($this->teamId, $this->applicantIds, $now);

        foreach ($this->applicantIds as $id) {
            $row = $rows[(int) $id] ?? null;

            if ($row === null || $row['selectable'] !== true) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $templateId = $row['template'] === CampaignSegment::TEMPLATE_FORM ? $this->templateAId : $this->templateBId;
            if (!$templateId) {
                $progress['skipped']++;
                $this->keepError($progress, $row['name'] . ': kein Template ' . $row['template'] . ' gewählt');
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $applicant = RecApplicant::forTeam($this->teamId)->find((int) $id);
            if ($applicant === null) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $result = $sender->send($applicant, (int) $templateId, $row['template'], $this->campaignUuid, $this->userId);

            if ($result['status'] !== NewDatesCampaignSender::STATUS_SENT) {
                $progress['failed']++;
                $this->keepError($progress, $row['name'] . ': ' . ($result['error'] ?? $result['status']));
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $applicant->rearmAutoPilot('Kampagne „Neue Termine“');
            $this->closeOrtWaitlist($applicant);

            $progress['sent']++;
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        }

        $progress['done'] = true;
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    /**
     * Offene Ort-Eintraege schliessen — die Kampagne IST die Benachrichtigung,
     * und ein offener Eintrag wuerde den re-armten Auto-Pilot sofort wieder
     * pausieren (ProcessAutoPilotApplicants::170). Termin-Abos
     * (rec_interview_id gesetzt) bleiben: die haben eigene Re-Arm-Logik.
     */
    private function closeOrtWaitlist(RecApplicant $applicant): void
    {
        $closed = RecInterviewWaitlist::query()
            ->where('rec_applicant_id', $applicant->id)
            ->ortBased()
            ->open()
            ->update(['cancelled_at' => now()]);

        if ($closed > 0) {
            try {
                $log = new RecAutoPilotLog([
                    'rec_applicant_id' => $applicant->id,
                    'type' => 'waitlist_replaced',
                    'summary' => 'Ort-Warteliste durch Kampagne „Neue Termine“ abgelöst (' . $closed . ' Eintrag/Einträge geschlossen).',
                    'details' => ['campaign' => $this->campaignUuid],
                ]);
                $log->created_at = now();
                $log->save();
            } catch (\Throwable) {
                // Log darf den Versand nicht kippen.
            }
        }
    }

    private function keepError(array &$progress, string $line): void
    {
        if (count($progress['errors']) < self::MAX_ERRORS_KEPT) {
            $progress['errors'][] = $line;
        }
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/SendNewDatesCampaignJobTest.php`
Expected: PASS (4). Scheitert `now()` ohne Container-Bindings: `Carbon::setTestNow` ist gesetzt, `now()` ist ein Helper auf `Carbon::now()` — sollte laufen; sonst `Illuminate\Support\Carbon::now()` direkt verwenden.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/SendNewDatesCampaign.php tests/Integration/SendNewDatesCampaignJobTest.php
git commit -m "feat(recruiting): Job SendNewDatesCampaign — Versand, Re-Arm, Wartelisten-Abloesung, Fortschritt im Cache

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: Statistik-Modal — Auswahl, Badges, Kampagnen-Start, Fortschritt

**Files:**
- Modify: `src/Livewire/Statistics/Index.php` (Properties bei `$drillIds` ~Zeile 253; `resetDrill()` ~Zeile 260; `drill()` ~Zeile 1568–1606; neue Methoden nach `drillApplicants()`)
- Modify: `resources/views/livewire/statistics/index.blade.php:762-794` (Drill-Modal)
- Test: `tests/Unit/Statistics/CampaignModalStateTest.php` (Create)

**Interfaces:**
- Consumes: `NewDatesCampaignRecipients::load()`, `SendNewDatesCampaign` (Konstruktor, `cacheKey()`, `initialProgress()`), `CampaignSegment::selectedIds()`, `RecApplicantSettings::getSetting()`.
- Produces (Livewire-Komponente `Index`):
  - `#[Locked] public string $drillScopeType = ''` — `'ohne_schulung'` schaltet den Kampagnen-Bereich frei
  - `public array $campaignSelection = []` (applicant_id ⇒ bool), `public ?int $campaignTemplateA`, `public ?int $campaignTemplateB`, `public ?string $campaignUuid = null`, `public string $campaignError = ''`
  - `#[Computed] campaignRows(): array` (Ergebnis des Loaders, Schlüssel applicant_id)
  - `#[Computed] campaignTemplates(): array` (approved Templates `[{id,label}]`)
  - `#[Computed] campaignProgress(): ?array`
  - `campaignSelectAll(bool $on): void`, `campaignSelectedIds(): array`, `startCampaign(): void`
  - `public function campaignEnabled(): bool` → `$this->drillScopeType === 'ohne_schulung' && $this->drillIds !== []`

- [ ] **Step 1: Failing Unit-Test für den reinen Zustand**

```php
<?php
// tests/Unit/Statistics/CampaignModalStateTest.php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Der Kampagnen-Bereich ist nur im Scope „Ohne Termin“ erreichbar — die
 * anderen Drill-Downs (gebucht, unterschrieben, Termin-Teilnehmer) zeigen ihn
 * nicht. Geprueft ohne Container (new Index(), Muster FremdeFilialeReasonTextTest).
 */
final class CampaignModalStateTest extends TestCase
{
    public function testKampagneNurImScopeOhneSchulung(): void
    {
        $c = new Index();
        $c->drillIds = [1, 2];

        $c->drillScopeType = 'ohne_schulung';
        $this->assertTrue($c->campaignEnabled());

        $c->drillScopeType = 'schulung';
        $this->assertFalse($c->campaignEnabled());

        $c->drillScopeType = 'ohne_schulung';
        $c->drillIds = [];
        $this->assertFalse($c->campaignEnabled(), 'Leere Auswahl → kein Button.');
    }

    public function testDefaultsDerProperties(): void
    {
        $c = new Index();
        $this->assertSame('', $c->drillScopeType);
        $this->assertSame([], $c->campaignSelection);
        $this->assertNull($c->campaignUuid);
        $this->assertSame('', $c->campaignError);
    }
}
```

- [ ] **Step 2: Laufen lassen — muss fehlschlagen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Statistics/CampaignModalStateTest.php`
Expected: FAIL — `Call to undefined method ... campaignEnabled()` bzw. undefined property.

- [ ] **Step 3: Komponente erweitern**

(a) Properties — direkt nach `public bool $showDrill = false;`:

```php
    /**
     * Kampagne „Neue Termine“ (Spec §5.3). Der Scope-Typ kommt aus dem Drill-
     * Token und ist locked wie $drillIds: nur die Kachel/Zeilen „Ohne Termin“
     * (ohne_schulung) schalten den Kampagnen-Bereich frei.
     *
     * $campaignSelection ist NICHT locked (der Client hakt an/ab), wird aber
     * serverseitig gegen Kohorte UND Waehlbarkeit geschnitten
     * (CampaignSegment::selectedIds) — nur IDs aus dem Modal werden je versendet.
     */
    #[Locked]
    public string $drillScopeType = '';
    /** @var array<int,bool> applicant_id => angehakt */
    public array $campaignSelection = [];
    public ?int $campaignTemplateA = null;
    public ?int $campaignTemplateB = null;
    public ?string $campaignUuid = null;
    public string $campaignError = '';
```

(b) `resetDrill()` ergänzen (nach `$this->drillLabel = '';`):

```php
        $this->drillScopeType = '';
        $this->campaignSelection = [];
        $this->campaignUuid = null;
        $this->campaignError = '';
```

(c) In `drill()` nach `$this->drillIds = $vm->resolveIdsFromClient($rows, $spec, $column);` einfügen:

```php
        $this->drillScopeType = (string) ($spec['type'] ?? '');
        $this->campaignSelection = [];
        $this->campaignUuid = null;
        $this->campaignError = '';
        if ($this->campaignEnabled()) {
            // Vorauswahl aus der Segmentregel; Template-Defaults aus den Settings.
            foreach ($this->campaignRows as $id => $row) {
                $this->campaignSelection[$id] = $row['checked'];
            }
            $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());
            $this->campaignTemplateA = $this->campaignTemplateA ?: (int) ($settings->getSetting('campaign_form_wa_template_id') ?? 0) ?: null;
            $this->campaignTemplateB = $this->campaignTemplateB ?: (int) ($settings->getSetting('campaign_booking_wa_template_id') ?? 0) ?: null;
        }
```

`use Platform\Recruiting\Models\RecApplicantSettings;`, `use Platform\Recruiting\Jobs\SendNewDatesCampaign;`, `use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;`, `use Platform\Recruiting\Support\CampaignSegment;`, `use Illuminate\Support\Facades\Cache;`, `use Illuminate\Support\Str;` oben ergänzen (falls nicht vorhanden).

(d) Neue Methoden nach `drillApplicants()`:

```php
    public function campaignEnabled(): bool
    {
        return $this->drillScopeType === 'ohne_schulung' && $this->drillIds !== [];
    }

    /**
     * Zeilen der Kampagne — Loader buendelt die Queries (Query-Budget §2).
     * Schluessel applicant_id, Reihenfolge wie $drillIds.
     *
     * @return array<int, array{applicant_id:int, name:string, applied_at:?string, phase:string, template:string, selectable:bool, checked:bool, badges:list<string>}>
     */
    #[Computed]
    public function campaignRows(): array
    {
        if (!$this->campaignEnabled()) {
            return [];
        }

        return app(NewDatesCampaignRecipients::class)->load($this->teamId(), $this->drillIds, new \DateTimeImmutable());
    }

    /** @return list<array{id:int,label:string}> approved Templates des Teams (Muster ApplicantSettingsModal) */
    #[Computed]
    public function campaignTemplates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }
        $accountId = RecApplicantSettings::getOrCreateForTeam($this->teamId())->getSetting('auto_pilot_wa_account_id');

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->when($accountId, fn ($q) => $q->where('whatsapp_account_id', (int) $accountId))
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'label' => "{$t->name} ({$t->language})"])
            ->values()
            ->all();
    }

    #[Computed]
    public function campaignProgress(): ?array
    {
        if ($this->campaignUuid === null) {
            return null;
        }

        return Cache::get(SendNewDatesCampaign::cacheKey($this->campaignUuid));
    }

    public function campaignSelectAll(bool $on): void
    {
        foreach ($this->campaignRows as $id => $row) {
            $this->campaignSelection[$id] = $on && $row['selectable'];
        }
    }

    /** @return list<int> */
    public function campaignSelectedIds(): array
    {
        $selectable = array_keys(array_filter($this->campaignRows, fn ($r) => $r['selectable']));

        return CampaignSegment::selectedIds($this->campaignSelection, $this->drillIds, $selectable);
    }

    /** Anzahl der gewaehlten Personen pro Template — fuer die Button-Sperre und den Zaehler. */
    public function campaignCounts(): array
    {
        $rows = $this->campaignRows;
        $a = 0; $b = 0;
        foreach ($this->campaignSelectedIds() as $id) {
            if (($rows[$id]['template'] ?? '') === CampaignSegment::TEMPLATE_FORM) { $a++; } else { $b++; }
        }

        return ['A' => $a, 'B' => $b, 'total' => $a + $b];
    }

    public function startCampaign(): void
    {
        $this->campaignError = '';
        if (!$this->campaignEnabled() || $this->campaignUuid !== null) {
            return;
        }
        $ids = $this->campaignSelectedIds();
        $counts = $this->campaignCounts();
        if ($ids === []) {
            $this->campaignError = 'Niemand ausgewählt.';
            return;
        }
        if ($counts['A'] > 0 && !$this->campaignTemplateA) {
            $this->campaignError = "Für {$counts['A']} Personen fehlt Template A (Bewerbung vervollständigen).";
            return;
        }
        if ($counts['B'] > 0 && !$this->campaignTemplateB) {
            $this->campaignError = "Für {$counts['B']} Personen fehlt Template B (Terminauswahl).";
            return;
        }

        $uuid = (string) Str::uuid();
        Cache::put(SendNewDatesCampaign::cacheKey($uuid), SendNewDatesCampaign::initialProgress(count($ids)), SendNewDatesCampaign::CACHE_TTL_SECONDS);
        SendNewDatesCampaign::dispatch(
            $uuid,
            $this->teamId(),
            auth()->id(),
            $ids,
            $counts['A'] > 0 ? (int) $this->campaignTemplateA : null,
            $counts['B'] > 0 ? (int) $this->campaignTemplateB : null,
        );
        $this->campaignUuid = $uuid;
    }
```

- [ ] **Step 4: Unit-Test laufen lassen**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Statistics/CampaignModalStateTest.php`
Expected: PASS (2)

- [ ] **Step 5: Blade — Modal umbauen**

`resources/views/livewire/statistics/index.blade.php`, den Block `<x-ui-modal wire:model="showDrill" size="lg" hideFooter>` … `</x-ui-modal>` ersetzen durch:

```blade
    @php
        $campaignEnabled = $this->campaignEnabled();
        $campaignRows = $campaignEnabled ? $this->campaignRows : [];
        $campaignProgress = $campaignEnabled ? $this->campaignProgress : null;
        $campaignCounts = $campaignEnabled ? $this->campaignCounts() : ['A' => 0, 'B' => 0, 'total' => 0];
        $campaignTemplates = $campaignEnabled ? $this->campaignTemplates : [];
        $campaignRunning = $campaignProgress !== null && !($campaignProgress['done'] ?? false);
    @endphp
    <x-ui-modal wire:model="showDrill" size="lg" :hideFooter="!$campaignEnabled">
        <x-slot name="header">
            {{ $this->drillLabel !== '' ? $this->drillLabel : 'Personen' }} ({{ count($this->drillIds) }})
        </x-slot>

        @if (count($this->drillIds) === 0)
            <div class="py-6 text-center text-sm text-[color:var(--ui-muted)]">Keine Personen in dieser Auswahl.</div>
        @elseif ($campaignEnabled)
            {{-- Kampagne „Neue Termine“: Auswahl + Badges. Polling nur, solange ein Versand laeuft. --}}
            <div @if ($campaignRunning) wire:poll.3s @endif>
                <div class="mb-2 flex items-center justify-between text-xs text-[color:var(--ui-muted)]">
                    <span>{{ $campaignCounts['total'] }} von {{ count($campaignRows) }} gewählt — {{ $campaignCounts['A'] }}× Template A (Bewerbung vervollständigen), {{ $campaignCounts['B'] }}× Template B (Terminauswahl)</span>
                    <span class="flex gap-2">
                        <button type="button" class="underline" wire:click="campaignSelectAll(true)">alle</button>
                        <button type="button" class="underline" wire:click="campaignSelectAll(false)">keine</button>
                    </span>
                </div>
                <ul class="divide-y divide-[var(--ui-border)]/60">
                    @foreach ($campaignRows as $id => $row)
                        @php
                            $rowDate = $row['applied_at'] ? \Illuminate\Support\Carbon::parse($row['applied_at'])->format('d.m.Y') : 'ohne Datum';
                            $rowDisabled = !$row['selectable'] || $campaignRunning;
                        @endphp
                        <li class="py-2 flex items-center gap-3 {{ $row['selectable'] ? '' : 'opacity-60' }}">
                            <input type="checkbox" class="h-4 w-4 rounded border-[var(--ui-border)]"
                                   wire:model="campaignSelection.{{ $id }}" @disabled($rowDisabled) />
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('recruiting.applicants.show', $id) }}" class="text-[color:var(--ui-primary)] hover:underline text-sm">{{ $row['name'] }}</a>
                                <span class="ml-2 text-xs text-[color:var(--ui-muted)]">{{ $row['phase'] }} · {{ $row['template'] }}</span>
                                @foreach ($row['badges'] as $badge)
                                    <span class="ml-1 inline-block rounded bg-[var(--ui-muted-5)] px-1.5 py-0.5 text-[11px] text-[color:var(--ui-muted)]">{{ $badge }}</span>
                                @endforeach
                            </div>
                            <span class="text-xs text-[color:var(--ui-muted)] whitespace-nowrap tabular-nums">{{ $rowDate }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            @php $drillApplicants = $this->drillApplicants; @endphp
            @if ($drillApplicants->count() !== count($this->drillIds))
                <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    {{ count($this->drillIds) }} IDs in der Auswahl, aber {{ $drillApplicants->count() }} Bewerber ladbar
                    (team-fremde oder gelöschte Datensätze werden nicht angezeigt).
                </div>
            @endif
            <ul class="divide-y divide-[var(--ui-border)]/60">
                @foreach ($drillApplicants as $applicant)
                    @php
                        $applicantName = $applicant->crmContactLinks->first()?->contact?->full_name;
                    @endphp
                    <li class="py-2 flex items-center justify-between gap-3">
                        <a href="{{ route('recruiting.applicants.show', $applicant) }}"
                           class="text-[color:var(--ui-primary)] hover:underline text-sm">
                            {{ $applicantName ?: 'Bewerber #' . $applicant->id }}
                        </a>
                        <span class="text-xs text-[color:var(--ui-muted)] whitespace-nowrap tabular-nums">
                            {{ $applicant->applied_at?->format('d.m.Y') ?? 'ohne Datum' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($campaignEnabled)
            <x-slot name="footer">
                <div class="w-full space-y-2">
                    @if ($campaignProgress !== null)
                        <div class="text-sm">
                            <strong>{{ $campaignProgress['sent'] }}</strong> / {{ $campaignProgress['total'] }} gesendet
                            · {{ $campaignProgress['failed'] }} Fehler · {{ $campaignProgress['skipped'] }} übersprungen
                            {!! ($campaignProgress['done'] ?? false) ? ' · <span class="text-green-700">abgeschlossen</span>' : ' · läuft …' !!}
                        </div>
                        @if (!empty($campaignProgress['errors']))
                            <ul class="text-xs text-red-700 list-disc pl-4">
                                @foreach ($campaignProgress['errors'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                            <x-ui-input-select
                                name="campaignTemplateA"
                                label="Template A — Bewerbung vervollständigen"
                                :options="$campaignTemplates"
                                optionValue="id"
                                optionLabel="label"
                                :nullable="true"
                                nullLabel="– Template wählen –"
                                wire:model="campaignTemplateA"
                            />
                            <x-ui-input-select
                                name="campaignTemplateB"
                                label="Template B — Terminauswahl"
                                :options="$campaignTemplates"
                                optionValue="id"
                                optionLabel="label"
                                :nullable="true"
                                nullLabel="– Template wählen –"
                                wire:model="campaignTemplateB"
                            />
                        </div>
                        @if ($this->campaignError !== '')
                            <div class="text-xs text-red-700">{{ $this->campaignError }}</div>
                        @endif
                        <div class="flex justify-end">
                            <x-ui-button variant="primary" wire:click="startCampaign" wire:loading.attr="disabled" wire:target="startCampaign" :disabled="$campaignCounts['total'] === 0">
                                Kampagne an {{ $campaignCounts['total'] }} Personen senden
                            </x-ui-button>
                        </div>
                    @endif
                </div>
            </x-slot>
        @endif
    </x-ui-modal>
```

Hinweise:
- `:hideFooter="!$campaignEnabled"` — prüfen, ob die Modal-Komponente ein Bool-Prop akzeptiert; falls sie nur das Attribut-Vorhandensein auswertet, stattdessen den Footer-Slot immer rendern und innen leer lassen.
- Keine Direktive an Wortzeichen kleben (`@if ($campaignRunning) wire:poll.3s @endif` steht mit Leerzeichen in einem Tag — das ist Block-Form innerhalb eines Tags und kompiliert; wenn `blade-check` meckert, `$pollAttr = $campaignRunning ? 'wire:poll.3s' : ''` vorberechnen und `{!! $pollAttr !!}` ausgeben).

- [ ] **Step 6: Blade prüfen + Render-Tests**

Run: `php tools/blade-check.php resources/views/livewire/statistics/index.blade.php`
Expected: keine Fehler.

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/StatisticsPageRenderTest.php tests/Integration/StatisticsTablesRenderTest.php tests/Integration/StatisticsPageStructureTest.php tests/Unit/Statistics`
Expected: PASS. `StatisticsPageRenderTest` prüft Attributnamen des gerenderten DOM gegen eine Whitelist — neue Attribute (`wire:poll.3s`, `wire:target`, `wire:loading.attr`, `disabled`, `type`) ggf. in die Whitelist des Tests aufnehmen (nur die Whitelist, nichts anderes im Test).

- [ ] **Step 7: Gesamte Suite**

Run: `../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle grün.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/index.blade.php tests/Unit/Statistics/CampaignModalStateTest.php tests/Integration/StatisticsPageRenderTest.php
git commit -m "feat(recruiting): Statistik-Modal Ohne Termin — Kampagne Neue Termine mit Auswahl, Badges, Template A/B und Fortschritt

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 10: Abschluss — Spec-Status, Sichtprüfung vorbereiten

**Files:**
- Modify: `docs/superpowers/specs/2026-08-28-neue-termine-kampagne-design.md` (Kopfzeile `Status:`)

- [ ] **Step 1: Spec-Status setzen**

`Stand: 28.08.2026 · Status: Entwurf zur Freigabe` → `Stand: 28.08.2026 · Status: umgesetzt auf feat/neue-termine-kampagne (Plan: docs/superpowers/plans/2026-08-28-neue-termine-kampagne.md)`

- [ ] **Step 2: Manuelle Prüfliste für den Reviewer/Deploy in die Commit-Message**

```bash
git add docs/superpowers/specs/2026-08-28-neue-termine-kampagne-design.md
git commit -m "docs(recruiting): Spec Neue Termine — Status umgesetzt; Deploy-Prueflist

Deploy:
- queue:restart Pflicht (neuer Job SendNewDatesCampaign)
- meingedeck composer.lock bumpen
- Settings campaign_form_wa_template_id / campaign_booking_wa_template_id setzen (ggf. JSON_SET, Select-Bug)
- Sichttest: Statistik MGL → Ohne Termin → Zaehler == Kachel; Vorauswahl P1/P2/P3 an, P4 aus, kein-Telefon nicht waehlbar
- Testversand an einen Test-Bewerber mit HR-Nummer, dann Kampagne

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 3: Branch pushen — NICHT mergen** (Merge nach Freigabe per ff, Memory „Kein gh CLI → ff-Merge-Workflow“)

```bash
git push -u origin feat/neue-termine-kampagne
```

---

## Self-Review (durchgeführt beim Schreiben)

**Spec-Abdeckung:** §5.1 → Task 1 · §5.2 → Task 2 (+Loader Task 6) · §5.3 → Task 9 · §5.4 → Tasks 7, 8 · §5.5 → Task 3 · §5.6 → nur Doku (Kundenseite) · §6 Fehlerbilder → Task 8 (Re-Check, Fehlerfall), Task 9 (Button-Sperre, Doppelklick via `campaignUuid !== null` + `wire:loading`) · §7 Tests → je Task · §8 Auslieferung → Task 10.

**Nicht im Plan (bewusst):** Meta-`failed`-Webhook-Tracking (Spec §5.4 letzter Punkt: Runde 1 ohne); Log-Flood und Wartelisten-Verfall (eigene Tickets).

**Typ-Konsistenz geprüft:** `CampaignSegment::classify()`-Eingabeschlüssel in Task 2 und Task 6 identisch; `NewDatesCampaignSender::send()`-Signatur in Task 7 und Task 8 identisch; `SendNewDatesCampaign`-Konstruktor in Task 8 und Task 9 identisch (`campaignUuid, teamId, userId, applicantIds, templateAId, templateBId`); Loader-Zeilenschlüssel (`applicant_id, name, applied_at, phase, template, selectable, checked, badges`) in Tasks 6, 8, 9 identisch.

**Bekannte Unsicherheiten für den Ausführenden:** exakte Spaltennamen der CRM-Tabellen im Test-Schema (Task 6/7 — gegen `platform-crm` prüfen, Test-Schema anpassen); Facade-Bindings (`Log`, `now()`) in Capsule-Tests (Muster in `HoldingTemplateSenderResolveTargetTest`/`TrainingCertificateWhatsAppDeliveryTest`); `x-ui-modal` Prop `hideFooter` als Bool (Task 9).
