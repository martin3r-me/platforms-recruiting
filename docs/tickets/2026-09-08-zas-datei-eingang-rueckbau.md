# ZAS-Datei-Eingang: Rückbau einplanen

**Angelegt:** 2026-09-08
**Status:** offen, Bedingung noch nicht erfüllt

## Worum es geht

Für die Selfies der ~1100 über ZAS übernommenen Mitarbeiter gibt es seit heute einen
schreibenden Endpunkt:

```
POST /recruiting/zas/employee-files/{personalnummer}/{slot}
```

Beteiligt: `ZasEmployeeFileUploadController`, `ZasEmployeeFileIntake`,
`ZasEmployeeFileStore`, `ZasImageContent`, `ZasInboundFileSlots`. Doku in
`docs/zas-inbound.md`.

Der Endpunkt ist für eine **Einmal-Aktion** gedacht: Hr. Michel (ZAS) schickt einmal die
vorhandenen Selfies, danach entstehen neue Mitarbeiter ausschließlich über unseren eigenen
Funnel und bringen ihr Bild über das Portal mit. Ein schreibender, von außen erreichbarer
Endpunkt, den niemand mehr braucht, ist eine offene Tür ohne Gegenwert.

## Rückbau

**Bedingung:** der Bilder-Lauf ist durch **und** es ist entschieden, dass wir keine weiteren
Dokumente von ZAS übernehmen (offene Frage an HR: die alten ZAS-Arbeitsverträge sind für die
Bestandsleute der einzige existierende Vertragsnachweis, Ausweise und Versichertenkarten
fehlen ebenfalls). Solange das offen ist, **nicht** abbauen — es wäre derselbe Endpunkt mit
einem zweiten Eintrag in `ZasInboundFileSlots::ALLOWED`.

**Vorher prüfen**, ob der Lauf überhaupt etwas gebracht hat:

```sql
SELECT COUNT(*) AS zas_ma,
       SUM(selfie_file_id IS NOT NULL) AS mit_selfie
FROM rec_employees
WHERE rec_zas_inbound_file_id IS NOT NULL;
```

**Dann:** die Route in `routes/zas.php` entfernen — das ist der Rückbau. Controller, Intake
und Store können mit weg; `ZasEmployeeMatcher` **bleibt**, den benutzt der CSV-Importer.

## Bewusst nicht gebaut

**Kein Throttle.** Die Idempotenz nimmt dem Amoklauf den Schaden: ab dem zweiten Aufruf pro
Bild antwortet der Endpunkt `already_present`, ohne Schreibvorgang — eine Endlosschleife
kostet Requests, richtet aber nichts an. Ein zu knappes Limit hätte dagegen einen echten
Nachteil: der Lauf über 1100 Bilder bricht mitten drin mit `429` ab und sieht für ZAS wie ein
Fehler aus. Bei einem durchgesickerten Token hilft ohnehin nur ein neues Token.

**Diese Entscheidung kippt**, wenn der Endpunkt dauerhaft bleibt und weitere Slots dazukommen
— dann nimmt er mehr Dateitypen an und steht unbegrenzt offen. In dem Fall ein großzügiges
`throttle:600,1` (reicht für 1100 Bilder in zwei Minuten) statt eines knappen Limits.

**Kein Config-Flag** zum Abschalten: eine Env-Änderung mit `config:clear` ist auf Forge
derselbe Aufwand wie das Entfernen der Route, und ein Flag wäre ein weiterer Zustand, den
niemand pflegt.

**Keine Massen-Variante, kein Merker für geänderte Bilder, keine Retry-Logik, keine weiteren
Slots.** Alles verschoben, bis es jemand braucht.
