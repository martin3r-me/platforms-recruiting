# Jobs anbieten: Versandweg Portal oder WhatsApp, Freigabe, Angebote im Portal, Push

Stand 13.11.2026. Anlass: Mail Markus 13.11. („Kommunikation unserer Einsatzanfragen künftig stärker über das Mitarbeiterportal steuern und den WhatsApp-Versand gezielt begrenzen“), abgestimmt mit Sebastian am selben Tag.

## 0. Worum es geht und warum

Markus will die Mitarbeiter ins eigene Portal ziehen. Wer alle freien Einsätze ständig per WhatsApp bekommt, hat keinen Grund, selbst ins Portal zu schauen. WhatsApp soll deshalb künftig für dringende Bedarfe und verbindliche Einsatzbestätigungen da sein. Die Monats- und Wochenübersichten gehören ins Portal. Es geht ihm um Kontrolle über Kosten und Kommunikation, nicht nur ums Sparen.

Wörtlich aus der Mail:

- „Mitarbeiterportal: Die Übersicht wird im persönlichen Mitarbeiterportal bereitgestellt. Dies soll der reguläre und bevorzugte Kommunikationsweg sein.“
- „WhatsApp: Ein Versand ist weiterhin möglich, benötigt aber zwingend eine Vier-Augen-Freigabe durch mich. Der Projektleiter kann den Versand lediglich beantragen. Ich erhalte eine Freigabeanfrage und entscheide mit Ja oder Nein. Erst nach meiner ausdrücklichen Freigabe darf die Nachricht versendet werden.“
- „Die dringenden Wellen bleiben davon ausgenommen. […] Der Versand erfolgt wie bisher direkt über WhatsApp, einschließlich der vorgesehenen automatisierten Eskalationen.“
- „[…] könnte das Mitarbeiterportal perspektivisch auch als eine Art App mit Push-Benachrichtigungen genutzt werden.“

„Monats- und Wochenübersichten“ sind in unserem Werkzeug die Anlässe `vier_wochen` und `naechste_woche` (`DispoJobAnlass::ALLE`). „Dringende Wellen“ ist der Anlass `dringend`.

## 1. Ausgangslage im Code

- Eine Anfrage (`RecDispoJobRequest`) hat Schichten und Wellen. `Create.php` legt über `DispoJobAnfrageAnlegen::anlegen()` Anfrage plus Welle 1 an und ruft nach dem Commit `starten()`, das den Job `SendDispoJobWave` einreiht.
- `SendDispoJobWave::handle()` plant die Empfänger (`DispoJobRecipientPlanner`, Obergrenze `welle_max`), lässt `DispoJobOfferSender::send()` je Person ein Angebot (`RecDispoJobOffer` + `RecDispoJobOfferShift`) anlegen und die WhatsApp-Vorlage schicken und legt bei Bedarf die Folgewelle an (`DispoJobFolgewelle::entscheiden`).
- Ein Angebot entsteht heute nur im Versand. Die Angebotsseite `/recruiting/jobs/{token}` (`Livewire\Public\JobOffers`) liest alle Angebote der Person aus laufenden Anfragen, unabhängig von `sent_at`.
- Das neue Portal (`Livewire\Public\PortalShell`, Layout `recruiting::layouts.portal`, Route `/recruiting/mitarbeiter/neu/{token}`) hat einen Reiter „Einsätze“ (Alpine `tab === 'jobs'`), der `EmployeeAssignments` mit `:eingebettet="true"` einbettet. Angebote zeigt das Portal nicht.
- Es gibt weder Web-App-Manifest noch Service Worker noch eine Push-Bibliothek (meingedeck hat `minishlink/web-push` nicht installiert).

## 2. Ziele

