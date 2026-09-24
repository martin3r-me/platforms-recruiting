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

### Runde 1 in drei Schritten

1. **Datenbasis** — 3–4 Tage · **fertig**
2. **Huelle** — 4–5 Tage · vier Bereiche, Startbildschirm mit Aufgabenliste,
   Einsaetze eingehaengt, Anmeldeschicht herausgeloest. Erster Deploy auf demo.
3. **Selbstbedienung** — 4–5 Tage · Upload mit Gueltigkeit, Fristenlauf,
   WhatsApp-Umleitung, HR-Sicht

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

### Als Naechstes

- **Canvas-Abgleich** — Canvas 67 beschreibt noch den Zuschnitt VOR dem
  Telefonat (Massenversand als Kern). Sonst erwartet Markus Ende Oktober die
  Quotenansicht und bekommt die Selbstbedienung.
- Vier Schritte in die Meilensteine von Canvas 67 (dort steht heute
  „Termine offen — bewusst")
- Dann Schritt 2, die Huelle

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
| 24.09. | Kein drittes Canvas | Zwei Kundendokumente synchron zu halten kostet genug. Spec und diese Notiz liegen beim Code. |

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
