# Design: HR-Bereich „Mitarbeiter“ (Schreibtisch-Reiter) + Konto-Kasten in der Akte

**Datum:** 2026-10-12
**Modul:** platforms-recruiting (kein Edit an Core/CRM/HCM)
**Branch:** setzt auf `feat/ma-konto` auf (HEAD c8e03d1b) — braucht `rec_persons`, `KontoWriter`, Einsatz-Prüfung, Dokumente, Vertrag aus der Akte
**Status:** Design zur Freigabe (Ausbau A + B, freigegeben von Sebastian; beide Stufen in einem Paket)

---

## 1. Ziel

Seit dem Mitarbeiterkonto (Canvas 68) gibt es Zustände, die nur noch über Kommandos
oder gar nicht sichtbar sind: Notfall-Anträge („Nummer und Passwort verloren“), Sperren,
Einladungen, die nie eingelöst wurden, und Menschen, die erinnert wurden und nicht
reagieren. HR hat dafür heute keinen Bildschirm — deshalb ist der Notfallweg (Weg 4)
abgeschaltet.

Nach diesem Paket gilt:

- **Der HR-Schreibtisch bekommt einen Reiter „Mitarbeiter“** mit vier Listen:
  1. **Konto-Anträge** — offene Nummernwechsel-Anträge; HR kann stoppen, Notfall-Anträge
     auch sofort freigeben.
  2. **Gesperrt** — wer gerade nicht ins Portal kommt, mit Grund; HR entsperrt mit
     Pflichtnotiz.
  3. **Erinnert, aber keine Reaktion** — Markus' Eskalationsstufe aus Canvas A3:
     erinnert vor mehr als X Tagen, Punkt immer noch offen; sortiert nach dem nächsten
     Einsatz. „Kontaktiert“ (mit Notiz, Zeile ruht N Tage) und „Erneut erinnern“.
  4. **Pilot-Überblick** — wer im neuen Portal ist, wer eingeladen, registriert, wann
     zuletzt angemeldet.
- **Die Mitarbeiterakte bekommt einen Konto-Kasten**: Status (eingeladen / registriert /
  letzte Anmeldung / gesperrt) und die Knöpfe „Einladung neu“, „Konto zurücksetzen“,
  „Entsperren“ — alle mit Pflichtnotiz und Verlaufseintrag.
- **Der Notfallweg bekommt einen eigenen Team-Schalter** (Vorgabe aus). Der bestehende
  Server-Riegel `recruiting.konto.weg4_aktiv` bleibt als zweiter Riegel davor.
- **Jede HR-Handlung an Konto, Sperre oder Erinnerung steht im Verlauf** (neue Tabelle
  `rec_hr_vermerke`, §7.1) — mit Wer, Wann, Was, Notiz.

Leitsatz: **ein Mensch = eine Zeile**, auch mit RG- und MA-Akte (Personen-Klammer
`rec_persons`, für Akten ohne Personen-Zeile `PersonScopeResolver`).

### Was bewusst NICHT drin ist

- **Kein WhatsApp-Versand der Einladung.** Es gibt keinen Einladungs-Sender und keine
  genehmigte Vorlage (Gate E, `KontoEinladen`-Docblock). „Einladung neu“ und „Konto
  zurücksetzen“ zeigen Link und 8-Zeichen-Code **einmal** auf dem Bildschirm, HR gibt
  ihn weiter — genau wie heute `recruiting:konto-einladen` (offene Frage 1).
- **Kein HR-Eintragen einer neuen Nummer (Weg 5) in der Oberfläche.** Bleibt Kommando
  `recruiting:konto-zuruecksetzen --person= --nummer=`.
- **Kein „Sperren“-Knopf.** Nur Entsperren. `locked_at` hat heute keinen Schreiber (§2),
  und ein Sperren-Knopf ist eine eigene Entscheidung (wer darf, wann automatisch).
- **Keine Arbeitserlaubnis-Fälle.** Die bleiben als `REASON_WORK_PERMIT` im Reiter „Fälle“.
- **Keine Mail an HR** bei neuem Notfall-Antrag. Sichtbar wird er über das Zähler-Abzeichen
  am Reiter und die Liste; die Log-Zeile `recruiting.konto.nummernwechsel_beantragt` bleibt.
- **Keine Änderung am ZAS-Export.** Alle Schreibzugriffe an `rec_employees` laufen über den
  Query-Builder, kein `zas_changed_at` (§9.4).
- **Keine neue Rolle.** Wer heute den HR-Schreibtisch sieht, sieht auch den neuen Reiter.

---

## 2. Befund aus dem Code (was es heute gibt — und was anders ist als gedacht)

Die freigegebene Skizze geht an vier Stellen von etwas aus, das der Code so nicht hat.
Das Design folgt dem Code:

| Annahme | Befund | Folge fürs Design |
|---|---|---|
| „gesperrt nach Fehlversuchen = `locked_at` / `code_versuche`“ | `rec_persons.locked_at` hat **keinen Schreiber** in `src/` (nur Leser: `KontoWriter::offeneZeile`, `personFuerEinladung`, `darfSichAnmelden`). Die Fehlversuchs-Sperre ist die **Drossel in `PortalAuth`**: 5 Fehlversuche je Nummer → 15 Min. Sperre im **Cache** (`employee_portal_locked:nummer:<xxh128>`), dazu 30 je IP/Stunde. `code_versuche` macht nur den laufenden Code wertlos, nicht das Konto. | Liste „Gesperrt“ kennt **fünf** Gründe (§3.2), die Drossel wird über eine neue Lese-Methode in `PortalAuth` gezeigt; `code_versuche` ist kein Sperrgrund. |
| „Nummernwechsel beantragt“ ist ein zweiter Antragstyp | `wechsel_*` setzt **nur** Weg 4 (`beantrageNummerwechselMitCode`, `wechsel_quelle = 'notfall'`). Weg 1/2 wechseln sofort. | Eine Liste, Spalte „Quelle“. „Freigeben“ nur bei `quelle = notfall`; jede andere (künftige) Quelle nur „Stoppen“. |
| „Einladung erneut senden“ | Es gibt keinen Sender; der Klartext existiert nur einmal (Hash in der DB). | Einmal-Anzeige im Modal (§4.3). |
| „Audit-Eintrag“ | Es gibt keine Verlaufstabelle an Person oder Anstellung. `rec_auto_pilot_logs` hängt hart an `rec_applicant_id` (FK, cascade) — ZAS-Bestand hat keine Bewerbung. | Neue Tabelle `rec_hr_vermerke` (§7.1). |