1. Der Disponent wählt bei `vier_wochen` und `naechste_woche` den Weg: Portal (Vorgabe) oder WhatsApp.
2. Portal-Wellen laufen mit derselben Wellenlogik wie heute, nur ohne WhatsApp: die Angebote werden angelegt und sind im Portal sichtbar.
3. WhatsApp bei `vier_wochen`/`naechste_woche` geht erst raus, nachdem der hinterlegte Freigeber Ja gesagt hat.
4. `dringend` bleibt exakt wie heute.
5. Der Mitarbeiter sieht seine offenen Angebote im Portal: Karte auf der Startseite und Bereich „Freie Einsätze“.
6. Das Portal lässt sich auf den Startbildschirm legen und schickt Push-Benachrichtigungen bei neuen Portal-Angeboten.
7. Alles, was das Verhalten für Disponenten ändert (Ziele 1 bis 3), hängt an einem Schalter und ist bis zum Portal-Rollout aus.

## 3. Nicht-Ziele

- Push für andere Anlässe (Einsatz bestätigen, fehlendes Dokument). Kommt später, falls gewünscht.
- Benachrichtigung des Freigebers per Mail oder WhatsApp. Sebastian: „würde es direkt im recruiting portal machen“.
- Vertretung für die Freigabe. Sebastian: „ausschließlich er“.
- Änderungen an der Empfängerplanung, am Rückkanal zu ZAS, an Eskalationen oder an den Vorlagen bei Meta.
- Anbindung der alten WhatsApp-Nummer und die Welle „dringend bis Reaktion“ (eigenes Thema).

## 4. Was auf keinen Fall passieren darf

- Bei eingeschaltetem Schalter verlässt eine WhatsApp für `vier_wochen` oder `naechste_woche` das Haus ohne Freigabe. Das gilt für jede Anfrage, die bei eingeschaltetem Schalter angelegt wurde: für Welle 1, für automatische Folgewellen und für „Neue Welle“ von Hand. Anfragen von vor dem Einschalten sind ausgenommen (6.4).
- Bei ausgeschaltetem Schalter ändert sich für Disponenten oder am Versand irgendetwas gegenüber heute.
- `dringend` wird durch Freigabe oder Schalter verzögert oder umgeleitet.
- Ein Push-Fehler bricht eine Welle ab oder verhindert, dass Angebote angelegt werden.
- Eine Push-Nachricht zeigt auf dem Sperrbildschirm Einsatzdetails (Kunde, Ort, Uhrzeit).

## 5. Entscheidungen von Sebastian

- **Wellen bleiben auch im Portal-Weg.** „wir lassen es bei wellen weil sonst müssen wir ja doppelte logik machen“. Eine Portal-Welle schaltet die Angebote nur für die Personen dieser Welle frei, die Folgewelle kommt nach dem eingestellten Abstand.
- **Freigabe gilt für die ganze Anfrage** samt aller Folgewellen. Das steht am Knopf, damit der Freigeber es weiß.
- **Nur eine Person gibt frei**, im HCM in der Disposition.
- **Push und „Auf den Startbildschirm“ kommen in dieser Runde mit.**
- **Angebote im Portal direkt auf der Startseite sichtbar**, wenn sich der Mitarbeiter einloggt.
- **Noch nicht scharf schalten.** „die freigabe logik soll dann noch nicht greifen erst mit rollout des portals“, weil heute kaum jemand das neue Portal nutzt.

## 6. Verhalten im Einzelnen

### 6.1 Schalter und Freigeber (Disposition → Einstellungen)

Zwei neue Einstellungen in `RecApplicantSettings`, gepflegt in `Livewire\Dispo\Settings`:

- `dispo_job_versandweg_aktiv` (an/aus, Vorgabe aus): „Versandweg-Auswahl und WhatsApp-Freigabe für Monats- und Wochenübersichten“.
- `dispo_job_freigeber_email`: die E-Mail-Adresse genau eines Plattform-Nutzers als Freigeber (kleingeschrieben verglichen). E-Mail statt Nutzer-ID nach dem Muster von `DispoAccess`: das SSO legt Konten anhand der E-Mail an.

