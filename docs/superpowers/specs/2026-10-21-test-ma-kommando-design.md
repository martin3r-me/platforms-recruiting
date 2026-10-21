# Test-MA per Kommando (`recruiting:test-ma`) — Design

Stand: 2026-10-21

## Warum

Zum Testen auf Prod (mitarbeiter.rheingedeck.de) braucht der Entwickler
regelmäßig einen echten Mitarbeiter: MA-Portal, Dispo-Test-VA,
WhatsApp-Vorlagen, Bescheinigungen. Bisher heißt das, alle Phasen per
WhatsApp durchzuspielen. Ein MA, der nur per MCP zusammengesetzt wird,
ist nicht „digital getreu“: Er entsteht nicht über denselben Code wie ein
echter MA.

Ziel: Ein Befehl erzeugt in Sekunden einen Test-MA, der **über denselben
Pfad** entsteht wie ein echter (Bewerber mit Feldwerten →
`CreateEmployeeFromApplicantService::createOrUpdate()`), und der
**niemals an ZAS geht** und **nicht in die Diensthandy-Telefonbücher**
gelangt.

## Ziele

1. Kommando `recruiting:test-ma` legt Bewerber (`is_test = true`) +
   CRM-Kontakt + MA an; `--remove` räumt alles restlos weg.
2. `rec_employees.is_test` (neu) schützt vor jeder ZAS-Lieferung.
3. Test-MA landet nicht in der Kontaktliste „Aktive Mitarbeiter“
   (CardDAV auf Diensthandys).

## Nicht-Ziele (vom User entschieden)

- **Keine Ausblendung im UI.** Test-MA und Test-Bewerber bleiben in
  Listen, Suche, Kennzahlen sichtbar, solange sie existieren (Punkt 3
  der Vorbesprechung bewusst gestrichen).
- **CRM nicht absichern.** Der CRM-Kontakt ist im CRM sichtbar; keine
  Änderung am CRM-Modul. Er wird mit `--remove` gelöscht.

## Anti-Ziele (wäre ein Fehlschlag, auch wenn es „läuft“)

- Ein Test-MA erscheint in `initial.csv` / `updates.csv` oder setzt
  einen ZAS-Export-Marker.
- Ein Test-MA steht im Telefonbuch der Teamleiter.
- Das Kommando schreibt MA-Spalten direkt statt über Feldwerte + Service
  (dann testet man nicht das, was echte MA durchlaufen).
- Das Kommando löst Nachrichten an Dritte aus (WhatsApp, Mail, HR-Fälle).

## Kommando

```
php artisan recruiting:test-ma
    --stelle=<rec_position_id>      Pflicht beim Anlegen
    --profil=eu|nicht-eu|student|schueler   Default eu
    --name="Vorname Nachname"       Default "Test Mitarbeiter"
    --phone=<Nummer>                Default leer (dann kein Telefon)
    --set feld=wert                 mehrfach; überschreibt Profilwerte
    --datei feld=/pfad/bild.jpg     mehrfach; echte Datei statt Platzhalter
    --leer=feld1,feld2              Felder bewusst nicht befüllen
    --owner=<user_id>               Default: OwnerResolver wie beim Eingang
php artisan recruiting:test-ma --remove=<employee_id>
php artisan recruiting:test-ma --list    alle MA mit is_test=true
```

Ausgabe nach dem Anlegen: Bewerber-ID, MA-ID, CRM-Kontakt-ID, Portal-Link
(`recruiting.public.employee-portal` mit `portal_token`), Liste befüllter
und leer gebliebener Felder.

### Ablauf Anlegen (alles in einer Transaktion, außer Datei-Upload s. u.)

1. CRM-Kontakt anlegen: Name aus `--name`, E-Mail
   `test-ma-<zufall>@invalid.local`, Telefon aus `--phone` (falls gesetzt).
   Muster: `src/Tools/CreateApplicantTool.php:113-176`.
2. `RecApplicant::create([... 'is_test' => true, 'auto_pilot' => false])`,
   `linkContact()`, an eine veröffentlichte Ausschreibung der Stelle
   hängen (`anzeigeVerknuepfen`, RecApplicant.php:2469). Gibt es keine
   Ausschreibung → Abbruch mit klarer Meldung.
3. `rec_phase_id` auf die Phase mit `creates_employee_on_completion`
   setzen (das ist der Punkt, an dem ein echter MA entsteht); hat die
   Stelle keine solche Phase → letzte aktive Phase.
   `clearExtraFieldDefinitionsCache()` (Muster DirectHire/Index.php:223-231).
   Owner: `OwnerResolver` wie beim echten Eingang; findet er niemanden →
   Abbruch mit Hinweis auf `--owner=<user_id>` (leerer Owner macht
   Bewerber unsichtbar). Ersteller = derselbe User.
