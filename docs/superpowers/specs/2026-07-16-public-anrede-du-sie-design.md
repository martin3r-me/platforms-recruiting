# Design: Umschaltbare Anrede (Sie/du) auf öffentlichen Seiten

**Datum:** 2026-07-16
**Status:** Entwurf zur Review
**Module:** platforms-recruiting (Hauptteil), platforms-core (kleiner Hook)

## Problem

Alle öffentlichen Seiten (Bewerber-Formulare, Interview-Booking, Portale, Vertragsunterschrift) haben hardcodete deutsche Texte. Die Core-Formularseite und die Recruiting-Views siezen, die Schulungs-Completion-Partials duzen — auf derselben Seite entsteht ein Stilbruch (Core-Card siezt, Recruiting-Snippet darunter duzt). RheinGedeck will überall duzen. Wird das Recruiting-Modul später an andere Kunden verkauft, muss jeder Kunde selbst wählen können.

## Entscheidungen (mit User validiert)

1. **Steuerungsebene: Team/Mandant.** Nicht das Modul entscheidet, sondern das Team, dem die Bewerber/Mitarbeiter gehören (RheinGedeck-HR = du; ein späterer Kunde = eigenes Team, eigener Default).
2. **Scope: alle Public-Views.** Core-Formular, Interview-Booking, Bewerber-/Mitarbeiterportal, Vertragsunterschrift, Schulungs-Partials. **Nicht** im Scope: E-Mails, WhatsApp-Templates, interne Admin-UI (bewusste Entscheidung; Stilbruch Seite↔Nachricht wird akzeptiert).
3. **Keine Mehrsprachigkeit.** Bleibt rein Deutsch, daher keine Laravel-Lang-Files — schlanker Bool-Mechanismus mit Ganz-Satz-Ternaries direkt in den Views.
4. **Default ist Sie.** Setting fehlt, ist null oder ungültig → immer Sie. Nur explizit aktiviertes `use_informal_address` duzt.

## Verworfene Alternativen

- **Lang-Files (`de`/`de_du`):** sauberste Text-Trennung, aber volle Lang-Infrastruktur (Files, Locale-Middleware, Key-Disziplin) nur für einen Bool-Unterschied — Overkill, da Mehrsprachigkeit ausgeschlossen wurde.
- **Zentraler Text-Katalog (`PublicTexts::get(...)`):** zentral pflegbar, aber Kontextsprung beim Lesen jedes Views; lohnt erst bei starker Text-Wiederverwendung, die hier nicht existiert.
- **Statisch auf du umstellen:** widerspricht der Verkaufs-Anforderung (anderer Kunde will ggf. Sie).

## Architektur

**Datenfluss:** Public-Link (Token) → Modell (`RecApplicant`/`RecEmployee`) → `team_id` → `RecApplicantSettings.settings['use_informal_address']` → `$duzen: bool` → Ternary im Blade.

`getOrCreateForTeam()` ist ein `firstOrCreate` und legt bei fehlender Zeile eine mit Defaults an — dieser Schreib-Seiteneffekt in Public-GET-Pfaden existiert heute schon (z. B. `RecContractTemplate.php:170` im Contract-Signing, `RecEmployee.php:408`) und ist idempotent → akzeptiert, konsistent mit Bestand.

**Setting:** Der Key existiert bereits: `use_informal_address` (bool, Default `false`) in `RecApplicantSettings::DEFAULT_SETTINGS` (Z. 22) — inklusive fertigem Checkbox-Toggle „Informelle Anrede (Duzen)" im `ApplicantSettingsModal` (General-Tab, Blade Z. 80), der beim Save bereits mitpersistiert wird (`save()` schreibt das komplette Settings-Array, keine Whitelist). **Bislang liest nur niemand den Key aus.** Diese Spec verdrahtet ihn; es wird kein neuer Key und keine neue Settings-UI gebaut. Migration unnötig (`settings` ist `'array'`-Cast, Z. 17-19).

**Core-Anbindung:** Core kennt Recruiting nicht. `PublicExtraFieldForm` nutzt das dort etablierte Duck-Typing-Muster (`usesAccordionFormLayout`, `renderPublicFormCompletionExtras`): `method_exists($model, 'usesInformalAddress')` → `$duzen`, sonst false. Andere Module ohne die Methode bleiben unverändert bei Sie.

**Text-Muster in Views:** Ganz-Satz-Ternaries, kein Wort-Flickwerk:
```blade
{{ $duzen ? 'Dein Interview-Termin wurde erfolgreich gebucht.' : 'Ihr Interview-Termin wurde erfolgreich gebucht.' }}
```

## Änderungen im Detail

### platforms-recruiting — neue Dateien

