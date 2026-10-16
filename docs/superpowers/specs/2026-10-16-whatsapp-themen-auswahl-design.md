# WhatsApp-Themen-Auswahl für Mitarbeiter + „Offen lassen“

Stand 16.10.2026 (Nachtrag abends nach Gegenlesen Sebastian/Claude) · Status: **Konzept abgestimmt, Plan folgt** · Ziel-Branch: `feat/ma-konto`
Auslöser: Mail Markus 16.10. („wie wir den Wahnsinn weiter reduzieren können“) + Telefonat Markus/Sebastian.

Diese Spec ist für eine Session geschrieben, die den Vorlauf nicht kennt. Alles Nötige steht hier.

---

## 1. Ausgangslage (auf main, seit 16.10.2026 live)

**Kommunikation „gelesen = erledigt“** (Spec `2026-10-16-kommunikation-gelesen-erledigt-design.md`, main `7bed50f3`):
- Offen = `comms_whatsapp_threads.is_unread` (CRM) **und** kein gültiger Stempel in `rec_conversation_handled`
  (`ConversationHandledState::isHandled` — ein neuerer Eingang hebt den Stempel auf).
- Erledigt-Knopf ist weg. Öffnen = gelesen = erledigt. „ungelesen schließen“ legt zurück.
- Button-Klicks stempeln sich selbst: `QuickReply::isQuickReply()` erkennt `meta_payload.type` `button`
  bzw. `interactive` + `button_reply|list_reply`; `QuickReplyStamper` stempelt **nur**, wenn der Klick der
  jüngste Eingang ist und seit der letzten menschlichen Antwort / dem letzten Stempel nichts getippt wurde.
  Aufruf im `HandleWhatsAppInboundForRecruiting` vor dem Kontext-Gate.
- „Menschliche Antwort“ = Ausgang mit `is_auto_reply = false` (`InboxQuery::humanOutboundTimestamps`).
- Mitarbeiter-Erkennung der Liste: `InboxQuery::kindOf()` — Thread am `RecEmployee` **oder** Bewerbung,
  aus der ein aktiver Mitarbeiter wurde.

**CRM kann jetzt interaktiv senden** (platform-crm `803308f`, in meingedeck gebumpt `4f82f2af`):
```php
WhatsAppMetaService::sendInteractive(
    CommsChannel $channel, string $to, array $interactive, string $displayBody,
    ?User $sender = null, bool $isAutoReply = false,
): CommsWhatsAppMessage
```
`$interactive` = Meta-„interactive“-Objekt 1:1 (`button` | `list` | `cta_url`). Gespeichert als
`message_type = 'interactive'`, `body = $displayBody`. Empfang von Klicks gab es schon
(`processIncomingMessage`: Titel in `body`, unsere ID in `meta_payload.interactive.button_reply.id`).

**Auf `feat/ma-konto` vorhanden und zu nutzen:** `OffenePunkte::fuer()` (was im Portal für einen MA offen ist),
`portal_v2_since` (ohne → kein Portal-Knopf), Regel „deaktivierte Anstellung bekommt nichts“
(`DokumentHinweisSender`), Versender-Muster mit drei Abwehren (`AufgabenSender`: Status `failed` ist Fehlschlag,
kein stiller Platzhalter-Ersatz, `send*()` im try/catch).

**Meta-Grenzen:** interaktive Nachrichten nur im offenen 24h-Fenster (hier immer offen — die Person hat gerade
geschrieben). `button`: max. 3 Buttons, Titel ≤ 20 Zeichen. `list`: ≤ 10 Zeilen, Titel ≤ 24. `cta_url`: **genau ein**
Link-Button pro Nachricht. Kosten: vom Kunden angestoßene Service-Nachrichten — vor Bau gegen aktuelles
Meta-Preismodell prüfen.

---

## 2. Teil A — Themen-Auswahl

### 2.1 Auslöser

Eingang auf dem **HR-Kanal** (`RecruitingChannelResolver::channelIds`), und **alle** Bedingungen erfüllt:

1. Absender ist **aktiver Mitarbeiter** (Anstellung `is_active`). Bewerber und inaktive Mitarbeiter bekommen nichts.
   Prüfung in **einer** Klasse kapseln (z. B. `ThemenAuswahlEmpfaenger`), damit die Personen-Klammer aus
   `feat/ma-konto` sie später ersetzen kann.
2. In diesem Thread lief in den letzten **8 Stunden** nichts — **in keine Richtung** (auch unsere Ausgänge,
   Kampagnen, Erinnerungen, Auto-Antworten zählen). Grund: Wer auf unsere Nachricht antwortet, hat kein neues
   Anliegen. Wert als Team-Setting (`comms_topic_quiet_hours`, Default 8).
3. Der Eingang ist **kein** Button-Klick (`QuickReply::isQuickReply`) und **keine** Sprachnachricht
   (dafür antwortet schon `VoiceNoteAutoReplyHandler`). **Bilder und Dokumente lösen aus** (Nachtrag 16.10.:
   ein Foto der Krankmeldung soll genauso zur Dispo finden wie ein Text).
4. Feature-Schalter im Team-Setting an (Default **aus**, damit der Rollout bewusst passiert).

**Abwesenheitsmodus aktiv** (entschieden 16.10.): **eine** Nachricht — oben der Abwesenheitstext (Daten aus
`comms_ooo_from/until/back_at`, als Freitext im Interactive-Body), darunter „Worum geht es?“ mit den drei Knöpfen.
Die Themen-Auswahl läuft im Listener **vor** `OooAutoReplyHandler`; hat sie gesendet, wird der OOO-Handler für diese
Nachricht übersprungen (keine zweite Nachricht). Für alle, die nicht auslösen, bleibt die OOO-Vorlage wie heute.

### 2.2 Nachricht 1: „Worum geht's?“

`sendInteractive` mit `isAutoReply: true`, `type: button`, drei Buttons, IDs fest:

| ID | Titel |
|---|---|
| `topic_dispo` | Disposition |
| `topic_lohn` | Lohn & Gehalt |
| `topic_hr` | HR allgemein |

Text in Du- und Sie-Form (Team-Setting `use_informal_address`, wie Public-Seiten — neue Texte immer als Ternary).
Kein „Pflicht“-Ton: wer die Buttons ignoriert und weiterschreibt, landet ganz normal bei HR.

### 2.3 Disposition

1. Klick `topic_dispo` → zweite Nachricht mit den **Filialen**. Quelle ist **keine neue Einstellung**, sondern
   `DispoForwardChannels::options($teamId)` (main 16.10., „An Dispo weiterleiten“): jeder aktive Dispo-Kanal mit
   Filialname („Dispo Köln“), Nummer = `comms_channels.sender_identifier`. IDs `dispo_<channel_id>`.
   Bis 3 Kanäle als `button`, ab 4 als `list` (≤ 10). Region wird **gewählt**, nicht abgeleitet; Vorbelegung
   per `preferredFor()` ist eine spätere Verfeinerung.
2. Klick `dispo_<channel_id>` → **zwei Dinge**:
   a) `cta_url`-Nachricht mit **einem** Link-Button „Zur Dispo <Label>“ →
      `https://wa.me/<Nummer ohne +>?text=<urlencode(ursprüngliche Nachricht)>`. Der MA tippt nur noch „Senden“ und
      schreibt **selbst** die Dispo an (öffnet dort das 24h-Fenster).
   b) **Automatische Weiterleitung** der Ursprungsnachricht an diesen Dispo-Kanal über
      `ConversationForwarder::forward(... target: DISPO, source: HR, targetChannelId)` mit Kommentar
      „Automatisch: Mitarbeiter hat Thema Disposition gewählt“ und `forwarded_by_user_id = null`. Damit liegt das
      Anliegen in der Dispo-Postfach-Karte, **auch wenn der MA den Link nicht drückt** (Gegenlesen 16.10.).
3. Danach den HR-Chat als erledigt stempeln, Grund `routed_dispo` (neue Konstante an `RecConversationHandled`) —
   er ist umgeleitet, Clara soll ihn nicht mehr sehen. Der Chip „Dispo → Köln“ bleibt unter „Alle“ sichtbar;
   ein neuer Eingang hebt den Stempel wie immer auf.

