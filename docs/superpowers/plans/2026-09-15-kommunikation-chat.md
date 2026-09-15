# Kommunikation als Chat — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine zweite Kommunikations-Seite unter `/recruiting/conversations-neu`
mit Postfach-Layout, Antworten im Chat, Erledigt-Zustand und einer
lueckenlosen Grundmenge — waehrend die alte Seite unveraendert weiterlaeuft.

**Architecture:** Neue Livewire-Komponente `Conversations\Inbox` mit eigenem,
schlankem Lesepfad (`Services/Comms/InboxQuery`). Die Grundmenge kommt ueber
das WhatsApp-Kanal-Set des Recruitings, nicht ueber den Thread-Kontext. Der
Erledigt-Zustand liegt in einer eigenen Recruiting-Tabelle. Sprechblasen,
Nachrichten-Mapper und Freitext-Sender der Dispo werden **aufgerufen**, nicht
kopiert und nicht verschoben.

**Tech Stack:** PHP 8.3, Laravel 11, Livewire 3, Tailwind, PHPUnit 11,
SQLite-Capsule fuer Integrationstests.

**Spec:** `docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md`

## Global Constraints

- **Die alte Seite wird nicht angefasst.** `Livewire\Conversations\Index`,
  `conversations/index.blade.php`, `ConversationInboxService`,
  `routes/web.php:74-75` und der Sidebar-Eintrag bleiben, wie sie sind. Einzige
  erlaubte Ausnahme: der additive `levelOrder()`-Helfer in Task 4.
- **Die Dispo wird nicht angefasst.** Keine Datei unter `src/Livewire/Dispo/`,
  `src/Services/Zas/Dispo/` oder `resources/views/livewire/dispo/` wird
  geaendert. Sie werden nur aufgerufen bzw. per `@include` eingebunden.
- **Kein Sidebar-Eintrag** fuer die neue Seite.
- **Testrunner** (Modul hat kein eigenes `vendor/`), immer aus dem Modulordner:
  `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter <Testname>`
- **Kein `--order-by=random`.** Die Suite ist nur in Default-Reihenfolge
  verlaesslich gruen (Begruendung steht in `phpunit.xml`).
- **Blade-Regeln** (sonst bricht es still oder mit 500):
  - `@php` **nur** in Blockform mit `@endphp` — nie `@php($x)` inline.
  - Keine Direktive an ein Wortzeichen geklebt (`da@if`, `Uhr@endif`
    kompilieren **nicht**, sie bleiben als Text stehen).
  - Keine `x-ui-*`-Komponenten in dieser View (die Dispo-Kommunikation nutzt
    ebenfalls keine); Werte vorberechnen statt inline im Attribut zu rechnen.
  - Pruefen mit `php tools/blade-check.php <datei>` — `php -l` prueft an einer
    `.blade.php` **nichts**.
- **Pure Logik ohne Laravel** gehoert in Klassen, die der Unit-Autoloader laden
  kann (nur `src/`, keine Facades, keine Eloquent-Modelle) — Muster:
  `ConversationEscalation`.
- **Sprache der Oberflaeche:** Deutsch, Sie-neutral, wie die bestehende Seite.
- **Commit-Fuss** an jedem Commit:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`

---

## Dateiuebersicht

**Neu:**

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php` | Tabelle fuer den Erledigt-Stempel |
| `src/Models/RecConversationHandled.php` | Eloquent-Modell dazu |
| `src/Services/Comms/ConversationHandledState.php` | pure Regel: erledigt ja/nein inkl. Wiederauftauchen |
| `src/Services/Comms/RecruitingChannelResolver.php` | Kanal-Set des Recruiting-WABA-Accounts |
| `src/Services/Comms/InboxRow.php` | Zeilen-DTO der neuen Seite |
| `src/Services/Comms/InboxFilter.php` | Filter-DTO |
| `src/Services/Comms/InboxQuery.php` | Lesepfad: Kanal-Menge → Eskalation → Sortierung → Seite → Anreicherung |
| `src/Services/Comms/ApplicantTemplateSender.php` | Template-Versand ohne den Formular-Token-Fehler |
| `src/Livewire/Conversations/Inbox.php` | die neue Komponente |
| `resources/views/livewire/conversations/inbox.blade.php` | die neue Ansicht |
| `src/Console/Commands/ArchiveOldConversations.php` | Backfill-Kommando |
| `tests/Unit/Comms/ConversationHandledStateTest.php` | |
| `tests/Unit/Comms/TemplateTokenDecisionTest.php` | |
| `tests/Integration/ConversationHandledTableTest.php` | |
| `tests/Integration/RecruitingChannelSetTest.php` | |
| `tests/Integration/InboxQueryCompletenessTest.php` | **der wichtigste Test des Pakets** |
| `tests/Integration/ArchiveOldConversationsCommandTest.php` | |
| `tests/Unit/MessagesPartialIncludeContractTest.php` | Vertrag des Sprechblasen-Partials |

**Geaendert:**

| Datei | Aenderung |
|---|---|
| `routes/web.php` | eine neue Route, nach Zeile 75 |
| `src/Services/Comms/ConversationInboxReport.php` | additiv: `levelOrder()` oeffentlich |

---

### Task 0: Branch

- [ ] **Schritt 1: Stand pruefen und Branch anlegen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git status --short
git rev-parse HEAD origin/main     # beide Hashes muessen gleich sein
git checkout -b feat/kommunikation-chat
```

Weichen die Hashes ab: **stoppen** und nachfragen. Nicht auf einem alten Stand
losbauen.

---

### Task 1: Tabelle und Modell fuer den Erledigt-Stempel

**Files:**
- Create: `database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php`
- Create: `src/Models/RecConversationHandled.php`
- Test: `tests/Integration/ConversationHandledTableTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `RecConversationHandled` mit `$table = 'rec_conversation_handled'`,
  Konstanten `REASON_MANUAL = 'manual'`, `REASON_BACKFILL = 'backfill'`,
  fillable `team_id, comms_whatsapp_thread_id, handled_at, handled_by_user_id, handled_reason`,
  Cast `handled_at => datetime`.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Task 1: Der Erledigt-Stempel liegt in einer eigenen Recruiting-Tabelle
 * (die Thread-Tabelle selbst gehoert platform-crm). Ein Thread darf nur
 * EINEN Stempel tragen — sonst haengt der Zustand davon ab, welche Zeile
 * zuerst gelesen wird.
 */
class ConversationHandledTableTest extends TestCase
{
    private const TEAM = 701;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $migration = require dirname(__DIR__, 2)
            . '/database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php';
        $migration->up();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_stempel_laesst_sich_schreiben_und_lesen(): void
    {
        $row = RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4711,
            'handled_at' => '2026-09-15 10:00:00',
            'handled_by_user_id' => 1,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);

        $this->assertSame('manual', $row->handled_reason);
        $this->assertSame('2026-09-15 10:00', $row->fresh()->handled_at->format('Y-m-d H:i'));
    }

    public function test_ein_thread_kann_nur_einen_stempel_tragen(): void
    {
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4712,
            'handled_at' => '2026-09-15 10:00:00',
            'handled_reason' => RecConversationHandled::REASON_BACKFILL,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4712,
            'handled_at' => '2026-09-15 11:00:00',
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);
    }
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationHandledTableTest`
Expected: FAIL — die Migrationsdatei existiert nicht (`require` schlaegt fehl).

- [ ] **Schritt 3: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erledigt-Stempel fuer WhatsApp-Konversationen (Spec 15.09.2026).
 *
 * Warum eine eigene Tabelle statt einer Spalte an comms_whatsapp_threads:
 * die Thread-Tabelle liegt in platform-crm und wird von drei Modulen geteilt;
 * "HR hat das abgehakt" ist ein reiner Recruiting-Begriff. Kein Fremdschluessel
 * ueber die Modulgrenze — verwaiste Zeilen sind moeglich und harmlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_conversation_handled', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('comms_whatsapp_thread_id')->unique();
            $table->timestamp('handled_at');
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->string('handled_reason', 32)->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_handled');
    }
};
```

- [ ] **Schritt 4: Modell schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Thread, den HR abgehakt hat. Fehlt die Zeile, ist nichts abgehakt.
 * Ob der Stempel noch gilt, entscheidet NICHT dieses Modell, sondern
 * ConversationHandledState — ein spaeterer Eingang hebt ihn auf.
 */
class RecConversationHandled extends Model
{
    public const REASON_MANUAL = 'manual';
    public const REASON_BACKFILL = 'backfill';

    protected $table = 'rec_conversation_handled';

    protected $fillable = [
        'team_id',
        'comms_whatsapp_thread_id',
        'handled_at',
        'handled_by_user_id',
        'handled_reason',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];
}
```

- [ ] **Schritt 5: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationHandledTableTest`
Expected: PASS (2 Tests)

- [ ] **Schritt 6: Committen**

```bash
git add database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php src/Models/RecConversationHandled.php tests/Integration/ConversationHandledTableTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Tabelle fuer den Erledigt-Stempel an Konversationen

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Die Erledigt-Regel als pure Logik

**Files:**
- Create: `src/Services/Comms/ConversationHandledState.php`
- Test: `tests/Unit/Comms/ConversationHandledStateTest.php`

**Interfaces:**
- Consumes: nichts (bewusst ohne Laravel, damit der Unit-Autoloader reicht)
- Produces: `ConversationHandledState::isHandled(?int $handledAt, ?int $lastInboundAt): bool`
  — beide Parameter sind **Unix-Sekunden**, nicht Carbon.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ConversationHandledState;

/**
 * Task 2: Ein abgehakter Chat bleibt abgehakt — bis die Person erneut
 * schreibt. Das ist ein reiner Zeitvergleich, kein Job und kein zweites
 * Zustandsfeld, das veralten koennte.
 */
class ConversationHandledStateTest extends TestCase
{
    private const T10 = 1_757_930_000; // irgendein fester Zeitpunkt
    private const T11 = 1_757_933_600; // eine Stunde spaeter

    public function test_ohne_stempel_nie_erledigt(): void
    {
        $this->assertFalse(ConversationHandledState::isHandled(null, self::T10));
    }

    public function test_gestempelt_und_kein_neuer_eingang_ist_erledigt(): void
    {
        $this->assertTrue(ConversationHandledState::isHandled(self::T11, self::T10));
    }

    public function test_neuer_eingang_nach_dem_stempel_hebt_ihn_auf(): void
    {
        $this->assertFalse(ConversationHandledState::isHandled(self::T10, self::T11));
    }

    public function test_eingang_exakt_auf_dem_stempel_bleibt_erledigt(): void
    {
        // Gleichstand zaehlt als "war beim Abhaken schon da" — sonst springt
        // ein Chat allein durch Sekundengenauigkeit zurueck in die Liste.
        $this->assertTrue(ConversationHandledState::isHandled(self::T10, self::T10));
    }

    public function test_gestempelt_ohne_jeden_eingang_ist_erledigt(): void
    {
        $this->assertTrue(ConversationHandledState::isHandled(self::T10, null));
    }
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationHandledStateTest`
Expected: FAIL — Klasse nicht gefunden.

- [ ] **Schritt 3: Die Klasse schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Gilt der Erledigt-Stempel noch? Reiner Zeitvergleich, ohne Laravel —
 * damit unit-testbar wie ConversationEscalation.
 *
 * Ein Chat ist genau dann erledigt, wenn er gestempelt wurde UND seither
 * niemand mehr geschrieben hat. Schreibt die Person danach erneut, faellt
 * der Chat von selbst in die Eskalation zurueck; es braucht keinen Job,
 * der Stempel zurueckraeumt.
 */
