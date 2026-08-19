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