### 2.4 Lohn & Gehalt

Keine Unterpunkte. Nach dem Klick sofort automatisch:

- **Akte unvollständig** (`OffenePunkte::fuer()` hat offene Punkte):
  Nachricht „Damit deine Abrechnung stimmen kann, fehlt noch: <Punkte mit Namen>“ + `cta_url` ins Portal
  (nur mit `portal_v2_since`; sonst ohne Knopf, nur der Text). Chat **bleibt offen** bei HR — mit Thema
  „Lohn“ und Vermerk „Akte unvollständig · Hinweis gesendet“. Begründung: eine echte Lohnfrage
  („gar kein Geld bekommen“) darf nicht verschwinden.
- **Akte vollständig:** Nachricht „Hast du deine Stundenübersicht und Abrechnung kontrolliert? Dann kannst du
  gezielt fragen. So loggst du dich ein: <URL>“ (`cta_url`). URL als Team-Setting; Vorschlag: Seite im
  Anleitungs-Bereich der App (`docs/anleitungen/<slug>.md`) statt Fremd-URL. Chat bleibt offen, Thema „Lohn“.
- **Nachtrag (Umsetzung Task 4):** „ohne Knopf, nur der Text“ ist technisch ein einzelner Knopf „Alles klar“
  (`topic_ack`), weil das CRM (`WhatsAppMetaService::sendText`) Freitext nicht als Auto-Antwort markieren kann — ein
  reiner Text zählte als menschliche HR-Antwort. Der Klick auf „Alles klar“ bewirkt nichts und stempelt nicht.

### 2.5 HR allgemein

Nur Thema „HR“ setzen, sonst nichts. Chat bleibt offen.

### 2.6 Wechselwirkung mit dem Quick-Reply-Stempel (wichtig)

Die Klicks auf unsere Buttons sind `interactive/button_reply` → `QuickReplyStamper` würde sie als „keine
Arbeit“ stempeln. Für Lohn/HR darf das **nicht** passieren. Heute schützt `qualifies()` zufällig: die getippte
Ursprungsnachricht liegt vor dem Klick, unsere Auswahl ist `is_auto_reply = true` und zählt nicht als Antwort →
kein Stempel. **Nicht darauf verlassen:** Themen-Klicks (IDs `topic_*`, `dispo_*`) im Stamper ausdrücklich
ausnehmen und den Zustand allein vom Themen-Handler setzen lassen. Test dafür.

### 2.7 Daten

Neue Tabelle `rec_conversation_topics` (eine Zeile je Anliegen):
`team_id, comms_whatsapp_thread_id, topic (dispo|lohn|hr|null), state (asked|chosen|akte_hint_sent|lohn_info_sent|routed),
dispo_target_key, origin_message_id, asked_at, chosen_at, timestamps`.
„Aktuelles Anliegen“ = jüngste Zeile, deren `asked_at` nicht vor der letzten 8h-Ruhepause liegt.

**Nachtrag 16.10. (Anzeige, Task 5):** Für Chip, Filter und Zähler in der Kommunikation gilt eine abweichende
Regel, festgelegt vom Controller: je Thread die **jüngste Zeile**, und zwar ohne Filter auf `topic` und ohne
Ruhezeit-Grenze. Einen Chip gibt es nur, wenn diese Zeile ein Thema hat. Begründung: Ein offener Lohn-Chat
soll seinen Chip behalten, auch wenn die Wahl länger als 8 h zurückliegt. Eine neue Frage ersetzt die alte
Wahl trotzdem, weil sie eine neue Zeile ohne Thema anlegt. Die Regel liegt an einer Stelle:
`RecConversationTopic::aktuelleJeThread()`. Der Auslöser (Task 3) prüft die Ruhezeit weiterhin selbst, weil er
entscheidet, ob neu gefragt wird. Er zeigt nichts an. Fehlt die Tabelle (Deploy ohne migrate), läuft die
Ansicht ohne Chips weiter.

### 2.8 Kommunikations-Ansicht

