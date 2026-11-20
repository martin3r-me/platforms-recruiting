# Jobs anbieten — Zusage verbindlich, Zusage austragen (2026-11-20)

Anlass: Janine (19.11.): zwei Zusagen, beide können doch nicht, Schicht gilt als „gedeckt“, auch bei
„Alle Zusagen sammeln“. Markus 19.11.: „unsere Mitarbeiter sind zu dumm … Pop up … Option, die Mitarbeiter
umzustellen nach einer Absage, dass die Welle weiterläuft“. Unsere Antwort 19.11. 17:39 (4 Punkte + ZAS),
Janine „Ja, finde ich gut!“, Markus 20.11. 12:14 „so bitte umsetzen“.

## Freigegeben (Wortlaut unserer Mail)

1. Klick auf „Zusage“ öffnet ein Fenster mit Tag, Uhrzeit, Veranstaltung, Tätigkeit und „Mit der Zusage wirst
   du verbindlich für diesen Einsatz gebucht.“ Erst mit „OK“ gespeichert.
2. An jeder Zusage „Zusage austragen“. Platz wieder frei, nächste Welle bietet ihn wieder an. Ist der
   Wellenplan zu Ende: Anfrage zeigt „1 Platz wieder frei“ mit Knopf für eine neue Welle. Ausgetragene
   bekommen diese Schicht nicht noch einmal angeboten.
3. Absage auf der Veranstaltungsseite zu einer Person, die über eine Zusage aus der Welle kam → Zusage
   automatisch ausgetragen.
4. „Alle Zusagen sammeln“: Wellen bieten weiter an, bis die Dispo die Schicht selbst als gedeckt markiert.
   „Wer zuerst kommt“ unverändert.
ZAS: Austragungen abholen klärt Sebastian mit Olaf (Entwurf 20.11.); bis dahin in ZAS von Hand austragen.

## Festlegungen (mit Sebastian 20.11.)

- Austragen = Absage mit Grund `ausgetragen` (declined_at/declined_reason) — zählt im Absagen-Zähler
  (60 Tage) als eigene Zeile „ausgetragen durch Dispo“, nicht als „Kann nicht“. accepted_at wird geleert
  (alte Zeit in `ausgetragen_zusage_at`), wer: `ausgetragen_by_user_id`. Folge: alle Zähler, Export,
  Abgleich sehen die Zusage nicht mehr, ohne dass sie angefasst werden; JobOffersZusage weist
  abgesagte Zeilen schon heute ab → nicht noch einmal zusagbar.
- Schwestern (dieselbe Schicht in anderer laufender Anfrage mitzugesagt, §9) werden mit ausgetragen —
  sonst ginge die Buchung über die Schwester doch an ZAS.
- „Austragen rückgängig“ für die Dispo, solange der Platz frei ist (nicht voll im Modus zuerst, nicht von
  Hand gedeckt, Person nicht inzwischen an dem Tag anders zugesagt). Schwestern mit gleichem
  Austrage-Zeitpunkt kommen mit zurück.
- Ist die Zusage schon an ZAS übergeben/eingetragen/angekommen: Austragen geht trotzdem, Hinweis
  „Steht schon in ZAS — bitte dort auch austragen.“
- Zurücknehmen der Absage auf der Veranstaltungsseite holt die Zusage NICHT zurück.
- Kein Bescheid an den Mitarbeiter (bräuchte Meta-Vorlage).
- „sammeln“ + genug Zusagen: Hinweis an der Schicht „genug Zusagen – als gedeckt markieren?“ neben dem
  vorhandenen Knopf.
- „Platz wieder frei“: wenn die Anfrage offen ist, keine Welle wartet/läuft, es eine Austragung nach dem
  Ende der letzten Welle gibt und freie Plätze bestehen → Hinweis mit Knopf „Neue Welle“ (vorhandenes
  Formular).
- Fenster beim Zusagen: Angebotsseite und eingebettete Liste im Portal (dieselbe Komponente), auch für
  „Doch, ich bin dabei“ in der Absage-Box. du/Sie über `$duzen`.

## Bau

- Migration: rec_dispo_job_offer_shifts + `ausgetragen_zusage_at` (timestamp), `ausgetragen_by_user_id`.
- `DispoJobZusageAustragen::ausfuehren/zuruecknehmen/wegenAbsage` (Transaktion, Sperren wie
  DispoJobZusageVerschieben, nachzaehlen an jeder betroffenen Schicht).
- `DispoJobGedeckt::istGedeckt(..., modus)`: automatisch nur im Modus zuerst; `offeneSchichten` danach.
- `DispoDecline::apply` ruft `wegenAbsage` für die abgesagten Einbuchungen (Fehler nur geloggt).
- Show (Anfrage): Knopf je Zusage, Rückgängig in der Absagen-Liste, Hinweise; Absagen-Liste zeigt
  „ausgetragen durch Dispo (Name)“. JobOffers: Fenster (`zusageOffen`), Satz „Du bist hier ausgetragen“.

Deploy: migrate (1) + view:clear; kein queue:restart nötig (Wellen-Job liest nur offeneSchichten —
trotzdem queue:restart empfohlen, weil SendDispoJobWave den geänderten Gedeckt-Begriff nutzt).
