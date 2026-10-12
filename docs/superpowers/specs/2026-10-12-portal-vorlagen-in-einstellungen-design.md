# Portal-Vorlagen in die Einstellungen + Schalter Einsatz-Prüfung — Design

Stand 12.10.2026 · Branch `feat/ma-konto` · vor dem Merge auf main

## 1. Anlass

Sebastian, 12.10.: „templates machen wir generell in den bewerber einstellungen … oder von mir aus nochmal wo separat fürs neue portal aber nicht in der env". Früher schon: „soll alles in der webapp konfigurierbar sein".

Heute holen sechs Versandwege ihre Meta-Vorlage aus `.env` (`config/recruiting.php`). Das war eine Abkürzung aus dem Konto- und dem Trigger-Paket. Dazu kommt der Fristenlauf: Seine Vorlage ist zwar eine Team-Einstellung, hat aber kein Feld im Fenster und geht nur per SQL.

Außerdem soll die stündliche `recruiting:einsatz-pruefung` nach dem Deploy nicht sofort HR-Fälle anlegen. Das gilt, bis Umzug, Personen-Anlage und Trockenlauf erledigt sind (Checkliste Risiko 1). Deshalb bekommt sie einen Schalter in derselben Sektion, Vorgabe aus.

## 2. Ergebnis für HR

Bewerber-Einstellungen → Reiter **Kommunikation** → neue Sektion **„Mitarbeiterportal"**. Inhalt der Sektion:

| Feld | Einstellungs-Schlüssel | Sender | Vorlage muss tragen |
|---|---|---|---|
| Code „Passwort vergessen" | `konto_code_passwort_wa_template_id` | EinmalcodeSender (passwort) | `{{code}}`, sonst nur Vorname; kein Knopf mit Variable |
| Code „Neue Nummer bestätigen" | `konto_code_nummernwechsel_wa_template_id` | EinmalcodeSender (nummernwechsel) | wie oben |
| Code „Notfall-Weg" | `konto_code_notfall_wa_template_id` | EinmalcodeSender (notfall) | wie oben |
| Hinweis an die alte Nummer | `konto_hinweis_alte_nummer_wa_template_id` | NummernwechselHinweisSender | nur Vorname oder gar kein Platzhalter; kein Knopf mit Variable |
| Offene Aufgaben — neu | `aufgaben_neu_wa_template_id` | AufgabenSender (neu) | `{{anzahl}}`, optional Vorname; genau ein URL-Knopf mit Variable am Ende |
| Offene Aufgaben — Erinnerung | `aufgaben_erinnerung_wa_template_id` | AufgabenSender (erinnerung) | `{{anzahl}}`, `{{datum}}`, optional Vorname; URL-Knopf wie oben |
| Nachweis läuft ab (Fristenlauf) | `proof_reminder_wa_template_id` (gibt es schon) | ProofReminderSender | unverändert |
| Dokument liegt im Portal | `document_wa_template_id` (gibt es schon, zieht um) | DokumentHinweisSender | unverändert |
| Vertrag zur Unterschrift | `employee_contract_wa_template_id` (gibt es schon, zieht um) | VertragHinweisSender | unverändert, leer = wie Dokumente |
| **Schalter** Einsatz-Prüfung läuft | `einsatz_pruefung_aktiv` (bool, Vorgabe `false`) | EinsatzPruefung | — |

- Unter jedem Feld steht ein Satz Hilfetext, so wie heute bei „Dokumente": wann die Vorlage rausgeht, welche Platzhalter sie braucht, welcher Knopf.
- Die Auswahl ist dieselbe Liste wie bei den anderen Vorlagen-Feldern (`availableWhatsAppTemplates`, nur genehmigte Vorlagen).
- Die Einstellungen sind Team-Einstellungen und liegen in `rec_applicant_settings.settings`. Es gibt keine Migration: Fehlende Schlüssel stehen in `DEFAULT_SETTINGS` mit `null` bzw. `false`.
- **Code „Anmeldung"** bekommt kein Feld. Der Zweck existiert in KontoWriter, wird aber nirgends verschickt (geprüft 12.10.). Ohne Einstellung meldet der Sender wie bisher „nicht konfiguriert".

## 3. Versand: Vorlage aus der Einstellung statt aus der Config

Alle drei betroffenen Sender (EinmalcodeSender, NummernwechselHinweisSender, AufgabenSender) holen die Vorlage über `HoldingTemplateSender::resolveTarget($teamId, $schluessel)`. Das ist dieselbe Kette wie bei Dokument, Vertrag, Zertifikat und Fristenlauf: Einstellung → Vorlage (genehmigt) → Account → Kanal.