Ist der Schalter an und kein Freigeber hinterlegt, warnt die Einstellungsseite deutlich: WhatsApp-Anfragen für Monats- und Wochenübersichten bleiben dann auf „wartet auf Freigabe“ stehen.

**Wer die beiden Einstellungen ändern darf** (Sebastian 13.11.): Solange kein Freigeber hinterlegt ist, jeder mit Zugang zur Einstellungsseite; den ersten Eintrag macht Sebastian beim Rollout. Sobald ein Freigeber hinterlegt ist, darf nur noch dieser selbst Schalter und Freigeber ändern. Alle anderen sehen die Felder gesperrt. Geprüft wird auf dem Server beim Speichern; die übrigen Einstellungen der Seite bleiben für alle wie heute speicherbar. Sonst könnte sich ein Disponent selbst eintragen oder den Schalter ausschalten und die Freigabe umgehen.

Schalter aus bedeutet: keine Auswahl beim Anlegen, jede Anfrage geht per WhatsApp sofort raus, keine Freigabe. Genau der heutige Stand.

### 6.2 Anlegen (Create)

Schalter an und Anlass `vier_wochen` oder `naechste_woche`: Auswahl „Mitarbeiterportal“ (vorgewählt) oder „WhatsApp (braucht Freigabe)“. Bei `dringend` gibt es keine Auswahl, der Weg ist WhatsApp.

- **Portal:** Die Anfrage braucht keine WhatsApp-Vorlage. Heute lässt sich ein Anlass nur wählen, wenn seine Vorlage hinterlegt ist (`anlassWaehlen`, `anlassVorbelegen`); für den Portal-Weg darf das kein Hindernis sein. Welle 1 startet sofort wie heute.
- **WhatsApp:** Die Anfrage wird mit Welle 1 angelegt, aber nicht gestartet. Zustand „wartet auf Freigabe“. Der Knopf heißt dann nicht „senden“, sondern sinngemäß „Freigabe beantragen“.

Der Weg steht fest an der Anfrage (neue Spalte, Werte `whatsapp` | `portal`, Altbestand und Schalter-aus = `whatsapp`) und gilt für alle ihre Wellen. Er lässt sich nachträglich nur über den Weg in 6.4 („abgelehnt → im Portal bereitstellen“) ändern.

### 6.3 Portal-Welle

`SendDispoJobWave` bleibt der eine Ort für Wellen. Bei Weg `portal`:

- keine Vorlagenprüfung (`DispoJobVorlagenWaechter`), kein Kanal, kein Aufruf des `WhatsAppMetaService`;
- je Empfänger dasselbe Angebot samt Schichten wie heute, mit Zeitstempel in `sent_at` (Bedeutung dann: „bereitgestellt“) und einer Kennzeichnung des Wegs am Angebot;
- Zähler, Fortschritt, „gedeckt“, Folgewelle, Stoppgründe unverändert;
- danach Push an die Personen dieser Welle (6.7), in eigener Fehlerbehandlung.

Die Empfängerplanung wird nicht danach gefiltert, ob jemand schon aufs neue Portal kommt. (Meine Festlegung: Der Schalter wird erst mit dem Rollout eingeschaltet, dann sind alle drauf. Ein Filter wäre die doppelte Logik, die Sebastian vermeiden will.) Damit diese Annahme nicht still bricht, zeigt die Einstellungsseite neben dem Schalter, wie viele aktive Mitarbeiter mit Status GO oder MA noch nicht aufs neue Portal umgestellt sind (`portal_v2_since` leer). Ist die Zahl größer null, steht dort eine Warnung: Diese Personen bekämen Portal-Angebote, die sie nicht sehen, und belegen trotzdem einen Platz in der Welle. Das Einschalten wird dadurch nicht verhindert.

