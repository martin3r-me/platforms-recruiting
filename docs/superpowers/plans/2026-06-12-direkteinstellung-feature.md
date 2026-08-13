# Direkteinstellung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kunden können persönliche Besetzungen (Serviceleiter/Teamleiter) selbst einrichten: Wizard erzeugt Stelle (2 Phasen, ohne AutoPilot) + Ausschreibung + Eingang (eigene Mail ODER Referenz-Code) + zuständigen User; Bewerbungen laufen gebündelt in einer eigenen Sidebar-Ansicht auf; bei Entscheidung wird der Portal-Link verschickt und bei vollständiger Datenerfassung entsteht der MA-Datensatz.

**Architecture:** Kein neues Datenmodell — nur ein `is_direct_hire`-Flag an `rec_positions` plus Konfiguration bestehender Objekte. Neuer generischer `RefCodeParser` (Format `RG-XXXX`) läuft quellen-unabhängig als zusätzlicher Stufe-1-Schritt in `ApplicationMatchingService`. `DirectHireSetupService` kapselt die Wizard-Objekterzeugung. Neue Livewire-Seiten: Wizard (`DirectHire\Create`) und Übersicht (`DirectHire\Index`, Sidebar-Punkt mit Badge nach Inbox-Muster).

**Tech Stack:** Laravel (Modul `platforms-recruiting`), Livewire, PHPUnit (bestehende Minimal-Infrastruktur), CRM-Models (`CommsChannel`, `CommsProviderConnection`).

**Spec:** `docs/superpowers/specs/2026-06-12-direkteinstellung-feature-design.md`

**Erkenntnisse aus dem AutoPilot-Debugging (2026-06-15) — bereits eingearbeitet:**
- **Owner kommt automatisch:** Der Owner-Fix (`assignPosting` erbt den Verantwortlichen der Stelle, Commit 470af64) ist live. Der Wizard setzt `owned_by_user_id` der Stelle auf die gewählte Person → jeder eingehende Direkteinstellungs-Bewerber erbt sie automatisch. Kein separater Owner-Schritt nötig.
- **AutoPilot wirklich aus:** Die Stelle bekommt `auto_pilot_enabled = false` UND `auto_start_auto_pilot = false` (beide Hebel, die im Debugging die Ursache waren). Enrichment läuft trotzdem und füllt Kontaktdaten vor — es geht nur nichts an den Bewerber raus.
- **Eingangsweg:** Code ist Default; eigene Mail ist ein einzelnes optionales Feld (leer → Code). Keine Radio-Auswahl.

**Bewusste Abweichungen von der Spec:**
- **Owner-Benachrichtigung:** Kein User-Benachrichtigungsweg im Modul (nur WhatsApp an Bewerber). V1: Sidebar-Badge + „Nur meine"-Filter. Echte Benachrichtigung = Ausbaustufe (genau die Kundenfrage vom 2026-06-15 → eigener späterer Wurf über `platform-notifications`).
- **Wizard-Einstieg:** Sidebar-Punkt erscheint laut Spec erst ab 1 aktiver Direkteinstellungs-Stelle. Damit der Wizard auffindbar bleibt, bekommt die Stellen-Übersicht einen Button „Direkteinstellung anlegen".

**Konventionen (aus dem Matching-Wurf übernommen):** Absolute Pfade, Modul-Root `<MODUL>` = `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`. PHPUnit: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit` (aktuell 9 Tests grün — müssen grün bleiben). Blade-Regel: keine inline-`@if`/`??` in `x-ui-*`-Attributen, Werte in `@php` vorberechnen. Nur die genannten Dateien committen, nie untracked `docs/`.

---

### Task 1: Migration + Flag `is_direct_hire`

**Files:**
- Create: `<MODUL>/database/migrations/2026_06_13_000001_add_is_direct_hire_to_rec_positions.php`
- Modify: `<MODUL>/src/Models/RecPosition.php` (fillable, cast, Scopes)

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->boolean('is_direct_hire')->default(false)->after('is_active');
            $table->index(['team_id', 'is_direct_hire']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'is_direct_hire']);
            $table->dropColumn('is_direct_hire');
        });
    }
};
```

- [ ] **Step 2: Model erweitern**

In `RecPosition.php`: `'is_direct_hire'` in `$fillable` aufnehmen, Cast `'is_direct_hire' => 'boolean'` ergänzen (falls `$casts` fehlt, anlegen — vorher Datei lesen und Stil übernehmen), plus zwei Scopes:

```php
public function scopeDirectHire($query)
{
    return $query->where('is_direct_hire', true);
}

public function scopeNotDirectHire($query)
{
    return $query->where('is_direct_hire', false);
}
```

- [ ] **Step 3: Lint + Tests**

Run: `php -l <MODUL>/src/Models/RecPosition.php && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: keine Syntax-Fehler, 9 Tests grün

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_13_000001_add_is_direct_hire_to_rec_positions.php src/Models/RecPosition.php
git commit -m "feat(direkteinstellung): is_direct_hire-Flag an Stellen + Scopes"
```

---

### Task 2: RefCodeParser (TDD) + quellen-unabhängige Code-Stufe im Matching

**Files:**
- Create: `<MODUL>/src/Services/RefParsers/RefCodeParser.php`
- Modify: `<MODUL>/src/Services/RefParsers/RefParserRegistry.php` (Key `ref_code`)
- Modify: `<MODUL>/src/Services/ApplicationMatchingService.php` (Code-Stufe in `matchDeterministic`)
- Test: `<MODUL>/tests/Unit/RefParsers/RefCodeParserTest.php`

