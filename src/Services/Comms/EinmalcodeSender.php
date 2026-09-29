<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Support\CodeDrossel;
use Platform\Recruiting\Support\Einmalcode;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Verschickt GENAU EINEN Einmalcode per WhatsApp — die Nachricht, mit der
 * jemand sein Konto einrichtet, sein Passwort zuruecksetzt oder seine
 * Handynummer wechselt (Canvas 68, Spec §2, Aufgabe 8).
 *
 * VORBILD IST ProofReminderSender, und zwar wegen der Fehler, die dessen
 * Docblock benennt. Zwei davon treffen hier genauso:
 *
 *  1. `RecEmployee::sendPortalNotification()` meldet `ok: true` direkt nach
 *     `sendTemplate()`, OHNE `$message->status` zu pruefen — ein von Meta
 *     abgelehnter Versand gilt dort als Erfolg. Diese Klasse prueft den
 *     Status und meldet `STATUS_FAILED`. Sonst wartet jemand auf einen Code,
 *     der nie ankam, und das Protokoll sagt "verschickt".
 *  2. `HoldingTemplateComponents::build()` setzt bei einem UNBEKANNTEN
 *     Platzhalter still den Vornamen (oder den Beispielwert der Vorlage)
 *     ein. Heisst der Platzhalter in der Meta-Vorlage anders als gedacht,
 *     bekaeme jemand seinen Vornamen statt des Codes zugeschickt, Meta
 *     naehme es an, und der Versand gaelte als Erfolg — waehrend der Code,
 *     den der Mensch vorher hatte, ueberschrieben waere. Deshalb wird diese
 *     geteilte Klasse hier GAR NICHT benutzt: die Parameter baut
 *     `parameter()` selbst, und ein Platzhalter, den wir nicht befuellen
 *     koennen, verhindert den Versand (`unbefuellbarePlatzhalter()`).
 *
 * RULING GD-1, DIE DROSSEL — der eigentliche Grund dieser Klasse.
 * `CodeDrossel` stand seit Aufgabe 3 fertig da und war bis hierher nirgends
 * verdrahtet; der Umsetzer von `KontoWriter` hat sie bewusst liegen lassen,
 * weil sie Zeitpunkte zaehlt, die `KontoWriter` nicht fuehrt. Sie gehoert an
 * die Stelle, die den Versand ausloest, also hierher. Hoechstens drei Codes
 * je Nummer und Stunde, hoechstens fuenf am Kalendertag.
 *
 * DAZU EIN ZWEITER ZAEHLER JE PERSON, mit denselben Grenzen (Fund F4). Der
 * Zaehler je Nummer bremst das ZIEL; beim Nummernwechsel bestimmt der
 * Anfordernde das Ziel aber selbst, und jede neue Zielnummer braechte einen
 * frischen Zaehler mit. Ein Angemeldeter koennte so unbegrenzt
 * Vorlagennachrichten an FREMDE Nummern schicken, auf unsere Rechnung und
 * unter unserem Absender. Der zweite Zaehler bremst deshalb den AUSLOESER.
 * Beide muessen zustimmen; keiner kann fuer den anderen einstehen.
 *
 * WOHER DIE ZEITPUNKTE KOMMEN: aus dem CACHE, unter einem Schluessel je
 * Nummer (`drosselSchluessel()`), als Liste von 'Y-m-d H:i:s'-Zeitpunkten.
 * Es gibt dafuer heute keine Spalte, und es soll auch keine geben:
 *  - Eine Spalte an `rec_persons` koennte den Zaehler je NUMMER nicht
 *    leisten. Beim Nummernwechsel geht der Code an eine Nummer, die noch an
 *    keiner Person haengt; dieser Zaehler muss an der Nummer haengen, nicht
 *    an der Zeile. (Der zweite, je Person, koennte dort stehen — aber dann
 *    laegen die beiden Haelften derselben Bremse an zwei Orten mit zwei
 *    Lebensdauern.)
 *  - `rec_auto_pilot_logs` braucht zwingend eine `rec_applicant_id` — die
 *    ZAS-Bestandsmitarbeiter ohne Bewerbung, also die groesste Gruppe,
 *    haetten dort gar keinen Zaehler (dieselbe Einschraenkung, an der schon
 *    ProofReminderSender vorbeibauen musste).
 *  - Der Cache ist ausserdem die Mechanik, die dieselbe Sache hier schon
 *    bremst: `PortalAuth` zaehlt Fehlversuche je Nummer und je IP genauso.
 *    Zwei aehnliche Mechaniken nebeneinander waeren die Stelle, an der die
 *    eine repariert und die andere vergessen wird.
 * DER PREIS, und er ist bewusst bezahlt: ein geleerter Cache setzt die
 * Zaehler zurueck. Das ist verschmerzbar (die Obergrenze ist eine
 * Kostenbremse, kein Identitaetsnachweis) und im Wirt selten — dort laeuft
 * CACHE_STORE=database, ein `cache:clear` ist ein bewusster Eingriff.
 *
 * DIE REIHENFOLGE IST BINDEND: erst die Drossel fragen, DANN
 * `KontoWriter::erzeugeCode()`. Das Erzeugen ersetzt den Code, der noch
 * unterwegs ist. Wer die Drossel danach fragt, hat dem Menschen den Code
 * entwertet, den er gerade im Daumen hat — und verschickt keinen neuen. Aus
 * demselben Grund stehen AUCH Konfiguration, Platzhalter-Pruefung und
 * Kanal-Aufloesung vor dem Erzeugen: sie koennen den Versand verhindern,
 * also duerfen sie den laufenden Code nicht schon verbrannt haben.
 *
 * GEZAEHLT WIRD, WAS ERZEUGT WURDE, nicht was ankam: der Zeitpunkt wird
 * direkt nach `erzeugeCode()` vermerkt, auch wenn der Versand danach
 * scheitert. Sonst liesse sich eine dauerhaft scheiternde Nummer (falsche
 * Nummer, gesperrtes Konto bei Meta) endlos haemmern — jeder Versuch kostet
 * uns Geld und entwertet einen Code. Ein GEDROSSELTER Versuch wird dagegen
 * NICHT vermerkt: sonst verlaengerte jedes Haemmern die Sperre um eine
 * weitere Stunde, und die Bremse waere eine Aussperrung, die ein Fremder
 * beim Mitarbeiter ausloest.
 *
 * DER PFEFFER GEHT DIESE KLASSE NICHTS AN (Ruling GD-5): `KontoWriter` liest
 * ihn an genau einer privaten Stelle und reicht ihn weiter. Hier wird
 * `erzeugeCode()` gerufen und der Klartext entgegengenommen, mehr nicht.
 *
 * DAS GEHEIMNIS GEHT IN DIE NACHRICHT UND SONST NIRGENDWOHIN — SOWEIT DIESE
 * KLASSE REICHT. Kein Log, keine Ausnahme, kein Dump: jeder fremde Text, der
 * von hier ins Log geht, laeuft durch `ohneCode()` (die Begruendung dort ist
 * nachgemessen, nicht vermutet).
 *
 * SIE REICHT ABER NICHT BIS ANS ENDE, und wer das nicht weiss, haelt eine
 * Zusage fuer eingeloest, die es nicht ist:
 * `WhatsAppMetaService::handleSendResponse()` legt unsere `components` als
 * `comms_whatsapp_messages.template_params` ab UND gibt sie ein zweites Mal
 * an `CommsLog::log(details: [...])`. Der Einmalcode steht damit im Klartext
 * in der CRM-Datenbank und im Kommunikations-Protokoll — zehn Minuten lang
 * gueltig, danach ein toter Wert, aber eben gespeichert. Das ist KEIN Fund
 * dieser Klasse zum Selberbeheben: die Stelle liegt in platform-crm und
 * haengt an allen anderen Sendern mit dran. Wer sie angeht, tut es dort und
 * bewusst (etwa: `template_params` fuer Vorlagen aus `code_vorlagen` nicht
 * ablegen).
 *
 * DIE ANTWORT VERRAET NICHTS. `STATUS_GEDROSSELT` muss fuer den Menschen
 * genauso aussehen wie `STATUS_SENT`, sonst waere sie die Auskunft, dass es
 * die Nummer gibt. Fuer die aufrufende SEITE (Aufgabe 9) heisst das
 * schaerfer: sie darf KEINEN der drei Werte unterscheidbar anzeigen — auch
 * `STATUS_FAILED` nicht, denn dahinter steckt unter anderem "gesperrtes
 * Konto". Die Werte sind fuer das Protokoll und fuer Kommandos da, nicht
 * fuer die Oberflaeche.
 *
 * OBSERVER-FREI: diese Klasse schreibt NICHTS an `rec_employees` (sie liest
 * dort nur den Vornamen) und ueberlaesst die Kontofelder dem einen
 * Schreiber. Ein Versand darf `zas_changed_at` nicht setzen — sonst spuelte
 * eine Versandwelle den halben Bestand in die naechste ZAS-Update-Datei.
 *
 * WAS HIER NICHT LIEGT: die Frage, WER einen Code anfordern darf. Diese
 * Klasse bekommt eine Personen-Kennung uebergeben; ob die Seite davor sie
 * haette nachschlagen duerfen, entscheidet sie nicht.
 */
