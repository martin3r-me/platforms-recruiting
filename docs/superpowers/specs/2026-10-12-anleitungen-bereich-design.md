# Anleitungen im Recruiting — Design

Stand: 12.10.2026 · Auftrag: User (Kundenwunsch „aktuelle Anleitungen je Bereich, im Web lesen und als PDF“)

## Ziel

Rheingedeck findet an der Stelle, an der jemand nicht weiterweiß, mit einem Klick die passende
Anleitung — im Look der Kundenfolien (blauer Rand, Kicker, Titel, Schritte links, Screenshot
rechts, Fußzeile „RheinGedeck · Intern“), am Bildschirm lesbar und als PDF zum Weitergeben.
Wir pflegen die Anleitungen; mit jedem Deploy ist der Kunde aktuell.

Erfolg heißt: eine neue Funktion bekommt ihre Anleitung als **eine Datei + Bilder** im Repo,
ohne Code-Änderung, und sieht automatisch aus wie alle anderen.

## Entscheidungen

**1. Eine Quelle: eine Datei pro Anleitung im Repo.** `docs/anleitungen/<slug>.md`, Bilder in
`docs/anleitungen/bilder/<slug>/`. Kein Editor in der Oberfläche, keine Datenbank. Web-Seite und
PDF entstehen aus derselben Datei. Das bisherige Python-PPTX-Skript wird nach der Umstellung
stillgelegt (ein Stand statt zwei).

**2. Format.** Kopf (Front Matter), dann Folien:

```
---
titel: Absage-Erkennung in der Dispo
bereich: Dispo            # Bewerber | Schulungen | Mitarbeiter | Verträge | Dispo
stand: 2026-10-12
seiten: [recruiting.dispo.settings, recruiting.dispo.events.show]   # dort erscheint das „?“
---

## Dispo · Absage-Erkennung | Haken setzen
Optionaler Einleitungssatz (grau, über den Schritten).

![Einstellungen mit Haken „prüfen“](haken-pruefen.png)
<!-- shot: seite=/recruiting/dispo-settings ausschnitt="Filial-Konfiguration" markieren="prüfen" -->

1. Schritt eins mit **Fettung**.
2. Schritt zwei.

> Merksatz in Blau unter den Schritten.
```

- `## Kicker | Titel` beginnt eine Folie. Ohne `|` ist alles Titel, Kicker = Bereich.
- Erkannt werden je Folie: Einleitung (Absätze vor der Liste), Schritte (nummerierte oder
  Aufzählungsliste), höchstens **ein** Bild, Merksatz (Zitat), Tabelle (zwei Spalten,
  „Gut zu wissen“). Fettung, Kursiv, Inline-Code werden übernommen; rohes HTML wird nicht
  ausgegeben (nur der `shot`-Kommentar wird gelesen).
- Layout ergibt sich aus dem Inhalt: **mit Bild** Schritte links / Bild rechts; **ohne Bild**
  Schritte über die volle Breite (zweispaltig ab 4 Schritten); **Tabelle** als Regel-Tabelle
  über die volle Breite.
- Fehlerhafte Datei (kein Kopf, Pflichtfeld fehlt, mehr als ein Bild in einer Folie) bricht
  die Seite nicht: die Anleitung erscheint in der Übersicht mit Hinweis „fehlerhaft“ und dem
  Grund; ein Test über alle Dateien im Repo fängt das vor dem Merge.

**3. Seiten.**
- Menüpunkt **„Anleitungen“** ganz unten in der Recruiting-Leiste — für **alle** Nutzer,
  auch die eingeschränkten Zugänge („Nur Veranstaltungen“, „Schulungsbewertung“); alle sehen
  alle Anleitungen (User 12.10.). `RecruitingRouteGate` lässt dafür die Anleitungs-Routen für
  beide Stufen durch; die eingeschränkte Leiste bekommt den Punkt ebenfalls.
- **Übersicht** `/recruiting/anleitungen`: Kacheln nach Bereich gruppiert (Titel, Stand,
  Folienzahl).
- **Anleitung** `/recruiting/anleitungen/{slug}`: links Inhaltsverzeichnis (Folientitel,
  Sprungmarken, hebt die sichtbare Folie hervor), rechts die Folien-Karten. Oben „Als PDF
  speichern“. Handy: Verzeichnis oben, Schritte und Bild untereinander.
- Slug = Dateiname ohne `.md`, nur `[a-z0-9-]`; alles andere → 404.

**4. „?“ auf den Arbeitsseiten.** Ein kleines Blade-Element `anleitung-hilfe` sucht zur
aktuellen Route die Anleitungen, deren Kopf sie unter `seiten` nennt, und zeigt ein „?“
(bei mehreren ein kleines Menü). Eingebaut zunächst in: Dispo-Einstellungen, VA-Seite,
Schulungstermine. Weitere Seiten, sobald es Anleitungen dafür gibt.

**5. PDF = Druckansicht des Browsers.** Knopf ruft `window.print()`. Eigene Druck-CSS:
A4 quer, jede Folie genau eine Seite (`break-after: page`), Leiste/Menü/Knöpfe/Verzeichnis
ausgeblendet, Fußzeile „RheinGedeck · Intern“ + Seitenzahl je Folie, Farben werden gedruckt
(`print-color-adjust: exact`). Kein Server-PDF (DomPDF kann das Layout nicht; Schriftärger).

**6. Bilder.**
- Ausgeliefert über eine eigene Route `/recruiting/anleitungen/{slug}/bild/{datei}`, nur für
  angemeldete Nutzer, nur `png|jpg|webp` aus genau `bilder/<slug>/` (kein `..`, kein
  Pfad-Trenner im Namen), mit Cache-Header. Nichts wird nach `public/` kopiert.
