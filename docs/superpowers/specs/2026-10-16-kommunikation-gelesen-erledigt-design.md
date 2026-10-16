# Kommunikation: gelesen = erledigt

Stand 16.10.2026 · Auslöser: Mail Markus 15.10. + Antwort Clara + Entscheidung Markus 15.10. 14:17

## Anlass

Unter „Verpasst“ standen 322 Chats, teils seit Mai, viele davon ein „Ja“/„Nein“/„bin dabei“.
Markus (Originalwortlaut): „Erledigt Button bitte weg, in der ganzen Firma gilt, steht auf
ungelesen = muss noch bearbeitet werden, steht auf gelesen = erledigt, da wird nichts mehr mit
doppeltem Boden und extra zur Sicherheit geklickt.“ Clara: „Die ganzen alten Nachrichten müssten
da einmal aussortiert werden.“ Zu 14 Tagen: „Ja kann weg“. KI: erst mal nicht.

## Regel

Ein Chat ist **offen** (= ungelesen = muss bearbeitet werden), wenn

1. `comms_whatsapp_threads.is_unread = true` **und**
2. kein gültiger Stempel in `rec_conversation_handled` liegt
   (`ConversationHandledState::isHandled` — ein neuer Eingang nach dem Stempel hebt ihn auf).

Alles andere ist **gelesen = erledigt**.

Der Stempel bleibt als zweite Quelle bestehen. Er trägt drei Dinge, ohne dass wir in die
Core-Tabelle schreiben müssen: die 737 schon erledigten Chats, die Mehrfachauswahl und die
Aufräumaktion. Neu kommt der Grund `quick_reply` dazu.

## Verhalten

| Aktion | Wirkung |
|---|---|
| Chat öffnen | `markAsRead()` wie heute → erledigt. Offene Weiterleitungen an HR für diesen Chat werden erledigt; ihre IDs merkt sich die Komponente (`#[Locked]`). |
| „ungelesen schließen“ | `markAsUnread()` wie heute **plus** Stempel dieses Chats löschen (sonst bliebe er über den Stempel erledigt) **plus** die beim Öffnen erledigten Weiterleitungen wieder öffnen (`done_at = null`, nur die gemerkten IDs, nur eigenes Team, nur vom selben User erledigt). |
| Mehrfachauswahl | Knopf heißt „als gelesen markieren“, Funktion unverändert (Stempel `manual` + Weiterleitungen erledigen). |
| Button-Klick kommt rein | Listener stempelt den Thread mit Grund `quick_reply`. Erkannt am Meta-Typ im `meta_payload`: `button` (Vorlagen-Quick-Reply) oder `interactive` mit `button_reply`/`list_reply`. Der Core normalisiert beides zu `text`, deshalb nur über `meta_payload`. Gilt für jeden Button, keine Ausnahmeliste (geprüft 16.10.: nur `t05` Ja/Nein und `t_init` (Dispo-Kanal) haben Quick-Replies). |
| Neue getippte Nachricht | Core setzt `is_unread = true`, Stempel wird durch den neueren Eingang ungültig → offen. Wie heute. |

Weg fallen: Einzel-Knopf „Erledigt“, „zurückholen“, Pille „Erledigt N“, `InboxFilter::$handled`,
`Inbox::markHandled()`/`unmarkHandled()`/`toggleHandledView()`, Zähler `handled`.

## Liste und Zähler

- **„Alle“ zeigt alle Chats** (gelesen und ungelesen), neueste zuerst — wie WhatsApp.
  „Alle/Bewerber/Mitarbeiter“ zählen alle Chats.
- **Ungelesen/Grün/Gelb/Rot/Verpasst zählen und filtern nur offene Chats.**
  Ungelesen = alle offenen. Ampel = offene nach Fenster-Restzeit.
- **Erledigte Zeilen haben keine Eskalation** (`LEVEL_NONE`): keine Farbkante, kein
  „verpasst seit“. `InboxRow::$isUnread` = offen (fett/Punkt in der Liste).
- Sidebar (`unread`, `red + missed`) folgt automatisch.

## Einmalige Läufe (nach Deploy, je erst `--dry-run`)

1. `recruiting:conversations-archivieren --team=3 --older-than=14` — Altfälle (Grund `backfill`,
   unabhängig von Button oder nicht). Gibt es schon, nur einmal starten.
2. `recruiting:conversations-archivieren --team=3 --quick-replies` (neu) — stempelt Threads, deren
   letzter Eingang ein Button-Klick ist, mit Grund `quick_reply`, egal wie alt. Für die letzten
   14 Tage, die Lauf 1 nicht erfasst.

Rückholbar: `DELETE FROM rec_conversation_handled WHERE handled_reason IN ('backfill','quick_reply')`.
Es wird nichts gelöscht, und `is_unread` im Core wird nicht angefasst.

## Bewusst nicht

- Kein Schreiben in Core/CRM-Code. Kein Massen-Update auf `comms_whatsapp_threads`.
- Keine KI-Klassifizierung getippter Kurzantworten (Markus: erst wenn es mit dem neuen Portal hoch bleibt).
- Keine Dauerregel „nach 14 Tagen automatisch erledigt“ — einmalig, wie Clara es beschrieben hat.
- Dispo-Postfach bleibt unverändert.

## Bekannte Folge

„Gelesen“ gilt für alle. Öffnet Markus einen Chat, ist er für Clara erledigt — so gewollt (Regel Markus).

## Deploy

Keine Migration (Spalte `handled_reason` ist ein String). `view:clear`. Kein `queue:restart` nötig
(Listener läuft synchron im Webhook, Code wird per PHP-Reload aktiv).
