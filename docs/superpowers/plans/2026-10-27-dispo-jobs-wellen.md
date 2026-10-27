# Jobs anbieten — Wellenplan, Gesellschaft, gedeckt, Kann nicht, einmal pro Tag — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Das vom Kunden am 27.10. freigegebene Paket (Spec) in einem Tag bauen, ohne die Bedienung zu verkomplizieren.

**Architecture:** Reine Rechenklassen (Planner, Folgewelle, Gesellschaft, Absagen) tragen die Regeln und werden per Unit-Test geprüft; Livewire-Komponenten und der Versand-Job rufen nur auf. Eine Migration ergänzt sechs Spalten. Folgewellen entstehen am Ende des Versand-Jobs als verzögerte Jobs, kein Scheduler.

**Tech Stack:** Laravel/Livewire 3, PHPUnit (Unit pur + Integration mit Capsule/SQLite).

**Spec:** `docs/superpowers/specs/2026-10-27-dispo-jobs-wellen-design.md`

## Global Constraints
- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml [--filter X] < /dev/null`; Blade: `php tools/blade-check.php <datei>`.
- Jede Integration-Testklasse, die `rec_dispo_job_*` anlegt, bekommt die neue Migration `2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php` in ihre Migrationsliste (nach `2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php`).
- Null-Semantik an Wellen: `firmen = null` = beide (alte Welle), `[] = niemand`; `welle_abstand_minuten = null` = keine Folgewelle; `welle_max = null` = alle.
- Kundentexte exakt wie in der Spec (§2.2, §5, §6, §8).
- Status-Schlüssel `'RG'`/`'MA'` (Gesellschaft) sind etwas anderes als `ZasStatus::GO/MA` (Status) — nie vermischen; Konstanten `DispoGesellschaft::RG/MA`.

## Review Focus
1. Person mit RG- und MA-Anstellung: Gesellschaft nur MA gewählt → Person dabei; Zahl je Filiale zählt sie unter RG und unter MA.
2. Obergrenze kappt NACH den Töpfen; wer in Welle 1 „später“ war, ist in Welle 2 nicht „schon angeschrieben“.
3. Folgewelle darf nie entstehen, wenn Anfrage geschlossen, Abstand null, alle Schichten gedeckt oder `rest = 0`; sie darf nie zweimal entstehen (Job läuft idempotent über `started_at`).
4. Einmal pro Tag: Zusage A am Tag X und Zusage B am Tag X aus anderer Anfrage → zweite scheitert mit `tag_belegt`; dieselbe Schicht aus zwei Anfragen (Schwestern) ist KEIN Verstoß.
5. „Kann nicht“ nur bei `anlass = dringend`; Absage ohne Grund wird abgelehnt; Zähler zählt alle Anstellungen der Person über 60 Tage.

---

### Task 1: Migration, Modelle, Konstanten, Gesellschaft (rein)

**Files:**
- Create: `database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php`
- Modify: `src/Models/RecDispoJobRequest.php` (fillable/casts `welle_max`, `welle_abstand_minuten`), `RecDispoJobWave.php` (`firmen` array, `auto` bool), `RecDispoJobShift.php` (`gedeckt_manuell_at` datetime), `RecDispoJobOfferShift.php` (`declined_at` datetime, `declined_reason`, `declined_note`)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobAnlass.php`: `public const WELLEN = ['dringend' => ['max' => 20, 'abstand_minuten' => 360], 'naechste_woche' => ['max' => 50, 'abstand_minuten' => 1440], 'vier_wochen' => ['max' => 50, 'abstand_minuten' => 4320]];` + `public const ABSTAENDE = [0 => 'keine', 120 => '2 Stunden', 360 => '6 Stunden', 720 => '12 Stunden', 1440 => '24 Stunden', 2880 => '2 Tage', 4320 => '3 Tage', 7200 => '5 Tage'];` + `wellen(string $anlass): array{max:?int, abstand_minuten:?int}`
- Create: `src/Services/Zas/Dispo/Jobs/DispoGesellschaft.php`: `RG='RG'`, `MA='MA'`, `ALLE=[RG=>'RG', MA=>'MA']`, `normalize(array $werte): list<string>` (nur bekannte, Reihenfolge RG, MA), `ausCompany(?string): string` (trim/upper, 'MA' → MA, sonst RG), `ausEinsatzfirma(?string): ?string` (enthält „MA“ als Wort oder beginnt mit „MA“ → MA; enthält „RG“/„Rhein“ → RG; sonst null), `label(?array): string` (null/beide → '', ['RG'] → 'RG', ['MA'] → 'MA', [] → 'keine Gesellschaft')
- Test: `tests/Unit/Dispo/Jobs/DispoGesellschaftTest.php`, `tests/Unit/Dispo/Jobs/DispoJobAnlassTest.php` (ergänzen), Migrationsliste in allen Integration-Tests patchen (siehe Global Constraints)

