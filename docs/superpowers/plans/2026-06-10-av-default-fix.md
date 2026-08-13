# AV-default fix in der Schulungsnachbereitung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In der Schulungsnachbereitung wird die Vertragsvorlage fix auf AV-default gesetzt (read-only, kein Dropdown) und automatisch zugewiesen; die alten AV-NNN bleiben aktiv, werden aber nicht mehr angeboten.

**Architecture:** Eine zentrale Computed-Auflösung `defaultContractTemplate()` (aktives Template mit `code='AV-default'` im Team). Auto-Zuweisung von `applicant.contract_template_id` auf den Default beim „Teilgenommen"-Setzen und defensiv vor dem Versand. Das Vorlagen-Dropdown im Blade wird durch eine read-only Anzeige ersetzt; fehlt der Default, wird der Versand blockiert (eigener State + bestehender no-template-Stopp im Service).

**Tech Stack:** Laravel, Livewire. **Kein Modul-Test-Harness** → `php -l` + manuelle Host-Verifikation.

---

## Referenzen (aktueller Code)

`src/Livewire/InterviewBookings/Index.php`: `updateStatus()` (Z.268), `setApplicantContractTemplate()` (Z.302), `sendContractsBulk()` (Z.389), `bulkSendState()` (Z.658), `availableContractTemplates()` (Z.698).
Blade `resources/views/livewire/interview-bookings/index.blade.php`: `$templates`-Zuweisung (Z.212), Vorlagen-`<select>` (Z.283–295), `missing_templates`-Button-Branch (bei `bulkSendState`-Rendering).
AV-default: `RecContractTemplate` mit `code='AV-default'` (id 16), aktuell `is_active=false`.

---

## Task 1: `defaultContractTemplate()` Computed + Auto-Assign-Helfer

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (neue Methoden nach `availableContractTemplates()`, Z.710)

- [ ] **Step 1: Computed + Helfer hinzufügen**

Nach dem Ende von `availableContractTemplates()` (Z.710) einfügen:

```php
    #[Computed]
    public function defaultContractTemplate()
    {
        return RecContractTemplate::where('team_id', auth()->user()->currentTeam->id)
            ->where('code', 'AV-default')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Weist dem Bewerber die AV-default-Vorlage zu, falls noch keine gesetzt
     * ist und ein aktives AV-default existiert. Idempotent.
     */
    private function assignDefaultTemplateIfMissing(?RecApplicant $applicant): void
    {
        if (!$applicant || $applicant->contract_template_id) {
            return;
        }
        $default = $this->defaultContractTemplate;
        if ($default) {
            $applicant->contract_template_id = $default->id;
            $applicant->save();
        }
    }
```

> `RecApplicant` ist in der Datei bereits importiert (wird in `setApplicantContractTemplate` etc. genutzt). `RecContractTemplate` ebenfalls (in `availableContractTemplates`/`setApplicantContractTemplate`).

- [ ] **Step 2: Lint**

Run: `php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php
git commit -m "feat(av-default): defaultContractTemplate-Resolver + Auto-Assign-Helfer"
```

---

## Task 2: Auto-Zuweisung beim „Teilgenommen"-Setzen

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (`updateStatus`, Z.291)

- [ ] **Step 1: In `updateStatus` nach dem Update die Zuweisung anstoßen**

Den Block am Ende von `updateStatus` ersetzen:

```php
        $booking->update($updates);
        session()->flash('success', 'Status aktualisiert!');
    }
```

durch:

```php
        $booking->update($updates);

        // Ab Status "Teilgenommen" wird die Standard-Vertragsvorlage (AV-default)
        // automatisch zugewiesen — HR wählt nichts mehr aus.
        if ($status === 'attended') {
            $this->assignDefaultTemplateIfMissing($booking->fresh('applicant')->applicant);
        }

        session()->flash('success', 'Status aktualisiert!');
    }
```

- [ ] **Step 2: Lint**

Run: `php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php
git commit -m "feat(av-default): AV-default automatisch bei Teilgenommen zuweisen"
```

