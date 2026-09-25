# Bestandsaufnahme altes Mitarbeiterportal — Abnahmeliste für den Umbau auf PortalShell

Stand: 2026-09-25 · Branch `feat/ma-portal` · Arbeitsverzeichnis
`modules/platforms-recruiting-portal`

**Zweck.** Das alte Portal (`src/Livewire/Public/EmployeePortal.php`, 786 Zeilen +
`resources/views/livewire/public/employee-portal.blade.php`, 412 Zeilen) soll
auslaufen. Vorher muss das neue (`src/Livewire/Public/PortalShell.php`) alles
können, was das alte kann. Diese Datei ist die Liste, gegen die abgenommen wird.
Jede Aussage trägt ihre Fundstelle. Wo etwas unklar ist, steht „unklar" —
nicht geraten.

**Lesehinweis zur Quellenlage.** Die Feldliste steht NICHT im Portal, sondern in
`RecEmployee::editableFieldGroups()` (`src/Models/RecEmployee.php:356-499`). Das
Blade rendert sie generisch, verhält sich aber in vier Punkten eigen — die
stehen in §1.4.

---

## 1. Felder, die der Mitarbeiter im alten Portal ändern kann

### 1.1 Die Tabelle

47 editierbare Feld-Schlüssel in 13 Gruppen. Quelle durchgehend
`src/Models/RecEmployee.php:356-499`, Render `…/employee-portal.blade.php:252-388`.

Spalte „Pflicht":
- **hart** = ein Guard in `saveAll()` bricht ab, es wird GAR NICHTS gespeichert
- **weich** = roter Rand am Feld + Nennung im Banner „Diese Daten fehlen noch"
  (`EmployeePortal.php:519-520`, Blade `190-198` und `287`), aber Speichern geht
- **nein** = nicht als fehlend markiert

| Spalte | Beschriftung | Gruppe | Typ | Pflicht | Sichtbarkeit hängt ab von | Besonderheiten |
|---|---|---|---|---|---|---|
| `email` | Email | Kontakt | text | weich | immer | keine Formatprüfung (`RecEmployee.php:362`) |
| `phone` | Telefon | Kontakt | text | weich | immer | **löst CRM-Kontakt-Sync aus**, s. N5 (`RecEmployee.php:363`) |
| `street` | Strasse | Adresse | text | weich | immer | lohnrelevant (§3.3) |
| `house_number` | Hausnummer | Adresse | text | weich | immer | lohnrelevant |
| `zip` | PLZ | Adresse | text | weich | immer | lohnrelevant |
| `city` | Ort | Adresse | text | weich | immer | lohnrelevant |
| `country_code` | Land | Adresse | text | weich | immer | **Freitext, kein Lookup** — Label bewusst nur „Land" (Fix E2) (`RecEmployee.php:370`) |
| `birth_country` | Geburtsland | Adresse | lookup `geburtsland` | weich | immer | seit 23.09. NICHT mehr die ZAS-`Nation` (`RecEmployee.php:371-373`) |
| `nationality` | Staatsangehörigkeit | Adresse | lookup `geburtsland` | **hart** (R16) | immer | gleicher Lookup wie Geburtsland (`RecEmployee.php:374`) |
| `birth_name` | Geburtsname | Persoenliches | text | weich | immer | |
| `birth_place` | Geburtsort | Persoenliches | text | weich | immer | |
| `gender` | Geschlecht | Persoenliches | lookup `geschlecht` | weich | immer | |
| `marital_status` | Familienstand | Persoenliches | lookup `familienstand` | weich | immer | |
| `employment_type` | Ich bin | Persoenliches | lookup `beschaeftigung_art` | weich | immer | **steuert die Bescheinigungs-Gruppe** (R29) |
| `religion` | Religion | Persoenliches | lookup `religion` | weich | immer | |
| `number_of_children` | Anzahl Kinder | Persoenliches | text | weich | immer | Cast `integer` (`RecEmployee.php:166`), Eingabe aber Freitext |
| `iban` | IBAN | Bankdaten | text | weich | immer | keine IBAN-Prüfung; lohnrelevant |
| `bic` | BIC | Bankdaten | text | weich | immer | lohnrelevant |
| `bank_institute` | Bank | Bankdaten | text | weich | immer | lohnrelevant |
| `account_holder` | Kontoinhaber | Bankdaten | text | weich | immer | lohnrelevant |
| `tax_class` | Steuerklasse | Steuer & Versicherung | **inline_select** `1..6` | weich | immer | Typ steht nicht im Docblock, s. §1.4 (`RecEmployee.php:392`) |
| `steuer_id` | Steuer-ID | Steuer & Versicherung | text | weich | immer | **Mutator entfernt jeden Leerraum** (R31) |
| `sozialversicherungsnummer` | Sozialversicherungsnummer | Steuer & Versicherung | text | weich | immer | **Mutator entfernt jeden Leerraum** (R31) |
| `health_insurance` | Krankenkasse | Steuer & Versicherung | lookup `krankenkasse` | weich | immer | lohnrelevant |
| `health_insurance_card_file_id` | Foto Versichertenkarte | Steuer & Versicherung | file | weich | immer | Upload sofort (R24–R26) |
| `is_main_employer` | Ist Rheingedeck der Hauptarbeitgeber? | Arbeitgeber | bool (dreiwertig) | **hart** (R17) | immer | **einziges Feld mit `live`** → sofortige Übertragung (`RecEmployee.php:406-410`, Blade `310-315`) |
| `other_employer` | Wer ist es dann? | Arbeitgeber | text | **hart, nur bei „nein"** | `visible_if: is_main_employer=false` — unbeantwortet ⇒ sichtbar | `maxlength=128` (`MainEmployerRequiredGuard::MAX_OTHER_EMPLOYER`); wird bei „ja" **zwangsgeleert** (R21) |
| `identity_card_valid_until` | Ausweis gueltig bis | Ausweis | date | weich | immer | `color-scheme: light` (Fix E4) |
| `identity_card_front_file_id` | Ausweis Vorderseite | Ausweis | file | weich | immer | |
| `identity_card_back_file_id` | Ausweis Rueckseite | Ausweis | file | weich | immer | |
| `selfie_file_id` | Selfie | Ausweis | file | weich | immer | |
| `immatrikulation_file_id` | Immatrikulationsbescheinigung | Schul-/Immatrikulationsbescheinigung | file | weich | nur `employment_type` ∈ {`student`, `student_erwerbstaetig`} | `SchoolCertificateFields:30-33` |
| `schulbescheinigung_file_id` | Schulbescheinigung | Schul-/Immatrikulationsbescheinigung | file | weich | nur `employment_type` = `schueler` | ebd. |
| `school_certificate_valid_until` | Gueltig bis | Schul-/Immatrikulationsbescheinigung | date | weich | bei Schüler UND Student | eine Spalte für beide Nachweise |
| `has_infection_protection_certificate` | Infektionsschutzbescheinigung vorhanden? | Gesundheit | bool | weich | immer | kein `live`, keine Folgepflicht |
| `infection_protection_first_issued_at` | Erstbescheinigung am | Gesundheit | date | weich | immer | |
| `erstbescheinigung_file_id` | Erstbescheinigung (Datei) | Gesundheit | file | weich | immer | **bewusst NICHT im ZAS-Export** (§3.2) |
| `is_first_aider` | Ersthelfer (gueltiger Erste-Hilfe-Schein) | Arbeitsschutz | bool (dreiwertig) | weich | immer | Frage selbst bleibt weich (`c3e5b1b`) |
| `first_aider_valid_until` | Ersthelfer-Schein gueltig bis | Arbeitsschutz | date | **hart, nur bei `is_first_aider === true`** | immer sichtbar | `required_if` steuert nur die Fehlt-Markierung (`RecEmployee.php:456-460`) |
| `first_aider_certificate_file_id` | Ersthelfer-Schein (Datei) | Arbeitsschutz | file | **hart, nur bei `is_first_aider === true`** | immer sichtbar | Dokumentpflicht gilt NUR im Portal, nicht in der HR-Akte (`FirstAiderDateGuard:17-23`) |
| `shirt_size` | Hemd / Bluse | Arbeitskleidung | inline_select `S,M,L,XL` | weich | immer | |
| `pants_size` | Hosengroesse (Zahl) | Arbeitskleidung | text | weich | immer | Cast `integer` |
| `shoe_size` | Schuhgroesse (Zahl) | Arbeitskleidung | text | weich | immer | Cast `integer` |
| `drivers_license_class` | Fuehrerschein-Klasse | Sonstiges | text | weich | immer | |
| `has_car` | PKW vorhanden | Sonstiges | bool | weich | immer | |
| `residence_permit_valid_until` | Aufenthaltserlaubnis bis | Aufenthalt (Non-EU) | date | weich | **nur wenn `is_eu_citizen === false`** (strikt; `null` ⇒ Gruppe fehlt) | `RecEmployee.php:358, 491-496` |
| `work_permit_valid_until` | Arbeitsgenehmigung bis | Aufenthalt (Non-EU) | date | weich | wie oben | ebd. |

