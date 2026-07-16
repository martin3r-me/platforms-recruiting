# Design: Einheitliches Termin-Wording aus der Gesprächsart

**Datum:** 2026-07-16
**Status:** Entwurf zur Review
**Modul:** platforms-recruiting (kein Core-Touch)

## Problem

Die Bewerber-Seiten mischen drei Wörter für denselben Termin: „Termin gebucht!", „Dein Interview-Termin wurde erfolgreich gebucht", Button „Schulung absagen", Snippet „Deine Schulung ist bestätigt!". Bewerber wissen nicht, was sie erwartet. Gewünscht: ein Wort pro Termin, dynamisch aus der Gesprächsart (z. B. „Vorstellungsrunde", „Einzelgespräch"), damit alle Seiten konsistent sprechen.

## Entscheidungen (mit User validiert)

1. **Quelle: `RecInterviewType.name` direkt** — kein separates Public-Label-Feld. Existierende Infrastruktur: Termine hängen via `interview_type_id` (nullable) an team-scoped Gesprächsarten mit eigener Verwaltungs-UI.
2. **Schöne ganze Sätze statt artikelloser Konstruktionen.** Dafür bekommt die Gesprächsart ein **Genus-Feld** (der/die/das), einmal pro Art gepflegt — nie pro Termin. Daraus leitet der Code alle Formen ab (dein/deine, Ihr/Ihre, den/die/das, zur/zum).
3. **Gepflegt in der bestehenden Gesprächsarten-UI** (`/recruiting/interview-types`, `InterviewTypes/Index`): Dropdown „Artikel: der/die/das" neben Name/Code/Beschreibung.
4. **Fallback „Termin" (maskulin, fest im Code):** greift bei Termin ohne Gesprächsart (Spalte ist nullable) und bei Gesprächsart ohne gesetztes Genus. Nie kaputt, nur generischer.
5. **Scope: nur Public-Seiten** (Booking-Seite + Bestätigungs-Snippet). WhatsApp-Templates bleiben unangetastet (user-gepflegt, Meta-Approval). Header „Termin auswählen" im Auswahl-State bleibt generisch — dort können Termine verschiedener Arten gelistet sein. Der Button-Text `rheingedeck.de/schulung` bleibt (ist die URL selbst).
6. **Kombiniert mit `$duzen`** (Anrede-Feature 2026-07-16): jede Satzform existiert in du- und Sie-Variante.

## Verworfene Alternativen

