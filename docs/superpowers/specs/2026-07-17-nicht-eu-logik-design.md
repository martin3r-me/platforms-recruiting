# Nicht-EU-Logik: Prüfung nach der Schulung — Design

**Datum:** 2026-07-17
**Modul:** platforms-recruiting
**Status:** Entwurf (zur Review) — Code-Referenzen gegen main `1611c71`

## Problem

Nicht-EU-Bewerber werden heute beim P3-Abschluss (Onboarding) sofort auf den
HR-Schreibtisch geroutet (`is_on_hr_desk=true`, `auto_pilot=false`) und vom
`LegalStatusGate` still von Schulungs-Remindern UND Vertrags-/Portallink-Versand
ausgeschlossen, bis HR prüft und freigibt. Danach sendet der Schulungsleiter.
Folge: Nicht-EU hängen VOR der Schulung, obwohl ihre Pflicht-Unterlagen beim
P3-Absenden bereits vollständig hochgeladen sind (bedingte Pflichtfelder,
verifiziert an "Düsseldorf allgemein", Phase 27: Vorderseiten + Ablaufdaten sind
`is_mandatory` mit `visibility_config` auf `eu_burger=false` + Dokumenttyp).

## Ziel (Produktentscheidungen — fix)

1. Nicht-EU laufen bis einschließlich Schulung **wie EU-Bürger** durch
   (P3-Routing entfällt, Schulungs-Reminder gehen raus).
2. Abzweig **bei "Teilgenommen"**: Statuswechsel auf `attended` routet
   ungeprüfte Nicht-EU (oder EU-Status unbeantwortet) auf den HR-Schreibtisch.
3. Der Schulungsleiter kann für diese Fälle NICHT senden — die Zeile in der
   Nachbereitung zeigt neutral **"Liegt beim HR-Schreibtisch"** (Eingaben gesperrt).
4. HR sendet **selbst vom Schreibtisch**: Zuschlag, Vertragslaufzeit, AV-Default,
   Zusatzvertrag, "Portallink & Verträge versenden" — exakt die
   Nachbereitung-Semantik. **Erfolgreicher Versand schließt den Fall**
   (approved, Bewerber verlässt den Schreibtisch). Der bestehende
   "Freigeben"-Button bleibt als Ausweichpfad (prüfen → freigeben ohne zu
   senden → Schulungsleiter sendet wie früher).
5. Bestand wird per einmaligem, idempotentem Befehl migriert (Fallzahl begrenzt).

## Geteilte Fakten (gegen Code verifiziert — für alle Tasks bindend)

**F1 — Completion-Kette:** `SendContractsService::send()` markiert Verträge als
`sent` und ruft selbst `checkAutoPilotCompletion()` (Service, Schritt "6)").
`checkAutoPilotCompletion` hat den Carve-out (`RecApplicant.php:417-426`): bei
`auto_pilot=false` laufen die Phase-Completion-Hooks TROTZDEM (kein Advance).
**Die Mitarbeiter-Erzeugung feuert also im Send-Aufruf am Desk selbst**;
`approveCase` (`HrDeskRoutingService.php`) macht danach den Advance
(`auto_pilot=true`, `is_on_hr_desk=false`, erneuter Check — im Code explizit
für dieses Szenario kommentiert). Der Bulk holt den Employee direkt nach
`send()` und schickt den Portallink (`InterviewBookings/Index.php:566-570`).

**F2 — Hook-Satz von `triggerPhaseCompletionHooks()` (läuft am Desk zweimal:
aus send() und aus approveCase) — exakt DREI Hooks, alle doppel-lauf-sicher:**
1. `syncEuCitizenFromExtraField()` (unbedingt): Early-Return bei synchronem
   Wert (`if ($legalStatus->is_eu_citizen === $bool) return;`) — Zweitlauf ist
   No-op, kein evaluateAndRoute, kein Case-Schreiben.
2. `confirm_booking_on_completion` (config-gated; in der contract_sent-Phase
   NICHT gesetzt): Query-Builder `where('status','booked')->update('registered')`
   — Zweitlauf trifft 0 Zeilen, Log nur bei `updated > 0`.
3. `creates_employee_on_completion`: `CreateEmployeeFromApplicantService::createOrUpdate`
   — dokumentiert idempotent, Fehler gekapselt (`employee_create_failed`-Log).
Keine WhatsApp, kein Zähler, kein Event im Satz. Für die contract_sent-Phase
kollabiert der Satz effektiv auf EU-Sync-No-op + Employee-Hook → **grün**.

