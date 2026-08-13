# Design: Stellen / Ausschreibungen / automatisches Bewerbungs-Matching

**Datum:** 2026-06-11
**Status:** Entwurf zur Review
**Scope:** Gesamtkonzept (Umsetzung wird separat in Phasen geschnitten)

## 1. Problem

Bewerbungen kommen heute fast ausschließlich über eine zentrale Sammeladresse (StepStone, Indeed, Kleinanzeigen, eigene Webseite — alles auf ein Postfach) sowie über WhatsApp rein. Die Zuordnung „Bewerbung → Ausschreibung" passiert aber nicht inhaltlich, sondern über die Kanal-Bindung: `IncomingApplicationService` hängt jeden neuen Bewerber an **alle offenen Postings des Kanals** (`rec_posting_comms_channel`). Folge:

- An mehreren Ausschreibungen hängen dieselben Eingangskanäle — die Verknüpfung ist faktisch bedeutungslos und ein Pflege-Wirrwarr.
- Bewerber landen pauschal an allen oder an der falschen Ausschreibung; die inhaltliche Zuordnung passiert manuell oder gar nicht.
- Die Kanal-Bindung hat zusätzlich eine versteckte Zweitfunktion: Sie entscheidet, ob ein CRM-Inbound überhaupt Recruiting betrifft (Kanal ohne offene Postings → ignoriert). Sie kann daher nicht ersatzlos entfallen.

## 2. Zielbild & Begriffe

| Begriff | Modell | Bedeutung |
|---|---|---|
| **Stelle** | `RecPosition` | Internes Arbeitsobjekt: Rolle an einem Einsatzort. Daran hängen Phasen (`RecPhase`), Interview-Termine (`RecInterview`), AutoPilot. **Bleibt unverändert.** |
| **Ausschreibung** | `RecPosting` | Nach außen gerichtete Stellenanzeige. Gehört zu genau **einer** Stelle (Einstiegspunkt). Langfristig vom Kunden anlegbar und Richtung Portale/Webseite schaltbar. |
| **Eingangskanal** | CRM `CommsChannel` + neu `rec_intake_channels` | Postfach/Nummer, auf dem Bewerbungen ankommen. Nur noch „Eingangstür" — **nicht mehr die Zuordnungsregel.** |
| **Eingangs-Quelle** | `RecSourcePlatform` (existiert) | Plattform/Absender-Klassifikation per Sender-Pattern (z. B. `@indeedemail.com` → Indeed). Wird zum Dispatcher für quellen-spezifische Referenz-Parser aufgewertet. |

Mentales Modell des Eingangs: **Bewerbung geht ein → Pipeline ermittelt die passende Ausschreibung → Ausschreibung hängt an einer Stelle → Bewerber bekommt deren erste Phase.** Die endgültige Stelle kann sich danach wie heute über Wunschorte + Terminbuchung ändern (`switchToPosition()`), daran ändert dieses Konzept nichts.

## 3. Getroffene Grundsatz-Entscheidungen

1. **Kardinalität bleibt 1 Ausschreibung = 1 Stelle.** Multi-Ort wird nicht über n:m gelöst, sondern über den existierenden Mechanismus Wunschorte-Extra-Feld + `switchToPosition()` bei der Buchung. Die Stelle an der Ausschreibung ist nur der Einstiegspunkt.
2. **Autonomie-Regel: sicher → automatisch, unsicher → Inbox.** Eindeutige Treffer (Referenz oder LLM mit hoher Konfidenz) werden ohne menschliches Zutun zugeordnet. Unsichere Fälle landen mit LLM-Vorschlag in der Eingangs-Inbox zur Ein-Klick-Bestätigung.
3. **Matching läuft vor dem Enrichment.** Das bestehende Enrichment (`EnrichInboxApplicants`) füllt Phasen-Extra-Felder; Phasen hängen an der Stelle. Ohne Posting keine Phase, ohne Phase kein Enrichment. Nach manueller Inbox-Zuordnung wird das Enrichment automatisch nachgezogen.
4. **Inhalts-Match schlägt Kanal-Default.** Wer an die Servicekraft-WhatsApp-Nummer „ich will als Koch arbeiten" schreibt, landet beim Koch.
5. **Ausschreibungen bleiben schlank.** Titel + Beschreibungstext (Felder existieren im Kern). Keine Gehaltsspannen-, Benefits- oder sonstigen Strukturfelder — alles Vertragliche ist nachgelagert.
6. **Modulgrenze:** Der gesamte Umbau passiert in `platforms-recruiting`. Die Kanäle selbst (`CommsChannel`) bleiben im CRM-Modul und werden dort weiter angelegt; Recruiting referenziert sie nur.

## 4. Zuordnungs-Pipeline (Phase 1 — Kern des Konzepts)

