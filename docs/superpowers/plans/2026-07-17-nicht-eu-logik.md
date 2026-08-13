# Nicht-EU-Logik: Prüfung nach der Schulung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nicht-EU-Bewerber laufen bis einschließlich Schulung wie EU-Bürger durch; der HR-Schreibtisch-Abzweig passiert erst bei "Teilgenommen", und HR sendet Verträge + Portallink selbst vom Schreibtisch (Nachbereitung-Semantik), wobei erfolgreicher Versand den Fall schließt.

**Spec:** `docs/superpowers/specs/2026-07-17-nicht-eu-logik-design.md` — die geteilten Fakten F1–F5 dort sind bindend; die tragenden sind unten in den Task-Verträgen wörtlich gepinnt. Code-Referenzen gegen main `1611c71`.

**Architecture:** Pure Entscheidungslogik (`NonEuPostTrainingGate`, `ContractSendEligibility`) + gemeinsamer Einzel-Versand-Service (`ContractDispatchService`, Task 1 — Bulk und Desk-Karte konsumieren ihn) + Observer-Trigger bei attended + einmaliger Migrations-Command. Ort der Wahrheit für "darf gesendet werden" ist die pure Eligibility; Ort der Wahrheit für die Versand-Sequenz ist der Dispatch-Service.

**Tech Stack:** PHP 8 / Laravel-Modul platforms-recruiting, Livewire 3, reines PHPUnit (Runner meingedeck-vendor), keine Migrationen (kein Schema-Change!).

## Global Constraints

- Test-Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` im Modul-Root. **Suite-Baseline: 191 Tests.** Tests OHNE Laravel/DB (nur `PHPUnit\Framework\TestCase`).
- **Kein Schema-Change.** Bestandsdaten werden ausschließlich vom manuellen Migrations-Command (Task 7) angefasst — Deploy selbst ändert keine Daten und dispatcht nichts.
- Blade: `@php ... @endphp` Block-Form, KEIN Inline-@php/@if in x-ui-*-Attributen, Werte vorberechnen. Buttons auf der HR-Karte als rohe `<button>` (bestehendes Muster).
- Der `LegalStatusGate` und der `HrDeskApprovalGate` bleiben als Klassen UNVERÄNDERT — es ändern sich nur Aufrufstellen.
- Verhalten des Bulk-Versands bleibt byte-gleich: WA-Menge = genau EINE Nachricht pro Bewerber (Employee-Portal-WA; Vertrags-WA via `skipNotification=true` bewusst unterdrückt — Bulk-Kommentar `InterviewBookings/Index.php:560-563`).
- Commits deutsch, conventional mit Scope `feat(recruiting):`/`fix(recruiting):`.
- Nach Push STOPP (Merge/Bump/Deploy separat nach Freigabe). Nach Deploy **`queue:restart` PFLICHT** (Reminder-Command + Observer im Worker). Live-Smoke-Pflichten: KEINE Buchungslink-WA nach Desk-Abschluss (F3), Lock-Badge in Nachbereitung erscheint ohne Reload, Migration zuerst `--dry-run`.

### Gepinnte Fakten (aus der Spec, für Task-Verträge)

- **F1:** `SendContractsService::send()` ruft selbst `checkAutoPilotCompletion()`; dessen Carve-out (`RecApplicant.php:417-426`) führt die Completion-Hooks auch bei `auto_pilot=false` aus (kein Advance). Mitarbeiter-Erzeugung feuert also IM Send-Aufruf; `approveCase` macht danach den Advance.
- **F2:** Hook-Satz = exakt 3 Hooks (EU-Sync mit Gleichheits-Early-Return; confirm_booking [in contract_sent-Phase nicht gesetzt, ohnehin 0-Zeilen-sicher]; createOrUpdate idempotent). Doppel-Lauf send()+approveCase ist grün.
- **F3:** `sendInterviewBookingNotification()` sitzt im Last-Phase-Branch (`RecApplicant.php:497-510`), Legacy-Fallback sendet bei FEHLENDEM Key + keiner Folge-Phase. Audit-Pflicht in Task 8.
- **F4:** `send()` ist idempotent (Reuse, `sent_at`-Guard, `nowSentCount`); Portallink-Schritt wird über `hasAnyContractSent()` geschützt (Bulk `:522`); `skipNotification=true` unterdrückt NUR die bewerberseitige Vertrags-Portal-WA.
- **F5:** `REASON_NON_EU_CITIZEN` wird nur in `evaluateAndRoute()` geroutet (`HrDeskRoutingService.php:43`); einziger Einstieg `setEuCitizen()`.
- **F6 (neu, Task-1-relevant):** Der Bulk validiert Vertragsbeginn als PFLICHT (`sendPortalLinkBulk` `$missingBeginn`-Abbruch) und Zuschlag über `bulkSendState()` (`missing_zuschlag`) + Throw im Service — das Desk-Prädikat ist damit byte-gleich definierbar.

---

### Task 0: Branch anlegen

- [ ] **Step 1:**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin && git log --oneline -1 origin/main
git checkout -b feature/nicht-eu-nach-schulung origin/main
```

---

### Task 1: `ContractSendEligibility` (pure, TDD) + `ContractDispatchService`

**Files:**
- Create: `src/Services/ContractSendEligibility.php`
- Create: `tests/Unit/ContractSendEligibilityTest.php`
- Create: `src/Services/ContractDispatchService.php`

**Interfaces:**
- Consumes (gepinnt): F1, F2, F4, F6 wörtlich wie oben. Bestehende Bausteine: `SendContractsService::send(RecApplicant $applicant, ?int $createdByUserId, ?array $contractFields, bool $skipNotification)`, `RecApplicant::hasAnyContractSent(): bool`, `RecEmployee::sendPortalNotification()`, `RecContractTemplate` (AV-default via `code = 'AV-default'`, aktiv).
- Produces:
  - `ContractSendEligibility::state(bool $hasSent, bool $legalBlocked, bool $hasBeginn, bool $hasZuschlag): string` → `'already_sent'|'legal_blocked'|'missing_beginn'|'missing_zuschlag'|'ready'` (Prüf-Reihenfolge exakt wie der Bulk: sent → legal → beginn → zuschlag). Tasks 5 und 6 konsumieren das als gemeinsames Prädikat.
  - `ContractDispatchService::sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?RecContractTemplate $defaultTemplate): array` → `['status' => 'sent'|'skipped_already_sent'|'error', 'portal_sent' => bool, 'message' => ?string]`. Sequenz: (1) `hasAnyContractSent()` → `skipped_already_sent` (Selbstheilung, F4); (2) AV-Default zuweisen falls `contract_template_id` leer; (3) `SendContractsService::send(..., skipNotification: true)`; (4) Employee laden + `sendPortalNotification()`. Kein Fall-/Desk-Zugriff im Service (das macht der Aufrufer).

