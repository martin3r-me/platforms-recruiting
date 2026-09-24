# Mitarbeiterportal — Fahrplan und Stand

**Stand 24.09.2026** · Branch `feat/ma-portal` · Worktree `platform/modules/platforms-recruiting-portal`

Diese Notiz sagt, **wo wir stehen**. Was gebaut wird, steht in der Spec:
`docs/superpowers/specs/2026-09-22-mitarbeiterportal-nachweise-design.md`.
Hier wird nichts davon wiederholt — sonst pflegen wir zwei Staende.

---

## Ziel

> Mitarbeiter pflegen ihre Nachweise selbst, das System passt auf die Fristen auf,
> WhatsApp hoert auf die Ablage zu sein — und wer fuer RG und MA arbeitet, macht
> das alles einmal.

Markus' groesster Schmerz, in seinen Worten am Telefon vom 22.09.: Masse an
Nachrichten, Dokumente per WhatsApp, manuelle Rueckfragen und Nachbearbeitung.

---

## Fahrplan

| | Was | Wann | Aufwand |
|---|---|---|---|
| **1** | **Nachweise in Selbstbedienung** + Portal-Huelle + RG/MA als eine Person | Oktober | 70–100 h |
| 2 | HR spielt Dokumente an viele aus — Quote, Fristen, Nachweisblatt | November | Spec vom 10.07. |
| 3 | Canvas 68: Konto, Person/Anstellung, Datenhoheit ZAS | Dez–Feb | 114–156 h |
| 4 | Prozessmotor, Regelmatrix, Vertragsautomatik, Zahlungsstatus, Agenda | 2027 | offen |

Schritt 4 ist Markus' Praesentation vom 22.09. Nicht abgelehnt, sondern eingeordnet.

**Zwei Zahlensysteme, nie vermischen.** Die Spalte oben ist *Kundenaufwand* —
Canvas, Abrechnung, aus der Code-Pruefung belegt. Fuer die Tagesplanung zaehlt
die *Bauzeit im Projekt*, und die ist gemessen: Die Datenbasis war mit 18–24
Kundenstunden veranschlagt und entstand in **rund anderthalb Stunden**.

Was der Faktor nicht abdeckt und was den Termin bestimmt: Migration gegen 1.558
echte Datensaetze, der Blick eines Menschen auf die Oberflaeche, Meta-Vorlauf,
Testrunde, offene Entscheidungen.

### Runde 1 in drei Schritten

| | | Kundenaufwand | Bauzeit | danach noetig |
|---|---|---|---|---|
| 1 | **Datenbasis** | 18–24 h | **1,5 h — fertig** | Migration gegen echte Daten |
| 2 | **Huelle** — vier Bereiche, Aufgabenliste, Einsaetze, Anmeldeschicht | 14–22 h | 2–4 h | dein Blick, Deploy auf demo |
| 3 | **Selbstbedienung** — Upload, Fristenlauf, WhatsApp-Umleitung, HR-Sicht | 20–26 h | 2–3 h | Meta-Vorlage, Fristenlauf gegen echte Daten |

---

## Stand

### Fertig — Schritt 1, Datenbasis

| Commit | Was |
|---|---|
| `758a962` | Spec Runde 1 |
| `10c36e7` | `tools/deploy-lock.py` — Deploy ohne `composer update` |
| `d94cac1` `f622695` | `recruiting:mitarbeiter-grenzfaelle` |
| `2610c83` | `ProofTypes` — Katalog, 14 Arten |
| `f64ca89` | `rec_employee_proofs` + `RecEmployeeProof` |
| `1b14699` | `ProofWriter` — Doppelschreiben, observer-frei |
| `5b6fb66` | `recruiting:nachweise-umziehen` |
| `2902715` | `ProofReader` + `ProofChecklist` — Aufgabenliste |

53 neue Tests. Gesamtlauf: 1.222 Unit + 845 Integration, gruen.

### Fertig — Schritt 2, die Huelle

