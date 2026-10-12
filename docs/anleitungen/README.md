# Anleitungen für den Kunden

Die Anleitungen erscheinen im Recruiting unter **Hilfe → Anleitungen** (für alle Nutzer, auch
eingeschränkte Zugänge) und lassen sich dort als PDF speichern. Sie werden mit dem Paket
ausgeliefert. Spec: `docs/superpowers/specs/2026-10-12-anleitungen-bereich-design.md`.

## Eine Anleitung schreiben

- Datei `docs/anleitungen/<slug>.md`, Slug nur Kleinbuchstaben, Ziffern, Bindestriche.
- Bilder in `docs/anleitungen/bilder/<slug>/`.
- Sprache des Kunden: Du-/Ihr-Form, keine Technik, Schritte so, wie man klickt.
- Nach jedem kundensichtbaren Feature nachziehen; ändert sich die Oberfläche, Datei und Bilder anpassen.

Aufbau:

    ---
    titel: …
    bereich: Bewerber | Schulungen | Mitarbeiter | Verträge | Dispo
    stand: JJJJ-MM-TT
    seiten: [routenname, …]      # dort erscheint das „?“
    ---

    ## Kicker | Titel             # neue Folie
    Einleitungssatz (optional)

    ![Was das Bild zeigt](datei.png)
    <!-- shot: seite=/recruiting/… klicks="Text; Text" ausschnitt="Text" markieren="Text" -->

    1. Schritt
    2. Schritt

    > Merksatz

    | Thema | So ist es |      # Tabelle für „Gut zu wissen“
    | --- | --- |

Höchstens ein Bild je Folie. Fehlt die Bilddatei, zeigt die Folie einen Platzhalter mit dem Text
in den eckigen Klammern. Der Test `AnleitungenInhaltTest` prüft vor dem Merge, dass jede Datei
lesbar ist, jede Route in `seiten` existiert und jedes Bild eine Datei oder ein Rezept hat.

## Screenshots per Rezept

    cd tools/anleitungen-screenshots && npm install    # einmalig
    node tools/anleitungen-screenshots <slug>           # eine Anleitung
    node tools/anleitungen-screenshots --alle           # alle neu
    node tools/anleitungen-screenshots --anmelden       # Sitzung erneuern

- Läuft gegen `https://demo.bhgdigital.de` (nur Testdaten). Beim ersten Lauf öffnet sich Chrome,
  einmal bei Microsoft anmelden; die Sitzung liegt in `~/.config/rg-anleitungen/sitzung.json`.
- Rezept-Schlüssel: `seite` (Pfad), `klicks` (mit `;` getrennt), `ausschnitt`, `markieren`,
  `handy` (390×844), `manuell` (Bild selbst ablegen). Werte sind sichtbare Texte; mit `css:`
  davor ein CSS-Selektor.
- Feste Regeln: 1440×900, doppelte Pixeldichte, helles Design, Ausschnitt mit 16 px Rand,
  Markierung als blauer Rahmen (#1273DE).
- Die Demo läuft auf dem Konto-Zweig. Seiten, die dort anders aussehen als live, erst nach
  dessen Merge neu aufnehmen.
- Bilder nach dem Lauf ansehen, Rezept nachbessern, dann committen.