- [ ] **Step 1: Failing Tests schreiben** — `tests/Unit/ContractSendEligibilityTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\ContractSendEligibility;

class ContractSendEligibilityTest extends TestCase
{
    public function test_bereits_gesendet_gewinnt_vor_allem(): void
    {
        $this->assertSame('already_sent', ContractSendEligibility::state(true, true, false, false));
    }

    public function test_legal_block_vor_feldern(): void
    {
        $this->assertSame('legal_blocked', ContractSendEligibility::state(false, true, true, true));
    }

    public function test_fehlender_beginn_vor_zuschlag(): void
    {
        $this->assertSame('missing_beginn', ContractSendEligibility::state(false, false, false, false));
    }

    public function test_fehlender_zuschlag(): void
    {
        $this->assertSame('missing_zuschlag', ContractSendEligibility::state(false, false, true, false));
    }

    public function test_ready(): void
    {
        $this->assertSame('ready', ContractSendEligibility::state(false, false, true, true));
    }
}
```

- [ ] **Step 2: RED**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/ContractSendEligibilityTest.php
```

Erwartung: FAIL (Class not found).

- [ ] **Step 3: Implementierung** — `src/Services/ContractSendEligibility.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/**
 * Gemeinsames Sende-Prädikat pro Bewerber — Ort der Wahrheit für
 * "darf jetzt Verträge + Portallink bekommen?". Genutzt vom Bulk
 * (Nachbereitung, bulkSendState) und der HR-Desk-Karte (Button-Enable).
 * Prüf-Reihenfolge exakt wie der historische Bulk: sent → legal →
 * beginn → zuschlag. Pure — keine DB, keine Laravel-Abhängigkeit.
 */
class ContractSendEligibility
{
    public static function state(bool $hasSent, bool $legalBlocked, bool $hasBeginn, bool $hasZuschlag): string
    {
        if ($hasSent) {
            return 'already_sent';
        }
        if ($legalBlocked) {
            return 'legal_blocked';
        }
        if (!$hasBeginn) {
            return 'missing_beginn';
        }
        if (!$hasZuschlag) {
            return 'missing_zuschlag';
        }
        return 'ready';
    }
}
```

- [ ] **Step 4: GREEN + Dispatch-Service anlegen** — `src/Services/ContractDispatchService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Pro-Bewerber-Versand-Sequenz "Portallink & Verträge" — extrahiert aus
 * dem Bulk-Button der Nachbereitung, genutzt von Bulk UND HR-Desk-Karte.
 *
 * Gepinnte Fakten (Spec F1/F2/F4):
 *  - SendContractsService::send() ruft selbst checkAutoPilotCompletion();
 *    dessen Carve-out führt die Completion-Hooks (inkl. Mitarbeiter-
 *    Erzeugung, idempotentes createOrUpdate) auch bei auto_pilot=false
 *    aus — die Employee-Anlage passiert also IM Send-Aufruf, der Desk
 *    braucht dafür kein approveCase vorab.
 *  - skipNotification=true unterdrückt NUR die bewerberseitige Vertrags-
 *    Portal-WA; die WA-Menge dieses Services ist damit identisch zum
 *    historischen Bulk: genau EINE Nachricht (Employee-Portal-WA).
 *  - hasAnyContractSent() ist der Idempotenz-Anker: bereits belieferte
 *    Bewerber werden komplett übersprungen (Selbstheilung, z.B. wenn
 *    nach erfolgreichem Versand der Fall-Abschluss abbrach).
 */
class ContractDispatchService
{
    public function sendForApplicant(
        RecApplicant $applicant,
        ?int $userId,
        ?array $contractFields,
        ?RecContractTemplate $defaultTemplate
    ): array {
        if ($applicant->hasAnyContractSent()) {
            return ['status' => 'skipped_already_sent', 'portal_sent' => false, 'message' => null];
        }

        // AV-default zuweisen falls leer — identisch zu
        // assignDefaultTemplateIfMissing() der Nachbereitung.
        if (!$applicant->contract_template_id && $defaultTemplate) {
            $applicant->contract_template_id = $defaultTemplate->id;
            $applicant->save();
        }

        try {
            // skipNotification=true: Vertrags-WA wird unterdrueckt — der
            // MA bekommt stattdessen nur die Portal-WA (das Portal listet
            // die Vertraege ohnehin auf). Gleiche Entscheidung wie im Bulk.
            app(SendContractsService::class)->send($applicant, $userId, $contractFields, true);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'portal_sent' => false, 'message' => $e->getMessage()];
        }

        // Phase-Hook hat den MA angelegt (F1) — jetzt Portal-Link nachschieben.
        $portalSent = false;
        $employee = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
        if ($employee) {
            $employee->sendPortalNotification();
            $portalSent = true;
        }

        return ['status' => 'sent', 'portal_sent' => $portalSent, 'message' => null];
    }
}
```

- [ ] **Step 5: Gate + Commit**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/ContractSendEligibilityTest.php
php -l src/Services/ContractSendEligibility.php && php -l src/Services/ContractDispatchService.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Services/ContractSendEligibility.php src/Services/ContractDispatchService.php tests/Unit/ContractSendEligibilityTest.php
git commit -m "feat(recruiting): ContractDispatchService + pures Sende-Prädikat — gemeinsamer Einzel-Versand"
```

Erwartung: 5 neue Tests grün, Suite 196.

---

### Task 2: Bulk auf den Dispatch-Service umstellen (byte-gleich)

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (nur `sendPortalLinkBulk()`-Schleifenkörper)

**Interfaces:**
- Consumes: `ContractDispatchService::sendForApplicant()` (Task 1, Rückgabe-Shape siehe dort), `$this->defaultContractTemplate` (Computed, existiert).
- Produces: identisches Außenverhalten (Eligibility-Filter, Fehlermeldungen, Zähler, Flash-Texte unverändert).

- [ ] **Step 0: Portal-Schritt im Service härten** (Reviewer-Befund T1: der alte Bulk fing Portal-Exceptions PRO Bewerber — der Service muss das spiegeln, sonst crasht eine Portal-Exception den ganzen Bulk). In `ContractDispatchService::sendForApplicant()` den Portal-Block ersetzen:

```php
        // Phase-Hook hat den MA angelegt (F1) — jetzt Portal-Link nachschieben.
        $portalSent = false;
        $employee = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
        if ($employee) {
            $employee->sendPortalNotification();
            $portalSent = true;
        }

        return ['status' => 'sent', 'portal_sent' => $portalSent, 'message' => null];
```

durch:

```php
        // Phase-Hook hat den MA angelegt (F1) — jetzt Portal-Link nachschieben.
        // Eigener try/catch: Verträge sind zu diesem Zeitpunkt RAUS — ein
        // Portal-Fehler darf weder den Status kippen noch (im Bulk) die
        // restlichen Bewerber blockieren (alte Bulk-Semantik: Fehler pro
        // Bewerber gezählt, Schleife läuft weiter).
        $portalSent = false;
        $portalError = null;
        try {
            $employee = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
            if ($employee) {
                $employee->sendPortalNotification();
                $portalSent = true;
            }
        } catch (\Throwable $e) {
            $portalError = $e->getMessage();
        }

        return ['status' => 'sent', 'portal_sent' => $portalSent, 'message' => $portalError];
```

- [ ] **Step 1: Schleifenkörper ersetzen** — in `sendPortalLinkBulk()` den Block:

```php
        $service = app(SendContractsService::class);
        $contractsSent = 0;
        $portalsSent = 0;
        $errors = 0;

        foreach ($eligible as $booking) {
            try {
                $applicantId = $booking->applicant->id;
                $fields = $this->contractDates[$applicantId] ?? null;
                // skipNotification=true: Vertrags-WA wird unterdrueckt — der
                // MA bekommt stattdessen nur die Portal-WA (das Portal listet
                // die Vertraege ohnehin auf).
                $service->send($booking->applicant, auth()->id(), $fields, true);
                $contractsSent++;

                // Phase-Hook hat den MA angelegt — jetzt Portal-Link nachschieben.
                $employee = RecEmployee::where('rec_applicant_id', $applicantId)->first();
                if ($employee) {
                    $employee->sendPortalNotification();
                    $portalsSent++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }
```

ersetzen durch:

```php
        $dispatch = app(\Platform\Recruiting\Services\ContractDispatchService::class);
        $contractsSent = 0;
        $portalsSent = 0;
        $errors = 0;

        foreach ($eligible as $booking) {
            $applicantId = $booking->applicant->id;
            $fields = $this->contractDates[$applicantId] ?? null;
            $result = $dispatch->sendForApplicant($booking->applicant, auth()->id(), $fields, $this->defaultContractTemplate);

            if ($result['status'] === 'sent') {
                $contractsSent++;
                if ($result['portal_sent']) {
                    $portalsSent++;
                } elseif ($result['message'] !== null) {
                    // Portal-Fehler NACH erfolgreichem Vertragsversand —
                    // alte Bulk-Semantik: contractsSent zählt, errors auch.
                    $errors++;
                }
            } elseif ($result['status'] === 'error') {
                $errors++;
            }
            // 'skipped_already_sent' kann hier nicht auftreten — der
            // Eligibility-Filter oben schließt hasAnyContractSent() aus.
        }
```

(`use Platform\Recruiting\Services\ContractDispatchService;` als Import ergänzen und im Code kurz referenzieren, Stil der Datei folgend.)

- [ ] **Step 2: Gate + Commit**

```bash
php -l src/Livewire/InterviewBookings/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Livewire/InterviewBookings/Index.php
git commit -m "refactor(recruiting): Bulk-Versand nutzt ContractDispatchService (verhaltensgleich)"
```

Reviewer-Gate dieses Tasks: WA-Menge unverändert (eine Employee-Portal-WA, keine Vertrags-WA), Zähler-/Flash-Semantik identisch, Eligibility-Filter unangetastet.

---

### Task 3: `NonEuPostTrainingGate` (pure, TDD)

**Files:**
- Create: `src/Services/NonEuPostTrainingGate.php`
- Create: `tests/Unit/NonEuPostTrainingGateTest.php`

**Interfaces:**
- Produces: `NonEuPostTrainingGate::shouldRoute(?string $oldStatus, string $newStatus, bool $hasLegalStatus, ?bool $isEuCitizen, bool $isChecked): bool` — Task 4 konsumiert exakt diese Signatur.

- [ ] **Step 1: Failing Tests** — `tests/Unit/NonEuPostTrainingGateTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\NonEuPostTrainingGate;

class NonEuPostTrainingGateTest extends TestCase
{
    public function test_nicht_eu_ungeprueft_bei_transition_zu_attended_routet(): void
    {
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, false, false));
    }

    public function test_null_zu_attended_routet(): void
    {
        // Frisch angelegte Buchung direkt als attended (Signatur lässt es zu).
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute(null, 'attended', true, false, false));
    }

    public function test_eu_status_unbeantwortet_mit_datensatz_routet(): void
    {
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, null, false));
    }

    public function test_attended_zu_attended_feuert_nicht_erneut(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('attended', 'attended', true, false, false));
    }

    public function test_eu_buerger_routet_nie(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, true, false));
    }

    public function test_geprueft_routet_nicht(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, false, true));
    }

    public function test_ohne_legalstatus_datensatz_routet_nicht(): void
    {
        // Bestandsbewerber ohne Phase-3-Antwort — Konvention wie LegalStatusGate.
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', false, null, false));
    }

    public function test_andere_zielstatus_routen_nicht(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'no_show', true, false, false));
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('attended', 'cancelled', true, false, false));
    }
}
```

- [ ] **Step 2: RED** (Class not found), **Step 3: Implementierung** — `src/Services/NonEuPostTrainingGate.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/**
 * Entscheidet, ob ein Buchungs-Save den Nicht-EU-Bewerber auf den
 * HR-Schreibtisch routet: NUR beim echten Übergang zu 'attended'
 * ("nach der Schulung"), NUR für rechtsstatus-prüfpflichtige Bewerber
 * (Nicht-EU oder unbeantwortet — MIT legalStatus-Datensatz; Bestand
 * ohne Datensatz routet nie, Konvention wie LegalStatusGate), und NUR
 * solange ungeprüft. Pure — keine DB, keine Laravel-Abhängigkeit.
 */
class NonEuPostTrainingGate
{
    public static function shouldRoute(
        ?string $oldStatus,
        string $newStatus,
        bool $hasLegalStatus,
        ?bool $isEuCitizen,
        bool $isChecked
    ): bool {
        if ($newStatus !== 'attended' || $oldStatus === 'attended') {
            return false;
        }
        if (!$hasLegalStatus || $isEuCitizen === true) {
            return false;
        }
        return !$isChecked;
    }
}
```

- [ ] **Step 4: GREEN + Gate + Commit**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/NonEuPostTrainingGateTest.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Services/NonEuPostTrainingGate.php tests/Unit/NonEuPostTrainingGateTest.php
git commit -m "feat(recruiting): NonEuPostTrainingGate — pure Routing-Entscheidung nach Schulung"
```

Erwartung: 8 neue Tests, Suite 204.

---

### Task 4: Trigger-Umbau — Observer rein, P3-Route + Reminder-Skip raus

**Files:**
- Create: `src/Observers/RecInterviewBookingComplianceObserver.php`
- Modify: `src/Services/HrDeskRoutingService.php` (nur Regel-1-Route-Hälfte in `evaluateAndRoute()`)
- Modify: `src/Console/Commands/SendInterviewReminders.php` (nur der LegalStatus-Skip, Zeile ~72)
- Modify: `src/RecruitingServiceProvider.php` (Observer-Registrierung)

**Interfaces:**
- Consumes: `NonEuPostTrainingGate::shouldRoute()` (Task 3), `HrDeskRoutingService::routeIfNotAlreadyOpen()` (existiert, idempotent).
- Produces: Statuswechsel → attended routet ungeprüfte Nicht-EU/null; P3 routet nicht mehr; Reminder gehen an alle. F5 gilt danach mit ZWEI Route-Stellen (evaluateAndRoute [nur noch andere Reasons] + Observer).

- [ ] **Step 1: Observer anlegen** — `src/Observers/RecInterviewBookingComplianceObserver.php`:

```php
<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\NonEuPostTrainingGate;

