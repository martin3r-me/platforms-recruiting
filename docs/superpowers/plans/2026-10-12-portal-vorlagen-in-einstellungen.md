# Portal-Vorlagen in die Einstellungen + Schalter Einsatz-Prüfung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die sechs `.env`-Vorlagen (Konto-Codes, Hinweis alte Nummer, Aufgaben neu/Erinnerung) werden Team-Einstellungen im Bewerber-Einstellungsfenster. Die Fristenlauf-Vorlage bekommt ein Feld. Die stündliche Einsatz-Prüfung bekommt einen Schalter (Vorgabe aus).

**Architecture:**
- Die Schlüssel-Konstanten und kleine Vorlagen-Helfer liegen in einer neuen Klasse `Support/PortalVorlagen`.
- Die drei Sender lösen die Vorlage über `HoldingTemplateSender::resolveTarget($teamId, $schluessel)` auf, wie DokumentHinweisSender. Platzhalter liest jeder Sender aus dem BODY der Vorlage.
- Die Chat-Schwärzung erkennt Code-Vorlagen strukturell (`{{code}}` im Body), dazu über die gesetzten Einstellungen und über die Alt-Liste.
- Die Config-Blöcke fallen weg.

**Tech Stack:** Laravel 12, Livewire 3, PHPUnit (Integration: Capsule + SQLite). Runner: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modul-Root.

**Spec:** `docs/superpowers/specs/2026-10-12-portal-vorlagen-in-einstellungen-design.md`

## Global Constraints

- Arbeitsverzeichnis: `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal`, Branch `feat/ma-konto`. Kein Edit außerhalb dieses Moduls (kein ui-tailwind, core, crm, meingedeck).
- **Einstellungs-Schlüssel, wörtlich:**
  - `konto_code_passwort_wa_template_id`
  - `konto_code_nummernwechsel_wa_template_id`
  - `konto_code_notfall_wa_template_id`
  - `konto_hinweis_alte_nummer_wa_template_id`
  - `aufgaben_neu_wa_template_id`
  - `aufgaben_erinnerung_wa_template_id`
  - `einsatz_pruefung_aktiv` (bool, Vorgabe `false`)
  - Bestehend: `proof_reminder_wa_template_id`, `document_wa_template_id`, `employee_contract_wa_template_id`.
- **Keine Migration.** Die Defaults stehen in `RecApplicantSettings::DEFAULT_SETTINGS`.
- **Kein Rückfall auf `.env`/Config** für Vorlagen.
- **Was in `config/recruiting.php` bleibt:** `konto.code_vorlagen_alt`, `konto.pepper` und `konto.weg4_aktiv`.
- **Kein Versand bei Zweifel:** keine Vorlage, nicht genehmigt, unbekannter Platzhalter, Code ohne `{{code}}`, Code-/Hinweis-Vorlage mit parametrisiertem Knopf. In jedem dieser Fälle gibt es eine Logzeile mit dem Grund. Der Grund nennt „Einstellungen → Kommunikation → Mitarbeiterportal".
- **Drossel und Reihenfolge im EinmalcodeSender bleiben unverändert:** erst Vorlage und Kanal prüfen, dann drosseln, dann `erzeugeCode`.
- **Blade-Hausregeln:**
  - kein inline `@if` in Komponenten-Attributen;
  - keine an Wortzeichen geklebten Direktiven;
  - `@php` nur in Block-Form;
  - in Blade-Kommentaren kein Direktiven-Wort für PHP-Blöcke.
  - Prüfen mit `php tools/blade-check.php <datei>`.