- [ ] Failing Tests: normalize/ausCompany/ausEinsatzfirma/label; `DispoJobAnlass::wellen('dringend') === ['max'=>20,'abstand_minuten'=>360]`, unbekannter Anlass → `['max'=>null,'abstand_minuten'=>null]`.
- [ ] Umsetzen; Migration schreiben; Modelle; Migrationsliste in Tests ergänzen (Skript: Zeile mit `2026_10_20_000001_add_stati` duplizieren).
- [ ] Tests grün (`--filter 'DispoGesellschaft|DispoJobAnlass|DispoJob'`), Commit `feat(recruiting): Jobs anbieten — Wellen-Spalten, Gesellschaft, Vorbelegung je Anlass`.

### Task 2: Kandidaten mit Gesellschaft, Planer mit Reihenfolge und Obergrenze

**Files:**
- Modify: `DispoJobKandidaten.php`: `laden()` lädt zusätzlich `company`; je Kandidat `firmen` (list, unique, aus `DispoGesellschaft::ausCompany` aller Datensätze der Person). `blockOptionen()` liefert je Block `firmen => ['RG'=>['aktiv'=>n,'inaktiv'=>m],'MA'=>[...]]` (beide Zählungen nach `company` gruppiert; `count` und `inaktiv` bleiben als Summen).
- Modify: `DispoJobRecipientPlanner.php`: `TOEPFE += ['spaeter' => 'kommt in einer späteren Welle dran']` (letzter Topf). `grundmenge()` filtert zusätzlich `firmen` (`$auswahl['firmen']` null = beide, [] = niemand; Kandidat ohne `firmen` = ['RG']). `plan()`: vor dem Kappen Empfänger sortieren nach `(einsaetze asc, anchor asc)`; `$auswahl['max']` (int|null, ≤0 = null) kappt; Überhang → Topf `spaeter`; Rückgabe `+ ['rest' => int]`.
- Modify: `DispoJobZahlen.php`: `ausDaten()` gibt `rest` durch; `je_firma` nicht nötig (Zahlen stehen an der Filiale).
- Modify: `DispoJobCreateState.php`: Prüfungen „Bitte wähle mindestens eine Gesellschaft.“ (`firmen` vorhanden und normalisiert leer) nach Status; Dringend-Regel (§7): `anlass === 'dringend'` und (`count(event_ids) !== 1` oder mehr als ein Tag mit gesucht > 0 in `schichten_tage`) → Text aus Spec. State bekommt `schichten_tage` (list der Tage mit gesucht>0).
- Test: `DispoJobRecipientPlannerTest`, `DispoJobKandidatenTest`, `DispoJobCreateStateTest`, `DispoJobZahlenTest`

- [ ] Failing Tests: Sortierung (Einsätze 3/0/1 → Anker 2,3,1), `max=2` → 2 Empfänger + `spaeter`=1 + `rest`=1, `max` null = alle, Firmen-Filter (Person ['RG','MA'] bei `['MA']` dabei, bei `[]` niemand, fehlend = beide), Kandidaten: Paar RG+MA → `firmen ['RG','MA']`, blockOptionen zählt sie unter RG und MA; CreateState Dringend mit zwei Tagen → Fehler; Gesellschaft leer → Fehler.
- [ ] Umsetzen, Tests grün, Commit `feat(recruiting): Jobs anbieten — Gesellschaft je Filiale, Reihenfolge und Obergrenze je Welle`.

