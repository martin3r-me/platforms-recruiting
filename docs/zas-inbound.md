# ZAS Inbound — CSV-Eingang (Push-Richtung)

Gegenstück zu den drei ZAS-Pull-Exporten. Hier schickt **ZAS uns** eine CSV.

**Phase 1 (aktuell):** Der Endpoint nimmt die CSV nur **an und speichert sie roh weg**.
Es findet **keine inhaltliche Verarbeitung** statt — wir kennen die Spalten noch nicht.
Beim Empfang wird die Struktur (Trennzeichen, Header-Spalten, Zeilenanzahl) nur
*Best-Effort* erkannt und in der JSON-Antwort zurückgespiegelt, damit ZAS und wir
sofort sehen, was angekommen ist. Das Spalten-Mapping kommt als **Phase 2**, sobald
klar ist, welche CSV ZAS tatsächlich liefert.

## Endpoint

```
POST /recruiting/zas/inbound
Authorization: Bearer <RECRUITING_ZAS_TOKEN>   (gleiches Token wie die Exporte)
```

Übertragung wahlweise:

- **Multipart-Upload** (empfohlen): Feld `file` (alternativ `csv`)
- **Raw-Body**: CSV-Inhalt direkt im Request-Body (`Content-Type: text/csv`)

### Query-Parameter

| Param            | Wirkung                                                                 |
|------------------|-------------------------------------------------------------------------|
| `?dry_run=true`  | Markiert die Lieferung als Test (`is_test=true`). Annahme + Speicherung passieren trotzdem — ideal zum Durchtesten der Verbindung. |

## Beispiel (curl)

```bash
# Multipart-Upload
curl -X POST https://<host>/recruiting/zas/inbound \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@beispiel.csv"

# Als Verbindungstest markiert
curl -X POST "https://<host>/recruiting/zas/inbound?dry_run=true" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@beispiel.csv"

# Raw-Body-Variante
curl -X POST https://<host>/recruiting/zas/inbound \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: text/csv" \
  --data-binary @beispiel.csv
```

## Antwort (201)

**Echt-Lieferung** — schlanke Quittung, keine Spaltenwerte/PII nach außen:

```json
{
  "status": "received",
  "id": 42,
  "uuid": "0192...",
  "is_test": false,
  "received_at": "2026-06-08T10:00:00+00:00",
  "size_bytes": 1234,
  "detected": { "delimiter": ";", "column_count": 4, "row_count": 12 }
}
```

**Test** (`?dry_run=true`) — zusätzlich volle Vorschau (Spaltennamen + erste
Datenzeile). Enthält echte Werte inkl. signierter Datei-URLs, daher bewusst nur
im Test-Modus:

```json
{
  "status": "received",
  "id": 42,
  "uuid": "0192...",
  "is_test": true,
  "received_at": "2026-06-08T10:00:00+00:00",
  "size_bytes": 1234,
  "detected": {
    "delimiter": ";",
    "column_count": 4,
    "row_count": 12,
    "columns": ["PersNr", "Name", "Vorname", "Status"]
  },
  "first_data_row": {
    "PersNr": "1001",
    "Name": "Mustermann",
    "Vorname": "Max",
    "Status": "aktiv"
  }
}
```

> Hinweis: `first_data_row` ist immer nur **eine** Zeile — die Antwortgröße
> hängt an der Spaltenanzahl, nicht an der Zeilenanzahl.

- `422` — keine CSV empfangen (weder Multipart-Feld noch Body)
- `401` — Bearer-Token fehlt/falsch
- `503` — Token serverseitig nicht konfiguriert

## Ablage

- Rohdatei: Disk aus `config('recruiting.zas.inbound_disk')` (Default `local`, privat),
  Pfad `zas-inbound/<Y/m/d>/<uuid>.csv` — 1:1 wie empfangen (inkl. BOM/Encoding).