final class ConversationHandledState
{
    /**
     * @param ?int $handledAt     Unix-TS des Stempels (null = nie abgehakt)
     * @param ?int $lastInboundAt Unix-TS der letzten eingehenden Nachricht
     */
    public static function isHandled(?int $handledAt, ?int $lastInboundAt): bool
    {
        if ($handledAt === null) {
            return false;
        }

        return $lastInboundAt === null || $lastInboundAt <= $handledAt;
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ConversationHandledStateTest`
Expected: PASS (5 Tests)

- [ ] **Schritt 5: Committen**

```bash
git add src/Services/Comms/ConversationHandledState.php tests/Unit/Comms/ConversationHandledStateTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Erledigt-Regel inkl. Wiederauftauchen bei neuem Eingang

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Das Recruiting-Kanal-Set

**Files:**
- Create: `src/Services/Comms/RecruitingChannelResolver.php`
- Test: `tests/Integration/RecruitingChannelSetTest.php`

**Interfaces:**
- Consumes: `RecApplicantSettings::getOrCreateForTeam()`, Setting
  `auto_pilot_wa_account_id`
- Produces:
  - `RecruitingChannelResolver::channelIds(int $teamId): array` — `list<int>`,
    leer wenn nichts konfiguriert (wirft nie)
  - `RecruitingChannelResolver::isConfigured(int $teamId): bool`

Muster: `Services/Zas/Dispo/DispoChannelResolver::dispoChannelIds()`. Der
Account steckt im `meta`-JSON des Kanals unter
`integrations_whatsapp_account_id`; als Rueckfall zaehlt der Kanal, dessen
`sender_identifier` der Account-Nummer entspricht.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Task 3: Die neue Kommunikation waehlt ihre Threads ueber das KANAL-SET aus,
 * nicht ueber den Thread-Kontext (Spec: Lueckenlosigkeit, Fall 2474). Dieser
 * Test haelt fest, was zum Set gehoert: alle aktiven WhatsApp-Kanaele des in
 * den Einstellungen gewaehlten Kontos — keine fremden, keine inaktiven.
 * Aufbau nach dem Muster von DispoConversationChannelSetTest.
 */
class RecruitingChannelSetTest extends TestCase
{
    private const TEAM = 702;

    private static int $accountId = 0;
    private static int $c1 = 0;
    private static int $c2 = 0;
    private static int $fremd = 0;
    private static int $inaktiv = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_set_enthaelt_alle_aktiven_kanaele_des_kontos(): void
    {
        $ids = RecruitingChannelResolver::channelIds(self::TEAM);
        sort($ids);

        $expected = [self::$c1, self::$c2];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains(self::$fremd, $ids);
        $this->assertNotContains(self::$inaktiv, $ids);
    }

    public function test_ohne_konfiguriertes_konto_ist_das_set_leer(): void
    {
        $this->assertSame([], RecruitingChannelResolver::channelIds(999));
        $this->assertFalse(RecruitingChannelResolver::isConfigured(999));
        $this->assertTrue(RecruitingChannelResolver::isConfigured(self::TEAM));
    }

    private static function seedFixtures(): void
    {
        self::$accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-recruiting-waba',
            'phone_number' => '+49 160 5552001',
            'title' => 'Recruiting-WABA',
            'active' => true,
            'user_id' => 1,
        ]);

        self::$c1 = self::createChannel('+49 160 5552001', self::$accountId, true);
        self::$c2 = self::createChannel('+49 160 5552002', self::$accountId, true);
        self::$fremd = self::createChannel('+49 160 5559999', 999999, true);
        self::$inaktiv = self::createChannel('+49 160 5552003', self::$accountId, false);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => self::$accountId],
        ]);
    }

    private static function createChannel(string $sender, int $accountId, bool $active): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM,
            'type' => 'whatsapp',
            'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender,
            'is_active' => $active,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $migration = require $root . '/' . $relative;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();

        return dirname($file, 3);
    }
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter RecruitingChannelSetTest`
Expected: FAIL — `RecruitingChannelResolver` nicht gefunden.

Schlaegt es stattdessen an einem Migrationspfad fehl, die Pfade gegen
`tests/Integration/DispoConversationChannelSetTest.php` abgleichen — dort
stehen die aktuell gueltigen.

- [ ] **Schritt 3: Den Resolver schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Kanal-Set des Recruitings: alle aktiven WhatsApp-Kanaele des in den
 * Einstellungen gewaehlten WABA-Kontos (auto_pilot_wa_account_id).
 *
 * Warum ueber den Kanal und nicht ueber den Thread-Kontext: die alte
 * Kommunikations-Uebersicht filtert auf context_model IN (rec_applicant,
 * RecEmployee) und macht damit jeden Chat unsichtbar, der (noch) an einem
 * blossen CrmContact haengt — Fall 2474, 41 Threads, 22 verlorene
 * Bewerbungen. Ueber den Kanal kann nichts verschwinden.
 *
 * Muster: DispoChannelResolver::dispoChannelIds(). Wirft nie; jeder fehlende
 * Baustein ergibt ein leeres Set, die UI zeigt dann einen Hinweis.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
final class RecruitingChannelResolver
{
    /** @return list<int> */
    public static function channelIds(int $teamId): array
    {
        try {
            $accountId = self::accountId($teamId);
            if ($accountId === 0) {
                return [];
            }

            $account = class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)
                ? \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId)
                : null;
            if (!$account || !$account->active) {
                return [];
            }

            $ids = CommsChannel::query()
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->get()
                ->filter(function ($channel) use ($accountId) {
                    $meta = is_array($channel->meta)
                        ? $channel->meta
                        : (json_decode((string) ($channel->meta ?? '[]'), true) ?: []);

                    return (int) ($meta['integrations_whatsapp_account_id'] ?? 0) === $accountId;
                })
                ->map(fn ($channel) => (int) $channel->id)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }

            // Rueckfall fuer Kanaele ohne Account-Marker im meta-JSON.
            $byNumber = CommsChannel::query()
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            return $byNumber ? [(int) $byNumber->id] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isConfigured(int $teamId): bool
    {
        return self::channelIds($teamId) !== [];
    }

    private static function accountId(int $teamId): int
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        return (int) ($settings->getSetting('auto_pilot_wa_account_id') ?: 0);
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter RecruitingChannelSetTest`
Expected: PASS (2 Tests)

- [ ] **Schritt 5: Committen**

```bash
git add src/Services/Comms/RecruitingChannelResolver.php tests/Integration/RecruitingChannelSetTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Kanal-Set des Recruitings als Grundmenge der Kommunikation

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Der Lesepfad `InboxQuery`

**Files:**
- Create: `src/Services/Comms/InboxRow.php`
- Create: `src/Services/Comms/InboxFilter.php`
- Create: `src/Services/Comms/InboxQuery.php`
- Modify: `src/Services/Comms/ConversationInboxReport.php` (nur additiv:
  `private const LEVEL_ORDER` → `public static function levelOrder()`)
- Test: `tests/Integration/InboxQueryCompletenessTest.php`

**Interfaces:**
- Consumes: `RecruitingChannelResolver::channelIds()`,
  `ConversationEscalation::compute()`, `ConversationHandledState::isHandled()`,
  `RecConversationHandled`
- Produces:
  - `InboxFilter` — Konstruktor
    `(string $level = 'all', string $owner = 'all', string $search = '', bool $handled = false)`;
    `$level` ∈ `all|unread|green|yellow|red|missed`
  - `InboxRow` — oeffentliche, readonly Felder:
    `threadId:int, subjectType:string ('applicant'|'employee'|'unassigned'), subjectId:?int,
    url:?string, title:string, firstName:?string, preview:?string, phone:?string,
    ownerUserId:?int, isUnread:bool, escalation:ConversationEscalation,
    contextLabel:?string, siblingCount:int`
  - `InboxQuery::counts(int $teamId, ?int $now = null): array` — Schluessel
    `unread, green, yellow, red, missed, handled, total`
  - `InboxQuery::page(int $teamId, InboxFilter $filter, int $limit, int $offset, ?int $now = null): array`
    — `['rows' => list<InboxRow>, 'total' => int]`

**Reihenfolge im Lesepfad** (die Reihenfolge ist der Sinn der Klasse):
1. duenne Thread-Zeilen des Kanal-Sets laden — **kein** Kontext-Filter, **kein**
   Zusammenfassen pro Person, nur `whereNotNull('last_inbound_at')`
2. letzten **menschlichen** Ausgang je Thread holen (`is_auto_reply = false`,
   eine gruppierte Query — Muster:
   `ConversationInboxService::humanOutboundTimestamps()`)
3. Stempel je Thread laden, `ConversationHandledState::isHandled()` anwenden
4. Eskalation rechnen, nach `ConversationInboxReport::levelOrder()` sortieren
5. filtern, `total` zaehlen, auf `limit/offset` schneiden
6. **erst jetzt** Namen, Owner und Links fuer die geschnittenen Zeilen laden

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

Der Test setzt fuenf Threads auf, die jeweils eine Falle abbilden:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

/**
 * Task 4 — DER WICHTIGSTE TEST DES PAKETS.
 *
 * Die alte Uebersicht filtert auf context_model IN (rec_applicant, RecEmployee)
 * und fasst pro Person auf den neuesten Thread zusammen. Beides sind
 * Verlustpfade: Fall 2474 (Chat haengt am blossen CrmContact -> unsichtbar,
 * 41 Threads, davon 22 verlorene Bewerbungen) und Fall #307 (zwei Threads
 * derselben Person, einer nur eingehend -> einer unsichtbar).
 *
 * Dieser Test waere im August rot gewesen. Er darf nie wieder rot werden.
 */
class InboxQueryCompletenessTest extends TestCase
{
    private const TEAM = 703;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $fremdChannelId = 0;
    private static int $threadOhneKontext = 0;
    private static int $threadBewerber = 0;
    private static int $threadZwilling = 0;
    private static int $threadFremderKanal = 0;
    private static int $threadErledigt = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_chat_ohne_bewerber_kontext_erscheint_trotzdem(): void
    {
        $ids = $this->threadIds(new InboxFilter());

        $this->assertContains(
            self::$threadOhneKontext,
            $ids,
            'Chat am blossen CrmContact fehlt — das ist Fall 2474.',
        );
    }

    public function test_beide_threads_derselben_person_erscheinen(): void
    {
        $ids = $this->threadIds(new InboxFilter());

        $this->assertContains(self::$threadBewerber, $ids);
        $this->assertContains(self::$threadZwilling, $ids);
    }

    public function test_fremder_kanal_erscheint_nicht(): void
    {
        $this->assertNotContains(self::$threadFremderKanal, $this->threadIds(new InboxFilter()));
    }

    public function test_erledigter_chat_faellt_aus_der_liste_und_den_zaehlern(): void
    {
        $this->assertNotContains(self::$threadErledigt, $this->threadIds(new InboxFilter()));

        $counts = (new InboxQuery())->counts(self::TEAM, self::JETZT);
        $this->assertSame(1, $counts['handled']);
    }

    public function test_erledigt_filter_zeigt_genau_die_abgehakten(): void
    {
        $ids = $this->threadIds(new InboxFilter(handled: true));

        $this->assertSame([self::$threadErledigt], $ids);
    }

    public function test_neuer_eingang_nach_dem_stempel_holt_den_chat_zurueck(): void
    {
        CommsWhatsAppThread::query()
            ->whereKey(self::$threadErledigt)
            ->update(['last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 60)]);

        $this->assertContains(self::$threadErledigt, $this->threadIds(new InboxFilter()));

        // Fuer die folgenden Tests zuruecksetzen.
        CommsWhatsAppThread::query()
            ->whereKey(self::$threadErledigt)
            ->update(['last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000)]);
    }

    public function test_seite_schneidet_und_meldet_die_gesamtzahl(): void
    {
        $result = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 2, 0, self::JETZT);

        $this->assertCount(2, $result['rows']);
        // 3 sichtbare Threads: ohne Kontext, Bewerber, Zwilling.
        // Fremder Kanal und abgehakter Chat gehoeren nicht dazu.
        $this->assertSame(3, $result['total']);
    }

    /** @return list<int> */
    private function threadIds(InboxFilter $filter): array
    {
        $result = (new InboxQuery())->page(self::TEAM, $filter, 50, 0, self::JETZT);

        return array_map(fn ($row) => $row->threadId, $result['rows']);
    }

    private static function seedFixtures(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-rec-inbox', 'phone_number' => '+49 160 5553001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);

        self::$channelId = self::createChannel('+49 160 5553001', $accountId);
        self::$fremdChannelId = self::createChannel('+49 160 5559999', 999999);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // 1) Chat am blossen CrmContact — Fall 2474.
        self::$threadOhneKontext = self::createThread(
            self::$channelId, '+49 151 70000001', 'Platform\\Crm\\Models\\CrmContact', 2484,
        );

        // 2+3) Zwei Threads derselben Person — Fall #307.
        self::$threadBewerber = self::createThread(
            self::$channelId, '+49 151 70000002', 'rec_applicant', 555,
        );
        self::$threadZwilling = self::createThread(
            self::$channelId, '0151 70000002', 'rec_applicant', 555,
        );

        // 4) Fremder Kanal (Dispo) — gehoert nicht hierher.
        self::$threadFremderKanal = self::createThread(
            self::$fremdChannelId, '+49 151 70000003', 'rec_applicant', 556,
        );

        // 5) Abgehakter Chat.
        self::$threadErledigt = self::createThread(
            self::$channelId, '+49 151 70000004', 'rec_applicant', 557,
        );
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => self::$threadErledigt,
            'handled_at' => date('Y-m-d H:i:s', self::JETZT - 1_000),
            'handled_by_user_id' => 1,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);
    }

    private static function createChannel(string $sender, int $accountId): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender, 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function createThread(int $channelId, string $phone, string $contextModel, int $contextId): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => $contextModel,
            'context_model_id' => $contextId,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
            'last_message_preview' => 'Dankeschoen',
        ])->id;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $migration = require $root . '/' . $relative;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        return dirname((new \ReflectionClass($class))->getFileName(), 3);
    }
}
```

