# Zeiterfassung: Stempeln im Mitarbeiter-Portal (Abschnitt 1) — Bauplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitarbeiter im neuen Portal stempeln „Kommen“/„Gehen“ an ihrer Einbuchung; die Disposition sieht, korrigiert, erledigt — alles mit Verlauf, hinter einem Schalter, ohne ZAS.

**Architecture:** Ein modulunabhängiger Kern in `src/Zeit/` (zwei Tabellen, `Stempeluhr`, `ZeitRechner`, Interface `ZeitRegeln`) kennt nur Typ-Kürzel + IDs. Die Rheingedeck-Schicht in `src/Services/Zeit/` (Regeln, Bezug aus der Einbuchung, Schalter, je ein Dienst für Portal und Webapp) hängt den Kern an `RecDispoAssignment`. `PortalShell` und `Dispo\Events\Show` bleiben dünn und delegieren an diese Dienste — dort sitzen die Prüfungen und die Tests.

**Tech Stack:** PHP 8, Laravel/Livewire 3, Blade, Eloquent; Tests PHPUnit mit Capsule + SQLite (kein testbench).

**Spec:** `docs/superpowers/specs/2026-11-16-zeiterfassung-stempeln-design.md` — verbindlich, vor jeder Task den genannten Abschnitt lesen.

## Global Constraints