In der Anfrage-Ansicht (`Show`) steht bei Portal-Wellen „im Portal bereitgestellt“ statt „gesendet“, und der Weg ist an der Anfrage sichtbar. Die Liste „Wer ist an dem Tag frei?“ zählt Portal-Angebote wie WhatsApp-Angebote als „angeschrieben“.

### 6.4 Freigabe

- **Wo:** In der Disposition bei „Jobs anbieten“. Die Übersicht (`Jobs\Index`) zeigt wartende Anfragen oben mit Zähler; die Navigation der Disposition trägt denselben Zähler. Sichtbar für alle, damit auch der Disponent sieht, dass seine Anfrage wartet.
- **Wer:** Nur der hinterlegte Freigeber sieht die Knöpfe „Freigeben“ und „Ablehnen“ auf der Anfrage. Geprüft wird auf dem Server, nicht nur in der Ansicht.
- **Text am Knopf „Freigeben“** (Bestätigungsdialog): „Mit der Freigabe gehen alle Wellen dieser Anfrage per WhatsApp raus, auch die automatischen Folgewellen. Welle 1 startet sofort an bis zu N Personen.“
- **Ja:** Freigabe wird mit Person und Zeit festgehalten, Welle 1 wird gestartet. Folgewellen und „Neue Welle“ brauchen keine weitere Freigabe.
- **Nein:** Ablehnung mit Person, Zeit und optionalem kurzem Grund. Es geht nichts raus. Der Disponent sieht „Freigabe abgelehnt“ samt Grund und hat zwei Knöpfe: „Stattdessen im Portal bereitstellen“ (Weg wird `portal`, Welle 1 startet) oder „Anfrage schließen“. (Meine Festlegung, damit die angelegte Anfrage nicht verloren ist.)
- **Freigeber legt selbst eine WhatsApp-Anfrage an:** Sie wartet genauso, er gibt sie mit demselben Knopf frei. (Meine Festlegung: ein einheitlicher Weg, die bewusste Bestätigung bleibt.)
- **Pflicht steht an der Anfrage:** Ob eine Anfrage eine Freigabe braucht, wird beim Anlegen festgehalten (Schalter an, Weg `whatsapp`, Anlass ungleich `dringend`) und ändert sich danach nicht mehr. (Meine Festlegung nach dem Prüfer-Durchgang.)
- **Sicherung im Job:** `SendDispoJobWave::handle()` sendet für eine Anfrage mit Freigabepflicht nur, wenn sie freigegeben ist. Sonst kehrt er zurück, ohne die Welle zu beanspruchen. Er liest nur die Anfrage, nicht die aktuelle Einstellung. Das fängt alle Nebenwege ab.
- **Einschalten:** Anfragen von vorher haben keine Freigabepflicht und laufen weiter, auch mit Wellen, die noch in der Warteschlange liegen (geplanter Start, Folgewelle im Abstand). Wer das am Rollout-Tag nicht will, schließt diese Anfragen vorher.
- **Ausschalten:** Anfragen, die auf Freigabe warten oder abgelehnt sind, bleiben in diesem Zustand. Sie kommen nur über Freigeben, „im Portal bereitstellen“ oder Schließen weiter. Neue Anfragen laufen wieder wie heute.
- **Nachvollziehbar:** Auf der Anfrage steht, wer wann beantragt, freigegeben oder abgelehnt hat.

### 6.5 Angebote im Portal