**F3 — Schulungs-Buchungslink-Falle (Voraussetzungs-Check!):**
`sendInterviewBookingNotification()` hängt NICHT im Hook-Satz, sondern im
Last-Phase-Branch von `checkAutoPilotCompletion` (`RecApplicant.php:497-510`),
den erst der approveCase-Lauf (auto_pilot=true) erreicht. Gating:
`send_booking_notification_on_completion` aus der Phase-Config; **Legacy-Fallback
sendet, wenn der Key FEHLT und keine Folge-Phase existiert.** Auf "Düsseldorf
allgemein" ist der Key in Phase 4 explizit `false` (verifiziert). **Verifikations-
Pflicht in der Migration/Abnahme: ALLE Stellen mit contract_sent-Endphase müssen
den Key explizit `false` haben** — sonst bekäme der Bewerber beim Desk-Abschluss
eine Schulungs-Buchungslink-WhatsApp.

**F4 — Send-Idempotenz:** `SendContractsService::send()` ist idempotent
(DB-Transaction; Reuse nicht-stornierter Verträge; `sent_at` nur wenn leer;
Vertrags-WA nur bei `nowSentCount > 0`). Der Portallink-Schritt ist es NICHT von
sich aus — der Bulk schützt ihn über den Eligibility-Filter
`hasAnyContractSent()` (`InterviewBookings/Index.php:522`). Der neue
Dispatch-Service übernimmt diesen Guard (siehe §4).

**F5 — Routing-Single-Source:** `REASON_NON_EU_CITIZEN` wird ausschließlich in
`HrDeskRoutingService::evaluateAndRoute()` geroutet (`:43`) bzw. auto-geschlossen
(`:53`). Einziger externer Einstieg: `RecApplicantLegalStatus::setEuCitizen()`
(`:134`); der Shim `handleEuStatusChange()` hat keine externen Aufrufer mehr.
Kein Core-, MCP- oder Backfill-Pfad routet Nicht-EU.

## Architektur

### §1 Trigger-Umbau: P3 raus, "Teilgenommen" rein

- **Raus:** In `evaluateAndRoute()` entfällt NUR die Route-Hälfte von Regel 1
  (`routeIfNotAlreadyOpen(REASON_NON_EU_CITIZEN)` bei `is_eu_citizen === false`).
  Die `elseif`-Hälfte (Auto-Close bei Korrektur auf EU) **bleibt**, ebenso
  Regel 2 (Deutschkenntnisse) unverändert. `setEuCitizen()`/EU-Sync bleiben
  unangetastet — der Datenpunkt wird weiter gepflegt.
- **Rein:** Neue Observer-Klasse (eigene Datei, `safelyRun`-Muster wie
  `RecInterviewWaitlistObserver`), Hook auf `RecInterviewBooking::saved`:
  Statuswechsel auf `attended` → pure Entscheidung → bei "routen"
  `routeIfNotAlreadyOpen(REASON_NON_EU_CITIZEN, Notiz "Nach Schulung:
  Rechtsstatus prüfen + Verträge versenden")`. Routing-Wirkung unverändert
  (aufs Desk, `auto_pilot=false` — wartet jetzt auf HR).