| Datei | Inhalt |
|---|---|
| `src/Support/PublicAddressStyle.php` | Pure-PHP: `informal(mixed $value): bool` — normalisiert den Setting-Wert (true/`'1'`/1 → true; false/null/Müll → false). Single Source of Truth für den Default Sie. |
| `src/Models/Concerns/ResolvesPublicAddressStyle.php` | Trait `usesInformalAddress(): bool` — `RecApplicantSettings::getOrCreateForTeam($this->team_id)->getSetting('use_informal_address')`, normalisiert via `PublicAddressStyle`. |
| `tests/Unit/PublicAddressStyleTest.php` | Pure-Unit-Tests: true, false, null, `'1'`/`'0'`, ungültiger Wert. |

### platforms-recruiting — geänderte Dateien

| Datei | Änderung |
|---|---|
| `src/Models/RecApplicant.php` | Trait `ResolvesPublicAddressStyle` einbinden; in `renderPublicFormCompletionExtras()` (~Z. 667) `$duzen` an das Partial geben |
| `src/Models/RecEmployee.php` | Trait einbinden |
| `src/Livewire/Public/InterviewBooking.php` | `public bool $duzen`, im `mount()` vom Modell aufgelöst |
| `src/Livewire/Public/ApplicantPortal.php` | dito |
| `src/Livewire/Public/EmployeePortal.php` | dito |
| `src/Livewire/Public/ContractSigning.php` | dito; zusätzlich Validierungsmeldung „Bitte unterschreiben Sie den Vertrag." (Z. 119) variant |
| `resources/views/livewire/public/interview-booking.blade.php` | Sie-Stellen (u. a. Z. 268–269) als Ternaries |
| `resources/views/livewire/public/applicant-portal.blade.php` | dito |
| `resources/views/livewire/public/employee-portal.blade.php` | dito |
| `resources/views/livewire/public/contract-signing.blade.php` | dito |
| `resources/views/partials/public-form-completion.blade.php` | heute hardcoded du → beide Varianten via `$duzen`-Parameter |
| `resources/views/livewire/applicant/applicant-settings-modal.blade.php` | nur Beschreibungstext des vorhandenen Toggles ergänzen: gilt auch für öffentliche Seiten (Z. 84) |

**Dead Code (Befund, optionaler Cleanup):** `RecApplicant` bindet `src/Traits/RendersPublicFormCompletionExtras.php` ein (Z. 31), definiert die Methode aber selbst (Z. 667) — die Klassen-Methode gewinnt, die Trait-Version und das von ihr gerenderte Partial `public-form-completion-schulung.blade.php` laufen nie (kein anderes Modell nutzt den Trait). Beide werden NICHT variant gemacht; Entfernung als separater Cleanup-Schritt im Plan.

Der Text-Audit bei der Umsetzung muss neben den Views auch PHP-Strings erfassen (Validierungs-/Fehlermeldungen in `src/Livewire/Public/` und `src/Tools/`); Stand heute ist `ContractSigning.php:119` die einzige bekannte PHP-Fundstelle.

### platforms-core — geänderte Dateien (Core-Edit-Freigabe vor Umsetzung explizit einholen)

| Datei | Änderung |
|---|---|
| `src/Livewire/Public/PublicExtraFieldForm.php` | `$duzen` via `method_exists($model, 'usesInformalAddress')`, Default false |
| `resources/views/livewire/public/public-extra-field-form.blade.php` | 3 Sie-Stellen (u. a. „Sie können diese Seite jetzt schließen.", Z. 433) als Ternaries |

## Fehlerbehandlung

- Setting fehlt / Zeile fehlt / Wert ungültig → Sie (Default über `PublicAddressStyle`).
- Modell ohne `usesInformalAddress` (andere Module) → Sie, Verhalten wie heute.
- Modell nicht auflösbar (ungültiger Token) → Seite rendert wie heute ihre Fehlzustände; Anrede-Auflösung wird gar nicht erreicht bzw. fällt auf Sie.

## Testing

- **Pure-Unit** (Modul-Konvention: kein Laravel/DB): `PublicAddressStyleTest` deckt die Auflösungslogik ab.
- **Sichtprüfung auf Staging:** beide Settings-Werte je einmal durch die betroffenen Seiten klicken (Formular saved/completed, Booking, Portale, Vertrag, Schulungs-Card).

## Rollout

1. Recruiting-Branch + Core-Branch, Merge nach Review (ff auf main, kein PR — Repo-Konvention).
2. Nach Deploy: meingedeck `composer.lock` bumpen (Pflicht, sonst nicht live).
3. Einmalig: im Team RheinGedeck-HR die vorhandene Checkbox „Informelle Anrede (Duzen)" im `ApplicantSettingsModal` (General-Tab) aktivieren — falls nicht ohnehin schon gesetzt (der Toggle speichert bereits, wird nur bisher nicht ausgelesen). Kein Seed-Command nötig.

## Bewusst akzeptierte Punkte

- E-Mails/WhatsApp siezen weiter bzw. sind user-editierbar → möglicher Stilbruch Nachricht↔Seite (User-Entscheidung, späterer Ausbau möglich).
- Neue Public-Texte müssen künftig in beiden Varianten geschrieben werden — das Ternary-Muster macht das im View sichtbar.
