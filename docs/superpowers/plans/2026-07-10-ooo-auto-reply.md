# HR-Abwesenheitsmodus (OOO-Auto-Reply) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teamweiter Abwesenheitsmodus auf der Conversations-Seite: eingehende WhatsApp-Nachrichten bekommen automatisch ein OOO-Template (1×/24h je Konversation), das nachweislich NICHT als Antwort zählt — Konversationen bleiben ungelesen und „verpasst".

**Architecture:** Pure `OooMode` (off/pending/active) als Source of Truth; `OooAutoReplyHandler` im WhatsApp-Inbound-Listener (vor dem Kontext-Gate, mit Blacklist-Gate); generisches `is_auto_reply`-Flag an der CRM-Outbound-Nachricht (freigegebener CRM-Eingriff), das auch der Voice-Note-Handler setzt; `ConversationInboxService` berechnet den „effektiven letzten Ausgang" über eine gruppierte Query, die Auto-Replies ausschließt.

**Tech Stack:** PHP 8.4, Laravel/Livewire, Blade, PHPUnit 11.5 (pure-unit, kein testbench).

**Spec:** `docs/superpowers/specs/2026-07-10-ooo-auto-reply-design.md` (bei Widerspruch gilt der Spec).

## Global Constraints

- Tests laufen NUR als pure Unit-Tests (kein Laravel/DB): Basisklasse `PHPUnit\Framework\TestCase`, Namespace `Platform\Recruiting\Tests\Unit`, Dateien unter `tests/Unit/`.
- Test-Runner (vom Modul-Root `platforms-recruiting/`): `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter <Name>`
- **Zwei Repos:** platforms-recruiting (`/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`) und platform-crm (`/Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm`). In BEIDEN vor Task 1 einen Branch `feat/ooo-auto-reply` anlegen. Commits nur auf diesen Branches.
- **CRM-Eingriff ist auf Task 1 beschränkt und freigegeben** (Spalte + Param). Nichts anderes im CRM anfassen.
- Migration NICHT lokal ausführen (lokale meingedeck-DB hat eine bekannte kaputte Fremd-Migration) — Verifikation per Code-Review + `php -l`.
- Settings-Keys exakt: `comms_ooo_enabled`, `comms_ooo_from`, `comms_ooo_until`, `comms_ooo_back_at`, `comms_ooo_template_id` (alle Datumswerte als `Y-m-d`-String).
- Template-Variablen exakt: `{{von}}`, `{{bis}}`, `{{wieder_da}}` — Werte formatiert `d.m.Y`. KEIN `{{name}}` im OOO-Kontext (firstName wird leer durchgereicht).
- Verpasst-Query: `MAX(created_at)` (NICHT sent_at), Ausschluss `is_auto_reply = true`, Thread fehlt im Ergebnis ⇒ `null` (nie beantwortet) — **KEIN Fallback auf `thread.last_outbound_at`**.
- UI: keine `x-ui-input-date`-Komponente vorhanden — Datumsfelder als native `<input type="date">`. Keine Inline-`@if`/Ternaries in Component-Attributen — Werte im `@php`-Block vorberechnen.
- CommsLog-Events exakt: `ooo_autoreply_sent`, `ooo_autoreply_skipped_blacklisted`.

## File Structure

**platform-crm (Task 1):**
- Neu: `database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php`
- Ändern: `src/Models/CommsWhatsAppMessage.php` (fillable + cast)
- Ändern: `src/Services/Comms/WhatsAppMetaService.php` (`sendTemplate` + `handleSendResponse` Param → Message-Create)

**platforms-recruiting (Tasks 2–6):**
- Neu: `src/Services/Comms/OooMode.php` + `tests/Unit/OooModeTest.php`
- Ändern: `src/Services/Comms/HoldingTemplateComponents.php` + `tests/Unit/HoldingTemplateComponentsTest.php` (falls nicht vorhanden: neu)
- Ändern: `src/Services/Comms/HoldingTemplateSender.php`
- Neu: `src/Services/Comms/OooAutoReplyHandler.php`
- Ändern: `src/Services/Comms/VoiceNoteAutoReplyHandler.php`
- Ändern: `src/Listeners/HandleWhatsAppInboundForRecruiting.php`
- Ändern: `src/Services/Comms/ConversationInboxService.php`
- Ändern: `src/Livewire/Conversations/Index.php` + `resources/views/livewire/conversations/index.blade.php`
- Ändern: `src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS) + `src/Livewire/Applicant/ApplicantSettingsModal.php`-Blade (`resources/views/livewire/applicant/applicant-settings-modal.blade.php`)

---

### Task 1: CRM — `is_auto_reply` an der Outbound-Nachricht

**Files:**
- Create: `platform-crm/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php`
- Modify: `platform-crm/src/Models/CommsWhatsAppMessage.php` (fillable ~Z.14–31, casts ~Z.33–41)
- Modify: `platform-crm/src/Services/Comms/WhatsAppMetaService.php` (`sendTemplate` Z.56–63, `handleSendResponse` Z.398–431)

**Interfaces:**
- Consumes: nichts (erste Task).
- Produces: Spalte `comms_whatsapp_messages.is_auto_reply` (bool, default false); `WhatsAppMetaService::sendTemplate(CommsChannel $channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', ?User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage`.

- [ ] **Step 1: Branches anlegen (beide Repos)**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm && git checkout -b feat/ooo-auto-reply
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && git checkout -b feat/ooo-auto-reply
```

- [ ] **Step 2: Migration schreiben**

`platform-crm/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_auto_reply = automatische Sofort-Quittung (z.B. OOO-Abwesenheitsnotiz,
     * Sprachnachricht-Hinweis), die NICHT als menschliche Antwort zählt.
     * Ausgewertet von Recruiting (ConversationInboxService, "verpasst"-Zähler).
     */
    public function up(): void
    {
        Schema::table('comms_whatsapp_messages', function (Blueprint $table) {
            $table->boolean('is_auto_reply')->default(false)->after('template_params');
        });
    }

    public function down(): void
    {
        Schema::table('comms_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('is_auto_reply');
        });
    }
};
```

- [ ] **Step 3: Model erweitern**

In `platform-crm/src/Models/CommsWhatsAppMessage.php` im `$fillable`-Array nach `'template_params',` einfügen:

```php
        'is_auto_reply',
