# meinGedeck — Betriebsdokumentation

Dokumente zur Zusammenarbeit zwischen dem Recruiting-Modul (für den Kunden „HCM")
und den angebundenen Systemen. Zielgruppe: wer am Kundensystem arbeitet oder eine
Frage von HR beantworten muss — nicht nur Entwickler.

| Dokument | Inhalt |
|---|---|
| [`zas-datenhoheit-und-sync.md`](zas-datenhoheit-und-sync.md) | **Einstieg für alles rund um ZAS.** Wer besitzt welche Daten, welche Kanäle gibt es, wie funktioniert der Änderungs-Marker, bekannte Fallen, Checkliste vor jedem Abruf, offene Punkte. |
| [`../zas-inbound.md`](../zas-inbound.md) | Der Eingangs-Endpunkt im Detail: Format, Statusfelder, Ablage der Rohdateien |

## Fehlend

`zas-applicant-export.md` wird an fünf Stellen im Code referenziert
(`ZasExportController`, `ZasFieldResolver`, `RecApplicantExportObserver`,
`ZasExportBackfill`, `RecruitingServiceProvider`), existiert aber nicht mehr. Die
wesentlichen Aussagen zum Bewerber-Export stehen inzwischen in
`zas-datenhoheit-und-sync.md`, Abschnitt 2.1. Wer die Formatkonventionen im Detail
braucht: die verbindliche Spaltenreihenfolge steht in `ZasFieldResolver::COLUMNS`,
die CSV-Konventionen im Klassenkommentar von `ZasCsvBuilder`.
