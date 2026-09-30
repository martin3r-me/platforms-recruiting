# Waeschepakete je Taetigkeit — Design

**Stand:** 30.09.2026
**Anlass:** Kundenwunsch Markus Ammerer (Mail-Wechsel 29.09.2026) — in einer
Veranstaltung sollen Service und Logistik unterschiedliche Kleidung
bestaetigt bekommen. Er hat elf Pakete geliefert (Name + Kleidungstext) und
angeboten, spaeter Taetigkeitsgruppen zu definieren. Freigabe seiner Seite am
29.09.2026 ("alles klar, das ist gut").

## Problem

Die Kleidungsvorgabe erreicht den Mitarbeiter heute ueber **ein einziges
Freitextfeld je Veranstaltung**: ZAS liefert `mitarbeiter_info`, der Import
legt es in `rec_dispo_events.dresscode` (`ZasDispoImportPlanner.php:58`), die
Einsatz-Seite zeigt es als Kasten "Kleidung / Infos"
(`employee-assignments.blade.php:322`).

Unterschiedliche Taetigkeiten werden darin **von Hand untergebracht**. VA 1558
und 1559 (Atruvia, Tag 1 und 2):

```
Bitte folgende Kleidung:
Weisse Bluse/Hemd (gebuegelt)
Schwarze Stoffhose
...

Logistiker bitte folgende Kleidung
Schwarzes Oberteil (ohne Logo)
Schwarze Hose
Schwarze Schuhe
Keine Jogginghose !
```

Jede Servicekraft liest den Logistiker-Absatz mit und umgekehrt; wer nicht bis
unten liest, kommt falsch angezogen. Bei VA 1616 steht nur noch der
Logistik-Teil drin. Tag 1 und Tag 2 derselben VA weichen voneinander ab, weil
beim Kopieren "Keine Jogginghose !" verlorenging.

**Zahlen zum Feld** (Stand 29.09.2026, 142 VAs mit Text):

| Inhalt | Anzahl |
|---|---|
| nur Kleidung | 94 |
| gemischt (Kleidung + Organisatorisches) | 4 |
| nur Organisatorisches | 11 |
| von beiden Wortlisten nicht getroffen | 33 |

Die 33 sind nicht leer, sondern kurze Orga-Notizen ohne die gesuchten Woerter
("Eeeingang", "03.12 Aufbau VA"). Zusammen traegt also **rund ein Drittel der
Texte etwas anderes als Kleidung** — Ansprechpartner, "Ausweis bitte
mitnehmen", "Bitte nur eintragen wenn du alle drei Tage uebernehmen kannst".
Das Feld einfach auszublenden wuerde diese Angaben lautlos loeschen.

## Entscheidung

Ein **Paket** ist ein Name plus ein Kleidungstext. Die Namen sind interne
Auswahlhilfen — der Mitarbeiter sieht **nur den Inhalt**, nie den Namen
(Kundenentscheid 30.09.). Markus' elf Zeilen sind der Startbestand.

**Stufe 1 (diese Spec):** Das Paket wird **von Hand** gewaehlt — je
Veranstaltung und, abweichend davon, je Taetigkeit innerhalb der
Veranstaltung. Gewaehlt wird im bestehenden Fenster "Bestätigung senden".

**Stufe 2 (spaeter, eigene Spec):** Taetigkeitsgruppen mit automatischer
Zuordnung. Markus definiert die Gruppen, sobald er aus der Praxis weiss, wie
sie geschnitten sein muessen. Die Aufloesung bekommt dafuer eine Stufe
dazwischen — kein Umbau.

Bewusst nicht andersherum: baut man die manuelle Auswahl ohne Speicherort,
tippt die Dispo bei jeder VA dieselbe Zuordnung neu und wir haetten nirgends
stehen, was "Logistik" eigentlich braucht.

## Datenmodell

