# Bewertungssystem: Erfassung am Termin, 5 Kriterien, Freitext — Design

**Datum:** 2026-08-05
**Modul:** platforms-recruiting
**Status:** Entwurf (zur Review) — Code-Referenzen gegen main `45f97d3`

> **Neuschrieb.** Diese Spec ersetzt die Fassung aus `f7ae381` vollständig. Zwei
> Entscheidungen haben die Struktur geändert: alle Bewertungsdaten hängen am
> **Bewerber** (nicht an der Buchung), und die Erfassung läuft ausschließlich über
> **ein Modal** (keine Inline-Sterne in Tabellenspalten). Damit entfallen
> Kindkomponente, `setRating()`, der Booking-Observer, der
> `LatestAttendedBookingResolver`, die `withTrashed`-Regel und der read-only
> Live-Block auf der Mitarbeiterkarte. Die verifizierten Faktenblöcke sind
> übernommen; gegenstandslose sind gestrichen. Der alte Stand bleibt in der
> Historie.

## Problem

Kundenaussage:

> „Hier war der Ablauf in der alten Version, dass die Bewertung (nicht ausführlich
> nach Vorgabe) erst nach dem Versenden des Vertrages vorgenommen werden konnte, was
> falsch ist. Die Bewertung erfolgt dynamisch während der Vorstellungsrunde und in
> der Schulung muss als Tabelle vorhanden sein A-Z sortiert nach Namen, mit
> Suchfunktion nach Namen, in den Spalten daneben muss die Bewertung gesetzt werden,
> dann zum Abschluss kann noch ein individueller Text geschrieben werden."

Drei Defekte im Bestand:

1. **Bewertung hängt am Mitarbeiter.** Das Bewertungs-Modal ist nur ansteuerbar,
   wenn für den Bewerber ein `RecEmployee` existiert (F1). Der Employee entsteht erst
   beim Vertragsversand → bewertet werden kann erst danach. Genau der Punkt, den der
   Kunde als „falsch" bezeichnet. Im Screenshot der Nachbereitung zeigt die Spalte
   „BEWERTUNG" für beide Teilnehmer „—", obwohl sie auf „Bestätigt" stehen — weil
   kein Employee existiert.
2. **Nur ein Stern-Wert.** `rec_employee_hr_data.star_rating` ist ein einzelner Wert
   1–5. Gefordert sind fünf Kriterien mit je 1–5 Sternen.
3. **Kein Freitext.** Auf `rec_employee_hr_data` existiert kein Notizfeld
   (`RecEmployeeHrData.php:20-33`).

Dazu zwei Tabellen-Defekte: Sortierung ist `booked_at DESC` (F11) statt A–Z nach
Namen, und das existierende Suchfeld ist im Nachbereitungs-Modus ausgeblendet (F2).

## Ziel (Produktentscheidungen — fix)

1. **Eine Logik für alle Termine.** „Vorstellungsrunde", „Schulung" usw. sind
   derselbe Termin-Begriff; es gibt keine typabhängige Sonderlogik. **Pro Bewerber
   gibt es genau einen Termin** — kein zweistufiger Ablauf.
2. **Freigabe-Regel: `status = 'attended'`.** Der Schulungsleiter setzt während des
   Termins „Teilgenommen", danach ist die Erfassung offen. Keine Employee-Kopplung
   mehr.
3. **Erfassung ausschließlich im Modal**, pro Bewerber, alle acht Felder in einem
   Vorgang und einem Speichern (§3).
4. **Alle Bewertungsdaten liegen am Bewerber** — nicht an der Buchung (§1).
5. **Fünf feste Kriterien**, identisch für alle Termine, nicht pro Team
   konfigurierbar (erzwungen durch den ZAS-Spaltenvertrag, F6).
6. **ZAS bekommt die fünf Sterne als eigene Spalten**, den Freitext nicht (§5).
7. **PDF-Handout wird eingebaut** — Hilfetext pro Kriterium am Bewertungsfeld.
8. **Selfie in beiden Ansichten** der Terminseite (§3a).
9. **Die Werte sind read-only in der Tabelle sichtbar**, damit HR den Stand ohne
   Modal-Öffnen erkennt (§3).

## Die fünf Kriterien

| # | Anzeige-Label | Spalte (beide Tabellen) | ZAS-Spalte |
| --- | --- | --- | --- |
| 1 | Erscheinungsbild & Hygiene | `rating_erscheinungsbild` | `BewertungErscheinungsbild` |
| 2 | Fachliche Grundkompetenz | `rating_fachkompetenz` | `BewertungFachkompetenz` |
| 3 | Auffassungsgabe & Lernbereitschaft | `rating_auffassungsgabe` | `BewertungAuffassungsgabe` |
| 4 | Auftreten & Kommunikation | `rating_auftreten` | `BewertungAuftreten` |
| 5 | Teamintegration & Verhalten | `rating_teamintegration` | `BewertungTeamintegration` |

ZAS-Namen im Stil des Bestands: CamelCase, keine Umlaute (`Waeschepaket`),
Gruppen-Prefix (`Infek*`, `Schulungs*`). **Vor dem Deploy von Hr. Michel
bestätigen** — danach sind sie Vertragsbestandteil (F6).

Labels, Spaltennamen, ZAS-Namen und Handout-Texte werden gemeinsam in **einer**
Support-Klasse `RatingCriteria` definiert (Single Source of Truth für UI,
ZAS-Mapping, Übernahme-Logik und Tests) — kein verstreutes Array pro
Verwendungsstelle.

## Geteilte Fakten (gegen Code verifiziert — für alle Tasks bindend)

**F1 — Die Bewertungs-Sperre ist eine reine Employee-Existenzprüfung.**
`openEvaluationModal()` (`InterviewBookings/Index.php:664-671`) und
`saveEvaluation()` (`:690-701`) prüfen `$booking?->applicant?->employee`; die
Blade-Bedingung ist `@if($employee)` (`index.blade.php:385`), der Else-Zweig zeigt
„MA noch nicht angelegt – Verträge zuerst versenden" (`:398`). **Keine Fachlogik,
kein Statusbezug** — und umgekehrt liest kein Versand-/Vertragspfad die Bewertung.
Die Abhängigkeit ist eine Einbahnstraße und kann ohne Rückwirkung gedreht werden.

**F2 — Namenssuche existiert und ist korrekt, nur versteckt.**
`InterviewBookings/Index.php:128-133` filtert über
`applicant.crmContactLinks.contact` auf `first_name`/`last_name`. Das Eingabefeld
steht in `index.blade.php:94`, aber innerhalb `@if($mode === 'overview')` (`:76-96`)
— im Nachbereitungs-Modus also nicht sichtbar.