- Metadaten + erkannte Struktur: Tabelle `rec_zas_inbound_files`
  (`status`: `received` → später `processed`/`failed`).

## Statusfelder: `Status` + `StatusMASeit`

Bestehende Mitarbeiter (Treffer über UUID oder `ZasPersonalNr`) werden **nicht mehr
komplett übersprungen**: für sie synchronisiert der Import gezielt **nur** die beiden
Statusfelder `export_status` und `status_ma_since`. Alles andere bleibt unangetastet —
ZAS soll keine HR-gepflegten Felder überschreiben. Solche Zeilen erscheinen im Bericht
unter `updated` (mit `changed`), unveränderte weiterhin unter `skipped`.

`StatusMASeit` ist der Tag, an dem in ZAS von Status GO auf MA umgestellt wurde
(Kundenwunsch 2026-08-18). Format `TT.MM.JJJJ`. Sonderfall gegenüber allen anderen
Datumsspalten: **leer bedeutet hier löschen**, nicht „nicht anfassen" — ZAS leert das
Feld beim Zurücksetzen auf GO. Damit eine kaputte Lieferung nicht den ganzen Bestand
abräumt, ist das Löschen an die `Status`-Spalte derselben Zeile gekoppelt:

| `Status` | `StatusMASeit` | Wirkung |
|----------|----------------|---------|
| `MA`     | Datum          | Datum wird gesetzt |
| ≠ `MA`   | leer           | Datum wird geleert |
| ≠ `MA`   | Datum          | Datum wird geleert + Warnung (ZAS hat nicht mitgelöscht) |
| `MA`     | leer/ungültig  | Wert bleibt stehen + Warnung (sieht nach Lieferfehler aus) |
| Spalte fehlt | —          | Wert bleibt unangetastet + Warnung |
| `Status` fehlt | —        | Wert bleibt unangetastet + Warnung (Löschen nur mit Status-Bestätigung) |

Das Feld geht **nicht** in die Pull-Exporte zurück: ZAS besitzt es, ein Echo würde nur
Re-Exporte auslösen. Aus demselben Grund stellt der Sync `zas_changed_at` nach dem
Schreiben auf seinen vorherigen Wert zurück (nicht auf `null` — ein bereits gesetzter
Marker stammt aus einer echten Änderung und würde sonst verschluckt).

Im HR-Backend ist beides **readonly** (Mitarbeiter-Detail, gelber HR-Block), weil
umgestellt wird in ZAS. Filterbar nach Zeitraum in der Mitarbeiter-Liste.

## Konfiguration

| Env                          | Default | Zweck                                  |
|------------------------------|---------|----------------------------------------|
| `RECRUITING_ZAS_TOKEN`       | —       | Bearer-Token (geteilt mit den Exporten)|
| `RECRUITING_ZAS_INBOUND_DISK`| `local` | Storage-Disk für die Roh-CSVs          |

## Datei-Eingang: `POST /recruiting/zas/employee-files/{personalnummer}/{slot}`

Gegenstück zum Abruf `GET /employee-files/{employeeUuid}/{slot}`. Schlüssel ist hier die
**Personalnummer**, nicht die UUID — für die Bestands-Mitarbeiter kennt ZAS unsere UUID nicht.

Anlass: rund 1100 Mitarbeiter sind über ZAS in unser System gekommen und haben kein Selfie
(Stand 04.09.2026: 203 von 1330 Mitarbeitern haben ein Bild, und das sind praktisch genau
unsere 204 Funnel-Leute). In den Crew-Kärtchen der Disposition fehlt damit bei 85 % der
Belegschaft das Gesicht. Die CSV liefert seit dem 04.09. den **Dateinamen** in `UplSelfie`
(99 von 100 Zeilen), aber ein Name ist kein Bild — die Datei kommt über diesen Endpunkt.