Eingehende Nachricht auf einem Intake-Kanal (E-Mail oder WhatsApp):

### Stufe 0 — Vorprüfung (wie heute, leicht erweitert)

- **Bestandscheck:** Absender (E-Mail/Telefon via CRM-Kontakt) existiert bereits als Bewerber → Antwort auf laufenden Vorgang. Note anhängen, AutoPilot-Reset wie heute. **Kein Matching.**
- **HCM-Check:** Absender ist bereits aktiver Mitarbeiter/Onboarding → ignorieren (wie heute).
- **Quellen-Erkennung:** Sender-Pattern gegen `RecSourcePlatform` matchen → `source_platform_id`. Kein Pattern-Treffer = starkes Indiz „keine echte Bewerbung" (fließt in Stufe 2 ein bzw. führt in die Inbox statt zur Auto-Anlage).

### Stufe 1 — Deterministisch (kostenlos, 100 % sicher)

Greift in dieser Reihenfolge:

1. **Dedizierter Kanal:** Der Kanal ist exklusiv an genau eine offene Ausschreibung gebunden (Kampagnen-Fall, z. B. eigene WhatsApp-Nummer pro Anzeige) → zuordnen, `matched_via = dedicated_channel`.
2. **Externe Referenz:** Quellen-spezifischer Parser extrahiert die Portal-Referenz aus der Nachricht — Indeed-Job-ID aus dem Body, Kleinanzeigen-Anzeigentitel aus dem Betreff, Posting-UUID aus dem Webseiten-Formular-Payload. Auflösung gegen `rec_posting_external_refs` → zuordnen, `matched_via = external_ref`.

### Stufe 2 — LLM-Matching (Fallback für unstrukturierte Eingänge)

Queue-Job mit der bestehenden LLM-Infrastruktur (`AiToolLoopRunner`). Input: Nachrichteninhalt + Anhänge-Auszug + Liste der **offenen** Ausschreibungen des Teams (Titel, Beschreibung, Stelle, Ort). Output: `posting_uuid` + Konfidenz + Begründung. Zusätzlich beantwortet derselbe Aufruf: **„Ist das überhaupt eine Bewerbung?"** (Portal-Systemmails, Spam → kein Bewerber, nur Log).

Entscheidungsregeln:

- **Rolle eindeutig + Ort eindeutig** → zuordnen, `matched_via = llm`.
- **Rolle eindeutig, Ort unklar** bei mehreren Orts-Varianten derselben Rolle → **trotzdem zuordnen**, deterministisch an die älteste offene Ausschreibung der Rolle. Begründung: Der echte Einsatzort klärt sich über Wunschorte + Buchung; eine „falsche" Orts-Variante der richtigen Rolle ist billig korrigierbar.
- **Rolle unsicher / mehrere Rollen plausibel / vermutlich keine Bewerbung** → weiter zu Stufe 3.

### Stufe 3 — Kanal-Default

