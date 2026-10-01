<?php

namespace Platform\Recruiting\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Support\Arbeitserlaubnis;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\TriggerRegeln;

/**
 * Der Ausloeser: ein gebuchter Einsatz prueft, was dem Menschen davor fehlt.
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
 *  - `rec_persons.aufgaben_gemeldet_at` — wann zuletzt VERSUCHT wurde. Der
 *    Stempel ist der Anker der Pause und wird deshalb auch nach einem
 *    Fehlschlag gesetzt (siehe ET-23 unten).
 *  - `rec_persons.aufgaben_nachricht_id` — WELCHE Nachricht das war (ET-23).
 *  - `rec_dispo_assignments.aufgaben_erinnert_at` — die Erinnerung haengt an
 *    der EINBUCHUNG, weil sie sich auf genau diesen Einsatz bezieht.
 *
 * ALLE VIER WERDEN OBSERVER-FREI GESCHRIEBEN, ueber `DB::table(...)`, nie
 * ueber Eloquent. Dieses Modul exportiert an ZAS immer ganze Zeilen; eine
 * Eloquent-Schreibung liefe mit vollem Beobachter-Lauf und setzte den
 * Aenderungsmarker — eine blosse PRUEFUNG spuelte damit den Bestand in die
 * naechste ZAS-Update-Datei. Am 02.09.2026 hat genau das 505
 * Bestands-Mitarbeiter verschoben. Der HR-Fall ist die EINZIGE Ausnahme: an
 * `rec_hr_desk_cases` haengt kein Export-Marker, und er wird regulaer per
 * `create()` angelegt.
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
 * DIE BETRIEBSVORGABEN
 * ===================================================================
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
 * angeschrieben wurde, darf auch noch seine Erinnerung bekommen.
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
 * OHNE PERSONEN-ZEILE WIRD NICHT GEMELDET. Es gibt dann keinen Ort, an dem
 * "schon gemeldet" stehen koennte — gesendet wuerde also in JEDEM
 * stuendlichen Lauf erneut. Fail-closed: uebersprungen und im Bericht
 * genannt. Der HR-Fall entsteht trotzdem, denn er haengt am Mitarbeiter.
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

    public function handle(): int
    {
        $heute = now()->toDateString();
        $jetzt = now()->toDateTimeString();

        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $ids    = $this->idsOption();
        $dryRun = (bool) $this->option('dry-run');
        $welle  = $this->option('welle') !== null ? max(0, (int) $this->option('welle')) : 0;

        $this->info(sprintf(
            'Einsatz-Pruefung — Stichtag: %s · Team: %s · Welle: %s%s',
            $heute,
            $teamId !== null ? (string) $teamId : 'alle',
            $welle > 0 ? (string) $welle : 'KEINE — es wird niemand angeschrieben',
            $dryRun ? ' · TROCKENLAUF' : '',
        ));

        $gruppen = $this->gruppen($this->kandidaten($teamId, $ids, $heute));

        $z = [
            'menschen'        => count($gruppen),
            'anstellungen'    => 0,
            'ohne_person'     => 0,
            'abweichend'      => 0,
            'altes_portal'    => 0,
            'geraeumt'        => 0,
            'nachtraeglich'   => 0,
            'faellig_neu'     => 0,
            'faellig_erinn'   => 0,
            'gesendet_neu'    => 0,
            'gesendet_erinn'  => 0,
            'fehlversand'     => 0,
            'abgebrochen'     => 0,
            'faelle_neu'      => 0,
        ];

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

            // DER FEHLERKAEFIG. Ein Ausfall kostet einen Mitarbeiter, nicht
            // den Lauf — und er faerbt den Rueckgabewert, damit er nicht
            // still im Protokoll versickert.
            try {
                $stand   = $punkteLeser->fuer($rep, $heute);
                $punkte  = $stand['punkte'];
                $umfang  = $scope->forEmployee($rep);
                $personId = $rep->rec_person_id !== null ? (int) $rep->rec_person_id : null;

                // ET-17: den blinden Fleck zaehlen, nicht heilen.
                if ($umfang['abweichend'] !== []) {
                    $z['abweichend']++;
                }

                // ---- Die harte Sperre. Sie haengt am MITARBEITER und
                // braucht deshalb keine Personen-Zeile.
                if ($stand['gesperrt']) {
                    $gruende = Arbeitserlaubnis::gruende($punkte);
                    $gesperrt[] = sprintf('MA #%d (%s)', $rep->id, $gruende === [] ? 'ohne Angabe' : implode(', ', $gruende));

                    if (!$this->hatOffenenArbeitserlaubnisFall($umfang['ids'])) {
                        if (!$dryRun) {
                            $this->oeffneFall($rep, $gruende);
                        }
                        $z['faelle_neu']++;
                    }
                }

                if ($personId === null) {
                    $z['ohne_person']++;
                    continue;
                }

                $person = DB::table('rec_persons')->where('id', $personId)->first();
                if ($person === null) {
                    // Verwaister Verweis: dieselbe Lage wie gar keine Zeile.
                    $z['ohne_person']++;
                    continue;
                }

                // ---- ET-16: die leere Liste raeumt.
                if ($punkte === []) {
                    $stehtWas = $person->aufgaben_signatur !== null
                        || $person->aufgaben_gemeldet_at !== null
                        || $person->aufgaben_nachricht_id !== null;

                    if ($stehtWas && !$dryRun) {
                        DB::table('rec_persons')->where('id', $personId)->update([
                            'aufgaben_signatur'     => null,
                            'aufgaben_gemeldet_at'  => null,
                            'aufgaben_nachricht_id' => null,
                        ]);
                    }
                    if ($stehtWas) {
                        $z['geraeumt']++;
                    }
                    continue;
                }

                $letzteSignatur = $person->aufgaben_signatur;
                $gemeldetAm     = $person->aufgaben_gemeldet_at;

                // ---- ET-23: die gespeicherte Nachricht nachlesen.
                if ($letzteSignatur !== null
                    && $person->aufgaben_nachricht_id !== null
                    && $this->nachrichtGescheitert((int) $person->aufgaben_nachricht_id)) {
                    if (!$dryRun) {
                        // Der STEMPEL bleibt stehen: der zweite Versuch kommt
                        // nach der Pause, nicht in der naechsten Stunde.
                        DB::table('rec_persons')->where('id', $personId)->update([
                            'aufgaben_signatur'     => null,
                            'aufgaben_nachricht_id' => null,
                        ]);
                        $letzteSignatur = null;
                    }
                    $z['nachtraeglich']++;
                }

                $signatur = TriggerRegeln::signatur($punkte);
                $einsatz  = $stand['einsatz'];

                // OffenePunkte liefert nur KOMMENDE AUFTRAEGE (ET-13 in
                // EinsatzBezug::naechster) und projiziert status_id bewusst
                // weg (ET-8). Die Angabe hier ist deshalb eine
                // Wiederholung dieser Zusage, kein zweiter Filter: was
                // ueberhaupt ankommt, IST ein Auftrag.
                $loest = $einsatz !== null && EinsatzBezug::loestAus(
                    ['datum' => $einsatz['datum'], 'status_id' => RecDispoAssignment::STATUS_AUFTRAG],
                    $heute,
                    EinsatzBezug::VORLAUF_TAGE,
                );

                $darf = TriggerRegeln::darfMelden($letzteSignatur, $gemeldetAm, $signatur, $jetzt, EinsatzBezug::PAUSE_TAGE);

                // ---- ET-15: der Einsatz schlaegt die Pause.
                if (!$darf && $loest
                    && $this->einsatzVorPausenende($gemeldetAm, EinsatzBezug::PAUSE_TAGE, $einsatz['datum'])) {
                    // Dieselbe geprueften Funktion, nur ohne Pause: der
                    // Signatur-Waechter bleibt in Kraft, es faellt
                    // ausschliesslich die Wartezeit.
                    $darf = TriggerRegeln::darfMelden($letzteSignatur, $gemeldetAm, $signatur, $jetzt, 0);
                }

                $amAltenPortal = $rep->portal_v2_since === null;

                if ($darf && $loest) {
                    if ($amAltenPortal) {
                        $z['altes_portal']++;
                    } else {
                        $z['faellig_neu']++;

                        if (!$dryRun && $this->welleNimmt($schluessel, $welle, $angeschrieben)) {
                            $ergebnis = $sender->sende($rep, $stand, 'neu');
                            $angesprochen[] = sprintf('MA #%d (neu: %s)', $rep->id, $ergebnis);
                            $this->buchen($personId, $signatur, $ergebnis, $sender->letzteNachrichtId(), $z);
                        }
                    }
                }

                // ---- Die Erinnerung, je Einbuchung.
                if ($amAltenPortal) {
                    continue;
                }

                foreach ($this->erinnerungsKandidaten($umfang['ids']) as $einbuchung) {
                    $datum = $einbuchung->datum?->format('Y-m-d') ?? '';

                    if (!EinsatzBezug::erinnerungFaellig(
                        ['datum' => $datum, 'status_id' => (int) $einbuchung->status_id],
                        $heute,
                        EinsatzBezug::ERINNERUNG_TAGE,
                    )) {
                        continue;
                    }

                    $z['faellig_erinn']++;

                    if ($dryRun || !$this->welleNimmt($schluessel, $welle, $angeschrieben)) {
                        continue;
                    }

                    // Die Erinnerung nennt das Datum IHRER Einbuchung, nicht
                    // pauschal das des naechsten Einsatzes — sonst saehe der
                    // Mensch bei zwei Einsaetzen in der Frist zweimal
                    // denselben Tag.
                    $standFuerDiese = $stand;
                    $standFuerDiese['einsatz'] = [
                        'datum'      => $datum,
                        'taetigkeit' => $einbuchung->taetigkeit,
                        'event'      => $einbuchung->event?->name,
                    ];

                    $ergebnis = $sender->sende($rep, $standFuerDiese, 'erinnerung');
                    $angesprochen[] = sprintf('MA #%d (Erinnerung: %s)', $rep->id, $ergebnis);

                    if ($ergebnis === AufgabenSender::STATUS_SENT) {
                        // whereNull zusaetzlich: macht das Update wiederholbar,
                        // falls zwei Laeufe dieselbe Einbuchung treffen.
                        DB::table('rec_dispo_assignments')
                            ->where('id', $einbuchung->id)
                            ->whereNull('aufgaben_erinnert_at')
                            ->update(['aufgaben_erinnert_at' => now()]);
                        $z['gesendet_erinn']++;
                    } else {
                        // KEIN Stempel: eine nicht angenommene Erinnerung wird
                        // morgen erneut versucht, solange der Einsatz noch in
                        // der Frist liegt (Muster SendProofReminders).
                        $z['fehlversand']++;
                    }
                }
            } catch (\Throwable $e) {
                $z['abgebrochen']++;
                $this->error(sprintf('MA #%d: Abbruch — %s', $rep->id, $e->getMessage()));
                Log::error('[EinsatzPruefung] Abbruch bei einem Menschen, der Lauf geht weiter', [
                    'rec_employee_id' => $rep->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $this->bericht($z, $gesperrt, $angesprochen, $welle, $dryRun);

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
     * Die Einbuchungen, fuer die eine Erinnerung ueberhaupt in Frage kommt.
     *
     * `missing_since` muss leer sein: eine aus der ZAS-Lieferung gefallene
     * Einbuchung ist keine verlaessliche Grundlage. `aufgaben_erinnert_at`
     * muss leer sein: die Erinnerung geht einmal je Einbuchung.
     *
     * @param  list<int>  $umfangIds
     * @return \Illuminate\Support\Collection<int, RecDispoAssignment>
     */
    private function erinnerungsKandidaten(array $umfangIds)
    {
        return RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $umfangIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('aufgaben_erinnert_at')
            // ohne with('event') waere jedes $a->event?->name unten eine
            // eigene Abfrage.
            ->with('event')
            ->orderBy('datum')
            ->orderBy('id')
            ->get();
    }

    /**
     * Das Ergebnis eines Versands festhalten — observer-frei.
     *
     * Der STEMPEL geht in beide Richtungen, die SIGNATUR nur bei Erfolg
     * (ET-23, beide Richtungen): ein Fehlschlag gilt nicht als informiert,
     * bremst die Wiederholung aber auf die Pause herunter.
     *
     * `nicht_konfiguriert` schreibt GAR NICHTS: solange keine Meta-Vorlage
     * eingetragen ist, hat kein Versuch stattgefunden, den man bremsen
     * muesste — und sobald sie eingetragen ist, soll es sofort losgehen.
     *
     * @param  array<string,int>  $z
     */
    private function buchen(int $personId, string $signatur, string $ergebnis, ?int $nachrichtId, array &$z): void
    {
        if ($ergebnis === AufgabenSender::STATUS_NICHT_KONFIGURIERT) {
            $z['fehlversand']++;

            return;
        }

        if ($ergebnis === AufgabenSender::STATUS_SENT) {
            DB::table('rec_persons')->where('id', $personId)->update([
                'aufgaben_signatur'     => $signatur,
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
     * Gezaehlt werden MENSCHEN: wer schon drin ist, darf auch noch seine
     * Erinnerung bekommen, sonst bliebe eine halbe Ansprache stehen.
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
     */
    private function bericht(array $z, array $gesperrt, array $angesprochen, int $welle, bool $dryRun): void
    {
        $this->line(sprintf(
            'Geprueft: %d Mensch(en) in %d Anstellung(en).',
            $z['menschen'],
            $z['anstellungen'],
        ));

        $this->line(sprintf(
            'Faellig: %d Mensch(en) fuer eine neue Nachricht, %d Erinnerung(en).',
            $z['faellig_neu'],
            $z['faellig_erinn'],
        ));

        if ($dryRun) {
            $this->warn('TROCKENLAUF — es wurde nichts geschrieben und nichts verschickt.');
        } elseif ($welle === 0) {
            $this->warn('Ohne --welle wurde NIEMAND angeschrieben. Die Pruefung lief vollstaendig; '
                .'HR-Faelle und das Raeumen der Signatur sind davon nicht betroffen.');
        } else {
            $this->line(sprintf(
                'Verschickt: %d neu, %d Erinnerung(en), %d fehlgeschlagen.',
                $z['gesendet_neu'],
                $z['gesendet_erinn'],
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

        if ($z['abgebrochen'] > 0) {
            $this->error(sprintf('%d Mensch(en) mit Abbruch — siehe Protokoll.', $z['abgebrochen']));
        }
    }
}