- **Startseite:** Hat die Person offene Angebote, steht oben eine Karte „N neue Einsätze für dich“ (Sie-Form nach `$duzen`). Tipp führt in den Bereich „Freie Einsätze“. Ohne Angebote keine Karte.
- **Bereich „Freie Einsätze“:** die bestehende Angebotsseite `JobOffers`, eingebettet nach dem Muster von `EmployeeAssignments` (`eingebettet`, ohne eigenen Kopf, lazy). Gleiche Regeln wie hinter dem WhatsApp-Link: Zusage, „Kann nicht“ bei dringend, „schon voll“, ein Einsatz pro Tag, Sperre bei gesperrtem Portal oder inaktivem Datensatz. Wo der Bereich im Portal sitzt (eigener Navigationspunkt oder oben im Reiter „Einsätze“), entscheidet der Builder nach dem vorhandenen Platz; die Startkarte muss in jedem Fall direkt dorthin führen.
- **Was erscheint:** alle Angebote der Person aus laufenden Anfragen, egal ob sie per Portal oder WhatsApp kamen, auch dringende. Im Portal ohne Einschränkung auf eine Anfrage.
- **Die Zahl N** ist dieselbe Menge, die die Angebotsseite als zusagbar zeigt (Tag frei, nicht voll, noch nicht beantwortet). Die Berechnung soll deshalb an einer Stelle liegen, damit Karte und Liste nie auseinanderlaufen.
- **Karte auf der Einsatz-Seite** `/recruiting/einsaetze/{token}` (nicht eingebettet, also hinter dem Bestätigungslink): gleiche Karte „N neue Einsätze für dich“, Tipp führt auf `/recruiting/jobs/{token}`. So erfahren auch Leute ohne Push und ohne neues Portal davon, ohne dass eine Meta-Vorlage geändert wird.

Dieser Abschnitt hängt nicht am Schalter. Er ist sofort live und zeigt bis zum Rollout eben die per WhatsApp verschickten Angebote. (Meine Festlegung: harmlos, und die Testnutzer können es schon prüfen.)

### 6.6 Portal als App auf dem Startbildschirm

- Das Portal bekommt ein Web-App-Manifest (Name, Symbol in RG-Markenfarben, Anzeige ohne Browserleiste) und einen Service Worker. Beides wird über Routen des Moduls ausgeliefert, nicht über publizierte Dateien (Assets in meingedeck nie neu publizieren, siehe Vorfall mit den Livewire-Assets).
- Auf der Startseite steht eine wegklickbare Karte „Portal als App nutzen“:
  - **Android/Chrome:** eigener Knopf, der die Installationsnachfrage des Handys auslöst (ein Tipp plus Bestätigung).
  - **iPhone/Safari:** Apple erlaubt keinen Knopf. Stattdessen eine kurze Anleitung in zwei Schritten mit Symbolen: „Teilen“ → „Zum Home-Bildschirm“.
  - Läuft das Portal schon als App, erscheint die Karte weiterhin, aber nur mit dem Push-Teil (auf dem iPhone lässt sich Push nur in der App einschalten). Der Installationsteil entfällt dann.
  - Browser, die weder den Installationsknopf noch die iPhone-Anleitung bekommen (z. B. Firefox auf Android, Chrome auf dem iPhone), sehen eine kurze Zeile mit dem Hinweis auf das Browsermenü.
- Randbedingung: Nach dem Öffnen vom Startbildschirm muss der Mitarbeiter in seinem Portal landen, höchstens mit einer Anmeldung dazwischen. Auf dem iPhone hat die App einen eigenen Speicher, getrennt von Safari. Wie Startadresse und Anmeldung zusammenspielen (Token-Adresse oder Konto-Anmeldung), klärt der Builder am vorhandenen Konto-Code (`KontoAnmelden`, `PortalShell`).

### 6.7 Push

