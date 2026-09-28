# Mitarbeiterkonto — Handynummer statt Token (Canvas 68)

**Stand:** 28.09.2026
**Quelle der Wahrheit:** Canvas 68 „Ein Mensch, ein Konto, zwei Anstellungen" (Projekt 321, office.bhgdigital.de). Diese Spec argumentiert AUS dem Canvas; wo beide auseinandergehen, gewinnt das Canvas.
**Gate:** Canvas 68 nennt diese Spec „Gate B". Gate A (Bestaetigung durch RHEINGEDECK, Eintrag 1773) ist zum Zeitpunkt des Schreibens **offen**.
**Vorgaenger:** Canvas 67 (Portal-Neuaufbau). Das Konto ist Voraussetzung fuer dessen Gate 5.

---

## 0. Warum ueberhaupt

Heute meldet sich ein Mitarbeiter mit **Geburtsdatum + letzten vier Stellen der Ausweisnummer** an, erreichbar nur ueber einen persoenlichen Link aus WhatsApp. Aus Canvas 68, Eintrag 1736:

> „Das funktioniert, ist aber kein Konto: Der Link kann verloren gehen, der Ausweis muss zur Hand sein, und wer bei RHEINGEDECK und MA arbeitet, existiert zweimal."

Der zweite Halbsatz ist der teure: Adresse, Bankverbindung, Ausweis und Nachweise werden zweimal gepflegt und laufen auseinander.

### Die Sicherheitsverschiebung, die dabei passiert — ausdruecklich benannt

Heute ist der **Token das Geheimnis**. Geburtsdatum und Ausweis-Endziffern sind nur die zweite Huerde dahinter; ohne den unerratbaren Link kommt niemand an das Formular.

Kuenftig ist der Benutzername die **Handynummer** — und die ist kein Geheimnis. Ab dann traegt allein das Passwort. Daraus folgt eine Regel, die in dieser Spec mehrfach wiederkehrt und nirgends aufgeweicht werden darf:

> **Das alte Verfahren (Geburtsdatum + Ausweis-Endziffern) darf NIE als alternativer Anmeldeweg neben dem Konto stehen.**
> Wer die Nummer kennt und das Geburtsdatum weiss, ginge sonst durch die Nebentuer — ohne den Token, den er heute braeuchte. Das waere unterm Strich **unsicherer als der Ist-Zustand**.

Ausweisziffern kommen nur noch in **einem** Weg vor: dem haertesten Zuruecksetzen-Fall (§5), und dort zusammen mit einem zweiten Nachweis und einem HR-Stopp.

---

## 1. Umfang

### Enthalten

1. Konto (Handynummer + Passwort) als eigene Anmeldeschicht
2. Registrierung ueber Einladungs-Token + Geburtsdatum
3. Fuenf Zuruecksetzen-Wege inkl. Nummernwechsel
4. Trennung Person / Anstellung, sichtbar im Portal
5. Zusammenfuehren zweier Anstellungen unter ein Konto (automatisch nur bei harter Regel)
6. Einsaetze im Portal sichtbar und bestaetigbar
7. Einladungsstrecke fuer Bestand (Wellen) und Neuzugaenge
8. Vorflug-Zahlen und HR-Listen fuer die Zweifelsfaelle

### Nicht enthalten (Canvas 68, Eintrag 1744)

> „Kein Konto fuer Bewerber. Die Grenze ist die Vertragsunterschrift."
> „Keine E-Mail-Anmeldung, kein SMS-Ersatz."
> „Keine Zusammenlegung der beiden ZAS-Mandanten. Es bleiben zwei Personalnummern, zwei Arbeitgeber, zwei Lohnlaeufe."
> „Keine Steuer- oder Sozialversicherungslogik fuer Mehrfachbeschaeftigung."
> „Keine Rechte- oder Rollenverwaltung. Ein Konto sieht genau sich selbst."

Ausbaustufe, bewusst nicht im ersten Wurf: **Passkeys** (Face ID / Fingerabdruck).

Ebenfalls ausserhalb: die **Pruefungsansicht** (Sozialversicherung, Steuer, Zoll, Gesundheitsamt) und die Uebernahme der Altakten. Canvas 68, Eintrag 1798, haelt fest, dass beides auf dieser Struktur aufsetzt und erst durch sie moeglich wird:

