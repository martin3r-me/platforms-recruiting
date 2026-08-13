# Ausschreibungs-Matching-Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eingehende Bewerbungen werden automatisch der passenden Ausschreibung zugeordnet (deterministisch → LLM → Kanal-Default → Inbox) statt pauschal an alle Postings des Eingangskanals gehängt.

**Architecture:** Neue Tabellen `rec_intake_channels` (Relevanz-Filter + Default-Posting) und `rec_posting_external_refs` (Portal-Referenzen). `IncomingApplicationService` wird auf eine Stufen-Pipeline umgebaut: Stufe 1 deterministisch inline (dedizierter Kanal, Referenz-Parser), Stufe 2–4 asynchron im `MatchApplicantToPostingJob` (LLM via `OpenAiService::chat`, Kanal-Default, Inbox-Vorschlag). Enrichment startet erst nach Zuordnung (`enrichment_status` wird beim Assign auf `null` gesetzt, der bestehende Scheduler greift dann).

**Tech Stack:** Laravel (Modul `platforms-recruiting`), Livewire, PHPUnit (neu, minimal ohne Testbench), `OpenAiService` aus platforms-core.

**Spec:** `docs/superpowers/specs/2026-06-11-stellen-ausschreibungen-matching-design.md`

**Bewusste Scope-Entscheidungen:**
- Referenz-Parser starten mit `kleinanzeigen` und `website_form` (für Kleinanzeigen existiert eine reale Beispiel-Mail). Indeed/StepStone-Parser werden nachgerüstet, sobald reale Benachrichtigungs-Mails als Muster vorliegen — die Registry macht das zu je einer Klasse + Test.
- Unit-Tests (TDD) nur für reine Logik (Parser). DB-/Livewire-Teile werden manuell verifiziert (Modul hat keine Testbench-Infrastruktur; bewusste Entscheidung mit dem User).
- Alle Pfade sind absolut. Modul-Root: `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting` (im Folgenden `<MODUL>`). PHPUnit-Binary der Host-App: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`.

---

### Task 1: Minimale Test-Infrastruktur

**Files:**
- Create: `<MODUL>/phpunit.xml`
- Create: `<MODUL>/tests/bootstrap.php`
- Create: `<MODUL>/tests/Unit/SmokeTest.php`

- [ ] **Step 1: phpunit.xml anlegen**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: tests/bootstrap.php anlegen** (eigener Autoloader — kein Composer-vendor im Modul; Tests decken nur reine PHP-Klassen ohne Laravel-Abhängigkeiten ab)

```php
<?php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Platform\\Recruiting\\Tests\\' => __DIR__ . '/',
        'Platform\\Recruiting\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
            }
            return;
        }
    }
});
```

- [ ] **Step 3: Smoke-Test anlegen**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function test_phpunit_runs(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml tests/
git commit -m "test: minimale PHPUnit-Infrastruktur fuer reine Unit-Tests"
```

---

### Task 2: Migrationen + neue Models

**Files:**
- Create: `<MODUL>/database/migrations/2026_06_12_000001_create_rec_intake_channels_table.php`
- Create: `<MODUL>/database/migrations/2026_06_12_000002_create_rec_posting_external_refs_table.php`
- Create: `<MODUL>/database/migrations/2026_06_12_000003_add_matching_columns.php`
- Create: `<MODUL>/src/Models/RecIntakeChannel.php`
- Create: `<MODUL>/src/Models/RecPostingExternalRef.php`
- Modify: `<MODUL>/src/Models/RecPosting.php` (Relationship `externalRefs()`)
- Modify: `<MODUL>/src/Models/RecApplicant.php` (fillable + Relationship `suggestedPosting()`)
- Modify: `<MODUL>/src/Models/RecApplicantPosting.php` (fillable)
- Modify: `<MODUL>/src/Models/RecSourcePlatform.php` (fillable `ref_parser`)

- [ ] **Step 1: Migration rec_intake_channels**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_intake_channels', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('comms_channel_id')->constrained('comms_channels')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('default_posting_id')->nullable()->constrained('rec_postings')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['comms_channel_id', 'team_id']);
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_intake_channels');
    }
};
```

- [ ] **Step 2: Migration rec_posting_external_refs**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_posting_external_refs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->foreignId('rec_source_platform_id')->constrained('rec_source_platforms')->cascadeOnDelete();
            $table->string('external_ref');
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rec_source_platform_id', 'external_ref']);
            $table->index('rec_posting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_posting_external_refs');
    }
};
```

- [ ] **Step 3: Migration Matching-Spalten** (Pivot-Audit, Inbox-Vorschlag, Parser-Auswahl)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicant_posting', function (Blueprint $table) {
            $table->string('matched_via', 30)->nullable()->after('notes');
            $table->string('match_confidence', 10)->nullable()->after('matched_via');
        });

        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('suggested_posting_id')->nullable()->constrained('rec_postings')->nullOnDelete();
            $table->text('match_reason')->nullable();
        });

        Schema::table('rec_source_platforms', function (Blueprint $table) {
            $table->string('ref_parser', 40)->nullable()->after('match_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicant_posting', function (Blueprint $table) {
            $table->dropColumn(['matched_via', 'match_confidence']);
        });
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_posting_id');
            $table->dropColumn('match_reason');
        });
        Schema::table('rec_source_platforms', function (Blueprint $table) {
            $table->dropColumn('ref_parser');
        });
    }
};
```

- [ ] **Step 4: Model RecIntakeChannel** (UUID-Muster wie `RecInterviewWaitlist`: `Symfony\Component\Uid\UuidV7` im `booted()`-Hook)

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecIntakeChannel extends Model
{
    protected $table = 'rec_intake_channels';

    protected $fillable = [
        'uuid', 'comms_channel_id', 'team_id', 'default_posting_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsChannel::class, 'comms_channel_id');
    }

    public function defaultPosting(): BelongsTo
    {
        return $this->belongsTo(RecPosting::class, 'default_posting_id');
    }

    public static function isIntake(int $commsChannelId): bool
    {
        return static::where('comms_channel_id', $commsChannelId)->where('is_active', true)->exists();
    }
}
```