**F3 — Der Name liegt nicht am Bewerber, sondern im CRM.**
Beide Modi rendern identisch
`applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt'`
(`index.blade.php:135` Übersicht, `:274` Nachbereitung). Eine Sortierung „A–Z nach
Nachname" ist damit kein `orderBy` auf der Buchung.

**F4 — `rec_interview_bookings.notes` ist belegt.** Das Feld ist im Buchungs-Modus
ein editierbares Textarea (`index.blade.php:146-153`, gespeichert per
`updateNotes()`), fillable in `RecInterviewBooking.php:22`. Der Bewertungs-Freitext
braucht einen **anderen Namen und ein anderes Label**.

**F5 — Kein „ist Schulung"-Marker am Termintyp.** `RecInterviewType` hat
`uuid, name, genus, code, description, sort_order, is_active, team_id,
created_by_user_id, owned_by_user_id` (`:17-28`) — Typen sind frei pro Team
konfigurierbar. Eine typabhängige Regel bräuchte erst ein neues Flag plus
Bestandspflege. Die Freigabe über `status = 'attended'` kommt ohne aus.

**F6 — ZAS hat einen harten Spaltenvertrag.**
`ZasEmployeeFieldResolver::COLUMNS` (`:37-100`) ist die CSV-Kopfzeile; beide
Export-Endpunkte liefern sie über `ZasCsvBuilder::build($rows, COLUMNS)` aus
(`ZasEmployeeInitialExportController.php:80`,
`ZasEmployeeUpdateExportController.php:80`). Etablierte Konvention im Code:
`// Schulungsdaten (ans Ende, nie dazwischen)` (`:98`). Heute wird die Bewertung als
**eine** Spalte geliefert: `'Sternebewertung' => $hr?->star_rating !== null ?
(string) $hr->star_rating : null` (`:228`).

**F7 — Bewertungsfelder sind nullable ohne Default, „leer" == NULL.**
Migration `2026_05_21_000004_add_linen_package_to_hr_data.php:19-29`: `json`
nullable, `unsignedTinyInteger` nullable (`comment('1-5 Sterne, HR-only')`), `json`
nullable. Casts `RecEmployeeHrData.php:40-42`: `array`, `array`, `integer`.
`saveEvaluation()` normalisiert aktiv auf NULL (`:704-708`) — `[]` ist kein
gültiger Leerwert.

**F8 — hrData ist die HR-/ZAS-Schicht und dort editierbar.**
HR pflegt Wäschepaket und Qualifikation auf der Mitarbeiterkarte
(`Employees/Show.php:263` und `:267`, `type => multi_lookup`). Die hrData-Row
entsteht explizit in `CreateEmployeeFromApplicantService.php:104`
(`ensureHrData()` = `firstOrCreate`, `RecEmployee.php:216-222`), **nicht** per
Observer; `createOrUpdate()` steigt bei existierendem Employee vorher aus
(`:38-41`).

**F9 — Der ZAS-Update-Marker hängt an hrData-Feldern.**
`RecEmployeeExportObserver::RELEVANT_HR_FIELDS` (`:100-104`) enthält
`linen_package_items`, `star_rating`, `qualifications`; der Listener hängt an
`RecEmployeeHrData::saved` (`:124-135`). **Neue hrData-Felder werden also allein
durch Eintragen in diese Konstante abgedeckt** — kein neuer Listener nötig.

**F10 — Die kanonische Statusliste.** `Support/BookingStatusGroups.php:14`:

```php
public const KNOWN = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'];
```

Grundlage der Policy-Matrix in §2. Der Docblock (`:8-10`) weist darauf hin, dass
`KNOWN` heute die zwei `$validStatuses`-Duplikate spiegelt
(`InterviewBookings/Index.php:312`, `Tools/UpdateInterviewBookingTool.php:74`) und
später auf eine zentrale Konstante umgestellt wird — für diese Spec unkritisch, die
Werteliste ist identisch.

**F11 — Keine Paginierung, `booked_at DESC`, Kontakt-Links ohne Ordnung.**
`bookings()` endet auf `->get()` (`InterviewBookings/Index.php:171`), sortiert
`orderBy('booked_at', 'desc')` (`:143`); die Komponente nutzt kein
`WithPagination`, die Blade rendert keine Paginierungs-Links. `crmContactLinks()`
ist ein blankes `morphMany` **ohne Ordering**
(`Platform\Hcm\Traits\HasEmployeeContact:14-20`, eingebunden über
`Traits/HasApplicantContact`) — `->first()` ist damit nicht deterministisch, und
die Relation ist to-many. Die einzige Stelle im Modul mit expliziter
Link-Priorisierung ist `EmployeeContactListSyncService::resolveDesired()`
(`:282-327`: auslieferbar = `contact.is_active` UND `owned_by_user_id IS NULL`,
Tie-Break kleinste `contact_id`).

**F12 — `bookings()` ist EINE Query für beide Modi, und hrData ist bereits eager
geladen.** `InterviewBookings/Index.php:124-172`: keine Modus-Verzweigung (nur
`filterStatus` verzweigt, `:149-169`). Eager-Load-Liste (`:134-142`):

```php
'applicant.crmContactLinks.contact',
'applicant.legalStatus',
'applicant.postings.position',
'applicant.contractTemplate',
'applicant.contracts:id,rec_applicant_id,rec_contract_template_id,status,sent_at',
'applicant.employee:id,rec_applicant_id',
'applicant.employee.hrData',
```

**Konsequenz: für die neuen Felder ist keine Änderung am Eager Loading nötig.** Die
acht Bewerber-Spalten kommen mit dem ohnehin geladenen `applicant`, und
`applicant.employee.hrData` steht schon in der Liste (`:141`). Die Ziel-Ladeliste
ist identisch mit der heutigen.

**F13 — Selfie: Quelle und Anzeigeweg existieren, Anzeige selbst noch nicht.**
Am Bewerber ist das Extra-Feld `selfie_upload`
(`Support/ApplicantEmployeeFieldMapping.php:66` mappt es auf
`rec_employees.selfie_file_id`; `Observers/RecApplicantExportObserver.php:69`;
`Services/Zas/ZasFieldResolver.php:68` mappt ZAS-Slot `upl-selfie` →
`['selfie_upload']` — **keine Legacy-Alternativnamen**). Der Wert ist eine File-ID
oder ein JSON-Array von File-IDs (`ZasFieldResolver::getRawExtraField`, `:457-477`)
und verweist auf `Platform\Core\Models\ContextFile`. Dieses Modell liefert
`getUrlAttribute()` (signierte URL über Route `core.context-files.show`, **TTL 60
Min.**), `getThumbnailAttribute()` (Variante `thumbnail_4_3`, sonst erste
`thumbnail_%`) und `isImage()`. Extra-Feld-Definitionen werden **pro Bewerber**
aufgelöst (`getDefinitionId($applicant, $name)`) — sie sind stellen-/phasengebunden
und existieren nicht zwangsläufig. Im Recruiting-Modul gibt es bisher **keine
Bildanzeige** (Grep über `resources/views`: nur ein Datei-Link in
`conversations/index.blade.php:244`).