Stimmt der Dateiname der Nachrichten-Migration nicht, den echten Namen mit
`ls $(…)/platform-crm/database/migrations | grep whatsapp_messages` holen und
eintragen.

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter InboxQueryCompletenessTest`
Expected: FAIL — `InboxQuery` nicht gefunden.

- [ ] **Schritt 3: `levelOrder()` additiv oeffentlich machen**

In `src/Services/Comms/ConversationInboxReport.php` bleibt `LEVEL_ORDER`
unveraendert; darunter kommt:

```php
    /**
     * Sortier-Prioritaet eines Levels (kleiner = weiter oben). Oeffentlich,
     * damit der neue Lesepfad (InboxQuery) exakt dieselbe Reihenfolge nutzt
     * und die beiden Seiten waehrend der Vorschau nicht unterschiedlich
     * sortieren.
     */
    public static function levelOrder(string $level): int
    {
        return self::LEVEL_ORDER[$level] ?? 9;
    }
```

Im vorhandenen `usort` die zwei Zeilen
`$orderA = self::LEVEL_ORDER[...] ?? 9;` durch
`$orderA = self::levelOrder($a->escalation->level);` ersetzen (analog `$orderB`).
Sonst nichts.

- [ ] **Schritt 4: Die beiden DTOs schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Comms;

/** Eine Zeile der neuen Kommunikations-Liste. Reines DTO. */
final class InboxRow
{
    public function __construct(
        public readonly int $threadId,
        /** 'applicant' | 'employee' | 'unassigned' */
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?string $url,
        /** Name, ersatzweise die Telefonnummer. */
        public readonly string $title,
        public readonly ?string $firstName,
        public readonly ?string $preview,
        public readonly ?string $phone,
        public readonly ?int $ownerUserId,
        public readonly bool $isUnread,
        public readonly ConversationEscalation $escalation,
        /** Etikett fuer fremde Kontexte, z.B. 'hcm_onboarding'. */
        public readonly ?string $contextLabel = null,
        /** Anzahl weiterer Threads derselben Person (0 = keiner). */
        public readonly int $siblingCount = 0,
    ) {}
}
```

```php
<?php

namespace Platform\Recruiting\Services\Comms;

/** Filterzustand der neuen Liste. Reines DTO. */
final class InboxFilter
{
    public function __construct(
        /** all | unread | green | yellow | red | missed */
        public readonly string $level = 'all',
        /** all | mine | <userId> */
        public readonly string $owner = 'all',
        public readonly string $search = '',
        /** true = NUR abgehakte zeigen */
        public readonly bool $handled = false,
        /** fuer owner = 'mine' */
        public readonly ?int $currentUserId = null,
    ) {}
}
```

- [ ] **Schritt 5: `InboxQuery` schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Lesepfad der neuen Kommunikation.
 *
 * Zwei Unterschiede zum alten ConversationInboxService, beide bewusst:
 *
 * 1. GRUNDMENGE UEBER DEN KANAL. Kein Filter auf context_model, kein
 *    Zusammenfassen pro Person. Was auf der Recruiting-Nummer eingeht, steht
 *    in der Liste — auch ohne Bewerber (Fall 2474) und auch als zweiter
 *    Thread derselben Person (Fall #307).
 *
 * 2. ERST SCHNEIDEN, DANN ANREICHERN. Namen, Kontakte und Owner werden nur
 *    fuer die sichtbaren Zeilen geladen. Der alte Dienst laedt alle ~1000
 *    Threads samt Bewerbern bei jedem Render; fuer eine Seite mit
 *    20-Sekunden-Poll ist das zu teuer.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
final class InboxQuery
{
    /** @return array{unread:int, green:int, yellow:int, red:int, missed:int, handled:int, total:int} */
    public function counts(int $teamId, ?int $now = null): array
    {
        $now ??= time();
        $rows = $this->scored($teamId, $now);

        $counts = ['unread' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0,
                   'missed' => 0, 'handled' => 0, 'total' => 0];

        foreach ($rows as $row) {
            if ($row['handled']) {
                $counts['handled']++;
                continue;
            }
            $counts['total']++;
            if ($row['is_unread']) {
                $counts['unread']++;
            }
            $level = $row['escalation']->level;
            if (isset($counts[$level])) {
                $counts[$level]++;
            }
        }

        return $counts;
    }

    /** @return array{rows: list<InboxRow>, total: int} */
    public function page(
        int $teamId,
        InboxFilter $filter,
        int $limit,
        int $offset,
        ?int $now = null,
    ): array {
        $now ??= time();

        // Owner und Namenssuche brauchen Bewerber-Daten, die die duenne Stufe
        // nicht hat — deshalb EINMAL vorab die erlaubten Bewerber-IDs holen
        // statt pro Zeile zu fragen.
        $allowed = $this->allowedSubjectIds($teamId, $filter);

        $rows = array_values(array_filter(
            $this->scored($teamId, $now),
            fn (array $row) => $this->matches($row, $filter, $allowed),
        ));

        usort($rows, static function (array $a, array $b): int {
            $orderA = ConversationInboxReport::levelOrder($a['escalation']->level);
            $orderB = ConversationInboxReport::levelOrder($b['escalation']->level);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            $expA = $a['escalation']->windowExpiresAt ?? PHP_INT_MAX;
            $expB = $b['escalation']->windowExpiresAt ?? PHP_INT_MAX;

            return $expA <=> $expB;
        });

        $total = count($rows);
        $slice = array_slice($rows, $offset, $limit);

        return ['rows' => $this->hydrate($slice), 'total' => $total];
    }

    /**
     * Duenne Zeilen des Kanal-Sets mit Eskalation und Erledigt-Zustand.
     * Noch OHNE Namen — die kosten Joins und werden erst fuer die sichtbare
     * Seite geholt.
     *
     * @return list<array<string, mixed>>
     */
    private function scored(int $teamId, int $now): array
    {
        $channelIds = RecruitingChannelResolver::channelIds($teamId);

        $query = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at');

        if ($channelIds !== []) {
            $query->whereIn('comms_channel_id', $channelIds);
        } else {
            // Rueckfall ohne konfiguriertes Konto: die alte, engere Menge.
            // Die UI sagt das an, damit niemand die Luecke fuer Vollstaendigkeit haelt.
            $query->whereIn('context_model', self::legacyContextModels())
                ->whereNotNull('context_model_id');
        }

        $threads = $query->get([
            'id', 'comms_channel_id', 'remote_phone_number', 'context_model',
            'context_model_id', 'is_unread', 'last_inbound_at', 'last_message_preview',
        ]);

        $threadIds = $threads->map(fn ($t) => (int) $t->id)->all();
        $humanOutbound = $this->humanOutboundTimestamps($threadIds);
        $handledAt = $this->handledTimestamps($teamId, $threadIds);

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);

        // Geschwister je Nummer (letzte 10 Ziffern) zaehlen — nur fuer den Hinweis-Chip.
        $byDigits = [];
        foreach ($threads as $thread) {
            $byDigits[self::digits((string) $thread->remote_phone_number)][] = (int) $thread->id;
        }

        $rows = [];
        foreach ($threads as $thread) {
            $inboundAt = $thread->last_inbound_at?->getTimestamp();
            $id = (int) $thread->id;
            $digits = self::digits((string) $thread->remote_phone_number);

            $rows[] = [
                'thread_id' => $id,
                'channel_id' => (int) $thread->comms_channel_id,
                'phone' => $thread->remote_phone_number,
                'context_model' => (string) ($thread->context_model ?? ''),
                'context_model_id' => $thread->context_model_id ? (int) $thread->context_model_id : null,
                'preview' => $thread->last_message_preview,
                'is_unread' => (bool) $thread->is_unread,
                'escalation' => ConversationEscalation::compute(
                    $inboundAt,
                    $humanOutbound[$id] ?? null,
                    $now,
                    $yellow,
                    $red,
                ),
                'handled' => ConversationHandledState::isHandled($handledAt[$id] ?? null, $inboundAt),
                'siblings' => max(0, count($byDigits[$digits] ?? []) - 1),
            ];
        }

        return $rows;
    }

    /**
     * @param ?array{owner: ?list<int>, search: ?list<int>} $allowed
     *        null = keine Einschraenkung; Listen enthalten Bewerber-IDs.
     */
    private function matches(array $row, InboxFilter $filter, ?array $allowed): bool
    {
        if ($row['handled'] !== $filter->handled) {
            return false;
        }

        $level = $row['escalation']->level;
        if ($filter->level === 'unread' && !$row['is_unread']) {
            return false;
        }
        if (!in_array($filter->level, ['all', 'unread'], true) && $level !== $filter->level) {
            return false;
        }

        // Zustaendigkeit haengt am Bewerber. Mitarbeiter-Chats und nicht
        // zugeordnete Chats haben keine — sie fallen bei aktivem Owner-Filter
        // heraus (wie in der alten Seite, wo owner dort null ist).
        if ($allowed !== null && $allowed['owner'] !== null) {
            if ($row['context_model_id'] === null
                || !in_array((int) $row['context_model_id'], $allowed['owner'], true)) {
                return false;
            }
        }

        if ($filter->search !== '') {
            $needle = mb_strtolower(trim($filter->search));
            $phoneTrifft = str_contains(mb_strtolower((string) $row['phone']), $needle);
            $nameTrifft = $allowed !== null
                && $allowed['search'] !== null
                && $row['context_model_id'] !== null
                && in_array((int) $row['context_model_id'], $allowed['search'], true);

            if (!$phoneTrifft && !$nameTrifft) {
                return false;
            }
        }

        return true;
    }

    /**
     * Loest Owner- und Namensfilter EINMAL in Bewerber-IDs auf.
     * Gibt null zurueck, wenn keiner der beiden Filter aktiv ist.
     *
     * @return ?array{owner: ?list<int>, search: ?list<int>}
     */
    private function allowedSubjectIds(int $teamId, InboxFilter $filter): ?array
    {
        $ownerAktiv = $filter->owner !== 'all';
        $sucheAktiv = trim($filter->search) !== '';

        if (!$ownerAktiv && !$sucheAktiv) {
            return null;
        }

        $ownerIds = null;
        if ($ownerAktiv) {
            $userId = $filter->owner === 'mine'
                ? (int) $filter->currentUserId
                : (int) $filter->owner;

            $ownerIds = RecApplicant::query()
                ->where('team_id', $teamId)
                ->where('owned_by_user_id', $userId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $searchIds = null;
        if ($sucheAktiv) {
            $needle = mb_strtolower(trim($filter->search));

            $searchIds = RecApplicant::query()
                ->with(['crmContactLinks.contact'])
                ->where('team_id', $teamId)
                ->get()
                ->filter(function ($applicant) use ($needle) {
                    $contact = $applicant->crmContactLinks->first()?->contact;

                    return $contact && str_contains(mb_strtolower((string) $contact->full_name), $needle);
                })
                ->map(fn ($applicant) => (int) $applicant->id)
                ->values()
                ->all();
        }

        return ['owner' => $ownerIds, 'search' => $searchIds];
    }

    /**
     * Namen, Owner und Links — nur fuer die sichtbaren Zeilen.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<InboxRow>
     */
    private function hydrate(array $rows): array
    {
        $applicantMorph = (new RecApplicant)->getMorphClass();
        $applicantIds = [];
        $employeeIds = [];

        foreach ($rows as $row) {
            if ($row['context_model_id'] === null) {
                continue;
            }
            if ($row['context_model'] === RecEmployee::class) {
                $employeeIds[] = $row['context_model_id'];
            } elseif (in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true)) {
                $applicantIds[] = $row['context_model_id'];
            }
        }

        $applicants = RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->whereIn('id', $applicantIds)
            ->get()
            ->keyBy('id');

        $employees = RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'rec_applicant_id'])
            ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $type = 'unassigned';
            $name = null;
            $firstName = null;
            $owner = null;
            $url = null;
            $subjectId = $row['context_model_id'];
            $contextLabel = null;

            if ($row['context_model'] === RecEmployee::class && $subjectId !== null) {
                $type = 'employee';
                $employee = $employees->get($subjectId);
                $name = $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : null;
                $firstName = $employee?->first_name;
                $url = $employee && $employee->rec_applicant_id
                    ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $employee->rec_applicant_id])
                    : ($employee ? $this->safeRoute('recruiting.employees.show', ['employee' => $subjectId]) : null);
            } elseif (in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true) && $subjectId !== null) {
                $type = 'applicant';
                $applicant = $applicants->get($subjectId);
                $contact = $applicant?->crmContactLinks->first()?->contact;
                $name = $contact?->full_name;
                $firstName = $contact?->first_name;
                $owner = $applicant?->owned_by_user_id;
                $url = $applicant ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $subjectId]) : null;
            } elseif ($row['context_model'] !== '' && !self::isBareContact($row['context_model'])) {
                $contextLabel = $row['context_model'];
            }

            $out[] = new InboxRow(
                threadId: $row['thread_id'],
                subjectType: $type,
                subjectId: $type === 'unassigned' ? null : $subjectId,
                url: $url,
                title: $name ?: ((string) $row['phone'] ?: 'Unbekannt'),
                firstName: $firstName,
                preview: $row['preview'],
                phone: $row['phone'],
                ownerUserId: $owner,
                isUnread: $row['is_unread'],
                escalation: $row['escalation'],
                contextLabel: $contextLabel,
                siblingCount: (int) $row['siblings'],
            );
        }

        return $out;
    }

    /**
     * Letzter MENSCHLICHER Ausgang je Thread. Auto-Quittungen (OOO, Voice)
     * zaehlen NICHT — sonst gilt ein Chat als beantwortet, den nie jemand
     * gelesen hat. Kein Rueckfall auf thread.last_outbound_at: die Spalte
     * wird von der Auto-Antwort mitgezogen.
     *
     * @param list<int> $threadIds
     * @return array<int, int>
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
            ->map(fn ($value) => \Carbon\Carbon::parse((string) $value)->getTimestamp())
            ->all();
    }

    /**
     * @param list<int> $threadIds
     * @return array<int, int>
     */
    private function handledTimestamps(int $teamId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return RecConversationHandled::query()
            ->where('team_id', $teamId)
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->get(['comms_whatsapp_thread_id', 'handled_at'])
            ->mapWithKeys(fn ($row) => [
                (int) $row->comms_whatsapp_thread_id => $row->handled_at->getTimestamp(),
            ])
            ->all();
    }

    /** @return list<string> */
    private static function legacyContextModels(): array
    {
        return [(new RecApplicant)->getMorphClass(), RecApplicant::class, RecEmployee::class];
    }

    private static function isBareContact(string $contextModel): bool
    {
        return ThreadContextGate::isBareContactContext($contextModel);
    }

    private static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return substr($digits, -10);
    }

    /**
     * Link bauen, ohne an einem fehlenden Router zu sterben.
     *
     * Der alte ConversationInboxService ruft route() direkt — und ist genau
     * deshalb ohne einen einzigen Integrationstest geblieben (die Capsule-
     * Tests dieses Moduls booten kein Laravel und haben keinen Router). Der
     * Link ist Beiwerk; die Sichtbarkeit einer Zeile darf nicht daran haengen.
     */
    private function safeRoute(string $name, array $params): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

