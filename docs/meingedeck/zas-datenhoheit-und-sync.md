# ZAS ↔ meinGedeck — Datenhoheit und Sync-Logik

**Stand:** 2026-09-02
**Gilt für:** Recruiting-Modul (für den Kunden „HCM") und das ZAS-System von Hr. Michel

Dieses Dokument beschreibt, **wer welche Daten besitzt**, über welche Kanäle sie fließen
und welche Fallen dabei bekannt sind. Anlass ist der Vorfall vom 02.09.2026 (siehe unten):
ein interner Bestands-Fix hat rund 500 Mitarbeiter in den Export gespült, deren Daten
in ZAS gepflegt werden — der Import hätte dort gepflegte Akten überschrieben.

---

## 1. Datenhoheit — die Grundregel

Abgestimmt mit HR am 02.09.2026:

| Personengruppe | Gepflegt in | Erkennungsmerkmal |
|---|---|---|
| **HCM-Mitarbeiter** — über unser Recruiting onboardet | **bei uns** | `rec_employees.rec_zas_inbound_file_id IS NULL`, `rec_applicant_id` gesetzt |
| **ZAS-Bestand** — vor der Umstellung in ZAS angelegt, per Inbound-CSV übernommen | **in ZAS** | `rec_zas_inbound_file_id` gesetzt (zeigt auf die Lieferung), `rec_applicant_id IS NULL` |

Daraus folgt der Betriebsgrundsatz, solange das Mitarbeiter-Portal nicht für alle live ist:

> **Von ZAS-Bestandsmitarbeitern darf nichts zurück nach ZAS fließen.**
> Für HCM-Mitarbeiter ist der Rückfluss erwünscht — dort haben wir die Hoheit.

Die Trennung ist dauerhaft und sauber abfragbar; sie geht auch nach dem Portal-Golive
nicht verloren.

---

## 2. Die Kanäle

Alle Endpunkte hinter `ZasBearerAuth` (`RECRUITING_ZAS_TOKEN`), Präfix `/recruiting/zas`.
**ZAS zieht (Pull), wir liefern** — außer beim Inbound, da schickt ZAS.

### 2.1 Bewerber-Export — `GET /applicants/export.csv`

- **Marker:** `rec_applicants.export_changed_at`, gesetzt vom `RecApplicantExportObserver`
- **Zusätzliche Bedingung:** es muss ein Vertrag mit `sent_at` existieren, `is_test = false`,
  optionales Phasen-Gate per Config
- **Nach dem Abruf:** Marker wird auf `NULL` gesetzt

### 2.2 Mitarbeiter-Erstlieferung — `GET /employees/initial.csv`

- **Filter:** `zas_initial_exported_at IS NULL` **und** `is_active = true`
- **Nach dem Abruf:** `zas_initial_exported_at` wird gestempelt — per direktem DB-Update,
  damit der Observer nicht anspringt und der MA nicht sofort auch im Update-Kanal steht

### 2.3 Mitarbeiter-Änderungen — `GET /employees/updates.csv`

**Der Kanal, um den es meistens geht.** Drei Bedingungen müssen zusammenkommen:

```
zas_initial_exported_at IS NOT NULL    -- schon mal geliefert
zas_changed_at          IS NOT NULL    -- Änderung liegt vor
is_active = true
```

- **Nach dem Abruf:** `zas_changed_at = NULL` für alles Ausgelieferte
- `?dry_run=true` liefert dieselbe CSV, **ohne** die Marker zu verbrauchen — zum Reinschauen
- `?limit=N` begrenzt die Menge

### 2.4 ZAS-Inbound — `POST /zas/inbound`

ZAS schickt uns eine CSV mit Mitarbeiterdaten. Details in [`../zas-inbound.md`](../zas-inbound.md).
Kurzfassung:

- **Neue Personen** werden angelegt und sofort mit `zas_initial_exported_at = now()`
  gestempelt (Schleifenschutz — sie sollen nicht als Neuanlage zurücklaufen)
- **Bekannte Personen** (Treffer über UUID oder `ZasPersonalNr`) werden **nicht**
  vollständig aktualisiert. Angefasst wird nur, was ZAS gehört: `export_status`,
  `status_ma_since`, sowie Personalnummer und Firma — und die **nur in leere Felder**
- Der bisherige `zas_changed_at`-Wert wird nach dem Schreiben wiederhergestellt, damit
  Daten, die *von* ZAS kamen, kein Echo auslösen

> **Nebenwirkung, die man kennen muss:** durch den Initial-Stempel sind die
> Bestandsmitarbeiter für den **Update-Kanal freigeschaltet**. Sobald sie einen Marker
> bekommen, gehen sie raus — obwohl für sie nichts zurückfließen soll.

---

## 3. Wie der Änderungs-Marker funktioniert

`rec_employees.zas_changed_at` wird vom `RecEmployeeExportObserver` gesetzt. Drei Auslöser:

| Auslöser | Bedingung |
|---|---|
| `RecEmployee::updated` | eines der Felder aus `RELEVANT_EMPLOYEE_FIELDS` hat sich geändert — Stammdaten, Adresse, Bank, Steuer, **Telefon**, E-Mail, alle Dokument-`file_id`s, Kostenstelle, `is_active`, `employment_ended_at` |
| `RecEmployeeHrData::saved` | eines der Felder aus `RELEVANT_HR_FIELDS` — Vertragsdaten, `export_status`, Anstellungsart, Wäschepaket, Qualifikation, die fünf Bewertungssterne |
| `RecContract::saved` | `signed_at` hat sich geändert und der Vertrag hängt an einem MA |

Geschrieben wird immer per direktem `DB::table()->update()`, damit sich der Observer nicht
selbst auslöst. Dasselbe gilt für das Nullen beim Abruf.

### Zwei Eigenschaften mit Folgen

**Der Marker kennt keinen Grund.** Er sagt „hier hat sich etwas Exportrelevantes geändert",
nicht *was*. Eine Formatkorrektur an der Telefonnummer ist für ihn dasselbe wie
nachgetragene Bankdaten.

**Der Marker ist ein Zeitstempel, kein Journal.** Mehrere Änderungen überschreiben sich
gegenseitig, und der Abruf löscht ihn. Im Nachhinein ist deshalb **nicht** rekonstruierbar,
wer aus welchem Grund im Kanal stand.

---

## 4. Der Export liefert immer VOLLE Zeilen

`ZasEmployeeFieldResolver::resolve()` baut jede der ~76 Spalten aus dem aktuellen
Datenbankstand. **Es gibt keinen Diff-Modus und keinen gespeicherten Stand dessen, was
zuletzt geliefert wurde.**

Michels Import gleicht die Werte ab und übernimmt, was von seinem Stand abweicht. Daraus
folgen zwei Risiken:

1. **Veraltete gefüllte Werte** — wir liefern „Musterstraße", er hat schon „Hauptstraße",
   unser Stand gewinnt.
2. **Leere Werte** — wir liefern leer, wo er Daten hat. Ob das schadet, hängt davon ab,
   wie sein Import eine leere Zelle behandelt (**offene Frage an ihn**, siehe Abschnitt 7).

Betroffen sind vor allem Spalten, die wir beim Inbound **gar nicht einlesen** und deshalb
bei Bestandsmitarbeitern immer leer haben: alle 14 `Upl*`-Dokumentlinks, `Qualifikation`,
`Waeschepaket`, `Sternebewertung` — dazu die berechneten Felder (Kostenstelle, Grundlohn,
Zuschlag, Schulungsdaten), die ohne Stelle und Buchung leer bleiben.

---

## 5. Was ZAS uns liefert — und was nicht

Ermittelt am 02.09.2026 über alle 46 gespeicherten Lieferungen (3.782 Zeilen) mit
`php artisan recruiting:zas-inbound-columns --all --only-empty`:

**Kommt mit, ist aber IMMER leer:**
`EUBuerger`, `Steuerklasse`, `Religion`, `Land`, `SchuhGroesse`, `StatusMASeit`,
sämtliche `Upl*`-Spalten, `Waeschepaket`, `Sternebewertung`, `Qualifikation`,
`SchulungsStandort`, `SchulungsDatum`

**Kommt gar nicht vor:** `Firma`, die fünf `Bewertung*`-Spalten (das sind unsere eigenen
Export-Felder, die er nicht zurückspiegelt)

Praktische Folgen:

- **`is_eu_citizen` ist bei allen übernommenen Mitarbeitern `NULL`.** Das MA-Portal blendet
  die Non-EU-Sektion bei `NULL` aus (`RecEmployee::editableFieldGroups()`), ein
  Nicht-EU-Bestandsmitarbeiter kann seine Aufenthaltsdokumente dort also **nicht** hochladen,
  solange HR den Haken nicht setzt. Das HR-Backend zeigt die Felder dagegen an.
- Die Bewertungsfelder (Qualifikation, Wäschepaket, Sterne) sind bei allen ZAS-Mitarbeitern
  leer und können es strukturell nur sein: der einzige Schreibpfad ist das Bewertungs-Modal
  am Schulungstermin, und das hängt an einer Buchung, die diese Leute nie hatten.
- Dokumente/Bilder liefert ZAS nicht. Anfrage an Hr. Michel läuft (Zugriff auf seinen
  FTP bzw. Dateiverweise in den `Upl*`-Spalten).

---

## 6. Bekannte Fallen

**Massen-Änderungen über Eloquent lösen Massen-Marker aus.** Jeder Bestands-Fix, der
`$employee->save()` benutzt und ein beobachtetes Feld anfasst, markiert alle betroffenen
Mitarbeiter für den Export. So ist der Vorfall vom 02.09. entstanden
(`recruiting:normalize-employee-phones`). Wer so einen Command schreibt, sollte entweder
observer-frei schreiben (Muster: `ZasInboundEmployeeImporter::syncMatchedFields()` merkt
sich den Marker und stellt ihn wieder her) oder die Folgen bewusst einplanen.

**Inaktive Mitarbeiter behalten ihren Marker für immer.** `is_active` ist selbst ein
Auslöser — das Deaktivieren setzt den Marker, und dieselbe Deaktivierung schließt den
Datensatz vom Export aus. Stand 02.09.: 14 solcher Karteileichen. Harmlos, aber verwirrend.

**Austritte erreichen ZAS nie.** Es gibt im Mitarbeiter-Export **keine** Austritts-Spalte
(weder `employment_ended_at` noch `is_active`). Der Trigger existiert, das Zielfeld nicht.
Ob das gewollt ist, ist mit Hr. Michel zu klären.

**Signierte Datei-URLs ändern sich bei jedem Abruf.** `ZasSignedUrlGenerator` setzt ein
neues Ablaufdatum, also auch eine neue Signatur. Für einen späteren Diff-Export heißt das:
die `Upl*`-Spalten müssen über die dahinterliegende `file_id` verglichen werden, nicht über
den URL-Text.

**Es gibt kein Protokoll der Abrufe.** Wann ZAS zuletzt gezogen hat, steht nur im
Webserver-Log (`grep "employees/updates.csv" /var/log/nginx/*access*.log`).

---

## 7. Betrieb — Checkliste vor jedem Abruf

Solange volle Zeilen geliefert werden, ist das der Türsteher:

```sql
SELECT SUM(rec_zas_inbound_file_id IS NOT NULL) AS zas_bestand,
       SUM(rec_zas_inbound_file_id IS NULL)     AS hcm
FROM rec_employees
WHERE zas_changed_at IS NOT NULL
  AND zas_initial_exported_at IS NOT NULL
  AND is_active = 1;
```

- **`zas_bestand` = 0** → unbedenklich, Import kann laufen
- **`zas_bestand` > 0** → erst die Person ansehen. Häufigste Ursache: ein
  Bestandsmitarbeiter hat im Portal ein Dokument hochgeladen oder Daten gepflegt.

Zum Reinschauen ohne die Marker zu verbrauchen:

```bash
curl -s -D - -o /dev/null "https://<host>/recruiting/zas/employees/updates.csv?dry_run=true" \
  -H "Authorization: Bearer <TOKEN>" | grep -i "x-records-count"
```

> Die Ausgabe enthält echte Personendaten und signierte Datei-URLs — nicht in Tickets
> oder Chats kopieren.

---

## 8. Offene Punkte

**Beim Golive des Mitarbeiter-Portals für alle:** die ZAS-Bestandsmitarbeiter müssen
**einmalig komplett aus ZAS glattgezogen** werden — ab dann liegt die Pflege bei uns. Die
Gruppe ist über `rec_zas_inbound_file_id IS NOT NULL` exakt adressierbar. Der heutige
Inbound-Import kann das **nicht**: er fasst bei Treffern bewusst nur Status, Personalnummer
und Firma an. Es braucht also einen eigenen, expliziten Voll-Übernahme-Modus.

**Diff-Export statt voller Zeilen.** Der saubere Weg aus Abschnitt 4: pro Mitarbeiter den
zuletzt gelieferten Stand als Snapshot speichern, beim Export nur abweichende Spalten
füllen, `UUID` und `ZasPersonalNr` immer mitgeben. Beim Einführen wird der Snapshot aus dem
aktuellen Stand geseedet („so tun, als hätten wir gerade alles geliefert") — damit ist das
Bestands-Problem in einem Zug erledigt.
**Blockiert durch die Frage an Hr. Michel:** überspringt sein Import eine leere Zelle, oder
leert er damit das Feld? Bei „leert" wäre ein Diff-Export schädlich, weil er zu 95 % aus
leeren Zellen besteht. Alternative für diesen Fall: volle Zeilen plus eine Zusatzspalte
`GeaenderteFelder` am Ende (abwärtskompatibel, weil er positional liest) — dann muss aber
er seinen Import erweitern.

**Weitere offene Fragen an ZAS:** `EUBuerger`, `Steuerklasse`, `Religion` und `StatusMASeit`
werden nie gefüllt geliefert; Dokumente/Bilder fehlen komplett.

**Abrufe protokollieren.** Ein Vorgang, der Daten beim Kunden verändert, sollte
nachvollziehbar sein — heute gibt es dafür nur das nginx-Log.

---

## 9. Werkzeuge

| Command | Zweck |
|---|---|
| `recruiting:zas-inbound-columns [--all] [--only-empty] [--samples]` | Füllgrad je Spalte über die gespeicherten Lieferungen; trennt „liefert er nicht" von „lesen wir nicht". Nur lesend. |
| `recruiting:zas-inbound-reprocess [fileId] [--dry-run]` | Gespeicherte Lieferung erneut durch den Import schicken |

---

## 10. Vorfall 02.09.2026 — zur Erinnerung

`recruiting:normalize-employee-phones` korrigierte am 01.09. um 22:13 Uhr rund 500
Telefonnummern per Eloquent. Über den `phone`-Trigger landeten alle betroffenen
Mitarbeiter im Update-Kanal — überwiegend ZAS-Bestand. Clara zog am 02.09. die Datei
(505 Datensätze) und fragte nach, weil plötzlich „alle alten Mitarbeiter" enthalten waren.
Der Import wurde gestoppt, bevor Schaden entstand.

Aufgeräumt wurde so: die Datei wurde verworfen, und die **echten** Änderungen seit ihrem
letzten Import (35 Stück, ausschließlich HCM) wurden gezielt neu markiert — ermittelt über
`updated_at`, die Lohnfeld-Historie `payroll_data_changed_fields`, `context_files` und
`rec_contracts`. Die Marker des Telefon-Laufs waren durch den Abruf bereits verbraucht.

Lehre: siehe Abschnitt 6, erster Punkt.
