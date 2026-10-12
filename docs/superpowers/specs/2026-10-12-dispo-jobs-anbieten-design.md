# Dispo „Jobs anbieten“ — Design

Stand: 12.10.2026 · Status: Entwurf · Modul: platforms-recruiting
Grundlage: Kunden-PDF `Bauauftrag_Disposition_Jobanfragen_v6` (6 Seiten), Telefonat S. Haustein / Markus
05.10., Entscheidungen S. Haustein 12.10.

## 1. Ziel

Ein Disponent sieht: „Mir fehlen für das Oktoberfest am 24.09. zwanzig Zapfer ab 17 Uhr.“ Statt Leute einzeln
anzurufen, wählt er die Veranstaltung, die Schicht und eine Zielgruppe (Filiale, Einsätze im laufenden Monat),
und schickt eine kurze WhatsApp mit persönlichem Link. Der Mitarbeiter sieht auf der Seite nur Schichten an
Tagen, an denen er frei ist, und tippt „Ich bin dabei“. Der Disponent sieht die Zusagen in einer Liste, trägt
die Leute **selbst in ZAS** ein und hakt sie ab. Die nächste ZAS-Lieferung bringt die Einbuchung zu uns; ab
dort läuft der bestehende Ablauf (Bestätigungsanfrage, Eskalation, Einsatz-Seite) unverändert.

Es ist ein **Anschreib-Werkzeug mit Rückmeldungs-Liste, kein Buchungssystem**: Wir schreiben nichts nach ZAS,
und eine Zusage ist noch keine Einbuchung.

## 2. Entscheidungen (12.10., S. Haustein; Markus 05.10.)

| Thema | Entscheidung |
|---|---|
| Woher kommen die freien Plätze? | Der Disponent sagt es selbst: je Schicht eine Zahl. Automatischer Vorschlag aus den ZAS-Platzhaltern (RG0/RG14) bleibt offen, Markus muss bestätigen, dass diese Zeilen immer „unbesetzt“ heißen. Das Design hält den Platz dafür frei (§4.3). |
| Mehr Zusagen als Plätze | Je Anfrage wählbar: **„Alle Zusagen sammeln“** (Standard; der Disponent sucht aus) oder **„Wer zuerst kommt“** (die Seite zeigt „noch N frei“ und schließt die Schicht, sobald sie voll ist). |
| Wortwahl | Auf der Seite „Ich bin dabei“, nie „buchen“. Rückmeldung: „Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.“ |
| Wer darf es nutzen | Alle vollen Recruiting-Zugänge. Die eingeschränkten Konten („Nur Veranstaltungen“, z. B. event@rheingedeck.de) nicht. |
| Reihenfolge im Ablauf | Filiale der Veranstaltung **zuerst**, dann Zeitraum, dann Veranstaltungen. |
| Mitarbeiterfiliale | Vorbelegt mit der Veranstaltungsfiliale, umstellbar. |
| Wellen | Jede Welle startet der Disponent selbst — sofort oder „in X Minuten“ (frei einstellbar, kein Zwang). Je Welle bekommt jeder Mitarbeiter höchstens eine Nachricht; wer in dieser Anfrage schon angeschrieben wurde, bekommt in einer späteren Welle keine zweite. |
| Link-Position | Der Link ist ein URL-Button; bei WhatsApp steht er immer **unter** dem Text. „Link zuerst“ geht nicht (bereits 05.10. geklärt). |

## 3. Befunde, auf denen das Design steht

- **Veranstaltung und Filiale.** `rec_dispo_events.filial_nr` (100 DUS & ES, 200 MGL, 300 WUP, 400 CGN,
  1100 DUS-V; `Support\Filialen`). Die VA-Liste (`Dispo\Events\Index`) filtert heute schon nach `filial_nr` und
  Datumsbereich über die Einbuchungen (`whereHas('assignments', whereDate('datum' …))`).
