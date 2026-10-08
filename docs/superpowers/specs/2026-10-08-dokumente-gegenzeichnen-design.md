# Design: Dokumente bereitstellen, zur Kenntnis nehmen, gegenzeichnen

**Datum:** 2026-10-08
**Modul:** platforms-recruiting (kein Edit an Core/CRM/HCM)
**Branch:** feat/ma-konto (setzt das neue Portal `PortalShell` und das Konto voraus)
**Ersetzt:** `2026-07-10-mitarbeiterportal-dokumente-design.md` (nie gebaut; Grundlage hat sich seitdem geändert)
**Status:** Design zur Freigabe

---

## 1. Ziel

HR legt eine PDF ab (Verschwiegenheitserklärung, Belehrung, Hausordnung, Lohnabrechnung),
wählt, was der Mitarbeiter damit tun soll, und wen es betrifft: eine Person, alle
Eingebuchten einer Veranstaltung oder eine Gruppe nach Tätigkeit/Firma/Suche. Der
Mitarbeiter bekommt eine WhatsApp, sieht das Dokument im Portal als offenen Punkt,
liest es und bestätigt oder unterschreibt. Zeitpunkte und Unterschrift liegen als
Nachweis in der Akte. HR sieht je Dokument, wer noch nicht reagiert hat.

Markus' Canvas 1 nennt das „Dokumentversand + Status" und „Öffnen vor Bestätigen;
Zeitstempel + Nachweisblatt". Dieses Paket erfüllt genau das.

### Was bewusst NICHT drin ist

- Keine eigene Aufgaben-Tabelle (Juli-Spec §3.2). Das Portal hat offene Punkte
  (`OffenePunkte`); ein Dokument mit Handlungsbedarf ist ein weiterer Punkt.
- Keine Formular-/Freitext-Aufgaben, keine Lohnrelevanz, keine Barzahlung, keine
  IP-Adresse beim Unterschreiben. Nie geklärt, kein Bedarf.
- Keine automatische Zustellung an Nachrücker einer Veranstaltung. Das Dokument
  merkt sich die Veranstaltung, HR legt Nachrücker von Hand nach. Stufe 2.