- **Name und Sprache** kommen aus der gewählten Vorlage. `*_SPRACHE` fällt weg.
- **Platzhalter** werden aus dem BODY der gewählten Vorlage gelesen. Die feste Liste in der Config entfällt. Damit entfällt auch die Checklisten-Falle „Platzhalter stehen fest im Code, Ändern = Deploy".
- **Prüfungen bleiben so streng wie heute.** Sie werden jetzt gegen die echte Vorlage gemacht statt gegen die Config:
  - Code-Vorlage ohne `{{code}}` → kein Versand.
  - Ein Platzhalter, den der Sender nicht befüllen kann → kein Versand. Der Vorname wird nie still eingesetzt.
  - Code- und Hinweis-Vorlage mit einem Knopf, der eine Variable braucht → kein Versand. Wir befüllen keinen Knopf, Meta würde ablehnen.
  - Aufgaben-Vorlage ohne URL-Knopf mit Variable → kein Versand (das ist schon heute so).
- **Kein Feld gewählt, oder Vorlage nicht genehmigt** → kein Versand. Status und Log bleiben wie heute: EinmalcodeSender `failed` mit lesbarem Grund, AufgabenSender `nicht_konfiguriert`, HinweisSender wie bisher. Der Grund nennt jetzt die Einstellung („Einstellungen → Kommunikation → Mitarbeiterportal") statt des `.env`-Schlüssels.
- **Kanal:** Die Sender nehmen den Kanal aus `resolveTarget()`, also den Kanal am Account der Vorlage. Bisher war es `RecruitingChannelResolver::channelIds()[0]`. So machen es schon alle Einstellungs-Sender, und eine Vorlage lässt sich nur über ihren eigenen Account senden.
- Drossel, Reihenfolge (Vorlage prüfen → drosseln → Code erzeugen), Schwärzung im Ausnahmetext und `status === 'failed'`-Prüfung bleiben unverändert.

## 4. Schwärzung im Chat (EinmalcodeVorlagen)

Heute erkennt der Chat eine Code-Nachricht am Vorlagennamen aus der Config. Diese Liste gibt es danach nicht mehr. Eine Nachricht gilt künftig als Code-Nachricht, wenn **eins** davon zutrifft:

1. **Struktur:** Der BODY der Vorlagen-Definition enthält `{{code}}`, egal in welcher Schreibweise. Das trifft jede Code-Vorlage, auch eine umbenannte oder alte, solange ihre Definition noch da ist.
2. **Aktuelle Einstellung:** Der Name ist in irgendeinem Team in einem der drei Code-Felder gewählt. Die Liste wird einmal je Anfrage geladen.
3. **Abgelegte Namen:** `recruiting.konto.code_vorlagen_alt` bleibt als Liste im Code (kein `.env`). Sie wird nie gekürzt.

`DispoThreadDirectory` übergibt dafür die Definition mit (`istCodeVorlage($name, $definition)`). Geschwärzt werden wie heute alle Werte.

## 5. Schalter Einsatz-Prüfung

- `einsatz_pruefung_aktiv` ist je Team gesetzt, Vorgabe `false`.
- Ist er aus, überspringt `recruiting:einsatz-pruefung` das Team vollständig: keine HR-Fälle, kein Signatur-Räumen, kein Versand. Der Bericht sagt je Team: „Team n: Einsatz-Prüfung ist aus (Einstellungen → Kommunikation → Mitarbeiterportal) — übersprungen".
- **`--dry-run` ignoriert den Schalter.** Der Trockenlauf aus der Checkliste soll die Zahlen zeigen, bevor jemand einschaltet. Er schreibt ohnehin nichts.
- `--ids=` ohne `--dry-run` respektiert den Schalter. Es gibt keine Hintertür: Wer eine scharfe Welle will, schaltet erst ein.
- Ein Umschalten wird geloggt (`recruiting.einsatz_pruefung.schalter`: Team, User, an/aus), nach dem Muster des Notfall-Weg-Schalters.
- Der Zeitplan-Eintrag bleibt unverändert. Er ist bei ausgeschaltetem Schalter ein No-op.

## 6. Was wegfällt

- In `config/recruiting.php` fallen weg: `konto.code_vorlagen`, `konto.hinweis_vorlage`, `aufgaben.vorlagen` samt allen `RECRUITING_KONTO_VORLAGE_*`- und `RECRUITING_AUFGABEN_VORLAGE_*`-Schlüsseln. Die Kommentare mit den Anforderungen an die Vorlagen wandern als Docblock in die Sender.
- Was bleibt: `konto.code_vorlagen_alt` (Schwärzungs-Gedächtnis), `konto.pepper` und `konto.weg4_aktiv`. Die beiden letzten sind technische bzw. Sicherheits-Riegel und keine Vorlagen (siehe §8).
- Es gibt keinen Rückfall auf `.env`, damit es nicht zwei Wahrheiten gibt. Auf prod waren die Schlüssel nie gesetzt (Vorlagen nicht genehmigt), es geht also nichts verloren.

## 7. Einstellungsfenster

- Die neue Sektion steht im Reiter `comms`. Die beiden Selects „Dokumente" und „Vertrag zur Unterschrift" ziehen aus dem Reiter `general` dorthin um. Das Zertifikat-Feld bleibt, wo es ist (Anker von SettingsModalCertificateToggleTest).
- Die Selects folgen dem Muster der vorhandenen Vorlagen-Felder: `x-ui-input-select`, `wire:model.live`, `:value`, Block-`@if`.
- **Bekannter Fehler „Selects speichern nicht"** (Memory `project_settings_modal_select_save_bug`): Bevor das Paket als fertig gilt, wird auf der Demo nachgeprüft, dass Auswahl, Speichern und Wiederöffnen den Wert halten. Hält er nicht, wird der Fehler im Modul behoben, also in `ApplicantSettingsModal` bzw. dem Blade. ui-tailwind wird nicht angefasst. Ein Integrationstest prüft `save()` mit gesetzten Template-IDs (Wert landet in `settings`).
- `save()` speichert die IDs als `int|null` (leer → `null`) und den Schalter als strikten bool.

## 8. Bewusst nicht in diesem Paket

- **`RECRUITING_KONTO_WEG4_AKTIV`** bleibt in `.env`. Das ist keine Vorlage, sondern der erste von zwei Riegeln vor dem Notfall-Weg (der zweite ist der Team-Schalter im Fenster). Er soll ausdrücklich nicht von HR allein umlegbar sein. Wenn Sebastian ihn trotzdem im Fenster will, geht das als Ein-Zeilen-Folgeänderung.
- Es kommt keine Prüfung der Vorlage schon beim Speichern. Ungeeignete Vorlagen fallen wie bei den vorhandenen Feldern erst beim Versand auf, mit Grund im Log. Der Hilfetext unter jedem Feld nennt die Anforderung.

## 9. Checkliste (docs/meingedeck/deploy-checkliste-ma-konto.md)

- Die Spalte „Textvorschlag Sie / du" wird zu „Textvorschlag (du)". Die Sie-Texte fallen weg.
- „Eintragen in" zeigt bei allen zehn Vorlagen das Feld in der Sektion „Mitarbeiterportal".
- Abschnitt 2.2 (`.env`-Schlüssel) und das Rollback „`.env` leeren + config:cache" werden ersetzt durch „Feld in den Einstellungen leeren".
- Risiko 1 bzw. Entscheidung 2.4 wird ersetzt durch „Schalter Einsatz-Prüfung bleibt aus bis nach `nachweise-umziehen`, `personen-anlegen` und dem Trockenlauf; dann einschalten".
- Der CFG-Hinweis zu fest verdrahteten Platzhaltern entfällt.

## 10. Tests

- Je Sender: Vorlage aus der Einstellung wird verschickt (Name, Sprache, benannte Parameter aus dem Body). Kein Feld gewählt → kein Versand plus Grund. Nicht genehmigt → kein Versand. Unbekannter Platzhalter → kein Versand. Code-Vorlage ohne `{{code}}` → kein Versand. Code-/Hinweis-Vorlage mit Variablen-Knopf → kein Versand.
- Schwärzung: alle drei Erkennungswege einzeln, dazu eine Nicht-Code-Vorlage, die ungeschwärzt bleibt.
- EinsatzPruefung: Schalter aus → Team übersprungen, keine HR-Fälle. Schalter aus + `--dry-run` → Bericht wie bisher. Schalter aus + `--ids` → übersprungen. Zwei Teams, eins an → nur dieses.
- Fenster: `save()` übernimmt alle neun IDs und den Schalter. Ein Umschalten des Schalters erzeugt die Logzeile. Blade-Check der Datei.
- Ein Config-Test sichert, dass `code_vorlagen`, `hinweis_vorlage` und `aufgaben.vorlagen` nicht mehr in der Config stehen, damit niemand den Weg zurück baut.
