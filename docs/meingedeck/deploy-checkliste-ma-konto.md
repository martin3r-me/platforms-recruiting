# Deploy-Checkliste `feat/ma-konto` → `main` → Produktion (meingedeck)

Stand: 12.10.2026 · Zweig `feat/ma-konto` @ `43b3703b` (344 Commits über `origin/main` `b102a0e2`,
`origin/main` ist vollständig enthalten → ff-Merge möglich) · Host-App `meingedeck` pinnt heute
`platform-recruiting` auf `b102a0e2` (`composer.lock`, Commit `58be62b2`).

Für: Sebastian (Deploy + Generalprobe auf der Demo). Jede Zeile nennt in `[…]` ihre Quelle.

**Quellen-Kürzel**

| Kürzel | Datei |
|---|---|
| S-Nachweise | `docs/superpowers/specs/2026-09-22-mitarbeiterportal-nachweise-design.md` |
| S-Konto | `docs/superpowers/specs/2026-09-28-mitarbeiterkonto-canvas68.md` |
| S-Trigger | `docs/superpowers/specs/2026-10-01-einsatz-trigger-design.md` |
| S-Dok | `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (§12 Deploy) |
| S-Spiegel | `docs/superpowers/specs/2026-10-09-personendaten-spiegeln-design.md` (§9) |
| S-Vertrag | `docs/superpowers/specs/2026-10-09-vertrag-aus-akte-und-ma-vertragscheck-design.md` (§8) |
| S-HR | `docs/superpowers/specs/2026-10-12-hr-bereich-mitarbeiter-design.md` (§11) |
| P-Klammer / P-Konto / P-Trigger | `docs/superpowers/plans/2026-09-28-personen-klammer.md` / `2026-09-29-konto-anmeldeschicht.md` / `2026-10-01-einsatz-trigger.md` |
| M:… | Memory-Notiz `~/.claude/projects/…platforms-recruiting/memory/<name>.md` |
| SP | `src/RecruitingServiceProvider.php` · CFG = `config/recruiting.php` · SET = `src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS) |

---

## 1. Überblick

### Was mit dem Merge live geht (Code + Schema)

| Paket | Was | Quelle |
|---|---|---|
| Nachweise-Selbstbedienung | Tabelle `rec_employee_proofs`, Nachweis-Block in der MA-Akte (für ALLE, ohne Gate), Seite `/employees/proof-inbox`, Fristenlauf-Kommando (ohne Zeitplan) | S-Nachweise, M:project_ma_konto_merge_risiken |
| Neues MA-Portal (Canvas 67) | Route `/recruiting/mitarbeiter/neu/{token}`; altes Portal leitet Umgestellte dorthin um | `routes/public.php`, `EmployeePortal.php` (Weiche) |
| Personen-Klammer (Gate C) | `rec_persons` + `rec_employees.rec_person_id` | S-Konto §4.3, P-Klammer |
| Konto-Anmeldeschicht (Gate D) | Öffentliche Seiten `/recruiting/konto`, `/recruiting/konto/anlegen[/{token}]`, Einmalcode, 5 Zurücksetzen-Wege, `recruiting:konto-einladen` | P-Konto, M:project_konto_anmeldeschicht_gate_d |
| Einsatz-Trigger | Stündliches `recruiting:einsatz-pruefung` (prüft, legt HR-Fälle an, verschickt ohne `--welle` nichts) | S-Trigger, SP |
| Dokumente gegenzeichnen | `rec_documents`, `rec_document_recipients`, Seite `/employees/documents`, Job `DokumentHinweiseVersenden` | S-Dok |
| Personendaten spiegeln | `PersonenSpiegel` über Observer `RecEmployee::updated` + ZAS-Eingang | S-Spiegel |
| Vertrag aus der Akte + MA-Vertragscheck | Knopf „Vertrag erstellen" in der Akte, `rec_contracts.rec_applicant_id` nullable | S-Vertrag |
| HR-Bereich Mitarbeiter | Reiter „Mitarbeiter" im HR-Schreibtisch, Konto-Kasten in der Akte, `rec_hr_vermerke`, Sitzungs-Ungültigkeit | S-HR |
| Altes Portal: 2 Sicherheitsfixes | `ec85cc58` (keine Stammdaten vor Login), `9280a97b` (hydrate-Sitzungsprüfung) | M:project_hr_bereich_mitarbeiter |

### Was dunkel bleibt, bis es bewusst eingeschaltet wird

- [ ] **Neues Portal** nur für Anstellungen mit `portal_v2_since` → setzt ausschließlich `recruiting:portal-umstellen` (zieht ganze Personen mit). Alle anderen: 404 auf `/mitarbeiter/neu/…`. [`SwitchPortalVersion.php`, M:project_personen_klammer_stufe1]
- [ ] **WhatsApp-Versand Konto** (Code, Hinweis an alte Nummer): fail-closed, solange die Felder „Code: …" und „Hinweis an die alte Nummer" in den Einstellungen (Mitarbeiterportal) leer sind. [`PortalVorlagen.php`, `EinmalcodeSender`]
- [ ] **Notfallweg (Weg 4)**: zwei Riegel, beide aus — `RECRUITING_KONTO_WEG4_AKTIV=false` und Team-Haken `konto_notfall_weg_aktiv=false`. [CFG `konto.weg4_aktiv`, SET, S-HR §11.7]
- [ ] **Einsatz-Trigger**: Schalter „Einsatz-Prüfung läuft" (`einsatz_pruefung_aktiv`, Einstellungen → Kommunikation → Mitarbeiterportal) steht auf **aus** (Vorgabe) → der Stundenlauf überspringt das Team, legt keine HR-Fälle an und verschickt nichts; `--dry-run` ignoriert den Schalter. Zusätzlich: Felder „Offene Aufgaben — neu/Erinnerung" leer = kein Versand. [Spec 2026-10-12-portal-vorlagen-in-einstellungen §5, SET]
- [ ] **MA-Vertragscheck**: `contract_check_companies = []` = aus. [SET, S-Vertrag §8]
- [ ] **Fristenlauf Nachweise** (`recruiting:nachweise-erinnern`): absichtlich KEIN Zeitplan-Eintrag; scharfer Lauf bricht ohne `--stichtag` ab. [SP, `SendProofReminders.php`]
- [ ] **Dokument-/Vertrags-WhatsApp**: ohne `document_wa_template_id` liegt das Dokument bereit, Status `nicht_konfiguriert`; ohnehin nur an Umgestellte (`STATUS_ALTES_PORTAL`). [S-Dok §12, `DokumentHinweisSender.php`]
- [ ] **Konto-Einladung** verschickt nichts — HR sieht Link + Code einmal (Kommando bzw. Konto-Kasten). [`KontoEinladen.php`, S-HR §12.1]

### Was NICHT dunkel ist (sofort sichtbar/wirksam nach Deploy)

1. Stündlicher Lauf `recruiting:einsatz-pruefung` ist bei ausgeschaltetem Schalter (Vorgabe) ein No-op. **Nach dem Einschalten** legt er **HR-Fälle „Arbeitserlaubnis fehlt"** an — vor `nachweise-umziehen` wären das FALSCHE Fälle (Tabelle leer); deshalb Schalter erst nach 4.4 (Schritt „Schalter Einsatz-Prüfung an"). [M:project_ma_konto_merge_risiken Nr. 1]
2. MA-Akte zeigt für alle den Block „Nachweise"; bis zum Umzug überall rot „Fehlt", danach bei allen ohne Datei (Ausweis ist immer Pflicht). [ebd. Nr. 2; `show.blade.php` ~Z. 513, kein Gate]
3. Mitarbeiterliste: Knopf „Nachweise" → `/employees/proof-inbox`. [ebd. Nr. 3]
4. Öffentliche Seite `/recruiting/konto` mit Login-Formular erreichbar. [ebd. Nr. 5]
5. Personendaten-Spiegel: jede Eloquent-Änderung an einer Akte schreibt die Geschwister-Akte mit und setzt deren ZAS-Marker → beide Gesellschaften in `updates.csv`. [S-Spiegel §9, `RecEmployeeExportObserver`]
6. HR-Schreibtisch: neuer Reiter „Mitarbeiter", neue Fall-Art, Karte „Mitarbeiter #…". [S-HR, M:project_einsatz_trigger_und_zas_sperrfeld]