/**
 * Nicht-EU-Abzweig "nach der Schulung": Statuswechsel einer Buchung auf
 * 'attended' routet ungeprüfte Nicht-EU-Bewerber (oder EU-Status
 * unbeantwortet) auf den HR-Schreibtisch — dort prüft HR und versendet
 * Verträge + Portallink selbst. Ersetzt das frühere P3-Routing.
 *
 * Fängt alle attended-Pfade (Nachbereitungs-Select, MCP-Tool) — attended
 * wird ausschließlich über Model-Saves gesetzt (verifiziert, kein
 * Query-Builder-Pfad, kein Event-Muting im Modul).
 */
class RecInterviewBookingComplianceObserver
{
    public static function register(): void
    {
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->wasChanged('status')) {
                    return;
                }

                $applicant = $booking->applicant;
                if (!$applicant) {
                    return;
                }

                $legalStatus = $applicant->legalStatus;
                $shouldRoute = NonEuPostTrainingGate::shouldRoute(
                    $booking->getOriginal('status'),
                    $booking->status,
                    $legalStatus !== null,
                    $legalStatus?->is_eu_citizen,
                    (bool) $legalStatus?->isChecked(),
                );

                if (!$shouldRoute) {
                    return;
                }

                app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
                    $applicant,
                    RecHrDeskCase::REASON_NON_EU_CITIZEN,
                    null,
                    'Nach Schulung: Rechtsstatus prüfen + Verträge versenden.'
                );
            }, 'rec_interview_booking.saved.compliance', $booking->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Compliance-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: P3-Route-Hälfte entfernen** — in `HrDeskRoutingService::evaluateAndRoute()` den Block:

```php
        // Regel 1: Nicht-EU-Bürger
        if ($applicant->legalStatus?->is_eu_citizen === false) {
            $this->routeIfNotAlreadyOpen(
                $applicant,
                RecHrDeskCase::REASON_NON_EU_CITIZEN,
                $userId
            );
        } elseif ($applicant->legalStatus?->is_eu_citizen === true) {
```

ersetzen durch:

```php
        // Regel 1: Nicht-EU-Bürger — das ROUTING passiert seit der
        // Nach-Schulung-Umstellung NICHT mehr hier (P3), sondern im
        // RecInterviewBookingComplianceObserver beim Statuswechsel auf
        // 'attended'. Hier verbleibt nur der Auto-Close bei Korrektur
        // auf EU-Bürger.
        if ($applicant->legalStatus?->is_eu_citizen === true) {
```

(Der `elseif`-Body — `autoCloseObsoleteCases(...)` — bleibt wortgleich; aus `elseif` wird `if`.)

- [ ] **Step 3: Reminder-Skip entfernen** — in `SendInterviewReminders.php` den Block um Zeile 72 (`if ($booking->applicant?->isLegalStatusUnchecked()) { ... $skipped++; continue; }` inkl. zugehörigem Kommentar) ersatzlos streichen. Danach: `grep -n "isLegalStatusUnchecked" src/Console/Commands/SendInterviewReminders.php` → leer.

- [ ] **Step 4: Registrieren** — in `RecruitingServiceProvider.php` nach `RecInterviewWaitlistObserver::register();` (Zeile ~151):

```php
        // Nicht-EU-Abzweig nach der Schulung: attended → HR-Schreibtisch.
        \Platform\Recruiting\Observers\RecInterviewBookingComplianceObserver::register();
```

- [ ] **Step 5: Gate + Commit**

```bash
php -l src/Observers/RecInterviewBookingComplianceObserver.php
php -l src/Services/HrDeskRoutingService.php
php -l src/Console/Commands/SendInterviewReminders.php
php -l src/RecruitingServiceProvider.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add -A src/ && git commit -m "feat(recruiting): Nicht-EU-Routing bei Teilgenommen statt P3; Reminder-Gate entfällt"
```

---

### Task 5: Nachbereitung — Batch-Query, Eager-Load, neutraler Lock-Badge

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (Eager-Load + neues Computed + Bulk-Filter)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Lock-Kriterium/Badge)

**Interfaces:**
- Consumes: offene `RecHrDeskCase`-Fälle (`open()`-Scope, `REASON_NON_EU_CITIZEN`).
- Produces: Computed `openNonEuCaseApplicantIds(): array` (applicant_id => true, EIN Batch-Query über die sichtbare Menge — kein Pro-Zeile-Query). Sperr-Kriterium der Zeile: offener Fall ODER ungeprüft (Verteidigungslinie).

- [ ] **Step 1: Eager-Load ergänzen** — im `bookings()`-Computed die `with([...])`-Liste um eine Zeile erweitern:

```php
                'applicant.legalStatus',
```

(direkt nach `'applicant.crmContactLinks.contact',` — behebt zugleich das bestehende N+1 auf legalStatus in der Blade-Schleife.)

- [ ] **Step 2: Batch-Computed einfügen** (nach `bookings()`):

```php
    /**
     * Offene Nicht-EU-Fälle der sichtbaren Bewerber — EIN Batch-Query,
     * keyed by applicant_id (Blade: Lock-Badge "Liegt beim HR-Schreibtisch").
     */
    #[Computed]
    public function openNonEuCaseApplicantIds(): array
    {
        $ids = $this->bookings->pluck('applicant.id')->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return RecHrDeskCase::query()
            ->open()
            ->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)
            ->whereIn('rec_applicant_id', $ids)
            ->pluck('rec_applicant_id')
            ->flip()
            ->all();
    }
```

(`use Platform\Recruiting\Models\RecHrDeskCase;` importieren, falls nicht vorhanden. In `updateStatus()`, `sendContractsBulk()` und `sendPortalLinkBulk()` das bestehende `unset($this->bookings)` jeweils auf `unset($this->bookings, $this->openNonEuCaseApplicantIds)` erweitern — der Badge erscheint damit ohne Reload im selben Request, in dem das attended-Routing feuert. BEWUSSTE GRENZE: Die Nachbereitung pollt nicht (kein wire:poll/Token — anders als das Dashboard); wird attended EXTERN gesetzt (MCP-Tool, andere Session), erscheint der Badge erst bei der nächsten Interaktion/Reload — view-weite, vorbestehende Eigenschaft dieser Ansicht, gilt für alle externen Änderungen. Das Dashboard-Poll-Token ist abgedeckt: Routing bumpt rec_applicants.updated_at [Flags], eine Token-Quelle.)

- [ ] **Step 3: Bulk-Filter erweitern (Verteidigung in beide Richtungen)** — in `sendContractsBulk()` UND `sendPortalLinkBulk()` den Legal-Filter:

```php
            if ($this->isLegalStatusUnchecked($b->applicant)) {
```

ersetzen durch:

```php
            if ($this->isLegalStatusUnchecked($b->applicant)
                || isset($this->openNonEuCaseApplicantIds[$b->applicant->id])) {
```

und in `bulkSendState()` den `$pendingAfterLegal`-Filter analog:

```php
        $pendingAfterLegal = $pending->filter(fn ($b) => !$this->isLegalStatusUnchecked($b->applicant)
            && !isset($this->openNonEuCaseApplicantIds[$b->applicant?->id]));
```