4. Feldwerte setzen über `setExtraField(name, wert)` für die
   Feld-Definitionen der Phasen bis einschließlich dieser Phase:
   - Wert aus Profil (bzw. `--set`/`--datei`).
   - **Datei-Felder nur, wenn das Profil sie nennt** — ein eu-Test-MA hat
     keinen Aufenthaltstitel, kein Visum, keine Bescheinigung. Sonst
     testen Portal-Bescheinigungen und Nicht-EU-Logik nichts.
   - Übrige Nicht-Datei-Felder ohne Profilwert: nur wenn Pflichtfeld,
     dann Platzhalter je Typ (Text „Test“, Datum heute+2 Jahre, Bool nein,
     Select erster Wert); sonst leer.
   - Sichtbarkeitsregeln des Public-Forms werden **nicht** ausgewertet;
     die Profile sind so gebaut, dass sie zueinander passen.
   - `--leer` gewinnt über alles.
   - Unbekannte Feldnamen in `--set`/`--datei`/`--leer` → Abbruch vor
     jeder Änderung (Tippfehler sollen nicht still verpuffen;
     `setExtraField` tut bei unbekanntem Namen sonst nichts).
5. Rechtsstatus (`RecApplicantLegalStatus`) direkt schreiben
   (`is_eu_citizen` + Datei-IDs je Profil), **ohne** `setEuCitizen()`,
   weil das `HrDeskRoutingService::evaluateAndRoute` auslöst und beim
   Profil nicht-eu HR-Fälle öffnen würde (Anti-Ziel).
6. `CreateEmployeeFromApplicantService::createOrUpdate($applicant)`.
   `is_test` muss **im `RecEmployee::create`-Array** gesetzt werden
   (`'is_test' => (bool) $applicant->is_test`), damit es vor dem
   Kontaktlisten-Sync und vor jedem Observer steht.

Kein Vertrag wird angelegt (siehe „Entscheidungen“). AutoPilot bleibt
aus, keine Nachricht geht raus.

### Profile

Gemeinsam: Geburtsdatum 01.01.1995, Adresse Teststraße 1, 40210
Düsseldorf, IBAN `DE89370400440532013000` (offizielle Test-IBAN), BIC
`COBADEFFXXX`, Steuer-ID / SV-Nummer mit plausiblem Format,
Geschlecht/Familienstand/Krankenkasse mit gültigen Auswahlwerten.

| Profil | nationalitaet | is_eu_citizen | ich_bin | Dateien |
|---|---|---|---|---|
| eu | DE | ja | (Standardwert der Stelle) | Ausweis vorne/hinten, Selfie, Versichertenkarte |
| nicht-eu | z. B. TR | nein | wie eu | + Nationalpass, Aufenthaltstitel vorne/hinten, Visum, Zusatzblatt; Aufenthaltserlaubnis/Arbeitsgenehmigung bis heute+1 Jahr |
| student | DE | ja | Student-Wert | + Immatrikulationsbescheinigung |
| schueler | DE | ja | Schüler-Wert | + Schulbescheinigung; Geburtsdatum volljährig lassen |

Die genauen Auswahlwerte (`ich_bin`, `geschlecht` …) liest der Builder
aus den Feld-Definitionen bzw. bestehenden Mappings.

### Dateien

Platzhalterbilder werden im Kommando erzeugt (z. B. GD: weißes Bild mit
Text „TEST – Selfie“), über `ContextFileService::uploadForContext()`
abgelegt (Muster `src/Services/Zas/ZasEmployeeFileStore.php:36-60`),
Kontext = Bewerber (wie das Public-Form). `--datei` nimmt eine echte
Datei. Die Datei-ID geht als Feldwert ein.

### `--remove=<employee_id>`

- Verweigert, wenn der MA nicht `is_test = true` ist (Schutz vor
  Verwechslung mit echtem MA).
- Löscht wie `recruiting:delete-employee` (Logik übernehmen/extrahieren,
  ohne interaktive Rückfrage) **plus** was jenes nicht löscht:
  RecApplicantLegalStatus, ContextFiles (Datensatz + Speicher) von
  Bewerber und MA, RecAutoPilotLog-Zeilen, Kontaktlisten-Mitgliedschaft
  (sollte leer sein), Schulungszertifikat falls vorhanden, CRM-Kontakt.
- Idempotent: zweiter Aufruf meldet „nichts zu tun“.

## ZAS-Schutz

Migration `2026_10_21_000001_add_is_test_to_rec_employees.php`: boolean
default false, `after('is_active')`, Index (Vorlage
`2026_05_08_000001_add_is_test_to_rec_applicants.php`). `RecEmployee`
fillable + cast.