### Task 3: Speichern, Folgewelle, Versand-Job, gedeckt-Regel im Versand

**Files:**
- Modify: `DispoJobAnfrageAnlegen.php`: `anlegen()` speichert `welle_max`, `welle_abstand_minuten` (0 → null); `welle()` speichert `firmen` (normalisiert, null wenn Schlüssel fehlt/null) und `auto` (bool, default false), nimmt `max` NICHT an der Welle (steht an der Anfrage).
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobFolgewelle.php` (rein): `entscheiden(bool $anfrageOffen, ?int $abstandMinuten, int $rest, bool $alleGedeckt): ?int` → Minuten der nächsten Welle oder null. Außerdem `gestopptGrund(bool $anfrageOffen, bool $alleGedeckt, int $empfaenger, int $rest): ?string` → 'bedarf_gedeckt' | 'niemand_uebrig' | null.
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobGedeckt.php`: `istGedeckt(object $shift, int $zugesagt): bool` (`gedeckt_manuell_at !== null || (gesucht > 0 && zugesagt >= gesucht)`), `offeneSchichten(RecDispoJobRequest): list<array{id,datum,taetigkeit,event,von}>` (nicht gedeckt, Tag ≥ heute, closed_at egal im Modus sammeln? → NEIN: gedeckt-Regel allein; `closed_at` im Modus zuerst ist Folge von gedeckt), `alleGedeckt(RecDispoJobRequest): bool`.
- Modify: `src/Jobs/SendDispoJobWave.php`: Schichten über `DispoJobGedeckt::offeneSchichten()`; vor dem Senden: leer → `summary['gestoppt']='bedarf_gedeckt'`, Welle schließen, keine Folgewelle; `auswahl` + `['firmen' => $wave->firmen, 'max' => $request->welle_max]`; nach `finished_at`: `DispoJobFolgewelle::entscheiden(...)` → `DispoJobAnfrageAnlegen::welle($request, [...gleiche Filter..., 'auto' => true, 'minuten' => $min])` + `starten()`. `summary['rest']` und `summary['naechste_welle_nr']` mitschreiben.
- Modify: `src/Livewire/Dispo/Jobs/Show.php`: `offeneSchichten()` → `DispoJobGedeckt::offeneSchichten()`.
- Test: `tests/Unit/Dispo/Jobs/DispoJobFolgewelleTest.php`, `DispoJobGedecktTest` (rein, istGedeckt), `tests/Integration/SendDispoJobWaveJobTest.php` (Folgewelle entsteht mit `scheduled_for`≈now+abstand, `auto=true`, gleiche Filter; keine bei abstand null / rest 0 / gedeckt / geschlossen; Auto-Welle bei „alle gedeckt“ sendet nichts und kettet nicht), `DispoJobCreateFlowTest` (welle_max/abstand gespeichert).

- [ ] Failing Tests, Umsetzen, grün, Commit `feat(recruiting): Jobs anbieten — automatische Folgewellen, Stopp bei gedecktem Bedarf`.

### Task 4: Haken „gedeckt“ und Wiederöffnen, Chips, Automatik stoppen

**Files:**
- Modify: `DispoJobGedeckt.php`: `markieren(RecDispoJobRequest, int $shiftId): bool` (setzt `gedeckt_manuell_at` + `closed_at`), `oeffnen(RecDispoJobRequest, int $shiftId): bool` (leert beide).
- Modify: `Show.php`: Aktionen `gedecktMarkieren(int)`, `gedecktOeffnen(int)`, `automatikStoppen()` (setzt `welle_abstand_minuten=null`, löscht wartende `auto`-Wellen ohne `started_at`, Hinweis aus Spec §2.4); `schichten()` liefert `gedeckt` (bool) und `gedeckt_manuell` (bool) je Schicht; `fortschritt()` nutzt gedeckt für `voll`.
- Modify: `show.blade.php`: Schichtkopf Chip „gedeckt“/„gedeckt (von Hand)“ grün, Knopf „als gedeckt markieren“/„wieder öffnen“ (nur bei offener Anfrage); Kopf: Knopf „Automatische Wellen stoppen“ wenn `welle_abstand_minuten` gesetzt, sonst grauer Text „Automatische Wellen: aus“; Wellenliste „· automatisch“, Gesellschaft-Label.
- Test: `DispoJobGedecktTest` (Integration: markieren/oeffnen; Welle bietet gedeckte Schicht nicht an), Blade-Check.