**Designentscheidung (wichtig):** Referenz-Codes kommen von beliebigen Absendern (Privat-Mail mit Code im Betreff) — die Quellen-Erkennung greift dort NICHT. Deshalb läuft der Code-Parser **immer**, unabhängig von der erkannten Quelle, als zusätzlicher deterministischer Schritt. Codes werden als `rec_posting_external_refs` unter einer Quelle mit `ref_parser = 'ref_code'` gespeichert (legt der Setup-Service in Task 3 an). Code-Format: `RG-` + 4 Zeichen aus `[A-HJ-NP-Z2-9]` (ohne verwechselbare I/O/0/1).

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/RefParsers/RefCodeParserTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

class RefCodeParserTest extends TestCase
{
    private RefCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RefCodeParser();
    }

    public function test_extracts_code_from_subject(): void
    {
        $this->assertSame('RG-K7M3', $this->parser->extract('Bewerbung RG-K7M3', null));
    }

    public function test_extracts_code_from_body_case_insensitive(): void
    {
        $this->assertSame('RG-K7M3', $this->parser->extract(null, "Hallo,\nich bewerbe mich. Code: rg-k7m3\nGruß"));
    }

    public function test_subject_wins_over_body(): void
    {
        $this->assertSame('RG-AAAA', $this->parser->extract('Re: RG-AAAA', 'anderer Code RG-BBBB im Text'));
    }

    public function test_returns_null_without_code(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung als Koch', 'kein Code enthalten'));
        $this->assertNull($this->parser->extract(null, null));
    }

    public function test_ignores_lookalike_words(): void
    {
        // "RG-TOOL" enthält O (nicht im Alphabet) und ist 4 Zeichen — O ist ausgeschlossen
        $this->assertNull($this->parser->extract('RG-TOOL eingesetzt', null));
    }

    public function test_generate_produces_valid_codes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $code = RefCodeParser::generate();
            $this->assertMatchesRegularExpression('/^RG-[A-HJ-NP-Z2-9]{4}$/', $code);
            $this->assertSame($code, $this->parser->extract("Betreff {$code}", null));
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: Errors `Class ... RefCodeParser not found`

- [ ] **Step 3: Implementierung** (reines PHP, KEINE Laravel-Imports — Unit-Tests laufen ohne Framework)

`src/Services/RefParsers/RefCodeParser.php`:

```php
<?php

namespace Platform\Recruiting\Services\RefParsers;

class RefCodeParser implements SourceRefParser
{
    /** Zeichensatz ohne verwechselbare I, O, 0, 1. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PATTERN = '/\bRG-([A-HJ-NP-Z2-9]{4})\b/i';

    public function extract(?string $subject, ?string $body): ?string
    {
        foreach ([$subject, $body] as $haystack) {
            if ($haystack && preg_match(self::PATTERN, $haystack, $m)) {
                return 'RG-' . strtoupper($m[1]);
            }
        }

        return null;
    }

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'RG-' . $code;
    }
}
```

In `RefParserRegistry.php` die Map erweitern:

```php
private const PARSERS = [
    'kleinanzeigen' => KleinanzeigenRefParser::class,
    'website_form' => WebsiteFormRefParser::class,
    'ref_code' => RefCodeParser::class,
];
```

ACHTUNG: `RefParserRegistryTest::test_keys_lists_all_parsers` erwartet aktuell `['kleinanzeigen', 'website_form']` — Test auf `['kleinanzeigen', 'website_form', 'ref_code']` aktualisieren (Erwartung folgt der Implementierung, das ist hier die korrekte Test-Anpassung).

- [ ] **Step 4: Code-Stufe im MatchingService**

In `ApplicationMatchingService::matchDeterministic()` — direkt NACH dem Dedizierter-Kanal-Block und VOR dem quellen-gebundenen Parser-Block einfügen:

```php
// Stufe 1b: Referenz-Code (quellen-unabhängig — Codes kommen von beliebigen Absendern)
if ($code = (new RefParsers\RefCodeParser())->extract($subject, $body)) {
    $posting = RecPostingExternalRef::query()
        ->where('team_id', $channel->team_id)
        ->where('external_ref', $code)
        ->whereHas('sourcePlatform', fn ($q) => $q->where('ref_parser', 'ref_code'))
        ->first()
        ?->posting;

    if ($posting && RecPosting::query()->open()->whereKey($posting->id)->exists()) {
        return new MatchResult($posting, MatchResult::VIA_EXTERNAL_REF);
    }
    if ($posting) {
        return new MatchResult(
            $posting,
            MatchResult::VIA_SUGGESTION,
            reason: 'Referenz-Code zeigt auf geschlossene Ausschreibung "' . $posting->title . '"',
        );
    }
    // Code ohne Treffer → normale Pipeline weiterlaufen lassen
}
```

(Import-Stil der Datei beachten: `RefParsers\RefCodeParser` als `use Platform\Recruiting\Services\RefParsers\RefCodeParser;` importieren und kurz referenzieren.)

- [ ] **Step 5: Tests laufen lassen — grün**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: alle Tests grün (9 alte + 6 neue = 15), inkl. angepasstem Registry-Test

- [ ] **Step 6: Lint + Commit**

Run: `php -l <MODUL>/src/Services/ApplicationMatchingService.php`

```bash
git add src/Services/RefParsers/ src/Services/ApplicationMatchingService.php tests/Unit/RefParsers/
git commit -m "feat(direkteinstellung): RefCodeParser (RG-XXXX) + quellen-unabhaengige Code-Stufe im Matching, TDD"
```

---

### Task 3: DirectHireSetupService

**Files:**
- Create: `<MODUL>/src/Services/DirectHireSetupService.php`