**F14 — Lookup-Werte kommen aus `CoreLookup`.**
`lookupOptionsFor()` (`InterviewBookings/Index.php:716-720`) liest
`CoreLookup::where('name', …)->getOptionsArray()`; genutzt für `waeschepaket` und
`qualifikation` (Blade `:561`, `:576`). Der ZAS-Export mappt dieselben Namen auf
Labels (`ZasEmployeeFieldResolver.php:227`, `:229`).

**F15 — Was ein Statuswechsel auf `attended` auslöst (vollständig).**
Setzende Pfade: `InterviewBookings/Index::updateStatus()` (`:310-358`,
`$booking->update()` bei `:346`) und `Tools/UpdateInterviewBookingTool` (`:74`).
Ausgelöst wird:

1. Model-`saving`-Hook (`RecInterviewBooking.php:56-60`):
   `SeatStandbyPolicy::mustClearReleaseMarker()` → `seat_released_at = null`.
2. `RecInterviewBookingComplianceObserver::saved` (`:25-59`) →
   `NonEuPostTrainingGate::shouldRoute()` → ggf.
   `routeIfNotAlreadyOpen(REASON_NON_EU_CITIZEN)` → **legt einen HR-Desk-Fall an**
   und setzt `is_on_hr_desk=true`, `auto_pilot=false`.
3. `RecInterviewWaitlistObserver::saved` (`:57-82`): `$activated` ist wahr →
   `WaitlistRearmService::rearmIfNowFull($interviewId)`.
4. **Nur im UI-Pfad** (`:350-352`): `assignDefaultTemplateIfMissing()` schreibt
   `applicant.contract_template_id` (AV-default) → Applicant-Save → dessen Observer.

**Nicht** ausgelöst: Phase-Check, Employee-Anlage, Auto-Pilot-Nachrichten.
`checkAutoPilotCompletion()` wird von keinem Booking-Pfad gerufen (Aufrufer:
`SendContractsService:219`, `HrDeskRoutingService:246`, `MigrateNonEuCases:179`,
`ProcessAutoPilotApplicants:151`); der Employee entsteht ausschließlich beim
Vertragsversand. **`updateStatus()` hat keine Guards** für „ist bereits Employee",
`is_active=false` oder „Funnel abgeschlossen" — und **kein Team-Scoping**
(`findOrFail`, `:317`).

**F16 — Team-Scoping fehlt in dieser Komponente durchgängig.**
Die schreibenden Pro-Buchung-Methoden nutzen alle `findOrFail($bookingId)` ohne
Team-Filter: `updateNotes:304`, `updateStatus:310`, `deleteBooking:360`,
`setApplicantContractTemplate:367`, `setApplicantZuschlag:402`, `sendReminder:811`.
Team-gescoped sind nur `availableApplicants:206`, `book:282` und
`defaultContractTemplate:789`. **Es gibt hier kein bestehendes Scoping-Muster zum
Nachbauen.** `openEvaluationModal:664` und `saveEvaluation:690` lösen die Buchung
dagegen über `$this->bookings->firstWhere('id', …)` auf und sind damit implizit auf
den geladenen Termin begrenzt — der stärkere Guard.

**F17 — Mitarbeiterkarte: Rendering und roter Fehlanzeiger.**
`hrFieldGroups()` (`Employees/Show.php:252-270`) definiert die Gruppen
„Vertrags-Status", „Ausstattung" (`linen_package_items`, `:263`) und „Bewertung &
Qualifikation" (`star_rating` als `inline_select` mit Optionen 1–5, `:266`;
`qualifications` als `multi_lookup`, `:267`). Gerendert in
`employees/show.blade.php:195-258`, mit Zweigen für `readonly` (`:210-213`),
`lookup`, `date`, `inline_select` (`:228-235`), `multi_lookup` (`:237-248`) und
Text. Zwei relevante Details: `:203` liest
`$employee->ensureHrData()->getAttribute($key)` **pro Feld im Loop** (ein
`firstOrCreate` je Feld je Render — vorbestehend), und `:204-205` setzt
`$isMissing` → **roter Rand auf leeren Feldern**.

**F18 — Der ZAS-CSV-Builder bereinigt Werte, er quotet sie nicht.**
`ZasCsvBuilder::sanitize()` (`:90-98`): `|;` wird entfernt, `;` → `,`, CR/LF →
Leerzeichen. Der Docblock (`:80-89`) hält fest: „Wir machen kein
RFC-4180-Quoting (das verstuende der ZAS-Importer nicht), deshalb stripen wir die
Zeichen statt sie zu escapen." **Ein Freitext kann das Schema also nicht brechen —
er käme aber verstümmelt an.**

**F19 — Queue-Jobs laden `RecApplicant`.**
`Jobs/MatchApplicantToPostingJob` (`implements ShouldQueue`, `:20`;
`RecApplicant::find`, `:38`) und `Jobs/NotifyWaitlistForInterview`
(`implements ShouldQueue`, `:24`; `$this->afterCommit = true`, `:56`). Beide werden
von Worker-Prozessen ausgeführt.

## Architektur

### §1 Datenmodell — acht Felder am Bewerber, sechs neue an hrData

**An den Bewerber** (`rec_applicants`), acht neue Spalten:

- `rating_erscheinungsbild`, `rating_fachkompetenz`, `rating_auffassungsgabe`,
  `rating_auftreten`, `rating_teamintegration` — je `unsignedTinyInteger` nullable,
  Wertebereich 1–5, Cast `integer`.
- `evaluation_note` — `text` nullable. **Eigener Name, nicht `notes`** (F4).
- `linen_package_items`, `qualifications` — `json` nullable, Cast `array`.

Alles 1:1 wie F7: nullable ohne Default, **„leer" == NULL, niemals `[]`**.

**`rec_interview_bookings` bekommt keine neuen Spalten.**

