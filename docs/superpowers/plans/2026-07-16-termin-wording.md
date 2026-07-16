# Termin-Wording aus Gesprächsart — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bewerber-Seiten sagen statt gemischtem „Termin/Interview/Schulung" einheitlich das Wort der Gesprächsart des gebuchten Termins (z. B. „Vorstellungsrunde"), grammatisch korrekt über ein Genus-Feld, mit Fallback „Termin".

**Architecture:** Pure Value-Object `TerminWort` (Name + Genus → Satzformen) wird aus `RecInterviewBooking → interview → interviewType` gespeist. Die Booking-Livewire-Komponente exponiert fertige, registergerechte Sätze als public Properties (du/Sie-Ternary wandert für diese Stellen ins PHP); das Completion-Partial bekommt den fertigen Satz von `RecApplicant::renderPublicFormCompletionExtras()`. Genus wird einmal pro Gesprächsart in der bestehenden UI gepflegt.

**Tech Stack:** Laravel/Livewire (Modul platforms-recruiting), reines PHPUnit ohne Laravel/DB für Unit-Tests.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-16-termin-wording-design.md` — bei Widerspruch gewinnt die Spec.
- Tests laufen NUR über `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` (Modul hat kein eigenes vendor/). Nur pure Unit-Tests — kein Laravel-Boot, keine DB, keine Eloquent-Instanzen in Tests.
- Kein Core-Touch: ausschließlich Dateien unter `modules/platforms-recruiting`.
- Alle Bewerber-Texte deutsch, jede Satzform in du- UND Sie-Variante (`$duzen`-Muster aus dem Anrede-Feature, Commit 1b28431).
- Fallback ist IMMER das komplette Paar „Termin" + maskulin — nie Custom-Name mit Fallback-Artikel mischen.
- `TerminWort`-API ist nach Task A eingefroren — Tasks B–D bauen exakt dagegen.
- Kein `gh` CLI; Commits auf dem Feature-Branch, Merge/Push/Bump macht der Orchestrator nach Review.

## Frozen API (Task A produziert, C+D konsumieren)

```php
namespace Platform\Recruiting\Support;

final class TerminWort
{
    public static function fromParts(?string $name, ?string $genus): self;
    public static function from(?\Platform\Recruiting\Models\RecInterviewType $type): self; // dünner Wrapper: fromParts($type?->name, $type?->genus)
    public function nominativ(): string;            // "Vorstellungsrunde" | "Termin"
    public function akkusativMitArtikel(): string;  // "die Vorstellungsrunde" | "das Einzelgespräch" | "den Termin"
    public function possessiv(bool $duzen): string; // "deine Vorstellungsrunde" | "dein Einzelgespräch" | "Ihr Termin" (kleines d, großes I; satzinitial ucfirst() beim Aufrufer)
}
```

---

### Task A: `TerminWort` Value Object + Unit-Tests (API einfrieren)

**Files:**
- Create: `src/Support/TerminWort.php`
- Test: `tests/Unit/TerminWortTest.php`

**Interfaces:**
- Consumes: nichts (pure PHP).
- Produces: die Frozen API oben. `fromParts` normalisiert: Name getrimmt; Genus getrimmt + lowercased; leerer Name ODER Genus ∉ {m,f,n} → Fallback-Paar („Termin", m).

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TerminWort;

class TerminWortTest extends TestCase
{
    public function test_femininum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Vorstellungsrunde', 'f');
        $this->assertSame('Vorstellungsrunde', $w->nominativ());
        $this->assertSame('die Vorstellungsrunde', $w->akkusativMitArtikel());
        $this->assertSame('deine Vorstellungsrunde', $w->possessiv(true));
        $this->assertSame('Ihre Vorstellungsrunde', $w->possessiv(false));
    }

    public function test_neutrum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Einzelgespräch', 'n');
        $this->assertSame('Einzelgespräch', $w->nominativ());
        $this->assertSame('das Einzelgespräch', $w->akkusativMitArtikel());
        $this->assertSame('dein Einzelgespräch', $w->possessiv(true));
        $this->assertSame('Ihr Einzelgespräch', $w->possessiv(false));
    }

    public function test_maskulinum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Rundgang', 'm');
        $this->assertSame('Rundgang', $w->nominativ());
        $this->assertSame('den Rundgang', $w->akkusativMitArtikel());
        $this->assertSame('dein Rundgang', $w->possessiv(true));
        $this->assertSame('Ihr Rundgang', $w->possessiv(false));
    }

    public function test_fallback_ist_immer_das_komplette_paar_termin_maskulin(): void
    {
        // Nie Custom-Name mit Fallback-Artikel mischen: fehlt eine Hälfte, fällt ALLES zurück.
        foreach ([
            [null, null],
            [null, 'f'],
            ['', 'f'],
            ['   ', 'f'],
            ['Vorstellungsrunde', null],
            ['Vorstellungsrunde', ''],
            ['Vorstellungsrunde', 'x'],
        ] as [$name, $genus]) {
            $w = TerminWort::fromParts($name, $genus);
            $this->assertSame('Termin', $w->nominativ(), "name=" . var_export($name, true) . " genus=" . var_export($genus, true));
            $this->assertSame('den Termin', $w->akkusativMitArtikel());
            $this->assertSame('dein Termin', $w->possessiv(true));
            $this->assertSame('Ihr Termin', $w->possessiv(false));
        }
    }

    public function test_normalisierung_trim_und_case(): void
    {
        $w = TerminWort::fromParts('  Vorstellungsrunde  ', ' F ');
        $this->assertSame('Vorstellungsrunde', $w->nominativ());
        $this->assertSame('deine Vorstellungsrunde', $w->possessiv(true));
    }
}
```