Der Service kapselt die komplette Wizard-Objekterzeugung in einer Transaktion. Vor der Implementierung VIER Pre-Checks am Bestandscode (Ergebnisse im Report dokumentieren):

1. **AutoPilot-Aus-Semantik:** `grep -n "auto_pilot_enabled\|auto_pilot_settings" <MODUL>/src/Console/Commands/ProcessAutoPilotApplicants.php <MODUL>/src/Models/RecPosition.php` — wie schaltet man AutoPilot pro Stelle ab? (Erwartung: `auto_pilot_settings['auto_pilot_enabled'] = false` überschreibt Team-Setting; exakten Key übernehmen. Falls es keinen Position-Level-Off-Schalter gibt: Bewerber werden ohnehin mit `auto_pilot = false` angelegt und `auto_start_auto_pilot` ist team-weit false — dann reicht das, im Report vermerken.)
2. **Phasen-Extra-Felder programmatisch:** Tool-Klasse für `recruiting.phase_extra_fields.POST` finden (`grep -rln "phase_extra_fields" <MODUL>/src/Tools/`) und deren Create-Logik lesen — dieselbe Logik (Definitions-Erzeugung am Phase-Model) im Service verwenden, NICHT duplizieren: wenn die Tool-Klasse eine wiederverwendbare Methode/einen Service nutzt, diesen aufrufen; sonst die minimale Definitions-Erzeugung exakt nachbauen.
3. **Kanal-Team-Semantik:** Bestehende Mail-Kanäle anschauen (`CommsChannel` des funktionierenden Eingangs, z.B. via Seed-Migration nachvollziehen): Liegt `team_id` auf dem Recruiting-Team oder einem Root-Team? Das CRM-`CreateChannelTool` speichert auf dem Root-Team (`ResolvesCommsRootTeam`) — der Service muss dieselbe Semantik treffen wie die existierenden, funktionierenden Kanäle, sonst greift das Intake-Gate nicht (`isIntakeChannel` prüft `channel->team_id`).
4. **Default-Phase-Hook:** `RecPosition::created` legt automatisch Phase „Bewerbung" (order 1) an — der Service muss die danach UPDATEN statt neu anlegen.

- [ ] **Step 1: Service implementieren**

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsProviderConnection;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