Begründung: Pro Bewerber gibt es genau **einen** Termin — „Vorstellungsrunde" und
„Schulung" sind zwei Namen für dieselbe Veranstaltung, kein zweistufiger Ablauf. Die
Bewertung ist damit eine Aussage über die **Person**, nicht über eine
Terminteilnahme. Eine Ablage an der Buchung hätte als einzigen Vorteil die
eindeutige Zuordnung bei einer Umbuchung — und der trägt nicht: Man bucht um, *weil
man nicht teilnehmen kann*; die Bewertung hängt an `attended` und passiert damit
nach allen Umbuchungen. Es gibt nichts zuzuordnen. Dafür entfielen bei der
Buchungs-Ablage weder Resolver noch Sonderregeln — sie kämen alle hinzu.

**An `rec_employee_hr_data`**, sechs neue Spalten: die fünf Ratings
(`unsignedTinyInteger` nullable, Cast `integer`) und `evaluation_note` (`text`
nullable). `linen_package_items` und `qualifications` existieren dort bereits.
Zweck: die Phasenregel aus §4 gilt damit für **alle acht Felder gleich**, und der
ZAS-Export liest uniform aus hrData wie heute schon `Sternebewertung`.

**`rec_employee_hr_data.star_rating` wird nicht angetastet.** Altdaten der
bestehenden Mitarbeiter bleiben lesbar, `Sternebewertung` läuft weiter, keine
Datenmigration. Das Feld wird nur nicht mehr neu geschrieben.

### §2 Freigabe-Regel

Pure Policy-Klasse, PHPUnit-testbar (Modul-Konvention: reines PHPUnit ohne
Laravel/DB):

```
EvaluationAvailability::isOpen(string $bookingStatus): bool
```