> „Ein Pruefer fragt nicht, ob eine Bescheinigung vorliegt, sondern ob am Einsatztag eine gueltige vorlag — genau das kann heute niemand belegen."

Eigenes Canvas, sobald Canvas 68 bestaetigt ist.

---

## 2. Das Konto

### 2.1 Anmelden (Alltag)

- Benutzername: **Handynummer**. Passwort: selbstgewaehlt.
- Auf jedem Geraet, in jedem Browser, **kostenlos** — es wird keine Nachricht verschickt.
- Auf dem eigenen Handy zusaetzlich „angemeldet bleiben" und das Angebot „zum Startbildschirm hinzufuegen".
- **Eine Nummer haengt an genau einem Konto.**
- E-Mail wird beim Anlegen abgefragt, ist **optional** und dient nur als Kontaktfeld — nie zur Anmeldung.

### 2.2 Links melden nie an

Aus Canvas 68, Eintrag 1740:

> „WhatsApp-Knoepfe (Dokument, Erinnerung) fuehren zur Login-Seite und nach dem Passwort direkt zum Ziel. Ein Link meldet nie von selbst an."

Das ist eine ausdrueckliche Ablehnung des Link-Logins durch den Kunden (14.09.2026). Ein Weiterleitungsziel wird also mitgefuehrt und nach erfolgreicher Anmeldung angesprungen.

**Ausnahme, bewusst:** die Einsatz-Bestaetigungsseite (§7).

### 2.3 Passwort und Sperre

- Mindestlaenge, **kein** Zwang zu Sonderzeichen.
- Versuchszaehler und zeitliche Sperre **wie heute** — d. h. die bestehende Mechanik aus `PortalAuth` (5 Versuche, 15 Minuten) wird uebernommen, nicht neu erfunden.
- Die **Sperre bei Eskalationsstufe 3 aus der Dispo** (`portal_locked_at`) bleibt wirksam und gilt auch fuer das Konto.
- **Heikle Aenderungen verlangen immer das Passwort**, auch bei bestehender Anmeldung: Bankverbindung, Handynummer, Passwort selbst.

### 2.4 Zwei-Nachweis-Regel (tragend)

> „Ein Code allein reicht nie. Jeder Weg braucht zwei Nachweise aus Besitz (Handy mit der Nummer), Wissen (Passwort oder Geburtsdatum) oder Identitaet (Ausweisziffern)."

| Vorgang | Nachweis 1 | Nachweis 2 |
|---|---|---|
| Kontoanlage | Einladungs-Token (Besitz) | Geburtsdatum (Wissen) |
| Passwort zuruecksetzen | Code an die Nummer (Besitz) | Geburtsdatum (Wissen) |
| Nummernwechsel | Passwort (Wissen) | Code an die neue Nummer (Besitz) |

Begruendung im Canvas: *„Handynummern werden vom Anbieter neu vergeben, Handys liegen entsperrt herum, Codes werden am Telefon vorgelesen."*

---

## 3. Registrierung

Drei Felder, mehr nicht.

1. HR verschickt eine Einladung per WhatsApp mit einem **persoenlichen Einmal-Token** — als Link **und** als kurzen lesbaren Code (am Rechner eintippbar).
2. Der Mitarbeiter gibt **Geburtsdatum** ein und legt ein **Passwort** fest.
3. Fertig.

- Der Token ist der Besitznachweis (er ging an diese Nummer), das Geburtsdatum der Wissensnachweis. **Die Nummer gilt damit als bestaetigt — kein zusaetzlicher Code.**
- Gibt HR einen Token **von Hand** heraus (am Tresen), kommt einmal ein Code an die Nummer.
- Token: **einmal nutzbar, sieben Tage gueltig**.
- Danach kann der Mitarbeiter selbst eine neue Einladung anfordern: **Nummer + Geburtsdatum**.

### Wege ins Konto

| Gruppe | Weg |
|---|---|
| Bestand | Einladungswellen per WhatsApp-Vorlage an aktive Mitarbeiter ohne Konto; HR steuert das Tempo. Zusammengelegt mit dem Portal-Anschreiben aus Canvas 67 |
| Neue Mitarbeiter | Token entsteht mit der Anlage aus dem Bewerberprozess, Einladung geht mit der Willkommensnachricht raus |
| Nachzuegler | Fordern mit Nummer + Geburtsdatum selbst eine neue Einladung an |
| Ohne WhatsApp | **Bekommen kein Konto.** Erscheinen auf der HR-Liste „nicht erreichbar" und werden auf dem bisherigen Weg bedient |