Weitere Fakten, auf denen das Design steht:

- **Weg 4 ist aus** über `config('recruiting.konto.weg4_aktiv')` (`.env`
  `RECRUITING_KONTO_WEG4_AKTIV`, Vorgabe `false`), geprüft in
  `KontoAnmelden::notfallAnfordern()` (hinter der IP-Bremse) und `notfallBestaetigen()`.
  Abgeschaltet antwortet die Seite zeichengleich wie bei falschen Nachweisen.
- **Stoppen** gibt es schon: `KontoWriter::stoppeNummerwechsel()` (löscht den Antrag, setzt
  `notfall_gesperrt_bis = now()+7 Tage`, Ruling GD-13). **Freigeben** gibt es nicht.
- **Anwenden** macht der Stundenlauf `recruiting:konto-zuruecksetzen --faellig`
  (`wendeNummernwechselAn`), danach Hinweis an die alte Nummer
  (`NummernwechselHinweisSender`). Zwischen Lesen und Löschen gibt es **keine Sperre** —
  ein HR-Stopp im selben Augenblick ist heute ein Wettlauf (§5.2).
- **Dispo-Sperre** (Stufe 3): `rec_employees.portal_locked_at/_reason`, gesetzt von
  `DispoEmployeeGateway::lockPortal`, gelesen über `DispoIdentityResolver::groupsFor`
  (`PortalAuth::istDispoGesperrt`), entsperrt heute in `Employees/Show::unlockPortal()`
  **ohne Notiz und ohne Verlauf**.
- **Erinnerungs-Stempel:** `rec_persons.aufgaben_gemeldet_at` + `aufgaben_signatur`
  (Signatur wird bei leerer Punktliste geräumt, ET-16 — „Signatur steht“ heißt also
  „beim letzten Lauf noch offen“), `rec_employee_proofs.reminded_at`,
  `rec_document_recipients.notified_at`, Akte-Verträge `rec_contracts.sent_at` mit
  Extrafeld `herkunft = akte` (`VertragsHerkunft::ausAkte`). Pause je Kanal:
  `EinsatzBezug::PAUSE_TAGE = 7`.
- **Einstellungen:** `RecApplicantSettings::DEFAULT_SETTINGS`, Modal
  `ApplicantSettingsModal` — Schalter als Checkbox, Zahlen als `x-ui-input-text
  type="number"` (wie `minimum_wage_hourly`), **nie Select** (Select-Speicherfehler).

---

## 3. Reiter „Mitarbeiter“ am HR-Schreibtisch

`HrDesk/Index` bekommt `#[Url] public string $tab = 'faelle'` mit zwei Reitern:
**Fälle** (alles wie heute) und **Mitarbeiter** (neu). Der Reiter rendert eine eigene
Kind-Komponente `<livewire:recruiting.hr-desk.mitarbeiter lazy />` — `Index.php` hat schon
663 Zeilen und ist im Modul nicht instanziierbar; die neue Komponente bekommt ihr Team
selbst und ist testbar (Muster `Dispo\HrDeclines`, das schon so eingebettet ist).

Am Reiter steht ein **Zähler-Abzeichen**: offene Konto-Anträge + Gesperrt (ohne
Drossel-Fälle, die enden nach 15 Min. von selbst). Es ist das, was die 24 Stunden von
Weg 4 erst zu einem Stopp-Recht macht.

In der Kind-Komponente: `#[Url] public string $liste = 'antraege'` mit vier Unter-Reitern,
je mit Zahl. Alle Listen: max. 50 Zeilen je Seite, Namenssuche, Name verlinkt auf die Akte
(bei RG+MA beide Akten als Chips „RG · MA“). Nummern immer nur **…1234** (letzte vier,
`NummernwechselTafel::kurz`).

### 3.1 Konto-Anträge

Quelle: `KontoWriter::offeneNummernwechsel($teamId)` (schon heute ohne Filter auf
gesperrt/stillgelegt, Befund G5). Spalten:

| Person | alt | neu | beantragt | wirksam ab | Quelle | Zustand | Aktion |
|---|---|---|---|---|---|---|---|
| Name (Akten-Chips) | …1234 | …5678 | 12.10., 09:14 | 13.10., 09:14 (noch 23 Std.) | „Nummer + Passwort verloren“ | `NummernwechselTafel::zustand()` | Stoppen · Freigeben |

- **Stoppen** (jede Quelle): Modal mit Pflichtnotiz → `HrKontoAktionen::stoppeAntrag()`
  → `KontoWriter::stoppeNummerwechsel()` (setzt die 7-Tage-Notfallsperre wie heute).
  Ergebnistext: „Antrag gestoppt. Die Nummer bleibt …1234. Der Notfallweg ist für diese
  Person bis 19.10. gesperrt.“
- **Freigeben** (nur `quelle = notfall`, nur Zustand „offen“): Modal mit Pflichtnotiz und
  Warnsatz („Die Nummer wechselt sofort auf …5678. Wer diesen Antrag gestellt hat, kann
  danach mit ‚Passwort vergessen‘ ein neues Passwort setzen. Nur freigeben, wenn Sie mit
  der Person gesprochen haben.“) → `KontoWriter::gibNummernwechselFrei()` (§5.1), danach
  CRM-Nachzug, Protokoll, Hinweis an die alte Nummer — dieselben Pflichten wie beim
  Stundenlauf.
- Zustand „gesperrt“/„stillgelegt“: nur Stoppen (wie heute: anwendbar ist er nie).
- Zustand „Notfallweg aus“ (neu, §6): Antrag liegt, wird vom Stundenlauf nicht angewendet;
  HR stoppt oder gibt frei.
- Darunter, eingeklappt: **gestoppte Anträge der letzten 30 Tage** aus `rec_hr_vermerke`
  (art `antrag_gestoppt`) und aktive Notfallsperren (`notfall_gesperrt_bis > now`) — damit
  HR sieht, wenn dieselbe Person es weiter versucht (`recruiting.konto.notfall_gesperrt`
  steht nur im Log).

### 3.2 Gesperrt

Eine Zeile je Mensch, Gründe als Chips; ein Mensch kann mehrere haben. Die Menge entsteht
in `HrMitarbeiterListen::gesperrt($teamId)` aus fünf Quellen:

| Grund (Chip) | Quelle | Wirkung | Aktion |
|---|---|---|---|
| **Dispo-Sperre** („Portal gesperrt seit … — <Grund>“) | `rec_employees.portal_locked_at` an einer **aktiven** Anstellung der Person (Gruppe wie `PortalAuth::istDispoGesperrt`) | kein Portal, kein Konto-Login | „Portal entsperren“ → `PortalEntsperrung::fuerAnstellung()` (§5.3) |
| **Konto gesperrt** | `rec_persons.locked_at` | kein Konto-Login, keine Einladung, kein Code | „Konto entsperren“ → `KontoWriter::entsperreKontoDurchHr()` |
| **Anmeldung gebremst** („5 Fehlversuche, frei ab 14:32“) | Cache-Sperre der Nummer in `PortalAuth` (neu lesbar, §5.4) | 15 Min. kein Login | „Bremse lösen“ → `PortalAuth::loeseNummernBremse()` |
| **Notfallweg gesperrt bis …** | `rec_persons.notfall_gesperrt_bis > now` | nur Weg 4 zu, Login geht | „Notfallsperre aufheben“ → `KontoWriter::hebeNotfallSperreAuf()` |
| **Nummer mehrdeutig** („Nummer ist auch in einem anderen Team vergeben — Anmeldung nicht möglich“) | gleiche `phone` in einer lebenden `rec_persons`-Zeile eines **anderen** Teams (GD-2, fail closed) | kein Konto-Login | keine (Plattform-Support); nennt **kein** fremdes Team, keine fremde Person |

Jede Aktion: Modal, **Pflichtnotiz** (5–500 Zeichen), Verlaufseintrag, Log-Zeile.
Bewusst nicht gelistet: `code_versuche` am Anschlag (nur der laufende Code ist wertlos,
ein neuer Code setzt zurück), die IP-Bremse (gehört zu keinem Menschen), die 404-Sperre
der Registrierungsseite (Token-gebunden, 1 Std.), Ausgeschiedene ohne aktive Anstellung
(gewollt: Canvas 1793), Arbeitserlaubnis (Reiter „Fälle“).

Drossel-Abfrage: für alle Personen des Teams mit Nummer die Schlüssel bilden und **ein**
`Cache::many()` — bei `CACHE_STORE=database` ist das eine Abfrage (`whereIn`), keine je
Person. Die Liste lädt diesen Teil erst beim Öffnen des Unter-Reiters.

### 3.3 Erinnert, aber keine Reaktion

Markus' A3-Eskalation: „wer nach X Tagen nicht reagiert, ruft HR an“. Eine Zeile je Mensch,
**sortiert nach dem nächsten Einsatz** (aufsteigend, ohne Einsatz ans Ende), dann nach dem
ältesten Erinnerungsdatum. X = Team-Einstellung `hr_erinnert_tage` (Vorgabe **5**).

Vier Quellen, jede als Chip mit Alter („Aufgaben · erinnert vor 6 Tagen“):

| Quelle | Kandidat (SQL, Team über `rec_employees.team_id`) | „noch offen“ |
|---|---|---|
| **Aufgaben** (Einsatz-Prüfung) | `rec_persons.aufgaben_gemeldet_at <= now − X` **und** `aufgaben_signatur IS NOT NULL` | Signatur steht = beim letzten Lauf offen (ET-16); für die angezeigten Zeilen zusätzlich live `OffenePunkte::fuer($bote)` → Punkt-Labels im Tooltip. Fällt die Live-Liste leer aus, verschwindet die Zeile. Steht die Nachricht (`aufgaben_nachricht_id`) auf `failed`: Chip rot „nicht zugestellt“. |
| **Nachweis** | `rec_employee_proofs.reminded_at <= now − X`, `superseded_at IS NULL` (kein neuerer Upload ersetzt ihn), Anstellung aktiv | Zeile ist die aktuelle Version und weiter fällig (`ProofReader`-Status) |
| **Dokument** | `rec_document_recipients.notified_at <= now − X`, `withdrawn_at IS NULL` | `!DokumentStatus::istErledigt()` |
| **Vertrag (Akte)** | `rec_contracts` an der Anstellung, Status nicht `completed`/`cancelled`, `sent_at <= now − X`, `VertragsHerkunft::ausAkte()` | wie `VertragLeser::offenePunkte` |

„Nächster Einsatz“: eine Abfrage über `rec_dispo_assignments` (`missing_since IS NULL`,
`datum >= heute`) für alle Kandidaten-Anstellungen, je Person das früheste Datum nach
`EinsatzBezug::naechster()` — dieselbe Regel wie `OffenePunkte::naechsterEinsatz`.

**Aktionen**

- **„Kontaktiert“** (je Zeile): Modal mit Pflichtnotiz → Vermerk `kontaktiert` mit
  `ruhe_bis = now + N Tage` (Team-Einstellung `hr_kontaktiert_ruhe_tage`, Vorgabe 3).
  Die **ganze Zeile** ruht bis dahin; Schalter „Ruhende zeigen“ blendet sie grau ein.
  Ist danach noch etwas offen, kommt sie von selbst zurück.
- **„Erneut erinnern“** (je Chip, Notiz optional): verschickt über den **bestehenden**
  Sender, und nur, wenn dessen eigene Regeln es erlauben:

  | Chip | Sender | Stempel danach (Query-Builder) | Pause (wie heute) |
  |---|---|---|---|
  | Aufgaben | `AufgabenSender::sende($bote, OffenePunkte::fuer($bote), 'erinnerung')` unter der Laufsperre der Einsatz-Prüfung (`EinsatzPruefung::LAUF_SPERRE`, wird `public`) | `aufgaben_gemeldet_at = now`, `aufgaben_nachricht_id` (auch nach Fehlschlag, wie ET-23) | 7 Tage ab `aufgaben_gemeldet_at` |
  | Nachweis | `ProofReminderSender::send()` — nur `portal_v2_since` (Ruling C2) | `reminded_at = now` nur bei `STATUS_SENT` | 7 Tage ab `reminded_at` |
  | Dokument | `DokumentService::erneutSenden()` (stempelt selbst `notified_at`) | — | 7 Tage ab `notified_at` |
  | Vertrag | `VertragHinweisSender::sende($anstellung)` | — (Vertrag hat keinen Hinweis-Stempel) | 7 Tage ab `max(sent_at, letzter Vermerk erneut_erinnert)` |

  Innerhalb der Pause ist der Knopf grau mit „erneut erinnern ab 14.10.“ — mit X = 5 also
  ab Tag 7 (offene Frage 2). Ergebnis jedes Versuchs, auch Fehlschlag mit Sender-Status,
  steht im Vermerk (`ergebnis`); Fehlschlag als gelber Hinweis („Nicht verschickt:
  Vorlage fehlt“), kein grüner.