final class EinmalcodeSender
{
    /** Die Nachricht ist bei Meta angenommen. */
    public const STATUS_SENT = 'sent';

    /** Nichts verschickt — und der laufende Code lebt weiter. */
    public const STATUS_GEDROSSELT = 'gedrosselt';

    /**
     * Alles andere: fehlende Einstellung, unbekannter Platzhalter, kein
     * Kanal, gesperrte Person, von Meta abgelehnt. Absichtlich EIN Wert —
     * die Unterscheidung steht im Log, nicht in der Antwort.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Vorsatz der Drossel-Schluessel; haelt sie von den Zaehlern in PortalAuth
     * getrennt. Dahinter steht 'nummer:' oder 'person:' — die beiden Zaehler
     * teilen sich den Speicher, aber nie einen Schluessel.
     */
    private const DROSSEL_VORSATZ = 'konto_code_versand:';

    /**
     * Wie lange die Liste der Anforderungszeitpunkte aufgehoben wird.
     *
     * Nachgerechnet, nicht geschaetzt: der aelteste Zeitpunkt, der bei
     * CodeDrossel noch zaehlen KANN, liegt 23:59:59 zurueck — naemlich eine
     * Anforderung um 00:00:00, gefragt um 23:59:59 desselben Kalendertags
     * (das Stundenfenster ist mit 60 Minuten viel kuerzer). Vierundzwanzig
     * Stunden genuegen also; die fuenfundzwanzigste ist Zugabe gegen
     * Uhr-Abweichungen zwischen Anwendungs- und Cache-Server.
     */
    private const DROSSEL_STUNDEN = 25;