Wahr genau bei `$bookingStatus === 'attended'`. Verwendet an **drei** Stellen mit
einer Wahrheit: der Blade-Bedingung der Bewertungs-Zelle, in
`openEvaluationModal()` und in `saveEvaluation()`. Kein Guard entfällt; sie wechseln
nur Kriterium und Meldung („Bewertung erst möglich, wenn die Teilnahme bestätigt
ist.").

Bewusst **kein** ODER mit „Employee existiert": Der Sonderfall aus der
Zertifikat-Spec (`ma_ohne_attended = 1`, `bewertungen_ohne_attended = 0`, §Zahlen)
betrifft genau einen Mitarbeiter ohne jede Bewertung — es gibt nichts zu retten, und
ein ODER würde die eine Regel aufweichen, die der Kunde verstehen soll.

**Der fehlende Status ist nicht durch einen beiläufigen Klick zu heilen.** Ein
Statuswechsel auf `attended` hat Nebenwirkungen (F15): er kann über den
Compliance-Observer einen HR-Desk-Fall anlegen und `auto_pilot=false` +
`is_on_hr_desk=true` setzen — bei einem Bewerber, der längst Mitarbeiter ist, wäre
das ein Rückschritt in den Funnel, und `updateStatus()` hat dafür keinen Guard.
Wer eine Altbewertung braucht, korrigiert deshalb **nicht** über den Status, sondern
trägt sie auf der Mitarbeiterkarte nach bzw. lässt sie stehen: bestehende
Bewertungen auf hrData bleiben unberührt und weiter sichtbar (F17) und weiter als
`Sternebewertung` im Export (§5). Diese Regel steuert ausschließlich die
Neuerfassung am Termin.

### §3 Erfassung — ein Modal, ein Speichern

**Klick auf „Bewerten" in der bestehenden Spalte „BEWERTUNG"** (ganz rechts in der
Nachbereitungs-Tabelle) öffnet das Modal für diesen Bewerber. Inhalt: fünf
Sterne-Reihen (Radio-Gruppen 1–5, bestehendes Muster `index.blade.php:543-555`),
Wäschepaket und Qualifikation als Checkbox-Gruppen aus den Lookups (F14,
unverändert), dann das Freitextfeld. **Ein `saveEvaluation()` schreibt alle acht
Felder.**

Der Employee-Bezug im Modal-Kopf (`:537-541`, „MA #…") entfällt — der Teilnehmer
heißt über den CRM-Kontakt (F3), nicht über den Mitarbeiterdatensatz.

**Warum ein Modal und keine Inline-Sterne in Tabellenspalten:** Die acht Felder
gehören fachlich zusammen und werden in einem Vorgang erfasst. Bei Inline-Sternen
wäre die Bewertung auf zwei Eingabeflächen verteilt, mit einem sichtbaren
Zwischenzustand „Sterne gesetzt, Wäschepaket/Qualifikation/Freitext nicht" — der
sich nur durch Öffnen des Modals auflöst. Ein Vorgang, ein Speichern, eine Fläche.
Nachgeordnet und als Layout-Argument: die Nachbereitungs-Tabelle ist bereits sechs
Spalten breit, davon zwei mit je zwei Eingabefeldern plus Hilfetext
(Vertragsvorlage mit Zuschlag; Vertragslaufzeit mit zwei Datumsfeldern und
zweizeiliger Erklärung) — fünf zusätzliche Sternspalten hätten die Tabelle nur
horizontal scrollbar gemacht.

**Abweichung vom wörtlichen Kundensatz, bewusst und begründet:** Der Kunde schrieb
„in den Spalten daneben muss die Bewertung gesetzt werden". Gesetzt wird im Modal;
die Werte sind **read-only in der Spalte BEWERTUNG sichtbar**, womit die Tabelle die
Bewertung zeigt, ohne sie zur Eingabefläche zu machen. Sollte der Kunde später auf
klickbare Sterne in der Zeile bestehen, werden **nur die Zellen klickbar** — Ablage,
Datenpfad und Guards bleiben unverändert. Diese Rückwärtskompatibilität ist der
Grund, warum die Entscheidung jetzt tragbar ist.

**Read-only-Anzeige in der Spalte BEWERTUNG** (ersetzt die heutige Zelle,
`index.blade.php:383-399`) — festgelegte Form, damit Blade- und Test-Task dasselbe
bauen:

- Fünf Werte als Zahlenreihe, mittig getrennt: `4·3·5·4·4`; ein leerer Wert als `–`.
- Marker für gesetztes Wäschepaket und gesetzte Qualifikation.
- Textsymbol, wenn ein Freitext vorliegt.
- Darunter/daneben der Button „Bewerten" bzw. „Bewertung bearbeiten".
- **Bei gesperrter Zeile** (Status ≠ `attended`) statt der Reihe ein neutraler
  Hinweis, Button inaktiv.

Das ist kein Kompromiss, sondern eine eigene Funktion: HR sieht auf einen Blick, wer
bewertet ist, ohne zwanzig Modals zu öffnen. Die Werte liegen durch das bestehende
Eager Loading ohnehin vor (F12).

**Der „bereits bewertet"-Zustand muss aus den neuen Quellen gelesen werden**, nicht
wie heute aus `$hr` (`:388`) — sonst zeigt die Zeile dauerhaft den Leerzustand.
Definition: mindestens ein Rating **ODER** Freitext **ODER** Wäschepaket **ODER**
Qualifikation gesetzt.

**Handout-Hilfe:** Info-Icon an jeder Sterne-Reihe im Modal → Popover mit dem
Kriterien-Text aus dem Handout, plus einmal ein Link auf das vollständige PDF. Die
Texte liegen in `RatingCriteria` neben Label und Spaltenname; keine
Admin-Oberfläche (stabiler Inhalt aus einem Handout). Das PDF wird als statisches
Asset ausgeliefert.

**Namensformat in beiden Ansichten gleich: „Nachname, Vorname".** Heute rendern
Übersicht (`:135`) und Nachbereitung (`:274`) identisch `full_name` (F3). Würde nur
die Nachbereitung umgestellt, stünde dieselbe Person in zwei Tabs derselben
Komponente in zwei Formaten. Gebaut wird ein gemeinsamer Helfer, der aus
`last_name`/`first_name` formatiert und auf `full_name`, dann `'Unbekannt'`
zurückfällt.

**Sortierung nur in der Nachbereitung auf A–Z**, nach genau dem angezeigten String —
damit kann die sichtbare Reihenfolge nicht von der Sortierung abweichen. Die
Übersicht behält `booked_at DESC` („zuletzt gebucht oben", F11); die
DB-Sortierung in `bookings()` bleibt unangetastet und wird im
Nachbereitungs-Modus durch die Collection-Sortierung überschrieben.

**Sortiert wird die geladene Collection, nicht per Join** (F11): Die Liste
paginiert nicht. Ein Join über `crm_contact_links` ist bewusst verworfen — die
Relation ist to-many ohne Ordering, ein Join würde Zeilen bei mehrfach verlinkten
Bewerbern vervielfachen. **Kopplung, die im Code als Kommentar stehen muss:** Wird
die Liste später paginiert, sortiert der Collection-Sort nur die aktuelle Seite;
dann muss auf DB-Sortierung mit expliziter Link-Priorisierung umgestellt werden.

**Die Kontaktwahl wird deterministisch** — kleinste `contact_id`, Muster
`EmployeeContactListSyncService::resolveDesired()` (`:282-327`, F11). `->first()`
auf einem `morphMany` ohne Ordering ist es nicht, und ohne Determinismus kann sich
die Sortierreihenfolge zwischen zwei Renderings ändern. Gilt für **beide** Ansichten
(gemeinsamer Helfer mit dem Namensformat). Die verstreuten `->first()`-Aufrufe im
übrigen Modul bleiben unangetastet (§Tradeoffs).

**Das Suchfeld wird im Nachbereitungs-Modus eingeblendet** (F2 — die Filterlogik
existiert und bleibt unverändert). Die Status-Filter-Auswahl bleibt dem
Buchungs-Modus vorbehalten.

**Zwei Notizfelder in derselben Ansicht brauchen eindeutige Labels** (F4): Die
Buchungsnotiz heißt im Buchungs-Modus weiter **„Notiz"** (Platzhalter „Notiz…"), das
neue Feld im Bewertungs-Modal heißt **„Bewertungstext"**. Sie liegen jetzt auf
verschiedenen Tabellen, erscheinen aber in derselben Ansicht — die Unterscheidung
bleibt nötig.

### §3a Selfie-Spalte in beiden Ansichten

Neue Spalte mit dem Selfie des Teilnehmers aus dem Extra-Feld `selfie_upload` (F13)
— **in beiden Modi**: in der Übersicht neben „KANDIDAT", in der Nachbereitung neben
„BEWERBER". Zweck ist das Wiedererkennen: der Schulungsleiter ordnet Namen und
Gesicht zu, bevor er bewertet.

- **Angezeigt wird die Thumbnail-Variante**, nicht das Original. Bei 20–25
  Teilnehmern wären das sonst genauso viele Vollbilder pro Seitenaufruf.
- **Fallback auf das Original**, wenn keine Variante existiert (Varianten werden
  asynchron erzeugt, `variants_status`) — mit CSS-Begrenzung.
- **`onerror`-Fallback am `img`-Tag** auf denselben Platzhalter wie „kein Selfie".
  Die signierten URLs laufen nach 60 Minuten ab (F13) und ein Termin dauert länger;
  ohne das sieht der Schulungsleiter kaputte Bild-Icons ohne erkennbaren Grund.
- **Klick öffnet das Bild groß** (signierte `url`).
- **Neutraler Platzhalter** (Initialen oder Icon), wenn kein Selfie vorliegt, das
  Feld an der Stelle nicht definiert ist oder die Datei kein Bild ist (`isImage()`).
- **Batch-Laden ist Pflicht — in genau vier Queries.** Pro Zeile wären es sonst drei
  (Definitions-ID, Feldwert, ContextFile + Variante), bei 25 Teilnehmern also 75
  zusätzliche Abfragen. Konkrete Form:
  1. Definitions-IDs (`core_extra_field_definitions`, `name = 'selfie_upload'`) —
     Definitionen sind stellen-/phasengebunden, es gibt also mehrere.
  2. Feldwerte (`core_extra_field_values`: `whereIn definition_id` ×
     `whereIn fieldable_id` × `fieldable_type = 'rec_applicant'`) →
     `[applicant_id => raw]`. Spalten- und Morph-Namen wie im bewährten Pfad
     `ZasFieldResolver::preloadExtraFields()` (`:447-451`); der Unique-Index
     `(definition_id, fieldable_type, fieldable_id)` garantiert einen Wert je
     Bewerber und Definition.
  3. `ContextFile::whereIn('id', $fileIds)` über alle aufgelösten File-IDs.
  4. `ContextFileVariant` für diese Files, `variant_type like 'thumbnail_%'`.

  Ergebnis als `#[Computed]`-Property `[applicant_id => ['url' => …, 'is_image' =>
  …]]`, im Blade nur Array-Zugriff. Muster für die Batch-Form:
  `openNonEuCaseApplicantIds()` (`InterviewBookings/Index.php:186-201`).

Weil `bookings()` **eine** Query für beide Modi ist (F12), läuft die Auflösung
automatisch für beide Ansichten — keine Verzweigung nötig.

### §3b `hasAnyContractSent` als Batch — optionaler eigener Task

Bei der Render-Analyse gefunden: `hasAnyContractSent()` ist eine eigene
`exists()`-Query pro Aufruf (`RecApplicant.php:1587-1593` nutzt den Query-Builder,
nicht die eager-geladene `contracts`-Relation) und wird pro Render bis zu **vier Mal
pro Bewerber** gerufen — Blade `:233`, Blade `:419`, `bulkSendState:756` (`every`),
`bulkSendState:760` (`filter`). Auflösung: einmal pro Render als Batch über alle
sichtbaren Bewerber (`[applicant_id => bool]`, Muster `openNonEuCaseApplicantIds`),
alle vier Stellen lesen daraus.

**Bewusst ein eigener, optionaler Task.** `bulkSendState` steuert den
Vertragsversand — das ist keine Bewertungs-Änderung, und ein Fehler dort trifft
einen schwerer wiegenden Pfad. Für dieses Feature ist der Task **nicht**
erforderlich: mit dem Modal fällt pro Bewerber ein Render an, nicht pro Sternklick.
Eigene Verifikation, falls er gebaut wird: `bulkSendState` liefert vor und nach der
Umstellung identische Werte für „keiner versendet", „gemischt" und „alle versendet";
die `every`/`filter`-Semantik bleibt exakt gleich.

### §4 Phasenregel — eine Regel, drei Leseseiten

**Genau eine Quelle je Lebensphase, für alle acht Felder — kein Dual-Write:**

- **Kein Employee:** die Bewerber-Spalten sind Lese- und Schreibseite.
- **Employee existiert:** **hrData** ist Lese- und Schreibseite; die Bewerber-Spalten
  werden dann nicht mehr angefasst.
- **Bei der MA-Erst-Anlage:** einmalige Übernahme **aller acht Werte** auf die
  frische hrData-Row, direkt neben `CreateEmployeeFromApplicantService.php:105`, im
  try/catch-Muster von `snapshotContractDatesToHrData()` (`:196-242`), aber mit
  **eigenem Log-Marker**, damit ein Fehler nicht als Snapshot-Fehler gelesen wird.
  Übernahme nur in leere Felder (`=== null`).

**Die Regel gilt für alle drei Leseseiten: Modal, Zeilen-Anzeige und
Mitarbeiterkarte.** Läse die Zeilen-Anzeige immer die Bewerber-Spalten, zeigte sie
nach einem HR-Edit auf der Mitarbeiterkarte veraltete Sterne — derselbe Fehler wie
beim verworfenen Dual-Write, nur an anderer Stelle. Beide Quellen liegen durch das
bestehende Eager Loading vor (F12), die Weiche kostet also keine Query.

**Warum kein Durchschreiben auf beide Seiten:** HR pflegt diese Felder auf der
Mitarbeiterkarte (F8/F17) und schreibt dabei nur hrData. Bei Dual-Write wäre die
Bewerber-Spalte danach veraltet, das Modal würde sie laden, und das nächste
Speichern schöbe den alten Wert über HRs aktuelle Korrektur — ohne jede Meldung.

Zur `=== null`-Prüfung: sie kann in *diesem* Pfad nie blockieren, weil
`ensureHrData()` ein `firstOrCreate` auf einem gerade erzeugten Employee ist (F8) —
die Row ist definitionsgemäß neu, alle Felder NULL, und ein „späterer HR-Edit"
existiert zum Aufrufzeitpunkt nicht. Sie bleibt trotzdem drin: billig und defensiv
gegen künftige Aufrufer. **Keine andere Begründung in den Code schreiben** —
falsche Begründungen werden abgeschrieben.

Die Werte am Bewerber werden nicht geleert (Archiv, §Tradeoffs).

**Auf der Mitarbeiterkarte werden die fünf Sterne und der Freitext normale
hrData-Felder** in `hrFieldGroups()` (`Employees/Show.php:252-270`): die Ratings als
`inline_select` mit Optionen 1–5 — genau wie `star_rating` heute (F17) — und der
Freitext als Textfeld. HR korrigiert sie dort, konsistent mit Wäschepaket und
Qualifikation. Kein read-only Sonderblock, keine Live-Auflösung, kein Resolver.

**Das alte `star_rating` wird umbenannt und auf `readonly` gesetzt:** Label
**„Sternebewertung (Altbestand)"** und `'readonly' => true`. Grund ist F17: `:204-205`
setzt auf leeren hrData-Feldern einen roten Rand als Fehlanzeiger — ein nie mehr
geschriebenes `star_rating` wäre bei jedem neuen Mitarbeiter dauerhaft rot als
„bitte ausfüllen" markiert. Der `readonly`-Zweig (`:210-213`) rendert ohne
Missing-Prüfung und ohne Eingabefeld. Der Wert bleibt lesbar, der Export unverändert.

### §5 ZAS-Export

**Fünf neue Spalten am Ende** von `ZasEmployeeFieldResolver::COLUMNS`, in der
Reihenfolge der Kriterien-Tabelle — nie zwischen bestehende (F6). Gelesen **aus
hrData**, genau wie `Sternebewertung` heute (`:228`). Kein Join, kein Resolver, keine
Auswahlregel.

**`Sternebewertung`, `Waeschepaket`, `Qualifikation` bleiben unverändert** — gleiche
Spalten, gleiche Quelle. Der Export bleibt damit für Hr. Michel
rückwärtskompatibel; er kann die fünf neuen Spalten in seinem Tempo aufnehmen.

**`evaluation_note` wird NICHT exportiert.** Nicht wegen eines Schema-Risikos — das
gibt es nicht: `ZasCsvBuilder::sanitize()` entfernt `|;`, wandelt `;` in `,` und
CR/LF in Leerzeichen (F18), ein Freitext kann das Format also nicht brechen. Der
Grund ist, dass genau diese Bereinigung den Text **verstümmelt** ankommen ließe
(Semikolons zu Kommas, Absätze zu Leerzeichen) und ZAS keinen Nutzen dafür hat.
Sollte er doch exportiert werden, muss der Spaltenname mit auf die Michel-Liste.

**Update-Marker:** Die fünf Rating-Feldnamen werden in
`RecEmployeeExportObserver::RELEVANT_HR_FIELDS` (`:100-104`) eingetragen. Der
bestehende `RecEmployeeHrData::saved`-Listener (`:124-135`, F9) deckt den Marker
damit ab — **kein neuer Listener nötig.** (`evaluation_note` kommt nur hinein, wenn
es exportiert wird; solange nicht, gehört es nicht in die Liste, sonst löst eine
reine Notiz-Änderung einen Re-Export ohne Inhaltsänderung aus.)

**Bei der Ersterfassung markiert nichts — das ist korrekt.** Zum Zeitpunkt der
Bewertung existiert typischerweise noch kein Employee (der entsteht erst mit dem
Vertragsversand), die Werte liegen am Bewerber und hrData wird nicht angefasst.
Diese Werte liefert der **Initial**-Export aus, der ohnehin alle noch nicht
exportierten Mitarbeiter enthält. Der Marker deckt ausschließlich den Fall
„Korrektur an hrData bei einem bestehenden Mitarbeiter" ab. **Ohne diesen Hinweis
wird das später als kaputt gelesen.**

### §6 Verhältnis zur Zertifikat-Spec

**Paket A der Zertifikat-Spec** (`2026-08-05-schulungszertifikat-bewertbarkeit-design.md`)
**entfällt vollständig** und wird von dieser Spec ersetzt. §B/§C/§D dort
(Vorlagen-Typ, Ausstellung am HR-Schreibtisch, WhatsApp-Zustellung) bleiben
unberührt und werden nach diesem Paket umgesetzt. Der Nicht-EU-Sonderfall
(„bewertbar, obwohl kein Vertrag") ist mit dieser Spec gegenstandslos — die Freigabe
hängt an `attended`, nicht am Vertragsstand.

## Die vier Live-Zahlen

Auf der Live-DB erhoben (2026-08-05, vom User ausgeführt):

| Kennzahl | Ergebnis |
| --- | --- |
| `ma_mit_bewerber` | 29 |
| `ma_ohne_bewerber` | 1 |
| `ma_ohne_attended` | 1 |
| `bewertungen_ohne_attended` | 0 |

30 Mitarbeiter insgesamt, davon 29 aus dem Bewerber-Funnel und 1 aus dem ZAS-Inbound
(`rec_applicant_id IS NULL` — strukturell nie bewertbar, kein Bewerber vorhanden).
Genau einer der 29 hat keine `attended`-Buchung.

**`bewertungen_ohne_attended = 0` heißt: die Freigabe-Regel aus §2 zerstört keine
Bestandsdaten**, und ein ODER mit „Employee existiert" wäre reine Vorsorge für einen
einzigen Mitarbeiter ohne jede Bewertung. Deshalb bleibt es bei der einen Regel.

## Tests & Verifikation

**Pure-Unit (PHPUnit, ohne Laravel/DB):**
- `EvaluationAvailability::isOpen`-Matrix über alle Werte aus
  `BookingStatusGroups::KNOWN` (F10).
- `RatingCriteria`: Vollständigkeit (5 Einträge), Eindeutigkeit von Spalten- und
  ZAS-Namen, Label und Handout-Text je Kriterium vorhanden.
- Normalisierung: Sterne nur 1–5, sonst NULL; Lookup-Arrays `[]` → NULL (F7).
- Sortier-/Anzeige-Schlüssel „Nachname, Vorname" inkl. Leerfälle (kein Kontakt, kein
  Nachname) — dürfen die Sortierung nicht kippen, und Anzeige-String und
  Sortierschlüssel müssen identisch sein.
- Deterministische Kontaktwahl: bei mehreren Links gewinnt die kleinste
  `contact_id`; gleiche Eingabe → gleiche Ausgabe über mehrere Aufrufe.
- **„bereits bewertet"-Zustand** als pure Funktion: mindestens ein Rating ODER
  Freitext ODER Wäschepaket ODER Qualifikation gesetzt.
- Kompakte Anzeigeform: fünf Werte als Zahlenreihe, leerer Wert als `–`.
- Selfie-Auflösung: File-ID skalar vs. JSON-Array (erste ID gewinnt), leerer/`0`-Wert,
  kein Feld definiert, Datei kein Bild → jeweils Platzhalter; Thumbnail vorhanden →
  Thumbnail, sonst Original (F13).

**Harness (sqlite, Muster Warteliste/Dedup-Guard):**
- Phasenregel §4 beim **Schreiben**: mit Employee schreibt das Modal nur hrData und
  lässt die Bewerber-Spalten unberührt; ohne Employee nur die Bewerber-Spalten.
- Phasenregel §4 beim **Lesen der Zeilen-Anzeige**: existiert ein Employee, liest sie
  hrData, sonst die Bewerber-Spalten.
- Übernahme **aller acht Felder** bei der MA-Erst-Anlage, nur in leere Felder.
- `RecEmployeeHrData::saved` setzt `zas_changed_at` bei Änderung der neuen
  Rating-Felder (F9).

Keine Tests in die Pure-Unit-Liste stellen, die faktisch eine DB brauchen.

**Live-Sichttest nach Deploy:**

1. Termin mit zwei Teilnehmern, einer auf `attended` gesetzt → bei ihm ist der
   Bewerten-Button aktiv, beim anderen der neutrale Sperr-Hinweis.
2. Modal öffnen, fünf Sterne + Wäschepaket + Qualifikation + Bewertungstext
   erfassen, speichern → die Zeile zeigt die Zahlenreihe, die Marker und das
   Textsymbol.
3. Suche und A–Z-Sortierung im Nachbereitungs-Modus prüfen; Namensformat
   „Nachname, Vorname" in **beiden** Ansichten identisch.
4. Selfie-Spalte in **beiden** Ansichten: Bild beim Bewerber mit Upload, Platzhalter
   ohne; Klick öffnet die Großansicht; im Netzwerk-Tab prüfen, dass nicht pro Zeile
   nachgeladen wird (Batch, §3a).
5. **Nach Versand von Portallink + Verträgen:** die Mitarbeiterkarte zeigt die fünf
   Sterne, den Bewertungstext, Wäschepaket und Qualifikation als editierbare Felder;
   „Sternebewertung (Altbestand)" ist read-only und ohne roten Rand.
6. Wert auf der Mitarbeiterkarte korrigieren → die Zeilen-Anzeige in der
   Nachbereitung zeigt den korrigierten Wert (Leseseite hrData, §4), und
   `zas_changed_at` ist gesetzt.
7. Fünf neue Spalten im ZAS-Export gefüllt, `Sternebewertung`/`Waeschepaket`/
   `Qualifikation` unverändert, kein Freitext im CSV.

## Benannte Tradeoffs

- **14 neue Spalten für acht logische Felder** (acht auf `rec_applicants`, sechs auf
  `rec_employee_hr_data`). Das ist der Preis der Phasenregel: die Daten müssen vor
  der MA-Anlage irgendwo liegen, und hrData ist die HR-/ZAS-Schicht, die es ohne
  Employee nicht gibt. Alternativen wären Dual-Write (verworfen, §4) oder eine
  Live-Auflösung über Relationen (verworfen mit der Buchungs-Ablage, §1).
- **Die Freigabe hängt an `booking.status`, die Daten am Bewerber.** Hat ein
  Bewerber zwei Buchungen (Umbuchung), ist dasselbe Datum in der einen Zeile
  editierbar und in der anderen gesperrt. Erreichbarer Fall: eine Buchung wird nach
  der Bewertung auf `no_show` korrigiert — die Werte bleiben am Bewerber, die Zeile
  ist gesperrt, und die Übernahme auf hrData bei einer späteren MA-Anlage passiert
  trotzdem. Gewollt (die Beobachtung wurde gemacht, der Status korrigiert nur die
  Anwesenheit), aber es muss dastehen.
- **Eine Bewertung pro Person, keine Historie.** Eine Korrektur überschreibt den
  vorherigen Wert; wer wann was geändert hat, ist nicht nachvollziehbar. Bei einem
  Termin pro Bewerber ist das der Normalfall und kein Verlust.
- **`star_rating` bleibt als toter Zweig liegen.** Wird nicht mehr geschrieben, aber
  weiter als `Sternebewertung` exportiert und auf der Mitarbeiterkarte angezeigt
  (read-only). Ein Aufräumen ist eine separate Abstimmung mit Hr. Michel.
- **Erst-Anlage-Semantik der Übernahme.** `createOrUpdate()` steigt bei existierendem
  Employee vor `:105` aus (F8); Backfill- und Re-Export-Aufrufe tragen die
  Bewerber-Werte daher bewusst nicht mit.
- **Stiller Verlust bei Übernahme-Fehler.** Das try/catch-Muster schluckt Fehler in
  ein `Log::warning`, damit die MA-Anlage nicht kippt. Übernimmt sich die Bewertung
  nicht, merkt es niemand außer im Log; die Werte liegen weiter am Bewerber.
- **Bewertung ohne Anwesenheit unmöglich.** Wer vergisst, `attended` zu setzen, kann
  nicht bewerten. Bewusst: eine Regel, die der Schulungsleiter versteht, ist
  wertvoller als ein Schlupfloch.
- **Sortierung über CRM-Daten.** Bewerber ohne verknüpften CRM-Kontakt oder ohne
  Nachnamen haben keinen Sortierschlüssel und landen am Ende.
- **Selfie-Spalte bleibt bei manchen Bewerbern dauerhaft leer.** Das Feld ist
  stellen-/phasengebunden (F13); wo eine Stelle kein Selfie erhebt, gibt es nichts
  anzuzeigen. Ein Nachfordern ist nicht Teil dieses Scopes.
- **Vorbestehende Lücken, die diese Spec nicht schließt:** kein Team-Scoping auf den
  Pro-Buchung-Methoden der Komponente (F16 — `saveEvaluation` löst über
  `$this->bookings` auf und ist damit implizit begrenzt, die anderen bleiben wie sie
  sind), `updateStatus()` ohne Guard gegen Statuswechsel bei abgeschlossenen
  Bewerbern (F15), `ensureHrData()` pro Feld im Render der Mitarbeiterkarte (F17),
  und die verstreuten nicht-deterministischen `crmContactLinks->first()`-Aufrufe im
  übrigen Modul (F11 — nur die Terminseite wird umgestellt). Alle vier bewusst
  außerhalb des Scopes, alle vier als Folge-Notiz.

## Deploy

- **Zwei-Push-Struktur:** Migrationen zuerst (Bewerber- und hrData-Spalten), Feature
  danach. Die Blade und die Mitarbeiterkarte lesen die neuen Spalten; ein
  Feature-Deploy vor der Migration wirft in beiden Ansichten.
- **`composer.lock`-Bump in `meingedeck` nach jedem Push** — sonst nicht live.
- **`queue:restart` ist nötig.** Nicht wegen eines Booking-Observers (den gibt es in
  dieser Fassung nicht), sondern weil zwei Queue-Jobs `RecApplicant` laden —
  `MatchApplicantToPostingJob` (`ShouldQueue` `:20`, `RecApplicant::find` `:38`) und
  `NotifyWaitlistForInterview` (`ShouldQueue` `:24`, `afterCommit` `:56`) — und
  dieses Paket dem Modell acht Spalten mit Casts anhängt (F19). Long-running Worker
  halten sonst die alte Klassendefinition.
- **Vor dem Deploy abstimmen:** die fünf ZAS-Spaltennamen mit Hr. Michel bestätigen.
  Der Export bleibt ohne seine Änderung funktionsfähig — er sieht die neuen Spalten
  erst, wenn er sie aufnimmt.
- **Handout-PDF** ins Repo legen und im Popover verlinken.

## Benannte Lücken

- **Handout-Texte pro Kriterium liegen noch nicht vor.** Bis dahin trägt
  `RatingCriteria` Label, Spalten- und ZAS-Namen; das Popover wird mit dem Text
  befüllt, sobald er da ist. Kein struktureller Einfluss.
- **Handout-PDF-Datei** liegt noch nicht im Repo.
- **Bestätigung der fünf ZAS-Spaltennamen** durch Hr. Michel steht aus (§Deploy).

## Betroffene Dateien

- **Neu:** `database/migrations/*_add_evaluation_fields_to_rec_applicants.php` (acht
  Spalten), `database/migrations/*_add_ratings_to_rec_employee_hr_data.php` (sechs
  Spalten), `src/Support/RatingCriteria.php`,
  `src/Support/EvaluationAvailability.php`, gemeinsamer Namens-/Kontakt-Helfer,
  zugehörige `tests/Unit/*`, Handout-PDF als Asset.
- **Ändern:** `src/Models/RecApplicant.php` (fillable + casts),
  `src/Models/RecEmployeeHrData.php` (fillable + casts),
  `src/Livewire/InterviewBookings/Index.php` (Freigabe-Regel, Modal um fünf Sterne
  und Freitext erweitert, Phasen-Weiche §4 für Lesen und Schreiben,
  Sortierung + Namensformat + deterministische Kontaktwahl, Suchfeld-Sichtbarkeit,
  Selfie-Batch-Query),
  `resources/views/livewire/interview-bookings/index.blade.php` (Modal,
  read-only-Anzeige in der Spalte BEWERTUNG, Suchfeld, Handout-Popover,
  Selfie-Spalte in beiden Modi, Namensformat in beiden Modi),
  `src/Services/CreateEmployeeFromApplicantService.php` (Übernahme aller acht Werte
  neben `:105`),
  `src/Livewire/Employees/Show.php` (`hrFieldGroups`: fünf Ratings + Freitext,
  `star_rating` als Altbestand read-only),
  `src/Services/Zas/ZasEmployeeFieldResolver.php` (fünf Spalten am Ende, gelesen aus
  hrData),
  `src/Observers/RecEmployeeExportObserver.php` (`RELEVANT_HR_FIELDS` erweitern).
