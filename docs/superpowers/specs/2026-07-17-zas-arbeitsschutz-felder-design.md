# Design: Arbeitsschutz-Felder für ZAS (Ersthelfer / Sicherheitsbeauftragter)

**Datum:** 2026-07-17
**Status:** Entwurf zur Review

## Kontext & Ziel

Kundenwunsch (Mail Markus Ammerer / Olaf Michel, ZAS): Zwei Felder beidseitig ergänzen, keine Pflichtfelder auf Datensatz-Ebene:

- **Sicherheitsbeauftragter** — nur Haken.
- **Ersthelfer** — Haken plus Gültig-bis-Datum („wie Ausweisfeld mit Datumspflicht", vgl. `AusweisNr`/`AusweisBis`): Das Datum ist Pflicht, sobald der Haken gesetzt ist.

Die Felder gehen in den ZAS-Export (initial + updates) und werden beim Inbound-Import **neuer** MA übernommen. Spaltenüberschriften werden ZAS von uns vorgegeben (siehe unten) und per Mail bestätigt.

## Scope

**Drin:**
1. Drei neue Spalten auf `rec_employees` + Model-Erweiterung.
2. ZAS-Export: drei neue CSV-Spalten (initial.csv + updates.csv) inkl. Delta-Trigger.
3. ZAS-Inbound: Mapping der drei Spalten bei Neuanlagen.
4. HR-Ansicht: Pflege in neuer Gruppe „Arbeitsschutz (HR-only)" inkl. Datumspflicht-Validierung.
5. Pure-Unit-Tests für Export-Formatierung und Import-Parsing.

**Explizit NICHT drin (Abgrenzung):**
- **Kein Update-Pfad für Bestands-MA im Inbound.** Der Importer bleibt Neuanlage-only; bei UUID-/PersNr-Match wird weiterhin geskippt. Rückfluss der Felder von ZAS zu bereits existierenden MA ist ein separates Phase-2-Thema (Feldhoheit, Export-Schleifen-Schutz, would_update-Dry-run — siehe Klärungsfragen unten).
- **Kein MA-Portal.** Die Felder erscheinen weder editierbar (`editableFieldGroups()`) noch read-only (`readOnlyDisplayFields()`) im Portal. Read-only-Anzeige kann später jederzeit nachgerüstet werden.
- Keine Felder auf `rec_employee_hr_data`.

## Konstanten-Tabelle (verbindlich für ALLE Umsetzungs-Tasks)

Diese sechs String-Literale müssen über Migration, Model, Resolver, Observer, Mapper und `fieldGroups()` deckungsgleich sein — jede Abweichung (Tippfehler, Singular/Plural, Casing) bricht Export, Import oder Delta-Trigger still:

| Fachlich | CSV-Header (ZAS) | Model-Attribut / DB-Spalte |
|---|---|---|
| Ersthelfer (Haken) | `Ersthelfer` | `is_first_aider` |
| Ersthelfer gültig bis | `ErsthelferBis` | `first_aider_valid_until` |
| Sicherheitsbeauftragter (Haken) | `Sicherheitsbeauftragter` | `is_safety_officer` |

## Datenmodell

Neue Spalten auf `rec_employees`, alle nullable (keine Pflichtfelder laut Kunde):

| Spalte | Typ | Cast |
|---|---|---|
| `is_first_aider` | boolean | `boolean` |
| `first_aider_valid_until` | date | `date` |
| `is_safety_officer` | boolean | `boolean` |

- Naming-Konvention wie `has_car` / `has_infection_protection_certificate` (englisch, bool-Präfix).
- Ablage auf `rec_employees` (nicht `hr_data`) nach dem Muster `personnel_number`/`cost_center`: Feld liegt am MA, wird in der HR-Ansicht aber HR-only (gelb) gerendert. Grund: Export/Import-Mapping bleibt trivial, da beide auf `rec_employees`-Attributen arbeiten.
- **Migration** nach Vorbild `2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php`: `Schema::hasColumn`-Guards, nullable, kein Default.
- **Model `RecEmployee`**: drei Einträge in `$fillable`, zwei `boolean`- und ein `date`-Cast in `$casts`.

## ZAS-Export

**CSV-Spaltenüberschriften (an ZAS zu kommunizieren):** `Ersthelfer`, `ErsthelferBis`, `Sicherheitsbeauftragter`

- `ZasEmployeeFieldResolver::COLUMNS`: die drei Spalten **am Ende anhängen, hinter `ZasPersonalNr`**. Verifiziert: `ZasPersonalNr` ist der letzte Eintrag der Konstante (`ZasEmployeeFieldResolver.php:101`), „hinter ZasPersonalNr" ist also das echte Array-Ende. Begründung: Falls ZAS positionsbasiert parst, würde ein Einschub in der Mitte alle Folgespalten verschieben; Anhängen ist garantiert abwärtskompatibel.
- `resolveColumn()`: drei neue Cases —
  - `Ersthelfer` → `boolLabel($e->is_first_aider)` („Ja"/„Nein", nie leer)
  - `ErsthelferBis` → `formatDate($e->first_aider_valid_until)` (`d.m.Y`, leer bei null)
  - `Sicherheitsbeauftragter` → `boolLabel($e->is_safety_officer)`
- `RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS`: die drei Spaltennamen ergänzen, damit Änderungen `zas_changed_at` setzen und im Delta-Export (`updates.csv`) landen.

Beide Export-Controller (initial/updates) nutzen `COLUMNS` zentral — dort ist nichts weiter anzufassen.

## ZAS-Inbound (nur Neuanlagen)

`ZasInboundRowMapper`:
- `BOOLS`: `'Ersthelfer' => 'is_first_aider'`, `'Sicherheitsbeauftragter' => 'is_safety_officer'` (Parsing: `'ja'` case-insensitive → true, alles andere false — bestehende Logik).
- `DATES`: `'ErsthelferBis' => 'first_aider_valid_until'` (strikt `d.m.Y`, ungültig → Warnung + Feld leer — bestehende Logik).

Verhalten bei Inkonsistenz (`Ersthelfer=Ja`, `ErsthelferBis` leer oder unparsebar): Zeile wird **trotzdem importiert**, mit Warnung im Sammel-Bericht („Ersthelfer ohne gültiges Bis-Datum"). Konsistent zum bestehenden lenienten Import (Warnungen statt Abweisung, außer bei Strukturfehlern). Das Mapping ist header-basiert — die Spaltenposition in der ZAS-Datei ist egal.

Platzierung des Cross-Field-Checks: imperativer Block in `map()` nach den BOOLS/DATES-Schleifen, vor dem `return` — dort existiert die `$warnings`-Sammlung bereits (Vorbild: Datums-Warnung `RowMapper:86`); der Importer prefixed Warnungen automatisch mit Zeile + PersNr.

## HR-Ansicht (`Livewire/Employees/Show.php`)

- Neue Gruppe in `fieldGroups()`, eingeordnet direkt nach „ZAS / Abrechnung (HR-only)":

  ```php
  'Arbeitsschutz (HR-only)' => [
      'is_first_aider'          => ['type' => 'bool', 'label' => 'Ersthelfer'],
      'first_aider_valid_until' => ['type' => 'date', 'label' => 'Ersthelfer-Schein gültig bis'],
      'is_safety_officer'       => ['type' => 'bool', 'label' => 'Sicherheitsbeauftragter'],
  ],
  ```

  Rendering (`bool` = Ja/Nein-Select, `date` = Date-Input) und `saveAll()`-Bool-Konvertierung existieren bereits.
- Die Gelb-Markierung hängt am Gruppennamen: das Blade prüft `str_contains($section, 'HR-only')` (`show.blade.php:71`) — der Name „Arbeitsschutz (HR-only)" rendert also automatisch gelb, nichts weiter nötig.
- **Datumspflicht (neue Logik):** `saveAll()` hat heute keinerlei Validierung; es konvertiert beide Wert-Arrays und persistiert an genau zwei Punkten am Ende (`Show.php:394/398`). Der Check wird ein **Guard vor den Persist-Aufrufen**: Wenn der eingehende Wert für `is_first_aider` truthy ist (`'1'/'true'/'ja'`, analog zur Bool-Konvertierung 356-361) und `first_aider_valid_until` nach trim leer ist → Fehlermeldung setzen und `return`, nichts speichern.
- **Endzustands-Prüfung, bewusst:** Der Guard blockt JEDEN `saveAll()`, solange der Endzustand „Ersthelfer=Ja ohne Datum" wäre — auch wenn nur ein unrelated Feld (z.B. Telefonnummer) geändert wurde. Gewollt: der leniente Inbound kann einen MA mit `Ersthelfer=Ja` ohne Datum anlegen, und die Maske erzwingt die Reparatur beim nächsten Edit. Die Fehlermeldung muss deshalb explizit sagen, dass das **Ersthelfer-Datum** fehlt (nicht generisch „Validierung fehlgeschlagen"), sonst versteht HR bei einem Telefonnummern-Edit die Ablehnung nicht.
- **Formular-State überlebt den Guard:** Early-Return OHNE `loadFieldValues()`-Aufruf — der steht heute nur im Erfolgspfad (`Show.php:408`), und das muss so bleiben: `$this->fieldValues`/`$this->hrFieldValues` bleiben unangetastet, die wire:model-Eingaben stehen nach der Fehlermeldung noch da, HR trägt nur das Datum nach und speichert erneut.
- **Fehlerdarstellung:** Feedback läuft heute ausschließlich über `public ?string $flash`, das im Blade als **grüne Erfolgsbox** gerendert wird — handgeschriebenes Markup (`<div class="... bg-green-50 border-green-200 ...">` + `@svg('heroicon-o-check-circle')`, `show.blade.php:61-66`), KEINE x-ui-Komponente. Die rote Variante wird als gespiegeltes handgeschriebenes Markup daneben gebaut (`bg-red-50`/`border-red-200`/`text-red-800`, `heroicon-o-exclamation-circle`); keine Bedingungen/`@php` in x-ui-Attribute (Repo-Blade-Falle), Werte ggf. in Block-Form `@php … @endphp` vorberechnen. Neue Property exakt wie das Vorbild: `public ?string $flashError = null`. `saveAll()` setzt beim Guard `$flashError` und nullt `$flash` (und umgekehrt bei Erfolg).

## Fehlerbehandlung

- Export: null-Werte → `boolLabel` liefert „Nein", `formatDate` liefert leer. Kein Sonderfall nötig.
- Import: siehe Inbound-Abschnitt (Warnung statt Abweisung).
- HR-Maske: einzige Fehlerquelle ist die Datumspflicht-Verletzung → Fehlermeldung, kein Save.

## Tests (pure PHPUnit, Repo-Konvention: kein Laravel/DB)

Rahmenbedingung: `tests/bootstrap.php` ist ein reiner Autoloader ohne Laravel-Bootstrap; Illuminate/Carbon sind über meingedecks vendor-Autoload verfügbar, aber es gibt keinen App-Container und keine DB-Connection. Kein bestehender Test instanziiert `RecEmployee` — dieses Feature legt das Muster erstmals an:

1. **Export-Formatierung** (`ZasEmployeeFieldResolver`): `COLUMNS` enthält die drei neuen Header am Ende; `resolveColumn()` liefert „Ja"/„Nein" für die Bools (inkl. null → „Nein") und `d.m.Y` bzw. leer für das Datum. Mechanik: `resolveColumn()` ist protected und `resolve()` macht `loadMissing()` (DB!) — daher **Test-Subklasse**, die `resolveColumn()` public macht. Model-Instanz via `(new RecEmployee)->setRawAttributes([...])` — `fill()`/Konstruktor würde beim date-Cast `getDateFormat()` → `getConnection()` ziehen und ohne Connection-Resolver fatal enden; der Lese-Cast eines `Y-m-d`-Strings läuft dagegen containerfrei über den Standardformat-Pfad.
2. **Import-Parsing** (`ZasInboundRowMapper`): `Ersthelfer=Ja/Nein/leer` → bool korrekt; `ErsthelferBis` gültig → `Y-m-d`, ungültig → Warnung + leer; Inkonsistenz-Warnung (Ja ohne Datum). Konstruktor braucht `ZasLookupReverseResolver` — Stub genügt, da die drei Spalten keine Lookups berühren (Testzeilen enthalten nur die neuen Header + ggf. UUID/ZasPersonalNr).
3. Runner: `meingedeck/vendor/bin/phpunit -c phpunit.xml` (Modul hat kein eigenes vendor/).

## Offene Punkte / an Kunde zu kommunizieren

1. **Spaltenüberschriften bestätigen:** `Ersthelfer`, `ErsthelferBis`, `Sicherheitsbeauftragter` — inkl. Klarstellung, dass `ErsthelferBis` das Gültig-bis-Datum des Ersthelfer-Scheins ist (Lesart „wie Ausweisfeld").
2. **Phase-2-Frage stellen:** Sollen die Felder bei bereits übertragenen Bestands-MA von ZAS zurück zu uns synchronisiert werden? (Heute: Inbound legt nur neue MA an.) Falls ja → separates Paket: Feldhoheit/Whitelist, Export-Schleifen-Schutz, would_update-Dry-run.
3. Nach Deploy: Vollzugsmeldung an Markus/Olaf („uns mitteilen wenn erfolgt").

## Umsetzungshinweise

- Frischer Branch von `origin/main` (vorher fetch), unabhängig von `feature/nicht-eu-nach-schulung`.
- Nach Push: `meingedeck` composer.lock bumpen (sonst nicht live).
- Kein `queue:restart` nötig — verifiziert: beide Exporte sind synchrone GET-Endpoints (ZAS pullt), Inbound synchroner POST, kein `dispatch`/`ShouldQueue` im ZAS-Code.
- `RELEVANT_EMPLOYEE_FIELDS` erwartet Model-Attributnamen (wird gegen `getChanges()`-Keys geschnitten), nicht CSV-Header.

## Live-Check nach Forge-Deploy (keine Staging-Verifikation möglich)

1. HR-Ansicht eines MA öffnen: Gruppe „Arbeitsschutz (HR-only)" erscheint und ist gelb markiert.
2. Bool-Select (Ja/Nein) und Date-Input funktionieren.
3. Ersthelfer=Ja setzen, Datum leer lassen, speichern → **rote** Box (nicht grün) mit expliziter Ersthelfer-Datum-Meldung, nichts gespeichert, eingegebene Werte bleiben in der Maske stehen.
4. Datum nachtragen + speichern → grüne Box „Aenderungen gespeichert".
5. `meingedeck` composer.lock bumpen (Pflicht, sonst nicht live) — vor dem Live-Check, sonst prüft man alten Code.