Der Intake-Kanal kann optional eine Fallback-Ausschreibung haben (für inhaltsleere Erstnachrichten wie WhatsApp „Hallo"). Greift nur, wenn Stufe 1 + 2 nichts liefern → zuordnen, `matched_via = channel_default`.

### Stufe 4 — Eingangs-Inbox

Bewerber wird `is_unrouted = true` angelegt (existiert), **plus** gespeicherter LLM-Vorschlag: vorgeschlagene Ausschreibung, Konfidenz, Begründung. In der Eingangs-Inbox: Ein-Klick-Bestätigen oder manuell umhängen → `matched_via = manual`. Nach Zuordnung wird das Enrichment automatisch angestoßen.

### Beispiel-Durchlauf (realer Alteingang)

Kleinanzeigen-Mail, Betreff `Nutzer-Anfrage zu deiner Anzeige "SERVICEKRÄFTE | EVENTGASTRONOMIE | KÖLN"`, Inhalt: Erfahrung als Servicekraft/Küchenhilfe, Kontakt-Daten.

1. Sammeladresse = Intake-Kanal → Pipeline startet. Absender unbekannt → neuer Vorgang.
2. Stufe 0: Pattern `@mail.kleinanzeigen.de` → Quelle „Kleinanzeigen".
3. Stufe 1: Kleinanzeigen-Parser extrahiert Anzeigentitel aus dem Betreff → Treffer in `rec_posting_external_refs`? Wenn ja: fertig (`external_ref`). Wenn die Anzeige nicht gepflegt ist:
4. Stufe 2: LLM matcht Betreff + Inhalt gegen offene Ausschreibungen → „Servicekräfte Eventgastronomie Köln", hohe Konfidenz → zugeordnet (`llm`).
5. Bewerber hängt an der Stelle „Servicekraft Köln", bekommt deren erste Phase, Enrichment läuft an und füllt Extra-Felder.

## 5. Datenmodell-Änderungen (Phase 1)

### Neue Tabellen

**`rec_intake_channels`** — ersetzt die Filter-Funktion der Posting-Bindung:

| Spalte | Typ | Zweck |
|---|---|---|
| `comms_channel_id` | FK → `comms_channels` (CRM) | Welcher Kanal ist Bewerbungs-Eingangstür |
| `team_id` | FK → `teams` | Team-Scope |
| `default_posting_id` | FK → `rec_postings`, nullable | Fallback-Ausschreibung (Stufe 3) |
| `is_active` | bool | An/aus |

**`rec_posting_external_refs`** — geschaltete Anzeigen je Portal:

| Spalte | Typ | Zweck |
|---|---|---|
| `rec_posting_id` | FK → `rec_postings` | Welche Ausschreibung |
| `rec_source_platform_id` | FK → `rec_source_platforms` | Auf welchem Portal |
| `external_ref` | string | Job-ID / Anzeigentitel / UUID auf dem Portal |
| unique | (`rec_source_platform_id`, `external_ref`) | Eine Referenz zeigt eindeutig auf eine Ausschreibung |

### Erweiterte Tabellen

- **`rec_applicant_posting`** (Pivot): + `matched_via` (enum: `dedicated_channel`, `external_ref`, `llm`, `channel_default`, `manual`), + `match_confidence` (nullable). Jede Zuordnung ist damit auditierbar.
- **`rec_applicants`**: + `suggested_posting_id` (FK, nullable), + `match_reason` (text, nullable) — der LLM-Vorschlag für die Inbox (Stufe 4).
- **`rec_source_platforms`**: + Parser-Zuordnung (z. B. `ref_parser` string, nullable — welcher quellen-spezifische Extraktor gilt).

### Geänderte Semantik (kein Schema-Change)

- **`rec_posting_comms_channel`** bleibt bestehen, bedeutet aber nur noch **„dedizierter Kanal"** (Stufe 1, Kampagnen-Fall). Die Regel „Bewerber → alle offenen Postings des Kanals" wird ersatzlos entfernt.

## 6. Konfigurationsflächen (UI)

1. **Neu: Recruiting-Einstellungen → „Eingangskanäle":** Liste der CRM-Kanäle, Markierung als Intake-Kanal, optionale Default-Ausschreibung. Einmal konfiguriert, keine laufende Pflege mehr — neue Ausschreibungen brauchen keine Kanal-Verknüpfung.
2. **Eingangs-Quellen** (existiert, `RecSourcePlatform`-Settings): unverändert; perspektivisch um Parser-Auswahl je Quelle ergänzt.
3. **Ausschreibung (Show):** Das heutige Kanal-Anhängen/Abhängen (`Livewire/Posting/Show.php`) wird ersetzt durch: (a) optionales Feld „dedizierter Kanal", (b) Pflege der externen Referenzen („diese Anzeige läuft auf Indeed unter ID …").
4. **Eingangs-Inbox:** Unroutete Bewerber zeigen den LLM-Vorschlag (Ausschreibung + Begründung) mit Ein-Klick-Bestätigung und Umhängen-Option.

## 7. Migration / Aufräumen des Ist-Zustands

