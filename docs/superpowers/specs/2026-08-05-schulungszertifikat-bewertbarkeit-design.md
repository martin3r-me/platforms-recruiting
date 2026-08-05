# Schulungs-Bewertbarkeit + Teilnahme-Zertifikat — Design

**Datum:** 2026-08-05
**Modul:** platforms-recruiting
**Status:** Entwurf — **§A ersetzt und zurückgestellt**, §B–§D gültig.
Code-Referenzen gegen main `45f97d3`

> **§A (Bewertbarkeit) ist überholt.** Der Kunde hat den Bewertungsumbau
> nachgereicht und vorgezogen: Erfassung während des Termins, fünf Kriterien à
> 1–5 Sterne, Freitext, Tabelle mit A–Z-Sortierung und Suche. Das verschluckt §A
> vollständig — Puffer-Spalten, Verfügbarkeits-Policy, Ziel-Weiche und Übernahme
> sind dort mit dem größeren Datenmodell abgedeckt. Maßgeblich ist
> **`2026-08-05-bewertungssystem-design.md`**; §A dieser Spec wird nicht
> umgesetzt.
>
> **§B–§D (Vorlagen-Typ, Ausstellung am HR-Schreibtisch, WhatsApp-Zustellung)
> bleiben unverändert gültig** und werden nach dem Bewertungssystem umgesetzt.
> Der Nicht-EU-Sonderfall („bewertbar, obwohl kein Vertrag") ist mit der neuen
> Spec gegenstandslos: die Freigabe hängt an `attended`, nicht am Vertragsstand.
>
> Offen aus dem Spec-Review, gilt weiter für §B–§D: **Verifikation V1** (der
> bestehende Applicant-WhatsApp-Sendeweg — `sendPortalNotification()` ist ein
> Employee-Pfad und für einen abgelehnten Bewerber ohne Mitarbeiterdatensatz
> nicht nutzbar) blockiert den Plan für §B–§D.

## Problem

Zwei Lücken am Ende des Schulungs-Flows, beide vom Kunden benannt:

> „Nach erfolgreicher Schulung, muss der Bewerber bewertbar sein, dann landet er auf
> dem HR Schreibtisch zur Freigabe des Arbeitsvertrages nach Verifizierung der
> hochgeladenen Dokumente. Falls die Dokumente nicht ausreichen muss das Zertifikat
> über die Schulung auswählbar sein, dass der Bewerber einen Nachweis erhält und die
> Zeit nicht ganz verschwendet war."

**(1) Bewertbarkeit.** Das Bewertungs-Modal der Schulungsnachbereitung (Wäschepaket,
Qualifikation, Sternebewertung) ist an die Existenz eines `RecEmployee` gekoppelt
(`InterviewBookings/Index.php:667-671`, Blade `:384-399`). Der Employee entsteht aber
erst beim Vertragsversand. Nicht-EU-Bewerber, die seit dem Juli-Umbau bei
`attended` auf den HR-Schreibtisch geroutet werden, haben zum Zeitpunkt der
Nachbereitung keinen Employee → die Bewertungsspalte zeigt „—" mit dem Tooltip
„MA noch nicht angelegt – Verträge zuerst versenden". Wer später abgelehnt wird,
ist **nie** bewertbar. Auch beim EU-Bürger greift die Kopplung, wenn der Versand
erst nach der Nachbereitung läuft.

**(2) Trostpreis.** Der Negativ-Pfad am Schreibtisch (`rejectCase()`,
`HrDeskRoutingService.php:267-289`) setzt Fall auf `rejected`, Bewerber auf
`rejected_at` + `is_active=false` und schreibt einen Log — der Bewerber bekommt
**nichts**: keine Nachricht, kein Dokument. Ein ausstellbares Dokument existiert
im Modul nirgends; alles was „Bescheinigung/Certificate" heißt, sind Upload-Felder
für Dokumente, die der Bewerber *liefert*.

## Ziel (Produktentscheidungen — fix)

1. ~~Bewertbar ist, wer teilgenommen hat ODER schon Mitarbeiter ist~~ —
   **entfällt, siehe Kopfhinweis.** Die Bewertbarkeit regelt
   `2026-08-05-bewertungssystem-design.md` (Freigabe allein über `attended`).
2. Zertifikat-Vorlagen leben in `rec_contract_templates` mit **`type`-Unterscheidung**;
   Editor, Platzhalter-Engine und Verwaltungsseite werden mitbenutzt (§B).
3. Am HR-Schreibtisch ist das Zertifikat beim **Ablehnen auswählbar** — nicht
   automatisch, nicht bei Freigabe (§C).
4. Zustellung per **WhatsApp mit Token-PDF-Link**; scheitert der Versand, lädt HR das
   PDF herunter und verschickt es von Hand. **Kein automatischer Mail-Fallback**
   (das Modul verschickt nach außen ausschließlich WhatsApp; Mail kommt nur herein) (§D).
5. Ablage als eigene Tabelle mit Inhalts-Snapshot — nicht als `RecContract` (§C).

## Ausführungs-Schnitt

**Nur noch ein Paket:** **B/C/D** = §B–§D (Vorlagen-Typ, Ausstellung, Zustellung).
Berührt HR-Desk, Vorlagen-Verwaltung, neue Tabelle + neue Public-Route.

~~Paket A~~ ist entfallen (Kopfhinweis) — es steckt im Bewertungssystem. Die
Reihenfolge ist damit: Bewertungssystem zuerst, danach dieses Paket. Gemeinsame
Dateien der beiden Vorhaben: `RecruitingServiceProvider` und
`resources/views/livewire/interview-bookings/index.blade.php` (dort ändert das
Bewertungssystem die Bewertungs-Zelle, dieses Paket nichts) — kein Konflikt bei
sequentieller Umsetzung.

## Geteilte Fakten (gegen Code verifiziert — für alle Tasks bindend)

**F1 — Public-Form-Link-Token: 128 Bit, kein Ablauf, keine Rotation.**
Erzeugung `platforms-core/src/Models/CorePublicFormLink.php:30-37` per
`bin2hex(random_bytes(16))`; Spalte `token` `string(64) unique`
(Migration `2026_02_23_000001:14`). Angelegt lazy über
`HasPublicFormLink::getOrCreatePublicFormLink()`
(`platforms-core/src/Traits/HasPublicFormLink.php:15-21`) mit `is_active=true` und
**ohne** `expires_at`. `isValid()` (`:61-72`) lässt `expires_at IS NULL` passieren.
Ein Grep über Recruiting **und** Core nach Schreibzugriffen auf `is_active`/`expires_at`
an Form-Links liefert nur die Model-Deklarationen (`:19`, `:25`, `:58`, `:67`) —
**keine Invalidierung, keine Rotation, nirgends.**

**F2 — `contract-pdf`-Guard prüft den Bewerberstatus NICHT.**
Route `routes/public.php:45-46`, Middleware nur `web` + `NoCacheHeaders`
(`RecruitingServiceProvider.php:126-128`, keine Auth). Controller-Guard
`ContractPdfController.php:18-28`: Token gültig, `linkable instanceof RecApplicant`,
Vertrag gehört dem Bewerber und `status='completed'`. Weder `rejected_at` noch
`is_active` werden geprüft; `rejectCase()` berührt den Token nicht. **Ein abgelehnter
Bewerber behält also unbegrenzt einen gültigen Applicant-Token.**

**F3 — `rejectCase()` läuft ohne Transaktion, mit ungeprüftem Applicant.**
`HrDeskRoutingService.php:267-289`: drei sequenzielle Writes (`$case->update`,
`$applicant->update`, `RecAutoPilotLog::create`). Grep nach
`DB::`/`transaction`/`savepoint` in der Datei: **null Treffer**. `$applicant =
$case->applicant` (`:276`) ist ungeprüft → Fatal bei fehlender Relation. Der
Log-Write (`:284-288`) ist **nicht** in try/catch (anders als der Phase-Check in
`approveCase`, `:244-257`). Aufrufer `HrDesk/Index.php:137-140` fängt nichts
(der `approve`-Zweig fängt nur `LegalStatusNotCheckedException`).

**F4 — Employee-Anlage: genau zwei `create`-Stellen, eine davon aus Bewerber.**
`CreateEmployeeFromApplicantService.php:55` (aus Bewerber) und
`Zas/ZasInboundEmployeeImporter.php:104` mit `'rec_applicant_id' => null` (`:106`,
nie aus einem Bewerber). Aufrufer von `createOrUpdate()`: `RecApplicant.php:714`
(Phase-Hook `creates_employee_on_completion`, `:712`), `DirectHire/Index.php:324`,
`ZasReExportByBookingDate.php:34`, `BackfillEmployeeFieldsFromApplicant.php:57`.
**Kein MCP-Tool legt Employees an** (Grep über `src/Tools/*.php` nach
`CreateEmployee|createOrUpdate|RecEmployee`: leer).

**F5 — hrData entsteht explizit im Service, nicht per Observer; Early-Return bei
Bestand.** `CreateEmployeeFromApplicantService.php:104-105`:

```php
$hrData = $employee->ensureHrData();
$this->snapshotContractDatesToHrData($applicant, $hrData);
```

`ensureHrData()` ist ein `firstOrCreate` (`RecEmployee.php:216-222`). Der einzige
Observer auf der Tabelle hängt an `RecEmployeeHrData::saved`
(`Observers/RecEmployeeExportObserver.php:124-135`, registriert über
`::register()` in `RecruitingServiceProvider.php:152`) und dient **nur** dem
ZAS-Update-Marker — er erzeugt nichts. `createOrUpdate()` steigt bei existierendem
Employee vorher aus (`:38-41`) → **jede Übernahme-Logik an `:105` feuert
ausschließlich bei der Erst-Anlage.**

**F6 — WhatsApp-Versand ist synchron und wirft nicht.**
`RecEmployee::sendPortalNotification()` (`:415-583`) ist eine Model-Methode ohne
Job/Dispatch, ruft `WhatsAppMetaService::sendTemplate()` direkt (`:546-553`) und
liefert `['ok' => bool, 'message' => ?string]`. Jede Vorstufe (Settings `:420-426`,
Modul-Existenz `:428-430`, Template `APPROVED` `:432-435`, Account aktiv `:441-448`,
`CommsChannel` `:450-457`, Telefonnummer `:459-479`) gibt `['ok' => false, …]` zurück
statt zu werfen; darüber liegt zusätzlich try/catch (`:571-582`).
**Konsequenz: ein WA-Fehler kann keine Transaktion kippen.**

**F7 — Der WA-URL-Button überträgt genau EINEN Suffix.**
`RecEmployee.php:532-544`: bei vorhandenem URL-Button wird
`['type'=>'button','sub_type'=>'url','index'=>0,'parameters'=>[['type'=>'text','text'=>$this->portal_token]]]`
gesendet — Meta hängt diesen Text an die Basis-URL des Templates. Die
Body-Variablen laufen separat über `$variableValues` (`:484-491`) mit
positionalem Auto-Mapping `['employee_name','portal_link']` (`:495`) und
Named-Fallback (`:517-528`). **Eine Route mit zwei dynamischen Segmenten ist per
URL-Button nicht adressierbar.**

**F8 — Bewertungsfelder: nullable, ohne Default, „leer" == NULL.**
Migration `2026_05_21_000004_add_linen_package_to_hr_data.php:19-29`:
`json('linen_package_items')->nullable()`,
`unsignedTinyInteger('star_rating')->nullable()->comment('1-5 Sterne, HR-only')`,
`json('qualifications')->nullable()`. Casts `RecEmployeeHrData.php:40-42`:
`array`, `array`, `integer`. `saveEvaluation()` normalisiert aktiv auf NULL
(`InterviewBookings/Index.php:704-708`, `?: null` bzw. Ternary) — **`[]` ist
kein gültiger Leerwert.**

**F9 — `type`-Guard-Landkarte.** Die einzige Stelle, an der aus
`applicant.contract_template_id` ein Arbeitsvertrag entsteht, ist
`SendContractsService.php:62-65` (Auflösung) → `:98-105` (`RecContract::create`).
Bereits per `code` gefiltert und damit unkritisch: `SendContractsService:74`
(`code='IFSG'`), `InterviewBookings/Index:789` und `HrDesk/Index:232`
(`code='AV-default'`), `HrDesk/Index:156` + `:206` (`code like 'AT-%'`), alle
ZAS-Leser (`ZasFileController:108-115`, `ZasEmployeeFileController:107-114`,
`ZasEmployeeFieldResolver:356-361`/`:395-397`/`:493-495`,
`ZasFieldResolver:348-349`/`:415-421`). **Ungefiltert und damit betroffen:**
`Applicant/Show:661-666` (Dropdown) + `:696` (`exists:`-Regel) + `:750`/`:767-773`
(Anlage), `DirectHire/Index:229-234` (Dropdown) + `:288-291`/`:311-319` (Anlage),
`InterviewBookings/Index:385-391` (Validierung), `Tools/CreateContractTool:87`/`:102`,
`Tools/ListContractTemplatesTool:56-58`, `Tools/UpdateApplicantTool:193` (FK-Check),
`Contracts/Index:53-57` (nur Filter-Anzeige, kosmetisch),
`ContractTemplates/Index:46-50` (Admin-Liste — soll beide Typen zeigen).

**F10 — `setApplicantContractTemplate` ist verwaist, aber erreichbar.**
Grep nach `setApplicantContractTemplate` in `resources/`: **exit=1, kein Treffer** —
die Nachbereitungs-Blade rendert keinen AV-Varianten-Dropdown mehr (nur
`$this->defaultContractTemplate` auf `:214`). Die Methode ist aber `public` auf der
Livewire-Komponente (`InterviewBookings/Index.php:367`) und damit über das
Wire-Protokoll aufrufbar; ihre Validierung (`:385-391`) prüft nur `team_id`,
`id` und `is_active`.

**F11 — Ort und Datum der Schulung hängen am Termin, nicht an der Buchung.**
`rec_interviews.location` `string` nullable (Migration
`2026_04_14_000001:36`, fillable `RecInterview.php:24`), `rec_interviews.starts_at`
`dateTime` NOT NULL indexiert (`:37`, `:55`, Cast `RecInterview.php:41`). Die Buchung
hat **keine** Ortsspalte; `booked_at` (`:73`) ist der Buchungszeitpunkt, nicht der
Schulungstermin (`RecInterviewBooking.php:17-40`). Zugriff über
`booking->interview` (`:63-66`).

**F12 — `meta.ort` ist ein Stub, der still leer liefert.**
`RecContractTemplate::resolveSource()` `:188-195`:

```php
if (str_starts_with($source, 'meta.')) {
    $metaKey = substr($source, strlen('meta.'));
    return match ($metaKey) {
        'datum_heute' => Carbon::now()->format('d.m.Y'),
        'ort' => '',
        default => '',
    };
}
```

Wer heute `meta.ort` mappt, bekommt kommentarlos einen leeren String.

## Architektur

### §A Bewertbarkeit ab Teilnahme — ÜBERHOLT, NICHT UMSETZEN

> Ersetzt durch `2026-08-05-bewertungssystem-design.md`. Der Abschnitt bleibt als
> Entscheidungsspur stehen (die Fakten F1–F12 gelten weiter, und die dortige
> Lösung baut auf denselben Stellen auf) — **umgesetzt wird er nicht.**
> Abweichungen der neuen Spec: Bewertung liegt an der Buchung statt am Bewerber,
> fünf Kriterien statt einem `star_rating`, Freigabe allein über `attended` statt
> ODER-Regel, zusätzlich Freitext.

**A1 — Puffer-Spalten auf `rec_applicants`.** Drei neue Spalten, **1:1 wie F8**:
`star_rating` `unsignedTinyInteger` nullable, `linen_package_items` `json` nullable,
`qualifications` `json` nullable. Casts auf `RecApplicant`: `integer`, `array`,
`array`. „Leer" ist NULL, **niemals `[]`** — sonst widersprechen sich die beiden
Prüfformen: die Übernahme in A4 prüft `=== null` und würde `[]` als „schon gefüllt"
lesen (Übernahme blockiert), während die Blade-Anzeige mit `!empty()` dasselbe `[]`
als leer liest (kein Wert sichtbar). Ergebnis wäre eine Bewertung, die weder
übertragen noch angezeigt wird. Migration ohne Default,
`Schema::hasColumn`-Guards wie in der Bestands-Migration.

**A2 — Policy-Klasse `EvaluationAvailability`.** Pure Funktion, PHPUnit-testbar
(Modul-Konvention: reines PHPUnit ohne Laravel/DB):

```
EvaluationAvailability::isAvailable(string $bookingStatus, bool $hasEmployee): bool
```

Wahr, wenn `$bookingStatus === 'attended'` **ODER** `$hasEmployee === true`.

**Bewusst ODER, nicht Ersetzung.** Ein reines „ab attended" wäre eine Regression:
Es gibt heute sichtbare Bewertungs-Buttons bei Buchungen, deren Status **nicht**
`attended` ist, obwohl ein Employee existiert — etwa `no_show` (Bewerber
nachträglich doch eingestellt) oder `confirmed`, weil der Status nach der Schulung
nie nachgezogen wurde, während der Versand über einen anderen Pfad lief
(`Applicant/Show`-Vertragsanlage, Direkteinstellung). Diese Zeilen würden ihren
Button verlieren und bereits erfasste Bewertungen unsichtbar machen.
**Live gemessen (§Verifikation): aktuell ist keine Bestandsbewertung betroffen
(`bewertungen_ohne_attended = 0`), die Klasse selbst existiert aber
(`ma_ohne_attended = 1`).** Die ODER-Form verhindert damit einen künftigen, still
eintretenden Verlust — keinen heutigen.

**A3 — Verwendung an drei Stellen, eine Wahrheit.** Die Policy wird benutzt in
(a) der Blade-Bedingung (`interview-bookings/index.blade.php:385`, ersetzt
`@if($employee)`), (b) `openEvaluationModal()` (`Index.php:668-671`) und
(c) `saveEvaluation()` (`:697-701`). Die serverseitigen Guards bleiben bestehen —
sie wechseln nur ihr Kriterium und ihre Fehlermeldung („Bewertung erst nach der
Schulung möglich."). Kein Guard entfällt.

**A4 — Ziel-Weiche und Übernahme.** `openEvaluationModal`/`saveEvaluation` lesen
und schreiben auf `hrData`, wenn ein Employee existiert (Verhalten heute
unverändert), sonst auf den Puffer. Bei der Employee-Erst-Anlage überträgt
`CreateEmployeeFromApplicantService` die drei Werte, **direkt neben `:105`**, im
try/catch-Muster von `snapshotContractDatesToHrData()` (`:196-242`) — aber mit
**eigenem Log-Marker** (`'[CreateEmployeeFromApplicantService] evaluationTransfer
failed'`), damit ein Fehler beim Snapshot nicht wie ein Fehler bei der Bewertung
gelesen wird und umgekehrt. Übernahme nur in leere hrData-Felder (`=== null`),
damit ein späterer HR-Edit nie überschrieben wird; idempotent bei Doppellauf.
Der Puffer wird **nicht** geleert (Archiv, s. §Tradeoffs).

### §B Vorlagen-Typ (Paket B/C/D)

**B1 — Migration.** Spalte `type` auf `rec_contract_templates`:
`string(20) NOT NULL DEFAULT 'contract'` — **nicht nullable**, damit es keinen
dritten Zustand „unbekannt" gibt, den jede Query mitdenken müsste. Bestand wird
durch den Default korrekt zu `contract`. Erlaubte Werte: `contract`, `certificate`
(Konstanten auf `RecContractTemplate`).

**B2 — Signatur-Zwang serverseitig.** `requires_signature` wird bei
`type === 'certificate'` **im Model** erzwungen (`booted()`/`saving`-Hook), nicht
nur im Formular. Das deckt drei Wege ab, die das Modal umgehen: MCP
(`CreateContractTemplateTool:87`, `UpdateContractTemplateTool:86`), einen
nachträglichen Typwechsel an einer Bestandsvorlage, und Seeder/Commands.

**B3 — Editor.** `ContractTemplates/Index` bekommt `type` in `$rules`, im
Create/Edit-Modal ein Dropdown („Vertrag" / „Schulungszertifikat"), in der Liste
einen Typ-Badge. Der Signatur-Toggle wird bei `certificate` ausgeblendet
(Anzeige folgt B2, erzwingt nichts selbst). Die Admin-Liste (`:46-50`) bleibt
**ungefiltert** — hier sollen beide Typen sichtbar sein.

**B4 — `type`-Guard.** Primär in `SendContractsService.php:64` als
`->where('type', 'contract')` in der AV-Auflösung: das ist der Trichter, durch den
jeder Weg über `applicant.contract_template_id` läuft — inklusive
`UpdateApplicantTool:193` und der verwaisten, aber wire-aufrufbaren
`setApplicantContractTemplate` (F10). Zusätzlich an den Bypass-Stellen, die
`SendContractsService` gar nicht anfassen — **fünf Einträge, zwei Direkt-Anlagen
plus drei Auswahl-Listen:**

| Stelle | Art | Maßnahme |
| --- | --- | --- |
| `DirectHire/Index.php:288-291` + `:311-319` | Anlage | `type`-Filter in der Auflösung |
| `Tools/CreateContractTool.php:87` + `:102` | Anlage (MCP) | `type`-Filter + Fehler `VALIDATION_ERROR` |
| `Applicant/Show.php:661-666` (+ `:696`, `:750`) | Dropdown + Validierung | `type`-Filter, `exists:`-Regel per Rule-Objekt |
| `DirectHire/Index.php:229-234` | Dropdown | `type`-Filter |
| `Tools/ListContractTemplatesTool.php:56-58` | MCP-Liste | optionaler `type`-Parameter, Default `contract` |

Ebenfalls mitziehen: `InterviewBookings/Index.php:385-391` (F10) und
`Contracts/Index.php:53-57` (Filter-Dropdown, kosmetisch — ein Zertifikat als
Filteroption wäre nur verwirrend, nicht gefährlich).

**B5 — Neue Platzhalter-Quellen.** `resolveSource()` bekommt einen
`schulung.`-Zweig mit `schulung.datum` und `schulung.ort`, aufgelöst über die
Schulungs-Buchung des Bewerbers:

- Selektion: `status = 'attended'`, `deleted_at IS NULL`, sortiert
  **`interview.starts_at DESC`, Tie-Break `bookings.id DESC`** — Join-Weg wie
  `ZasReExportByBookingDate.php:58-62`.
- **Bewusst nicht `latest('id')`** (das Muster aus `RecApplicant.php:936`): Bei
  einer Umbuchung kann die zuletzt *erfasste* Buchung ein *früheres* Termindatum
  haben. Auf dem Dokument steht das Datum, das der Bewerber liest — es muss das
  späteste tatsächliche Teilnahmedatum sein, nicht das jüngste Insert.
- `schulung.datum` → `starts_at` im Format `d.m.Y` (konsistent zu `contact.*` und
  `applicant.extra_field.*`, `:128`/`:143`/`:147`). `schulung.ort` →
  `interview.location`. Keine Buchung gefunden oder Feld leer → leerer String
  (Semantik wie alle anderen Zweige).

**B6 — `meta.ort` bleibt Dead End.** Kein Alias auf `schulung.ort`. Begründung:
`meta.*` ist per Design kontextfrei (nur `datum_heute` aus `Carbon::now()`), ein
Ort kann dort nicht sinnvoll herkommen. In der Platzhalter-Hilfe des Editors wird
`meta.ort` als „ohne Funktion" markiert oder entfernt — der stille Leerstring
(F12) ist die eigentliche Falle, nicht der fehlende Wert.

### §C Ausstellung am HR-Schreibtisch (Paket B/C/D)

**C1 — Tabelle `rec_training_certificates`.** Spalten: `id`, `uuid` (unique,
UuidV7 via `booted()` wie überall im Modul), `team_id`, `rec_applicant_id`,
`rec_contract_template_id`, `personalized_content` (longText, Snapshot),
`issued_at`, `issued_by_user_id` nullable, `wa_sent_at` nullable, Timestamps.
**Unique-Constraint auf `rec_applicant_id`** — auf DB-Ebene, nicht nur als
Blade-Bedingung: „kein zweites Ausstellen" ist eine Invariante, und ein
Doppelklick am Desk ist kein Sonderfall (heutige Akzeptanzklasse: `wire:loading`
+ Service-Guards, kein DB-Lock).

**Nicht** als `RecContract` ablegen: eine Contract-Zeile würde
`hasAnyContractSent()` wahr machen, worauf die Versand-Guards des Nicht-EU-Umbaus
aufsetzen (`ContractDispatchService`, `InterviewBookings/Index:522`), und in
Portal-, Employees-Show- und ZAS-Vertragslisten auftauchen.

**C2 — Reihenfolge: Ablehnung und Zertifikat gemeinsam, WhatsApp danach.**

1. **`rejectCase()` reparieren** (F3): die drei Writes in `DB::transaction`,
   `$case->applicant` vor `->update()` prüfen und bei `null` sauber aussteigen
   (Log + return statt Fatal). Das ist eine Bestandsreparatur, kein Feature —
   sie ist Voraussetzung für Schritt 2 und auch ohne das Zertifikat richtig.
2. **Desk-Aktion:** `DB::transaction { rejectCase() + Zertifikat-Zeile mit
   Snapshot }` — alles oder nichts.
3. **WhatsApp NACH dem Commit.** Risikofrei belegt durch F6: synchron, wirft nicht,
   jede Stufe gibt `['ok' => false]`, zusätzlich try/catch. Fehler → `wa_sent_at`
   bleibt leer + Flash-Meldung; die Ablehnung steht.

**Warum nicht „Zertifikat vorher, dann ablehnen":** „Zertifikat committed,
Ablehnung gekippt" ist der schlechtere Endzustand — die Danke-Nachricht ist raus,
der Bewerber ist aber weiter offener HR-Fall, und der Retry wäre durch das
Unique-Constraint aus C1 („kein zweites Ausstellen") blockiert. Der umgekehrte
Fehlerfall („abgelehnt, Zertifikat fehlt") existiert durch die gemeinsame
Transaktion nicht mehr.

**C3 — UI.** Im Ablehnen-Zweig des Resolve-Modals (`HrDesk/Index.php:137-140`)
erscheint „☑ Teilnahme-Zertifikat ausstellen" mit Vorlagen-Dropdown (aktive
`certificate`-Vorlagen des Teams; bei genau einer vorausgewählt). Sichtbar nur,
wenn der Bewerber eine `attended`-Buchung hat — bestehendes Batch-Muster
`attendedApplicantIds()` (`HrDesk/Index.php:242-255`), das der Sende-Bereich
schon nutzt. Nach der Ausstellung zeigt die Karte, solange sie sichtbar ist,
„Zertifikat ausgestellt am … [Download] [Erneut senden]"; der Download ist der
manuelle Mail-Weg.

Gilt gleichermaßen für `no_german_knowledge`-Fälle — Kriterium ist die
`attended`-Buchung, nicht der Fall-Grund.

### §D Zustellung (Paket B/C/D)

**D1 — Route `/recruiting/zertifikat/{uuid}`**, aufgelöst über
`rec_training_certificates.uuid`. Neu in `routes/public.php`, damit unter `web` +
`NoCacheHeaders` (`RecruitingServiceProvider.php:126-128`). Rendering per DomPDF
aus dem Snapshot über eine Blade-Hülle analog `recruiting::pdf.contract` /
`RendersContractPdf`.

**Nicht über den Applicant-Token.** Beide Varianten sind URL-Button-kompatibel
(ein Suffix, F7), aber:

- (a) Die Routing-Architektur hängt dann nicht an der Geschäftsregel „ein
  Zertifikat pro Bewerber". Eine token-only Route müsste bei einer späteren
  Lockerung dieser Regel umgebaut werden.
- (b) Der Applicant-Token öffnet auch Bewerbungsformular und `contract-pdf`,
  unbegrenzt und ohne Rotation (F1/F2). Ihn per WhatsApp **aktiv erneut** zu
  versenden ist eine neu geöffnete Tür in eine bestehende Lücke — der Trostpreis
  soll das Zertifikat zustellen, nicht den Generalschlüssel.

**Präzisierung zur Entropie** (nicht identisch, aber ausreichend): Der
Applicant-Token hat 128 Bit Zufall (F1). UUIDv7 hat 48 Bit Zeitstempel + 4 Bit
Version + 2 Bit Variante und damit **~74 Bit Zufall**, plus einen ableitbaren
Erstellungszeitpunkt. 2^74 ist praktisch unerratbar, und die Ausstellungszeit
steht ohnehin auf dem Dokument — die Entscheidung für `uuid` bleibt. Wer
Bit-Parität will, nimmt stattdessen eine eigene `token`-Spalte mit
`bin2hex(random_bytes(16))`; das ist eine Ein-Zeilen-Änderung an C1 und ändert
den Rest des Designs nicht.

**Kein Status-Guard im Controller** — bewusst: der abgelehnte, inaktive Bewerber
ist der Normalfall dieses Dokuments. Geprüft wird nur die Existenz der
Zertifikat-Zeile.

**D2 — WhatsApp.** Muster von `sendPortalNotification()` (F6/F7): zwei neue
Team-Settings `training_certificate_wa_template_id` und
`training_certificate_wa_account_id`, Body-Variablen `vorname`/`name` +
`certificate_link`, URL-Button-Suffix = `uuid`. Erfolg → `wa_sent_at`; jeder
Fehlerpfad → `['ok' => false]` + Flash + `wa_sent_at` bleibt leer. Ist kein
WA-Template konfiguriert, wird das Zertifikat trotzdem ausgestellt (Snapshot +
Download) und HR sieht einen Hinweis statt eines Versandergebnisses.

## Tests & Verifikation

**Pure-Unit (PHPUnit, Modul-Konvention — kein Laravel, keine DB):**
- `EvaluationAvailability::isAvailable`-Matrix: `attended`/kein Employee,
  `no_show`/Employee, `confirmed`/Employee, `registered`/kein Employee,
  `attended`/Employee.
- Nur-wenn-leer-Logik der Bewertungs-Übernahme, inkl. `[]`-vs-`null`-Fälle (F8).
- Zertifikat-Sichtbarkeit/Freigabe als pure Funktion (attended? bereits
  ausgestellt? Vorlage vorhanden?).
- `schulung.*`-Auflösung: Sortierung `starts_at DESC` + Tie-Break `id DESC`,
  Umbuchungs-Fall (jüngstes Insert ≠ spätester Termin), Format `d.m.Y`,
  Leerfälle.

**Harness (sqlite, Muster Warteliste/Dedup-Guard):** Transaktions-Rollback in C2
(Zertifikat-Fehler → Ablehnung nicht committed), Unique-Constraint auf
`rec_applicant_id`, `requires_signature`-Zwang bei Typwechsel (B2),
`type`-Guard in `SendContractsService` (Zertifikat-ID als
`contract_template_id` → RuntimeException statt Vertrag).

**Live-Verifikation zur (überholten) §A-Entscheidung — erledigt, Ergebnisse unten.**
Die Zahlen bleiben dokumentiert, weil die Bewertungssystem-Spec sie für ihre
Freigabe-Regel heranzieht (dort §2: kein ODER mit „Employee existiert"). Aus der
Entwicklungsumgebung nicht ausführbar und daher vom User auf dem Server erhoben:
`meingedeck` hat kein `.env`, die lokale `database/database.sqlite` (Stand
2026-07-07, 72 Tabellen) enthält keine `rec_*`-Tabellen, und es existiert kein
Recruiting-Employees-MCP-Tool (`tool_registry.SEARCH` im Namespace `recruiting`:
applicants, contracts, contract_templates, interviews, interview_bookings,
interview_types, event_locations, lookup(s), phases — `hcm.employees.*` zeigt auf
`hcm_employees`). Ausgeführte Queries:

```sql
-- Vorflug (Nenner): MA mit Bewerberbezug
SELECT COUNT(*) AS ma_mit_bewerber
FROM rec_employees WHERE rec_applicant_id IS NOT NULL;

-- Vorflug (Nenner): MA ohne Bewerberbezug — ZAS-Import, strukturell nie bewertbar
SELECT COUNT(*) AS ma_ohne_bewerber
FROM rec_employees WHERE rec_applicant_id IS NULL;

-- MA mit Bewerber, aber ohne attended-Buchung (Direkteinstellung/Altbestand)
SELECT COUNT(*) AS ma_ohne_attended
FROM rec_employees e
WHERE e.rec_applicant_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM rec_interview_bookings b
    WHERE b.rec_applicant_id = e.rec_applicant_id
      AND b.status = 'attended' AND b.deleted_at IS NULL
  );

-- Der eigentliche Regressionszähler zu A2: bereits erfasste Bewertungen,
-- die eine reine "ab attended"-Regel unsichtbar machen würde
SELECT COUNT(*) AS bewertungen_ohne_attended
FROM rec_employee_hr_data hr
JOIN rec_employees e ON e.id = hr.rec_employee_id
WHERE (hr.star_rating IS NOT NULL OR hr.linen_package_items IS NOT NULL
       OR hr.qualifications IS NOT NULL)
  AND NOT EXISTS (
    SELECT 1 FROM rec_interview_bookings b
    WHERE b.rec_applicant_id = e.rec_applicant_id
      AND b.status = 'attended' AND b.deleted_at IS NULL
  );
```

**Ergebnis (Live-DB, 2026-08-05, vom User ausgeführt):**

| Kennzahl | Ergebnis |
| --- | --- |
| `ma_mit_bewerber` | 29 |
| `ma_ohne_bewerber` | 1 |
| `ma_ohne_attended` | 1 |
| `bewertungen_ohne_attended` | 0 |

Auslegung: 30 Mitarbeiter insgesamt, davon 29 aus dem Bewerber-Funnel und 1 aus
dem ZAS-Inbound (`rec_applicant_id IS NULL`, F4 — strukturell nie bewertbar, kein
Bewerber vorhanden). Genau **einer** der 29 hat keine `attended`-Buchung.

**`bewertungen_ohne_attended = 0` heißt: die ODER-Regel aus A2 rettet keine
Bestandsdaten.** Sie bleibt trotzdem so gebaut, aus zwei Gründen: (a) der eine
Fall aus `ma_ohne_attended` belegt, dass die Klasse „Employee ohne attended"
real existiert und nicht durch eine Invariante ausgeschlossen ist — eine reine
„ab attended"-Regel würde ihm die Bewertbarkeit **künftig** nehmen; (b) die
Umstellung wäre bei 0 betroffenen Zeilen zwar heute verlustfrei, aber der Verlust
träte still ein, sobald wieder eine Direkteinstellung oder ein nicht nachgezogener
Buchungsstatus dazukommt. Die ODER-Form kostet nichts (eine Bedingung in der
Policy) und macht den Fall unmöglich statt unwahrscheinlich.

**Offen, nicht blockierend:** Die Zahl `ma_ohne_attended = 1` unterscheidet nicht,
ob dieser Mitarbeiter **gar keine** Buchung hat (Direkteinstellung → erscheint in
der Nachbereitung ohnehin nie, die Liste iteriert über Buchungen eines Termins)
oder eine Buchung mit anderem Status (`no_show`/`confirmed` → Zeile ist sichtbar,
Button heute vorhanden). Nur im zweiten Fall hat die ODER-Regel eine sofortige
Live-Wirkung. Für die Abnahme genügt die Unterscheidung nicht, für das Verständnis
des einen Falls hilft sie:

```sql
SELECT e.id AS employee_id, e.rec_applicant_id,
       (SELECT COUNT(*) FROM rec_interview_bookings b
        WHERE b.rec_applicant_id = e.rec_applicant_id AND b.deleted_at IS NULL) AS buchungen,
       (SELECT GROUP_CONCAT(b.status) FROM rec_interview_bookings b
        WHERE b.rec_applicant_id = e.rec_applicant_id AND b.deleted_at IS NULL) AS status_liste
FROM rec_employees e
WHERE e.rec_applicant_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM rec_interview_bookings b
    WHERE b.rec_applicant_id = e.rec_applicant_id
      AND b.status = 'attended' AND b.deleted_at IS NULL
  );
```

**Live-Sichttest nach Deploy (nur noch B/C/D):** Zertifikat-Vorlage anlegen,
Testbewerber mit `attended` ablehnen mit Zertifikat, WhatsApp kommt an, PDF öffnet,
Fall zu, Bewerber inaktiv, kein Vertrag und kein Portallink erzeugt.
(Der §A-Sichttest entfällt — er steht in der Bewertungssystem-Spec.)

## Benannte Tradeoffs

- **Erst-Anlage-Semantik der Übernahme.** `createOrUpdate()` steigt bei
  existierendem Employee vor `:105` aus (F5). Backfill- und Re-Export-Aufrufe
  (`BackfillEmployeeFieldsFromApplicant`, `ZasReExportByBookingDate`) tragen den
  Puffer daher **bewusst nicht** mit. Wer im Puffer liegende Bewertungen für
  bereits bestehende Mitarbeiter braucht, braucht ein eigenes Command — nicht
  Teil dieses Scopes.
- **Stiller Bewertungsverlust bei Übernahme-Fehler.** Das try/catch-Muster von
  `snapshotContractDatesToHrData` schluckt Fehler in ein `Log::warning` (`:236-241`),
  damit die MA-Anlage nicht kippt. Übernimmt sich die Bewertung nicht, merkt es
  niemand außer im Log; die Werte liegen dann weiter im Puffer, aber die MA-Karte
  zeigt sie nicht. Bewusst akzeptiert: MA-Anlage schlägt schwerer als Bewertung.
- **Kein nachträgliches Ausstellen nach der Ablehnung.** Das Zertifikat hängt an
  der Ablehnen-Aktion; ist der Fall geschlossen, ist die Desk-Karte weg. Ein
  Ausstell-Einstieg auf der Bewerber-Show (für „hätte er doch eins bekommen
  sollen") ist **out of scope**.
- **Blade-Hülle nicht im Snapshot.** Gespeichert wird nur der personalisierte
  Template-Inhalt, nicht die umgebende PDF-Hülle (Layout, Stempel). Ändert sich
  die Hülle, ändert sich das Aussehen alter Zertifikate — wie heute bei Verträgen.
- **Falsche Vorlage nur per DB korrigierbar.** Wurde mit der falschen Vorlage
  ausgestellt, verhindert das Unique-Constraint (C1) ein zweites Zertifikat. Die
  Korrektur ist ein DB-Eingriff. Bewusst: die Invariante ist wertvoller als der
  Selfservice für einen seltenen Fehlgriff.
- **Wiederbewerbung zeigt die Alt-Bewertung nicht automatisch.** Der Puffer bleibt
  am alten Bewerber-Datensatz stehen. Kommt die Person mit nachgereichten
  Unterlagen als neuer Bewerber wieder (Dedup-Guard erkennt sie), ist die alte
  Bewertung nicht am neuen Datensatz sichtbar — nur über den alten auffindbar.

## Deploy

- **Zwei-Push-Struktur:** Migration zuerst, Feature danach. Grund: Paket B/C/D
  bringt eine neue Public-Route und eine neue Tabelle; ein Feature-Deploy vor der
  Migration erzeugt 500er auf einer öffentlich erreichbaren URL.
- **`composer.lock`-Bump in `meingedeck` nach jedem Push** — sonst ist der Stand
  nicht live.
- **Kein `queue:restart`.** Durch F6 widerlegt: der WA-Versand ist synchron und
  dispatcht keinen Job; auch Bewertung und Ausstellung laufen im Request. Es gibt
  in diesem Paket keinen Worker-Code, der alten Stand halten könnte. (Der
  entsprechende Punkt aus der ursprünglichen Offene-Punkte-Liste ist damit
  gestrichen.)
- **Vor Live nötig, außerhalb Code:** Zertifikat-Text als Vorlage anlegen
  (Kunde/HR), WhatsApp-Template bei Meta einreichen und genehmigen lassen, die
  beiden Team-Settings setzen.

## Betroffene Dateien

**~~Paket A~~ — entfällt.** Die betroffenen Dateien stehen in
`2026-08-05-bewertungssystem-design.md`.

**Paket B/C/D**
- Neu: `database/migrations/*_add_type_to_rec_contract_templates.php`,
  `database/migrations/*_create_rec_training_certificates_table.php`,
  `src/Models/RecTrainingCertificate.php`,
  `src/Services/IssueTrainingCertificateService.php`,
  `src/Http/Controllers/TrainingCertificatePdfController.php`,
  `resources/views/pdf/training-certificate.blade.php`, zugehörige `tests/Unit/*`.
- Ändern: `src/Services/HrDeskRoutingService.php` (`rejectCase`-Reparatur),
  `src/Livewire/HrDesk/Index.php` + Blade (Ausstell-Option, Download, Erneut senden),
  `src/Models/RecContractTemplate.php` (`type`, Konstanten, `requires_signature`-Zwang,
  `schulung.*`-Quellen), `src/Livewire/ContractTemplates/Index.php` + Blade
  (Typ-Feld, Badge, Platzhalter-Hilfe), `src/Services/SendContractsService.php`
  (`type`-Guard `:64`), `src/Livewire/DirectHire/Index.php`,
  `src/Livewire/Applicant/Show.php`, `src/Livewire/InterviewBookings/Index.php`
  (`setApplicantContractTemplate`), `src/Livewire/Contracts/Index.php`,
  `src/Tools/CreateContractTool.php`, `src/Tools/ListContractTemplatesTool.php`,
  `src/Models/RecApplicantSettings.php` (zwei WA-Settings), `routes/public.php`.