HR sieht durchgaengig: wer eingeladen ist, wer registriert ist, wer nicht reagiert.

---

## 4. Person und Anstellung

> „Was den Menschen beschreibt, gibt es einmal am Konto. Was das Arbeitsverhaeltnis beschreibt, gibt es je Gesellschaft, weil es arbeitsrechtlich zwei Arbeitgeber sind."

| Zur Person (einmal am Konto) | Je Anstellung (RG / MA) |
|---|---|
| Name, Geburtsdatum, Adresse, Handynummer, E-Mail | Personalnummer aus ZAS |
| Bankverbindung, Steuer-ID, SV-Nummer, Krankenkasse | Vertrag und Zusatzvertraege |
| Staatsangehoerigkeit, Ausweis, Aufenthaltstitel, Arbeitserlaubnis | Beschaeftigungsart, Stundensatz |
| Nachweise (Ersthelfer, Gesundheitszeugnis, Schule, Imma) | Eintritt, Austritt, Status im ZAS |
| Kleidergroessen, Foto | Einsaetze, Lohnmeldungen, Belehrungen |

### 4.1 Bauweise

**Person als Klammer UEBER `rec_employees`, kein Umbau der Tabelle.** Import, Export, Vertraege und Dispo bleiben an der Anstellung. Das ist keine Empfehlung, sondern die im Canvas festgelegte Bauweise — sie haelt den Eingriff klein und laesst ZAS unberuehrt.

Wie diese Klammer aussieht, steht in §4.3 — das ist Gate C und am 28.09.2026 entschieden.

### 4.3 Gate C — die Klammer (entschieden am 28.09.2026)

#### Was heute da ist, und warum es nicht reicht

`rec_employees.person_key` existiert seit dem 09.09.2026. Zwei Dinge daran sind besser als oft angenommen: er ist eine **UuidV7**, also NICHT aus dem Namen abgeleitet — eine Heirat aendert ihn nicht. Und er hat bereits **genau einen Schreiber** (`PersonPairLinker::stamp`), der observer-frei ueber den Query Builder schreibt.

Er taugt trotzdem nicht als Anker fuer ein Konto, und zwar aus einem Grund, der alle anderen schlaegt:

> **Gesetzt wird er nur beim Paaren.** Wer nur EINE Anstellung hat, traegt `person_key = NULL`. Das ist die grosse Mehrheit.

Ein Konto kann nicht an etwas haengen, das die meisten Menschen gar nicht haben. Dazu kommt das Verhalten beim Zusammenziehen: `stamp()` uebernimmt einen vorhandenen Schluessel der Gruppe und ueberschreibt damit den der anderen Seite. Fuer einen Paarungs-Marker ist das harmlos. Fuer die Zugehoerigkeit eines Kontos waere es ein stiller Verlust.

`person_key` ist ein **Paarungs-Marker, keine Personen-Identitaet.**

#### Die Entscheidung

Eine eigene Personen-Zeile. Eine neue Tabelle, eine neue Spalte an `rec_employees`, **kein Umbau** — genau die Bauweise, die das Canvas vorgibt.

```
rec_persons        id, team_id,
                   phone                (der Benutzername),
                   password_hash        (leer bis zur Registrierung),
                   email                (optional, nie zur Anmeldung),
                   invited_at, registered_at, locked_at,
                   merged_into_person_id (leer, ausser stillgelegt),
                   timestamps

rec_employees      + rec_person_id      (eine neue, indizierte Spalte)
```

Die Zeile ist **die Person**, nicht das Konto. Die Anmeldefelder sind Spalten darauf und bleiben leer, bis sich jemand registriert. Damit bekommt **jeder** eine Zeile — auch wer nie ein Konto anlegt, auch wer nur eine Anstellung hat. Die Klammer ist von Tag eins vollstaendig, und genau das ist der Unterschied zum Stempel.

Eine getrennte Konto-Tabelle wurde erwogen und verworfen: eine Person hat hoechstens ein Konto, das waere ein 1:1-Join ohne Gewinn.

