# Zeiterfassung: Stempeln im Mitarbeiter-Portal (Abschnitt 1)

Stand 16.11.2026. Anlass: Markus' Präsentation „Hybrid-Zeiterfassung“ (Mail 12.11.) und der Wunsch nach einem Vorschuss-Antrag im Portal. Entschieden von Sebastian am 13.11. und 16.11.

## 0. Wozu und für wen

Mitarbeiter stempeln im neuen Portal an ihrem Einsatz „Kommen“ und „Gehen“. Die Disposition sieht die Zeiten an der Veranstaltung und kann sie korrigieren. Jede Änderung ist nachvollziehbar.

Das ist ein **Test im Parallelbetrieb**: Lohn und ZAS-Übergabe laufen weiter über Olafs Portal. Unsere Zeiten gehen nirgendwohin. Sie zeigen, ob das Stempeln im Alltag trägt, und sind später die Grundlage für den Vorschuss.

Martin (Core) hat auf die Frage, ob der Stempel-Kern in den Core gehört, nicht geantwortet. Deshalb gilt: Der Kern liegt im Recruiting-Modul, ist aber so geschnitten, dass er ohne Umbau umziehen kann.

## 1. Ziele, Nicht-Ziele, Tabus

**Ziele**
- Ein Zeitdatensatz je Person und Einsatz (Markus), mit Kommen, Gehen, Pause, Bemerkung.
- Stempeln durch den Mitarbeiter im Portal, in einem Zeitfenster um die Schicht.
- Korrektur und stellvertretendes Stempeln durch die Disposition in der Webapp.
- Verlauf jeder Änderung: Feld, alter Wert, neuer Wert, wer, wann, warum.
- Sperre eines Datensatzes (Mechanik vorhanden, Auslöser „ZAS-Übergabe“ erst später).
- Kern wiederverwendbar: keine Abhängigkeit auf Recruiting-Modelle.

