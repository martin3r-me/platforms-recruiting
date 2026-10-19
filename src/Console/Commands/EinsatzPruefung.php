<?php

namespace Platform\Recruiting\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\VertragsPruefung;
use Platform\Recruiting\Support\Arbeitserlaubnis;
use Platform\Recruiting\Support\Bote;
use Platform\Recruiting\Support\PilotKreis;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\PortalVorlagen;
use Platform\Recruiting\Support\TriggerRegeln;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\VertragsVorbelegung;

/**
 * Der Ausloeser: ein gebuchter Einsatz prueft, was dem Menschen davor fehlt.
 *
 * KUNDENENTSCHEIDUNG MARKUS 12.10.2026 (Go-live-Folie 8): „7 Tage Ruhe?
 * Mitarbeiter bekommt eine Info und sollte das im Portal als unerledigt
 * sehen … Keine weitere Info noetig als Nachricht, da er auf BAR steht."
 * Damit gibt es nur noch EINEN Nachrichten-Anlass: 'neu' (neue offene
 * Punkte, Signatur-Wechsel, Pause PAUSE_TAGE — unveraendert). Der Anlass
 * 'erinnerung' (kurz vor dem Einsatz) ist samt seiner Bremsen (F3, ET-26,
 * ET-27, F6-Erinnerungshaelfte) aus diesem Kommando ENTFERNT, nicht nur
 * abgeschaltet: kein Versand, kein Stempel `aufgaben_erinnert_at` mehr. Die
 * Spalte bleibt im Bestand (Migration unangetastet), wird hier aber nicht
 * mehr geschrieben. Die Absaetze unten zu F3/F6/ET-26/ET-27 sind
 * Befund-Historie und beschreiben den Stand VOR dieser Entscheidung.
 *
 * NACHTRAG 13.10.2026 (Markus, Folie 8: „Eine neue Nachricht kommt nur, wenn
 * ein neuer Punkt dazukommt."): der Signatur-Wechsel allein loest nicht mehr
 * aus. Neben der Signatur stehen jetzt die gemeldeten CODES an der Person
 * (`rec_persons.aufgaben_codes`, JSON-Liste); gemeldet wird nur, wenn
 * mindestens ein aktueller Code bei der letzten ERFOLGREICHEN Meldung fehlte
 * (TriggerRegeln::hatNeuenPunkt). Wird die Liste nur kleiner, bleibt es
 * still. Pause und ET-15 unveraendert. Altbestand ohne Codes: Hash-Vergleich
 * wie bisher, kein Schwall nach dem Deploy. Codes werden geschrieben und
 * geraeumt, wo die Signatur geschrieben und geraeumt wird.
 *
 * DUENNE HUELLE (Muster SendProofReminders, PersonPairAudit): die Regeln
 * liegen in reinen, einzeln geprueften Klassen — OffenePunkte (was ist
 * offen), EinsatzBezug (loest es aus), TriggerRegeln (darf gemeldet werden),
 * Arbeitserlaubnis (sperrt es), AufgabenSender (verschickt). Dieses Kommando
 * liest, fragt, schreibt. Es faellt keine Entscheidung selbst, die es nicht
 * ausdruecklich besitzt.
 *
 * LAEUFT NACH DEM DISPO-IMPORT, NICHT DARIN (Spec 3.3). Der Import soll
 * importieren; stolpert die Pruefung, darf das die Lieferung nicht
 * gefaehrden.
 *
 * ===================================================================
 * WAS DIESES KOMMANDO BESITZT — die Zustandsspalten
 * ===================================================================
 *
 *  - `rec_persons.aufgaben_signatur` — ueber GENAU DIESE Punktmenge wurde
 *    informiert. An der PERSON, nicht an der Anstellung: wer bei
 *    RHEINGEDECK und MA arbeitet, hat einen Ausweis und soll eine Nachricht
 *    bekommen, nicht zwei.
 *  - `rec_persons.aufgaben_codes` — WELCHE Punkte (Codes, JSON-Liste) die
 *    letzte erfolgreiche Meldung enthielt (Nachtrag 13.10.2026). Lebt und
 *    stirbt mit der Signatur.
 *  - `rec_persons.aufgaben_gemeldet_at` — wann zuletzt VERSUCHT wurde. Der
 *    Stempel ist der Anker der Pause und wird deshalb auch nach einem
 *    Fehlschlag gesetzt (siehe ET-23 unten).
 *  - `rec_persons.aufgaben_nachricht_id` — WELCHE Nachricht das war (ET-23).
 *  - `rec_dispo_assignments.aufgaben_erinnert_at` — die Erinnerung haengt an
 *    der EINBUCHUNG, weil sie sich auf genau diesen Einsatz bezieht.
 *    Seit Markus 12.10.2026 schreibt dieses Kommando die Spalte NICHT mehr
 *    (Erinnerung entfaellt); sie bleibt im Bestand stehen.
 *
 * ALLE FUENF WERDEN OBSERVER-FREI GESCHRIEBEN, ueber `DB::table(...)`, nie
 * ueber Eloquent. Dieses Modul exportiert an ZAS immer ganze Zeilen; eine
 * Eloquent-Schreibung liefe mit vollem Beobachter-Lauf und setzte den
 * Aenderungsmarker — eine blosse PRUEFUNG spuelte damit den Bestand in die
 * naechste ZAS-Update-Datei. Am 02.09.2026 hat genau das 505
 * Bestands-Mitarbeiter verschoben. Der HR-Fall ist die EINZIGE Ausnahme: an
 * `rec_hr_desk_cases` haengt kein Export-Marker, und er wird regulaer per
 * `create()` angelegt. Zweiter Fall-Grund: `contract_missing` (MA-Vertrags-
 * check), ebenfalls per `create()`; er schliesst sich selbst, sobald ein
 * unterschriebener AV alle kommenden Buchungen der Anstellung deckt.
 *
 * ===================================================================
 * DIE FUENF AUFLAGEN AUS DEN PRUEFUNGEN DER VORGAENGERAUFGABEN
 * ===================================================================
 *
 * ET-16 — DIE LEERE LISTE RAEUMT. Die Signatur wurde frueher nie geloescht.
 * Gemessener Ablauf: 01.01. fehlt der Ausweis, Nachricht raus, Signatur
 * steht. 30.01. ist der Ausweis da, die Liste ist leer — die Signatur bleibt
 * aber stehen. 20.07. laeuft derselbe Ausweis ab: dieselbe Punktmenge, also
 * dieselbe Signatur, also Schweigen, und zwar fuer immer. Das ist kein
 * Randfall, das ist der Normalfall ablaufender Dokumente. Deshalb: ist die
 * Liste leer, werden Signatur, Stempel und Nachrichten-Verweis geraeumt.
 * Spammen kann das nicht, weil ueber eine leere Liste ohnehin nie etwas
 * rausgeht (AufgabenSender verweigert bei null Punkten, ET-22).
 * DAMIT DAS GREIFT, reicht der Zielkreis "hat einen kommenden Auftrag"
 * NICHT: wer gerade nicht gebucht ist, wuerde nie geprueft und seine
 * Signatur nie geraeumt. Deshalb kommt JEDER, an dessen Person eine
 * Signatur steht, zusaetzlich in den Zielkreis (zweite Abfrage in
 * kandidaten()).
 *
 * ET-15 — DER EINSATZ SCHLAEGT DIE PAUSE. Gemessen: 03.10. Nachricht zu E1
 * am 20.10.; 05.10. kommt ein neuer Punkt dazu UND ein Einsatz E2 am 09.10.
 * `loestAus()` ist wahr, aber die Pause liefe bis zum 10.10. — die Nachricht
 * zu E2 kaeme NIE, denn danach ist E2 vorbei. Uebrig bliebe nur die
 * Erinnerung, also genau die Nachricht, die zu spaet kommt, um noch zu
 * helfen. Deshalb: liegt der ausloesende Einsatz VOR dem Ende der Pause,
 * gewinnt der Einsatz. Umgesetzt wird das, indem dieselbe geprueften
 * Funktion ein zweites Mal gefragt wird, nur mit Pause 0 — der
 * Signatur-Waechter bleibt dabei in Kraft, es faellt ausschliesslich die
 * Pause. Ein naher Einsatz hebt also NICHT alle Bremsen auf.
 *
 * ET-23 — `sent` IST KEIN BEWEIS DER ZUSTELLUNG. Der Status entsteht allein
 * aus dem HTTP-Ergebnis des Annahme-Aufrufs; dass eine Nachricht nicht
 * zugestellt werden konnte, meldet Meta erst spaeter per Webhook (Fall
 * 131026). Haekelte dieses Kommando auf `sent` hin ab, bekaeme der
 * Betroffene NIE WIEDER etwas. Deshalb liest der naechste Lauf die
 * gespeicherte Nachricht nach; steht sie auf `failed`, wird die SIGNATUR
 * geraeumt — der STEMPEL aber nicht. Das ist Absicht: so kommt der zweite
 * Versuch nach der Pause und nicht in der naechsten Stunde, sonst bekaeme
 * ein dauerhaft unerreichbarer Anschluss stuendlich einen Versuch.
 * NICHT PERFEKT, und das steht hier, damit es niemand spaeter entdeckt: im
 * Core-Webhook kann ein spaetes `sent` ein `failed` ueberschreiben
 * (bekannter Altfehler, nicht in diesem Zweig entstanden) — dann bleibt es
 * beim Schweigen. Strikt besser als Dauerschweigen ist es trotzdem.
 * DIE GEGENRICHTUNG IST EBENSO REAL: faellt die Ausnahme NACH dem HTTP-POST
 * (Thread, Nachricht und Protokoll entstehen erst danach), meldet der Sender
 * `failed`, obwohl die Nachricht drausen ist. Deshalb wird auch nach einem
 * Fehlschlag der STEMPEL gesetzt: die Wiederholung kommt dann nach sieben
 * Tagen statt stuendlich. Doppelt kann eine Nachricht so immer noch
 * ankommen — aber hoechstens einmal pro Woche, nicht zwoelfmal am Tag.
 *
 * ET-18 — DER TOTE STEMPEL ist NICHT hier geloest, sondern im Importer
 * (ZasDispoWebexportImporter): verschwindet eine Einbuchung aus der
 * Lieferung und taucht wieder auf, raeumt er `aufgaben_erinnert_at` mit.
 * Sonst verschluckt die Wiederholungsbremse eine voellig berechtigte
 * Erinnerung.
 *
 * ET-17 — DER BLINDE FLECK WIRD GENANNT, NICHT GEHEILT. `OffenePunkte`
 * benutzt bewusst nur `['ids']` des `PersonScopeResolver` und verwirft
 * `['abweichend']` — eine abweichende Behandlung von Nachweisen und
 * Einsaetzen liesse die beiden darueber uneins werden, WER der Mensch ist,
 * und das waere schlimmer als die Luecke darunter. Ohne `rec_person_id`
 * verlangt Zweig 2 des Resolvers zusaetzlich dieselbe Handynummer; weicht
 * sie ab, verschwindet der Einsatz der Schwester-Anstellung spurlos. Dieses
 * Kommando ZAEHLT diese Menschen und nennt die Zahl im Bericht. Geheilt
 * wird der Fleck ueber `rec_person_id` (recruiting:personen-anlegen).
 *
 * ===================================================================
 * DIE VIER FUNDE DER ABSCHLUSSPRUEFUNG (Fugen zwischen den Aufgaben)
 * ===================================================================
 *
 * F2 — EIN NAHER EINSATZ DARF KEINEN FERNEN VERDECKEN. `OffenePunkte`
 * liefert genau EINEN Bezug, den naechsten. Lag der naeher als die
 * Vorlaufzeit, loeste gar nichts aus, und ein spaeterer, fuer den die
 * Nachricht noch rechtzeitig gekommen waere, kam nie zur Sprache. Gemessen:
 * 28 Einbuchungen an 28 Tagen, 14 Laeufe, NULL "neu"-Nachrichten — und weil
 * nie eine Signatur entsteht, lief auch ET-16/ET-23 fuer diesen Menschen
 * leer. Getroffen war genau die Gruppe, auf die es ankommt. Gefragt wird
 * jetzt die ganze Menge der kommenden Auftraege; der erste mit genug
 * Vorlauf ist der Anlass und steht auch als Datum in der Nachricht.
 *
 * F3 — DIE ERINNERUNG HAT EINE BREMSE JE MENSCH. Sie hing an gar nichts
 * ausser dem Stempel je EINBUCHUNG: gemessen drei WhatsApp in EINEM Lauf,
 * 16 in 14 Tagen, und eine fuenftaegige Messe (eine Zeile je Tag) waere bis
 * zu fuenf gleiche Nachrichten gewesen. `--welle` bremst das nicht, die
 * zaehlt Menschen. Jetzt: hoechstens EINE Erinnerung je Mensch und Lauf,
 * dieselbe Pause wie bei der neuen Nachricht (gemessen am juengsten Stempel
 * ueber alle Anstellungen), und gestempelt werden ALLE in dieser Frist
 * faelligen Einbuchungen — die Nachricht spricht fuer sie alle.
 *
 * F4 — DER BOTE IST DIE ANSTELLUNG MIT DEM PORTAL, nicht die mit der
 * Buchung. Siehe Support\Bote::fuer().
 *
 * F6 — OHNE PERSONEN-ZEILE FAELLT NUR DIE NEUE NACHRICHT AUS, NICHT DIE
 * ERINNERUNG. Deren Zustand steht in `rec_dispo_assignments`, nicht in
 * `rec_persons`. Vorher sprang ein `continue` ueber beides — und fuer diese
 * Menschen ist die Erinnerung der EINZIGE Kanal, waehrend die Gruppe
 * nachwaechst (neue Anstellungen aus Funnel und ZAS entstehen weiterhin ohne
 * Personen-Zeile). Jeder neue Mitarbeiter war ab Anlage dauerhaft stumm.
 *
 * ET-26 — KEIN MENSCH BEKOMMT ZWEI NACHRICHTEN IN EINEM LAUF. Erst seit F2
 * ueberhaupt moeglich: vorher sperrte ein naher Einsatz die neue Nachricht.
 * Die neue gewinnt (sie nennt den Einsatz, fuer den die Aufgaben noch zu
 * schaffen sind), die Erinnerung entfaellt — und wird nach einem ECHTEN
 * Versand mitgestempelt, denn ueber dieselbe Punktliste ist der Mensch
 * informiert worden. Ohne Versand kein Stempel, sonst verbrennt der Lauf
 * eine Erinnerung an jemanden, der nichts bekommen hat.
 *
 * ET-27 — DIE PAUSE DARF AUCH AUF DEM ERINNERUNGS-PFAD KEINE NACHRICHT
 * HINTER IHREN EINSATZ SCHIEBEN. (abgedeckt() ist mit der Erinnerung
 * entfernt, Markus 12.10.2026.)
 *
 * ===================================================================
 * DIE BETRIEBSVORGABEN
 * ===================================================================
 *
 * ET-24 — EINE EIGENE LAUFSPERRE, NICHT NUR withoutOverlapping. Der
 * Zeitplan-Eintrag traegt `withoutOverlapping(30)`, aber das schuetzt den
 * Zeitplan nur gegen SICH SELBST. Der geplante Betriebsmodus ist ein
 * Mischbetrieb: stuendlich prueft der Zeitplan, von Hand werden die Wellen
 * gefahren. Zwei solche Laeufe gleichzeitig sind innerhalb EINES Laufs
 * unbedenklich (gruppen() fasst jeden Menschen zu genau einem Durchgang
 * zusammen), ZWISCHEN zwei Laeufen aber nicht: die Signatur wird erst NACH
 * dem Versand geschrieben, beide Laeufe lesen also denselben alten Stand und
 * schreiben beide. Der Mensch bekaeme die Nachricht zweimal, in derselben
 * Minute, und keine der Bremsen greift. Deshalb nimmt das Kommando eine
 * eigene Sperre, die Zeitplan- UND Handlauf erfasst.
 * EIN TROCKENLAUF BRAUCHT SIE NICHT und nimmt sie auch nicht: er schreibt
 * nichts und verschickt nichts, kann also nichts doppeln — und wer waehrend
 * des stuendlichen Laufs nachsehen will, soll das koennen.
 * EINE KOLLISION IST KEIN FEHLER: sie ist der vorgesehene Betrieb, und die
 * Arbeit holt der naechste Lauf nach. Deshalb SUCCESS mit deutlicher
 * Warnung und nicht FAILURE — sonst schlueg der Zeitplan jedes Mal Alarm,
 * wenn jemand von Hand eine Welle faehrt.
 *
 * OHNE --welle GEHT NICHTS RAUS. Dieselbe Bremse wie bei
 * recruiting:konto-einladen, und aus demselben Grund: ein Kommando, das
 * WhatsApp verschickt und keine Obergrenze kennt, ist eine Falle. Wie viele
 * es wirklich sind, weiss niemand vorher — getroffen wird nicht der Vorrat
 * (1321 von 1603 haben laut Vorflug keinen Ausweis), sondern nur, wer gerade
 * einen festen Auftrag mit genug Vorlauf hat, und diese Zahl haengt daran,
 * wie weit im Voraus die Dispo bucht. Die PRUEFUNG laeuft trotzdem
 * vollstaendig: der HR-Fall entsteht, die Signatur wird geraeumt, der
 * Bericht zeigt die ganze Wahrheit. Gedeckelt ist nur der Versand.
 * Gezaehlt werden MENSCHEN, nicht Nachrichten: wer in dieser Welle schon
 * angeschrieben wurde, darf auch noch seine Erinnerung bekommen. (Seit
 * Markus 12.10.2026 gibt es je Mensch und Lauf ohnehin nur noch die eine
 * Nachricht 'neu'.)
 *
 * --dry-run SCHREIBT NICHTS UND VERSCHICKT NICHTS — auch nicht das Raeumen
 * der Signatur. Ein Trockenlauf, der "nur ein bisschen" schreibt, ist
 * keiner.
 *
 * JEDER MENSCH IN SEINEM EIGENEN FEHLERKAEFIG. Ein Ausfall darf einen
 * Mitarbeiter kosten, nie den Durchlauf. Der RUECKGABEWERT meldet den
 * Fehlschlag (FAILURE), damit ein Zeitplan Alarm schlagen kann — an ihm
 * haengt die Ueberwachung, nicht am Text der Ausgabe.
 *
 * KENNUNGEN IN DER AUSGABE, NIE NAMEN UND NIE EINE RUFNUMMER. Die
 * Mitarbeiter-Kennung steht sehr wohl da (gleicher Schnitt wie im
 * AufgabenSender): ohne sie liesse sich nach einem Lauf ueber hunderte
 * Menschen nicht feststellen, wer gemeint ist.
 *
 * DAS ALTE PORTAL BEKOMMT KEINE NACHRICHT. Wer `portal_v2_since` nicht
 * gesetzt hat, laeuft im Portal auf eine 404-Seite — die Nachricht verweist
 * aber genau dorthin (Spec 2.1: das Portal traegt die Aufgaben, nicht die
 * Nachricht). Ein Versand waere eine Sackgasse UND wuerde den Menschen
 * zugleich als informiert abhaken. Die PRUEFUNG laeuft fuer ihn trotzdem:
 * der HR-Fall entsteht, und das ist die Haelfte, die auch ohne Portal
 * traegt. Derselbe Schnitt wie in SendProofReminders, dort ohne
 * Ausnahme-Option.
 *
 * OHNE PERSONEN-ZEILE WIRD DIE NEUE NACHRICHT NICHT GEMELDET. Es gibt dann
 * keinen Ort, an dem "schon gemeldet" stehen koennte — gesendet wuerde also
 * in JEDEM stuendlichen Lauf erneut. Fail-closed: uebersprungen und im
 * Bericht genannt. Der HR-Fall entsteht trotzdem, denn er haengt am
 * Mitarbeiter; und die ERINNERUNG geht ebenfalls raus (F6), denn ihr Zustand
 * haengt an der Einbuchung. (Die Erinnerung entfaellt seit Markus
 * 12.10.2026 — ohne Personen-Zeile geht also gar keine Nachricht mehr raus,
 * nur der HR-Fall entsteht.)
 * Geheilt wird das mit recruiting:personen-anlegen; neue Anstellungen aus
 * Funnel und ZAS entstehen weiterhin ohne Personen-Zeile, der Fleck waechst
 * also nach.
 */