#### Was aus dem `person_key` wird

Er bleibt unveraendert und behaelt seine heutige Aufgabe. Er wird vom **Anker zum Futter**: beim Backfill und beim ZAS-Import sagt er, welche Anstellungen auf dieselbe Personen-Zeile zeigen sollen. Entschieden wird danach ueber `rec_person_id`.

#### Regeln (alle tragend)

1. **Ein Schreiber.** Genau eine Stelle darf `rec_person_id` setzen oder aendern — Muster `PersonPairLinker::stamp` und `ProofWriter`. Zwei Schreibwege auf dieselbe Zugehoerigkeit waeren die naechste Doppeltuer.
2. **Observer-frei.** Geschrieben wird ueber den Query Builder. Eine Personen-Zuordnung ist keine fachliche Aenderung am Mitarbeiter und darf ihn nicht in den ZAS-Update-Export spuelen (`zas_changed_at` bleibt unberuehrt).
3. **Zusammenziehen heisst umhaengen, nicht ueberschreiben.** Zwei Anstellungen zusammenlegen = `rec_person_id` auf dieselbe Zeile zeigen lassen. Nichts wird ueberschrieben, nichts geloescht, HR kann es zurueckdrehen — dieselben Eigenschaften, die die Paarung heute schon hat.
4. **Zwei registrierte Personen, die derselbe Mensch sind** (moeglich, wenn beide Anstellungen verschiedene Nummern trugen): eine Zeile gewinnt, die andere bekommt `merged_into_person_id` und verliert ihre Anmeldung. Die Zeile bleibt stehen und ihre Nummer bleibt gesperrt — Canvas-Eintrag 1793: eine neu vergebene Nummer darf nie an alte Daten fuehren.
5. **Die Nummer der Person ist die Wahrheit fuer die Anmeldung.** Sie steht doppelt (an der Person als Benutzername, an der Anstellung fuer ZAS-Export und CRM) — das ist die einzige bewusst hingenommene Doppelung. Aendert sie sich an der Person, wird sie im selben Vorgang auf alle Anstellungen dieser Person geschrieben. Ohne diese Regel entsteht genau die Drift, die §9.2 zaehlt.

#### Backfill

Alle Mitarbeiter bekommen eine Zeile, **auch inaktive** — sonst bekommt ein Rueckkehrer eine zweite Person. Datensaetze mit gleichem nicht-leerem `person_key` teilen sich eine. Weichen die Nummern zweier Anstellungen ab, gilt die Canvas-Regel *„der zuletzt geaenderte Wert gewinnt"*, und der Fall geht zusaetzlich auf die HR-Liste.

#### Was der Umbau nebenbei beseitigt

`PersonScopeResolver` / `PersonProofScope` loesen heute zur LAUFZEIT auf, welche Anstellungen zu einem Menschen gehoeren — ueber `person_key` plus uebereinstimmende Handynummer. Das war der Ersatz fuer eine fehlende Personen-Zeile, und er ist nachweislich fragil: im Demo-Bestand (keine Telefonnummern, weil erfundene Nummern echten Menschen gehoeren koennten) paart er gar nicht, und zwei Anstellungen desselben Menschen sehen einander nicht.

Sobald `rec_person_id` gesetzt ist, gewinnt die Spalte. Die Laufzeit-Aufloesung bleibt waehrend der Umstellung nur als Rueckfall fuer noch nicht gefuellte Zeilen und wird nach dem Backfill **entfernt**. Identitaet gehoert in eine Spalte, nicht in eine Suchabfrage.

#### Was NICHT mitwandert (bewusst)

Stammdaten und Nachweise bleiben vorerst an der Anstellung. Das Canvas sieht vor, dass sie spaeter einmal an der Person liegen; mit dieser Zeile ist das eine Umzugs-Migration statt eines Umbaus. Der Umzug ist **nicht** Teil dieses Gates.

### 4.2 Zusammenfuehren

> „Automatisch zusammengezogen wird nur bei harter Uebereinstimmung: gleicher Personen-Marker, oder gleiche Handynummer plus gleiches Geburtsdatum."
> „Alles andere ist ein Zweifelsfall und landet auf einer HR-Liste. Beispiel: gleiche Nummer, anderes Geburtsdatum. Das System paart nicht, HR entscheidet."

