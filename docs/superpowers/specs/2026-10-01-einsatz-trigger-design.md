# Einsatz-Trigger — vom Datenmodell zum Prozessmotor

**Stand:** 2026-10-01
**Grundlage:** Markus' Arbeitsdeck „RheinGedeck HR-Digitalisierung — Canvas-Abgleich" (21.09.2026), Folien 5–7, 10–15, 24–27, 30; Entscheidungsblock B1/B2/B4.

---

## 1. Warum

Markus formuliert es auf Folie 30 selbst am besten:

> Canvas 2 schafft die Datenbasis. Unser Ziel braucht zusätzlich die automatische Orchestrierung.

Die Datenbasis steht seit Ende September: Person und Anstellung, ein Konto je Mensch,
Nachweise mit Gültigkeit und Historie, die Pflichtmatrix in `ProofTypes::requiredFor()`.
Was fehlt, ist **der Moment**: es gibt keine Stelle, an der ein Ereignis eine Regel
auslöst, die eine Aufgabe erzeugt.

Markus' Kernfall (Folie 6) ist heute reine Handarbeit:

> Mitarbeiter inaktiv → Einsatz gebucht → HCM erkennt Reaktivierung → HR-Check
> automatisch → Mitarbeiter erledigt To-dos. **Kein manueller Start durch HR.**

Von den fünfzehn offenen Punkten seines Decks hängen fünf an genau diesem fehlenden
Stück (B1, B2, B4, B5 und, nachgelagert, C4/C5). Diese Spec baut das Scharnier und den
ersten Prüfer; die weiteren Prüfer docken später an.

---

## 2. Die Entscheidungen

### 2.1 Das Portal trägt die Aufgaben, die Nachricht ist nur der Wegweiser

Markus trennt das auf den Folien 24 und 25 sauber: Kanal 1 ist der **Ort der
Bearbeitung**, Kanal 2 erreicht den Menschen **aktiv** — „keine parallele
Sachbearbeitung".

Daraus folgt die tragende Festlegung dieser Spec: **die Nachricht ist nicht der Träger
der Information.** Die offenen Punkte stehen im Portal, vollständig und aktuell. Die
Nachricht sagt nur, dass es etwas zu tun gibt.