    /**
     * Die Platzhalternamen, die als VORNAME befuellt werden. Dieselben wie in
     * HoldingTemplateComponents::build() (isNameVar), damit eine Vorlage mit
     * {{name}} hier dasselbe bekommt wie ueberall sonst im Modul.
     *
     * OHNE das dortige '1' (Fund F3): eine Code-Vorlage traegt {{code}} und
     * ist damit benannt, und Meta laesst benannte und positionelle
     * Platzhalter nicht nebeneinander zu. Ein {{1}} kann in dieser Vorlage
     * also gar nicht vorkommen; es hier zu fuehren, verspraeche etwas, das
     * bei Meta scheitert.
     *
     * @var list<string>
     */
    private const NAME_PLATZHALTER = ['name', 'vorname'];

    /** Platzhalter fuer den Code selbst. Ohne ihn geht die Vorlage nicht raus. */
    private const PLATZHALTER_CODE = 'code';

    /** Platzhalter fuer die Gueltigkeitsdauer ("gilt 10 Minuten"). */
    private const PLATZHALTER_MINUTEN = 'minuten';

    public function __construct(private readonly ?CacheRepository $cache = null) {}

    /**
     * Einen Einmalcode erzeugen und verschicken.
     *
     * @param  string       $zweck     eine der KontoWriter::ZWECK_*-Konstanten
     * @param  string|null  $anNummer  nur beim Nummernwechsel: die NEUE Nummer,
     *                                 an die der Code geht
     * @return string  STATUS_SENT | STATUS_GEDROSSELT | STATUS_FAILED
     */
    public function sende(int $personId, string $zweck, ?string $anNummer = null): string
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first();
        if ($person === null) {
            return $this->fertig($personId, $zweck, null, self::STATUS_FAILED, "Person {$personId} existiert nicht.");
        }