- **Einschalten:** Knopf „Benachrichtigungen einschalten“ in derselben Karte bzw. darunter. Die Nachfrage des Handys kommt nur nach diesem Tipp. Auf dem iPhone ist der Knopf erst nutzbar, wenn das Portal als App läuft; vorher steht dort der Hinweis auf die Anleitung. Nach dem Einschalten kommt sofort eine Probe-Benachrichtigung („Benachrichtigungen sind eingeschaltet“), damit der Mitarbeiter und wir sehen, dass es geht.
- **Speichern:** Je Gerät ein Abo, gebunden an die Person (Identitäts-Gruppe, damit ein Ankerwechsel es nicht verliert). Meldet der Push-Dienst ein Abo als erloschen, wird es gelöscht. Ausschalten im Portal ist möglich.
- **Nur bekannte Push-Dienste:** Der Server nimmt nur Endpunkte an, die https ohne Benutzer/Passwort auf Port 443 sind und deren Host genau `fcm.googleapis.com` oder `updates.push.services.mozilla.com` ist oder auf `.push.apple.com`, `.notify.windows.com` oder `.push.services.mozilla.com` endet. Sonst schickte der Server seine Probe an jede Adresse, die ein Browser ihm nennt.
- **Probe nur bei Neuem:** Die Probe-Benachrichtigung geht nur raus, wenn das Gerät neu angemeldet wird oder die Person wechselt (anderer Besitzer des Endpunkts). Erneutes Speichern desselben Abos löst keine Probe aus.
- **Auslöser:** Nur Portal-Wellen. Nach dem Anlegen der Angebote einer Welle bekommt jede Person dieser Welle mit Abo eine Benachrichtigung. WhatsApp-Wellen lösen keinen Push aus.
- **Text:** „Neue Einsätze für dich“ / „Schau ins Portal und sag zu.“ Keine Kunden, Orte oder Zeiten. Tipp öffnet das Portal im Bereich „Freie Einsätze“.
- **Technik:** Web Push nach Standard mit VAPID-Schlüsseln aus der Umgebung der Host-App. Bibliothek `minishlink/web-push` als Abhängigkeit des Moduls. Versand über die Warteschlange. Fehlen die Schlüssel, wird nichts gesendet und der Einschalt-Knopf im Portal erscheint nicht; Wellen laufen unberührt.

## 7. Daten

Vom Builder auszugestalten, festgelegt ist nur der Inhalt:

- Anfrage: Weg (`whatsapp`|`portal`); Freigabe beantragt von/am; freigegeben von/am; abgelehnt von/am mit Grund. Der Zustand „wartet auf Freigabe“ muss in der Übersicht ohne Rechnen abfragbar sein.
- Angebot: Weg, auf dem es bereitgestellt wurde.
- Push-Abos: Person, Endpunkt, Schlüssel, Gerätehinweis, angelegt, zuletzt erfolgreich.
- Einstellungen: die zwei Schlüssel aus 6.1.

Bestehende Zeilen bleiben gültig: Weg `whatsapp`, gelten als freigegeben.

## 8. Tests und Abnahme

Nach der Konvention des Moduls (Unit rein, Integration mit Capsule). Mindestens:

- Schalter aus: Anlegen und Wellenlauf verhalten sich wie vor dem Umbau (bestehende Tests bleiben grün, plus ein Test, der die Auswahl und den Wartezustand ausschließt).
- Schalter an, Weg WhatsApp, nicht freigegeben: Job sendet nichts und beansprucht die Welle nicht; nach Freigabe sendet er. Dasselbe für Folgewelle und „Neue Welle“.
- `dringend` sendet bei Schalter an ohne Freigabe.
- Portal-Welle: Angebote entstehen, kein WhatsApp-Aufruf, Folgewelle entsteht nach denselben Regeln, Obergrenze greift.
- Freigeben/Ablehnen durch einen anderen Nutzer als den Freigeber wird auf dem Server abgewiesen.
- Schalter oder Freigeber ändern durch einen anderen Nutzer als den hinterlegten Freigeber wird auf dem Server abgewiesen; die übrigen Einstellungen desselben Speichervorgangs werden trotzdem gespeichert.
- Ablehnen → „im Portal bereitstellen“ startet Welle 1 als Portal-Welle.
- Zahl auf der Karte gleich Zahl der zusagbaren Zeilen der Angebotsseite.
- Push-Fehler (Dienst nicht erreichbar, Abo erloschen, Schlüssel fehlen) lässt die Welle sauber durchlaufen; erloschenes Abo ist danach weg.
- Geteilte Blade-Teile: `SharedPartialContractTest` und `tools/blade-check.php`.