| Commit | Was |
|---|---|
| `5a58185` | `PortalAuth` — Anmeldung als eigene Schicht |
| `bf2bba1` | `portal_v2_since` + `recruiting:portal-umstellen` |
| _dieser_ | `PortalShell` — vier Bereiche, eigenes Layout, Route |

Die Optik ist die abgenommene: `resources/mockups/crew-portal.html` liefert die
CSS **woertlich** (`layouts/portal-styles.blade.php`), das Portal bricht nur aus
dem gezeichneten Telefonrahmen aus. Bewusst ohne Tailwind und ohne `x-ui-*` —
das Portal traegt seine Farben selbst, damit weder der Dunkelmodus noch eine
stille `x-ui`-Eigenheit hineinregiert.

Der **Start-Bereich zeigt echte Daten**: er haengt an `ProofReader::checklist()`.
Rot heisst abgelaufen oder fehlend, gelb laeuft zu, gruen liegt vor. Der
Reiter traegt die Zahl der offenen Punkte.

Noch Attrappe: **Einsaetze** (ehrlicher Hinweis statt erfundener Termine).
Noch ohne Knopf: **Dokumente** listet die Nachweise, hochladen kommt in Schritt 3.

Erreichbar unter `/recruiting/mitarbeiter/neu/{token}` — und nur fuer die, bei
denen `portal_v2_since` steht. Alle anderen bekommen 404, als gaebe es die
Seite nicht.

### Fertig — Schritt 3, Selbstbedienung

Fuenf Aufgaben, subagent-getrieben gebaut (Plan:
`docs/superpowers/plans/2026-09-24-nachweise-selbstbedienung.md`): Regeln fuers
Hochladen · Upload-Strecke im Portal · Fristenlauf-Planer mit Stichtag ·
Versand und Kommando · HR-Sicht. Dazu Schlusspruefung ueber den ganzen Branch,
eine Fix-Welle und die Korrekturen aus der Kundenrunde.

42 Commits, 2.196 Tests gruen.

**Was die Schlusspruefung gefunden hat und was daraus folgt** — der schwerste
Fund war ein stiller Datenverlust: Der Spiegel schrieb die Rueckseiten-Spalte
bedingungslos, das Portal hatte nur ein Dateifeld. Wer seinen Ausweis erneuerte,
verlor die Rueckseite aus Export und Akte. Behoben, plus das zweite Dateifeld,
plus der von Spec §12 verlangte Test, der die GANZE Mitarbeiterzeile
vorher/nachher vergleicht.

**Korrigiert aus der Kundenrunde:** Die Dateiauswahl erzwingt keine Kamera mehr
(mein Planfehler). Aufenthaltstitel und Arbeitsgenehmigung koennen unbefristet
sein. Die HR-Bestaetigung ist raus — sie hatte keine Wirkung.

**Wichtige Korrektur an der Spec:** Der Satz „an denen die harte Einsatzsperre
haengt" war falsch. Die Sperre gibt es fuer BEWERBER (LegalStatusGate), nicht
fuer Mitarbeiter; die Spalten werden an acht Stellen gelesen, nirgends fuer eine
Sperre. Eine Bestaetigung MIT Wirkung braeuchte: das Datum erst bei der
Bestaetigung spiegeln statt sofort beim Upload. Spalten und Katalog-Methode
bleiben dafuer stehen.

### Als Naechstes

- **Canvas-Abgleich** — Canvas 67 beschreibt noch den Zuschnitt VOR dem
  Telefonat (Massenversand als Kern). Sonst erwartet Markus Ende Oktober die
  Quotenansicht und bekommt die Selbstbedienung.
