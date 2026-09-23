# Design: Nachweise in Selbstbedienung — Mitarbeiterportal Runde 1

**Datum:** 2026-09-22
**Modul:** platforms-recruiting (kein Edit an Core/CRM/HCM)
**Status:** Design zur Freigabe
**Bezug:** Canvas 67 (Mitarbeiterportal Neuaufbau), Canvas 68 (Ein Mensch, ein Konto)

---

## 1. Ziel

Der groesste Schmerz bei RHEINGEDECK laeuft von unten nach oben: Mitarbeiter
schicken Ausweise, Immatrikulationsbescheinigungen und Aufenthaltstitel per
WhatsApp, HR fischt sie aus Chats und traegt sie von Hand ein. Runde 1 dreht
diese Richtung um.

Nach Runde 1 gilt:

- Der Mitarbeiter pflegt seine Nachweise selbst, mit Art und Gueltigkeit.
- Das System erkennt Fristen und meldet sich rechtzeitig bei ihm.
- WhatsApp verweist bei eingehenden Dokumenten auf den Upload statt zur Ablage
  zu werden.
- Wer bei RHEINGEDECK und MA arbeitet, macht das alles einmal, nicht zweimal.

**Nicht** Teil dieser Runde: HR spielt Dokumente an viele aus (Kenntnis /
Bestaetigen / Unterschreiben, Quote, Nachweisblatt). Das ist die
Gegenrichtung, sie ist in der Spec vom 2026-07-10 entworfen und wird Runde 2.

## 2. Abgrenzung

- Kein Benutzerkonto — die Anmeldung bleibt Geburtsdatum plus Ausweis-Endziffern.
  Die Anmeldung wird aber als eigene Schicht gebaut, damit das Konto aus
  Canvas 68 spaeter eingehaengt werden kann, ohne die Huelle anzufassen.
- Keine Personen-Tabelle. Die Zusammenfuehrung laeuft ueber den vorhandenen
  `person_key`.
- Keine Aenderung am ZAS-Export (siehe §7).
- Kein Unterschreiben von Vertraegen — die Formfrage zur Befristung ist offen.
- Keine Regel-Engine. Die Pflicht-Regeln bleiben, wo sie heute stehen.

## 3. Bestand (wiederverwendbar, verifiziert)

| Baustein | Fundort | Nutzung |
|---|---|---|
| Portal + Verifizierung | `Livewire/Public/EmployeePortal.php`, `routes/public.php:41` | Login-Schicht wird herausgeloest, `#[Locked]`-Props und Rate-Limit wandern mit |
| Datei-Upload | Core `ContextFileService::uploadForContext` | unveraendert |
| Datei-Whitelist | `Support/EmployeeFileSlots.php` | bleibt bis zur Export-Umstellung |
| Personen-Marker | `person_key`, `Services/Zas/PersonPairLinker`, `Console/PersonPairAudit` | Grundlage der Zusammenfuehrung |
| Geschwister-Aufloesung | `Services/Statistics/EinsatzLookup.php:59-81` | Muster fuer die Aufloesung ueber `person_key` |
| Nicht-EU-Regeln | `Support/NonEuDocumentMapping.php` | Pflicht-Regel, bleibt |
| Bescheinigungs-Regeln | `Support/SchoolCertificateFields.php` | Pflicht-Regel, bleibt |
| Lohn-Trigger | `RecApplicantSettings::PAYROLL_TRACKABLE_FIELDS`, `Observers/RecEmployeeExportObserver` | HR-Pruefung nur bei lohnrelevanten Aenderungen |
| WhatsApp-Versand | `RecEmployee::sendPortalNotification`, `WhatsAppMetaService` | Erinnerungen |
| Eingehende Nachrichten | `Listeners/HandleWhatsAppInboundForRecruiting` | Umleitung auf den Upload |

### Verifizierte Negativbefunde

- **Zustellstatus ist nicht belastbar.** `RecEmployee::sendPortalNotification`
  liefert `ok: true` direkt nach `sendTemplate()`, ohne `$message->status` zu
  pruefen. Der Dispo-Sender macht es richtig
  (`Services/Zas/Dispo/DispoConfirmationSender.php:106`). Muss vor der ersten
  Erinnerung gefixt werden.
- **Nur 5 der 16 Dateispalten haben ein Ablaufdatum.** Es fehlen unter anderem
  Fiktionsbescheinigung, Visumsblatt und Nationalpass.
- **Schul- und Immatrikulationsbescheinigung teilen sich ein Datumsfeld**
  (`school_certificate_valid_until`).
- **Der ZAS-Export liest die Dateispalten und die Ablaufdaten direkt**
  (`Services/Zas/ZasEmployeeFieldResolver.php`) — neun Gueltigkeiten und 14
  Upload-Adressen. Ersatzloses Verschieben wuerde ihn brechen.

## 4. Datenmodell

Zwei neue Tabellen.

### 4.1 `rec_proof_types` — Katalog der Nachweisarten

Konfiguration, kein Regelwerk: `code`, `label`, `has_expiry`,
`reminder_lead_days`, `has_back_side`, `sort_order`, `is_active`.

