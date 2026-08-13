# ZAS Dispo-Inbound — Design (Phase 1)

**Datum:** 2026-08-06
**Status:** Entwurf zur Review
**Modul:** platforms-recruiting (bewusste Zwischenstation, siehe „Zielbild & Umzugsplan")

## Zusammenfassung

ZAS (Herr Michel) schickt uns künftig Dispositionsdaten: Veranstaltungen inklusive aller
Felder (Kunde, Ort, Uhrzeit, …) sowie die Personen, die sich zum Arbeiten auf die
Veranstaltungen eingebucht haben. Phase 1 stellt dafür einen Push-Endpunkt bereit,
persistiert alles roh und macht die Daten in einer Sichtungs-UI lesbar. **Keine
inhaltliche Verarbeitung** — Ziel ist, anhand echter Daten zu sehen, was ZAS liefert,
und daraus Phase 2 (strukturierte Models, Matching) passgenau zu designen.

## Entscheidungen (mit Begründung)

| Entscheidung | Begründung |
|---|---|
| **Push statt Pull** | ZAS pusht heute schon maschinell an uns (Mitarbeiter-Inbound). Pull bräuchte eine API im Altsystem, Credentials und Polling — Aufwand ohne Mehrwert. |
| **Endpunkt lebt vorerst in Recruiting** | Die komplette ZAS-Infrastruktur (Routing, Auth, Raw-File-Muster) existiert dort. Ein eigenes Staffing-Modul bleibt Zielbild; der Umzug kostet später nur eine URL-Abstimmung mit Herrn Michel. |
| **Bestehendes ZAS-Bearer-Token wiederverwenden** | Gleicher Partner, gleiches System, gleiche Vertrauensgrenze. Der Inbound ist reiner Dateneingang (kein Datenabfluss bei Token-Leak). Eigenes, team-gebundenes Token kommt mit dem Umzug ins Staffing-Modul. |
| **Roh persistieren, nichts validieren** | Phase 1 ist explorativ („schauen, was ankommt"). Kaputte oder halbe Payloads werden gespeichert und markiert, nie abgelehnt — wir verlieren keine Daten und ZAS dreht keine Retry-Schleifen. |
| **Keine strukturierten Tabellen in Phase 1** | Schema nach Sichtung ist billiger als Schema raten und migrieren. VA-/Einsatz-Models entstehen in Phase 2 aus den echten Spalten. |
| **Eigene Sidebar-Gruppe „Disposition"** | Dispo ist inhaltlich ein eigenes Ding, das nur vorübergehend in Recruiting wohnt. Eigene Gruppe = klare Optik heute, sauberer Umzug morgen (Gruppe wandert komplett mit). |

## Scope Phase 1

### 1. Endpunkt

- **Route:** `POST /recruiting/zas/dispo-inbound` in `routes/zas.php`
- **Auth:** bestehende `ZasBearerAuth`-Middleware (Token aus `config('recruiting.zas.token')`,
  timing-safe, 503 bei fehlender Konfiguration, 401 ohne Detailhinweise)
- **Annahme:** CSV (Content-Type tolerant behandeln: `text/csv`, `application/octet-stream`,
  Multipart-Upload — nehmen, was kommt). JSON wird ebenfalls angenommen und roh gespeichert,
  falls ZAS später umstellt.
- **Antwort:** `201` mit schlanker JSON-Quittung (Muster `ZasInboundController`:
  id/uuid/size/erkannte Struktur). Inhaltliche Fehler führen nie zu 4xx/5xx —
  gespeichert wird immer.
- **`?dry_run=true`** wie beim bestehenden Inbound: markiert `is_test`, speichert
  trotzdem, Antwort enthält zusätzlich Spaltennamen + erste Datenzeile.
- **Grenzen:** leerer Body → `422` (einziger inhaltlicher Reject). Kein App-seitiges
  Größenlimit — es gilt das Server-Limit der Forge-Site (aus dem Repo nicht
  ermittelbar; bei Bedarf dort nachschauen).

### 2. Persistenz

Neue Tabelle analog `RecZasInboundFile` (eigenes Model, z. B. `RecZasDispoInboundFile`):

- Datei-Inhalt (Storage-Pfad, nicht DB-Blob), Original-Dateiname falls vorhanden
- `received_at`, Bytegröße, erkanntes Format (csv/json/unbekannt)
- Parse-Status: `viewable` / `unparseable` — rein informativ für die Sichtung, keine
  Verarbeitungssemantik. Phase 1 schreibt nur diese beiden Werte; `pending` bleibt als
  Reserve für die Verarbeitungs-Pipeline in Phase 2.
- Zeilen-/Spaltenzahl (nur wenn parsebar), Quelle (fix `zas` — vorbereitet auf spätere
  weitere Quellen)
- **Bewusst team-los** (wie `rec_zas_inbound_files`): das Bearer-Token trägt keinen
  Team-Kontext. Die Sichtungs-UI läuft hinter normaler Auth ohne Team-Filter;
  Team-Zuordnung wird erst in Phase 2 beim Verarbeiten relevant (Config, analog
  `RECRUITING_ZAS_INBOUND_TEAM_ID`). Nebeneffekt: jeder eingeloggte User jedes Teams
  sieht die Dispo-Dateien — heute irrelevant, spätestens beim
  Rheingedeck-Disponenten-Zugang (Zielbild Punkt 6) nicht mehr.

### 2b. Encoding-Normalisierung (Entscheidung, keine Option)

Nur-BOM-strippen reicht nicht: Windows-1252-Bytes in einer Livewire-Komponente
lassen `json_encode` scheitern → 500 auf der Detailseite, erstmals live bei der
ersten echten Datei.

- Encoding-Block aus `ImportApplicantsCsvService::readCsv()` in eine pure Klasse
  ziehen: **`CsvEncodingNormalizer::toUtf8(string $raw): string`**
  (`mb_detect_encoding` UTF-8/Windows-1252/ISO-8859-1/ASCII, Fallback Windows-1252,
  konvertieren, BOM strippen — identisches Verhalten wie heute).
- `ImportApplicantsCsvService` auf die neue Klasse umstellen; die Dispo-Sichtung
  nutzt sie mit.
- Damit haben die Encoding-Grenzfälle aus Abschnitt 5 (BOM, Delimiter, kaputte
  Encodings) ein pure-unit-testbares Objekt.
- Die Roh-Datei auf dem Disk bleibt unverändert 1:1 — normalisiert wird nur für
  Anzeige/Parsing.

### 3. Sichtungs-UI

- **Sidebar:** neue Gruppe `Disposition` in `config/recruiting.php`, erster Eintrag
  **„ZAS-Eingang"**
- **Liste:** eingegangene Dateien mit Datum, Name, Größe, Format, Zeilenzahl, Status —
  per `->get()` ohne Paginierung (Modul-Konvention, Handvoll Dateien)
- **Detail:** CSV geparst als Tabelle gerendert, dazu Spaltenübersicht (Spaltenname +
  Beispielwerte + Füllgrad). Unparsebare Dateien: Roh-Ansicht.
  - **Row-Cap:** Detailtabelle zeigt max. ~200 Zeilen, mit Hinweis „200 von 14.203" —
    stündlicher Voll-Bestand mit VA×Person-Zeilen wird fünfstellig.
  - Die **Spaltenübersicht** (Füllgrad, Beispielwerte) rechnet weiter über die
    **ganze** Datei.
  - Geparste Zeilen sind **keine public property** der Livewire-Komponente (sonst
    liegt das komplette Array bei jedem Request im serialisierten Component-State) —
    lokal in `render()` oder `#[Computed]`.
- Zweck: Feldanalyse für Phase 2 direkt in der Oberfläche (welche VA-Felder, wie hängen
  Personen an VAs, welche IDs für Idempotenz/Matching).

### 4. Fehlerfälle

| Fall | Verhalten |
|---|---|
| Fehlendes/falsches Token | 401, keine Speicherung (wie bestehende ZAS-Endpunkte) |
| Token nicht konfiguriert | 503 (Defense-in-depth aus `ZasBearerAuth`) |
| Kaputtes/unerwartetes Format | Speichern, Status `unparseable`, 201 |
| Duplikat (gleiche Datei mehrfach) | Einfach mehrfach speichern — Sichtung zeigt alles, Idempotenz ist Phase-2-Thema |
| Zu großer Payload | Server-Limit der Forge-Site greift vor der App (kein eigener Check) |
| Leerer Body | 422 |

### 5. Tests

Reines PHPUnit ohne Laravel/DB (Modul-Konvention): Logik pure-unit-testbar schneiden.

- Bearer-Extraktion/-Verhalten ist bereits durch bestehende Tests abgedeckt (Middleware unverändert)
- CSV-/Format-Erkennung: csv/json/garbage → korrektes Format + Status
- Spaltenübersicht-Builder: Spalten, Beispielwerte, Füllgrad aus Beispiel-CSVs
- Grenzfälle: leerer Body, nur Header-Zeile, BOM, Semikolon- vs. Komma-Delimiter,
  kaputte Encodings

### 6. Übergabe an ZAS

- Herr Michel bekommt: URL + Hinweis „bestehendes Token, POST, CSV im Body"
- **Anforderung an die Daten:** Pro eingebuchter Person muss die **`ZasPersonalNr`**
  mitkommen (ZAS-eigene Nummer, existiert für jeden — Pflicht-Schlüssel fürs spätere
  Matching, auch für noch nicht importierte MA). Wo vorhanden zusätzlich die **`UUID`**
  (unsere, von ZAS beim Export gespeichert — Bonus-Schlüssel für Personen, die von uns
  kamen). Namen allein reichen nicht.
- **Erwartete Zusatzfelder (mit Sheran besprochen, ZAS ergänzt sie vorab):** pro
  Veranstaltung eine **Anfahrtsbeschreibung** (Eingang, Weg für den MA) und die
  **Pflicht-Kleidung** für den Einsatz. Phase 1: nur Spalten in der Sichtung;
  Phase 2: müssen weiterverarbeitbar sein (z. B. für Benachrichtigungen an Eingebuchte).
- **Datenwunsch für später (kostet jetzt nichts):** je Einbuchung eine eindeutige
  ZAS-Einbuchungs-ID mitschicken — nötig, sobald wir Lösch-Markierungen zurückmelden
  („PersonalNr + VA" allein ist bei Doppel-/Mehrtages-Einbuchungen nicht eindeutig).
- **Sync-Modell (Vorgabe an ZAS, keine offene Frage):** pro Push immer der komplette
  aktuelle Bestand, perspektivisch regelmäßig (z. B. stündlich). Upsert statt
  Delta-Protokoll; Abmeldungen erkennbar am Fehlen im Folge-Push. Details nach Sichtung.
- **Klärungspunkt an ihn (blockiert Phase 1 nicht):** eine CSV (Zeile = VA×Person?)
  oder getrennte Dateien für VAs und Einbuchungen? Fürs Rohspeichern egal, für
  Phase 2 wichtig.

## Nicht im Scope (Phase 1)

- Kein Parsing in Fach-Models, kein Matching auf `RecEmployee`, keine Idempotenz-Logik
- Keine Verknüpfung zum Events-Modul
- Kein Rückkanal zu ZAS
- Keine Benachrichtigungen/WhatsApp

## Zielbild & Umzugsplan (Richtung, kein Commitment)

Festgehalten, damit Phase 1 nichts verbaut und niemand die Zwischenstation als Endzustand
einbaut:

1. **Eigenes Modul `platforms-staffing`** — Personaldisposition als verkaufbares
   Olivia-Produktfeature, unabhängig von Rheingedeck/ZAS.
2. **Datenmodell (Zielbild):** `StaffMember` (dünne Dispo-Karteikarte: Name, Handy, Rollen —
   *kein* zweiter Personalstamm), `StaffRole`, `StaffEvent` (optional → Events-VA),
   `StaffDemand` (Bedarf ohne Person, „4 Köche am 14.8."), `StaffAssignment` (Einbuchung
   mit Status, Person optional).
3. **Personen wohnen woanders:** `StaffMember` verweist optional auf `HcmEmployee`
   (Kunde mit HCM), `RecEmployee` (Rheingedeck — Mitarbeiter, nicht Bewerber) oder
   CRM-Kontakt; ohne Verweis steht die Karteikarte für sich. Anbindung ist additiv,
   kein Umbau nötig.
4. **Generischer Inbound:** ein Endpunkt für alle Quellen, team-gebundene API-Tokens,
   Quelle im Token, Mapper pro Quelle (ZAS = erster Mapper). ZAS-Spezifika landen als
   `source_meta`-JSON, nie als Kern-Spalten.
5. **Kern kennt kein ZAS.** Exit-Tür: Erweist sich ZAS-Dispo als zu speziell, wird der
   Adapter getrennt — der Produktkern bleibt sauber.
6. **Rheingedeck-Zugang (langfristig):** abgespeckter Disponenten-Blick (statt PDFs aus
   dem Altsystem) = Staffing-Modul mit enger Rechtevergabe; die Sidebar-Gruppe
   „Disposition" ist der Vorläufer davon.
7. **Umzug Recruiting → Staffing:** neue URL + eigenes Token in *einer* Abstimmung mit
   Herrn Michel, Tabellen wandern mit, Sidebar-Gruppe zieht um.
8. **Abgrenzung Artikelwelt:** „Koch-Stunde 32 €" als Verkaufsposition bleibt in
   Events/Commerce (Quote-/OrderPosition); Staffing ist die dispositive Seite (wer steht
   wirklich da). Brücke später: Auftragsposition kann `StaffDemand` erzeugen.
9. **Cross-Team Broich ↔ Rheingedeck:** bewusst offen; Standardfall ist normales
   `team_id`-Scoping, der Spezialfall braucht eine eigene Design-Runde.

## Phasen-Überblick

| Phase | Inhalt | Status |
|---|---|---|
| 1 | Endpunkt + Roh-Persistenz + Sichtungs-UI (dieses Dokument) | Design |
| 2 | Strukturierte Models aus echten Spalten, ZAS-Mapper, Idempotenz (`external_ref`), `RecEmployee`-Matching über `ZasPersonalNr`/`UUID` (unbekannte Nummern bleiben als offene Referenz am Einsatz, Nachzügler-Matching beim späteren MA-Import). Verarbeitung startet im **Dry-Run gegen die vorhandenen Roh-Dateien**, bevor sie scharf geschaltet wird. | Idee |
| 3+ | Staffing-Modul, Olivia-Buchung, HCM-Link, WhatsApp/Autopilot, Rheingedeck-Zugang. **Rückkanal zu ZAS:** Eingebuchter reagiert 4h nach Reminder nicht → Einbuchung wird als „zu löschen" markiert → ZAS pollt stündlich einen Export der Lösch-Markierungen (Muster der bestehenden Export-Endpunkte) und löscht bei sich. Voraussetzung: ZAS-Einbuchungs-ID (siehe Datenwunsch). | Vision |
