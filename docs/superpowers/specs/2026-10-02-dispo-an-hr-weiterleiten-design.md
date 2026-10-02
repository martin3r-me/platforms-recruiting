# Nachrichten von der Dispo an HR weiterleiten — Design

**Stand:** 02.10.2026
**Anlass:** Kundenfrage (Markus): „Bekomme ich die Nachricht irgendwie zu Clara
weitergeleitet?" Auslöser war ein MA (Jonas Stein, MGL), der in der
Dispo-Kommunikation nach seinem Gehalt fragt. Gehaltsfragen gehören zu HR
und sollen über die HR-Nummer laufen, nicht über die Dispo-Nummer.

## Ziel

Die Dispo leitet eine oder mehrere Nachrichten eines MA, optional mit einem
Kommentar, an HR weiter. Die Weiterleitung erscheint in
`/recruiting/conversations`. HR schickt dem MA von dort die bestehende
Erstnachricht-Vorlage über die HR-Nummer, und das Gespräch läuft ab dann im
HR-Chat weiter.

## Bewusst nicht

- **Keine E-Mail.** Die Weiterleitung lebt nur in `/recruiting/conversations`.
- **Keine automatische Nachricht an den MA über die Dispo-Nummer** („HR meldet
  sich"). Die Weiterleitung passiert nicht unbedingt, solange das Fenster
  offen ist, und der MA soll nicht von zwei Nummern angeschrieben werden.
- **Keine Zuweisung an einzelne Personen.** Ziel ist die HR-Kommunikation des
  Teams. Bei RheinGedeck arbeiten alle in Team 3, also sieht jeder aus HR
  die Weiterleitung.
- **Keine neue Meta-Vorlage.** Genutzt wird die vorhandene Erstnachricht
  `t_com_gen` („Gespräch starten", `Inbox::CHAT_TEMPLATES`).

## Kernproblem: Es gibt oft noch keinen HR-Chat

Die HR-Kommunikation listet nur Threads, die es auf den Recruiting-Kanälen
schon gibt (`RecruitingChannelResolver::channelIds`). Hat der MA der
HR-Nummer nie geschrieben, existiert kein Thread, an den man die
Weiterleitung hängen könnte. Die Weiterleitung ist deshalb ein **eigener
Datensatz**, der zunächst nur auf den Dispo-Thread zeigt. Den HR-Thread
bekommt er erst mit der Erstnachricht: `WhatsAppMetaService::sendTemplate()`
legt ihn an und liefert ihn über `$message->thread` (Muster
`NoAssignmentCampaignSender`).

## Ablauf

### Dispo (`/recruiting/dispo-kommunikation`)

1. Jede **eingehende** Nachricht im Verlauf bekommt ein
   **Weiterleiten-Symbol** neben der Blase, wie bei WhatsApp. Am Desktop
   erscheint es beim Drüberfahren, am Handy ist es immer sichtbar, weil es
   dort kein Hover gibt. Ausgehende Nachrichten haben kein Symbol. Einen
   Knopf im Chat-Kopf gibt es nicht.
2. Ein Klick öffnet ein Fenster mit genau **dieser Nachricht vorausgewählt**.
   Darunter stehen die übrigen eingehenden Nachrichten der letzten Tage zum
   Dazuwählen und ein optionales Kommentarfeld.
3. Beim Absenden wird der Datensatz angelegt. Die Nachrichtentexte werden
   als **Kopie** gespeichert (siehe Datenmodell).
4. Im Dispo-Verlauf erscheint ein grauer Vermerk *„An HR weitergeleitet ·
   Sebastian · 02.10. 09:14"*. In der Thread-Liste bekommt der Chat den Chip
   **„bei HR"**, nach dem Abschluss **„HR erledigt"**. Massgeblich ist die
   jüngste Weiterleitung des Threads.
5. Kein Versand an den MA.

### HR (`/recruiting/conversations`)

1. Ein neuer Reiter **„Weitergeleitet (n)"** zeigt die offenen
   Weiterleitungen. Die Zahl steht auch in der Seitenleiste an
   „Kommunikation".
2. Ein Eintrag zeigt: MA-Name, MA-Nummer (Chips wie in der Dispo), Telefon,
   die weitergeleiteten Nachrichten im Wortlaut mit Uhrzeit, den Kommentar,
   wer weitergeleitet hat und wann.
3. Die Aktion hängt davon ab, wie es auf der HR-Nummer aussieht:
   - **Kein HR-Thread oder Fenster zu:** Knopf **„Erstnachricht senden"**.
     Er schickt `t_com_gen` über die HR-Nummer (Vorname wie in
     `Inbox::sendTemplate`), hängt den MA als Kontext an den Thread
     (`addContext(RecEmployee, …, 'dispo_forward')`) und speichert den
     HR-Thread am Datensatz. Die Detailansicht zeigt danach den HR-Verlauf
     (siehe Entscheidungen).
   - **HR-Fenster offen** (der MA hat der HR-Nummer in den letzten 24 h
     geschrieben, `computeWindowOpen`): Knopf **„Chat öffnen"**. Dann ist
     keine Vorlage nötig, und HR antwortet frei.
4. Im HR-Chat steht die Weiterleitung oben als **interne Karte**
   (Nachrichten + Kommentar + Absender). Der MA sieht sie nicht.
5. **„Erledigt"** schließt die Weiterleitung. Sie verschwindet dann aus dem
   Reiter, und der Dispo-Chip wechselt auf „HR erledigt". Das geht auch
   ohne Erstnachricht, etwa wenn HR telefonisch geklärt hat.

## Datenmodell

Neue Tabelle `rec_conversation_forwards` (Migration):

| Spalte | Zweck |
|---|---|
| `team_id` | Team |
| `source` | Quelle, heute nur `dispo` |
| `target` | Ziel, heute nur `hr` |
| `source_thread_id` | Dispo-Thread (`comms_whatsapp_threads`) |
| `rec_employee_id` (nullable) | MA, falls über die Dispo-Zuordnung bekannt |
| `phone`, `display_name` | Empfänger der Erstnachricht |
| `messages` (json) | Kopie: `[{message_id, body, received_at}]` |
| `comment` (text, nullable) | Kommentar der Dispo |
| `forwarded_by_user_id`, `forwarded_at` | wer und wann weitergeleitet hat |
| `target_thread_id` (nullable) | HR-Thread, gesetzt bei Erstnachricht oder „Chat öffnen" |
| `first_contact_at`, `first_contact_by_user_id` (nullable) | Erstnachricht |
| `done_at`, `done_by_user_id` (nullable) | erledigt |

**Warum eine Kopie der Texte:** HR muss die Nachrichten lesen können, ohne
dass die HR-Seite auf die Dispo-Kanäle zugreift. Ausserdem bleibt die
Weiterleitung so stabil, auch wenn sich am Dispo-Thread später etwas
ändert. Mehrere Weiterleitungen pro MA sind erlaubt, jede bekommt einen
eigenen Datensatz.

## Erweiterbarkeit (Entscheidung 02.10.)

Jede Weiterleitung trägt `source` und `target`. Die erlaubten Werte und die
Texte („An HR weiterleiten", „bei HR", „HR erledigt") stehen an einer Stelle,
in `ForwardTargets`. Jede Ansicht filtert auf ihr Ziel. Ein neues Ziel
braucht drei Dinge: einen neuen Eintrag in `ForwardTargets`, eine Ansicht,
die darauf filtert, und gegebenenfalls eine eigene Erstnachricht-Regel (die
heutige gilt nur für `hr`). Ein Plugin-System oder eine Registry kommt bewusst
erst, wenn ein zweites Ziel konkret wird.

## Entscheidungen aus dem Code-Check (02.10.)

- **Ein neuer HR-Thread steht erst in der Chat-Liste, wenn der MA antwortet.**
  `InboxQuery::scored()` filtert auf `last_inbound_at IS NOT NULL`. Ein
  Thread, der nur Claras Vorlage enthält, fehlt dort deshalb, und zwar
  unabhängig vom Kontext. Das wird bewusst **nicht** geändert, denn es würde
  die Ampel-Logik der ganzen Seite berühren. Stattdessen zeigt die Detailansicht
  im Reiter „Weitergeleitet" den HR-Verlauf selbst an (gesendete Vorlage plus
  Status). Antwortet der MA, erscheint der Chat ganz normal in der Liste, mit
  der internen Karte.
- **HR-Thread finden statt doppelt anlegen:**
  `CommsWhatsAppThread::findOrCreateForPhone(channel, phone)` (CRM, nur
  aufgerufen, nicht geändert) sucht über Kanal plus Nummer im E.164-Format.
  Wir suchen vorher über alle Recruiting-Kanäle nach derselben Nummer, nur
  die Ziffern verglichen. Ein Treffer wird wiederverwendet, sonst wird der
  Thread mit `'+' . ziffern` auf dem Kanal von
  `HoldingTemplateSender::resolveTemplate()` angelegt. Gesendet wird über
  `ApplicantTemplateSender::send()`, so bleiben die Kontoprüfung und das
  Füllen von `{{name}}` an einer Stelle.
- **MA nicht zugeordnet:** Weiterleiten ist erlaubt. „Erstnachricht senden"
  ist dann aber gesperrt mit dem Hinweis *„Kein MA zugeordnet – die Vorlage
  braucht den Vornamen"*, weil `t_com_gen` `{{name}}` trägt. „Erledigt" geht
  immer.
- **Fehler beim Versand** (Meta lehnt ab, 131026): `first_contact_at` bleibt
  leer, der Fehler wird im Eintrag angezeigt, und der Eintrag bleibt offen.
- **Team:** `team_id` ist das aktuelle Team des Weiterleitenden. Das ist
  derselbe Wert, den `Inbox::teamId()` liest.
- **Rechte:** Ohne eigenes Recht. Wer die jeweilige Seite öffnen kann, kann
  dort weiterleiten bzw. bearbeiten.
- **Vermerk im Dispo-Verlauf:** Er wird als eigene Zeilenart `note` in die
  Nachrichtenliste einsortiert (nach Zeitpunkt). Das geteilte Partial
  `dispo/_messages` lernt `note` und ein abschaltbares Weiterleiten-Symbol
  (`forwardable`, Standard aus). Der VA-Chat und die HR-Seite bleiben dadurch
  unverändert.

## Tests

- Unit: die Auswahlliste (angeklickte Nachricht vorausgewählt, nur eingehende) und die Ableitung des
  Chip-Zustands (offen / erledigt / jüngste gewinnt).
- Integration (Capsule): Weiterleiten legt Datensatz + Kopie an.
  Erstnachricht setzt `target_thread_id` und `first_contact_at`, ein
  fehlgeschlagener Versand setzt nichts. „Erledigt" nimmt den Eintrag aus dem
  Reiter. Fremde Teams sehen nichts.

## Deploy

`migrate` ist Pflicht. `queue:restart` ist nicht nötig, weil kein Job
beteiligt ist.
