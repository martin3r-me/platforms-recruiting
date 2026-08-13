# Design: Zuschlag als Datenfeld (statt AV-Template-Code)

**Datum:** 2026-06-09
**Modul:** platforms-recruiting
**Status:** Design abgestimmt, vor Implementierung

## Problem

Heute wird der **Zuschlag** nicht als Datenfeld gepflegt, sondern ist in der **Wahl der
Arbeitsvertrags-Variante** kodiert:

- Es gibt 6 Vertragsvorlagen `AV-010 … AV-260` (`rec_contract_templates.code`), bei denen der
  Betrag per Seeder (`CreateArbeitsvertragVariants`) **fest in den `content`** geschrieben
  wurde (`{{zuschlag}}` ersetzt, Mapping-Key entfernt).
- Der Schulungsleiter wählt in der **Schulungsnachbereitung**
  (`InterviewBookings/Index`) eine Variante → `RecApplicant.contract_template_id`.
- Der **ZAS-Export** (`ZasFieldResolver::getZuschlag`, `ZasEmployeeFieldResolver::getZuschlag`)
  **parst den Betrag aus dem Code** `AV-NNN` (`AV-060` → `0,60`).

Das ist unflexibel (nur feste Stufen, Betrag nur über Template wählbar) und der Zuschlag ist
kein echtes, sichtbares Datum.

## Ziel

Der Zuschlag wird ein **echtes Datenfeld**, das in der Schulungsnachbereitung beim Vergeben des
Arbeitsvertrags eingetragen wird, sich in eine **neue, generische AV-Vorlage** zieht, im ZAS-Export
mitgeht und am Mitarbeiter sichtbar ist.

## Abgestimmte Entscheidungen

