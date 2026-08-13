# Zertifikat-Link als dynamischer URL-Button — Design

**Datum:** 2026-08-13
**Modul:** platforms-recruiting
**Branch:** `feat/zertifikat-wa-button`, von main `9382981`
**Status:** **Gate 1 (Spec-Review) bestanden am 2026-08-13**, Korrekturen aus dem
Review eingearbeitet — siehe §Entscheidungen aus dem Review. Keine offenen Fragen.
Alle Code-Referenzen gegen main **`9382981`** nachgemessen, nicht aus der Vorgänger-Spec übernommen.

> **Setzt F1 aus `docs/zertifikat/folgeliste.md` um und korrigiert G8 und §D4 der
> Spec `2026-08-11-schulungszertifikat-html-design.md`.** Was von dort gilt und
> was fällt, steht namentlich in §Änderungen an der alten Spec. Das
> Zertifikat-Paket selbst ist gemerged und live — dies ist eine Änderung am
> Bestand, kein Neubau.

---

## Problem

Der WhatsApp-Versand des Zertifikat-Links (Weg (a), Ablehnung am
HR-Schreibtisch) schickt die vollständige PDF-URL als **Body-Variable**
`{{zertifikat_link}}` im Nachrichtentext. Der Bewerber bekommt damit eine
Nachricht, in der eine Zeile wie
`https://…/recruiting/zertifikat/9f2c…-…-…` mitten im Fließtext steht — bei
einer UUID eine lange, unlesbare Zeile. Das ist als Preis in §D4 der alten Spec
benannt und war dort bewusst akzeptiert.

**Die Begründung für diese Akzeptanz war falsch, und das ist der eigentliche
Anlass.** §D4 und G8 stützten sich darauf, dass der Button-Weg neuer,
ungebauter Code sei — gefunden worden war nur `Applicant/Show.php`. Ein
breiterer Grep zeigt **sechs** Sendestellen, die Button-Komponenten bauen, und
**sieben** Stellen, die URL-Buttons erkennen (H1). Der Body-Variablen-Weg ist im
Modul die **Ausnahme**, nicht die Regel; der Button-Weg ist das eingefahrene
Muster. Die Entscheidung „Body jetzt, Button später" beruhte auf einer zu engen
Suche.

## Ziel

1. Der Zertifikat-Link geht als **dynamischer URL-Button** raus, nicht im
   Fließtext.
2. Der Versand läuft über einen **eigenen Sendepfad** mit direktem
   `WhatsAppMetaService::sendTemplate()`, nach dem Muster der sechs bestehenden
   Stellen.
3. Der Guard aus Task 12 **wandert mit** und prüft künftig die Button-Struktur
   statt der Body-Variable.
4. Der Body-Weg wird **ersetzt**, nicht als Fallback behalten.

## Nicht-Ziel (ausdrücklich)

- **`HoldingTemplateComponents` wird nicht erweitert.** Kein Button-Zweig, keine
  neue Signatur, kein neuer Parameter. Der Pfad bedient Holding-Bestätigung,
  OOO-Auto-Reply und Voice-Note-Antworten (H6) — dort einen Button-Fall
  einzuziehen, riskiert drei fremde Sendewege für einen.
- **`HoldingTemplateSender::sendToMany()`/`sendOne()`/`build()` bleiben
  unverändert.** Was aus dem Sender **gelesen** wird, steht in W2, und es ist
  eine Ergänzung ohne Verhaltensänderung an den bestehenden Aufrufern.
- **`Applicant/Show.php:529-552` wird in diesem Schnitt nicht reparariert.** Der
  Defekt wird präzise beschrieben (H4) und ist als eigener Punkt zu führen; die
  Reparatur berührt das manuelle Template-Senden für jeden Bewerber und jedes
  Template und ist kein Zertifikat-Thema. Siehe §Nicht in diesem Schnitt.
- Die anderen fünf Button-Stellen werden nicht angefasst und nicht
  zusammengelegt.

---

## Geteilte Fakten (gegen `9382981` gemessen — bindend)

Eigene Numerierung **H**, damit keine Verwechslung mit G1–G24 der alten Spec
entsteht. Wo ein H-Fakt einen G-Fakt ersetzt, steht es dabei.

**H1 — Sieben Erkennungsstellen, sechs Sendestellen, zwei verschiedene
Erkennungsregeln.** Vollständig gegen `9382981` gezogen:

| Stelle | Erkennung | verlangt `{{` in der URL? | Button-Wert |
| --- | --- | --- | --- |
| `Models/RecInterview.php:154-166` → Füllung `:204-216` | Schleife | **ja** (`:162`) | `resolveVariableValue($mapping['url_button'], $booking)` — aus **Mapping** |
| `Livewire/InterviewSchedule/Index.php:137-150` → `:168` | Schleife | **ja** (`:145`) | — (nur Anzeige-Flag `has_url_button` fürs Mapping-UI) |
| `Models/RecApplicant.php:1404-1418` → Send `:1421` | `collect()` | **nein** | `getOrCreatePublicFormLink()->token` |
| `Models/RecApplicant.php:1644-1656` → Send `:1659` | `collect()` | **nein** | `$portalLink->token` |
| `Models/RecEmployee.php:546-558` → Send `:561` | `collect()` | **nein** | `$this->portal_token` |
| `Livewire/Applicant/Show.php:531-552` → Send `:557` | Schleife | **nein** (`:535`) | Bewerber-**Formular**-Token (H4) |
| `Console/Commands/ProcessAutoPilotApplicants.php:467-478` → Send `:481` | `collect()` | **nein** | `$formToken` |

**Der Unterschied in Spalte 3 ist kein Stilunterschied.** `RecInterview` und
`InterviewSchedule` setzen `$hasUrlButton` nur, wenn die Button-URL eine
Variable enthält; die anderen fünf setzen es bei **jedem** URL-Button, auch bei
einem statischen. Ein statischer URL-Button, der einen Parameter mitbekommt, ist
für Meta ein Parameter zu viel. **Nicht gemessen** (kein Send gegen die Meta-API
abgesetzt): ob Meta den Send dann ablehnt oder den Parameter verwirft. Für diese
Spec ist die Konsequenz dieselbe — die strengere Regel ist kostenlos und wird
übernommen (W4).

**H2 — Der Button-Parameter trägt nur den variablen Schwanz der URL, nicht die
ganze URL. GEMESSEN.**

**Quelle: im Meta-Manager geprüft, 2026-08-13.** Die Button-URL des Templates
lautet `https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/` + variabler
Rest. Damit ist die Prämisse bestätigt und **nicht erneut zu prüfen** — die
frühere Fassung dieses Fakts sagte „aus dem Code belegt, nicht neu an Meta
gemessen" und hat damit eine Messung zweimal angefordert.

Der Code stützt es an fünf Stellen unabhängig: alle füllenden Stellen mit echtem
Wert übergeben einen **Token** — `$portalLink->token`
(`RecApplicant.php:1654`), `$this->portal_token` (`RecEmployee.php:556`),
`$formToken` (`ProcessAutoPilotApplicants.php:476`), `$formLinkToken`
(`RecApplicant.php:1411`) — und keine URL mit Schema und Host.

**Folge für das Zertifikat:** der Parameter ist `$certificate->uuid`, nicht
`route(...)`. **Das ist der Kern des Tradeoffs in T1** — die Basis-URL steht bei
Meta, das Modul liefert nur das letzte Pfadsegment und sieht den Rest nie.

**H3 — Alle sechs Sendestellen hardcodieren `index: 0`.** `RecInterview.php:212`,
`RecApplicant.php:1412` und `:1653`, `RecEmployee.php:555`,
`Applicant/Show.php:547`, `ProcessAutoPilotApplicants.php:475`. Keine Stelle
ermittelt die Position des Buttons in der `BUTTONS`-Komponente. Ein Template mit
Quick-Reply an Position 0 und URL-Button an Position 1 wird von **keiner** Stelle
im Modul korrekt befüllt. Das ist eine geteilte Annahme, keine dieses Pakets —
sie wird übernommen und in T2 benannt.