- [ ] **Step 2: Test laufen lassen, muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TerminWortTest`
Expected: Error „Class ... TerminWort not found".

- [ ] **Step 3: Implementierung**

```php
<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecInterviewType;

/**
 * Bewerber-Wording eines Termins aus der Gesprächsart (Name + Genus).
 *
 * Fallback ist IMMER das komplette Paar "Termin"/maskulin — nie ein
 * Custom-Name mit Fallback-Artikel gemischt (falscher Artikel wäre
 * schlimmer als generisch). possessiv() liefert satzmittig ("deine …",
 * "Ihr …"); satzinitial beim Aufrufer mit ucfirst().
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class TerminWort
{
    private function __construct(
        private readonly string $name,
        private readonly string $genus, // 'm' | 'f' | 'n'
    ) {
    }

    public static function fromParts(?string $name, ?string $genus): self
    {
        $name = trim((string) $name);
        $genus = strtolower(trim((string) $genus));
        if ($name === '' || !in_array($genus, ['m', 'f', 'n'], true)) {
            return new self('Termin', 'm');
        }
        return new self($name, $genus);
    }

    public static function from(?RecInterviewType $type): self
    {
        return self::fromParts($type?->name, $type?->genus);
    }

    public function nominativ(): string
    {
        return $this->name;
    }

    public function akkusativMitArtikel(): string
    {
        return match ($this->genus) {
            'f' => 'die ' . $this->name,
            'n' => 'das ' . $this->name,
            default => 'den ' . $this->name,
        };
    }

    public function possessiv(bool $duzen): string
    {
        $pronomen = $this->genus === 'f'
            ? ($duzen ? 'deine' : 'Ihre')
            : ($duzen ? 'dein' : 'Ihr');
        return $pronomen . ' ' . $this->name;
    }
}
```

- [ ] **Step 4: Tests laufen lassen, müssen grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TerminWortTest`
Expected: OK (5 tests). Danach kompletter Lauf: `... -c phpunit.xml` → alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/Support/TerminWort.php tests/Unit/TerminWortTest.php
git commit -m "feat(recruiting): TerminWort — Bewerber-Wording aus Gespraechsart (pure, Fallback Termin)"
```

---

### Task B: Migration `genus` + Gesprächsarten-UI

**Files:**
- Create: `database/migrations/2026_07_16_000002_add_genus_to_rec_interview_types.php`
- Modify: `src/Models/RecInterviewType.php` (nur `$fillable`)
- Modify: `src/Livewire/InterviewTypes/Index.php`
- Modify: das Blade der Komponente (via `grep -rn "interview-types" resources/views -l` finden; erwarteter Pfad `resources/views/livewire/interview-types/index.blade.php`)

**Interfaces:**
- Consumes: nichts aus Task A (bewusst entkoppelt — nur die Spalte, die `TerminWort::from()` per `$type?->genus` liest).
- Produces: nullable Spalte `genus` (string(1), Werte 'm'|'f'|'n'|null) auf `rec_interview_types`; `genus` in `$fillable`.

Kein Unit-Test möglich (DB/Livewire — Modul-Konvention erlaubt nur pure Tests); Verifikation = `php -l` + Sichtprüfung nach Deploy.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_types', function (Blueprint $table) {
            // Genus des Namens fuer Bewerber-Saetze (der/die/das).
            // Nullable ohne Default: fehlt es, faellt das Wording im Code
            // komplett auf "Termin" zurueck (TerminWort::fromParts).
            $table->string('genus', 1)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_types', function (Blueprint $table) {
            $table->dropColumn('genus');
        });
    }
};
```

Vorher per `ls database/migrations | tail -3` prüfen, dass der Timestamp-Prefix `2026_07_16_000002` noch frei ist (000001 ist am 16.07. schon vergeben: `add_armed_to_rec_interview_waitlist`); sonst hochzählen.

- [ ] **Step 2: `genus` in `$fillable` von `RecInterviewType`**

In `src/Models/RecInterviewType.php` im `$fillable`-Array nach `'name',` einfügen:

```php
        'name',
        'genus',
        'code',
```

- [ ] **Step 3: Livewire-Komponente `InterviewTypes/Index` erweitern**

Vier Stellen (Einzel-Property-Stil der Datei beibehalten):

```php
    // bei den Properties (nach public $name = ''):
    public $genus = null;

    // in protected $rules ergänzen:
    'genus' => 'nullable|in:m,f,n',

    // in openEditModal() nach $this->name = $m->name;:
    $this->genus = $m->genus;

    // in save() im $data-Array nach 'name' => $this->name,:
    'genus' => $this->genus ?: null,
```

Und in `resetForm()` das Feld zurücksetzen (`$this->genus = null;` — bestehende Reset-Zeilen als Muster nehmen).

- [ ] **Step 4: Dropdown im Blade (Create- UND Edit-Modal)**

Blade der Komponente öffnen; direkt unter dem Name-Feld in beiden Modal-Formularen (bzw. dem geteilten Form-Partial, falls es eins gibt) einfügen — Markup/Klassen an die umliegenden Felder angleichen:

```blade
<div>
    <label class="block text-sm font-medium mb-1">Artikel (für Bewerber-Texte)</label>
    <select wire:model="genus" class="{{-- Klassen vom Nachbar-Select/Input übernehmen --}}">
        <option value="">— nicht gesetzt (Fallback „Termin") —</option>
        <option value="m">der (maskulin)</option>
        <option value="f">die (feminin, z. B. Vorstellungsrunde)</option>
        <option value="n">das (neutrum, z. B. Einzelgespräch)</option>
    </select>
</div>
```

(Kein `x-ui-input-select` mit berechneten Attributen nötig; falls das Blade durchgängig x-ui-Komponenten nutzt, Optionen vorher in einem `@php ... @endphp`-BLOCK vorberechnen — nie inline, bekannter Pitfall.)
Zusätzlich in der Listen-Tabelle der Arten eine schmale Spalte „Artikel" mit `{{ ['m' => 'der', 'f' => 'die', 'n' => 'das'][$type->genus] ?? '—' }}`, damit fehlende Pflege auffällt.

- [ ] **Step 5: Verifikation + Commit**

Run: `php -l src/Livewire/InterviewTypes/Index.php && php -l database/migrations/2026_07_16_000002_add_genus_to_rec_interview_types.php && php -l src/Models/RecInterviewType.php`
Expected: 3× „No syntax errors".

```bash
git add database/migrations/2026_07_16_000002_add_genus_to_rec_interview_types.php src/Models/RecInterviewType.php src/Livewire/InterviewTypes/Index.php resources/views/livewire/interview-types/
git commit -m "feat(recruiting): Genus-Feld an Gespraechsart (Migration + Verwaltungs-UI)"
```

---

### Task C: Booking-Komponente + Blade auf Satz-Properties umstellen

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php`
- Modify: `resources/views/livewire/public/interview-booking.blade.php`

**Interfaces:**
- Consumes: `TerminWort::from(?RecInterviewType): TerminWort` mit `nominativ()`, `akkusativMitArtikel()`, `possessiv(bool $duzen)` (Task A, eingefroren). Bestehendes `public bool $duzen` (gesetzt in `mount()` Z. 63).
- Produces: vier public String-Properties fürs Blade: `$terminGebuchtTitel`, `$terminGebuchtSatz`, `$terminAbsagenLabel`, `$terminAbsagenConfirm`.

- [ ] **Step 1: Import + Properties + Refresh-Methode in der Komponente**

Import ergänzen: `use Platform\Recruiting\Support\TerminWort;`

Properties (nach `public bool $duzen = false;`, Defaults = Sie/Fallback, werden im mount überschrieben):

```php
    /** Fertige, registergerechte Termin-Sätze (Wort aus der Gesprächsart,
     *  du/Sie bereits aufgelöst — bewusst im PHP statt Blade-Ternary,
     *  wegen satzinitialem Casing und JS-Attribut-Escaping). */
    public string $terminGebuchtTitel = 'Termin gebucht!';
    public string $terminGebuchtSatz = 'Ihr Termin wurde erfolgreich gebucht.';
    public string $terminAbsagenLabel = 'Termin absagen';
    public string $terminAbsagenConfirm = 'Möchten Sie den Termin wirklich absagen? Sie werden danach von unserem HR-Team kontaktiert.';