(Begründung im Kommentar ergänzen: "offener Nicht-EU-Fall = liegt bewusst bei HR — auch wenn HR schon 'geprüft' getoggelt, aber noch nicht gesendet hat, darf der Schulungsleiter nicht parallel senden.")

- [ ] **Step 4: Blade-Kriterium umstellen** — im `@php`-Block der Zeile (ab `$legalStatus = $applicant?->legalStatus;`) NACH `$isLegalCheckPending = ...;` ergänzen und die Folgezeilen ersetzen. Aus:

```php
                                        $rowBgClass = '';
                                        if ($isLegalCheckPending) {
                                            $rowBgClass = 'bg-red-50';
                                        } elseif ($isNonEuChecked) {
                                            $rowBgClass = 'bg-emerald-50';
                                        }

                                        $blockContracts = $hasSent || $isLegalCheckPending;
```

wird:

```php
                                        $hasOpenNonEuCase = $applicant
                                            && isset($this->openNonEuCaseApplicantIds[$applicant->id]);

                                        $rowBgClass = '';
                                        if ($hasOpenNonEuCase) {
                                            // Liegt bewusst bei HR — neutral, kein Handlungsbedarf hier.
                                            $rowBgClass = 'bg-blue-50/60';
                                        } elseif ($isLegalCheckPending) {
                                            // Verteidigungslinie: ungeprüft ohne offenen Fall (Randfall).
                                            $rowBgClass = 'bg-red-50';
                                        } elseif ($isNonEuChecked) {
                                            $rowBgClass = 'bg-emerald-50';
                                        }

                                        $blockContracts = $hasSent || $isLegalCheckPending || $hasOpenNonEuCase;
```

Und beim Namens-Badge den Block:

```blade
                                                @if($isLegalCheckPending)
                                                    <div class="text-[10px] text-red-700 mt-0.5 font-medium">Rechtsstatus ungeprüft</div>
                                                @elseif($isNonEuChecked)
                                                    <div class="text-[10px] text-emerald-700 mt-0.5">Rechtsstatus geprüft</div>
                                                @endif
```

ersetzen durch:

```blade
                                                @if($hasOpenNonEuCase)
                                                    <div class="text-[10px] text-blue-700 mt-0.5 font-medium">Liegt beim HR-Schreibtisch</div>
                                                @elseif($isLegalCheckPending)
                                                    <div class="text-[10px] text-red-700 mt-0.5 font-medium">Rechtsstatus ungeprüft</div>
                                                @elseif($isNonEuChecked)
                                                    <div class="text-[10px] text-emerald-700 mt-0.5">Rechtsstatus geprüft</div>
                                                @endif
```

Die beiden Hinweis-Stellen `"Erst auf HR-Schreibtisch prüfen."` (Zuschlag-Spalte) analog erweitern: bei `$hasOpenNonEuCase` Text `"Versand macht HR vom Schreibtisch."`, sonst wie bisher. (Alle Bedingungen im `@php`-Block vorberechnen, KEIN Inline-@php.)

- [ ] **Step 5: Gate + Commit**

```bash
php -l src/Livewire/InterviewBookings/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Livewire/InterviewBookings/Index.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(recruiting): Nachbereitung — Liegt-bei-HR-Badge (Batch-Query) statt roter Sperre"
```

---

### Task 6: HR-Desk-Karte — Sende-Bereich

**Files:**
- Modify: `src/Livewire/HrDesk/Index.php`
- Modify: `resources/views/livewire/hr-desk/index.blade.php`

**Interfaces:**
- Consumes: `ContractDispatchService::sendForApplicant()` + `ContractSendEligibility::state()` (Task 1, Shapes dort), `HrDeskRoutingService::approveCase()` (bestehend — schließt Fall, reaktiviert Auto-Pilot, macht Phase-Advance; wirft `LegalStatusNotCheckedException` bei ungeprüft), `RecContract::resolveContractDates()`, AV-Default (`code='AV-default'`).
- Produces: Desk-Aktionen `setDeskZuschlag`, `setDeskContractDate`, `sendContractsFromDesk(int $caseId)`. Sende-Bereich nur sichtbar bei `non_eu_citizen`-Fall MIT attended-Booking des Bewerbers.

- [ ] **Step 1: Component erweitern** — Imports ergänzen (`RecContract`, `RecContractTemplate` existiert, `RecInterviewBooking`, `ContractDispatchService`, `ContractSendEligibility`, `LegalStatusNotCheckedException` existiert). Properties + Methoden einfügen (nach `setAdditionalContractTemplate()`):

```php
    /** Vertragslaufzeit-Eingaben pro Bewerber: [applicantId => ['vertragsbeginn' => ?, 'vertragsende' => ?]] */
    public array $deskContractDates = [];

    #[Computed]
    public function defaultContractTemplate()
    {
        return RecContractTemplate::where('team_id', (int) Auth::user()->currentTeam->id)
            ->where('code', 'AV-default')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Bewerber-IDs (der sichtbaren Fälle) mit attended-Booking — EIN
     * Batch-Query; steuert die Sichtbarkeit des Sende-Bereichs.
     */
    #[Computed]
    public function attendedApplicantIds(): array
    {
        $ids = $this->cases->pluck('rec_applicant_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return RecInterviewBooking::whereIn('rec_applicant_id', $ids)
            ->where('status', 'attended')
            ->pluck('rec_applicant_id')
            ->flip()
            ->all();
    }

    /**
     * Zuschlag setzen — identische Validierung wie die Nachbereitung
     * (setApplicantZuschlag): Ziffern + optional Komma/Punkt, max 2
     * Nachkommastellen, DECIMAL(5,2).
     */
    public function setDeskZuschlag(int $applicantId, $value): void
    {
        $applicant = RecApplicant::forTeam((int) Auth::user()->currentTeam->id)->find($applicantId);
        if (!$applicant) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            $applicant->zuschlag = null;
            $applicant->save();
            unset($this->cases);
            return;
        }

        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $raw)) {
            session()->flash('message', 'Zuschlag muss eine Zahl sein (z.B. 0,60).');
            return;
        }

        $applicant->zuschlag = round((float) str_replace(',', '.', $raw), 2);
        $applicant->save();
        unset($this->cases);
    }

    /**
     * Vertragslaufzeit setzen — gleiche Auto-Calc-Vorbelegung wie die
     * Nachbereitung (setContractDate): Beginn gesetzt + Ende leer →
     * Ende via resolveContractDates (+1 Jahr, Anfang Monat, −1 Tag).
     */
    public function setDeskContractDate(int $applicantId, string $field, ?string $value): void
    {
        if (!in_array($field, ['vertragsbeginn', 'vertragsende'], true)) {
            return;
        }

        $value = $value !== '' ? $value : null;
        $current = $this->deskContractDates[$applicantId] ?? ['vertragsbeginn' => null, 'vertragsende' => null];
        $current[$field] = $value;

        if ($field === 'vertragsbeginn' && $value && empty($current['vertragsende'])) {
            $resolved = RecContract::resolveContractDates($value, null);
            $current['vertragsende'] = $resolved['vertragsende'];
        }

        $this->deskContractDates[$applicantId] = $current;
    }

    /**
     * "Portallink & Verträge versenden" vom HR-Schreibtisch: sendet über
     * den gemeinsamen ContractDispatchService (identische Sequenz wie der
     * Nachbereitungs-Bulk) und schließt bei Erfolg den Fall über den
     * bestehenden approveCase-Pfad (Desk-Entlassung, Auto-Pilot an,
     * Phase-Advance — Spec F1/F2). Selbstheilung: war schon gesendet
     * (skipped_already_sent), wird nur noch der Fall geschlossen.
     */
    public function sendContractsFromDesk(int $caseId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $userId = (int) Auth::id();

        $case = RecHrDeskCase::forTeam($teamId)->with('applicant.legalStatus')->find($caseId);
        $applicant = $case?->applicant;
        if (!$case || !$case->isOpen() || $case->reason !== RecHrDeskCase::REASON_NON_EU_CITIZEN || !$applicant) {
            return;
        }

        // Gemeinsames Prädikat (Task 1) — identisch zum Bulk-Gate.
        $fields = $this->deskContractDates[$applicant->id] ?? null;
        $state = ContractSendEligibility::state(
            $applicant->hasAnyContractSent(),
            $applicant->isLegalStatusUnchecked(),
            !empty($fields['vertragsbeginn']),
            $applicant->zuschlag !== null,
        );

        if ($state === 'legal_blocked') {
            session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
            return;
        }
        if ($state === 'missing_beginn') {
            session()->flash('message', 'Vertragsbeginn fehlt.');
            return;
        }
        if ($state === 'missing_zuschlag') {
            session()->flash('message', 'Zuschlag fehlt.');
            return;
        }

        if ($state === 'ready') {
            $result = app(ContractDispatchService::class)
                ->sendForApplicant($applicant, $userId, $fields, $this->defaultContractTemplate);

            if ($result['status'] === 'error') {
                session()->flash('message', 'Versand fehlgeschlagen: ' . $result['message']);
                return; // Fall bleibt offen — kein halber Zustand.
            }
        }
        // state === 'already_sent' ODER erfolgreicher Versand: Fall schließen.

        try {
            app(HrDeskRoutingService::class)->approveCase($case, $userId, 'Verträge + Portallink vom HR-Schreibtisch versendet.');
            session()->flash('message', 'Verträge + Portallink versendet — Fall geschlossen.');
        } catch (LegalStatusNotCheckedException) {
            session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
        }

        unset($this->cases, $this->reasonCounts, $this->attendedApplicantIds);
    }
```

