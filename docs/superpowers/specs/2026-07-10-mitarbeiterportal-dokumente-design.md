# Design: Dokumentenbereitstellung & Login-Aufgaben im Mitarbeiterportal

**Datum:** 2026-07-10
**Modul:** platforms-recruiting (alles bleibt im Recruiting-Modul; kein Edit an Core/CRM/HCM)
**Status:** Design zur Freigabe

---

## 1. Ziel & Kontext

Der Kunde fordert zwei Blöcke im bestehenden Mitarbeiterportal (`/mitarbeiter/{token}`):

**5.1 Bereitstellung**
- Dokumente per Drag & Drop dem Mitarbeiter (MA) zur Verfügung stellen
- Dokumente müssen kategorisiert werden
- eSign wird gesetzt und dokumentiert
- Status Barzahlung wird gesetzt wenn zutreffend
- Lohnrelevanz wird berücksichtigt
- (Rückmeldung "unterzeichnet" an ZAS: **bewusst aus V1 ausgeklammert**, siehe §9)

**5.2 Benachrichtigungen nach dem Login**
- Rechtlich kritische Dokumente: MA erhält WhatsApp-Nachricht; nach Login Hinweis, dass ein Dokument gegenzuzeichnen ist
- Sonstige Hinweise/Belehrungen: nach Login Hinweis auf zu erledigende Aufgabe (Belehrung ansehen, Dokument lesen, Formular ausfüllen, Folgebelehrung bestätigen)
- Hinweis bleibt sichtbar, bis die Aktion abgeschlossen ist

### Bestand (wiederverwendbar, verifiziert)

| Baustein | Fundort | Nutzung |
|---|---|---|
| Mitarbeiterportal | `src/Livewire/Public/EmployeePortal.php`, Route `routes/public.php:39-42` | verifizierte Session (`employee_portal_verified:{id}`), Geburtsdatum + Ausweis-Endziffern |
| Signatur-Pad (UI) | `styles/platforms-ui-tailwind/.../form/input-signature.blade.php` | Canvas → Base64-PNG; reine Eingabe-UI, wird **nicht** verändert |
| Vertrags-Signierlogik | `src/Livewire/Public/ContractSigning.php:114-156` | Muster: `signature_data` + `signed_at` in DB, kein Hash |
| Dateien | Core `ContextFile` (`platforms-core`) + Drag&Drop-Komponente `InlineFileUpload` | polymorpher Kontext, signierte Auslieferungs-URLs |
| WhatsApp | `RecEmployee::sendPortalNotification()`, `RecApplicant::sendContractPortalNotification()`, `WhatsAppMetaService` (CRM) | Template-Versand über Meta Cloud API |
| PDF-Renderer | `src/Http/Controllers/Concerns/RendersContractPdf.php` + `resources/views/pdf/contract.blade.php` | DomPDF aus Template + Variablen |
| Payroll-Marker | `src/Observers/RecEmployeeExportObserver.php`, `PayrollChangesExportController.php` | `payroll_data_changed_at` + `_fields`, **reiner interner Marker** |
| HR-Schreibtisch | `src/Livewire/HrDesk/Index.php` | abgeleiteter Arbeitsvorrat (Query, kein materialisierter Datensatz) |

### Verifizierte Negativbefunde (bestimmen das Design)

- **Kein Hash/Checksum im Core.** Grep über `ContextFileService.php`, `ContextFile.php`, Migration nach `sha|hash|md5|checksum`: null Treffer. HMAC existiert nur für Auslieferungs-**URLs** (Zugriffsschutz, kein Datei-Nachweis). → `file_sha256` ist neu und liegt **vollständig in unserem Spielraum**.
- **Kein Payroll-Sync.** `payroll_data_changed_at` hat keine Jobs/Listeners; alle Konsumenten sind lesend (Sidebar-Badge, Lohnänderungs-Ansicht, CSV-Export mit manueller Quittierung). → lohnrelevantes Dokument setzt nur den internen Marker, kein Sync-Nebeneffekt.
- **Kein "Barzahlung"-Feld** existiert irgendwo im Modul. Semantik beim Kunden noch offen → als Merkmal ohne Logik führen, **kein sichtbares Badge** bis geklärt.
- **Kein wiederverwendbarer WhatsApp→E-Mail-Fallback.** `resolveChannelWithOverrides` ist privat & bewerber-gebunden in `ProcessAutoPilotApplicants`; `sendPortalNotification`/`sendContractPortalNotification` sind WhatsApp-only und enthalten den Template-Mapping-Code bereits **zweifach** (Klon-Kommentar `RecEmployee.php:481`).