Gesetzt wird der Katalog per Seeder. Vorlaufzeiten nach Kundenvorgabe:
Aufenthaltstitel und Arbeitsgenehmigung 60 Tage, alles andere 30.

### 4.2 `rec_employee_proofs` — ein hochgeladener Nachweis

| Spalte | Zweck |
|---|---|
| `rec_employee_id` | an welcher Anstellung hochgeladen |
| `person_key` | **wem er gehoert** — hierueber wird gelesen |
| `proof_type_code` | Art aus dem Katalog |
| `file_id`, `file_back_id` | Datei(en) ueber ContextFile |
| `valid_until` | vom Mitarbeiter beim Upload eingetragen |
| `version` | Fassung, hochgezaehlt je Art und Person |
| `superseded_at` | gesetzt, wenn eine neue Fassung kommt |
| `reminded_at` | letzte Erinnerung, verhindert taegliche Wiederholung |
| `confirmed_by_user_id`, `confirmed_at` | nur fuer Aufenthaltstitel / Arbeitsgenehmigung |

Die jeweils aktuelle Fassung ist die mit `superseded_at IS NULL`. Nichts wird
geloescht — eine neue Fassung ersetzt fachlich, die alte bleibt nachweisbar.

### 4.3 Der Katalog

**Immer:** Ausweis (V/R, Ablauf), Selfie (kein Ablauf), Krankenkassenkarte
(kein Ablauf), IBAN-Nachweis / Bankkarte (kein Ablauf, neu).

**Nicht-EU** (`is_eu_citizen = false`, Regel in `NonEuDocumentMapping`):
Nationalpass (Ablauf, **neu**), Aufenthaltstitel V/R (Ablauf),
Visumsblatt (Ablauf, **neu**), Zusatzblatt Arbeitsgenehmigung V/R (Ablauf),
Fiktionsbescheinigung V/R (Ablauf, **neu**).

**Nach Beschaeftigungsart** (Regel in `SchoolCertificateFields`):
Schueler → Schulbescheinigung (Ablauf), Student → Immatrikulation (Ablauf).
Durch das neue Modell bekommt jede Art ihr eigenes Datum; das heute geteilte
Feld entkoppelt sich.

**Taetigkeitsbezogen:** Ersthelferschein (nur bei `is_first_aider`, Ablauf),
IfSG-Erstbescheinigung (Folgebelehrung nach zwei Jahren, Daten existieren
bereits als `infection_protection_first_issued_at` und werden als
`InfekGueltigBis` exportiert).

### 4.4 Pflicht-Regeln bleiben im Code

Welcher Mensch welchen Nachweis braucht, ergibt sich aus `is_eu_citizen`,
`employment_type` und `is_first_aider`. Diese Regeln stehen heute in
`NonEuDocumentMapping` und `SchoolCertificateFields`, sind reine Logik und
unit-getestet. Sie werden **nicht** in eine Tabelle ueberfuehrt — das waere der
Anfang einer Regel-Engine und gehoert in ein eigenes Vorhaben.

## 5. Zusammenfuehrung RG und MA

Gelesen wird ueber den `person_key`: Nachweise, Vollstaendigkeit und
Aufgabenliste gelten je **Person**, nicht je Anstellung. Ein Upload zaehlt fuer
beide Anstellungen.

**Sichtbarkeitsregel.** Der Marker steuert ab hier, was ein angemeldeter Mensch
sieht — Ausweis, Bankdaten, Vertraege. Deshalb gilt die zusammengefuehrte
Ansicht nur bei **harter** Paarung: von HR im Audit bestaetigt, oder zusaetzlich
gleiche Handynummer. Ein automatisch gestempeltes Paar ohne zweites Merkmal
bekommt beim ersten Login die Frage „Du bist auch bei MA angestellt — ist das
dein Datensatz?"; erst nach Bestaetigung wird zusammengefuehrt. Protokolliert,
von HR trennbar.

**Vor dem Start** laeuft `recruiting:person-pair-audit` ueber den Bestand, HR
arbeitet die Zweifelsfaelle ab. Jeder ungeklaerte Fall bleibt doppelt.

Einsaetze, Vertraege und Dokumente werden mit der Gesellschaft gekennzeichnet.
Stammdaten bleiben in dieser Runde je Anstellung (siehe §10).

## 6. Aufgaben und Fristen

Die Aufgabenliste wird **abgeleitet**, nicht gespeichert: Katalog plus
Pflicht-Regel plus vorhandene Nachweise ergeben „was fehlt, was laeuft ab".
Damit kann sie nicht auseinanderlaufen.

Gespeichert wird nur `reminded_at` am Nachweis.

Ein taeglicher Lauf findet Nachweise, deren `valid_until` innerhalb der
Vorlaufzeit liegt, und verschickt genau **eine** Erinnerung per WhatsApp. Danach
steht der Punkt als Aufgabe im Portal, bis er erledigt ist. Dieselbe Mechanik
traegt die IfSG-Folgebelehrung.

## 7. ZAS-Export bleibt unveraendert

