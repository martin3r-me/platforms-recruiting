# Kampagne „Schulung voll“ — Design (Nachtrag zu „Neue Termine“)

Stand: 05.10.2026 · Status: umgesetzt auf feat/kampagne-schulung-voll · Modul: platforms-recruiting
Grundlage: `2026-08-28-neue-termine-kampagne-design.md` (Versandkette, Segmentregel, Job, Sender)

## 1. Ziel

Ist eine Schulung, die an einer Ausschreibung hängt, **ausgebucht**, soll HR mit einem Klick die Bewerber
dieser Ausschreibung anschreiben, die **noch keinen Termin** haben: per WhatsApp, mit Link auf die
Terminauswahl („es gibt freie Termine an deinem Wunschort“). Der Bewerber bucht dort eine andere Schulung
und läuft danach normal durch Onboarding und Vertrag.

Kundenwunsch (05.10.): universell für jede Schulung, Tätigkeit und Ausschreibung; nur an Leute, die Phase 1
hinter sich haben; die Daten-Vervollständigung danach muss wie gehabt greifen.

## 2. Befunde, auf denen das Design steht

- Die öffentliche Buchungsseite filtert Termine nach **Stelle (Ort)**, nicht nach Ausschreibung
  (`Public/InterviewBooking::visibleInterviews`). Ein Cateringhilfe-Bewerber in MGL sieht alle MGL-Schulungen.
  „Alternative Schulungen“ sind also schon sichtbar; die Nachricht muss nur dorthin zeigen.
- Ein **Ausschreibungs-Wechsel** passiert bei der Buchung nur, wenn der **Ort** wechselt
  (`maybeSwitchPosition`). Bucht jemand am selben Ort eine Schulung einer anderen Ausschreibung, bleibt er in
  seiner Ausschreibung. **Entscheidung 05.10.: so belassen.** Tabelle 2 zeigt je Schulung die Herkunft als
  Unterzeile pro Ausschreibung, die KPI-Dimension bleibt unangetastet.
- Die Kette nach der Buchung ist dieselbe wie bei „Neue Termine“: Reaktions-Hook → Phasen-Check → Aufstieg mit
  Status-Reset → Erstkontakt der Onboarding-Phase → `confirm_booking_on_completion`. Kein Re-Arm beim Versand
  (Kundenentscheid 28.08. gilt weiter).
- Termine tragen seit 17.08. eine Ausschreibung (`rec_interviews.rec_posting_id`, nullable). Die
  „Ohne Termin“-Zeilen des Assigners tragen die Ausschreibung des Bewerbers (`posting_id`).

## 3. Entscheidungen (05.10., mit S. Haustein)

| Thema | Entscheidung |
|---|---|
| Einstieg | Tabelle 2 der Statistik: Badge „Ausgebucht“ + Pille „N ohne Termin“ an vollen, **künftigen** Terminen mit Ausschreibung. Klick öffnet das bestehende Drill-Modal mit dem Kampagnen-Fuß. |
| Zielgruppe | Bewerber der Ausschreibung des Termins mit Zeilentyp `ohne_schulung` — **alle**, unabhängig von Ort-/Tätigkeits-/Status-Filter der Seite (termin_rows). |
| Wählbar | Nur ab dem Buchungsschritt. Template-A-Zeilen (Phase vor dem Buchungsschritt) bleiben sichtbar, sind aber **nicht wählbar**. Phase 3/4 mit Storno wie bisher (3 vorangehakt, 4 abgehakt). |
| Termin-Abo auf dem vollen Termin | anschreiben; Abo bleibt stehen (Badge „Warteliste seit …“ wie bisher). |
| Ausschreibungs-Wechsel bei Buchung am selben Ort | **nicht** in diesem Paket. |
| Template | eigener Settings-Key `campaign_full_training_wa_template_id`, Rückfall auf `campaign_booking_wa_template_id`. Nur Terminauswahl-Nachricht; Template A entfällt im Modus. |
| Re-Arm | keiner (wie 28.08.). |
| Automatik | keine — erst manuell lernen (wie 28.08.). |

## 4. Design

### 4.1 Tabelle 2 (`buildInterviewTable`)
Je Termin zusätzlich: `posting_id` (nur wenn die team-gescopte Relation sie liefert), `voll`
(`max > 0 && seat_taking >= max`, Lesart wie meter.blade.php), `kuenftig` (`starts_at > now`), `ohne_termin`
(Σ ids der `ohne_schulung`-Zeilen dieser `posting_id` aus **termin_rows**).
View: bei `voll && kuenftig` Badge „Ausgebucht“; dazu Pille (Klick) bei `ohne_termin > 0`, „0 ohne Termin“
mit Tooltip, oder „keine Ausschreibung“ mit Hinweis zum Nachtragen.