### 1.2 Nur-Lese-Felder (angezeigt, nicht änderbar)

`RecEmployee::readOnlyDisplayFields()` (`src/Models/RecEmployee.php:562-576`),
Render Blade `215-232` unter der Überschrift „Einträge bei Bewerbung" mit dem
Abzeichen „nicht änderbar". Leere Werte werden ausgeblendet.

| Spalte | Beschriftung | Warum nicht editierbar |
|---|---|---|
| `identity_card_number` | Ausweisnummer | Login-Faktor 2 — Änderung würde aussperren (`RecEmployee.php:559`) |
| `recruited_by_personnel_number` | Geworben von (Personalnummer) | einmalig in P3 gesetzt (`RecEmployee.php:560`) |

### 1.3 Bewusst ausgeschlossen

`RecEmployee.php:330-336`: `first_name`, `last_name`, `birth_date`,
`identity_card_number`, `is_eu_citizen` und die Legal-Status-`file_id`s,
`recruited_by_personnel_number`, `personnel_number`.

### 1.4 Wo das Blade von der Feld-Definition abweicht

1. **`inline_select` ist ein fünfter Typ, den der Docblock nicht kennt.** Der
   Typ-Katalog in `RecEmployee.php:323-328` nennt nur text/date/bool/lookup/file.
   `tax_class` und `shirt_size` nutzen aber `inline_select`
   (`RecEmployee.php:392, 468`); gerendert wird das in Blade `331-340`, Wert und
   Beschriftung sind derselbe String. In `formatDisplayValue()`
   (`EmployeePortal.php:552-558`) fällt `inline_select` in den `default`-Zweig.
2. **Die Upload-Zuordnung existiert zweimal.** Einmal als
   `EmployeePortal::FILE_FIELDS` (`93-102`), einmal wörtlich im Blade als
   `$fileUploadProps` (`240-249`). Steht ein File-Feld nur in einer der beiden
   Listen, erscheint entweder kein Hochladen-Knopf (Blade fehlt) oder der Klick
   läuft ins Leere. Genau das ist am 06.08. passiert, s. Fix E7.
3. **Zwei Gruppen bekommen im Blade einen fest eingebauten Erklärtext**, der
   nicht aus der Feld-Definition kommt: `Arbeitsschutz` (Blade `255-259`) und
   `Arbeitgeber` (Blade `260-270`). Wer die Gruppen im neuen Portal nachbaut und
   nur die Felder überträgt, verliert beide Texte — der Arbeitgeber-Text ist
   fachlich geprüft und wurde zweimal korrigiert (Fixes E9, E10).
4. **Das Blade versteckt nichts von sich aus.** Die gesamte Sichtbarkeit
   entscheidet `EmployeePortal::editableGroups()` über `fieldIsVisible()`
   (`509-511`, `631-646`). Eine leere Gruppe wird trotzdem als leere Karte mit
   Überschrift gerendert (Blade `272-277` läuft ohne `@if(count($entries))`) —
   **unklar**, ob das je auftritt: `Schul-/Immatrikulationsbescheinigung` wird bei
   leerem Inhalt in `RecEmployee.php:486-488` entfernt, alle anderen Gruppen haben
   mindestens ein unbedingt sichtbares Feld.

---

## 2. Regeln, die beim Speichern (und beim Betreten) greifen

### 2.1 Reihenfolge in `saveAll()` — die ist bindend

`src/Livewire/Public/EmployeePortal.php:256-380`. Die drei Guards laufen
**nacheinander mit Early-Return**; wer den ersten nicht passiert, sieht die
späteren Fehler nie.

1. `FirstAiderDateGuard::error(...)` — Zeile `281-292`
2. `NationalityRequiredGuard::error(...)` — Zeile `296-303`
3. `MainEmployerRequiredGuard::error(...)` — Zeile `315-323`

Erst danach wird überhaupt ein `$updates`-Array gebaut.

### 2.2 Alle Regeln, einzeln

**Zugang / Zustand**