```

Im `$casts`-Array nach `'template_params' => 'array',` einfügen:

```php
        'is_auto_reply' => 'boolean',
```

- [ ] **Step 4: `sendTemplate` + `handleSendResponse` erweitern**

In `platform-crm/src/Services/Comms/WhatsAppMetaService.php`:

Signatur `sendTemplate` (Z.56–63) — **Vorher:**

```php
    public function sendTemplate(
        CommsChannel $channel,
        string $to,
        string $templateName,
        array $components = [],
        string $languageCode = 'de',
        ?User $sender = null,
    ): CommsWhatsAppMessage {
```

**Nachher:**

```php
    public function sendTemplate(
        CommsChannel $channel,
        string $to,
        string $templateName,
        array $components = [],
        string $languageCode = 'de',
        ?User $sender = null,
        bool $isAutoReply = false,
    ): CommsWhatsAppMessage {
```

Innerhalb von `sendTemplate` den Aufruf von `handleSendResponse(...)` finden (er übergibt u.a. `$templateName` und Template-Params) und als **letztes Argument** `$isAutoReply` ergänzen — z.B. aus `return $this->handleSendResponse($response, $channel, $phone, $body, 'template', $sender, $templateName, $templateParams);` wird `..., $templateParams, $isAutoReply);` (exakte Argumentliste im File prüfen, nur den Parameter anhängen).

Signatur `handleSendResponse` (Z.398–407) — **Vorher:**

```php
    protected function handleSendResponse(
        $response,
        CommsChannel $channel,
        string $phone,
        string $body,
        string $messageType,
        ?User $sender,
        ?string $templateName = null,
        ?array $templateParams = null,
    ): CommsWhatsAppMessage {
```

**Nachher:**

```php
    protected function handleSendResponse(
        $response,
        CommsChannel $channel,
        string $phone,
        string $body,
        string $messageType,
        ?User $sender,
        ?string $templateName = null,
        ?array $templateParams = null,
        bool $isAutoReply = false,
    ): CommsWhatsAppMessage {
```

Im `$thread->messages()->create([...])` (Z.418ff) nach `'template_params' => $templateParams,` einfügen:

```php
            'is_auto_reply' => $isAutoReply,
```

- [ ] **Step 5: Lint + Regressionscheck**

```bash
php -l /Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm/src/Models/CommsWhatsAppMessage.php
php -l /Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm/src/Services/Comms/WhatsAppMetaService.php
php -l /Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && ../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected` ×3; Recruiting-Suite grün (Stand: 111 Tests). Migration NICHT lokal ausführen.

- [ ] **Step 6: Commit (platform-crm)**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platform-crm
git add database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php src/Models/CommsWhatsAppMessage.php src/Services/Comms/WhatsAppMetaService.php
git commit -m "feat(comms): is_auto_reply-Flag fuer WhatsApp-Outbound (Auto-Quittungen zaehlen nicht als Antwort)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `OooMode` (pure, TDD)

**Files:**
- Create: `platforms-recruiting/src/Services/Comms/OooMode.php`
- Test: `platforms-recruiting/tests/Unit/OooModeTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces: `OooMode::state(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): string` (Konstanten `STATE_OFF='off'`, `STATE_PENDING='pending'`, `STATE_ACTIVE='active'`); `OooMode::isActive(bool, ?string, ?string, string): bool`.

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/OooModeTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\OooMode;

class OooModeTest extends TestCase
{
    public function test_disabled_is_off_regardless_of_dates(): void
    {
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(false, '2026-07-01', '2026-07-20', '2026-07-10'));
    }

    public function test_missing_dates_are_off_never_forever_active(): void
    {
        // Fehlende Daten duerfen NIE "ewig aktiv" bedeuten.
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, null, '2026-07-20', '2026-07-10'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-01', null, '2026-07-10'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, null, null, '2026-07-10'));
    }

    public function test_pending_before_from(): void
    {
        // Vorplanung: enabled, aber Abwesenheit hat noch nicht begonnen.
        $this->assertSame(OooMode::STATE_PENDING, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-10'));
    }

    public function test_active_between_from_and_back_at(): void
    {
        $this->assertSame(OooMode::STATE_ACTIVE, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-16'));
        // Grenztag: today == from -> aktiv
        $this->assertSame(OooMode::STATE_ACTIVE, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-14'));
    }

    public function test_off_from_back_at_day_on(): void
    {
        // Grenztag: today == backAt -> aus (lazy Auto-Off ab dem Wieder-da-Tag)
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-21'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-08-01'));
    }

    public function test_is_active_only_in_active_state(): void
    {
        $this->assertTrue(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-16'));
        $this->assertFalse(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-10')); // pending
        $this->assertFalse(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-21')); // abgelaufen
        $this->assertFalse(OooMode::isActive(false, '2026-07-14', '2026-07-21', '2026-07-16')); // aus
    }
}
```

- [ ] **Step 2: RED verifizieren**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter OooModeTest`
Expected: FAIL — `Class "Platform\Recruiting\Services\Comms\OooMode" not found`.

- [ ] **Step 3: Implementierung**

`src/Services/Comms/OooMode.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Alleinige Source of Truth fuer den HR-Abwesenheitsmodus (OOO).
 * Reine Datums-Logik auf 'Y-m-d'-Strings (lexikographisch == chronologisch),
 * dependency-frei -> unit-testbar (Modul-Test-Konvention).
 *
 * Es gibt KEINEN Reset des enabled-Flags irgendwo — der Zustand ergibt sich
 * rein aus den Werten (lazy Auto-Off ab dem Wieder-da-Tag).
 */
final class OooMode
{
    public const STATE_OFF = 'off';
    public const STATE_PENDING = 'pending'; // geplant, Abwesenheit noch nicht begonnen
    public const STATE_ACTIVE = 'active';

    public static function state(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): string
    {
        if (!$enabled || $fromYmd === null || $backAtYmd === null) {
            return self::STATE_OFF; // fehlende Daten: nie "ewig aktiv"
        }
        if ($todayYmd >= $backAtYmd) {
            return self::STATE_OFF; // ab dem Wieder-da-Tag ist HR zurueck
        }
        if ($todayYmd < $fromYmd) {
            return self::STATE_PENDING;
        }
        return self::STATE_ACTIVE;
    }

    public static function isActive(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): bool
    {
        return self::state($enabled, $fromYmd, $backAtYmd, $todayYmd) === self::STATE_ACTIVE;
    }
}
```

- [ ] **Step 4: GREEN verifizieren**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter OooModeTest`
Expected: PASS (6 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/OooMode.php tests/Unit/OooModeTest.php
git commit -m "feat(recruiting): OooMode — purer Drei-Zustand (off/pending/active) fuer den Abwesenheitsmodus

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Template-Builder `namedValues` (TDD) + Sender-Durchreichung

**Files:**
- Modify: `platforms-recruiting/src/Services/Comms/HoldingTemplateComponents.php`
- Modify: `platforms-recruiting/src/Services/Comms/HoldingTemplateSender.php` (Z.28, Z.52, Z.62–69, Z.80–83)
- Test: `platforms-recruiting/tests/Unit/HoldingTemplateComponentsTest.php` (existiert er, erweitern; sonst neu anlegen)

**Interfaces:**
- Consumes: Task 1: `sendTemplate(..., bool $isAutoReply = false)`.
- Produces: `HoldingTemplateComponents::build(array $templateComponents, string $firstName, array $namedValues = []): array`; `HoldingTemplateSender::sendToMany(int $teamId, iterable $recipients, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array`; `sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array`.

- [ ] **Step 1: Failing Tests schreiben**

Falls `tests/Unit/HoldingTemplateComponentsTest.php` nicht existiert, neu anlegen mit Namespace/Basisklasse wie in Task 2; diese Tests hinzufügen:

```php
    public function test_named_values_fill_matching_params(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Wir sind vom {{von}} bis {{bis}} abwesend und ab {{wieder_da}} wieder da.',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'von', 'example' => '01.01.2026'],
                ['param_name' => 'bis', 'example' => '02.01.2026'],
                ['param_name' => 'wieder_da', 'example' => '03.01.2026'],
            ]],
        ]];

        $result = HoldingTemplateComponents::build($components, '', [
            'von' => '14.07.2026', 'bis' => '18.07.2026', 'wieder_da' => '21.07.2026',
        ]);

        $params = $result[0]['parameters'];
        $this->assertSame('14.07.2026', $params[0]['text']);
        $this->assertSame('18.07.2026', $params[1]['text']);
        $this->assertSame('21.07.2026', $params[2]['text']);
        $this->assertSame('von', $params[0]['parameter_name']);
    }

    public function test_named_values_take_precedence_over_examples_and_name_logic(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Hallo {{name}}, wieder da am {{wieder_da}}.',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'name', 'example' => 'Max'],
                ['param_name' => 'wieder_da', 'example' => '01.01.2026'],
            ]],
        ]];

        // name kommt weiterhin aus firstName, wieder_da aus namedValues
        $result = HoldingTemplateComponents::build($components, 'Nini', ['wieder_da' => '21.07.2026']);
        $params = $result[0]['parameters'];
        $this->assertSame('Nini', $params[0]['text']);
        $this->assertSame('21.07.2026', $params[1]['text']);
    }

    public function test_missing_named_value_falls_back_to_example_then_empty_guard(): void
    {
        $components = [[
            'type' => 'BODY',
            'text' => 'Bis {{bis}}.',
            'example' => [],
        ]];

        // kein namedValue, kein Example, kein firstName -> leerer Param -> Guard greift
        $result = HoldingTemplateComponents::build($components, '', []);
        $this->assertTrue(HoldingTemplateComponents::hasEmptyRequiredParam($result));
    }