- **Texte in der UI auf Deutsch,** mit echten Umlauten in der UI. Die Code-Kommentare folgen dem Stil der Datei.
- **Commit-Trailer:**
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
  ```
- **Tests vor jedem Commit:** die betroffenen Testdateien grün. Am Task-Ende die komplette Suite grün (Stand vorher: 4323 Tests grün).

## Review Focus

1. **Alte Code-Nachrichten im Chat bleiben geschwärzt,** auch wenn kein Team ein Code-Feld gesetzt hat. Der strukturelle `{{code}}`-Weg trägt das. Test in Task 5.
2. **Vorlage mit Platzhalter in Großschreibung** (`{{Code}}`, `{{Vorname}}`) wird wie heute akzeptiert. Der Parametername geht klein an Meta. Tests in Tasks 2–4.
3. **Team A hat die Prüfung an, Team B aus:** Ein Lauf ohne `--team` bearbeitet nur A. Test in Task 6.
4. **Fenster speichern, ohne die Portal-Felder anzufassen,** lässt die vorhandenen IDs stehen. Leere Auswahl wird `null`, nicht `""`. Test in Task 7.
5. **Gewählte Vorlage wurde später bei Meta abgelehnt** (Status ≠ APPROVED): kein Versand, Grund im Log. Test in Tasks 2–4.

---

### Task 1: Schlüssel, Defaults, Helfer `PortalVorlagen`

**Files:**
- Create: `src/Support/PortalVorlagen.php`
- Modify: `src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS)
- Test: `tests/Unit/PortalVorlagenTest.php`

**Produces:**

```php
final class PortalVorlagen
{
    public const CODE_PASSWORT        = 'konto_code_passwort_wa_template_id';
    public const CODE_NUMMERNWECHSEL  = 'konto_code_nummernwechsel_wa_template_id';
    public const CODE_NOTFALL         = 'konto_code_notfall_wa_template_id';
    public const HINWEIS_ALTE_NUMMER  = 'konto_hinweis_alte_nummer_wa_template_id';
    public const AUFGABEN_NEU         = 'aufgaben_neu_wa_template_id';
    public const AUFGABEN_ERINNERUNG  = 'aufgaben_erinnerung_wa_template_id';
    public const EINSATZ_PRUEFUNG_AKTIV = 'einsatz_pruefung_aktiv';

    /** Zweck (KontoWriter::ZWECK_*) → Schluessel. 'anmeldung' fehlt bewusst (wird nirgends verschickt). */
    public const CODE_SCHLUESSEL = [
        'passwort'       => self::CODE_PASSWORT,
        'nummernwechsel' => self::CODE_NUMMERNWECHSEL,
        'notfall'        => self::CODE_NOTFALL,
    ];

    /** Anlass → Schluessel. */
    public const AUFGABEN_SCHLUESSEL = ['neu' => self::AUFGABEN_NEU, 'erinnerung' => self::AUFGABEN_ERINNERUNG];

    /** Wo HR das Feld findet — Teil jeder Fehlermeldung. */
    public const ORT = 'Einstellungen → Kommunikation → Mitarbeiterportal';

    /** @return list<string> alle {{name}} im BODY in Fundreihenfolge, ohne Doppelte, Schreibweise wie in der Vorlage */
    public static function rumpfPlatzhalter(array $components): array;

    /** true, wenn ein Knopf beim Senden einen Parameter braucht: URL-Knopf mit {{ in der URL, oder Typ OTP / COPY_CODE. */
    public static function hatKnopfMitParameter(array $components): bool;

    /** Team-Schalter Einsatz-Pruefung, strikt bool (true, 1, "1", "true" → an; alles andere aus). */
    public static function einsatzPruefungAktiv(int $teamId): bool;
}
```

- `rumpfPlatzhalter`: wie `DokumentHinweisSender::rumpfPlatzhalter` (`/\{\{(\w+)\}\}/` über alle Teile mit `type === 'BODY'`).
- `hatKnopfMitParameter`:
  - Ist `WhatsAppTemplateUrlButtons::dynamicIndexes($components) !== []` → true.
  - Ein BUTTONS-Eintrag mit `type` (groß/klein egal) `OTP` oder `COPY_CODE` → true.
- `einsatzPruefungAktiv`: liest über `RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(...)`. Die strikte Bool-Auslegung ist wie `HrEinstellungen::alsBool` (gleiche Klasse wiederverwenden, falls öffentlich, sonst gleiche Logik).

- [ ] **Step 1: Failing Unit-Tests schreiben**
  - `rumpfPlatzhalter`: Reihenfolge, Doppelte, ignoriert HEADER/FOOTER.
  - `hatKnopfMitParameter`: statischer URL → false; URL mit `{{1}}` → true; COPY_CODE → true; QUICK_REPLY → false; keine Knöpfe → false.