**H4 — `Applicant/Show.php:529-552` ist die Negativvorlage, und der Defekt ist
präziser als G8 ihn beschreibt.** Der Block:

```php
// :531-541
$hasUrlButton = false;
foreach ($template->components ?? [] as $comp) {
    if (($comp['type'] ?? '') === 'BUTTONS') {
        foreach ($comp['buttons'] ?? [] as $btn) {
            if (($btn['type'] ?? '') === 'URL') { $hasUrlButton = true; break 2; }
        }
    }
}
// :543-552
if ($hasUrlButton) {
    $publicUrl = $this->applicant->getPublicUrl();
    $formToken = basename(parse_url($publicUrl, PHP_URL_PATH));
    $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => 0,
                     'parameters' => [['type' => 'text', 'text' => $formToken]]];
}
```

**Der Token ist nicht der Defekt.** `RecApplicant.php:1403-1406` nennt ihn im
Kommentar ausdrücklich den kanonischen Bewerber-Public-Token, „gleiche Quelle wie
`/form/`, `/portal/`, `/contract/` und `/recruiting/interviews/`". Für ein
Template, dessen Button-URL auf eine dieser Routen zeigt, ist er **genau der
richtige Wert**.

**Drei Defekte, alle in der Bedingung, nicht im Wert:**

1. **Der Block läuft unbedingt**, sobald das Template *irgendeinen* URL-Button
   hat — `:531-541` fragt nirgends, wohin dieser Button zeigt. Bei einem
   Template mit Button auf eine dritte Route landet trotzdem der Formular-Token
   darin.
2. **Er verlangt kein `{{` in der URL** (H1, Spalte 3) — ein statischer
   URL-Button bekommt einen Parameter.
3. **Es gibt keinen Guard**: das Template wird vom Bediener im Modal frei
   gewählt, und es gibt keine Stelle, die vorher prüft, ob der eingesetzte Wert
   zu diesem Button passt. Ein falscher Link geht raus, erfolgreich, ohne
   Fehlermeldung.

**G8 der alten Spec ist auf diese Fassung zu korrigieren** (§Änderungen).

**H5 — Der heutige Guard und was er verhindert.**
`TrainingCertificateWhatsAppDelivery.php:143-161` fragt vor dem Send, ob das
konfigurierte Template die Body-Variable trägt, per
`WhatsAppTemplateBodyVariables::has()`
(`src/Support/WhatsAppTemplateBodyVariables.php:61-64`).

Der Grund steht im Docblock der Support-Klasse (`:9-14`) und ist gemessen:
`HoldingTemplateComponents::build()` füllt eine Variable, die **nicht** in
`$namedValues` vorkommt, mit dem **Beispieltext** des Templates
(`HoldingTemplateComponents.php:45`). Ohne den Guard ginge also eine Nachricht
mit dem Meta-Beispieltext statt dem Link an einen abgelehnten Bewerber —
erfolgreich, ohne Fehler, ohne Logzeile.

**Nach dem Umbau verschwindet dieser Beispieltext-Mechanismus für den Link**,
weil der Link nicht mehr durch `build()` läuft. **Der Guard bleibt trotzdem
nötig, mit anderer Begründung:** hat das Template keinen dynamischen URL-Button,
dann setzt der neue Pfad seinen Button-Component ins Leere. Der Send geht dann
entweder mit einem Parameter für eine Komponente durch, die das Template nicht
hat (Meta-Ablehnung), oder — schlimmer, wenn Meta tolerant ist — als Nachricht
**ohne Link** raus. Beides ist genau das Ergebnis, das der Guard aus Task 12
verhindern soll. **Er darf nicht wegfallen, er wechselt nur das Kriterium.**

**H6 — `HoldingTemplateSender` ist der Pfad, der geteilt wird — und was der neue
Pfad dabei verliert.** `sendOne()` (`:81-84`) delegiert vollständig an
`sendToMany()` (`:28-78`). Was dort passiert und im direkten Pfad selbst
erledigt werden muss:

| Leistung | Ort heute | im neuen Pfad |
| --- | --- | --- |
| Template aus Settings-Key auflösen, `status === 'APPROVED'` prüfen | `resolveConfig():100-113` | **muss neu geholt werden** (W2) |
| WhatsApp-Account + aktiven `CommsChannel` auflösen (4 Queries) | `resolveConfig():115-132` | **muss neu geholt werden** (W2) |
| Body-Komponenten bauen (Vorname/Beispiel/`$namedValues`) | `HoldingTemplateComponents::build()` via `:52` | **wird weiterverwendet, lesend** (W3) |
| Leere Pflicht-Parameter überspringen (Meta 131008) | `hasEmptyRequiredParam()` via `:56-59` | **wird weiterverwendet, lesend** (W3) |
| `Throwable` pro Empfänger fangen | `:72-74` | **eigenes catch, existiert schon** (`Delivery:174-180`) |
| `sender: auth()->user()`, `isAutoReply` | `:68-69` | explizit setzen (W1) |
| Thread-Kontext an den Bewerber hängen | **passiert nicht** | Entscheidung, siehe W6 |

Dieselbe Klasse bedient laut Aufrufern: die Jugendschutz-Absage
(`HrDesk/Index.php`), den OOO-Auto-Reply
(`Services/Comms/OooAutoReplyHandler.php:96`) und die Voice-Note-Antwort
(`Services/Comms/VoiceNoteAutoReplyHandler.php:57`). Das ist der Grund, warum
`build()` und `sendToMany()` nicht angefasst werden.

**H7 — Die sechs Sendestellen verlinken den Thread, der Holding-Pfad nicht.**
`RecApplicant.php`, `RecEmployee.php:570`, `ProcessAutoPilotApplicants.php:494`,
`Applicant/Show.php:568-570`, `InterviewBookings/Index.php:1127-1133` rufen alle
`$message->thread->addContext(...)` mit eigenem Purpose-String.
`HoldingTemplateSender::sendToMany()` tut das nicht — der heutige
Zertifikat-Versand erzeugt also einen WhatsApp-Thread, der **nicht** am Bewerber
hängt. Das ist heute so und wird von dieser Spec nicht als Defekt behauptet;
es ist aber die Stelle, an der der Musterwechsel eine Entscheidung erzwingt
(W6).

**H8 — Die drei Namen und die Route.**
`src/Support/TrainingCertificateWaTemplate.php` hält heute drei Konstanten:
`SETTINGS_KEY` (`:29`), `BODY_VARIABLE` (`:47`), `ROUTE_NAME` (`:58`). Die Route
ist `routes/public.php:54-55`: `/zertifikat/{uuid}`, Name
`recruiting.public.training-certificate`. Der Klassen-Docblock (`:5-18`) begründet
die Konstanten damit, dass die Namen an vier Orten im Betrieb zusammenpassen
müssen.