        // Beim Nummernwechsel geht der Code an die NEUE Nummer — sie ist ja
        // das, was bestaetigt werden soll. Sonst an die hinterlegte.
        $nummer = PhoneE164::normalize(
            $zweck === KontoWriter::ZWECK_NUMMERNWECHSEL ? $anNummer : $person->phone
        );
        if ($nummer === null) {
            return $this->fertig($personId, $zweck, null, self::STATUS_FAILED, 'Keine lesbare Handynummer fuer den Versand.');
        }

        $vorlage = $this->vorlage($zweck);
        if ($vorlage['fehler'] !== null) {
            return $this->fertig($personId, $zweck, $nummer, self::STATUS_FAILED, $vorlage['fehler'], 'error');
        }

        $kanal = $this->kanal((int) $person->team_id);
        if ($kanal === null) {
            return $this->fertig($personId, $zweck, $nummer, self::STATUS_FAILED, 'Kein aktiver WhatsApp-Kanal fuer das Team.', 'error');
        }

        // ---- ab hier erst: die Drossel, und DANN das Erzeugen ------------
        //
        // Ruling GD-1. Diese Reihenfolge ist die eigentliche Zusage dieser
        // Klasse: erzeugeCode() ueberschreibt den Code, der noch unterwegs
        // ist. Andersherum entwertete ein gedrosselter Versuch den Code im
        // Daumen des Menschen und schickte keinen neuen.
        $jetzt = now()->format('Y-m-d H:i:s');

        // ZWEI ZAEHLER, BEIDE MUESSEN ZUSTIMMEN.
        //
        // Der je NUMMER ist der aus Ruling GD-1. Er allein haette ein Loch,
        // und zwar das teurere: beim Nummernwechsel bestimmt der Anfordernde
        // die Zielnummer selbst, und jede neue Zielnummer braechte einen
        // frischen Zaehler mit. Ein Angemeldeter koennte damit unbegrenzt
        // Vorlagennachrichten an FREMDE Nummern schicken — auf unsere
        // Rechnung, unter unserem Absender, sieben Cent das Stueck.
        //
        // Deshalb der zweite je PERSON, mit denselben Grenzen. Er bremst den
        // AUSLOESER, der Nummern-Zaehler das ZIEL; keiner von beiden kann fuer
        // den anderen einstehen.
        $schluessel = [
            $this->nummernSchluessel($nummer),
            $this->personenSchluessel($personId),
        ];

        foreach ($schluessel as $einzeln) {
            if (!CodeDrossel::darfSenden($this->anforderungen($einzeln), $jetzt)) {
                // Die Antwort aendert sich fuer den Menschen NICHT (sonst waere
                // sie die Auskunft, dass es die Nummer gibt) - es wird nur nichts
                // verschickt und eine Zeile geloggt.
                return $this->fertig($personId, $zweck, $nummer, self::STATUS_GEDROSSELT, null);
            }
        }

