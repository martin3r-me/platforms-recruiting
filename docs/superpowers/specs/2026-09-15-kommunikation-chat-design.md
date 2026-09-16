# Kommunikation als Chat — Design

**Stand:** 15.09.2026
**Anlass:** Die Kommunikations-Uebersicht (`/recruiting/conversations`) ist eine
Tabelle ohne Verlauf und ohne Antwortmoeglichkeit. Gewuenscht ist die Chat-Optik
der Dispo-Kommunikation, ohne Ampel und Eskalation zu verlieren.

## Problem

Die heutige Seite zeigt sieben Spalten und kann drei Dinge: gelesen markieren,
filtern, eine Eingangsbestaetigung an markierte Zeilen schicken. Wer antworten
will, klickt "Oeffnen", landet in der Bewerberakte und scrollt dort bis ans Ende
zum CRM-Baustein `livewire:crm.inline-comms`. Der Verlauf ist auf der
Uebersicht nirgends sichtbar.

Dazu der Zaehler, der die Seite unbrauchbar macht: **411 "Verpasst"**.

### Woher die 411 kommen (gemessen am 15.09.2026)

Ueber `core.comms.whatsapp_threads.GET` gezaehlt. Bewerber-Threads mit
mindestens einem Eingang: **981**. Nach Alter der letzten *eingehenden*
Nachricht:

| letzter Eingang | Threads |
|---|---|
| juenger als 7 Tage | 153 |
| 7–30 Tage | 356 |
| 30–90 Tage | 160 |
| 90–180 Tage | 312 |
| aelter als 180 Tage | 0 |

Ausserdem: 29 Threads ohne jede ausgehende Nachricht, 30 ungelesen.

Damit ist "Verpasst" **kein reines Altersproblem**. 472 Threads (48 %) haben
seit ueber 30 Tagen keinen Eingang, aber mindestens rund hundert der 411 sind
juenger als 90 Tage. Der eigentliche Mechanismus steht in der Liste selbst:
die letzten Nachrichten heissen "Ja bin dabei" und "Dankeschoen". Auf
Hoeflichkeitsfloskeln antwortet niemand — und weil `ConversationEscalation`
einen Chat nur durch eine **ausgehende** Nachricht als beantwortet ansieht,
bleibt er fuer immer unbeantwortet.

Der Ampel fehlt also ein Zustand: *erledigt, hier ist nichts mehr zu tun*.

### Das zweite, schwerere Problem: die Seite zeigt nicht alles

`ConversationInboxService::dedupedThreads()` waehlt Threads ueber den
**Kontext** aus:

```php
->whereIn('context_model', [$applicantMorph, $applicantFull, $employeeFull])
```

Wer keinen Bewerber- oder Mitarbeiter-Kontext traegt, existiert fuer diese
Seite nicht. Genau das war Fall 2474 (Marie van Ackeren, 10.08.2026): Sie
schrieb zuerst per WhatsApp, das CRM heftete den Thread an einen `CrmContact`,
der Bewerber entstand vier Stunden spaeter aus einer Mail — der Chat blieb
unsichtbar. DB-verifiziert waren **41 Threads** betroffen, davon **22
komplett verlorene WhatsApp-Bewerbungen**.

Das Intake-Gate ist seit 10.08. gefixt (`ApplicantThreadLinker` als
Einzelmechanismus). Die **Anzeige** ist es nicht: sie filtert weiter auf den
Kontext und wuerde jeden zukuenftigen Aussetzer genauso schlucken.