- [ ] **Step 2:** Laufen lassen, rot.
- [ ] **Step 3:** Klasse implementieren. In `DEFAULT_SETTINGS` die sechs ID-Schlüssel mit `null` und `einsatz_pruefung_aktiv => false` ergänzen, mit kurzem Kommentar wie bei den Nachbarn.
- [ ] **Step 4:** Ein Integrationstest (falls Unit ohne DB nicht geht) für `einsatzPruefungAktiv` prüft: Default false, `"1"` → true, `"nein"` → false.
- [ ] **Step 5:** Tests grün, Commit `feat(recruiting): Portal-Vorlagen — Schluessel + Helfer`.

### Task 2: EinmalcodeSender liest die Vorlage aus der Einstellung

**Files:**
- Modify: `src/Services/Comms/EinmalcodeSender.php` (`vorlage()`, `kanal()`, Docblocks)
- Test: `tests/Integration/EinmalcodeSenderTest.php` (Setup umstellen: statt `config()->set(code_vorlagen…)` eine `IntegrationsWhatsAppTemplate`-Zeile anlegen und ihre ID in die Team-Einstellung schreiben. Muster: `tests/Integration/DokumentHinweisSenderTest.php`)

**Consumes:** `PortalVorlagen::CODE_SCHLUESSEL`, `rumpfPlatzhalter`, `hatKnopfMitParameter`, `ORT`.

**Verhalten:**
- `vorlage(string $zweck, int $teamId)` holt `$schluessel = PortalVorlagen::CODE_SCHLUESSEL[$zweck] ?? null`.
  - `null` → Fehler „Fuer den Zweck "{$zweck}" gibt es keine Vorlagen-Einstellung — es wird nichts verschickt."
  - Sonst `app(HoldingTemplateSender::class)->resolveTarget($teamId, $schluessel)`. Ein Fehler wird zu „Keine genehmigte Vorlage unter {ORT} → {Feldname} ({$ziel['error']})".
- Name = `$template->name`, Sprache = `$template->language ?: 'de'`, Platzhalter = `rumpfPlatzhalter($template->components)`.
- Bestehende Prüfungen bleiben auf diese Liste angewandt: unbefüllbare Platzhalter, `{{code}}` Pflicht (klein verglichen). Neu: `hatKnopfMitParameter` → Fehler „Die Vorlage hat einen Knopf mit Variable — den befuellen wir nicht, Meta wuerde ablehnen."
- Der Kanal kommt aus `$ziel['channel']`. `kanal()` und der `RecruitingChannelResolver`-Import fallen weg, falls unbenutzt.
- Die Reihenfolge in `sende()` bleibt: Person → Nummer → Vorlage+Kanal → Drossel → erzeugeCode → Versand.
- `EinmalcodeVorlagen::KONFIG` wird hier nicht mehr gelesen. Die Konstante entfernt Task 5.
- Die Anforderungs-Kommentare aus der Config (benannte Platzhalter, UTILITY ohne AUTH-Knopf) wandern in den Klassen-Docblock.

- [ ] **Step 1:** Testsetup auf Einstellung + Vorlagen-Zeile umstellen.
  - Neue Tests: Feld leer → kein Versand + Log nennt `Mitarbeiterportal`; Vorlage `REJECTED` → kein Versand; Vorlage mit COPY_CODE-Knopf → kein Versand; `{{Code}}` großgeschrieben → Versand mit `parameter_name` `code`; Kanal = Kanal am Account der Vorlage.
  - Alte Tests, die die Config-Form prüfen (um Z. 651), werden entfernt bzw. in Task 5 durch den Config-Wächter ersetzt.
- [ ] **Step 2:** Rot.
- [ ] **Step 3:** Implementieren.
- [ ] **Step 4:** `EinmalcodeSenderTest`, `KontoAnmelden*`, `KontoZuruecksetzenTest` und alle Tests, die `EinmalcodeSender` real benutzen, grün. Deren Setups ebenso umstellen.
- [ ] **Step 5:** Commit `feat(recruiting): Einmalcode-Vorlage aus den Einstellungen`.