Sichttests nach Deploy: Karte auf der Startseite, Bereich „Freie Einsätze“ in Hell und Dunkel, Installieren auf einem Android-Gerät und einem iPhone, Probe-Benachrichtigung auf beiden, Freigabe-Ablauf mit zwei Konten auf dem Demo-Team.

## 9. Deploy und Rollout

Reihenfolge, wie sie im Code dieses Zweigs tatsächlich gilt.

1. **Bump in meingedeck:** der übliche Befehl `composer update martin3r/platform-recruiting --no-interaction --no-scripts` zieht die neue Abhängigkeit `minishlink/web-push` mit (5 Pakete; ein Probelauf gegen meingedeck löste sauber auf, auf v9.0.4).
2. **Migrationen** (`php artisan migrate`), zwei Dateien:
   - `2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables`: neue Spalten an `rec_dispo_job_requests` (Versandweg, Freigabe) und `rec_dispo_job_offers` (Kanal).
   - `2026_11_13_000002_create_rec_push_abos_table`: Tabelle `rec_push_abos` für die Push-Abos.
3. **`php artisan queue:restart`** (der Wellen-Job hat sich geändert, laufende Worker nutzen sonst den alten Code) und **`php artisan view:clear`** (neue Portal-Ansichten).
4. **VAPID-Schlüssel** in der Umgebung von meingedeck eintragen (macht Sebastian auf Forge, danach Config neu laden bzw. Deploy). Namen:
   - `RECRUITING_VAPID_PUBLIC_KEY`
   - `RECRUITING_VAPID_PRIVATE_KEY`
   - `RECRUITING_VAPID_SUBJECT`: muss eine `mailto:`-Adresse oder eine https-Adresse sein. Ist er leer, nimmt der Versand `app.url`.

   Schlüsselpaar erzeugen (Bibliothek muss nach Schritt 1 installiert sein; Sebastian führt es selbst auf dem Server aus):

   ```
   php artisan tinker --execute='print_r(\Minishlink\WebPush\VAPID::createVapidKeys());'
   ```

   Ausgabe: `publicKey` kommt in `RECRUITING_VAPID_PUBLIC_KEY`, `privateKey` in `RECRUITING_VAPID_PRIVATE_KEY`. Den privaten Schlüssel nirgends ablegen außer in der Umgebung. Wechselt das Paar später, sind alle bestehenden Abos wertlos; die Mitarbeiter müssen Benachrichtigungen dann neu einschalten.
   
   Fehlen öffentlicher oder privater Schlüssel (oder die Bibliothek), ist Push aus: kein Einschalt-Knopf im Portal, nichts in der Warteschlange, Wellen laufen unberührt.
5. **Nach dem Deploy bleibt der Schalter aus.** Sofort nutzbar sind 6.5 (Angebote im Portal), 6.6 (Portal als App) und das Einschalten von Push samt Probe.
6. **Einstellungen** unter Disposition → Einstellungen, Abschnitt „Jobs anbieten: Versandweg“:
   - Kästchen „Versandweg-Auswahl und WhatsApp-Freigabe für Monats- und Wochenübersichten“ (`dispo_job_versandweg_aktiv`, Vorgabe aus),
   - Feld „E-Mail-Adresse des Freigebers“ (`dispo_job_freigeber_email`).

   Wer ändern darf: solange kein Freigeber eingetragen ist, jeder mit Zugang zur Seite; den Ersteintrag macht Sebastian. Danach nur noch der Freigeber selbst (serverseitig geprüft, die Felder sind für alle anderen gesperrt). Ist der Schalter an und kein Freigeber hinterlegt, bleiben WhatsApp-Anfragen auf „wartet auf Freigabe“ stehen.