- Fehlt ein Bild: grauer Platzhalter im Bildformat mit dem Alt-Text („was das Bild zeigen
  soll“). Anleitungen sind damit auch vor den Screenshots veröffentlichbar.

**7. Screenshots standardisiert per Rezept (Demo-Umgebung).**
- Jedes Bild trägt unter der Bildzeile ein Rezept
  `<!-- shot: seite=… [klicks="…; …"] [ausschnitt="…"] [markieren="…"] [handy] [manuell] -->`.
  `ausschnitt`/`markieren`/`klicks` sind sichtbare Texte (Überschrift, Knopfbeschriftung)
  oder CSS-Selektoren; Texte sind lesbarer und überleben Umbauten besser.
- Ein lokales Skript `tools/anleitungen-screenshots/` (Node + Playwright, nutzt das
  installierte Chrome) liest die Rezepte und erzeugt die Bilder gegen
  **demo.bhgdigital.de** (Team 6, nur Testdaten → keine Anonymisierung).
  Aufruf: `node tools/anleitungen-screenshots <slug|--alle>`.
- Anmeldung: Demo-Login läuft über Microsoft. Beim ersten Lauf öffnet das Skript ein
  sichtbares Chrome, der User meldet sich einmal an; die Sitzung wird lokal außerhalb des
  Repos gespeichert (`~/.config/rg-anleitungen/sitzung.json`) und wiederverwendet, bis sie
  abläuft. Keine Zugangsdaten im Repo, keine Änderung an der Demo.
- Feste Bildregeln: Fenster 1440×900, Pixeldichte 2, helles Design, Ausschnitt =
  Element + 16 px Rand, Markierung = 3 px Rahmen in Rheingedeck-Blau (#1273DE) mit leichtem
  Abstand, `handy` = 390×844. PNG unter dem Namen aus der Bildzeile.
- `manuell` überspringt das Bild (z. B. WhatsApp auf dem Handy); der User legt es unter
  demselben Namen ab.
- Ablauf beim Schreiben einer Anleitung: Text + Rezepte schreiben → Skript laufen lassen →
  Bilder ansehen und Rezepte nachbessern → committen. Nach Oberflächen-Änderungen:
  `--alle` erneuern, der Diff zeigt geänderte Bilder.
- Bekannte Grenze: Die Demo läuft auf dem Konto-Zweig (`feat/ma-konto`), nicht auf main.
  Bilder von Seiten, die dort anders aussehen als live, erst nach dessen Merge erneuern.
  Fehlen auf der Demo die Szenen (z. B. eine VA mit „mögliche Absage“), legen wir sie dort
  mit den vorhandenen Test-Kommandos an.

**8. Erste Inhalte.**
- `schulungstermine-umplanen` und `schulungsnachbereitung-vertragsdaten`: auf das neue
  Format umgestellt; Texte und vorhandene Screenshots aus dem PPTX übernommen (Rezepte
  nachgetragen).
- Neu: `dispo-absage-erkennung` — Haken je Filiale (gilt nur heute), Vorlage, Diensthandy;
  was beim Alarm passiert; Meldung in der VA (Chip, Filter „⚠ Mögliche Absage“,
  Chat-Hinweis); „Absage erfassen“ / „Keine Absage“; violette Sprechblase bei
  „Wo bist du?“; Gut zu wissen (KI sagt nie selbst ab, Sprachnachrichten nicht geprüft,
  nur ab Einschalten und nur heute, Zähler).
- README in `docs/anleitungen/` auf das neue Verfahren umgeschrieben (Format, Rezepte,
  Skript, Regeln), Satz „wird nicht ausgeliefert“ korrigiert.

## Aufbau (Code)

- `Support/Anleitungen/AnleitungParser` — Datei → `Anleitung` (Kopf, Folien) bzw. Fehler.
  Rein, ohne Framework, Markdown über `league/commonmark` (schon vorhanden).
- `Support/Anleitungen/AnleitungKatalog` — liest den Ordner, liefert Übersicht, einzelne
  Anleitung per Slug, Anleitungen zu einer Route; je Request einmal gelesen.
- Livewire `Anleitungen\Index`, `Anleitungen\Show`; Controller für Bilder; Blade-Partial
  `folie` (eine Karte, auch für den Druck), Komponente `anleitung-hilfe` („?“).
- Routen in `routes/web.php` unter `recruiting.anleitungen.*`; `RecruitingRouteGate` erlaubt
  `recruiting.anleitungen.index|show|bild` für beide eingeschränkten Stufen.

## Tests

- Parser (Unit): Folien-Trennung, Kicker/Titel, Einleitung/Schritte/Bild/Merksatz/Tabelle,
  Rezept-Lesung, rohes HTML wird nicht ausgegeben, Fehlerfälle.
- Alle echten Dateien in `docs/anleitungen/` parsen fehlerfrei, jede verwiesene Route in
  `seiten` existiert, jedes Bild hat entweder eine Datei oder ein Rezept.
- Route-Gate: Anleitungs-Routen für beide eingeschränkten Stufen offen, alles andere
  unverändert zu.
- Bild-Auslieferung: nur Dateien aus `bilder/<slug>/`, Ausbruchsversuche (`..`, `%2F`,
  fremde Endung, fremder Slug) → 404.
- Blade-Check über die neuen Views; Sichttest am Bildschirm und im Druck (Chrome, Safari).

## Bewusst nicht

Editor/Upload in der Oberfläche, Suche, Klick-Rundgang durch die echte Oberfläche,
Server-PDF, Rechte je Anleitung, Versionsverlauf für den Kunden.

## Deploy

Kein `migrate`, kein `queue:restart`; `view:clear`.
