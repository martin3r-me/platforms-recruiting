# Jobs anbieten — Statusfilter GO/MA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Disponenten können bei Jobs anbieten nach ZAS-Status filtern (GO / MA), Vorbelegung nur GO.

**Architecture:** Jeder Kandidat bekommt einen Personen-Status (`status`: 'GO'|'MA'). Der reine Planer filtert ihn als Teil der Grundmenge. Die Auswahl wird je Welle in `rec_dispo_job_waves.stati` gespeichert und vom Versand-Job gelesen.

**Tech Stack:** Laravel/Livewire 3, PHPUnit (Unit pur + Integration mit Capsule/SQLite).

**Spec:** `docs/superpowers/specs/2026-10-20-dispo-jobs-statusfilter-design.md`

## Global Constraints

- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml [--filter X] < /dev/null`
- Blade prüfen: `php tools/blade-check.php <datei>`
- Status-Schlüssel exakt `'GO'` und `'MA'` (Konstanten `ZasStatus::GO`, `ZasStatus::MA`).
- Kundentexte: „GO – aktiver Vertrag“, „MA – braucht neuen Vertrag“, „Bitte wähle mindestens einen Status.“, „Status in ZAS“.
- Keine Änderung an der Filialzahl „· N Mitarbeiter“.
- `stati = null` an einer Welle heißt GO + MA.

## Review Focus

1. Person mit GO- und MA-Anstellung darf nur unter GO auftauchen, nie in beiden Zahlen.
2. Alte Welle ohne `stati` darf beim (Neu-)Start niemanden verlieren.
3. Zahl neben dem Häkchen darf sich durch das eigene Häkchen nicht ändern.
4. Leere Status-Auswahl darf nicht als „alle“ durchrutschen (weder im Planer noch beim Speichern).
5. Neue Welle übernimmt den Status der letzten Welle, nicht die Vorbelegung GO.

---

### Task 1: Personen-Status und Planer-Filter

**Files:**
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobKandidaten.php` (Zeile mit `$row['ohne_vertrag'] = …`)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobRecipientPlanner.php` (Grundmenge in `plan()`)
- Test: `tests/Unit/Dispo/Jobs/DispoJobRecipientPlannerTest.php`, `tests/Integration/DispoJobKandidatenTest.php`

**Produces:** Kandidat-Feld `status` ('GO'|'MA'); `$auswahl['stati']` (list<string>|null, fehlend/null = beide); `DispoJobRecipientPlanner::STATI = ['GO' => 'GO – aktiver Vertrag', 'MA' => 'MA – braucht neuen Vertrag']`; `DispoJobRecipientPlanner::stati(array $werte): list<string>` (nur bekannte Schlüssel, Reihenfolge GO, MA).

- [ ] Failing Unit-Tests im Planer: `status`-Filter bildet die Grundmenge (kein Topf erhöht sich); `['GO']` nimmt nur GO; `['MA']` nur MA; `['GO','MA']` beide; `[]` niemand; fehlender Schlüssel `stati` = beide; Kandidat ohne `status`-Feld zählt als GO.
- [ ] Failing Integration-Test in `DispoJobKandidatenTest`: Paar GO+MA → `status` GO und `ohne_vertrag` false; nur MA → MA; Datensatz ohne hrData → GO.
- [ ] Umsetzen: in `laden()` `$row['status'] = $row['ohne_vertrag'] ? ZasStatus::MA : ZasStatus::GO;`. Im Planer nach dem Gruppen-Check: `if ($stati !== null && !in_array($k['status'] ?? ZasStatus::GO, $stati, true)) continue;` mit `$stati = array_key_exists('stati', $auswahl) && $auswahl['stati'] !== null ? self::stati($auswahl['stati']) : null;`.
- [ ] Tests grün, Commit `feat(recruiting): Jobs anbieten — Personen-Status und Statusfilter im Planer`.

### Task 2: Zahlen, Prüfung, Speichern, Versand

**Files:**
- Create: `database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php` (`json('stati')->nullable()->after('gruppen')`, down entfernt sie)
- Modify: `src/Models/RecDispoJobWave.php` (fillable + cast `array`)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobZahlen.php` (`je_status`)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobCreateState.php` (Prüfung „Bitte wähle mindestens einen Status.“ nach der Gruppen-Prüfung)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobAnfrageAnlegen.php` (`welle()` speichert `stati`)
- Modify: `src/Jobs/SendDispoJobWave.php` (`'stati' => $wave->stati`)
- Test: `tests/Unit/Dispo/Jobs/DispoJobZahlenTest.php`, passender Test für CreateState, Integrationstest für `welle()` + Job falls vorhanden (sonst Unit über `welle()`-Attribute)

**Consumes:** Task 1. **Produces:** `berechnen()/ausDaten()` liefern zusätzlich `je_status: array{GO:int, MA:int}`.

- [ ] Failing Tests: `je_status` zählt Personen der Grundmenge aus Filiale + Gruppe ohne Status-Filter und ohne Töpfe (Rechnung: Planer-Grundmenge mit `stati => null`, dann je `status` zählen — eigene kleine Funktion `DispoJobRecipientPlanner::grundmenge()` extrahieren, damit Planer und Zählung dieselbe Regel nutzen); ändert sich nicht, wenn nur `stati` wechselt. CreateState: `stati => []` sperrt mit dem Text. `welle()` speichert `['GO']`; Job reicht `null` als null durch.
- [ ] Umsetzen, Migration ausführen lassen nur im Test-Schema.
- [ ] Tests grün, Commit `feat(recruiting): Jobs anbieten — Statusauswahl zählen, prüfen und je Welle speichern`.

### Task 3: Oberfläche

**Files:**
- Modify: `src/Livewire/Dispo/Jobs/Create.php` (`public array $stati = ['GO'];`, in `auswahl()` und State, in der Neurechnen-Liste `['maBloecke','gruppen','nurPassend','stati']`)
- Modify: `src/Livewire/Dispo/Jobs/Show.php` (`public array $wStati`, aus letzter Welle vorbelegt, `null` → `['GO','MA']`; in `auswahl()`; Wellen-Liste `stati_label`)
- Modify: `resources/views/livewire/dispo/jobs/create.blade.php` (neue Karte „Status in ZAS“ zwischen Filiale und Gruppen, zwei Häkchen mit Zahl aus `je_status`)
- Modify: `resources/views/livewire/dispo/jobs/show.blade.php` (dieselbe Karte in „Neue Welle“; Wellenzeile „· nur GO“ / „· nur MA“ / „· GO + MA“)
- Test: bestehende Livewire-/Partial-Tests anpassen, Blade-Check beider Dateien

- [ ] Failing Test für die Wellenbeschriftung (reine Hilfsfunktion `DispoJobRecipientPlanner::statiLabel(?array): string`).
- [ ] Umsetzen; `php tools/blade-check.php` für beide Views.
- [ ] Volle Suite grün, Commit `feat(recruiting): Jobs anbieten — Statusfilter in Anfrage und neuer Welle`.

### Abschluss

- [ ] Spec-Nachträge bei Abweichungen.
- [ ] Review-Agent (Diff per Commit-SHA), Funde selbst nachprüfen.
- [ ] Erst nach Freigabe: Merge, Bump, lokale main nachziehen.