Bote = erste aktive Anstellung der Person mit `portal_v2_since` (dieselbe Regel wie
`EinsatzPruefung::bote()`; die Methode wandert dafür nach `src/Support/Bote.php`, das
Kommando ruft sie von dort). Ohne Boten: Knopf grau „nicht im neuen Portal“.

### 3.4 Pilot-Überblick

Basis: Anstellungen des Teams mit `portal_v2_since IS NOT NULL`, gruppiert nach
`rec_person_id` (ohne Personen-Zeile: eigene Zeile mit Chip „keine Personen-Zeile —
`recruiting:personen-anlegen`“). Spalten:

| Person | Akten | im Pilot seit | Einladung | registriert | letzte Anmeldung (Konto) | altes Portal zuletzt |
|---|---|---|---|---|---|---|
| Name | RG · MA | min(`portal_v2_since`) | `invited_at` / „abgelaufen“ (`invite_expires_at < now` und `invite_used_at` leer) / „—“ | `registered_at` | `letzte_anmeldung_at` | max(`portal_verified_at`, `portal_last_seen_at`) |

Filter-Chips mit Zahl: Alle · nicht eingeladen · eingeladen, nicht registriert ·
Einladung abgelaufen · registriert, nie angemeldet · seit 14 Tagen nicht angemeldet.
Keine Aktionen in der Liste — die Knöpfe liegen an genau **einer** Stelle, dem
Konto-Kasten (§4); die Zeile verlinkt dorthin (`?tab=stammdaten#konto`).

Zustandsableitung (eingeladen/abgelaufen/registriert/…) in einer reinen Klasse
`src/Support/KontoStatus.php` — Liste und Konto-Kasten benutzen dieselbe.

---

## 4. Konto-Kasten in der Mitarbeiterakte

Eigene Kind-Komponente `Employees\KontoKasten` (`#[Locked] public int $employeeId`),
eingebettet in `employees/show.blade.php` oberhalb der Felder, Anker `#konto`. `Show.php`
(1485 Zeilen) wächst nicht.

### 4.1 Anzeige

- Kopf: „Konto“ + Status-Chip aus `KontoStatus`: *nicht eingeladen* · *eingeladen am …
  (gilt bis …)* · *Einladung abgelaufen* · *registriert am …* · *zurückgesetzt am …
  (wartet auf neues Passwort)*.
- Zeilen: Benutzername …1234 · letzte Anmeldung (Konto) · altes Portal zuletzt · im
  neuen Portal seit (oder „noch altes Portal“).
- Sperrgründe aus derselben Quelle wie §3.2, je mit eigenem Entsperren-Knopf. Der rote
  Kasten „MA-Portal-Zugang gesperrt“ in `show.blade.php` (Z. ~209) bleibt als Anzeige,
  sein Knopf öffnet jetzt das Notiz-Modal des Kastens; `Show::unlockPortal()` entfällt
  (ersetzt durch `PortalEntsperrung`, §5.3).
- Offener Notfall-Antrag: Hinweis mit Link auf §3.1.
- **Verlauf**: die letzten 10 Vermerke der Person (beide Akten), mit Benutzer und Notiz.
- Ohne Personen-Zeile: „Diese Akte hat noch keine Personen-Zeile — Konto-Funktionen erst
  nach `recruiting:personen-anlegen`.“ Alle Knöpfe aus.
- Stillgelegte Person (`merged_into_person_id`): Hinweis auf die Zeile, an der die
  Anstellungen jetzt hängen; Knöpfe aus.

### 4.2 Knöpfe

| Knopf | sichtbar wenn | Wirkung |
|---|---|---|
| **Einladung neu** | nicht registriert, nicht gesperrt | `KontoWriter::ladeEin()` (ersetzt jede frühere Einladung) |
| **Konto zurücksetzen** | registriert, nicht gesperrt | `KontoWriter::setzeKontoZurueckDurchHr()` (§5.1): Passwort weg, laufender Code weg, neue Einladung |
| **Entsperren** (je Grund) | Sperrgrund vorhanden | wie §3.2 |

Vor „Einladung neu“ und „Zurücksetzen“ gilt **derselbe Riegel wie im Kommando**: ohne
Nummer, ohne hinterlegtes Geburtsdatum (`KontoWriter::personenOhneGeburtsdatum`) oder mit
bekanntem „kein WhatsApp“ wird abgelehnt, mit genau diesem Grund. Die Grund-Logik wandert
aus `KontoEinladen::grund()` in `src/Support/EinladungsRiegel.php`; Kommando und Kasten
rufen dieselbe.

### 4.3 Einmal-Anzeige der Einladung

Nach Erfolg zeigt ein Modal Link (`route('recruiting.public.konto-anlegen', $token)`) und
Code, mit dem Satz aus dem Kommando: „Der Code ist ein Geheimnis: nur an den Menschen
selbst, nicht in eine Gruppen-Unterhaltung. Er gilt sieben Tage und wird nicht noch einmal
angezeigt.“ Die Property ist `#[Locked]`, wird beim Schließen geleert, steht nie im Log
und nie im Vermerk. Wer sie verliert, drückt den Knopf erneut (neue Einladung entwertet
die alte — bewusst, wie im Kommando).

---

## 5. Schreibwege

Ein Schreiber je Gegenstand bleibt die Regel. Die Oberfläche ruft nur
`HrKontoAktionen` (neu, `src/Services/HrKontoAktionen.php`); der prüft Team und Person,
ruft den zuständigen Schreiber, schreibt den Vermerk und die Log-Zeile — für Schreibtisch
und Akte **dieselbe** Stelle.

### 5.1 `KontoWriter` — neue und geänderte Methoden

Alle über `DB::table`, Kontofelder bleiben außerhalb von `$fillable`
(`MassenzuweisungGeschlosseneWeltTest` bleibt grün).

- **`gibNummernwechselFrei(int $personId, string $erwartetBeantragtAt): ?array{alt,neu}`** —
  in `DB::transaction`: Zeile mit `lockForUpdate()` lesen; nur wenn `wechsel_quelle =
  notfall`, Zeile offen (`offeneZeile`-Regel) und `wechsel_beantragt_at` **gleich dem
  Wert, den HR gesehen hat** — sonst `null` („Der Antrag hat sich inzwischen geändert“).
  Dann `PersonLinker::setzeNummer` + `loescheWechselAntrag` in derselben Transaktion.
  Nach dem Commit: `zieheCrmKontakteNach`, `protokolliereWechsel(…, 'hr_freigabe')`.
  Der Hinweis an die alte Nummer geht von `HrKontoAktionen` aus (wie im Kommando).
  Die Fälligkeit wird bewusst **nicht** geprüft — das ist der Zweck.