```

- [ ] **Step 2: RED verifizieren**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HoldingTemplateComponentsTest`
Expected: FAIL — `Too few arguments`/Assertion-Fehler (build kennt `$namedValues` noch nicht).

- [ ] **Step 3: `build()` erweitern**

In `HoldingTemplateComponents.php` — Signatur + Wertermittlung. **Vorher (Z.19, Z.36–41):**

```php
    public static function build(array $templateComponents, string $firstName): array
```
```php
            foreach ($matches[1] as $i => $paramName) {
                $isNameVar = in_array(strtolower($paramName), ['name', 'vorname', '1'], true);
                $example = $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '';

                $value = $isNameVar ? $firstName : ($example !== '' ? $example : $firstName);
```

**Nachher:**

```php
    public static function build(array $templateComponents, string $firstName, array $namedValues = []): array
```
```php
            foreach ($matches[1] as $i => $paramName) {
                $isNameVar = in_array(strtolower($paramName), ['name', 'vorname', '1'], true);
                $example = $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '';

                // Explizit uebergebene Werte (z.B. OOO-Daten) haben Vorrang;
                // name/vorname kommt weiterhin aus firstName (Holding/Voice unveraendert).
                if (!$isNameVar && array_key_exists($paramName, $namedValues)) {
                    $value = (string) $namedValues[$paramName];
                } else {
                    $value = $isNameVar ? $firstName : ($example !== '' ? $example : $firstName);
                }
```

