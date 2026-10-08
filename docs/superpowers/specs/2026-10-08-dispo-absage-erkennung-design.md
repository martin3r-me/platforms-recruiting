# Absagen in Chat-Nachrichten erkennen — Design

**Stand:** 2026-10-08
**Anlass:** Mitarbeiter sagen nach der Bestaetigungs-Anfrage per freier
Nachricht ab, nicht ueber einen Knopf. Heute merkt das nur, wer den Chat
liest. Markus 07.10.: die Absage soll automatisch an die Disponummer gehen.
Abgestimmt mit Markus 08.10. — „genau so wie geplant umsetzen".

## Was gebaut wird

Eine eingehende Nachricht von jemandem, der in einer kommenden Veranstaltung
eingebucht und bereits angeschrieben ist, wird von einem Sprachmodell
eingestuft. Haelt es die Nachricht fuer eine Absage, geht ein Alarm ans
Diensthandy der Filiale und die Zeile in der Veranstaltung wird als
**moegliche Absage** markiert. Ein Disponent bestaetigt oder verwirft das mit
einem Klick. **Die KI fuehrt nie selbst eine Absage aus.**

## Entscheidungen

**1. Die KI meldet, der Mensch entscheidet.**
Eine faelschlich ausgefuehrte Absage nimmt jemanden still aus der
Veranstaltung, stoppt die Eskalation und kann das Portal sperren — am
Einsatztag fehlt dann eine Person, ohne dass jemand gewarnt wurde. Freitext ist
dafuer zu mehrdeutig („ich kann erst ab 18 Uhr", „morgen nicht, Samstag
schon", „nein" auf eine andere Frage). Deshalb gibt es keine Stufe
„automatisch absagen", auch nicht als Option.

**2. Kein Absage-Knopf fuer Mitarbeiter (Kunde 08.10.).**
Bewusst: die Huerde, abzusagen, soll hoch bleiben. Damit ist die freie
Nachricht der einzige Weg, auf dem eine Absage ankommt — umso wichtiger
Entscheidung 1.

**3. Ausloeser ist der bestehende Recruiting-Listener, kein Polling.**
`HandleWhatsAppInboundForRecruiting` empfaengt jede eingehende WhatsApp. Ganz
oben (neben dem Abwesenheits-Handler, VOR dem Kontext-Gate — Dispo-Threads
tragen Kontexte) wird nur ein Job eingereiht: `CheckDispoDeclineJob(messageId)`.
Der Webhook wartet nie auf das Sprachmodell; ein Fehler im Job beruehrt den
uebrigen Inbound-Fluss nicht. Core und CRM werden nicht angefasst.
Folge fuer den Deploy: `queue:restart`.

**4. Schalter je Filiale, zwei Stufen.**
Neue Spalte `decline_check_enabled_at` (nullable datetime) an
`rec_dispo_filiale_settings`, gepflegt im Dispo-Einstellungsfenster neben dem
Diensthandy. Gesetzt = an, null = aus (Standard). Der Zeitstempel ist
zugleich die Untergrenze: **geprueft werden nur Nachrichten, die nach dem
Einschalten eingehen** — kein Nachlauf ueber die Vergangenheit, kein
Kostenstoss beim Einschalten.

**5. Vorfilter vor dem Sprachmodell.** Ans Modell geht nur, was ALLE Huerden
nimmt:
- Richtung eingehend, mit Text (Sprachnachricht/Bild ohne Text: nicht
  pruefbar, wird uebersprungen — bekannte Grenze, siehe unten).
- Der Absender wird ueber die bestehende Identitaetsaufloesung einem
  Mitarbeiter zugeordnet (Telefon/CRM-Kontakt, kanonisch).
- Er hat in einer Veranstaltung **der eingeschalteten Filiale** mindestens
  eine Einbuchung, die kommend ist (`datum >= heute`), nicht abgesagt, nicht
  verschwunden, nicht zur Loeschung gemeldet, und **bereits angeschrieben**
  (`reminder_sent_at` gesetzt und vor der Nachricht). Bestaetigte Zeilen
  zaehlen ausdruecklich mit — der Kernfall ist „erst zugesagt, dann
  abgesagt".
- Die Nachricht ist keine offensichtliche Zusage. Kurze Muster wie „ja",
  „ok", „passt", „bin dabei", „danke", ein einzelnes Emoji sowie
  Knopf-Antworten werden ohne Modell als „keine Absage" verbucht.

**6. Das Modell waehlt nur aus einer vorgegebenen Liste.**
Eingabe: der Nachrichtentext, die letzten Nachrichten des Gespraechs als
Kontext (inkl. der gerenderten Bestaetigungs-Anfrage) und die Liste der
Kandidaten-Einbuchungen (id, Veranstaltung, Datum, Zeit). Ausgabe als JSON:
`{"absage": bool, "sicherheit": "high|medium|low", "einbuchungen": [ids],
"grund": "<max 200 Zeichen>"}`. Nur ids aus der Kandidatenliste werden
uebernommen (gleicher Manipulationsschutz wie `MatchApplicantToPostingJob`).
Gemeldet wird bei `absage=true` und Sicherheit `high` oder `medium`; `low`
wird nur protokolliert. Nennt das Modell keine Einbuchung, gelten alle
Kandidaten der Person in dieser Veranstaltung.
Aufruf ueber `OpenAiService::chat` mit dem Standardmodell des Providers,
wie im Modul ueblich.