### Task 3: NummernwechselHinweisSender liest die Vorlage aus der Einstellung

**Files:**
- Modify: `src/Services/Comms/NummernwechselHinweisSender.php`
- Test: der vorhandene Test dieses Senders (`grep -rl NummernwechselHinweisSender tests`) und `KontoZuruecksetzenTest`

**Verhalten:**
- Schlüssel `PortalVorlagen::HINWEIS_ALTE_NUMMER`, Auflösung über `resolveTarget`, Kanal aus dem Ziel.
- Erlaubte Platzhalter nur die NAME_PLATZHALTER. Eine Vorlage ohne Platzhalter → `components = []` (wie heute). `hatKnopfMitParameter` → kein Versand.
- `KONFIG` wird entfernt. Die Prüfung „alte = neue Nummer" bleibt vor der Vorlage.

- [ ] Steps wie Task 2. Tests:
  - gesetzt → Versand mit Vorname;
  - leer → kein Versand + Log `Mitarbeiterportal`;
  - unbekannter Platzhalter → kein Versand;
  - Knopf mit Variable → kein Versand;
  - nicht genehmigt → kein Versand.
- Commit `feat(recruiting): Hinweis an die alte Nummer aus den Einstellungen`.

### Task 4: AufgabenSender liest die Vorlage aus der Einstellung

**Files:**
- Modify: `src/Services/Comms/AufgabenSender.php` (`sende()`; `vorlageNachschlagen()` entfällt)
- Test: `tests/Integration/AufgabenSenderTest.php` und `EinsatzPruefungTest.php`, falls diese Config setzen

**Verhalten:**
- `$schluessel = PortalVorlagen::AUFGABEN_SCHLUESSEL[$anlass] ?? null`. Unbekannter Anlass → `STATUS_NICHT_KONFIGURIERT` + Log `recruiting.aufgaben.vorlage_fehlt`.
- `resolveTarget` liefert einen Fehler → `STATUS_NICHT_KONFIGURIERT`, Log `recruiting.aufgaben.vorlage_fehlt` mit `anlass`, `mitarbeiter`, `grund` (Fehlertext + ORT).
- Platzhalter = `rumpfPlatzhalter`. Werte: `anzahl`, `datum` und zusätzlich der Vorname für NAME_PLATZHALTER (`name`, `vorname`, `1`) aus `$employee->first_name`. Ein leerer Vorname gilt als unbefüllbar → FAILED wie bei den anderen.
- Unbekannter Platzhalter → wie heute `recruiting.aufgaben.platzhalter_unbefuellbar` → FAILED. Groß/klein egal, `parameter_name` klein.
- Die Reihenfolge der übrigen Prüfungen bleibt: 0 Punkte, Nummer, URL-Knopf-Pflicht, Token, eindeutiger Knopf, Versand, failed-Blick, `letzteNachrichtId`.
- Kanal aus dem Ziel, `kanal()` entfällt, falls unbenutzt.
- Die vier Nachschlag-Logzeilen (`vorlage_fehlt_ganz`, …) entfallen. Ihr Zweck steckt jetzt im `resolveTarget`-Fehler. Die Checkliste (Task 8) nennt sie nicht mehr.

- [ ] Steps wie Task 2. Tests:
  - neu mit `{{anzahl}}` → Versand;
  - erinnerung mit `{{anzahl}}` + `{{datum}}` → Datum `d.m.Y`;
  - `{{Vorname}}` → Vorname;
  - leer → `nicht_konfiguriert`;
  - REJECTED → `nicht_konfiguriert`;
  - unbekannter Platzhalter → failed.
- Commit `feat(recruiting): Aufgaben-Vorlagen aus den Einstellungen`.

### Task 5: Schwärzung strukturell + Config aufräumen

**Files:**
- Modify: `src/Support/EinmalcodeVorlagen.php`, `src/Services/Zas/Dispo/DispoThreadDirectory.php` (Aufruf Z. ~315), `config/recruiting.php`
- Test: `tests/Integration/DispoThreadDirectoryTest.php` (Schwärzungs-Tests um Z. 489/534) und ein neuer Config-Wächter in `tests/Unit/` oder bei den vorhandenen Config-Tests