- [ ] Failing Tests, Umsetzen, grün, Commit `feat(recruiting): Jobs anbieten — Schicht als gedeckt markieren, Automatik stoppen`.

### Task 5: „Kann nicht“ (nur Dringend) und einmal pro Tag

**Files:**
- Modify: `JobOffersZusage.php`: in `annehmen()` vor dem Zählen: `tag_belegt`, wenn die Person (`$employeeIds`) für `shift->datum` schon eine Zusage hat (`accepted_at` not null, `declined_at` null, andere Schicht, lockForUpdate-Zählung über offer_shifts join shifts join offers); Schwester-Schichten (gleicher Schlüssel) ausgenommen. Rückgabe `'tag_belegt'`.
- Create: `src/Services/Zas/Dispo/Jobs/JobOffersAbsage.php`: `GRUENDE = ['krank'=>'krank','anderer_job'=>'anderer Job','privat'=>'privat verhindert','uni_schule'=>'Uni/Schule','sonstiges'=>'sonstiges']`; `ausfuehren(int $offerShiftId, array $employeeIds, string $grund, string $notiz): 'ok'|'weg'|'kein_grund'|'nicht_dringend'|'schon_zugesagt'`; setzt `declined_at`, `declined_reason`, `declined_note` (mb_substr 200).
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobAbsagen.php`: `anzahl(array $employeeIds, string $seit): int`, `liste(array $employeeIds, string $seit): list<array{datum, veranstaltung, schicht, grund, notiz, am}>`, `jeSchicht(int $shiftId): list<...>` (Name über Offer→Employee), `TAGE = 60`.
- Modify: `src/Livewire/Public/JobOffers.php`: `angebote()` liefert je Schicht `dringend` (bool, request.anlass), `declined` (bool), `tag_belegt` (bool: anderer akzeptierter Eintrag am selben Tag) und je Karte `anlass` (Dringend>Woche>Vierwochen), `antworten_bis` (string|null, aus nächster wartender auto-Welle der Anfrage); Props `absageOffen` (?int offer_shift_id), `absageGrund`, `absageNotiz`; Aktionen `absageOeffnen(int)`, `absageAbbrechen()`, `absagen()`; Zähler `absagen60` (Computed). `zusagen()` Hinweis für `tag_belegt`.
- Modify: `resources/views/livewire/public/job-offers.blade.php`: Texte je Anlass (§8), „Bitte antworte bis …“, „Kann nicht“ (nur dringend, klein grau), aufklappender Block mit Satz, Zähler („Das wäre deine N. Absage in den letzten 60 Tagen.“ nur wenn N ≥ 2 inkl. dieser), Chips (Radio-Buttons), Textfeld, zwei Knöpfe; abgesagt → „Du hast abgesagt“; tag_belegt → „Du hast für diesen Tag schon zugesagt“ ohne Knopf.
- Modify: `DispoJobZusagenInfo.php`: `absagen_60` je Person; `show.blade.php`: Spalte „Absagen (60 T.)“ + je Schicht eingeklappter Block „N Absagen“ (`DispoJobAbsagen::jeSchicht`).
- Modify: `src/Livewire/Employees/Show.php` + `employees/show.blade.php` (Reiter dispo): Kasten „Absagen auf Jobanfragen (60 Tage): N“ mit Liste.
- Test: `tests/Integration/JobOffersZusageTest.php` (oder bestehender Zusage-Test: `tag_belegt`, Schwestern kein Verstoß), `JobOffersAbsageTest` (ok, kein_grund, nicht_dringend, schon_zugesagt), `DispoJobAbsagenTest` (anzahl über zwei Anstellungen, 60-Tage-Grenze), `JobOffersPageTest` (Kann-nicht nur bei dringend sichtbar; Absage durchklicken), ZusagenInfo-Test, Blade-Check.

- [ ] Failing Tests, Umsetzen, grün, Commit `feat(recruiting): Jobs anbieten — Kann nicht bei Dringend mit Grund und Zähler, einmal pro Tag`.

### Task 6: Nachricht mit Datum, Mitarbeiterseite „antworten bis“

**Files:**
- Modify: `DispoJobOfferSender.php`: `usesName` → `bodyVariablen(template): list<string>` (findet `{{name}}`, `{{datum}}`); Parameter in der Reihenfolge der Vorlage; `datum` = frühester Tag der `shift_ids` dieses Empfängers, Format `D, dd.MM.` deutsch („Sa, 31.10.“). Empfänger-Array bekommt `datum` aus dem Planer (`plan()` setzt je Empfänger `datum` = min datum der Schichten).
- Test: `tests/Integration/DispoJobOfferSenderTest.php` (Vorlage mit `{{name}}` und `{{datum}}` → zwei Parameter in Vorlagenreihenfolge; ohne `{{datum}}` → nur name; Vorlage ohne beide → nur Button).

- [ ] Failing Tests, Umsetzen, grün, Commit `feat(recruiting): Jobs anbieten — Datum in der Nachricht, Texte je Anlass auf der Mitarbeiterseite`.

### Task 7: Oberfläche Anlegen und Neue Welle

**Files:**
- Modify: `src/Livewire/Dispo/Jobs/Create.php`: Props `firmen` (list, Vorbelegung `['RG','MA']`, bei VA-Einstieg `DispoGesellschaft::ausEinsatzfirma($va->einsatzfirma)` → nur diese), `welleMax` (string, aus `DispoJobAnlass::wellen($anlass)['max']`, bei Anlasswechsel neu vorbelegen, solange der Nutzer sie nicht angefasst hat → Flag `wellenAngefasst`), `welleAbstand` (string Minuten, '0' = keine). `auswahl()` + `['firmen' => ..., 'max' => ...]`; `state()` + `firmen`, `schichten_tage`; `anlegenUndSenden()` übergibt `welle_max`, `welle_abstand_minuten`; Neurechnen-Liste + `firmen`, `welleMax`.
- Modify: `create.blade.php`: In der Filial-Karte unter den Blöcken: „Gesellschaft“ mit zwei Häkchen „RG“ / „MA“; Filialzeile „MGL · RG 332 · MA 47“, darunter grau „inaktiv: RG 650 · MA 55 (werden nicht angeschrieben)“ (nur wenn > 0). Neue Karte „Wellen“ (§2.2) unter den Gruppen. Zahlen-Kasten: „Sendet jetzt an **N** Personen“ + wenn `rest > 0`: „· M weitere in späteren Wellen“ (ohne Abstand: „· M weitere — nächste Welle von Hand starten“). Bestätigungstext ergänzt um beide Zahlen.
- Modify: `Show.php` + `show.blade.php` „Neue Welle“: Gesellschaft-Häkchen (vorbelegt aus letzter Welle, null → beide), „Pro Welle höchstens [ ]“ (vorbelegt `welle_max`), kein Abstand; Bestätigung mit Zahlen; Wellenliste: Gesellschaft-Label, „· automatisch“, „· bis zu N“.
- Test: `DispoJobCreateFlowTest` (Gesellschaft gespeichert), Blade-Check beider Views, volle Suite.

- [ ] Umsetzen, `php tools/blade-check.php` beide Views, volle Suite grün, Commit `feat(recruiting): Jobs anbieten — Gesellschaft, Wellenplan und Zahlen in Anfrage und neuer Welle`.

### Task 8: Abschluss
- [ ] Spec-Nachträge (Abweichungen), Anleitung `docs/anleitungen/dispo-jobs-anbieten.md` um Wellen/Gesellschaft/gedeckt/Kann nicht ergänzen (Text, Bilder später).
- [ ] Review-Agent (Diff per Commit-SHAs), Funde selbst nachprüfen, Fix-Runde.
- [ ] Abfrage für den User: `SELECT einsatzfirma, COUNT(*) FROM rec_dispo_events GROUP BY 1` → Erkennung in `DispoGesellschaft::ausEinsatzfirma` anpassen, falls nötig.
- [ ] Erst nach Freigabe: Merge, Bump, lokale main nachziehen. Deploy: migrate, view:clear, queue:restart.