- **Schichten.** `rec_dispo_assignments` trägt je Zeile `datum`, `von`, `bis`, `taetigkeit`. Die vorkommenden
  Kombinationen einer VA sind genau die Werte, die der Spaltenfilter (`DispoRowFilters::options`) schon anbietet.
- **Mitarbeiterfiliale.** Der Mitarbeiter hat **kein Filial-Feld**. ZAS liefert aber die Kostenstelle
  (`rec_employees.cost_center`, Rückfall `position->cost_center`), und die trägt dieselben Nummern wie die
  Filialen (100/200/…; so auch im ZAS-Export, `ZasEmployeeFieldResolver` „Kostenstelle“). **Vorflug vor dem
  Bau:** `SELECT cost_center, COUNT(*) FROM rec_employees WHERE is_active = 1 GROUP BY 1` auf Prod — die
  Verteilung muss zu den Filialen passen, sonst braucht die Zuordnung eine Tabelle.
- **Qualifikation.** Seit 08.10. liegen die ZAS-Tätigkeiten je Mitarbeiter in
  `rec_employee_hr_data.dispo_taetigkeiten` (JSON-Liste von Bezeichnungen, Abgleich über den Namen).
- **Handynummer, Token, Versand.** `DispoEmployeeGateway::contacts()` liefert Nummer (E.164) und
  `portal_token`. Die Einsatz-Seite (`/einsaetze/{token}`, `Public\EmployeeAssignments`) kennt den
  Mitarbeiter allein über `rec_employees.portal_token`. `DispoInfoSender` schickt eine Vorlage mit
  `{{name}}` im Text und dem Token als Suffix im URL-Button (Index 0) — genau das Muster, das wir brauchen.
  Kanal: `DispoChannelResolver::resolveForEvent()` (Filial-Kanal, sonst Standard).
- **Kampagnen-Muster.** Queue-Job je Versand, Fortschritt im Cache (`{total,sent,failed,skipped,done,errors}`,
  `wire:poll.3s`), Vorlagen-Vorschau mit `CampaignTemplatePreview::render` (Text statt Vorlagenname, Wächter:
  URL-Button an Position 0 dynamisch, keine Platzhalter außer dem Vornamen).
- **Rechte.** `RecruitingRouteGate::EVENT_ROUTES` ist eine Positivliste. Neue Routen, die dort nicht stehen,
  sind für „Nur Veranstaltungen“ automatisch gesperrt. Es ist nichts zu tun außer: *nicht* eintragen.
- **Es fehlt:** ein Zähler „Einsätze im laufenden Monat“ je Person, eine Tagesprüfung „ist an Datum X
  irgendwo gebucht“, und jede Form von Angebots-Datensatz. Das wird neu gebaut.
- **Identität.** Eine Person kann als RG- und als MA-Datensatz existieren (`DispoIdentityResolver::groupFor`).
  Zählung, Tagesprüfung und „eine Nachricht je Person“ laufen über die Personengruppe, nicht über die
  einzelne `rec_employees.id`.

## 4. Ablauf für den Disponenten

Eigener Bereich **Disposition → Jobs anbieten**. Eine *Anfrage* ist ein Vorgang mit Schichten, Wellen und
Rückmeldungen. Die Übersicht listet laufende und abgeschlossene Anfragen; „Neue Anfrage“ führt durch fünf
Schritte (§4.1–4.5); Wellen und Rückmeldungen (§4.6–4.7) liegen danach auf der Seite der Anfrage. Von der VA-Seite kommt man mit dem Knopf **„Jobs anbieten“** direkt hinein — Filiale und
Veranstaltung sind dann vorbelegt, es geht bei Schritt 4 weiter.

### 4.1 Filiale
Pflichtauswahl aus den Filialen, die in `rec_dispo_events.filial_nr` vorkommen. Die letzte Wahl wird je Nutzer
gemerkt (Session).

### 4.2 Zeitraum
Von/bis, Vorbelegung morgen bis +28 Tage („nächste vier Wochen“). Vergangene Tage sind nicht wählbar.