---

## 2. Architekturüberblick

Zwei neue schlanke Modelle plus ein extrahierter geteilter Sender:

- **`RecEmployeeDocument`** — ein bereitgestelltes Dokument (Datei via `ContextFile`, Kategorie, Flags, Status-Zeitstempel, Signatur-Nachweis).
- **`RecEmployeeTask`** — eine Aufgabe für den MA (Typ, Status, optional an ein Dokument gekoppelt). Trägt auch aufgaben­lose bzw. dokumentlose Fälle (Formular, Freitext) und die V2-Folgebelehrungs-Historie.
- **`WhatsAppTemplateSendService`** — empfänger-agnostischer Sender, aus `sendPortalNotification`/`sendContractPortalNotification` extrahiert; **gegen beide Alt-Aufrufstellen validiert**, damit kein dritter Klon entsteht.

Orchestriert durch **`EmployeeDocumentService::provide()`** (ein Aufruf = Dokument + ggf. Aufgabe + ggf. Benachrichtigung, atomar mit Benachrichtigung außerhalb der DB-Transaktion).

Verträge (`RecContract`) bleiben **unangetastet** — kein Risiko am produktiven AV/IfSG-/AutoPilot-Flow.

---

## 3. Datenmodell

### 3.1 `rec_employee_documents` (Model `RecEmployeeDocument`)

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `team_id`, `rec_employee_id` | | FK, mandantengebunden |
| `context_file_id` | FK → `context_files.id` | das bereitgestellte PDF |
| `title` | string | vorbelegt aus Dateiname, editierbar |
| `category` | string (Enum, feste Liste) | siehe §4 |
| `requires_signature` | bool | eSign nötig |
| `creates_task` | bool | erzeugt Aufgabe/Hinweis (von Kategorie vorbelegt) |
| `is_legally_critical` | bool | **fachliche** Einordnung (Badge/Filter) |
| `notify_employee` | bool | **Verhalten**: Benachrichtigung beim Bereitstellen |
| `is_payroll_relevant` | bool | hängt beim Abschluss ins Payroll-Tracking ein |
| `is_cash_payment` | bool | Merkmal ohne Logik; **kein Badge** bis Semantik geklärt |
| `provided_at` | datetime | Bereitstellung |
| `first_viewed_at` | datetime nullable | erstes Öffnen im Portal |
| `acknowledged_at` | datetime nullable | "gelesen/bestätigt" (wenn keine Signatur) |
| `signed_at` | datetime nullable | Unterschrift |
| `signature_data` | text nullable | Base64-PNG (wie Verträge) |
| `file_sha256` | string(64) nullable | Hash der Datei, **bei `provide()` berechnet** (§7) |
| `signed_ip` | string nullable | kleiner Audit-Bonus |
| `notified_at` | datetime nullable | nur bei **Erfolg** gesetzt |
| `notified_channel` | string nullable | z. B. `whatsapp` (E-Mail = V2) |
| `notify_error` | text nullable | Fehlermeldung; **bei Erfolg auf NULL zurückgesetzt** |
| `created_by_user_id` | FK nullable | wer bereitgestellt hat |
| Timestamps + SoftDeletes | | Zurückziehen = Soft-Delete |

**Status** wird aus Zeitstempeln abgeleitet (keine Status-Spalte): `provided → viewed → acknowledged|signed`.

**Immutability (harte Invariante):** Nach `provide()` ist die Datei unveränderlich. Es gibt **keine "Datei ersetzen"-Aktion** (weder UI noch Service). Korrektur = Dokument zurückziehen (Soft-Delete, nur solange unsigniert) + neues bereitstellen.

**Soft-Delete gesperrt**, sobald `signed_at` gesetzt ist (signierte Dokumente sind Nachweise).