| # | Regel | Fundstelle | Was sie blockiert, Begründung im Code |
|---|---|---|---|
| R1 | Token unbekannt **oder** `is_active = false` ⇒ `tokenInvalid` | `EmployeePortal.php:111-116` | Kein Datenladen. |
| R2 | `portal_v2_since !== null` ⇒ Redirect auf `recruiting.public.portal-shell` mit demselben Token | `EmployeePortal.php:132-136` | „Das alte Portal schreibt über Eloquent (setzt also den ZAS-Export-Marker) und legt KEINE Nachweis-Zeile an — was er hier einträge, käme im neuen Portal nie an." Bewusst `redirect()`, kein `navigate` (verschiedene Layouts). |
| R3 | `portal_locked_at !== null` ⇒ `portalLocked = true`, **kein** `loadFieldValues()` | `EmployeePortal.php:146-149` | Eskalations-Stufe 3 (`DispoEmployeeGateway::lockPortal`). „serverseitig VOR jedem Feldladen prüfen — keine Daten laden". |
| R4 | Session-Flag `employee_portal_verified:{employeeId}` vorhanden ⇒ direkt `verified` | `EmployeePortal.php:157-160`, Schlüssel `746-749` | Kein dauerhaftes Login, nur Session. |
| R5 | Sperr-Cache gesetzt ⇒ `rateLimited` | `EmployeePortal.php:163-166`, `776-779` | |
| R6 | `verify()`: Rate-Limit erneut | `171-176` | |
| R7 | `verify()`: Datensatz weg ⇒ `tokenInvalid` | `178-182` | |
| R8 | `verify()`: `portal_locked_at` **erneut** | `184-189` | „der MA kann zwischen mount() und verify() gesperrt worden sein (Eskalations-Cron läuft unabhängig vom Request)". |
| R9 | `verify()`: leere Eingabe ⇒ Meldung, **kein** Fehlversuch verbraucht | `191-196` | |
| R10 | `verifyPortalAccess()`: `is_active` + `birth_date` + `identity_card_number` gesetzt, Geburtsdatum exakt `Y-m-d`, letzte 4 Stellen **case-insensitiv und whitespace-bereinigt** | `RecEmployee.php:304-317` | `strcasecmp` + `preg_replace('/\s+/','')`. Ausweisnummern enthalten Buchstaben (Fix E5). |
| R11 | 5 Fehlversuche ⇒ 15 min Sperre; Zähler und Sperre hängen am **Token**, nicht am Mitarbeiter | `104-105`, `751-784` | Cache-Schlüssel `employee_portal_attempts:{token}` / `employee_portal_locked:{token}`. |

**Speichern**

| # | Regel | Fundstelle | Was sie blockiert |
|---|---|---|---|
| R12 | `state !== 'verified'` **oder** `portalLocked` ⇒ stiller Abbruch | `258-260` | Schützt gegen `$wire.call('saveAll')` ohne Anmeldung. |
| R13 | Datensatz nicht mehr auffindbar ⇒ Abbruch | `261-264` | |
| R14 | `portal_locked_at` **frisch aus der DB** ⇒ Abbruch | `265-270` | „eine zwischenzeitliche Sperre darf den Save nicht mehr durchlassen, unabhängig vom gecachten Session-State". |
| R15 | **FirstAiderDateGuard, mit Dokumentpflicht** (`$requireCertificate = true`): bei `is_first_aider` ∈ {`1`,`true`,`ja`} müssen Bis-Datum UND Datei da sein; drei getrennte Fehlertexte | `281-292`; `src/Support/FirstAiderDateGuard.php:33-63` | Endzustands-Prüfung — blockt auch Saves, die nur andere Felder ändern, „damit ein unvollständiger Zustand nicht stehenbleibt". Die File-Id kommt **vom Datensatz**, nicht aus `$fieldValues` (Zeile `284`), weil Dateien über die Upload-Properties laufen. `(int)` fängt `''` und eine `0` aus manipuliertem POST (`FirstAiderDateGuard:47-49`). HR (`Employees/Show.php:786`) ruft denselben Guard **ohne** vierten Parameter — sonst wären die ~12 Bestands-Ersthelfer ohne Datei unspeicherbar. |
| R16 | **NationalityRequiredGuard**: `nationality` leer ⇒ Abbruch. Rückfall auf den Datensatz, falls das Formular den Schlüssel nicht mitschickt | `296-303`; `src/Support/NationalityRequiredGuard.php:20-27` | Kundenentscheidung 23.09.2026. Nur Portal, nicht HR — „HR darf nicht an einem Feld hängenbleiben, das der Mitarbeiter liefern muss". |
| R17 | **MainEmployerRequiredGuard**: `is_main_employer` unbeantwortet ⇒ Abbruch; bei „nein" ohne Namen ⇒ Abbruch; Name > 128 Zeichen ⇒ Abbruch | `315-323`; `src/Support/MainEmployerRequiredGuard.php:35-61` | Markus 24.09.2026 — an der Angabe hängt die Steuerklasse. 128 = Spaltenbreite; ohne Grenze SQLSTATE 22001, „derselbe Abbruch, der am 25.08.2026 die MA-Anlage gekillt hat". |
| R18 | **Der `(string)`-Fallback beim dreiwertigen `is_main_employer` — ausdrücklich NICHT `(string)`** | `312-314` | `(string) false` ergäbe `''`, also genau die Form, die der Guard als „unbeantwortet" liest: „Wer ordentlich ‚nein' geantwortet hat, könnte dann nie wieder speichern." Deshalb Abbildung auf `'1'`/`'0'`. |
| R19 | Whitelist: Schlüssel, die nicht in `editableFieldsFlat()` stehen, werden übersprungen | `328-331` | „Schutz gegen manipulated POST". |
| R20 | `type === 'file'` wird beim Speichern **immer** übersprungen | `334-341` | „sonst könnte ein manipulierter POST fremde File-Ids setzen oder einen gerade geprüften Nachweis im selben Request wieder leeren" — die Lücke wurde in `c3e5b1b` geschlossen. |
| R21 | `is_main_employer === true` ⇒ `other_employer` wird auf `null` gesetzt, **auch wenn im Formular noch etwas stand oder am Datensatz hing** | `361-366` | Entscheidung 25.09.2026: die Spalte ist ausschließlich die Antwort auf „wenn nicht wir, wer dann". Sonst hätte „wer im Juli ‚nein, Müller GmbH' angab und im September auf ‚ja' wechselt, Müller als Hauptarbeitgeber stehen". |
| R22 | Werte: Strings werden getrimmt; `bool` über `PortalBoolValue::parse()`; alles andere `'' ⇒ null` | `342-350` | |
| R23 | `$updates` leer ⇒ Meldung „Keine Aenderungen.", kein DB-Zugriff | `368-371` | |

**Dateien**

| # | Regel | Fundstelle | Was sie blockiert |
|---|---|---|---|
| R24 | `handleFileUpload()`: dieselben drei Wächter wie beim Speichern (`state`, `portalLocked`, frisches `portal_locked_at`) | `434-444` | |
| R25 | Feld muss in `editableFieldsFlat()` stehen **und** Typ `file` haben | `450-453` | |
| R26 | Jeder `\Throwable` beim Upload wird gefangen und als Flash ausgegeben (`'Upload-Fehler: ' . $e->getMessage()`) | `467-469` | **Befund:** die Roh-Meldung der Ausnahme wird dem Mitarbeiter angezeigt. |