**Verhalten:**
- Neue Signatur `EinmalcodeVorlagen::istCodeVorlage(?string $name, mixed $definition = null): bool`. True, wenn eins zutrifft:
  1. **Struktur:** In `$definition` steht ein BODY mit `{{code}}`, Groß/Klein egal. Die Definition kommt so, wie `DispoThreadDirectory` sie an `WhatsAppTemplateRenderer::render` gibt. Format im Code nachsehen, bevor Annahmen getroffen werden.
  2. **Einstellung:** Der Name ist in irgendeinem Team in einem der drei `CODE_SCHLUESSEL` gesetzt (Template-ID → Name). Das wird einmal je Prozess/Anfrage in einer statischen Variable gehalten, mit `vergiss()`-Methode für Tests.
  3. **Alt-Liste:** Der Name steht in `KONFIG_ALT`.
- `namen()` liefert Weg 2 + 3. `KONFIG` wird entfernt.
- Der Aufruf in `DispoThreadDirectory` gibt `$definition` mit.
- `config/recruiting.php`:
  - `konto.code_vorlagen`, `konto.hinweis_vorlage` und der ganze `aufgaben`-Block fallen weg, ebenso ihre Kommentare.
  - Der Kommentar zu `weg4_aktiv` Punkt 2 sagt jetzt „Code-Vorlage Notfall in {ORT} gesetzt".
  - Der Kommentar zu `code_vorlagen_alt` wird angepasst: „Wer die Code-Vorlage in den Einstellungen gegen eine andere tauscht, traegt den ALTEN Namen hier ein" (Weg 1 deckt das meist schon ab, solange die Definition existiert).
- `RecruitingServiceProvider` Z. ~324: Der Kommentar zu `recruiting.aufgaben.vorlagen` wird auf die Einstellungen umgestellt.
- **Config-Wächter-Test:** `config('recruiting.konto.code_vorlagen')`, `config('recruiting.konto.hinweis_vorlage')` und `config('recruiting.aufgaben')` sind `null`. Die Datei `config/recruiting.php` enthält weder `RECRUITING_KONTO_VORLAGE_` noch `RECRUITING_AUFGABEN_VORLAGE_`.

- [ ] Tests:
  - Code-Nachricht mit Definition `{{code}}` und ohne Einstellung → geschwärzt;
  - Name per Team-Einstellung, Definition fehlt → geschwärzt;
  - Alt-Name → geschwärzt;
  - normale Vorlage → Klartext.
- [ ] Danach `grep -rn "code_vorlagen\b\|hinweis_vorlage\|aufgaben.vorlagen\|EinmalcodeVorlagen::KONFIG\b" src tests config` → keine Treffer mehr, außer `code_vorlagen_alt` / `KONFIG_ALT`.
- [ ] Volle Suite grün. Commit `refactor(recruiting): Code-Schwaerzung strukturell, Vorlagen-Config entfernt`.

### Task 6: Schalter Einsatz-Prüfung

**Files:**
- Modify: `src/Console/Commands/EinsatzPruefung.php`, `src/RecruitingServiceProvider.php` (nur Kommentar am Zeitplan-Eintrag)
- Test: `tests/Integration/EinsatzPruefungTest.php` (vorhandene Tests setzen den Schalter im Setup auf an, damit sie unverändert weiterlaufen)

**Verhalten:**
- Ohne `--dry-run` werden Kandidaten nach Team gefiltert: Nur Teams mit `PortalVorlagen::einsatzPruefungAktiv($teamId)` werden bearbeitet. Das gilt für alle Pfade, also HR-Fälle, Signatur-Räumen, Vertragsprüfung und Versand. Die Filterung passiert so früh, dass für ausgeschaltete Teams nichts geschrieben wird. Der Ort steht im Code: `kandidaten()`/`gruppen()` und `lauf()`.
- Je übersprungenes Team gibt es eine Berichtszeile: `Team {id}: Einsatz-Pruefung ist aus (Einstellungen → Kommunikation → Mitarbeiterportal) — uebersprungen`.
- `--dry-run` ignoriert den Schalter. Im Bericht steht dann je ausgeschaltetes Team zusätzlich `Team {id}: Schalter aus — im scharfen Lauf uebersprungen`.
- `--ids` ohne `--dry-run` respektiert den Schalter.
- Der Zeitplan-Kommentar ergänzt, dass der Lauf ohne Schalter ein No-op ist.

