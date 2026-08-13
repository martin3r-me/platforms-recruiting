# Zuschlag als Datenfeld — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Zuschlag wird ein echtes Datenfeld (`rec_applicants.zuschlag`), das in der Schulungsnachbereitung eingetragen wird, sich in die (vom User gebaute) generische AV-Vorlage zieht, im ZAS-Export mitgeht und am Mitarbeiter read-only sichtbar ist — statt aus dem AV-Template-Code geparst zu werden.

**Architecture:** Neue Decimal-Spalte auf `rec_applicants` als Single Source. Eingabe in `InterviewBookings/Index` (Schulungsnachbereitung). Vertrags-Platzhalter `{{zuschlag}}` zieht den Wert über `field_mappings: zuschlag → applicant.zuschlag`; `resolveSource` formatiert ihn deutsch. Universeller Versand-Stopp im `SendContractsService` (deckt UI + MCP-Tool). ZAS-Resolver: Feld wenn gesetzt, sonst Code-Fallback (`AV-NNN`) für Bestand. Sauberer Cut: alte AV-NNN werden nicht mehr für neue Vergaben genutzt.

**Tech Stack:** Laravel, Livewire, MySQL. Konventionen: anonyme Migration-Klasse, `decimal:2`-Cast. **Kein Modul-Test-Harness** → Verifikation per `php -l` + manuell im Host (rheingedeck) via UI/MCP, wie bei den letzten Features.

---

## Referenzen (bestehender Code)

- Eingabe-View: `src/Livewire/InterviewBookings/Index.php` — `setApplicantContractTemplate()` (Z.302), `setContractDate()` (Z.338), `sendContractsBulk()` (Z.356), `bulkSendState()` (Z.618), `isLegalStatusUnchecked()` (Z.592). Blade: `resources/views/livewire/interview-bookings/index.blade.php` — Vertragsvorlage-`<td>` (Z.281–304).
- Versand: `src/Services/SendContractsService.php` — `send()` (Z.46), wirft heute schon bei fehlender `contract_template_id` (Z.48). Tool: `src/Tools/SendContractsTool.php` (geht über denselben Service).
- Platzhalter: `src/Models/RecContractTemplate.php` — `resolveSource()` (`applicant.`-Zweig gibt rohen Spaltenwert unformatiert zurück).
- ZAS: `src/Services/Zas/ZasFieldResolver.php::getZuschlag()` (Z.~340), `ZasEmployeeFieldResolver.php::getZuschlag()` (Z.~465), `parseZuschlagFromCode()`.
- Mitarbeiter: `resources/views/livewire/employees/show.blade.php` — sensible/HR-Sektion (amber, ~Z.184).

---

## File Structure

**Geändert:**
- `database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php` *(neu)* — Spalte.
- `src/Models/RecApplicant.php` — `zuschlag` in `$fillable` + `$casts`.
- `src/Models/RecContractTemplate.php` — `resolveSource` deutsche Formatierung für `applicant.zuschlag`.
- `src/Livewire/InterviewBookings/Index.php` — `setApplicantZuschlag()`, `sendContractsBulk()`-Pre-Check, `bulkSendState()`-State.
- `resources/views/livewire/interview-bookings/index.blade.php` — Zuschlag-Eingabe + Datalist.
- `src/Services/SendContractsService.php` — universeller Zuschlag-Guard.
- `src/Services/Zas/ZasFieldResolver.php` + `ZasEmployeeFieldResolver.php` — Feld-first + Code-Fallback.
- `resources/views/livewire/employees/show.blade.php` — read-only Anzeige in HR-Sektion.

---

## Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php`
- Modify: `src/Models/RecApplicant.php` (`$fillable` Z.33, `$casts` Z.47)

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
        Schema::table('rec_applicants', function (Blueprint $table) {
            // Zuschlag pro Bewerber (€/Std). Single Source of Truth; löst die
            // alte Kodierung über den AV-Template-Code (AV-NNN) ab.
            $table->decimal('zuschlag', 5, 2)->nullable()->after('contract_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn('zuschlag');
        });
    }
};
```

- [ ] **Step 2: Model `$fillable` ergänzen**

In `src/Models/RecApplicant.php` im `$fillable`-Array `'contract_template_id',` durch beide Zeilen ersetzen:

```php
        'contract_template_id',
        'zuschlag',
