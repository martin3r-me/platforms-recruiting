# Bewertungssystem: Erfassung am Termin, 5 Kriterien, Freitext — Design

**Datum:** 2026-08-05
**Modul:** platforms-recruiting
**Status:** Entwurf (zur Review) — Code-Referenzen gegen main `45f97d3`

## Problem

Kundenaussage:

> „Hier war der Ablauf in der alten Version, dass die Bewertung (nicht ausführlich
> nach Vorgabe) erst nach dem Versenden des Vertrages vorgenommen werden konnte, was
> falsch ist. Die Bewertung erfolgt dynamisch während der Vorstellungsrunde und in
> der Schulung muss als Tabelle vorhanden sein A-Z sortiert nach Namen, mit
> Suchfunktion nach Namen, in den Spalten daneben muss die Bewertung gesetzt werden,
> dann zum Abschluss kann noch ein individueller Text geschrieben werden."

Drei Defekte im Bestand:

1. **Bewertung hängt am Mitarbeiter.** Das Bewertungs-Modal ist nur ansteuerbar, wenn
   für den Bewerber ein `RecEmployee` existiert (`InterviewBookings/Index.php:668-671`
   und `:697-701`, Blade `:385` `@if($employee)`). Der Employee entsteht erst beim
   Vertragsversand → bewertet werden kann erst danach. Genau der Punkt, den der Kunde
   als „falsch" bezeichnet.
2. **Nur ein Stern-Wert.** `rec_employee_hr_data.star_rating` ist ein einzelner Wert
   1–5. Gefordert sind fünf Kriterien mit je 1–5 Sternen.
3. **Kein Freitext.** Auf `rec_employee_hr_data` existiert kein Notizfeld
   (`RecEmployeeHrData.php:20-33`).

Dazu zwei Tabellen-Defekte: Sortierung ist `booked_at DESC`
(`InterviewBookings/Index.php:143`) statt A–Z nach Namen, und das existierende
Suchfeld ist im Nachbereitungs-Modus ausgeblendet (`index.blade.php:76-96`).

## Ziel (Produktentscheidungen — fix)

1. **Eine Logik für alle Termine.** „Vorstellungsrunde", „Schulung" usw. sind
   derselbe Termin-Begriff; es gibt keine typabhängige Sonderlogik.
2. **Freigabe-Regel: `status = 'attended'`.** Der Schulungsleiter setzt während des
   Termins „Teilgenommen", danach ist die komplette Erfassung offen —
   Wäschepaket, Qualifikation, fünf Sterne-Kriterien, Freitext. Keine
   Employee-Kopplung mehr.
3. **Fünf Sterne inline in fünf Tabellenspalten**, Wäschepaket/Qualifikation/
   Freitext im Abschluss-Modal. Wörtliche Kundenanforderung, und die einzige
   Variante, die „dynamisch während des Termins" trägt (§3).
4. **Fünf feste Kriterien**, identisch für alle Termine — nicht pro Team
   konfigurierbar (erzwungen durch den ZAS-Spaltenvertrag, siehe F6).
5. **ZAS bekommt alle fünf Werte als eigene Spalten.**
6. **PDF-Handout wird eingebaut** — Hilfetext pro Kriterium direkt am
   Bewertungsfeld.
7. **Selfie in der Tabelle**, Spalte direkt neben „Bewerber", aus dem Extra-Feld
   `selfie_upload` — zum Wiedererkennen beim Bewerten (§3a).
8. **Alle Bewertungsdaten sind am Mitarbeiter sichtbar**, sobald aus dem Bewerber
   einer wird — live aufgelöst, nicht kopiert (§4b).

## Die fünf Kriterien

| # | Anzeige-Label | DB-Spalte | ZAS-Spalte |
| --- | --- | --- | --- |
| 1 | Erscheinungsbild & Hygiene | `rating_erscheinungsbild` | `BewertungErscheinungsbild` |
| 2 | Fachliche Grundkompetenz | `rating_fachkompetenz` | `BewertungFachkompetenz` |
| 3 | Auffassungsgabe & Lernbereitschaft | `rating_auffassungsgabe` | `BewertungAuffassungsgabe` |
| 4 | Auftreten & Kommunikation | `rating_auftreten` | `BewertungAuftreten` |
| 5 | Teamintegration & Verhalten | `rating_teamintegration` | `BewertungTeamintegration` |

ZAS-Namen im Stil des Bestands: CamelCase, keine Umlaute (`Waeschepaket`),
Gruppen-Prefix (`Infek*`, `Schulungs*`). **Vor dem Deploy von Hr. Michel
bestätigen** — danach sind sie Vertragsbestandteil (F6).

Definiert werden Labels, Spalten und Handout-Texte gemeinsam in **einer**
Support-Klasse `RatingCriteria` (Single Source of Truth für UI, ZAS-Mapping und
Tests) — kein verstreutes Array pro Verwendungsstelle.

## Geteilte Fakten (gegen Code verifiziert — für alle Tasks bindend)

**F1 — Die Bewertungs-Sperre ist eine reine Employee-Existenzprüfung.**
`openEvaluationModal()` (`InterviewBookings/Index.php:664-671`) und
`saveEvaluation()` (`:690-701`) prüfen `$booking?->applicant?->employee`; die
Blade-Bedingung ist `@if($employee)` (`index.blade.php:385`), der Else-Zweig zeigt
„MA noch nicht angelegt – Verträge zuerst versenden" (`:398`). **Keine
Fachlogik, kein Statusbezug** — und umgekehrt liest kein Versand-/Vertragspfad die
Bewertung. Die Abhängigkeit ist eine Einbahnstraße und kann ohne Rückwirkung
gedreht werden.