```

Private Methode (ans Ende der Komponente, vor `render()`):

```php
    /**
     * Berechnet die Termin-Sätze aus der Gesprächsart des übergebenen
     * Interviews. null (keine Buchung, z.B. Auswahl-State) → Fallback
     * "Termin". Wird bei jedem State-Wechsel mit dem dann gültigen
     * Interview aufgerufen — nie im Blade abgeleitet.
     */
    private function refreshTerminWording(?RecInterview $interview): void
    {
        $wort = TerminWort::from($interview?->interviewType);

        $this->terminGebuchtTitel = $wort->nominativ() . ' gebucht!';
        $this->terminGebuchtSatz = ucfirst($wort->possessiv($this->duzen)) . ' wurde erfolgreich gebucht.';
        $this->terminAbsagenLabel = $wort->nominativ() . ' absagen';
        $this->terminAbsagenConfirm = $this->duzen
            ? 'Möchtest du ' . $wort->akkusativMitArtikel() . ' wirklich absagen? Du wirst danach von unserem HR-Team kontaktiert.'
            : 'Möchten Sie ' . $wort->akkusativMitArtikel() . ' wirklich absagen? Sie werden danach von unserem HR-Team kontaktiert.';
    }
```

- [ ] **Step 2: Refresh an den vier State-Übergängen aufrufen**

1. `mount()` — nach `$this->duzen = ...` (Z. 63) und vor dem State-Set (Z. 69):
   ```php
   $this->refreshTerminWording($this->existingBooking?->interview);
   ```
2. `bookInterview()` — direkt vor `$this->state = 'booked';` (Z. ~298); das gebuchte Interview liegt dort als lokale Variable vor (Namen im Code prüfen, vermutlich `$interview`):
   ```php
   $this->refreshTerminWording($interview);
   ```
3. `cancelAndRebook()` — direkt vor `$this->state = 'selection';` (Z. ~490):
   ```php
   $this->refreshTerminWording(null);
   ```
4. `cancelSchulung()` — kein Aufruf nötig (Cancelled-State nutzt keins der Wörter), NICHTS ändern.

- [ ] **Step 3: Blade auf die Properties umstellen**

Vier Ersetzungen in `resources/views/livewire/public/interview-booking.blade.php`:

1. Z. ~345: `<h1 ...>Termin gebucht!</h1>` → `<h1 ...>{{ $terminGebuchtTitel }}</h1>`
2. Z. ~346: das komplette `{{ $duzen ? 'Dein Interview-Termin wurde erfolgreich gebucht.' : 'Ihr Interview-Termin wurde erfolgreich gebucht.' }}` → `{{ $terminGebuchtSatz }}`
3. Z. ~322 und ~402 (beide Buttons): `<span wire:loading.remove wire:target="cancelSchulung">Schulung absagen</span>` → `<span wire:loading.remove wire:target="cancelSchulung">{{ $terminAbsagenLabel }}</span>`
4. Z. ~318 und ~398 (beide Confirms): das gesamte `@click="if (confirm('{{ $duzen ? '…' : '…' }}')) $wire.cancelSchulung()"` ersetzen durch:
   ```blade
   @click="if (confirm(@js($terminAbsagenConfirm))) $wire.cancelSchulung()"
   ```
   (`@js` erzeugt einen korrekt gequoteten JS-String — Apostroph-sicher bei freien Type-Namen.)

**Unverändert lassen:** Header „Termin auswählen", Warteliste-Texte/-Confirm (Z. ~216), Cancelled-State (Z. ~420f), „Umbuchen"-Button.

- [ ] **Step 4: Verifikation + Commit**

Run: `php -l src/Livewire/Public/InterviewBooking.php` → „No syntax errors".
Run: `grep -n "Schulung" resources/views/livewire/public/interview-booking.blade.php` → erwartete Treffer: nur noch Kommentare (`{{-- Schulung absagen ... --}}`), keine sichtbaren Texte.
Run: kompletter Testlauf `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` → grün.

```bash
git add src/Livewire/Public/InterviewBooking.php resources/views/livewire/public/interview-booking.blade.php
git commit -m "feat(recruiting): Booking-Seite spricht das Wort der Gespraechsart (Satz-Properties)"
```

---

### Task D: Completion-Partial über RecApplicant verdrahten

**Files:**
- Modify: `src/Models/RecApplicant.php:667-684` (`renderPublicFormCompletionExtras`)
- Modify: `resources/views/partials/public-form-completion.blade.php`

**Interfaces:**
- Consumes: `TerminWort::from()` / `possessiv(bool)` (Task A). Bestehende Übergabe `'duzen' => $this->usesInformalAddress()`.
- Produces: neuer View-Parameter `bestaetigtSatz` (string) fürs Partial.

- [ ] **Step 1: `renderPublicFormCompletionExtras` erweitern**

Die Methode returnt früher bei `!$booking?->interview` — am View-Aufruf ist `interview` garantiert, nur `interviewType` kann null sein (Fallback via `TerminWort::from`). Den bestehenden `return view(...)` ersetzen durch:

```php
        $duzen = $this->usesInformalAddress();
        $wort = \Platform\Recruiting\Support\TerminWort::from($booking->interview->interviewType);

        return view('recruiting::partials.public-form-completion', [
            'interview'      => $booking->interview,
            'booking'        => $booking,
            'duzen'          => $duzen,
            'bestaetigtSatz' => ucfirst($wort->possessiv($duzen)) . ' ist bestätigt!',
        ])->render();