**Sichtbarkeit / Pflicht-Ableitung**

| # | Regel | Fundstelle | Wirkung |
|---|---|---|---|
| R27 | `visible_if` — dreiwertig: sichtbar, solange die Bedingung nicht **ausdrücklich widerlegt** ist. Gemessen gegen den **Formularwert**, ersatzweise den Datensatz | `631-646` | „Ein unbeantwortetes Ja/Nein versteckt also nichts" — sonst sähe niemand, dass nach einem „nein" noch etwas verlangt wird. |
| R28 | `required_if` — **strikter** Vergleich gegen den Datensatz | `RecEmployee.php:544-552` | „is_first_aider ist als boolean gecastet und dreiwertig. Ein lockerer Vergleich würde ‚unbeantwortet' (null) mit ‚Nein' (false) verwechseln." Steuert `missingFields()` und den roten Rand, **nicht** die Sichtbarkeit. |
| R29 | Bescheinigungs-Gruppe nach `employment_type`; leere Gruppe wird entfernt | `RecEmployee.php:482-488`; `src/Support/SchoolCertificateFields.php:27-34` | Schüler → Schulbescheinigung, Student/`student_erwerbstaetig` → Immatrikulation, sonst Gruppe weg. HR bleibt bewusst ungegatet. |
| R30 | Non-EU-Gruppe nur bei `is_eu_citizen === false` (strikt) | `RecEmployee.php:358, 491-496` | Bei `null` erscheint die Gruppe nicht. |
| R31 | Mutatoren: `steuer_id` und `sozialversicherungsnummer` werden ohne jeden Leerraum gespeichert (inkl. `\x{00A0}`, `\x{202F}`) | `RecEmployee.php:346-354`; `src/Support/TaxAndSvNumber.php:23-35` | Clara 28.08.2026 — „sonst landet später nicht die vollständige Nummer in Agenda". Bewusst kein Ziffernfilter. |

**31 Regeln.**

### 2.3 Regeln, die es NICHT gibt (Lücken, die beim Umbau nicht nachgebaut werden müssen)

- Keine Datei-Validierung im alten Portal: kein MIME-Check, keine Größengrenze.
  `accept="image/*,.pdf"` (Blade `362`) ist reines Browser-Attribut. Das neue
  Portal validiert (`PortalShell.php:262-280`) — das ist ein **Zugewinn**, keine
  Regression.
- `saveAll()` prüft **nicht** erneut auf `is_active` oder `portal_v2_since`
  (`EmployeePortal.php:256-270`); nur `portal_locked_at`. Im neuen Portal tut das
  `berechtigterMitarbeiter()` (`PortalShell.php:425-448`).
- Keine Längenprüfung außer für `other_employer`.

---

## 3. Nebenwirkungen des Speicherns

Alles hier läuft über **Eloquent** (`$employee->update(...)`,
`EmployeePortal.php:373` und `465`) — deshalb feuern die Observer. Das neue
Portal schreibt bewusst über den Query Builder
(`PortalShell.php:326-338, 362-365`) und löst sie damit **nicht** aus.