class DirectHireSetupService
{
    /**
     * Standard-Datenerfassungs-Felder (Phase 2). Namen bewusst identisch zu den
     * Onboarding-Feldern normaler Stellen, damit CRM-Sync und Vertragsvorlagen
     * (SyncApplicantExtraFieldsToCrm, Template-Resolver) sie auflösen können.
     *
     * @var array<int, array{name: string, label: string, type: string, required: bool}>
     */
    public const STANDARD_FIELDS = [
        // PFLICHT, nicht abwählbar: MA-Portal-Login verlangt Geburtsdatum +
        // letzte 4 Ziffern der Ausweisnummer (EmployeePortal::verify) —
        // ohne diese beiden Felder kann sich der neue MA nie anmelden.
        ['name' => 'geburtsdatum', 'label' => 'Geburtsdatum', 'type' => 'text', 'required' => true],
        ['name' => 'ausweisnummer', 'label' => 'Ausweisnummer', 'type' => 'text', 'required' => true],
        ['name' => 'strasse', 'label' => 'Straße', 'type' => 'text', 'required' => true],
        ['name' => 'hausnummer', 'label' => 'Hausnummer', 'type' => 'text', 'required' => true],
        ['name' => 'plz', 'label' => 'PLZ', 'type' => 'text', 'required' => true],
        ['name' => 'stadt', 'label' => 'Stadt', 'type' => 'text', 'required' => true],
        ['name' => 'geburtsort', 'label' => 'Geburtsort', 'type' => 'text', 'required' => true],
        ['name' => 'steuer_id', 'label' => 'Steuer-ID', 'type' => 'text', 'required' => false],
        ['name' => 'sozialversicherungsnummer', 'label' => 'Sozialversicherungsnummer', 'type' => 'text', 'required' => false],
        ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'required' => false],
        ['name' => 'krankenkasse', 'label' => 'Krankenkasse', 'type' => 'text', 'required' => false],
        ['name' => 'foto_ausweis_vorderseite', 'label' => 'Foto Ausweis Vorderseite', 'type' => 'file', 'required' => true],
        ['name' => 'foto_ausweis_ruckseite', 'label' => 'Foto Ausweis Rückseite', 'type' => 'file', 'required' => true],
    ];

    /**
     * Legt das komplette Direkteinstellungs-Set an.
     *
     * @param array{
     *     title: string,
     *     owner_user_id: int,
     *     team_id: int,
     *     created_by_user_id: int,
     *     intake_mode: 'mail'|'code',
     *     mail_prefix: ?string,
     *     fields: array<int, string>,  // Auswahl aus STANDARD_FIELDS-names
     * } $input
     * @return array{position: RecPosition, posting: RecPosting, ref_code: ?string, channel: ?CommsChannel}
     * @throws \RuntimeException bei nicht erfüllbaren Voraussetzungen (Domain/Connection/Mail vergeben)
     */
    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            // 1. Stelle (created-Hook legt Phase 1 "Bewerbung" automatisch an)
            $position = RecPosition::create([
                'title' => $input['title'],
                'team_id' => $input['team_id'],
                'created_by_user_id' => $input['created_by_user_id'],
                'owned_by_user_id' => $input['owner_user_id'],
                'is_active' => true,
                'is_direct_hire' => true,
                // Pre-Check 1: exakten Off-Key übernehmen bzw. weglassen, falls nicht nötig
                // Beide AutoPilot-Hebel hart aus (genau die zwei aus dem AutoPilot-Debugging
                // vom 2026-06-15): enabled=false blockt den Prozessor, auto_start=false
                // verhindert, dass das Enrichment AutoPilot selbst anschaltet. Enrichment
                // läuft trotzdem und füllt Kontaktdaten vor — nur es geht nichts an den Bewerber.
                'auto_pilot_settings' => ['auto_pilot_enabled' => false, 'auto_start_auto_pilot' => false],
            ]);

            // 2. Phase 1 auf "persönlich" umstellen (manuell abschließen, kein Auto-Advance)
            $phase1 = $position->phases()->where('order', 1)->firstOrFail();
            $phase1->update([
                'completion_type' => 'manual',
                'auto_advance' => false,
            ]);

            // 3. Phase 2 "Datenerfassung" mit MA-Anlage-Hook
            $phase2 = $position->phases()->create([
                'team_id' => $input['team_id'],
                'name' => 'Datenerfassung',
                'order' => 2,
                'is_active' => true,
                'auto_advance' => false,
                'completion_type' => 'fields',
                'completion_config' => ['creates_employee_on_completion' => true],
            ]);

            // 4. Gewählte Standard-Felder als Phase-2-Definitionen (Pre-Check 2: Tool-Logik nutzen)
            $selected = array_filter(
                self::STANDARD_FIELDS,
                fn (array $f) => in_array($f['name'], $input['fields'], true),
            );
            foreach ($selected as $field) {
                $this->createPhaseField($phase2, $field);
            }

            // 5. Ausschreibung
            $posting = RecPosting::create([
                'rec_position_id' => $position->id,
                'team_id' => $input['team_id'],
                'title' => $input['title'],
                'status' => 'published',
                'published_at' => now(),
                'is_active' => true,
                'created_by_user_id' => $input['created_by_user_id'],
            ]);

            // 6. Eingang
            $refCode = null;
            $channel = null;
            if ($input['intake_mode'] === 'code') {
                $refCode = $this->createRefCode($posting, $input['team_id']);
            } else {
                $channel = $this->createDedicatedChannel($posting, $input['mail_prefix'], $input['team_id'], $input['created_by_user_id']);
            }

            return [
                'position' => $position,
                'posting' => $posting,
                'ref_code' => $refCode,
                'channel' => $channel,
            ];
        });
    }

    /**
     * Erzeugt einen kollisionsfreien Referenz-Code als externe Referenz unter
     * der Team-Quelle "Referenz-Code" (ref_parser=ref_code, Pattern matcht nie).
     */
    private function createRefCode(RecPosting $posting, int $teamId): string
    {
        $source = RecSourcePlatform::firstOrCreate(
            ['team_id' => $teamId, 'name' => 'Referenz-Code'],
            [
                'match_pattern' => '@@referenz-code-niemals-absender@@',
                'ref_parser' => 'ref_code',
                'is_active' => true,
                'priority' => 999,
            ],
        );

        if ($source->ref_parser !== 'ref_code') {
            $source->update(['ref_parser' => 'ref_code']);
        }

        do {
            $code = RefCodeParser::generate();
        } while (RecPostingExternalRef::where('rec_source_platform_id', $source->id)->where('external_ref', $code)->exists());

        RecPostingExternalRef::create([
            'rec_posting_id' => $posting->id,
            'rec_source_platform_id' => $source->id,
            'external_ref' => $code,
            'team_id' => $teamId,
        ]);

        return $code;
    }

    /**
     * Legt den dedizierten Mail-Kanal an (Logik gespiegelt vom CRM CreateChannelTool:
     * Provider-Connection + Domain-Whitelist) und bindet ihn exklusiv an die Ausschreibung.
     *
     * BUILD-HINWEIS (vor Umsetzung verifizieren): Die Domain mitarbeiter.rheingedeck.de
     * läuft als Catch-all (jeder Prefix kommt an). Zu prüfen ist, OB eingehende Mails
     * pro Adresse auf einen eigenen CommsChannel gemappt werden (dann greift der dedizierte
     * Kanal als Stufe-1-Match) ODER ob alle Domain-Mails auf EINEN Eingangskanal laufen
     * (Beispiel-Verdacht: Kanal 2 fing website@/bewerber@ gemeinsam). Im zweiten Fall NICHT
     * über einen dedizierten Kanal matchen, sondern die Empfänger-Adresse (To-Header) als
     * externe Referenz registrieren und beim Inbound darüber zuordnen. Beides ist mit der
     * Matching-Pipeline machbar; den passenden Weg beim Bauen anhand des realen Inbound-Verhaltens wählen.
     *
     * @throws \RuntimeException wenn Connection/Domain fehlen oder die Adresse vergeben ist
     */
    private function createDedicatedChannel(RecPosting $posting, string $mailPrefix, int $teamId, int $userId): CommsChannel
    {
        // Pre-Check 3: Team-Semantik an existierenden funktionierenden Kanälen ausrichten.
        // Domain aus einem bestehenden aktiven Email-Kanal des Teams ableiten — KEINE Hardcodierung.
        $referenceChannel = CommsChannel::query()
            ->where('team_id', $teamId)
            ->where('type', 'email')
            ->where('is_active', true)
            ->whereNotNull('sender_identifier')
            ->first();

        if (!$referenceChannel) {
            throw new \RuntimeException('Kein bestehender E-Mail-Kanal im Team — Domain kann nicht abgeleitet werden.');
        }

        $domain = substr((string) $referenceChannel->sender_identifier, strrpos((string) $referenceChannel->sender_identifier, '@') + 1);
        $address = strtolower(trim($mailPrefix)) . '@' . $domain;

        $connection = CommsProviderConnection::forTeamProvider($referenceChannel->team, $referenceChannel->provider);
        if (!$connection) {
            throw new \RuntimeException('Keine aktive Provider-Connection für E-Mail-Kanäle gefunden.');
        }

        $exists = CommsChannel::query()
            ->where('team_id', $referenceChannel->team_id)
            ->where('type', 'email')
            ->where('sender_identifier', $address)
            ->exists();
        if ($exists) {
            throw new \RuntimeException("Die Adresse {$address} ist bereits vergeben.");
        }

        $channel = CommsChannel::create([
            'team_id' => $referenceChannel->team_id,
            'created_by_user_id' => $userId,
            'comms_provider_connection_id' => $connection->id,
            'type' => 'email',
            'provider' => $referenceChannel->provider,
            'name' => 'Direkteinstellung: ' . $posting->title,
            'sender_identifier' => $address,
            'visibility' => 'team',
            'is_active' => true,
            'meta' => [],
        ]);

        // Exklusive Bindung = dedizierter Kanal (Stufe 1 der Pipeline)
        $posting->commsChannels()->syncWithoutDetaching([$channel->id]);

        return $channel;
    }

    private function createPhaseField($phase, array $field): void
    {
        // Pre-Check 2: exakt die Create-Logik der phase_extra_fields-Tool-Klasse verwenden.
        // Erwartete Form (an realen Code anpassen!):
        // $phase->extraFieldDefinitions()->create([...name, label, type, is_required, order...]);
        throw new \LogicException('Pre-Check 2 ausführen und diese Methode mit der realen Definitions-Erzeugung füllen.');
    }
}
```

WICHTIG: `createPhaseField()` ist bewusst als LogicException-Platzhalter formuliert — der Implementer MUSS Pre-Check 2 ausführen und die Methode mit der echten Definitions-Erzeugung füllen (gleiche Validierung/Feldstruktur wie das Tool). Der Task ist erst fertig, wenn keine LogicException mehr im Code steht.

- [ ] **Step 2: Lint + Tests**

Run: `php -l <MODUL>/src/Services/DirectHireSetupService.php && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`
Expected: sauber, 15 Tests grün

- [ ] **Step 3: Commit**

```bash
git add src/Services/DirectHireSetupService.php
git commit -m "feat(direkteinstellung): Setup-Service (Stelle+Phasen+Posting+Eingang in einer Transaktion)"
```

---

### Task 4: MA-Anlage-Hook auch ohne AutoPilot (Portal-Submit)

**Files:**
- Modify: vom Pre-Check abhängig (vermutlich der Public-Portal-Submit-Handler)

**Problem:** `creates_employee_on_completion` wird über `checkAutoPilotCompletion()`/`triggerPhaseCompletionHooks()` ausgewertet — Direkteinstellungs-Bewerber haben AutoPilot aus. Wenn der Kandidat im Portal seine Daten vervollständigt, muss der Hook trotzdem feuern.

- [ ] **Step 1: Call-Sites ermitteln**

Run: `grep -rn "triggerPhaseCompletionHooks\|checkAutoPilotCompletion\|isPhaseComplete" <MODUL>/src --include="*.php" | grep -v "Models/RecApplicant.php"`

Den Public-Portal-Submit-Handler finden (`grep -rln "applicant-portal\|public_token" <MODUL>/src/Livewire/Public/`) und lesen: Wird nach dem Speichern der Portal-Daten irgendein Completion-Check ausgeführt?

- [ ] **Step 2: Hook ergänzen (falls fehlend)**

Im Portal-Submit-Handler nach dem Speichern der Extra-Felder ergänzen (Methodennamen existieren am Model: `isPhaseComplete()`, `triggerPhaseCompletionHooks()` — exakte Signaturen vorher in `RecApplicant.php` nachlesen und übernehmen):

```php
// Direkteinstellung & Co.: Phasen-Abschluss auch ohne AutoPilot auswerten
$applicant->refresh();
if ($applicant->rec_phase_id && $applicant->isPhaseComplete()) {
    $applicant->triggerPhaseCompletionHooks();
}
```

Falls der Portal-Submit den Check bereits macht (Step 1 zeigt es): nichts ändern, im Report dokumentieren, Task ist dann ein No-Op.

WICHTIG (Regression-Schutz): Der Check muss exakt die bestehende Semantik nutzen — KEINE eigene Vollständigkeitslogik bauen. Verhalten für normale AutoPilot-Bewerber darf sich nicht ändern (deren Hooks feuern weiterhin über den Processor; doppeltes Feuern muss idempotent sein — prüfen, ob `triggerPhaseCompletionHooks` bzw. `creates_employee_on_completion` gegen Doppel-Anlage geschützt ist, z.B. via bestehendem `RecEmployee`-Check. Wenn nicht: Guard `if (!$applicant->employee()->exists())` um die Hook-Auslösung legen, Benennung der Relation vorher verifizieren).

- [ ] **Step 3: Lint + Tests + Commit**

Run: `php -l <geänderte Datei> && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`

```bash
git add <geänderte Datei>
git commit -m "feat(direkteinstellung): Phasen-Abschluss-Hooks auch ohne AutoPilot beim Portal-Submit"
```

---

### Task 5: Wizard-UI (Livewire DirectHire\Create)

**Files:**
- Create: `<MODUL>/src/Livewire/DirectHire/Create.php`
- Create: `<MODUL>/resources/views/livewire/direct-hire/create.blade.php`
- Modify: `<MODUL>/routes/web.php` (Route)
- Modify: `<MODUL>/src/Livewire/Position/Index.php` + zugehörige View (Button „Direkteinstellung anlegen")

- [ ] **Step 1: Route registrieren** (Stil von `routes/web.php` übernehmen)

```php
Route::get('/direkteinstellungen/neu', \Platform\Recruiting\Livewire\DirectHire\Create::class)->name('recruiting.direct-hire.create');
```

- [ ] **Step 2: Component**

```php
<?php