---

## Task 3: Pre-Send-Fallback + No-Default-Guard in `sendContractsBulk`

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (`sendContractsBulk`, Z.389 ff.)

- [ ] **Step 1: Am Anfang von `sendContractsBulk` Default prüfen + zuweisen**

Direkt nach der öffnenden Zeile `public function sendContractsBulk(): void` und dem einleitenden Kommentar-Block — konkret **unmittelbar vor** der `$blockedByLegalStatus = collect();`-Zeile — einfügen:

```php
        // AV-default ist Pflicht-Quelle. Fehlt sie (inaktiv/nicht angelegt),
        // kann nichts versendet werden.
        $default = $this->defaultContractTemplate;
        if (!$default) {
            session()->flash('error', 'AV-default-Vorlage fehlt oder ist inaktiv — bitte zuerst aktivieren.');
            return;
        }

        // Defensiv: anwesenden Bewerbern ohne Vorlage den Default zuweisen
        // (falls sie vor diesem Feature schon auf "Teilgenommen" standen).
        foreach ($this->bookings as $b) {
            if ($b->status === 'attended' && $b->applicant && !$b->applicant->contract_template_id) {
                $b->applicant->contract_template_id = $default->id;
                $b->applicant->save();
            }
        }
        unset($this->bookings);
```

> `unset($this->bookings)` invalidiert die Computed-Collection, damit die anschließende `$eligible`-Berechnung die frisch gesetzten `contract_template_id` sieht.

- [ ] **Step 2: Lint**

Run: `php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php
git commit -m "feat(av-default): Pre-Send-Fallback + No-Default-Guard im Bulk-Versand"
```

---

## Task 4: `bulkSendState` — `no_default_template` statt `missing_templates`

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (`bulkSendState`, Z.658)

- [ ] **Step 1: `missing_templates`-Block ersetzen**

In `bulkSendState()` den bestehenden Block:

```php
        $missingTemplate = $attended->filter(fn ($b) => empty($b->applicant?->contract_template_id));
        if ($missingTemplate->isNotEmpty()) {
            return 'missing_templates';
        }
```

ersetzen durch:

```php
        // Vorlage ist fix AV-default → kein Auswahl-Gate mehr. Einziger Block:
        // wenn kein aktives AV-default existiert.
        if (!$this->defaultContractTemplate) {
            return 'no_default_template';
        }
```

- [ ] **Step 2: Lint**

Run: `php -l src/Livewire/InterviewBookings/Index.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php
git commit -m "feat(av-default): bulkSendState no_default_template statt missing_templates"
```

---

## Task 5: Blade — read-only Vorlage + Button-State

**Files:**
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Z.212, Z.283–295, `missing_templates`-Branch)

- [ ] **Step 1: `$templates`-Zuweisung durch Default ersetzen**

Zeile 212 ersetzen:

```blade
                        $templates = $this->availableContractTemplates;
```

durch:

```blade
                        $defaultTpl = $this->defaultContractTemplate;
```

- [ ] **Step 2: Vorlagen-`<select>` durch read-only Anzeige ersetzen**

Den Block Z.283–295 (das komplette `<select> … </select>`) ersetzen:

```blade
                                                <select
                                                    wire:change="setApplicantContractTemplate({{ $booking->id }}, $event.target.value)"
                                                    @disabled($blockContracts)
                                                    title="{{ $isLegalCheckPending ? 'Bewerber muss zuerst auf HR-Schreibtisch geprüft werden' : '' }}"
                                                    class="text-xs border border-[var(--ui-border)] rounded px-2 py-1 min-w-[180px] {{ $isLegalCheckPending ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                                >
                                                    <option value="">— keine Vorlage —</option>
                                                    @foreach($templates as $tpl)
                                                        <option value="{{ $tpl->id }}" @selected($applicant->contract_template_id === $tpl->id)>
                                                            {{ $tpl->code ? $tpl->code . ' — ' : '' }}{{ $tpl->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
```