**Neue Tabelle `rec_dispo_dress_packages`** — der Paket-Katalog.

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `uuid` | | Konvention |
| `team_id` | fk | Mandant |
| `name` | string(120) | interne Auswahlhilfe, nie beim MA sichtbar |
| `items_text` | text | was der Mitarbeiter liest, wortwoertlich |
| `is_active` | bool, default true | Ausmustern ohne Loeschen |
| `sort_order` | int | Reihenfolge in der Auswahl |

Bewusst **eigene Tabelle statt Core-Lookup**: ein Lookup-Wert ist ein Label,
hier gehoert der Text untrennbar dazu. Ein Lookup wuerde den Inhalt in ein
Feld zwingen, das nicht dafuer da ist.

**Neue Tabelle `rec_dispo_event_dress`** — was in dieser VA gilt.

| Spalte | Typ | Zweck |
|---|---|---|
| `rec_dispo_event_id` | fk, cascade | die VA |
| `taetigkeit` | string, NOT NULL, default `''` | `''` = gilt VA-weit; sonst genau dieser Taetigkeitswert |
| `rec_dispo_dress_package_id` | fk | das Paket |

Unique ueber (`rec_dispo_event_id`, `taetigkeit`). **`taetigkeit` ist bewusst
NOT NULL mit Leerstring statt NULL:** MySQL laesst in einem Unique-Index
beliebig viele NULLs zu — die VA-weite Vorgabe waere damit mehrfach anlegbar
und die Aufloesung nicht mehr eindeutig. Der Taetigkeitswert wird
**so gespeichert, wie er in den Einbuchungen steht** — Freitext aus ZAS, keine
Normalisierung. Schreibvarianten sind damit verschiedene Zeilen; das faengt
Stufe 2 ueber die Gruppen ab, nicht Stufe 1 ueber Raten.

**Neue Spalten an `rec_dispo_assignments`:**

- `rec_dispo_dress_package_id` (nullable) — das beim Versand **festgeschriebene**
  Paket.
- `dress_frozen_at` (timestamp, nullable) — wann.

**Neue Spalten an `rec_dispo_events`:**

- `hinweis` (text, nullable) — unser eigener Hinweistext, ersetzt den
  ZAS-Kasten, sobald ein Paket greift.
- `dresscode_ack` (text, nullable) — Kopie des ZAS-Textes zum Zeitpunkt der
  Bestaetigung im Sende-Fenster.
- `dresscode_ack_at` (timestamp, nullable).

Der Dispo-Import fasst diese Spalten **nie** an: `RecDispoEvent::updateOrCreate`
(`ZasDispoWebexportImporter.php:151`) schreibt nur den festen Attribut-Satz des
Planners. Pflegehinweis: diesen Satz nicht um die neuen Spalten erweitern,
sonst raeumt die naechste Lieferung die Arbeit der Dispo ab.

## Welches Paket gilt

Aufloesung **je Einbuchung** (nicht je VA — eine Person kann an Tag 1 Service
und an Tag 2 Logistik machen, `eventGroups()` haelt `taetigkeit` pro Tag):

1. festgeschriebenes Paket an der Einbuchung (`rec_dispo_dress_package_id`)
2. Zeile in `rec_dispo_event_dress` mit exakt dieser `taetigkeit`
3. Zeile in `rec_dispo_event_dress` mit `taetigkeit = ''` (VA-weit)
4. kein Paket → alles bleibt wie heute

*(Stufe 2 schiebt zwischen 3 und 4 den Gruppen-Default.)*

Reine Leseoperation in einem eigenen Dienst `DispoDressResolver` — keine
Schreibzugriffe, damit die Einsatz-Seite ihn gefahrlos aufrufen kann.

## Anzeige auf der Einsatz-Seite

Betroffen ist **ausschliesslich** der Kasten "Kleidung / Infos". Datum,
Zeiten, Ort, Anfahrt, Ansprechpartner, Anhaenge, Bestaetigen-Knopf: unberuehrt.

- **Kein Paket in dieser VA:** unveraendert wie heute, inklusive Ueberschrift.
- **Paket aufgeloest:** Kasten **"Deine Kleidung"** mit `items_text`. Der
  ZAS-Text erscheint nicht mehr. Ist `hinweis` gefuellt, steht er darunter als
  **"Hinweise zur Veranstaltung"**.