### 4.3 Veranstaltungen
Liste der VAs der Filiale, die im Zeitraum mindestens einen Einsatztag haben (gleiche Abfrage wie die
VA-Liste, ohne verschwundene/zur Löschung gemeldete Zeilen). Je VA ein Haken; je Zeile Name, Datum, Ort,
„N Einbuchungen“. Mehrfachauswahl.

### 4.4 Tage und Schichten
Je gewählter VA die Einsatztage im Zeitraum als Pillen; je Tag die vorkommenden Schichten
(Arbeitsbeginn × Tätigkeit) als Zeilen mit einem Zahlenfeld **„gesucht“** (Standard leer = nicht anbieten).
Zusätzlich „Andere Schicht“: Beginn (HH:MM) und Tätigkeit (Auswahl aus dem ZAS-Katalog, `Dispo4`) frei
eingeben — für den Fall, dass die Schicht in ZAS noch gar nicht angelegt ist.
Platz für Markus' Antwort: Trägt eine Schicht RG0/RG14-Zeilen, zeigt die Zeile deren Zahl als Hinweis
„laut ZAS N unbesetzt“ (nur Anzeige, keine Vorbelegung, bis Markus bestätigt).

### 4.5 Empfänger
- **Mitarbeiterfiliale:** vorbelegt mit §4.1, umstellbar, Mehrfachauswahl (z. B. MGL + DUS).
- **Gruppen nach Einsätzen im laufenden Monat:** `0` · `1–4` („weniger als 5“) · `5–9` („weniger als 10“) ·
  `10–19` („weniger als 20“) · `alle`. Mehrfachauswahl = Vereinigung. Die Bänder sind **disjunkt**, damit
  „nächste Gruppe“ in Welle 2 nicht dieselben Leute trifft wie Welle 1. Beschriftung behält das Kundenwort
  („weniger als 5“) und nennt das Band in Klammern.
- **Passende Tätigkeit:** Haken „nur Mitarbeiter, die die gesuchte Tätigkeit laut ZAS haben“ — Standard **an**,
  mit Zahl daneben, damit der Disponent den Effekt sieht. Bei 0 Treffern ein Hinweis, dass die Tätigkeiten
  aus ZAS vielleicht noch fehlen.
- **Vorlage:** Auswahl aus den in den Dispo-Einstellungen hinterlegten Jobangebots-Vorlagen; Vorschau als
  Sprechblase mit Beispielname (Muster Kampagnen-Modal). Vorlagen-Wächter wie bei den Kampagnen.
- **Modus:** „Alle Zusagen sammeln“ (Standard) / „Wer zuerst kommt“.
- **Zahlen vor dem Senden** (Muster `DispoRecipientPlanner`): „Sendet an N Personen“, darunter die Töpfe
  „ohne Handynummer“, „an allen gewählten Tagen schon gebucht“, „Portal gesperrt“, „ohne passende Tätigkeit“,
  „in dieser Anfrage schon angeschrieben“. Jede Zahl aufklappbar mit Namen.

### 4.6 Wellen
Eine Welle = eine Empfängerauswahl (§4.5) + Start. Der Disponent legt die erste Welle an und startet sie:
**„Jetzt senden“** oder **„In X Minuten senden“** (Zahlenfeld, Vorschlag 30). Eine geplante Welle lässt sich
bis zum Start abbrechen. Danach kann er beliebig weitere Wellen mit anderer Gruppenauswahl anlegen
(typisch: Welle 1 = `0`, Welle 2 = `1–4`, …). Die Empfängerzahlen werden **beim Start** neu berechnet, nicht
beim Anlegen — wer inzwischen gebucht ist oder zugesagt hat, fällt heraus.