- **`wendeNummernwechselAn()`** — dieselbe Transaktion + `lockForUpdate()` + Neu-Lesen;
  CRM-Nachzug und Protokoll nach dem Commit. Verhalten sonst unverändert.
- **`stoppeNummerwechsel()`** — liest ebenfalls mit `lockForUpdate()` in einer
  Transaktion. Kein Erwartungswert: Stoppen nimmt nur weg und ist immer sicher. Rückgabe
  `false` = „war schon weg“ (angewendet, freigegeben oder gestoppt).
- **`setzeKontoZurueckDurchHr(int $personId): string`** — `offeneZeile`; `password_hash`,
  `code_hash`, `code_expires_at`, `code_zweck`, `code_neue_nummer` → `null`,
  `code_versuche = 0`; dann `ladeEin()`; Rückgabe Klartext-Token. `registered_at` bleibt
  (Beleg). Ab sofort scheitert das alte Passwort (`pruefeAnmeldung` rechnet gegen den
  Leerlauf-Hash). Kein Passwort durch HR (Weg-5-Regel: kein Generalschlüssel).
- **`entsperreKontoDurchHr(int $personId): bool`** — `locked_at = null` nur wenn gesetzt
  und nicht stillgelegt.
- **`hebeNotfallSperreAuf(int $personId): bool`** — `notfall_gesperrt_bis = null`.

### 5.2 Der Wettlauf Stoppen/Freigeben gegen den Stundenlauf

Heute liest `--faellig` die Liste, wendet an und löscht den Antrag erst danach; ein
HR-Stopp dazwischen meldete „gestoppt“, während die Nummer trotzdem wandert. Mit §5.1
nehmen alle drei Wege dieselbe Zeilensperre (InnoDB), der zweite wartet, liest neu und
findet den Antrag nicht mehr. Ergebnis für HR in jedem Fall wahr: „Antrag war schon
angewendet — die Nummer steht jetzt auf …5678“ bzw. „schon gestoppt“. Ein Vermerk wird
nur bei Wirkung geschrieben.

### 5.3 `PortalEntsperrung` (neu, `src/Services/PortalEntsperrung.php`)

Die Dispo-Entsperrung aus `Show::unlockPortal()` zieht um: `groupFor($employeeId)` aus
`DispoIdentityResolver` (genau die Menge, die `PortalAuth` fragt, GD-9),
`DB::table('rec_employees')->whereIn(...)->update(['portal_locked_at' => null,
'portal_locked_reason' => null])` — Query-Builder, kein `updated_at`, kein Marker.
Rückgabe: Anzahl entsperrter Akten (für den Text „… auch für 1 verknüpfte Akte“).

### 5.4 `PortalAuth` — Drossel lesbar machen

Der Schlüssel bleibt privat in `PortalAuth` (eine Stelle, die ihn bildet):

- `gebremsteNummern(array $nummern): array<string,bool>` — bildet je Nummer
  `lockoutKey(nummernSchluessel(...))`, ein `store()->many()`.
- `loeseNummernBremse(string $nummer): void` — `clearFailures(nummernSchluessel(...))`.
  Die IP-Bremse bleibt unberührt.

### 5.5 `HrKontoAktionen` — Ablauf jeder Aktion

1. Team aus `Auth::user()->currentTeam` (nie aus dem Request).
2. Person laden: `rec_persons.team_id = $teamId` **und** es gibt eine `rec_employees`-Zeile
   mit `rec_person_id = $id` und `team_id = $teamId`. Sonst „nicht gefunden“ (keine
   Unterscheidung fremd/nicht vorhanden). Für Zeilen ohne Person (§3.3): Anstellung mit
   `team_id = $teamId`.
3. Notiz prüfen (Pflicht: getrimmt 5–500 Zeichen).
4. Schreiber rufen (§5.1–5.4) bzw. Sender (§3.3).
5. Bei Wirkung: Vermerk (§7.1) + `Log::notice('recruiting.hr.konto_aktion', [team_id,
   person_id, employee_id, art, user_id, ergebnis])` — **ohne** Notiz, ohne Nummer.

---

## 6. Notfallweg-Schalter

Zwei Riegel, beide müssen offen sein:

1. **Server-Riegel** `config('recruiting.konto.weg4_aktiv')` — bleibt wie er ist (`.env`,
   Vorgabe aus). Er ist der Deploy-Riegel und Not-Aus für alle Teams.
2. **Team-Schalter** `konto_notfall_weg_aktiv` (neu, Vorgabe `false`) im
   Einstellungs-Modal, Abschnitt „Mitarbeiterkonto“, als **Checkbox** mit Text: „Notfallweg
   ‚Nummer und Passwort verloren‘ erlauben. Anträge erscheinen unter HR-Schreibtisch →
   Mitarbeiter → Konto-Anträge und werden nach 24 Stunden wirksam, wenn niemand stoppt.
   Nur einschalten, wenn verabredet ist, wer die Liste täglich ansieht.“ Ist der
   Server-Riegel zu, steht darunter grau: „Auf dem Server noch abgeschaltet
   (RECRUITING_KONTO_WEG4_AKTIV) — der Haken wirkt erst danach.“

Prüfstellen:

- `KontoAnmelden::notfallAnfordern()`: Reihenfolge bleibt Bremse → Server-Riegel →
  Person auflösen. **Neu:** nach `anmeldefaehigePersonFuerNummer()` der Team-Schalter des
  Teams der Person (`rec_persons.team_id`), zusammen mit `darfNotfallWeg` — aus = kein
  Versand, **dieselbe** Antwort, Log `recruiting.konto.weg4_abgeschaltet` mit
  `ebene = team`. Die öffentliche Seite hat kein Team; deshalb erst nach der Auflösung.
- `notfallBestaetigen()`: dieselbe Prüfung für die Person aus der Sitzung.
- `--faellig`: neue Weiche über `NummernwechselTafel::zustand($zeile, $weg4FuerTeam)` →
  Zustand „Notfallweg aus, wartet auf HR“; wird übersprungen und gezählt wie G5
  (kein exit 1). Ausschalten lässt also nichts still wirksam werden; HR stoppt oder gibt
  frei.
- Speichern des Modals: kippt der Wert, `Log::notice('recruiting.konto.notfallweg_schalter',
  [team_id, user_id, an])`.

Ein-Stelle-Regel: die Frage „ist Weg 4 für Person X an?“ beantwortet
`KontoWriter::notfallWegAn(int $personId): bool` (Server-Riegel UND Team-Schalter);
`KontoAnmelden::weg4Aktiv()` bleibt nur für den Vor-Check ohne Person.