namespace Platform\Recruiting\Livewire\DirectHire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Recruiting\Services\DirectHireSetupService;

class Create extends Component
{
    public string $title = '';
    // Eingangsweg: KEINE explizite Auswahl. Leeres Mail-Prefix → Code wird generiert.
    // Prefix eingegeben → eigene Mail-Adresse. (Code ist der Default.)
    public string $mailPrefix = '';
    public ?int $ownerUserId = null;
    /** @var array<int, string> ausgewählte Feld-Namen */
    public array $fields = [];

    /** Ergebnis nach Anlage (für die Bestätigungs-Ansicht) */
    public ?string $createdRefCode = null;
    public ?string $createdMailAddress = null;
    public ?int $createdPositionId = null;

    /** Nicht abwählbar — MA-Portal-Login braucht sie (siehe STANDARD_FIELDS-Kommentar). */
    public const LOCKED_FIELDS = ['geburtsdatum', 'ausweisnummer'];

    public function mount(): void
    {
        // Standard-Set vorbelegt (abwählbar, außer LOCKED_FIELDS)
        $this->fields = array_column(DirectHireSetupService::STANDARD_FIELDS, 'name');
        $this->ownerUserId = Auth::id();
    }

    public function create(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'mailPrefix' => 'nullable|string|max:64|regex:/^[a-z0-9][a-z0-9.\-]*$/i',
            'ownerUserId' => 'required|integer',
            'fields' => 'array',
        ]);

        // Code ist Default; nur wenn ein Prefix eingegeben wurde → eigene Mail-Adresse.
        $intakeMode = trim($this->mailPrefix) !== '' ? 'mail' : 'code';

        $teamId = (int) Auth::user()->currentTeam->id;

        // Owner muss Team-Mitglied sein
        $ownerValid = Auth::user()->currentTeam->users()->whereKey($this->ownerUserId)->exists()
            || (int) Auth::user()->currentTeam->user_id === (int) $this->ownerUserId;
        if (!$ownerValid) {
            $this->addError('ownerUserId', 'Ungültiger Benutzer.');
            return;
        }

        try {
            $result = app(DirectHireSetupService::class)->create([
                'title' => trim($this->title),
                'owner_user_id' => (int) $this->ownerUserId,
                'team_id' => $teamId,
                'created_by_user_id' => (int) Auth::id(),
                'intake_mode' => $intakeMode,
                'mail_prefix' => $intakeMode === 'mail' ? trim($this->mailPrefix) : null,
                'fields' => $this->fields,
            ]);
        } catch (\RuntimeException $e) {
            $this->addError('mailPrefix', $e->getMessage());
            return;
        }

        $this->createdRefCode = $result['ref_code'];
        $this->createdMailAddress = $result['channel']?->sender_identifier;
        $this->createdPositionId = $result['position']->id;
    }

    public function render()
    {
        return view('livewire.direct-hire.create')->layout(/* Layout-Aufruf wie bei Position\Index — vorher nachlesen */);
    }
}
```

Vorher `Position/Index.php` lesen: exakten `render()`/Layout-Stil, Team-Member-Abfrage-Muster (gibt es ein etabliertes `users`-Dropdown-Muster im Modul? z.B. Owner-Auswahl in bestehenden Views — `grep -rn "owned_by_user_id" <MODUL>/src/Livewire/ | head`) und übernehmen.

- [ ] **Step 3: View**

Inhalt (Markup-Idiom an bestehende Formulare des Moduls anlehnen, x-ui-Komponenten + @php-Regel):

1. Titel-Input („z. B. Serviceleiter Köln")
2. **Ein** optionales Feld „Eigene Bewerbungs-Mail (optional)" — Präfix-Input mit `@mitarbeiter.rheingedeck.de` als statischem Suffix daneben. **Leer lassen → es wird automatisch ein Referenz-Code erzeugt.** Hinweistext: „Leer lassen, dann erzeugen wir einen kurzen Code für die Anzeige." Keine Radio-Auswahl nötig.
3. Zuständiger User (Select über Team-Mitglieder)
4. Checkbox-Liste der Standard-Felder (`DirectHireSetupService::STANDARD_FIELDS`, vorbelegt angehakt)
5. Submit „Direkteinstellung anlegen"
6. **Erfolgs-Ansicht** (wenn `$createdPositionId` gesetzt): zeigt prominent den Referenz-Code bzw. die Mail-Adresse, dazu die fertigen Bewerbungs-Links zum Kopieren:

```blade
@php
    $waText = rawurlencode('Bewerbung ' . $createdRefCode);
    $mailtoSubject = rawurlencode('Bewerbung ' . $createdRefCode);