1. **Seeding (trivial, kein Muss):** Die Migration befüllt `rec_intake_channels` einmalig aus den distinct Kanälen in `rec_posting_comms_channel` (Default-Posting leer). Spart die manuelle Erstkonfiguration; ein kurzes Deploy-Fenster ist unkritisch.
2. Die alten `rec_posting_comms_channel`-Einträge der geteilten Kanäle (Sammeladresse, WhatsApp) werden gelöst; nur Kanäle, die wirklich exklusiv zu einer Ausschreibung gehören, bleiben als dedizierte Kanäle verknüpft. (Da heute dieselben Kanäle an mehreren Postings hängen, ist „exklusiv" automatisch erkennbar: genau eine Verknüpfung.)
3. Bestandsbewerber und deren Posting-Zuordnungen bleiben unberührt; `matched_via` bleibt für Altdaten `null`.
4. `IncomingApplicationService::handleInboundMessage()` wird auf die Pipeline umgebaut; `findPostingsForChannel()` (Zuordnung an alle offenen Postings) entfällt.

## 8. Phase 2 (Konzept): Ausschreibung als echte Stellenanzeige

Bewusst schlank gehalten:

- **Inhalte:** Titel + Beschreibungstext — im Wesentlichen die existierenden Felder. Keine Gehalts-/Benefits-/Strukturfelder.
- **Kunden-Anlage:** Kunde legt Ausschreibung als `draft` an → interne Freigabe → `published`. Permission-Modell + Freigabe-Schritt, der bestehende Status-Enum reicht (`draft`/`published`/`closed`).
- **Richtung Portale:** Kein Multiposting-API-Abenteuer. Anzeige wird manuell (oder später per Feed) bei StepStone & Co. geschaltet, die externe Job-ID wird als `rec_posting_external_refs`-Eintrag zurückgepflegt — und schließt damit den Kreis zur Matching-Pipeline aus Phase 1.
- **Später (eigener Wurf):** echtes Multiposting (API/HR-XML), Anzeigen-Vorlagen.

## 9. Edge Cases & Fehlerverhalten

| Fall | Verhalten |
|---|---|
| Bewerbung passt auf keine offene Ausschreibung (Initiativ) | Stufe 3 (Kanal-Default), sonst Inbox. Bewerber bleibt bis zur Zuordnung phasen-los (`is_unrouted`), Enrichment startet erst nach Zuordnung. |
| Referenz zeigt auf geschlossene Ausschreibung | Keine Auto-Zuordnung (würde Phase/AutoPilot an einer geschlossenen Anzeige starten) → Inbox mit der geschlossenen Ausschreibung als Vorschlag; Mensch entscheidet, ob reaktivieren oder umhängen. |
| Dieselbe Person bewirbt sich über zwei Portale | Bestandscheck (Stufe 0) fängt das ab — zweiter Eingang wird als Note an den bestehenden Bewerber gehängt. |
| Weitergeleitete Formular-Mails (eingebettete Bewerber-Adresse) | Bestehende Extraktion in `HandleCommsInboundForRecruiting` bleibt erhalten und läuft vor der Pipeline. |
| Portal-Systemmails / Spam auf der Sammeladresse | Stufe 0 (kein Quellen-Pattern) + Stufe 2 („keine Bewerbung") → kein Bewerber, nur Log-Eintrag. |
| LLM nicht erreichbar / Quota erschöpft | Job-Retry; nach finalem Fehlschlag → Inbox ohne Vorschlag (`is_unrouted`). Kein Eingang geht verloren. |
| Kosten/Latenz LLM | Bewusst akzeptiert: Bestandscheck + Stufe 1 fangen die Masse ab, Matching läuft asynchron als Queue-Job. |

## 10. Nicht-Ziele

- Keine Änderung an Stellen, Phasen, Interviews, AutoPilot, Wunschort-/`switchToPosition`-Mechanik.
- Kein n:m zwischen Ausschreibung und Stellen.
- Kein Multiposting-API, keine automatische Portal-Schaltung.
- Keine automatische Rückfrage an Bewerber bei unklarem Matching (bewusst verworfen zugunsten Kanal-Default + Inbox; kann später als Ausbaustufe kommen).
- Keine Änderungen außerhalb von `platforms-recruiting`.

## 11. Umsetzungshinweise (bekannte Risiken)

1. **Bewerber ohne Posting/Phase aushalten:** Zwischen Anlage und Matching-Job-Abschluss (bzw. bis zur Inbox-Bestätigung) existieren Bewerber ohne Posting. Code, der `primaryPosition()`/`rec_phase_id` stillschweigend voraussetzt, muss null-sicher sein. Der `is_unrouted`-Zustand existiert bereits — beim Umbau gezielt mittesten.
2. **LLM-Antwort hart validieren:** JSON-Schema erzwingen; nur Posting-UUIDs aus der mitgegebenen Kandidatenliste akzeptieren (schützt auch gegen Manipulationsversuche im Mail-Text). Parse-/Validierungsfehler → Inbox, nie raten.
3. **Prompt-Größe deckeln:** Posting-Beschreibungen im Matching-Prompt kappen (z. B. 300 Zeichen), CV-Auszug begrenzen.
4. **LLM-Aufwand:** Matching ist ein einzelner Klassifikations-Call (~3–6k Input-Tokens, ~150 Output) über die bestehende Provider-Infrastruktur (`CoreAiProvider`, aktuell OpenAI) — kein Tool-Loop. Das bestehende Enrichment bleibt unverändert und läuft wie heute **nach** der Zuordnung.
5. **Betrieb:** Nach Deploy `queue:restart`, sonst routet der alte Worker-Code weiter.

## 12. Test-Strategie (Umriss)

- **Unit:** Referenz-Parser je Quelle (Indeed, Kleinanzeigen, Webseite) gegen echte Beispiel-Mails; Pipeline-Stufen-Entscheidung als reine Logik testbar.
- **Feature:** Inbound-Simulation je Stufe (dedizierter Kanal, external_ref, LLM-mock, Default, Inbox) inkl. `matched_via`-Audit; Enrichment startet erst nach Zuordnung; Bestandscheck verhindert Duplikate.
- **Migration:** Bestehende Kanal-Verknüpfungen korrekt in Intake-Registry überführt, Altdaten unberührt.