- [ ] Tests:
  - aus → keine HR-Fälle, Berichtszeile;
  - aus + `--dry-run` → Zahlen wie bei an, Hinweiszeile;
  - aus + `--ids` → übersprungen;
  - zwei Teams, eins an → nur dort Fälle.
- [ ] `EinsatzPruefungTest` + `EinsatzPruefungVertragTest` grün. Commit `feat(recruiting): Schalter Einsatz-Pruefung je Team (Vorgabe aus)`.

### Task 7: Sektion „Mitarbeiterportal" im Einstellungsfenster

**Files:**
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php`, `src/Livewire/Applicant/ApplicantSettingsModal.php`
- Test: neu `tests/Integration/SettingsModalPortalVorlagenTest.php` (Muster `SettingsModalToggleWriteTest.php`)

**Verhalten:**
- Die Selects „Dokumente" (`document_wa_template_id`, mit Hilfetext) und „Vertrag zur Unterschrift" (`employee_contract_wa_template_id`) ziehen aus `general` heraus. Das Zertifikat-Select und sein Hilfeblock bleiben unberührt.
- Im Reiter `comms` kommt am Ende eine Sektion im Stil der vorhandenen comms-Sektionen (h3 + Erklärsatz):
  - Überschrift „Mitarbeiterportal"
  - Erklärsatz: „WhatsApp-Vorlagen und Schalter für das neue Mitarbeiterportal. Ohne gewählte Vorlage geht die jeweilige Nachricht nicht raus — der Grund steht im Log."
- In dieser Reihenfolge, jeweils `x-ui-input-select` im Muster der vorhandenen Felder (`:value`, `name`, `:options="$this->availableWhatsAppTemplates"`, `optionValue="id"`, `optionLabel="label"`, `:nullable="true"`, `wire:model.live`), mit einem Hilfesatz darunter. Platzhalter im Hilfetext als `&#123;&#123;code&#125;&#125;` usw., wie in der vorhandenen Datei:

  | Feld | Hilfetext |
  |---|---|
  | „Code: Passwort vergessen" | „Vorlage mit &#123;&#123;code&#125;&#125; (Pflicht), optional Vorname. Kein Knopf mit Variable. Kategorie UTILITY." |
  | „Code: neue Nummer bestätigen" | gleicher Hilfetext |
  | „Code: Notfall-Weg" | gleicher Hilfetext + „Geht nur raus, wenn der Notfall-Weg freigeschaltet ist." |
  | „Hinweis an die alte Nummer" | „Ohne Platzhalter oder nur mit Vorname. Nennt die neue Nummer bewusst nicht." |
  | „Offene Aufgaben — neu" | „&#123;&#123;anzahl&#125;&#125;, optional Vorname, und ein URL-Knopf, dessen Variable am Ende steht." |
  | „Offene Aufgaben — Erinnerung" | „&#123;&#123;anzahl&#125;&#125; und &#123;&#123;datum&#125;&#125;, optional Vorname, URL-Knopf wie oben." |
  | „Nachweis läuft ab (Fristenlauf)" (`proof_reminder_wa_template_id`) | Anforderungen aus `ProofReminderSender` lesen und in einem Satz nennen |
  | „Dokument liegt im Portal" | bisheriger Hilfetext |
  | „Vertrag zur Unterschrift" | bisheriger Hilfetext, nullLabel „– wie Dokumente –" |

  - Alle anderen haben nullLabel „– Template wählen –".
  - Ist `availableWhatsAppTemplates` leer, wird statt der Selects ein Satz angezeigt: „Keine genehmigten WhatsApp-Vorlagen gefunden."