- **Loesen die Tage einer VA unterschiedlich auf** (Service Tag 1, Logistik
  Tag 2), wandert "Deine Kleidung" aus dem VA-Kopf in die Tageszeile. Sind
  alle Tage gleich, bleibt es ein Kasten im Kopf — sonst liest man dasselbe
  dreimal.

## Sende-Fenster

`openSendModal()` / `doSendConfirmations()` in `Livewire\Dispo\Events\Show`.
Das Fenster traegt bereits Vorlaufzeit, Ansprechpartner, Eskalationsplan und
Tagesfilter und schreibt beim Senden an die VA zurueck — die Auswahl fuegt
sich in dieses Muster ein.

Neu im Fenster:

1. **Ein Paket-Select je Taetigkeit**, die in dieser VA vorkommt, plus eine
   Zeile "alle uebrigen" fuer die VA-weite Vorgabe. Die Taetigkeiten kommen
   aus den Einbuchungen der VA, nicht aus einem Katalog.
2. **Der bisherige ZAS-Text steht sichtbar daneben.** Wer waehlt, sieht im
   selben Moment, was gleich verschwindet.
3. **Riegel:** Ist `dresscode` nicht leer und wird zum ersten Mal ein Paket
   gesetzt, muss einmal aktiv bestaetigt werden ("Text gesehen"). Erst dann
   ist der Senden-Knopf frei. Gespeichert wird die Kopie in `dresscode_ack`.
4. **Uebernehmen-Knopf:** kopiert den ZAS-Text in das Feld `hinweis`, wo er
   gekuerzt werden kann. Kein automatisches Schneiden (siehe Verworfenes).
5. **Vorschau:** was der Mitarbeiter sehen wird — Kleidung oben, Hinweis
   darunter.

Gespeichert wird beim Senden, im selben `$event->update([...])`, das heute
schon `vorlauf_minuten` und `ansprechpartner` schreibt.

## Einfrieren beim Versand

`DispoConfirmationSender::send()` stempelt jeder Empfaenger-Einbuchung das
aufgeloeste Paket in `rec_dispo_dress_package_id` + `dress_frozen_at`.

Grund: Aendert Markus drei Wochen spaeter den Inhalt von "Logistik", darf sich
nicht rueckwirkend aendern, was jemand bestaetigt hat. Die Aufloesungskette
greift danach nur noch fuer Einbuchungen ohne Stempel.

Die WhatsApp-Nachricht selbst bleibt unveraendert — ihr Variablen-Vertrag ist
bei Meta genehmigt (`DispoConfirmationSender`, Kopfkommentar). Die Kleidung
steht hinter dem URL-Button, nicht im Nachrichtentext.

## Pflegemaske

Neue Route `/dispo-dress-packages` neben den bestehenden Dispo-Routen
(`routes/web.php:109-125`), Liste + Anlegen/Bearbeiten/Deaktivieren.

**Auflage (bekannter Speicher-Bug, siehe `Livewire\Dispo\Settings`
Kopfkommentar):** schlichte Inputs/Selects mit `wire:model` und explizitem
Speichern-Knopf — **nicht** `x-ui-input-select` + `@entangle`.

Startbestand: Markus' elf Zeilen ueber ein einmaliges Artisan-Kommando mit
Probelauf (`--dry-run`, Muster der uebrigen Kommandos im Modul). Die doppelte
"schwarze Schuerze" in "Standard schwarz-schwarz" ist bereinigt
(Kundenentscheid 30.09.: Tippfehler).

## Was ausdruecklich nicht passiert

- **Kein Schreiben in `rec_employee_hr_data.linen_package_items`.** Das Feld
  heisst im HR-Backend "Waeschepaket erhalten", ist die Ausgabe-Liste und geht
  ueber `ZasEmployeeFieldResolver` an ZAS zurueck. Hier geht es um eine
  Soll-Vorgabe je Einsatz. Gleiche Auswahl denkbar, niemals dasselbe Feld.
