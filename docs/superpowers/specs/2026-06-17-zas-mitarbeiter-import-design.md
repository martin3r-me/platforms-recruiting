# ZAS-Mitarbeiter-Import — Design

**Datum:** 2026-06-17
**Modul:** platforms-recruiting
**Status:** Entwurf zur Freigabe

## Ziel

ZAS liefert über den bestehenden Inbound-Endpunkt CSV-Datensätze von Mitarbeitern,
die bei uns **noch nicht im System** sind (z.B. Bestands-MA wie Geschäftsführer
Markus Ammerer, PersNr 1187). Diese sollen als `RecEmployee` angelegt werden, sodass
sie im Mitarbeiter-Portal erscheinen und sich dort einloggen können.

**Scope dieser Iteration: NUR Neuanlage.** Bestehende Datensätze aktualisieren
(„Fall A" — Match per UUID/zas_id → Felder ergänzen) ist bewusst **ausgeklammert**
und wird bei Bedarf später ergänzt.

## Nicht-Ziele (YAGNI)

- Kein Update bestehender MA (nur Dubletten-Schutz: bereits vorhandene werden übersprungen).
- Keine Datei-Übernahme (Upl*-Spalten kommen leer; Dokumente lädt der MA im Portal hoch).
- Keine Anzeige unbekannter Lookup-Werte im UI-Dropdown (Werte werden roh gespeichert; UI-Sichtbarkeit später).
- Keine Positions-/Kostenstellen-Zuordnung beim Import.
- Keine Review-/Freigabe-Warteschlange — Anlage erfolgt direkt (ZAS gilt als verlässliche Quelle; Korrektur im Portal).

## Architektur

Ein Endpunkt, Verzweigung pro Zeile (kein zweiter Endpunkt):

```
POST /recruiting/zas/inbound  (bestehend, ZasBearerAuth)
  → ZasInboundController         [Phase 1: roh speichern + rec_zas_inbound_files] (unverändert)
  → ZasInboundEmployeeImporter   [NEU: Zeilen verarbeiten, MA anlegen]
       ↳ ZasInboundRowMapper     [NEU: CSV-Spalten → RecEmployee/HrData-Felder]
       ↳ ZasLookupReverseResolver[NEU: Label → unser Lookup-Code (Hybrid)]
```

- **ZasInboundController** (bestehend): nimmt an, speichert roh, schreibt `rec_zas_inbound_files`.
  Ruft danach den Importer auf und hängt dessen Ergebnis an Antwort + Inbound-Eintrag.
- **ZasInboundEmployeeImporter**: iteriert die Datenzeilen, entscheidet pro Zeile
  (anlegen / überspringen / Fehler), legt `RecEmployee` + `RecEmployeeHrData` an, sammelt ein Ergebnis-Protokoll.
- **ZasInboundRowMapper**: bildet eine CSV-Zeile (Header→Wert-Map) auf ein Feld-Array ab.
  Invertiert die Mapping-Tabelle aus `ZasEmployeeFieldResolver`. Normalisiert Datum/Bool/Zahl, trimmt.
- **ZasLookupReverseResolver**: löst Klartext-Labels zu unseren Lookup-Codes auf (siehe unten).

Begründung Einzel-Endpunkt: Die Unterscheidung „neu vs. bestehend" steckt in der Zeile
(UUID/zas_id), nicht in der URL. Ein Endpunkt hält Auth/Speicherung/Logging an einer
Stelle und verarbeitet auch gemischte Dateien problemlos.

## Pro-Zeile-Logik (Matching-Kaskade)

Für jede Datenzeile:

1. **UUID gefüllt und `RecEmployee` mit dieser UUID existiert** → **überspringen**
   (Ergebnis `skipped: exists`; Update ist nicht im Scope).
2. **Sonst: `ZasPersonalNr` gesetzt und ein `RecEmployee.zas_id` stimmt überein** → **überspringen**
   (`skipped: exists` — verhindert Dubletten bei erneutem Versand).
3. **Sonst** → **neu anlegen**.

Dubletten-Match läuft im Code (`RecEmployee::where('zas_id', …)->first()`), da `zas_id`
nur indiziert, nicht unique ist. Match ist team-gescoped (siehe Team-Zuordnung).

## Feld-Mapping (Neuanlage)

Quelle ist die invertierte Tabelle aus `ZasEmployeeFieldResolver`. Direkte String-Felder
werden 1:1 (getrimmt) übernommen. Beispiele aus der ZAS-Beispieldatei:

**Direkt auf `rec_employees`:** last_name (Name), first_name (Vorname), birth_name,
birth_date (Geburtsdatum), birth_place, identity_card_number (AusweisNr),
identity_card_valid_until (AusweisBis), number_of_children (KinderAnzahl), phone (Telefon),
email, street, house_number, zip, city, country_code (Land, Default `de` wenn leer),
bank_institute (Bank), iban, bic, account_holder, tax_class (Steuerklasse),
steuer_id (SteuerID), sozialversicherungsnummer (SVNummer), drivers_license_class,
has_car (PKW, Ja/Nein→bool), recruited_by_personnel_number (GeworbenVonPersNr),
shirt_size / pants_size / shoe_size, residence_permit_valid_until, work_permit_valid_until,
school_certificate_valid_until, infection_protection_first_issued_at (InfekErstbescheinigung),
is_eu_citizen (EUBuerger), **employed_since (aus „Eintritt")**, **zas_id (aus ZasPersonalNr)**.

**Lookup-Felder (Hybrid, siehe unten):** gender (Geschlecht), marital_status (Familienstand),
religion, health_insurance (Krankenkasse), employment_type (Ichbin), birth_country (Nation).

**Auf `rec_employee_hr_data`:** export_status (Status, normalisiert `go`→`GO`),
employment_classification (Anstellungsart, Hybrid + Präfix), contract_sent_date (VertragVersendetAm),
contract_signed_at (VertragZurueckAm), contract_end_date (BefristetBis).

**Ignoriert (reine Export-Rechenfelder ohne Speicher-Spalte):** BeschErforderlich,
AufenthaltGenehmigungErforderlich, FolgeBescheinigungAm, InfekGueltigBis,
InfekBeschErforderlich, InfekBeschVorhanden, Grundlohn, Zuschlag, SchulungsStandort, SchulungsDatum.

**Ignoriert (kein Ziel-Feld bei Direktanlage):** Kostenstelle (kommt im Export aus der
Position; ohne Positions-Zuordnung kein Platz — als Notiz vermerkt, später erweiterbar).

**Leer/optional:** Upl*-Datei-URLs (kommen leer → keine Datei-Übernahme).

Normalisierung: Whitespace trimmen, Datum `d.m.Y`→`Y-m-d` (Carbon), Bool `Ja/Nein`→bool,
Zahlen casten, leere Strings → `null`.

## Lookup-Auflösung (Hybrid)

`ZasLookupReverseResolver` lädt pro Lookup (per Name aus `core_lookups`/`core_lookup_values`)
eine Map und löst je Wert auf:

1. **Case-insensitiver Match gegen `value` ODER `label`** → unser sauberer Code
   (z.B. „Weiblich"→`weiblich`, „verheiratet"→`verheiratet`, „Deutschland"→`de`,
   „Private Krankenkasse"→`pkv`).
2. **Nur für `anstellungsart` zusätzlich Präfix-Match** (z.B. „Vollzeit 172 Stunden"→`vollzeit`),
   weil das HR-Feld den Default `kurzfristig` hat — ein Nicht-Treffer dürfte nicht still
   auf „kurzfristig" fallen.
3. **Kein Treffer** → **roher ZAS-String wird gespeichert** (kein Datenverlust, z.B.
   „Geschäftsführer/in") und der Fall in den Notizen des Inbound-Eintrags vermerkt.
   (Hinweis: nicht-gemappte Werte erscheinen in UI-Dropdowns nicht vorausgewählt —
   bewusst akzeptiert, Sichtbarkeit später nachrüstbar.)

Verifiziert (Team RHEINGEDECK-HR, Stand 2026-06-17): geschlecht, familienstand,
geburtsland, krankenkasse mappen sauber; beschaeftigung_art („Geschäftsführer/in")
und anstellungsart („Vollzeit 172 Stunden", via Präfix → vollzeit) sind die bekannten Kanten.

## Anlage-Details

- **team_id:** aus Config `RECRUITING_ZAS_INBOUND_TEAM_ID` (Pflicht). Ohne gesetzte
  Team-ID wird nicht angelegt → Zeile als Fehler protokolliert mit klarer Meldung.
- **rec_applicant_id:** `null` (MA stammt nicht aus dem Recruiting — Feld ist nullable).
- **is_active:** `true`; **portal_token** wird automatisch erzeugt (Model-`booted()`).
  Portal-Login funktioniert (braucht Geburtsdatum + Ausweisnummer — beide geliefert).
- **RecEmployeeHrData:** via `ensureHrData()` anlegen, dann Felder setzen (export_status,
  employment_classification, Vertragsdaten).
- **Export-Schleifen-Schutz:** `zas_initial_exported_at = now()` setzen, damit der aus ZAS
  importierte MA **nicht** über unseren Ausgangs-Initial-Export sofort wieder an ZAS geht.
  (Direkter DB-Wert beim Anlegen; der `RecEmployeeExportObserver` feuert nur bei `updated`,
  nicht bei `created` — Neuanlage setzt also kein `zas_changed_at`.)
- **Herkunfts-Marker:** `rec_zas_inbound_file_id` wird auf den `rec_zas_inbound_files`-Eintrag
  gesetzt, aus dem der MA stammt. Gibt eindeutig „ist ZAS-Import" (Feld gefüllt) **und**
  Rückverfolgung zur konkreten Lieferung. Bei Recruiting-Anlage bleibt das Feld `null`.

## Datenmodell-Änderung

Neue Migration auf `rec_employees`:

- `rec_zas_inbound_file_id` — `unsignedBigInteger`, nullable, FK auf `rec_zas_inbound_files.id`
  (onDelete: set null), indiziert. Herkunfts-/Provenienz-Marker für ZAS-Importe.

Relationen: `RecEmployee` bekommt `belongsTo(RecZasInboundFile)`, optional Gegenrichtung
`RecZasInboundFile hasMany RecEmployee`.

## Verarbeitungs-Zeitpunkt

Synchron im Request, **nachdem** die Rohdatei + `rec_zas_inbound_files`-Eintrag geschrieben
wurden (Phase 1 bleibt unangetastet). Vorteil: ZAS sendet Einzeldatensätze und bekommt
sofort eine klare Rückmeldung. Die Annahme/Speicherung schlägt nie fehl, auch wenn die
Verarbeitung scheitert.

## Fehlerbehandlung

- **Pro Zeile** in try/catch: eine fehlerhafte Zeile wird als `failed` protokolliert
  (mit Grund) und stoppt nicht die übrigen Zeilen.
- **Rohdatei** wird immer gespeichert (Phase 1), unabhängig vom Verarbeitungsergebnis.
- **`rec_zas_inbound_files`** wird nach der Verarbeitung aktualisiert:
  `status` → `processed` (alles ok), `partial` (teilweise) oder `failed` (nichts ging),
  `processed_at` gesetzt, `notes` enthält: angelegte Employee-IDs, übersprungene (exists),
  nicht-aufgelöste Lookups (mit Rohwert), ignorierte Felder mit Inhalt (z.B. Kostenstelle).

## Antwort (HTTP)

Erweiterung der bestehenden JSON-Antwort um ein `import`-Objekt:

```json
{
  "status": "received",
  "id": 7,
  "import": {
    "status": "processed",
    "created":  [{ "employee_id": 42, "zas_id": "1187" }],
    "skipped":  [],
    "failed":   [],
    "warnings": [
      "employment_type: roh gespeichert (kein Lookup-Treffer)",
      "Kostenstelle '102' ignoriert (keine Positions-Zuordnung)"
    ]
  }
}
```

**Echt-Betrieb (PII-frei, konsistent zur Phase-1-Entscheidung):** Datensätze werden per
`employee_id` + `zas_id` bestätigt — **kein Name**. ZAS kennt die `zas_id` und kann den
Datensatz eindeutig zuordnen.

**`?dry_run=true`:** Zeilen werden gemappt und validiert, aber **nicht** angelegt
(`would_create` statt `created`); hier zusätzlich der **Name** zur Kontrolle + volle Vorschau wie bisher.

Hinweis an ZAS: Wir antworten pro Datensatz mit diesem `import`-Block — ZAS-seitig sollte
die Antwort angezeigt/ausgewertet werden, damit „angelegt/übersprungen/Fehler" sichtbar ist.

## Konfiguration

| Env | Default | Zweck |
|-----|---------|-------|
| `RECRUITING_ZAS_INBOUND_TEAM_ID` | — (Pflicht) | Team, dem importierte MA zugeordnet werden |
| `RECRUITING_ZAS_INBOUND_DISK` | `local` | (bestehend) Disk für Rohdateien |

## Teststrategie

Das Modul hat keine Test-Harness (bewusst, wie die übrige ZAS-Strecke). Verifikation:

- Standalone-Verifikation der Mapping- und Reverse-Lookup-Logik gegen die echte
  ZAS-Beispiel-CSV (Markus Ammerer) — analog zur Inbound-Parse-Prüfung.
- Manueller End-to-End-Test per `?dry_run=true` (zeigt `would_create` + Warnungen),
  danach echter Lauf, Kontrolle in `rec_zas_inbound_files` + Portal-Login.

## Offene Punkte / spätere Erweiterungen

- Fall A (Update bestehender MA, non-destruktiv) bei Bedarf.
- UI-Sichtbarkeit roher Lookup-Werte (Variante 2: Select zeigt gespeicherten Wert als Fallback).
- Kostenstelle → Positions-Zuordnung.
- Datei-Übernahme (falls ZAS künftig Dokumente mitliefert).