Zusätzlich im `cases()`-Eager-Load ergänzen: `'applicant.contractTemplate'` und `'applicant.contracts:id,rec_applicant_id,rec_contract_template_id,status,sent_at'` (für `hasAnyContractSent`/Anzeige ohne N+1).

- [ ] **Step 2: Blade — Sende-Bereich einfügen** — direkt NACH dem schließenden `@endif` der Rechtsstatus-Sektion (`@if($showLegalSection && $legalStatus) ... @endif`), im selben Karten-Container:

```blade
                                {{-- Vertrags-Versand vom Schreibtisch (Nachbereitung-Semantik) --}}
                                @php
                                    $isNonEuCase = $case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN;
                                    $hasAttended = $applicant && isset($this->attendedApplicantIds[$applicant->id]);
                                    $showSendSection = $isNonEuCase && $hasAttended && $legalStatus;
                                    $deskFields = $applicant ? ($deskContractDates[$applicant->id] ?? []) : [];
                                    $deskBeginn = $deskFields['vertragsbeginn'] ?? '';
                                    $deskEnde = $deskFields['vertragsende'] ?? '';
                                    $sendState = $showSendSection
                                        ? \Platform\Recruiting\Services\ContractSendEligibility::state(
                                            (bool) $applicant->hasAnyContractSent(),
                                            (bool) $applicant->isLegalStatusUnchecked(),
                                            !empty($deskBeginn),
                                            $applicant->zuschlag !== null,
                                        )
                                        : null;
                                    $sendReady = $sendState === 'ready' || $sendState === 'already_sent';
                                    $shownTpl = $applicant?->contractTemplate ?? $this->defaultContractTemplate;
                                @endphp
                                @if($showSendSection)
                                    <div class="mt-3 p-3 rounded-md border border-blue-200 bg-blue-50/60">
                                        <div class="text-xs font-semibold text-blue-900 uppercase tracking-wide mb-2">
                                            Verträge &amp; Portallink (nach Schulung)
                                        </div>
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">AV-Vorlage</label>
                                                @if($shownTpl)
                                                    <div class="text-xs px-2 py-1 rounded bg-white border border-gray-300 text-gray-700">
                                                        {{ $shownTpl->code ? $shownTpl->code . ' — ' : '' }}{{ $shownTpl->name }}
                                                    </div>
                                                @else
                                                    <div class="text-xs text-red-700">AV-default-Vorlage fehlt oder ist inaktiv.</div>
                                                @endif
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Zuschlag €/Std</label>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    value="{{ $applicant->zuschlag !== null ? number_format((float) $applicant->zuschlag, 2, ',', '.') : '' }}"
                                                    wire:change="setDeskZuschlag({{ $applicant->id }}, $event.target.value)"
                                                    placeholder="z.B. 0,60"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1 w-[110px]"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Vertragsbeginn</label>
                                                <input
                                                    type="date"
                                                    value="{{ $deskBeginn }}"
                                                    wire:change="setDeskContractDate({{ $applicant->id }}, 'vertragsbeginn', $event.target.value)"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Vertragsende</label>
                                                <input
                                                    type="date"
                                                    value="{{ $deskEnde }}"
                                                    wire:change="setDeskContractDate({{ $applicant->id }}, 'vertragsende', $event.target.value)"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                wire:click="sendContractsFromDesk({{ $case->id }})"
                                                wire:loading.attr="disabled"
                                                @disabled(!$sendReady)
                                                @class([
                                                    'px-3 py-1.5 text-xs font-semibold rounded-md border',
                                                    'border-blue-300 text-white bg-blue-600 hover:bg-blue-700' => $sendReady,
                                                    'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' => !$sendReady,
                                                ])
                                            >
                                                Portallink &amp; Verträge versenden
                                            </button>
                                        </div>
                                        @if($sendState === 'legal_blocked')
                                            <p class="text-[11px] text-amber-800 mt-2">Erst Rechtsstatus prüfen — dann wird der Versand aktiv.</p>
                                        @elseif($sendState === 'missing_beginn')
                                            <p class="text-[11px] text-gray-600 mt-2">Vertragsbeginn setzen (Ende leer = Auto: +1 Jahr, Anfang Monat, −1 Tag).</p>
                                        @elseif($sendState === 'missing_zuschlag')
                                            <p class="text-[11px] text-gray-600 mt-2">Zuschlag setzen.</p>
                                        @elseif($sendState === 'already_sent')
                                            <p class="text-[11px] text-emerald-700 mt-2">Verträge bereits versendet — Klick schließt nur noch den Fall.</p>
                                        @endif
                                        <p class="text-[11px] text-gray-500 mt-1">Zusatzvertrag (oben) wird automatisch mitversendet. Alternativ: "Freigeben" ohne Versand — dann sendet der Schulungsleiter.</p>
                                    </div>
                                @endif
```