class EinsatzPruefung extends Command
{
    protected $signature = 'recruiting:einsatz-pruefung
        {--team= : Nur Mitarbeiter dieses Teams}
        {--ids= : Bestimmte Mitarbeiter-Kennungen, komma-getrennt — sticht den regulaeren Zielkreis}
        {--welle= : Hoechstens so viele MENSCHEN auf einmal anschreiben. Ohne diese Angabe geht nichts raus.}
        {--dry-run : Nur zeigen, was passieren wuerde — schreibt nichts und verschickt nichts}';

    protected $description = 'Einsatz-Trigger: prueft vor einem gebuchten Einsatz, was dem Menschen fehlt, meldet es ihm und oeffnet bei fehlender Arbeitserlaubnis einen HR-Fall';

    /**
     * ET-24: der Schluessel der Laufsperre — ein Lauf im ganzen Haus.
     * Oeffentlich: auch HR-"Erneut erinnern" nimmt diese Sperre (Spec HR-Bereich §3.3).
     */
    public const LAUF_SPERRE = 'recruiting:einsatz-pruefung:lauf';

    /**
     * Grosszuegig, weil ein scharfer Lauf ueber den Bestand viele
     * Einzel-Aufrufe an Meta macht. Die Sperre verfaellt von selbst — ein
     * abgestuerzter Lauf legt das Kommando also nicht dauerhaft still.
     */
    private const SPERRE_SEKUNDEN = 1800;