### 4.7 Rückmeldungen
Je Schicht: „gesucht N · zugesagt M · in ZAS eingetragen K“. Darunter die Zusagen mit Name, Zeit der Zusage,
Handynummer, Link zur MA-Akte, und ein Haken **„in ZAS eingetragen“**. Bringt eine spätere ZAS-Lieferung für
diese Person eine Einbuchung an dieser VA an diesem Tag, erscheint automatisch „✓ in ZAS angekommen“ —
unabhängig vom Haken. Der Disponent sieht so, ob er jemanden vergessen hat.
Eine Anfrage wird **abgeschlossen**, wenn der Disponent es tut oder der letzte angebotene Tag vorbei ist.
Abgeschlossene Anfragen zeigen auf der Mitarbeiter-Seite nichts mehr an.

## 5. Seite für den Mitarbeiter

Route `/jobs/{token}` (öffentlich, Guest-Layout, Muster `Public\EmployeeAssignments`), Token =
`rec_employees.portal_token`. **Ein Link je Person**, nicht je Anfrage: Die Seite bündelt alle offenen Angebote
an diese Person über alle laufenden Anfragen. Dieselbe URL steht in jeder Nachricht.

Angezeigt wird je Veranstaltung und Tag die Schicht (Datum, Beginn, Tätigkeit, Ort) — **nur**, wenn
1. die Person diese Schicht angeboten bekommen hat (ein Angebots-Datensatz existiert),
2. die Anfrage läuft und die Schicht nicht geschlossen ist,
3. die Person an diesem Tag **nirgends** gebucht ist (Tagesprüfung erneut beim Aufruf, nicht nur beim Versand),
4. der Tag nicht vorbei ist.

Je Schicht ein Knopf **„Ich bin dabei“**. Im Modus „Wer zuerst kommt“ steht daneben „noch N frei“; ist die
Schicht voll, bleibt sie sichtbar mit „schon voll — danke fürs Melden“ und ohne Knopf. Nach dem Tippen:
„Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.“ Eine Zusage lässt sich auf der Seite nicht
zurücknehmen (dafür ist der Chat da; der Disponent sieht die Antwort in der Kommunikation).
Portal-Sperre (`portal_locked_at`) gilt wie auf der Einsatz-Seite. Unbekannter Token → 404.

## 6. Nachricht

Drei Meta-Vorlagen (Utility, de), legt der Kunde an; Kurztexte aus dem PDF:
„Vier Wochen: Jetzt Einsätze buchen.“ · „Nächste Woche: Wir brauchen dich.“ · „Dringend: Bitte diesen
Einsatz buchen.“ (Wortlaut der Vorlagen ist Kundensache; die Seite dahinter sagt „Ich bin dabei“.)
Anforderung an jede Vorlage: Text nur mit `{{name}}` (optional), **URL-Button an Position 0 dynamisch**
mit Suffix = Token, Ziel `https://<host>/jobs/{{1}}`. Einstellungs-Schlüssel `dispo_job_offer_template_ids`
(Liste), gepflegt auf der Dispo-Einstellungsseite neben den anderen Dispo-Vorlagen.

Versand über den Filial-Kanal der Veranstaltungsfiliale (`DispoChannelResolver`). Jede gesendete Nachricht
landet wie heute im Chat-Verlauf der Person.

## 7. Datenmodell

Vier Tabellen, alle `team_id`-gescopt, Zeitstempel in Serverzeit (Europe/Berlin wie Prod).

- `rec_dispo_job_requests` — die Anfrage. `uuid`, `filial_nr`, `zeitraum_von/bis`, `modus`
  (`sammeln`|`zuerst`), `whatsapp_template_id`, `status` (`entwurf`|`laeuft`|`abgeschlossen`),
  `created_by_user_id`, `closed_at`.
- `rec_dispo_job_shifts` — angebotene Schicht. `request_id`, `rec_dispo_event_id`, `datum`, `von`,
  `taetigkeit`, `gesucht` (int), `closed_at` (Modus „zuerst“: voll). Eindeutig je
  (request, event, datum, von, taetigkeit).