**F2 — Namenssuche existiert und ist korrekt, nur versteckt.**
`InterviewBookings/Index.php:128-133` filtert über
`applicant.crmContactLinks.contact` auf `first_name`/`last_name`. Das Eingabefeld
steht in `index.blade.php:94`, aber innerhalb `@if($mode === 'overview')`
(`:76-96`) — im Nachbereitungs-Modus also nicht sichtbar.

**F3 — Der Name liegt nicht am Bewerber, sondern im CRM.**
Angezeigt wird er über `applicant.crmContactLinks.first()->contact->full_name`
(z.B. `index.blade.php:534`). Sortierung „A–Z nach Nachname" ist damit kein
`orderBy` auf der Buchung, sondern entweder ein Join über
`crm_contact_links` → `crm_contacts` oder eine Sortierung der geladenen Collection.

**F4 — `rec_interview_bookings.notes` ist belegt.** Das Feld ist im
Buchungs-Modus ein editierbares Textarea (`index.blade.php:146-153`, gespeichert
per `updateNotes()`), fillable in `RecInterviewBooking.php:22`. Der
Bewertungs-Freitext braucht eine **eigene** Spalte.

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
`// Schulungsdaten (ans Ende, nie dazwischen)` (`:98`). Heute wird die Bewertung
als **eine** Spalte geliefert: `'Sternebewertung' => $hr?->star_rating !== null ?
(string) $hr->star_rating : null` (`:228`).

**F7 — Bewertungsfelder sind nullable ohne Default, „leer" == NULL.**
Migration `2026_05_21_000004_add_linen_package_to_hr_data.php:19-29`:
`json` nullable, `unsignedTinyInteger` nullable (`comment('1-5 Sterne, HR-only')`),
`json` nullable. Casts `RecEmployeeHrData.php:40-42`: `array`, `array`, `integer`.
`saveEvaluation()` normalisiert aktiv auf NULL (`:704-708`) — `[]` ist kein
gültiger Leerwert.

**F8 — hrData bleibt die HR-/ZAS-Schicht und ist dort editierbar.**
HR pflegt Wäschepaket und Qualifikation auf der Mitarbeiterkarte
(`Employees/Show.php:263` und `:267`, `type => multi_lookup`). Die hrData-Row
entsteht explizit in `CreateEmployeeFromApplicantService.php:104`
(`ensureHrData()` = `firstOrCreate`, `RecEmployee.php:216-222`), nicht per
Observer; `createOrUpdate()` steigt bei existierendem Employee vorher aus
(`:38-41`).

**F9 — Der ZAS-Update-Marker kennt nur hrData-Felder.**
`RecEmployeeExportObserver::RELEVANT_HR_FIELDS` (`:100-104`) enthält
`linen_package_items`, `star_rating`, `qualifications`; der Listener hängt an
`RecEmployeeHrData::saved` (`:124-135`). **Änderungen an einer Buchung laufen
daran vorbei.** Vorbild für die Lösung steht in derselben Datei:
`RecContract::saved` (`:137-153`) sucht den Employee per `rec_applicant_id` und
ruft `markEmployeeId()`.

**F10 — Die kanonische Statusliste.** `Support/BookingStatusGroups.php:14`:

```php
public const KNOWN = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'];
```

Das ist die Grundlage der Policy-Matrix in §2. Der Docblock (`:8-10`) weist
ausdrücklich darauf hin, dass `KNOWN` heute die zwei `$validStatuses`-Duplikate
spiegelt (`InterviewBookings/Index.php:312`, `Tools/UpdateInterviewBookingTool.php:74`)
und später auf eine zentrale Konstante umgestellt wird — für diese Spec unkritisch,
die Werteliste ist identisch.

**F11 — Die Nachbereitungsliste paginiert nicht, und Kontakt-Links haben keine
Ordnung.** `bookings()` endet auf `->get()` (`InterviewBookings/Index.php:171`);
die Komponente nutzt kein `WithPagination`, die Blade rendert keine
Paginierungs-Links. `crmContactLinks()` ist ein blankes `morphMany` **ohne
Ordering** (`Platform\Hcm\Traits\HasEmployeeContact:14-20`, eingebunden über
`Traits/HasApplicantContact`) — `->first()` ist damit nicht deterministisch, und
die Relation ist to-many. Die einzige Stelle im Modul mit expliziter
Link-Priorisierung ist `EmployeeContactListSyncService::resolveDesired()`
(`:282-327`: auslieferbar = `contact.is_active` UND `owned_by_user_id IS NULL`,
Tie-Break kleinste `contact_id`).