---

## 7. Datenmodell

### 7.1 Neue Tabelle `rec_hr_vermerke`

**Warum eine Tabelle:** Verlauf und Ruhe brauchen einen Ort an Person *oder* Anstellung,
auch ohne Bewerbung. `rec_auto_pilot_logs` hängt per FK an `rec_applicants` (fehlt beim
ZAS-Bestand), `rec_hr_desk_cases` ist ein Fall-Modell mit Zählern (ein „kontaktiert“ als
Fall verfälschte die Reiter-Zahlen), Spalten an `rec_persons` hielten nur den letzten
Stand und keine Geschichte, und `rec_employees` scheidet wegen ZAS aus.

| Spalte | Typ | Zweck |
|---|---|---|
| `id` | bigint | |
| `team_id` | unsignedBigInteger, index | Scope |
| `rec_person_id` | unsignedBigInteger, null, index | Mensch (Normalfall) |
| `rec_employee_id` | unsignedBigInteger, null, index | Akte (ohne Personen-Zeile, oder Akte, aus der gehandelt wurde) |
| `art` | string(40) | `antrag_gestoppt`, `antrag_freigegeben`, `konto_entsperrt`, `portal_entsperrt`, `bremse_geloest`, `notfallsperre_aufgehoben`, `einladung_neu`, `konto_zurueckgesetzt`, `kontaktiert`, `erneut_erinnert` |
| `bezug` | string(60), null | `aufgaben`, `nachweis:<id>`, `dokument:<recipient_id>`, `vertrag:<contract_id>`, `nummernwechsel` |
| `notiz` | text, null | Pflicht bei allen Arten außer `erneut_erinnert` (dort optional) |
| `ergebnis` | string(40), null | Sender-Status (`sent`, `failed`, `nicht_konfiguriert` …) bzw. `ok` |
| `ruhe_bis` | timestamp, null | nur `kontaktiert` |
| `user_id` | unsignedBigInteger, null | wer |
| `created_at` | timestamp, useCurrent | wann |

Indizes: `(team_id, rec_person_id, created_at)`, `(team_id, rec_employee_id,
created_at)`, `(rec_person_id, art, ruhe_bis)`. Kein FK (Muster
`rec_conversation_handled`). **Nur anfügen**: Modell `RecHrVermerk` mit `UPDATED_AT =
null`, kein Bearbeiten, kein Löschen in der Oberfläche. Kein `updated_at`.
Migration mit `hasTable`-Wache, `down()` = drop.

### 7.2 Neue Spalte `rec_persons.sitzungen_ungueltig_ab`

`timestamp nullable`, eigene Migration (`hasColumn`-Wache, `down()` = dropColumn).
Gesetzt nur von `HrKontoAktionen` (Zurücksetzen, Entsperren nach Notfallsperre), gelesen
nur von `PortalAuth::sitzungGueltig()` (§9.6). Schreibzugriff per Query-Builder.

### 7.2 Einstellungen (`RecApplicantSettings::DEFAULT_SETTINGS`)

| Schlüssel | Vorgabe | Eingabe | Lesen |
|---|---|---|---|
| `konto_notfall_weg_aktiv` | `false` | Checkbox | `(bool)` |
| `hr_erinnert_tage` | `5` | `x-ui-input-text type="number" min=1 max=60` | `max(1, min(60, (int)))` |
| `hr_kontaktiert_ruhe_tage` | `3` | `x-ui-input-text type="number" min=1 max=30` | `max(1, min(30, (int)))` |

Neuer Abschnitt „Mitarbeiterkonto & Erinnerungen“ im Modal. Keine Selects.

### 7.3 Keine Änderung an

`rec_persons`, `rec_employees`, `rec_employee_proofs`, `rec_document_recipients`,
`rec_contracts` — nur neue Leser und die bestehenden Stempel.

---

## 8. Komponenten

| Datei | neu/geändert | Inhalt |
|---|---|---|
| `src/Livewire/HrDesk/Index.php` + View | geändert | `#[Url] $tab`, Reiterleiste, Abzeichen, bettet Kind-Komponente ein |
| `src/Livewire/HrDesk/Mitarbeiter.php` + `resources/views/livewire/hr-desk/mitarbeiter.blade.php` | neu | vier Listen, Modals, Aktionen → `HrKontoAktionen` |
| `src/Livewire/Employees/KontoKasten.php` + View | neu | Konto-Kasten (§4) |
| `src/Livewire/Employees/Show.php` + View | geändert | Kasten einbetten, `unlockPortal()` raus, roter Kasten öffnet Kasten-Modal |
| `src/Services/HrKontoAktionen.php` | neu | Scope, Notiz, Schreiber, Vermerk, Log |
| `src/Services/HrMitarbeiterListen.php` | neu | Lese-Abfragen der vier Listen + Abzeichen-Zahl |
| `src/Services/PortalEntsperrung.php` | neu | Dispo-Entsperrung (aus Show) |
| `src/Services/KontoWriter.php` | geändert | §5.1 + `notfallWegAn()` |
| `src/Services/PortalAuth.php` | geändert | §5.4 |
| `src/Livewire/Public/KontoAnmelden.php` | geändert | Team-Schalter (§6) |
| `src/Console/Commands/KontoZuruecksetzen.php` | geändert | `zustand()` mit Team-Schalter |
| `src/Console/Commands/KontoEinladen.php` | geändert | Grund-Logik → `EinladungsRiegel` |
| `src/Console/Commands/EinsatzPruefung.php` | geändert | `LAUF_SPERRE` public, `bote()` → `Support/Bote` |
| `src/Support/KontoStatus.php`, `EinladungsRiegel.php`, `Bote.php`, `SperrGruende.php` | neu | reine Regeln, ohne Framework testbar |
| `src/Support/NummernwechselTafel.php` | geändert | Zustand „Notfallweg aus“ |
| `src/Models/RecHrVermerk.php` + Migration | neu | §7.1 |
| `src/Models/RecApplicantSettings.php`, `ApplicantSettingsModal` + View | geändert | §7.2, Log beim Kippen |

**Blade-Hausregeln:** kein `@if` in Komponenten-Attributen, keine `:required="… ?? …"`,
Zustände vorher in `@php … @endphp`-Blöcken berechnen, Direktiven nie an Wortzeichen
kleben; Prüfung mit `tools/blade-check.php` (nicht `php -l`). Umlaute im UI-Text.
Knöpfe mit `wire:loading.attr="disabled"` und `wire:target`.

---

## 9. Sicherheit