Weiter:

- Der Mitarbeiter **muss nichts bestaetigen**. Er sieht beim ersten Login seine Anstellungen und Stammdaten zur Kontrolle.
- Bei unterschiedlichen Stammdaten gewinnt **der zuletzt geaenderte Wert**. Danach gibt es nur noch einen Satz, der in beide ZAS-Mandanten exportiert wird.
- **Kein Datensatz wird geloescht.** Zusammenziehen ist nachvollziehbar und von HR wieder trennbar.

---

## 5. Zuruecksetzen — fuenf Wege

Vom leichtesten zum haertesten Fall (Canvas 68, Eintrag 1789):

| # | Lage | Weg | HR noetig |
|---|---|---|---|
| 1 | Neue Nummer, alte noch aktiv | Angemeldet, Passwort bestaetigen, neue Nummer eintragen, Code an die neue | nein |
| 2 | Neue Nummer, alte weg, Passwort bekannt | Anmelden mit **alter** Nummer + Passwort, neue Nummer eintragen, Code an die neue | nein |
| 3 | Passwort vergessen, Nummer aktiv | „Passwort vergessen": Code an die Nummer **plus Geburtsdatum**, neues Passwort | nein |
| 4 | Nummer weg **und** Passwort vergessen | Geburtsdatum + **Ausweisziffern**, neue Nummer eintragen, Code an die neue. HR bekommt eine Meldung und kann **innerhalb von 24 Stunden stoppen** | Stopp-Recht |
| 5 | Gar nichts geht | HR traegt die neue Nummer im HCM ein, Einladung geht neu raus | ja |

Weg 2 ist ausdruecklich der Gewinn gegenueber einem reinen Code-Login: *„Wer die SIM verliert, kommt trotzdem selbst rein."*

Weg 4 ist der einzige Ort, an dem Ausweisziffern ueberhaupt noch vorkommen — und das Canvas haelt fest: *„Dieser Weg ist nicht schwaecher als der heutige Login."*

### Begleitregeln (alle nicht verhandelbar)

- Die **alte Nummer bekommt einmalig einen Hinweis**, dass die Nummer geaendert wurde, sofern noch zustellbar.
- **„Passwort vergessen" antwortet immer gleich**, egal ob die Nummer im System ist — sonst kann man Nummern durchprobieren.
- **Jeder Wechsel wird protokolliert.**
- **Konten ausgeschiedener Mitarbeiter werden gesperrt**, damit eine neu vergebene Nummer nicht an alte Daten fuehrt.

---

## 6. Risiken mit Gegenmittel

### 6.1 Neu vergebene Handynummer (Eintrag 1793)

> „Anbieter geben Nummern nach einigen Monaten neu aus. Der neue Besitzer hat WhatsApp auf der Nummer und koennte ueber 'Passwort vergessen' einen Code ausloesen. Ohne zweiten Nachweis saesse er auf Ausweiskopie, Bankverbindung und Vertraegen eines fremden Menschen."

Gegenmittel: Zwei-Nachweis-Regel (§2.4), Sperre ausgeschiedener Konten, neutrale Antwort bei „Passwort vergessen".

### 6.2 Geteilte Handynummern (Eintrag 1782)

> „Eine Nummer darf aber nur an einem Konto haengen."
> „Vor der ersten Welle werden Mehrfachnummern gelistet. Diese Faelle bekommen keine automatische Einladung, sondern werden von HR angesprochen."

**Stand 28.09.2026:** Der Kunde haelt geteilte Nummern fuer „selten bis gar nicht" und widerspricht dem Canvas-Beispiel der Minderjaehrigen mit Elternnummer ausdruecklich — U18 haetten eigene Nummern. Der Zaehler (§9) beantwortet das mit Zahlen; der Canvas-Eintrag wird danach entsprechend korrigiert.

### 6.3 Zwei Menschen werden zu einem Konto

Gegenmittel: die harte Paarungsregel (§4.2) plus die Trennbarkeit — kein Datensatz wird geloescht.

---

## 7. Einsaetze im Portal

**Vom Kunden bestaetigt am 28.09.2026:** sichtbar und bestaetigbar ist im Portal **genau das, was angeschrieben wurde** — dieselbe Regel wie heute auf der Token-Seite (Kundenregel vom 01.09.2026). Nicht der komplette Dispo-Bestand der Person.