### 3.2 `rec_employee_tasks` (Model `RecEmployeeTask`)

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `team_id`, `rec_employee_id` | | |
| `type` | string (Enum) | `sign_document`, `read_document`, `fill_form`, `custom` |
| `title`, `description` | string / text nullable | |
| `link_url` | string nullable | nur `fill_form` (externes Formular) |
| `rec_employee_document_id` | FK nullable | gesetzt bei Dokument-Aufgaben |
| `status` | string | `open` / `done` |
| `completed_at` | datetime nullable | |
| `completed_by_user_id` | FK nullable | **null = vom MA im Portal erledigt**; User-ID = von HR abgehakt |
| `due_date` | date nullable | nur informativ in V1 |
| `created_by_user_id` | FK nullable | null = systemgeneriert |
| Timestamps + SoftDeletes | | |

**`fill_form` ist ausdrücklich NICHT PDF-Form-Filling.** Kein Feld-Mapping ins PDF (bewusst raus, zu aufwändig für den Use-Case). `fill_form` = manuell angelegte Aufgabe mit `link_url` auf ein externes Formular. Bereitgestellte Formular-**PDFs** erzeugen dagegen `read_document` (ansehen/bestätigen).

---

## 4. Kategorien & Flag-Defaults

Feste Liste im Code (Konstante am Model, z. B. `DocumentCategoryDefaults`). Jede Kategorie liefert Default-Flags; **jedes Flag pro Dokument frei übersteuerbar**.

| Kategorie | `requires_signature` | `creates_task` | `is_legally_critical` | `notify_employee` | `is_payroll_relevant` |
|---|---|---|---|---|---|
| `vertrag_zusatz` (Vertrag/Zusatzvereinbarung) | ✅ | ✅ | ✅ | ✅ | ⬜ |
| `belehrung` (Belehrung/Unterweisung) | ⬜ | ✅ | ✅ | ✅ | ⬜ |
| `formular` | ⬜ | ✅ | ⬜ | ⬜ | ⬜ |
| `lohnabrechnung` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| `bescheinigung` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| `sonstiges` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |

**Invariante:** `requires_signature = true` **erzwingt** `creates_task = true` (eine Signaturpflicht, von der der MA nie erfährt, wäre widersprüchlich). Im Formular ist die `creates_task`-Checkbox ausgegraut/gesetzt, solange Signatur aktiv ist.

---

## 5. Task-Typ-Ableitung (eine einzige Stelle)

In `EmployeeDocumentService::provide()`, deterministisch, als reine Methode (`TaskTypeResolver`) pure-unit-testbar:

1. `requires_signature` → `sign_document`
2. sonst `creates_task` → `read_document` (deckt "Belehrung ansehen/bestätigen" ab)
3. sonst → **keine Aufgabe** (nur bereitstellen; z. B. Lohnabrechnung, Bescheinigung)

`fill_form` und `custom` werden **nur manuell** angelegt, nie von `provide()`. (Der frühere Typ `acknowledge_instruction` entfällt — er war funktional identisch zu `read_document`.)

---

## 6. Backoffice-UI (Mitarbeiter-Detailseite `Livewire/Employees/Show`)

Neuer Abschnitt "Dokumente & Aufgaben", wo heute die `*_file_id`-Uploads liegen. **Nur einzeln pro MA** (Massen-Upload = späteres V2).

**Upload-Flow (Drag & Drop):**
1. Drop-Zone (Muster `InlineFileUpload`), **serverseitig** PDF-only (MIME + Extension), Größenlimit (~20 MB).
2. Drop öffnet Bereitstellungs-Formular (Inline-Panel). Titel (aus Dateiname), Kategorie-Select. Kategoriewechsel belegt Flag-Checkboxen live vor (`updatedCategory()`-Hook im Livewire-Backend — **nicht** über Inline-`@if` in `x-ui-*`-Attributen, siehe Blade-Pitfalls-Regel). Bei aktivem `notify_employee`: Hinweistext "Mitarbeiter erhält eine WhatsApp-Nachricht".
3. "Bereitstellen" → `EmployeeDocumentService::provide()`.

**Dokumentenliste:** Titel, Kategorie-Badge, Flag-Badges (kritisch/lohnrelevant — **kein** Barzahlung-Badge), abgeleiteter Status mit Datum, Benachrichtigungs-Spalte (✓ gesendet / ⚠ Fehler + "Erneut senden"). Aktionen: PDF öffnen (signierte URL), bei signierten Dokumenten "Signaturnachweis" (§7), Zurückziehen (Soft-Delete, gesperrt sobald signiert; schließt offene Aufgabe mit).

**Aufgabenliste:** offene zuerst. Manuell anlegen: `read_document` (+Dokumentwahl), `fill_form` (+`link_url`), `custom`. HR kann Aufgaben abhaken → `completed_by_user_id` = eigene ID.