**7. Eine Pruefung = eine Zeile, auch ohne Treffer.**
Neue Tabelle `rec_dispo_decline_checks`, eine Zeile je (Nachricht,
Veranstaltung): `team_id`, `filial_nr`, `rec_dispo_event_id`,
`rec_employee_id` (kanonisch), `comms_whatsapp_message_id`,
`outcome` (`decline` | `no_decline` | `pattern_skip` | `failed`),
`used_llm` (bool), `confidence`, `assignment_ids` (json), `reason`,
`excerpt` (Nachrichtentext, gekuerzt), `alarm_message_id`,
`review_status` (null | `open` | `accepted` | `dismissed` | `superseded`),
`reviewed_by_user_id`, `reviewed_at`. Eindeutig auf (Nachricht,
Veranstaltung) — ein doppelt zugestellter Webhook prueft nicht zweimal.
Daraus speisen sich Markierung, Zaehler und die Nachvollziehbarkeit.

**8. Zaehler „geprueft diesen Monat".**
Im Einstellungsfenster je Filiale: Anzahl Zeilen mit `used_llm = true` im
laufenden Monat, daneben die Zahl der Meldungen. Keine Euro-Angabe.

**9. Alarm ans Diensthandy: eine Nachricht je Meldung.**
Neue Vorlage, auswaehlbar im Einstellungsfenster wie die bestehende
Alarm-Vorlage (Setting `dispo_decline_alarm_template_id`). Vertrag der
Vorlage: drei Platzhalter im Text — `{{1}}` Name, `{{2}}` Veranstaltung,
`{{3}}` Datum(e). Hat die Vorlage einen URL-Knopf mit Platzhalter, wird die
Veranstaltungs-id mitgegeben (Sprung direkt in die VA); ohne Knopf wird kein
Knopf-Parameter gesendet (sonst lehnt Meta ab). Telefon ueber
`PhoneE164::normalize` wie beim bestehenden Alarm. Fehlen Diensthandy oder
Vorlage, wird trotzdem markiert — nur der Alarm entfaellt (protokolliert).

**10. In der Veranstaltung: Chip, Filter, Chat-Banner.**
- Die Zeile bekommt einen bernsteinfarbenen Chip **moegliche Absage**
  (bewusst nicht rot — es ist noch keine).
- Neuer Status-Filter `suspected` neben „abgesagt" (Pills und mobiles
  Dropdown).
- Klick auf den Chip oeffnet den bestehenden Chat der Person. Ueber den
  Nachrichten ein Hinweis-Kasten: Auszug, Begruendung des Modells, betroffene
  Tage, zwei Knoepfe:
  - **Absage erfassen** oeffnet das **bestehende** Absage-Fenster,
    vorbelegt: Grund „abgesagt", Notiz = Nachrichtenauszug, Tage = die
    gemeldeten Einbuchungen. Portalsperre und HR-Uebergabe entscheidet der
    Disponent wie heute. Erst `saveDecline()` sagt ab; dabei werden offene
    Meldungen der Person in dieser VA auf `accepted` gesetzt.
  - **Keine Absage** setzt die Meldung auf `dismissed`, nichts sonst.
- In der Veranstaltungsuebersicht ein Zaehler-Abzeichen neben dem
  bestehenden 💬-Abzeichen fuer offene Meldungen.

**11. Spaetere Bestaetigung raeumt die Meldung ab.**
Wird eine gemeldete Einbuchung nach der Meldung bestaetigt (`confirmed_at`
juenger als die Pruefung), gilt die Meldung als `superseded` und verschwindet.
Ausgewertet beim Lesen, nicht per Hook in den Bestaetigungswegen.

**12. Fehler sind folgenlos.**
Modell nicht erreichbar oder Antwort nicht lesbar: `outcome = failed`, Job
versucht es bis zu 3-mal, danach Log und Ende. Es wird nie abgesagt, nie
markiert, nie alarmiert ohne lesbare Einstufung.

## Datenschutz
Nachrichtentexte gehen an den angebundenen Sprachmodell-Anbieter — wie heute
schon bei der Bewerber-Zuordnung (`MatchApplicantToPostingJob`). Durch den
Vorfilter nur von Mitarbeitern mit kommendem Einsatz und nur, solange die
Filiale den Schalter an hat.

## Bekannte Grenzen
- Sprachnachrichten und Bilder werden nicht geprueft.
- Eine Absage ohne vorherige Bestaetigungs-Anfrage (Person noch nicht
  angeschrieben) wird nicht erkannt — bewusst, das haelt das Volumen klein.
- Die Einstufung bleibt eine Einschaetzung; darum Entscheidung 1.

## Nicht in diesem Bau
- Automatisches Absagen (Entscheidung 1).
- Absage-Knopf fuer Mitarbeiter (Entscheidung 2).
- Kostenanzeige in Euro.
- Erkennung anderer Anliegen (Verspaetung, Rueckfragen).

## Tests
- Vorfilter und Zusage-Muster: Unit, rein.
- Auslesen der Modellantwort inkl. Fremd-ids, Prosa um das JSON, fehlende
  Felder: Unit, rein.
- Kandidaten-Auswahl, Dedup, Schalter-Untergrenze, superseded: Integration
  (Capsule/SQLite).
- Alarm-Parameter mit und ohne URL-Knopf: Unit.
- Annehmen/Verwerfen als eigener Dienst, ohne Livewire testbar.
- Blade: `tools/blade-check.php`, geteilte Partials bekommen explizite
  Variablen.

## Deploy
`migrate` (neue Tabelle + Spalte), `view:clear`, **`queue:restart`**.
Danach: Vorlage bei Meta anlegen und im Einstellungsfenster waehlen, Schalter
je Filiale einschalten.