Wichtig an der Reihenfolge: `counts()` ruft `scored()` **ohne** Owner- und
Suchfilter auf. Die Pillen zeigen also immer den vollen Stand — sonst zaehlt
die Ampel die eigene Filterung mit und man sieht nie, wie viel wirklich offen
ist.

- [ ] **Schritt 6: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter InboxQueryCompletenessTest`
Expected: PASS (7 Tests)

- [ ] **Schritt 7: Die Tests der alten Seite gegenpruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "ConversationInbox"`
Expected: PASS — `levelOrder()` darf nichts verschoben haben.

- [ ] **Schritt 8: Committen**

```bash
git add src/Services/Comms/InboxRow.php src/Services/Comms/InboxFilter.php src/Services/Comms/InboxQuery.php src/Services/Comms/ConversationInboxReport.php tests/Integration/InboxQueryCompletenessTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Lesepfad der neuen Kommunikation — Grundmenge ueber den Kanal

Kein Kontext-Filter, kein Zusammenfassen pro Person: Faelle 2474 und #307
koennen hier nicht mehr verschwinden. Namen werden erst fuer die sichtbare
Seite geladen.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Route, Komponente und Liste

**Files:**
- Create: `src/Livewire/Conversations/Inbox.php`
- Create: `resources/views/livewire/conversations/inbox.blade.php`
- Modify: `routes/web.php` (nach Zeile 75 einfuegen)

**Interfaces:**
- Consumes: `InboxQuery`, `InboxFilter`, `RecruitingChannelResolver`
- Produces: die Komponente mit diesen oeffentlichen Eigenschaften — spaetere
  Tasks bauen darauf auf und duerfen sie nicht umbenennen:

```php
public string $level = 'all';        // all|unread|green|yellow|red|missed
public string $owner = 'all';        // all|mine|<userId>
public string $search = '';
public bool $showHandled = false;
public ?int $selectedThreadId = null;
public int $perPage = 50;
public bool $selectMode = false;
public array $selected = [];         // Thread-IDs als Strings
public string $replyText = '';
public ?string $sendError = null;
public bool $showOooPanel = false;
public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];
```

und diesen Computed-Eigenschaften: `counts()`, `rows()`, `total()`,
`teamUsers()`, `channelConfigured()`.

- [ ] **Schritt 1: Route eintragen**

In `routes/web.php` direkt nach Zeile 75:

```php
// Vorschau der neuen Kommunikation (Spec 2026-09-15). Laeuft neben der alten
// Seite; bewusst OHNE Sidebar-Eintrag, Aufruf ueber die URL.
Route::get('/conversations-neu', \Platform\Recruiting\Livewire\Conversations\Inbox::class)
    ->name('recruiting.conversations.preview');
```

Die Zeilen 74/75 bleiben unveraendert.

- [ ] **Schritt 2: Komponente schreiben**

```php
<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Neue Kommunikation (Vorschau unter /recruiting/conversations-neu).
 * Postfach-Layout mit Ampel; die alte Seite laeuft unveraendert weiter.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
class Inbox extends Component
{
    public string $level = 'all';
    public string $owner = 'all';
    public string $search = '';
    public bool $showHandled = false;
    public ?int $selectedThreadId = null;
    public int $perPage = 50;
    public bool $selectMode = false;
    public array $selected = [];
    public string $replyText = '';
    public ?string $sendError = null;
    public bool $showOooPanel = false;
    public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    #[Computed]
    public function channelConfigured(): bool
    {
        return RecruitingChannelResolver::isConfigured($this->teamId());
    }

    #[Computed]
    public function counts(): array
    {
        return app(InboxQuery::class)->counts($this->teamId());
    }

    private function filter(): InboxFilter
    {
        return new InboxFilter(
            level: $this->level,
            owner: $this->owner,
            search: $this->search,
            handled: $this->showHandled,
            currentUserId: (int) Auth::id(),
        );
    }

    #[Computed]
    public function result(): array
    {
        return app(InboxQuery::class)->page($this->teamId(), $this->filter(), $this->perPage, 0);
    }

    #[Computed]
    public function rows(): array
    {
        return $this->result['rows'];
    }

    #[Computed]
    public function total(): int
    {
        return $this->result['total'];
    }

    #[Computed]
    public function teamUsers(): array
    {
        return Auth::user()->currentTeam->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();
    }

    public function setLevel(string $level): void
    {
        // Zweiter Klick auf dieselbe Pille loest den Filter wieder.
        $this->level = ($this->level === $level) ? 'all' : $level;
        $this->showHandled = false;
        $this->resetPage();
    }

    public function toggleHandledView(): void
    {
        $this->showHandled = !$this->showHandled;
        $this->level = 'all';
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
        unset($this->result, $this->rows, $this->total);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->perPage = 50;
        unset($this->result, $this->rows, $this->total, $this->counts);
    }

    /** Laedt einen Thread NUR im Team-Kontext — nie eine fremde ID. */
    protected function threadForTeam(int $threadId): ?CommsWhatsAppThread
    {
        return CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', $this->teamId())
            ->first();
    }

    public function select(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->replyText = '';
        $this->sendError = null;
        $this->threadForTeam($threadId)?->markAsRead();
        $this->resetPage();
        $this->dispatch('sidebar-refresh');
    }