- [ ] **Step 5: Model RecPostingExternalRef**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecPostingExternalRef extends Model
{
    protected $table = 'rec_posting_external_refs';

    protected $fillable = [
        'uuid', 'rec_posting_id', 'rec_source_platform_id', 'external_ref', 'team_id',
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

    public function posting(): BelongsTo
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }

    public function sourcePlatform(): BelongsTo
    {
        return $this->belongsTo(RecSourcePlatform::class, 'rec_source_platform_id');
    }
}
```

- [ ] **Step 6: Bestehende Models erweitern**

In `RecPosting.php` ergänzen:

```php
public function externalRefs(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(RecPostingExternalRef::class, 'rec_posting_id');
}
```

In `RecApplicant.php`: `'suggested_posting_id', 'match_reason'` in `$fillable` aufnehmen und Relationship ergänzen:

```php
public function suggestedPosting(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(RecPosting::class, 'suggested_posting_id');
}
```

In `RecApplicantPosting.php`: `'matched_via', 'match_confidence'` in `$fillable` aufnehmen.

In `RecSourcePlatform.php`: `'ref_parser'` in `$fillable` aufnehmen.

- [ ] **Step 7: Lint**

Run: `php -l <MODUL>/src/Models/RecIntakeChannel.php && php -l <MODUL>/src/Models/RecPostingExternalRef.php`
Expected: `No syntax errors detected` (2×)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_12_* src/Models/
git commit -m "feat(matching): Tabellen rec_intake_channels + rec_posting_external_refs, Audit-Spalten, Models"
```

---

### Task 3: Referenz-Parser (TDD)

**Files:**
- Create: `<MODUL>/src/Services/RefParsers/SourceRefParser.php`
- Create: `<MODUL>/src/Services/RefParsers/KleinanzeigenRefParser.php`
- Create: `<MODUL>/src/Services/RefParsers/WebsiteFormRefParser.php`
- Create: `<MODUL>/src/Services/RefParsers/RefParserRegistry.php`
- Test: `<MODUL>/tests/Unit/RefParsers/KleinanzeigenRefParserTest.php`
- Test: `<MODUL>/tests/Unit/RefParsers/WebsiteFormRefParserTest.php`
- Test: `<MODUL>/tests/Unit/RefParsers/RefParserRegistryTest.php`

Wichtig: Parser sind reines PHP — **keine** Laravel-/Illuminate-Imports, sonst laufen die Unit-Tests nicht (kein Framework-Bootstrap).

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/RefParsers/KleinanzeigenRefParserTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\KleinanzeigenRefParser;

class KleinanzeigenRefParserTest extends TestCase
{
    private KleinanzeigenRefParser $parser;

    protected function setUp(): void
    {
        $this->parser = new KleinanzeigenRefParser();
    }

    public function test_extracts_anzeigentitel_from_real_subject(): void
    {
        // Realer Alteingang aus dem System
        $subject = 'Nutzer-Anfrage zu deiner Anzeige "SERVICEKRÄFTE | EVENTGASTRONOMIE | KÖLN"';

        $this->assertSame(
            'SERVICEKRÄFTE | EVENTGASTRONOMIE | KÖLN',
            $this->parser->extract($subject, null)
        );
    }

    public function test_returns_null_without_anzeigen_pattern(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung als Koch', 'Hallo, ich suche Arbeit'));
    }

    public function test_returns_null_for_null_subject(): void
    {
        $this->assertNull($this->parser->extract(null, 'irgendein Body'));
    }
}
```

`tests/Unit/RefParsers/WebsiteFormRefParserTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\WebsiteFormRefParser;

class WebsiteFormRefParserTest extends TestCase
{
    private WebsiteFormRefParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WebsiteFormRefParser();
    }

    public function test_extracts_posting_ref_from_body(): void
    {
        $body = "Neue Bewerbung über das Formular\n\nPosting-Ref: 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b\n\nName: Max";

        $this->assertSame(
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            $this->parser->extract(null, $body)
        );
    }

    public function test_returns_null_without_ref_line(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung', 'Hallo, hier meine Bewerbung'));
    }
}
```

`tests/Unit/RefParsers/RefParserRegistryTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\KleinanzeigenRefParser;
use Platform\Recruiting\Services\RefParsers\RefParserRegistry;

class RefParserRegistryTest extends TestCase
{
    public function test_resolves_known_parser(): void
    {
        $this->assertInstanceOf(KleinanzeigenRefParser::class, RefParserRegistry::for('kleinanzeigen'));
    }

    public function test_returns_null_for_unknown_or_null_key(): void
    {
        $this->assertNull(RefParserRegistry::for('gibtsnicht'));
        $this->assertNull(RefParserRegistry::for(null));
    }