| Thema | Entscheidung |
|---|---|
| Eingabe | Freies Betragsfeld + Vorschlagswerte (Datalist: 0,10 / 0,60 / 1,10 / 1,60 / 2,10 / 2,60) |
| Pflege-Ort | Schulungsnachbereitung (beim AV-Vergeben) |
| Sichtbarkeit | Mitarbeiter-Detail, HR-only-Sektion unten, **read-only** (Editierbarkeit später nachrüstbar) |
| Speicherung | Spalte `rec_applicants.zuschlag` (Single Source of Truth) |
| Neue Vorlage | Wird vom User **manuell im UI** gebaut (z.B. Code „AV-default"), **kein Auto-Seeder, kein Default** in der Auswahl |
| Versand-Stopp | **Universell**: Verträge nur versendbar, wenn `zuschlag` gesetzt — am Versand-Service erzwungen, additiv zu bestehenden Checks |
| Alte AV-NNN | Werden ab jetzt **nicht mehr genutzt** (sauberer Cut); bleiben nur für ZAS-Export der Bestandsmitarbeiter relevant |
| ZAS-Backward-Compat | `zuschlag`-Feld wenn gesetzt, **sonst** Fallback auf Code-Parsing (`AV-NNN`) — kein Backfill |
| Spätänderung | Vorerst keine — Feld read-only am Mitarbeiter, keine ZAS-Update-Verdrahtung nötig |

## Architektur

### 1. Datenmodell

Neue Spalte `rec_applicants.zuschlag` — `DECIMAL(5,2)`, nullable. **Single Source of Truth.**
Der Mitarbeiter (`RecEmployee`) liest den Wert über seine `rec_applicant_id`-Verknüpfung —
kein Duplizieren (gleiche Annahme, die der ZAS-Employee-Resolver heute schon nutzt).

### 2. Schulungsnachbereitung — Eingabe

Dateien: `src/Livewire/InterviewBookings/Index.php`,
`resources/views/livewire/interview-bookings/index.blade.php`.

- Neben dem bestehenden Vorlagen-Dropdown ein **Zuschlag-Feld** pro Bewerber:
  freie Dezimaleingabe mit einer **Datalist** der bekannten Stufen
  (0,10 / 0,60 / 1,10 / 1,60 / 2,10 / 2,60) als Schnellauswahl.
- Neue Livewire-Action (analog zu `setApplicantContractTemplate`), z.B.
  `setApplicantZuschlag(int $bookingId, $value)`: parst die Eingabe (deutsches **oder**
  Punkt-Dezimal toleriert), validiert `>= 0`, schreibt `applicant.zuschlag`.
- **Kein** Default-Template (Auswahl bleibt wie sie ist; der User wählt die neue Vorlage,
  die er selbst baut).

### 3. Neue generische AV-Vorlage

Wird **vom User im UI** gebaut (kein Seeder). Sie enthält den Platzhalter `{{zuschlag}}`, und ihr
`field_mappings` mappt `zuschlag → applicant.zuschlag`.

**`resolveSource`-Erweiterung** (`src/Models/RecContractTemplate.php`): Der `applicant.`-Zweig
gibt heute rohe Spaltenwerte unformatiert zurück (`(string) $applicant->{$field}` → `0.60` mit
Punkt). Wir erweitern ihn so, dass der `zuschlag`-Wert **deutsch formatiert** ausgegeben wird
(`0,60`, wie der bestehende `settings.`-Zweig es via `number_format(...,2,',','.')` tut). Umsetzung
gezielt für `applicant.zuschlag` (kein Blanket-Format aller numerischen Spalten, um andere
Platzhalter wie Hausnummer/IDs nicht zu verändern).

### 4. Vertrag erzeugen

Beim Versand wird `{{zuschlag}}` aus `applicant.zuschlag` befüllt und in
`RecContract.personalized_content` **eingefroren** (bestehender Snapshot-Mechanismus). Bereits
versendete/signierte Verträge bleiben dadurch unangetastet.

### 5. Versand-Stopp (universell)

Verträge dürfen nur versendet werden, wenn `applicant.zuschlag` gesetzt (nicht null) ist.

- Durchsetzung am **Versand-Service/-Pfad** (nicht nur im UI), damit auch der Tool-/API-Weg
  (`recruiting.applicants.send_contracts`) gestoppt wird. Exakter Einhängepunkt
  (`SendContractsService` o.ä.) wird beim Implementieren verifiziert.
- **Additiv**: bestehende Checks (Nicht-EU-Rechtsstatus, vorhandene `$blockContracts`-Logik in
  der Schulungsnachbereitung) bleiben **1:1** erhalten; der Zuschlag-Check kommt als zusätzliche
  Bedingung obendrauf.
- UI: Block-Hinweis analog zum bestehenden Rechtsstatus-Block („Zuschlag fehlt — Vertrag kann
  erst nach Eingabe versendet werden").

### 6. ZAS-Export (Backward-Compat)

`ZasFieldResolver::getZuschlag` (Bewerber) und `ZasEmployeeFieldResolver::getZuschlag`
(Mitarbeiter, via verknüpften Bewerber):

1. Wenn `applicant.zuschlag` gesetzt → diesen Wert deutsch formatiert ausgeben.
2. Sonst → **bisheriges** Verhalten: `AV-NNN`-Code parsen (`parseZuschlagFromCode`).

So bleiben **Bestandsmitarbeiter** (alte AV-NNN, kein Feld) korrekt, ohne Backfill.

### 7. Mitarbeiter-Ansicht (HR-only)

Im Mitarbeiter-Detail (`resources/views/livewire/employees/show.blade.php`), **HR-only-Sektion
unten**, den Zuschlag **read-only**, €-formatiert anzeigen — gelesen über den verknüpften
Bewerber (`employee.applicant.zuschlag`). Fehlt Wert/Verknüpfung → „—".

## Bewusste Tradeoffs / Nicht-Ziele

- **Sauberer Cut:** Alte AV-NNN werden ab jetzt nicht mehr für neue Vergaben genutzt. In-flight
  Bewerber, die noch eine alte Variante zugewiesen haben, brauchen vor Versand einen
  Zuschlag-Wert (gewollt).
- **Keine Spätänderung** des Zuschlags am Mitarbeiter (read-only) → keine ZAS-Update-Verdrahtung
  in diesem Schritt. Nachrüstbar.
- **Kein Backfill** bestehender Bewerber → der ZAS-Fallback deckt sie ab.
- **Kein Auto-Seeder / kein Default-Template** → der User baut und verifiziert die neue Vorlage
  selbst, bevor sie genutzt wird.

## Touch-Points (zu verifizieren beim Planen)

- Migration: `rec_applicants.zuschlag` DECIMAL(5,2) nullable.
- `src/Models/RecApplicant.php`: `zuschlag` in `$fillable` + `$casts` (`decimal:2`).
- `src/Livewire/InterviewBookings/Index.php` + Blade: Zuschlag-Eingabe + Action + Block-Logik.
- `src/Models/RecContractTemplate.php`: `resolveSource` deutsche Formatierung für `applicant.zuschlag`.
- Versand-Pfad (`SendContractsService` / `recruiting.applicants.send_contracts`): Zuschlag-Guard.
- `src/Services/Zas/ZasFieldResolver.php` + `ZasEmployeeFieldResolver.php`: Feld-first + Code-Fallback.
- `resources/views/livewire/employees/show.blade.php`: HR-only Read-only-Anzeige.

## Testing / Verifikation

Kein Modul-Test-Harness → manuelle Verifikation im Host (rheingedeck), via UI + ggf. tinker/MCP:

- Schulungsnachbereitung: Zuschlag eintragen → `rec_applicants.zuschlag` gesetzt; ungültige Eingabe abgelehnt.
- Neue Vorlage: Vertrag rendern → `{{zuschlag}}` zeigt `0,60` (deutsch), Snapshot eingefroren.
- Versand-Stopp: ohne Zuschlag blockiert (UI **und** Tool-Weg); mit Zuschlag versendbar; Nicht-EU-Block weiterhin aktiv.
- ZAS: Bewerber/Mitarbeiter **mit** Feld → Feldwert; Bestand **ohne** Feld (AV-NNN) → Code-Fallback unverändert.
- Mitarbeiter: Zuschlag read-only in HR-only-Sektion sichtbar.