**Nicht-Ziele (spätere Abschnitte)**
- Abschnitt 1b: Teamleiter korrigiert im Portal auf dem Handy (Markus' Kernanforderung; erkannt über die eigene Einbuchung mit Tätigkeit aus `recruiting.zas.dispo_lead_taetigkeiten`).
- Abschnitt 2: Vorschuss-Antrag (nur bei grüner Akte inkl. Hemdgröße/Name/Land, 80 % der Stunden × Stundensatz, Abzug im Monatslohn). Braucht eine erweiterte Akten-Ampel und einen Stundensatz-Ermittler; beides gibt es heute nicht als eine Stelle.
- ZAS-Übergabe der Zeiten, Lohnarten für Leihwäsche, Wäsche-Ausgabe/-Abgabe, Standortprüfung (GPS), Lohnübersicht/SEPA.

**Tabus**
- Wer das neue Portal nicht nutzt, merkt nichts. Altes Portal und die Einsatz-Seite hinter dem Bestätigungslink (`EmployeeAssignments`, nur Token, keine Sitzung) bleiben unverändert. **Stempeln ist dort nie möglich** — ein weitergeleiteter Link darf nicht zum Stempeln reichen.
- Kein Schreiben an `rec_employees`/`rec_persons`, kein ZAS-Export-Marker, keine neue Spalte im ZAS-Export.
- Kein HCM-Modul, keine Änderung außerhalb `platforms-recruiting`.
- Die Uhrzeit kommt immer vom Server, nie vom Handy.

## 2. Schnitt: Kern und Rheingedeck-Schicht

### 2.1 Kern (`src/Zeit/`, Namespace `Platform\Recruiting\Zeit`)

Der Kern kennt nur „eine Person“ und „einen Bezug“ als Typ-Kürzel plus ID. Er importiert **keine** Klasse aus `Platform\Recruiting\Models`, `…\Services` oder `…\Livewire` (außer seinen eigenen). Ein Architektur-Test prüft das am Quelltext.

**Tabelle `rec_zeit_eintraege`** (ein Datensatz je Person und Bezug)

| Spalte | Bedeutung |
|---|---|
| `id`, `uuid` | |
| `team_id` (nullable) | Mandant, vom Aufrufer gesetzt |
| `person_typ`, `person_id` | Kürzel + ID, z. B. `rec_employee` / 4711 |
| `bezug_typ`, `bezug_id` | Kürzel + ID, z. B. `rec_dispo_assignment` / 9001 |
| `tag` (date) | Einsatztag, vom Aufrufer gesetzt |
| `soll_von_at`, `soll_bis_at` (datetime, nullable) | Schicht laut Plan zum Zeitpunkt des ersten Stempels (Schnappschuss) |
| `kommen_at`, `gehen_at` (datetime, nullable) | Ist-Zeiten |
| `pause_minuten` (unsigned int, nullable) | null = nach Regel berechnet; Zahl = von Hand gesetzt |
| `bemerkung` (text, nullable) | |
| `gesperrt_at` (datetime, nullable), `gesperrt_grund` (string, nullable) | |
| `created_at`, `updated_at` | |

Eindeutig über (`person_typ`, `person_id`, `bezug_typ`, `bezug_id`). Keine Fremdschlüssel (Muster `rec_hr_vermerke`), idempotente Guards (`hasTable`).

**Tabelle `rec_zeit_verlauf`** (nur anfügen, kein `updated_at`)

`id`, `rec_zeit_eintrag_id`, `feld` (`kommen_at|gehen_at|pause_minuten|bemerkung|gesperrt_at`), `alt` (text, nullable), `neu` (text, nullable), `art` (`selbst|stellvertretend|korrektur|sperre`), `akteur_typ`, `akteur_id` (nullable), `akteur_name` (string, Schnappschuss), `grund` (string, nullable), `created_at`.

**Klassen**
- `ZeitEintrag`, `ZeitVerlauf` (Eloquent, nur diese zwei Tabellen).
- `ZeitBezug` (Wertobjekt): `personTyp`, `personId`, `bezugTyp`, `bezugId`, `tag`, `sollVon`, `sollBis`, `teamId`.
- `ZeitAkteur` (Wertobjekt): `typ`, `id`, `name`.
- `ZeitRegeln` (Interface): `fruehestesKommen(ZeitBezug): ?Carbon`, `spaetestesStempeln(ZeitBezug): ?Carbon`, `pauseMinuten(int $anwesendMinuten): int`.
- `Stempeluhr` (Service, bekommt `ZeitRegeln`):
  - `kommen(ZeitBezug, ZeitAkteur, string $art, ?Carbon $zeit = null): string` → `ok | schon | zu_frueh | zu_spaet | gesperrt`
  - `gehen(ZeitBezug, ZeitAkteur, string $art, ?Carbon $zeit = null): string` → `ok | kein_kommen | schon | zu_spaet | gesperrt`
  - `korrigieren(ZeitBezug, ZeitAkteur, array $werte, string $grund): string` → `ok | gesperrt | ungueltig | unveraendert` (`$werte`: `kommen_at`, `gehen_at`, `pause_minuten`, `bemerkung`; legt den Datensatz an, wenn es ihn noch nicht gibt; Grund Pflicht; Gehen nicht vor Kommen)
  - `sperren(ZeitEintrag, ZeitAkteur, string $grund)`, `entsperren(...)`
  - `$zeit = null` heißt Serverzeit jetzt. Fenster-Prüfung nur bei `art = selbst`.
- `ZeitRechner` (rein): `anwesendMinuten`, `pauseMinuten` (gesetzter Wert, sonst Regel), `arbeitsMinuten` = anwesend − Pause, nie negativ; `verspaetungMinuten` = Kommen − Soll-Beginn, nie negativ.

Jeder schreibende Weg läuft in einer Transaktion mit Zeilensperre und schreibt Verlauf im selben Schritt. Doppelklick auf „Kommen“ ergibt `schon`, keinen zweiten Verlaufseintrag.

**Umzug später:** Ordner `src/Zeit/` verschieben, Namespace und Tabellen-Präfix ändern, die zwei Migrationen mitnehmen. Die Typ-Kürzel bleiben gültig.

### 2.2 Rheingedeck-Schicht (außerhalb `src/Zeit/`)

- `RheingedeckZeitRegeln implements ZeitRegeln`:
  - Kommen frühestens **60 Minuten vor** Schichtbeginn.
  - Stempeln (Kommen wie Gehen) spätestens **60 Minuten nach** Schichtende. Danach nur noch Korrektur durch die Disposition.
  - Pause: mehr als 6 Stunden anwesend → 30 Minuten, mehr als 9 Stunden → 45 Minuten, sonst 0.
- `DispoZeitBezug::fuer(RecDispoAssignment): ZeitBezug` baut den Bezug: Person = `rec_employee` + `rec_employee_id` der Einbuchung, Bezug = `rec_dispo_assignment` + ID, Tag = `datum`, Soll aus `datum` + `von`/`bis` (Text `HH:MM`). **Schicht über Mitternacht:** ist `bis` kleiner oder gleich `von`, liegt das Ende am Folgetag. Zeitzone `Europe/Berlin`.
- Schalter `zeit_stempeln_aktiv` in den Team-Settings (`RecApplicantSettings`, Anker-Team wie die `dispo_*`-Settings), Standard **aus**. Aus heißt: keine Stempel-Kachel im Portal, keine Zeit-Spalte in der Webapp, die Aktionen verweigern serverseitig.

## 3. Portal (Mitarbeiter)

Ort: `PortalShell`, Reiter „start“, oberhalb der bestehenden Kacheln. Nur dort — nicht in `EmployeeAssignments`.

**Wann die Kachel erscheint:** Schalter an, Mitarbeiter verifiziert, und es gibt eine Einbuchung der Person, die
- über `EmployeeAssignments::sichtbareEinsaetze` sichtbar ist (Status Auftrag, nicht verschwunden, nicht zur Löschung gemeldet, nicht abgesagt, angeschrieben oder bestätigt), gesucht über die Identitätsgruppe wie die Kachel „nächster Einsatz“, und
- **bestätigt** ist (`confirmed_at`), und
- deren Stempel-Fenster gerade offen ist (60 Minuten vor Beginn bis 60 Minuten nach Ende) oder für die schon „Kommen“ gestempelt ist und „Gehen“ fehlt.

Bei mehreren passenden Einbuchungen (zwei Schichten an einem Tag) je Einbuchung eine Zeile.

**Was die Kachel zeigt**
- Veranstaltung, Tag, Schicht laut Plan.
- Vor dem Kommen: Knopf „Kommen“.
- Nach dem Kommen: „Gekommen um 13:52“, Knopf „Gehen“.
- Nach dem Gehen: „13:52 – 22:07, Pause 30 Min, Arbeitszeit 7:45 Std“ und der Satz „Stimmt etwas nicht? Sag deiner Teamleitung oder der Disposition Bescheid.“
- Außerhalb des Fensters ohne offenen Stempel: keine Kachel.
- Texte in Du/Sie über `$duzen`, wie im restlichen Portal.

**Aktionen** `stempelKommen(int $assignmentId)`, `stempelGehen(int $assignmentId)` an `PortalShell`:
1. `berechtigterMitarbeiter()` wie jede schreibende Portal-Aktion (aktiv, `portal_v2_since`, nicht gesperrt, Sitzung gültig).
2. Die Einbuchung muss zur Identitätsgruppe des angemeldeten Mitarbeiters gehören und die Bedingungen oben erfüllen — neu geladen, nicht aus dem Formularzustand.
3. `Stempeluhr` mit `art = selbst`, Akteur = Mitarbeiter (Typ `rec_employee`, Name aus der Akte), Zeit = Serverzeit.
4. Ergebnis als kurze Meldung in der Kachel: „zu früh – ab 13:00 möglich“, „Zeitfenster vorbei – bitte bei der Disposition melden“, „gesperrt“.

Der Mitarbeiter kann eigene Stempel **nicht** ändern oder zurücknehmen.

## 4. Webapp (Disposition)

Ort: Veranstaltungs-Seite `Dispo\Events\Show`, Einbuchungs-Tabelle.

- Neue Spalte „Zeit“ (nur bei Schalter an): „13:52 – 22:07“, „13:52 – läuft“, oder leer. Verspätung ab 1 Minute als kleiner Zusatz „+12“. Gesperrte Datensätze mit Schloss.
- Klick öffnet das Fenster „Zeit“ zur Einbuchung: Soll-Schicht, Kommen, Gehen (Datum + Uhrzeit), Pause (leer = nach Regel, mit Anzeige des Regelwerts), Bemerkung, Pflichtfeld „Grund der Änderung“, darunter der Verlauf (neueste zuerst: wann, wer, Feld, alt → neu, Grund).
- Zwei Schnellknöpfe im Fenster: „Jetzt kommen“ / „Jetzt gehen“ für stellvertretendes Stempeln (`art = stellvertretend`, ohne Fenster-Prüfung).
- Speichern = `Stempeluhr::korrigieren` (`art = korrektur`), Akteur = angemeldeter Plattform-Nutzer (Typ `user`).
- Rechte: nur Nutzer mit vollem Dispo-Zugriff. Konten „Nur Veranstaltungen“ (`blockedForEventOnly()`) sehen die Spalte, können aber nichts ändern.
- Der bestehende Verspätet-Marker (`toggleLate`) bleibt unberührt.

Sperren/Entsperren gibt es in Abschnitt 1 nur als Kern-Funktion mit Tests, ohne Knopf — der Auslöser kommt mit der ZAS-Übergabe.

## 5. Einstellungen

`Dispo\Settings`: Schalter „Stempeln im Mitarbeiter-Portal (Test)“ mit dem Hinweis „Gilt nur für Mitarbeiter im neuen Portal. Die Zeiten gehen nicht an ZAS.“ Der Schlüssel muss beim Speichern anderer Einstellungs-Fenster erhalten bleiben (Vorfall `ApplicantSettingsModal`, 13.11.: Schlüssel immer aus dem gespeicherten Stand übernehmen).

## 6. Was eine Person merkt, die nicht im Pilot ist

Nichts. Das neue Portal antwortet ohne `portal_v2_since` mit 404; die Einsatz-Seite und das alte Portal bekommen keine Zeile Code. In der Webapp ist ohne Schalter nichts zu sehen.

## 7. Prüf-Schwerpunkte

1. **Fremde Einbuchung:** Stempel-Aufruf mit der ID einer Einbuchung, die einer anderen Person gehört → verweigert, kein Datensatz.
2. **Ohne Sitzung / gesperrtes Portal / Schalter aus:** jede Stempel-Aktion verweigert, auch per direktem Aufruf.
3. **Nachtschicht 22:00–04:00:** Fenster und Soll-Ende liegen am Folgetag; Gehen um 04:10 ist gültig.
4. **Doppelklick / zwei Geräte:** genau ein Datensatz, genau ein Verlaufseintrag je Stempel.
5. **Einbuchung ändert sich nach dem Kommen** (Zeit verschoben, Person in ZAS ausgetauscht, Einbuchung verschwunden): der Zeitdatensatz bleibt erhalten und in der Webapp auffindbar; „Gehen“ bleibt möglich, solange gestempelt ist.

## 8. Deploy

Zwei Migrationen → Deploy mit `migrate` vor Traffic, `view:clear`. Schalter bleibt aus, bis Sebastian ihn einschaltet. Kein `queue:restart` nötig (keine Jobs).

## 9. Tests

- Unit (rein): `ZeitRechner` (Pausenstufen an den Grenzen 6:00/6:01/9:00/9:01, negative Werte), `RheingedeckZeitRegeln` (Fenster, Mitternacht), `DispoZeitBezug` (Mitternacht, fehlendes `bis`).
- Integration (Capsule + SQLite, beide Migrationen laden — sonst SQLite-Spaltenlücke): `Stempeluhr` (alle Rückgaben, Verlauf je Feld, Sperre, Korrektur legt an, unverändert schreibt nichts), Portal-Aktionen (Prüf-Schwerpunkte 1–4, Vorbild `PortalShellVerifyTest` + `TestPortalSitzung`), Webapp-Korrektur (Rechte, Grund Pflicht, Verlauf).
- Architektur: `src/Zeit/` importiert nichts aus dem übrigen Modul.
- `SharedPartialContractTest` für neue Blade-Teile, `tools/blade-check.php`.

## 10. Von mir gesetzt (bitte gegenlesen)

- Fenster gilt auch fürs Gehen: später als 60 Minuten nach Schichtende geht nur über die Disposition. Bei Veranstaltungen, die deutlich überziehen, bleibt der Stempel „läuft“ stehen, bis die Dispo ihn schließt.
- Stempeln nur bei **bestätigter** Einbuchung.
- Der Mitarbeiter kann eigene Stempel nicht ändern.
- Pause wird nach Regel berechnet und nicht gestempelt.
- Person im Kern = die Anstellung (`rec_employee`) der Einbuchung, nicht die übergreifende Person; so passt es zu Lohn und Einsatz.
- Tabellen heißen `rec_zeit_*` (Modul-Konvention), nicht neutral.