- [ ] **Step 3: Gate + Commit**

```bash
php -l src/Livewire/HrDesk/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Livewire/HrDesk/Index.php resources/views/livewire/hr-desk/index.blade.php
git commit -m "feat(recruiting): HR-Schreibtisch versendet Verträge + Portallink selbst (Nicht-EU nach Schulung)"
```

---

### Task 7: Migrations-Command `recruiting:migrate-non-eu-cases`

**Files:**
- Create: `src/Console/Commands/MigrateNonEuCases.php`
- Modify: `src/RecruitingServiceProvider.php` (Command-Registrierung im `$this->commands([...])`-Array; NICHT schedulen — einmaliger manueller Lauf)

**Interfaces:**
- Consumes: `HrDeskRoutingService::approveCase()` (Regel 2), `routeIfNotAlreadyOpen()` (Regel 3), Scopes/Models. Regeln aus der Spec §5 (1: offen+ungeprüft+kein attended → schließen+Flags+Kick; 2: offen+geprüft → approveCase; 3: ungeprüft(false ODER null, MIT legalStatus)+attended+kein Fall → Fall anlegen; 4: offen+inzwischen EU → Auto-Close). Idempotent, `--dry-run`. **Status-Festlegung (gegen Code geklärt):** Es existieren nur open/approved/rejected; die bestehende `autoCloseObsoleteCases()` setzt für Nicht-Approvals selbst `STATUS_APPROVED` und differenziert über `resolution_notes` + Log-Typ `hr_desk_auto_resolved` — Regeln 1+4 folgen exakt diesem Muster (kein Case-Observer hört auf Status-Übergänge; `autoCloseObsoleteCases` ist private und reaktiviert den Auto-Pilot nicht, deshalb kein Direkt-Aufruf).

- [ ] **Step 1: Command schreiben** — `src/Console/Commands/MigrateNonEuCases.php`:

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\HrDeskRoutingService;

/**
 * Einmalige Überführung der Nicht-EU-Bestandsfälle in die
 * Nach-Schulung-Logik (Spec 2026-07-17, §5). Idempotent — kann
 * gefahrlos mehrfach laufen. IMMER zuerst mit --dry-run.
 */
class MigrateNonEuCases extends Command
{
    protected $signature = 'recruiting:migrate-non-eu-cases {--dry-run : Nur zählen, nichts schreiben}';

    protected $description = 'Überführt Nicht-EU-Bestandsfälle in die Nach-Schulung-Prüfung (einmalig, idempotent)';

    public function handle(HrDeskRoutingService $routing): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['r1_geschlossen' => 0, 'r2_approved' => 0, 'r3_angelegt' => 0, 'r4_obsolet' => 0, 'r_offen_gelassen' => 0];

        $openCases = RecHrDeskCase::query()
            ->open()
            ->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)
            ->with('applicant.legalStatus')
            ->get();

        foreach ($openCases as $case) {
            $applicant = $case->applicant;
            if (!$applicant) {
                continue;
            }
            $legal = $applicant->legalStatus;
            $hasAttended = RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                ->where('status', 'attended')
                ->exists();

            // Regel 4: inzwischen EU → Fall obsolet.
            if ($legal?->is_eu_citizen === true) {
                $counts['r4_obsolet']++;
                if (!$dry) {
                    $case->update([
                        'status' => RecHrDeskCase::STATUS_APPROVED,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Migration: Bewerber ist inzwischen als EU-Buerger gekennzeichnet.',
                    ]);
                    // Log-Typ wie autoCloseObsoleteCases: Reporting kann
                    // human-approved von auto-closed unterscheiden.
                    \Platform\Recruiting\Models\RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'hr_desk_auto_resolved',
                        'summary'          => 'Migration: Nicht-EU-Fall obsolet (inzwischen EU-Buerger).',
                    ]);
                    $this->releaseIfNoOtherOpenCases($applicant, $case);
                }
                continue;
            }

            // Regel 2: geprüft → regulär freigeben (Gate passiert).
            if (!$applicant->isLegalStatusUnchecked()) {
                $counts['r2_approved']++;
                if (!$dry) {
                    $routing->approveCase($case, 0, 'Migration: bereits geprüft — zurück in den Schulungsleiter-Flow.');
                }
                continue;
            }

            // Regel 1: ungeprüft, Schulung noch nicht besucht → Fall
            // schließen, weiterlaufen lassen; kommt bei attended wieder.
            if (!$hasAttended) {
                $counts['r1_geschlossen']++;
                if (!$dry) {
                    $case->update([
                        'status' => RecHrDeskCase::STATUS_APPROVED,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Migration: Pruefung erfolgt nach der Schulung (neuer Flow).',
                    ]);
                    \Platform\Recruiting\Models\RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'hr_desk_auto_resolved',
                        'summary'          => 'Migration: Nicht-EU-Pruefung auf nach-Schulung umgestellt.',
                    ]);
                    $this->releaseIfNoOtherOpenCases($applicant, $case);
                }
                continue;
            }

            // Offen + ungeprüft + attended: liegt bereits richtig —
            // der neue Desk-Sende-Bereich bedient ihn. Nichts tun.
            $counts['r_offen_gelassen']++;
        }

        // Regel 3: ungeprüfte Prüfpflichtige (false ODER null, MIT
        // legalStatus) mit attended-Booking, aber ohne offenen Fall —
        // die heute in der Nachbereitung rot hängen.
        $candidates = RecApplicant::query()
            ->where('is_active', true)
            ->whereNull('rejected_at')
            ->whereHas('legalStatus', function ($q) {
                $q->whereNull('legal_status_checked_at')
                    ->where(fn ($q2) => $q2->where('is_eu_citizen', false)->orWhereNull('is_eu_citizen'));
            })
            ->whereHas('interviewBookings', fn ($q) => $q->where('status', 'attended'))
            ->whereDoesntHave('hrDeskCases', function ($q) {
                $q->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)->open();
            })
            ->get();

        foreach ($candidates as $applicant) {
            $counts['r3_angelegt']++;
            if (!$dry) {
                $routing->routeIfNotAlreadyOpen(
                    $applicant,
                    RecHrDeskCase::REASON_NON_EU_CITIZEN,
                    null,
                    'Migration: Nach Schulung — Rechtsstatus pruefen + Vertraege versenden.'
                );
            }
        }

        $this->table(['Regel', 'Anzahl'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info($dry ? 'DRY-RUN — nichts geschrieben.' : 'Migration ausgeführt.');

        return self::SUCCESS;
    }

    /**
     * Flags zurücksetzen + defensiver Progressions-Kick — Semantik wie
     * approveCase (ohne dessen Prüf-Gate; Migration IST die Ausnahme).
     */
    private function releaseIfNoOtherOpenCases(RecApplicant $applicant, RecHrDeskCase $closedCase): void
    {
        $hasOther = $applicant->hrDeskCases()
            ->where('id', '!=', $closedCase->id)
            ->open()
            ->exists();

        if ($hasOther) {
            return;
        }

        $applicant->update(['is_on_hr_desk' => false, 'auto_pilot' => true]);

        try {
            $applicant->refresh();
            $applicant->checkAutoPilotCompletion();
        } catch (\Throwable) {
            // defensiver Kick — Fehler blockiert die Migration nicht.
        }
    }
}
```

- [ ] **Step 2: Registrieren** — im `$this->commands([...])`-Array des Providers ergänzen:

```php
                \Platform\Recruiting\Console\Commands\MigrateNonEuCases::class,