```
POST /recruiting/zas/employee-files/1187/emp-selfie?filename=Selfie-IMG_0623.jpeg
Authorization: Bearer <RECRUITING_ZAS_TOKEN>      (dasselbe Token wie die CSV-Endpunkte)
Content-Type: image/jpeg

<Bytes>
```

Der Inhalt kommt als **Raw-Body** (so wie ZAS auch die CSV schickt) oder als Multipart-Feld
`file`. `?filename=` ist optional; der Name dient der Wiederholungserkennung und wird als
Originalname der Datei gespeichert. Pfadanteile werden abgeschnitten (`1187/Selfie-x.jpg`
→ `Selfie-x.jpg`).

### Antworten

| Status | HTTP | Bedeutung |
|--------|------|-----------|
| `stored` | 201 | Datei übernommen, Slot gefüllt |
| `already_present` | 200 | Dieselbe Datei liegt schon vor — nichts geschrieben |
| `slot_filled` | 409 | Slot ist mit einer **anderen** Datei belegt; wird nicht überschrieben |
| `not_found` | 404 | Personalnummer bei uns unbekannt |
| `slot_not_allowed` | 422 | Slot ist für den Eingang nicht freigegeben |
| `empty` / `personnel_number_missing` | 422 | kein Inhalt bzw. keine Nummer |
| `too_large` | 413 | über 10 MB |
| `not_an_image` | 415 | Inhalt ist kein JPEG/PNG |

### Regeln

- **Freigegeben ist nur `emp-selfie`** (`ZasInboundFileSlots::ALLOWED`). Alles andere wird
  abgewiesen, obwohl der Ausliefer-Endpunkt 16 Slots kennt — es ist ein schreibender
  Endpunkt mit geteiltem Token. Erweitern ist eine Zeile.
- **Bilder werden am Inhalt geprüft**, nicht an der Endung: in der Testlieferung vom 03.09.
  stand `PlanHalle18.jpg` im Selfie-Feld, ein Hallenplan. Erlaubt sind JPEG und PNG.
- **Nie überschreiben.** Was HR oder der Mitarbeiter selbst hochgeladen hat, gewinnt. Zeigt
  die Spalte auf eine Datei, die es nicht mehr gibt, darf sie neu belegt werden (mit
  Log-Eintrag) — sonst könnte dieser Mitarbeiter nie wieder ein Bild bekommen.
- **Wiederholbar.** Gleicher Name + gefüllter Slot → `already_present`. ZAS kann die Schleife
  über den gesamten Bestand beliebig oft laufen lassen; das ist so zugesichert.
- **Keine Neuanlage.** Eine unbekannte Personalnummer ist ein Fehler, kein Anlass für einen
  neuen Mitarbeiter.
- **Beide Firmen erlaubt** (RG und MA). Von den MA-Leuten führen wir längst den vollen
  Stammdatensatz aus der CSV; das Selfie zu verweigern wäre inkonsequent, und die Dispo
  braucht das Gesicht. Grenze ist das Team (`inbound_team_id`), nicht die Firma.
- **Observer-frei geschrieben.** `selfie_file_id` steht in der Watch-Liste des
  `RecEmployeeExportObservers`. Normal geschrieben würde `zas_changed_at` gesetzt, der
  Mitarbeiter landete im Update-Export, und wir schickten ZAS eine signierte URL auf das
  Bild zurück, das ZAS uns gerade gegeben hat (derselbe Mechanismus wie beim
  Telefon-Vorfall am 02.09.). Ein **bereits gesetzter** Marker bleibt unangetastet.

### Bildvarianten

`ContextFileService::uploadForContext()` wandelt Bilder nach WebP und stellt den
Varianten-Job in die Queue. Das setzt einen laufenden Queue-Worker voraus — ohne den
zeigen die Crew-Kärtchen das Original in Vollgröße. Nach einem Massenlauf lohnt der Blick,
ob die Varianten durchgelaufen sind (`recruiting:backfill-image-variants`).