- Chip am Chat (Liste + Kopf): „Lohn“, „HR“, „Dispo → Köln“, Vermerk „Akte unvollständig · Hinweis gesendet“.
- Filter nach Thema (Pillen oder Auswahl neben „Zuständig“).
- Recruiting-Chat zeigt `interactive`-Ausgänge als Text (`displayBody`) — echte Button-Optik ist Kür.

---

## 3. Teil B — „Offen lassen“ (Wiedervorlage)

Markus hat am Telefon den „doppelten Boden“ wieder geöffnet: Clara soll um 18 Uhr Chats bewusst als
**noch zu erledigen** markieren können.

- Knopf im Chat-Kopf „Offen lassen“ → Marker in neuer Tabelle `rec_conversation_followups`
  (`team_id, comms_whatsapp_thread_id, marked_by_user_id, marked_at, cleared_at, cleared_by_user_id`).
  „Ungelesen schließen“ bleibt daneben bestehen (Sebastian 16.10.: zwei verschiedene Dinge).
- Pille **„Offen N“** zeigt markierte Chats — unabhängig von gelesen/ungelesen und vom Stempel.
- **Gelöst wird der Marker nur bewusst** (Sebastian 16.10.): Knopf „Erledigt“ im Chat-Kopf eines markierten Chats
  setzt `cleared_at` + `cleared_by_user_id`. Eine Antwort von HR löst ihn **nicht** automatisch.
- Chip „offen gelassen von Clara · 18:05“ in Liste und Kopf.

---

## 4. Schnitt / Reihenfolge

Teil A §2.1–2.3, 2.5–2.8 und Teil B hängen **nicht** am Portal; §2.4 braucht `OffenePunkte` und
`portal_v2_since` → alles auf `feat/ma-konto`. `feat/ma-konto` fasst `InboxQuery`, `Livewire/Conversations/*`
und den Inbound-Listener bisher **nicht** an (geprüft 16.10.) — Konfliktgefahr mit main gering, trotzdem
vorher main in den Branch holen.

Rollout: Feature-Schalter erst nach Sichttest an; Texte von Markus abnehmen lassen.

---

## 5. Offene Fragen — Stand 16.10. abends

1. **Abwesenheitsmodus:** entschieden — eine kombinierte Nachricht (§2.1).
2. **Teil B vs. „ungelesen schließen“:** entschieden — beide bleiben, Marker nur bewusst lösen (§3).
3. **Filialen:** entschieden — aus `DispoForwardChannels`, keine neue Einstellung (§2.3). Offen bleibt nur, ob
   die drei Dispo-Nummern aus Sherans Text (+49 1523…, +49 1577…, +49 176…) wirklich Meta-Kanäle sind. → Markus/Sheran.
4. **Texte** der vier Nachrichten (Du/Sie): Vorgabetexte im Plan, als Team-Einstellungen überschreibbar, Markus nimmt ab.
5. **Login-Anleitung:** Team-Einstellung `comms_topic_lohn_url` (leer → Nachricht ohne Knopf). Markus liefert die URL.
6. **Mehrere Threads einer Person:** entschieden — Auslöser je Thread.
7. **Meta-Kosten:** vom Kunden ausgelöste Service-Gespräche sind seit 1.11.2024 kostenlos, interaktive Antworten im
   24h-Fenster auch. Vor dem Einschalten gegen das aktuelle Preisblatt prüfen.

## 6. Tests (Modul-Konvention: Unit pur + Integration mit Capsule/SQLite)

Auslöser (8h in beide Richtungen, nur aktiver MA, kein Klick, keine Sprachnachricht, Schalter aus),
Klick-Verarbeitung je ID, Stamper-Ausnahme für `topic_*`/`dispo_*`, `routed_dispo`-Stempel,
wa.me-Link mit URL-kodiertem Ursprungstext, Lohn-Zweig mit/ohne offene Punkte und mit/ohne `portal_v2_since`,
Followup-Marker erledigt sich durch menschliche Antwort, nicht durch Auto-Antwort.
`sendInteractive` in Tests über Unterklasse/Fake ersetzen (Muster `DokumentHinweisSender`).