Dazu ein zweiter, leiserer Verlustpfad in derselben Methode: sie reduziert pro
Person auf den Thread mit dem neuesten Eingang. Existieren zwei Threads
derselben Person (Nummern-Format-Dubletten splitten Konversationen — belegt am
Fall #307: Thread 166 nur ausgehend, Thread 215 nur eingehend), ist einer
davon unsichtbar.

## Entscheidung

Eine zweite, neue Seite neben der alten, erreichbar unter einer eigenen Route.
Sie bringt vier Dinge: Postfach-Layout (Liste | Verlauf), Antworten direkt im
Chat, den fehlenden Erledigt-Zustand — und eine **lueckenlose Grundmenge**,
die nichts mehr wegfiltern kann. Die alte Seite bleibt vollstaendig
unangetastet, bis die neue gut genug ist.

| | alt | neu |
|---|---|---|
| Route | `/recruiting/conversations` | `/recruiting/conversations-neu` |
| Routen-Name | `recruiting.conversations.index` | `recruiting.conversations.preview` |
| Komponente | `Livewire\Conversations\Index` | `Livewire\Conversations\Inbox` |
| View | `conversations/index.blade.php` | `conversations/inbox.blade.php` |

**Kein Sidebar-Eintrag.** Die Vorschau wird ueber die URL aufgerufen; der
Menuepunkt "Kommunikation" zeigt weiter auf die alte Seite.

## Was bewusst nicht gemacht wird

**Kein Umzug der Dispo-Bausteine (vorerst).** Sprechblasen-Partial,
Nachrichten-Mapper und Antwort-Sender liegen in Ordnern mit "Dispo" im Namen,
enthalten aber nichts Dispo-Spezifisches. Sie liegen im selben Modul, also ruft
die neue Komponente sie **direkt auf** — ohne eine Zeile an der produktiven
Dispo zu aendern. Das Umbenennen nach `Services/Comms/` ist Kosmetik und
gehoert zum Umschwenken, nicht zum Bau.

**Keine gemeinsame Postfach-Komponente fuer Dispo und Recruiting.** Die Dispo
traegt Filial-Tabs, Identitaetsgruppen, Kanal-Sets und die Einsatz-Leiste, das
Recruiting Ampel, Zustaendigkeit, Abwesenheitsmodus und Sammelversand. Die
gemeinsame Abstraktion waere groesser als beide Spezialfaelle zusammen.

**Kein Umhaengen der Zustaendigkeit im Chat.** Ein leeres `owned_by_user_id`
macht den Bewerber im Auto-Pilot unsichtbar. Ein Dropdown im Chat-Kopf hat
fast immer einen leeren Eintrag, und dann klickt man sich das im Vorbeigehen
kaputt. Anzeigen und filtern ja, aendern weiter in der Bewerberakte.

**Kein Loeschen von Threads.** Erledigt ist ein Stempel, keine Loeschung.

## Oberflaeche

### Kopf — eine Zeile statt vier Bloecke

Links der Titel. Daneben die Zaehler als **klickbare Pillen**:
`Ungelesen` · `Gelb` · `Rot` · `Verpasst` · `Erledigt`. Die Zahl ist der
Filter — ein Klick setzt die Liste, ein zweiter loest ihn wieder.

Rechts zwei Symbolknoepfe:

- **Mond** = Abwesenheitsmodus. Grau wenn aus, gelb wenn aktiv, blau wenn
  geplant. Klick klappt das heutige Formular (von/bis/wieder da) als Panel auf.
  Logik unveraendert aus `OooMode` — der Zustand kommt nie aus dem rohen Flag.
- **Haken** = Mehrfachauswahl an/aus. Erst danach erscheinen Kaestchen in der
  Liste und die Aktionsleiste am unteren Rand.

Darunter eine schmale Zeile mit Suchfeld und Zustaendig-Filter
(Alle / Mir zugewiesen / Person).

### Liste (ca. 360 px)

Pro Zeile:

- Ampel als 3-px-Farbkante **links an der Zeile** statt als Badge
- Initialen-Avatar
- Name, fett wenn ungelesen; Chip "MA" nur bei Mitarbeitern (Bewerber ist der
  Normalfall und braucht kein Etikett)
- einzeilige Vorschau der letzten Nachricht
- Countdown-Chip: `noch 2,4 h` (gelb), `noch 40 min` (rot),
  `verpasst seit 5 Tg` (grau)
- Kuerzel der zustaendigen Person
- rechts die Uhrzeit der letzten Nachricht

Geladen wird in Bloecken zu 50 mit "mehr laden".

### Chat rechts

**Kopfzeile:** Name, Nummer, Countdown, Zustaendig, Link "Bewerberakte ↗",
Knopf "Erledigt".

**Kontextzeile** darunter, in der Art der Einsatz-Leiste der Dispo:
Phase (`applicant->phase`) · Stelle (`applicant->position`, ersatzweise die
erste aus `positions()`) · naechster Termin (kommende
`interviewBookings`). Fehlt etwas, entfaellt der Teil — keine Platzhalter.
Zweck: antworten koennen, ohne die Akte zu oeffnen.

**Verlauf:** das bestehende Sprechblasen-Partial
(`recruiting::livewire.dispo._messages`). Bilder, Sprachnachrichten,
Dokumente, Tagestrenner "Heute/Gestern" und Sendestatus sind damit geschenkt.
Autoscroll ans Ende ueber denselben `wire:key`-Trick wie in der Dispo.

**Mobil:** Master-Detail wie in der Dispo — Liste, bei Auswahl Vollbild-Chat
mit Zurueck-Pfeil.

**Nachladen:** `wire:poll.visible.20s`.

## Senden

**Fenster offen → Freitext.** Ueber `DispoReplySender`, der das 24h-Fenster
selbst prueft und `['ok' => false, 'error' => ...]` zurueckgibt statt zu
werfen; der Eingabetext bleibt bei Fehlern stehen.

Der Sender formuliert seinen Fenster-Fehler dispo-spezifisch
("Erinnerungen laufen als Vorlage ueber die Veranstaltung"). Die neue
Komponente prueft das Fenster deshalb selbst und blendet das Textfeld bei
geschlossenem Fenster gar nicht erst ein; kommt der Fenster-Fehler doch noch
(Fenster laeuft zwischen Rendern und Klick ab), ersetzt die Komponente den
Text. **Die Dispo-Klasse wird nicht geaendert.**

**Fenster zu → Vorlagen.** Eine Knopfleiste mit dem konfigurierten
"Wir melden uns"-Template (ueber `HoldingTemplateSender`, ein Empfaenger) und
"Vorlage waehlen…" fuer die freigegebenen Templates des konfigurierten
WhatsApp-Kontos.

### Warum ein eigener Template-Sender

`Applicant\Show::sendManualTemplate` (Show.php:543) haengt den Bewerber-
Formular-Token an **jedes** Template, das irgendeinen URL-Button traegt — auch
wenn dessen URL gar keinen Platzhalter hat. Das ist der bekannte
Theo-Wirtz-Fall und noch offen.

Die neue Seite bekommt `Services/Comms/ApplicantTemplateSender`, der den Token
**nur** setzt, wenn die Button-URL tatsaechlich einen Platzhalter enthaelt.
Der Fix an der Bewerberakte wird danach aus demselben Sender nachgezogen —
als eigenes Ticket, nicht in diesem Paket.

## Lueckenlosigkeit — nichts darf verschwinden

**Oberste Regel der neuen Seite: was auf der Recruiting-Nummer eingeht, steht
in der Liste. Ohne Ausnahme.** Lieber eine Zeile zu viel, die jemand abhakt,
als eine Bewerbung, die niemand je sieht.

### Grundmenge ueber den Kanal statt ueber den Kontext

`Services/Comms/RecruitingChannelResolver` liefert die IDs aller aktiven
WhatsApp-Kanaele des in den Einstellungen gewaehlten Kontos
(`auto_pilot_wa_account_id`) — gebaut nach dem Muster von
`DispoChannelResolver::dispoChannelIds()`, wo derselbe Gedanke im Code schon
"Lueckenlosigkeit" heisst.

Die Liste zeigt dann **jeden** Thread dieser Kanaele mit mindestens einem
Eingang. Der Kontext (Bewerber, Mitarbeiter, blosser CRM-Kontakt, gar keiner)
entscheidet nur noch, *wie reich* eine Zeile ist — nicht mehr, *ob* sie
existiert.

Ist kein Konto konfiguriert, faellt die Seite auf die bisherige Kontext-Menge
zurueck und sagt das sichtbar an ("Kein WhatsApp-Konto gewaehlt — es werden
nur zugeordnete Chats angezeigt"), statt stillschweigend zu filtern.

### Kein Zusammenfassen pro Person

Jeder Thread ist eine Zeile. Gehoeren zwei Threads zur selben Person, stehen
beide da (die Zeile traegt dann einen Hinweis-Chip). Das heutige
"neuester Eingang gewinnt" wird nicht uebernommen — es ist ein Verlustpfad,
kein Aufraeumen.

### Zeilen ohne Zuordnung

Ohne Bewerber-/Mitarbeiter-Kontext gibt es keinen Namen. Solche Zeilen zeigen
die Telefonnummer als Titel, einen roten Chip **"nicht zugeordnet"** und sind
ganz normal lesbar und beantwortbar. Dazu eine Aktion **"Bewerber
zuordnen…"**: Suche, Auswahl, fertig — der Link laeuft ueber
`ApplicantThreadLinker::link()`, den bestehenden Einzelmechanismus (er
befoerdert den Bewerber auch dann, wenn der Thread noch am nackten CrmContact
haengt). Kein zweiter Link-Pfad, keine Wiederholung an der Call-Site.

Fremde Kontexte (etwa `hcm_onboarding`) werden ebenfalls angezeigt, mit Chip.
Sie laufen auf derselben Nummer, also gehoeren sie in dieselbe Liste; wer sie
nicht sehen will, hakt sie ab.

### Zaehler

Die Ampel-Zaehler rechnen ueber die **gesamte** Kanal-Menge, nicht nur ueber
zugeordnete Chats. Eine unbeantwortete Nachricht ist unbeantwortet, egal an
wem sie haengt. Dadurch werden die Zahlen anfangs groesser als heute — das ist
kein Fehler, sondern das, was vorher fehlte.

### Absicherung gegen Rueckfall

Ein Test haelt das fest: ein Thread auf dem Recruiting-Kanal **mit
`CrmContact`-Kontext und ohne Bewerber** muss in der Liste erscheinen. Genau
dieser Test waere im August rot gewesen.

## Erledigt-Zustand

### Tabelle `rec_conversation_handled`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | bigint | |
| `team_id` | bigint, indiziert | Mandant |
| `comms_whatsapp_thread_id` | bigint, **unique** | Thread im CRM |
| `handled_at` | timestamp | wann abgehakt |
| `handled_by_user_id` | bigint, nullable | wer (null beim Backfill) |
| `handled_reason` | string | `manual` \| `backfill` |
| `created_at`/`updated_at` | timestamps | |

Kein Fremdschluessel auf `comms_whatsapp_threads` — die Tabelle liegt in
platform-crm. Verwaiste Zeilen sind moeglich und harmlos: sie werden nie
wieder gelesen, und Threads haben ohnehin SoftDeletes. Die Alternative
(Spalte an der CRM-Tabelle) wurde verworfen: ein Recruiting-Begriff in einer
von drei Modulen geteilten Tabelle, plus Migration im Fremd-Repo, plus
Core/CRM-Bump — fuer einen Haken pro Thread ein schlechter Tausch. Das Muster
"Recruiting-Tabelle referenziert CRM-ID" gibt es hier schon
(`rec_applicant_contact_links`).

### Regeln

1. **Erledigt** blendet den Chat aus Ampel, Zaehlern und Standardliste aus.
   Ueber die Pille "Erledigt" bleibt er erreichbar.
2. **Wiederauftauchen ohne Job:** ein Thread gilt nur dann als erledigt, wenn
   `handled_at` existiert **und** `last_inbound_at <= handled_at`. Schreibt die
   Person danach erneut, steht der Chat von selbst wieder in der Eskalation.
   Reiner Zeitvergleich, kein Cron, kein Zustandsfeld, das veralten kann.
3. **Sammel-Erledigen** ueber dieselbe Mehrfachauswahl wie der Sammelversand.

### Altlast-Backfill

Kommando `recruiting:conversations-archivieren --older-than=30 --dry-run`
stempelt `handled_reason = backfill` auf alle Threads, deren letzter Eingang
aelter als N Tage ist. Ohne `--dry-run` laeuft nichts, was vorher nicht
angezeigt wurde. Vollstaendig rueckholbar per
`DELETE FROM rec_conversation_handled WHERE handled_reason = 'backfill'`.

**Laeuft erst auf ausdrueckliche Ansage.** Nicht Teil des Deployments.

### Zaehler waehrend der Vorschau

Die Sidebar-Zahl und die alte Seite nutzen weiter `ConversationInboxService`
unveraendert und wissen nichts von "erledigt". Das ist Absicht: sonst wirkt die
alte Ansicht ploetzlich leer, ohne dass jemand versteht warum. Beim
Umschwenken zieht die Sidebar nach.

## Aufbau und Last

`ConversationInboxService::build()` laedt heute **alle** Threads eines Teams
samt Bewerbern und CRM-Kontakten — bei 981 Threads pro Aufruf. Fuer eine
Tabelle, die man einmal am Tag oeffnet, ist das unauffaellig. Fuer einen Chat,
der alle 20 s nachlaedt, nicht.

Die neue Seite bekommt deshalb einen eigenen Lesepfad in
`Services/Comms/InboxQuery`:

1. duenne Thread-Zeilen des **Kanal-Sets** laden (nur die Spalten, die die
   Eskalation braucht) — kein Kontext-Filter, kein Zusammenfassen pro Person
2. erledigte Threads ausschliessen (ein `whereNotIn` bzw. Left Join auf
   `rec_conversation_handled`)
3. Eskalation rechnen und sortieren — weiter ueber das unveraenderte
   `ConversationEscalation`
4. auf die sichtbaren 50 schneiden
5. **erst jetzt** Namen, Kontakte und Zustaendigkeit fuer diese 50 nachladen

`ConversationInboxService` bleibt unveraendert, solange die alte Seite lebt,
und faellt beim Umschwenken mit ihr weg.

## Absicherung

- **Unit (ohne Laravel)**, wie `ConversationEscalation`: die Erledigt-Regel
  inklusive Wiederauftauchen. Faelle: nie erledigt; erledigt ohne neuen
  Eingang; erledigt, danach neuer Eingang; Eingang exakt auf `handled_at`.
- **Integration (Capsule + SQLite)**: Migration, Sammel-Erledigen,
  Filterpillen, Ausschluss aus den Zaehlern, Blockweises Nachladen.
- **Lueckenlosigkeit (Integration, der wichtigste Test des Pakets)**:
  Thread auf dem Recruiting-Kanal mit `CrmContact`-Kontext und ohne Bewerber
  erscheint in der Liste; zwei Threads derselben Person erscheinen beide;
  Thread eines fremden Kanals (Dispo) erscheint **nicht**; ohne konfiguriertes
  Konto greift der angesagte Fallback.
- **`ApplicantThreadLinker`**: Zuordnen aus der Liste befoerdert einen Thread,
  der am nackten CrmContact haengt, wirklich auf den Bewerber (Legacy-Spalten
  umgeschrieben) — sonst verschwindet er nach dem Zuordnen wieder.
- **`SharedPartialContractTest`** wird um das jetzt von zwei Komponenten
  genutzte Sprechblasen-Partial erweitert. Der `$this->`-Vertrag prueft sich
  nicht von selbst — sonst bricht es erst beim Klick auf einen Thread.
- **`ApplicantTemplateSender`**: Token wird gesetzt bei URL-Button *mit*
  Platzhalter, und nicht gesetzt bei URL-Button *ohne* Platzhalter (der
  Theo-Wirtz-Fall als Test).
- Keine zufaellige Testreihenfolge, Dispatcher setzen (Modul-Konvention).

## Umschwenken — ERLEDIGT am 16.09.2026

Der Kunde hat die Vorschau abgenommen ("Clara ist sehr happy"), damit ist die
neue Seite die Kommunikation. Umgesetzt wie unten beschrieben:

- `/recruiting/conversations` zeigt auf `Conversations\Inbox`; der Routenname
  bleibt, damit Sidebar-Eintrag und vorhandene Links weiter funktionieren.
- `/recruiting/conversations-neu` leitet auf die richtige Seite um, statt ins
  Leere zu laufen.
- Geloescht: `Conversations\Index`, `conversations/index.blade.php`,
  `ConversationInboxService`, `ConversationInboxReport`, `ConversationInboxRow`
  (die letzten beiden waren nach der Sortier-Umstellung ohnehin unbenutzt).
- Der Sidebar-Zaehler laeuft ueber `InboxQuery::counts()` — also ueber das
  Kanal-Set und OHNE abgehakte Chats. Vorher zeigte er dauerhaft eine hoehere
  Zahl als die Seite, auf die er verlinkt.

Nicht gemacht (bewusst): das Umbenennen der geteilten Dispo-Bausteine. Sie
werden weiterhin nur aufgerufen; ein Umzug waere reine Kosmetik an
produktivem Code.

## Umschwenken (urspruenglicher Plan)

1. Route `recruiting.conversations.index` auf `Conversations\Inbox` zeigen
   lassen, Vorschau-Route entfernen
2. `Conversations\Index` + `conversations/index.blade.php` +
   `ConversationInboxService::build()` loeschen
3. Sidebar-Zaehler auf den neuen Lesepfad umstellen (erledigte zaehlen nicht)
4. Kosmetik: Sprechblasen-Partial, Nachrichten-Mapper und Antwort-Sender nach
   `Services/Comms/` umbenennen, Dispo-Aufrufe mitziehen, Vertragstest gruen

**Rollback vor dem Umschwenken:** nichts tun — die alte Seite laeuft
unveraendert. Danach: Route zuruecksetzen (die geloeschten Dateien liegen in
der Historie).

## Offene Punkte

- Umhaengen der Zustaendigkeit im Chat: bewusst draussen. Falls doch
  gewuenscht, nur mit Sperre gegen Leeren.
- Der Fix an `Show::sendManualTemplate` ist ein eigenes Ticket.
- Der Grenzwert fuer den Backfill (`--older-than`) wird erst beim Aufraeumen
  festgelegt, nicht im Code verdrahtet.