    public function back(): void
    {
        $this->selectedThreadId = null;
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.inbox')
            ->layout('platform::layouts.app');
    }
}
```

- [ ] **Schritt 3: Ansicht anlegen — Grundgeruest und Liste**

`resources/views/livewire/conversations/inbox.blade.php`. Aufbau exakt wie
`resources/views/livewire/dispo/conversations/index.blade.php` (Zeilen 29–130
sind die Vorlage fuer Rahmen, Kopf und Liste): aussen
`<div class="flex h-[calc(100vh-4rem)] flex-col lg:h-[calc(100vh-3rem)]" wire:poll.visible.20s>`,
darin ein `grid ... lg:grid-cols-[360px_1fr]`, links die Liste, rechts der
Chat. Alle Hilfswerte in **einem** `@php … @endphp`-Block oben.

Kopfzeile mit den Pillen:

```blade
@php
    $counts = $this->counts;
    $pills = [
        ['key' => 'unread', 'label' => 'Ungelesen', 'value' => $counts['unread'], 'class' => 'text-orange-600'],
        ['key' => 'yellow', 'label' => 'Gelb',      'value' => $counts['yellow'], 'class' => 'text-amber-600'],
        ['key' => 'red',    'label' => 'Rot',       'value' => $counts['red'],    'class' => 'text-red-600'],
        ['key' => 'missed', 'label' => 'Verpasst',  'value' => $counts['missed'], 'class' => 'text-gray-700'],
    ];
    $levelBar = [
        'missed' => 'bg-gray-800',
        'red'    => 'bg-red-500',
        'yellow' => 'bg-amber-400',
        'green'  => 'bg-emerald-500',
        'none'   => 'bg-gray-200',
    ];
    $fensterText = function ($escalation) {
        if ($escalation->level === 'missed') {
            $stunden = abs($escalation->hoursLeftInWindow);
            return $stunden >= 24
                ? 'verpasst seit ' . floor($stunden / 24) . ' Tg'
                : 'verpasst seit ' . round($stunden) . ' h';
        }
        if (!$escalation->windowOpen) {
            return '';
        }
        $h = $escalation->hoursLeftInWindow;
        return $h >= 1 ? 'noch ' . round($h, 1) . ' h' : 'noch ' . max(1, round($h * 60)) . ' min';
    };
@endphp
```

Die Pillen als Knopfreihe:

```blade
<div class="flex flex-wrap items-center gap-1.5">
    @foreach ($pills as $pill)
        <button type="button" wire:click="setLevel('{{ $pill['key'] }}')"
                class="rounded-full border px-3 py-1 text-xs font-semibold {{ $level === $pill['key'] ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white ' . $pill['class'] }}">
            {{ $pill['label'] }} {{ $pill['value'] }}
        </button>
    @endforeach
    <button type="button" wire:click="toggleHandledView"
            class="rounded-full border px-3 py-1 text-xs font-semibold {{ $showHandled ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-500' }}">
        Erledigt {{ $counts['handled'] }}
    </button>
</div>
```

Eine Listenzeile (Ampel als Kante links, Countdown-Chip unten):

```blade
@foreach ($this->rows as $row)
    @php
        $istGewaehlt = $selectedThreadId === $row->threadId;
        $balken = $levelBar[$row->escalation->level] ?? $levelBar['none'];
        $fenster = $fensterText($row->escalation);
    @endphp
    <button type="button" wire:click="select({{ $row->threadId }})" wire:key="row-{{ $row->threadId }}"
            class="flex w-full items-start gap-3 border-b border-gray-100 border-l-[3px] px-3 py-3 text-left hover:bg-gray-50 {{ $istGewaehlt ? 'border-l-gray-900 bg-gray-50' : 'border-l-transparent' }}">
        <span class="mt-1 inline-block h-8 w-1 shrink-0 rounded {{ $balken }}"></span>
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5 text-sm {{ $row->isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' }}">
                <span class="truncate">{{ $row->title }}</span>
                @if ($row->isUnread)
                    <span class="h-2 w-2 shrink-0 rounded-full bg-orange-500"></span>
                @endif
            </span>
            <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row->preview ?: '—' }}</span>
            <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10.5px] font-semibold">
                @if ($fenster !== '')
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ $fenster }}</span>
                @endif
                @if ($row->subjectType === 'employee')
                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">MA</span>
                @endif
            </span>
        </span>
    </button>
@endforeach
```

Unter der Liste der Nachlade-Knopf:

```blade
@if (count($this->rows) < $this->total)
    <button type="button" wire:click="loadMore" class="w-full border-t border-gray-100 px-3 py-3 text-xs font-semibold text-gray-500 hover:bg-gray-50">
        mehr laden ({{ count($this->rows) }} von {{ $this->total }})
    </button>
@endif
```

Und der Hinweis bei fehlender Konfiguration, direkt ueber der Liste:

```blade
@if (!$this->channelConfigured)
    <div class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        Kein WhatsApp-Konto gewählt — es werden nur zugeordnete Chats angezeigt.
        In Einstellungen → Kommunikation ein Konto wählen.
    </div>
@endif
```

Rechts vorerst nur der Leerzustand (der Chat kommt in Task 6):

```blade
<div class="hidden min-h-0 flex-col bg-gray-50 lg:flex">
    <div class="grid flex-1 place-items-center p-8 text-center text-sm text-gray-500">
        Chat auswählen, um den Verlauf zu sehen.
    </div>
</div>
```

- [ ] **Schritt 4: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`
Expected: keine Meldung. (`php -l` prueft an einer `.blade.php` nichts.)

- [ ] **Schritt 5: Im Browser oeffnen**

`/recruiting/conversations-neu` aufrufen. Erwartung: Pillen mit Zahlen, Liste
mit Ampelkanten, „mehr laden" am Ende, alte Seite unter `/recruiting/conversations`
unveraendert.

- [ ] **Schritt 6: Committen**

```bash
git add routes/web.php src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Vorschau-Route /conversations-neu mit Liste und Ampel-Pillen

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Der Chat — Verlauf, Kopf, Kontextzeile

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`

**Interfaces:**
- Consumes: `DispoThreadDirectory::messages(CommsWhatsAppThread $thread, array $labels): array`
  (wird nur aufgerufen, nicht geaendert), `RecApplicant` mit `phase`,
  `position`, `interviewBookings`
- Produces: Computed `selectedThread()`, `selectedRow()`, `messages()`,
  `contextChips()` — `contextChips()` liefert
  `list<array{label: string, value: string, url: ?string}>`

- [ ] **Schritt 1: Computed-Eigenschaften ergaenzen**

```php
    #[Computed]
    public function selectedThread(): ?CommsWhatsAppThread
    {
        return $this->selectedThreadId === null ? null : $this->threadForTeam($this->selectedThreadId);
    }

    /** Die Listenzeile zum offenen Chat (Titel, Ampel, Owner, Link). */
    #[Computed]
    public function selectedRow(): ?\Platform\Recruiting\Services\Comms\InboxRow
    {
        foreach ($this->rows as $row) {
            if ($row->threadId === $this->selectedThreadId) {
                return $row;
            }
        }

        // Der Chat kann durch einen Filterwechsel aus der Liste gefallen sein —
        // dann einzeln nachladen, statt den Verlauf zu schliessen.
        $thread = $this->selectedThread;
        if ($thread === null) {
            return null;
        }
        $single = app(InboxQuery::class)->page(
            $this->teamId(),
            new InboxFilter(handled: $this->showHandled, search: (string) $thread->remote_phone_number),
            50,
            0,
        );
        foreach ($single['rows'] as $row) {
            if ($row->threadId === $this->selectedThreadId) {
                return $row;
            }
        }

        return null;
    }

    #[Computed]
    public function messages(): array
    {
        $thread = $this->selectedThread;
        if ($thread === null) {
            return [];
        }

        return app(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::class)
            ->messages($thread, []);
    }

    /** @return list<array{label: string, value: string, url: ?string}> */
    #[Computed]
    public function contextChips(): array
    {
        $row = $this->selectedRow;
        if ($row === null || $row->subjectType !== 'applicant' || $row->subjectId === null) {
            return [];
        }

        $applicant = \Platform\Recruiting\Models\RecApplicant::query()
            ->with(['phase', 'position'])
            ->find($row->subjectId);
        if ($applicant === null) {
            return [];
        }

        $chips = [];
        if ($applicant->phase) {
            $chips[] = ['label' => 'Phase', 'value' => (string) $applicant->phase->name, 'url' => null];
        }
        if ($applicant->position) {
            $chips[] = ['label' => 'Stelle', 'value' => (string) $applicant->position->name, 'url' => null];
        }

        $booking = $applicant->interviewBookings()
            ->with('interview')
            ->whereIn('status', ['registered', 'confirmed'])
            ->get()
            ->filter(fn ($b) => $b->interview && $b->interview->start_at >= now())
            ->sortBy(fn ($b) => $b->interview->start_at)
            ->first();

        if ($booking) {
            $chips[] = [
                'label' => 'Termin',
                'value' => $booking->interview->start_at->format('d.m.Y H:i'),
                'url' => null,
            ];
        }

        return $chips;
    }
```

Passt eine Spalte nicht (z.B. heisst das Feld am Termin anders), die echten
Namen mit
`grep -n "start_at\|public function interview" src/Models/RecInterviewBooking.php`
pruefen und hier eintragen — **nicht** raten und auch keinen Platzhalter
stehen lassen.

- [ ] **Schritt 2: Chat-Spalte in die Ansicht bauen**

Vorlage: `dispo/conversations/index.blade.php` Zeilen 152–230. Kopfzeile mit
Zurueck-Pfeil (nur mobil), Titel, Nummer, Countdown, Zuständig, Link zur
Bewerberakte. Darunter die Kontextzeile:

```blade
@if ($this->contextChips !== [])
    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-3 py-2 text-xs lg:px-5">
        @foreach ($this->contextChips as $chip)
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1">
                <span class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">{{ $chip['label'] }}</span>
                <span class="font-semibold text-gray-700">{{ $chip['value'] }}</span>
            </span>
        @endforeach
    </div>
@endif
```

Der Verlauf nutzt das bestehende Partial:

```blade
<div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-3 py-4 lg:px-5"
     wire:key="msgs-{{ $selectedThreadId }}-{{ count($this->messages) }}"
     x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
    @include('recruiting::livewire.dispo._messages', ['messages' => $this->messages, 'portalUrl' => null])
</div>
```

`portalUrl` muss mitgegeben werden — das Partial erwartet die Variable, und
ein fehlender Wert faellt erst beim Klick auf einen Thread auf.

- [ ] **Schritt 3: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`
Expected: keine Meldung.

- [ ] **Schritt 4: Im Browser pruefen**

Einen Chat mit Bildern oder Sprachnachricht oeffnen: Blasen, Tagestrenner,
Medien, Autoscroll ans Ende. Danach die Dispo-Kommunikation
(`/recruiting/dispo/conversations`) oeffnen und einen Thread anklicken —
sie muss unveraendert funktionieren.

- [ ] **Schritt 5: Committen**

```bash
git add src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Verlauf, Kopfzeile und Kontextzeile im neuen Chat

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Freitext-Antwort

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`

**Interfaces:**
- Consumes: `DispoReplySender::send(CommsWhatsAppThread $thread, string $text, mixed $sender): array{ok: bool, error: ?string}`
- Produces: `Inbox::sendReply(): void`, Computed `windowOpen(): bool`

- [ ] **Schritt 1: Senden ergaenzen**

```php
    #[Computed]
    public function windowOpen(): bool
    {
        $row = $this->selectedRow;

        return $row !== null && $row->escalation->windowOpen;
    }

    public function sendReply(): void
    {
        $this->sendError = null;

        $thread = $this->selectedThread;
        if ($thread === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Zas\Dispo\DispoReplySender::class)
            ->send($thread, $this->replyText, Auth::user());

        if (!$result['ok']) {
            // Der geteilte Sender formuliert den Fenster-Fehler fuer die Dispo
            // ("... ueber die Veranstaltung"). Hier gilt eine andere Regel,
            // deshalb wird genau dieser Fall umformuliert.
            $this->sendError = str_contains((string) $result['error'], '24h-Fenster')
                ? 'Das 24-Stunden-Fenster ist zu — bitte eine Vorlage senden.'
                : $result['error'];
            return; // replyText bleibt stehen
        }

        $this->replyText = '';
        unset($this->messages);
        $this->resetPage();
        $this->dispatch('reply-sent');
    }