```

- [ ] **Step 3: Model `$casts` ergänzen**

Im `$casts`-Array (Z.47) eine Zeile hinzufügen:

```php
        'zuschlag' => 'decimal:2',
```

- [ ] **Step 4: Lint**

Run: `php -l src/Models/RecApplicant.php && php -l database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php src/Models/RecApplicant.php
git commit -m "feat(zuschlag): Spalte rec_applicants.zuschlag + Model"
```

---

## Task 2: `resolveSource` — deutsche Formatierung für `applicant.zuschlag`

Der `applicant.`-Zweig gibt rohe Spaltenwerte unformatiert zurück (`(string) $applicant->{$field}` → `0.60` mit Punkt). Für `zuschlag` muss deutsches Geldformat raus (`0,60`).

**Files:**
- Modify: `src/Models/RecContractTemplate.php` (`resolveSource`, `applicant.`-Zweig)

- [ ] **Step 1: Plain-Column-Zweig anpassen**

In `resolveSource()` die letzte Zeile des `applicant.`-Blocks ersetzen:

```php
            return (string) ($applicant->{$field} ?? '');
```

durch:

```php
            $value = $applicant->{$field} ?? '';
            // Zuschlag ist ein Geldbetrag → deutsches Format (0,60) wie im settings.-Zweig.
            if ($field === 'zuschlag' && $value !== '' && is_numeric($value)) {
                return number_format((float) $value, 2, ',', '.');
            }
            return (string) $value;
```

- [ ] **Step 2: Lint**

Run: `php -l src/Models/RecContractTemplate.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Models/RecContractTemplate.php
git commit -m "feat(zuschlag): resolveSource formatiert applicant.zuschlag deutsch"
```

> **Manuelle Host-Verifikation (später):** Neue AV-Vorlage mit `{{zuschlag}}` + Mapping `zuschlag → applicant.zuschlag` bauen, Bewerber mit `zuschlag=0.60` → gerenderter Vertrag zeigt `0,60`.

---

## Task 3: Eingabe in der Schulungsnachbereitung

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (neue Methode nach `setApplicantContractTemplate`, Z.331)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Vertragsvorlage-`<td>`, nach Z.295)

- [ ] **Step 1: Livewire-Action `setApplicantZuschlag` hinzufügen**

In `src/Livewire/InterviewBookings/Index.php` direkt nach dem Ende von `setApplicantContractTemplate()` (Z.331) einfügen. Eingaben kommen ohne Tausenderpunkte; nur Komma→Punkt normalisieren:

```php
    /**
     * Setzt den Zuschlag (€/Std) für einen Bewerber. Akzeptiert deutsches
     * (0,60) oder Punkt-Dezimal (0.60). Leere Eingabe → null.
     */
    public function setApplicantZuschlag(int $bookingId, $value): void
    {
        $booking = RecInterviewBooking::with('applicant')->findOrFail($bookingId);
        if (!$booking->applicant) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            $booking->applicant->zuschlag = null;
            $booking->applicant->save();
            return;
        }

        $normalized = str_replace(',', '.', $raw);
        if (!is_numeric($normalized)) {
            session()->flash('error', 'Zuschlag muss eine Zahl sein (z.B. 0,60).');
            return;
        }

        $num = round((float) $normalized, 2);
        if ($num < 0) {
            session()->flash('error', 'Zuschlag darf nicht negativ sein.');
            return;
        }

        $booking->applicant->zuschlag = $num;
        $booking->applicant->save();
    }