**HR-Schreibtisch (abgeleitete Sicht, NICHT materialisiert):** Query nach `is_legally_critical`-Dokumenten mit offenem `notify_error` (analog Autopilot-Eskalations-Muster in `HrDesk/Index`). Verschwindet automatisch bei Retry-Erfolg (`notify_error → NULL`) und bei Soft-Delete — keine separate Aufräumlogik.

---

## 7. Signatur, Hash & Nachweis-PDF

**Hash-Timing (entschieden, da voll in unserem Spielraum):**
- Bei `provide()` wird `file_sha256` aus der gespeicherten Datei berechnet → friert die Bytes ein, die der MA vorgesetzt bekommt.
- Bei `sign()` wird der Hash **neu berechnet und gegen `file_sha256` verifiziert**. Mismatch (dank Immutability nie erwartet) → **Abbruch**, kein `signed_at`. Das ist der Wachhund über der Immutability-Invariante.

**Signieren im Portal** läuft in der bestehenden verifizierten Session (kein zweiter Token-Flow). Atomar: `signature_data`, `signed_at`, Hash-Verify, verknüpfte Aufgabe → `done`, Payroll-Marker falls `is_payroll_relevant`.

**Payroll-Anbindung:** Bei Abschluss eines `is_payroll_relevant`-Dokuments wird `payroll_data_changed_fields` um einen Eintrag (`Dokument: <Titel>`) ergänzt + `payroll_data_changed_at` gesetzt. **Reiner interner Marker, keine Sync-Wirkung** — HR quittiert über den bestehenden Lohnänderungs-Export. Kein "MA nicht sync-fähig"-Randfall.

**Signaturnachweis-PDF:** nutzt den **bestehenden** Vertrags-PDF-Generator (`RendersContractPdf` + DomPDF). Der Nachweis ist nur ein weiteres Template mit den Signatur-Feldern als Variablen: `signature_data`, `file_sha256`, `first_viewed_at`, `signed_at`, MA-Name, Dokument-Titel. **Kein eigener Renderer.** `first_viewed_at` UND `signed_at` erscheinen beide — der Abstand ist das Indiz gegen "nach 0 Sekunden bestätigt".

---

## 8. Portal-Seite (Mitarbeiter-Sicht, `EmployeePortal`)

**Hinweis-Banner nach Login** (im `verified`-State, ganz oben): Block "Offene Aufgaben" aus `rec_employee_tasks` mit `status = open`. CTA je Typ: "Ansehen & unterschreiben" / "Ansehen & bestätigen" / "Formular öffnen" (externer Link) / Freitext. Aufgaben zu `is_legally_critical`-Dokumenten rot/priorisiert. Block bleibt sichtbar bis alle Aufgaben erledigt sind → erfüllt "Hinweis bleibt bis Aktion abgeschlossen".

**"Meine Dokumente":** Liste **aller** bereitgestellten Dokumente (auch aufgabenlose: Lohnabrechnungen, Bescheinigungen). Öffnen läuft über eine Livewire-Aktion, die zuerst `first_viewed_at` setzt (falls leer) und dann die signierte URL liefert — **nicht** als nackter Link (sonst ist "Gesehen" unzuverlässig).

**Dokument-Ansicht + Signieren:** eingebettetes PDF + Download, Checkbox "gelesen und verstanden", Canvas-Signatur (`input-signature`), "Unterschreiben". Öffnen setzt `first_viewed_at`.

**`read_document`:** dieselbe Ansicht ohne Signaturfeld, Button "Gelesen & bestätigt" → `acknowledged_at` (idempotent, erster Zeitpunkt gilt).

**`fill_form` / `custom`:** Karte mit Beschreibung/Link; MA hakt selbst ab (Selbstauskunft, `completed_by_user_id = null`). **MA kann nicht selbst wieder öffnen** — Zurücksetzen nur durch HR (bestehendes Reset), damit der Nachweiswert erhalten bleibt.

**Absicherung:** jede Aktion prüft Session (`employee_portal_verified`), Zugehörigkeit MA↔Dokument/Aufgabe, und Zustand (zurückgezogen → freundliche Meldung). Signieren & Bestätigen **idempotent**. Bestehendes Rate-Limiting greift.

