# ZAS-Status „inaktiv“ — Import, Bestand, Jobs anbieten

Stand 14.10.2026. Auslöser: Kundenzahlen MGL (~317 aktiv) gegen unsere 586 bei „Jobs anbieten“. Befund (Abfragen 14.10.): ZAS schickt den Mitarbeiterbestand mehrmals täglich voll (Sep 99.202 Zeilen, Okt bis 14.10. 77.134, Pakete à 100), die Spalte „Status“ ist immer gefüllt — aber nur mit GO oder MA. In ZAS inaktive Mitarbeiter kommen ebenfalls mit GO/MA. Olaf Michel stellt den Export so um, dass „Status“ zusätzlich „inaktiv“ trägt und ALLE Inaktiven (auch historische, bisher nie exportierte) mitkommen.

## 1. Bedeutung der Status (S. Haustein, 14.10.)

| Status | Vertrag | ZAS-Portal | bei uns |
|---|---|---|---|
| GO | aktiv | sieht Schichten, bucht sich ein | aktiv |
| MA | keiner gerade (z. B. auf Reisen) | sieht Schichten, braucht neuen Vertrag | aktiv, Hinweis „braucht neuen Vertrag“ |
| inaktiv | keiner | gesperrt | **inaktiv** (is_active = false), Daten bleiben |

Status-MA ist NICHT die Firma MA (Personalnummer-Präfix). Mehr Werte gibt es nicht.

## 2. Import (ZasInboundEmployeeImporter)

### 2.1 Wert erkennen
`ZasStatus::normalize(?string): ?string` (rein): trim, Großbuchstaben; `GO` → `GO`, `MA` → `MA`, alles was mit `INAKT` beginnt (`inaktiv`, `INAKTIV`, `Inaktive`) → `INAKTIV`; leer → null (Feld bleibt unberührt, wie heute); sonst unverändert großgeschrieben (Warnung im Lieferbericht „unbekannter Status“). Der RowMapper nutzt die Funktion statt `mb_strtoupper`.

### 2.2 Bekannter Mitarbeiter
- Status `INAKTIV`, Mitarbeiter aktiv → deaktivieren (`is_active = false`), `export_status = INAKTIV`.
- Status `GO`/`MA`, Mitarbeiter inaktiv **und** gespeicherter `export_status = INAKTIV` → reaktivieren (`is_active = true`).
- Status `GO`/`MA`, Mitarbeiter inaktiv, gespeicherter Status `GO`/`MA` → **nicht** reaktivieren. Das ist eine Deaktivierung von Hand durch HR (heute 36 Fälle); ZAS darf sie nicht überstimmen. Nur ein von ZAS verursachtes Inaktiv kehrt durch ZAS zurück.
- Gilt für ALLE Mitarbeiter, auch die aus dem Recruiting (nicht nur ZAS-Bestand): der Status gehört ZAS.
- Schreibweg wie heute ohne Echo nach ZAS: `is_active` per direktem DB-Update im selben Block wie die Marker-Wiederherstellung (`syncMatchedFields`), danach `EmployeeContactListSyncService::syncEmployee()` für den frischen Datensatz (nimmt Deaktivierte aus der WhatsApp-Kontaktliste, Reaktivierte wieder auf), in try/catch wie der Observer.
- Ein reiner Status-Wechsel zählt als Änderung (keine `skipped/exists`-Zeile).

### 2.3 Unbekannter Mitarbeiter mit Status INAKTIV
- Wird angelegt, aber sofort mit `is_active = false` und `export_status = INAKTIV`.
- KEINE Dubletten-Prüfung (`ZasInboundDuplicateFinder`) — bei tausenden Altfällen nur Lärm. Personen-Paarung (`PersonPairLinker`) läuft wie bei jeder Anlage.
- Keine weiteren Nebenwirkungen: kein CRM-Kontakt (Backfill filtert schon `is_active = true`), keine Kontaktliste (Observer reagiert nur auf `updated`), kein Export an ZAS (Exporte filtern `is_active = true`).

### 2.4 Lieferbericht
Neue Zähler in den Notizen: `deactivated` (Liste {employee_id, personnel_number}), `reactivated` (dto.), `created_inactive` (Anzahl, die Einträge stehen wie gewohnt in `created` mit `inactive: true`). Kein Log je Zeile.

### 2.5 Anzeige Mitarbeiterakte
Feld „Status (aus ZAS)“: Optionen `GO`, `MA`, `INAKTIV` (heute nur GO/MA — INAKTIV würde sonst als leer gezeigt).

## 3. Überall „nur aktive“

Alle Stellen, die heute `is_active = true` abfragen, schließen Inaktive automatisch aus: Jobs anbieten (Kandidaten, Mitarbeiterfiliale), Dispo-Bestätigung, Sammelversände, Exporte an ZAS, Mitarbeiterliste (Standardfilter), Statistik (zählt Inaktive seit 5c1b81b2 als „geklärt“). Keine Änderung nötig; ein Test hält Jobs anbieten fest.

## 4. Jobs anbieten — Inaktive sichtbar, aber getrennt

- **Mitarbeiterfiliale (Schritt 3 und „Neue Welle“):** je Block zusätzlich die Zahl der Inaktiven, grau: „MGL · 507 Mitarbeiter · 1.234 inaktiv (werden nicht angeschrieben)“. `DispoJobKandidaten::blockOptionen()` liefert `inaktiv` je Block (eine zusätzliche gruppierte Query).
- **Status MA:** Kandidaten tragen `ohne_vertrag` = keine Anstellung der Person mit Status GO, mindestens eine mit MA. Sie werden angeschrieben wie alle anderen.
  - Zahlen-Kasten „Sendet an N Personen“: zusätzliche graue Zeile „davon M mit Status MA (brauchen neuen Vertrag)“, wenn M > 0.
  - Zusagen-Tabelle der Anfrage: Chip „Status MA – braucht neuen Vertrag“ hinter dem Namen (`DispoJobZusagenInfo` liefert `ohne_vertrag`).

## 5. Bewusst nicht
- Keine Rückmeldung an ZAS, kein Export von INAKTIV.
- Keine automatische Deaktivierung über „seit X Tagen nicht geliefert“ (ZAS liefert jetzt den echten Status).
- Keine 90-Tage-Regel bei Jobs anbieten (erledigt sich mit dem echten Status).
- Keine Änderung an `employment_ended_at`.

## 6. Tests
- `ZasStatus::normalize` (rein).
- Importer (Integration, Muster `ZasInboundStatusSyncTest`): deaktivieren; reaktivieren nur nach ZAS-Inaktiv; HR-Deaktivierung bleibt bei GO; unbekannt+INAKTIV → inaktiv angelegt ohne Dubletten-Prüfung; unbekannt+GO wie bisher aktiv; Zähler im Bericht; kein `zas_changed_at`-Echo.
- Jobs anbieten: Inaktive nicht in Kandidaten, Zahl je Block; `ohne_vertrag` (GO+MA-Gruppe = mit Vertrag, nur MA = ohne).

## 7. Auslieferung
Keine Migration. Deploy + `view:clear`. **Vor** Olafs Umstellung live. Danach: erster großer Lauf mit vielen `created_inactive` — Lieferbericht im ZAS-Eingang prüfen.
