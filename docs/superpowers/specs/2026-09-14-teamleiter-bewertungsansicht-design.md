# Teamleiter-Bewertungsansicht — Design

**Stand:** 14.09.2026
**Anlass:** Kundenwunsch — bei geteilten Schulungen gibt es zwei Leiter, und
der zweite soll seine Gruppe bewerten koennen.

## Problem

Wird eine grosse Schulung in zwei Gruppen geteilt, betreut ein Teamleiter die
zweite Gruppe. Bewerten kann er heute nichts: Teamleiter-Konten sind ueber
`dispo_event_only_emails` auf genau einen Menuepunkt gesperrt (Disposition →
Veranstaltungen, lesend).

**Verworfener Kundenvorschlag: die Schulung zur Veranstaltung machen.**
Dispo-Veranstaltungen arbeiten mit Mitarbeitern, die Bewertung arbeitet mit
Bewerbern — sie haengt an der Buchung, schreibt fuenf Kriterien plus Notiz und
speist das Schulungszertifikat. Die Schulung zur VA zu machen hiesse,
Nachbereitung, Bewertung und Zertifikat an ein zweites Datenmodell zu haengen,
an dem auch der Lohn- und Versandpfad mit dranhaengt. Zwei Wege zur selben
Sache.

**Ebenfalls verworfen: Filter auf "meine Schulungen".** Das Konto
(z. B. `event@rheingedeck.de`) ist ein SAMMELKONTO fuer mehrere Teamleiter. Es
ist bei keiner Schulung als Schulungsleiter eingetragen — die echten Leiter
sind es. Ein solcher Filter haette dem Konto eine leere Liste gezeigt.

## Entscheidung

Eine eigene, schlanke Ansicht. Das Konto sieht alle Schulungen eines
Zeitfensters und kann pro Schulung genau drei Dinge:

1. Anwesenheit setzen
2. Bewerten (das bestehende Modal)
3. "Klaerung an HR" ausloesen

Alles andere existiert in dieser Ansicht nicht.

## Warum eine eigene Komponente, keine `@if` in der bestehenden Liste

`InterviewBookings\Index` hat ueber 1100 Zeilen, die Blade haengt voll an Lohn,
Versand, Vertragsvorlage und HR-Faellen. Jedes `@if` dort waere eine Stelle, an
der in einem halben Jahr ein neuer Knopf versehentlich fuer den Teamleiter
sichtbar wird.

Die Fehlerrichtung dreht sich damit um: Was in der schlanken Ansicht nicht
steht, kann nicht durchrutschen — statt "alles ist da, hoffentlich haben wir
ueberall daran gedacht, es zu verstecken".

Das Bewertungs-Modal wandert dafuer aus der grossen Komponente in etwas
Geteiltes, damit es nicht zweimal existiert und auseinanderlaeuft.

## Zugang

Zweite E-Mail-Liste `dispo_training_leader_emails`, gepflegt im selben
Einstellungsbereich wie die bestehende. Bewusst getrennt von
`dispo_event_only_emails`, damit nicht jedes Veranstaltungs-Konto automatisch
Bewerberdaten sieht. Ein Konto darf auf beiden Listen stehen.

Zuordnung per E-Mail (kleingeschrieben), gleiche Fehlerrichtung wie `DispoAccess`:
Wer nicht auf der Liste steht, ist normaler Nutzer — die Stufe ist ein Opt-in.

## Absicherung des Vertragsversands

Es gibt sechs Stellen, die Vertraege versenden: `sendContractsBulk` und
`sendPortalLinkBulk` (Buchungsliste), `sendContractsFromDesk` (HR-Schreibtisch),
Bewerberakte, Direkteinstellung, MCP-Tool.

**Ebene 1 — die Methode existiert nicht.** Die neue Komponente hat keinen
Versand-Code. Livewire kann nur aufrufen, was auf der Komponente draufsteht.