1. **Team-Scope überall.** Jede Liste beginnt mit `team_id = currentTeam` (Personen über
   `rec_persons.team_id` **und** Anstellung im Team; Nachweise/Dokumente/Verträge über
   `rec_employees.team_id`, nie über eine nullable `team_id` der Nebentabelle). Jede
   Aktion prüft neu (§5.5), egal was die Liste angezeigt hat. Personen mit
   `team_id = null` erscheinen nirgends (fail closed).
2. **Livewire.** Kontext offener Modals (`aktionPersonId`, `aktionEmployeeId`, `aktion`,
   `aktionBezug`, `erwartetBeantragtAt`, `einladungsLink`) ist `#[Locked]` und wird nur
   serverseitig beim Öffnen gesetzt; `KontoKasten::$employeeId` ist `#[Locked]`. Methoden-
   Parameter (`oeffne(int $personId, …)`) gelten als Eingabe und werden geprüft.
   Erlaubte `aktion`-Werte als geschlossene Liste.
3. **Freigeben ist der gefährliche Knopf.** Nur Quelle `notfall`, nur Zustand offen, nur
   mit dem gesehenen `beantragt_at`, nur mit Notiz. Er öffnet kein Konto: danach braucht
   der Mensch weiterhin Code an die neue Nummer **plus** Geburtsdatum (Weg 3).
4. **ZAS.** Kein Eloquent-`save()` an `rec_employees`; Entsperrung und Stempel über
   `DB::table`. Kontoaktionen sind keine fachliche Änderung (Klassen-Docblock
   `KontoWriter`).
5. **Geheimnisse.** Einladungs-Klartext nur im Modal; nie in Log, Vermerk, Flash oder
   URL-Query. Nummern nur vierstellig. Notizen nur escaped (`{{ }}`), nicht ins Log.
6. **Laufende Sitzungen werden beendet** (Entscheidung Sebastian 12.10.: ein
   zurückgesetztes Konto darf keinen Fremden eingeloggt lassen). Mechanik:
   - Neue Spalte `rec_persons.sitzungen_ungueltig_ab` (timestamp, nullable; Migration
     §7). `HrKontoAktionen` setzt sie bei **Konto zurücksetzen** und bei **Entsperren
     nach Notfallsperre** auf `now()` (nicht bei „Einladung neu" — die ändert kein
     Geheimnis; nicht bei Dispo-Entsperrung — die öffnet, sie schließt nicht).
   - Die Portal-Sitzung speichert statt `true` den **Anmeldezeitpunkt**
     (`session()->put(PortalAuth::sessionKey($id), now()->toIso8601String())`), an
     allen drei Schreibstellen: `KontoAnmelden` (~:455), `PortalShell` (~:207),
     `EmployeePortal` (~:210). Ein Altwert `true` gilt als Zeitpunkt 0 (vor jeder
     Ungültigkeit) — bestehende Sitzungen bleiben bis zum ersten Zurücksetzen gültig.
   - **Eine Prüfstelle:** `PortalAuth::sitzungGueltig(int $employeeId): bool` liest den
     Sitzungswert und `sitzungen_ungueltig_ab` der Person (über die Anstellung →
     `rec_person_id`; ohne Person: gültig wie heute). Ist der Anmeldezeitpunkt ≤
     `sitzungen_ungueltig_ab`, wird der Sitzungsschlüssel verworfen und `false`
     geliefert. Alle `session()->has(PortalAuth::sessionKey(…))`-Stellen rufen
     stattdessen diese Methode: `PortalShell::mount` (~:160) und
     `berechtigterMitarbeiter()`, `EmployeePortal` (~:157), `KontoAnmelden`-Weiche,
     `DokumentZugriff`, `VertragPdfController`, Download-Controller der Nachweise.
     Eine zweite Prüfung mit eigener Logik ist verboten — sonst gibt es wieder eine
     Seite, die den Fremden weiter bedient.
   - Die Dispo-Sperre wirkt wie heute sofort (wird je Aufruf geprüft). Der alte
     Token-Weg (`/mitarbeiter/…`) läuft über dieselbe Prüfstelle und wird damit ebenfalls
     beendet.
7. **„Nummer mehrdeutig“** verrät dem Team nur, *dass* die Nummer anderswo existiert —
   keinen Namen, kein Team. Nötig, weil sonst niemand erklären kann, warum die Anmeldung
   scheitert.
8. **Rechte.** Unverändert: wer den HR-Schreibtisch sieht, darf hier handeln. Jede
   Handlung trägt `user_id` im Vermerk.

---

## 10. Tests

Integration mit Capsule/SQLite (`tests/Integration`, Konvention 08/2026), Livewire über
`ComputedWiring`. Reihenfolge-Falle beachten (kein `order-by=random`, Dispatcher setzen).

**Listen und Scope**
- Team A sieht in allen vier Listen keine Person, keinen Nachweis, kein Dokument, keinen
  Vertrag von Team B — auch wenn Team B dieselbe Telefonnummer hat.
- Person mit RG- und MA-Akte erscheint in jeder Liste genau einmal; Akte ohne
  `rec_person_id` erscheint einmal als eigene Zeile.
- Konto-Anträge: gesperrte/stillgelegte Zeile sichtbar, nur „Stoppen“ angeboten.
- Gesperrt: je Grund eine Zeile/Chip (Dispo an inaktiver Anstellung → **nicht** gelistet,
  wie GD-9); Drossel über eine `Cache::many`-Abfrage (Abfragezähler); mehrdeutige Nummer
  ohne Nennung des anderen Teams.
- Erinnert: Grenze genau X Tage (X−1 nicht, X ja); Signatur geräumt → weg; Nachweis mit
  neuerer Version (`superseded_at`) → weg; Dokument unterschrieben → weg; Vertrag nicht
  aus der Akte → nicht gelistet; Sortierung nach nächstem Einsatz, ohne Einsatz ans Ende;
  kontaktiert → ruht N Tage, danach wieder da.
- Pilot: Filter-Zahlen stimmen mit Zeilen; „abgelaufen“ genau an `invite_expires_at`.

**Aktionen**
- Fremdes Team: `oeffne()`/`bestaetige()` mit Person/Anstellung aus Team B → „nicht
  gefunden“, nichts geschrieben, kein Vermerk.
- Fremde Person im eigenen Team per `$wire.set('aktionPersonId', …)` → Livewire wirft
  (Locked); per Methoden-Parameter → Scope-Prüfung greift.
- Notiz leer/4 Zeichen → abgelehnt; 500+ → abgelehnt.
- Stoppen setzt `notfall_gesperrt_bis`, Vermerk `antrag_gestoppt`, kein `rec_employees`-
  `updated_at`.