Der Upload schreibt **doppelt**: die neue Nachweis-Zeile und die alte Spalte auf
`rec_employees`. Portal, Aufgaben und Fristenlauf lesen nur neu; Export,
HR-Ansicht und Datei-Endpunkt lesen unveraendert alt.

Damit ist der Export in Runde 1 nicht betroffen. Das Umstellen der Lesestellen
und das Entfernen der alten Spalten ist eine eigene spaetere Runde.

Die drei neuen Gueltigkeiten — Fiktionsbescheinigung, Visumsblatt, Nationalpass
— haben keine alte Spalte und gehen deshalb **nicht** in den Export. Sie werden
im Portal erhoben und dienen unseren Erinnerungen. Offener Posten an ZAS/Michel:
ob `FiktionBis` spaeter exportiert werden soll. Neue Exportspalten werden
vorher angekuendigt.

## 8. Portal

Vier Bereiche mit fester Leiste, Markenfarben und Schriften aus dem abgenommenen
Entwurf, eigenes Guest-Layout (kein Eingriff in platforms-core).

- **Start** — naechster Einsatz, darunter die Aufgabenliste
- **Einsaetze** — beide Anstellungen, je Karte mit Gesellschaft gekennzeichnet
- **Dokumente** — offene Aufgaben, eigene Nachweise, Vertraege als PDF
- **Profil** — Stammdaten in Gruppen, Vollstaendigkeitsanzeige

Der Upload erfasst Datei und Gueltigkeit in einem Schritt. Ein Datum in der
Vergangenheit wird abgewiesen, mehr als zehn Jahre in der Zukunft gewarnt.

**Anmeldeschicht:** Die Identitaetsaufloesung wird aus `EmployeePortal`,
`EmployeeAssignments` und `DispoAttachmentController` in eine eigene Schicht
gezogen. Die `#[Locked]`-Properties, der Versuchszaehler und die
Eskalationssperre wandern unveraendert mit.

## 9. HR-Sicht

- **Lohnrelevante Aenderungen** laufen ueber den bestehenden Trigger
  (`payroll_data_changed_at`, Sidebar-Abzeichen, `Employees/PayrollChanges`).
  Kein neuer Arbeitsvorrat noetig.
- **Nachweis-Uploads** gelten sofort als erledigt — ausser Aufenthaltstitel und
  Arbeitsgenehmigung, an denen die harte Einsatzsperre haengt; dort bestaetigt
  HR das Datum.
- Eine Liste „neu eingegangen" und eine Liste „offen je Person".

## 10. Bewusst offen

- Stammdaten (Adresse, IBAN) bleiben je Anstellung. Optional und empfohlen:
  Spiegelung auf die `person_key`-Geschwister ueber Eloquent, damit der
  Export-Observer beide Anstellungen markiert (6–8 h).
- **Stichtag-Kollision:** Der spaetere Voll-Import aus ZAS wuerde selbst
  gepflegte Felder ueberschreiben. Muss vor dem Start entschieden werden.
- Massenversand an viele (Runde 2), Vertragsunterschrift (Formfrage),
  Regelmatrix, Einsatz-Trigger, Zahlungsstatus (eigene Vorhaben).

## 11. Risiken

| Risiko | Gegenmittel |
|---|---|
| Falsche Paarung zeigt fremde Daten | harte Paarung, Rueckfrage beim ersten Login, Protokoll, trennbar (§5) |
| Komponentenumbau reisst den Auth-Fix vom September auf | `#[Locked]`-Props und Rate-Limit wandern mit, bestehende Tests muessen gruen bleiben |
| Stille Blade-Brueche | `tools/blade-check.php` statt `php -l`, `SharedPartialContractTest`, Durchklick auf iPhone **und** Android vor Gate 5 |
| Export bricht | Doppelschreiben (§7) |
| Erinnerung gilt als zugestellt, obwohl Meta ablehnt | Zustellstatus-Fix vor der ersten Erinnerung |
| Worker laufen mit altem Code | `queue:restart` nach jedem Deploy |

## 12. Tests

- Pure Unit: Katalog, Pflicht-Regel je Beschaeftigungsart und Staatsangehoerigkeit,
  Fristenberechnung, Fassungswechsel
- Integration: Upload schreibt beide Seiten; Aufloesung ueber `person_key` zeigt
  Geschwister-Nachweise; Erinnerung genau einmal; Export liefert nach dem Upload
  unveraenderte Werte
- Regression: bestehende Portal-Auth-Tests, `SharedPartialContractTest`
- Migration: Trockenlauf ueber den Bestand, Abgleich Anzahl Dateispalten gegen
  erzeugte Nachweis-Zeilen

## 13. Aufwand

Spec 4–6 · Portal-Huelle inkl. Guest-Layout 14–22 · Nachweis-Modell, Katalog,
Migration, Doppelschreiben 14–18 · Upload-Strecke 6–8 · Aufgabenliste 4–6 ·
Fristenlauf und Erinnerungen 4–5 · WhatsApp-Umleitung 4–6 · Zusammenfuehrung
und Kennzeichnung 8–12 · HR-Sicht 5–7 · Zustellstatus-Fix 1–2 · Tests und
Abnahme 6–8.

**Summe 70–100 Stunden.**
