"""
Kundenfolien "Anleitung: Schulungstermine umplanen" (Folienfassung des
Bausteins docs/anleitungen/2026-10-08-schulungstermine-umplanen.md) im Stil der
RheinGedeck-HCM-Folien (blauer Rand links, Kicker, Titel, Schritte links,
Screenshot rechts, Fusszeile "RheinGedeck · Intern").

Aufruf:
    python schulungstermine_folien.py <screenshot-ordner> <ziel.pptx>

Screenshots liegen anonymisiert in folien/screenshots/ (nie echte Namen/Fotos). Erwartete Dateinamen
siehe SCREENSHOTS unten; fehlt eine Datei, steht dort ein beschrifteter
Platzhalter. Braucht python-pptx und Pillow.
"""
import sys
from pathlib import Path

from PIL import Image
from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.util import Emu, Inches, Pt

BLAU = RGBColor(0x12, 0x73, 0xDE)
SCHWARZ = RGBColor(0x11, 0x11, 0x11)
GRAU = RGBColor(0x5F, 0x63, 0x68)
LINIE = RGBColor(0xB8, 0xBC, 0xC4)
PLATZHALTER = RGBColor(0xF1, 0xF3, 0xF5)
FONT = "Calibri"

# Dateiname → was darauf zu sehen sein soll (steht auch im Platzhalter).
SCREENSHOTS = {
    "A_liste_angehakt.png": "Teilnehmerliste (Übersicht) mit angehakten Personen und blauem Balken „N ausgewählt · Verschieben nach…“",
    "B_fenster_verschieben.png": "Fenster „Teilnehmer verschieben“ mit Zieltermin-Auswahl und Kommentarfeld",
    "C_status_teilgenommen.png": "Eine Zeile der Teilnehmerliste mit Status-Auswahl „Teilgenommen“",
    "F_statistik_pille.png": "Statistik → Schulungstermine: Badge „Ausgebucht“ und Pille „N ohne Termin“",
    "G_kampagne_fenster.png": "Kampagnen-Fenster: Anlass-Karte, Empfängerliste, Nachricht, Senden-Knopf",
}


def text(slide, x, y, w, h, inhalt, groesse, fett=False, farbe=SCHWARZ, ausrichtung=PP_ALIGN.LEFT, anker=MSO_ANCHOR.TOP):
    box = slide.shapes.add_textbox(Inches(x), Inches(y), Inches(w), Inches(h))
    tf = box.text_frame
    tf.word_wrap = True
    tf.vertical_anchor = anker
    tf.margin_left = tf.margin_right = Inches(0.05)
    zeilen = inhalt if isinstance(inhalt, list) else [inhalt]
    for i, zeile in enumerate(zeilen):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = ausrichtung
        p.space_after = Pt(groesse * 0.6)
        # **fett** innerhalb einer Zeile
        teile = zeile.split("**")
        for j, teil in enumerate(teile):
            if not teil:
                continue
            r = p.add_run()
            r.text = teil
            r.font.name = FONT
            r.font.size = Pt(groesse)
            r.font.bold = fett or (j % 2 == 1)
            r.font.color.rgb = farbe
    return box


def rahmen(slide, nr, kicker, titel):
    bar = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, Inches(0.17), Inches(7.5))
    bar.fill.solid(); bar.fill.fore_color.rgb = BLAU; bar.line.fill.background()
    text(slide, 0.67, 0.36, 11.46, 0.3, kicker.upper(), 12, fett=True, farbe=BLAU)
    text(slide, 0.67, 0.80, 11.96, 0.7, titel, 28.5, fett=True)
    linie = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0.56), Inches(1.57), Inches(12.17), Emu(9144))
    linie.fill.solid(); linie.fill.fore_color.rgb = LINIE; linie.line.fill.background()
    text(slide, 0.67, 7.02, 6.77, 0.3, "RheinGedeck · Intern", 10.5, farbe=GRAU)
    text(slide, 12.04, 7.02, 0.58, 0.3, f"{nr:02d}", 10.5, farbe=GRAU, ausrichtung=PP_ALIGN.RIGHT)


