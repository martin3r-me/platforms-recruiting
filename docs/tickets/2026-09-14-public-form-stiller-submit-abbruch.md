# Public-Formular: Absenden scheitert stumm bei Mehr-Sitzungs-Bewerbern

**Aufgenommen:** 14.09.2026
**Status:** offen — Fix liegt in `platforms-core`, Freigabe steht aus
**Belegfälle:** Jana Carina Derichs (Bewerberin #2518, 06.–09.09.2026), Theo Wirtz (#2548, 08/2026)

## Symptom

Die Bewerberin drückt „Absenden", es passiert nichts. Keine Fehlermeldung, kein
Scroll zu einem Feld, kein Hinweis. Auf jedem Gerät gleich — Jana hat es über
mehrere Tage mit verschiedenen Geräten versucht und ein Video davon geschickt
(`context_file_id` 4053 an ihrer Akte).

Folge im Fall Jana: Die Onboarding-Daten mussten am 11.09. per WhatsApp abgetippt
werden. Bis dahin standen Adresse, Ausweisnummer und Geburtsort leer — und der
Arbeitsvertrag war am 26.08. bereits mit genau diesen leeren Feldern rausgegangen.

## Wen es trifft — und wen nicht

Das Formular zeigt nur **noch nicht ausgefüllte** Felder und ersetzt beim Laden
die Definitionsliste durch die gefilterte (`PublicExtraFieldForm.php:209`,
Vollbestand bleibt in `allFieldDefinitions`).

- **Eine Sitzung, alles am Stück:** `eu_burger` liegt im Formular, der Wert steht
  in der Wertetabelle, die Sichtbarkeit wird korrekt ausgewertet. Absenden
  klappt. → die große Mehrheit.
- **Mehrere Sitzungen:** `eu_burger` wurde früher beantwortet und ist als
  „filled" herausgefiltert. → Bug schlägt zu. Typisch für AutoPilot-Bewerber,
  die ihre Daten in Etappen nachliefern.

## Kette (alle Bausteine am Live-Stand verifiziert)

1. `WithExtraFields.php:92` — leere Multi-Selects laden als `[]`, nicht `null`.
2. `WithExtraFields.php:982` — `getFieldValuesByName()` baut seine Wertetabelle
   über die **gefilterten** `extraFieldDefinitions`. Das früher beantwortete
   `eu_burger` fehlt darin → `null`.
3. `ExtraFieldConditionEvaluator.php:311` — `isFalse(null) === true`. Die
   Bedingung `eu_burger is_false` gilt damit als erfüllt, das versteckte Feld
   `nicht_eu_dokumente` zählt serverseitig als **sichtbar** und läuft nicht in
   den `nullable`-Kurzschluss.
4. `WithExtraFields.php:581` — `min:1` hängt nur an `$isEffectivelyMandatory`,
   **nicht** am `($isDirty || $isForced)`-Gate, das eine Zeile darüber für
   `required` gilt. `nullable` rettet nichts, weil der Wert `[]` ist und nicht
   `null`.
5. `save()` bricht ab. Der Fehler hängt an einem Feld, das im DOM per `x-show`
   versteckt ist (das JS wertet korrekt gegen `allFieldValues` aus),
   `scroll-to-field` läuft ins Leere → für die Bewerberin „nichts passiert".

Kernpunkt: `PublicExtraFieldForm::save()` prüft die Sichtbarkeit **zweimal** —
für `$forceRequiredIds` korrekt über `allFieldDefinitions`, im Regel-Builder
falsch über die gefilterten. Eine richtig, eine falsch.

Letzte Änderung an beiden Dateien: 16.07.2026, also zwei Monate vor Janas
Versuchen. Sie hat genau diesen Code getroffen.

## Fix-Richtung (platforms-core, drei Stellen)

1. Rules-Visibility gegen **alle** Werte auswerten, nicht gegen die gefilterten
   — dieselbe Quelle wie `$forceRequiredIds` benutzen.
2. `min:1` ans gleiche Gate hängen wie `required` (`$isDirty || $isForced`).
3. Validierungsfehler an unsichtbaren Feldern als globale Meldung rendern, statt
   sie an ein Feld zu hängen, das niemand sieht. Diese Klasse — „Fehler an
   unsichtbarem Feld" — ist generisch und wird sonst wiederkommen.

Punkt 3 ist der eigentliche Schutz: Punkt 1 und 2 beheben diesen einen Fall,
Punkt 3 sorgt dafür, dass der nächste nicht wieder stumm bleibt.