- [ ] **Step 4: Sender-Durchreichung**

In `HoldingTemplateSender.php`:

Signaturen — **Vorher (Z.28, Z.80):**

```php
    public function sendToMany(int $teamId, iterable $recipients, string $settingsKey = 'comms_holding_template_id'): array
```
```php
    public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id'): array
```

**Nachher:**

```php
    public function sendToMany(int $teamId, iterable $recipients, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array
```
```php
    public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array
```

Aufruf-Stellen — **Vorher (Z.52, Z.62–69, Z.82):**

```php
            $components = HoldingTemplateComponents::build($template->components ?? [], $firstName);
```
```php
                $this->whatsApp->sendTemplate(
                    channel: $channel,
                    to: $phone,
                    templateName: $template->name,
                    components: $components,
                    languageCode: $template->language ?? 'de',
                    sender: auth()->user(),
                );
```
```php
        return $this->sendToMany($teamId, [['phone' => $phone, 'first_name' => $firstName]], $settingsKey);
```

**Nachher:**

```php
            $components = HoldingTemplateComponents::build($template->components ?? [], $firstName, $namedValues);
```
```php
                $this->whatsApp->sendTemplate(
                    channel: $channel,
                    to: $phone,
                    templateName: $template->name,
                    components: $components,
                    languageCode: $template->language ?? 'de',
                    sender: auth()->user(),
                    isAutoReply: $isAutoReply,
                );
```
```php
        return $this->sendToMany($teamId, [['phone' => $phone, 'first_name' => $firstName]], $settingsKey, $namedValues, $isAutoReply);
```

- [ ] **Step 5: GREEN + volle Suite**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: alles grün (neue + bestehende Tests), keine Regression.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Comms/HoldingTemplateComponents.php src/Services/Comms/HoldingTemplateSender.php tests/Unit/HoldingTemplateComponentsTest.php
git commit -m "feat(recruiting): Template-Builder mit namedValues + isAutoReply-Durchreichung im Sender

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: `OooAutoReplyHandler` + Listener-Hook + Voice-Flag

**Files:**
- Create: `platforms-recruiting/src/Services/Comms/OooAutoReplyHandler.php`
- Modify: `platforms-recruiting/src/Listeners/HandleWhatsAppInboundForRecruiting.php` (Konstruktor Z.18–23, Hook nach Z.45 — nach dem `inbound_received`-Log, VOR dem Kontext-Gate Z.50)
- Modify: `platforms-recruiting/src/Services/Comms/VoiceNoteAutoReplyHandler.php` (Z.57)

**Interfaces:**
- Consumes: `OooMode` (Task 2), `HoldingTemplateSender::sendOne(..., array $namedValues, bool $isAutoReply)` + `configuredTemplateName()` (Task 3), `VoiceNoteAutoReplyThrottle::shouldSkip(?int, int, int=24)` (bestehend), Settings-Keys aus Global Constraints.
- Produces: `OooAutoReplyHandler::handle(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): void`; Konstante `OooAutoReplyHandler::SETTINGS_KEY = 'comms_ooo_template_id'`.

- [ ] **Step 1: Handler schreiben**

`src/Services/Comms/OooAutoReplyHandler.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * HR-Abwesenheitsmodus: schickt bei aktivem OOO automatisch das konfigurierte
 * Abwesenheits-Template auf eingehende Nachrichten zurueck. Gedrosselt auf
 * 1x/24h je Konversation. Gilt fuer Bewerber-, Mitarbeiter- und kontextlose
 * Threads — nie fuer Fremd-Kontexte (Helpdesk, Sales, ...).
 *
 * Der Send wird mit is_auto_reply=true markiert und zaehlt damit NICHT als
 * Antwort im "verpasst"-Zaehler (ConversationInboxService).
 */
final class OooAutoReplyHandler
{
    public const SETTINGS_KEY = 'comms_ooo_template_id';

    public function __construct(private readonly HoldingTemplateSender $sender) {}

    public function handle(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): void
    {
        if (!$this->isEligibleContext($thread->context_model)) {
            return;
        }

        $teamId = (int) $channel->team_id;
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        if (!OooMode::isActive(
            (bool) $settings->getSetting('comms_ooo_enabled', false),
            $settings->getSetting('comms_ooo_from'),
            $settings->getSetting('comms_ooo_back_at'),
            now()->format('Y-m-d'),
        )) {
            return;
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        if ($phone === '') {
            return;
        }

        if ($this->isBlockedContact($thread, $teamId, $phone)) {
            CommsLog::log(
                event: 'ooo_autoreply_skipped_blacklisted',
                status: 'info',
                summary: "OOO-Auto-Reply uebersprungen: Kontakt geblockt/geblacklistet ({$phone})",
                details: ['thread_id' => $thread->id],
                extra: [
                    'team_id' => $teamId,
                    'channel_type' => 'whatsapp',
                    'channel_id' => $channel->id,
                    'source' => 'recruiting_ooo_autoreply',
                    'recipient' => $phone,
                ],
            );
            return;
        }

        // Feature aktiv? (Template konfiguriert UND bei Meta genehmigt)
        $templateName = $this->sender->configuredTemplateName($teamId, self::SETTINGS_KEY);
        if ($templateName === null) {
            return;
        }

        // Drosselung: 1x/24h je Konversation, gekeyt pro Thread + Template-Name
        // (gleiches Muster wie VoiceNoteAutoReplyHandler — kein Cross-Blocking).
        $last = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $thread->id)
            ->where('direction', 'outbound')
            ->where('template_name', $templateName)
            ->latest('created_at')
            ->first();

        if (VoiceNoteAutoReplyThrottle::shouldSkip($last?->created_at?->getTimestamp(), time())) {
            return;
        }

        $fmt = static fn (?string $ymd): string => $ymd ? \Carbon\Carbon::parse($ymd)->format('d.m.Y') : '';
        $namedValues = [
            'von'       => $fmt($settings->getSetting('comms_ooo_from')),
            'bis'       => $fmt($settings->getSetting('comms_ooo_until')),
            'wieder_da' => $fmt($settings->getSetting('comms_ooo_back_at')),
        ];

        // firstName bewusst leer — das OOO-Template nutzt kein {{name}}.
        $result = $this->sender->sendOne($teamId, $phone, '', self::SETTINGS_KEY, $namedValues, true);

        CommsLog::log(
            event: 'ooo_autoreply_sent',
            status: ($result['error'] === null && ($result['sent'] ?? 0) > 0) ? 'success' : 'error',
            summary: $result['error'] === null
                ? "OOO-Abwesenheitsnotiz an {$phone} gesendet"
                : "OOO-Abwesenheitsnotiz fehlgeschlagen: {$result['error']}",
            details: ['thread_id' => $thread->id, 'template' => $templateName, 'result' => $result],
            extra: [
                'team_id' => $teamId,
                'channel_type' => 'whatsapp',
                'channel_id' => $channel->id,
                'source' => 'recruiting_ooo_autoreply',
                'recipient' => $phone,
            ],
        );
    }

    /** Bewerber-, Mitarbeiter- oder kontextloser Thread — sonst kein OOO. */
    private function isEligibleContext(?string $contextModel): bool
    {
        if ($contextModel === null) {
            return true;
        }

        return $contextModel === (new RecApplicant)->getMorphClass()
            || $contextModel === RecApplicant::class
            || $contextModel === RecEmployee::class;
    }

    /**
     * Zweistufiges Block-Gate: is_blacklisted ODER Kontakt-Status BLOCKED.
     * Erst der verknuepfte Thread-Kontakt; ohne Kontakt Fallback-Lookup ueber
     * die Telefonnummer in mehreren Schreibweisen (CRM-Muster, +49/ohne +).
     */
    private function isBlockedContact(CommsWhatsAppThread $thread, int $teamId, string $phone): bool
    {
        $contact = $thread->contact;
        if ($contact instanceof CrmContact) {
            return (bool) $contact->is_blacklisted
                || $contact->contactStatus?->code === 'BLOCKED';
        }

        $variants = array_unique([$phone, '+' . ltrim($phone, '+'), ltrim($phone, '+')]);

        return CrmContact::query()
            ->where('team_id', $teamId)
            ->where(function ($q) {
                $q->where('is_blacklisted', true)
                    ->orWhereHas('contactStatus', fn ($s) => $s->where('code', 'BLOCKED'));
            })
            ->whereHas('phoneNumbers', fn ($p) => $p->whereIn('international', $variants))
            ->exists();
    }
}
```