**Ebene 2 — Route-Gate.** Die Middleware leitet gelistete Konten von allem weg,
was nicht ausdruecklich erlaubt ist. Ohne gerenderte Seite gibt es keinen
gueltigen Livewire-Snapshot fuer die grosse Komponente, also auch keinen
Methodenaufruf per Direktanfrage (siehe Kommentar in `DispoEventOnlyGate.php`).

> **Die eine Stelle, an der man es teuer falsch machen kann:** Die neue Seite
> braucht eine EIGENE Route. Traegt jemand die bestehende
> `recruiting.interview-bookings.index` in die Erlaubnisliste ein, steht die
> komplette grosse Liste mit allen Versandknoepfen offen.

**Ebene 3 — Pruefung in der Komponente**, zusaetzlich zur Route; dieselbe
Doppelung wie bei der VA-Seite.

**Ebene 4 — Tests.** Von einem Teamleiter-Konto aus: Versandmethode direkt
aufrufen, grosse Liste aufrufen, Bewerberakte, Direkteinstellung. Plus ein Test,
der prueft, dass die schlanke Komponente ueberhaupt keine Versandmethode hat —
der faellt, sobald jemand spaeter eine dranbaut.

**Geprueft, nicht angenommen:** `updateStatus` loest bei "Teilgenommen" KEINEN
Versand aus. Es weist die Standard-Vertragsvorlage zu und routet ungepruefte
Nicht-EU-Faelle an HR. Der Versand ist ueberall ein eigener, bewusster Klick.
Der Teamleiter kann also auch nicht indirekt ueber die Anwesenheit etwas
rauslassen.

**Nicht abgedeckt:** das MCP-Tool. Anderer Kanal, haengt nicht an diesem Gate —
praktisch irrelevant, solange das Konto keinen MCP-Zugang hat.

## Klaerung an HR

Ist drin (Kundenentscheidung 14.09.). Die Mechanik existiert und ist idempotent:
druecken zwei Teamleiter auf dieselbe Person, entsteht trotzdem nur ein Fall
(`HrDeskRoutingService::routeIfNotAlreadyOpen`).

Zwei Vorkehrungen, weil das Konto ein Sammelkonto ist:

- **Namensfeld im Modal.** Der eingetippte Name wird der Notiz vorangestellt:
  "Kevin (Teamleiter): will 15,50 €". Sonst steht bei HR nur die Sammel-Adresse
  und niemand weiss, wen man zurueckrufen muss. Dasselbe Feld gehoert an die
  Bewertungsnotiz, dann ist die ganze Ansicht zurechenbar.
- **Klarer Modaltext:** "Nur wenn HR handeln muss — der Vertrag wird bis zur
  Klaerung angehalten." Sonst wird der Knopf zum Notizzettel und der
  HR-Schreibtisch laeuft voll.

Restrisiko: ein versehentlicher Klick haelt einen Vertrag an. Kein Schaden,
keine Doppelfaelle, sichtbar am Schreibtisch, in Sekunden zu schliessen.

## Gesperrt

Lohn, Zuschlag, Lohn-/Laufzeit-Empfehlung, Vertragslaufzeit, Vertragsversand,
Portallink, Vertragsvorlage. Kein Sprung in Bewerber- oder Mitarbeiterakte —
Namen stehen als Text, nicht als Link. Schulungen anlegen, aendern, loeschen:
nein. Buchungen anlegen oder stornieren: nein.

## Zeitfenster

Letzte 4 Wochen plus alles Kommende. Eine Zeile, jederzeit aenderbar.

## Offene Frage fuer spaeter

Zurechenbarkeit: Bei einem Sammelkonto steht unter jeder Bewertung dieselbe
Adresse. Fuer das Zertifikat ist das egal — es zieht den Schulungsleiter
weiterhin aus der Buchung. Will der Kunde spaeter echte Nachvollziehbarkeit,
braucht jeder Teamleiter ein eigenes Konto. Das Namensfeld oben ist die
billige Naeherung, kein Ersatz.