---

## 2. VORHER (Tage vor dem Deploy)

### 2.1 Meta-WhatsApp-Vorlagen einreichen

Allgemeine Regeln, aus dem Code abgeleitet:

- **Alle Konto-/Aufgaben-Vorlagen: BENANNTE Platzhalter** (`{{code}}`, `{{anzahl}}`), kein `{{1}}`. Die Sender schicken `parameter_name` (klein geschrieben). [`EinmalcodeSender.php`]
- **Platzhalter werden aus der Vorlage gelesen; unbekannte verhindern den Versand.** Name und Sprache kommen aus der in den Einstellungen gewählten (genehmigten) Vorlage, die Platzhalter aus deren BODY. Ein Platzhalter, den der Sender nicht befüllen kann, ein fehlendes `{{code}}` in einer Code-Vorlage oder ein Knopf mit Variable bei Code-/Hinweis-Vorlagen → KEIN Versand, Grund im Log. Der Vorname wird nie still eingesetzt. [Spec §3]
- **Portal-Vorlagen: genau EIN dynamischer URL-Knopf**, Token als Suffix. Kein Knopf / statischer Knopf → `vorlage_ohne_link` bzw. `template_without_url_button`, zwei dynamische Knöpfe → `vorlage_mehrere_knoepfe`/`knopf_nicht_eindeutig`; jeweils KEIN Versand. [`WhatsAppTemplateUrlButtons.php`, `ApplicantTemplateSender::buildTokenComponents`]
- **Knopf-URL**: die Testvorlagen in den Plänen nutzen `https://<host>/recruiting/mitarbeiter/neu/{{1}}` [plans/2026-10-08-dokumente-gegenzeichnen.md Z. 1522]. Alternative `https://<host>/recruiting/mitarbeiter/{{1}}` (alte Route leitet Umgestellte mit demselben Token weiter, `EmployeePortal.php` Weiche) — diese Form führt nach `portal-umstellen --zurueck` nicht auf eine 404. Host: `mitarbeiter.rheingedeck.de` laut M:reference_forge_deploy_skript_npm — vor dem Einreichen gegen die bestehende Portal-Vorlage (`employee_portal_wa_template_id`) abgleichen; die Basis-URL bei Meta hat keinen Wächter (MEMORY.md, Eintrag „Zertifikat-Link als URL-Button").
- **Anrede: nur du.** Es wird je Zweck eine Fassung in du-Form eingereicht (die Sender unterscheiden nicht zwischen Sie und du). Textvorschläge unten.
- **Vorlage wählen statt Name eintragen:** Die Felder in „Einstellungen → Kommunikation → Mitarbeiterportal" bieten nur genehmigte Vorlagen an. Nach der Meta-Freigabe den Vorlagen-Abgleich laufen lassen, sonst steht die Vorlage nicht zur Auswahl; ist keine gewählt oder nicht genehmigt, steht im Log `recruiting.aufgaben.vorlage_fehlt` mit einem `grund`. [`AufgabenSender`, `PortalVorlagen.php`]
- **Kategorie Code-Vorlagen — offener Punkt**: S-Konto §10 empfiehlt Metas Code-Klasse (AUTHENTICATION, „Code kopieren"). `EinmalcodeSender` schickt aber **nur eine Body-Komponente mit benanntem `{{code}}`, keine Knopf-Komponente** [`EinmalcodeSender.php` ~Z. 282–293]. Metas AUTHENTICATION-Vorlagen haben einen von Meta vorgegebenen Text und einen Code-Knopf — das passt nicht zum heutigen Sender. Bis zur Klärung als **UTILITY** einreichen und das Risiko einer Umstufung/Ablehnung durch Meta einplanen.

| # | Zweck | Sender | Eintragen in | Body-Platzhalter (erlaubt) | Knopf | Kategorie (Annahme) | Textvorschlag (du) | Rückfall |
|---|---|---|---|---|---|---|---|---|
| 1 | Code „Passwort vergessen" (Weg 3) | `EinmalcodeSender` (`KontoAnmelden.php` Z. 778) | Einstellungen → Kommunikation → Mitarbeiterportal → Code: Passwort vergessen | `{{code}}` (Pflicht), sonst nur Vorname | keiner mit Variable | UTILITY (s. o.) | „Dein Code zum Zurücksetzen des Passworts für dein Mitarbeiterkonto lautet {{code}}. Er gilt 10 Minuten — bitte gib ihn an niemanden weiter." | keiner — ohne Feld kein Versand (Log `recruiting.konto.einmalcode_versand`) |
| 2 | Code „neue Nummer bestätigen" (geht an die NEUE Nummer) | `EinmalcodeSender` (`KontoAnmelden.php` Z. 650) | Einstellungen → Kommunikation → Mitarbeiterportal → Code: neue Nummer bestätigen | wie #1 | keiner mit Variable | UTILITY | „Mit dem Code {{code}} bestätigst du diese Nummer für dein Mitarbeiterkonto. Er gilt 10 Minuten." | keiner (dieselbe Vorlage wie #1 darf in beiden Feldern gewählt werden) |
| 3 | Code Notfallweg (Weg 4, beantragt Wechsel, 24 h, HR kann stoppen) | `EinmalcodeSender` (`KontoAnmelden.php` Z. 966) | Einstellungen → Kommunikation → Mitarbeiterportal → Code: Notfall-Weg — **zuletzt und getrennt eintragen** | wie #1 | keiner mit Variable | UTILITY | „Dein Code {{code}} beantragt den Wechsel deiner Nummer. Der Wechsel wird frühestens nach 24 Stunden wirksam. Er gilt 10 Minuten." | keiner; ohne `weg4_aktiv` ohnehin kein Versand |
| 4 | Code „Anmeldung" | — | **kein Feld** | — | — | — | — | Wird nicht verschickt (`ZWECK_ANMELDUNG` nur in der Zweck-Liste `KontoWriter.php` Z. 108, kein Aufrufer) → nicht einreichen |
| 5 | Hinweis an die ALTE Nummer nach Nummernwechsel | `NummernwechselHinweisSender` | Einstellungen → Kommunikation → Mitarbeiterportal → Hinweis an die alte Nummer | nur Vorname oder gar keiner (neue Nummer bewusst nicht im Text) | keiner mit Variable | UTILITY | „Die Handynummer deines Mitarbeiterkontos wurde soeben geändert. Warst du das nicht, melde dich bitte umgehend bei der Personalabteilung." | keiner; der Wechsel selbst ist dann schon vollzogen |
| 6 | Aufgaben „neu" (Einsatz-Trigger) | `AufgabenSender` | Einstellungen → Kommunikation → Mitarbeiterportal → Offene Aufgaben — neu | `{{anzahl}}`, optional Vorname | **genau 1 URL-Knopf mit Variable am Ende**, Token-Suffix | UTILITY | „Für deinen nächsten Einsatz sind im Mitarbeiterportal noch {{anzahl}} Punkte offen. Bitte erledige sie rechtzeitig über den Knopf unten." | keiner; Status `nicht_konfiguriert` / `vorlage_untauglich`, Aufrufer schreibt nichts |
| 7 | Aufgaben „Erinnerung" | `AufgabenSender` | Einstellungen → Kommunikation → Mitarbeiterportal → Offene Aufgaben — Erinnerung | `{{anzahl}}`, `{{datum}}` (als `d.m.Y`), optional Vorname | 1 URL-Knopf mit Variable | UTILITY | „Erinnerung: Dein Einsatz am {{datum}} rückt näher, im Mitarbeiterportal sind noch {{anzahl}} Punkte offen. Bitte erledige sie über den Knopf unten." | keiner |
| 8 | Fristenlauf Nachweise | `ProofReminderSender` | Einstellungen → Kommunikation → Mitarbeiterportal → Nachweis läuft ab (Fristenlauf) | unverändert: `{{nachweis}}`, `{{datum}}`, Vorname als `{{name}}`/`{{vorname}}`/`{{1}}`; jeder andere → `template_unknown_placeholder` | 1 URL-Knopf mit Variable | UTILITY | „Hallo {{vorname}}, dein Nachweis {{nachweis}} läuft am {{datum}} ab. Bitte lade rechtzeitig einen neuen über den Knopf unten hoch." | keiner; Text liegt laut M:project_nachweise_selbstbedienung bei Markus |
| 9 | „Im Portal liegt ein Dokument" | `DokumentHinweisSender` (Job `DokumentHinweiseVersenden`) | Einstellungen → Kommunikation → Mitarbeiterportal → Dokument liegt im Portal | unverändert: nur Vorname `{{vorname}}`/`{{name}}`/`{{1}}` oder keiner | 1 URL-Knopf mit Variable | UTILITY | „Hallo {{vorname}}, in deinem Mitarbeiterportal liegt ein neues Dokument für dich bereit. Du öffnest es über den Knopf unten." | ohne Wert: Status `nicht_konfiguriert`, „Erneut senden" holt nach [S-Dok §12] |
| 10 | „Vertrag zur Unterschrift im Portal" | `VertragHinweisSender` | Einstellungen → Kommunikation → Mitarbeiterportal → Vertrag zur Unterschrift | wie #9 | 1 URL-Knopf mit Variable | UTILITY | „Hallo {{vorname}}, in deinem Mitarbeiterportal liegt ein Vertrag zur Unterschrift für dich bereit. Du öffnest ihn über den Knopf unten." | **ja**: leer → nimmt das Feld „Dokument liegt im Portal" [`VertragHinweisSender.php`] |
| — | Portal-Benachrichtigung (bestehend) | `RecEmployee::sendPortalNotification` | `employee_portal_wa_template_id` (unverändert) | unverändert | unverändert | — | — | Altfehler: meldet Meta-`failed` als Erfolg (M:project_ma_portal_locked_fix) — nicht im Zweig geändert |
| — | Konto-Einladung | **kein Sender** | — | — | — | — | — | HR gibt Link + Code von Hand weiter [`KontoEinladen.php`, S-HR §12.1] |

Name und Sprache kommen aus der gewählten Vorlage; die Sprach-Schlüssel `RECRUITING_*_VORLAGE_SPRACHE` gibt es nicht mehr. Die Sprache der Meta-Vorlage muss deutsch sein, weil die Texte deutsch sind — die Sender lesen sie aus der Vorlage. [Spec §3]

### 2.2 `.env`-Schlüssel (Produktion)

Die WhatsApp-Vorlagen stehen NICHT mehr in `.env` (alle zehn: Einstellungen → Kommunikation → Mitarbeiterportal, Tabelle 2.1). Es bleiben nur zwei technische Schlüssel:

| Schlüssel | Vorgabe | Bedeutung | Wann setzen | Quelle |
|---|---|---|---|---|
| `RECRUITING_KONTO_PEPPER` | leer → `app.key` | Pfeffer für Einladungs-Token + Einmalcode (nicht Passwort). Wechsel macht offene Einladungen (7 Tage) und Codes (10 Min) ungültig. | optional, vor der ersten Einladung entscheiden und dann nie mehr ändern | CFG `konto.pepper` |
| `RECRUITING_KONTO_WEG4_AKTIV` | `false` | Server-Riegel Notfallweg (bewusst nicht im Fenster) | **zuletzt**, erst nach S-HR §11.7 | CFG `konto.weg4_aktiv` |

Nach jeder `.env`-Änderung dieser beiden: `php artisan config:cache` (S-HR §11.7) bzw. `optimize:clear`, wenn prod ohne Config-Cache läuft. Für die Vorlagen ist kein `config:cache` nötig. Beim Umbenennen/Austauschen einer Code-Vorlage den ALTEN Namen in `konto.code_vorlagen_alt` eintragen (Code-Änderung, CFG-Kommentar) — sonst können alte Codes im Chat-Verlauf unmaskiert stehen, falls die alte Definition nicht mehr gefunden wird.

### 2.3 Team-Einstellungen (neu im Zweig, `RecApplicantSettings::DEFAULT_SETTINGS`)

| Schlüssel | Vorgabe | Wo | Nach Deploy | Quelle |
|---|---|---|---|---|
| `document_wa_template_id` | `null` | Einstellungen → Kommunikation → Mitarbeiterportal → Dokument liegt im Portal | setzen, sobald #9 genehmigt | SET, S-Dok §12 |
| `employee_contract_wa_template_id` | `null` | … → Vertrag zur Unterschrift | optional (Rückfall auf #9) | SET, S-Vertrag §8 |
| `proof_reminder_wa_template_id` | `null` | … → Nachweis läuft ab (Fristenlauf) | erst mit Stichtag-Entscheidung | SET, `ProofReminderSender::SETTINGS_KEY` |
| `konto_code_passwort_wa_template_id`, `konto_code_nummernwechsel_wa_template_id`, `konto_code_notfall_wa_template_id` | `null` | … → Code: Passwort vergessen / neue Nummer bestätigen / Notfall-Weg | nach Freigabe #1–#3 (Notfall zuletzt) | SET, Spec §2 |
| `konto_hinweis_alte_nummer_wa_template_id` | `null` | … → Hinweis an die alte Nummer | nach Freigabe #5 | SET |
| `aufgaben_neu_wa_template_id`, `aufgaben_erinnerung_wa_template_id` | `null` | … → Offene Aufgaben — neu / Erinnerung | nach Freigabe #6/#7, vor der ersten Welle | SET |
| `einsatz_pruefung_aktiv` | `false` (aus) | … → Schalter „Einsatz-Prüfung läuft" | **AUS lassen**; einschalten erst nach `nachweise-umziehen` → `personen-anlegen` → `einsatz-pruefung --dry-run` → Zahlen gemeldet (4.4) | SET, Spec §5 |
| `contract_check_companies` | `[]` (aus) | Einstellungen → Checkboxen „Gesellschaft RG/MA" | **AUS lassen**, Einschalten s. 4.9 | SET, S-Vertrag §8 |
| `tax_class_per_company` | `false` | Einstellungen → Lohn | AUS lassen bis Antwort Lohnbüro | SET, S-Spiegel §9, M:project_markus_info_dokument_offene_punkte |
| `konto_notfall_weg_aktiv` | `false` | Einstellungen (Checkbox) | **AUS lassen** | SET, S-HR §11.4 |
| `hr_erinnert_tage` | `5` | Einstellungen | Vorgabe ok | SET, S-HR |
| `hr_kontaktiert_ruhe_tage` | `3` | Einstellungen | Vorgabe ok | SET, S-HR §12.3 |

Selects im Einstellungs-Fenster: nach dem Speichern per SQL nachsehen, ob der Wert steht (M:project_settings_modal_select_save_bug; Workaround `JSON_SET`, prod-Team = 3).

### 2.4 Klären / vorbereiten

- [ ] **APP_TIMEZONE auf prod prüfen** (`php artisan tinker --execute="echo config('app.timezone');"`). [M:project_hr_bereich_mitarbeiter]
- [ ] **MySQL-FOR-UPDATE-Check** (zwei Konsolen, Zeile sperren, zweite Aktion muss warten) — Suite läuft auf SQLite. Nur Voraussetzung für die Notfallweg-Freigabe, nicht für den Deploy. [S-HR §11.4]
- [ ] **`migrate --pretend` gegen prod-MySQL lesen** für die zwei `change()`-Migrationen (`2026_10_01_000002`, `2026_10_09_000003`); in der SQLite-Suite ist der `change()`-Zweig nicht belegt. [M:project_ma_konto_merge_risiken, S-Vertrag §8]
- [ ] **`vertraege-an-anstellung`: offene `mehrdeutig`/`firma_fehlt` klären** — Stand 07.10.: 2× `mehrdeutig` = Verträge #385/#386, Dublette MA 16/1312 (HR klären, Backfill idempotent erneut). [S-Vertrag §8, M:project_vertrag_an_der_anstellung]
- [ ] **Personen-Paare-Konflikte** aus dem Vorflug (29.09.): 1 Gruppe verschiedene Menschen **MA 160 + 223**; 5 Abweichungen Akte↔CRM-Kontakt **MA 869, 1511, 1538, 1594, 1614**; ~7 Paare ohne Marker. Zahlen neu ziehen mit `docs/meingedeck/vorflug-konto-abfragen.sql` und `php artisan recruiting:mitarbeiter-grenzfaelle` (Fälle `nummer_mehrfach_andere_person`, `ohne_telefon_akte`, `crm_ohne_nummer`). [M:project_personen_klammer_stufe1, S-Konto §9]
- [ ] **`is_main_employer` in `employee_payroll_tracked_fields`** des Teams (Auflage 2 vor `portal-umstellen`) und **wie viele MA `is_main_employer IS NULL`** haben (jede Erstantwort = volle Zeile in `updates.csv`). [`SwitchPortalVersion.php` Docblock, M:project_konto_anmeldeschicht_gate_d]
- [ ] **ZAS ankündigen (Olaf Michel)**: „Stammdaten-Änderungen einer Person kommen ab jetzt für RG- und MA-Datensatz gemeinsam in der Aktualisierungsdatei. Keine neue Spalte." [S-Spiegel §9] — Sonst bleibt der Export byteweise gleich, keine Spaltenankündigung nötig. [M:project_ma_konto_merge_risiken, M:feedback_zas_export_spalten_ankuendigen]
- [ ] **HR vorwarnen**: neue Fall-Art „Arbeitserlaubnis fehlt", Karte „Mitarbeiter #…", „Ablehnen" fehlt bei Fällen ohne Bewerber; Nachweis-Block rot; wer schaut täglich in „Konto-Anträge". [M:project_einsatz_trigger_und_zas_sperrfeld, M:project_ma_konto_merge_risiken Nr. 4, S-HR §11.6]
- [ ] **Entscheidung Nachweis-Block** in der Akte: hinter `portal_v2_since` legen (Code-Änderung) ODER HR vorwarnen. [M:project_ma_konto_merge_risiken Nr. 2]
- [ ] **Schalter Einsatz-Prüfung bleibt aus (Vorgabe).** Der Zeitplan-Eintrag bleibt unverändert und ist bei ausgeschaltetem Schalter ein No-op — kein Zeitfenster-Rennen mehr nötig. Reihenfolge: `nachweise-umziehen` → `personen-anlegen` → `einsatz-pruefung --dry-run` → Zahlen melden → Schalter an (Schritt in 4.4). [Spec §5, M:project_ma_konto_merge_risiken „Deploy-Reihenfolge"]
- [ ] **Offene Fragen an Markus, die das Einschalten blockieren** [M:project_markus_info_dokument_offene_punkte, M:project_nachweise_selbstbedienung, M:project_einsatz_trigger_und_zas_sperrfeld, S-Vertrag §9]:
  - Stichtag Altbestand Fristenlauf (~540 abgelaufene Nachweise) + Vorlagentext #8
  - Zahlen 4/2/7 (Vorlauf/Erinnerung/Pause) des Einsatz-Triggers; „läuft ab"→„abgelaufen" meldet nicht erneut; zwei Einsätze in einer Pause = eine Nachricht
  - MA-Vertrag: Beginn/Dauer, Tätigkeit, Vertragsarten/Texte, Automatik, Zuschlag/Datei an ZAS
  - Steuerklasse und Haupt-/Nebenarbeitgeber je Person oder je Gesellschaft (Schalter `tax_class_per_company`)
  - Arbeitgeber-Erklärtext (Auflage 3 vor `portal-umstellen`)
  - ~1320 von 1603 sähen „Ausweis fehlt" bei Umstellung aller (Kommunikationsfrage)
- [ ] **Offen, fremde Repos (Entscheidung, nicht Deploy-blockierend)**: `trustProxies(at:'*')` in meingedeck einengen; Einmalcode liegt in platform-crm im Klartext (template_params, CommsLog, Log bei failed); steht vor prod ein CDN? [M:project_konto_anmeldeschicht_gate_d]
- [ ] **Hotfix altes Portal** (`ec85cc58` + `9280a97b`, LIVE-Lücke auf main): Wenn er VOR dem Merge separat auf main geht, ist kein ff mehr möglich → main erneut in den Zweig mergen, Suite, Demo neu pinnen. Am einfachsten: mit diesem Merge ausrollen. [M:project_hr_bereich_mitarbeiter]
- [ ] **Was auf main gebumpt, aber laut Memory noch nicht deployt ist** — läuft mit diesem Forge-Deploy mit: „Nicht zugestellt" (migrate), „Versand vormerken" (migrate + queue:restart), „Teilnehmer verschieben" (migrate), „Schulung voll" (queue:restart + Settings-Key), „Sammelversand ohne Einsatz" (queue:restart + Settings-Key), „Anleitungen" (view:clear). Vorher prüfen, was davon schon gelaufen ist. [MEMORY.md-Einträge dazu]

---

## 3. GENERALPROBE AUF DER DEMO

Demo: `demo.bhgdigital.de`, lokaler Klon `/Users/shaustein/Documents/dev/platforms/demo.bhgdigital.de`, Pin heute `43b3703b` (Commit `3567815`), **Team 6**. `composer.json` verlangt `dev-main`, die Bindung lebt nur im Lock → **`./update.sh` NIE laufen lassen** (holt `main` zurück). [M:project_einsatz_trigger_und_zas_sperrfeld, MEMORY.md]

Ziel: denselben Ausgangszustand wie prod herstellen (Schema von `main`, echter Bestand pseudonymisiert), dann exakt die Prod-Schritte aus Abschnitt 4 fahren.

1. [ ] **Pin auf main** (`b102a0e2`): Lock-Zeiger drehen, z. B. `python3 tools/deploy-lock.py --app /Users/shaustein/Documents/dev/platforms/demo.bhgdigital.de --package martin3r/platform-recruiting --ref b102a0e2 --dry-run`, dann ohne `--dry-run`; Forge-Deploy Demo. [`tools/deploy-lock.py`]
2. [ ] **Nur `rec_*` zurücksetzen** (andere Module bleiben): auf der Demo-DB
   ```sql
   SELECT GROUP_CONCAT(CONCAT('`',table_name,'`')) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name LIKE 'rec\_%';
   SET FOREIGN_KEY_CHECKS=0; DROP TABLE <Liste>; SET FOREIGN_KEY_CHECKS=1;
   ```
   und in `migrations` die Zeilen der Recruiting-Migrationen löschen (Dateinamen = `ls database/migrations` des Moduls, ohne `.php`). Hinweis: zwei Recruiting-Migrationen schreiben auch in `core_extra_field_definitions` und `comms_channels` (geprüft per grep) — diese Zeilen bleiben stehen; `seed-rec-contract-extra-fields` ist idempotent.
3. [ ] **`php artisan migrate --force` mit main-Code** → nur die main-Migrationen laufen.
4. [ ] **Pseudonymisierten Bestand einspielen**: in TablePlus gegen prod `SELECT * FROM rec_employees;` als CSV, lokal `php tools/dump-mitarbeiter-pseudonym.php --csv=rec_employees.csv --team=6 > demo-mitarbeiter.sql`, auf der Demo einspielen. `--team` ist PFLICHT (prod 3, Demo 6). Anmeldung im alten Portal: Token `dump-<id>`, Geburtsdatum +137 Tage, Ausweis-Endziffern `4711`. Nummern sind nicht wählbar. [`tools/dump-mitarbeiter-pseudonym.php`, M:project_personen_klammer_stufe1]
5. [ ] **Pin auf den Zweig** `43b3703b` (wie Schritt 1 mit `--ref 43b3703b`), Forge-Deploy Demo.
5a. [ ] **Sichttest im Browser (harter Schritt, VOR dem Einschalten der Einsatz-Prüfung):** Einstellungen → Kommunikation → Mitarbeiterportal: eine Vorlage wählen, Speichern, Fenster neu öffnen — Wahl steht noch da; zusätzlich Wert in `rec_applicant_settings.settings` prüfen. Bekannter Fehler: Selects verlieren beim Speichern die Auswahl. [M:project_settings_modal_select_save_bug]
6. [ ] **Abschnitt 4.4–4.9 exakt wie auf prod fahren** (alle `--dry-run` zuerst). Erwartungswerte zum Vergleichen:
   - `migrate`: 16 Migrationen des Zweigs (Liste 4.3), keine Fehler; zusätzlich `migrate:rollback --step=1` + `migrate` für `2026_10_09_000003` (S-Vertrag §8 verlangt MySQL migrate + rollback + migrate auf der Demo).
   - `nachweise-umziehen`: Demo 29.09. erzeugte **1759 Nachweise bei 1603 Datensätzen**. [M:project_personen_klammer_stufe1]
   - `personen-anlegen --dry-run`: vier Zahlen (Personen angelegt, Anstellungen verbunden, Nummern uneinig, Nummer nicht gesetzt) + Kennungen der HR-Sorten; prod-Vorflug erwartete **~1213 Personen-Zeilen**. [P-Klammer Z. 1127, M:project_personen_klammer_stufe1]
   - `einsatz-pruefung --dry-run` (ignoriert den Schalter): Zeilen „Arbeitserlaubnis fehlt: n … davon k neu", „Uebersprungen, weil ohne Personen-Zeile: …", „Vertragsprüfung: …" notieren. Auf der Demo zusätzlich „Gesellschaft MA" anhaken und trocken laufen lassen → Zahl „k Fälle neu" an Sebastian/Markus. Schalter auf der Demo erst danach an. [S-Vertrag §8.1]
   - `personendaten-abgleich`: Lesemodus, Anzahl Abweichungen notieren. [S-Spiegel §9]
7. [ ] **Sichttests aus Abschnitt 6** auf der Demo (Team 6), inkl. Konto-Durchlauf: `recruiting:konto-einladen --ids=<person> --dry-run`, einladen, `/recruiting/konto/anlegen/<token>`, Konto anlegen, abmelden, unter `/recruiting/konto` mit Nummer + Passwort anmelden. [P-Konto Z. 718]
8. [ ] **Rollback proben**: `portal-umstellen --ids=<id> --zurueck`; Pin zurück auf `b102a0e2` ohne Schema-Rückbau → Akte, HR-Schreibtisch, Dispo-Import müssen mit main-Code laufen (Schema additiv). [S-HR §11 „Rollback: Code zurück genügt"]

Wenn ein Schritt scheitert: **nicht auf prod gehen**. Häufige Ursachen laut Memory: Forge-Deploy reißt im npm-Teil → Code live, aber `migrate`/`view:clear` fehlen → von Hand nachholen (M:reference_forge_deploy_skript_npm); Liste leer trotz Daten → falsches `--team` beim Dump; Login unmöglich → Nummern-Normalisierung (Dump-Skript Z. ~76).

---

## 4. DEPLOY PRODUKTION

### 4.1 Merge (ff)

- [ ] Freigabe von Sebastian liegt vor (Regel: nichts auf main ohne Ansage). [M:project_konto_anmeldeschicht_gate_d „How to apply"]
- [ ] Haupt-Worktree aktualisieren (steht 1 Commit hinter `origin/main`):
  ```bash
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting fetch origin
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting checkout main
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting pull --ff-only
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting log --oneline HEAD..origin/feat/ma-konto | wc -l   # erwartet: Zweig vollständig vor main
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting merge --ff-only origin/feat/ma-konto
  git -C /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting push origin main
  ```
  Kein PR, kein `gh`; Remote-Zweig nur auf ausdrücklichen Auftrag löschen. [M:feedback_kein_gh_ff_merge]
- [ ] Ist `--ff-only` abgelehnt (main ist weitergelaufen): main in den Zweig mergen, Suite, erneut. [M:project_einsatz_trigger_und_zas_sperrfeld „Merkposten"]

### 4.2 meingedeck bumpen

- [ ] **Rollback-Referenz notieren: `platform-recruiting` = `b102a0e2`** (meingedeck `58be62b2`).
- [ ] `composer update martin3r/platform-recruiting -d /Users/shaustein/Documents/dev/platforms/meingedeck --no-interaction --no-scripts`
- [ ] `git -C /Users/shaustein/Documents/dev/platforms/meingedeck diff composer.lock` — nur `reference` (source + dist) von platform-recruiting darf sich ändern; `composer.json` des Moduls ist im Zweig unverändert. [M:feedback_bump_meingedeck, M:project_ma_konto_merge_risiken „Entwarnungen"]
- [ ] Commit `chore: bump platform-recruiting to 43b3703b (feat/ma-konto: Konto, Nachweise, Einsatz-Trigger, Dokumente, Spiegel, Vertrag aus Akte, HR-Bereich)`, push. Bei veraltetem Hash: Packagist-CDN-Cache, `composer clear-cache`. [M:feedback_bump_meingedeck]

### 4.3 Forge-Deploy (mit migrate)

- [ ] Zeitpunkt frei wählbar: Der Stundenlauf `einsatz-pruefung` ist durch den ausgeschalteten Schalter (Vorgabe) wirkungslos. [Spec §5]
- [ ] Forge-Deploy starten. Skript: git pull, composer, FPM-Reload, vite build, `migrate --force`, `optimize:clear`. **Reißt der Deploy im npm-Teil, ist der PHP-Code schon live, aber `migrate`/`view:clear` fehlen** — dann sofort von Hand `php artisan migrate --force` und `php artisan view:clear`. Ohne migrate knallen: HR-Desk-Liste, MA-Akte, ZAS-Dispo-Import (Lieferung bleibt unverarbeitet → Reprocess), beide Stunden-Jobs. [M:reference_forge_deploy_skript_npm, M:project_ma_konto_merge_risiken]
- [ ] `php artisan migrate:status | grep -E '2026_09_2|2026_10_0|2026_10_12'` — alle 16 „Ran":

| Migration | Art | Hinweis |
|---|---|---|
| `2026_09_23_000001_create_rec_employee_proofs_table` | neue Tabelle | down = drop |
| `2026_09_24_000001_add_portal_v2_since_to_rec_employees` | neue Spalte | down = dropColumn |
| `2026_09_28_000001_create_rec_persons_table` | neue Tabelle | down = drop (**Konten/Passwörter weg**) |
| `2026_09_28_000002_add_rec_person_id_to_rec_employees` | neue Spalte + Index | down vorhanden |
| `2026_09_29_000001_add_konto_felder_to_rec_persons` | neue Spalten | down vorhanden |
| `2026_09_29_000002_add_phone_index_to_rec_persons` | Index | trägt die öffentliche Anmeldeabfrage |
| `2026_09_29_000003_add_nummernwechsel_zu_rec_persons` | neue Spalten | down vorhanden |
| `2026_09_29_000004_add_notfall_sperre_zu_rec_persons` | neue Spalte | down vorhanden |
| `2026_10_01_000001_add_trigger_state` | Spalten an `rec_persons` **und `rec_dispo_assignments`** (`aufgaben_erinnert_at`) | bestehende Tabelle |
| `2026_10_01_000002_add_employee_to_hr_desk_cases` | **ändert bestehende Spalte**: `rec_applicant_id` → nullable per `change()` (nur wenn NOT NULL) + neue Spalte | down macht NOT NULL nur, wenn keine Zeile ohne Bewerber existiert |
| `2026_10_01_000003_add_aufgaben_nachricht_to_rec_persons` | neue Spalte | down vorhanden |
| `2026_10_09_000001_create_rec_documents_table` | neue Tabelle | down = drop |
| `2026_10_09_000002_create_rec_document_recipients_table` | neue Tabelle | down = drop |
| `2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts` | **ändert bestehende Spalte** (`change()`) | **down scheitert absichtlich**, sobald Verträge ohne Bewerbung existieren |
| `2026_10_12_000002_create_rec_hr_vermerke_table` | neue Tabelle | down = drop |
| `2026_10_12_000003_add_sitzungen_ungueltig_ab_to_rec_persons` | neue Spalte | down vorhanden |

Keine der 16 ist eine Daten-Migration; die Datenschritte sind Kommandos (4.4). Auf main liegt zusätzlich `2026_10_12_000001_create_rec_delivery_failure_acks_table` („Nicht zugestellt") — läuft mit, falls noch nicht geschehen.

### 4.4 Einmal-Kommandos, in dieser Reihenfolge

Vorher Zähler notieren: `SELECT COUNT(*) FROM rec_employees WHERE zas_changed_at IS NOT NULL;` (Datenschritte dürfen ihn nicht bewegen). [M:project_personen_klammer_stufe1, M:feedback_bestandsaenderung_ohne_export_marker]

1. [ ] `php artisan recruiting:nachweise-umziehen --dry-run` → Zahlen lesen → `php artisan recruiting:nachweise-umziehen`. Muss vor dem nächsten Stundenlauf von `einsatz-pruefung` fertig sein. [M:project_ma_konto_merge_risiken]
2. [ ] `php artisan recruiting:personen-anlegen --dry-run` → vier Zahlen + Kennungen lesen → `php artisan recruiting:personen-anlegen`. **HARTE VORAUSSETZUNG** für Konto, HR-Bereich und Trigger-Nachrichten. Trockenlauf rollt je Gruppe zurück, **verbraucht aber AUTO_INCREMENT** in `rec_persons`. Teil-Commits je Gruppe → darf neben dem Betrieb laufen. **Nicht gleichzeitig mit `person-pair-audit --apply`.** [P-Klammer Z. 1127, M:project_konto_anmeldeschicht_gate_d, M:project_personen_klammer_stufe1]
3. [ ] `SELECT COUNT(*) FROM rec_employees WHERE zas_changed_at IS NOT NULL;` — gleicher Wert wie vorher? `SELECT COUNT(*) FROM rec_persons;` (~1213 laut Vorflug), `SELECT COUNT(*) FROM rec_employees WHERE is_active=1 AND rec_person_id IS NULL;`.
4. [ ] `php artisan recruiting:seed-rec-contract-extra-fields --dry-run` → ohne `--dry-run`. Legt `zuschlag` + `herkunft` an; **ohne `herkunft` lehnt die Akte „Vertrag erstellen" ab**. [S-Vertrag §8]
5. [ ] `php artisan recruiting:einsatz-pruefung --dry-run` (läuft auch bei ausgeschaltetem Schalter) → Bericht lesen: „Arbeitserlaubnis fehlt: n … davon k neu", „ohne Personen-Zeile", „altes Portal", „Vertragsprüfung: 0 …" (Check aus). k an HR/Sebastian melden, bevor der Schalter eingeschaltet wird. [P-Trigger Z. 1932–1933, `EinsatzPruefung::bericht`]
6. [ ] `php artisan recruiting:personendaten-abgleich` (Lesemodus) → Liste an HR. Angleichen nur gezielt: `--person=<rec_person_id> --nach=<rec_employee_id> [--felder=…]`. [S-Spiegel §9]
7. [ ] Optional Stand Konto: `php artisan recruiting:konto-einladen --bericht` (schreibt nichts). [`KontoEinladen.php`]
7a. [ ] **Sichttest im Browser (harter Schritt, VOR dem Einschalten der Einsatz-Prüfung):** Einstellungen → Kommunikation → Mitarbeiterportal: eine Vorlage wählen, Speichern, Fenster neu öffnen — Wahl steht noch da; zusätzlich Wert in `rec_applicant_settings.settings` prüfen. Bekannter Fehler: Selects verlieren beim Speichern die Auswahl. [M:project_settings_modal_select_save_bug]
8. [ ] **Schalter Einsatz-Prüfung an** (Einstellungen → Kommunikation → Mitarbeiterportal → „Einsatz-Prüfung läuft"; `einsatz_pruefung_aktiv` = an) — erst NACH `nachweise-umziehen` → `personen-anlegen` → `einsatz-pruefung --dry-run` → Zahlen an HR/Sebastian gemeldet (Schritte 1, 2, 5; Reihenfolge nicht vertauschen). Danach per SQL nachsehen, dass der Wert steht (M:project_settings_modal_select_save_bug), im Log `recruiting.einsatz_pruefung.schalter` prüfen; ab der nächsten vollen Stunde legt der Lauf HR-Fälle an. Ohne diesen Schritt bleibt der Trigger dauerhaft aus. [Spec §5]

### 4.5 Caches und Worker

- [ ] `php artisan view:clear` (alle Pakete). [S-Dok, S-Spiegel, S-Vertrag, S-HR]
- [ ] **`php artisan queue:restart` — JA.** Neuer Job `DokumentHinweiseVersenden` (ShouldQueue) und geänderte Modelle/Observer; Worker lädt Code nur beim Start. [S-Dok §12, M:project_dokumente_gegenzeichnen, M:feedback_forge_queue_restart_after_deploy] (Ältere Notizen sagen „kein queue:restart" — überholt seit dem Dokumente-Paket.)

### 4.6 Einstellungen setzen

- [ ] Vorlagen-Felder in Einstellungen → Kommunikation → Mitarbeiterportal wählen, sobald die Meta-Vorlage genehmigt ist (Tabelle 2.1, Notfall-Code zuletzt); danach per SQL prüfen, dass die IDs stehen. [M:project_settings_modal_select_save_bug]
- [ ] „Dokument liegt im Portal" (`document_wa_template_id`) sobald #9 genehmigt; „Vertrag zur Unterschrift" (`employee_contract_wa_template_id`) optional.
- [ ] „Nachweis läuft ab (Fristenlauf)" (`proof_reminder_wa_template_id`) erst mit Stichtag; das Feld steht jetzt im Fenster, bei Bedarf per SQL:
  `UPDATE rec_applicant_settings SET settings = JSON_SET(settings, '$.proof_reminder_wa_template_id', <id>) WHERE team_id = 3;` [M:project_settings_modal_select_save_bug]
- [ ] Je Vertragsart eine AV-Vorlage mit `company = MA` anlegen — ohne Vorlage ist der Knopf in MA-Akten leer. [S-Vertrag §8]
- [ ] Schalter „Einsatz-Prüfung läuft" bleibt aus, bis 4.4 Schritt 8.

### 4.7 Schalter AUS lassen

- [ ] `contract_check_companies` = leer
- [ ] `konto_notfall_weg_aktiv` = aus, `RECRUITING_KONTO_WEG4_AKTIV` = false, Feld „Code: Notfall-Weg" = leer
- [ ] `einsatz_pruefung_aktiv` = aus (bis 4.4 Schritt 8)
- [ ] `tax_class_per_company` = aus (bis Lohnbüro antwortet)
- [ ] Kein `--welle` im Zeitplan, kein Zeitplan-Eintrag für `nachweise-erinnern`
- [ ] Kein `portal-umstellen --alle`

### 4.8 Pilotgruppe einschalten

Voraussetzung: Sichttest am echten Gerät (iPhone + Android), `is_main_employer` im Lohn-Tracking, Arbeitgeber-Erklärtext freigegeben (die drei Auflagen im Docblock von `SwitchPortalVersion.php`).

- [ ] `php artisan recruiting:portal-umstellen --ids=<MA-IDs> --dry-run` → liest „Mitgenommen (gleiche Person): …" (ganze Menschen, RG+MA) und ggf. Zweifelsfälle (werden NICHT mitgenommen) und Warnung „Nachweise nur in den Altspalten" (dann zuerst `nachweise-umziehen`).
- [ ] `php artisan recruiting:portal-umstellen --ids=<MA-IDs> --ich-habe-den-sichttest-gemacht`
- [ ] `php artisan recruiting:konto-einladen --ids=<rec_persons.id> --dry-run` → ohne `--dry-run`; Code/Link steht NUR auf dem Bildschirm (nur Hash gespeichert) → sofort weitergeben. Nicht eingeladen werden: schon Konto, keine Nummer, Nummer `unavailable`, **kein Geburtsdatum**. Achtung: `--ids` hier = **Personen**, bei `portal-umstellen` = **Mitarbeiter**. [`KontoEinladen.php`]
- [ ] Erste scharfe Trigger-Welle erst nach genehmigten Vorlagen #6/#7 (Felder „Offene Aufgaben — neu/Erinnerung" gewählt) UND eingeschaltetem Schalter (`--ids=` ohne `--dry-run` respektiert ihn): `php artisan recruiting:einsatz-pruefung --ids=<MA-IDs> --dry-run`, dann `--ids=<MA-IDs> --welle=<n>`. Erst zählen, dann Welle. [P-Trigger Z. 1933, Spec §5]

### 4.9 MA-Vertragscheck einschalten (später, nicht am Deploy-Tag)

1. Trockenlauf auf der Demo mit aktuellem Prod-Abzug, „Gesellschaft MA" angehakt — **auf prod nicht „kurz einschalten"**, der Stundenlauf legt sonst sofort Fälle an.
2. Zahl k („k Fälle neu") an Sebastian/Markus.
3. Nach OK auf prod anhaken; nur Einsätze der nächsten 30 Tage; HR-geschlossene Fälle bleiben zu.
4. Ausschalten schließt offene Fälle NICHT. [S-Vertrag §8]

### 4.10 Notfallweg (später, getrennt, in dieser Reihenfolge)

MySQL-FOR-UPDATE-Check bestanden → HR-Leserhythmus für „Konto-Anträge" verabredet → Vorlage #3 genehmigt → Feld „Code: Notfall-Weg" wählen → `RECRUITING_KONTO_WEG4_AKTIV=true` → `config:cache` → Team-Haken. [S-HR §11.7, CFG-Kommentar `weg4_aktiv`]

### 4.11 Beobachten (erste Tage)

- [ ] Zeitplan-Läufe: `recruiting:einsatz-pruefung` (stündlich, `withoutOverlapping(30)`; bei Schalter aus Bericht „Einsatz-Prüfung ist aus … übersprungen"), `recruiting:konto-zuruecksetzen --faellig` (stündlich, ohne Anträge No-op). [SP]
- [ ] Log-Präfixe: `recruiting.aufgaben.*` (u. a. `vorlage_fehlt` (mit `grund`), `vorlage_ohne_link`, `vorlage_mehrere_knoepfe`, `kein_portal_token`, `abgelehnt`), `recruiting.dokumente.*` (`nicht_konfiguriert`, `vorlage_ohne_link`, `platzhalter_unbekannt`, `keine_nummer`), `recruiting.konto.*` (`einmalcode_versand`, `weg4_abgeschaltet`, `nummer_gewechselt`, `nummernwechsel_beantragt`, `anmeldung_mehrdeutig`, `contact_phone_sync_failed`), `[ProofReminderSender] Versand <status>`, `recruiting.einsatz_pruefung.schalter` (Umschalten), `rec_employee.updated.spiegel`. [Sender-Klassen, `RecEmployeeExportObserver`]
- [ ] HR-Schreibtisch: Anzahl neuer Fälle „Arbeitserlaubnis fehlt" gegen Trockenlauf-Zahl k.
- [ ] `updates.csv`-Volumen nach dem ersten ZAS-Abruf (Spiegel → beide Gesellschaften). [S-Spiegel §9, M:project_zas_update_export_vorfall]

---

## 5. ROLLBACK

| Was | Wie | Grenze |
|---|---|---|
| Code | meingedeck `composer.lock` auf `b102a0e2` zurück (Revert des Bump-Commits), push, Forge-Deploy, `view:clear`, `queue:restart` | Schema bleibt stehen; alle neuen Spalten nullable/zusätzlich → main-Code läuft weiter (S-HR §11 „Code zurück genügt"). Vorher auf der Demo geprobt (3.8). |
| Einzelne Personen vom neuen Portal | `php artisan recruiting:portal-umstellen --ids=<…> --zurueck` (keine Bestätigung nötig, zieht ganze Personen mit) oder `--alle --zurueck` | Daten aus dem neuen Portal bleiben in `rec_employee_proofs` |
| Versand | Vorlagen-Felder in den Einstellungen leeren (Mitarbeiterportal) bzw. Schalter „Einsatz-Prüfung läuft" aus | Kein `config:cache` nötig; bereits verschickte Nachrichten bleiben |
| Notfallweg | `RECRUITING_KONTO_WEG4_AKTIV=false` (Not-Aus für alle Teams) oder Team-Haken aus | laufende Anträge: `konto-zuruecksetzen --offen` / `--stopp=<id>` |
| Vertragscheck | Haken aus | offene Fälle schließt HR |
| Schema | `migrate:rollback` möglich (alle 16 haben `down()`) — **nicht empfohlen** | `rec_contracts`-down scheitert, sobald Verträge ohne Bewerbung existieren; `hr_desk_cases`-down lässt nullable stehen, wenn Fälle ohne Bewerber da sind; Drop von `rec_persons` löscht Konten + Passwörter; Drop `rec_documents*` verliert Zustellungen/Unterschriften |

**Nicht rückholbar:** verschickte WhatsApps; vom Spiegel geschriebene Geschwister-Felder (kein Undo, nur `personendaten-abgleich --nach`); von ZAS bereits abgeholte `updates.csv`-Zeilen; angelegte HR-Fälle (HR schließt); verbrauchte `rec_persons`-Kennungen; Unterschriften im Portal.

---

## 6. NACH DEM DEPLOY

### Sichttests je Paket

- [ ] **Nachweise**: Akte mit/ohne Nachweise, Proof-Inbox, Upload im neuen Portal (iPhone + Android). [S-Nachweise §11, M:project_nachweise_selbstbedienung]
- [ ] **Portal**: Pilotperson über alten WhatsApp-Link → Umleitung auf `/mitarbeiter/neu/…`; Live-Reaktivität der Arbeitgeber-Frage auf „nein". [`SwitchPortalVersion.php` Auflage 1]
- [ ] **Konto**: einladen → anlegen → abmelden → mit Nummer + Passwort anmelden; „Passwort vergessen" (sobald #1 genehmigt). [P-Konto Z. 718]
- [ ] **HR-Bereich**: Reiter mit Zahlen; je Liste eine Zeile; Akte mit/ohne Personen-Zeile; „Einladung neu" an Testperson, Registrierung mit dem Code. [S-HR §11.5]
- [ ] **Dokumente**: Akte → PDF ablegen → WhatsApp kommt → Portal zeigt Punkt → unterschreiben → Nachweisblatt öffnet. Upload-Grenze 12 MB. [S-Dok §12, M:project_dokumente_gegenzeichnen]
- [ ] **Vertrag aus der Akte**: Knopf-Sperre, Vorbelegung, Schreibtisch-Hinweis, Checkboxen. [M:project_vertrag_aus_akte_ma_check]
- [ ] **Einsatz-Trigger**: Einbuchung `status_id = 1` in 10 Tagen → `--dry-run` zeigt den Menschen mit offenen Punkten. [P-Trigger Z. 1932]
- [ ] **Spiegel**: Adresse in RG-Akte ändern → MA-Akte zieht mit, beide Marker gesetzt.
- [ ] **Altes Portal**: Token-Link allein zeigt keine Stammdaten (Fix `ec85cc58`).

### Bekannte Folgetickets

- „Nummer ändern in der Akte" (Reset sperrt Angreifer mit umgestellter Nummer nicht aus). [S-HR §12.6]
- Reiter „Erinnert": SQL-Vorfilter + 60-s-Cache. [S-HR §12.7]
- `SendProofReminders` vor einem Zeitplan-Eintrag in Blöcke teilen; X im ui-tailwind-Modal. [M:project_hr_bereich_mitarbeiter]
- WhatsApp-Einladung, sobald Einladungsvorlage genehmigt. [S-HR §12.1]
- ZAS-Export: „alles eingereicht" + „harte Sperre" (vorher Michel/Olaf ankündigen). [M:project_einsatz_trigger_und_zas_sperrfeld]
- ProofWriter/`PersonLinker::setzeNummer` schreiben ohne `zas_changed_at` → Ablaufdaten/Telefon erreichen ZAS nicht. [M:project_ma_konto_merge_risiken „Später"]
- `ZasInboundEmployeeImporter` legt Anstellungen ohne `rec_person_id` an → Gruppe „ohne Personen-Zeile" wächst nach; `personen-anlegen` ist wiederholbar. [M:project_konto_anmeldeschicht_gate_d, `EinsatzPruefung.php` F6]
- Stammdaten nach `rec_persons` umziehen (semantisch sauber) — eigenes Paket nach dem Pilot. [M:project_personendaten_spiegeln]
- Auto-Antwort „bitte über das Portal hochladen", ZAS-Sperrspalte. [M:project_markus_info_dokument_offene_punkte]
- `RecEmployee::sendPortalNotification` stempelt Meta-`failed` als Erfolg. [M:project_ma_portal_locked_fix]
- `herkunft` in FillContractFieldsTool/saveContractFields sperren (optional). [M:project_vertrag_aus_akte_ma_check]

---

## 7. RISIKEN & HERAUSFORDERUNGEN

| # | Risiko | Gegenmittel | Quelle |
|---|---|---|---|
| 1 | **Stundenlauf `einsatz-pruefung` legt nach dem Einschalten sofort HR-Fälle an**, vor `nachweise-umziehen` falsche (Tabelle leer), ungedeckelt | Schalter „Einsatz-Prüfung läuft" bleibt aus (Vorgabe). Reihenfolge: `nachweise-umziehen` → `personen-anlegen` → `einsatz-pruefung --dry-run` → Zahlen melden → Schalter an (4.4 Schritt 8); HR vorwarnen | Spec §5, M:project_ma_konto_merge_risiken Nr. 1, SP |
| 2 | **Vertragscheck blind eingeschaltet** → Hunderte HR-Fälle (ZAS-Bestand hat meist keinen AV) | Vorgabe `[]`, Trockenlauf auf Demo mit Prod-Abzug, Zahl melden, dann einschalten | SET-Kommentar, S-Vertrag §8 |
| 3 | **Fristenlauf ohne Stichtag** → >500 WhatsApps (~540 abgelaufene Nachweise) auf einen Schlag | Kein Zeitplan-Eintrag; Lauf bricht ohne `--stichtag` ab; `--ohne-stichtag` nur bewusst; `--limit` | `SendProofReminders.php`, SP |
| 4 | **Code vor Schema**: Forge reißt im npm-Teil → HR-Desk, Akte, Dispo-Import, Stundenjobs knallen | Deploy-Log prüfen, sonst `migrate --force` + `view:clear` sofort von Hand | M:reference_forge_deploy_skript_npm, M:project_ma_konto_merge_risiken |
| 5 | **`personen-anlegen` vergessen** → Konto, HR-Bereich, Trigger-Nachricht wirkungslos | Fester Schritt 4.4.2, Zähler `rec_person_id IS NULL`; Schalter erst danach an | M:project_konto_anmeldeschicht_gate_d |
| 6 | **Code-Vorlagen-Kategorie**: AUTHENTICATION passt nicht zum Sender (nur Body, kein Knopf) | Als UTILITY mit benanntem `{{code}}` einreichen; bei Meta-Ablehnung Sender-Änderung nötig | `EinmalcodeSender.php`, S-Konto §10 |
| 7 | **Ungeeignete Vorlage gewählt** (unbekannter Platzhalter, Code-Vorlage ohne `{{code}}`, Knopf mit Variable bei Code-/Hinweis-Vorlage) → kein Versand | Platzhalter werden aus der Vorlage gelesen; Tabelle 2.1 einhalten; Log `recruiting.aufgaben.vorlage_fehlt` / `grund` beobachten | Spec §3 |
| 8 | **Personen-Spiegel + offene Marker**: ZAS-Korrektur bei offenem `zas_changed_at` geht verloren; beide Gesellschaften in `updates.csv` | Michel-Ankündigung, Volumen nach erstem Abruf prüfen; Entscheidung Schutz-Einschränkung offen | S-Spiegel §9, M:project_personendaten_spiegeln |
| 9 | **Nachweis-Block rot für alle** (Ausweis Pflicht; ~1320/1603 ohne) → HR-Rückfragen | Entscheidung Block-Gate oder Vorwarnung | M:project_ma_konto_merge_risiken Nr. 2, M:project_personen_klammer_stufe1 |
| 10 | **Laufende Sitzungen/Legacy**: altes und neues Portal teilen den Sitzungsschlüssel; alte Links führen ins alte Portal | Weiche im alten Portal; `portal-umstellen` nimmt ganze Personen mit; `sitzungen_ungueltig_ab` beendet Sitzungen bei Reset | `EmployeePortal.php`, `SwitchPortalVersion.php`, S-HR §12.5 |
| 11 | **Hotfix altes Portal separat auf main** → kein ff mehr | Mit dem Merge ausrollen oder danach main in den Zweig mergen | M:project_hr_bereich_mitarbeiter |
| 12 | **60-s-Caches**: Reiter-Zähler je Team 60 s gecacht → Zahlen hinken nach | Bei Sichttest 1 Minute warten; HR-Aktionen leeren den Cache | S-HR §13 |
| 13 | **Öffentliche Seite `/konto`**: Route-Throttle nutzt `$request->ip()` hinter `trustProxies('*')` (umgehbar); PortalAuth-Bremse liest REMOTE_ADDR | Versand fail-closed; Proxy-Einengung in meingedeck entscheiden; CDN klären | M:project_ma_konto_merge_risiken Nr. 5, M:project_konto_anmeldeschicht_gate_d |
| 14 | **Einladungscode nur auf dem Bildschirm** (nur Hash in DB) | Sofort weitergeben; erneuter Lauf erneuert die Einladung | `KontoEinladen.php` |
| 15 | **Konto-Zurücksetzen ändert die Nummer nicht** → Fremder mit umgestellter Nummer kommt über Weg 3 wieder rein | Erst `konto-zuruecksetzen --person=<id> --nummer=<neu>`; Folgeticket | S-HR §12.6 |
| 16 | **Demo-Pin** wird durch `update.sh` lautlos auf main zurückgedreht | `update.sh` nie; nur Lock-Zeiger | M:project_einsatz_trigger_und_zas_sperrfeld |
| 17 | **Doppelversand Dokumente** bei mehreren Queue-Workern | Ein Worker; beobachten | M:project_dokumente_gegenzeichnen |
| 18 | **Mitlaufende main-Deploys** (Nicht zugestellt, Versand vormerken, …) mit eigenen migrate/queue/Settings-Schritten | Vorab-Abgleich 2.4 | MEMORY.md |

**Zeitplan-Einträge, die direkt nach dem Deploy laufen** (SP): `recruiting:einsatz-pruefung` stündlich (bei Vorgabe-Schalter aus = No-op; nach dem Einschalten prüft + legt HR-Fälle an, versendet ohne `--welle` nichts) · `recruiting:konto-zuruecksetzen --faellig` stündlich (ohne Anträge No-op). `recruiting:nachweise-erinnern` hat bewusst keinen Eintrag.