```

- [ ] **Schritt 2: Antwortleiste in die Ansicht**

Vorlage: `dispo/conversations/index.blade.php` Zeilen 255–285 (das Textfeld
waechst mit, Enter macht eine neue Zeile, gesendet wird nur per Knopf):

```blade
@if ($this->windowOpen)
    <div class="flex items-end gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2 pl-3 focus-within:border-gray-400"
         x-data="{ fit(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 200) + 'px'; } }">
        <textarea wire:model="replyText" rows="1" placeholder="Antwort schreiben …"
                  x-init="fit($el)" x-on:input="fit($el)"
                  x-on:reply-sent.window="$nextTick(() => fit($el))"
                  class="max-h-[200px] min-h-[36px] w-full resize-none overflow-y-auto border-0 bg-transparent p-1.5 text-sm leading-snug text-gray-900 placeholder:text-gray-400 focus:ring-0"></textarea>
        <button type="button" wire:click="sendReply" wire:loading.attr="disabled"
                class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-50" aria-label="Senden">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 12 20 4l-4 16-4-7z"/></svg>
        </button>
    </div>
@endif
@if ($sendError)
    <p class="mt-2 text-sm text-red-600">{{ $sendError }}</p>
@endif
```

- [ ] **Schritt 3: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`
Expected: keine Meldung.

- [ ] **Schritt 4: Echt senden**

Einen Chat mit offenem Fenster waehlen, kurze Nachricht senden. Erwartung:
Blase erscheint rechts, Textfeld leer und wieder einzeilig. Gegenprobe an
einem verpassten Chat: kein Textfeld.

- [ ] **Schritt 5: Committen**

```bash
git add src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Freitext-Antwort im neuen Chat bei offenem 24h-Fenster

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Vorlagen senden — ohne den Formular-Token-Fehler

**Files:**
- Create: `src/Services/Comms/ApplicantTemplateSender.php`
- Test: `tests/Unit/Comms/TemplateTokenDecisionTest.php`
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`

**Interfaces:**
- Produces:
  - `ApplicantTemplateSender::needsFormToken(array $components): bool` —
    **pure**, ohne Laravel, damit im Unit-Test pruefbar
  - `ApplicantTemplateSender::send(CommsWhatsAppThread $thread, int $templateId, ?int $applicantId, mixed $sender): array{ok: bool, error: ?string}`

**Hintergrund:** `Applicant\Show::sendManualTemplate` (Show.php ab Zeile 543)
setzt den Token, sobald das Template **irgendeinen** URL-Knopf hat — auch wenn
dessen URL gar keinen Platzhalter traegt. Das ist der Theo-Wirtz-Fall. Hier
entscheidet der Platzhalter, nicht die Existenz des Knopfes.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;

/**
 * Task 8: Der Formular-Token darf NUR in einen URL-Knopf mit Platzhalter.
 * Die Bewerberakte setzt ihn bei jedem URL-Knopf — dadurch landete der Token
 * im MA-Portal-Link (Fall Theo Wirtz). Dieser Test haelt die Regel fest.
 */