```

(`ucfirst` weil satzinitial — heute steht dort großes „Deine"; `possessiv()` liefert bewusst klein.)

- [ ] **Step 2: Partial umstellen**

In `resources/views/partials/public-form-completion.blade.php`:

1. Die h3-Zeile `{{ $duzen ? 'Deine Schulung ist bestätigt!' : 'Ihre Schulung ist bestätigt!' }}` → `{{ $bestaetigtSatz }}`
2. Die Info-Zeile `{{ $duzen ? 'Weitere Infos zur Schulung findest du hier:' : 'Weitere Infos zur Schulung finden Sie hier:' }}` → `{{ $duzen ? 'Weitere Infos findest du hier:' : 'Weitere Infos finden Sie hier:' }}` („zur Schulung" entfällt — Präpositions-Formen liefert die Wort-Klasse bewusst nicht)
3. Button-Text/Link `rheingedeck.de/schulung` NICHT anfassen (ist die URL selbst).

- [ ] **Step 3: Verifikation + Commit**

Run: `php -l src/Models/RecApplicant.php` → „No syntax errors".
Run: `grep -n "Schulung" resources/views/partials/public-form-completion.blade.php` → nur noch der Link-Button/URL und ggf. Kommentar, kein Anrede-Satz.
Run: kompletter Testlauf → grün.

```bash
git add src/Models/RecApplicant.php resources/views/partials/public-form-completion.blade.php
git commit -m "feat(recruiting): Bestaetigungs-Snippet nutzt Gespraechsart-Wording"
```

---

### Task E: End-Audit

**Files:** keine neuen — nur Verifikation.

- [ ] **Step 1: Wording-Audit über alle Public-Views**

Run: `grep -rn "Schulung\|Interview-Termin" resources/views/livewire/public resources/views/partials src/Livewire/Public src/Models/RecApplicant.php`
Expected: keine bewerber-sichtbaren Treffer mehr außer (a) Blade-/PHP-Kommentaren, (b) der URL `rheingedeck.de/schulung`, (c) internen Methoden-/Variablennamen (`cancelSchulung`, `getSchulungUrl` — bewusst nicht umbenannt, reines Wording-Feature).

- [ ] **Step 2: Kompletter Testlauf + php -l über alle angefassten PHP-Dateien**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: OK, 191 Tests (186 Bestand + 5 neue).

- [ ] **Step 3: Kein Commit** — Merge auf main, Push und meingedeck-Lock-Bump macht der Orchestrator nach User-Review (Rollout-Reihenfolge siehe Spec: Migration läuft beim Forge-Deploy, danach Genus für die zwei Bestandsarten in der UI setzen).