`where('is_test', false)` an:
- `ZasEmployeeInitialExportController::fetchInitialEmployees` (:96-99)
- `ZasEmployeeUpdateExportController::fetchChangedEmployees` (:96-99)
- `RecEmployeeExportObserver::markEmployeeId` (:212-217) und Bulk-Update
  cost_center (:187-191) — Test-MA bekommt nie `zas_changed_at`
- `ZasEmployeeFileController` (:54) → 404 für Test-MA
- `ZasEmployeeExportBackfill` (:48-50), `ZasReExportByBookingDate`
  (:80-81, 140-141)
- Dispo-Abgleich: `DispoIdentityResolver` (:60-63, :122-125),
  `DispoEmployeeDirectory` (:18-23, :34-39) — damit eine ZAS-Lieferung
  nie auf einen Test-MA matcht.

- Dispo-Bestätigungen an ZAS: `ZasDispoConfirmationExport::rows()`
  (:26-35) schließt Buchungen aus, deren `rec_employee_id` auf einen
  Test-MA zeigt (Buchungen ohne `rec_employee_id` bleiben drin).
  Hauptanwendungsfall ist `recruiting:dispo-test-va --employee=<Test-MA>`;
  ohne diesen Filter ginge die Bestätigung an ZAS. `dispo-test-va`
  funktioniert ohne Personalnummer (setzt `pnr_raw = 'TEST'`).

Bewerber-Export filtert `is_test` schon (ZasExportController.php:127).

Die übrigen ZAS-Inbound-Matcher (`ZasEmployeeMatcher`,
`ZasInboundDuplicateFinder`, `ZasEmployeeContactLinker`,
`ZasDispoTaetigkeitSync`, `PersonPairLinker`) lässt der Builder nach
eigenem Urteil mitfiltern: Ein Test-MA hat keine Personalnummer, ein
Match ist nur über Name/Telefon denkbar.

## Kontaktliste „Aktive Mitarbeiter“

`EmployeeContactListSyncService::resolveDesired()` (:291-293) bekommt
`->where('is_test', false)`. Damit greifen `syncAll()` und
`syncEmployee()` automatisch.

## Entscheidungen (meine, nicht vom User bestätigt)

- **Kein Vertrag** am Test-MA. Grund: Vertrags-PDF + Signatur sind ein
  eigener Pfad; Arbeitgeber-Erklärung fehlt dann, und das Portal fragt sie
  nach — das ist ein realistischer Testfall. Wird später ein Vertrag
  gebraucht, ist das eine Erweiterung (`--mit-vertrag`).
- **Rechtsstatus ohne HR-Routing** (s. Ablauf 5).
- **Keine Personalnummer.** ZAS vergibt sie; der Test-MA bekommt keine.
  Für die Dispo-Test-VA nimmt `recruiting:dispo-test-va --employee` die
  MA-ID.
- **Datei-Felder nur nach Profil, Sichtbarkeitsregeln nicht ausgewertet**
  (s. Ablauf 4).
- **Phase mit MA-Anlage statt letzter Phase**, Owner über OwnerResolver.
- **Dispo-Bestätigungs-Export filtert Test-MA** — fällt unter „nie an ZAS“.
- **Stelle ist Pflicht**, kein Default: Felder hängen an den Phasen der
  Stelle, und welche Stelle getestet wird, soll bewusst gewählt sein.
- Nur Prod-Code auf `main`; das neue Portal (feat/ma-konto) ist nicht
  Teil davon. Nach dessen Merge braucht ein Test-MA ggf. eine Person
  (`recruiting:personen-anlegen`) — dann nachziehen.

## Tests

Integrations-Tests im Capsule/SQLite-Muster
(`tests/Integration/DispoTestVaCommandTest.php`): Logik in öffentlichen
Methoden (`createTestEmployee(...)`, `removeTestEmployee(...)`),
`handle()` nur Optionen + Ausgabe. Mindestens:

- MA entsteht mit `is_test = true`, Felder aus Profil gemappt (Name,
  IBAN, Nationalität, Selfie-Datei-ID), is_eu_citizen je Profil.
- Initial- und Update-Export liefern den Test-MA nicht; Observer setzt
  keinen Marker bei Änderung; echter MA daneben wird weiter geliefert
  (Gegenprobe, sonst prüft der Filter nichts).
- `resolveDesired()` enthält Test-MA nicht, echten MA schon.
- `--remove` verweigert echten MA; löscht Test-MA restlos; zweiter
  Aufruf idempotent.
- Unbekannter Feldname in `--set` → Abbruch ohne Datensatz.
- Profil nicht-eu öffnet keinen HR-Fall.
- Profil eu hat keine Nicht-EU-Dateifelder befüllt.
- Bestätigungs-Export lässt bestätigte Buchung des Test-MA weg, die eines
  echten MA nicht.

## Deploy

Merge → Bump meingedeck → Forge-Deploy **mit `migrate`**. Kein
`queue:restart` nötig (der Observer läuft synchron); im Zweifel trotzdem.