class TemplateTokenDecisionTest extends TestCase
{
    public function test_url_knopf_mit_platzhalter_braucht_den_token(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}']],
        ]];

        $this->assertTrue(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_url_knopf_ohne_platzhalter_bekommt_keinen_token(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL', 'url' => 'https://example.test/portal']],
        ]];

        $this->assertFalse(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_ohne_url_knopf_kein_token(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{1}}'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ja']]],
        ];

        $this->assertFalse(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_leere_komponenten_sind_harmlos(): void
    {
        $this->assertFalse(ApplicantTemplateSender::needsFormToken([]));
    }
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TemplateTokenDecisionTest`
Expected: FAIL — Klasse nicht gefunden.

- [ ] **Schritt 3: Den Sender schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Template-Versand aus der Kommunikation.
 *
 * Unterschied zu Applicant\Show::sendManualTemplate: dort wird der
 * Bewerber-Formular-Token an JEDES Template mit URL-Knopf gehaengt — auch an
 * eines, dessen URL gar keinen Platzhalter hat. Genau so landete der Token im
 * MA-Portal-Link (Fall Theo Wirtz). Hier entscheidet der Platzhalter.
 *
 * Liefert ok/error statt zu werfen (Muster: DispoReplySender).
 */
final class ApplicantTemplateSender
{
    /**
     * Braucht dieses Template den Formular-Token? Pure Entscheidung ohne
     * Laravel — damit unit-testbar.
     *
     * @param array<int, array<string, mixed>> $components Template-Komponenten von Meta
     */
    public static function needsFormToken(array $components): bool
    {
        foreach ($components as $component) {
            if (($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }
            foreach ($component['buttons'] ?? [] as $button) {
                if (($button['type'] ?? '') !== 'URL') {
                    continue;
                }
                if (str_contains((string) ($button['url'] ?? ''), '{{')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{ok: bool, error: ?string} */
    public function send(
        CommsWhatsAppThread $thread,
        int $templateId,
        ?int $applicantId,
        mixed $sender,
    ): array {
        $template = IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'error' => 'Vorlage nicht gefunden oder nicht genehmigt.'];
        }

        $channel = CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Kanal des Chats nicht gefunden.'];
        }

        $components = [];
        if (self::needsFormToken((array) ($template->components ?? []))) {
            $applicant = $applicantId ? RecApplicant::find($applicantId) : null;
            if ($applicant === null) {
                return ['ok' => false, 'error' => 'Diese Vorlage enthält einen Bewerber-Link — der Chat ist aber keinem Bewerber zugeordnet.'];
            }
            $publicUrl = $applicant->getPublicUrl();
            $formToken = basename(parse_url($publicUrl, PHP_URL_PATH));

            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => 0,
                'parameters' => [['type' => 'text', 'text' => $formToken]],
            ];
        }

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $channel,
                to: (string) $thread->remote_phone_number,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
                sender: $sender,
            );

            if (($message->status ?? null) === 'failed') {
                return ['ok' => false, 'error' => 'Meta hat den Versand abgelehnt: '
                    . (string) ($message->meta_payload['error']['message'] ?? 'unbekannter Grund')];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Senden fehlgeschlagen: ' . $e->getMessage()];
        }
    }
}
```

Hat das Template Platzhalter im Textkoerper, verlangt Meta zusaetzlich
Body-Parameter. Dieser Sender fuellt sie nicht — die Knopfleiste in Schritt 4
zeigt deshalb nur Vorlagen **ohne** Body-Platzhalter plus das
Eingangsbestaetigungs-Template, das ueber `HoldingTemplateSender` laeuft und
seine Anrede selbst setzt.

- [ ] **Schritt 4: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TemplateTokenDecisionTest`
Expected: PASS (4 Tests)

- [ ] **Schritt 5: Knopfleiste in Komponente und Ansicht**

In `Inbox.php`:

```php
    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function chatTemplates(): array
    {
        $accountId = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting('auto_pilot_wa_account_id');

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED');
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderBy('name')->get()
            ->filter(function ($template) {
                // Nur Vorlagen ohne Body-Platzhalter — fuer die anderen fehlen
                // hier die Parameter, und ein leerer Parameter ist schlimmer
                // als ein fehlender Knopf.
                foreach ((array) ($template->components ?? []) as $component) {
                    if (($component['type'] ?? '') === 'BODY'
                        && str_contains((string) ($component['text'] ?? ''), '{{')) {
                        return false;
                    }
                }
                return true;
            })
            ->map(fn ($template) => ['id' => (int) $template->id, 'label' => (string) $template->name])
            ->values()
            ->all();
    }

    public function sendTemplate(int $templateId): void
    {
        $this->sendError = null;
        $thread = $this->selectedThread;
        $row = $this->selectedRow;
        if ($thread === null || $row === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Comms\ApplicantTemplateSender::class)->send(
            $thread,
            $templateId,
            $row->subjectType === 'applicant' ? $row->subjectId : null,
            Auth::user(),
        );

        if (!$result['ok']) {
            $this->sendError = $result['error'];
            return;
        }

        unset($this->messages);
        $this->resetPage();
    }

    public function sendHoldingTemplate(): void
    {
        $this->sendError = null;
        $row = $this->selectedRow;
        if ($row === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)->sendToMany(
            $this->teamId(),
            [['phone' => $row->phone, 'first_name' => $row->firstName]],
        );

        if ($result['error'] !== null) {
            $this->sendError = $result['error'];
            return;
        }

        unset($this->messages);
        $this->resetPage();
    }
```

In der Ansicht, im `@else`-Zweig zu `$this->windowOpen`:

```blade
<div class="mb-2 flex flex-wrap gap-2">
    <button type="button" wire:click="sendHoldingTemplate" wire:loading.attr="disabled"
            class="rounded-lg border border-gray-900 bg-gray-900 px-3 py-1.5 text-[13px] font-semibold text-white hover:bg-gray-800 disabled:opacity-60">
        „Wir melden uns"
    </button>
    @foreach ($this->chatTemplates as $template)
        <button type="button" wire:click="sendTemplate({{ $template['id'] }})" wire:loading.attr="disabled"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[13px] font-semibold text-gray-600 hover:border-gray-400 disabled:opacity-60">
            {{ $template['label'] }}
        </button>
    @endforeach
</div>
<div class="rounded-xl bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
    Das 24-Stunden-Fenster ist zu — freier Text geht erst wieder, wenn die Person schreibt.
</div>
```

- [ ] **Schritt 6: Blade pruefen und echt senden**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`

Dann an einem verpassten Chat „Wir melden uns" senden und pruefen, dass die
Nachricht ankommt. Enthaelt eine der Vorlagen einen URL-Knopf mit Link zum
Bewerberformular: senden und die URL in WhatsApp pruefen — sie muss den Token
tragen. Eine Vorlage mit statischem URL-Knopf (z.B. Portal) muss **ohne**
angehaengten Token ankommen.

- [ ] **Schritt 7: Committen**

```bash
git add src/Services/Comms/ApplicantTemplateSender.php tests/Unit/Comms/TemplateTokenDecisionTest.php src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Vorlagen im Chat — Formular-Token nur bei Platzhalter im URL-Knopf

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Erledigt — einzeln, gesammelt, als Filter

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`
- Test: `tests/Integration/InboxQueryCompletenessTest.php` (bereits abgedeckt)

**Interfaces:**
- Consumes: `RecConversationHandled`
- Produces: `Inbox::markHandled(int $threadId)`, `Inbox::unmarkHandled(int $threadId)`,
  `Inbox::markSelectedHandled()`, `Inbox::toggleSelectMode()`,
  `Inbox::selectAllVisible()`, `Inbox::clearSelection()`,
  `Inbox::sendHoldingToSelected()`

- [ ] **Schritt 1: Methoden ergaenzen**

```php
    public function markHandled(int $threadId): void
    {
        $thread = $this->threadForTeam($threadId);
        if ($thread === null) {
            return;
        }

        \Platform\Recruiting\Models\RecConversationHandled::updateOrCreate(
            ['comms_whatsapp_thread_id' => $threadId],
            [
                'team_id' => $this->teamId(),
                'handled_at' => now(),
                'handled_by_user_id' => (int) Auth::id(),
                'handled_reason' => \Platform\Recruiting\Models\RecConversationHandled::REASON_MANUAL,
            ],
        );

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }
        $this->resetPage();
        $this->dispatch('sidebar-refresh');
    }

    public function unmarkHandled(int $threadId): void
    {
        \Platform\Recruiting\Models\RecConversationHandled::query()
            ->where('team_id', $this->teamId())
            ->where('comms_whatsapp_thread_id', $threadId)
            ->delete();

        $this->resetPage();
        $this->dispatch('sidebar-refresh');
    }

    public function toggleSelectMode(): void
    {
        $this->selectMode = !$this->selectMode;
        $this->selected = [];
    }

    public function selectAllVisible(): void
    {
        $this->selected = array_map(fn ($row) => (string) $row->threadId, $this->rows);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function markSelectedHandled(): void
    {
        foreach ($this->selected as $threadId) {
            $this->markHandled((int) $threadId);
        }
        $this->selected = [];
        $this->selectMode = false;
    }

    public function sendHoldingToSelected(): void
    {
        $ids = array_map('intval', $this->selected);
        if ($ids === []) {
            session()->flash('error', 'Bitte zuerst Chats markieren.');
            return;
        }

        $recipients = [];
        foreach ($this->rows as $row) {
            if (in_array($row->threadId, $ids, true)) {
                $recipients[] = ['phone' => $row->phone, 'first_name' => $row->firstName];
            }
        }

        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
            ->sendToMany($this->teamId(), $recipients);

        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);
        } else {
            session()->flash('message', '„Wir melden uns" an ' . $result['sent'] . ' Kontakt(e) gesendet.');
        }

        $this->selected = [];
        $this->selectMode = false;
        $this->resetPage();
    }
```

- [ ] **Schritt 2: Bedienung in die Ansicht**

Im Chat-Kopf:

```blade
@if ($showHandled)
    <button type="button" wire:click="unmarkHandled({{ $selectedThreadId }})"
            class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
        zurückholen
    </button>
@else
    <button type="button" wire:click="markHandled({{ $selectedThreadId }})"
            class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
        Erledigt
    </button>
@endif
```

Im Kopf der Liste der Haken-Knopf (`wire:click="toggleSelectMode"`), in der
Listenzeile bei `$selectMode` ein Kaestchen
(`<input type="checkbox" wire:model.live="selected" value="{{ $row->threadId }}">` —
als eigenes Element **neben** dem Zeilen-Knopf, nicht darin, sonst
verschluckt der Knopf den Klick), und unter der Liste die Aktionsleiste:

```blade
@if ($selectMode)
    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 bg-white px-3 py-2 text-xs">
        <span class="font-semibold text-gray-700">{{ count($selected) }} markiert</span>
        <button type="button" wire:click="selectAllVisible" class="text-gray-500 hover:underline">alle sichtbaren</button>
        <button type="button" wire:click="clearSelection" class="text-gray-500 hover:underline">Auswahl löschen</button>
        <button type="button" wire:click="markSelectedHandled" class="ml-auto rounded-lg border border-gray-200 px-2.5 py-1 font-semibold text-gray-700 hover:bg-gray-50">als erledigt</button>
        <button type="button" wire:click="sendHoldingToSelected" class="rounded-lg bg-gray-900 px-2.5 py-1 font-semibold text-white hover:bg-gray-800">„Wir melden uns"</button>
    </div>
@endif
```

- [ ] **Schritt 3: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`

- [ ] **Schritt 4: Regressionstest laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter InboxQueryCompletenessTest`
Expected: PASS (7 Tests) — Erledigt-Ausschluss und Wiederauftauchen sind dort
bereits festgehalten.

- [ ] **Schritt 5: Im Browser pruefen**

Chat abhaken → verschwindet aus der Liste, Zaehler „Erledigt" steigt. Pille
„Erledigt" → Chat ist da, „zurückholen" holt ihn zurueck.

- [ ] **Schritt 6: Committen**

```bash
git add src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Erledigt-Zustand — einzeln, gesammelt und als Filter

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Nicht zugeordnete Chats sichtbar machen und zuordnen

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`

**Interfaces:**
- Consumes: `ApplicantThreadLinker::link(CommsWhatsAppThread $thread, int $applicantId, string $source): void`
- Produces: `Inbox::$linkSearch`, `Inbox::$linkingThreadId`,
  `Inbox::openLinkPanel(int $threadId)`, `Inbox::linkCandidates()`,
  `Inbox::linkToApplicant(int $applicantId)`

- [ ] **Schritt 1: Zuordnen ergaenzen**

```php
    public ?int $linkingThreadId = null;
    public string $linkSearch = '';

    public function openLinkPanel(int $threadId): void
    {
        $this->linkingThreadId = $threadId;
        $this->linkSearch = '';
    }

    public function closeLinkPanel(): void
    {
        $this->linkingThreadId = null;
        $this->linkSearch = '';
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function linkCandidates(): array
    {
        $needle = trim($this->linkSearch);
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return \Platform\Recruiting\Models\RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->where('team_id', $this->teamId())
            ->get()
            ->map(function ($applicant) {
                $contact = $applicant->crmContactLinks->first()?->contact;

                return [
                    'id' => (int) $applicant->id,
                    'label' => trim(($contact?->full_name ?: 'Bewerber') . ' #' . $applicant->id),
                ];
            })
            ->filter(fn ($row) => str_contains(mb_strtolower($row['label']), mb_strtolower($needle)))
            ->take(10)
            ->values()
            ->all();
    }

    public function linkToApplicant(int $applicantId): void
    {
        $thread = $this->linkingThreadId ? $this->threadForTeam($this->linkingThreadId) : null;
        if ($thread === null) {
            $this->closeLinkPanel();
            return;
        }

        // EIN Mechanismus fuers Verknuepfen — er befoerdert den Bewerber auch
        // dann, wenn der Thread noch am blossen CrmContact haengt. Die Logik
        // hier zu wiederholen ist exakt die Bugklasse aus Fall 2474.
        \Platform\Recruiting\Services\Comms\ApplicantThreadLinker::link($thread, $applicantId, 'inbox_manual');

        $this->closeLinkPanel();
        $this->resetPage();
        session()->flash('message', 'Chat dem Bewerber zugeordnet.');
    }
```

- [ ] **Schritt 2: Chip und Panel in die Ansicht**

In der Listenzeile, bei den Chips:

```blade
@if ($row->subjectType === 'unassigned')
    <span class="rounded bg-red-50 px-1.5 py-0.5 text-red-700">nicht zugeordnet</span>
@endif
@if ($row->contextLabel)
    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500">{{ $row->contextLabel }}</span>
@endif
@if ($row->siblingCount > 0)
    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500">+{{ $row->siblingCount }} weiterer Chat</span>
@endif
```

Im Chat-Kopf, wenn nicht zugeordnet:

```blade
@if ($this->selectedRow && $this->selectedRow->subjectType === 'unassigned')
    <button type="button" wire:click="openLinkPanel({{ $selectedThreadId }})"
            class="shrink-0 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
        Bewerber zuordnen…
    </button>
@endif
@if ($linkingThreadId === $selectedThreadId && $linkingThreadId !== null)
    <div class="absolute right-4 top-16 z-10 w-80 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
        <input type="search" wire:model.live.debounce.300ms="linkSearch" placeholder="Name oder Bewerber-Nummer"
               class="w-full rounded-lg border-gray-200 text-sm">
        <div class="mt-2 max-h-64 overflow-y-auto">
            @forelse ($this->linkCandidates as $candidate)
                <button type="button" wire:click="linkToApplicant({{ $candidate['id'] }})"
                        class="block w-full rounded px-2 py-1.5 text-left text-sm hover:bg-gray-50">{{ $candidate['label'] }}</button>
            @empty
                <p class="px-2 py-1.5 text-xs text-gray-400">Mindestens zwei Zeichen eingeben.</p>
            @endforelse
        </div>
        <button type="button" wire:click="closeLinkPanel" class="mt-2 text-xs text-gray-500 hover:underline">schließen</button>
    </div>
@endif
```

Der umgebende Chat-Kopf braucht dafuer `relative` in seinen Klassen.

- [ ] **Schritt 3: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`

- [ ] **Schritt 4: Im Browser pruefen**

Einen Chat mit rotem Chip „nicht zugeordnet" oeffnen, einem Bewerber zuordnen.
Danach **die Seite neu laden**: der Chat muss weiterhin sichtbar sein und jetzt
den Bewerbernamen tragen. Verschwindet er, hat die Befoerderung der
Legacy-Spalten nicht gegriffen — dann `ApplicantThreadLinker` pruefen, nicht
hier nachbessern.

- [ ] **Schritt 5: Committen**

```bash
git add src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): nicht zugeordnete Chats sichtbar und zuordenbar

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Abwesenheitsmodus als Panel

**Files:**
- Modify: `src/Livewire/Conversations/Inbox.php`
- Modify: `resources/views/livewire/conversations/inbox.blade.php`

**Interfaces:**
- Consumes: `OooMode::state()`, `TeamClock::today()`, `RecApplicantSettings`,
  `HoldingTemplateSender::configuredTemplateName($teamId, OooAutoReplyHandler::SETTINGS_KEY)`
- Produces: `oooState()`, `oooView()`, `openOooForm()`, `activateOoo()`,
  `deactivateOoo()` — **identische Regeln** wie in
  `src/Livewire/Conversations/Index.php:209-311`

- [ ] **Schritt 1: Die Methoden uebernehmen**

Die Methoden aus `Conversations/Index.php` — `oooSettings()` (Zeile 209),
`teamToday()`, `oooState()` (222), `oooView()` (236), `openOooForm()` (249),
`activateOoo()` (267), `deactivateOoo()` (304) — wortgleich in `Inbox.php`
uebernehmen — inklusive der drei Pruefungen: Template konfiguriert,
`von <= bis < wieder da`, Wieder-da in der Zukunft. Der Zustand kommt **immer**
aus `OooMode::state()`, nie aus dem rohen Flag.

