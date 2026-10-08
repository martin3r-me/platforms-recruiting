# ZAS-Qualifikationen aus dem Dispo-Webexport — Design

**Stand:** 2026-10-08
**Anlass:** Mail Olaf Michel 06.10.2026 — die Qualifikationen liegen nicht im
MA-Export, sondern als Bloecke `{Dispo4}` und `{Dispo5}` im Dispo-Webexport.

## Ausgangslage, gemessen

Alle Zahlen aus Lieferung #254 (07.10.2026 13:19, prod), gelesen mit
`ZasDispoBlockSplitter` ueber die Rohdatei:

| | |
|---|---|
| `{Dispo4}` Katalogeintraege | 271 |
| `{Dispo5}` Zeilen | 7.611 |
| verschiedene Personalnummern | 1.442 |
| verschiedene Taetigkeits-IDs | 170, davon 169 mit Katalogname |
| aktive MA mit Zuordnung | 1.409 von 1.692 |
| aktive MA ohne Zuordnung | 266 — ausnahmslos Neuzugaenge (264 juenger als 90 Tage, 0 aelter als ein Jahr) |
| aktive MA ohne Personalnummer | 17 (eigenes, aelteres Thema) |
| Taetigkeiten je MA | min 1 / median 3 / max 87 |

Zeilenformat, beide Bloecke mit **leerer vierter Zelle**:

```
{Dispo4}
1;Küchenchef;RG1;
{Dispo5}
RG1464;RG8;17;
```

`{Dispo4}` = `Nr;Name;RG-Code;` — der Schluessel ist Spalte 3, nicht die letzte.
`{Dispo5}` = `PNr;Taetigkeits-ID;Anzahl Einsaetze;`

## Entscheidungen

**1. Abgleich ueber den Namen, nicht ueber die ID.**
Gemessen: alle 74 Klartexte aus `{Dispo}` haben einen Katalognamen, und 7 Namen
tragen zwei IDs (`Logistiker` = RG13+RG271, `Broich Cateringhilfe` = RG27+RG269,
`Thekenmitarbeiter` = RG76+RG270, `Cateringhilfe` = RG80+RG272, `Abraeumer` =
RG89+RG98, `Cateringhilfe Live` = RG287+RG288, `Self Service` = RG289+RG290).
Die zweiten IDs bilden die zusammenhaengenden Bloecke RG269–RG272 und
RG287–RG290 — ZAS hat dieselbe Taetigkeit doppelt angelegt. Der Name fuehrt sie
korrekt zusammen; die ID wuerde sie trennen und jedes Waeschepaket doppelt
pflegen lassen. **Die frueher notierte Regel „immer auf die ID keyen" gilt hier
nicht.**

**2. Zielfeld ist `rec_employee_hr_data.dispo_taetigkeiten`.**
Es existiert seit 15.09.2026 samt `dispo_taetigkeiten_synced_at`, Dienst
`ZasDispoTaetigkeitSync`, Auswahlliste `dispo_taetigkeit` und Anzeige in der
MA-Akte. Gebaut wurde es fuer die MA-Export-Spalte `DispoTaetigkeiten`, die nie
kam. Bedeutung identisch, nur die Quelle ist eine andere.

**3. ZAS ist fuer dieses Feld fuehrend, es bleibt unbearbeitbar.**
Wer von Hand anhaken will, nutzt das daneben liegende `qualifications`
(Auswahlliste `qualifikation`, in der MA-Akte unter „Qualifikation &
Altbestand" editierbar). Das geht als Spalte `Qualifikation` **zu ZAS raus**.
Beides in ein Feld zu legen hiesse, ZAS seine eigenen Daten als Aenderung
zurueckzuschicken — der Vorfall vom 02.09.

**4. Der MA-Importer-Pfad fuer `DispoTaetigkeiten` wird stillgelegt.**
Entscheidung des Kunden am 08.10.: „er wird das nicht liefern, wir machen den
import dafuer dann dicht". Sonst schrieben spaeter zwei Quellen auf dasselbe
Feld und ueberschrieben sich je nach Reihenfolge der Lieferungen.

**5. Platzhalter-Personalnummern werden verworfen.**
`RG0` und `RG14` stehen in der Dispo fuer unbesetzte Plaetze, und `RG14` trifft
auf einen **echten** Mitarbeiter (MA 126, siehe
`project_dispo_zahlen_und_platzhalter`). In `{Dispo5}` traegt `RG14` vier
Zeilen, darunter 782 Einsaetze auf `RG8` — ohne Filter bekaeme MA 126 diese
Qualifikationen angehaengt. Andere auffaellige Nummern (`RG902`, `RG999999`)
brauchen **keinen** Filter: sie treffen auf keinen Mitarbeiter und laufen
ohnehin als `unmatched` ins Protokoll. Hartkodiert wird nur, was nachweislich
kollidiert.

**6. Die Auswahlliste bekommt den ganzen Katalog, nicht nur das Zugewiesene.**
Damit kann die MA-Akte zeigen, was jemand **nicht** kann — „kann der Logistik?"
wird beantwortbar statt nur „was macht er ueblicherweise".

**7. Der Qualifikations-Sync darf den Dispo-Import nie umwerfen.**
Er laeuft nach der Haupttransaktion in eigenem try/catch. Qualifikationen sind
Sekundaerdaten; ein Fehler dort darf keine Einbuchungen zurueckrollen.

## Bekannte Grenzen

- **Der MA-Mandant fehlt.** `{Dispo4}`/`{Dispo5}` enthalten ausschliesslich
  `RG`-Codes. `{Dispo2}` zeigt aber 1.082 Zeilen mit `MA`-Praefix (42 IDs, 24 %
  der Einsaetze). Fuer den MA-Mandanten gibt es weder Katalog noch Zuordnung.
  Offene Frage an Olaf, kein Blocker fuer diesen Bau.
- **Die ID `RG`** (nur Praefix, kein Wert) steht in 30 von 7.611 Zeilen. Sie hat
  keinen Katalogeintrag und wird verworfen.
- **266 Neuzugaenge ohne Zuordnung** bleiben leer, bis ZAS sie disponiert. Das
  ist Normalzustand, kein Defekt.
- **Die Waeschepakete haengen nicht hieran.** Sie greifen auf die Taetigkeit an
  der Einbuchung (`{Dispo}.taetigkeit`, 4.831 Zeilen, 0 ohne Wert). `{Dispo5}`
  bringt Planbarkeit vorher, keine bessere Kleidung.

## Nicht in diesem Bau

- Umstellung der Dispo-Mitarbeiterkarten von `qualifications` auf
  `dispo_taetigkeiten` (`DispoEmployeeGateway::qualifications()`). Eigene
  Entscheidung, eigener Bau.
- Alles, was den MA-Mandanten betrifft.