- [ ] **Step 2: Listener-Hook einbauen**

In `HandleWhatsAppInboundForRecruiting.php`:

Konstruktor — **Vorher (Z.18–23):**

```php
    public function __construct(
        private IncomingApplicationService $applicationService,
        private ReminderResponseHandler $reminderResponseHandler,
        private ApplicationMatchingService $matchingService,
        private VoiceNoteAutoReplyHandler $voiceNoteAutoReply,
    ) {}
```

**Nachher:**

```php
    public function __construct(
        private IncomingApplicationService $applicationService,
        private ReminderResponseHandler $reminderResponseHandler,
        private ApplicationMatchingService $matchingService,
        private VoiceNoteAutoReplyHandler $voiceNoteAutoReply,
        private \Platform\Recruiting\Services\Comms\OooAutoReplyHandler $oooAutoReply,
    ) {}
```

Hook direkt NACH dem `CommsLog::log(event: 'inbound_received', ...)`-Block (endet Z.45) und VOR dem Kontext-Gate (`if ($thread->context_model !== null) {`, Z.50) einfügen:

```php
        // HR-Abwesenheitsmodus: Auto-Quittung VOR dem Kontext-Gate, damit auch
        // Mitarbeiter-Threads erfasst werden (eigener Kontext-Filter im Handler,
        // Fremd-Kontexte bleiben aussen vor). Fehler stoppen nie den Inbound-Flow.
        try {
            $this->oooAutoReply->handle($channel, $thread, $message);
        } catch (\Throwable $e) {
            Log::warning('[Recruiting] OOO-Auto-Reply fehlgeschlagen', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }
```

- [ ] **Step 3: Voice-Handler markiert seine Sends**

In `VoiceNoteAutoReplyHandler.php` Z.57 — **Vorher:**

```php
        $result = $this->sender->sendOne($teamId, $phone, $this->resolveFirstName($thread), self::SETTINGS_KEY);
```

**Nachher (Sendeverhalten identisch, nur Markierung):**

```php
        $result = $this->sender->sendOne($teamId, $phone, $this->resolveFirstName($thread), self::SETTINGS_KEY, [], true);
```

- [ ] **Step 4: Lint + volle Suite**