Die Token-Seite **bleibt daneben bestehen**:

> „Einsatzbestaetigung bleibt kontofrei: Der Link aus der Dispo oeffnet weiterhin nur die Bestaetigungsseite, ein Tipp, fertig. Kein Konto steht zwischen Dispo und Zusage. Im Portal sind die Einsaetze zusaetzlich sichtbar, mit demselben Bestaetigen-Knopf."

Technisch vorhanden ist das meiste bereits in `Livewire\Public\EmployeeAssignments`: Gruppierung nach Veranstaltung, kommende und vergangene Einsaetze, `confirm()` je Veranstaltung, Aufloesung der Dispo-Identitaetsgruppe ueber beide Gesellschaften. Der Portal-Bereich nutzt **dieselben Abfragen**; eine zweite Sichtbarkeitsregel darf nicht entstehen.

---

## 8. Datenhoheit und der Stichtag (Eintrag 1794)

Die Herkunft haengt an der **Person**, nicht am Datensatz:

- Haengt irgendwo in der Personen-Gruppe eine Bewerbung bei uns → Person ist **„Recruiting"**, wird bei uns gepflegt, auch ihr MA-Datensatz.
- Ohne Bewerbung bei uns → **„ZAS-Bestand"**.

**Stichtag vor Gate F:** ein letzter Full-Import aus dem ZAS uebernimmt fuer alle ZAS-Bestandspersonen saemtliche Stammdaten **einschliesslich Bankverbindung**. Einzige Ausnahme: die **Handynummer bleibt, wie sie bei uns steht** — sie ist ab dann der Benutzername. Recruiting-Personen werden nicht ueberschrieben.

Danach nur noch **eine Richtung**: Pflege bei uns, Export ins ZAS in beide Mandanten. Kein Stammdaten-Import mehr. Zurueck kommen nur noch **Personalnummer und Beschaeftigungsstatus** — die Felder, die das ZAS besitzt.

> „Das muss HR und Michel vor dem Stichtag mitgeteilt werden. Wer nach dem Stichtag noch im ZAS eine Adresse aendert, aendert sie ins Leere."

---

## 9. Vorflug — Zahlen, die VOR Gate D vorliegen muessen

Canvas 68, Eintrag 1745, verlangt **drei** Zahlen, und zwar ausdruecklich vor Gate D, nicht erst vor den Wellen:

> „Die Nummer in der Personalakte wird der Benutzername, der WhatsApp-Versand nutzt heute die Nummer am Kontakt. Beide muessen uebereinstimmen, sonst erhaelt der Mitarbeiter seinen Einmalcode auf einer anderen Nummer als der, mit der er sich anmeldet."

| # | Zahl | Stand 28.09.2026 |
|---|---|---|
| 1 | Nummer fehlt | vorhanden — `recruiting:mitarbeiter-grenzfaelle`, Fall `ohne_telefon_akte` |
| 2 | Nummer mehrfach vergeben | **gebaut** (§9.1), liegt auf `feat/ma-portal` |
| 3 | Akte und Kontakt weichen ab | **fehlt noch** (§9.2) |

Alle drei Zahlen liegen derzeit auf `feat/ma-portal` und damit **nirgends, wo sie auf dem Echtbestand laufen koennten**: das Grenzfall-Kommando ist auf diesem Branch neu, `main` kennt es nicht. Auf Wunsch des Kunden (28.09.) wird dafuer vorerst **nicht** auf `main` gemergt. Der Weg, wenn es soweit ist: ein eigener kleiner Branch von `main` mit Kommando, Planer und `PhoneE164::suffix()` — geprueft, das Kommando haengt an keiner Neuerung des Portal-Branchs.

### 9.1 Mehrfach vergebene Nummern

Zwei neue Faelle im bestehenden Kommando, Gruppe `login`:

- `nummer_mehrfach_gleiche_person` — dieselbe Person, zwei Anstellungen. Normal, das ist das RG/MA-Paar.
- `nummer_mehrfach_andere_person` — **verschiedene Menschen an einer Nummer.** Der Fall, der das Konto bricht.

Gruppiert wird ueber `PhoneE164::suffix()` (letzte neun Ziffern), damit `+49 152 …`, `0152 …` und `0049152 …` dieselbe Nummer sind. Konservativ: ein fehlendes Geburtsdatum oder ein leerer Personen-Marker bestaetigt **nie** eine Gleichheit — im Zweifel Zweifelsfall.