- **Artikellose Sätze („Vorstellungsrunde gebucht!"):** kein neues Feld nötig, aber Sätze klingen technisch; User will schöne ganze Sätze.
- **Fest verdrahtete Wortliste im Code:** jede neue Gesprächsart bräuchte einen Deploy — widerspricht dem Verkaufs-Gedanken.
- **Zentraler Satz-Katalog-Service:** Kontextsprung beim Lesen; beim Anrede-Feature bewusst gegen Text-Kataloge entschieden — kein zweites Text-System.

## Architektur

**Datenfluss:** `RecInterviewBooking` → `interview` → `interviewType` (`name` + neu `genus`) → pure Wort-Klasse → fertige Satzbausteine → View-Ternaries (du/Sie).

### Neue pure PHP-Klasse: `src/Support/TerminWort.php`

Value Object, pure-unit-testbar (Modul-Konvention):

```php
TerminWort::from(?RecInterviewType $type): self   // via ->fromParts(?string $name, ?string $genus) DB-frei
$wort->nominativ()                  // "Vorstellungsrunde" | "Termin"
$wort->akkusativMitArtikel()        // "die Vorstellungsrunde" | "den Termin" | "das Einzelgespräch"
$wort->possessiv(bool $duzen)       // "deine/Ihre Vorstellungsrunde" | "dein/Ihr Termin"
```

Kern ist `fromParts(?string $name, ?string $genus)` (rein, testbar); `from()` ist dünner Komfort-Wrapper. Genus-Werte `'m'|'f'|'n'`; alles andere/null → Fallback komplett auf „Termin"/m (Name UND Genus, nie gemischt — ein fremder Name mit falschem Artikel wäre schlimmer als generisch).

### Migration

`add_genus_to_rec_interview_types`: `$table->string('genus', 1)->nullable()` — nullable, kein Default, Fallback regelt der Code. Kein Daten-Backfill in der Migration (Genus der Bestandsarten kennt nur der Mensch); die zwei RheinGedeck-Arten werden nach Deploy einmal in der UI gesetzt.

### UI: Gesprächsarten-Verwaltung

`InterviewTypes/Index` (+ Blade): Feld `genus` in Form-State, Validierung `nullable|in:m,f,n`, Dropdown „Artikel" mit der/die/das (leer = nicht gesetzt → Fallback). In Liste als Spalte sichtbar, damit fehlende Genus-Pflege auffällt.

### Verdrahtung in die Seiten

| Stelle | Änderung |
|---|---|
| `src/Livewire/Public/InterviewBooking.php` | lädt Buchung schon; exponiert Satzbausteine der gebuchten/abzusagenden Art als public Properties (z. B. `$terminPossessiv`, `$terminAkkusativ`), berechnet im `mount()`/nach Buchung via `TerminWort` |
| `resources/views/livewire/public/interview-booking.blade.php` | „Termin gebucht!" → `{Nominativ} gebucht!`; „Dein/Ihr Interview-Termin wurde erfolgreich gebucht." → `{Possessiv} wurde erfolgreich gebucht.`; Button „Schulung absagen" → `{Nominativ} absagen`; Confirm „Möchtest du die Schulung wirklich absagen?" → `…{AkkusativMitArtikel}…`; Cancelled-State analog. Warteliste-Texte bleiben generisch („Termine") — sie beziehen sich auf mehrere/unbestimmte Termine |
| `src/Models/RecApplicant.php` (`renderPublicFormCompletionExtras`) | reicht zusätzlich zu `duzen` die Satzbausteine des gebuchten Termins ans Partial (`$booking->interview->interviewType`) |
| `resources/views/partials/public-form-completion.blade.php` | „Deine Schulung ist bestätigt!" → `{Possessiv} ist bestätigt!`; „Weitere Infos zur Schulung findest du hier:" → „Weitere Infos findest du/finden Sie hier:" (Genitiv-/Präpositions-Formen bewusst vermieden — nur die 4 Formen der Wort-Klasse verwenden) |

Alle Texte behalten ihre `$duzen`-Ternaries; das Termin-Wort wird darin interpoliert.

## Fehlerbehandlung

- Termin ohne Gesprächsart / Genus nicht gesetzt / unbekannter Genus-Wert → vollständiger Fallback „Termin" (m).
- Kein Booking (z. B. Auswahl-State) → generisches „Termin"-Wording wie heute.
- Gelöschte Gesprächsart (SoftDeletes): Relation liefert null → Fallback.

## Testing

- **Pure-Unit:** `TerminWortTest` — alle 3 Formen × 3 Genera × du/Sie, Fallbacks (null-Name, null-Genus, Müll-Genus), keine Vermischung von Custom-Name mit Fallback-Artikel.
- **Sichtprüfung live:** Buchung + Bestätigungs-Snippet je einmal mit Art „Vorstellungsrunde" (f), einmal mit Termin ohne Art (Fallback), du- und Sie-Team.

## Rollout

1. Branch, ff-Merge auf main nach Review, Push; meingedeck-Lock-Bump danach (nur Recruiting — kein Core-Touch). Kein queue:restart nötig (Views/Model/Migration).
2. `php artisan migrate` läuft beim Forge-Deploy.
3. Einmalig in `/recruiting/interview-types`: Genus für die zwei Bestandsarten setzen (Vorstellungsrunde = die, Einzelgespräch = das).

## Bewusst akzeptierte Punkte

- Bis HR das Genus setzt, sagen die Seiten generisch „Termin" — kein Fehlerbild.
- WhatsApp-Nachrichten sagen weiter ihr eigenes Wording (Scope-Entscheidung; `{{gespraechsart}}`-Template-Variable wäre ein späterer Ausbau).
- Der Name der Gesprächsart ist zugleich das Bewerber-Wort — wer intern kryptische Namen pflegt, sieht die auch im Frontend (bewusst: eine Quelle, kein Doppel-Label).