@endphp
{{-- bei Code-Variante: --}}
<p class="font-mono text-lg">{{ $createdRefCode }}</p>
<p class="text-sm">Mail-Link: <code>mailto:&lt;Sammeladresse&gt;?subject={{ $mailtoSubject }}</code></p>
<p class="text-xs text-[var(--ui-secondary)]">Den Code (oder diese vorbefüllten Links) in die Anzeige aufnehmen — Bewerbungen damit werden automatisch zugeordnet.</p>
```

(Die echte Sammeladresse für den mailto-Link aus den aktiven Intake-Kanälen des Teams ziehen: `RecIntakeChannel` mit `channel.type=email` → `sender_identifier`; wenn keiner existiert, Hinweistext statt Link.)

- [ ] **Step 4: Einstiegs-Button in der Stellen-Übersicht**

In `Position/Index.php`-View neben dem bestehenden „Stelle anlegen"-Button: sekundärer Button „Direkteinstellung anlegen" → `route('recruiting.direct-hire.create')` (wire:navigate, Stil der Nachbar-Buttons).

- [ ] **Step 5: Lint + Tests + Commit**

Run: `php -l <MODUL>/src/Livewire/DirectHire/Create.php && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`

```bash
git add src/Livewire/DirectHire/ resources/views/livewire/direct-hire/ routes/web.php src/Livewire/Position/Index.php resources/views/livewire/position/
git commit -m "feat(direkteinstellung): Wizard-UI mit Code-/Mail-Eingang und Standard-Feld-Auswahl"
```

---

### Task 6: Sidebar-Punkt + Übersichts-Seite (DirectHire\Index)

**Files:**
- Create: `<MODUL>/src/Livewire/DirectHire/Index.php`
- Create: `<MODUL>/resources/views/livewire/direct-hire/index.blade.php`
- Modify: `<MODUL>/routes/web.php` (Route `recruiting.direct-hire.index`)
- Modify: `<MODUL>/src/Livewire/Sidebar.php` + `resources/views/livewire/sidebar.blade.php`

- [ ] **Step 1: Route**

```php
Route::get('/direkteinstellungen', \Platform\Recruiting\Livewire\DirectHire\Index::class)->name('recruiting.direct-hire.index');
```

- [ ] **Step 2: Component** (Team-/Computed-Idiome an `Inbox/Index.php` ausrichten — vorher lesen)

```php
<?php