- Keine Erinnerung von selbst. Der zweite Fang ist die Einsatz-Prüfung (§6).
- Kein E-Mail-Versand (eigenes Paket „E-Mail als Ersatzweg").
- Keine ZAS-Rückmeldung, keine Änderung am Export (§9).
- Keine Datei-Ersetzung. Korrektur = zurückziehen + neu bereitstellen.

---

## 2. Datenmodell

Ein Dokument, viele Zustellungen. Beide Tabellen hängen am Mitarbeiter, schreiben
aber nie auf `rec_employees` (kein ZAS-Marker, Vorfall 02.09.2026).

### 2.1 `rec_documents` (Model `RecDocument`)

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `team_id` | | mandantengebunden |
| `uuid` | uuid unique | öffentliche Kennung für Download-Routen |
| `title` | string(255) | vorbelegt aus Dateiname, editierbar |
| `category` | string(30) | `contract`, `instruction`, `form`, `payslip`, `certificate`, `other` |
| `action` | string(20) | `none` (nur ablegen), `acknowledge` (Kenntnisnahme), `sign` (Unterschrift) |
| `disk`, `stored_path`, `original_filename` | string | Ablage wie `rec_dispo_attachments` (eigener Speicher, kein ContextFile — Auslieferung an den Mitarbeiter braucht eine eigene Zugriffsprüfung, §5.3) |
| `file_sha256` | char(64) | Prüfsumme beim Bereitstellen, eingefroren |
| `file_size` | unsigned int | Bytes |
| `rec_dispo_event_id` | FK nullable | gesetzt beim Einstieg von der Veranstaltungsseite |
| `created_by_user_id` | FK nullable | wer bereitgestellt hat |
| Timestamps + SoftDeletes | | Zurückziehen des ganzen Dokuments (§7) |

Kategorie belegt `action` vor (Kategorie `contract`/`instruction` → `sign` bzw.
`acknowledge`, Rest → `none`), HR kann es je Dokument übersteuern. Die Vorbelegung
lebt in EINER reinen Klasse `DokumentKategorie` (Codes, Labels, Default-Aktion).

### 2.2 `rec_document_recipients` (Model `RecDocumentRecipient`)

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `team_id` | | |
| `rec_document_id` | FK | |
| `rec_employee_id` | FK (ohne DB-FK, Muster `rec_contracts.rec_employee_id`) | die Anstellung, an der HR es abgelegt hat |
| `uuid` | uuid unique | öffentliche Kennung des Portal-Downloads (eine Zustellung, ein Mensch) |
| `person_key` | string nullable, index | Kopie von `rec_employees.person_key` zum Entdoppeln und für die Portal-Sicht |
| `notified_at` | datetime nullable | nur bei Erfolg (`STATUS_SENT` nach Statusprüfung, Muster `AufgabenSender`) |
| `notify_error` | string(120) nullable | letzter Fehlerstatus (`no_phone`, `failed`, `nicht_konfiguriert`, …); bei Erfolg NULL |
| `first_viewed_at` | datetime nullable | erstes Öffnen im Portal |
| `acknowledged_at` | datetime nullable | „gelesen und verstanden" bestätigt |
| `signed_at` | datetime nullable | Unterschrift |
| `signature_data` | text nullable | Base64-PNG wie `rec_contracts.signature_data` |
| `withdrawn_at` | datetime nullable | zurückgezogen (statt SoftDelete, damit die Zeile im Überblick als „zurückgezogen" bleibt) |
| Timestamps | | |

Unique: (`rec_document_id`, `rec_employee_id`).

**Status wird abgeleitet, keine Status-Spalte** (`DokumentStatus::fuer($recipient, $action)`):
`zurueckgezogen` → `unterschrieben` → `bestaetigt` → `gesehen` → `offen`; bei `action = none`
gibt es nur `abgelegt`/`gesehen`. Benachrichtigungsstand ist eine zweite Achse
(`notified_at`/`notify_error`), nie mit dem Fortschritt verschmolzen.

**Invarianten**

- Datei unveränderlich nach Bereitstellen (kein Ersetzen, kein Service dafür).
- `withdrawn_at` nur solange `signed_at IS NULL`. Eine Unterschrift ist ein Nachweis.
- `first_viewed_at`, `acknowledged_at`, `signed_at`: erster Zeitpunkt gewinnt (idempotent).

---

## 3. Bereitstellen: ein Service, drei Einstiege

### 3.1 `DokumentService::bereitstellen(array $daten, UploadedFile $datei, list<int> $employeeIds, ?int $eventId, ?int $userId): RecDocument`

1. Validierung: PDF (MIME `application/pdf` UND Endung), ≤ 20 MB, Titel nicht leer,
   `category`/`action` aus der Liste, mindestens ein Empfänger.
2. Datei speichern (Disk wie Dispo-Anhänge, Pfad `recruiting/dokumente/{team}/{uuid}.pdf`),
   `file_sha256` aus den gespeicherten Bytes berechnen.
3. Empfänger entdoppeln auf Personen: `EmpfaengerEntdoppler::aufPersonen(list<RecEmployee>)`
   (rein, getestet). Zwei Anstellungen mit gleichem `person_key` → EIN Empfänger, die
   Anstellung mit der kleineren id gewinnt. Ohne `person_key` bleibt jede Anstellung
   ein Empfänger (Muster `SendProofReminders`: lieber eine Nachricht zu viel).
4. In einer Transaktion: Dokument + Empfängerzeilen.
5. NACH der Transaktion stellt die Oberfläche den Job `DokumentHinweiseVersenden` ein
   (nur bei `action ≠ none`). Der Job ruft `DokumentService::hinweiseVersenden()`: je
   Zustellung ohne Versuch die WhatsApp, Ergebnis in `notified_at`/`notify_error`.
   Idempotent, ein Fehlschlag wird nicht von selbst wiederholt. HR sieht den Stand
   („37 von 200 benachrichtigt, läuft …") auf der Seite Dokumente und in der Akte.

Rückgabe das Dokument; der Aufrufer zeigt „14 bereitgestellt, 13 benachrichtigt, 1 ohne Nummer".

### 3.2 Einstieg Akte (`Livewire/Employees/Show`)

Abschnitt „Dokumente" unterhalb der Verträge. Kurzform: Drop-Zone (PDF), Titel,
Kategorie, Aktion (vorbelegt), Knopf „Bereitstellen". Empfänger = diese Anstellung.
Darunter die Liste der Dokumente dieser Person (über `person_key`, also auch die der
anderen Anstellung) mit Status, Datum, Knöpfen „Öffnen", „Erneut senden",
„Zurückziehen", „Nachweis" (§5.4).

### 3.3 Einstieg Veranstaltung (`Livewire/Dispo/Events/Show`)

Knopf „Dokument an alle Eingebuchten". Gleiches Kurzformular, Hinweis „an N Personen"
(Eingebuchte = Assignments mit `STATUS_AUFTRAG`, `missing_since IS NULL`, mit
`rec_employee_id`; entdoppelt über §3.1 Schritt 3). `rec_dispo_event_id` wird gesetzt.
Auf der Seite erscheint danach eine Zeile „Dokumente dieser Veranstaltung" mit
„12 von 14" und Link auf die Dokumente-Seite.

### 3.4 Einstieg Seite „Dokumente" (`Livewire/Employees/Documents`, Route `/employees/documents`)

Die lange Form und der Überblick (§8). Formular oben: Datei, Titel, Kategorie, Aktion.
Empfängerwähler darunter: Suchfeld (Name/Personalnummer), Filter Firma, aktiv/inaktiv,
Tätigkeit, Veranstaltung (Dropdown kommender Veranstaltungen).

Der Tätigkeit-Filter liest `rec_employee_hr_data.dispo_taetigkeiten` (JSON-Liste von
Katalognamen, ZAS führend, Quelle `{Dispo5}` des Webexports seit main 08.10.2026). Die
Auswahlwerte kommen aus der Lookup-Liste `ZasDispoTaetigkeitSync::LOOKUP`, nicht aus
den Mitarbeiterzeilen, damit auch Tätigkeiten ohne aktuellen Träger wählbar sind. Der
Vergleich läuft über den Namen in Kleinschreibung (so entdoppelt ZAS seine Doppel-IDs).
Grenze: der MA-Mandant liefert keine `{Dispo5}`-Zuordnung, der Filter findet also nur
RG-Anstellungen; das steht als Hinweis neben dem Filter. Treffer als Liste mit Haken, alle vorgehakt,
einzelne abwählbar. Zähler „N ausgewählt". Dann Bereitstellen. Bei mehr als `DokumentEmpfaengerSuche::LIMIT`
(500) Treffern verweigert der Wähler das Bereitstellen („Mehr als 500 Treffer — bitte
Filter eingrenzen").

Der Wähler existiert nur hier. Die Mitarbeiterliste bekommt keinen Haken-Modus und
keinen Knopf.

Kein Haken, kein Empfänger → kein Bereitstellen (Fehlertext).

---

## 4. Nachricht an den Mitarbeiter

`DokumentHinweisSender` nach dem Muster `AufgabenSender`:

- Meta-Vorlage aus der Team-Einstellung `document_wa_template_id` (Einstellungs-Fenster →
  Kommunikation, Liste der genehmigten Vorlagen), aufgelöst über dieselbe Kette wie
  Zertifikat und Fristenlauf (`HoldingTemplateSender::resolveTarget`). Platzhalter nur
  `vorname` plus URL-Knopf mit dem Portal-Token. Kein Dokumenttitel in der Nachricht:
  universeller Text „im Portal liegt etwas für dich“.
- Unbekannter Platzhalter in der Vorlage → kein Versand, Status `vorlage_untauglich`
  (nie stiller Vorname wie in `HoldingTemplateComponents`).
- Keine lesbare Nummer (`PhoneE164::normalize() === null`) → `no_phone`, kein Versand.
- Erfolg nur, wenn `$message->status` nicht `failed` ist (Altfehler
  `sendPortalNotification`). `notified_at` nur dann; sonst `notify_error`.
- `action = none` → kein Versand, `notified_at` bleibt leer, Status zeigt „abgelegt".
- Kein zweiter Versuch von selbst. „Erneut senden" ist ein Knopf (Akte, Dokumente-Seite),
  der denselben Sender ruft. Wer kein neues Portal hat (`portal_v2_since IS NULL`):
  kein Versand, Status `altes_portal` — der Knopf im Alt-Portal führte ins Leere
  (Ruling C2 des Fristenlaufs). Das Dokument liegt trotzdem bereit, sichtbar nach
  der Umstellung.

---

## 5. Portal (`PortalShell`)

### 5.1 Sichtbarkeit

`DokumentLeser::fuer(RecEmployee $employee)`: alle Empfängerzeilen, deren
`rec_employee_id` in `PersonScopeResolver::forEmployee($employee)['ids']` liegt, ohne
`withdrawn_at`, Dokument nicht gelöscht. Dokumente aller Anstellungen der Person, einmal.
Fremde IDs aus der Anfrage werden nie verwendet; Aktionen nehmen die Empfänger-ID und
prüfen sie gegen diese Menge (Muster `berechtigterMitarbeiter()` bei jedem Aufruf).

### 5.2 Start und offene Punkte

`OffenePunkte::fuer()` bekommt eine zweite Quelle: je Empfängerzeile mit
`action ∈ {acknowledge, sign}` und ohne `acknowledged_at`/`signed_at` ein Punkt
`['code' => 'dokument:' . $recipientId, 'label' => $title, 'status' => 'offen', 'ko' => false]`.
Damit zählt der Startbildschirm mit, die Einsatz-Prüfung nimmt den Punkt in die
Signatur auf und meldet ihn beim nächsten gebuchten Einsatz mit. `gesperrt` bleibt
allein Sache der Arbeitserlaubnis.

Der Code `dokument:<id>` ist kein Nachweis-Code: `ProofTypes::istKo()`/`label()` werden
für ihn NICHT gefragt, `OffenePunkte` trägt Label und `ko = false` selbst ein. Alle
Verbraucher der Punkteliste (`PortalShell::dekoriert()`, `AufgabenSender`,
`EinsatzPruefung`) müssen mit einem Code ohne Katalogeintrag auskommen; das ist ein
eigener Test (§11).

Die Dekoration im Start-Kasten zeigt je Punkt „Lesen und bestätigen" bzw.
„Lesen und unterschreiben"; Klick springt in den Reiter Dokumente zur Zeile.

### 5.3 Reiter Dokumente

Neuer Block über den Verträgen: Titel, Kategorie-Label, Status, Datum. Aktionen:

- **Öffnen** (`oeffneDokument(int $recipientId)`): prüft Berechtigung, setzt
  `first_viewed_at` falls leer (Query Builder, idempotent), liefert die Download-URL
  `route('recruiting.public.dokument', ['uuid' => …])`. Kein nackter Link im Blade,
  sonst ist „gesehen" nichts wert.
- **Download-Route** `GET /mitarbeiter/dokument/{uuid}` → `DokumentDownloadController`:
  Sitzung muss für GENAU die Anstellung des Empfängers oder eine ihrer
  Schwester-Anstellungen stehen (`session()->has(PortalAuth::sessionKey($id))` für
  eine id aus dem Personen-Scope), Portalsperre → 403, zurückgezogen → 404.
  Entscheidung in reiner Klasse `DokumentZugriff::entscheide()` (Muster
  `DispoAttachmentAccess`). Antwort `Storage::disk()->response()` mit
  `Cache-Control: private, no-store`.
- **Bestätigen** (`bestaetigeDokument`): nur bei Aktion `acknowledge` (`unterschreiben` nur
  bei Aktion `sign`; `DokumentUnterschrift` verweigert sonst und schreibt nichts). Haken „gelesen und verstanden" Pflicht,
  setzt `acknowledged_at` falls leer. Vorher muss `first_viewed_at` gesetzt sein,
  sonst Fehlertext „Bitte zuerst öffnen".
- **Unterschreiben** (`unterschreibeDokument`): Haken + `x-ui-input-signature`
  (wie `ContractSigning`). Beim Speichern: Prüfsumme der gespeicherten Datei neu
  berechnen und gegen `file_sha256` prüfen; Abweichung → Abbruch, `Log::error`,
  kein `signed_at`. Sonst `signature_data`, `signed_at`, `acknowledged_at`
  (falls leer) in einer Transaktion, erster Zeitpunkt gewinnt.
- Zurückgezogen während der Ansicht: Aktion lädt frisch, findet `withdrawn_at`,
  Meldung „Dieses Dokument wurde zurückgezogen", keine Änderung.

Eingaben (Haken, Unterschrift) sind NICHT `#[Locked]`; die Empfänger-ID wird bei
jedem Aufruf gegen den Scope geprüft, nicht als Zustand getragen.

### 5.4 Nachweisblatt

Nach `signed_at`: `GET /employees/{employee}/dokument/{uuid}/nachweis` (HR, Modul-Guard)
rendert mit DomPDF über `RendersContractPdf`-Muster ein neues Template
`pdf/dokument-nachweis.blade.php`: Name, Personalnummer, Firma, Dokumenttitel,
Dateiname, Prüfsumme, bereitgestellt am, erstes Öffnen, bestätigt am, unterschrieben am,
Unterschriftsbild, Firmenstempel. Kein eigener Renderer. Bei Kenntnisnahme kein Blatt.

---

## 6. Zweiter Fang: Einsatz-Prüfung

Nichts Neues zu bauen. Weil offene Dokumente Punkte in `OffenePunkte` sind, ändert
sich die Aufgaben-Signatur an der Person, und `recruiting:einsatz-pruefung` schickt
beim nächsten gebuchten Einsatz die Sammelnachricht (mit Pause-Regeln ET-15/ET-23).
Test: ein offenes Dokument ohne Nachweislücke erzeugt genau einen Punkt, mit
`ko = false`, `gesperrt = false`.

Damit niemand zweimal zum selben Dokument angeschrieben wird, lässt die Einsatz-Prüfung
Dokumente aus, die in den letzten `EinsatzBezug::PAUSE_TAGE` (7) Tagen ihre eigene WhatsApp
bekommen haben (`OffenePunkte::fuerTrigger()`); Fehlversuche ohne `notified_at` bleiben drin,
dort ist die Einsatz-Prüfung der zweite Fang. Das Portal zeigt alle offenen Dokumente.

---

## 7. Zurückziehen

- **Je Empfänger** (Akte, Dokumente-Seite): `withdrawn_at = now()` nur wenn
  `signed_at IS NULL`; sonst Fehlertext „Unterschrieben, kann nicht zurückgezogen werden".
  Verschwindet aus Portal und offenen Punkten.
- **Ganzes Dokument** (Dokumente-Seite): alle Empfänger ohne `signed_at` zurückziehen,
  dann SoftDelete am Dokument nur, wenn KEIN Empfänger unterschrieben hat. Mit
  Unterschriften bleibt das Dokument (Nachweis), Status „teilweise zurückgezogen".
- Kein Hard-Delete, keine Dateilöschung.

---

## 8. Überblick für HR (Seite „Dokumente")

Tabelle je Dokument: Titel, Kategorie, Aktion, Veranstaltung, bereitgestellt von/am,
Fortschritt „12 von 14" (unterschrieben bzw. bestätigt je `action`; bei `none`
„gesehen x von y"). Aufklappen: Personen mit Status (`DokumentStatus`), Benachrichtigung
(gesendet am / Fehler), Knöpfe Erneut-senden, Zurückziehen, Nachweis. Filter:
offen/erledigt, Veranstaltung. Sortierung neueste zuerst. Kein Eintrag auf dem
HR-Schreibtisch (bleibt Bewerber-Thema bis zum Paket „HR-Bereich Mitarbeiter").

Die Akte zeigt dieselben Zeilen gefiltert auf die Person.

---

## 9. Was sich NICHT ändert

- Kein Schreiben an `rec_employees`: keine Observer, kein `zas_changed_at`, kein Export.
- `rec_contracts`, Zertifikate, Nachweise unangetastet. `x-ui-input-signature` wird
  wiederverwendet, `ContractSigning` nicht angefasst.
- ZAS bekommt nichts Neues (Ankündigungsregel nicht berührt).
- Altes Portal (`EmployeePortal`) zeigt keine Dokumente. Wer dort ist, wird nicht
  benachrichtigt (§4).

---

## 10. Fehler und Randfälle

| Fall | Verhalten |
|---|---|
| Nicht-PDF, zu groß, leer | Serverseitiger Fehler, nichts gespeichert |
| Empfänger ohne Nummer | Zeile mit `notify_error = no_phone`, Erneut-senden möglich |
| Meta-Vorlage fehlt | alle Empfänger `nicht_konfiguriert`, Dokument liegt bereit |
| Empfänger im alten Portal | `altes_portal`, kein Versand, sichtbar nach Umstellung |
| Person zweimal in der Auswahl (RG+MA) | ein Empfänger (Entdoppler) |
| Mitarbeiter deaktiviert | Portal-Login scheitert ohnehin; Zeile bleibt, kein Versand |
| Datei auf dem Speicher weg | Öffnen 404, Unterschreiben bricht ab (Prüfsumme nicht berechenbar), `Log::error` |
| Doppelklick Bestätigen/Unterschreiben | erster Zeitpunkt gilt |
| Zurückgezogen während Ansicht | freundliche Meldung, keine Änderung |
| Veranstaltung ohne Eingebuchte | Knopf inaktiv mit Hinweis |

---

## 11. Tests

Konvention: Unit pur + Integration mit Capsule/SQLite (`tests/Integration`), Log-Attrappe
in `setUp()`, `config`-Binding wo Modelle es lesen.

**Unit (rein):**
- `DokumentKategorie`: Codes, Labels, Default-Aktion je Kategorie; unbekannter Code → Ausnahme.
- `DokumentStatus`: Ableitung aus Zeitstempeln für alle drei Aktionen, Reihenfolge
  zurückgezogen > unterschrieben > bestätigt > gesehen > offen.
- `EmpfaengerEntdoppler`: zwei Anstellungen gleicher `person_key` → eine; ohne Key bleiben zwei;
  Reihenfolge stabil (kleinere id).
- `DokumentZugriff`: Matrix aus (Sitzung ja/nein, gesperrt, Empfänger gefunden, zurückgezogen) → 200/403/404.

**Integration:**
- Bereitstellen legt Dokument + N Empfänger an, Prüfsumme stimmt mit der Datei überein.
- Bereitstellen aus der Veranstaltung nimmt nur `STATUS_AUFTRAG` ohne `missing_since`.
- Sender: Erfolg setzt `notified_at` und löscht `notify_error`; `failed` setzt nur `notify_error`;
  `action = none` sendet nicht; altes Portal sendet nicht.
- Portal: `DokumentLeser` liefert Dokumente beider Anstellungen einmal, fremde nie.
- Öffnen setzt `first_viewed_at` genau einmal; zweites Öffnen ändert es nicht.
- Bestätigen ohne vorheriges Öffnen → Fehler, kein Zeitpunkt.
- Unterschreiben mit manipulierter Datei (Bytes nach Bereitstellen geändert) → kein `signed_at`.
- Zweites Unterschreiben überschreibt `signed_at`/`signature_data` nicht.
- Zurückziehen nach Unterschrift → Fehler; vorher → `withdrawn_at`, Punkt verschwindet aus `OffenePunkte`.
- `OffenePunkte::fuer()` liefert für ein offenes Dokument genau einen Punkt, `ko=false`, `gesperrt=false`;
  für `action = none` keinen.
- Download-Controller: ohne Sitzung 403, mit Sitzung der Schwester-Anstellung 200, zurückgezogen 404.
- Schreibungen auf `rec_employees`: null (Query-Zähler, Muster `EmployeeCreationCertificateTest`).

**Mutationsprobe:** je Test eine benannte Mutation, die ihn rot macht
(Checkliste `reference_pruefmuster_gruenes_nichts`).

---

## 12. Deploy

- Zwei Migrationen (`rec_documents`, `rec_document_recipients`), kein Backfill.
- Einstellungs-Fenster → Kommunikation: „Dokumente — WhatsApp-Template mit Portal-Link“
  auf die genehmigte Meta-Vorlage setzen. Ohne Wert liegt jedes Dokument bereit, aber
  niemand bekommt eine WhatsApp (Status `nicht_konfiguriert`, Erneut-senden holt es nach).
- Speicherpfad auf dem Disk der Dispo-Anhänge; nichts in `.env`.
- `view:clear`.
- `queue:restart` nach dem Deploy: die Hinweise verschickt der Job `DokumentHinweiseVersenden`
  auf dem Worker, nie der Klick.
- Sichttest: Akte → PDF ablegen → WhatsApp kommt → Portal zeigt Punkt → unterschreiben →
  Nachweisblatt öffnet.