```bash
php -l src/Services/Comms/OooAutoReplyHandler.php
php -l src/Listeners/HandleWhatsAppInboundForRecruiting.php
php -l src/Services/Comms/VoiceNoteAutoReplyHandler.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: `No syntax errors detected` ×3, Suite grün.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/OooAutoReplyHandler.php src/Listeners/HandleWhatsAppInboundForRecruiting.php src/Services/Comms/VoiceNoteAutoReplyHandler.php
git commit -m "feat(recruiting): OOO-Auto-Reply-Handler im Inbound (Blacklist-Gate, 24h-Throttle, is_auto_reply)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Verpasst-Zähler — effektiver letzter Ausgang

**Files:**
- Modify: `platforms-recruiting/src/Services/Comms/ConversationInboxService.php` (build Z.87–103, counts Z.112–143; neuer Import + private Methode)

**Interfaces:**
- Consumes: Spalte `is_auto_reply` (Task 1).
- Produces: keine neuen öffentlichen Interfaces — `build()`/`counts()` verhalten sich extern gleich, nutzen aber intern den effektiven letzten menschlichen Ausgang.

- [ ] **Step 1: Private Query-Methode + Import ergänzen**

Import oben ergänzen:

```php
use Platform\Crm\Models\CommsWhatsAppMessage;
```

Private Methode am Ende der Klasse:

```php
    /**
     * Letzter MENSCHLICHER Outbound je Thread (Auto-Quittungen wie OOO/Voice
     * via is_auto_reply ausgeschlossen). EINE gruppierte, index-gestuetzte
     * Query (Composite-Index thread_id+created_at) — kein N+1. Laeuft bewusst
     * unconditional fuer alle Teams (fixt auch den Voice-Fall ohne Config).
     *
     * WICHTIG: Threads ohne menschlichen Outbound fehlen im Ergebnis —
     * der Aufrufer MUSS das als null (nie beantwortet) werten. KEIN Fallback
     * auf thread.last_outbound_at (wurde von der Auto-Reply gebumpt; ein
     * Fallback regressiert still in genau den Bug, den das Flag fixt).
     *
     * @param array<int, int> $threadIds
     * @return array<int, int> thread_id => Unix-TS des letzten menschlichen Outbounds
     */
    private function humanOutboundTimestamps(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('comms_whatsapp_thread_id, MAX(created_at) AS last_human_outbound_at')
            ->pluck('last_human_outbound_at', 'comms_whatsapp_thread_id')
            ->map(fn ($v) => \Carbon\Carbon::parse((string) $v)->getTimestamp())
            ->all();
    }
```

- [ ] **Step 2: `build()` umstellen**

Nach `$bySubject = $this->dedupedThreads($teamId);` (Z.32) einfügen:

```php
        $humanOutbound = $this->humanOutboundTimestamps(
            array_map(fn ($t) => (int) $t->id, array_values($bySubject)),
        );
```

Im Row-Array (Z.99) — **Vorher:**

```php
                'last_outbound_at' => $thread->last_outbound_at?->getTimestamp(),
```

**Nachher:**

```php
                // Effektiver letzter MENSCHLICHER Ausgang; fehlt der Thread in
                // der Map (nur Auto-Reply-Outbounds), gilt null = nie beantwortet.
                'last_outbound_at' => $humanOutbound[(int) $thread->id] ?? null,
```

- [ ] **Step 3: `counts()` umstellen**

Nach `$bySubject = $this->dedupedThreads($teamId);` (Z.120) einfügen:

```php
        $humanOutbound = $this->humanOutboundTimestamps(
            array_map(fn ($t) => (int) $t->id, array_values($bySubject)),
        );
```

Im `ConversationEscalation::compute(...)`-Aufruf (Z.128–134) — **Vorher:**

```php
            $level = ConversationEscalation::compute(
                $thread->last_inbound_at?->getTimestamp(),
                $thread->last_outbound_at?->getTimestamp(),
                $now,
                $yellow,
                $red,
            )->level;
```

**Nachher:**

```php
            $level = ConversationEscalation::compute(
                $thread->last_inbound_at?->getTimestamp(),
                $humanOutbound[(int) $thread->id] ?? null,
                $now,
                $yellow,
                $red,
            )->level;
```

- [ ] **Step 4: Lint + volle Suite**

```bash
php -l src/Services/Comms/ConversationInboxService.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: sauber + Suite grün (`ConversationEscalation`-Tests unverändert gültig — pure Klasse untouched).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/ConversationInboxService.php
git commit -m "feat(recruiting): Verpasst-Zaehler ignoriert Auto-Replies (effektiver letzter menschlicher Ausgang)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: UI — Banner (3 Zustände) + Formular + Template-Picker

**Files:**
- Modify: `platforms-recruiting/src/Livewire/Conversations/Index.php`
- Modify: `platforms-recruiting/resources/views/livewire/conversations/index.blade.php` (Banner nach der `<h1>`-Zeile, Z.33–36)
- Modify: `platforms-recruiting/src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS, nach Z.63)
- Modify: `platforms-recruiting/resources/views/livewire/applicant/applicant-settings-modal.blade.php` (nach dem Voice-Picker-Block, Z.777–791)

**Interfaces:**
- Consumes: `OooMode::state/isActive` (Task 2), `HoldingTemplateSender::configuredTemplateName($teamId, OooAutoReplyHandler::SETTINGS_KEY)` (Task 3/4), Settings-Keys.
- Produces: Livewire-Props/-Methoden `oooForm`, `showOooForm`, `oooState()` (computed), `oooSettingsView()` (computed), `openOooForm()`, `activateOoo()`, `deactivateOoo()`.

- [ ] **Step 1: DEFAULT_SETTINGS ergänzen**

In `RecApplicantSettings.php` nach `'comms_voice_not_supported_template_id' => null,` (Z.63):

```php
        // HR-Abwesenheitsmodus (OOO): Template + Zeitraum. enabled/from/until/
        // back_at werden von der Conversations-Seite gesetzt; Template im
        // Einstellungen-Modal. Auto-Off lazy via OooMode (today >= back_at).
        'comms_ooo_template_id' => null,
        'comms_ooo_enabled' => false,
        'comms_ooo_from' => null,
        'comms_ooo_until' => null,
        'comms_ooo_back_at' => null,
```

- [ ] **Step 2: Livewire-Komponente erweitern**

In `src/Livewire/Conversations/Index.php` — Imports ergänzen:

```php
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\OooAutoReplyHandler;
use Platform\Recruiting\Services\Comms\OooMode;
```

Properties (nach `public array $selected = [];`, Z.34):

```php
    /** OOO-Aktivierungsformular (Y-m-d-Strings aus <input type="date">). */
    public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];
    public bool $showOooForm = false;