Gepinnt wird das von `tests/Unit/WhatsAppTemplateBodyVariablesTest.php:228-249`:
der Test liest die Blade des Einstellungs-Modals und prüft, dass der Hinweistext
denselben Variablennamen nennt wie der Versand. Betroffene Nicht-Test-Stellen
mit dem heutigen Wortlaut: `Models/RecApplicantSettings.php:60-69` (Kommentar am
Default) und `resources/views/livewire/applicant/applicant-settings-modal.blade.php:181-186`
(Hinweistext, nennt ausdrücklich „keinen URL-Button").

**H9 — Der Guard-Zweig ist ein Status unter sieben.**
`TrainingCertificateWhatsAppDelivery` führt sieben Status-Konstanten (`:51-69`),
darunter `STATUS_TEMPLATE_WITHOUT_VARIABLE = 'template_without_variable'`
(`:66`). Jeder Status hat eine HR-lesbare Meldung, die die Komponente unverändert
flasht (`:74-76`), und jede Fehlermeldung endet auf `vonHand()` (`:231-234`).
`tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php` prüft die Zweige
gegen einen Fake-Sender (`:234-242`, `:475`, Fixtures `:863-883`).

**H11 — `sendTemplate()` roh, und die Klasse liegt außerhalb des Moduls.
Gemessen am 2026-08-13**, `platform-crm/src/Services/Comms/WhatsAppMetaService.php:56-64`:

```php
    public function sendTemplate(
        CommsChannel $channel,
        string $to,
        string $templateName,
        array $components = [],
        string $languageCode = 'de',
        ?User $sender = null,
        bool $isAutoReply = false,
    ): CommsWhatsAppMessage {
```

Typen aus den Imports `:9-15`: `Platform\Crm\Models\CommsChannel`,
`?Platform\Core\Models\User`, Rückgabe `Platform\Crm\Models\CommsWhatsAppMessage`.
Klasse **nicht `final`** (`:20`).

**Warum das hier als Fakt steht und nicht im Plan genügt:** die Methode liegt in
`platform-crm`, das dieses Paket nicht anfassen darf, und der ganze Umbau hängt
an ihr. Sechs benannte Argumente aus dem Gedächtnis zu schreiben ist genau die
Bauart, die zuletzt als `->with('contractTemplate')` erst beim ersten echten
Klick aufgefallen ist.

**`$channel` ist typisiert — und der Modultest kann das nicht prüfen.** Er bindet
eine duck-typed Attrappe mit untypisiertem `$channel`. Getragen wird die Zusage
stattdessen von der Auflösung: `resolveTarget()` gibt genau den `CommsChannel`
zurück, den `resolveConfig():125-128` aus der Datenbank holt, und das ist gegen
die echte Migration prüfbar. Arbeitsteilung, benannt statt übersehen.

**H12 — Es gibt keinen Wiederversand-Weg. Gemessen am 2026-08-13.** `deliver()`
hat genau **einen** Aufrufer (`HrDesk/Index.php:270`, im Ablehnen-Zweig hinter der
Zertifikat-Checkbox); `wa_sent_at` erscheint außerhalb der Delivery nur in
`RecTrainingCertificate.php:53` und `:58` (fillable, Cast) — **nichts leert es**,
und es gibt keinen „erneut senden"-Knopf am HR-Schreibtisch.

**Folge:** der einzige zweite Eintritt ist eine zweite Ablehnung mit Haken, und
die fällt in `already_sent` (`:106-108`). Der Guard hat damit **eine** Tür, an der
er steht. Wer später einen Wiederversand baut, muss ihn durch `deliver()` führen —
ein Weg, der `wa_sent_at` leert und selbst sendet, hätte den Guard nicht vor sich.

**H13 — Die Route trägt keine Constraint auf `{uuid}`. Gemessen am 2026-08-13.**
`routes/public.php:54-55` ist ein nacktes `Route::get('/zertifikat/{uuid}', …)`
ohne `->where(...)`, ohne Pattern-Registrierung. Das Sentinel-Verfahren aus W7
hängt damit nicht an der Annahme, dass der URL-Generator `where`-Regeln beim
Bauen nicht prüft — es gibt keine. Wird später eine ergänzt, macht sie
`UUID_SENTINEL` ungültig und T-7 rot; das ist der laute Weg herum.

**H10 — Testkonvention unverändert.** Kein Laravel-Bootstrap im Modul-Runner:
neue Struktur-Leser sind **pure Unit-Tests** (Muster
`WhatsAppTemplateBodyVariables`), die Sendeentscheidungen bleiben im
Integrationstest mit handgebautem Container. Runner:
`meingedeck/vendor/bin/phpunit -c phpunit.xml`.

---

## Architektur

### W1 — Eigener Sendepfad, direkt `WhatsAppMetaService::sendTemplate()`

Der Umbau bleibt **innerhalb** von
`src/Services/TrainingCertificateWhatsAppDelivery.php`. Die Klasse ist schon der
Ort, an dem jede Entscheidung des Versands steht (Docblock `:18-24`) und der
einzige Aufrufer ist `HrDesk/Index::confirmResolve()` mit Aufruf und Flash.
Signatur und Rückgabeform (`array{status, error, link}`) bleiben unverändert —
die Livewire-Komponente wird nicht angefasst.

Was sich innerhalb ändert: statt `HoldingTemplateSender::sendOne()`
(`:167-173`) baut die Klasse ihre Komponenten selbst und ruft
`WhatsAppMetaService::sendTemplate()`.

**Die nächste Vorlage ist `RecInterview.php:204-216`, nicht `Show.php`** — und
zwar aus einem strukturellen Grund, nicht aus Geschmack: dort wird der
Button-Wert über `resolveVariableValue($mapping['url_button'], $booking)`
(`:206-207`) aus einem **konfigurierbaren Mapping** aufgelöst, also aus einem
beliebigen Wert. Der Zertifikat-Fall ist dieselbe Form mit einer festen Quelle
(der `uuid`) statt einer konfigurierten. Übernommen wird außerdem der
Leerwert-Riegel `:208` — kein Button-Component bei leerem Wert.

Aufruf-Form nach dem Muster der sechs Stellen, mit den beiden Argumenten, die
`HoldingTemplateSender:68-69` heute setzt und die sonst still auf Default
fielen:

```
sendTemplate(
    channel:      <aus W2>,
    to:           $phone,
    templateName: $template->name,
    components:   <Body aus W3> + <Button aus W4>,
    languageCode: $template->language ?? 'de',
    sender:       auth()->user(),
)
```

`isAutoReply` bleibt weg (Default) — dies ist kein Auto-Reply; heute geht der
Aufruf mit dem `sendOne`-Default `false` durch.

### W2 — Kanal und Template kommen aus **einer** Auflösung

Der direkte Sendepfad braucht einen `CommsChannel` und das Template-Objekt.
Heute liefert das `HoldingTemplateSender::resolveConfig()` — **`private`**
(`:96`), vier Queries, mit Prüfung auf `APPROVED` und aktiven Account.

**Entschieden im Review (Q2): eine lesende, öffentliche Methode am
`HoldingTemplateSender`, die `resolveConfig()` unverändert durchreicht** —
kleinste Änderung an einem Pfad mit drei fremden Aufrufern. Die Extraktion in
eine eigene Support-Klasse ist als Folgepunkt notiert (T4, Folgeliste F11).
Arbeitsname
`resolveTarget(int $teamId, string $settingsKey): array` mit derselben Rückgabe
`{error, template, channel}`. Keine Änderung an `sendToMany()`, `sendOne()`,
`configuredTemplateName()` oder `HoldingTemplateComponents` — die drei fremden
Aufrufer (H6) sehen keinen veränderten Code auf ihrem Weg.

Warum nicht die zwei Alternativen:

- **Auflösung in der Delivery-Klasse nachbauen** wäre die zweite Kopie einer
  vierstufigen Kette (Settings → Template → Account → Channel) inklusive der
  Regel `auto_pilot_wa_account_id` gewinnt über `$template->whatsapp_account_id`
  (`:115`). Genau die Kopien, die im Modul schon siebenfach existieren (H1).
- **`resolveConfig()` in eine eigene Support-Klasse ziehen** wäre die saubere
  Form und ist ein Refactoring an einem Pfad, der drei fremde Sendewege trägt.
  Als Folgepunkt notieren, nicht hier.

**Nebengewinn, benannt:** heute holt die Delivery das Template selbst per
`IntegrationsWhatsAppTemplate::find($templateId)` (`:135-137`) **nur für den
Guard**, während der Sender es unabhängig ein zweites Mal auflöst. Zwei
Lookups derselben ID, praktisch immer dasselbe Ergebnis, strukturell zwei
Wahrheiten. Mit `resolveTarget()` prüft der Guard genau das Template-Objekt, das
gleich gesendet wird. Der `class_exists`-Schutz (`:135`) bleibt nötig — im
Sender steckt er in `resolveConfig():106-108`.

**Die `not_configured`-Prüfung bleibt vorgeschaltet** (`:122-133`). Grund
unverändert und im Code kommentiert (`:123-126`): `resolveConfig()` antwortet für
**jeden** Settings-Key mit „Kein Eingangsbestätigungs-Template konfiguriert
(Einstellungen → Kommunikation)" und schickte HR damit in die falsche
Einstellung.

### W3 — Body-Komponenten weiter über `HoldingTemplateComponents::build()`, **lesend**

Das genehmigte Template wird mit hoher Wahrscheinlichkeit eine Anrede-Variable
im Body haben (`{{1}}`/`{{name}}`/`{{vorname}}`) — der heutige Versand füllt sie
über `$firstName` (`Delivery:164`, Auflösung `:210-224`). Dieser Teil hat mit dem
Button nichts zu tun und wird nicht nachgebaut:

```
$components = HoldingTemplateComponents::build($template->components ?? [], $firstName);   // Body, unverändert
if (HoldingTemplateComponents::hasEmptyRequiredParam($components)) { → STATUS_FAILED }     // Meta 131008
$components[] = <Button aus W4>;
```

**Das ist ein Aufruf, keine Erweiterung.** `build()` bleibt Zeile für Zeile wie
sie ist; die Klasse bekommt lediglich einen vierten Leser. Der Grund, `build()`
nicht anzufassen (H6), verbietet das Erweitern, nicht das Benutzen — und ein
eigener Body-Parser wäre die achte Kopie derselben `/\{\{(\w+)\}\}/`-Schleife im
Modul.

**`$namedValues` wird nicht mehr übergeben.** Der Link ist der einzige Wert, der
dort je drin stand; er wandert in den Button. Damit fällt auch der
Beispieltext-Mechanismus (`build():45`) als Risiko für den Link weg — er bleibt
für die Anrede-Variable bestehen, wo er richtig ist.

**`hasEmptyRequiredParam()` wird zum eigenen Zweig, nicht zum stillen Skip.** Im
Sender führt ein leerer Pflicht-Parameter zu `skipped++` (`:56-59`) — ohne
Meldung. Der heutige Pfad übersetzt das schon in `STATUS_FAILED` über
`$result['sent'] < 1` (`:182-191`). Im direkten Pfad muss das ausdrücklich
geprüft werden, sonst geht ein garantiert scheiternder Send an Meta. Konkret
erreichbar: ein Bewerber ohne auflösbaren Vornamen (`firstName()` gibt `''`
zurück, `:212-223`) bei einem Template mit Anrede-Variable.

### W4 — Der Button-Component und sein Wert

```
['type' => 'button', 'sub_type' => 'url', 'index' => 0,
 'parameters' => [['type' => 'text', 'text' => (string) $certificate->uuid]]]
```

**Der Wert ist die `uuid`, nicht `route(...)`** — H2. Die Basis-URL steht im
Meta-Template.

**`index: 0`** wie an allen sechs Stellen (H3), mit der Einschränkung aus T2.

**Der Leerwert-Riegel aus `RecInterview:208` wird übernommen**: leere `uuid` →
kein Button-Component. Praktisch unerreichbar (die `uuid` wird bei der
Ausstellung gesetzt), aber die Alternative wäre ein Button-Parameter mit leerem
Text und damit Meta 131008 — dieselbe Klasse Fehler, die `hasEmptyRequiredParam`
für den Body abfängt. Dann greift der `no_link`-Zweig aus W5, nicht ein Send.

**`$link` in der Rückgabe bleibt die vollständige URL.** `route(ROUTE_NAME,
['uuid' => …])` (`:163`) wird weiter gebildet — nicht mehr für den Send, sondern
für den Rückgabewert `link`, den die Fehlerzweige mitgeben (`:189`) und den HR
für den Versand von Hand braucht. Wer diese Zeile beim Umbau als „nicht mehr
gebraucht" entfernt, nimmt HR den Notweg aus jeder Fehlermeldung.

### W5 — Der Guard wandert: `zertifikat_link` → dynamischer URL-Button

**Neue Support-Klasse, Muster `WhatsAppTemplateBodyVariables`** (dependency-frei,
pure unit-testbar, H10). Arbeitsname
`src/Support/WhatsAppTemplateUrlButtons.php`:

```
WhatsAppTemplateUrlButtons::dynamicIndexes(?array $components): list<int>
WhatsAppTemplateUrlButtons::hasDynamicAt(?array $components, int $index): bool
```

**Kriterium: `type === 'URL'` **und** `str_contains($btn['url'] ?? '', '{{')`** —
die strengere der beiden Regeln aus H1, wie `RecInterview.php:162` und
`InterviewSchedule/Index.php:145`. Die lasche Variante der fünf anderen Stellen
wird nicht kopiert; sie ist Defekt 2 aus H4.

**Geprüft wird die Position, nicht nur die Existenz.** Weil der Sender `index: 0`
hardcodiert (H3), ist „irgendwo ein dynamischer URL-Button" die falsche Frage —
sie wäre wahr für ein Template mit Quick-Reply an 0 und URL-Button an 1, und
dann ginge der Parameter an die Quick-Reply-Position. `hasDynamicAt(…, 0)` ist
die Frage, die zum Sender passt. **Das ist die eine Stelle, an der der neue
Guard mehr prüft als das alte Muster** — und der Grund steht in T2.

**Der Statusname wandert mit:** `STATUS_TEMPLATE_WITHOUT_VARIABLE`
(`Delivery:66`) → `STATUS_TEMPLATE_WITHOUT_URL_BUTTON`, Wert
`'template_without_url_button'`. Der alte Name bleibt nicht als Alias stehen; er
ist modulintern und nur im Integrationstest gelesen (H9).

**Die Meldung nennt weiter das Template und den Befund**, in derselben Bauart wie
`:149-160` — Vorlagenname, was fehlt, was gefunden wurde, „Es wurde NICHTS
versendet", `vonHand()`. Der „gefunden"-Teil zählt die Buttons des Templates auf,
mit Typ **und Position**, damit HR im Meta-Manager weiß, was zu ändern ist. Ohne
diesen Teil ist die Meldung eine Verneinung ohne Handlungsanweisung — derselbe
Grund, aus dem `vonHand()` existiert (`:227-229`).

**Die Meldung muss die richtige Anweisung sagen, nicht nur den Befund. [Review]**
Die Aufzählung von Typ und Position liefert die Information; der Satz muss sie
auch aussprechen. Zwei Fälle, zwei Anweisungen:

- **Dynamischer URL-Button vorhanden, aber nicht an Position 0** (z.B.
  Quick-Reply an 0, URL-Button an 1): die richtige Anweisung ist **„den
  URL-Button im Meta-Template an die erste Position verschieben"** — nicht „kein
  URL-Button gefunden", was hier schlicht falsch wäre und HR in die Suche nach
  einem Button schickt, den es gibt.
- **Kein dynamischer URL-Button** (keiner, oder nur statische ohne `{{`): „das
  Template hat keinen URL-Button mit Variable" plus die Form der erwarteten
  Button-URL aus W7.

Beide Zweige sind im Guard unterscheidbar, weil `dynamicIndexes()` die Positionen
zurückgibt und nicht nur ein `bool` — das ist der Grund für diese Signatur.

**Die Reihenfolge der Zweige bleibt: `null`-Template läuft weiter.** Der
Kommentar `:139-142` gilt unverändert — über den Inhalt wird nur geurteilt, wenn
die Zeile wirklich da ist. Fehlt sie (bei Meta gelöscht, Integration nicht
installiert), ist „kein URL-Button" die falsche Diagnose; das beantwortet die
Auflösung aus W2 präziser.

### W6 — Warum der neue Pfad `Show.php:543-552` nicht wiederholen **kann**

Der Auftrag ist nicht „aufpassen, dass wir es nicht auch so machen" — der neue
Pfad hat den Defekt strukturell nicht, und zwar aus drei getrennten Gründen.
Jeder einzelne würde genügen; die drei sind es, weil jeder von ihnen später
einzeln zerstörbar ist.

1. **Es gibt keinen Bewerber-Token in diesem Pfad, an keiner Stelle.** Der Wert
   kommt aus `$certificate->uuid` — derselben Zeile, die auch das Dokument
   adressiert. `Show.php` muss sich seinen Wert erst besorgen
   (`getPublicUrl()` → `parse_url` → `basename`, `:544-545`); hier ist er das
   Objekt, um das der ganze Aufruf geht. **Die Delivery-Klasse ruft weder
   `getPublicUrl()` noch `getOrCreatePublicFormLink()` noch liest sie
   `portal_token`** — und §D1 der alten Spec verbietet das ausdrücklich, mit
   Begründung: der Applicant-Token öffnet Bewerbungsformular, Vertrags-PDFs und
   die ganze Vertragsliste, die `uuid` öffnet genau ein Dokument. **Das ist
   testbar zu nageln** (§Tests, T-4), nicht nur zu behaupten.
2. **Das Template ist nicht frei gewählt, sondern konfiguriert.** `Show.php`
   sendet, was der Bediener im Modal anklickt — jedes Template, mit jedem
   Button, auf jede URL. Hier kommt es aus **einem** Settings-Key
   (`TrainingCertificateWaTemplate::SETTINGS_KEY`), gesetzt von HR in einem Feld,
   dessen Hinweistext die verlangte Button-URL nennt (W7). Die Menge der
   möglichen Templates ist eins, nicht alle.
3. **Der Guard läuft vor dem Send und ist die Sendebedingung.** `Show.php` hat
   keinen — `$hasUrlButton` ist dort ein Auslöser („das Template hat einen
   Button, also füllen wir ihn"), hier ist es eine Vorbedingung („das Template
   hat den Button an Position 0, sonst senden wir nicht"). Das ist die
   Umkehrung, nicht eine strengere Fassung derselben Prüfung: bei `Show.php`
   führt die Erkennung zum Einsetzen, hier führt ihr **Fehlen zum Abbruch mit
   Meldung**.

**Was daraus für den Bauenden folgt:** der Button-Wert wird aus dem Zertifikat
gelesen, das schon in der Hand ist (`:85-91`) — es ist keine zweite Quelle zu
öffnen. Wer beim Bauen anfängt, sich den Wert „wie in Show.php" zu besorgen,
verlässt den Pfad, den diese drei Punkte beschreiben.

**Der Thread-Kontext bleibt DRAUSSEN. Entschieden im Review (Q3).** Die sechs
Stellen hängen den Thread per `addContext()` an ihr Subjekt (H7), der
Holding-Pfad nicht — heute ist der Zertifikat-Thread also nicht am Bewerber, und
mit dem Musterwechsel läge `$message->thread` erstmals in der Hand.

**Er wird trotzdem nicht angehängt.** Das wäre eine Verhaltensänderung über den
Auftrag hinaus, und sie fällt ohne Rest weg: der Versand funktioniert
vollständig ohne. Der Grund, aus dem sie richtig wäre — die Antwort des Bewerbers
auf einen Zertifikat-Link läuft ohne Kontext in die bekannte Kontext-Lücke —
steht als **eigener Punkt in der Folgeliste (F10)**, damit sie entschieden und
nicht vergessen ist.

**Was der Rückgabewert von `sendTemplate()` also nur noch trägt: `wa_sent_at`**
(`:194`). Die Zeile bleibt bewusst außerhalb jedes `try` — der Klassen-Docblock
`:33-37` begründet es und bleibt unverändert gültig: scheitert dieses UPDATE, ist
die Nachricht schon raus, und „Versand fehlgeschlagen" wäre dann die falsche
Aussage. Ohne `addContext()` gibt es nach dem Send **keinen** weiteren Schritt,
der ein eigenes `try` bräuchte.

### W7 — Der Body-Weg wird ersetzt, nicht als Fallback behalten

**Benannte Entscheidung.** Kein `if (kein Button) { dann Body-Variable }`.

**Begründung:** zwei Zustellwege für dasselbe Dokument sind zwei Pfade zum
Pflegen, und der zweite wäre der ungetestete. Konkret in diesem Fall: der
Fallback-Zweig würde nur bei einem falsch konfigurierten Template feuern — also
genau dann, wenn niemand hinsieht, und mit einem Ergebnis (Link im Fließtext),
das aussieht als hätte alles funktioniert. Der Guard aus W5 wäre damit still
entwertet: er würde nicht mehr melden, sondern umschalten. **Der Sinn des
Guards ist, dass HR es erfährt.**

**Was ersatzlos entfällt:**

- `TrainingCertificateWaTemplate::BODY_VARIABLE` (`:31-47`) samt Docblock, dessen
  ganzer Inhalt („BODY-Variable und KEIN URL-Button, und das ist keine
  Stilfrage") die hier revidierte Entscheidung begründet.
- Der `$namedValues`-Aufruf (`:172`) und mit ihm der letzte Modul-Aufruf, der
  einen Link durch `build()` schickt.
- **`WhatsAppTemplateBodyVariables` bleibt** — die Klasse ist der Leser für die
  Anrede-Variable und wird von `WhatsAppTemplateUrlButtons` nicht ersetzt,
  sondern ergänzt. Ihr Docblock (`:9-14`) bleibt inhaltlich richtig; nur der
  Verweis auf den Zertifikat-Link ist zu korrigieren.

**Die Namens-Klammer bleibt bestehen, mit anderem Inhalt.** Der Klassen-Docblock
von `TrainingCertificateWaTemplate` (`:5-18`) begründet die Konstanten damit,
dass drei Strings an vier Orten zusammenpassen müssen. Beim Button gibt es keinen
Variablennamen mehr — dafür eine **Form**, die zusammenpassen muss: die bei Meta
hinterlegte Button-URL muss auf das Pfadsegment der Route enden.

**Entschieden im Review (Q1): `BODY_VARIABLE` wird ersetzt durch genau eine
Konstante, und die erwartete Button-URL wird aus der Route ABGELEITET, nicht als
String gepflegt.**

- **`URL_BUTTON_INDEX = 0` bleibt als Konstante.** Das ist eine echte geteilte
  Zahl zwischen Sender (W4) und Guard (W5) — zwei Stellen im Modul, die
  dieselbe Position meinen müssen.
- **Kein `META_BUTTON_URL_TAIL` als handgeschriebener String.** Der naheliegende
  Wert `'/recruiting/zertifikat/{{1}}'` enthält das Präfix `recruiting`, obwohl
  die Route selbst nur `/zertifikat/{uuid}` registriert
  (`routes/public.php:54-55`) — das Präfix kommt aus
  `RecruitingServiceProvider.php:128`. Eine Konstante mit dieser Form wäre eine
  dritte Stelle mit derselben Annahme, und ein Pin-Test gegen den Route-Pfad
  müsste selbst wissen, wie Präfix und Pfad zusammenkommen. Dann wäre der
  Wächter die vierte Kopie der Annahme, die er bewachen soll.

**Stattdessen wird die Form erzeugt.** Ein Sentinel durch `route()` schicken und
danach ersetzen:

```
$url  = route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => <Sentinel>]);
$form = str_replace(<Sentinel>, '{{1}}', $url);   // → https://…/recruiting/zertifikat/{{1}}
```

Der Sentinel muss urlsafe sein und darf nach dem Encoden unverändert
wiederauftauchen — `{{1}}` direkt durchzuschicken funktioniert **nicht**,
`route()` encodet die Klammern zu `%7B%7B1%7D%7D`. Ein Wort aus
Buchstaben (Arbeitsname `UUID_SENTINEL`) überlebt.

**Wo das läuft:** in der Livewire-Komponente des Einstellungs-Modals, zur
Render-Zeit, und die Blade zeigt das Ergebnis. Damit stimmt der Hinweistext
immer mit der laufenden App überein — Host, Präfix und Pfad kommen alle aus der
Route-Registrierung, es gibt keine zweite Wahrheit mehr zu pflegen. Der
Sentinel-Tausch selbst ist eine pure Funktion und gehört zu
`TrainingCertificateWaTemplate` (nimmt die fertige URL als Argument, ruft
`route()` nicht selbst — die Klasse bleibt Laravel-frei, H8).

**Die Konstante mit der Form lebt in der ERWARTUNG DES TESTS, nicht im
Produktivcode.** T-7 baut den Router wie die Host-App und prüft, dass die
abgeleitete Form `'/recruiting/zertifikat/{{1}}'` ergibt. Ändert jemand Präfix
oder Route-Pfad, wird der Test **rot** — und das ist die gewünschte Wirkung, denn
dann muss die Button-URL bei Meta nachgezogen werden (T1). Eine Konstante im
Produktivcode wäre in demselben Fall still falsch geblieben.

**Was der Test dabei nicht kann, benannt:** was bei Meta wirklich hinterlegt ist,
sieht kein Test — T1 bleibt unverändert.

**Weitere Textstellen, die mitwandern** (der Pin-Test wird sonst rot, und das ist
sein Zweck): `RecApplicantSettings.php:60-69` (Kommentar am Default),
`applicant-settings-modal.blade.php:181-186` (Hinweistext — er sagt heute
ausdrücklich „keinen URL-Button" und wird damit zur Falschaussage),
`TrainingCertificateWhatsAppDelivery` Docblock und Statuskommentar `:65-66`.

**Der neue Hinweistext enthält keine URL als Literal**, sondern die abgeleitete
Form aus der Komponente — plus den Preis aus T1 in einem Satz: ändert sich die
Domain, muss die Button-URL bei Meta nachgezogen werden. T-7 prüft, dass in der
Blade **kein** hartkodiertes `zertifikat/`-URL-Literal steht; sonst wäre die
Ableitung gebaut und danach umgangen.

### W8 — `failed` bekommt einen Log-Marker pro Ursache

**Aus dem Review (B2).** Der Statuswert `failed` sammelt nach dem Umbau vier
Ursachen: Auflösung meldet `error` (W2), leerer Pflicht-Parameter (W3),
`sendTemplate()` wirft, leere `uuid` (W4). Die HR-Meldung unterscheidet sie, der
Status nicht — und im Log stünde eine Zeile ohne Unterscheidung. Bei einem
Versand, der direkt beim abgelehnten Bewerber landet, ist beim Nachsehen genau
diese Unterscheidung die Frage.

**Ein Log-Marker pro Ursache, keine eigenen Statuswerte.** Die vier Ursachen sind
für den Aufrufer dasselbe Ereignis („es ging nichts raus, HR muss von Hand") —
vier Statuswerte hießen vier Zweige in `HrDesk/Index::confirmResolve()`, die alle
dasselbe täten. Für die Diagnose reicht das Log.

Form wie im Modul üblich (`CreateEmployeeFromApplicantService.php:242`,
`:335`, `:366`): `Log::error('[TrainingCertificateWhatsAppDelivery] <Ursache>',
[<Kontext>])`. Ursache im Klartext, unterscheidbar zwischen den vier; Kontext
mindestens Bewerber-ID, Zertifikat-`uuid` und — wo vorhanden — Template-Name und
Meta-Fehlertext.

**Der Guard-Zweig (`template_without_url_button`) wird mitgeloggt**, obwohl er
kein `failed` ist: er ist der Zweig, der am häufigsten feuern wird (Rollout 2),
und ohne Logzeile ist er nur so lange sichtbar, wie der Flash am Bildschirm
steht.

**Nicht geloggt: `sent`, `already_sent`, `no_certificate`, `no_phone`,
`not_configured`.** Die ersten zwei sind der Normalfall, die anderen drei
Zustände des Bewerbers oder der Konfiguration, nicht Störungen des Versands —
und `wa_sent_at` ist für den Erfolgsfall der bessere Nachweis als eine Logzeile.

---

## Fehlerpfade — Statusliste nach dem Umbau

Vier von sieben Zweigen bleiben wörtlich; die Änderungen sind markiert.

| Status | Bedingung | Ergebnis |
| --- | --- | --- |
| `no_certificate` | kein Zertifikat mit `kind` (`:85-99`) | unverändert |
| `already_sent` | `wa_sent_at !== null` (`:106-108`) | unverändert |
| `no_phone` | `primaryContactPhone() === null` (`:110-117`) | unverändert |
| `not_configured` | Settings-Key leer (`:122-133`) | unverändert |
| **`template_without_url_button`** | Template da, aber kein dynamischer URL-Button an Index 0 (W5) | **ersetzt** `template_without_variable`; **geloggt** (W8) |
| **`failed`** | Auflösung aus W2 meldet `error`; **oder** leerer Pflicht-Parameter (W3); **oder** `sendTemplate()` wirft; **oder** leere `uuid` (W4) | **erweitert** — heute nur `Throwable` und `sent < 1`. **Vier Ursachen, vier Log-Marker, ein Statuswert** (W8) |
| `sent` | Send durch, `wa_sent_at` gestempelt (`:194`) | unverändert |

`error`-Text bleibt in jedem Zweig die fertige, HR-lesbare Meldung mit
`vonHand()` am Ende (`:74-76`, `:231-234`). Die Rückgabeform ändert sich nicht,
`HrDesk/Index::confirmResolve()` wird nicht angefasst.

**§D5 gilt unverändert: ein Sendefehler kippt die Ablehnung nicht.** Der Aufruf
liegt nach dem Commit, die Methode wirft nicht. Beim Wechsel auf den direkten
Send fällt das `try/catch` aus `sendToMany():72-74` weg — das eigene `catch`
(`:174-180`) trägt es dann allein. Es existiert bereits und wurde genau für den
ungeschützten Teil gebaut (Docblock `:26-31`); nach dem Umbau ist der ganze Send
dieser ungeschützte Teil.

---

## Tests

Modulkonvention (H10): pure Unit für die Struktur-Leser, Integration mit
handgebautem Container für die Sendeentscheidungen.

| # | Was | Wo | Warum dieser Test und nicht nur einer |
| --- | --- | --- | --- |
| T-1 | `dynamicIndexes()`/`hasDynamicAt()`: Button an 0, Button an 1, statischer URL-Button (kein `{{`), Quick-Reply, keine `BUTTONS`-Komponente, `null` | neu, Unit | Deckt die Kriterien einzeln ab. **Der statische URL-Button ist der wichtige Fall** — er ist Defekt 2 aus H4 und der einzige, den fünf Bestandsstellen falsch beantworten |
| T-2 | Guard: **zwei** Zweige getrennt — (a) kein dynamischer URL-Button, (b) dynamischer Button an Position 1 statt 0. Beide → `template_without_url_button`, **kein Send**; Meldung (b) sagt „an die erste Position verschieben", nicht „keinen Button gefunden" | `TrainingCertificateWhatsAppDeliveryTest`, ersetzt den `template_without_variable`-Fall (`:475`) | Der Zweck des Guards ist, dass nichts rausgeht — „kein Send" ist die Assertion, nicht der Statuswert. Fall (b) ist der, bei dem eine falsche Meldung HR in die falsche Suche schickt (W5) |
| T-3 | Erfolgsfall: gesendete `components` enthalten genau einen `button`-Eintrag, `sub_type: url`, `index: 0`, `parameters[0].text` **=== die `uuid`** | dito, ersetzt `:234-242` | **Der belastbare Teil der Token-Absicherung.** Nagelt H2 fest: steht hier die vollständige Route-URL oder ein Token, ist der Test rot — und genau das ist der Fehler, der beim Bauen naheliegt |
| T-4 | Die Delivery-Klasse ruft **nirgends** `getPublicUrl()`, `getOrCreatePublicFormLink()` oder `portal_token` | Unit, Quelltext-Assertion (Muster: der Blade-Leser in `WhatsAppTemplateBodyVariablesTest:236-243`) | W6.1 als Test statt als Zusicherung. **Und was er nicht kann, gehört in den Test geschrieben: er hält nur den direkten Aufruf** — wer `getPublicUrl()` in einen Helfer verlegt, macht ihn grün. Er ist die Leitplanke gegen das Kopieren aus `Show.php`, nicht der Nachweis; der Nachweis ist T-3 |
| T-5 | Body bleibt: Template mit `{{1}}` → Body-Component mit dem Vornamen, **plus** Button-Component | dito | Der naheliegende Umbaufehler ist, beim Wechsel auf den direkten Send die Anrede zu verlieren |
| T-6 | Leerer Pflicht-Parameter (Bewerber ohne auflösbaren Vornamen, Template mit Anrede-Variable) → `failed` mit Meldung, **kein Send** | dito | W3: im Sender war das ein stiller `skipped`; ohne diesen Test wird der Zweig beim Umbau schlicht vergessen |
| T-7 | **Zwei Teile.** (a) Unit: der Sentinel-Tausch macht aus `…/zertifikat/<Sentinel>` die Form `…/zertifikat/{{1}}`. (b) Integration: Router wie die Host-App bauen (Präfix-Gruppe wie `RecruitingServiceProvider.php:128`, Muster `TrainingCertificatePublicRouteTest`), Form ableiten, gegen die **Testerwartung** `/recruiting/zertifikat/{{1}}` prüfen. Dazu: die Blade enthält kein hartkodiertes `zertifikat/`-URL-Literal | (a) Unit neu; (b) `TrainingCertificatePublicRouteTest` erweitern; `WhatsAppTemplateBodyVariablesTest:228-249` umbauen | W7/B1. Die Erwartung steht **im Test**, nicht im Produktivcode: ein Präfix- oder Pfadwechsel wird damit rot und zeigt an, dass die Button-URL bei Meta nachzuziehen ist (T1). Eine Konstante wäre still falsch geblieben. Der Blade-Teil verhindert, dass die Ableitung gebaut und dann umgangen wird |
| T-8 | `WhatsAppTemplateBodyVariables`-Tests bleiben grün | Bestand | Die Klasse bleibt (W7) und wird weiter für die Anrede gebraucht; ihre Tests dürfen nicht als „gehört zum Body-Weg" mitentfernt werden |
| T-9 | Die vier `failed`-Ursachen und der Guard-Zweig schreiben **unterscheidbare** Log-Marker | dito | W8/B2. Ohne Assertion sind vier Ursachen morgen wieder eine Logzeile — und der Statuswert unterscheidet sie nicht |

**Volle Suite muss grün bleiben** (Stand main: `OK (746 tests, 2239
assertions)`), unter der Default-Reihenfolge — F3 der Folgeliste (geteilte
Statics) ist unverändert offen und wird hier nicht angefasst.

---

## Benannte Tradeoffs

**T1 — Die Basis-URL liegt bei Meta, und das Modul sieht sie nie.** Mit der
Body-Variable schrieb das Modul die vollständige URL in die Nachricht: Domain,
Pfad und `uuid` kamen aus `route()`, also aus der laufenden App. Mit dem Button
liefert das Modul nur die `uuid`; alles davor steht im genehmigten Meta-Template.

**Zwei Folgen, beide neu:**

1. **Ändert sich die Domain oder der Route-Pfad, muss die Button-URL bei Meta
   nachgezogen werden** — im Meta-Manager, mit erneuter Genehmigung, außerhalb
   dieses Repos und außerhalb jedes Deploys. Ein Domainwechsel, der bisher
   vollständig durch `route()` mitwanderte, wird zu einer Aufgabe an einem
   zweiten Ort.
2. **Kein Guard kann das prüfen.** Der Guard aus W5 sieht die Template-Struktur
   und beantwortet „gibt es einen dynamischen URL-Button an Position 0" — nicht
   „zeigt er auf die richtige Basis-URL". Steht dort ein veralteter Host, geht
   die Nachricht **erfolgreich** raus, mit einem Button, der ins Leere führt, und
   nichts im Modul bemerkt es. Das ist eine echte neue blinde Stelle gegenüber
   dem Body-Weg, keine bloß theoretische: der Fall tritt genau dann ein, wenn
   jemand die Domain wechselt und diesen Absatz nicht kennt.

Das ist der Preis der Entscheidung, und er ist eingegangen worden, weil er
einmalig bei einem Domainwechsel anfällt, während die lange URL im Fließtext bei
jeder Nachricht anfällt. **Der Preis gehört in den Hinweistext des Modals**,
nicht nur in diese Spec.

**T2 — `index: 0` ist geteilte Annahme, nicht Wahrheit.** Ein Template mit
Quick-Reply an Position 0 und URL-Button an Position 1 wird von keiner Stelle im
Modul korrekt befüllt (H3). Der Guard aus W5 macht diesen Fall für den
Zertifikat-Weg **sichtbar** (er meldet „kein dynamischer URL-Button an Position
0" und sendet nicht) statt ihn falsch zu senden — mehr nicht. Die Reparatur wäre,
den Index zu ermitteln statt zu setzen, an sechs Stellen. Nicht in diesem
Schnitt; als Folgepunkt notieren.

**T3 — Kein Notweg, wenn Meta den Button ablehnt.** Ohne Fallback (W7) bleibt bei
einem strukturell unpassenden Template genau ein Weg: die Meldung an HR und
`vonHand()` — PDF herunterladen, manuell senden. Das ist beabsichtigt und
funktioniert nur, solange die Meldung gelesen wird; sie erscheint als Flash über
dem HR-Schreibtisch, direkt nach der Ablehnung.

**T4 — Der `resolveTarget()`-Zugang öffnet den Holding-Sender ein Stück.** Was
heute `private` ist, wird lesbar. Wer später `sendToMany()` umbaut, hat einen
zweiten Konsumenten seiner Auflösung — sichtbar, aber vorhanden. Die Alternative
(eigene Support-Klasse für die Auflösung) wäre die sauberere Form und ein
Refactoring an einem Pfad mit drei fremden Aufrufern. **Im Review entschieden
(Q2): erst Zugang, Extraktion als Folgepunkt** — Folgeliste F11.

**T5 — Unverändert offen: F5 (Portal-Zugriff abgelehnter Bewerber).** Diese Spec
ändert die Zustellform, nicht die Zugriffsfläche. Die `uuid` öffnet weiter genau
ein Dokument, verfällt nicht und wird nicht rotiert. **Der Button verkürzt
nichts daran** — er versteckt die URL nur optisch; sie steht weiter in der
Nachricht und ist weitergebbar.

---

## Änderungen an der alten Spec `2026-08-11-schulungszertifikat-html-design.md`

Die alte Spec wird **nicht** umgeschrieben; sie beschreibt das gemergte Paket.
Diese Spec ist der Nachfolger für §D und trägt die Korrekturen. Wer §D dort
liest, muss hierher verwiesen werden — als Kasten am Anfang von §D, in derselben
Form wie die `[v3]`-Kästen.

**G8 — zu korrigieren.** Alter Wortlaut: „Der einzige Pfad, der URL-Buttons
füllt, füllt sie falsch. […] Pfad **nicht verwendbar**." Beides ist zu
präzisieren, und der zweite Satz war die Grundlage der falschen Entscheidung:

> **G8 (korrigiert 2026-08-13).** Es gibt **sechs** Pfade, die URL-Buttons
> füllen, und **sieben**, die sie erkennen — vollständige Liste in H1 der
> Button-Spec. `Applicant/Show.php:529-552` ist einer davon, und der einzige mit
> einem Defekt: der Block läuft **unbedingt**, sobald das Template irgendeinen
> URL-Button hat (`:531-541` prüft nicht, wohin er zeigt, und nicht, ob die URL
> überhaupt eine Variable trägt), und setzt dann den
> Bewerber-Formular-Token hinein. **Der Token ist nicht falsch** —
> `RecApplicant.php:1403-1406` nennt ihn den kanonischen Bewerber-Public-Token,
> und für Templates mit Button auf `/form/`, `/portal/`, `/contract/` oder
> `/recruiting/interviews/` ist er genau richtig. Falsch ist, dass er
> **ungeprüft in jeden Button** gesetzt wird. Der Befund trägt also keine
> Aussage über die Verwendbarkeit des Button-Musters; er benennt einen Defekt an
> einer von sieben Stellen.

**§D4 — abgelöst.** „Weg (a) nutzt `HoldingTemplateSender` […] Der PDF-Link geht
als Body-Variable" gilt nicht mehr. Was von der Begründung **stehen bleibt**: G7
ist richtig gemessen — `HoldingTemplateComponents::build()` kann strukturell
keine Buttons, und ein Umbau würde Holding, Auto-Reply und Voice-Note
mitanfassen. **Die Schlussfolgerung war falsch, nicht der Fakt**: dass *dieser
eine Sender* keine Buttons kann, heißt nicht, dass das Modul es nicht kann. Der
Ausweg ist der eigene Sendepfad, nicht der Body.

**§D4 „Preis, benannt" — erledigt.** Die lange URL im Nachrichtentext ist der
Grund dieser Spec. An ihre Stelle tritt T1.

**Ziel 4 der alten Spec** („Link als Body-Variable, nicht als URL-Button (§D)")
— zweiter Halbsatz gestrichen.

**Was ausdrücklich gilt und nicht angetastet wird:** §D1 (Zertifikat-`uuid`
statt Applicant-Token — durch W6.1 sogar fester genagelt als vorher), §D3 (Weg
(b) verschickt nichts), §D5 (Sendefehler kippt die Ablehnung nicht), §D6 (warum
der Versand trotz Portal bleibt), §C und §F vollständig.

---

## Nicht in diesem Schnitt

- **Der Fix an `Applicant/Show.php:529-552`.** Er betrifft jedes manuell
  gesendete Template für jeden Bewerber, nicht nur Zertifikate; die richtige
  Form (Button-Ziel prüfen, `{{`-Kriterium, Index ermitteln) berührt alle sechs
  Stellen. Eigener Punkt. **Was hier passiert:** die alte Spec bekommt die
  G8-Korrektur, und die Folgeliste hält F2 damit als erledigt fest — der Defekt
  ist beschrieben, nicht behoben.
- **`index`-Ermittlung an den sechs Stellen** (T2) → Folgeliste **F12**.
- **Auflösung von Template/Account/Channel in eine eigene Support-Klasse ziehen**
  (T4, Q2) → Folgeliste **F11**.
- **Thread-Kontext am Zertifikat-Versand** (W6, Q3) → Folgeliste **F10**.
- **F3–F9 der Folgeliste** — unverändert offen, keiner davon wird von diesem
  Umbau berührt oder verschärft.

---

## Rollout

**Kein Datenmigrations-Schritt, aber eine Reihenfolge, und sie ist nicht
beliebig.**

1. **Das Meta-Template mit dynamischem URL-Button muss existieren und genehmigt
   sein, bevor deployt wird.** Button-URL
   `https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/` + Variable (H2,
   gemessen), URL-Button an **Position 0** (T2).
2. **HR stellt den Settings-Key um, kein SQL im Deploy. Entschieden im Review
   (Q4).** Nach dem Deploy zeigt der Schlüssel noch auf das alte Body-Template;
   dann greift der neue Guard genau wie gebaut: Zertifikat wird ausgestellt, es
   geht **nichts** raus, HR bekommt die Meldung mit `vonHand()`. **Das Fenster
   existiert und ist harmlos** — kein stiller Fehlversand, und der Guard ist der
   Grund. Es gehört in die Live-Checkliste, nicht in eine Annahme. Der Zweig wird
   außerdem geloggt (W8), damit er nicht nur so lange sichtbar ist wie der Flash.
3. `queue:restart` ist **nicht** nötig — der Versand läuft synchron im
   Livewire-Request von `confirmResolve()`, nicht in einem Job.
4. Nach dem Merge: `meingedeck`-Bump, sonst ist nichts live.

**Live-Sichtprüfung nach der Umstellung** (in `docs/zertifikat/live-checkliste.md`
nachzutragen): eine Ablehnung mit Haken, und am Gerät prüfen, dass die Nachricht
einen Button trägt, dass er das PDF öffnet, und dass `wa_sent_at` gesetzt ist.

---

## Entscheidungen aus dem Review vom 2026-08-13

Alle vier offenen Fragen sind beantwortet; zwei Blocker sind eingearbeitet. Die
Spec hat keine offenen Fragen mehr.

| Punkt | Entscheidung | eingearbeitet in |
| --- | --- | --- |
| **Prämisse H2** | Button-URL im Meta-Manager geprüft: `https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/` + variabler Rest. **Gemessen, nicht erneut zu prüfen** | H2 |
| **B1** (Blocker) | Kein handgeschriebener `META_BUTTON_URL_TAIL`. Form aus `route(ROUTE_NAME, [<Sentinel>])` **ableiten**; Erwartung lebt in der Testerwartung, nicht im Produktivcode. `URL_BUTTON_INDEX = 0` bleibt Konstante | W7, T-7 |
| **B2** (Blocker) | `failed` behält **einen** Statuswert, bekommt **vier unterscheidbare Log-Marker**; der Guard-Zweig wird mitgeloggt | W8, Statusliste, T-9 |
| **Q1** | siehe B1 | W7 |
| **Q2** | `resolveTarget()` am `HoldingTemplateSender`. Extraktion in eine Support-Klasse als Folgepunkt | W2, T4, Folgeliste F11 |
| **Q3** | Thread-Kontext **raus**. Als eigener Punkt mit Begründung in die Folgeliste | W6, Folgeliste F10 |
| **Q4** | HR stellt um, das Fenster existiert und ist harmlos, kein SQL im Deploy. In die Live-Checkliste | Rollout 2 |
| **Kleinigkeit** | T-4 bleibt, mit der Grenze **im Test notiert** (hält nur den direkten Aufruf); belastbar ist T-3 | T-4 |
| **Kleinigkeit** | Guard-Meldung sagt die Anweisung, nicht nur den Befund („an die erste Position verschieben") | W5, T-2 |
| **Kleinigkeit** | Bauart „W6 als Test statt als Zusicherung" wird als Muster beibehalten | T-4, T-3 |

---

## Ausführungs-Schnitt

**Ein Paket, subagent-driven, in dieser Reihenfolge** — jeder Schritt lässt sich
grün abschließen, bevor der nächste beginnt:

1. **Guard-Klasse `WhatsAppTemplateUrlButtons`** (W5) — Laravel-frei, mit T-1.
   Zuerst, weil sie ohne alles andere testbar ist und der Umbau in Schritt 3 sie
   braucht.
2. **`resolveTarget()` am `HoldingTemplateSender`** (W2) — die eine neue
   öffentliche Methode, ohne Änderung an `sendToMany()`/`sendOne()`/`build()`.
3. **Umbau in `TrainingCertificateWhatsAppDelivery`** (W1, W3, W4, W6, W8) — der
   Sendepfad, der Guard-Zweig, die Log-Marker. T-2, T-3, T-5, T-6, T-9.
4. **Konstanten, Ableitung, Hinweistext, Pin-Test** (W7) — T-4, T-7, T-8.
   **Zuletzt**, weil dieser Schritt die Blade-Texte mitzieht.

Kein Bestandscode wird angefasst außer der einen neuen öffentlichen Methode aus
Schritt 2. Volle Suite muss nach jedem Schritt grün sein.