**WhatsApp-Inhalt:** kritische Dokumente nutzen den normalen Portal-Link (`portal_token`); nach Login steht die Aufgabe oben. Kein Deep-Link in V1.

---

## 9. Bewusst ausgeklammert (V2 / offen)

- **ZAS-Rückmeldung "unterzeichnet":** aus V1 raus (Kundenwunsch). Hinweis: Vertrags-Signatur fließt heute schon passiv über die Delta-Export-Spalte `VertragZurueckAm` in den ZAS-Pull; ein aktiver Push existiert nicht.
- **Barzahlung-Semantik:** Feld vorhanden (`is_cash_payment`), Bedeutung/Ort mit Kunde zu klären; kein Badge bis dahin.
- **E-Mail-Fallback:** Sender ist so geschnitten, dass der Fallback später **eine** Einbaustelle hat; V1 ist WhatsApp-only mit Fehler-Badge + HR-Schreibtisch.
- **Folgebelehrung (automatische Wiedervorlage):** V2. Nachweis-Historie liegt dann auf **Tasks** (je Wiederholung eigener Task mit eigenem `completed_at`), nicht am Dokument-`acknowledged_at`. Das Datenmodell steht dem nicht im Weg.
- **Massen-Bereitstellung** (z. B. monatliche Lohnabrechnungen): V2.

---

## 10. Fehlerbehandlung & Randfälle

- **MA ohne Telefonnummer / WhatsApp scheitert:** `provide()` schlägt nicht fehl — Dokument + Aufgabe entstehen, nur `notify_error` gesetzt (Benachrichtigung außerhalb der Transaktion). Kritische Dokumente erscheinen über die abgeleitete HR-Schreibtisch-Query.
- **Erfolgspfad des Senders setzt `notify_error = NULL`** (nicht nur `notified_at`), sonst bliebe das Dokument dauerhaft in der Retry-Menge und der Fehler-Badge klebte trotz Zustellung.
- **MA deaktiviert (`is_active = false`):** Portal-Verifizierung scheitert ohnehin; offene Aufgaben bleiben, keine weiteren Benachrichtigungen.
- **Dokument während offener Ansicht zurückgezogen:** Sign-/Bestätigen-Aktion lädt frisch, prüft `deleted_at` → freundliche Meldung.
- **Doppel-Tab / Doppelklick:** Signieren & Bestätigen idempotent (erster Zeitpunkt gewinnt).
- **Nicht-PDF-Upload:** serverseitige Validierung (MIME + Extension).
- **Kategorie-Defaults nach Übersteuerung:** Formular übernimmt Defaults nur beim Kategoriewechsel, nie beim Speichern — was HR angehakt hat, gilt.

---

## 11. Teststrategie

Modul-Konvention: **reines PHPUnit, kein Laravel/keine DB** → Logik pure-unit-testbar schneiden (statische/wertbasierte Klassen statt Logik in Livewire-Methoden).

**Pure-Unit-Tests:**
- Kategorie → Flag-Default-Auflösung (`DocumentCategoryDefaults`)
- Task-Typ-Ableitung (`TaskTypeResolver`): sign / read / kein Task; `fill_form`/`custom` nie automatisch
- Invariante: `requires_signature` erzwingt `creates_task`
- Status-Ableitung aus Zeitstempeln
- Retry-Query-Bedingung (Dokument in Retry-Menge gdw. `notify_error IS NOT NULL`; nach Erfolg raus)
- Idempotenz: zweites `acknowledge`/`sign` überschreibt ersten Zeitpunkt nicht
- **Hash-Verify-Abbruch (Pflicht):** `provide()`-Hash ≠ aktueller Hash → `sign()` bricht ab, **`signed_at` bleibt null**, keine Signatur persistiert. Schützt die Immutability-Invariante.
- `WhatsAppTemplateSendService`-Mapping: gleiche Eingaben → gleiche Komponenten-Struktur wie die Alt-Pfade `sendPortalNotification` **und** `sendContractPortalNotification` (Validierung gegen beide, damit die Extraktion sauber delegiert)

**Manueller Verifikationsplan** (DB/Livewire/WhatsApp nicht automatisiert): bereitstellen → WhatsApp prüfen → im Portal öffnen (`first_viewed_at`) → signieren/bestätigen → Zeitstempel + Aufgabe erledigt + Payroll-Marker prüfen → Nachweis-PDF prüfen → Retry nach simuliertem Sendefehler.