- **Pure Funktion** (PHPUnit, Konvention):
  `NonEuPostTrainingGate::shouldRoute(?string $oldStatus, string $newStatus, bool $hasLegalStatus, ?bool $isEuCitizen, bool $isChecked): bool`
  — true nur bei Transition ≠attended→attended UND (isEuCitizen === false ODER
  (hasLegalStatus UND isEuCitizen === null)) UND !isChecked.
  Testmatrix enthält explizit **`null → attended`** (Signatur lässt es zu,
  z.B. frisch erzeugte Buchung direkt als attended).
  Bewerber OHNE legalStatus-Datensatz (Bestand vor Feature) routen NICHT —
  identisch zur Bestandslogik des LegalStatusGate ("kein Datensatz = nie
  geblockt").
- Observer-Registrierung im Provider-Boot neben
  `RecInterviewWaitlistObserver::register()` (`RecruitingServiceProvider.php:151`).
- Kein Event-Muting auf Bookings (verifiziert: `saveQuietly|updateQuietly|withoutEvents`
  = 0 Treffer; `attended` wird nur über Model-Pfade gesetzt: `updateStatus`
  `$booking->update`, MCP-Tool `$booking->save`; der Bulk setzt kein attended).
  **Echtes Event-Feuern ist vor Deploy nicht verifizierbar → Live-Smoke (§6).**

### §2 Gate-Anpassungen

- **Schulungs-Reminder:** Der LegalStatus-Skip in `SendInterviewReminders.php:72`
  entfällt ersatzlos.
- **Nachbereitung (`InterviewBookings/Index` + Blade):** Sperr-/Anzeige-Kriterium
  wechselt von "ungeprüft" auf "**offener Nicht-EU-Fall**" (Query pro Bewerber:
  offener `RecHrDeskCase` mit `REASON_NON_EU_CITIZEN`). Zeile mit offenem Fall:
  neutraler Badge "Liegt beim HR-Schreibtisch", Zuschlag/Laufzeit/Senden gesperrt.
  Vor der Teilnahme (kein Fall): Zeile normal. **Zweite Verteidigungslinie:** die
  `isLegalStatusUnchecked`-Filter in `sendContractsBulk`/`sendPortalLinkBulk`/
  `setApplicantContractTemplate` bleiben bestehen (ungeprüfte ohne Fall werden
  beim Senden weiterhin übersprungen, nie beliefert).
- **`HrDeskApprovalGate` bleibt unverändert** (Freigeben nur nach Prüfung).

### §3 HR-Desk-Karte: Sende-Bereich

Auf der `non_eu_citizen`-Fallkarte, unter der bestehenden Rechtsstatus-Sektion
(Als geprüft markieren + Zusatzvertrag-Dropdown), neuer Vertrags-Bereich mit
Nachbereitung-Semantik: AV-Vorlage (Auto-Zuweisung AV-Default falls leer, wie
`assignDefaultTemplateIfMissing`), Zuschlag-Eingabe, Vertragslaufzeit von/bis
(gleiche Defaults via `RecContract::resolveContractDates`), Zusatzvertrag-Anzeige.
Button **"Portallink & Verträge versenden"** — aktiv erst wenn: als geprüft
markiert UND Zuschlag gesetzt UND Vertragsbeginn gesetzt (identisch zur
Bulk-Pflicht). Ablauf beim Klick: `ContractDispatchService::sendForApplicant()`
(§4) → bei Erfolg `approveCase()` (bestehender, getesteter Schließ-Pfad: Fall
approved, Desk-Entlassung, `auto_pilot=true`, Phase-Advance). Fehler beim Senden
→ Fall bleibt offen, Flash-Meldung, kein halber Zustand (F4-Guards).
Der "Freigeben"-Button bleibt unverändert daneben bestehen (Ausweichpfad).
Sichtbarkeit des Sende-Bereichs: nur wenn der Bewerber ein `attended`-Booking hat
(Fälle aus dem neuen Trigger haben das immer; Alt-Fälle vor der Schulung zeigen
den Bereich nicht — für sie gilt der Ausweichpfad).

### §4 `ContractDispatchService::sendForApplicant()`

Extraktion der Pro-Bewerber-Sequenz des Bulk-Buttons in einen Service, den
Bulk (Nachbereitung) und Desk-Karte gemeinsam nutzen:

1. Guard: `hasAnyContractSent()` → wenn ja, **nichts senden** (Selbstheilung:
   Desk-Aufrufer schließt dann nur noch den Fall). Deckt auch den Fall
   "Versand ok, approveCase danach abgebrochen, HR klickt erneut".
2. AV-Default zuweisen falls `contract_template_id` leer.
3. Validierung: Zuschlag gesetzt, Vertragsbeginn gesetzt.
4. `SendContractsService::send($applicant, $userId, $fields, skipNotification: true)`
   — inkl. IFSG + Zusatzvertrag + Employee-Erzeugung (F1/F2).
5. Employee laden, `sendPortalNotification()`.
6. Ergebnis-Struct zurück (gesendet/übersprungen/Fehler je Schritt).

Der Bulk (`sendPortalLinkBulk`) wird auf den Service umgestellt (Verhalten
byte-gleich; seine Eligibility-Filter bleiben davor). Bewusste Grenze: Guard 1
überspringt bei bereits gesendeten Verträgen auch den Portallink — der Randfall
"Verträge via MCP gesendet, Portallink nie" (MCP-`SendContractsTool` ist
ungegated, vorbestehend) wird über die bestehenden manuellen Portal-Pfade
gelöst, nicht hier.

### §5 Bestands-Migration `recruiting:migrate-non-eu-cases`

Einmalig, idempotent, `--dry-run`-Option, Zähler-Ausgabe. Vier Regeln:

1. Offener Nicht-EU-Fall + ungeprüft + **kein** `attended`-Booking → Fall
   schließen (Notiz "Migriert: Prüfung erfolgt nach der Schulung"),
   `auto_pilot=true` + `is_on_hr_desk=false` (sofern kein anderer offener Fall).
2. Offener Nicht-EU-Fall + **geprüft** → regulär `approveCase` (Gate passiert).
3. Ungeprüfter Bewerber MIT legalStatus-Datensatz und `is_eu_citizen === false`
   ODER `null` (gleiche Semantik wie der neue Trigger) + `attended`-Booking +
   **kein** offener Nicht-EU-Fall → Fall anlegen (Notiz wie §1) → erscheint im
   neuen Desk-Sende-Bereich. (Ohne die null-Abdeckung würden heute rot
   markierte null-Fälle nach dem Umbau unsichtbar hängen.)
4. Offener Nicht-EU-Fall + `is_eu_citizen === true` (inzwischen korrigiert) →
   Auto-Close mit Notiz (Semantik der bestehenden `elseif`-Hälfte).

Zustandsraum vollständig (8 Zustände): Offen+ungeprüft+attended → **bewusst
unangetastet** (liegt richtig, neuer Sende-Bereich bedient ihn); kein Fall +
geprüft (+/- attended) → normaler Flow (Schulungsleiter sendet); kein Fall +
ungeprüft + kein attended → neuer Trigger übernimmt; EU ohne Fall → normal.

### §6 Tests & Verifikation

- **Pure-Unit:** `NonEuPostTrainingGate::shouldRoute`-Matrix (inkl.
  `null→attended`, attended→attended [kein Re-Fire], EU/checked/kein-Datensatz-
  Fälle); Sende-Freigabe-Bedingung der Desk-Karte als pure Funktion.
- **Harness (sqlite, wie Warteliste-Muster):** Observer-Guard-Wahrheitstabelle
  gegen den echten Code; Migrations-Regeln 1-4 + Nicht-Anfassen-Fälle;
  Dispatch-Service-Sequenz inkl. `hasAnyContractSent`-Selbstheilung.
- **Config-Check (F3):** alle Stellen mit contract_sent-Endphase haben
  `send_booking_notification_on_completion: false` explizit gesetzt.
- **Live-Smoke nach Deploy:** Test-Nicht-EU durch den kompletten Flow
  (P3 → Reminder kommt → attended → erscheint auf Desk → prüfen → Zuschlag →
  senden → eine Vertrags-Portal-Kette, Fall zu, Employee da). `queue:restart`
  nach Deploy PFLICHT (Reminder-Command + Observer laufen im Worker).

## Bewusste Abgrenzungen

- **Deutschkenntnisse-Fälle sperren die Nachbereitung nicht — vorbestehend,
  bewusst außerhalb dieses Scopes.** (Die alte rote Sperre rechnete rein auf dem
  Legal-Status und hat `no_german_knowledge` nie erfasst.)
- `handleEuStatusChange()` ist ohne externe Aufrufer (Dead Code) — **jetzt nicht
  anfassen**, Cleanup-Notiz für später.
- MCP-`SendContractsTool` prüft den Legal-Status nicht (vorbestehend) — bewusst
  nicht Teil dieses Umbaus; Notiz für Follow-up.
- Doppelklick-Race auf der Desk-Karte: gleiche Akzeptanzklasse wie der heutige
  Bulk (`wire:loading`-Disable + F4-Guards; kein DB-Lock).
- `is_eu_citizen === null` wird am Trigger wie Nicht-EU behandelt (konsistent
  zum Gate); praktisch selten (Pflichtfeld P3).
- Direkteinstellung unberührt (eigener Flow ohne Schulungs-Buchung/`SendContractsService`).

## Ausführung

Subagent-driven (Muster Warteliste V1/V2). **`ContractDispatchService::
sendForApplicant()` als ERSTER Implementierungs-Task** — §3 (Desk-Karte) und
§5-Regel-2/-3-Verbraucher konsumieren ihn. In dessen Consumes-Vertrag werden
die geteilten Fakten F1-F4 wörtlich gepinnt.

## Betroffene Dateien

- **Neu:** `src/Services/NonEuPostTrainingGate.php`, `src/Services/ContractDispatchService.php`,
  `src/Observers/RecInterviewBookingComplianceObserver.php`,
  `src/Console/Commands/MigrateNonEuCases.php`, zugehörige `tests/Unit/*`.
- **Ändern:** `src/Services/HrDeskRoutingService.php` (Regel-1-Route-Hälfte raus),
  `src/Console/Commands/SendInterviewReminders.php` (Skip raus),
  `src/Livewire/InterviewBookings/Index.php` + Blade (Kriterium + Bulk auf Service),
  `src/Livewire/HrDesk/Index.php` + Blade (Sende-Bereich),
  `src/RecruitingServiceProvider.php` (Observer + Command-Registrierung).