- Darunter eine Checkbox im Muster der vorhandenen Schalter (`wire:model="settings.einsatz_pruefung_aktiv"`):
  - Titel „Einsatz-Prüfung läuft"
  - Text: „Stündliche Prüfung der kommenden Einsätze: legt HR-Fälle an (z. B. Arbeitserlaubnis fehlt) und bereitet die Aufgaben-Nachrichten vor. Erst einschalten, wenn der Nachweis-Umzug und der Trockenlauf erledigt sind."
- `save()`:
  - Die neun ID-Schlüssel werden normalisiert: leer/`''`/`'0'` → `null`, sonst `(int)`.
  - `einsatz_pruefung_aktiv` wird strikt bool.
  - Kippt der Schalter, kommt `Log::notice('recruiting.einsatz_pruefung.schalter', ['team_id','user_id','an'])` nach dem Muster des Notfall-Wegs.
- **Select-Save-Fehler:** Der Test ruft `set('settings.<schluessel>', <id>)` → `save()` auf und prüft den gespeicherten Wert in der DB. Ein frischer `mount()` muss ihn wieder in `settings` haben. Zeigt der Test einen Verlust, wird die Ursache im Modul behoben, nicht in ui-tailwind.
- `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php` grün. `BladeCompileIntegrityTest`, `SettingsModalCertificateToggleTest` und `SettingsModalToggleWriteTest` bleiben grün.

- [ ] Tests:
  - alle neun IDs + Schalter speichern und wieder laden;
  - `''` → `null`;
  - Schalter-Kippen → Logzeile, kein Kippen → keine;
  - Rendern des comms-Reiters enthält „Mitarbeiterportal" und alle neun `name="settings.…"`;
  - general-Reiter enthält `settings.document_wa_template_id` nicht mehr.
- [ ] Commit `feat(recruiting): Einstellungen — Sektion Mitarbeiterportal (Vorlagen + Schalter Einsatz-Pruefung)`.

### Task 8: Deploy-Checkliste nachziehen

**Files:**
- Modify: `docs/meingedeck/deploy-checkliste-ma-konto.md`

**Änderungen:**
- Vorlagen-Tabelle:
  - Die Spalte „Textvorschlag Sie / du" wird zu „Textvorschlag (du)". Die Sie-Texte werden gestrichen.
  - „Eintragen in" zeigt bei allen zehn Vorlagen „Einstellungen → Kommunikation → Mitarbeiterportal → <Feldname>".
  - #4 (Code Anmeldung) wird gestrichen; Hinweis: wird nicht verschickt, kein Feld.
  - Die Platzhalter-Spalte heißt „Body-Platzhalter (erlaubt)" und folgt der Spec §2.
- Der CFG-Hinweis „Platzhalterliste steht fest im Code" wird gestrichen. Neu ist der Hinweis „Platzhalter werden aus der Vorlage gelesen; unbekannte verhindern den Versand".
- Abschnitt 2.2 (`.env`-Schlüssel) entfällt. Bleiben nur `RECRUITING_KONTO_WEG4_AKTIV` (aus lassen) und `RECRUITING_KONTO_PEPPER` (optional), falls dort genannt.
- `config:cache` wird nur erwähnt, wo es noch für andere Schlüssel nötig ist.
- **Entscheidung „Zeitplan einsatz-pruefung" und Risiko 1** werden ersetzt durch:
  - „Schalter Einsatz-Prüfung bleibt aus (Vorgabe). Reihenfolge: `nachweise-umziehen` → `personen-anlegen` → `einsatz-pruefung --dry-run` → Zahlen melden → Schalter an."
  - Dazu ein neuer Schritt im Deploy-Ablauf: „Schalter an" mit Haken.
- Rollback „Versand" wird zu „Vorlagen-Felder in den Einstellungen leeren bzw. Schalter aus".
- Der Folgepunkt „Alle WhatsApp-Vorlagen in die Webapp-Einstellungen" wird gestrichen (erledigt).
- Commit `docs(recruiting): Deploy-Checkliste — Vorlagen in den Einstellungen, nur du, Schalter Einsatz-Pruefung`.