def bild(slide, ordner, datei, x, y, w, h):
    """Screenshot einpassen (Seitenverhaeltnis bleibt), sonst Platzhalter."""
    pfad = ordner / datei
    if pfad.exists():
        with Image.open(pfad) as im:
            bw, bh = im.size
        faktor = min(w / bw, h / bh)
        pw, ph = bw * faktor, bh * faktor
        pic = slide.shapes.add_picture(str(pfad), Inches(x + (w - pw) / 2), Inches(y + (h - ph) / 2), Inches(pw), Inches(ph))
        pic.line.color.rgb = LINIE
        pic.line.width = Pt(0.75)
        return
    box = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(y), Inches(w), Inches(h))
    box.adjustments[0] = 0.04
    box.fill.solid(); box.fill.fore_color.rgb = PLATZHALTER
    box.line.color.rgb = LINIE; box.line.width = Pt(1.25); box.line.dash_style = 4  # gestrichelt
    tf = box.text_frame; tf.word_wrap = True; tf.vertical_anchor = MSO_ANCHOR.MIDDLE
    for i, (zeile, groesse, fett) in enumerate([(f"Screenshot {datei.split('_')[0]}", 16, True), (SCREENSHOTS[datei], 13, False), (datei, 10, False)]):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = PP_ALIGN.CENTER
        r = p.add_run(); r.text = zeile
        r.font.name = FONT; r.font.size = Pt(groesse); r.font.bold = fett; r.font.color.rgb = GRAU


def hinweis(slide, x, y, w, zeilen):
    """Blaue Kernaussagen rechts neben einem Bild (wie Folie 2 im Original)."""
    text(slide, x, y, w, 4, zeilen, 19, fett=True, farbe=BLAU)


def schrittfolie(s, ordner, schritte, merksatz=None, datei=None, merksatz_farbe=BLAU, oben=1.94):
    """Schritte links, Screenshot rechts. Ohne Screenshot laufen die Schritte
    zweispaltig ueber die ganze Breite (kein leerer Platzhalter)."""
    mit_bild = datei is not None and (ordner / datei).exists()
    if mit_bild:
        text(s, 0.75, oben, 3.6, 4.0, schritte, 17)
        if merksatz:
            text(s, 0.75, 5.75, 3.6, 1.1, merksatz, 16, fett=merksatz_farbe == BLAU, farbe=merksatz_farbe)
        bild(s, ordner, datei, 4.55, oben - 0.06, 8.05, 6.88 - oben)
        return
    mitte = (len(schritte) + 1) // 2
    text(s, 0.75, oben, 5.7, 5.9 - oben, schritte[:mitte], 18)
    text(s, 6.85, oben, 5.7, 5.9 - oben, schritte[mitte:], 18)
    if merksatz:
        text(s, 0.75, 6.0, 11.8, 0.8, merksatz, 16, fett=merksatz_farbe == BLAU, farbe=merksatz_farbe)


def tabelle(s, zeilen):
    tab = s.shapes.add_table(len(zeilen), 2, Inches(0.75), Inches(1.9), Inches(11.8), Inches(0.6 * len(zeilen))).table
    tab.columns[0].width = Inches(3.3); tab.columns[1].width = Inches(8.5)
    tab.first_row = False
    for i, (a, b) in enumerate(zeilen):
        for j, wert in enumerate((a, b)):
            zelle = tab.cell(i, j)
            zelle.fill.solid(); zelle.fill.fore_color.rgb = RGBColor(0xFF, 0xFF, 0xFF) if i % 2 else PLATZHALTER
            zelle.margin_left = Inches(0.12); zelle.margin_top = zelle.margin_bottom = Inches(0.05)
            p = zelle.text_frame.paragraphs[0]
            r = p.add_run(); r.text = wert
            r.font.name = FONT; r.font.size = Pt(14); r.font.bold = j == 0
            r.font.color.rgb = BLAU if j == 0 else SCHWARZ