- Freigeben: Nummer an Person und allen Anstellungen gewechselt, CRM-Nachzug aufgerufen,
  Hinweis an alte Nummer versucht, Antrag weg; mit abweichendem `beantragt_at` → nichts.
  Quelle ≠ notfall → abgelehnt. Zielnummer im Team vergeben → Antrag bleibt, Meldung ohne
  volle Nummer.
- **Wettlauf:** `wendeNummernwechselAn` läuft, danach HR-Stopp → `false`, Meldung „schon
  angewendet“, kein Vermerk; HR-Stopp, danach `--faellig` → nichts angewendet; HR-Freigabe,
  danach `--faellig` → kein zweiter Wechsel, kein zweiter Hinweis. (SQLite kennt keine
  Zeilensperre — getestet wird die Neu-Lese-Logik; die MySQL-Sperre per `--pretend`-Blick
  und Review.)
- **Doppelklick:** zweimal `bestaetige()` für Stoppen/Entsperren/Bremse → ein Vermerk;
  zweimal „Erneut erinnern“ → ein Versand (zweiter Klick fällt in die Pause), zweimal
  „Einladung neu“ → zwei Einladungen gewollt, zwei Vermerke, nur die zweite gültig.
- Zurücksetzen: altes Passwort scheitert sofort, laufender Code ungültig, Registrierung
  mit neuem Token + Geburtsdatum klappt; Person ohne Geburtsdatum → abgelehnt mit Grund.
- Einladungs-Klartext steht in keinem Log-Kanal und keinem Vermerk (Log-Attrappe mit
  `clearResolvedInstance`).
- Erneut erinnern je Quelle: richtiger Sender, Stempel nur nach Erfolg (Nachweis) bzw.
  auch nach Fehlschlag (Aufgaben, ET-23); innerhalb der 7-Tage-Pause abgelehnt; ohne
  `portal_v2_since` abgelehnt; Aufgaben-Erinnerung respektiert die Laufsperre der
  Einsatz-Prüfung.
- Dispo-Entsperrung aus Liste und Akte: dieselbe Gruppe, `zas_changed_at` unverändert.

**Notfallweg-Schalter**
- Server aus + Team an → kein Versand. Server an + Team aus → kein Versand, Antwort
  zeichengleich mit „falsche Nachweise“ (Vergleich des gerenderten Zustands). Beide an →
  Versand. Team A an, Person aus Team B (Team aus) → kein Versand.
- `notfallBestaetigen` mit Sitzung von vor dem Abschalten → kein Antrag.
- `--faellig` mit Team-Schalter aus → Zustand „Notfallweg aus“, übersprungen, exit 0.

**Reine Klassen (Unit):** `KontoStatus`, `SperrGruende`, `EinladungsRiegel`, `Bote`,
`NummernwechselTafel::zustand` — jede Verzweigung.

**Mutationsproben** (Checkliste „grünes Nichts“): Team-Filter einer Liste entfernen →
rot; `lockForUpdate`-Neu-Lesen entfernen → Wettlauf-Test rot; Notiz-Pflicht entfernen →
rot; Team-Schalter-Abfrage in `notfallAnfordern` entfernen → rot; Pausen-Prüfung beim
Erneut-Erinnern entfernen → rot.

---

## 11. Deploy

Voraussetzung: `feat/ma-konto` ist gemerged und mit `migrate` + `personen-anlegen`
ausgerollt (Deploy-Liste in `project_konto_anmeldeschicht_gate_d`). Dieses Paket kommt
danach als eigener Merge.

1. Merge (ff) auf main, **meingedeck bumpen**.
2. Forge-Deploy **mit `migrate`** (eine Migration: `rec_hr_vermerke`). Ohne sie knallt der
   neue Reiter und der Konto-Kasten (Vermerk-Abfrage).
3. `view:clear`. **Kein `queue:restart`** (keine Jobs, alle Versände synchron).
4. Kein `.env`-Eintrag nötig. Alle drei Einstellungen haben Vorgaben; Notfallweg bleibt
   aus (beide Riegel zu).
5. Sichttests: Reiter mit Zahlen; je Liste eine Zeile; Akte mit/ohne Personen-Zeile;
   „Einladung neu“ an Testperson (Team 6 auf der Demo), Registrierung mit dem Code.
6. HR informieren (wer schaut täglich in „Konto-Anträge“?).
7. **Notfallweg scharf schalten erst danach, getrennt und in dieser Reihenfolge:**
   Meta-Vorlage `RECRUITING_KONTO_VORLAGE_NOTFALL` genehmigt und eingetragen →
   `RECRUITING_KONTO_WEG4_AKTIV=true` → `config:cache` → Haken im Team setzen.

Rollback: Code zurück genügt; die Tabelle stört niemanden. Ein bereits laufender
Notfall-Antrag bleibt dann wie heute nur per Kommando sichtbar.

---

## 12. Offene Fragen (mit Empfehlung)

1. **Einladung per WhatsApp?** Bis eine Einladungs-Vorlage bei Meta genehmigt ist, zeigt
   der Kasten Link + Code einmal an und HR gibt ihn weiter. — *Empfehlung:* so bauen;
   der WhatsApp-Versand wird ein eigener kleiner Schritt, sobald die Vorlage da ist.
2. **Darf „Erneut erinnern“ die 7-Tage-Pause unterschreiten?** Mit X = 5 erscheint die
   Zeile an Tag 5, der Knopf geht erst an Tag 7 (Pause wie freigegeben respektiert). —
   *Empfehlung:* Pause respektieren wie freigegeben; „Kontaktiert“ geht sofort.
3. **Ruhezeit nach „Kontaktiert“: N = 3 Tage, für die ganze Zeile** (nicht je Chip). —
   *Empfehlung:* 3 Tage, ganze Zeile; als Team-Einstellung änderbar.
4. **Zwei Riegel für den Notfallweg** (Server-`.env` UND Team-Haken) statt nur Team-Haken.
   — *Empfehlung:* beide behalten; der Server-Riegel ist der Not-Aus für alle Teams.
5. **Laufende Portal-Sitzungen beim Zurücksetzen/Entsperren beenden?** —
   **Entschieden (Sebastian 12.10.): ja**, siehe §9.6. Spalte `sitzungen_ungueltig_ab`
   an `rec_persons`, eine Prüfstelle `PortalAuth::sitzungGueltig()`.

Entscheidungen 1–4: wie empfohlen (Sebastian 12.10.). Knopf heißt „Kontaktiert".