- `rec_dispo_job_waves` — Welle. `request_id`, `nr`, `ma_filial_nrs` (JSON), `gruppen` (JSON, z. B.
  `["0","1-4"]`), `nur_passende_taetigkeit` (bool), `scheduled_for`, `started_at`, `finished_at`,
  `job_uuid` (Cache-Schlüssel des Fortschritts), `summary` (JSON: gesendet/fehlgeschlagen/übersprungen je Topf).
- `rec_dispo_job_offers` — ein Angebot = eine Person in einer Welle. `wave_id`, `request_id`,
  `rec_employee_id` (der Datensatz, an dessen Nummer gesendet wurde), `anchor_employee_id` (kleinste
  `rec_employees.id` der Personengruppe — darüber läuft „eine Nachricht je Person“),
  `message_id` (Comms), `sent_at`, `send_error`.
- `rec_dispo_job_offer_shifts` — welche Schichten in diesem Angebot standen (nur die freien Tage der Person).
  `offer_id`, `shift_id`, `accepted_at`, `zas_entered_at` (Haken), `zas_entered_by_user_id`,
  `zas_assignment_id` (automatisch, wenn die Einbuchung ankommt).

Die Tagesprüfung ist bewusst **nicht** gespeichert, sondern wird zweimal live gerechnet (Versand, Aufruf).

## 8. Bausteine (Code)

- `Services/Zas/Dispo/Jobs/DispoEinsatzZaehler` — rein: Einsätze je Personengruppe im Kalendermonat
  (Status Auftrag, nicht storniert/verschwunden/zur Löschung gemeldet/abgesagt). Liefert die Band-Zuordnung.
- `Services/Zas/Dispo/Jobs/DispoTagesPruefung` — rein: Menge der Tage, an denen eine Personengruppe gebucht
  ist (gleiche Zeilenregel). Eingabe: Gruppen-IDs + Datumsliste.
- `Services/Zas/Dispo/Jobs/DispoJobRecipientPlanner` — rein (Muster `DispoRecipientPlanner`): aus
  Kandidaten + Zähler + Tagesprüfung + Qualifikation + bisherigen Angeboten der Anfrage → `recipients`
  (mit ihren freien Schichten) und `skipped` je Topf.
- `Services/Zas/Dispo/Jobs/DispoJobOfferSender` — schickt die Vorlage (Muster `DispoInfoSender`), legt
  `offers` + `offer_shifts` an, stempelt `message_id`/`send_error`.
- `Jobs/SendDispoJobWave` — Queue-Job (Muster `SendNewDatesCampaign`): plant beim Start neu, sendet,
  schreibt Fortschritt in den Cache, schließt die Welle (`finished_at`, `summary`). Verzögerter Start über
  `delay`; Abbruch = Welle gelöscht, der Job prüft beim Lauf, ob sie noch existiert und `scheduled_for` noch
  gilt.
- `Services/Zas/Dispo/Jobs/DispoJobZasAbgleich` — nach jedem Webexport-Import: für offene Zusagen prüfen, ob
  eine Einbuchung (Person, VA, Datum) angekommen ist → `zas_assignment_id`. Aufruf aus
  `ZasDispoWebexportImporter` nach dem Schreiben.
- Livewire `Dispo\Jobs\Index` (Übersicht), `Dispo\Jobs\Create` (Schritte 1–5, Vorbelegung per Query-Parameter
  `event`), `Dispo\Jobs\Show` (Wellen, Rückmeldungen, Abschluss), `Public\JobOffers` (Mitarbeiter-Seite).
- Routen `recruiting.dispo.jobs.index|create|show` unter `/dispo-jobs`, öffentlich
  `recruiting.public.job-offers` unter `/jobs/{token}`. Sidebar-Eintrag „Jobs anbieten“ nur im vollen Zweig.
- VA-Seite: Knopf „Jobs anbieten“ → `create?event={id}`.
- Dispo-Einstellungen: Mehrfachauswahl „Vorlagen für Jobangebote“.