```

- [ ] **Step 3: Gate + Commit**

```bash
php -l src/Console/Commands/MigrateNonEuCases.php && php -l src/RecruitingServiceProvider.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Console/Commands/MigrateNonEuCases.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Migrations-Command für Nicht-EU-Bestandsfälle (dry-run, idempotent)"
```

Hinweis für den Implementer: `approveCase(…, 0, …)` — prüfen, ob `resolved_by_user_id=0` FK-sicher ist; falls die Spalte constrained ist, stattdessen `$case->update`-Variante wie Regel 1 mit anschließendem `releaseIfNoOtherOpenCases` verwenden und im Report vermerken.

---

### Task 8: Harness + F3-Audit + Trigger-Sicherheit + Push (dann STOPP)

**Files:** keine im Repo (Harness im Scratchpad)

- [ ] **Step 1: Harness** (sqlite-:memory:-Smoke mit echten Klassen, Muster Warteliste; Blade-Compile beider geänderter Views; volle Suite; git sanity):
1. `NonEuPostTrainingGate`/`ContractSendEligibility`: bereits unit-getestet — im Harness nur je ein End-to-End-Aufruf gegen echte Model-Felder.
2. Observer-Guard-Wahrheitstabelle gegen den echten Observer-Code (attended-Transition/attended→attended/EU/checked/kein legalStatus/anderer Status).
3. Migrations-Regeln 1–4 + "offen gelassen"-Fall als Replika gegen sqlite-Daten (alle 8 Spec-Zustände durchspielen; Idempotenz: zweiter Lauf ändert nichts).
4. Dispatch-Service: Selbstheilung (`hasAnyContractSent` → skipped), Fehler-Pfad (send wirft → status error, kein Portal-Versuch).
5. `evaluateAndRoute`: routet Nicht-EU NICHT mehr (Regel-2-Deutschkenntnisse weiterhin JA), Auto-Close bei EU weiterhin JA.
6. Reminder-Command-Quelle: `grep isLegalStatusUnchecked` in SendInterviewReminders = leer.

- [ ] **Step 2: F3-Audit (Live, read-only via MCP):** Für ALLE Stellen (aktiv UND inaktiv — Migrations-Regel 3 kann attended-Nicht-EU auf inzwischen inaktiven Stellen wiederbeleben) die Phasen mit `completion_type = contract_sent` listen und prüfen: `completion_config.send_booking_notification_on_completion` ist explizit `false`. Jede Stelle ohne expliziten Key → an den User melden (Betriebsnotiz; Key setzen VOR dem Deploy). Ergebnis in den Report.

- [ ] **Step 3: Trigger-Sicherheits-Verifikation VOR Push** (STOPP bei Nicht-Bestätigung):
1. Deploy ändert keine Daten: kein Schema-Change, Migration nur als manueller Command (nicht gescheduled — Provider-Diff prüfen).
2. `grep -rn "routeIfNotAlreadyOpen" src/` → Aufrufer sind ausschließlich: evaluateAndRoute (andere Reasons), die zwei bestehenden Cancelled-Training-Stellen, der neue Observer, der Migrations-Command.
3. Der Observer dispatcht/routet nur bei echter attended-Transition (Gate-Tests + Harness-Tabelle).
4. Reminder-Verhalten: einzige Änderung = Gate-Skip entfällt (WENIGER Blockade, nie mehr Nachrichten pro Person als vorher — der Reminder bleibt one-shot via `reminder_sent_at`).

- [ ] **Step 4: Push**

```bash
git push -u origin feature/nicht-eu-nach-schulung
```

DANACH STOPP — Whole-Branch-Review (stärkstes Modell; Mandat: T1+T2 byte-gleicher Bulk, T4-Trigger-Kette, T6-Desk-Sequenz send→approve gegen F1/F2, Migration-Zustandsraum) gehört VOR den Push-Report. Merge/Bump/Deploy separat nach Freigabe.

- [ ] **Step 5 (nach Freigabe, separater Durchlauf):** ff-Merge → meingedeck-Bump → Deploy → **`queue:restart` PFLICHT** → Migration: erst `php artisan recruiting:migrate-non-eu-cases --dry-run` (Zahlen an User), nach Freigabe echt. Live-Smoke: Test-Nicht-EU durch den Flow (P3 → Reminder kommt → attended → Desk-Karte mit Sende-Bereich → prüfen → Zuschlag/Beginn → senden → EINE Portal-WA, **KEINE Buchungslink-WA**, Fall zu, Employee da, Nachbereitung zeigt "Gesendet"); Lock-Badge erscheint beim attended-Klick in der Nachbereitung ohne Reload (Same-Request-Pfad; externes attended via MCP zeigt den Badge dokumentiert erst bei nächster Interaktion — nicht Teil des Smoke).

---

## Bewusste Entscheidungen (Review-relevant)

- Ort der Wahrheit: Das pure Eligibility-Prädikat (Task 1) kodiert exakt die Bulk-Semantik in Bulk-Reihenfolge (sent → legal → beginn → zuschlag; F6-verifiziert: der Bulk validiert Vertragsbeginn heute als Pflicht). Der Desk-Button konsumiert es; der Bulk behält bewusst seine Set-Aggregation (`bulkSendState` unangetastet außer dem Case-Filter aus Task 5) — ein Refactoring des byte-gleich-sensiblen Bulk-States wäre Risiko ohne Nutzen. Die Gleichheit sichern die Prädikat-Tests, die die Bulk-Reihenfolge spiegeln.
- Desk-Reihenfolge: erst senden, bei Erfolg `approveCase` — Abbruch dazwischen heilt sich über `hasAnyContractSent` (zweiter Klick schließt nur noch).
- Sperr-Kriterium Nachbereitung: offener Fall ODER ungeprüft (beide Richtungen verteidigt — auch "geprüft, aber Fall noch offen, HR sendet gleich" bleibt für den Schulungsleiter gesperrt).
- Migration Regel 2 nutzt `approveCase` (bewusst mit Gate — geprüfte passieren); Regeln 1/4 schließen am Gate vorbei (Migration ist die dokumentierte Ausnahme; kein Prüf-Skip, die Prüfung kommt bei attended wieder).
- Deutschkenntnisse-Lücke, `handleEuStatusChange`-Dead-Code, MCP-SendContractsTool ungegated: dokumentiert draußen (Spec-Abgrenzungen).
- `resolved_by_user_id` bei Migration/System-Aktionen: Implementer-Hinweis in Task 7 (FK-Prüfung).