durch:

```blade
                                                @if($defaultTpl)
                                                    <div class="text-xs px-2 py-1 min-w-[180px] inline-block rounded bg-[var(--ui-muted-5)] border border-[var(--ui-border)] text-[var(--ui-secondary)]">
                                                        {{ $defaultTpl->code ? $defaultTpl->code . ' — ' : '' }}{{ $defaultTpl->name }}
                                                    </div>
                                                @else
                                                    <div class="text-xs text-red-700">AV-default-Vorlage fehlt oder ist inaktiv.</div>
                                                @endif
```

> Das Zuschlag-Eingabefeld (direkt darunter) und der `@if($isLegalCheckPending)`-Hinweis bleiben unverändert.

- [ ] **Step 3: Button-Branch `missing_templates` → `no_default_template`**

Im `bulkSendState`-Rendering den Branch:

```blade
                            @elseif($bulkState === 'missing_templates')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="Allen anwesenden Bewerbern eine Vertragsvorlage zuweisen">
                                    Verträge versenden — Vorlagen fehlen
                                </button>
```

ersetzen durch:

```blade
                            @elseif($bulkState === 'no_default_template')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="AV-default-Vorlage fehlt oder ist inaktiv">
                                    Verträge versenden — AV-default fehlt
                                </button>
```

- [ ] **Step 4: Lint**

Run: `php -l resources/views/livewire/interview-bookings/index.blade.php` (über `php -l` auf die kompilierte Datei nicht möglich; stattdessen sicherstellen, dass keine Blade-Syntaxfehler — visuelle Prüfung der `@if/@else/@endif`-Balance).
Expected: ausgewogene Blade-Direktiven.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(av-default): read-only AV-default-Anzeige statt Dropdown + Button-State"
```

---

## Task 6: AV-default aktivieren (Konfiguration, kein Code)

- [ ] **Step 1:** AV-default (id 16) auf `is_active = true` setzen — per MCP (`recruiting.contract_templates.PUT`) oder im UI. Einmaliger Schritt; ohne ihn zeigt die Schulungsnachbereitung den „AV-default fehlt"-Hinweis und der Versand bleibt blockiert.

---

## Manuelle Host-Verifikation (nach Deploy)

1. AV-default aktiv → Schulungsnachbereitung zeigt read-only „AV-default — Arbeitsvertrag default", kein Dropdown.
2. Bewerber auf „Teilgenommen" → `rec_applicants.contract_template_id` = AV-default-id (automatisch).
3. Versand-Gates: ohne Zuschlag bzw. Vertragsbeginn blockiert; mit beidem versendbar; Nicht-EU-Rechtsstatus-Block weiter aktiv.
4. Gerenderter Vertrag zieht den Zuschlag korrekt (bereits verifiziert).
5. AV-default testweise inaktiv → „AV-default fehlt"-Hinweis + Button „AV-default fehlt", kein stiller Fehlversand.
6. Alte Schulungen mit versendeten AV-NNN-Verträgen: unverändert „Verträge versendet".

---

## Self-Review (gegen Spec)

- Default-Auflösung (Spec §1) → Task 1 (`defaultContractTemplate`, code 'AV-default').
- Aktivierung (Spec §2) → Task 6.
- Read-only UI + Auto-Assign (Spec §3) → Task 2 (Teilgenommen) + Task 3 (Pre-Send) + Task 5 (Blade read-only).
- Eligibility/State (Spec §4) → Task 3 (no-default-Guard) + Task 4 (`no_default_template`); Zuschlag/Vertragsbeginn/Rechtsstatus-Gates unangetastet.
- Alt-AV-NNN aktiv lassen (Spec §5) → nicht angefasst; nur Dropdown entfernt (Task 5).
- Bestand unberührt (Spec §6) → keine Migration/kein Backfill, frozen snapshots.

Keine offenen Platzhalter. Namen konsistent: `defaultContractTemplate`, `assignDefaultTemplateIfMissing`, State `no_default_template`.