7. **Rollout des Portal-Wegs:** erst auf dem Demo-Team komplett durchspielen, dann Freigeber eintragen und Schalter einschalten.

### Sichttests am Gerät

Vorher: VAPID-Schlüssel in der Umgebung, Deploy mit `view:clear`, Testperson mit `portal_v2_since`.

Android (Chrome):
- Portal anmelden: Karte „Portal als App nutzen“ mit Knopf „Auf den Startbildschirm“; Knopf löst den Installationsdialog aus, danach Symbol „Rheingedeck“ (petrol, „RG“) auf dem Startbildschirm.
- Vom Symbol öffnen: ohne Browserleiste, im eigenen Portal; die Karte zeigt keinen Installationsteil mehr.
- „Benachrichtigungen einschalten“: erst jetzt die Systemnachfrage, danach sofort die Probe „Benachrichtigungen sind eingeschaltet“; Karte zeigt „Benachrichtigungen sind an“ und „Ausschalten“. Probe antippen öffnet den Reiter Einsätze, ohne zweites Fenster.
- Portal-Welle auslösen (Demo-Team): „Neue Einsätze für dich“ kommt, Tipp führt zu „Freie Einsätze“.
- „Ausschalten“: neue Welle bringt nichts mehr, Zeile in `rec_push_abos` ist weg. Erlaubnis ablehnen: ruhiger Satz, keine erneute Nachfrage beim Neuladen. × auf der Karte: bleibt weg. Hell und dunkel ansehen.
- Samsung Internet mitprüfen (strenge Liste der Push-Dienste).

iPhone (iOS ab 16.4, Safari):
- In Safari: Karte mit Zwei-Schritte-Anleitung (Teilen, „Zum Home-Bildschirm“) und dem Satz, dass erst auf den Startbildschirm gelegt werden muss; kein Einschalt-Knopf.
- Vom Home-Bildschirm öffnen: Vollbild, einmal anmelden (eigener Speicher), Anleitung weg. Dort „Benachrichtigungen einschalten“: Systemnachfrage, danach Probe, auch bei gesperrtem Bildschirm; Antippen öffnet den Reiter Einsätze.
- Portal-Welle bringt eine Benachrichtigung, Ausschalten stoppt sie.
- Chrome oder In-App-Browser auf dem iPhone: keine Anleitung, nur die Menü-Zeile; Seite funktioniert unverändert.
- Geteiltes Gerät: A schaltet ein, B meldet sich an und sieht „Benachrichtigungen einschalten“; nach dem Tippen geht die Probe an B, A bekommt auf diesem Gerät nichts mehr.

Beide Geräte:
- Startkarte „N neue Einsätze“ zählt nach dem Zusagen eines Angebots neu.
- Freigabe-Ablauf mit zwei Konten auf dem Demo-Team: Disponent legt WhatsApp-Anfrage an, wartet auf Freigabe; Freigeber gibt frei bzw. lehnt ab (danach Portal); Zähler in Übersicht und Sidebar stimmen.

## 10. Dem Builder überlassen

- Schema und Namen der neuen Spalten und der Abo-Tabelle.
- Ob der Portal-Weg im bestehenden `DispoJobOfferSender` oder in einer eigenen Klasse daneben liegt.
- Platzierung des Bereichs „Freie Einsätze“ im Portal und Gestaltung der Karten im Stil von `_crew-stil` bzw. `portal-styles`.
- Startadresse des Manifests und Zusammenspiel mit der Anmeldung (Randbedingung in 6.6).
- Aufbau des Service Workers und Auslieferung über Modul-Routen.

## 11. Offene Punkte an Markus (blockieren den Bau nicht)

- Bestätigung, dass im Portal-Weg weiter in Wellen freigeschaltet wird (so gebaut, weil es dieselbe Logik wie bei WhatsApp ist).
- Bestätigung, dass seine Freigabe für die ganze Anfrage gilt und er der einzige Freigeber ist.
