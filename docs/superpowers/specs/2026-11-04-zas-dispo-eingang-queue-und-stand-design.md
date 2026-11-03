# ZAS-Dispo-Eingang: Verarbeitung im Hintergrund + „ZAS-Stand“

Stand 04.11.2026. Anlass: Mail Markus 03.11. („Drei Versuche, drei verschiedene Fehlermeldungen, die Daten im HCM sind von gestern 14:30 Uhr … Wir benötigen hier bitte eine Lösung, dass der Export bestätigt angekommen ist.“) und Mail Olaf 03.11. (Exportdatei zu groß, Umstellung auf ADODB-Stream, Frage „können das auch Fehlermeldungen von Dir gewesen sein“).

## 0. Befund (Prod-Daten 30.10.–03.11., Dateien #381–#401)

- Jede volle Lieferung (2,9 MB, ~4.200 Dispo-Zeilen) brauchte bei uns **129–141 s**, synchron in der HTTP-Anfrage. nginx wartet 300 s und bricht nicht ab; Olafs Access-Client wartet kürzer und meldet „Send-Fehler: Das Zeitlimit für den Vorgang wurde erreicht“, obwohl die Datei gespeichert und verarbeitet ist (#396, 14:51).
- „Nicht genügend Zeichenfolgenspeicher“ und „3059 Operation durch Benutzer abgebrochen“ sind Access-intern, entstanden vor dem Senden. Keine der drei Meldungen trägt einen HTTP-Status, also kam keine von unserem Endpunkt.
- Zwischen #393 (02.11. 14:30) und #396 (03.11. 14:51) kam keine Dispo-Lieferung an. HCM stand ~24 Stunden auf altem Stand, ohne dass es jemand sah.
- Die kleinen Dateien (471–474 KB, #385/#386/#394/#395/#400/#401) sind keine abgerissenen Übertragungen, sondern ein eigener Block `{Zeiten}` (8.080–8.121 Zeilen, Form `MA18760;26.09.2026;17:00:00;21:00:00;0,5;8;0;;RG;RG19725;`), den Olaf seit 30.10. 15:45 mitschickt. Unbekannter Block → Datei gilt als verarbeitet mit Nullzählern, nichts wird markiert. Bedeutung offen (Frage an Olaf).
- #383 (30.10. 13:48) steht bis heute auf `viewable` ohne `processed_at` (Import-Hook muss damals gescheitert sein). Liegengebliebene dieser Art dürfen NICHT automatisch nachgeholt werden, sie wären älter als der Bestand.

## 1. Verarbeitung im Hintergrund

- `ZasDispoInboundController`: speichert die Rohdatei wie bisher, reiht bei Echt-Lieferungen `ProcessZasDispoInboundFile::dispatch($record->id)` ein und antwortet sofort 201. Antwort-Feld `processing` = `queued` | `none` (Probe) | `not_queued` (Einreihen gescheitert, geloggt). Der Block `import` in der Antwort entfällt (Olaf wertet nur den HTTP-Status aus).
- `ProcessZasDispoInboundFile` (Jobs): `WithoutOverlapping('zas-dispo-inbound')->shared()->releaseAfter(15)->expireAfter(timeout+60)`, `timeout 900`, `tries 120`, `maxExceptions 2`. Verarbeitet in einer Schleife die **älteste anstehende Echt-Datei, die neuer ist als die letzte verarbeitete** (`id > max(id) where processed_at not null`; `failed` setzt `processed_at` und zählt mit), höchstens `MAX_JE_LAUF = 5` je Lauf, danach reiht er sich für den Rest neu ein (Review 04.11. Fund 3: ein Rückstau darf nicht in den Timeout laufen). Die eigene `fileId` ist nur Auslöser und Log-Kontext. Damit stimmt die Reihenfolge auch, wenn der Job der jüngeren Datei zuerst dran ist, und alte Liegengebliebene (#383) bleiben liegen.
- Kann der Importer den Abschluss nicht schreiben (Review Fund 1: `notes` ist `text`/64 KB, bei einem Formatwechsel hängt jede Zeile einen Fehler an → „Data too long“), zieht der Job den Abschluss kompakt nach (Zähler, erste 20 Fehler, `errors_gesamt`, Vermerk `abschluss`). Scheitert auch das, wird die Datei in diesem Lauf übersprungen und die nächste kommt dran; nach deren Abschluss liegt sie automatisch unterhalb der letzten verarbeiteten und wird nicht mehr angefasst. Zusätzlich kappt der Importer selbst `errors` in den notes bei 100 (`ZasDispoWebexportImporter::notizen()`).
- `recruiting:dispo-reprocess` ohne ID (Review Fund 2): dieselbe Regel und **dieselbe Sperre** wie der Job (`Cache::lock('laravel-queue-overlap:zas-dispo-inbound')`), bricht ab, wenn der Job gerade läuft; Liegengebliebene werden nur genannt. Mit ID: bewusst alles, auch Liegengebliebene (der Importer schützt dabei nur den Personenwechsel über `veraltet`, nicht `missing_since`, Rebestätigung, VA-Stammdaten, Bedarfe — deshalb nie blind).
- Deploy: `queue:restart` (neuer Job), keine Migration. Cache und Queue laufen auf `database`, Locks sind dort vorhanden (andere Module nutzen `WithoutOverlapping` bereits). **Empfehlung meingedeck:** `DB_QUEUE_RETRY_AFTER` auf mindestens 1000 setzen (Standard 90 s ist kürzer als ein Import; mit mehr als einem Worker würde der laufende Job nach 90 s erneut reserviert, die Kopie dreht dann nur Warteschleifen).

## 2. „ZAS-Stand“ in der Disposition

- `ZasDispoLieferungsStand::aktuell()`: letzte verarbeitete Echt-Datei mit `einbuchungen() > 0` (notes `blocks_found` + `stats.assignments`), Suchtiefe 60 Dateien. `offen` = neueste wartende Echt-Datei oberhalb der letzten verarbeiteten (gleiche Regel wie der Job). Stufen: `ok` < 4 Std., `gelb` ab 4 Std., `rot` ab 24 Std.
- `anzeige()`: „ZAS-Stand: Di 03.11. 18:15 · 4.195 Einbuchungen“, bei gelb/rot zusätzlich „· 24 Std. alt“ bzw. „· 3 Tage alt“, bei wartender Datei „· neue Lieferung von 19:24 wird verarbeitet“.
- VA-Liste (`Dispo\Events\Index`, Computed `zasStand`, try/catch): farbige Zeile unter der Überschrift, läuft mit dem 30-s-Poll mit.
- ZAS-Eingang (`dispo/index.blade.php`): Echt-Datei ohne `processed_at` oberhalb der letzten verarbeiteten = „in Warteschlange“ (blau), unterhalb = „liegengeblieben“ (grau, Tooltip mit Reprocess-Hinweis; Review Fund 4: gleiche Regel wie Job und ZAS-Stand); verarbeitet ohne Dispo-Zeilen = „ohne Dispo-Zeilen“ (grau, z. B. `{Zeiten}`); verarbeitet mit Zeilen = „processed · 4.195 Einbuchungen“.
- Modell `RecZasDispoInboundFile`: `notizen()`, `einbuchungen()`, `wartet(int $letzteVerarbeitete)`, `liegengeblieben(int $letzteVerarbeitete)`.

## 3. Bewusst nicht gebaut (Folgethemen)

- Schutz gegen abgerissene Dateien (unsauberes Ende, deutlich weniger Dispo-Zeilen als zuletzt). Hat es am 03.11. nicht gegeben, bleibt eine Lücke: eine Teil-Datei würde alle nicht enthaltenen künftigen Einbuchungen als verschwunden markieren (ausgeblendet, keine Nachricht, nächste volle Datei holt sie zurück).
- Importdauer selbst (~30 ms je Zeile). Mit der Queue wartet niemand mehr darauf.
- Olafs Verkleinerung des Exports (15:54/16:03: 2.639 statt 4.195 Zeilen, 18:15 wieder voll): Vergangenheit weglassen ist für uns unkritisch (verschwunden gilt nur ab heute), der laufende Monat muss aber vollständig bleiben (Gruppen „Einsätze in diesem Monat“, 30-Tage-Zähler).

## 4. Tests

`ZasDispoInboundJobTest` (Reihenfolge-Beweis über `missing_since`, Liegengebliebene, Proben, Leerlauf, `failed` zählt als Abschluss, Obergrenze je Lauf, kompakter Abschluss bei gescheitertem Importer-Abschluss, Überspringen bei unabschließbarer Datei, Sperre mit geteiltem Schlüssel), `ZasDispoLieferungsStandTest` (Zeiten-Datei zählt nicht, Stufen exakt an 4 h/24 h mit den Zeiten des Vorfalls, offen vs. liegengeblieben), `ZasDispoInboundControllerTest` (sofortige 201 ohne Import, Job eingereiht, Probe nicht, Einreihen scheitert → 201 + `not_queued`, 422 leer), `DispoReprocessCommandTest` (ohne ID nur Anstehende + Warnung zu Liegengebliebenen, Abbruch bei gesetzter Sperre, mit ID bewusst, Dry-Run schreibt nichts).