```

- [ ] **Step 2: Blade — Zuschlag-Eingabe + Datalist**

In `resources/views/livewire/interview-bookings/index.blade.php` innerhalb des Vertragsvorlage-`<td>`, direkt **nach** dem schließenden `</select>` (Z.295) und vor dem `@if($isLegalCheckPending)`-Block einfügen:

```blade
                                                <div class="mt-1.5">
                                                    <input
                                                        type="text"
                                                        inputmode="decimal"
                                                        list="zuschlag-suggestions"
                                                        value="{{ $applicant->zuschlag !== null ? number_format((float) $applicant->zuschlag, 2, ',', '.') : '' }}"
                                                        @disabled($blockContracts)
                                                        wire:change="setApplicantZuschlag({{ $booking->id }}, $event.target.value)"
                                                        placeholder="Zuschlag €/Std (z.B. 0,60)"
                                                        class="text-xs border border-[var(--ui-border)] rounded px-2 py-1 min-w-[180px] {{ $isLegalCheckPending ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                                    />
                                                </div>
```

Und **einmalig** (außerhalb der Schleife, z.B. ganz am Ende des Root-`<div>` der View) die Datalist ergänzen:

```blade
    <datalist id="zuschlag-suggestions">
        <option value="0,10"></option>
        <option value="0,60"></option>
        <option value="1,10"></option>
        <option value="1,60"></option>
        <option value="2,10"></option>
        <option value="2,60"></option>
    </datalist>
```

- [ ] **Step 3: Lint**

Run: `php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(zuschlag): Eingabe + Datalist in der Schulungsnachbereitung"
```

---

## Task 4: Universeller Versand-Stopp

Verträge nur versendbar, wenn `applicant.zuschlag` gesetzt ist. Durchgesetzt am **Service** (deckt UI + `SendContractsTool`), zusätzlich freundliche UI-Meldung + Button-State.

**Files:**
- Modify: `src/Services/SendContractsService.php` (`send()`, nach dem `contract_template_id`-Guard Z.48–53)
- Modify: `src/Livewire/InterviewBookings/Index.php` (`sendContractsBulk()` Z.356, `bulkSendState()` Z.618)

- [ ] **Step 1: Service-Guard (autoritativ)**

In `src/Services/SendContractsService.php::send()` direkt **nach** dem bestehenden `contract_template_id`-Throw-Block (endet Z.53) einfügen:

```php
        if ($applicant->zuschlag === null) {
            throw new \RuntimeException(
                "Bewerber #{$applicant->id} hat keinen Zuschlag gesetzt — "
                . "bitte erst in der Schulungsnachbereitung eintragen."
            );
        }
```

- [ ] **Step 2: Livewire Pre-Check in `sendContractsBulk()`**

In `sendContractsBulk()` direkt **nach** dem `missingBeginn`-Block (der mit `session()->flash('error', 'Bei mind. einem zu versendenden Bewerber fehlt der Vertragsbeginn.'); return; }` endet, Z.396–399) einfügen:

```php
        // Zuschlag ist Pflicht (universeller Cut) — verhindern dass jemand ohne Zuschlag versendet.
        $missingZuschlag = $eligible->filter(fn ($b) => $b->applicant->zuschlag === null);
        if ($missingZuschlag->isNotEmpty()) {
            session()->flash('error', 'Bei mind. einem zu versendenden Bewerber fehlt der Zuschlag.');
            return;
        }
```

- [ ] **Step 3: `bulkSendState()` um `missing_zuschlag` erweitern**

In `bulkSendState()` direkt **vor** dem abschließenden `return 'ready';` (Z.652) einfügen:

```php
        $missingZuschlag = $pending->filter(fn ($b) => $b->applicant?->zuschlag === null);
        if ($missingZuschlag->isNotEmpty()) {
            return 'missing_zuschlag';
        }
```

- [ ] **Step 4: Blade — Meldung für `missing_zuschlag`**

In `resources/views/livewire/interview-bookings/index.blade.php` dort, wo `bulkSendState()`-States gerendert werden (suche nach `'missing_dates'`), einen analogen Zweig für `'missing_zuschlag'` ergänzen, z.B.:

```blade
                @elseif($this->bulkSendState === 'missing_zuschlag')
                    <span class="text-xs text-amber-700">Bei mind. einem Bewerber fehlt der Zuschlag — bitte eintragen.</span>
```

(Exakte Markup-Struktur aus dem benachbarten `missing_dates`-Zweig übernehmen; Button bleibt analog disabled.)

- [ ] **Step 5: Lint**

Run: `php -l src/Services/SendContractsService.php && php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 6: Commit**