## 9. Fehlerfälle

- Keine Vorlage hinterlegt → Schritt 5 zeigt den Hinweis mit Link zu den Einstellungen, Senden gesperrt.
- Vorlage ohne dynamischen URL-Button an Position 0 oder mit fremden Platzhaltern → roter Kasten, Senden
  gesperrt (Text aus `CampaignTemplatePreview::warnung`).
- Meta lehnt einzelne Nachrichten ab → `send_error` am Angebot, Welle läuft weiter, Zusammenfassung nennt die
  Zahl; die Person erscheint im Reiter „Nicht zugestellt“ der Kommunikation wie jede andere gescheiterte
  Nachricht.
- Null Empfänger beim Start → Welle endet sofort mit Zusammenfassung „niemand übrig“ und den Töpfen.
- Zusage auf eine inzwischen geschlossene/vergangene Schicht (Doppelklick, alter Tab) → Seite lädt neu,
  Schicht ist weg, keine Zeile.
- Zwei Anfragen bieten derselben Person dieselbe Schicht an → auf der Seite einmal; Zusage zählt in beiden.

## 10. Tests

- Unit (rein): `DispoEinsatzZaehlerTest` (Bandgrenzen 0/4/5/9/10/19/20, Monatsgrenze, Storno/verschwunden
  zählen nicht, RG+MA-Gruppe wird zusammengezählt), `DispoTagesPruefungTest`,
  `DispoJobRecipientPlannerTest` (jeder Topf einzeln, Reihenfolge der Prüfungen, „schon angeschrieben“ über
  Wellen hinweg, Mutationsprobe: leeres Gruppen-Array heißt niemand, nicht alle), Vorlagen-Wächter.
- Integration (Capsule/SQLite, Muster `DispoInfoSenderTest`): Sender legt Angebote + Schichten an und stempelt
  message_id; Mitarbeiter-Seite zeigt nur freie, offene Schichten; „Ich bin dabei“ im Modus „zuerst“
  schließt bei voll und lehnt die (N+1)-te Zusage ab; ZAS-Abgleich setzt `zas_assignment_id`; Welle mit
  `scheduled_for` in der Zukunft sendet nicht; gelöschte Welle sendet nicht.
- `RecruitingRouteGateTest`: `recruiting.dispo.jobs.*` für „Nur Veranstaltungen“ gesperrt, öffentliche
  Route ungegated.
- `tools/blade-check.php` über alle neuen Views; Sichttest Desktop (Disponent) und Handy (Mitarbeiter-Seite).

## 11. Auslieferung

Migrationen (5 Tabellen) → `migrate`; Queue-Job neu → `queue:restart`; `view:clear`.
Danach: drei Meta-Vorlagen anlegen (Kunde), in den Dispo-Einstellungen wählen, Testanfrage gegen eine
Test-VA mit der eigenen Nummer.

## 12. Bewusst nicht (Runde 1)

- Kein Schreiben nach ZAS, keine automatische Einbuchung.
- Keine Erinnerung an Angeschriebene ohne Antwort, keine zweite Nachricht in derselben Anfrage.
- Kein „Kann nicht“-Knopf auf der Seite (Absagen kommen über den Chat).
- Keine Haken in der VA-Liste („Ausgewählte anbieten“) — die Mehrfachauswahl steckt im Schritt 3.
- Keine automatische Vorbelegung der Platzzahl aus RG0/RG14 (offen bei Markus).
- Kein Zugang für „Nur Veranstaltungen“-Konten.

## 13. Offen bei Markus

1. Stehen RG0/RG14 in ZAS immer für unbesetzte Plätze? (→ Vorbelegung „gesucht“.)
2. Gruppen als disjunkte Bänder (0 / 1–4 / 5–9 / 10–19) — passt das zu „nächste Gruppe“?
3. Ist die Kostenstelle des Mitarbeiters die richtige „Mitarbeiterfiliale“? (→ Vorflug-Abfrage.)