def bauen(ordner: Path, ziel: Path):
    prs = Presentation()
    prs.slide_width, prs.slide_height = Emu(12192000), Emu(6858000)
    leer = prs.slide_layouts[6]
    nr = 0

    def folie(kicker, titel):
        nonlocal nr
        nr += 1
        s = prs.slides.add_slide(leer)
        rahmen(s, nr, kicker, titel)
        return s

    # --- Teilnehmer verschieben ------------------------------------------------
    s = folie("Teilnehmer verschieben · Schritt 1", "Teilnehmer auswählen")
    schrittfolie(s, ordner, [
        "1. **Schulungstermine** öffnen und den Termin anklicken, aus dem ihr verschieben wollt (Schulung A).",
        "2. In der Teilnehmerliste die Personen **anhaken**. Das Kästchen oben in der Kopfzeile wählt alle verschiebbaren auf einmal.",
        "3. Oben erscheint der blaue Balken „N ausgewählt“. Dort auf **„Verschieben nach…“** klicken.",
    ], "Geht vorher und auch während der Schulung.", "A_liste_angehakt.png")

    s = folie("Teilnehmer verschieben · Schritt 2", "Zieltermin wählen und verschieben")
    schrittfolie(s, ordner, [
        "4. Im Fenster den **Zieltermin** wählen (Schulung B). Hinter jedem Termin steht, wie viele Plätze noch frei sind.",
        "5. Optional einen **Kommentar** eintragen, z. B. „Logistik-Gruppe“. Er steht später im Verlauf des Bewerbers.",
        "6. Auf **„Verschieben“** klicken. Fertig.",
    ], "Status, Bestätigung und Notizen bleiben. Wer schon erinnert wurde, bekommt keine zweite Erinnerung. Es geht keine Nachricht raus.",
        "B_fenster_verschieben.png")

    s = folie("Teilnehmer verschieben · Während der Schulung", "Erst „Teilgenommen“, dann in Gruppen aufteilen")
    text(s, 0.75, 1.91, 11.8, 0.8, "Verschieben geht auch, solange der Termin läuft, und auch mit Teilnehmern, die schon auf **Teilgenommen** stehen. In der großen Gruppe alle Anwesenden auf Teilgenommen setzen, danach in die Gruppen verschieben. Der Status wandert mit.", 18)
    bild(s, ordner, "C_status_teilgenommen.png", 0.75, 2.95, 7.97, 3.85)
    hinweis(s, 9.18, 3.0, 3.4, ["Ziel: gleiche Stelle", "Bis der Zieltermin zu Ende ist", "Teilgenommen nur am selben Tag"])
    text(s, 9.18, 5.75, 3.4, 1.0, "Im Zieltermin steht an der Person „aus …“ mit dem alten Termin.", 15)

    # --- Kampagne „Schulung voll“ ---------------------------------------------
    s = folie("Wenn eine Schulung voll ist · Schritt 1", "Bewerber ohne Termin finden")
    text(s, 0.75, 1.75, 11.8, 0.8, "Ist ein kommender Termin ausgebucht, schreibt ihr mit einem Klick alle Bewerber dieser Ausschreibung ohne Termin an. Sie bekommen eine WhatsApp mit dem Link zur Terminauswahl und buchen sich selbst in einen anderen Termin.", 16, farbe=GRAU)
    schrittfolie(s, ordner, [
        "1. **Statistik** öffnen, Filiale wählen, nach unten zur Tabelle **„Schulungstermine“** scrollen.",
        "2. Am vollen Termin steht in der Spalte Ausschreibung das Badge **„Ausgebucht“** und daneben die Pille **„N ohne Termin“**. Auf die Pille klicken.",
        "3. Oben im Fenster steht der Anlass: welcher Termin voll ist und wie viele weitere Termine mit freien Plätzen es an dieser Stelle gibt. **Ist die Karte rot, gibt es keine Alternative.** Dann erst einen neuen Termin anlegen.",
    ], "Die Pille erscheint nur bei Platzzahl, voll belegt, künftigem Termin und hinterlegter Ausschreibung. Fehlt die Ausschreibung, steht nur das Badge mit „keine Ausschreibung“.",
        "F_statistik_pille.png", merksatz_farbe=GRAU, oben=2.75)

    s = folie("Wenn eine Schulung voll ist · Schritt 2", "Empfänger prüfen und senden")
    schrittfolie(s, ordner, [
        "4. Die Liste zeigt die Bewerber. Jede angehakte Zeile trägt den Chip **„bekommt: Termine ansehen“**. Wer nicht angeschrieben werden soll: Haken raus. Graue Zeilen sind gesperrt, der Grund steht daneben.",
        "5. Unten steht die Nachricht so, wie der Bewerber sie liest, mit „Anna“ als Beispielname. Beim Versand steht dort der echte Vorname. Über **„Vorlage ändern“** wählt ihr eine andere Vorlage.",
        "6. Auf **„WhatsApp an N Personen senden“** klicken und die Nachfrage bestätigen. Der Fortschritt läuft im Fenster mit.",
    ], "Die Bewerber buchen sich danach selbst in einen freien Termin.", "G_kampagne_fenster.png")

    # --- Gut zu wissen ---------------------------------------------------------
    s = folie("Gut zu wissen · Teilnehmer verschieben", "Regeln auf einen Blick")
    tabelle(s, [
        ("Verschieben: welche Termine", "Nur aktive Termine derselben Stelle (gleicher Ort), die noch nicht zu Ende sind. Ein laufender Termin geht also noch. Wer in eine andere Filiale soll, bucht über die Terminauswahl neu."),
        ("Verschieben: wer", "Gebucht, registriert und bestätigt immer. Teilgenommen nur in einen Termin am selben Tag. Stornierte, nicht erschienene und vor Ort aussortierte bleiben, wo sie sind."),
        ("Verschieben: Plätze", "Hat der Zieltermin eine Platzzahl, werden nur so viele verschoben, wie Plätze frei sind. Wer nicht mehr passt, bleibt in Schulung A."),
        ("Verschieben: Nachricht", "Keine. Wenn die Person Bescheid wissen soll, bitte selbst anschreiben."),
    ])

    s = folie("Gut zu wissen · Kampagne „Schulung voll“", "Regeln auf einen Blick")
    tabelle(s, [
        ("Kampagne: wer bekommt sie", "Nur Bewerber, die Phase 1 abgeschlossen haben und in der Terminauswahl stehen. Wer die Bewerbung noch nicht vervollständigt hat, bleibt sichtbar, ist aber gesperrt."),
        ("Kampagne: danach", "Keine automatischen Erinnerungen. Bucht die Person einen Termin, läuft alles wie gewohnt weiter: Daten vervollständigen, Bestätigung, Vertrag."),
        ("Kampagne: doppelt", "Wer in den letzten 14 Tagen schon eine Kampagne bekam, ist vorsichtshalber abgehakt (Badge „angeschrieben am …“). Haken setzen geht trotzdem."),
        ("Kampagne: Ausschreibungswechsel", "Bucht jemand am selben Ort eine Schulung einer anderen Ausschreibung, bleibt er in seiner Ausschreibung. In der Statistik seht ihr ihn beim neuen Termin unter „Herkunft“."),
        ("Vorlage pflegen", "Bewerberliste → Einstellungen → „WhatsApp Template — Schulung voll, freie Termine“. Ohne Auswahl greift die normale Terminauswahl-Vorlage."),
    ])

    prs.save(ziel)
    fehlend = [d for d in SCREENSHOTS if not (ordner / d).exists()]
    print(f"{ziel} gespeichert, {nr} Folien.")
    if fehlend:
        print("Ohne Screenshot (Textfolie):", ", ".join(fehlend))


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    bauen(Path(sys.argv[1]).expanduser(), Path(sys.argv[2]).expanduser())