```bash
git add src/Services/SendContractsService.php src/Livewire/InterviewBookings/Index.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(zuschlag): universeller Versand-Stopp ohne Zuschlag (Service + UI)"
```

---

## Task 5: ZAS-Export — Feld-first + Code-Fallback

**Files:**
- Modify: `src/Services/Zas/ZasFieldResolver.php` (`getZuschlag`)
- Modify: `src/Services/Zas/ZasEmployeeFieldResolver.php` (`getZuschlag`)

- [ ] **Step 1: Bewerber-Resolver**

In `src/Services/Zas/ZasFieldResolver.php` die Methode `getZuschlag(RecApplicant $applicant)` ersetzen durch:

```php
    protected function getZuschlag(RecApplicant $applicant): ?string
    {
        // Neu: Zuschlag aus dem Datenfeld, wenn gesetzt.
        if ($applicant->zuschlag !== null) {
            return number_format((float) $applicant->zuschlag, 2, ',', '.');
        }

        // Fallback (Bestand): aus dem AV-Template-Code parsen (AV-060 → "0,60").
        $templateCode = DB::table('rec_contract_templates')
            ->where('id', $applicant->contract_template_id)
            ->value('code');

        return $this->parseZuschlagFromCode($templateCode);
    }
```

- [ ] **Step 2: Mitarbeiter-Resolver**

In `src/Services/Zas/ZasEmployeeFieldResolver.php` die Methode `getZuschlag(RecEmployee $employee)` ersetzen durch:

```php
    protected function getZuschlag(RecEmployee $employee): ?string
    {
        if (!$employee->rec_applicant_id) {
            return null;
        }

        // Neu: Zuschlag aus dem Datenfeld des verknüpften Bewerbers.
        $zuschlag = DB::table('rec_applicants')
            ->where('id', $employee->rec_applicant_id)
            ->value('zuschlag');
        if ($zuschlag !== null) {
            return number_format((float) $zuschlag, 2, ',', '.');
        }

        // Fallback (Bestand): aus dem AV-Template-Code parsen.
        $templateCode = DB::table('rec_applicants')
            ->join('rec_contract_templates', 'rec_applicants.contract_template_id', '=', 'rec_contract_templates.id')
            ->where('rec_applicants.id', $employee->rec_applicant_id)
            ->value('rec_contract_templates.code');

        if (!$templateCode || !preg_match('/^AV-(\d{3})$/', $templateCode, $m)) {
            return null;
        }
        $cents = (int) $m[1];
        return number_format($cents / 100, 2, ',', '.');
    }
```

- [ ] **Step 3: Lint**

Run: `php -l src/Services/Zas/ZasFieldResolver.php && php -l src/Services/Zas/ZasEmployeeFieldResolver.php`
Expected: „No syntax errors detected" für beide.

- [ ] **Step 4: Commit**

```bash
git add src/Services/Zas/ZasFieldResolver.php src/Services/Zas/ZasEmployeeFieldResolver.php
git commit -m "feat(zuschlag): ZAS liest Feld zuerst, Code-Fallback fuer Bestand"
```

---

## Task 6: Mitarbeiter — Zuschlag read-only in HR-Sektion

**Files:**
- Modify: `resources/views/livewire/employees/show.blade.php` (sensible/HR-Sektion, ~Z.184)
- Ggf. Modify: `src/Livewire/Employees/Show.php` (sicherstellen, dass die `applicant`-Relation verfügbar ist)

- [ ] **Step 1: Datenzugriff prüfen**

`src/Livewire/Employees/Show.php` lesen: Wie heißt die Employee-Variable in der View (`$employee`?) und ist die Relation zum Bewerber geladen? Falls nicht, `applicant` per `with('applicant')`/`loadMissing('applicant')` verfügbar machen (RecEmployee → `rec_applicant_id` → RecApplicant). Bestätige den Namen, bevor du Step 2 einfügst.

- [ ] **Step 2: Read-only-Anzeige in der HR-Sektion**