- **Kein neuer ZAS-Export, keine neue Spalte Richtung Olaf.**
- **Kein neues Meta-Template.**
- **Keine Abhaengigkeit von `hr_data.qualifications` oder
  `dispo_taetigkeiten`.** Beide sind leer und bleiben es, solange ZAS nicht
  liefert (siehe Memory `project_zas_qualifikation_nie_geliefert`).

## Verworfene Alternativen

**ZAS-Text am Wortlaut schneiden.** Fast alle Kleidungsteile beginnen mit
"Bitte folgende Kleidung:"; daran haette sich der Kleidungsabsatz automatisch
entfernen lassen. Verworfen (Kundenentscheid 30.09.): eine Konvention ist
keine Struktur. VA 1616 nennt Kleidung ohne Ankuendigung, 1696 und 1378
benutzen "Kleidung:". Eine Regel, die entscheidet, was der Mitarbeiter sieht,
darf nicht auf Schreibgewohnheiten stehen. Der Mensch im Sende-Fenster
entscheidet stattdessen — er ist ohnehin dort.

**Paket nach Ort/Kunde/Taetigkeit modellieren.** Markus' Liste mischt drei
Achsen ("Logistik", "Lanxess Arena", "Standard schwarz-weiss"). Verworfen: das
sind interne Etiketten, keine Systematik. Eine flache Liste bildet ab, wie
gearbeitet wird.

**ZAS-Text komplett ausblenden, ohne Ersatz.** Wuerde bei rund einem Drittel
der VAs Ansprechpartner und Auflagen loeschen.

## Tests

Konvention des Moduls: Unit rein, Integration mit Capsule (`tests/Integration`).

1. `DispoDressResolver` — Vorrang der vier Stufen, inklusive "Tag 1 anders als
   Tag 2" und "kein Paket → null".
2. Einfrieren: nach `send()` traegt jede Empfaenger-Einbuchung ein Paket;
   spaetere Aenderung am Paket-Datensatz aendert die Anzeige der
   festgeschriebenen Einbuchung nicht.
3. `eventGroups()` der Einsatz-Seite: mit Paket steht kein ZAS-Text in der
   Ausgabe; ohne Paket steht er unveraendert drin.
4. Riegel: Senden ohne Bestaetigung bei nicht-leerem `dresscode` wird
   abgewiesen; mit Bestaetigung laeuft es und `dresscode_ack` ist gesetzt.
5. Import fasst `hinweis`, `dresscode_ack` und die Paket-Spalten nicht an.
6. Blades ueber `tools/blade-check.php` (kein `php -l` auf `.blade.php`).

## Offene Punkte

- **Paketliste als Einzelteile statt Textblock.** Markus' Zeilen tragen
  Angaben wie "Hemd via Kunden" und "Oberbekleidung vom Kunden". Erst mit
  Einzelteilen kann die Seite "bekommst du von uns" / "bring selbst mit"
  trennen. Bewusst zurueckgestellt; Markus wurde um einen einheitlichen
  Trenner gebeten, damit das spaeter ohne Nachpflege geht.
- **Stufe 2: Taetigkeitsgruppen.** Braucht vorher die Haeufigkeitsliste der
  echten `taetigkeit`-Werte, damit Markus an der Realitaet entlang schneidet.
- **Abgleich Paketliste gegen Bestand.** Die 94 reinen Kleidungstexte gegen
  die elf Pakete halten, damit auffaellt, was fehlt.

## Deploy

Additive Migrationen, kein Datenumbau. Nach dem Merge: Bump meingedeck,
Forge-Deploy mit `migrate` und `view:clear`. Der Versand laeuft synchron im
Request (`doSendConfirmations()` ruft den Sender direkt), es haengt also kein
Queue-Pfad an dieser Aenderung — `queue:restart` ist nicht noetig, schadet
aber auch nicht. Sichttest: eine echte VA mit zwei
Taetigkeiten, Paket setzen, Bestaetigungsseite mit echtem Token oeffnen.
