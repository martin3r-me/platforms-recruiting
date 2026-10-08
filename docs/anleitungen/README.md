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

`folien/schulungstermine_folien.py` baut die Anleitung als PowerPoint im Stil der RheinGedeck-HCM-Folien
(blauer Rand, Schritte links, Screenshot rechts). Screenshots liegen bewusst nicht im Repo (echte
Bewerberdaten). Fehlt ein Bild, steht ein beschrifteter Platzhalter da; die erwarteten Dateinamen stehen
oben im Skript.

```
python docs/anleitungen/folien/schulungstermine_folien.py <screenshot-ordner> <ziel.pptx>
```

Braucht `python-pptx` und `Pillow`.