### 9.2 Akte gegen Kontakt

Die Login-Nummer steht in `rec_employees.phone`, der WhatsApp-Versand laeuft ueber die Nummer am CRM-Kontakt. Weichen sie ab, geht der Einmalcode an eine andere Nummer als die, mit der sich der Mensch anmeldet — **der Mitarbeiter sperrt sich aus, ohne dass jemand etwas falsch gemacht hat.**

Das Kommando kennt heute `crm_ohne_nummer` (Kontakt ohne aktive Nummer), aber **keinen Abweichungsfall**. Der fehlt und gehoert vor Gate D nachgezogen.

---

## 10. Offene Punkte

| Punkt | Bei wem | Blockiert |
|---|---|---|
| Gate A — Bestaetigung des Canvas | Markus / RHEINGEDECK | den Bau, nicht diese Spec |
| Aufbewahrungsfrist fuer aeltere Nachweis-Fassungen | RHEINGEDECK | **nichts.** Entscheidung 28.09.: vorerst **dauerhaft aufbewahren**, kein Loeschlauf. Nennt Markus spaeter eine Frist, wird sie nachgezogen — die Fassungen tragen ihren Gueltigkeitszeitraum, ein spaeterer Lauf findet sie |
| Hinweistext beim ersten Login (gemeinsame Stammdaten RG+MA) | RHEINGEDECK | nichts — das Canvas haelt fest: *„Die datenschutzrechtliche Bewertung liegt bei RHEINGEDECK; sie hat keinen Einfluss auf Umsetzung oder Termin."* Der Kunde neigt am 28.09. dazu, ganz darauf zu verzichten |
| Zwei Meta-Vorlagen (Einladung, Einmalcode) | RHEINGEDECK liefert Texte | Gate E. Fuer Codes gibt es bei Meta eine eigene Vorlagenklasse mit „Code kopieren" und schnellerer Genehmigung — **frueh einreichen** |
| Minderjaehrige: Konto auf eigene oder Elternnummer | RHEINGEDECK | nur, falls §9.1 Treffer liefert |
| ~~Datenmodell der Personen-Klammer~~ | ~~Gate C~~ | **entschieden 28.09., siehe §4.3** — eigene Personen-Zeile `rec_persons` + `rec_employees.rec_person_id`; `person_key` wird vom Anker zum Futter |
| Feldliste HR-direkt-in-ZAS (Michel) | offener Posten vor dem Bau | Gate C |

---

## 11. Was die Kopplung kostet — damit es niemand spaeter entdeckt

Canvas 68, Eintrag 1774:

> „Das Portal allein, mit dem heutigen Login, waere nach rund 2 bis 2,5 Wochen bei den Testmitarbeitern. Mit dem Konto davor sind es rund 7 bis 10 Wochen bis zur Freischaltung. **Die Kopplung kostet also etwa 5 bis 7 Wochen.**"

Der Grund ist eine bewusste Kundenentscheidung: die Testmitarbeiter sollen das neue Portal **von Anfang an mit dem neuen Login** sehen, damit sich niemand zweimal umgewoehnen muss.

Gesamtaufwand laut Canvas: **114–156 Stunden**, und dieser Wert gehoert zu dem, was Markus in Gate A bestaetigen soll.

---

## 12. Bauvorschrift, die schon erfuellt ist

Die Portal-Huelle aus Canvas 67 wurde mit **austauschbarer Anmeldeschicht** gebaut. Im Kopf von `src/Services/PortalAuth.php` steht:

> „Heute beantwortet sie die Frage „wer ist das?" mit Token plus Geburtsdatum und Ausweis-Endziffern. Spaeter mit Handynummer und Passwort. Die vier Portal-Bereiche wissen nichts davon, wie die Antwort zustande kam."

Das Konto wird also **eingehaengt, nicht eingebaut**. Die Bereiche Start, Nachweise, Profil und Dokumente bleiben unberuehrt; geaendert wird der Anmeldebildschirm und die Kennzeichnung der Gesellschaft an Einsaetzen, Vertraegen und Dokumenten — genau die Abgrenzung, die Eintrag 1744 zieht.