    /** @var array<int, list<string>> contract_check_companies je Team, einmal je Lauf gelesen */
    private array $vertragsFirmenJeTeam = [];

    /** @var array<int, bool> einsatz_pruefung_nur_pilot je Team, einmal je Lauf gelesen */
    private array $nurPilotJeTeam = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ET-24: der Trockenlauf nimmt die Sperre nicht — er schreibt nichts
        // und verschickt nichts, kann also nichts doppeln.
        if ($dryRun) {
            return $this->lauf(true);
        }

        $sperre = Cache::lock(self::LAUF_SPERRE, self::SPERRE_SEKUNDEN);

        if (!$sperre->get()) {
            $this->warn('Es laeuft bereits eine Einsatz-Pruefung — dieser Lauf macht NICHTS. '
                .'Das ist der vorgesehene Betrieb (stuendlicher Lauf neben einem Hand-Lauf); '
                .'die Arbeit holt der naechste Lauf nach. Zum blossen Nachsehen genuegt --dry-run.');

            return self::SUCCESS;
        }

        try {
            return $this->lauf(false);
        } finally {
            $sperre->release();
        }
    }

    private function lauf(bool $dryRun): int
    {
        $heute = now()->toDateString();
        $jetzt = now()->toDateTimeString();

        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $ids    = $this->idsOption();
        $welle  = $this->option('welle') !== null ? max(0, (int) $this->option('welle')) : 0;

        $this->info(sprintf(
            'Einsatz-Pruefung — Stichtag: %s · Team: %s · Welle: %s%s',
            $heute,
            $teamId !== null ? (string) $teamId : 'alle',
            $welle > 0 ? (string) $welle : 'KEINE — es wird niemand angeschrieben',
            $dryRun ? ' · TROCKENLAUF' : '',
        ));

        // Task 6: der Team-Schalter, VOR jedem Pfad, der schreibt (HR-Faelle,
        // Signatur-Raeumen, Vertragspruefung, Versand). --ids hat keine
        // Hintertuer; nur der Trockenlauf sieht durch den Schalter hindurch.
        $gruppen = $this->gruppen($this->nurEingeschalteteTeams($this->kandidaten($teamId, $ids, $heute), $dryRun));

        $z = [
            'menschen'        => count($gruppen),
            'anstellungen'    => 0,
            'ohne_person'     => 0,
            'abweichend'      => 0,
            'altes_portal'    => 0,
            'geraeumt'        => 0,
            'nachtraeglich'   => 0,
            'faellig_neu'     => 0,
            'gesendet_neu'    => 0,
            'fehlversand'     => 0,
            'abgebrochen'     => 0,
            'faelle_neu'      => 0,
            'vertrag_buchungen'   => 0,
            'vertrag_ohne'        => 0,
            'vertrag_faelle_neu'  => 0,
            'vertrag_geschlossen' => 0,
        ];

        /** @var list<string> $nichtImPilot Kennungen der Menschen, deren HR-Fall die Pilot-Sperre zurueckhielt — nie Namen */
        $nichtImPilot = [];

        /** @var list<string> $gesperrt Kennungen mit Grund — nie Namen */
        $gesperrt = [];

        /** @var list<string> $angesprochen Kennungen der tatsaechlich Angeschriebenen — nie Namen, nie Nummern */
        $angesprochen = [];

        /** @var array<string, true> $angeschrieben Die Welle zaehlt Menschen, nicht Nachrichten */
        $angeschrieben = [];

        $punkteLeser = new OffenePunkte();
        $scope       = new PersonScopeResolver();
        $sender      = app(AufgabenSender::class);

        foreach ($gruppen as $schluessel => $anstellungen) {
            $z['anstellungen'] += count($anstellungen);
            /** @var RecEmployee $rep */
            $rep = $anstellungen[0];

            // Der Vertragscheck hat einen eigenen Kaefig; feuert danach auch
            // der aeussere, zaehlt der Mensch trotzdem nur einmal.
            $vertragAbgebrochen = false;

            // DER FEHLERKAEFIG. Ein Ausfall kostet einen Mitarbeiter, nicht
            // den Lauf — und er faerbt den Rueckgabewert, damit er nicht
            // still im Protokoll versickert.
            try {
                // fuerTrigger: frisch per WhatsApp gemeldete Dokumente bleiben PAUSE_TAGE still (Spec Dokumente §6).
                $stand   = $punkteLeser->fuerTrigger($rep, $heute);
                $punkte  = $stand['punkte'];
                $umfang  = $scope->forEmployee($rep);
                $personId = $rep->rec_person_id !== null ? (int) $rep->rec_person_id : null;

                // ET-17: den blinden Fleck zaehlen, nicht heilen.
                if ($umfang['abweichend'] !== []) {
                    $z['abweichend']++;
                }

                // ---- Pilot-Sperre der HR-Faelle (Sebastian 19.10.2026: neue
                // Features nur fuer die Pilotgruppe). Wer keine umgestellte
                // Anstellung hat, bekaeme ohnehin keine Nachricht (Bote) — ein
                // HR-Fall fuer ihn waere Arbeit am alten Bestand, die niemand
                // bestellt hat. Es wird NUR das Anlegen zurueckgehalten:
                // bestehende offene Faelle bleiben stehen (kein Aufraeumen).
                $darfFallAnlegen = !$this->nurPilot((int) $rep->team_id) || PilotKreis::istImPilot((int) $rep->id);
                $pilotGesperrt = false;

                // ---- Die harte Sperre. Sie haengt am MITARBEITER und
                // braucht deshalb keine Personen-Zeile.
                if ($stand['gesperrt']) {
                    $gruende = Arbeitserlaubnis::gruende($punkte);
                    $gesperrt[] = sprintf('MA #%d (%s)', $rep->id, $gruende === [] ? 'ohne Angabe' : implode(', ', $gruende));

                    if (!$this->hatOffenenArbeitserlaubnisFall($umfang['ids'])) {
                        if (!$darfFallAnlegen) {
                            $pilotGesperrt = true;
                        } else {
                            if (!$dryRun) {
                                $this->oeffneFall($rep, $gruende);
                            }
                            $z['faelle_neu']++;
                        }
                    }
                }

                // ---- MA-Vertragscheck (Spec Vertrag aus der Akte §3.2). VOR
                // dem Kurzschluss darunter: wer sonst nichts offen hat, wuerde
                // sonst nie geprueft. Dieselbe Menge kommender Auftraege wie
                // fuer den Anlass (eine Abfrage, kein Auseinanderlaufen).
                // Eigener Fehlerkaefig: ein Fehler im Vertragscheck kostet
                // nicht Nachricht und Raeumen dieses Menschen,
                // faerbt aber den Lauf (abgebrochen) wie jeder andere Abbruch.
                $kommende = $this->kommendeAuftraege($umfang['ids'], $heute);
                try {
                    $this->vertragsPruefung($rep, $umfang['ids'], $kommende, $heute, $dryRun, $z, $darfFallAnlegen, $pilotGesperrt);
                } catch (\Throwable $e) {
                    $z['abgebrochen']++;
                    $vertragAbgebrochen = true;
                    $this->error(sprintf('MA #%d: Vertragsprüfung abgebrochen — %s', $rep->id, $e->getMessage()));
                    Log::error('[EinsatzPruefung] Vertragspruefung abgebrochen, der Rest der Pruefung laeuft weiter', [
                        'rec_employee_id' => $rep->id,
                        'error'           => $e->getMessage(),
                        'exception'       => $e,
                    ]);
                }

                // Einmal je Mensch, auch wenn beide Fallarten zurueckgehalten wurden.
                if ($pilotGesperrt) {
                    $nichtImPilot[] = sprintf('MA #%d', $rep->id);
                }

                // ---- Ist nichts offen, ist auch nichts zu melden. Vorher
                // raeumen (ET-16), dann fertig.
                if ($punkte === []) {
                    $this->raeumen($personId, $dryRun, $z);
                    continue;
                }

                // ---- F4: WER TRAEGT DIE NACHRICHT? Nicht die Anstellung, an
                // der gerade eine Einbuchung haengt, sondern die, ueber die
                // dieser MENSCH sein Portal erreicht. portal_v2_since,
                // Telefonnummer, Portal-Token und Kanal stehen alle an der
                // ANSTELLUNG, nicht am Menschen — und bei einem
                // Doppelbeschaeftigten RG+MA ist "eine Zeile umgestellt, eine
                // nicht" der Normalfall einer Pilotumstellung. Vorher
                // entschied die kleinste Kennung UNTER DEN KANDIDATEN, also
                // der Zufall, wo gerade gebucht ist: derselbe Mensch bekam je
                // nach Reihenfolge alles oder gar nichts.
                $bote = Bote::fuer($umfang['ids']);
                $amAltenPortal = $bote === null;

                // ---- F2: NICHT NUR DER NAECHSTE EINSATZ. OffenePunkte
                // liefert GENAU EINEN Bezug (den naechsten). Liegt der naeher
                // als die Vorlaufzeit, loeste frueher gar nichts aus — ein
                // spaeterer Einsatz, der ausloesen WUERDE, kam nie zur
                // Sprache. Beim regelmaessig Gebuchten war das kein
                // Aufschub, sondern Dauerschweigen: gemessen 28 Einbuchungen
                // an 28 Tagen, 14 Laeufe, null "neu"-Nachrichten — und weil
                // nie eine Signatur entsteht, lief auch der ganze
                // ET-16/ET-23-Haushalt fuer ihn leer. Gefragt wird deshalb
                // die ganze Menge der kommenden Auftraege, und der ERSTE mit
                // genug Vorlauf wird der Anlass.
                $ausloeser = $this->ausloesenderEinsatz($kommende, $heute);
                $loest     = $ausloeser !== null;

                // ---- Die neue Nachricht. NUR sie braucht die Personen-Zeile:
                // ihr Zustand (Signatur, Stempel) liegt in rec_persons.
                $person = $personId !== null
                    ? DB::table('rec_persons')->where('id', $personId)->first()
                    : null;

                if ($person === null) {
                    // Keine oder eine verwaiste Personen-Zeile: keine neue
                    // Nachricht (kein Ort fuer "schon gemeldet"). Die
                    // Erinnerung, die hier frueher noch lief (F6), entfaellt
                    // seit Markus 12.10.2026.
                    $z['ohne_person']++;
                } else {
                    $letzteSignatur = $person->aufgaben_signatur;
                    $gemeldetAm     = $person->aufgaben_gemeldet_at;
                    // Nachtrag 13.10.2026: null = Altbestand (nur Hash) →
                    // darfMelden() vergleicht wie bisher die Signatur.
                    $letzteCodes    = TriggerRegeln::codesAusSpalte($person->aufgaben_codes ?? null);

                    // ---- ET-23: die gespeicherte Nachricht nachlesen.
                    if ($letzteSignatur !== null
                        && $person->aufgaben_nachricht_id !== null
                        && $this->nachrichtGescheitert((int) $person->aufgaben_nachricht_id)) {
                        if (!$dryRun) {
                            // Der STEMPEL bleibt stehen: der zweite Versuch
                            // kommt nach der Pause, nicht in der naechsten
                            // Stunde.
                            DB::table('rec_persons')->where('id', $personId)->update([
                                'aufgaben_signatur'     => null,
                                'aufgaben_codes'        => null,
                                'aufgaben_nachricht_id' => null,
                            ]);
                        }
                        // F5: AUSSERHALB des Schreibzweigs. Vorher rechnete
                        // der Trockenlauf mit der alten Signatur weiter und
                        // meldete weniger Faellige als der scharfe Lauf
                        // (gemessen 0 gegen 1) — ausgerechnet die Zahl, nach
                        // der jemand die Wellengroesse bemisst, und in der
                        // untertreibenden Richtung falsch.
                        $letzteSignatur = null;
                        $letzteCodes    = null;
                        $z['nachtraeglich']++;
                    }

                    $signatur = TriggerRegeln::signatur($punkte);
                    $codes    = TriggerRegeln::codes($punkte);

                    $darf = TriggerRegeln::darfMelden($letzteSignatur, $gemeldetAm, $signatur, $jetzt, EinsatzBezug::PAUSE_TAGE, $letzteCodes, $codes);

                    // ---- ET-15: der Einsatz schlaegt die Pause.
                    if (!$darf && $loest
                        && $this->einsatzVorPausenende($gemeldetAm, EinsatzBezug::PAUSE_TAGE, $ausloeser['datum'])) {
                        // Dieselbe geprueften Funktion, nur ohne Pause: der
                        // Signatur-Waechter bleibt in Kraft, es faellt
                        // ausschliesslich die Wartezeit.
                        // Auch der Neu-Punkt-Waechter (13.10.2026) bleibt.
                        $darf = TriggerRegeln::darfMelden($letzteSignatur, $gemeldetAm, $signatur, $jetzt, 0, $letzteCodes, $codes);
                    }

                    if ($darf && $loest) {
                        if ($amAltenPortal) {
                            $z['altes_portal']++;
                        } else {
                            $z['faellig_neu']++;

                            if (!$dryRun && $this->welleNimmt($schluessel, $welle, $angeschrieben)) {
                                // Der Anlass ist der AUSLOESENDE Einsatz, nicht
                                // der naechste: er ist der, fuer den die
                                // Nachricht noch rechtzeitig kommt.
                                $standFuerNeu = $stand;
                                $standFuerNeu['einsatz'] = $ausloeser;

                                $ergebnis = $sender->sende($bote, $standFuerNeu, 'neu');
                                $angesprochen[] = sprintf('MA #%d (neu: %s)', $bote->id, $ergebnis);
                                $this->buchen($personId, $signatur, TriggerRegeln::codesFuerSpalte($punkte), $ergebnis, $sender->letzteNachrichtId(), $z);
                            }
                        }
                    }
                }

                // ---- Die Erinnerung vor dem Einsatz ENTFAELLT
                // (Kundenentscheidung Markus 12.10.2026, siehe Klassen-
                // Docblock): hier stand der Erinnerungs-Pfad (F3, ET-26,
                // ET-27, F6). Kein Versand, kein Stempel
                // aufgaben_erinnert_at. Wer offen hat, sieht es im Portal.
            } catch (\Throwable $e) {
                if (!$vertragAbgebrochen) {
                    $z['abgebrochen']++;
                }
                $this->error(sprintf('MA #%d: Abbruch — %s', $rep->id, $e->getMessage()));
                Log::error('[EinsatzPruefung] Abbruch bei einem Menschen, der Lauf geht weiter', [
                    'rec_employee_id' => $rep->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $this->bericht($z, $gesperrt, $angesprochen, $welle, $dryRun, $nichtImPilot);

        return $z['abgebrochen'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // =================================================================
    // Zielkreis
    // =================================================================

    /**
     * Wer wird geprueft?
     *
     * Zwei Quellen, und die zweite ist nicht optional (ET-16): wer eine
     * Signatur traegt, muss geprueft werden, auch ohne kommenden Auftrag —
     * sonst wird seine Signatur nie geraeumt und er verstummt dauerhaft.
     *
     * KEIN is_active-Filter: die Autoritaet ist der AUFTRAG. Eine beendete
     * Anstellung mit kommendem Auftrag ist ein Widerspruch in den Daten, und
     * ihn hier still wegzufiltern hiesse, einen Einsatz ohne Nachweise
     * stattfinden zu lassen.
     *
     * @param  list<int>|null  $ids
     * @return list<int>
     */
    private function kandidaten(?int $teamId, ?array $ids, string $heute): array
    {
        if ($ids !== null) {
            // Die ausdrueckliche Auswahl sticht den Zielkreis: sie ist das
            // Werkzeug fuer die Diagnose eines Einzelfalls.
            return $ids;
        }

        $ausEinsatz = DB::table('rec_dispo_assignments as a')
            ->join('rec_employees as e', 'e.id', '=', 'a.rec_employee_id')
            ->where('a.status_id', RecDispoAssignment::STATUS_AUFTRAG)
            // GUERTEL UND HOSENTRAEGER, und das steht hier, damit niemand
            // diese Zeile fuer die tragende haelt: eine verschwundene
            // Einbuchung wird weiter unten ohnehin zweimal ausgeschlossen —
            // in OffenePunkte::naechsterEinsatz() und in
            // kommendeAuftraege(). Faellt sie hier weg, kaeme der Mensch
            // zwar in den Zielkreis, bekaeme aber trotzdem nichts. Sie bleibt
            // stehen, weil sie den Zielkreis klein haelt, nicht weil eine
            // Regel an ihr haengt.
            ->whereNull('a.missing_since')
            ->where('a.datum', '>=', $heute)
            ->when($teamId !== null, fn ($q) => $q->where('e.team_id', $teamId))
            ->distinct()
            ->pluck('a.rec_employee_id')
            ->all();

        $mitSignatur = DB::table('rec_employees as e')
            ->join('rec_persons as p', 'p.id', '=', 'e.rec_person_id')
            ->whereNotNull('p.aufgaben_signatur')
            ->when($teamId !== null, fn ($q) => $q->where('e.team_id', $teamId))
            ->pluck('e.id')
            ->all();

        return array_values(array_unique(array_map('intval', array_merge($ausEinsatz, $mitSignatur))));
    }

    /**
     * Die Kandidaten nach MENSCH buendeln.
     *
     * Der Schluessel ist die Personen-Zeile, wo es eine gibt, sonst die
     * Anstellung. Ohne diese Buendelung bekaeme ein Doppel-Beschaeftigter
     * (RHEINGEDECK und MA) jede Nachricht zweimal — die Nachweise gehoeren
     * dem Menschen, nicht der Anstellung.
     *
     * @param  list<int>  $kandidaten
     * @return array<string, list<RecEmployee>>
     */
    private function gruppen(array $kandidaten): array
    {
        if ($kandidaten === []) {
            return [];
        }

        $gruppen = [];

        RecEmployee::query()
            ->whereIn('id', $kandidaten)
            ->orderBy('id')
            ->chunkById(200, function ($menschen) use (&$gruppen) {
                foreach ($menschen as $employee) {
                    $schluessel = $employee->rec_person_id !== null
                        ? 'person:'.$employee->rec_person_id
                        : 'ma:'.$employee->id;
                    $gruppen[$schluessel][] = $employee;
                }
            });

        return $gruppen;
    }

    /**
     * Der Team-Schalter einsatz_pruefung_aktiv (Vorgabe aus). Im scharfen
     * Lauf fallen die Kandidaten ausgeschalteter Teams heraus, bevor
     * irgendetwas geschrieben wird; der Trockenlauf behaelt sie (er soll die
     * Zahlen zeigen, BEVOR jemand einschaltet) und sagt nur dazu, dass der
     * scharfe Lauf sie ueberspringen wuerde. Gelesen wird OHNE
     * getOrCreateForTeam() — eine Pruefung legt keine Zeilen an.
     *
     * @param  list<int>  $kandidaten
     * @return list<int>
     */
    private function nurEingeschalteteTeams(array $kandidaten, bool $dryRun): array
    {
        if ($kandidaten === []) {
            return [];
        }

        /** @var array<int, int> $teamJeId */
        $teamJeId = [];
        foreach (array_chunk($kandidaten, 500) as $stueck) {
            foreach (DB::table('rec_employees')->whereIn('id', $stueck)->pluck('team_id', 'id') as $id => $team) {
                $teamJeId[(int) $id] = (int) $team;
            }
        }

        $teams = array_values(array_unique($teamJeId));
        sort($teams);

        $aus = [];
        foreach ($teams as $team) {
            if (PortalVorlagen::einsatzPruefungAktivOhneAnlage($team)) {
                continue;
            }
            $aus[$team] = true;

            if ($dryRun) {
                $this->line(sprintf('Team %d: Schalter aus — im scharfen Lauf uebersprungen', $team));
            } else {
                $this->warn(sprintf('Team %d: Einsatz-Pruefung ist aus (%s) — uebersprungen', $team, PortalVorlagen::ORT));
            }
        }

        if ($dryRun || $aus === []) {
            return $kandidaten;
        }

        return array_values(array_filter(
            $kandidaten,
            static fn (int $id) => isset($teamJeId[$id]) && !isset($aus[$teamJeId[$id]]),
        ));
    }

    /** @return list<int>|null */
    private function idsOption(): ?array
    {
        $roh = $this->option('ids');
        if ($roh === null) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', trim((string) $roh), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ))));

        return $ids;
    }

    // =================================================================
    // Die einzelnen Schritte
    // =================================================================

    /** @param list<int> $umfangIds Alle Anstellungen dieses Menschen */
    private function hatOffenenArbeitserlaubnisFall(array $umfangIds): bool
    {
        return RecHrDeskCase::query()
            ->whereIn('rec_employee_id', $umfangIds)
            ->where('reason', RecHrDeskCase::REASON_WORK_PERMIT)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->exists();
    }

    /** @param list<string> $gruende */
    private function oeffneFall(RecEmployee $employee, array $gruende): void
    {
        // Eloquent, und das ist hier richtig: an rec_hr_desk_cases haengt
        // kein ZAS-Export-Marker, und der creating-Hook vergibt die uuid.
        RecHrDeskCase::create([
            'rec_applicant_id' => null,
            'rec_employee_id'  => $employee->id,
            'team_id'          => $employee->team_id,
            'reason'           => RecHrDeskCase::REASON_WORK_PERMIT,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => now(),
            'notes'            => 'Einsatz-Pruefung: Arbeitserlaubnis fehlt oder ist abgelaufen ('
                .($gruende === [] ? 'ohne Angabe' : implode(', ', $gruende)).').',
        ]);
    }

    /**
     * Spec Vertrag aus der Akte §3.2-§3.4. Faelle per Eloquent (kein
     * ZAS-Marker an rec_hr_desk_cases); der Trockenlauf zaehlt nur.
     *
     * @param  list<int>  $umfangIds
     * @param  \Illuminate\Support\Collection<int, RecDispoAssignment>  $kommende
     * @param  array<string,int>  $z
     * @param  bool  $darfAnlegen  false = Pilot-Sperre: kein neuer Fall, kein Verschieben
     * @param  bool  $pilotGesperrt  wird true, wenn ein Fall wegen der Pilot-Sperre unterblieb
     */
    private function vertragsPruefung(RecEmployee $rep, array $umfangIds, $kommende, string $heute, bool $dryRun, array &$z, bool $darfAnlegen = true, bool &$pilotGesperrt = false): void
    {
        $firmen = $this->vertragsFirmen((int) $rep->team_id);
        if ($firmen === []) {
            return;
        }

        // Befunde kommen je Anstellung UND Gesellschaft; Fall und Schliessen
        // haengen aber nur an der Anstellung. Deshalb erst je Anstellung
        // buendeln — sonst schloss ein gedeckter RG-Befund den Fall eines
        // ungedeckten MA-Befunds an derselben Zeile, und der naechste Lauf
        // oeffnete ihn neu (Review Task 8, Fund 1).
        $jeAnstellung = [];
        foreach ((new VertragsPruefung())->pruefe($umfangIds, $kommende, $firmen, VertragsPruefung::horizontBis($heute)) as $befund) {
            $z['vertrag_buchungen'] += $befund['buchungen'];
            $z['vertrag_ohne'] += $befund['ohne'];
            $jeAnstellung[$befund['anstellung_id']][] = $befund;
        }

        // Erst verschieben (Schlussreview N3): ein alter Fall einer
        // Gesellschaft, die jetzt an einer ANDEREN Zeile geprueft wird, darf
        // die Zeile nicht mehr blockieren (kein neuer Fall daneben) und nicht
        // mit der falschen Notiz "unterschrieben" schliessen. Verschobene
        // Faelle zaehlen unten nicht mehr als offen — auch im Trockenlauf.
        // Pilot-Sperre: Verschieben ist Schliessen HIER plus Anlegen DORT. Ohne
        // das Anlegen bliebe nur das Schliessen — ein offener Fall verschwaende.
        // Deshalb bleibt er bei gesperrtem Anlegen, wo er ist (unberuehrt).
        $verschoben = $darfAnlegen ? $this->verschiebeVertragsFaelle($umfangIds, $jeAnstellung, $dryRun) : [];
        $z['vertrag_geschlossen'] += count($verschoben);

        foreach ($jeAnstellung as $anstellungId => $befunde) {
            // Der frueheste ungedeckte Tag ueber alle Gesellschaften; seine
            // Firma steht in der Notiz.
            $ungedeckt = null;
            $alleUnterschrieben = true;
            $vertragId = null;
            foreach ($befunde as $befund) {
                if ($befund['deckung'] === VertragsDeckung::KEINER) {
                    if ($ungedeckt === null || (string) $befund['erster_tag'] < (string) $ungedeckt['erster_tag']) {
                        $ungedeckt = $befund;
                    }
                }
                if ($befund['deckung'] !== VertragsDeckung::UNTERSCHRIEBEN) {
                    $alleUnterschrieben = false;
                }
                $vertragId ??= $befund['vertrag_id'];
            }

            if ($ungedeckt !== null) {
                if (!$this->hatOffenenVertragsFall((int) $anstellungId, $verschoben)
                    && !$this->vonHrErledigt((int) $anstellungId, (string) $ungedeckt['erster_tag'])) {
                    if (!$darfAnlegen) {
                        $pilotGesperrt = true;
                    } else {
                        if (!$dryRun) {
                            $this->oeffneVertragsFall($ungedeckt);
                        }
                        $z['vertrag_faelle_neu']++;
                    }
                }

                continue;
            }

            // §3.4: erst wenn ALLE kommenden Buchungen dieser Anstellung, in
            // jeder geprueften Gesellschaft, unterschrieben gedeckt sind.
            // "unterwegs" schliesst nicht.
            if ($alleUnterschrieben) {
                $z['vertrag_geschlossen'] += $this->schliesseVertragsFaelle(
                    (int) $anstellungId, $vertragId, $dryRun, $verschoben, self::firmenDerBefunde($befunde)
                );
            }
        }
    }

    /**
     * @param  list<array>  $befunde
     * @return array<string, true>
     */
    private static function firmenDerBefunde(array $befunde): array
    {
        $firmen = [];
        foreach ($befunde as $befund) {
            $firmen[(string) $befund['firma']] = true;
        }

        return $firmen;
    }

    /**
     * Schlussreview I2: ein von HR geschlossener Fall (resolved_by_user_id,
     * z. B. "Papiervertrag liegt vor") bleibt zu, solange der frueheste
     * ungedeckte Einsatztag nicht NACH dem Einsatztag eines solchen Falls
     * liegt. Der Tag kommt aus der Notiz (VertragsVorbelegung, die eine
     * Formatstelle); eine unlesbare Notiz sperrt nichts.
     */
    private function vonHrErledigt(int $anstellungId, string $ersterTag): bool
    {
        $tage = RecHrDeskCase::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', '!=', RecHrDeskCase::STATUS_OPEN)
            ->whereNotNull('resolved_by_user_id')
            ->pluck('notes')
            ->map(fn ($notiz) => VertragsVorbelegung::einsatztagAusNotiz($notiz))
            ->filter();

        return $tage->isNotEmpty() && $ersterTag <= (string) $tage->max();
    }

    /**
     * Ledger T8-1: wechselt die gepruefte Anstellung (die MA-Zeile ist neu
     * da), schliesst sich der offene Fall an der bisherigen Zeile derselben
     * Person — wenn die Gesellschaft seiner Notiz jetzt an einer ANDEREN
     * Anstellung geprueft wird. Der neue Fall dort entsteht im selben Lauf.
     *
     * Waechter gegen Flattern je Anstellung UND Gesellschaft (Schlussreview
     * N3): bleibt nur, wenn seine Zeile selbst noch fuer die Gesellschaft
     * seiner Notiz geprueft wird. Ein alter MA-Fall an der RG-Zeile wandert
     * also auch dann, wenn die RG-Zeile noch fuer RG geprueft wird.
     *
     * @param  list<int>  $umfangIds
     * @param  array<int, list<array>>  $jeAnstellung
     * @return list<int>  IDs der verschobenen Faelle (im Trockenlauf: die, die es waeren)
     */
    private function verschiebeVertragsFaelle(array $umfangIds, array $jeAnstellung, bool $dryRun): array
    {
        $zielJeFirma = [];
        foreach ($jeAnstellung as $anstellungId => $befunde) {
            foreach ($befunde as $befund) {
                $zielJeFirma[$befund['firma']] ??= (int) $anstellungId;
            }
        }
        if ($zielJeFirma === []) {
            return [];
        }

        $faelle = RecHrDeskCase::query()
            ->whereIn('rec_employee_id', $umfangIds)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->get();

        $verschoben = [];
        foreach ($faelle as $fall) {
            $firma = VertragsVorbelegung::firmaAusNotiz($fall->notes);
            $zeile = (int) $fall->rec_employee_id;
            // Wird die Zeile des Falls selbst noch fuer SEINE Gesellschaft
            // geprueft (z. B. zwei gebuchte Zeilen ohne MA-Akte), bleibt er —
            // sonst schloesse und oeffnete jeder Lauf ihn neu. Unlesbare
            // Notiz: wie bisher nur die Zeile.
            if (array_key_exists($zeile, $jeAnstellung)
                && ($firma === null || isset(self::firmenDerBefunde($jeAnstellung[$zeile])[$firma]))) {
                continue;
            }
            $ziel = $zielJeFirma[$firma ?? ''] ?? null;
            if ($ziel === null || $ziel === $zeile) {
                continue;
            }
            if (!$dryRun) {
                $fall->update([
                    'status'           => RecHrDeskCase::STATUS_APPROVED,
                    'resolved_at'      => now(),
                    'resolution_notes' => sprintf('Automatisch: verschoben auf Akte #%d', $ziel),
                ]);
            }
            $verschoben[] = (int) $fall->id;
        }

        return $verschoben;
    }

    /**
     * Ohne Settings-Zeile oder ohne Schluessel: Default [] (aus). Query Builder
     * statt getOrCreateForTeam() — eine Pruefung legt keine Zeilen an.
     *
     * @return list<string>
     */
    private function vertragsFirmen(int $teamId): array
    {
        if (!array_key_exists($teamId, $this->vertragsFirmenJeTeam)) {
            $roh = DB::table('rec_applicant_settings')->where('team_id', $teamId)->value('settings');
            $settings = is_string($roh) ? (json_decode($roh, true) ?: []) : (is_array($roh) ? $roh : []);
            $wert = array_key_exists('contract_check_companies', $settings)
                ? $settings['contract_check_companies']
                : RecApplicantSettings::DEFAULT_SETTINGS['contract_check_companies'];

            $this->vertragsFirmenJeTeam[$teamId] = array_values(array_unique(array_filter(
                array_map(static fn ($f) => strtoupper(trim((string) $f)), (array) $wert),
                static fn (string $f) => $f !== ''
            )));
        }

        return $this->vertragsFirmenJeTeam[$teamId];
    }

    /**
     * Pilot-Sperre der HR-Faelle je Team. Ohne Settings-Zeile oder ohne
     * Schluessel: Vorgabe AN. Ein unlesbarer Wert sperrt ebenfalls
     * (fail-closed) — ausgeschaltet ist nur ein ausdrueckliches false/0/off.
     * Query Builder statt getOrCreateForTeam(): eine Pruefung legt keine Zeilen an.
     */
    private function nurPilot(int $teamId): bool
    {
        if (!array_key_exists($teamId, $this->nurPilotJeTeam)) {
            $roh = DB::table('rec_applicant_settings')->where('team_id', $teamId)->value('settings');
            $settings = is_string($roh) ? (json_decode($roh, true) ?: []) : (is_array($roh) ? $roh : []);
            $wert = $settings[PortalVorlagen::EINSATZ_PRUEFUNG_NUR_PILOT] ?? null;

            $this->nurPilotJeTeam[$teamId] = $wert === null
                ? (bool) RecApplicantSettings::DEFAULT_SETTINGS[PortalVorlagen::EINSATZ_PRUEFUNG_NUR_PILOT]
                : (filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);
        }

        return $this->nurPilotJeTeam[$teamId];
    }

    /** @param list<int> $ausser  in diesem Lauf verschobene Faelle */
    private function hatOffenenVertragsFall(int $anstellungId, array $ausser = []): bool
    {
        return RecHrDeskCase::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->when($ausser !== [], fn ($q) => $q->whereNotIn('id', $ausser))
            ->exists();
    }

    /** @param array{anstellung_id:int, team_id:int, firma:string, erster_tag:?string, event:?string, taetigkeit:?string} $befund */
    private function oeffneVertragsFall(array $befund): void
    {
        RecHrDeskCase::create([
            'rec_applicant_id' => null,
            'rec_employee_id'  => $befund['anstellung_id'],
            'team_id'          => $befund['team_id'],
            'reason'           => RecHrDeskCase::REASON_CONTRACT_MISSING,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => now(),
            'notes'            => VertragsVorbelegung::notiz((string) $befund['erster_tag'], $befund['event'], $befund['taetigkeit'], $befund['firma']),
        ]);
    }

    /**
     * Schliesst nur Faelle, deren Gesellschaft an dieser Zeile geprueft (und
     * damit unterschrieben gedeckt) ist — ein Fall einer anderen
     * Gesellschaft bekaeme sonst die falsche Notiz (Schlussreview N3).
     * Unlesbare Notiz: wie bisher.
     *
     * @param  list<int>  $ausser  in diesem Lauf verschobene Faelle
     * @param  array<string, true>  $firmen  an dieser Zeile gepruefte Gesellschaften
     */
    private function schliesseVertragsFaelle(int $anstellungId, ?int $vertragId, bool $dryRun, array $ausser = [], array $firmen = []): int
    {
        $faelle = RecHrDeskCase::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->when($ausser !== [], fn ($q) => $q->whereNotIn('id', $ausser))
            ->get()
            ->filter(function (RecHrDeskCase $fall) use ($firmen) {
                $firma = VertragsVorbelegung::firmaAusNotiz($fall->notes);

                return $firma === null || $firmen === [] || isset($firmen[$firma]);
            });

        if (!$dryRun) {
            foreach ($faelle as $fall) {
                $fall->update([
                    'status'           => RecHrDeskCase::STATUS_APPROVED,
                    'resolved_at'      => now(),
                    'resolution_notes' => sprintf('Automatisch: Arbeitsvertrag unterschrieben (#%d)', (int) $vertragId),
                ]);
            }
        }

        return $faelle->count();
    }

    /**
     * Steht die gespeicherte Nachricht inzwischen auf `failed`? (ET-23)
     *
     * Ein toter Verweis (die Nachricht wurde geloescht) gilt als "nichts
     * nachzulesen" und NICHT als Fehlschlag — sonst wuerde das Aufraeumen
     * alter Nachrichten eine Versandwelle ausloesen.
     */
    private function nachrichtGescheitert(int $nachrichtId): bool
    {
        return CommsWhatsAppMessage::query()->whereKey($nachrichtId)->value('status') === 'failed';
    }

    /**
     * ET-15: liegt der Einsatz vor dem Ende der Pause?
     *
     * Auf den TAG gerechnet, nicht auf die Stunde: der Einsatz hat nur ein
     * Datum. Ein Einsatz AM Tag des Pausenendes zaehlt damit als "nach der
     * Pause" — die vorsichtige Richtung, denn an diesem Tag laeuft die Pause
     * ohnehin ab und die Nachricht geht reguler raus.
     */
    private function einsatzVorPausenende(?string $gemeldetAm, int $pauseTage, string $einsatzDatum): bool
    {
        if ($gemeldetAm === null || $gemeldetAm === '' || $einsatzDatum === '') {
            return false;
        }

        try {
            $pausenende = (new DateTimeImmutable($gemeldetAm))->setTime(0, 0)->modify('+'.$pauseTage.' days');
            $einsatz    = (new DateTimeImmutable($einsatzDatum))->setTime(0, 0);
        } catch (\Throwable) {
            return false;
        }

        return $einsatz < $pausenende;
    }

    /**
     * ALLE kommenden Auftraege dieses Menschen, frueheste zuerst — die
     * gemeinsame Grundlage fuer den Anlass (F2) und den Vertragscheck (bis
     * Markus 12.10.2026 auch fuer die inzwischen entfallene Erinnerung).
     *
     * Eine Abfrage statt mehrerer: alle brauchen dieselbe Menge, und
     * mehrere Abfragen koennten zwischen ihnen auseinanderlaufen.
     *
     * `missing_since` muss leer sein: eine aus der ZAS-Lieferung gefallene
     * Einbuchung ist keine verlaessliche Grundlage fuer eine Nachricht.
     *
     * @param  list<int>  $umfangIds
     * @return \Illuminate\Support\Collection<int, RecDispoAssignment>
     */
    private function kommendeAuftraege(array $umfangIds, string $heute)
    {
        return RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $umfangIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->where('datum', '>=', $heute)
            // ohne with('event') waere jedes $a->event?->name eine eigene
            // Abfrage.
            ->with('event')
            ->orderBy('datum')
            ->orderBy('id')
            ->get();
    }

    /**
     * F2: der ERSTE kommende Auftrag mit genug Vorlauf — nicht der naechste.
     *
     * Der naechste kann zu nah sein; dann loest er nicht aus, und ein
     * spaeterer, fuer den die Nachricht noch rechtzeitig kaeme, bliebe
     * ungesehen. Weil die Liste nach Datum sortiert ist, ist der erste
     * Treffer zugleich der frueheste, fuer den die Nachricht noch hilft.
     *
     * @param  \Illuminate\Support\Collection<int, RecDispoAssignment>  $kommende
     * @return array{datum:string, taetigkeit:?string, event:?string}|null
     */
    private function ausloesenderEinsatz($kommende, string $heute): ?array
    {
        foreach ($kommende as $einbuchung) {
            $datum = $einbuchung->datum?->format('Y-m-d') ?? '';

            // status_id steht hier, weil loestAus() es verlangt; gefiltert
            // hat darauf schon die Abfrage (ET-8/ET-13: eine Wiederholung
            // der Zusage, kein zweiter Filter).
            if (EinsatzBezug::loestAus(
                ['datum' => $datum, 'status_id' => (int) $einbuchung->status_id],
                $heute,
                EinsatzBezug::VORLAUF_TAGE,
            )) {
                return [
                    'datum'      => $datum,
                    'taetigkeit' => $einbuchung->taetigkeit,
                    'event'      => $einbuchung->event?->name,
                ];
            }
        }

        return null;
    }

    /**
     * ET-16: ist nichts mehr offen, verschwindet der ganze gespeicherte
     * Zustand. Ohne Personen-Zeile gibt es nichts zu raeumen — dann wird nur
     * gezaehlt.
     *
     * @param  array<string,int>  $z
     */
    private function raeumen(?int $personId, bool $dryRun, array &$z): void
    {
        $person = $personId !== null
            ? DB::table('rec_persons')->where('id', $personId)->first()
            : null;

        if ($person === null) {
            $z['ohne_person']++;

            return;
        }

        $stehtWas = $person->aufgaben_signatur !== null
            || ($person->aufgaben_codes ?? null) !== null
            || $person->aufgaben_gemeldet_at !== null
            || $person->aufgaben_nachricht_id !== null;

        if (!$stehtWas) {
            return;
        }

        if (!$dryRun) {
            DB::table('rec_persons')->where('id', $personId)->update([
                'aufgaben_signatur'     => null,
                'aufgaben_codes'        => null,
                'aufgaben_gemeldet_at'  => null,
                'aufgaben_nachricht_id' => null,
            ]);
        }

        $z['geraeumt']++;
    }

    /**
     * Das Ergebnis eines Versands festhalten — observer-frei.
     *
     * Der STEMPEL geht in beide Richtungen, die SIGNATUR (und seit 13.10.2026
 * die CODES) nur bei Erfolg
     * (ET-23, beide Richtungen): ein Fehlschlag gilt nicht als informiert,
     * bremst die Wiederholung aber auf die Pause herunter.
     *
     * `nicht_konfiguriert` und `vorlage_untauglich` schreiben GAR NICHTS
     * (F1): solange keine brauchbare Meta-Vorlage in den Einstellungen gewaehlt ist, hat kein
     * Versuch bei Meta stattgefunden, den man bremsen muesste — und sobald
     * sie richtig steht, soll es ohne Wartezeit losgehen. Ein Stempel waere
     * hier das Gegenteil von Vorsicht: er machte aus einem
     * Konfigurationsfehler eine woechentlich wiederholte, aussichtslose
     * Versandwelle ueber den ganzen Bestand.
     *
     * @param  array<string,int>  $z
     */
    private function buchen(int $personId, string $signatur, ?string $codes, string $ergebnis, ?int $nachrichtId, array &$z): void
    {
        if ($ergebnis === AufgabenSender::STATUS_NICHT_KONFIGURIERT
            || $ergebnis === AufgabenSender::STATUS_VORLAGE_UNTAUGLICH) {
            $z['fehlversand']++;

            return;
        }

        if ($ergebnis === AufgabenSender::STATUS_SENT) {
            DB::table('rec_persons')->where('id', $personId)->update([
                'aufgaben_signatur'     => $signatur,
                // Nachtrag 13.10.2026: die Codes der ERFOLGREICHEN Meldung —
                // nur bei Erfolg, genau wie die Signatur (ET-23).
                'aufgaben_codes'        => $codes,
                'aufgaben_gemeldet_at'  => now(),
                'aufgaben_nachricht_id' => $nachrichtId,
            ]);
            $z['gesendet_neu']++;

            return;
        }

        DB::table('rec_persons')->where('id', $personId)->update([
            'aufgaben_gemeldet_at' => now(),
        ]);
        $z['fehlversand']++;
    }

    /**
     * Passt dieser Mensch noch in die Welle?
     *
     * Gezaehlt werden MENSCHEN: wer schon drin ist, zaehlt nicht doppelt.
     * (Bis Markus 12.10.2026 durfte er so noch seine Erinnerung bekommen.)
     *
     * @param  array<string, true>  $angeschrieben
     */
    private function welleNimmt(string $schluessel, int $welle, array &$angeschrieben): bool
    {
        if (isset($angeschrieben[$schluessel])) {
            return true;
        }

        if (count($angeschrieben) >= $welle) {
            return false;
        }

        $angeschrieben[$schluessel] = true;

        return true;
    }

    /**
     * @param  array<string,int>  $z
     * @param  list<string>  $gesperrt
     * @param  list<string>  $angesprochen
     * @param  list<string>  $nichtImPilot
     */
    private function bericht(array $z, array $gesperrt, array $angesprochen, int $welle, bool $dryRun, array $nichtImPilot = []): void
    {
        $this->line(sprintf(
            'Geprueft: %d Mensch(en) in %d Anstellung(en).',
            $z['menschen'],
            $z['anstellungen'],
        ));

        // Nur noch die neue Nachricht — die Erinnerung vor dem Einsatz
        // entfaellt (Markus 12.10.2026).
        $this->line(sprintf(
            'Faellig: %d Mensch(en) fuer eine neue Nachricht.',
            $z['faellig_neu'],
        ));

        if ($dryRun) {
            $this->warn('TROCKENLAUF — es wurde nichts geschrieben und nichts verschickt.');
        } elseif ($welle === 0) {
            $this->warn('Ohne --welle wurde NIEMAND angeschrieben. Die Pruefung lief vollstaendig; '
                .'HR-Faelle und das Raeumen der Signatur sind davon nicht betroffen.');
        } else {
            $this->line(sprintf(
                'Verschickt: %d neu, %d fehlgeschlagen.',
                $z['gesendet_neu'],
                $z['fehlversand'],
            ));

            if ($angesprochen !== []) {
                // Die Spur, wer wirklich angesprochen wurde: Kennungen, nie
                // Namen und nie eine Rufnummer. Ohne sie liesse sich nach
                // einem Lauf ueber hunderte Menschen nicht feststellen, wen
                // es getroffen hat.
                $this->line('  '.implode(' · ', $angesprochen));
            }
        }

        $this->line(sprintf('Signatur geraeumt (nichts mehr offen): %d.', $z['geraeumt']));
        $this->line(sprintf('Signatur geraeumt (Nachricht nachtraeglich gescheitert): %d.', $z['nachtraeglich']));
        $this->line(sprintf(
            'Uebersprungen, weil altes Portal (ohne portal_v2_since fuehrt der Knopf auf eine 404-Seite): %d.',
            $z['altes_portal'],
        ));

        // ET-17: der blinde Fleck, als Zahl statt als Schweigen.
        $this->line(sprintf(
            'Uebersprungen, weil ohne Personen-Zeile: %d · davon mit abweichender Nummer: %d '
            .'(ihre Zweit-Anstellung ist fuer diese Pruefung unsichtbar — Heilweg: recruiting:personen-anlegen).',
            $z['ohne_person'],
            $z['abweichend'],
        ));

        $this->line(sprintf('Arbeitserlaubnis fehlt: %d Mensch(en), davon %d neu auf dem HR-Schreibtisch.',
            count($gesperrt),
            $z['faelle_neu'],
        ));

        if ($gesperrt !== []) {
            // Die Kennungen, damit HR nachsehen kann — nie Namen.
            $this->line('  '.implode(' · ', $gesperrt));
        }

        $this->line(sprintf(
            'Vertragsprüfung: %d Buchungen geprüft, %d ohne Vertrag, %d Fälle neu, %d Fälle geschlossen.',
            $z['vertrag_buchungen'],
            $z['vertrag_ohne'],
            $z['vertrag_faelle_neu'],
            $z['vertrag_geschlossen'],
        ));

        // Pilot-Sperre: wer einen HR-Fall bekommen HAETTE, aber keine
        // umgestellte Anstellung hat. Gezaehlt je Mensch, Kennungen statt Namen.
        $this->line(sprintf(
            'Nicht im Pilot, übersprungen: %d (HR-Fall nicht angelegt — Schalter „HR-Fälle nur für die Pilotgruppe").',
            count($nichtImPilot),
        ));
        if ($nichtImPilot !== []) {
            $this->line('  '.implode(' · ', $nichtImPilot));
        }

        if ($z['abgebrochen'] > 0) {
            $this->error(sprintf('%d Mensch(en) mit Abbruch — siehe Protokoll.', $z['abgebrochen']));
        }
    }
}