Der Grund ist nicht Eleganz, sondern Folie 27 („Was wir vermeiden müssen"):

> ZU VIELE NACHRICHTEN — Mehrere WhatsApps, E-Mails und Erinnerungen für denselben
> Vorgang.

Wäre die Nachricht der Träger, müsste jede Änderung eine neue erzeugen. So nicht.

### 2.2 Ausgelöst wird bei **Auftrag**, nicht bei Angebot

`RecDispoAssignment` kennt vier Zustände: `STATUS_ANGEBOT` (0), `STATUS_AUFTRAG` (1),
`STATUS_BEENDET` (2), `STATUS_STORNO` (3).

Angefragt ist nicht gebucht. Wer absagt, soll nichts bekommen. Markus' Text auf Folie 26
(„Super, dein Einsatz ist gebucht") gehört zum Auftrag.

**Zusätzlich eine Mindest-Vorlaufzeit:** liegt der Einsatz zu nah, löst die Buchung
nichts aus. Eine Nachricht, die am Vorabend „bitte lade deinen Ausweis hoch" sagt, ist
keine Hilfe, sondern Störung — der Fall gehört auf die HR-Liste, nicht ins Handy des
Mitarbeiters.

**Und er gilt fuer jeden Eingebuchten, nicht nur fuer Rueckkehrer.** Markus' Folie 6
beschreibt den abgemeldeten Mitarbeiter, weil das der auffaelligste Fall ist — nicht,
weil es der einzige waere. Nachgesehen: `ProofReminderSender` arbeitet auf vorhandenen
Nachweis-Zeilen mit einem `valid_until` und erinnert an deren **Ablauf**. Ein Nachweis,
der **nie hochgeladen** wurde, hat gar keine Zeile und bekommt deshalb nie eine
Erinnerung. Fuer den Fall „fehlt" gibt es heute **keinen** Anstoss — der Mensch erfaehrt
es nur, wenn er von sich aus ins Portal schaut.

Das ist kein Randfall: der Ausweis ist Pflicht fuer jeden, hochgeladen haben ihn laut
Vorflug **282 von 1603**.

### 2.3 Zwei Anlässe für eine Nachricht, sonst Ruhe

**Erster Anlass:** es ist etwas offen, über **genau diese** Punkte wurde noch nicht
informiert, die letzte Nachricht ist lange genug her, und der Einsatz ist weit genug weg.

**Zweiter Anlass:** der Einsatz rückt näher und es ist immer noch offen. Das ist der
Moment, in dem es wirklich drängt — und bisher merkt es niemand, bis jemand vor Ort
steht.

Ein Schub von Aufträgen am selben Tag erzeugt damit **eine** Nachricht, nicht drei. Ein
abgelaufener Nachweis meldet sich, weil sich die Punkte geändert haben.

### 2.4 Die erste Fassung prüft nur Nachweise

Von Markus' fünf Prüfungen (Folie 7) kann das System heute **eine** entscheiden:
Unterlagen — und darin enthalten die Arbeitsberechtigung.

Nachgesehen und belegt:

- **Nachweise:** `ProofTypes::requiredFor()` liefert die Pflichtliste je Mensch —
  Ausweis für jeden; bei Nicht-EU zusätzlich Nationalpass, Aufenthaltstitel,
  Arbeitsgenehmigung; Schüler Schulbescheinigung; Student Immatrikulation; Ersthelfer
  den Schein. **Vorhanden.**
- **Vertrag:** es gibt keine Regel „braucht dieser Mensch einen Vertrag". Verträge
  hängen am **Bewerber** (`applicant.contracts`) — wer über ZAS kam und nie Bewerber
  war, hat gar keinen Vertragsdatensatz. **Fehlt, eigenes Thema (Markus' B2).**
- **Stammdaten:** es gibt keinen Marker „zuletzt bestätigt am". **Fehlt.**
- **Abrechnung:** BAR/Überweisung wird nirgends geführt. **Unbebautes Land (B5).**

Der Motor bekommt deshalb eine Form, in die weitere Prüfer einhängen, ohne dass
Auslöser, Bündelung und Nachrichtenregeln noch einmal angefasst werden.

### 2.5 Gefragt wird der Mensch, nicht die Anstellung

**Berichtigt am 01.10.2026.** Eine fruehere Fassung dieses Abschnitts behauptete, die
Nachweise wuerden je Anstellung gelesen. **Das stimmt nicht** — der Fehler stammt aus
einer gross-/kleinschreibungs-empfindlichen Suche, die `PersonScopeResolver` nicht fand.

Tatsaechlich liest `ProofReader::current()` bereits ueber die ganze Person:

```php
->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
```

und sagt es im Kopf auch: „Wer bei RHEINGEDECK und MA arbeitet, hat einen Ausweis, keine
zwei."

**Die echte Luecke ist kleiner und liegt auf der anderen Seite:** die **Pflichten**
werden nur aus der **einen** Anstellung bestimmt, mit der jemand gerade zu tun hat —

```php
$pflicht = ProofTypes::requiredFor([
    'is_eu_citizen'   => $employee->is_eu_citizen,
    'employment_type' => $employee->employment_type,
    'is_first_aider'  => $employee->is_first_aider,
]);
```

Wer bei RG als Student und bei MA als Aushilfe gefuehrt wird, sieht je nach Anstellung
eine andere Pflichtliste — die Immatrikulation taucht mal auf und mal nicht.

**Festlegung:** Die Pflichten werden ueber **alle aktiven Anstellungen der Person
vereinigt**. Die vorhandenen Nachweise werden bereits richtig gelesen und bleiben, wie
sie sind.

### 2.6 Die harte Sperre ist ein Zustand, kein Ereignis

Folie 14 verlangt die harte rechtliche Sperre bei fehlender oder abgelaufener
Aufenthalts- oder Arbeitserlaubnis. **Sie greift unabhängig von einer Buchung** — wer
nicht arbeiten darf, darf auch ohne Einsatz nicht arbeiten.

Sie wird deshalb **abgeleitet, nicht gespeichert**: ein gespeicherter Zustand veraltet
in dem Augenblick, in dem ein Dokument abläuft.

---

## 3. Der Motor

### 3.1 Der Prüfer — **gibt es schon**

**Berichtigt am 01.10.2026.** Eine fruehere Fassung wollte den reinen Pruefer neu bauen.
Er existiert: `ProofChecklist::build($pflicht, $vorhanden, $heute)` ist bereits reine
Logik ohne Datenbank und Uhr und liefert

```php
list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}>
```

mit den Zustaenden `fehlt`, `abgelaufen`, `laeuft_ab` und `ok`.

**Zu bauen ist deshalb nur, was ihm fehlt:**

1. die **Vereinigung der Pflichten** ueber alle aktiven Anstellungen (§2.5),
2. die Unterscheidung **K.-o.-Punkt gegen normalen Punkt** — welche Codes bedeuten „darf
   nicht arbeiten" statt „fehlt noch",
3. der **Einsatz-Bezug**: zu welchem Einsatz ein offener Punkt gehoert.

Weitere Prüfer (Vertrag, Stammdaten, Abrechnung) ergänzen die Liste später, ohne die
Schnittstelle zu ändern.

### 3.2 Die Aufgabenliste fürs Portal

Liest den aktuellen Stand und nennt zu jedem Punkt den **Bezug**: nicht „Ausweis fehlt",
sondern „Ausweis fehlt — gebraucht für deinen Einsatz am 12.10. in Düsseldorf".

**Wird bei jedem Aufruf berechnet, nicht gespeichert.** Eine gespeicherte Liste driftet
gegen die Wirklichkeit, und die Wirklichkeit ändert sich hier täglich (Nachweise laufen
ab, Einsätze kommen dazu).

### 3.3 Der Auslöser

Ein **eigenes Kommando**, das nach dem Dispo-Import läuft — **nicht im Importer**.

Der Import soll importieren. Stolpert die Prüfung, darf das die Lieferung nicht
gefährden; der Importer meldet ohnehin schon `assignments_created`. Ein eigener Lauf ist
außerdem wiederholbar und einzeln testbar.

Er geht über die neuen Aufträge, prüft die betroffenen Menschen und entscheidet über
Nachrichten. **Jeder Mensch in seinem eigenen Fehlerkäfig** — ein Datensatz, der
stolpert, darf den Lauf nicht beenden.

---

## 4. Was gespeichert wird

Nur das, was die Nachrichtenregeln brauchen. Die Aufgabenliste selbst wird **nicht**
gespeichert.

| Wo | Was | Wofür |
|---|---|---|
| Person | Fingerabdruck der zuletzt gemeldeten Punkte | „über genau diese Punkte wurde schon informiert" |
| Person | Zeitpunkt dieser Meldung | die Mindestpause |
| Einbuchung | Zeitpunkt der Erinnerung | der zweite Anlass |

Der Fingerabdruck haengt an der **Person**, nicht an der Anstellung — sonst bekaeme ein
Mensch mit zwei Anstellungen zwei Nachrichten mit demselben Inhalt (§2.5).

Geschrieben wird **observer-frei** über den Query Builder: eine Prüfung ist keine
fachliche Änderung am Menschen und darf `zas_changed_at` nicht setzen — sonst schiebt
jeder nächtliche Lauf volle Zeilen in die ZAS-Aktualisierungsdatei (Vorfall 02.09.2026).

---

## 5. Die harte Sperre und die Lücke zu ZAS

**Das ist der wichtigste offene Punkt dieser Spec, und er ist nicht durch Code bei uns
zu schließen.**

Markus' Folie 14 denkt HCM → Disposition mit. Diesen Draht gibt es nicht — in **beide**
Richtungen nicht:

- **Vorher verhindern** können wir nichts. Die einzige statusartige Spalte im
  Mitarbeiter-Export heißt `Status`, hat die Werte `GO`/`MA`, ist in der Oberfläche als
  „Status (aus ZAS)" **schreibgeschützt** und wird vom Inbound bei uns eingetragen. Sie
  gehört ZAS und fließt in die Gegenrichtung.
- **Nachher ausbuchen** können wir auch nicht. Der Rückkanal trägt ausschließlich
  Bestätigungen:
  `ZasDispoConfirmationExport::COLUMNS = ['DSID','EinsatzRef','Einsatz','Datum','PNr','BestaetigtAm']`
  — „NUR die bestaetigten Einbuchungen (User-Entscheid)".

Die Disposition gehört ZAS; wir spiegeln sie. Eine Sperre bei uns ist deshalb heute
strukturell eine Sperre **unseres Portals**, nicht des Einsatzes.

### Was diese Spec deshalb tut

Der Zustand „darf nicht arbeiten" wird **gebaut und bereitgestellt** — als abgeleitete
Eigenschaft, die ein künftiger Export mitlesen kann. Dazu entsteht ein `RecHrDeskCase`
und ein Alarm, damit ein Mensch es **rechtzeitig merkt** und in ZAS handelt.

### Der Folgeschritt, der nicht vergessen werden darf

> **Der Mitarbeiter-Export nach ZAS wird um zwei Angaben erweitert:** ob der Mensch alles
> Erforderliche eingereicht hat, und ob eine harte Sperre vorliegt, weil etwas
> Unverzichtbares fehlt.

Das ist **keine reine Code-Aufgabe**: ZAS muss die Spalten lesen, und neue Spalten im
Export werden **vorher angekündigt** — der Zeilenende-Marker `|` wandert bei jeder
Erweiterung mit, und Michel hat zusätzliche Spalten schon einmal selbst entdeckt, statt
sie angekündigt zu bekommen. Also: Abstimmung mit Michel und Olaf, dann Export, dann ist
die Sperre echt.

Bis dahin ist die harte Sperre **laut, aber nicht bindend**.

---

## 6. Nicht enthalten

- **Vertragsbedarf** (Markus' B2) — braucht die Regel „wer braucht wann welchen
  Vertrag" und die Verknüpfung Vertrag ↔ Mitarbeiter.
- **Stammdaten-Bestätigung** — braucht einen Marker und eine Festlegung, wie alt Daten
  sein dürfen.
- **Zahlungsstatus BAR/Überweisung** (B5), **Agenda** (C4), **SEPA** (C5) — anderer
  Systembereich.
- **Ein zweiter Kanal neben WhatsApp** (A4) — steht im Widerspruch zu Markus' eigenem
  Ziel „Kommunikation bündeln" (Folie 31).
- **Die Export-Erweiterung zu ZAS** — siehe Abschnitt 5, eigenes Thema mit ZAS.

---

## 7. Offene Festlegungen vor der Umsetzung

Diese Zahlen sind fachliche Entscheidungen, keine technischen. Sie gehören an **eine**
Stelle, damit sie änderbar bleiben:

| | Vorschlag | Begruendung |
|---|---|---|
| **Mindest-Vorlaufzeit** | 4 Tage | Darunter ist die Nachricht keine Hilfe mehr: Dokument suchen, abfotografieren, hochladen braucht einen Abend, und ein Vertrag will gelesen werden. Faelle darunter stehen auf der HR-Liste. |
| **Erinnerungsschwelle** | 2 Tage vorher | Letzter Moment, an dem der Mensch noch handeln **und** HR noch Ersatz suchen kann. |
| **Mindestpause** | 7 Tage | Eine Woche ist die uebliche Taktung der Einsaetze; kuerzer wird es Rauschen. |

Die drei Zahlen stehen als Konstanten an **einer** Stelle und sind aenderbar, ohne dass
jemand die Regeln anfassen muss.

Dazu, nicht technisch: **der Text der Meta-Vorlage.** Ohne genehmigte Vorlage geht keine
Nachricht raus — die Aufgabenliste im Portal funktioniert davon unabhängig.