**F12 — Selfie: Quelle und Anzeigeweg existieren, Anzeige selbst noch nicht.**
Am Bewerber ist das Extra-Feld `selfie_upload`
(`Support/ApplicantEmployeeFieldMapping.php:66` mappt es auf
`rec_employees.selfie_file_id`; `Observers/RecApplicantExportObserver.php:69`;
`Services/Zas/ZasFieldResolver.php:68` mappt ZAS-Slot `upl-selfie` →
`['selfie_upload']` — **keine Legacy-Alternativnamen**). Der Wert ist eine
File-ID oder ein JSON-Array von File-IDs (`ZasFieldResolver::getRawExtraField`,
`:457-477`) und verweist auf `Platform\Core\Models\ContextFile`. Dieses Modell
liefert `getUrlAttribute()` (signierte URL über Route `core.context-files.show`,
TTL 60 Min.), `getThumbnailAttribute()` (Variante `thumbnail_4_3`, sonst erste
`thumbnail_%`) und `isImage()`. Extra-Feld-Definitionen werden **pro Bewerber**
aufgelöst (`getDefinitionId($applicant, $name)`) — sie sind stellen-/phasengebunden
und existieren nicht zwangsläufig. Im Recruiting-Modul gibt es bisher **keine
Bildanzeige** (Grep über `resources/views`: nur ein Datei-Link in
`conversations/index.blade.php:244`) — kein bestehendes Muster, aber auch keins,
das gebrochen wird.

**F13 — Lookup-Werte kommen aus `CoreLookup`.**
`lookupOptionsFor()` (`InterviewBookings/Index.php:716-720`) liest
`CoreLookup::where('name', …)->getOptionsArray()`; genutzt für `waeschepaket` und
`qualifikation` (Blade `:561`, `:576`). Der ZAS-Export mappt dieselben Namen auf
Labels (`ZasEmployeeFieldResolver.php:227`, `:229`).

## Architektur

### §1 Datenmodell — zwei Naturen von Daten

Die Oberfläche ist eine Logik; die Ablage ist es nicht, weil zwei fachlich
verschiedene Dinge erfasst werden.

**An die Buchung** (`rec_interview_bookings`) — Momentaufnahmen *dieses* Termins:

- `rating_erscheinungsbild`, `rating_fachkompetenz`, `rating_auffassungsgabe`,
  `rating_auftreten`, `rating_teamintegration` — je `unsignedTinyInteger`
  nullable, Wertebereich 1–5, Cast `integer` (1:1 wie F7).
- `evaluation_note` — `text` nullable. **Eigene Spalte, nicht `notes`** (F4).

Begründung — **ausdrücklich nicht** „mehrere Termine pro Bewerber": Pro Bewerber
gibt es genau **einen** Termin. „Vorstellungsrunde" und „Schulung" sind zwei Namen
für dieselbe Veranstaltung, kein zweistufiger Ablauf. Bis dahin ist die Person
ausschließlich Bewerber; der Mitarbeiter entsteht erst mit Portallink +
Vertragsversand. Die Buchungsbindung steht trotzdem, aus drei Gründen:

1. Eine Bewertung ist die Beobachtung eines konkreten Termins und gehört an die
   Zeile, in der sie erfasst wird.
2. Bei einer **Umbuchung** entsteht eine zweite Buchungszeile. Dann ist die
   Zuordnung eindeutig statt überschrieben — man sieht, zu welchem Termin die
   Bewertung gehört.
3. Die Erfassungsfläche **ist** die Buchungsliste. An die eigene Zeile zu
   schreiben braucht keine Cross-Row-Synchronisation.

**Geprüft und verworfen:** Sterne und Freitext direkt an `rec_applicants`. Bei
genau einem Termin wäre das einfacher, verliert aber die eindeutige Zuordnung bei
Umbuchung, und zwei Buchungszeilen derselben Person würden dieselben Werte
anzeigen und sich gegenseitig überschreiben.

**An den Bewerber** (`rec_applicants`) — dauerhafte Sachstände der Person:

- `linen_package_items` `json` nullable, `qualifications` `json` nullable, Casts
  `array` (1:1 wie F7, „leer" == NULL, **niemals `[]`**).

Begründung: „hat Hemd + Schürze bekommen" und „ist als Servicekraft einsetzbar"
sind keine Termin-Momentaufnahmen, sondern der aktuelle Stand der Person — er gilt
über den Termin hinaus und soll fortgeschrieben werden, nicht dupliziert. Von dort
laufen sie weiter auf hrData (§4), wo HR sie wie heute editiert (F8) und der
ZAS-Export sie unverändert liest (F13).

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
`openEvaluationModal()` und in `saveEvaluation()`. Kein Guard entfällt — sie
wechseln nur ihr Kriterium und ihre Meldung („Bewertung erst möglich, wenn die
Teilnahme bestätigt ist.").

Bewusst **kein** ODER mit „Employee existiert": Wer heute schon Mitarbeiter ist,
hat definitionsgemäß eine Schulung durchlaufen; ist seine Buchung nicht auf
`attended`, ist *das* der Datenfehler und wird durch einen Klick behoben. Der
Sonderfall aus der Zertifikat-Spec (`ma_ohne_attended = 1`, `bewertungen_ohne_
attended = 0`) betrifft genau einen Mitarbeiter ohne jede Bewertung — es gibt
nichts zu retten, und ein ODER würde die eine Regel aufweichen, die der Kunde
verstehen soll. **Bestehende Bewertungen auf hrData bleiben unberührt und weiter
sichtbar** — sie werden auf der Mitarbeiterkarte angezeigt (F8) und weiter als
`Sternebewertung` exportiert (§5); diese Regel steuert nur die Neuerfassung am
Termin.

### §3 Erfassung — fünf Sterne inline, Rest im Modal

**Die fünf Kriterien werden inline in fünf Tabellenspalten gesetzt**, je ein
kompakter Sterne-Selector pro Zelle. Das ist die wörtliche Kundenanforderung („in
den Spalten daneben muss die Bewertung gesetzt werden") und sie ist auch
praktisch die einzige tragfähige Variante: bei 15–20 Teilnehmern ist
Modal-öffnen-und-schließen-pro-Person der Unterschied zwischen „dynamisch während
des Termins" — der Formulierung des Kunden — und „abends nachtragen". Der
Schulungsleiter geht reihum und klickt; er navigiert nicht.

Jede Zelle schreibt einzeln (`wire:click` pro Stern → eine Methode
`setRating(bookingId, criterion, value)`), kein Sammel-Speichern. Erneuter Klick
auf denselben Wert setzt zurück auf NULL — sonst ist ein Fehlklick nicht
korrigierbar.

**Das Modal bleibt für den Abschluss:** Wäschepaket, Qualifikation und Freitext
(„dann zum Abschluss kann noch ein individueller Text geschrieben werden").
Bestehendes `showEvaluationModal`, umgebaut: Kopf mit Teilnehmername, Wäschepaket
und Qualifikation als Checkbox-Gruppen aus den Lookups (F13, unverändert), dann
das Freitextfeld. Die fünf Sterne-Reihen wandern **nicht** ins Modal — sie sind
inline. Der Employee-Bezug im Modal-Kopf (`:537-541`, „MA #…") entfällt; der
Teilnehmer heißt über den CRM-Kontakt (F3), nicht über den Mitarbeiterdatensatz.

**Handout-Hilfe:** Info-Icon **am Spaltenkopf** jedes Kriteriums → Popover mit dem
Kriterien-Text aus dem Handout, plus einmal ein Link auf das vollständige PDF. Die
Texte liegen in `RatingCriteria` neben Label und Spaltenname; keine
Admin-Oberfläche (stabiler Inhalt aus einem Handout). Das PDF wird als statisches
Asset ausgeliefert.

**Abschluss-Zelle** (ersetzt die heutige Bewertungs-Zelle,
`index.blade.php:383-399`): bei gesperrter Zeile ein neutraler Hinweis, bei
offener Zeile Marker für gesetztes Wäschepaket/Qualifikation und ein Textsymbol
bei vorhandenem Freitext, plus Button „Abschluss" / „Abschluss bearbeiten".
**Der „bereits erfasst"-Zustand muss aus den neuen Quellen gelesen werden**, nicht
wie heute aus `$hr` (`:388`), sonst zeigt die Zeile dauerhaft den Leerzustand,
obwohl Werte gesetzt sind.

**Gesperrte Zeilen:** Vor `attended` sind sowohl die fünf Sterne-Zellen als auch
der Abschluss-Button inaktiv (§2), optisch wie die heute gesperrte
Vertragsspalte.

**Zwei Notizfelder auf derselben Zeile (F4) brauchen eindeutige Labels**, sonst
landet die Bewertung im falschen Feld: die bestehende Buchungsnotiz heißt im
Buchungs-Modus weiter **„Notiz"** (Platzhalter „Notiz…"), das neue Feld im
Abschluss-Modal heißt **„Bewertungstext"**. Beide erscheinen nie im gleichen
Modus.

**Sortierung: „Nachname, Vorname" — angezeigt und sortiert nach demselben
String.** Die Bewerber-Spalte zeigt in der Nachbereitung `Nachname, Vorname`
statt `full_name`; sortiert wird nach genau diesem String, aufsteigend. Damit
kann die sichtbare Reihenfolge nicht von der Sortierung abweichen — bei
Sortierung nach Nachname mit Anzeige von `full_name` (F3) sähe die Liste
unsortiert aus („Anna Zimmermann" vor „Bernd Achterberg").

**Sortiert wird die geladene Collection, nicht per Join** (F11): Die Liste
paginiert nicht, und der Sortierschlüssel wird aus demselben Kontakt gebildet,
den die Zeile anzeigt (`crmContactLinks->first()?->contact`) — Schlüssel und
Label können damit nie auseinanderlaufen. Ein Join über `crm_contact_links` ist
bewusst verworfen: die Relation ist to-many ohne Ordering (F11), ein Join würde
Zeilen bei mehrfach verlinkten Bewerbern vervielfachen und bräuchte eine eigene
Link-Priorisierung. **Kopplung, die im Code als Kommentar stehen muss:** Wird die
Liste später paginiert, sortiert der Collection-Sort nur die aktuelle Seite —
dann muss auf DB-Sortierung mit expliziter Link-Priorisierung umgestellt werden
(Muster: `EmployeeContactListSyncService::resolveDesired()`, `:282-327`, die
einzige Stelle im Modul mit Link-Priorisierung).

Das Suchfeld wird im Nachbereitungs-Modus eingeblendet (F2 — die Filterlogik
existiert und bleibt unverändert). Die Status-Filter-Auswahl bleibt dem
Buchungs-Modus vorbehalten.

### §3a Selfie-Spalte

Neue Spalte **direkt neben „Bewerber"** in der Nachbereitungs-Tabelle: das Selfie
des Teilnehmers aus dem Extra-Feld `selfie_upload` (F12). Zweck ist das
Wiedererkennen am Termin — der Schulungsleiter ordnet Namen und Gesicht zu, bevor
er bewertet.

- **Angezeigt wird die Thumbnail-Variante**, nicht das Original. Bei 20–25
  Teilnehmern wären das sonst genauso viele Vollbilder pro Seitenaufruf.
- **Fallback auf das Original**, wenn keine Variante existiert (Varianten werden
  asynchron erzeugt, `variants_status`) — mit CSS-Begrenzung. Ohne Fallback wäre
  die Spalte bei frischen Uploads leer.
- **Klick öffnet das Bild groß** (signierte `url`, TTL 60 Min.) — nützlich, wenn
  das Thumbnail zu klein ist, um sicher zuzuordnen.
- **Neutraler Platzhalter** (Initialen oder Icon), wenn kein Selfie vorliegt, das
  Feld an der Stelle nicht definiert ist oder die Datei kein Bild ist
  (`isImage()`). Kein Fehler, keine leere Zelle.
- **Batch-Laden ist Pflicht.** Pro Zeile wären sonst drei Queries nötig
  (Definitions-ID, Feldwert, ContextFile + Variante) — bei 25 Teilnehmern 75
  zusätzliche Abfragen. Auflösung als **eine** Batch-Query über alle sichtbaren
  Bewerber, Muster wie `openNonEuCaseApplicantIds()`
  (`InterviewBookings/Index.php:186-201`): `[applicant_id => thumbnail-/url-Daten]`,
  im Blade nur noch Array-Zugriff.

Die Spalte gehört zur Nachbereitungs-Ansicht; der Buchungs-Modus bleibt unverändert.

### §4 Weg zur hrData (Wäschepaket + Qualifikation)

**Genau eine Quelle je Lebensphase — kein Dual-Write.** Das Modal liest und
schreibt immer nur *eine* Seite:

- **Kein Employee:** die Bewerber-Spalten sind Lese- und Schreibseite.
- **Employee existiert:** **hrData** ist Lese- und Schreibseite; die
  Bewerber-Spalten werden dann nicht mehr angefasst.
- **Bei der MA-Erst-Anlage:** einmalige Übernahme der beiden Bewerber-Werte auf die
  frische hrData-Row, direkt neben `CreateEmployeeFromApplicantService.php:105`, im
  try/catch-Muster von `snapshotContractDatesToHrData()` (`:196-242`), aber mit
  **eigenem Log-Marker**, damit ein Fehler nicht als Snapshot-Fehler gelesen wird.
  Übernahme nur in leere Felder (`=== null`).

Damit ist die Aussage „der Bewerber-Stand ist führend, bis der Mitarbeiter
existiert" tatsächlich implementiert und nicht nur für die Schreibrichtung
behauptet.

**Warum kein Durchschreiben auf beide Seiten:** HR pflegt dieselben Felder auf der
Mitarbeiterkarte (F8, `Employees/Show.php:263` und `:267`) und schreibt dabei nur
hrData. Bei Dual-Write wäre die Bewerber-Spalte danach veraltet, das Modal würde
sie laden, und das nächste Speichern schöbe den alten Wert über HRs aktuelle
Korrektur — ohne jede Meldung. Mit der Phasenregel existiert nie ein zweiter Wert
für dasselbe Feld.

Zur `=== null`-Prüfung: sie kann in *diesem* Pfad nie blockieren, weil
`ensureHrData()` ein `firstOrCreate` auf einem gerade erzeugten Employee ist (F8) —
die Row ist definitionsgemäß neu, alle Felder NULL, und ein „späterer HR-Edit"
existiert zum Aufrufzeitpunkt nicht. Sie bleibt trotzdem drin: billig und
defensiv gegen künftige Aufrufer. **Keine andere Begründung in den Code
schreiben** — falsche Begründungen werden abgeschrieben.

Der Puffer am Bewerber wird nicht geleert (Archiv, §Tradeoffs).

### §4b Bewertung auf der Mitarbeiterkarte

Wird der Bewerber zum Mitarbeiter (Portallink + Vertragsversand), müssen **alle**
Bewertungsdaten mitwandern — die fünf Kriterien und der Freitext, nicht nur
Wäschepaket und Qualifikation. Ohne das zeigt die Mitarbeiterkarte für jeden neuen
MA dauerhaft keine Bewertung, während der Altbestand über `star_rating` eine zeigt
(§1: `star_rating` wird nicht mehr geschrieben). Das ist der Defekt, den dieser
Abschnitt behebt.

**Umsetzung: live auflösen, nicht kopieren.** Die Mitarbeiterkarte zeigt die Werte
der jüngsten `attended`-Buchung des über `rec_applicant_id` verknüpften Bewerbers —
**dieselbe Regel wie §5**: `interview.starts_at DESC`, Tie-Break `bookings.id DESC`,
`deleted_at IS NULL`. Anzeige **read-only**, mit Sprung in die Nachbereitung des
betreffenden Termins für Korrekturen.

**Die Auswahlregel wird EINMAL implementiert** — `LatestAttendedBookingResolver` —
und von §4b und §5 gemeinsam benutzt. Zwei getrennte Implementierungen derselben
Regel würden garantiert auseinanderlaufen, und dann zeigen Mitarbeiterkarte und
ZAS-Export verschiedene Werte, was genau der Zustand ist, den die Live-Auflösung
verhindern soll.

**Warum kein hrData-Snapshot:** Er bräuchte fünf weitere Spalten plus eine
Freitextspalte, und bei jeder nachträglichen Korrektur an der Buchung liefen
Snapshot und Buchung auseinander — genau die zweite Wahrheit, die §5 vermeidet.
Live aufgelöst zeigen Mitarbeiterkarte und ZAS-Export zwangsläufig denselben Wert.

Die Anzeige passt **nicht** in `hrFieldGroups()` (`Employees/Show.php:252-270`) —
dieser Mechanismus rendert editierbare Felder, die auf hrData-Spalten liegen.
Der neue Block ist ein eigener read-only Abschnitt neben der bestehenden Gruppe
„Bewertung & Qualifikation"; `star_rating` bleibt dort als Altbestand stehen (§1).

### §5 ZAS-Export

**Fünf neue Spalten am Ende** von `ZasEmployeeFieldResolver::COLUMNS`, in der
Reihenfolge der Kriterien-Tabelle — nie zwischen bestehende (F6).

**Auflösung live aus der Buchung**, nicht als hrData-Snapshot: über
`LatestAttendedBookingResolver` (dieselbe Klasse wie §4b) — Werte der jüngsten
`attended`-Buchung des Bewerbers, sortiert `interview.starts_at DESC`, Tie-Break
`bookings.id DESC`, `deleted_at IS NULL`. Join-Weg wie
`ZasReExportByBookingDate.php:58-62`. Live-Auflösung ist im Resolver etabliert
(z.B. `avContractEndDate()`, ausdrücklich „nicht den hrData-Snapshot", `:509`) und
vermeidet eine zweite Wahrheit.

**Sortierung bewusst nach Termindatum, nicht nach Insert-ID:** Bei einer Umbuchung
kann die zuletzt erfasste Buchung ein früheres Termindatum haben. Exportiert wird
die Bewertung der letzten *tatsächlichen* Teilnahme.

**`Sternebewertung`, `Waeschepaket`, `Qualifikation` bleiben unverändert** —
gleiche Spalten, gleiche Quelle (hrData). Der Export bleibt damit für Hr. Michel
rückwärtskompatibel; er kann die fünf neuen Spalten in seinem Tempo aufnehmen.

**Bekannte Skalierungsgrenze:** Die Auflösung ist ein Join **pro Employee-Zeile**,
und mit §4b kommt derselbe Join auf der Mitarbeiterkarte hinzu. Bei 30 Mitarbeitern
irrelevant; wächst der Bestand in die Tausende, wird der Export-Endpunkt spürbar
und die Auflösung muss auf eine Batch-Query über alle exportierten Employees
umgestellt werden. Bewusst jetzt nicht gebaut (YAGNI), aber benannt, damit es nicht
später als Rätsel auftaucht.

**Update-Marker (F9):** Neuer Listener `RecInterviewBooking::saved` im
`RecEmployeeExportObserver`, nach dem Muster von `RecContract::saved`
(`:137-153`): nur bei Änderung an einem der fünf Rating-Felder, Employee über
`rec_applicant_id` suchen, `markEmployeeId()`. Ohne diesen Listener erreicht eine
nachträgliche Korrektur den ZAS-Update-Export nie. In `safelyRun` wie alle anderen.

**Bei der Ersterfassung markiert der Listener nichts — das ist korrekt.** Zum
Zeitpunkt der Bewertung existiert typischerweise noch kein Employee (der entsteht
erst mit dem Vertragsversand), der Lookup über `rec_applicant_id` findet also
nichts und bricht ab. Diese Werte liefert der **Initial**-Export aus, der ohnehin
alle noch nicht exportierten Mitarbeiter enthält. Der Listener deckt ausschließlich
den Fall „Korrektur an einer Buchung, deren Bewerber schon Mitarbeiter ist" ab.
**Ohne diesen Hinweis wird der Listener später als kaputt gelesen.**

### §6 Verhältnis zur Zertifikat-Spec

**Paket A der Zertifikat-Spec entfällt vollständig** und wird von dieser Spec
ersetzt: Puffer-Spalten, `EvaluationAvailability` (hier neu und einfacher
geschnitten), Ziel-Weiche und Übernahme sind hier abgedeckt, mit dem größeren
Datenmodell. §B/§C/§D (Vorlagen-Typ, Ausstellung am HR-Schreibtisch,
WhatsApp-Zustellung) bleiben unberührt und werden nach diesem Paket umgesetzt. Der
Nicht-EU-Sonderfall („bewertbar, obwohl kein Vertrag") ist mit dieser Spec
gegenstandslos — die Freigabe hängt an `attended`, nicht am Vertragsstand.

## Tests & Verifikation

**Pure-Unit (PHPUnit, ohne Laravel/DB):**
- `EvaluationAvailability::isOpen`-Matrix über alle bekannten Buchungsstatus
  (`BookingStatusGroups::KNOWN`: booked, registered, confirmed, attended,
  cancelled, no_show).
- `RatingCriteria`: Vollständigkeit (5 Einträge), Eindeutigkeit von Spalten- und
  ZAS-Namen, Label/Handout-Text je Kriterium vorhanden.
- Wertebereichs-Normalisierung der Sterne (nur 1–5, sonst NULL) und der
  Lookup-Arrays (`[]` → NULL, F7).
- Sortierschlüssel „Nachname, Vorname" inkl. Leerfälle (kein Kontakt, kein
  Nachname) — die dürfen die Sortierung nicht kippen, und Anzeige-String und
  Sortierschlüssel müssen identisch sein (§3).
- „bereits erfasst"-Zustand der Abschluss-Zelle als pure Funktion (Freitext ODER
  Wäschepaket ODER Qualifikation gesetzt) — die Sterne stehen inline und zählen
  hier nicht mit.
- Stern-Toggle: gleicher Wert erneut → NULL, anderer Wert → neuer Wert.
- Auswahlregel „jüngste attended-Buchung" (starts_at DESC, Tie-Break id DESC)
  inkl. Umbuchungs-Fall.
- Selfie-Auflösung als pure Funktion: File-ID skalar vs. JSON-Array (erste ID
  gewinnt), leerer/`0`-Wert, kein Feld definiert, Datei kein Bild → jeweils
  Platzhalter; Thumbnail vorhanden → Thumbnail, sonst Original (F12).

**Harness (sqlite, Muster Warteliste/Dedup-Guard):** Phasenregel aus §4 — bei
existierendem Employee schreibt das Modal **nur** hrData und lässt die
Bewerber-Spalten unberührt, ohne Employee **nur** die Bewerber-Spalten; Übernahme
bei MA-Erst-Anlage; `RecInterviewBooking::saved` setzt `zas_changed_at` nur bei
Rating-Änderung und nur mit vorhandenem Employee; ZAS-Auflösung und §4b-Auflösung
liefern die Werte derselben (richtigen) Buchung.

**Live-Sichttest nach Deploy:**

1. Termin mit zwei Teilnehmern, einer auf `attended` gesetzt → dessen fünf
   Sterne-Zellen und Abschluss-Button offen, beim anderen gesperrt.
2. Fünf Sterne inline setzen (jeder Klick speichert sofort), erneuter Klick auf
   denselben Wert setzt zurück. Wäschepaket + Qualifikation + Bewertungstext im
   Abschluss-Modal erfassen.
3. Suche und A–Z-Sortierung im Nachbereitungs-Modus prüfen — Anzeige
   „Nachname, Vorname", Reihenfolge sichtbar alphabetisch.
4. Selfie-Spalte: Bild beim Bewerber mit Upload, Platzhalter ohne, Klick öffnet die
   Großansicht — im Netzwerk-Tab prüfen, dass nicht pro Zeile nachgeladen wird
   (Batch-Query, §3a).
5. **Umbuchungs-Test:** denselben Bewerber auf einen anderen Termin umbuchen → die
   Bewertung bleibt an der alten Buchungszeile, die neue Zeile startet leer bei den
   Sternen und beim Text; Wäschepaket und Qualifikation sind vorbelegt
   (personengebunden).
6. **Nach Versand von Portallink + Verträgen:** die Mitarbeiterkarte zeigt alle fünf
   Sterne, den Bewertungstext, Wäschepaket und Qualifikation (§4b), die fünf neuen
   Spalten im ZAS-Export sind gefüllt.
7. Nachträgliche Korrektur eines Sterns an der Buchung → `zas_changed_at` am
   Employee gesetzt, Wert im Update-Export geändert.

## Benannte Tradeoffs

- **Freitext termingebunden.** Er liegt an der Buchung, nicht an der Person. Im
  Normalfall (genau ein Termin, K1) ist das ohne Unterschied; **bei einer Umbuchung**
  entstehen zwei Buchungszeilen und damit potenziell zwei Texte, und „der Text zum
  Bewerber" ist keine einzelne Aussage mehr. Bewusst so: der Kunde beschreibt ihn als
  Abschluss *dieser* Bewertung, und die eindeutige Zuordnung zum Termin wiegt mehr
  als eine garantiert einzelne Aussage. Wollte man genau einen, immer aktuellen Text
  pro Person, wäre das eine Spalte am Bewerber mit Überschreib-Semantik —
  Ein-Zeilen-Änderung, aber dann ist bei Umbuchung nicht mehr erkennbar, zu welchem
  Termin die Einschätzung gehört.
- **Wäschepaket/Qualifikation sind terminübergreifend.** Sie liegen an der Person;
  eine Änderung gilt für alle Buchungszeilen dieses Bewerbers. Gewollt (Sachstand,
  nicht Momentaufnahme) und im Normalfall unsichtbar, weil es nur eine Zeile gibt.
- **Die Bewertung auf der Mitarbeiterkarte ist abgeleitet, nicht gespeichert**
  (§4b). Wird die Buchung gelöscht, ist die Bewertung dort nicht mehr sichtbar — und
  auch nicht mehr im ZAS-Export. Bewusst akzeptiert: eine Wahrheit schlägt Redundanz.
  Ein Snapshot würde bei jeder Korrektur an der Buchung auseinanderlaufen.
- **`star_rating` bleibt als toter Zweig liegen.** Wird nicht mehr geschrieben,
  aber weiter als `Sternebewertung` exportiert und auf der Mitarbeiterkarte
  angezeigt. Ein Aufräumen (Spalte entfernen, ZAS-Spalte streichen) ist eine
  separate Abstimmung mit Hr. Michel — nicht Teil dieses Scopes.
- **Erst-Anlage-Semantik der Übernahme.** `createOrUpdate()` steigt bei
  existierendem Employee vor `:105` aus (F8); Backfill- und Re-Export-Aufrufe
  tragen den Bewerber-Puffer daher bewusst nicht mit. Relevant nur, wenn Werte am
  Bewerber liegen und der MA auf anderem Weg entstand.
- **Stiller Verlust bei Übernahme-Fehler.** Das try/catch-Muster schluckt Fehler
  in ein `Log::warning`, damit die MA-Anlage nicht kippt. Übernimmt sich das
  Wäschepaket nicht, merkt es niemand außer im Log; die Werte liegen weiter am
  Bewerber.
- **Keine Bewertungs-Historie pro Termin.** Eine Korrektur überschreibt den
  vorherigen Wert derselben Buchung; wer wann was geändert hat, ist nicht
  nachvollziehbar. Pro Termin genau ein Stand.
- **Bewertung ohne Anwesenheit unmöglich.** Wer vergisst, `attended` zu setzen,
  kann nicht bewerten. Bewusst: eine Regel, die der Schulungsleiter versteht, ist
  wertvoller als ein Schlupfloch.
- **Sortierung über CRM-Daten.** Bewerber ohne verknüpften CRM-Kontakt oder ohne
  Nachnamen haben keinen Sortierschlüssel und landen am Ende. Sichtbar nur bei
  unvollständigen Datensätzen.
- **Selfie-Spalte bleibt bei manchen Bewerbern dauerhaft leer.** Das Feld ist
  stellen-/phasengebunden (F12); wo eine Stelle kein Selfie erhebt, gibt es nichts
  anzuzeigen. Ein Nachfordern des Selfies ist nicht Teil dieses Scopes.
- **Signierte Bild-URLs laufen nach 60 Minuten ab.** Bleibt die Nachbereitung
  länger offen, ohne dass Livewire die Zeilen neu rendert, liefern die Bild-URLs
  403. Praktisch unkritisch, weil jede Bewertung ein Re-Render auslöst; ein
  Seiten-Reload behebt es in jedem Fall.

## Deploy

- **Zwei-Push-Struktur:** Migrationen zuerst (Buchungs- und Bewerber-Spalten),
  Feature danach. Die Blade liest die neuen Spalten; ein Feature-Deploy vor der
  Migration wirft in der Nachbereitungs-Ansicht.
- **`composer.lock`-Bump in `meingedeck` nach jedem Push** — sonst nicht live.
- **`queue:restart` IST nötig** — anders als bei der Zertifikat-Spec. Der neue
  `RecInterviewBooking::saved`-Listener und die neuen Casts/Fillables auf
  `RecInterviewBooking` werden von den Worker-Prozessen mitgeladen, und es gibt Jobs,
  die Buchungen schreiben (Erinnerungen, `seat_released_at`/Standby,
  Warteliste-Re-Arm). Long-running Worker halten sonst die alten Klassendefinitionen,
  und der Listener feuert dort nicht. Die Repo-Regel ist damit erfüllt: Code, den
  Worker ausführen, hat sich geändert.
- **Vor dem Deploy abstimmen:** fünf ZAS-Spaltennamen mit Hr. Michel bestätigen.
  Der Export bleibt ohne seine Änderung funktionsfähig — er sieht die neuen Spalten
  erst, wenn er sie aufnimmt.
- **Handout-PDF** ins Repo legen und im Popover am Spaltenkopf verlinken (§3).

## Benannte Lücken

- **Handout-Texte pro Kriterium liegen noch nicht vor.** Bis dahin trägt
  `RatingCriteria` Label + Spalten + ZAS-Namen; das Popover wird mit dem Text
  befüllt, sobald er da ist. Kein struktureller Einfluss.
- **Handout-PDF-Datei** liegt noch nicht im Repo.
- **Bestätigung der ZAS-Spaltennamen** durch Hr. Michel steht aus (§Deploy).

## Betroffene Dateien

- **Neu:** `database/migrations/*_add_ratings_to_rec_interview_bookings.php`,
  `database/migrations/*_add_evaluation_buffer_to_rec_applicants.php`,
  `src/Support/RatingCriteria.php`, `src/Support/EvaluationAvailability.php`,
  `src/Support/LatestAttendedBookingResolver.php` (gemeinsame Auswahlregel für §4b
  und §5 — eine Implementierung, zwei Aufrufer), zugehörige `tests/Unit/*`,
  Handout-PDF als Asset.
- **Ändern:** `src/Models/RecInterviewBooking.php` (fillable + casts),
  `src/Models/RecApplicant.php` (fillable + casts),
  `src/Livewire/InterviewBookings/Index.php` (Freigabe-Regel, `setRating()`,
  Abschluss-Modal, Phasen-Weiche §4, Sortierung, Suchfeld-Sichtbarkeit,
  Selfie-Batch-Query),
  `resources/views/livewire/interview-bookings/index.blade.php` (fünf
  Sterne-Spalten, Abschluss-Zelle + Modal, Suchfeld, Handout-Popover,
  Selfie-Spalte, Bewerber-Spalte „Nachname, Vorname"),
  `src/Services/CreateEmployeeFromApplicantService.php` (Übernahme neben `:105`),
  `src/Services/Zas/ZasEmployeeFieldResolver.php` (fünf Spalten + Auflösung),
  `src/Observers/RecEmployeeExportObserver.php` (Booking-Listener),
  `src/Livewire/Employees/Show.php` + `resources/views/livewire/employees/show.blade.php`
  (read-only Bewertungsblock, §4b).