Zusaetzlich das `updated()`-Verhalten (Bis-Datum setzt „wieder da" auf bis+1):

```php
    public function updated($property): void
    {
        if ($property === 'oooForm.until' && $this->oooForm['until'] !== '' && $this->oooForm['back_at'] === '') {
            $this->oooForm['back_at'] = \Carbon\Carbon::parse($this->oooForm['until'])->addDay()->format('Y-m-d');
        }
        if ($property === 'search' || $property === 'owner') {
            $this->resetPage();
        }
    }
```

Achtung: `updatedSearch()`/`updatedOwner()` aus Task 5 entfallen damit —
Livewire ruft sonst beides.

- [ ] **Schritt 2: Mond-Knopf und Panel**

```blade
@php
    $oooState = $this->oooState;
    $mondKlasse = match ($oooState) {
        'active' => 'border-amber-300 bg-amber-50 text-amber-700',
        'pending' => 'border-sky-200 bg-sky-50 text-sky-700',
        default => 'border-gray-200 bg-white text-gray-500',
    };
@endphp
<button type="button" wire:click="$toggle('showOooPanel')" title="Abwesenheitsmodus"
        class="grid h-8 w-8 place-items-center rounded-lg border {{ $mondKlasse }}">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg>
</button>
```

Das aufklappbare Panel uebernimmt Text und Formular aus
`conversations/index.blade.php:41-100` — Zustandstexte unveraendert, nur in
ein Panel statt in ein Vollbreiten-Banner.

- [ ] **Schritt 3: Blade pruefen und ausprobieren**

Run: `php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php`

Im Browser: Panel oeffnen, Zeitraum setzen, aktivieren, Mond wird gelb,
deaktivieren. Danach die **alte** Seite oeffnen — sie muss denselben Zustand
zeigen (beide lesen dieselben Einstellungen).

- [ ] **Schritt 4: Committen**

```bash
git add src/Livewire/Conversations/Inbox.php resources/views/livewire/conversations/inbox.blade.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Abwesenheitsmodus als Panel in der neuen Kommunikation

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Backfill-Kommando fuer die Altlast

**Files:**
- Create: `src/Console/Commands/ArchiveOldConversations.php`
- Test: `tests/Integration/ArchiveOldConversationsCommandTest.php`
- Modify: die Stelle, an der das Modul seine Kommandos registriert
  (`grep -rn "Commands\\\\" src/*ServiceProvider*.php` zeigt sie)

**Interfaces:**
- Signatur:
  `recruiting:conversations-archivieren {--team= : Team-ID} {--older-than=30 : Tage seit dem letzten Eingang} {--dry-run}`
- Produces: `ArchiveOldConversations::planFor(int $teamId, int $days, ?int $now = null): array` —
  `list<int>` der betroffenen Thread-IDs. Getrennt vom Schreiben, damit
  `--dry-run` **exakt** dieselbe Menge meldet, die ohne das Flag geschrieben wird.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

Aufbau wie `InboxQueryCompletenessTest` (Capsule, dieselben Migrationen). Kern:

```php
    public function test_dry_run_schreibt_nichts_und_meldet_dieselbe_menge(): void
    {
        $command = new \Platform\Recruiting\Console\Commands\ArchiveOldConversations();

        $plan = $command->planFor(self::TEAM, 30, self::JETZT);

        $this->assertContains(self::$threadAlt, $plan);
        $this->assertNotContains(self::$threadFrisch, $plan);
        $this->assertSame(0, \Platform\Recruiting\Models\RecConversationHandled::count());
    }

    public function test_stempel_traegt_backfill_als_grund(): void
    {
        $command = new \Platform\Recruiting\Console\Commands\ArchiveOldConversations();
        $command->stamp(self::TEAM, $command->planFor(self::TEAM, 30, self::JETZT));

        $row = \Platform\Recruiting\Models\RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadAlt)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('backfill', $row->handled_reason);
        $this->assertNull($row->handled_by_user_id);
    }
```

Dazu zwei Threads im Fixture: `$threadAlt` mit `last_inbound_at` = jetzt minus
100 Tage, `$threadFrisch` mit jetzt minus 2 Tage.

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ArchiveOldConversationsCommandTest`
Expected: FAIL — Klasse nicht gefunden.

- [ ] **Schritt 3: Kommando schreiben**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Einmal-Aufraeumen der Altlast: stempelt Chats, deren letzter Eingang laenger
 * als N Tage zurueckliegt, als erledigt (Grund 'backfill').
 *
 * Nichts wird geloescht, und der Lauf ist vollstaendig rueckholbar:
 *   DELETE FROM rec_conversation_handled WHERE handled_reason = 'backfill';
 *
 * Schreibt die Person danach erneut, taucht der Chat ohnehin von selbst wieder
 * auf (ConversationHandledState).
 */
class ArchiveOldConversations extends Command
{
    protected $signature = 'recruiting:conversations-archivieren
        {--team= : Team-ID}
        {--older-than=30 : Tage seit dem letzten Eingang}
        {--dry-run : nur zeigen, nichts schreiben}';

    protected $description = 'Hakt alte, unbeantwortete WhatsApp-Konversationen als erledigt ab';

    public function handle(): int
    {
        $teamId = (int) ($this->option('team') ?: config('recruiting.zas.inbound_team_id'));
        if ($teamId <= 0) {
            $this->error('Kein Team angegeben (--team=).');

            return self::FAILURE;
        }

        $days = (int) $this->option('older-than');
        $plan = $this->planFor($teamId, $days);

        $this->info(count($plan) . ' Chats ohne Eingang seit mehr als ' . $days . ' Tagen.');

        if ($this->option('dry-run')) {
            $this->line('Probelauf — nichts geschrieben.');

            return self::SUCCESS;
        }

        $this->stamp($teamId, $plan);
        $this->info(count($plan) . ' als erledigt gestempelt (Grund: backfill).');

        return self::SUCCESS;
    }

    /** @return list<int> */
    public function planFor(int $teamId, int $days, ?int $now = null): array
    {
        $now ??= time();
        $grenze = date('Y-m-d H:i:s', $now - $days * 86_400);

        $query = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<', $grenze);

        $channelIds = RecruitingChannelResolver::channelIds($teamId);
        if ($channelIds !== []) {
            $query->whereIn('comms_channel_id', $channelIds);
        }

        $schonGestempelt = RecConversationHandled::query()
            ->where('team_id', $teamId)
            ->pluck('comms_whatsapp_thread_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $query->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $schonGestempelt, true))
            ->values()
            ->all();
    }

    /** @param list<int> $threadIds */
    public function stamp(int $teamId, array $threadIds): void
    {
        $jetzt = now();

        foreach (array_chunk($threadIds, 200) as $chunk) {
            $rows = array_map(fn (int $id) => [
                'team_id' => $teamId,
                'comms_whatsapp_thread_id' => $id,
                'handled_at' => $jetzt,
                'handled_by_user_id' => null,
                'handled_reason' => RecConversationHandled::REASON_BACKFILL,
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ], $chunk);

            RecConversationHandled::insert($rows);
        }
    }
}
```

- [ ] **Schritt 4: Kommando registrieren**

Die Registrierungsstelle finden und das Kommando dort eintragen:

```bash
grep -rn "Console\\\\Commands" src/*ServiceProvider*.php | head
```

- [ ] **Schritt 5: Test laufen lassen, gruen bestaetigen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ArchiveOldConversationsCommandTest`
Expected: PASS (2 Tests)

- [ ] **Schritt 6: Committen**

```bash
git add src/Console/Commands/ArchiveOldConversations.php tests/Integration/ArchiveOldConversationsCommandTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Kommando zum Abhaken alter Konversationen (mit Probelauf)

Laeuft NICHT automatisch — erst auf ausdrueckliche Ansage und zuerst mit
--dry-run.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: Vertrag des geteilten Partials und Gesamtlauf

**Files:**
- Create: `tests/Unit/MessagesPartialIncludeContractTest.php`

**Interfaces:**
- Consumes: nichts Neues

Das Sprechblasen-Partial wird ab Task 6 von **zwei** Ansichten eingebunden.
Sein Vertrag sind zwei **Variablen** (`$messages`, `$portalUrl`), nicht
`$this->`-Aufrufe — der vorhandene `tests/Unit/SharedPartialContractTest.php`
prueft ausschliesslich `$this->`-Aufrufe und wuerde hier mit
„Keine \$this->-Aufrufe gefunden" fehlschlagen. Deshalb ein eigener,
schmaler Test statt eines erzwungenen Eintrags dort.

- [ ] **Schritt 1: Den Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Das Sprechblasen-Partial (dispo/_messages) erwartet ZWEI Variablen:
 * $messages und $portalUrl. Fehlt eine an einer Einbindung, faellt das
 * nicht beim Rendern der Seite auf, sondern erst beim Klick auf einen Chat.
 *
 * Seit der neuen Kommunikation (15.09.2026) binden zwei Ansichten es ein.
 * Dieser Test haelt fest, dass jede von ihnen beides mitgibt.
 */
class MessagesPartialIncludeContractTest extends TestCase
{
    private const PARTIAL = 'recruiting::livewire.dispo._messages';

    private const ANSICHTEN = [
        'resources/views/livewire/dispo/conversations/index.blade.php',
        'resources/views/livewire/conversations/inbox.blade.php',
    ];

    /** @dataProvider ansichten */
    public function test_einbindung_gibt_messages_und_portalurl_mit(string $relativerPfad): void
    {
        $pfad = dirname(__DIR__, 2) . '/' . $relativerPfad;
        $this->assertFileExists($pfad);

        $inhalt = (string) file_get_contents($pfad);

        $treffer = [];
        preg_match_all(
            '/@include\(\s*[\'"]' . preg_quote(self::PARTIAL, '/') . '[\'"]\s*,(.*?)\)\s*$/ms',
            $inhalt,
            $treffer,
        );

        $this->assertNotEmpty(
            $treffer[1],
            "{$relativerPfad} bindet {$this->partialName()} nicht (mehr) ein — Pfad geaendert?",
        );

        foreach ($treffer[1] as $argumente) {
            $this->assertStringContainsString("'messages'", $argumente,
                "{$relativerPfad} gibt \$messages nicht mit — der Verlauf bleibt leer.");
            $this->assertStringContainsString("'portalUrl'", $argumente,
                "{$relativerPfad} gibt \$portalUrl nicht mit — das bricht beim Klick auf einen Chat.");
        }
    }

    public static function ansichten(): array
    {
        $faelle = [];
        foreach (self::ANSICHTEN as $ansicht) {
            $faelle[$ansicht] = [$ansicht];
        }

        return $faelle;
    }

    private function partialName(): string
    {
        return self::PARTIAL;
    }
}
```

- [ ] **Schritt 2: Test laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter MessagesPartialIncludeContractTest`
Expected: PASS (2 Faelle)

Schlaegt der Regex fehl, weil die Einbindung ueber mehrere Zeilen laeuft:
`@include`-Aufruf in `inbox.blade.php` einzeilig schreiben — nicht den Test
aufweichen.

- [ ] **Schritt 3: Blade-Kompilat gegenpruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter BladeCompileIntegrityTest`
Expected: PASS — dieser Test faengt Risse, die erst beim Kompilieren
entstehen (ein Blade-Kommentar mit dem Wort einer Block-Direktive hat am
13.08. die halbe Bewerberliste verschluckt).

- [ ] **Schritt 4: Gesamtlauf**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS fuer beide Suiten. Kein `--order-by=random`.

Ist etwas rot, das nichts mit diesem Paket zu tun hat: erst auf `main`
gegenpruefen, ob es dort auch rot ist, bevor daran gearbeitet wird.

- [ ] **Schritt 5: Blade aller geaenderten Ansichten pruefen**

```bash
php tools/blade-check.php resources/views/livewire/conversations/inbox.blade.php
```

- [ ] **Schritt 6: Committen**

```bash
git add tests/Unit/MessagesPartialIncludeContractTest.php
git commit -m "$(cat <<'EOF'
test(recruiting): Vertrag des Sprechblasen-Partials fuer beide Ansichten

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Nach dem Bau — was NICHT automatisch passiert

- **Kein Merge nach main ohne Freigabe.** Der Branch wird gepusht und
  vorgefuehrt; gemergt wird per Fast-Forward, wenn der Kunde die Vorschau
  abgenommen hat.
- **Der Bump von meingedeck** gehoert zum Deploy, nicht zum Bau.
- **`php artisan migrate`** ist nach dem Deploy noetig (eine neue Tabelle).
- **Das Backfill-Kommando laeuft nicht von selbst** — erst auf Ansage, und
  zuerst mit `--dry-run`.
- **Das Umschwenken auf `/recruiting/conversations`** ist ein eigener Auftrag;
  die Schritte stehen in der Spec unter „Umschwenken".
