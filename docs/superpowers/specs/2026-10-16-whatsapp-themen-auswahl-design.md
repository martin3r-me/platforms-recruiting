# WhatsApp-Themen-Auswahl für Mitarbeiter + „Offen lassen“

Stand 16.10.2026 · Status: **Konzept abgestimmt, noch nicht gebaut** · Ziel-Branch: `feat/ma-konto`
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
   (dafür antwortet schon `VoiceNoteAutoReplyHandler`).
4. Feature-Schalter im Team-Setting an (Default **aus**, damit der Rollout bewusst passiert).

Offen: Verhalten bei aktivem Abwesenheitsmodus (`OooAutoReplyHandler`) — siehe §5.

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

1. Klick `topic_dispo` → zweite `button`-Nachricht mit den **Filialen**. IDs `dispo_<filial_nr>`.
   **Keine eigene Konfiguration** — die Zuordnung existiert schon: `rec_dispo_filiale_settings`
   (filial_nr → comms_channel_id), Namen aus `Support\Filialen` (`config('recruiting.filialen')`),
   Kanal-Set aus `DispoChannelResolver::dispoChannelIds()`. Angeboten wird jede Filiale mit Kanal; neue Kanäle
   erscheinen ohne Deploy. Stand Prod 16.10.2026:

   | filial_nr | Name | Kanal | Nummer |
   |---|---|---|---|
   | 100 | Düsseldorf & Essen | 33 | +49 211 78177725 |
   | 200 | Mönchengladbach | 31 | +49 211 78177723 |
   | 300 | Wuppertal | — (nicht angeboten) | — |
   | 400 | Köln | 32 | +49 211 78177729 |

   Button-Grenze 3 ist damit genau ausgeschöpft — bei einer vierten Filiale mit Kanal auf `list` umschalten.
   Region wird **gewählt**, nicht abgeleitet. Ableitung aus den Einsätzen ist eine spätere Verfeinerung.
   **Anzeigename bei Meta** ist bei allen drei Nummern gleich („RheinGedeck Disposition“) — der MA sieht im
   neuen Chat nicht, welche Filiale. Deshalb im vorausgefüllten Text die Filiale nennen
   („An Dispo Köln: …“); getrennte Anzeigenamen bei Meta wären zusätzlich möglich.
2. Klick `dispo_<key>` → `cta_url`-Nachricht mit **einem** Link-Button „Zur Dispo <Label>“ →
   `https://wa.me/<Nummer ohne +>?text=<urlencode(ursprüngliche Nachricht)>`. Der MA tippt nur noch „Senden“ und
   schreibt **selbst** die Dispo an (öffnet dort das 24h-Fenster; die Dispo darf ihn nicht von sich aus anschreiben).
3. Danach den HR-Chat als erledigt stempeln, Grund `routed_dispo` (neue Konstante an `RecConversationHandled`) —
   er ist umgeleitet, Clara soll ihn nicht mehr sehen.

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

### 2.8 Kommunikations-Ansicht

- Chip am Chat (Liste + Kopf): „Lohn“, „HR“, „Dispo → Köln“, Vermerk „Akte unvollständig · Hinweis gesendet“.
- Filter nach Thema (Pillen oder Auswahl neben „Zuständig“).
- Recruiting-Chat zeigt `interactive`-Ausgänge als Text (`displayBody`) — echte Button-Optik ist Kür.

---

## 3. Teil B — „Offen lassen“ (Wiedervorlage)

Markus hat am Telefon den „doppelten Boden“ wieder geöffnet: Clara soll um 18 Uhr Chats bewusst als
**noch zu erledigen** markieren können.

- Knopf im Chat-Kopf „Offen lassen“ → Marker in neuer Tabelle `rec_conversation_followups`
  (`team_id, comms_whatsapp_thread_id, marked_by_user_id, marked_at, cleared_at, cleared_reason`).
- Pille **„Offen N“** zeigt markierte Chats — unabhängig von gelesen/ungelesen.
- **Erledigt sich automatisch, sobald jemand antwortet** (menschlicher Ausgang `is_auto_reply = false`
  nach `marked_at`) — abgeleitet im Lesepfad wie `ConversationHandledState`, kein Job.
- Ausweg per Klick „erledigt“ (für Fälle, die am Telefon geklärt wurden), setzt `cleared_reason = manual`.
- Chip „offen gelassen von Clara · 18:05“.

---

## 4. Schnitt / Reihenfolge

Teil A §2.1–2.3, 2.5–2.8 und Teil B hängen **nicht** am Portal; §2.4 braucht `OffenePunkte` und
`portal_v2_since` → alles auf `feat/ma-konto`. `feat/ma-konto` fasst `InboxQuery`, `Livewire/Conversations/*`
und den Inbound-Listener bisher **nicht** an (geprüft 16.10.) — Konfliktgefahr mit main gering, trotzdem
vorher main in den Branch holen.

Rollout: Feature-Schalter erst nach Sichttest an; Texte von Markus abnehmen lassen.

---

## 5. Offene Fragen (vor dem Plan klären)

1. **Abwesenheitsmodus aktiv:** nur OOO-Antwort, nur Auswahl, oder beides in einer Nachricht?
2. **Teil B vs. „ungelesen schließen“:** beide Knöpfe behalten oder „ungelesen schließen“ durch „Offen lassen“
   ersetzen? (Empfehlung: ersetzen — ein Knopf, Marker erledigt sich durch Antwort.)
3. ~~Filialen~~ — geklärt 16.10., siehe §2.3 (Zuordnung aus `rec_dispo_filiale_settings`).
4. **Texte** der vier Nachrichten (Du/Sie) — Markus abnehmen lassen.
5. **Login-Anleitung:** welches Portal (ZAS?) und welche Ziel-URL.
6. **Ein MA mit mehreren Threads/Nummern:** Auslöser je Thread (Vorschlag) oder je Person?
7. **Meta-Kosten** der Service-Antworten bestätigen.

## 6. Tests (Modul-Konvention: Unit pur + Integration mit Capsule/SQLite)

Auslöser (8h in beide Richtungen, nur aktiver MA, kein Klick, keine Sprachnachricht, Schalter aus),
Klick-Verarbeitung je ID, Stamper-Ausnahme für `topic_*`/`dispo_*`, `routed_dispo`-Stempel,
wa.me-Link mit URL-kodiertem Ursprungstext, Lohn-Zweig mit/ohne offene Punkte und mit/ohne `portal_v2_since`,
Followup-Marker erledigt sich durch menschliche Antwort, nicht durch Auto-Antwort.
`sendInteractive` in Tests über Unterklasse/Fake ersetzen (Muster `DokumentHinweisSender`).
