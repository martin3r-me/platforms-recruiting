# Crew-Karte zeigt die ZAS-Qualifikationen — Design

**Stand:** 2026-10-08
**Anlass:** Seit dem Lauf gegen Lieferung #258 tragen 1.462 Mitarbeiter ihre
Taetigkeiten in `rec_employee_hr_data.dispo_taetigkeiten`. Die Mitarbeiterkarte
der Veranstaltungsseite zeigt aber weiter das handgepflegte Feld
`qualifications`, das praktisch leer ist. Kunde 08.10.: „das sind ja quasi auch
qualifikationen".

## Entscheidungen

**1. Nur `profileCards()` wird umgestellt, nicht `qualifications()`.**
Beide Methoden des `DispoEmployeeGateway` lesen heute `hrData->qualifications`.
`qualifications()` speist aber den Filter „Qualifikation (aus der MA-Akte)" im
Info-Versand — und der bleibt bewusst auf dem alten Feld.

**Begruendung (Kunde 08.10.):** Im Info-Versand schreibt man Leute danach an,
**als was sie in dieser VA eingebucht sind** — dafuer gibt es die Gruppe
„Taetigkeit (aus dieser VA)", gespeist aus der Einbuchung, und die funktioniert.
Die Qualifikation ist die andere Achse: jemand ist Logistiker UND Thekenkraft.
Sie dort anzubieten wuerde bei einer grossen VA fuenfzig Eintraege neben die
Gruppe stellen, die tatsaechlich die richtige ist. Die alte Gruppe bleibt
unsichtbar, solange niemand das Feld von Hand pflegt (sie rendert nur bei
nicht leerer Liste) — kein Aufraeumbedarf.

**2. Keine Uebersetzung ueber die Auswahlliste mehr.**
`qualifications` speicherte Lookup-Werte, die ueber `core_lookup_values` in
Labels uebersetzt wurden. `dispo_taetigkeiten` speichert die Katalognamen
**direkt** (so schreibt `ZasDispoTaetigkeitSync` sie). Die Uebersetzungsschicht
in `profileCards()` entfaellt ersatzlos.

**3. Die Karte deckelt die Liste bei 10 Chips.**
Gemessen an Lieferung #258: Median 3 Taetigkeiten je Mitarbeiter, **Maximum 87**.
Die Chip-Liste ist heute unbegrenzt — beim alten, fast leeren Feld harmlos, mit
den ZAS-Daten wuerde das Bottom-Sheet am Handy zu einer Wand. Der Rest klappt
per Alpine **in der Karte selbst** auf.

**Kein zweites Fenster.** Die Karte ist bereits ein Modal (mobil Bottom-Sheet
ueber `items-end`/`rounded-t-2xl`, ab `sm` zentrierter Dialog mit `max-w-lg`,
dazu `max-h-[calc(100dvh-2rem)]` und ein innerer `overflow-y-auto`). Ein Fenster
im Fenster hiesse zwei Schliessen-Ebenen uebereinander — am Handy eine Falle.
Alpine ist dort schon im Einsatz (Foto-Zoom), es kommt nichts Neues dazu.

**4. Die Karte zeigt den Stand.**
`dispo_taetigkeiten_synced_at` wird mitgereicht und als „aus ZAS · Stand
TT.MM. HH:MM" neben der Ueberschrift angezeigt. Der Teamleiter muss sehen
koennen, wie frisch die Angabe ist.

**5. Der Leertext sagt die Wahrheit.**
Statt „keine hinterlegt" kuenftig ein Satz, der benennt, dass ZAS das pflegt und
dass es bei neuen Mitarbeitern der Normalfall ist — 266 aktive Mitarbeiter haben
noch keine Zuordnung, ausnahmslos Neuzugaenge.

**6. Ueber die Identitaetsgruppe wird VEREINIGT, nicht „erster nicht leerer".**
`crewCard()` fuellt Luecken heute aus der Gruppe (`if ($quals === [])`). Bei den
ZAS-Daten ist das zu wenig: dieselbe Person kann einen RG- und einen
MA-Datensatz haben, und `{Dispo5}` traegt fuer **beide** Qualifikationen (247
MA-Personalnummern mit 1.128 Zeilen, davon matchen mindestens 219 auf unsere
Mitarbeiter). Mit „erster nicht leerer" saehe man nur eine Haelfte. Vereinigt
wird entdoppelt, wie es `pnrs` an derselben Stelle schon tut.

## Nicht in diesem Bau

- Der Info-Versand und seine Filter (Entscheidung 1).
- Das Feld `qualifications` selbst — es bleibt, bleibt pflegbar und geht
  weiterhin als Spalte `Qualifikation` an ZAS.
