# Anleitungen für den Kunden (Bausteine fürs Handbuch)

Kurze, verständliche Anleitungen für HR bei Rheingedeck — je Thema eine Datei, in der Sprache des Kunden
(Du-Form, keine Technik). Aus diesen Bausteinen entsteht später das Kundenhandbuch. Nicht öffentlich, wird
nicht ausgeliefert.

Regeln:
- Eine Datei pro Thema, Dateiname `YYYY-MM-DD-<thema>.md` (Datum = Stand der Oberfläche, auf die sich der Text bezieht).
- Schritte nummeriert, wie der Mitarbeiter klickt: Seite → Element → Aktion.
- Am Ende „Gut zu wissen“: Regeln und Grenzen, die man sonst erst beim Ausprobieren merkt.
- Ändert sich die Oberfläche, wird die Datei nachgezogen, nicht kopiert.

| Datum | Thema | Datei |
| --- | --- | --- |
| 08.10.2026 | Teilnehmer verschieben (auch während der Schulung) · Vertragsdaten nach der Schulung · Kampagne „Schulung voll“ | [2026-10-08-schulungstermine-umplanen.md](2026-10-08-schulungstermine-umplanen.md) |

## Folien für den Kunden

Die Anleitungen gibt es auch als PowerPoint im Stil der RheinGedeck-HCM-Folien (blauer Rand, Kicker, Titel,
Schritte links, Screenshot rechts, Fußzeile „RheinGedeck · Intern“). Das ist die Mustervorlage für weitere
Kundenfolien.

| Datei | Inhalt |
| --- | --- |
| `folien/2026-10-08-schulungstermine-umplanen.pptx` | Fertige Folien zum Baustein vom 08.10.2026 |
| `folien/schulungstermine_folien.py` | Generator dieser Folien; die Stilfunktionen (`rahmen`, `text`, `bild`, `hinweis`) sind die Vorlage für neue Decks |
| `folien/screenshots/` | Screenshots zum Generator, Dateinamen siehe `SCREENSHOTS` im Skript |

Neu bauen:

```
python docs/anleitungen/folien/schulungstermine_folien.py docs/anleitungen/folien/screenshots docs/anleitungen/folien/2026-10-08-schulungstermine-umplanen.pptx
```

Braucht `python-pptx` und `Pillow`. Fehlt ein Screenshot, steht ein beschrifteter Platzhalter da.

**Screenshots nur anonymisiert ablegen.** Die vorhandenen stammen von der Live-Seite; Namen wurden nur im
Browserfenster durch Beispielnamen ersetzt und Fotos unscharf gestellt, bevor das Bild entstand. Keine echten
Bewerbernamen, Fotos oder Telefonnummern ins Repo.