    public function test_keys_lists_all_parsers(): void
    {
        $this->assertSame(['kleinanzeigen', 'website_form'], RefParserRegistry::keys());
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: Errors `Class "Platform\Recruiting\Services\RefParsers\..." not found`

- [ ] **Step 3: Implementierung**

`src/Services/RefParsers/SourceRefParser.php`:

```php
<?php

namespace Platform\Recruiting\Services\RefParsers;

interface SourceRefParser
{
    /**
     * Extract the external reference (job id / Anzeigentitel / Posting-UUID) from an inbound message.
     */
    public function extract(?string $subject, ?string $body): ?string;
}
```

`src/Services/RefParsers/KleinanzeigenRefParser.php`:

```php
<?php

namespace Platform\Recruiting\Services\RefParsers;

class KleinanzeigenRefParser implements SourceRefParser
{
    public function extract(?string $subject, ?string $body): ?string
    {
        if ($subject && preg_match('/zu deiner Anzeige\s+"([^"]+)"/iu', $subject, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
```

`src/Services/RefParsers/WebsiteFormRefParser.php` (Konvention: das Webseiten-Formular schreibt eine Zeile `Posting-Ref: <uuid>` in die Benachrichtigungs-Mail):

```php
<?php

namespace Platform\Recruiting\Services\RefParsers;

class WebsiteFormRefParser implements SourceRefParser
{
    public function extract(?string $subject, ?string $body): ?string
    {
        if ($body && preg_match('/Posting-Ref:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $body, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
}
```

`src/Services/RefParsers/RefParserRegistry.php`:

```php
<?php

namespace Platform\Recruiting\Services\RefParsers;

class RefParserRegistry
{
    /** @var array<string, class-string<SourceRefParser>> */
    private const PARSERS = [
        'kleinanzeigen' => KleinanzeigenRefParser::class,
        'website_form' => WebsiteFormRefParser::class,
    ];

    public static function for(?string $key): ?SourceRefParser
    {
        $class = self::PARSERS[$key] ?? null;

        return $class ? new $class() : null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::PARSERS);
    }
}
```

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: `OK (9 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add src/Services/RefParsers/ tests/Unit/RefParsers/
git commit -m "feat(matching): Referenz-Parser (kleinanzeigen, website_form) mit Registry, TDD"
```

---

### Task 4: ApplicationMatchingService + MatchResult (Stufe 1 deterministisch)

**Files:**
- Create: `<MODUL>/src/Services/MatchResult.php`
- Create: `<MODUL>/src/Services/ApplicationMatchingService.php`

- [ ] **Step 1: MatchResult DTO**

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecPosting;

class MatchResult
{
    public const VIA_DEDICATED_CHANNEL = 'dedicated_channel';
    public const VIA_EXTERNAL_REF = 'external_ref';
    public const VIA_LLM = 'llm';
    public const VIA_CHANNEL_DEFAULT = 'channel_default';
    public const VIA_MANUAL = 'manual';
    /** Kein Auto-Assign, nur Vorschlag für die Inbox (z. B. Referenz auf geschlossene Ausschreibung). */
    public const VIA_SUGGESTION = 'suggestion';

    public function __construct(
        public readonly RecPosting $posting,
        public readonly string $via,
        public readonly ?string $confidence = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isAssignable(): bool
    {
        return $this->via !== self::VIA_SUGGESTION;
    }
}
```

- [ ] **Step 2: ApplicationMatchingService**

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecIntakeChannel;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefParserRegistry;

class ApplicationMatchingService
{
    /**
     * Gate: Ist dieser Kanal überhaupt ein Bewerbungs-Eingang?
     * Intake-Registry ODER dedizierter Kanal (exklusiv an genau einer offenen Ausschreibung).
     */
    public function isIntakeChannel(CommsChannel $channel): bool
    {
        return RecIntakeChannel::isIntake($channel->id)
            || $this->dedicatedPostingForChannel($channel) !== null;
    }

    /**
     * Dedizierter Kanal = Kanal hängt an GENAU einer offenen Ausschreibung (Kampagnen-Fall).
     */
    public function dedicatedPostingForChannel(CommsChannel $channel): ?RecPosting
    {
        $postings = RecPosting::query()
            ->whereHas('commsChannels', fn ($q) => $q->where('comms_channels.id', $channel->id))
            ->open()
            ->limit(2)
            ->get();

        return $postings->count() === 1 ? $postings->first() : null;
    }

    /**
     * Stufe 1: dedizierter Kanal, dann Portal-Referenz via quellen-spezifischem Parser.
     * Liefert auch VIA_SUGGESTION, wenn eine Referenz auf eine geschlossene Ausschreibung zeigt.
     */
    public function matchDeterministic(
        CommsChannel $channel,
        ?RecSourcePlatform $source,
        ?string $subject,
        ?string $body,
    ): ?MatchResult {
        if ($dedicated = $this->dedicatedPostingForChannel($channel)) {
            return new MatchResult($dedicated, MatchResult::VIA_DEDICATED_CHANNEL);
        }

        if (!$source || !$source->ref_parser) {
            return null;
        }

        $ref = RefParserRegistry::for($source->ref_parser)?->extract($subject, $body);
        if (!$ref) {
            return null;
        }

        $posting = RecPostingExternalRef::query()
            ->where('rec_source_platform_id', $source->id)
            ->where('external_ref', $ref)
            ->first()
            ?->posting;

        if (!$posting) {
            return null;
        }

        $isOpen = RecPosting::query()->open()->whereKey($posting->id)->exists();
        if ($isOpen) {
            return new MatchResult($posting, MatchResult::VIA_EXTERNAL_REF);
        }

        // Spec §9: Referenz auf geschlossene Ausschreibung → kein Auto-Assign, Inbox-Vorschlag
        return new MatchResult(
            $posting,
            MatchResult::VIA_SUGGESTION,
            reason: 'Portal-Referenz zeigt auf geschlossene Ausschreibung "' . $posting->title . '"',
        );
    }

    /**
     * Stufe 3: optionale Fallback-Ausschreibung des Intake-Kanals (nur wenn offen).
     */
    public function defaultPostingForChannel(CommsChannel $channel): ?RecPosting
    {
        $intake = RecIntakeChannel::query()
            ->where('comms_channel_id', $channel->id)
            ->where('is_active', true)
            ->first();

        $posting = $intake?->defaultPosting;
        if (!$posting) {
            return null;
        }

        return RecPosting::query()->open()->whereKey($posting->id)->exists() ? $posting : null;
    }
}
```

- [ ] **Step 3: Lint + bestehende Tests**

Run: `php -l <MODUL>/src/Services/MatchResult.php && php -l <MODUL>/src/Services/ApplicationMatchingService.php && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: keine Syntax-Fehler, Tests weiter grün

- [ ] **Step 4: Commit**

```bash
git add src/Services/MatchResult.php src/Services/ApplicationMatchingService.php
git commit -m "feat(matching): ApplicationMatchingService mit deterministischer Stufe 1 + MatchResult-DTO"
```

---

### Task 5: IncomingApplicationService umbauen + Listener-Gates

**Files:**
- Modify: `<MODUL>/src/Services/IncomingApplicationService.php`
- Modify: `<MODUL>/src/Listeners/HandleCommsInboundForRecruiting.php`
- Modify: `<MODUL>/src/Listeners/HandleWhatsAppInboundForRecruiting.php`

Kernänderungen: (a) Gate über `isIntakeChannel()` statt `findPostingsForChannel()`, (b) Bewerber wird NICHT mehr an alle Kanal-Postings gehängt, (c) neue Bewerber starten ggf. ohne Posting/Phase (`is_unrouted`), (d) zentrale `assignPosting()`-Methode setzt Posting + Phase + Audit + gibt Enrichment frei.

- [ ] **Step 1: `handleInboundMessage()` ersetzen**

Signatur erweitern (neuer optionaler Parameter `$source` — die Listener erkennen die Quelle bereits heute via `RecSourcePlatform::detectFromSender()` und reichen sie jetzt durch):

```php
public function handleInboundMessage(
    CommsChannel $channel,
    string $senderIdentifier,
    ?string $senderName = null,
    ?string $subject = null,
    ?string $messageBody = null,
    ?RecSourcePlatform $source = null,
): ?array {
    $matching = app(ApplicationMatchingService::class);

    if (!$matching->isIntakeChannel($channel)) {
        Log::debug('[IncomingApplicationService] Channel is not a recruiting intake channel', [
            'channel_id' => $channel->id,
            'channel_type' => $channel->type,
        ]);
        return null;
    }

    $teamId = $channel->team_id;

    if ($this->senderHasActiveHcmRecord($senderIdentifier, $channel->type, $teamId)) {
        Log::info('[IncomingApplicationService] Sender has active onboarding or employee record, skipping applicant creation', [
            'sender' => $senderIdentifier,
            'channel_type' => $channel->type,
        ]);
        return null;
    }

    // Bestandscheck (Stufe 0) — breite Suche, Posting-unabhängig
    $existingApplicant = $this->findExistingApplicantByContact($senderIdentifier, $teamId);

    if ($existingApplicant) {
        Log::info('[IncomingApplicationService] Existing applicant found, appending to application', [
            'applicant_id' => $existingApplicant->id,
            'sender' => $senderIdentifier,
        ]);

        $notePrefix = now()->format('d.m.Y H:i');
        $appendNote = "[{$notePrefix}] Weitere Nachricht via {$channel->type}: " . ($subject ?? $messageBody ?? 'Nachricht erhalten');
        $existingApplicant->notes = trim(($existingApplicant->notes ?? '') . "\n" . $appendNote);
        $existingApplicant->save();

        if ($channel->type === 'whatsapp') {
            $this->markPhoneAsWhatsAppOptedIn($existingApplicant, $senderIdentifier);
        }

        return [
            'applicant' => $existingApplicant,
            'posting' => $existingApplicant->postings()->first(),
            'is_new' => false,
        ];
    }

    // Neuer Bewerber: Stufe 1 inline, Stufe 2-4 asynchron im Job
    $match = $matching->matchDeterministic($channel, $source, $subject, $messageBody);

    return DB::transaction(function () use ($match, $channel, $senderIdentifier, $senderName, $subject, $messageBody, $teamId) {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $defaultStatusId = $settings->getSetting('default_status_id');

        $firstName = null;
        $lastName = null;
        if ($senderName) {
            $nameParts = $this->parseSenderName($senderName);
            $firstName = $nameParts['first_name'];
            $lastName = $nameParts['last_name'];
        }

        $notes = "Automatisch erstellt via {$channel->type} ({$channel->name})";
        if ($subject) {
            $notes .= "\nBetreff: {$subject}";
        }

        $applicant = RecApplicant::create([
            'rec_applicant_status_id' => $defaultStatusId,
            'rec_phase_id' => null,
            'applied_at' => now()->toDateString(),
            'notes' => $notes,
            'progress' => 0,
            'team_id' => $teamId,
            'created_by_user_id' => null,
            'is_active' => true,
            'auto_pilot' => false,
            'is_unrouted' => true,
            'enrichment_status' => 'unrouted',
        ]);

        $this->createAndLinkContact($applicant, $senderIdentifier, $firstName, $lastName, $channel->type, $teamId);

        if ($match && $match->isAssignable()) {
            $this->assignPosting($applicant, $match);
        } elseif ($match) {
            // Referenz auf geschlossene Ausschreibung → Inbox mit Vorschlag
            $applicant->forceFill([
                'suggested_posting_id' => $match->posting->id,
                'match_reason' => $match->reason,
            ])->save();
        } else {
            \Platform\Recruiting\Jobs\MatchApplicantToPostingJob::dispatch(
                $applicant->id,
                $channel->id,
                $subject,
                $messageBody,
            )->afterCommit();
        }

        Log::info('[IncomingApplicationService] New applicant created', [
            'applicant_id' => $applicant->id,
            'matched_via' => $match?->via,
            'channel_type' => $channel->type,
            'sender' => $senderIdentifier,
        ]);

        return [
            'applicant' => $applicant,
            'posting' => ($match && $match->isAssignable()) ? $match->posting : null,
            'is_new' => true,
        ];
    });
}
```

Imports oben ergänzen: `use Platform\Recruiting\Models\RecSourcePlatform;`

- [ ] **Step 2: `assignPosting()` neu hinzufügen** (zentrale Zuordnung — genutzt von Stufe 1 inline, Job und Inbox)

```php
/**
 * Assign an applicant to a posting: pivot with audit, phase from position, release enrichment.
 */
public function assignPosting(RecApplicant $applicant, MatchResult $match): void
{
    $applicant->postings()->syncWithoutDetaching([
        $match->posting->id => [
            'applied_at' => now()->toDateString(),
            'notes' => 'Zugeordnet via ' . $match->via,
            'matched_via' => $match->via,
            'match_confidence' => $match->confidence,
        ],
    ]);

    $applicant->forceFill([
        'rec_phase_id' => $applicant->rec_phase_id ?? $match->posting->position?->firstPhase()?->id,
        'is_unrouted' => false,
        'suggested_posting_id' => null,
        'match_reason' => null,
        'enrichment_status' => null, // Enrichment-Scheduler greift jetzt
    ])->save();

    Log::info('[IncomingApplicationService] Applicant assigned to posting', [
        'applicant_id' => $applicant->id,
        'posting_id' => $match->posting->id,
        'matched_via' => $match->via,
        'confidence' => $match->confidence,
    ]);
}
```

Import oben ergänzen: `use Platform\Recruiting\Services\MatchResult;` ist nicht nötig (gleicher Namespace).

- [ ] **Step 3: Alte Methoden entfernen/anpassen**

- `findPostingsForChannel()` **löschen** (einzige verbleibende Nutzer sind die Listener — siehe Step 4 — und `EnrichInboxApplicants`; dort prüfen: `grep -n "findPostingsForChannel" <MODUL>/src -r`. Falls `EnrichInboxApplicants` sie nutzt, dort auf `$applicant->postings` umstellen).
- `linkApplicantToPostings()` **löschen**.
- `findExistingApplicantForPostings()` **löschen** (Bestandscheck nutzt nur noch `findExistingApplicantByContact()`).

- [ ] **Step 4: Listener-Gates umstellen**

`HandleCommsInboundForRecruiting.php`: Die Stelle finden, an der `handleInboundMessage(...)` aufgerufen wird (ca. Zeile 98-104) und die bereits erfolgte Quellen-Erkennung (`RecSourcePlatform::detectFromSender(...)`) als `source:`-Parameter durchreichen. Suchen mit:

```bash
grep -n "detectFromSender\|handleInboundMessage\|is_unrouted\|enrichment_status" <MODUL>/src/Listeners/HandleCommsInboundForRecruiting.php
```

Regeln für die Anpassung (gilt für beide Listener):
1. `handleInboundMessage(...)` bekommt zusätzlich `source: $detectedSource` (die Variable aus dem vorhandenen `detectFromSender`-Aufruf; falls die Erkennung bisher NACH dem Service-Aufruf passiert, die Erkennung VOR den Aufruf ziehen).
2. Der Listener darf `is_unrouted` und `enrichment_status` **nicht mehr selbst setzen** — das verwaltet jetzt der Service/Job. `source_platform_id` setzt der Listener weiterhin (unverändert).
3. Null-Rückgabe von `handleInboundMessage` weiterhin als "nicht relevant" behandeln (return).
4. `$result['posting']` kann jetzt `null` sein — alle Verwendungen null-safe machen.

`HandleWhatsAppInboundForRecruiting.php`: zusätzlich die Methode `channelHasPostings()` ersetzen:

```php
private function channelIsIntake(CommsChannel $channel): bool
{
    return app(\Platform\Recruiting\Services\ApplicationMatchingService::class)->isIntakeChannel($channel);
}
```

und den Aufrufer von `channelHasPostings(...)` auf `channelIsIntake(...)` umstellen.

- [ ] **Step 5: Lint + Grep-Verifikation**

Run: `php -l <MODUL>/src/Services/IncomingApplicationService.php && php -l <MODUL>/src/Listeners/HandleCommsInboundForRecruiting.php && php -l <MODUL>/src/Listeners/HandleWhatsAppInboundForRecruiting.php`
Expected: keine Syntax-Fehler

Run: `grep -rn "findPostingsForChannel\|linkApplicantToPostings" <MODUL>/src`
Expected: keine Treffer

- [ ] **Step 6: Commit**

```bash
git add src/Services/IncomingApplicationService.php src/Listeners/
git commit -m "feat(matching): Eingang ueber Intake-Gate + deterministische Stufe 1, kein Link-an-alle-Postings mehr"
```

---

### Task 6: MatchApplicantToPostingJob (Stufe 2 LLM, Stufe 3 Default, Stufe 4 Inbox)

**Files:**
- Create: `<MODUL>/src/Jobs/MatchApplicantToPostingJob.php`

- [ ] **Step 1: Job implementieren**

```php
<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\CoreAiProvider;
use Platform\Core\Services\OpenAiService;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Services\ApplicationMatchingService;
use Platform\Recruiting\Services\IncomingApplicationService;
use Platform\Recruiting\Services\MatchResult;

class MatchApplicantToPostingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = 60;

    public function __construct(
        private int $applicantId,
        private int $channelId,
        private ?string $subject,
        private ?string $body,
    ) {
    }

    public function handle(ApplicationMatchingService $matching, IncomingApplicationService $applications): void
    {
        $applicant = RecApplicant::find($this->applicantId);

        // Schon zugeordnet (manuell/parallel) oder gelöscht → nichts tun
        if (!$applicant || !$applicant->is_unrouted || $applicant->postings()->exists()) {
            return;
        }

        $channel = CommsChannel::find($this->channelId);

        // Kandidaten: offene Ausschreibungen, älteste zuerst (Regel "Ort unklar → älteste offene")
        $candidates = RecPosting::query()
            ->forTeam($applicant->team_id)
            ->open()
            ->with('position')
            ->orderBy('id')
            ->get();

        $llm = $candidates->isEmpty() ? null : $this->askLlm($applicant, $candidates);

        // Stufe 2a: LLM sagt "keine Bewerbung" → kein Auto-Discard, aber Inbox mit Begründung
        if ($llm && $llm['is_application'] === false) {
            $applicant->forceFill([
                'match_reason' => 'Vermutlich keine Bewerbung: ' . $llm['reason'],
            ])->save();
            return;
        }

        // Stufe 2b: hohe Konfidenz → automatisch zuordnen
        if ($llm && $llm['posting'] && $llm['confidence'] === 'high') {
            $applications->assignPosting(
                $applicant,
                new MatchResult($llm['posting'], MatchResult::VIA_LLM, 'high', $llm['reason']),
            );
            return;
        }

        // Stufe 3: Kanal-Default
        if ($channel && ($default = $matching->defaultPostingForChannel($channel))) {
            $applications->assignPosting(
                $applicant,
                new MatchResult($default, MatchResult::VIA_CHANNEL_DEFAULT),
            );
            return;
        }

        // Stufe 4: Inbox mit Vorschlag (falls vorhanden)
        if ($llm && $llm['posting']) {
            $applicant->forceFill([
                'suggested_posting_id' => $llm['posting']->id,
                'match_reason' => $llm['reason'],
            ])->save();
        }
    }

    /**
     * Ein einzelner Klassifikations-Call, strikt validiert.
     * Liefert null bei Fehler/unparsbarer Antwort (→ Pipeline läuft mit Stufe 3/4 weiter).
     *
     * @return array{is_application: bool, posting: ?RecPosting, confidence: string, reason: string}|null
     */
    private function askLlm(RecApplicant $applicant, \Illuminate\Database\Eloquent\Collection $candidates): ?array
    {
        $list = $candidates->map(fn (RecPosting $p) => [
            'uuid' => $p->uuid,
            'titel' => $p->title,
            'stelle' => $p->position?->title,
            'ort' => $p->position?->location,
            'beschreibung' => mb_substr((string) $p->description, 0, 300),
        ])->values()->all();

        $messages = [
            [
                'role' => 'system',
                'content' => 'Du ordnest eingehende Bewerbungen der passenden Stellenanzeige zu. '
                    . 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne Markdown: '
                    . '{"is_application": true|false, "posting_uuid": "<uuid aus der Liste>"|null, '
                    . '"confidence": "high"|"medium"|"low", "reason": "<max 200 Zeichen, deutsch>"}. '
                    . 'Waehle posting_uuid NUR aus der mitgegebenen Liste. '
                    . 'confidence=high nur, wenn die Rolle eindeutig zu genau einer Anzeige passt. '
                    . 'Wenn dieselbe Rolle an mehreren Orten ausgeschrieben ist und der Ort unklar bleibt: '
                    . 'waehle die ERSTE passende Anzeige in der Liste mit confidence=high. '
                    . 'is_application=false fuer Systemmails, Newsletter, Spam.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'nachricht' => [
                        'betreff' => $this->subject,
                        'text' => mb_substr((string) $this->body, 0, 4000),
                    ],
                    'offene_ausschreibungen' => $list,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $result = app(OpenAiService::class)->chat($messages, $this->determineModel(), [
                'max_tokens' => 300,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MatchApplicantToPostingJob] LLM call failed', [
                'applicant_id' => $applicant->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Spec §9: bei transienten Fehlern erst Job-Retry; erst nach letztem Versuch
            // ohne LLM weitermachen (Stufe 3/4 statt Datenverlust).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            return null;
        }

        $raw = trim((string) ($result['content'] ?? ''));
        // tolerant gegen ```json ... ```-Wrapper
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $json = json_decode(trim((string) $raw), true);

        if (!is_array($json)) {
            Log::warning('[MatchApplicantToPostingJob] LLM response not parseable', [
                'applicant_id' => $applicant->id,
                'raw' => mb_substr($raw, 0, 500),
            ]);
            return null;
        }

        $posting = null;
        if (!empty($json['posting_uuid'])) {
            // NUR UUIDs aus der Kandidatenliste akzeptieren (Manipulationsschutz)
            $posting = $candidates->firstWhere('uuid', $json['posting_uuid']);
        }

        $confidence = in_array($json['confidence'] ?? null, ['high', 'medium', 'low'], true)
            ? $json['confidence']
            : 'low';

        return [
            'is_application' => ($json['is_application'] ?? true) !== false,
            'posting' => $posting,
            'confidence' => $confidence,
            'reason' => mb_substr((string) ($json['reason'] ?? ''), 0, 500),
        ];
    }

    private function determineModel(): string
    {
        try {
            $provider = CoreAiProvider::where('key', 'openai')->where('is_active', true)->with('defaultModel')->first();
            $fallback = $provider?->defaultModel?->model_id;
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        } catch (\Throwable $e) {
        }

        return 'gpt-5.2';
    }
}
```

Hinweis: Namespace von `OpenAiService`/`CoreAiProvider` vor dem Commit gegen `EnrichInboxApplicants.php` prüfen (`grep -n "use Platform" <MODUL>/src/Console/Commands/EnrichInboxApplicants.php`) und exakt übernehmen.

- [ ] **Step 2: Lint**

Run: `php -l <MODUL>/src/Jobs/MatchApplicantToPostingJob.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Jobs/MatchApplicantToPostingJob.php
git commit -m "feat(matching): LLM-Matching-Job (Stufe 2-4) mit striktem JSON-Schema und Kandidaten-Whitelist"
```

---

### Task 7: Eingangs-Inbox — Vorschlag anzeigen, bestätigen, umhängen

**Files:**
- Modify: `<MODUL>/src/Livewire/Inbox/Index.php`
- Modify: `<MODUL>/resources/views/livewire/inbox/index.blade.php`

- [ ] **Step 1: Component erweitern**

In `Index.php` das Eager-Loading der `unroutedApplicants` um `'suggestedPosting.position'` ergänzen und eine Computed-Property + zwei Aktionen hinzufügen:

```php
#[Computed]
public function openPostings()
{
    $teamId = auth()->user()?->currentTeam?->id;

    return \Platform\Recruiting\Models\RecPosting::query()
        ->forTeam($teamId)
        ->open()
        ->with('position')
        ->orderBy('title')
        ->get();
}

public function confirmSuggestedPosting(int $applicantId): void
{
    $applicant = \Platform\Recruiting\Models\RecApplicant::query()
        ->forTeam(auth()->user()?->currentTeam?->id)
        ->findOrFail($applicantId);

    if (!$applicant->suggested_posting_id || !$applicant->suggestedPosting) {
        return;
    }

    app(\Platform\Recruiting\Services\IncomingApplicationService::class)->assignPosting(
        $applicant,
        new \Platform\Recruiting\Services\MatchResult(
            $applicant->suggestedPosting,
            \Platform\Recruiting\Services\MatchResult::VIA_MANUAL,
            reason: 'Inbox-Vorschlag bestätigt',
        ),
    );

    unset($this->unroutedApplicants);
}

public function assignPosting(int $applicantId, int $postingId): void
{
    $teamId = auth()->user()?->currentTeam?->id;

    $applicant = \Platform\Recruiting\Models\RecApplicant::query()->forTeam($teamId)->findOrFail($applicantId);
    $posting = \Platform\Recruiting\Models\RecPosting::query()->forTeam($teamId)->findOrFail($postingId);

    app(\Platform\Recruiting\Services\IncomingApplicationService::class)->assignPosting(
        $applicant,
        new \Platform\Recruiting\Services\MatchResult($posting, \Platform\Recruiting\Services\MatchResult::VIA_MANUAL),
    );

    unset($this->unroutedApplicants);
}
```

Hinweis: Wie der Team-Kontext in diesem Component aufgelöst wird, an den vorhandenen Methoden ablesen (`grep -n "team" <MODUL>/src/Livewire/Inbox/Index.php`) und exakt dasselbe Muster verwenden statt `auth()->user()?->currentTeam?->id`, falls dort ein anderes existiert.

- [ ] **Step 2: View erweitern**

In `index.blade.php` pro Bewerber-Zeile (innerhalb der bestehenden Schleife über `$this->unroutedApplicants`) einen Block ergänzen — Platzierung neben den bestehenden Aktionen (Quelle zuweisen / Verwerfen):

```blade
{{-- Ausschreibungs-Zuordnung --}}
<div class="mt-2 space-y-1">
    @if($applicant->suggestedPosting)
        <div class="flex items-center gap-2 text-sm">
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">Vorschlag</span>
            <span>{{ $applicant->suggestedPosting->title }}</span>
            <button type="button"
                    wire:click="confirmSuggestedPosting({{ $applicant->id }})"
                    class="text-xs font-medium text-emerald-600 hover:underline">
                Bestätigen
            </button>
        </div>
        @if($applicant->match_reason)
            <p class="text-xs text-[var(--ui-secondary)]">{{ $applicant->match_reason }}</p>
        @endif
    @elseif($applicant->match_reason)
        <p class="text-xs text-[var(--ui-secondary)]">{{ $applicant->match_reason }}</p>
    @endif

    <div class="flex items-center gap-2">
        @php($postingOptions = $this->openPostings)
        <select wire:change="assignPosting({{ $applicant->id }}, $event.target.value)"
                class="text-xs border rounded px-1.5 py-1">
            <option value="">Ausschreibung zuordnen …</option>
            @foreach($postingOptions as $posting)
                <option value="{{ $posting->id }}">{{ $posting->title }}@if($posting->position?->location) — {{ $posting->position->location }}@endif</option>
            @endforeach
        </select>
    </div>
</div>
```

Vorher die View lesen und Styling/Komponenten-Idiom der umliegenden Zeilen übernehmen (x-ui-*-Komponenten falls dort genutzt; Achtung Memory-Regel: keine inline-`@if` in x-ui-Attributen, Werte in `@php`-Block vorberechnen).

- [ ] **Step 3: Lint**

Run: `php -l <MODUL>/src/Livewire/Inbox/Index.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Inbox/Index.php resources/views/livewire/inbox/index.blade.php
git commit -m "feat(matching): Inbox zeigt LLM-Vorschlag mit Ein-Klick-Bestaetigung + manuellem Zuordnen"
```

---

### Task 8: Settings-Tab „Eingangskanäle"

**Files:**
- Modify: `<MODUL>/src/Livewire/Applicant/ApplicantSettingsModal.php`
- Modify: `<MODUL>/resources/views/livewire/applicant/applicant-settings-modal.blade.php`

Die Eingangs-Quellen (Screenshot des Users) leben als Tab in diesem Modal — der neue Tab „Eingangskanäle" kommt direkt daneben, gleiches Muster.

- [ ] **Step 1: Component erweitern**

```php
public array $intakeChannels = [];

public function loadIntakeChannels(int $teamId): void
{
    $registered = \Platform\Recruiting\Models\RecIntakeChannel::query()
        ->where('team_id', $teamId)
        ->get()
        ->keyBy('comms_channel_id');

    $this->intakeChannels = \Platform\Crm\Models\CommsChannel::query()
        ->where('team_id', $teamId)
        ->orderBy('name')
        ->get()
        ->map(fn ($channel) => [
            'channel_id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type,
            'is_intake' => $registered->has($channel->id) && $registered[$channel->id]->is_active,
            'default_posting_id' => $registered[$channel->id]->default_posting_id ?? null,
        ])
        ->toArray();
}

public function toggleIntakeChannel(int $channelId): void
{
    $teamId = $this->resolveTeamId(); // selbes Muster wie loadSourcePlatforms-Aufrufer

    $intake = \Platform\Recruiting\Models\RecIntakeChannel::query()
        ->where('team_id', $teamId)
        ->where('comms_channel_id', $channelId)
        ->first();

    if ($intake) {
        $intake->update(['is_active' => !$intake->is_active]);
    } else {
        \Platform\Recruiting\Models\RecIntakeChannel::create([
            'comms_channel_id' => $channelId,
            'team_id' => $teamId,
            'is_active' => true,
        ]);
    }

    $this->loadIntakeChannels($teamId);
}

public function setIntakeDefaultPosting(int $channelId, ?int $postingId): void
{
    $teamId = $this->resolveTeamId();

    \Platform\Recruiting\Models\RecIntakeChannel::query()
        ->where('team_id', $teamId)
        ->where('comms_channel_id', $channelId)
        ->update(['default_posting_id' => $postingId ?: null]);

    $this->loadIntakeChannels($teamId);
}
```

Konkretes Vorgehen: `loadSourcePlatforms()` im Component anschauen — wie dort `teamId` ermittelt und wo `loadSourcePlatforms()` aufgerufen wird (beim Öffnen des Modals). `loadIntakeChannels($teamId)` an denselben Stellen mit aufrufen. `resolveTeamId()` durch das dort verwendete Muster ersetzen (es gibt vermutlich bereits eine Property/Methode).

- [ ] **Step 2: View — neuer Tab**

Im Blade neben dem Tab „Eingangs-Quellen" einen Tab „Eingangskanäle" ergänzen (gleiche Tab-Mechanik wie vorhanden). Tab-Inhalt:

```blade
<div class="space-y-2">
    <p class="text-sm text-[var(--ui-secondary)]">
        Lege fest, auf welchen Kanälen Bewerbungen eingehen. Nur markierte Kanäle erzeugen Bewerber.
        Optional kann pro Kanal eine Fallback-Ausschreibung gesetzt werden, die greift, wenn keine
        automatische Zuordnung möglich ist.
    </p>

    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-[var(--ui-secondary)]">
                <th class="py-1">Kanal</th>
                <th class="py-1">Typ</th>
                <th class="py-1">Bewerbungs-Eingang</th>
                <th class="py-1">Fallback-Ausschreibung</th>
            </tr>
        </thead>
        <tbody>
            @foreach($intakeChannels as $row)
                <tr class="border-t">
                    <td class="py-1.5">{{ $row['name'] }}</td>
                    <td class="py-1.5">{{ $row['type'] }}</td>
                    <td class="py-1.5">
                        <input type="checkbox"
                               wire:click="toggleIntakeChannel({{ $row['channel_id'] }})"
                               @checked($row['is_intake'])>
                    </td>
                    <td class="py-1.5">
                        @if($row['is_intake'])
                            <select wire:change="setIntakeDefaultPosting({{ $row['channel_id'] }}, $event.target.value)"
                                    class="text-xs border rounded px-1.5 py-1">
                                <option value="">— keine —</option>
                                @foreach($this->openPostingsForSettings as $posting)
                                    @php($selected = (int) ($row['default_posting_id'] ?? 0) === $posting->id)
                                    <option value="{{ $posting->id }}" @selected($selected)>{{ $posting->title }}</option>
                                @endforeach
                            </select>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

Dazu im Component:

```php
#[Computed]
public function openPostingsForSettings()
{
    return \Platform\Recruiting\Models\RecPosting::query()
        ->forTeam($this->resolveTeamId())
        ->open()
        ->orderBy('title')
        ->get();
}
```

- [ ] **Step 3: Lint + Commit**

Run: `php -l <MODUL>/src/Livewire/Applicant/ApplicantSettingsModal.php`
Expected: `No syntax errors detected`

```bash
git add src/Livewire/Applicant/ApplicantSettingsModal.php resources/views/livewire/applicant/applicant-settings-modal.blade.php
git commit -m "feat(matching): Settings-Tab Eingangskanaele (Intake-Registry + Fallback-Ausschreibung)"
```

---

### Task 9: Posting-UI — dedizierter Kanal + externe Referenzen

**Files:**
- Modify: `<MODUL>/src/Livewire/Posting/Show.php`
- Modify: zugehörige View (Pfad via `grep -rn "commsChannels" <MODUL>/resources/views/livewire/posting/` ermitteln)

- [ ] **Step 1: Component — Kanal-Bereich umwidmen, Referenz-Verwaltung ergänzen**

Die bestehenden Methoden für Kanal-attach/-detach (`Show.php:71-78`) bleiben funktional erhalten, werden aber in der View als „Dedizierter Kanal (Kampagne)" beschriftet. Neu hinzu:

```php
public string $newRefSourceId = '';
public string $newRefValue = '';

public function addExternalRef(): void
{
    $this->validate([
        'newRefSourceId' => 'required|integer',
        'newRefValue' => 'required|string|max:255',
    ]);

    \Platform\Recruiting\Models\RecPostingExternalRef::firstOrCreate(
        [
            'rec_source_platform_id' => (int) $this->newRefSourceId,
            'external_ref' => trim($this->newRefValue),
        ],
        [
            'rec_posting_id' => $this->posting->id,
            'team_id' => $this->posting->team_id,
        ],
    );

    $this->newRefSourceId = '';
    $this->newRefValue = '';
    $this->posting->load('externalRefs.sourcePlatform');
}

public function removeExternalRef(int $refId): void
{
    \Platform\Recruiting\Models\RecPostingExternalRef::query()
        ->where('rec_posting_id', $this->posting->id)
        ->whereKey($refId)
        ->delete();

    $this->posting->load('externalRefs.sourcePlatform');
}
```

Im `mount()` das Eager-Loading (`Show.php:18`) um `'externalRefs.sourcePlatform'` ergänzen. Für das Quellen-Dropdown:

```php
#[Computed]
public function sourcePlatforms()
{
    return \Platform\Recruiting\Models\RecSourcePlatform::query()
        ->where('team_id', $this->posting->team_id)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();
}
```

- [ ] **Step 2: View — Abschnitt „Externe Referenzen"**

Im Kanal-Abschnitt der Posting-Show-View: Überschrift in „Dedizierter Kanal (Kampagne)" ändern und einen Hinweistext ergänzen: „Nur für exklusive Kampagnen-Kanäle. Reguläre Eingänge laufen über die Eingangskanäle (Bewerber-Einstellungen) und werden automatisch zugeordnet."

Neuer Abschnitt darunter:

```blade
<div class="mt-4">
    <h3 class="text-sm font-medium">Externe Referenzen</h3>
    <p class="text-xs text-[var(--ui-secondary)]">
        Unter welcher ID/welchem Titel läuft diese Anzeige auf den Portalen?
        Eingehende Portal-Mails werden darüber automatisch dieser Ausschreibung zugeordnet.
    </p>

    <ul class="mt-2 space-y-1">
        @foreach($posting->externalRefs as $ref)
            <li class="flex items-center gap-2 text-sm">
                <span class="font-medium">{{ $ref->sourcePlatform?->name }}:</span>
                <span class="font-mono text-xs">{{ $ref->external_ref }}</span>
                <button type="button" wire:click="removeExternalRef({{ $ref->id }})"
                        class="text-xs text-red-600 hover:underline">Entfernen</button>
            </li>
        @endforeach
    </ul>

    <div class="mt-2 flex items-center gap-2">
        @php($sources = $this->sourcePlatforms)
        <select wire:model="newRefSourceId" class="text-xs border rounded px-1.5 py-1">
            <option value="">Quelle …</option>
            @foreach($sources as $source)
                <option value="{{ $source->id }}">{{ $source->name }}</option>
            @endforeach
        </select>
        <input type="text" wire:model="newRefValue" placeholder="Job-ID / Anzeigentitel"
               class="text-xs border rounded px-1.5 py-1 flex-1">
        <button type="button" wire:click="addExternalRef"
                class="text-xs font-medium text-emerald-600 hover:underline">Hinzufügen</button>
    </div>
</div>
```

Auch hier: vorhandenes View-Idiom übernehmen (x-ui-Komponenten, Memory-Regel zu Attributen beachten).

- [ ] **Step 3: Lint + Commit**

Run: `php -l <MODUL>/src/Livewire/Posting/Show.php`
Expected: `No syntax errors detected`

```bash
git add src/Livewire/Posting/Show.php resources/views/livewire/posting/
git commit -m "feat(matching): Posting-UI mit dediziertem Kanal + Pflege externer Portal-Referenzen"
```

---

### Task 10: Seed-/Cleanup-Migration + Abschluss-Verifikation

**Files:**
- Create: `<MODUL>/database/migrations/2026_06_12_000004_seed_intake_channels_from_posting_links.php`

- [ ] **Step 1: Seed-Migration** (geteilte Kanäle → Intake-Registry + Links lösen; exklusive Kanäle bleiben dediziert)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        // Kanäle, die an MEHR als einem Posting hängen = geteilte Eingangskanäle (Sammeladresse, WhatsApp)
        $sharedChannelIds = DB::table('rec_posting_comms_channel')
            ->select('comms_channel_id')
            ->groupBy('comms_channel_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('comms_channel_id');

        foreach ($sharedChannelIds as $channelId) {
            $teamId = DB::table('comms_channels')->where('id', $channelId)->value('team_id');
            if (!$teamId) {
                continue;
            }

            $exists = DB::table('rec_intake_channels')
                ->where('comms_channel_id', $channelId)
                ->where('team_id', $teamId)
                ->exists();

            if (!$exists) {
                DB::table('rec_intake_channels')->insert([
                    'uuid' => UuidV7::generate(),
                    'comms_channel_id' => $channelId,
                    'team_id' => $teamId,
                    'default_posting_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Geteilte Verknüpfungen lösen — Zuordnung macht ab jetzt die Pipeline
            DB::table('rec_posting_comms_channel')->where('comms_channel_id', $channelId)->delete();
        }

        // Exklusive Verknüpfungen (genau 1 Posting) bleiben als dedizierte Kanäle bestehen.
    }

    public function down(): void
    {
        // Bewusst kein Rollback der Datenbereinigung (Altzustand nicht rekonstruierbar).
    }
};
```

- [ ] **Step 2: Alle Unit-Tests + Lint final**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit && find src -name "*.php" -newer composer.json | xargs -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"`
Expected: Tests grün, `ALL CLEAN`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_12_000004_seed_intake_channels_from_posting_links.php
git commit -m "feat(matching): Seed-Migration Intake-Kanaele aus bestehenden Posting-Verknuepfungen"
```

- [ ] **Step 4: Manuelle End-to-End-Verifikation (nach Deploy/lokal in der Host-App)**

Checkliste (in `php artisan tinker` der Host-App bzw. über die UI):

1. `RecIntakeChannel::all()` — Sammeladresse + WhatsApp-Kanal sind geseedet und aktiv.
2. Settings-Modal → Tab „Eingangskanäle" zeigt die Kanäle, Toggle + Default-Dropdown funktionieren.
3. Test-Mail an die Sammeladresse mit Kleinanzeigen-Betreff (`Nutzer-Anfrage zu deiner Anzeige "<vorher als external_ref gepflegter Titel>"`) → Bewerber entsteht mit `matched_via=external_ref`, korrekter Phase, `enrichment_status=null`.
4. Test-Mail mit freiem Text („Ich möchte als Servicekraft arbeiten") → `MatchApplicantToPostingJob` läuft, Bewerber `matched_via=llm` ODER landet mit Vorschlag in der Inbox.
5. Inbox: Vorschlag bestätigen → Posting/Phase gesetzt, Bewerber verschwindet aus Inbox, Enrichment füllt anschließend die Felder.
6. Mail an einen Nicht-Intake-Kanal → es entsteht KEIN Bewerber.
7. Bestandsbewerber schreibt erneut → Note angehängt, keine neue Posting-Verknüpfung.
8. **Nach Deploy zwingend `php artisan queue:restart`** (sonst routet der alte Worker-Code weiter) und meingedeck composer.lock bumpen.

---

## Offene Punkte für spätere Würfe (bewusst NICHT in diesem Plan)

- Indeed-/StepStone-Parser (reale Beispiel-Mails sammeln, dann je 1 Klasse + Test in `RefParsers/`).
- Anhang-Auszug (CV) als zusätzlicher LLM-Input.
- Direkteinstellungs-Feature (eigene Spec vom 2026-06-12, baut auf dieser Pipeline auf).