```

Methoden (nach `sendTemplateToSelected()`, vor `render()`):

```php
    private function oooSettings(): RecApplicantSettings
    {
        return RecApplicantSettings::getOrCreateForTeam($this->teamId());
    }

    /** off | pending | active — alleinige Quelle: OooMode (nie das rohe Flag). */
    #[Computed]
    public function oooState(): string
    {
        $s = $this->oooSettings();

        return OooMode::state(
            (bool) $s->getSetting('comms_ooo_enabled', false),
            $s->getSetting('comms_ooo_from'),
            $s->getSetting('comms_ooo_back_at'),
            now()->format('Y-m-d'),
        );
    }

    /** Anzeige-Daten fuer Banner (d.m.Y) + Template-Konfig-Status. */
    #[Computed]
    public function oooView(): array
    {
        $s = $this->oooSettings();
        $fmt = static fn (?string $ymd): ?string => $ymd ? \Carbon\Carbon::parse($ymd)->format('d.m.Y') : null;

        return [
            'from' => $fmt($s->getSetting('comms_ooo_from')),
            'back_at' => $fmt($s->getSetting('comms_ooo_back_at')),
            'template_configured' => app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
                ->configuredTemplateName($this->teamId(), OooAutoReplyHandler::SETTINGS_KEY) !== null,
        ];
    }

    public function openOooForm(): void
    {
        if (!$this->oooView['template_configured']) {
            session()->flash('error', 'Kein Abwesenheits-Template konfiguriert (Einstellungen → Kommunikation).');
            return;
        }
        $this->oooForm = ['from' => now()->format('Y-m-d'), 'until' => '', 'back_at' => ''];
        $this->showOooForm = true;
    }

    /** Bis-Datum gesetzt → wieder_da mit bis+1 vorbefuellen (editierbar). */
    public function updated($property): void
    {
        if ($property === 'oooForm.until' && $this->oooForm['until'] !== '' && $this->oooForm['back_at'] === '') {
            $this->oooForm['back_at'] = \Carbon\Carbon::parse($this->oooForm['until'])->addDay()->format('Y-m-d');
        }
    }

    public function activateOoo(): void
    {
        $from = $this->oooForm['from'];
        $until = $this->oooForm['until'];
        $backAt = $this->oooForm['back_at'];

        if ($from === '' || $until === '' || $backAt === '') {
            session()->flash('error', 'Bitte alle drei Daten angeben.');
            return;
        }
        // Y-m-d: String-Vergleich == chronologischer Vergleich
        if (!($from <= $until && $until < $backAt)) {
            session()->flash('error', 'Es muss gelten: von ≤ bis < wieder da.');
            return;
        }
        if ($backAt <= now()->format('Y-m-d')) {
            session()->flash('error', 'Das Wieder-da-Datum muss in der Zukunft liegen.');
            return;
        }

        $s = $this->oooSettings();
        $s->setSetting('comms_ooo_from', $from);
        $s->setSetting('comms_ooo_until', $until);
        $s->setSetting('comms_ooo_back_at', $backAt);
        $s->setSetting('comms_ooo_enabled', true);

        $this->showOooForm = false;
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus gespeichert.');
    }

    public function deactivateOoo(): void
    {
        $this->oooSettings()->setSetting('comms_ooo_enabled', false);
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus deaktiviert.');
    }
```

- [ ] **Step 3: Banner ins Blade**

In `resources/views/livewire/conversations/index.blade.php` direkt NACH dem `<div class="flex items-center justify-between">…</div>`-Header-Block (Z.33–36) einfügen:

```blade
    {{-- HR-Abwesenheitsmodus: Zustand IMMER aus OooMode (3 Zustaende), nie aus dem rohen Flag --}}
    @php
        $oooState = $this->oooState;
        $oooView = $this->oooView;
    @endphp
    <div class="rounded-lg border px-4 py-3 text-sm
        @if($oooState === 'active') border-amber-300 bg-amber-50
        @elseif($oooState === 'pending') border-sky-200 bg-sky-50
        @else border-gray-200 bg-white @endif">
        <div class="flex items-center justify-between gap-4">
            <div>
                @if($oooState === 'active')
                    <span class="font-medium text-amber-900">Abwesenheitsmodus aktiv</span>
                    <span class="text-amber-800">— wieder da am {{ $oooView['back_at'] }}. Eingehende Nachrichten erhalten automatisch die Abwesenheitsnotiz (1×/24h je Konversation).</span>
                @elseif($oooState === 'pending')
                    <span class="font-medium text-sky-900">Abwesenheitsmodus geplant</span>
                    <span class="text-sky-800">— ab {{ $oooView['from'] }} (wieder da am {{ $oooView['back_at'] }}).</span>
                @else
                    <span class="font-medium text-gray-700">HR in Abwesenheit</span>
                    <span class="text-gray-500">— Abwesenheitsnotiz fuer eingehende Nachrichten aktivieren.</span>
                @endif
            </div>
            <div class="flex-shrink-0">
                @if($oooState === 'off')
                    <button wire:click="openOooForm"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50">
                        Aktivieren…
                    </button>
                @else
                    <button wire:click="deactivateOoo"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-red-200 text-red-700 bg-white hover:bg-red-50">
                        Deaktivieren
                    </button>
                @endif
            </div>
        </div>

        @if($showOooForm && $oooState === 'off')
            <div class="mt-3 pt-3 border-t border-gray-200 flex flex-wrap items-end gap-4">
                <label class="text-xs text-gray-600">Abwesend von
                    <input type="date" wire:model="oooForm.from"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs text-gray-600">Bis (letzter Tag)
                    <input type="date" wire:model.live="oooForm.until"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs text-gray-600">Wieder da ab
                    <input type="date" wire:model="oooForm.back_at"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <button wire:click="activateOoo"
                        class="px-3 py-1.5 text-sm font-medium rounded-md border border-emerald-200 text-emerald-700 bg-white hover:bg-emerald-50">
                    Speichern &amp; aktivieren
                </button>
                <button wire:click="$set('showOooForm', false)"
                        class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                    Abbrechen
                </button>
            </div>
        @endif
    </div>