### 4.2 Drill-Scope `posting_type` (`CohortViewModel::resolveIds`)
Trifft Zeilen mit `posting_id === posting` **und** `type === type`; beides Pflicht, `posting = null` trifft
nichts (fail-closed). `drill()` löst diesen Scope gegen `termin_rows` auf. Token trägt zusätzlich
`anlass_interview` (int); `drill()` übernimmt ihn nur aus diesem Scope in die gesperrte Property
`campaignAnlassInterviewId`.

### 4.3 Freischaltung und Modus (`Statistics\Index`)
`campaignEnabled()`: Scope `type_all` **oder** `posting_type`, Typ `ohne_schulung`, kein `set`, IDs vorhanden.
`campaignNurBuchung()`: Scope `posting_type`. Im Modus: `campaignRows` läuft durch
`CampaignSegment::nurBuchungsphase()` (A-Zeilen `selectable=false, checked=false`), Template B wird aus dem
neuen Key vorbelegt (Rückfall B), Template-A-Select ausgeblendet.

### 4.4 Anlass-Karte (`campaignAnlass()`)
Team-gescopt, fail-closed (fremd/unbekannt → null): Datum, Terminart, Belegung, Ausschreibung, Stelle und die
Zahl der **Alternativen** = kommende aktive Termine (`planned`/`confirmed`) derselben **Stelle** mit freiem Platz
(unbegrenzt zählt mit), ohne den Anlass selbst. Null Alternativen → rote Karte mit Hinweis. „Dieselbe Stelle“
ist die Näherung an die Buchungsseite; die Wunschorte jedes Empfängers nachzurechnen wäre eine Query je Person.

### 4.5 Job und Sender
`SendNewDatesCampaign` bekommt `nurBuchungsphase`, `anlass`, `anlassInterviewId` (optional, Default wie bisher).
Im Modus legt der Job dieselbe Sperre über seinen Re-Check (A-Zeilen übersprungen, keine Fehlerzeile).
Der Sender erhält den Anlass als öffentliche Felder (keine Signaturänderung von `send()`); Log-Typ bleibt
`campaign_sent` (14-Tage-Schutz greift über beide Kampagnen), Text „Kampagne „Schulung voll“ gesendet“,
Details `anlass`, `anlass_interview_id`.

### 4.6 Template (Meta, Kunde hat es angelegt)
Name `schulung_freie_termine`, Utility, de. Body nur mit `{{name}}`; URL-Button dynamisch
`https://mitarbeiter.rheingedeck.de/recruiting/interviews/{{1}}`, Text „Termine ansehen“. Wortlaut: „freie
Schulungstermine an deinem Wunschort“ — kein Tätigkeits-, Orts- oder Datumsbezug im Text.

## 5. Tests
- Unit: `CampaignSegmentTest` (nurBuchungsphase), `CohortViewModelTest` (Scope posting_type, fail-closed),
  `CampaignModalStateTest` (Freischaltung + Modus), `CampaignSettingsKeysTest` (Key + Rückfall).
- Integration: `StatisticsFullTrainingCampaignTest` (Tabelle-2-Felder, Token-Auflösung gegen termin_rows,
  Anlass-Karte inkl. Alternativen, fail-closed), `SendNewDatesCampaignJobTest` (Modus überspringt A,
  Anlass am Sender), `NewDatesCampaignSenderTest` (Anlass in der Akte).
- `tools/blade-check.php` auf `interviews-table.blade.php` und `index.blade.php`.

## 6. Auslieferung
1. ff auf main, meingedeck-Bump. Keine Migration. **Kein `queue:restart` zwingend** (Job-Konstruktor erweitert,
   alte Worker verarbeiten neue Jobs mit Defaults nicht korrekt → Worker trotzdem neu starten, sobald die erste
   „Schulung voll“-Kampagne gestartet werden soll). `view:clear`.
2. Settings-Key setzen (ggf. per `JSON_SET`, Memory „Settings-Modal: Selects speichern nicht“).
3. Sichttest Prod: Tabelle 2 mit einem vollen künftigen Termin → Pille → Modal-Kopf → Testversand.

## 7. Offen / Folgepaket
- Modal-UX (Erklärzeile, Vorlagen-Vorschau statt Name, Zeilen-Chip „bekommt: …“, Button mit Kanal): eigenes
  Paket nach diesem.
- Ausschreibungs-Wechsel bei Buchung am selben Ort: nur auf ausdrücklichen Kundenwunsch, eigene Spec.