| # | Nebenwirkung | Ausgelöst wodurch | Fundstelle |
|---|---|---|---|
| N1 | **ZAS-Export-Marker** `zas_changed_at = now()` (direkter `DB::update`, um Rekursion zu vermeiden) | `RecEmployee::updated` **und** mindestens ein geändertes Feld aus `RELEVANT_EMPLOYEE_FIELDS` | `src/Observers/RecEmployeeExportObserver.php:121-129, 200-205` |
| N2 | **Lohn-Trigger** `payroll_data_changed_at = now()` + Anhang an `payroll_data_changed_fields` (JSON-Liste mit `field`/`old`/`new`/`at`) | geändertes Feld aus dem Team-Setting `employee_payroll_tracked_fields` **und** es ist keine Erstbefüllung (`old === null` zählt nicht) **und** der Wert hat sich wirklich geändert (leer/whitespace ≙ null) | `RecEmployeeExportObserver.php:131-134, 216-291`; Default-Liste `src/Models/RecApplicantSettings.php:133-138` |
| N3 | **ContextFile-Zeile** in `core_context_files`, Kontext `rec_employee` / `employee->id`, `team_id` gesetzt, `user_id = null` | jeder Datei-Upload | `EmployeePortal.php:455-465` |
| N4 | **`portal_verified_at = now()`** — über Eloquent, feuert also alle `updated`-Observer (setzt aber keinen ZAS-Marker: das Feld steht nicht in der Relevanz-Liste) | erfolgreiche Anmeldung | `EmployeePortal.php:211` |
| N5 | **CRM-Kontakt bekommt die neue Telefonnummer** (`ContactPhoneSync::syncEmployee`) | `phone` geändert | `src/Observers/RecEmployeePhoneSyncObserver.php:21-33`, registriert `src/RecruitingServiceProvider.php:211`. Vorfall RG19734, 04.09.: ohne das ordnet der WhatsApp-Eingang Antworten von der neuen Nummer keinem Kontakt zu. Fehler werden geloggt (`contact_phone_sync_failed`), kippen den Save nicht. |
| N6 | **MA-Kontaktbuch-Sync** (`EmployeeContactListSyncService::syncEmployee`) | nur bei `is_active` / `employment_ended_at` — **aus dem Portal nicht erreichbar**, beide Felder sind nicht editierbar | `src/Observers/RecEmployeeContactListObserver.php:28-35`. Hier nur aufgeführt, damit niemand es beim Umbau für eine Portal-Wirkung hält. |
| N7 | **Normalisierung beim Schreiben**: `steuer_id`/`sozialversicherungsnummer` verlieren jeden Leerraum | jedes Speichern dieser Felder | `RecEmployee.php:346-354` |
| N8 | **`CorePublicFormLink`-Zeilen werden angelegt** — je eine für den Bewerber und für jeden nicht stornierten Vertrag | **bloßes Anzeigen** der Vertragsliste (Computed `contracts()`), nicht erst das Speichern | `EmployeePortal.php:669, 674`; `platforms-core/src/Traits/HasPublicFormLink.php:15-21` |
| N9 | **Session-Flag** `employee_portal_verified:{id}` — wird **von beiden Portalen geteilt** | Anmeldung bzw. Abmeldung | `EmployeePortal.php:210, 220, 746-749`; `src/Services/PortalAuth.php:119-122` |
| N10 | **Cache-Einträge** `employee_portal_attempts:{token}` und `employee_portal_locked:{token}` — ebenfalls von beiden Portalen geteilt | Fehlversuch / erfolgreiche Anmeldung | `EmployeePortal.php:751-784`; `PortalAuth.php:104-117` („Ein eigener Schlüssel hieße: im alten gesperrt, im neuen offen.") |
| N11 | **Computed-Cache wird verworfen** (`unset($this->employee, $this->missingFields, $this->editableGroups)`) | nach `saveAll()` und nach jedem Upload | `EmployeePortal.php:379, 473` |

**11 Nebenwirkungen.**

### 3.1 Was das alte Portal NICHT auslöst — und warum das wichtig ist

- **Keine Nachweis-Zeile** (`rec_employee_proofs` / `ProofWriter`). Das alte
  Portal kennt die Tabelle nicht; es schreibt nur die Altspalten. Genau deshalb
  gibt es die Weiche R2 (`EmployeePortal.php:118-131`).
- **Kein Protokolleintrag, keine Benachrichtigung.** Im gesamten
  `EmployeePortal.php` gibt es weder `Log::`- noch Mail-/WhatsApp-Aufrufe.
  `RecEmployee::sendPortalNotification()` (`RecEmployee.php:598 ff.`) wird vom
  Portal **nicht** gerufen, sondern von
  `CreateEmployeeFromApplicantService::createOrUpdate()`.
- **Kein `portal_last_seen_at`.** Diesen Stempel setzt nur
  `EmployeeAssignments` (`src/Livewire/Public/EmployeeAssignments.php:87-90`).

### 3.2 Welche Portal-Felder den ZAS-Marker auslösen — und welche nicht

Schnittmenge der 47 editierbaren Felder mit
`RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS` (`:37-102`).

**Lösen den Marker aus (42):** `email`, `street`, `house_number`, `zip`, `city`,
`country_code`, `birth_country`, `nationality`, `birth_name`, `birth_place`,
`gender`, `marital_status`, `employment_type`, `religion`, `number_of_children`,
`iban`, `bic`, `bank_institute`, `account_holder`, `tax_class`, `steuer_id`,
`sozialversicherungsnummer`, `health_insurance`,
`health_insurance_card_file_id`, `identity_card_valid_until`,
`identity_card_front_file_id`, `identity_card_back_file_id`, `selfie_file_id`,
`immatrikulation_file_id`, `schulbescheinigung_file_id`,
`school_certificate_valid_until`, `has_infection_protection_certificate`,
`infection_protection_first_issued_at`, `is_first_aider`,
`first_aider_valid_until`, `shirt_size`, `pants_size`, `shoe_size`,
`drivers_license_class`, `has_car`, `residence_permit_valid_until`,
`work_permit_valid_until`. (Das sind 42 Schlüssel — gezählt über die Liste, nicht
geschätzt.)

**Lösen ihn ausdrücklich NICHT aus (5) — das ist jeweils eine Entscheidung:**

| Spalte | Begründung im Code |
|---|---|
| `phone` | „fehlt hier BEWUSST (03.09.): führend ist ZAS — ein Rück-Export würde per PNr-Match dortige Akten überschreiben (Vorfall Katona RG999999)" (`RecEmployeeExportObserver.php:47-51`) |
| `erstbescheinigung_file_id` | „Bewusst NICHT im ZAS-Export (RelevantFields unverändert)" (Commit `8095dad`) |
| `first_aider_certificate_file_id` | steht nicht in der Liste; exportiert werden nur `is_first_aider` und `first_aider_valid_until` |
| `is_main_employer` | ZAS soll die Angabe (noch) nicht sehen; die Aktualisierungsdatei liefert VOLLE ZEILEN, ein Marker würde die in ZAS gepflegte Akte überschreiben (Vorfall 02.09.2026) — `PortalShell.php:326-338`, Test `tests/Integration/EmployerFieldsNoExportMarkerTest.php` |
| `other_employer` | wie oben |

### 3.3 Welche Portal-Felder den Lohn-Trigger auslösen

Aus `RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields']`
(`:133-138`), Schnittmenge mit den editierbaren Feldern: `iban`, `bic`,
`bank_institute`, `account_holder`, `tax_class`, `steuer_id`,
`sozialversicherungsnummer`, `health_insurance`, `is_main_employer`, `street`,
`house_number`, `zip`, `city` — **13 Felder**.

Zwei Fallen: **(a)** Erstbefüllungen zählen nicht (`Observer:237-240`), **(b)**
Bestandsteams haben eine eigene gespeicherte Feldauswahl — dort muss
`is_main_employer` zusätzlich im Einstellungs-Modal angehakt werden (Commit
`4f232f0`, letzter Absatz). Siehe auch die bekannte Falle, dass Selects im
Settings-Modal nicht speichern.

---

## 4. Eigenheiten, die aus einem behobenen Fehler stammen

Durchgegangen wurde die vollständige Historie beider Dateien
(`git log -- src/Livewire/Public/EmployeePortal.php resources/views/livewire/public/employee-portal.blade.php`,
23 Commits). **Alle drei vom Auftraggeber genannten Beispiele sind bestätigt**
(E3, E4, E11) — mit einer Präzisierung bei E11.

| # | Commit / Datum | Was kaputt war | Wie behoben | Woran man den Fix heute erkennt |
|---|---|---|---|---|
| **E1** | `65c9d81`, 21.05. | `hover:bg-[var(--ui-primary-dark)]` — die Variable existiert nirgends, Tailwind fiel auf Weiß zurück, Buttons wurden beim Hovern unsichtbar | 3 Vorkommen auf `hover:bg-[var(--ui-primary)]/90` | Kein `--ui-primary-dark` mehr im Blade; stattdessen `/90` in `…blade.php:94, 168, 403` |
| **E2** | `01379ee`, 21.05. | Text „noch nicht eingetragen" an jedem leeren Feld war Lärm; Label „Land (z.B. DE)" war irreführend, weil das Land ausgeschrieben wird | roter Rand statt Text; Label auf „Land" verkürzt | `$inputBorder = $isMissing ? 'border-red-300' : …` (Blade `286-288`, genutzt in `297, 316, 327, 334, 344, 381`); `'label' => 'Land'` (`RecEmployee.php:370`) |
| **E3** | `9baafe9`, 06.08. | **`inputmode="numeric"` am Ausweisfeld** — Ausweisnummern enthalten Buchstaben, auf der iOS-Zahlentastatur waren die nicht eingebbar. **Login-Blocker.** | Attribut ersatzlos entfernt; dazu `autocapitalize="characters"`, `autocomplete="off"`, `spellcheck="false"`, `maxlength="4"` | **`inputmode` kommt im ganzen Blade nicht mehr vor** (geprüft: 0 Treffer). Das Feld ist `type="text"` (Blade `71-82`), Platzhalter `z.B. 0T47` |
| **E4** | `9baafe9`, 06.08. | **Dunkler nativer Kalender** auf hellem Formular; zusätzlich: das Guest-Layout setzt `dark:text-white` am Body, Preflight vererbte das an Inputs → weiße Schrift auf weißer Karte, Eingaben unsichtbar (media-based Dark-Mode) | `style="color-scheme: light"` an jedem Datumsfeld; explizites `bg-white text-gray-900` an jedem Input/Select | `style="color-scheme: light"` an Blade `64` (Login-Geburtsdatum) und `328` (jedes Datumsfeld der Stammdaten); `bg-white text-gray-900` an Blade `63, 79, 297, 316, 327, 334, 381` |
| **E5** | `9baafe9`, 06.08. | Wording „letzte 4 Ziffern" suggerierte Ziffern | „letzte 4 **Stellen**", Platzhalter `0T47`; Unit-Tests für `verifyPortalAccess` (Buchstaben, Groß-/Kleinschreibung, Leerraum) | Blade `53, 70, 80`; `strcasecmp` in `RecEmployee.php:316`; `tests/Unit/EmployeePortalVerifyAccessTest.php` |
| **E6** | `4656d13`, 19.08. | **Auth-Bypass**: `$wire.set('state','verified')` umging Geburtsdatum und Ausweis vollständig | `#[Locked]` auf fünf Eigenschaften | `#[Locked]` über `state`, `employeeId`, `token`, `displayName`, `duzen` (`EmployeePortal.php:38-47`); dazu `portalLocked` (`57-58`) |
| **E7** | `8095dad`, 06.08. | **Nur 4 von 6 File-Feldern hatten eine Upload-Property.** Immatrikulation und Schulbescheinigung wurden als „fehlt noch" markiert, hatten aber keinen Knopf — der Mitarbeiter konnte die Forderung nicht erfüllen | beide Properties + Hooks ergänzt | `uploadImmatrikulation` / `uploadSchulbescheinigung` in `FILE_FIELDS` (`98-99`), Properties (`87-88`), Hooks (`407-415`) **und** im Blade-Duplikat `$fileUploadProps` (`245-246`). Heute sind es **8** File-Felder in beiden Listen |
| **E8** | `c3e5b1b`, 01.09. | `saveAll()` schrieb `file`-Typen aus `$fieldValues` — ein manipulierter POST konnte damit den Guard passieren und den gerade geprüften Nachweis im selben Request wieder leeren | `type === 'file'` wird beim Speichern immer übersprungen | `continue;` mit vierzeiliger Begründung in `EmployeePortal.php:334-341` |
| **E9** | `c3e5b1b`, 01.09. | Ohne `required_if` hätte **jeder Nicht-Ersthelfer dauerhaft zwei rote Felder** im Portal — „bei nein passiert nichts" war nicht abgebildet | neues Meta `required_if`, strikter Vergleich | `RecEmployee.php:456-465` und `fieldIsRelevant()` `544-552`; Nutzung in `EmployeePortal.php:519` |
| **E10** | `dd5ceb5`, 23.09. | Clara/RHEINGEDECK 28.08.: „Verträge bei Darkmodus nicht lesbar da weiß auf weißem Hintergrund." Das Guest-Layout aus platforms-core setzt am Body eine helle Schrift; unsere Seiten sind durchgehend hell, `--ui-surface` hat keine dunkle Variante | Textfarbe an der Wurzel festnageln | `class="… text-gray-900 …"` am Wurzel-`div` samt 6-zeiligem Kommentar (`…blade.php:1-7`). **Kein Dark-Mode-Design** — das Layout gehört platforms-core und bleibt unangetastet |
| **E11** | `4f232f0`, 25.09. | **Der `(string)`-Fallback beim dreiwertigen `is_main_employer`.** *Präzisierung:* Der Fix besteht darin, `(string)` **nicht** zu verwenden. `(string) false` ergibt `''` — genau die Form, die der Guard als „unbeantwortet" liest. Wer ordentlich „nein" geantwortet hat, hätte **nie wieder speichern** können | ausdrückliche Abbildung auf `'1'`/`'0'` | `$recordFlag = $employee->is_main_employer === null ? null : ($employee->is_main_employer ? '1' : '0');` — `EmployeePortal.php:312-314`, mit 8-zeiligem Warnkommentar `306-311` |
| **E12** | `5afaddb`, 25.09., Befund 1 | **Das Label unter der Auswahl war fest** („Arbeitest du noch woanders?"). Bei „Nebenarbeitgeber" ist genau dieses Feld Pflicht und wird als **Hauptarbeitgeber** gespeichert: Wer Hauptjob Müller + Minijob Schmitz hatte, trug Schmitz ein. „HR meldet dem Lohnbüro den Falschen, und kein Validator kann das fangen, weil das Feld gefüllt ist." | Beschriftung trägt die Bedingung selbst | `'label' => 'Wer ist es dann?'` (`RecEmployee.php:421`) mit Begründung `411-415` |
| **E13** | `5afaddb`, 25.09., Befund 2 | **Guard und Schreibpfad lasen verschiedene Werte.** `'nein'` galt dem Guard als „beantwortet ja", verlangte den Namen nicht und wurde als `false` gespeichert. Umgekehrt passierte `'Ja'` den Guard und landete als **NULL** — eine gültige Antwort still gelöscht, mit der Meldung „Änderungen gespeichert." | eine einzige Quelle: `PortalBoolValue` | `src/Support/PortalBoolValue.php` (TRUTHY `1/true/ja`, FALSY `0/false/nein`, sonst `null`); gelesen vom Schreibpfad (`EmployeePortal.php:346`), vom Guard (`MainEmployerRequiredGuard.php:41`) und von der Sichtbarkeit (`EmployeePortal.php:638`). `tests/Unit/PortalBoolValueTest.php` |
| **E14** | `5afaddb`, 25.09., Befund 3 | **Keine Längengrenze im Portal.** `other_employer` ist `string(128)`; ein längerer Wert wäre als SQLSTATE 22001 durchgeschlagen und hätte den ganzen Speichervorgang mitgerissen | Guard-Prüfung **und** `maxlength` im Formular | `MainEmployerRequiredGuard::MAX_OTHER_EMPLOYER = 128` (`:32`), Prüfung `:55-58`; Meta `maxlength` (`RecEmployee.php:431`); Render `@if(!empty($entry['maxlength'])) maxlength=…` (Blade `380`) |
| **E15** | `0f9cffa`, 25.09. | **Der Erklärtext war sachlich falsch.** „Der Job, bei dem du am meisten verdienst" ist eine Faustregel, nicht die Regel — und ein **Minijob zählt nicht mit**. „Wer woanders einen 556-Euro-Minijob hat und bei uns 400 Euro verdient, hätte nach dem alten Text ‚Nein' gewählt — und damit die falsche Steuerklasse ausgelöst. Bei Schülern und Studenten ist genau das der Normalfall." | Text beschreibt die Entscheidung statt des Geldes, nennt den Minijob und lädt zum Nachfragen ein | `$sectionHint` für `Arbeitgeber` im Blade `260-270`, mit Kommentar „BEWUSST NICHT ‚wo du am meisten verdienst'". **Offen laut Commit: die Formulierung sollte noch jemand freigeben, der die Lohnabrechnung verantwortet** |
| **E16** | `f999bb0`, 25.09. | Im Portal blieb das Namensfeld bei „ja" sichtbar und wurde beim Speichern nur geleert — „wer bei ‚ja' etwas eintippte, sah seine Eingabe verschwinden" | `visible_if` (dreiwertig, gegen den Formularwert) + `live` an genau diesem einen Ja/Nein-Feld | `'visible_if' => ['is_main_employer' => false]` und `'live' => true` (`RecEmployee.php:406-427`); `fieldIsVisible()` (`631-646`); Blade rendert `wire:model.live` nur bei `$entry['live']` (`311-315`) |
| **E17** | `14ff85a`, 13.08. | **Die Zertifikat-Zeile behauptete „Unterschrieben am …"** über ein Dokument, das niemand unterschrieben hat: sie trägt das Ausstellungsdatum in `signed_at` und gewann damit die Bedingung `status === 'completed' \|\| signed_at` | `issued`-Zweig steht **vor** der Unterschrieben-Bedingung | Blade `131-138` vor `139-146`, mit 5-zeiligem Pflicht-Kommentar; gemessen an der **gerenderten** Blade in `tests/Integration/PortalCertificateBadgeTest.php` |
| **E18** | `9fe431c`, 24.09. | **Umgestellte Mitarbeiter landeten weiter im alten Portal.** `portal_v2_since` entschied nur, WER das neue sehen darf; die alte Adresse blieb offen — und dorthin zeigen alle bereits verschickten WhatsApp-Links. Weil beide Portale den Sitzungsschlüssel teilen, war ein umgestellter Mensch dort sogar schon angemeldet. Er konnte etwas eintragen, das im neuen nie ankommt | Weiche in `mount()` | `if ($employee->portal_v2_since !== null) { $this->redirect(route('recruiting.public.portal-shell', …)); return; }` — `EmployeePortal.php:132-136`, mit 18-zeiliger Begründung `118-131`. `tests/Integration/EmployeePortalV2RedirectTest.php` |

**18 aus Fehlern entstandene Eigenheiten.**

Zwei davon (E10, E17) hängen an **gerenderter** Blade und sind per Quelltext-Diff
leicht zu verlieren. E15 ist reiner Text und hat gar keinen Test.

---

## 5. Was das alte Portal NICHT kann

Damit beim Umbau niemand Umfang erfindet.

1. **Keine Nicht-EU-Dokumente hochladen.** Die Non-EU-Gruppe enthält
   ausschließlich zwei **Datumsfelder** (`RecEmployee.php:492-495`).
   Nationalpass, Aufenthaltstitel (V/R), Visumsblatt, Zusatzblatt
   Arbeitsgenehmigung (V/R) und Fiktionsbescheinigung (V/R) sind im alten Portal
   **nicht** hochladbar — obwohl die Spalten existieren
   (`RecEmployee.php:105-113`) und im ZAS-Export stehen
   (`RecEmployeeExportObserver.php:82-85`). Das neue Portal kann das
   (`ProofTypes.php:54-77`). **Zugewinn, keine Abnahmeanforderung.**
2. **Keine Nachweis-Zeilen, keine Ablauf-Logik.** Kein „Läuft ab am", keine
   Vorlaufzeiten, keine Erinnerung, keine Checkliste, kein Fortschritt. Nur der
   Banner „Diese Daten fehlen noch" als Komma-Liste (Blade `190-198`).
3. **Keine hochgeladene Datei ansehen, herunterladen oder ersetzen-und-löschen.**
   Gezeigt wird nur der Dateiname (`fileNameForId()`, `573-584`); „Ersetzen"
   überschreibt die File-Id, die alte Datei bleibt verwaist.
4. **Keine Einsätze/Anstellungen.** Die liegen in der separaten Komponente
   `EmployeeAssignments` unter einer eigenen Route.
5. **Kein Personen-Bezug (RG + MA).** Ein Token = eine Anstellung. Das neue
   Portal löst über `PersonScopeResolver` auf (`PortalShell.php:455-465`).
6. **Keine Personalnummer, keine Firma** werden angezeigt.
7. **Kein Konto, kein Passwort, keine Handynummer** (Canvas 68 ist offen).
8. **Kein `portal_last_seen_at`**, also keine Nutzungsmessung über dieses Portal.
9. **Keine Navigation** — eine einzige lange Seite, kein Bereichswechsel.
10. **Kein Vertrag wird hier unterschrieben**; die Liste verlinkt nur nach
    `ContractSigning` (`EmployeePortal.php:688`) und bietet ein PDF, sobald der
    Status `completed` ist (`689-691`).
11. **Keine Anzeige stornierter Verträge** (`status !== 'cancelled'`, `672`).
12. **Kein Abmelden vom Sperr- oder Ratenbegrenzungs-Bildschirm** — der
    Abmelden-Knopf existiert nur im `verified`-Zweig (Blade `393-398`).
13. **Keine Feldvalidierung** für E-Mail, IBAN, Steuer-ID, Kinderzahl,
    Kleidergrößen.
14. **Keine Mehrsprachigkeit.** Nur Deutsch, Sie/du über
    `use_informal_address` (`EmployeePortal.php:140`).

---

## 6. Anmelde- und Sperrlogik: alt gegen neu

Gemeinsam: Token in der URL, Geburtsdatum + letzte 4 Stellen der Ausweisnummer,
5 Versuche, 15 Minuten Sperre, **dieselben Cache-Schlüssel und derselbe
Sitzungsschlüssel** (`PortalAuth.php:104-122` gegen `EmployeePortal.php:746-759`)
— eine Sperre gilt bewusst in beiden.

| Punkt | Alt (`EmployeePortal`) | Neu (`PortalShell`) | Bewertung |
|---|---|---|---|
| Unbekannter/inaktiver Token | Zustand `tokenInvalid` mit Erklärtext (`111-116`, Blade `33-38`) | `abort(404)` — „das wäre eine Auskunft darüber, dass es den Token gibt" (`PortalShell.php:96-99`) | neu ist strenger |
| Nicht umgestellt | irrelevant | `abort(404)` (ebd.) | — |
| Umgestellt | Redirect ins neue Portal (`132-136`) | — | **muss bleiben, bis das alte abgeschaltet ist** |
| Name/Anrede im Snapshot | `displayName` und `duzen` werden **in `mount()`** gesetzt, also **vor** der Anmeldung — sie fahren im `wire:snapshot` der Anmeldeseite mit (`138-140`) | Identität erst **nach** `verify()`, und nur der **Vorname** (`PortalShell.php:160-163, 468-478`) | **Datenschutz-Verbesserung im neuen Portal. Nicht zurückbauen.** |
| Feldwerte laden | `loadFieldValues()` läuft **in `mount()`**, vor der Session-Prüfung (`152`) — nur die Sperre R3 kommt davor | keine Stammdaten in der Hülle | s.o. |
| Anmelde-Zustand | `verify()` prüft den eigenen Zustand **nicht** | `if ($this->state !== 'unverified') return;` (`PortalShell.php:124-127`) | neu ist strenger |
| Leere Eingabe | kostet keinen Versuch (`191-196`) | kostet keinen Versuch, mit ausdrücklicher Begründung gegen `$wire.call` (`PortalShell.php:145-150`) | gleich |
| Sperre erneut prüfen | in `verify()` (`184-189`), `saveAll()` (`267-270`), `handleFileUpload()` (`441-444`) | zentral in `berechtigterMitarbeiter()` bei **jedem** Durchgang, zusätzlich `is_active` und `portal_v2_since` (`PortalShell.php:425-448`) | neu deckt mehr ab |
| `portal_verified_at` | Eloquent-`update` → alle `updated`-Observer feuern (`211`) | `DB::table(...)->update(...)` → keine Modell-Ereignisse, kein Export-Marker (`PortalShell.php:163-167`) | bewusster Unterschied |
| Sperr-Bildschirm | eigener Zweig `$portalLocked`, hat **Vorrang vor jedem State** (Blade `19-24`) | Zustand `gesperrt` (`PortalShell.php:106-110`) | gleichwertig |
| Abmelden | vergisst das Session-Flag, setzt Zustand zurück; **`displayName` bleibt stehen** (`218-225`) | löscht zusätzlich Name und Initialen (`PortalShell.php:198-208`) | neu ist sauberer |
| Fehlversuch-Rückmeldung | „Daten passen nicht. Noch N Versuch(e) bis zur Sperre." (`205`) | „Das passt noch nicht zusammen. Du hast noch N Versuche." + `idLast4` wird geleert (`PortalShell.php:181-185`) | Wortlaut weicht ab |
| Nach Abmelden im geteilten Gerät | — | ausdrücklich bedacht: „weil beide Portale sich den Sitzungsschlüssel teilen, auch gleich im alten" (`PortalShell.php:193-196`) | — |

**Was das neue Portal aus der Anmeldeschicht schon einmal verloren hatte** und
laut Kommentar zurückgeholt wurde: die zweite Sperr-Prüfung zwischen `mount()`
und `verify()` (`PortalShell.php:134-138`: „Dieser Wächter stand schon im alten
Portal; beim Herauslösen der Anmeldeschicht ist er zunächst verlorengegangen.").
Das ist der vierte dokumentierte Fall von verlorener Historie — er gehört auf
die Abnahmeliste.

---

## 7. Zusammenfassung für die Abnahme

| Kategorie | Anzahl |
|---|---|
| Editierbare Felder | **47** (+ 2 nur lesbar) |
| Regeln beim Betreten und Speichern | **31** |
| Nebenwirkungen | **11** (davon 5 Spalten mit ausdrücklichem Marker-Verbot) |
| Aus Fehlern entstandene Eigenheiten | **18** |

### Die drei Dinge, die beim Umbau am ehesten vergessen werden

1. **Die zwei fest im Blade verdrahteten Erklärtexte** (`Arbeitsschutz` und
   `Arbeitgeber`, Blade `255-270`). Sie stehen nicht in der Feld-Definition,
   werden also von keiner generischen Feldübernahme mitgenommen, und der
   Arbeitgeber-Text ist der einzige Ort im Produkt, der dem Mitarbeiter den
   Minijob-Sonderfall erklärt (E15). Es gibt **keinen Test**, der ihn festhält —
   er würde lautlos verschwinden, und die Folge wäre eine falsche Steuerklasse
   genau bei Schülern und Studenten, also bei der Mehrheit der Zielgruppe.
2. **Die drei Guards mit ihrer Reihenfolge und dem `(string)`-Verbot** (R15-R18).
   Das neue Portal ruft heute nur `MainEmployerRequiredGuard` auf
   (`PortalShell.php:349`). `FirstAiderDateGuard` **mit** Dokumentpflicht und
   `NationalityRequiredGuard` fehlen dort bisher ganz. Beim Nachziehen ist die
   Falle nicht der Guard selbst, sondern der Rückfall auf den Datensatz: `(string)
   false === ''` sperrt jeden aus, der korrekt „nein" geantwortet hat (E11).
   Zusätzlich ist die Kaskade selbst Teil des Verhaltens — eine parallele
   Sammelvalidierung würde andere Fehlertexte zeigen als heute.
3. **Welche Felder den ZAS-Marker NICHT setzen dürfen** (§3.2). Fünf Spalten
   haben ein ausdrückliches Verbot mit je einem Vorfall dahinter: `phone`
   (Katona RG999999), `is_main_employer` und `other_employer` (Vorfall
   02.09.2026, volle Zeilen in der Aktualisierungsdatei),
   `erstbescheinigung_file_id` und `first_aider_certificate_file_id`. Weil das
   neue Portal über den Query Builder schreibt, fällt das heute nicht auf —
   sobald aber jemand für die Profilfelder auf Eloquent umstellt, weil es
   bequemer ist, setzt der Observer den Marker, und die nächste Aktualisierung
   überschreibt vollständige, in ZAS gepflegte Akten. Umgekehrt gilt genauso:
   wer alles über den Query Builder schreibt, verliert für die **42**
   Spalten aus §3.2 den Marker, den Lohn-Trigger für 13 Felder, den
   CRM-Telefon-Sync und die beiden Leerraum-Mutatoren.

### Offene Punkte aus dieser Aufnahme

- Die Formulierung des Arbeitgeber-Erklärtexts wartet laut Commit `0f9cffa` noch
  auf eine Freigabe durch jemanden, der die Lohnabrechnung verantwortet.
- **Unklar**, ob eine leere Feldgruppe im Blade je als leere Karte gerendert wird
  (§1.4, Punkt 4) — nicht nachgestellt.
- **Unklar**, ob die Roh-Ausnahmemeldung beim Upload-Fehler (R26) je
  personenbezogene oder interne Angaben enthält; das neue Portal zeigt bewusst
  einen festen Satz.