namespace Platform\Recruiting\Livewire\DirectHire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;

class Index extends Component
{
    public bool $onlyMine = false;

    #[Computed]
    public function positions()
    {
        $q = RecPosition::forTeam((int) Auth::user()->currentTeam->id)
            ->directHire()
            ->where('is_active', true)
            ->with(['phases' => fn ($q) => $q->where('is_active', true)->orderBy('order')])
            ->orderBy('title');

        if ($this->onlyMine) {
            $q->where('owned_by_user_id', Auth::id());
        }

        return $q->get();
    }

    /** Bewerber je Stelle, gruppiert — aktive, nicht geparkte. */
    #[Computed]
    public function applicantsByPosition(): array
    {
        $positionIds = $this->positions->pluck('id')->all();

        return RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->where('is_parked', false)
            ->whereHas('postings.position', fn ($q) => $q->whereIn('rec_positions.id', $positionIds))
            ->with(['crmContactLinks.contact.emailAddresses', 'crmContactLinks.contact.phoneNumbers', 'phase', 'postings.position'])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (RecApplicant $a) => $a->postings->first()?->position?->id)
            ->all();
    }

    public function startDataCollection(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $position = $applicant->postings->first()?->position;
        $phase2 = $position?->phases()->where('order', 2)->where('is_active', true)->first();
        if (!$phase2 || !$position?->is_direct_hire) {
            return;
        }

        $applicant->update(['rec_phase_id' => $phase2->id]);

        // Portal-Link senden (bestehender WhatsApp-Flow; Rückgabe ['ok' => bool, 'message' => string])
        $result = $applicant->sendContractPortalNotification();
        session()->flash('message', $result['ok']
            ? 'Datenerfassung gestartet — Portal-Link wurde gesendet.'
            : 'Datenerfassung gestartet. Portal-Link konnte nicht automatisch gesendet werden: ' . $result['message']);

        unset($this->applicantsByPosition);
    }

    public function parkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $applicant->update(['is_parked' => true, 'parked_at' => now()]);
        session()->flash('message', 'Bewerber geparkt.');
        unset($this->applicantsByPosition);
    }

    public function render()
    {
        return view('livewire.direct-hire.index')->layout(/* wie Inbox\Index */);
    }
}
```

Pre-Check: `sendContractPortalNotification()`-Rückgabeform in `RecApplicant.php:874` verifizieren (Erwartung `['ok' => bool, 'message' => string]`) und das Parken-Idiom mit bestehenden Park-Aktionen abgleichen (`grep -rn "is_parked" <MODUL>/src/Livewire/ | head` — exakt deren Feld-Set übernehmen, z.B. ob zusätzlich Status/Notes gesetzt werden).

- [ ] **Step 3: View**

Pro Stelle eine Karte/Sektion: Titel + Owner-Name + Eingang (Code bzw. Mail — Code via `externalRefs` der Posting laden, Mail via `commsChannels`), darunter die Bewerber-Tabelle: Name (CRM-Kontakt), Kontaktdaten, Eingangsdatum, aktuelle Phase, Aktionen:
- Phase 1: Button „Datenerfassung starten + Portal-Link" (`startDataCollection`) + Button „Parken" (`parkApplicant`) + Link „Öffnen" → `route('recruiting.applicants.show', $applicant->id)`
- Phase 2: Fortschritts-Hinweis (`{{ $applicant->progress }}%`) + Link „Öffnen"

Toggle „Nur meine" (`wire:model.live="onlyMine"`). Leerer Zustand: Hinweis + Button zum Wizard (`recruiting.direct-hire.create`). Bewusst simpel — kein Phasen-Board.

- [ ] **Step 4: Sidebar**

In `Sidebar.php` die Stats um zwei Werte erweitern (Muster der bestehenden Stats übernehmen — Datei vorher lesen):

```php
'direct_hire_positions' => RecPosition::forTeam($teamId)->directHire()->where('is_active', true)->count(),
'direct_hire_new' => RecApplicant::forTeam($teamId)
    ->where('is_active', true)->where('is_parked', false)
    ->whereHas('phase', fn ($q) => $q->where('order', 1))
    ->whereHas('postings.position', fn ($q) => $q->where('is_direct_hire', true)->where('is_active', true))
    ->count(),