- Vier Schritte in die Meilensteine von Canvas 67 (dort steht heute
  „Termine offen — bewusst")
- Dann Schritt 3: hochladen. Foto aufnehmen, Gueltigkeit dazu, alte Fassung
  wird abgeloest — und der Fristenlauf, der sich meldet, bevor etwas ablaeuft.

---

## Offen — und bei wem

### Markus
- **540 abgelaufene Nachweise am ersten Tag** (davon 342 Immatrikulationen).
  Erinnerung oder nur Aufgabe im Portal? Vorschlag: nur Aufgabe, sonst gehen am
  Starttag ueber 500 WhatsApps raus.
- Text fuer die Erinnerungs-Vorlage → Meta, 1–3 Werktage Genehmigung
- Testmitarbeiter benennen (vor Schritt 3)

### Olaf (ZAS)
- `EUBuerger` kommt leer — 1.288 von 1.558. *(Teilweise geloest: `9bb2f33`
  leitet den Status aus `AufenthaltGenehmigungErforderlich` ab.)*
- `FiktionBis` fehlt im Export. Kuerzeste Gueltigkeit, entscheidet ueber die
  Arbeitserlaubnis, Ablaufdatum existiert nirgends.
- `UplPass` fehlt in der CSV, obwohl der Datei-Slot `emp-pass` existiert
- Die `MA1000000xxx`-Nummern — mutmassliche Quelle der Dubletten

### Martin
- Zugriff auf `martin3r-me/platforms-avatar` fuer `SHAbdigital`. Ohne ihn
  schlaegt jedes `composer update` auf demo fehl, unabhaengig von diesem Projekt.
- Kapazitaet: Runde 1 sind 70–100 h. Was im Betrieb dafuer liegen bleibt, ist
  eine Entscheidung, keine Nebenwirkung.

### Anwalt
- Befristete Vertraege: einfache Signatur genuegt nicht (§ 14 Abs. 4 TzBfG).
  Rund 1.000 Arbeitsvertraege im Jahr. Weg ab sofort festlegen (Papier bei der
  Schulung vs. QES), Altbestand getrennt bewerten.
- Offene Detailfrage: Reicht auf Arbeitgeberseite ein qualifiziertes Siegel,
  oder muss eine natuerliche Person signieren?

### Unter uns
- 13 Personen-Gruppen aus dem Paar-Audit brauchen eine HR-Entscheidung:
  Zweitanstellung oder Dublette? Alle sind Drillinge mit auffaelligen Nummern.

---

## Entscheidungen

| Datum | Entscheidung | Warum |
|---|---|---|
| 22.09. | Runde 1 ist **Selbstbedienung**, Massenversand wird Runde 2 | Markus' Schmerz laeuft von unten nach oben: Mitarbeiter schicken, HR tippt ab |
| 22.09. | Kein dauerhaft abonnierter Kalender, nur Einzeltermin | Abo geht auf Android nicht, Aktualisierung liegt bei Google/Apple und dauert bis zu einen Tag. Aenderung am Abnahmestand — braucht Markus. |
| 23.09. | Kopplung an Canvas 68 faellt — Portal geht mit dem heutigen Login live | Markus' Forderung nach Oktober. Preis: ein Login-Wechsel spaeter. |
| 23.09. | Katalog als PHP-Klasse, nicht als Tabelle | Zuordnung auf die Altspalten traegt Doppelschreiben, Umzug und Export. Eine Tabelle koennte davon wegdriften. |
| 23.09. | Doppelschreiben **observer-frei** ueber den Query-Builder | Sonst setzte jeder Upload `zas_changed_at` und die erste Welle spuelte den halben Bestand in `updates.csv` — der Vorfall vom 02.09. |
| 24.09. | Zusammengefuehrte Akte nur bei gleichem Marker **und** gleicher Nummer | Der Marker steuert ab jetzt Sichtbarkeit. 332 von 336 Gruppen erfuellen beides; die vier uebrigen sehen nur ihre Anstellung. |
| 24.09. | Rueckfrage beim ersten Login entfaellt | Waere ein Ablauf fuer vier Faelle. HR-Liste genuegt. |
| 24.09. | Drei neue Gueltigkeiten (Pass, Visum, Fiktion) gehen **nicht** in den Export | Kein Eingriff am ZAS-Export in Runde 1. Ein Test haelt es fest. |
| 24.09. | Zwei Zahlensysteme getrennt fuehren | Canvas-Stunden sind fuer Markus und die Rechnung richtig, fuer die Tagesplanung falsch. Gemessener Faktor bei reiner Logik: rund zwoelf. |
| 24.09. | Kein drittes Canvas | Zwei Kundendokumente synchron zu halten kostet genug. Spec und diese Notiz liegen beim Code. |

---

## Umstellung und Kommunikation

Betroffen von der Umstellung sind **nur die Menschen, die das Portal heute
tatsaechlich nutzen** — also die, die sich schon einmal mit Geburtsdatum und
Ausweis-Endziffern angemeldet haben. Wie viele das sind, wissen wir noch nicht:

```sql
SELECT
  SUM(portal_verified_at IS NOT NULL)                          AS jemals_angemeldet,
  SUM(portal_last_seen_at >= CURDATE() - INTERVAL 30 DAY)      AS letzte_30_tage,
  SUM(portal_last_seen_at >= CURDATE() - INTERVAL 90 DAY)      AS letzte_90_tage
FROM rec_employees WHERE is_active = 1;
```

### Was sich fuer sie NICHT aendert

- **Dieselbe Adresse.** Bei Gate 6 uebernimmt die Hauptroute; alle Links aus
  alten WhatsApp-Nachrichten bleiben gueltig. Niemand muss etwas neu verschicken.
- **Dieselbe Anmeldung.** Geburtsdatum plus Ausweis-Endziffern, unveraendert.
  Der Wechsel auf das Konto kommt erst mit Canvas 68.
- **Dieselbe Sperre.** Altes und neues Portal teilen sich die Cache-Schluessel
  fuer den Versuchszaehler.

### Was sich sehr wohl aendert

Die Optik komplett — und vor allem: **Das Portal sagt ihnen jetzt, was fehlt.**
Wer bisher nichts gesehen hat, sieht auf einmal eine Aufgabenliste. Bei rund 540
abgelaufenen Nachweisen im Bestand ist das fuer viele kein leerer Bildschirm.

Die Nachricht an die Mitarbeiter ist deshalb nicht „du musst dich umgewoehnen",
sondern „dein Portal zeigt dir jetzt, was wir noch von dir brauchen".

### Umstellung in Stufen

`portal_v2_since` am Mitarbeiter: NULL = altes Portal, Datum = neues. Umgestellt
wird per Kommando, zurueckgenommen mit NULL. Damit ist jede Welle eine
bewusste Handlung und im Datensatz nachvollziehbar.

---

## Arbeitsweise

- **Zwei Arbeitsverzeichnisse**: `platforms-recruiting` auf `main` (Kundenarbeit),
  `platforms-recruiting-portal` auf `feat/ma-portal` (dieses Projekt)
- **Rebase**, sobald `main` eine Datei anfasst, die wir auch anfassen —
  nicht nach Kalender
- **Tests**: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
- **Deploy auf demo**: `tools/deploy-lock.py` — `composer update` ist dort durch
  das fehlende Avatar-Repo blockiert. Bei Migrationen zweimal deployen,
  danach `queue:restart`.
- **Lokal laeuft keine App** (keine `.env` in demo oder meingedeck).
  Pruefung ueber Tests, Ansicht auf dem Server.

---

## Zahlen aus dem Bestand (23.09.2026)

1.558 Mitarbeiter, 1.529 aktiv.

| | |
|---|---|
| koennen sich **nicht** anmelden (aktiv) | **7** |
| per WhatsApp nicht erreichbar | ~12 |
| Personen mit zwei Anstellungen | **336 Gruppen**, davon 332 mit gleicher Nummer |
| abgelaufene Nachweise | **~540**, davon 342 Schul-/Immatrikulation |
| laufen in 60 Tagen ab | 283 |
| ohne EU-Kennzeichen | 1.288 (83 %) |
| unter 18 | 53 |
| ohne verknuepfte Bewerbung | 1.235 (79 %) |