In `resources/views/livewire/employees/show.blade.php` in der HR-only/sensiblen Sektion (der amber gestylte Block um Z.184) einen statischen Read-only-Block ergänzen:

```blade
                    @php $maZuschlag = $employee->applicant?->zuschlag; @endphp
                    <div class="flex justify-between text-sm py-1">
                        <span class="text-[var(--ui-muted)]">Zuschlag</span>
                        <span class="font-medium text-[var(--ui-secondary)]">
                            {{ $maZuschlag !== null ? number_format((float) $maZuschlag, 2, ',', '.') . ' €/Std' : '—' }}
                        </span>
                    </div>
```

> Variablennamen (`$employee`) an die tatsächliche View anpassen (aus Step 1). Platzierung: innerhalb des HR-only-Containers, damit es nicht in einer bewerber-/mitarbeiteröffentlichen Sektion landet.

- [ ] **Step 3: Lint**

Run: `php -l resources/views/livewire/employees/show.blade.php && php -l src/Livewire/Employees/Show.php`
Expected: „No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/employees/show.blade.php src/Livewire/Employees/Show.php
git commit -m "feat(zuschlag): read-only Anzeige in Mitarbeiter HR-Sektion"
```

---

## Manuelle Host-Verifikation (nach Deploy auf rheingedeck)

Kein Modul-Test-Harness. Nach `composer install` + `php artisan migrate` + `php artisan optimize:clear` + **`php artisan queue:restart`**:

1. **Eingabe:** Schulungsnachbereitung → Zuschlag `0,60` eintragen → in DB `rec_applicants.zuschlag = 0.60`. Ungültige Eingabe (`abc`, `-1`) → Fehlermeldung, kein Speichern.
2. **Vorlage/Render:** Neue generische AV-Vorlage bauen (Code z.B. `AV-default`, damit sie im Dropdown-Filter `code like 'AV-%'` erscheint) mit `{{zuschlag}}` + `field_mappings: zuschlag → applicant.zuschlag` → Vertrag rendern → zeigt `0,60`. Snapshot in `personalized_content` eingefroren.
3. **Versand-Stopp:** Bewerber ohne Zuschlag → „Verträge versenden" blockiert (UI **und** über `recruiting.applicants.send_contracts` → Service wirft). Nicht-EU-Rechtsstatus-Block weiterhin aktiv. Mit Zuschlag → versendbar.
4. **ZAS:** Bewerber/Mitarbeiter **mit** Feld → Feldwert im Export (`Grundlohn`/`Zuschlag`-Spalten). Bestand **ohne** Feld (alte AV-NNN) → Code-Fallback liefert unverändert den richtigen Wert.
5. **Mitarbeiter:** Zuschlag read-only in der HR-only-Sektion sichtbar; ohne Wert „—".

---

## Self-Review (gegen Spec geprüft)

- **Datenmodell** (Spec §1) → Task 1.
- **Eingabe Schulungsnachbereitung** (Spec §2) → Task 3 (freies Feld + Datalist; kein Default-Template — Dropdown unverändert).
- **Neue Vorlage / Formatierung** (Spec §3) → Task 2 (`resolveSource`); Vorlage selbst baut der User (kein Seeder, korrekt — nicht im Plan).
- **Vertrag erzeugen / Snapshot** (Spec §4) → unverändert (bestehender `personalizeContent`-Pfad zieht das neue Mapping), in Verifikation Schritt 2 abgedeckt.
- **Versand-Stopp universell** (Spec §5) → Task 4 (Service-Guard autoritativ + UI). Bestehende Checks unangetastet (additiv).
- **ZAS Feld-first + Fallback** (Spec §6) → Task 5 (beide Resolver).
- **Mitarbeiter HR-only read-only** (Spec §7) → Task 6.
- **Grundlohn out of scope** → nicht angefasst. ✓

Keine offenen Platzhalter (die eine Fehlversuchs-Zeile in Task 3 Step 1 ist bewusst markiert und durch die finale Methode in Step 2 ersetzt). Methodennamen konsistent: `setApplicantZuschlag`, `bulkSendState`-State `missing_zuschlag`, `getZuschlag`.
