# Jobs anbieten — Filter nach ZAS-Status (GO / MA)

Stand 20.10.2026. Auslöser: Mail Daniel Roesberg an Markus Ammerer, 20.10.2026 15:51, Betreff „AW: Wunsch Dispo Templates“, Wortlaut:

> 1. Filter / Alle Mitarbeiter die auf Go sind
> 2. Filter / alle die auf MA stehen
> 3. Wenn es Programmierbar ist: Alle die auf Inaktiv gestellt worden sind. Vom Gestern bis ein Jahr zurückgerechnet. Falls wir wirklich mal an unsere Grenzen kommen

Dazu aus der Mail von 15:22: „Ich denke auch, dass wir zuerst die Mitarbeiter anschreiben sollten, die auf go stehen.“

Diese Spec deckt Punkt 1 und 2 ab. Punkt 3 ist unten als Stufe 2 beschrieben und wird **nicht** gebaut.

## 1. Begriffe

Status kommt aus ZAS (`rec_employee_hr_data.export_status`, Spec 2026-10-14-zas-status-inaktiv): GO = aktiver Vertrag, MA = gerade kein Vertrag (braucht neuen), INAKTIV = gesperrt. Inaktive sind schon heute aus Jobs anbieten ausgeschlossen (`is_active = false`).

Jobs anbieten rechnet je **Person** (Anker der Identitätsgruppe, `DispoJobKandidaten::laden`). Eine Person kann zwei aktive Anstellungen haben (RG + MA). Deshalb braucht der Filter einen Status je Person:

| Stati der aktiven Datensätze der Person | Personen-Status |
|---|---|
| mindestens ein GO | **GO** |
| kein GO, mindestens ein MA | **MA** (= heutiges `ohne_vertrag`) |
| kein Status bekannt (kein hrData / leer) | **GO** |

Die letzte Zeile ist eine bewusste Festlegung: Der Datenbank-Standard ist GO, und eine Person darf nicht still aus beiden Filtern fallen. Heute kommt der Fall laut Abfrage 20.10. in Mönchengladbach nicht vor.

## 2. Verhalten

- **Dritter Filter neben Filiale und Gruppe.** Alle drei werden UND-verknüpft und bilden die Grundmenge, wie heute Filiale und Gruppe (`DispoJobRecipientPlanner`, Grundmenge, kein Topf). Wer wegen des Status nicht dabei ist, erscheint nicht in den Töpfen „wird nicht angeschrieben“.
- **Zwei Häkchen**, keine Auswahlliste: „GO – aktiver Vertrag“ und „MA – braucht neuen Vertrag“. Beide zusammen = alle aktiven Mitarbeiter. Die Häkchen überschneiden sich nie, weil jede Person genau einen Status hat (Tabelle oben).
- **Zahl neben jedem Häkchen:** Personen mit diesem Status in der aktuell gewählten Filiale und Gruppe, vor den Töpfen. Sie ändert sich mit Filiale und Gruppe, aber nicht mit dem eigenen Häkchen. So sieht der Disponent vorher, wie viele er mit MA dazunimmt. Beispiel: „GO – aktiver Vertrag · 312 Personen“.
- **Kein Status gewählt** → Senden gesperrt mit „Bitte wähle mindestens einen Status.“ (wie bei Filiale und Gruppe).
- **Vorbelegung neue Anfrage:** nur GO (Daniels Wunsch „zuerst GO“). MA wird bei Bedarf dazugeklickt oder in einer späteren Welle genommen.
- **Vorbelegung neue Welle:** wie die letzte Welle der Anfrage (so wie Filiale heute).
- **Der Hinweis „davon M mit Status MA (brauchen neuen Vertrag)“** im Zahlen-Kasten und der Chip in der Zusagen-Tabelle bleiben unverändert.
- **Wellen-Liste der Anfrage:** zeigt den Status mit, z. B. „· nur GO“ oder „· GO + MA“.
- **Alte Wellen** (vor dieser Änderung, Spalte leer) zählen als GO + MA — so wurden sie verschickt.
- Die Zahl „· 512 Mitarbeiter“ je Filiale bleibt wie sie ist (Datensätze; User-Entscheidung 20.10.: zwei Anstellungen zählen gewollt doppelt).

## 3. Datenhaltung

- `rec_dispo_job_waves.stati` — JSON, nullable. `null` = GO + MA (alte Wellen). Neue Wellen speichern immer eine Liste, z. B. `["GO"]`.
- Keine weitere Migration, kein neuer Job.

## 4. Stufe 2 — Inaktive der letzten zwölf Monate (nicht gebaut)

Daniels Punkt 3 hat zwei Hürden:

1. **Datum fehlt.** Wir wissen nur, seit wann ZAS uns jemanden als inaktiv meldet. Bei allen Inaktiven ist das der Tag der Umstellung. „Seit wann in ZAS inaktiv“ müsste Olaf als Datum mitliefern (neue Spalte → vorher ankündigen).
2. **Inaktive sind heute bewusst gesperrt.** Die Jobangebots-Seite zeigt ihnen „Zugang gesperrt“ (`JobOffersZugang::gesperrt`), Kandidaten werden nur aus `is_active = true` geladen. Ein Notfall-Filter für Inaktive müsste diese Sperre für Jobangebote gezielt aufheben. Außerdem ist ihr ZAS-Portal gesperrt; nach einer Zusage müsste erst wieder ein Vertrag her.

Entscheidung dazu erst, wenn das Datum geklärt ist.

## 5. Tests

- Personen-Status je Kandidat: GO+MA-Paar = GO, nur MA = MA, ohne Status = GO.
- Planer: Status-Filter als Grundmenge (kein Topf), GO allein, MA allein, beide, keiner.
- Zahlen: Zahl je Status hängt an Filiale und Gruppe, nicht am eigenen Häkchen.
- Prüfung vor dem Senden: leerer Status sperrt.
- Welle speichert `stati`; Versand-Job nutzt sie; `null` verhält sich wie GO + MA.

## 6. Auslieferung

Migration (eine Spalte) → `migrate`, dazu `view:clear`. `queue:restart` nötig, weil der Versand-Job die neue Spalte liest.