- Arbeitsordner ist NUR diese Arbeitskopie (`.claude/worktrees/zeiterfassung-stempeln`, Zweig `feat/zeiterfassung-stempeln`). Nie in den Hauptordner des Moduls wechseln. Kein `push`, kein `merge`, kein Rebase.
- Nichts außerhalb `platforms-recruiting` ändern. Kein HCM-Modul.
- Kein Schreiben an `rec_employees`/`rec_persons`, keine neue Spalte dort, kein ZAS-Export-Marker, keine Änderung am ZAS-Export.
- `src/Zeit/` importiert nichts aus `Platform\Recruiting\…` außer `Platform\Recruiting\Zeit\…` (Fremdbibliotheken wie Illuminate, Carbon, `Symfony\Component\Uid\UuidV7` sind erlaubt).
- Stempeln ist in `EmployeeAssignments` (Einsatz-Seite, nur Token) nie möglich: diese Klasse und ihr Blade bekommen keine Änderung.
- Uhrzeit immer vom Server (`now()`); kein Zeitwert aus dem Browser bei `art = selbst`/`stellvertretend`.
- Schalter `zeit_stempeln_aktiv` Standard aus; aus heißt: keine Kachel, keine Liste, keine Spalte, jede Aktion verweigert serverseitig.
- Portal-Texte als `$duzen`-Ternary (Du/Sie). Texte wörtlich aus der Spec §3/§4.
- Blade: keine an Wortzeichen geklebten Direktiven (`da@if`), kein inline `@php(...)` ohne `@endphp`; Werte vorberechnen. Datums-/Zeitfelder nie per `wire:model` an datetime-Casts binden — String-Properties (`Y-m-d\TH:i`) benutzen.
- Tests: Aufruf aus der Arbeitskopie `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml <Pfad>` (geprüft 16.11.: `tests/Unit/NachnameZuerstTest.php` → OK). Blade: `php tools/blade-check.php` → „0 mit Funden“.
- SQLite-Spaltenlücke: jede Testklasse lädt alle Migrationen, deren Spalten sie liest oder schreibt (Basisklasse `ZeitTestCase`, Task 1). Kein `order-by=random`. `Carbon::setTestNow()` in `tearDown` zurücksetzen. Wer `TestPortalSitzung::oeffne()` ruft, ruft `aufraeumen()` in `tearDown`.
- Vor einer Mutationsprobe committen (nie `git checkout -- datei` auf uncommittete Arbeit). Kein bares `git stash`.
- Commit je Task, Nachricht deutsch im Stil des Repos (`feat(recruiting): Zeiterfassung — …`), Trailer `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Am Ende jeder Task: betroffene Tests grün; am Ende von Task 8 die ganze Suite (`… -c phpunit.xml`, ca. 2 Minuten) und `blade-check`.

## Review Focus

1. **Fremde Einbuchung** (Spec §7.1): Stempel-Aufruf mit der ID einer Einbuchung einer anderen Person → `nicht_erlaubt`, kein Datensatz. Test in Task 4.
2. **Ohne Sitzung / gesperrtes Portal / Schalter aus** (§7.2): jede Stempel-Aktion verweigert, auch per direktem Methodenaufruf. Tests in Task 4 (Dienst: Schalter, Sperre; `PortalShell`: Sitzung).
3. **Nachtschicht 22:00–04:00** (§7.3): Soll-Ende am Folgetag, Gehen um 04:10 gültig, 05:01 `zu_spaet`. Tests in Task 3 (Bezug, Regeln) und Task 4 (durchgängig).
4. **Doppelklick / zwei Geräte** (§7.4): genau ein Datensatz, genau ein Verlaufseintrag je Stempel. Test in Task 2.
5. **Einbuchung ändert sich nach dem Kommen** (§7.5): verschoben (`confirmed_at` leer), verschwunden (`missing_since`), abgesagt, Person in ZAS getauscht → Datensatz bleibt, „Gehen“ geht, Webapp findet ihn. Tests in Task 4 (drei Zustände) und Task 8 (Personenwechsel).

## Dateien im Überblick

| Datei | Zweck | Task |
|---|---|---|
| `database/migrations/2026_11_16_000001_create_rec_zeit_eintraege_table.php` | Tabelle Zeitdatensatz | 1 |
| `database/migrations/2026_11_16_000002_create_rec_zeit_verlauf_table.php` | Tabelle Verlauf | 1 |
| `src/Zeit/{ZeitEintrag,ZeitVerlauf,ZeitBezug,ZeitAkteur,ZeitRegeln,ZeitRechner}.php` | Kern-Daten und Rechnen | 1 |
| `src/Zeit/Stempeluhr.php` | Kern-Dienst | 2 |
| `src/Services/Zeit/{RheingedeckZeitRegeln,DispoZeitBezug,ZeitSchalter}.php` | Rheingedeck-Regeln, Bezug, Schalter | 3 |
| `src/Services/Zeit/PortalStempeln.php` | Portal-Logik | 4, 5 |
| `src/Services/Zeit/DispoZeiten.php` | Webapp-Logik | 6, 7 |
| `resources/views/livewire/public/_stempel-kachel.blade.php`, `_meine-zeiten.blade.php` | Portal-Ansichten | 4, 5 |
| `resources/views/livewire/dispo/events/_zeit-fenster.blade.php` | Fenster „Zeit“ | 6, 7 |
| `tests/Integration/ZeitTestCase.php` | Test-Basis | 1 |

---

### Task 1: Kern-Daten — Migrationen, Modelle, Wertobjekte, `ZeitRechner`

Spec §2.1.

**Files:**
- Create: die zwei Migrationen (Muster `database/migrations/2026_10_12_000002_create_rec_hr_vermerke_table.php`: anonyme Klasse, `Schema::hasTable`-Guard, keine Fremdschlüssel)
- Create: `src/Zeit/ZeitEintrag.php`, `ZeitVerlauf.php`, `ZeitBezug.php`, `ZeitAkteur.php`, `ZeitRegeln.php`, `ZeitRechner.php`
- Test: `tests/Integration/ZeitTestCase.php`, `tests/Integration/ZeitEintragTest.php`, `tests/Unit/ZeitRechnerTest.php`

**Interfaces — Produces:**
- Tabellen exakt nach Spec §2.1. `rec_zeit_eintraege`: Unique-Index über (`person_typ`, `person_id`, `bezug_typ`, `bezug_id`), Index auf (`bezug_typ`, `bezug_id`) und (`person_typ`, `person_id`, `tag`). `rec_zeit_verlauf`: Index auf `rec_zeit_eintrag_id`, nur `created_at`.
- `final class ZeitBezug { public function __construct(public readonly string $personTyp, public readonly int $personId, public readonly string $bezugTyp, public readonly int $bezugId, public readonly string $tag /* Y-m-d */, public readonly ?\Carbon\CarbonImmutable $sollVon, public readonly ?\Carbon\CarbonImmutable $sollBis, public readonly ?int $teamId = null) }`
- `final class ZeitAkteur { public function __construct(public readonly string $typ, public readonly ?int $id, public readonly string $name) }`
- `interface ZeitRegeln { fruehestesKommen(ZeitBezug $b): ?\Carbon\CarbonImmutable; spaetestesStempeln(ZeitBezug $b): ?\Carbon\CarbonImmutable; pauseMinuten(int $anwesendMinuten): int; }`
- `ZeitEintrag extends Model`: Tabelle `rec_zeit_eintraege`, `$guarded = []`, Casts `tag` date, die sechs `_at` datetime, `pause_minuten` integer; `uuid` beim Anlegen (UuidV7); `verlauf(): HasMany` (neueste zuerst über `id desc`); `zustand(): string` → `erledigt` (gesperrt_at gesetzt) | `ausgecheckt` (gehen_at) | `eingecheckt` (kommen_at) | `offen`; `istGesperrt(): bool`; `static finde(string $personTyp, int $personId, string $bezugTyp, int $bezugId): ?self`.
- `ZeitVerlauf extends Model`: Tabelle `rec_zeit_verlauf`, `const UPDATED_AT = null`, `$guarded = []`.
- `final class ZeitRechner` (statisch, rein): `anwesendMinuten(?\DateTimeInterface $kommen, ?\DateTimeInterface $gehen): ?int` (null, wenn eines fehlt; nie negativ), `pauseMinuten(?int $gesetzt, ?int $anwesend, ZeitRegeln $regeln): int` (gesetzter Wert gewinnt, sonst Regel, ohne Anwesenheit 0), `arbeitsMinuten(?int $anwesend, int $pause): ?int` (nie negativ), `verspaetungMinuten(?\DateTimeInterface $kommen, ?\DateTimeInterface $sollVon): int` (nie negativ, 0 wenn eines fehlt), `alsStunden(int $minuten): string` (`465` → `7:45`).
- `abstract class ZeitTestCase extends DressTestCase`: lädt in `setUpBeforeClass` zusätzlich die zwei Zeit-Migrationen sowie `2026_08_20_000002_add_individual_note…`, `2026_09_03_000002_add_note_timestamp…`, `2026_09_08_000001_add_late_marker…` und alle Migrationen, die `confirmed_at`, `reminder_sent_at`, `declined_at`, `missing_since`, `deletion_marked_at` anlegen, soweit `DressTestCase` sie nicht schon lädt (per `Schema::hasColumn` prüfen und im Kommentar festhalten); leert in `setUp` beide Zeit-Tabellen und bindet `ZeitRegeln::class` im Container an `regeln()` (damit `app(Stempeluhr::class)` auch ohne Service-Provider auflöst — gebraucht in Task 8); `tearDown`: Bindung wieder entfernen (`forgetInstance` + `unset($container[ZeitRegeln::class])`), `Carbon::setTestNow()`. Helfer `bezug(int $personId = 901, int $bezugId = 1, string $von = '2026-10-01 14:00', ?string $bis = '2026-10-01 22:00'): ZeitBezug`, `regeln(): ZeitRegeln` (anonyme Klasse: ±60 Minuten, Pause 30 ab >360, 45 ab >540) und `akteur(string $typ = 'user', int $id = 7, string $name = 'Dispo Test'): ZeitAkteur`.

- [ ] **Step 1: Tests schreiben (rot).** `ZeitRechnerTest`: Pause an den Grenzen 360 → 0, 361 → 30, 540 → 30, 541 → 45; gesetzte Pause `0` gewinnt gegen Regel; `anwesendMinuten` mit Gehen vor Kommen → 0; `arbeitsMinuten(20, 30)` → 0; `verspaetungMinuten` 13:52 gegen 14:00 → 0, 14:12 → 12; `alsStunden(465)` → `7:45`, `alsStunden(5)` → `0:05`. `ZeitEintragTest`: Anlegen setzt `uuid`; zweiter Datensatz mit demselben Vierer-Schlüssel wirft `QueryException`; `zustand()` für alle vier Zustände; `finde()` trifft nur den eigenen Schlüssel.
- [ ] **Step 2: Rot bestätigen.** `… phpunit -c phpunit.xml tests/Unit/ZeitRechnerTest.php tests/Integration/ZeitEintragTest.php` → Fehler „Class not found“.
- [ ] **Step 3: Migrationen, Modelle, Wertobjekte, Rechner schreiben.**
- [ ] **Step 4: Grün bestätigen**, dazu `tests/Integration/MassenzuweisungGeschlosseneWeltTest.php` (fährt alle Migrationen; die zwei neuen dürfen nicht auf der Liste der gescheiterten landen).
- [ ] **Step 5: Commit.**

### Task 2: `Stempeluhr` und Architektur-Test

Spec §2.1 „Klassen“ und der Absatz zu Transaktion/Doppelklick.

**Files:**
- Create: `src/Zeit/Stempeluhr.php`
- Test: `tests/Integration/StempeluhrTest.php`, `tests/Unit/ZeitKernArchitekturTest.php`

**Interfaces — Consumes:** alles aus Task 1. **Produces:**
- `public function __construct(private ZeitRegeln $regeln)`
- `kommen(ZeitBezug $b, ZeitAkteur $a, string $art, ?\DateTimeInterface $zeit = null): string` → `ok | schon | zu_frueh | zu_spaet | gesperrt`
- `gehen(ZeitBezug $b, ZeitAkteur $a, string $art, ?\DateTimeInterface $zeit = null): string` → `ok | kein_kommen | schon | zu_spaet | gesperrt`
- `korrigieren(ZeitBezug $b, ZeitAkteur $a, array $werte, string $grund): string` → `ok | gesperrt | ungueltig | unveraendert`
- `umhaengen(string $bezugTyp, int $vonBezugId, int $nachBezugId, string $personTyp, int $personId): void`
- `sperren(ZeitEintrag $e, ZeitAkteur $a, string $grund): string` → `ok | schon`
- `entsperren(ZeitEintrag $e, ZeitAkteur $a, string $grund): string` → `ok | nicht_gesperrt | ungueltig` (leerer Grund)
- Konstanten `ART_SELBST = 'selbst'`, `ART_STELLVERTRETEND = 'stellvertretend'`, `ART_KORREKTUR = 'korrektur'`, `ART_SPERRE = 'sperre'`.

Regeln, die die Signaturen nicht festlegen:
- Fenster nur bei `ART_SELBST`, gegen den übergebenen `ZeitBezug`: `fruehestesKommen = null` → `zu_frueh`; `spaetestesStempeln = null` → keine obere Grenze.
- Jedes Schreiben zieht `soll_von_at`, `soll_bis_at`, `tag`, `team_id` aus dem Bezug nach.
- Verlauf: je geändertem Feld eine Zeile; Zeitwerte als `Y-m-d H:i:s`, Pause als Ziffern, leer als `null`; `kommen`/`gehen` schreiben `art` wie übergeben, `korrigieren` immer `korrektur`, Sperren/Öffnen `sperre` mit Feld `gesperrt_at`.
- `korrigieren`: nur Schlüssel, die in `$werte` vorhanden sind (`array_key_exists`), werden angefasst. `ungueltig` bei leerem Grund, Gehen vor Kommen, `kommen_at = null` mit gesetztem Gehen (nach Anwenden der Werte), `pause_minuten` außerhalb 0–600. Nichts geändert → `unveraendert`, kein Verlauf, kein Datensatz.
- Nach dem Leeren (`kommen_at`/`gehen_at` = null) gibt `kommen()` wieder `ok`.
- Transaktion mit `lockForUpdate()`; verliert das Anlegen das Rennen am Unique-Index (`QueryException`), neu lesen und wie „Datensatz existiert“ weiterbehandeln.
- `umhaengen`: ändert nur `bezug_id` des Datensatzes dieser Person; kein Verlauf; hat das Ziel für diese Person schon einen Datensatz, nichts tun.

- [ ] **Step 1: Tests schreiben (rot).** `StempeluhrTest` (Zeit mit `Carbon::setTestNow`):
  - `test_kommen_und_gehen_schreiben_je_einen_verlauf`: ok/ok, ein Datensatz, zwei Verlaufszeilen (`kommen_at`, `gehen_at`, `art = selbst`, `akteur_name`).
  - `test_doppeltes_kommen_ist_schon_ohne_zweiten_verlauf` (Review Focus 4): zweimal `kommen` → `ok`, `schon`; `ZeitEintrag::count() === 1`, `ZeitVerlauf::count() === 1`. Dasselbe für `gehen`.
  - `test_fenster_gilt_nur_fuer_selbst`: 12:59 → `zu_frueh`, 13:00 → `ok`; Gehen 23:01 → `zu_spaet`; mit `ART_STELLVERTRETEND` um 10:00 → `ok`.
  - `test_ohne_beginn_kein_selbst_stempeln`: Bezug mit `sollVon = null` → `zu_frueh`, stellvertretend `ok`.
  - `test_verlaengerte_schicht_gilt_beim_gehen`: Kommen mit Ende 22:00, Gehen 23:30 mit Bezug Ende 24:00 → `ok`, `soll_bis_at` nachgezogen.
  - `test_gehen_ohne_kommen`: `kein_kommen`, kein Datensatz.
  - `test_korrektur_legt_an_und_protokolliert_alt_neu`: ohne Datensatz `korrigieren(['kommen_at' => …, 'gehen_at' => …], 'vergessen')` → `ok`; danach Gehen 22:00 → 22:30: Verlauf `alt = '2026-10-01 22:00:00'`, `neu = '2026-10-01 22:30:00'`, `grund`, `art = korrektur`.
  - `test_korrektur_ungueltig`: leerer Grund; Gehen vor Kommen; Kommen leeren bei gesetztem Gehen; Pause 601 — je `ungueltig`, nichts geschrieben.
  - `test_unveraendert_schreibt_nichts`.
  - `test_leeren_und_neu_stempeln`: beide Zeiten auf null → `ok`, Datensatz und Verlauf bleiben, `kommen()` danach `ok`.
  - `test_sperre_blockt_alles`: nach `sperren` liefern `kommen`, `gehen`, `korrigieren` `gesperrt`; `sperren` nochmal `schon`; `entsperren` ohne Grund `ungueltig`, mit Grund `ok`, Verlauf zwei Zeilen `gesperrt_at`.
  - `test_umhaengen_zieht_nur_die_person_um`: zwei Personen am selben Bezug 1; `umhaengen(…, 1, 2, …, 901)` → nur 901 hängt an 2, Verlauf unverändert.
  `ZeitKernArchitekturTest`: jede `*.php` unter `src/Zeit/` enthält kein `Platform\Recruiting\` außer `Platform\Recruiting\Zeit\` (Regex über `use`-Zeilen und voll qualifizierte Namen); Zusicherung, dass mindestens 7 Dateien geprüft wurden.
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: `Stempeluhr` schreiben.**
- [ ] **Step 4: Grün bestätigen.**
- [ ] **Step 5: Commit.**

### Task 3: Rheingedeck-Schicht — Regeln, Bezug, Schalter, Einstellungen

Spec §2.2, §5.

**Files:**
- Create: `src/Services/Zeit/RheingedeckZeitRegeln.php`, `DispoZeitBezug.php`, `ZeitSchalter.php`
- Modify: `src/RecruitingServiceProvider.php` (bei den vorhandenen `bind`-Aufrufen ab Zeile 128: `ZeitRegeln::class` → `RheingedeckZeitRegeln::class`)
- Modify: `src/Livewire/Dispo/Settings.php` (`mount()` ab :74, `save()` ab :195), `resources/views/livewire/dispo/settings.blade.php` (neuer Block nach dem „Nur Veranstaltungen“-Feld um :251)
- Modify: `src/Livewire/Applicant/ApplicantSettingsModal.php:315` (Schlüssel-Liste erweitern)
- Test: `tests/Unit/RheingedeckZeitRegelnTest.php`, `tests/Integration/DispoZeitBezugTest.php`, `tests/Integration/ZeitSchalterTest.php` (Vorbild für Settings im Capsule-Test: `tests/Integration/DispoAccessTest.php`, `DispoSettingsVersandwegTest.php`)

**Interfaces — Produces:**
- `final class RheingedeckZeitRegeln implements ZeitRegeln`, `const VOR_MINUTEN = 60`, `const NACH_MINUTEN = 60`; `fruehestesKommen` = `sollVon − 60` oder null; `spaetestesStempeln` = `sollBis + 60` oder null; Pause `> 360` → 30, `> 540` → 45, sonst 0.
- `final class DispoZeitBezug`: `const PERSON_TYP = 'rec_employee'`, `const BEZUG_TYP = 'rec_dispo_assignment'`; `static fuer(RecDispoAssignment $a, ?int $teamId = null): ?ZeitBezug` — null ohne `rec_employee_id`. `sollVon` aus `datum` + `von` (Regex `^(\d{2}):(\d{2})`), null ohne gültiges `von`. `sollBis` aus `datum` + `bis`; ist `bis <= von`, Folgetag; fehlt `bis`, 24:00 des Einsatztags (= 00:00 Folgetag). Zeitzone `config('app.timezone')`, Rückfall `Europe/Berlin`. Dazu `static ausEintrag(ZeitEintrag $e): ZeitBezug` (aus den gespeicherten Feldern, für Einbuchungen, die es nicht mehr gibt).
- `class ZeitSchalter` (nicht final, nicht statisch — Tests binden eine Attrappe in den Container): `const SETTING_AKTIV = 'zeit_stempeln_aktiv'`, `const SETTING_ENTSPERREN = 'zeit_entsperren_emails'`; `teamId(): int` (`config('recruiting.zas.inbound_team_id')`, sonst `currentTeam` des angemeldeten Nutzers, sonst 0 — jeder Fehler → 0); `aktiv(): bool` (Team 0 oder Fehler → false); `darfEntsperren(mixed $user): bool` (E-Mail kleingeschrieben in der Liste; leere Liste → false).
- `Dispo\Settings`: Properties `public string $zeitStempelnAktiv = ''`, `public string $zeitEntsperrenEmails = ''`; `save()` normalisiert die Liste wie `eventOnlyEmails` (eine Adresse je Zeile, klein, dedupliziert) und schreibt beide Schlüssel per `setSetting`. Blade-Texte: Schalter „Stempeln im Mitarbeiter-Portal (Test)“ mit Hinweis „Gilt nur für Mitarbeiter im neuen Portal. Die Zeiten gehen nicht an ZAS.“; Feld „Darf erledigte Zeiten wieder öffnen (E-Mail-Adressen)“ mit Hinweis „Eine Adresse pro Zeile. Leer heißt: niemand.“
- `ApplicantSettingsModal::save()`: beide Zeit-Schlüssel in die Schleife der immer aus dem gespeicherten Stand übernommenen Schlüssel.

- [ ] **Step 1: Tests schreiben (rot).**
  - `RheingedeckZeitRegelnTest`: 14:00–22:00 → frühestens 13:00, spätestens 23:00; `sollVon = null` → null; Pausenstufen wie Task 1.
  - `DispoZeitBezugTest`: `test_nachtschicht_endet_am_folgetag` (Review Focus 3: `datum 2026-10-01`, 22:00–04:00 → `sollBis = 2026-10-02 04:00`); `test_gleiche_zeit_ist_folgetag` (20:00–20:00); `test_ohne_bis_gilt_tagesende` (`sollBis = 2026-10-02 00:00`); `test_ohne_von_ist_soll_von_null`; `test_ohne_person_null`; `test_typ_kuerzel` (`rec_employee`/`rec_dispo_assignment`, `tag = '2026-10-01'`).
  - `ZeitSchalterTest`: ohne Team-Konfiguration `aktiv() === false`; mit Setting true → true; `darfEntsperren` trifft unabhängig von Groß-/Kleinschreibung, leere Liste false; `ApplicantSettingsModal`-Erhalt als Quelltext-Vertrag (beide Konstanten stehen in der Schlüssel-Schleife bei :315) — wie der bestehende Test zu `DispoJobVersandweg` es hält.
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Klassen, Provider-Bindung, Einstellungen, Blade schreiben.**
- [ ] **Step 4: Grün bestätigen** (inkl. `tests/Integration/DispoSettingsVersandwegTest.php`), `php tools/blade-check.php`.
- [ ] **Step 5: Commit.**

### Task 4: Portal — Stempel-Kachel und Aktionen

Spec §3 bis einschließlich „Aktionen“.

**Files:**
- Create: `src/Services/Zeit/PortalStempeln.php`, `resources/views/livewire/public/_stempel-kachel.blade.php`
- Modify: `src/Livewire/Public/PortalShell.php` (neue Aktionen neben den übrigen schreibenden Aktionen; `render()` :1321/:1388 gibt `stempelZeilen` mit), `resources/views/livewire/public/portal-shell.blade.php` (Reiter „start“, vor dem Block „Dein nächster Einsatz“ bei :276)
- Test: `tests/Integration/PortalStempelnTest.php`, `tests/Integration/PortalShellStempelnTest.php`

**Interfaces — Consumes:** `Stempeluhr`, `DispoZeitBezug`, `ZeitSchalter`, `EmployeeAssignments::sichtbareEinsaetze(array $ids, \DateTimeInterface $jetzt)`, `DispoIdentityResolver::groupFor(int)`. **Produces:**
- `class PortalStempeln { __construct(private Stempeluhr $uhr, private ZeitSchalter $schalter, private DispoIdentityResolver $identitaet, private ZeitRegeln $regeln) }`
- `zeilen(RecEmployee $angemeldet, \DateTimeInterface $jetzt): array` → Liste von `['assignment_id' => int, 'name' => string, 'tag' => 'd.m.Y', 'soll' => '14:00–22:00', 'zustand' => 'offen|eingecheckt|ausgecheckt|erledigt', 'kommen' => ?'H:i', 'gehen' => ?'H:i', 'pause' => ?int, 'arbeitszeit' => ?'7:45', 'kann_kommen' => bool, 'kann_gehen' => bool]`. Leer bei Schalter aus oder wenn ein Datensatz der Identitätsgruppe `portal_locked_at` trägt.
- `kommen(RecEmployee $angemeldet, int $assignmentId, \DateTimeInterface $jetzt): string` → Rückgaben der `Stempeluhr` plus `aus` (Schalter) und `nicht_erlaubt`.
- `gehen(RecEmployee $angemeldet, int $assignmentId, \DateTimeInterface $jetzt): string` → ebenso.
- `static meldung(string $status, ?\DateTimeInterface $fruehestens, bool $duzen): ?string` → null bei `ok`/`schon`; `zu_frueh` „zu früh – ab 13:00 möglich“; `zu_spaet` „Zeitfenster vorbei – bitte bei der Disposition melden“ (Du) / „… melden Sie sich bitte bei der Disposition“ (Sie); `gesperrt` „gesperrt“; `aus`/`nicht_erlaubt`/`kein_kommen` „Das geht gerade nicht.“
- `PortalShell`: `public ?string $stempelMeldung = null`, `public function stempelKommen(int $assignmentId): void`, `public function stempelGehen(int $assignmentId): void` — beide beginnen mit `$employee = $this->berechtigterMitarbeiter(); if (!$employee) return;` und rufen dann den Dienst mit `now()`.

Zwei Wege, die Zeilen zu finden (beide über `$ids = groupFor($angemeldet->id)`):
1. **Regulär:** `sichtbareEinsaetze($ids, $jetzt)`, davon nur `confirmed_at !== null`, mit Bezug (`DispoZeitBezug::fuer`), und `jetzt` zwischen `fruehestesKommen` und `spaetestesStempeln`. `kann_kommen` wenn kein Kommen gestempelt.
2. **Offener Stempel:** `ZeitEintrag` mit `person_typ = rec_employee`, `person_id ∈ $ids`, `bezug_typ = rec_dispo_assignment`, `kommen_at` gesetzt, `gehen_at` leer, nicht gesperrt — unabhängig vom Zustand der Einbuchung. Bezug: gibt es die Einbuchung noch **und** gehört sie derselben Anstellung, `DispoZeitBezug::fuer`; sonst `DispoZeitBezug::ausEintrag`. Name aus der Einbuchung (`event->name`), sonst „Einsatz“. `kann_gehen` solange `jetzt <= spaetestesStempeln` (oder keine Grenze).
Doppelte (Zeile aus beiden Wegen) nach `assignment_id` zusammenführen.

`kommen()` erlaubt nur Weg 1. `gehen()` erlaubt Weg 1 oder Weg 2. Alles andere → `nicht_erlaubt`. Akteur: `new ZeitAkteur('rec_employee', $anstellungDerEinbuchung, trim(first_name . ' ' . last_name))`.

Kachel (Texte Spec §3 „Was die Kachel zeigt“): je Zeile Veranstaltung, Tag, Soll; Knopf „Kommen“ (`wire:click="stempelKommen({{ id }})"`, `wire:loading.attr="disabled"`), danach „Gekommen um 13:52“ + Knopf „Gehen“, danach Zusammenfassung „13:52 – 22:07, Pause 30 Min, Arbeitszeit 7:45 Std“ + Hinweissatz; `$stempelMeldung` darunter. Keine Zeilen → das Partial gibt nichts aus. Fehler beim Laden kosten nur die Kachel (try/catch + Log-Warnung wie `naechsterEinsatz()` :1075).

- [ ] **Step 1: Tests schreiben (rot).** `PortalStempelnTest extends ZeitTestCase` (Schalter-Attrappe an, `Carbon::setTestNow('2026-10-01 13:30')`, Einbuchung 14:00–22:00, `confirmed_at` gesetzt, `reminder_sent_at` gesetzt; leere Tabelle `rec_employees` anlegen wie in `DispoInfoImSendefensterTest`, soweit nötig mit `portal_locked_at`):
  - `test_zeile_erscheint_im_fenster_und_kommen_geht`: eine Zeile, `kann_kommen`; `kommen` → `ok`; Zeile danach `eingecheckt`, `kann_gehen`.
  - `test_vor_dem_fenster_keine_zeile` (12:59) und `test_unbestaetigt_keine_zeile`.
  - `test_fremde_einbuchung_wird_verweigert` (Review Focus 1): Einbuchung von Anstellung 950, angemeldet 901 → `kommen`/`gehen` = `nicht_erlaubt`, `ZeitEintrag::count() === 0`.
  - `test_schalter_aus_verweigert_und_zeigt_nichts` (Review Focus 2): Attrappe aus → `zeilen() === []`, `kommen` = `aus`.
  - `test_gesperrtes_portal_zeigt_nichts`.
  - `test_nachtschicht_durchgaengig` (Review Focus 3): 22:00–04:00, Kommen 21:30, Gehen am Folgetag 04:10 → `ok`; 05:01 → `zu_spaet`.
  - `test_offener_stempel_schlaegt_alles` (Review Focus 5), drei Unterfälle nach dem Kommen: `confirmed_at = null`; `missing_since` gesetzt; `declined_at` gesetzt → jeweils weiter eine Zeile mit `kann_gehen`, `gehen` → `ok`.
  - `test_einbuchung_geloescht_gehen_bleibt_moeglich`: Zeile der Einbuchung löschen → Zeile mit Name „Einsatz“, `gehen` → `ok`.
  - `test_nach_gehen_zusammenfassung`: 13:52–22:07 → `pause 30`, `arbeitszeit '7:45'`.
  `PortalShellStempelnTest` (Vorbild `PortalShellVerifyTest` + `TestPortalSitzung`): `test_ohne_sitzung_passiert_nichts` (Review Focus 2: `state = 'verified'`, keine Sitzung geöffnet → `stempelKommen(…)` legt nichts an, `state === 'unverified'`); `test_mit_sitzung_wird_gestempelt`; Quelltext-Vertrag: `EmployeeAssignments.php` und `employee-assignments.blade.php` enthalten weder `stempel` noch `Stempeluhr`.
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Dienst, Aktionen, Partial, Einbindung schreiben.**
- [ ] **Step 4: Grün bestätigen**, dazu alle `tests/Integration/PortalShell*Test.php`, `php tools/blade-check.php`.
- [ ] **Step 5: Commit.**

### Task 5: Portal — „Meine Zeiten“

Spec §3 letzter Absatz.

**Files:**
- Modify: `src/Services/Zeit/PortalStempeln.php`, `src/Livewire/Public/PortalShell.php` (`render()` gibt `meineZeiten` mit), `resources/views/livewire/public/portal-shell.blade.php` (Reiter „jobs“, nach der Einbettung bei :558)
- Create: `resources/views/livewire/public/_meine-zeiten.blade.php`
- Test: `tests/Integration/PortalMeineZeitenTest.php`

**Interfaces — Produces:**
- `PortalStempeln::meineZeiten(RecEmployee $angemeldet, \DateTimeInterface $jetzt): array` → Liste, neueste zuerst (`tag desc`, `id desc`), von `['tag' => 'd.m.Y', 'name' => ?string, 'kommen' => ?'H:i', 'gehen' => ?'H:i', 'pause' => ?int, 'arbeitszeit' => ?string, 'zustand' => 'eingecheckt|ausgecheckt|erledigt', 'vermerk' => null|'korrigiert'|'von der Disposition erfasst']`. Nur Datensätze der Identitätsgruppe mit gesetztem `kommen_at`, `tag >= jetzt − 3 Monate`. `vermerk`: Verlauf mit `art = korrektur` → „korrigiert“; sonst mit `art = stellvertretend` → „von der Disposition erfasst“. Leer bei Schalter aus. Name über die Einbuchung, falls vorhanden, sonst null (Blade zeigt dann nur den Tag). Einbuchungen und Verlaufs-Arten gebündelt laden (kein N+1).
- Zustandstexte im Blade: „eingecheckt“, „ausgecheckt“, „erledigt“. Überschrift „Meine Zeiten“. Ohne Zeilen keine Ausgabe.

- [ ] **Step 1: Tests schreiben (rot).** `test_nur_eigene_datensaetze` (fremder Datensatz fehlt); `test_korrektur_vermerk_ohne_namen_und_grund` (nach `korrigieren`: `vermerk === 'korrigiert'`, das Zeilen-Array enthält weder den Akteur-Namen noch den Grund-Text); `test_stellvertretend_vermerk`; `test_verschwundene_einbuchung_bleibt_sichtbar` (`name === null`); `test_aelter_als_drei_monate_fehlt`; `test_schalter_aus_leer`; `test_korrigierte_zeit_wird_gezeigt` (Gehen 22:00 → 22:30 korrigiert → `gehen === '22:30'`).
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Methode, Partial, Einbindung schreiben.**
- [ ] **Step 4: Grün bestätigen**, `php tools/blade-check.php`.
- [ ] **Step 5: Commit.**

### Task 6: Webapp — Spalte und Fenster „Zeit“ (Korrektur, stellvertretend, Verlauf)

Spec §4 bis „toggleLate bleibt unberührt“.

**Files:**
- Create: `src/Services/Zeit/DispoZeiten.php`, `resources/views/livewire/dispo/events/_zeit-fenster.blade.php`
- Modify: `src/Livewire/Dispo/Events/Show.php` (neuer Abschnitt nach `toggleLate()` :1735), `resources/views/livewire/dispo/events/show.blade.php` (Kopfzeile :416–428, Zeilen ab :474, Einbindung des Fensters bei den übrigen Fenstern)
- Modify: `tests/Unit/SharedPartialContractTest.php:57` (Eintrag `'dispo/events/_zeit-fenster'` → `Show::class`)
- Test: `tests/Integration/DispoZeitenTest.php`, `tests/Integration/DispoZeitFensterTest.php`

**Interfaces — Produces:**
- `class DispoZeiten { __construct(private Stempeluhr $uhr, private ZeitSchalter $schalter, private ZeitRegeln $regeln) }`
  - `fuerEvent(int $eventId): array` → `[assignmentId => ZeitEintrag]`, nur Datensätze, deren `person_id` der `rec_employee_id` der Einbuchung entspricht; Verlauf nicht mitladen.
  - `zelle(?ZeitEintrag $e): array` → `['zustand' => 'offen|eingecheckt|ausgecheckt|erledigt', 'text' => 'nicht eingecheckt'|'eingecheckt 13:52'|'ausgecheckt 13:52 – 22:07'|'erledigt 13:52 – 22:07', 'verspaetung' => int, 'gesperrt' => bool]`.
  - `fenster(RecDispoAssignment $a): array` → `['soll' => string, 'kommen' => ?'Y-m-d\TH:i', 'gehen' => ?'Y-m-d\TH:i', 'pause' => ?int, 'regelpause' => int, 'bemerkung' => string, 'zustand' => string, 'gesperrt' => bool, 'verlauf' => list<['wann' => 'd.m. H:i', 'wer' => string, 'feld' => 'Kommen|Gehen|Pause|Bemerkung|Sperre', 'alt' => ?string, 'neu' => ?string, 'grund' => ?string]>]` (neueste zuerst).
  - `korrigieren(RecDispoAssignment $a, ZeitAkteur $akteur, array $werte, string $grund): string`, `jetztKommen(RecDispoAssignment $a, ZeitAkteur $akteur): string`, `jetztGehen(…): string` → Rückgaben der `Stempeluhr` plus `aus` und `ohne_person`.
- `Show`: Computed `zeitAktiv(): bool`, `zeitEintraege(): array`, `zeitFenster(): ?array`; Properties `public bool $showZeitModal = false`, `public ?int $zeitAssignmentId = null`, `public string $zeitKommen = ''`, `$zeitGehen = ''`, `$zeitPause = ''`, `$zeitBemerkung = ''`, `$zeitGrund = ''`, `public ?string $zeitMeldung = null`; Aktionen `openZeit(int $assignmentId)`, `closeZeit()`, `zeitSpeichern()`, `zeitJetztKommen()`, `zeitJetztGehen()`.
- Jede schreibende Aktion: `zeitAktiv`, `!blockedForEventOnly()`, Einbuchung per `where('rec_dispo_event_id', $this->eventId)->whereKey(...)` neu laden (Muster `toggleLate`), Akteur `new ZeitAkteur('user', auth()->id(), (string) auth()->user()?->name)`. Danach `unset($this->zeitEintraege, $this->zeitFenster)`.
- `zeitSpeichern()`: leere Strings → null; `zeitPause` leer → null (Regel), sonst Ganzzahl; Status → Meldung: `ungueltig` „Bitte Grund angeben und Zeiten prüfen (Gehen nicht vor Kommen).“, `gesperrt` „Der Eintrag ist erledigt und gesperrt.“, `unveraendert` „Nichts geändert.“; bei `ok` Formular aus `fenster()` neu füllen und `zeitGrund` leeren.
- Spaltenkopf **„Stempel“** (siehe Abweichungen), eigene `<th>`/`<td>` nach „Bestätigung“, nur bei `$this->zeitAktiv`. Zelle: Text aus `zelle()`, Verspätung als „+12“, Schloss bei gesperrt; klickbar (`wire:click="openZeit(id)"`) nur mit Person; ohne Person leer. „Nur Veranstaltungen“-Konten sehen den Text, das Fenster öffnet nur lesend (keine Knöpfe).
- Fenster (Partial, greift auf `$this->zeitFenster` zu): Soll-Schicht, zwei `datetime-local`-Felder (`wire:model="zeitKommen"`/`zeitGehen`), Pause mit Platzhalter „nach Regel: 30“, Bemerkung, Pflichtfeld „Grund der Änderung“, Knöpfe „Jetzt kommen“/„Jetzt gehen“/„Speichern“, darunter der Verlauf.

- [ ] **Step 1: Tests schreiben (rot).** `DispoZeitenTest extends ZeitTestCase`: `test_zelle_texte_fuer_alle_zustaende` (inkl. `verspaetung 12` bei Kommen 14:12); `test_fuer_event_nur_passende_person`; `test_korrektur_22_00_auf_22_30_mit_verlauf` (Markus' Fall: `fenster()['verlauf'][0]` trägt `feld 'Gehen'`, `alt '22:00'`-Anteil, `neu`, `grund`, `wer`); `test_jetzt_kommen_ohne_fensterpruefung`; `test_ohne_person_ohne_datensatz` (`ohne_person`); `test_schalter_aus` (`aus`). `DispoZeitFensterTest` (Show ohne Livewire-Mount über `dispoComponent()` + `ComputedWiring`, View-Attrappe und `DataStore`-Singleton wie `DispoDressSendFormTest`): `test_speichern_ohne_grund_meldet_und_schreibt_nichts`; `test_einbuchung_einer_anderen_veranstaltung_wird_ignoriert`; `test_nur_veranstaltungen_konto_kann_nichts_aendern` (Attrappe für `DispoAccess` über das Setting oder Quelltext-Vertrag: jede der fünf Aktionen prüft `blockedForEventOnly()`); `test_toggle_late_unberuehrt` (Quelltext von `toggleLate` unverändert gegenüber `git show origin/main:src/Livewire/Dispo/Events/Show.php`).
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Dienst, Aktionen, Spalte, Partial schreiben.**
- [ ] **Step 4: Grün bestätigen**, dazu `tests/Unit/SharedPartialContractTest.php`, `tests/Integration/DispoDressSendFormTest.php`, `php tools/blade-check.php`.
- [ ] **Step 5: Commit.**

### Task 7: Erledigt, Sammel-Aktion, Wieder öffnen

Spec §4 „Erledigt“ und „Wieder öffnen“.

**Files:**
- Modify: `src/Services/Zeit/DispoZeiten.php`, `src/Livewire/Dispo/Events/Show.php`, `resources/views/livewire/dispo/events/_zeit-fenster.blade.php`, `resources/views/livewire/dispo/events/show.blade.php` (Knopf über der Tabelle)
- Test: `tests/Integration/DispoZeitErledigtTest.php`

**Interfaces — Produces:**
- `DispoZeiten::erledigt(RecDispoAssignment $a, ZeitAkteur $akteur): string` → `ok | schon | nicht_ausgecheckt | aus | ohne_person`
- `DispoZeiten::alleErledigt(iterable $assignments, ZeitAkteur $akteur): int` (Anzahl neu gesperrter; nur Zustand `ausgecheckt`)
- `DispoZeiten::oeffnen(RecDispoAssignment $a, ZeitAkteur $akteur, string $grund, mixed $user): string` → `ok | nicht_berechtigt | nicht_gesperrt | ungueltig | aus`
- `Show`: Aktionen `zeitErledigt()`, `zeitAlleErledigt()`, `zeitOeffnen()`; Computed `zeitErledigbar(): int` (ausgecheckte, nicht gesperrte unter `filteredAssignments()`), `zeitDarfOeffnen(): bool`.
- Fenster: Knopf „Erledigt“ nur bei `ausgecheckt` und vollem Zugriff; bei `erledigt` alle Felder nur als Anzeige, Text „Erledigt und gesperrt.“; „Wieder öffnen“ mit Grund-Feld nur bei `zeitDarfOeffnen`.
- Über der Tabelle (nur wenn `zeitErledigbar > 0` und voller Zugriff): „Alle ausgecheckten als erledigt markieren (N)“ mit `wire:confirm="N Einträge als erledigt markieren? Danach sind sie gesperrt."`. Wirkt auf die **aktuell gefilterten Zeilen** (siehe Abweichungen).

- [ ] **Step 1: Tests schreiben (rot).** `test_erledigt_sperrt_auch_die_disposition` (danach `korrigieren`, `jetztGehen` → `gesperrt`; Portal-`gehen` irrelevant, da ausgecheckt); `test_erledigt_nur_wenn_ausgecheckt` (`nicht_ausgecheckt` bei `eingecheckt` und ohne Datensatz); `test_sammel_aktion_trifft_nur_ausgecheckte` (drei Einbuchungen: ausgecheckt, eingecheckt, schon erledigt → Rückgabe 1); `test_oeffnen_nur_mit_listen_eintrag` (leere Liste `nicht_berechtigt`; gelistet ohne Grund `ungueltig`; mit Grund `ok`, Verlauf trägt Grund und `art = sperre`); `test_erledigte_zeile_zeigt_schloss` (`zelle()['gesperrt']`).
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Methoden, Aktionen, Blade schreiben.**
- [ ] **Step 4: Grün bestätigen**, `php tools/blade-check.php`.
- [ ] **Step 5: Commit.**

### Task 8: Personenwechsel — Zeit zieht mit der alten Person um; Gesamtlauf

Spec §2.2 „Personenwechsel“, §7.5.

**Files:**
- Modify: `src/Services/Zas/Dispo/DispoPersonenwechsel.php` (`verweiseUmziehen()` :247)
- Test: `tests/Integration/ZeitPersonenwechselTest.php`

**Interfaces — Consumes:** `Stempeluhr::umhaengen`, `DispoZeitBezug::PERSON_TYP/BEZUG_TYP`. **Produces:** in `verweiseUmziehen()` ein dritter Block: `if (Schema::hasTable('rec_zeit_eintraege') && $alt->rec_employee_id !== null) { app(Stempeluhr::class)->umhaengen(DispoZeitBezug::BEZUG_TYP, $liveId, $ablageId, DispoZeitBezug::PERSON_TYP, (int) $alt->rec_employee_id); }`.

- [ ] **Step 1: Tests schreiben (rot).** `test_zeit_zieht_in_die_ablage_zeile` (Kommen für Person 901 an Einbuchung X; `(new DispoPersonenwechsel())->archivieren($dsRef, 'RG999')`; danach hängt der Datensatz an der `~alt1`-Zeile, an X hängt keiner); `test_alte_person_kann_nach_dem_wechsel_gehen` (Review Focus 5: X gehört jetzt Anstellung 950; `PortalStempeln::zeilen` für 901 zeigt weiter eine Zeile mit `kann_gehen`, `gehen` mit der ID der Ablage-Zeile → `ok`; für 950 ist X frisch — `kommen` im Fenster → `ok`, zwei getrennte Datensätze); `test_webapp_findet_beide` (`DispoZeiten::fuerEvent` liefert je einen Datensatz an X und an der Ablage-Zeile); `test_ohne_zeit_tabelle_laeuft_der_wechsel_weiter` (vorhandene Klasse `ZasDispoPersonenwechselImportTest` bleibt grün — sie lädt die Zeit-Migrationen nicht, der `hasTable`-Guard muss also vor dem Auflösen der `Stempeluhr` greifen).
- [ ] **Step 2: Rot bestätigen.**
- [ ] **Step 3: Block ergänzen.**
- [ ] **Step 4: Grün bestätigen**, dann die **ganze Suite** und `php tools/blade-check.php`. Erwartet: keine Fehler, „0 mit Funden“.
- [ ] **Step 5: Commit.**

---

## Abweichungen von der Spec und offene Punkte

- **Spaltenname:** Die Einbuchungs-Tabelle hat schon eine Spalte „Zeit“ (Anfangszeit, `show.blade.php:420`). Die neue Spalte heißt deshalb „Stempel“; das Fenster heißt weiter „Zeit“.
- **„Für den gewählten Tag“:** Die Seite kennt keinen einzelnen gewählten Tag, nur Spaltenfilter (`rowDays`, mehrfach wählbar). Die Sammel-Aktion wirkt auf die aktuell gefilterten Zeilen; der Knopf nennt die Anzahl.
- **Schalter als Objekt:** `ZeitSchalter` ist eine Klasse mit Instanz-Methoden statt statischer Funktionen, damit Tests ihn ersetzen können, ohne `inbound_team_id` zu setzen (das würde `DispoIdentityResolver` scharf schalten und `crm_contact_links` verlangen).
- **`sperren()` im Kern** prüft nicht, ob ausgecheckt ist; diese Regel sitzt in `DispoZeiten::erledigt` (Rheingedeck-Schicht).
- **Zusätzliche Rückgaben** der Dienste, die die Spec nicht nennt: `aus`, `nicht_erlaubt`, `ohne_person`, `nicht_ausgecheckt`, `nicht_berechtigt`, `nicht_gesperrt`.
- **Abgeschlossene Zeile in der Kachel:** Nach dem Gehen bleibt die Zusammenfassung sichtbar, solange das Fenster offen ist; danach steht die Zeit nur noch unter „Meine Zeiten“.
- **Zweig-Stand:** `origin/main` ist dem Zweig einen Commit voraus (FLYNK, 16.11.). Vor dem Mergen nachziehen; für den Bau ohne Belang.

## Deploy (aus Spec §8)

Zwei Migrationen → Deploy mit `migrate` vor Traffic, danach `view:clear`. Kein `queue:restart`. Schalter bleibt aus.