```

Hinweis: Der Flash-`error`-Block existiert im Blade vermutlich nur für `message` — prüfen; falls kein `session('error')`-Block vorhanden ist, analog zum `message`-Block (Z.38–40) einen roten `error`-Block ergänzen.

- [ ] **Step 4: Template-Picker ins Einstellungen-Modal**

In `applicant-settings-modal.blade.php` direkt NACH dem Voice-Picker-Block (endet mit dem `</p>` bei Z.791, vor dem `@else`) einfügen:

```blade
                    {{-- HR-Abwesenheitsmodus (OOO) --}}
                    <x-ui-input-select
                        name="settings.comms_ooo_template_id"
                        label="WhatsApp Template — Abwesenheitsnotiz (HR in Abwesenheit)"
                        :options="$this->availableWhatsAppTemplates"
                        optionValue="id"
                        optionLabel="label"
                        :nullable="true"
                        nullLabel="– kein Abwesenheitsmodus –"
                        wire:model="settings.comms_ooo_template_id"
                    />
                    <p class="text-xs text-[var(--ui-muted)] -mt-2">
                        Wird bei aktivem Abwesenheitsmodus (Kommunikations-Übersicht) automatisch auf eingehende
                        Nachrichten gesendet — höchstens 1× pro 24&nbsp;h je Konversation. Body-Variablen
                        <span class="font-mono">@{{von}}</span>, <span class="font-mono">@{{bis}}</span> und
                        <span class="font-mono">@{{wieder_da}}</span> werden automatisch mit den Abwesenheitsdaten
                        gefüllt. Die Antwort zählt nicht als Bearbeitung — Konversationen bleiben „verpasst".
                    </p>
```

- [ ] **Step 5: Lint + volle Suite + manuelle Verifikation**

```bash
php -l src/Livewire/Conversations/Index.php
php -l src/Models/RecApplicantSettings.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Manuelle Checkliste (lokal/Staging, aus Spec §Tests):
1. Template im Einstellungen-Modal wählen → Banner „Aktivieren…" funktioniert.
2. Ohne Template → „Aktivieren…" zeigt Fehler-Flash mit Hinweis auf Einstellungen.
3. Aktivieren mit `von=heute, bis=morgen, wieder_da=übermorgen` → Banner „aktiv".
4. Vorplanung (`von` in Zukunft) → Banner „geplant ab …", Inbound löst KEIN Template aus.
5. Aktiver Modus: Inbound → OOO-Template kommt mit korrekten Datumswerten; 2. Inbound < 24h → kein 2. Send.
6. Konversation bleibt ungelesen + „verpasst" — auch wenn der EINZIGE Outbound die Auto-Reply ist (Null-Fall: kein stiller Fallback auf `thread.last_outbound_at`).
7. Geblockter/geblacklisteter Kontakt → kein Send, Log `ooo_autoreply_skipped_blacklisted`.
8. Nach `wieder_da` (bzw. Deaktivieren): kein Send mehr, Banner zeigt Aus.
9. Sidebar-Badge (Eskalations-Zähler) konsistent mit der Liste.

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Conversations/Index.php resources/views/livewire/conversations/index.blade.php src/Models/RecApplicantSettings.php resources/views/livewire/applicant/applicant-settings-modal.blade.php
git commit -m "feat(recruiting): Abwesenheitsmodus-UI — Banner (aus/geplant/aktiv), Formular, Template-Picker

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Deploy-Hinweise (nach Merge, ausserhalb dieses Plans)

- BEIDE Repos pushen; meingedeck `composer update martin3r/platform-crm martin3r/platform-recruiting` + composer.lock-Bump committen (bekannte Konvention), Deploy → Migration läuft dort.
- Kein Queue-Restart nötig für den Inbound-Pfad? DOCH prüfen: Inbound-Listener können queued laufen — nach Deploy sicherheitshalber `queue:restart` (bekannte Konvention „deployter Fix greift nicht").

## Self-Review

**Spec coverage:** Settings-Keys → Task 6 Step 1 + Nutzung Tasks 4/6 ✓. OooMode 3-Zustand + Grenztage → Task 2 ✓. CRM `is_auto_reply` + sendTemplate-Param ✓ Task 1. Builder namedValues + Vorrang + Leer-Guard → Task 3 ✓. Handler-Gates in Spec-Reihenfolge (Kontext → isActive → Blacklist zweistufig mit Nummern-Varianten → Template → Throttle → Send → Log) → Task 4 ✓. Voice setzt Flag, Verhalten sonst identisch → Task 4 Step 3 ✓. Verpasst-Query: created_at, unconditional, Null-Behandlung ohne Fallback, build+counts → Task 5 ✓. UI 3 Zustände aus OooMode, Validierung from≤until<back_at & back_at>heute, native date-inputs, Picker im Modal → Task 6 ✓. Kein Backfill ✓ (kein Task). Manuelle Checkliste ✓ Task 6 Step 5.

**Placeholder scan:** keine TBD/TODO; jeder Code-Step mit vollständigem Code. Ein bewusst offener Prüfauftrag (exakte Argumentliste des `handleSendResponse`-Aufrufs in Task 1 Step 4; Flash-`error`-Block in Task 6 Step 3) ist als konkrete Prüfanweisung formuliert, nicht als Platzhalter.

**Type consistency:** `OooMode::state(bool, ?string, ?string, string): string` konsistent Task 2/4/6. `sendOne(int, string, string, string, array, bool)` konsistent Task 3/4. `SETTINGS_KEY`-Konstante Task 4 → Task 6. `humanOutboundTimestamps(array): array<int,int>` nur intern Task 5. `sendTemplate(..., bool $isAutoReply = false)` Task 1 → Task 3 (named arg `isAutoReply:`) ✓.