        try {
            // Gesperrt, stillgelegt, unbekannter Zweck, Nummernwechsel ohne
            // lesbare Nummer: alles Regeln aus KontoWriter. Sie werden hier
            // NICHT nachgebaut, nur nicht verschluckt.
            $klartext = KontoWriter::erzeugeCode($personId, $zweck, $anNummer);
        } catch (\Throwable $e) {
            return $this->fertig($personId, $zweck, $nummer, self::STATUS_FAILED, $e->getMessage());
        }

        // Gezaehlt wird, was erzeugt wurde: ab hier ist der vorherige Code
        // ohnehin tot, und ein scheiternder Versand darf nicht beliebig oft
        // wiederholbar sein.
        foreach ($schluessel as $einzeln) {
            $this->merkeAnforderung($einzeln, $jetzt);
        }

        $parameter = [];
        foreach ($vorlage['platzhalter'] as $name) {
            $parameter[] = $this->parameter($name, $klartext, $personId);
        }
        $components = [['type' => 'body', 'parameters' => $parameter]];

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $kanal,
                to:           $nummer,
                templateName: $vorlage['name'],
                components:   $components,
                languageCode: $vorlage['sprache'],
            );
        } catch (\Throwable $e) {
            // ohneCode(): eine HTTP-Ausnahme fuehrt den gesendeten Rumpf im
            // Text mit, und darin steht der Code.
            return $this->fertig($personId, $zweck, $nummer, self::STATUS_FAILED, $this->ohneCode($e->getMessage(), $klartext), 'warning');
        }

        // DIE ZEILE GEGEN DEN BEKANNTEN FEHLER: ein Erfolg gilt erst nach
        // diesem Blick, nicht schon nach der Rueckkehr von sendTemplate().
        if (($nachricht->status ?? null) === 'failed') {
            $meldung = (string) ($nachricht->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.');

            return $this->fertig($personId, $zweck, $nummer, self::STATUS_FAILED, $this->ohneCode($meldung, $klartext), 'warning');
        }

        return $this->fertig($personId, $zweck, $nummer, self::STATUS_SENT, null);
    }

    // ------------------------------------------------------------- Vorlage

    /**
     * Vorlagenname, Sprache und erwartete Platzhalter — AUS DER
     * KONFIGURATION, nicht fest verdrahtet.
     *
     * Grund: die Meta-Vorlagen sind noch nicht genehmigt, Name und
     * Platzhalter stehen nicht sicher fest. Ein fest verdrahteter Name
     * muesste nach der Freigabe durch ein Deploy; so genuegt ein
     * .env-Eintrag. Muster sind die ZAS- und Flynk-Bloecke in
     * config/recruiting.php.
     *
     * FEHLT DIE EINSTELLUNG, WIRD NICHT VERSCHICKT, und die Meldung sagt,
     * welcher Schluessel fehlt — kein stiller Fehlschlag. Ein Versand mit
     * geratenem Namen waere bei Meta ohnehin abgelehnt, nur eben ohne
     * lesbaren Grund.
     *
     * @return array{fehler: ?string, name: string, sprache: string, platzhalter: list<string>}
     */
    private function vorlage(string $zweck): array
    {
        $schluessel = "recruiting.konto.code_vorlagen.{$zweck}";
        $eintrag = config($schluessel);

        $fehler = fn (string $meldung): array => ['fehler' => $meldung, 'name' => '', 'sprache' => 'de', 'platzhalter' => []];

        if (!is_array($eintrag)) {
            return $fehler("Kein Eintrag unter {$schluessel} — code_vorlagen kennt den Zweck \"{$zweck}\" nicht.");
        }

        $name = trim((string) ($eintrag['name'] ?? ''));
        if ($name === '') {
            return $fehler("Kein Meta-Vorlagenname unter {$schluessel}.name (code_vorlagen) — bis zur Freigabe bei Meta wird nichts verschickt.");
        }

        $platzhalter = array_values(array_map('strval', (array) ($eintrag['platzhalter'] ?? [])));

        $unbefuellbar = $this->unbefuellbarePlatzhalter($platzhalter);
        if ($unbefuellbar !== []) {
            return $fehler(sprintf(
                'Die Vorlage "%s" erwartet den Platzhalter {{%s}}, den wir nicht befuellen koennen — bekannt sind '
                .'{{%s}}, {{%s}} und der Vorname ({{%s}}). Ungeprueft stuende dort still der Vorname.',
                $name,
                implode('}}, {{', $unbefuellbar),
                self::PLATZHALTER_CODE,
                self::PLATZHALTER_MINUTEN,
                implode('}}, {{', self::NAME_PLATZHALTER),
            ));
        }

        // Ohne {{code}} ginge eine formal einwandfreie Nachricht raus, Meta
        // naehme sie an, der Versand gaelte als Erfolg — und dem Menschen
        // fehlte genau die Zahl, wegen der sie verschickt wurde.
        // Gross-/Kleinschreibung egal - dieselbe Strenge wie bei der
        // Platzhalter-Wache darueber, die schon immer klein verglichen hat
        // (Fund F8: die beiden waren ungleich).
        if (!in_array(self::PLATZHALTER_CODE, array_map('strtolower', $platzhalter), true)) {
            return $fehler(sprintf(
                'Die Vorlage "%s" hat keinen {{%s}}-Platzhalter — eine Nachricht ohne den Code waere keine.',
                $name,
                self::PLATZHALTER_CODE,
            ));
        }

        return [
            'fehler'      => null,
            'name'        => $name,
            'sprache'     => trim((string) ($eintrag['sprache'] ?? 'de')) ?: 'de',
            'platzhalter' => $platzhalter,
        ];
    }

    /**
     * Welche der eingestellten Platzhalter koennen wir NICHT befuellen?
     *
     * @param  list<string>  $platzhalter
     * @return list<string>  in Fundreihenfolge, ohne Dubletten
     */
    private function unbefuellbarePlatzhalter(array $platzhalter): array
    {
        $bekannt = array_merge(self::NAME_PLATZHALTER, [self::PLATZHALTER_CODE, self::PLATZHALTER_MINUTEN]);

        $unbekannt = [];
        foreach ($platzhalter as $name) {
            if (!in_array(strtolower($name), $bekannt, true) && !in_array($name, $unbekannt, true)) {
                $unbekannt[] = $name;
            }
        }

        return $unbekannt;
    }

    /**
     * Ein einzelner Body-Parameter.
     *
     * IMMER BENANNT (Fund F3): hier stand eine Abzweigung fuer positionelle
     * Vorlagen, und die war tot. Eine Code-Vorlage muss {{code}} enthalten,
     * ist also benannt; Meta laesst benannte und positionelle Platzhalter in
     * derselben Vorlage nicht nebeneinander zu. Ein positioneller Platzhalter
     * konnte den Code folglich nie tragen - der Zweig sah aber aus, als
     * koennte er es, und der Konfigurations-Kommentar versprach es sogar. Wer
     * sich darauf verlaesst, beantragt bei Meta eine positionelle Vorlage und
     * braucht am Ende genau das Deploy, das die Konfiguration vermeiden
     * sollte. Damit ist es eine ANFORDERUNG AN DIE META-VORLAGE: benannte
     * Platzhalter, {{code}} darunter.
     *
     * @return array{type: string, text: string, parameter_name: string}
     */
    private function parameter(string $name, string $klartext, int $personId): array
    {
        $wert = match (true) {
            strtolower($name) === self::PLATZHALTER_CODE    => $klartext,
            strtolower($name) === self::PLATZHALTER_MINUTEN => (string) Einmalcode::GUELTIG_MINUTEN,
            default                                         => $this->vorname($personId),
        };

        return ['type' => 'text', 'text' => $wert, 'parameter_name' => $name];
    }

    /**
     * Der Vorname — er haengt an der ANSTELLUNG (rec_employees), nicht an
     * der Person. Bei mehreren Anstellungen steht ueberall derselbe Mensch,
     * die erste genuegt. Nur gelesen, nie geschrieben.
     */
    private function vorname(int $personId): string
    {
        $wert = DB::table('rec_employees')
            ->where('rec_person_id', $personId)
            ->whereNotNull('first_name')
            ->value('first_name');

        return trim((string) $wert);
    }

    // -------------------------------------------------------------- Kanal

    /**
     * Der WhatsApp-Kanal des Teams.
     *
     * ueber RecruitingChannelResolver, also ueber dasselbe Kanal-Set, das
     * auch die Kommunikation benutzt — keine zweite Aufloesungskette.
     * Bewusst NICHT ueber HoldingTemplateSender::resolveTarget(): das
     * verlangt eine bei uns gespeicherte, bei Meta genehmigte Template-Zeile,
     * und genau die gibt es fuer den Einmalcode noch nicht.
     */
    private function kanal(int $teamId): ?CommsChannel
    {
        $ids = RecruitingChannelResolver::channelIds($teamId);
        if ($ids === []) {
            return null;
        }

        return CommsChannel::query()->find($ids[0]);
    }

    // ------------------------------------------------------------ Drossel

    /**
     * Die bisherigen Anforderungszeitpunkte dieser Nummer.
     *
     * @return list<string>  'Y-m-d H:i:s'
     */
    private function anforderungen(string $schluessel): array
    {
        $liste = $this->speicher()->get($schluessel, []);

        return is_array($liste) ? array_values(array_map('strval', $liste)) : [];
    }

    /**
     * Einen Zeitpunkt vermerken.
     *
     * Beim Schreiben werden zu alte Eintraege weggelassen — nicht fuer die
     * Richtigkeit (CodeDrossel zaehlt ohnehin nur innerhalb seiner Fenster),
     * sondern damit die Liste nicht endlos waechst.
     *
     * NICHT ATOMAR, UND DAS IST EINE ENTSCHEIDUNG (Fund F7): Lesen und
     * Schreiben sind zwei Schritte. Zwei Anforderungen, die sich genau
     * ueberlappen, koennen einander ueberschreiben - im schlimmsten Fall geht
     * eine Nachricht zu viel raus, sieben Cent. Eine Sperre (Cache::lock)
     * waere teurer als der Schaden: sie kostet bei JEDEM Versand einen
     * zusaetzlichen Umlauf, und faellt sie aus, kommt niemand mehr an seinen
     * Code. Wer das aendern will, rechne erst den Preis beider Seiten aus.
     */
    private function merkeAnforderung(string $schluessel, string $jetzt): void
    {
        $grenze = now()->subHours(self::DROSSEL_STUNDEN)->format('Y-m-d H:i:s');

        $liste = array_values(array_filter(
            $this->anforderungen($schluessel),
            static fn (string $zeitpunkt): bool => $zeitpunkt >= $grenze,
        ));
        $liste[] = $jetzt;

        $this->speicher()->put($schluessel, $liste, now()->addHours(self::DROSSEL_STUNDEN));
    }

    /**
     * Der Schluessel haengt an der NORMALISIERTEN Nummer, nicht an der
     * Person: beim Nummernwechsel gehoert die Ziel-Nummer noch niemandem,
     * und zwei Schreibweisen derselben Nummer duerfen nicht zwei Zaehler
     * ergeben.
     *
     * GEHASHT, aus denselben zwei Gruenden wie bei PortalAuth: eine
     * vollstaendige Handynummer hat im Klartext nichts in der Cache-Tabelle
     * zu suchen, und ein Hash ist immer gleich lang (cache.key ist
     * varchar(255) PRIMARY KEY). Kein kryptografischer Hash noetig — hier
     * wird nichts geprueft, nur ein Schluessel gebildet.
     */
    private function nummernSchluessel(string $nummer): string
    {
        return self::DROSSEL_VORSATZ . 'nummer:' . hash('xxh128', $nummer);
    }

    /**
     * Der Zaehler des AUSLOESERS (Fund F4).
     *
     * Er haengt an der Personen-Kennung, nicht an einer Nummer: genau das
     * Wechseln der Nummer soll er ja nicht belohnen. Die Kennung ist eine
     * laufende Zahl ohne Personenbezug und braucht deshalb keinen Hash;
     * gedeckelt ist die Schluessellaenge damit trotzdem.
     */
    private function personenSchluessel(int $personId): string
    {
        return self::DROSSEL_VORSATZ . 'person:' . $personId;
    }

    private function speicher(): CacheRepository
    {
        return $this->cache ?? Cache::store();
    }

    // ----------------------------------------------------------- Abschluss

    /**
     * Letzte Station JEDES Ausgangs: eine Log-Zeile, dann das Ergebnis.
     *
     * NIE DIE VOLLSTAENDIGE NUMMER (Datenschutz, gleiche Kuerzung wie in
     * KontoWriter::pruefeAnmeldung): die letzten vier Stellen genuegen, um
     * den Fall in der Akte wiederzufinden.
     *
     * UND NIE DER CODE. Er steht in `$meldung` nur dann, wenn ihn ein
     * fremder Text mitgebracht hat — und der ist vor dem Aufruf durch
     * ohneCode() gelaufen.
     *
     * DREI STUFEN (Fund F5), und die Grenze verlaeuft nach der REICHWEITE:
     *  - `error`   trifft ALLE: fehlende Vorlagen-Einstellung, unbekannter
     *              Platzhalter, kein WhatsApp-Kanal. In diesem Zustand kann
     *              sich niemand mehr ein Passwort zuruecksetzen. Auf `info`
     *              stuende das auf derselben Stufe wie jeder Erfolg und ginge
     *              in der Flut unter.
     *  - `warning` trifft EINEN Versand, obwohl alles eingerichtet war: von
     *              Meta abgelehnt, Ausnahme beim Senden. Einzelne Zeilen sind
     *              Betrieb, viele sind ein Ausfall.
     *  - `info`    der normale Verlauf, einschliesslich dessen, was an genau
     *              diesem einen Menschen liegt (gesperrt, keine lesbare
     *              Nummer), und die Drossel.
     */
    private function fertig(int $personId, string $zweck, ?string $nummer, string $status, ?string $meldung, string $stufe = 'info'): string
    {
        $daten = [
            'person_id' => $personId,
            'zweck'     => $zweck,
            'status'    => $status,
        ];

        if ($nummer !== null) {
            $daten['nummer_endet_auf'] = substr($nummer, -4);
        }
        if ($meldung !== null) {
            $daten['meldung'] = $meldung;
        }

        Log::{$stufe}('recruiting.konto.einmalcode_versand', $daten);

        return $status;
    }

    /**
     * Schwaerzt den Code in einem fremden Text.
     *
     * Der Weg dorthin ist NACHGEMESSEN, nicht vermutet:
     * WhatsAppMetaService::handleSendResponse() schreibt unsere `components`
     * als `template_params` in comms_whatsapp_messages. Scheitert dieses
     * INSERT (Feldlaenge, Verbindung), traegt die QueryException den Code im
     * Text — Laravel setzt die Bindings in die Meldung ein
     * (QueryException::formatMessage, Str::replaceArray). Genau so eine
     * Meldung stand in diesem Testlauf auf dem Schirm:
     * "... SQL: insert into "comms_channels" (...) values (3, chan-konto-code, ...)".
     *
     * ProofReminderSender protokolliert $e->getMessage() woertlich — bei
     * einem Geheimnis darf das nicht sein, und ein Log ist genau der Ort, an
     * den man spaeter jemanden schauen laesst. Dasselbe gilt fuer die
     * Fehlermeldung aus dem meta_payload: sie kommt von Meta zurueck und
     * zitiert bei einem Parameterfehler den gesendeten Wert.
     */
    private function ohneCode(string $text, string $klartext): string
    {
        return str_replace($klartext, '******', $text);
    }
}