```

In `sidebar.blade.php` in der Gruppe „Übersicht" nach der Eingangs-Inbox (Muster Zeilen 35-43 exakt kopieren):

```blade
@if(($this->stats['direct_hire_positions'] ?? 0) > 0)
    <x-ui-sidebar-item :href="route('recruiting.direct-hire.index')">
        @svg('heroicon-o-user-plus', 'w-4 h-4')
        <span class="ml-2 text-sm">Direkteinstellungen</span>
        @if(($this->stats['direct_hire_new'] ?? 0) > 0)
            <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-600">{{ $this->stats['direct_hire_new'] }}</span>
        @endif
    </x-ui-sidebar-item>
@endif
```

- [ ] **Step 5: Lint + Tests + Commit**

Run: `php -l <MODUL>/src/Livewire/DirectHire/Index.php && php -l <MODUL>/src/Livewire/Sidebar.php && cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit`

```bash
git add src/Livewire/DirectHire/ resources/views/livewire/direct-hire/ routes/web.php src/Livewire/Sidebar.php resources/views/livewire/sidebar.blade.php
git commit -m "feat(direkteinstellung): Sidebar-Punkt mit Badge + Uebersichts-Seite (Portal-Link, Parken, Nur-meine)"
```

---

### Task 7: KPI-/Listen-Abgrenzung + Abschluss-Verifikation

**Files:**
- Modify: `<MODUL>/src/Livewire/Dashboard/Dashboard.php` (`modeScopedPositionIds()`)
- Modify: Bewerber-/Stellen-Listen-Views (Badge)

- [ ] **Step 1: Dashboard-Ausschluss**

In `Dashboard::modeScopedPositionIds()` (Zeilen 53-67) beide Mode-Zweige um den Ausschluss ergänzen — Direkteinstellungs-Stellen gehören weder ins Prod- noch ins Legacy-Dashboard:

```php
$q = RecPosition::forTeam(auth()->user()->currentTeam->id)->notDirectHire();
```

(Cache-Key bleibt, TTL 30s reicht. Prüfen, ob `DashboardLegacy` dieselbe Methode erbt — wenn ja, ist ein Edit genug.)

- [ ] **Step 2: Badge in Listen**

In der Stellen-Übersicht (`Position/Index`-View) und der Bewerber-Liste (`Applicant/Index`-View) ein kleines Badge „Direkteinstellung" an betroffenen Zeilen (Bewerber: wenn `postings->first()?->position?->is_direct_hire`; vorher in den Components prüfen, dass `postings.position` eager-geladen wird — sonst ergänzen). Markup-Idiom: bestehende Badges in denselben Views kopieren. `@php`-Vorberechnung beachten.

- [ ] **Step 3: Finale Verifikation**

Run: `cd <MODUL> && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit && git diff --name-only main...HEAD -- '*.php' | xargs -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"`
Expected: 15 Tests grün, ALL CLEAN

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Dashboard/Dashboard.php resources/views/livewire/
git commit -m "feat(direkteinstellung): Dashboard-KPI-Ausschluss + Direkteinstellung-Badges in Listen"
```

- [ ] **Step 5: Manuelle E2E-Checkliste (nach Deploy)**

1. Wizard öffnen (Stellen-Übersicht → „Direkteinstellung anlegen"), Variante **Referenz-Code**: Stelle entsteht mit 2 Phasen (Bewerbung: manual, Datenerfassung: fields + creates_employee), AutoPilot aus, Code wird angezeigt.
2. Sidebar-Punkt „Direkteinstellungen" erscheint mit der neuen Stelle.
3. Testmail an die Sammeladresse mit `Bewerbung RG-XXXX` im Betreff → Bewerber landet automatisch an der Direkteinstellungs-Ausschreibung (`matched_via=external_ref`), Badge zählt hoch, KEINE automatischen Nachrichten an den Bewerber.
4. Basis-Enrichment füllt Kontaktdaten (Name/Telefon), verschickt nichts.
5. „Datenerfassung starten" → Phase 2 gesetzt, Portal-Link versendet (bzw. Hinweis, falls WA nicht konfiguriert).
6. Portal ausfüllen (alle Pflichtfelder) → MA-Datensatz entsteht automatisch.
7. Wizard Variante **eigene Mail**: Kanal entsteht (Adresse prüfen!), Testmail direkt an die neue Adresse → Bewerber via `dedicated_channel`. **Postmark-Inbound-Routing für die neue Adresse verifizieren** — falls Mails nicht ankommen, ist das Inbound-Routing (Catch-all) das Thema, nicht der Code.
8. Dashboard: Direkteinstellungs-Bewerber tauchen NICHT in den Funnel-KPIs auf; Bewerber-Liste zeigt das Badge.
9. Nach Deploy: `php artisan queue:restart` + meingedeck composer.lock bumpen.

---

## Offene Punkte für spätere Würfe (bewusst NICHT in diesem Plan)

- Owner-Benachrichtigung bei Neueingang (kein bestehender User-Notification-Weg im Modul — Ausbaustufe, ggf. via platform-notifications).
- Vertraulichkeit (nur Owner + Admins sehen Direkteinstellungs-Bewerber) — Spec §6, Ausbaustufe.
- `ref_parser`-Dropdown in den Eingangs-Quellen-Settings (Kleinanzeigen/Webseite manuell aktivierbar machen).
- Wizard-Bearbeitung bestehender Direkteinstellungen (Owner wechseln, Felder ändern).
