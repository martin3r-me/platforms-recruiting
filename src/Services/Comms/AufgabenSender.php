<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Verschickt die Aufgaben-Nachricht per WhatsApp — den Hinweis, dass im
 * Mitarbeiter-Portal etwas fuer den naechsten Einsatz offen ist (Spec §2.3,
 * Aufgabe 9). Der Inhalt (`$stand`) kommt aus `OffenePunkte::fuer()`
 * (Aufgabe 8), berechnet vom Aufrufer (Aufgabe 10) — diese Klasse verschickt
 * nur, sie liest OffenePunkte nicht selbst.
 *
 * VORBILD IST `EinmalcodeSender`, und zwar wegen dessen zwei Altfehler-
 * Abwehren. Beide treffen hier genauso:
 *
 *  1. `RecEmployee::sendPortalNotification()` meldet `ok: true` direkt nach
 *     `sendTemplate()`, OHNE `$message->status` zu pruefen — ein von Meta
 *     abgelehnter Versand gilt dort als Erfolg. Diese Klasse prueft den
 *     Status und meldet `STATUS_FAILED`. Sonst wartet jemand im Portal auf
 *     einen Hinweis, der nie ankam, waehrend das Protokoll "verschickt" sagt.
 *  2. `HoldingTemplateComponents::build()` setzt bei einem UNBEKANNTEN
 *     Platzhalter still den Vornamen ein. Deshalb wird diese geteilte Klasse
 *     hier GAR NICHT benutzt: die Komponenten baut `platzhalter()` selbst,
 *     und ein Platzhalter, den wir nicht befuellen koennen (oder dessen
 *     Wert gerade leer ist — etwa `datum` ohne bevorstehenden Einsatz),
 *     verhindert den Versand.
 *
 * ET-11 (Review vor Aufgabe 9): `sendTemplate()` steht in einem try/catch.
 * Der urspruengliche Brief fuer diese Aufgabe liess das aus — ohne es wuerde
 * eine HTTP-Ausnahme bei einer Meta-Stoerung aus der Schleife des Kommandos
 * (Aufgabe 10) herausfliegen und den GANZEN Lauf mitreissen, nicht nur einen
 * einzelnen Menschen auslassen. Ein Ausfall bei Meta darf einen Mitarbeiter
 * kosten, nicht den Durchlauf.
 *
 * NACHBESSERUNG RUNDE 1 (Review 01.10.2026) — drei weitere Zusagen, die
 * jetzt nachgewiesen sind:
 *
 *  - ET-20: das Einsatzdatum geht als `d.m.Y` an den Menschen, nicht im
 *    ISO-Format, das `OffenePunkte::fuer()` zusagt. Die Umformung passiert
 *    HIER, nicht dort — `OffenePunkte`s Vertrag ist ISO (das Portal sortiert
 *    danach), die Lesbarkeit fuer ein Handy ist eine Eigenschaft der
 *    NACHRICHT, nicht der Datenquelle.
 *  - ET-21: ohne lesbare Rufnummer wird NICHT verschickt (genau wie
 *    `EinmalcodeSender::sende()` bei `PhoneE164::normalize() === null`
 *    abbricht) — sonst ginge ein bezahlter, von vornherein aussichtsloser
 *    Meta-Aufruf mit leerem Empfaenger raus. Und die Normalisierung selbst
 *    ist jetzt durch `testEineNationaleNummerWirdVorDemVersandNormalisiert`
 *    geschuetzt: ohne sie haette eine nationale "0151…"-Nummer bei Meta
 *    dieselbe Folge wie im dokumentierten Fehler 131026 (deutsche Nummer als
 *    US-`wa_id` gedeutet, "sent" mit spaeterem stillen "failed" per Webhook).
 *  - ET-22 (Entscheidung): der Sender verweigert bei NULL offenen Punkten.
 *    Eine Nachricht "0 offene Punkte, schau ins Portal" waere sinnlos und
 *    kostet trotzdem. Aufgabe 10 kann diesen Fall nach heutigem Stand nicht
 *    erzeugen (eine leere Signatur loest keinen Versand aus) — dieser Sender
 *    verlaesst sich darauf aber nicht und weigert sich selbst.
 *
 * F1 (Abschlusspruefung) — DIE NACHRICHT TRAEGT DEN PORTAL-LINK. Vorher
 * baute diese Klasse ausschliesslich einen Rumpf: kein URL-Knopf, kein
 * Token. Eine Nachricht, die "schau ins Portal" sagt und keinen Weg dorthin
 * gibt, ist genau die Sackgasse, gegen die `ProofReminderSender` gebaut
 * wurde — der VERWEIGERT den Versand mit dem Wortlaut "ohne Link waere die
 * Erinnerung eine Sackgasse". Schlimmer war die andere Richtung: traegt die
 * genehmigte Meta-Vorlage den noetigen dynamischen URL-Knopf (und sie MUSS
 * einen tragen, sonst erreicht niemand sein Portal), ging der Aufruf ohne
 * dessen Komponente raus, Meta lehnte ab, der Aufrufer stempelte und
 * wiederholte in sieben Tagen — eine woechentliche Fehlschlag-Schleife ueber
 * den ganzen Bestand, jede Woche bezahlt.
 * DAS VORBILD IM DOCBLOCK WAR FALSCH GEWAEHLT: `EinmalcodeSender` traegt
 * seinen Inhalt (den Code) im Rumpf und braucht keinen Link. Fuer eine
 * Portal-Nachricht ist `ProofReminderSender` das Vorbild, und von dort kommt
 * jetzt auch der Weg: `WhatsAppTemplateUrlButtons::dynamicIndexes()` prueft,
 * `ApplicantTemplateSender::buildTokenComponents()` fuellt — dieselbe
 * Fix-Klasse, die den Theo-Wirtz-Fehler behoben hat, keine eigene Kopie.
 *
 * DIE NACHRICHT NENNT DIE OFFENEN PUNKTE NICHT EINZELN (Spec §2.1: das
 * Portal traegt die Aufgaben) — sie nennt nur ihre ANZAHL und verweist aufs
 * Portal. Waere die Nachricht selbst der Traeger, muesste jede Aenderung an
 * den Punkten eine neue Nachricht erzeugen.
 *
 * KEINE VOLLE RUFNUMMER IM LOG (Datenschutz, gleiche Kuerzung wie in
 * `KontoWriter`/`EinmalcodeSender`): `PhoneE164::suffix()` liefert die
 * letzten neun Ziffern formatunabhaengig, geloggt werden davon nur die
 * letzten vier. Die MITARBEITER-KENNUNG steht dagegen in JEDER Logzeile
 * (Review-Befund E) — sie ist keine Rufnummer und faellt nicht unter die
 * Datenschutz-Vorgabe; ohne sie liesse sich nach einem Lauf ueber hunderte
 * Menschen nicht feststellen, WER nicht erreicht wurde.
 *
 * OBSERVER-FREI: diese Klasse liest nur `$employee->phone` und
 * `$employee->team_id` und schreibt NICHTS an `rec_employees`. Ein Versand
 * darf `zas_changed_at` nicht setzen — sonst spuelte eine Versandwelle den
 * halben Bestand in die naechste ZAS-Update-Datei.
 */
final class AufgabenSender
{
    /** Die Nachricht ist bei Meta angenommen. */
    public const STATUS_SENT = 'sent';

    /**
     * Alles andere, was NACH einer vollstaendigen Konfiguration noch
     * schiefgehen kann: keine offenen Punkte zum Melden (ET-22), keine
     * lesbare Rufnummer (ET-21), unbefuellbarer Platzhalter, kein
     * WhatsApp-Kanal, von Meta abgelehnt, Ausnahme beim Senden.
     */
    public const STATUS_FAILED = 'failed';

    /** Kein Meta-Vorlagenname fuer diesen Anlass eingetragen — es wird nichts verschickt. */
    public const STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert';

    /**
     * F1: die eingetragene Vorlage taugt nicht — sie ist nicht gefunden,
     * nicht eindeutig, oder sie hat keinen dynamischen URL-Knopf, in den der
     * Portal-Link passt.
     *
     * EIGENER STATUS UND NICHT STATUS_FAILED, und das ist der Kern des
     * Fundes: `failed` liesse den Aufrufer den Stempel setzen und in sieben
     * Tagen denselben aussichtslosen Versuch wiederholen — eine
     * woechentliche Fehlschlag-Schleife ueber den ganzen Bestand. Wie bei
     * STATUS_NICHT_KONFIGURIERT schreibt der Aufrufer hier GAR NICHTS: es
     * hat kein Versuch bei Meta stattgefunden, es ist nichts zu bremsen, und
     * sobald die Vorlage richtig steht, geht es ohne Wartezeit los.
     */
    public const STATUS_VORLAGE_UNTAUGLICH = 'vorlage_untauglich';

    /**
     * Die Kennung der zuletzt angelegten Nachricht — NUR nach STATUS_SENT
     * gesetzt, sonst null (ET-23).
     *
     * WARUM NICHT IM RUECKGABEWERT: `sende()` sagt `: string` zu, und diese
     * Zusage ist in Aufgabe 9 geprueft und anderweitig benutzt. Ein Umbau auf
     * ein Array haette dort jede Aufrufstelle und jeden Test mitgerissen,
     * ohne dass eine einzige davon besser geworden waere.
     *
     * WAS DAS KOSTET: der Wert gehoert dem LETZTEN Aufruf. Der Aufrufer
     * (EinsatzPruefung) arbeitet eine Person nach der anderen ab und liest
     * ihn unmittelbar nach `sende()`; wer parallel senden will, braucht eine
     * eigene Instanz. Zurueckgesetzt wird er am ANFANG jedes Aufrufs, damit
     * ein fehlgeschlagener Versand nicht die Kennung des vorherigen erbt —
     * das waere der gefaehrliche Fall: der naechste Lauf laese dann den
     * Status einer fremden Nachricht nach.
     */
    private ?int $letzteNachrichtId = null;

    public function letzteNachrichtId(): ?int
    {
        return $this->letzteNachrichtId;
    }

    /**
     * @param  array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}  $stand  Rueckgabe von OffenePunkte::fuer()
     * @param  string  $anlass  'neu' oder 'erinnerung'
     */
    public function sende(RecEmployee $employee, array $stand, string $anlass): string
    {
        // ET-23: zuerst raeumen. Ein Rueckfall auf die Kennung des
        // vorherigen Aufrufs waere schlimmer als gar keine — der naechste
        // Lauf wuerde den Status einer fremden Nachricht nachlesen.
        $this->letzteNachrichtId = null;

        $vorlage = (array) config("recruiting.aufgaben.vorlagen.{$anlass}", []);
        $name = trim((string) ($vorlage['name'] ?? ''));

        if ($name === '') {
            // Kein stiller Fehlschlag: ohne Vorlage kann niemand erreicht
            // werden, und das ist bis zur Freigabe bei Meta der Normalfall.
            Log::error('recruiting.aufgaben.vorlage_fehlt', [
                'anlass'      => $anlass,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_NICHT_KONFIGURIERT;
        }

        $anzahl = count($stand['punkte'] ?? []);
        if ($anzahl === 0) {
            // ET-22 (Entscheidung Review 01.10.2026): eine Nachricht "0
            // offene Punkte, schau ins Portal" ist sinnlos und kostet
            // trotzdem. Aufgabe 10 kann diesen Fall nach heutigem Stand
            // nicht erzeugen, aber dieser Sender verlaesst sich darauf
            // nicht und weigert sich selbst.
            Log::info('recruiting.aufgaben.keine_offenen_punkte', [
                'anlass'      => $anlass,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_FAILED;
        }

        $werte = [
            'anzahl' => (string) $anzahl,
            'datum'  => $this->menschlichesDatum((string) ($stand['einsatz']['datum'] ?? '')),
        ];

        $komponenten = [];
        foreach ((array) ($vorlage['platzhalter'] ?? []) as $platzhalter) {
            $platzhalter = (string) $platzhalter;
            $schluessel  = strtolower($platzhalter);

            // Strikter Leerstring-Vergleich, NICHT empty(): nach dem
            // Null-Guard oben kann 'anzahl' hier nie mehr "0" sein, aber die
            // strikte Form bleibt die richtige — ein kuenftiger, legitim
            // falsy-aber-nicht-leerer Wert soll nicht wie ein fehlender
            // behandelt werden.
            if (!array_key_exists($schluessel, $werte) || $werte[$schluessel] === '') {
                // HoldingTemplateComponents::build() wuerde hier still den
                // Vornamen einsetzen — der Mensch bekaeme seinen Namen statt
                // der Zahl, und Meta naehme es an. Lieber gar nicht senden.
                Log::warning('recruiting.aufgaben.platzhalter_unbefuellbar', [
                    'anlass'      => $anlass,
                    'platzhalter' => $platzhalter,
                    'mitarbeiter' => $employee->id,
                ]);

                return self::STATUS_FAILED;
            }

            $komponenten[] = ['type' => 'text', 'parameter_name' => $schluessel, 'text' => $werte[$schluessel]];
        }

        // ET-21 (Review 01.10.2026): ohne lesbare Rufnummer wird NICHT
        // verschickt — genau wie EinmalcodeSender::sende() an derselben
        // Stelle abbricht. Der vorherige Rueckfall auf die Rohnummer haette
        // bei phone=null/'' einen bezahlten, von vornherein aussichtslosen
        // Meta-Aufruf mit leerem Empfaenger ausgeloest.
        $nummer = PhoneE164::normalize($employee->phone);
        if ($nummer === null || $nummer === '') {
            Log::error('recruiting.aufgaben.keine_nummer', [
                'anlass'      => $anlass,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_FAILED;
        }

        $kanal = $this->kanal((int) $employee->team_id);
        if ($kanal === null) {
            Log::error('recruiting.aufgaben.kein_kanal', [
                'anlass'      => $anlass,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_FAILED;
        }

        $sprache = trim((string) ($vorlage['sprache'] ?? 'de')) ?: 'de';

        // F1: der Portal-Link. Erst die genehmigte Vorlage nachschlagen,
        // dann pruefen, dass sie einen dynamischen URL-Knopf hat, dann den
        // Token hineinlegen. Jeder dieser Schritte kann VERWEIGERN — eine
        // Portal-Nachricht ohne Weg ins Portal geht nicht raus.
        $nachschlag = $this->vorlageNachschlagen($name, $sprache);
        if ($nachschlag['vorlage'] === null) {
            // ET-29: VIER BETRIEBSLAGEN, VIER LOGZEILEN. An dieser Zeile
            // haengt am Deploy-Tag ein Handgriff, und die vier sind
            // verschiedene: die Vorlage ist gar nicht da (Meta-Abgleich
            // laufen lassen) · sie ist nicht genehmigt (bei Meta nachhaken)
            // · sie gibt es nur in einer anderen Sprache (env korrigieren)
            // · es gibt zwei gleichnamige genehmigte (die falsche WABA
            // aufraeumen). „Irgendwas mit der Vorlage" schickt jemanden in
            // die falsche Richtung.
            Log::error('recruiting.aufgaben.'.$nachschlag['grund'], [
                'anlass'      => $anlass,
                'vorlage'     => $name,
                'sprache'     => $sprache,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $vorlagenTeile = (array) ($nachschlag['vorlage']->components ?? []);

        if (WhatsAppTemplateUrlButtons::dynamicIndexes($vorlagenTeile) === []) {
            // Wortgleich zur Begruendung in ProofReminderSender: ohne Link
            // waere die Nachricht eine Sackgasse.
            Log::error('recruiting.aufgaben.vorlage_ohne_link', [
                'anlass'      => $anlass,
                'vorlage'     => $name,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $token = trim((string) $employee->portal_token);
        if ($token === '') {
            // Ein Datenproblem an DIESEM Menschen, kein Vorlagen-Problem:
            // deshalb STATUS_FAILED, damit der Aufrufer bremst und ihn im
            // Bericht nennt, statt es stuendlich zu wiederholen.
            Log::error('recruiting.aufgaben.kein_portal_token', [
                'anlass'      => $anlass,
                'mitarbeiter' => $employee->id,
            ]);

            return self::STATUS_FAILED;
        }

        $knopf = ApplicantTemplateSender::buildTokenComponents($vorlagenTeile, $token);
        if (!$knopf['ok']) {
            // Mehr als ein dynamischer Knopf — nicht eindeutig sendbar.
            Log::error('recruiting.aufgaben.vorlage_mehrere_knoepfe', [
                'anlass'      => $anlass,
                'vorlage'     => $name,
                'mitarbeiter' => $employee->id,
                'grund'       => $knopf['error'],
            ]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $components = array_merge(
            [['type' => 'body', 'parameters' => $komponenten]],
            $knopf['components'],
        );

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $kanal,
                to:           $nummer,
                templateName: $name,
                components:   $components,
                languageCode: $sprache,
            );
        } catch (\Throwable $e) {
            // ET-11: eine HTTP-Ausnahme darf den Aufrufer (die Schleife des
            // Kommandos aus Aufgabe 10) nicht mitreissen — ein Ausfall bei
            // Meta kostet einen Mitarbeiter, nicht den Lauf.
            Log::warning('recruiting.aufgaben.ausnahme', [
                'anlass'           => $anlass,
                'mitarbeiter'      => $employee->id,
                'nummer_endet_auf' => $this->endungVon($nummer),
            ]);

            return self::STATUS_FAILED;
        }

        // DIE ZEILE GEGEN DEN BEKANNTEN FEHLER: ein Erfolg gilt erst nach
        // diesem Blick, nicht schon nach der Rueckkehr von sendTemplate()
        // (RecEmployee::sendPortalNotification tut das nicht).
        if (($nachricht->status ?? null) === 'failed') {
            Log::warning('recruiting.aufgaben.abgelehnt', [
                'anlass'           => $anlass,
                'mitarbeiter'      => $employee->id,
                'nummer_endet_auf' => $this->endungVon($nummer),
            ]);

            return self::STATUS_FAILED;
        }

        // ET-23: die Kennung erst HIER, nach dem Status-Blick. Eine von Meta
        // abgelehnte Nachricht ist kein Beleg, den der naechste Lauf
        // nachlesen muesste — sie hat den Versand schon jetzt als
        // fehlgeschlagen gemeldet.
        $this->letzteNachrichtId = isset($nachricht->id) ? (int) $nachricht->id : null;

        return self::STATUS_SENT;
    }

    /**
     * ET-20 (Review 01.10.2026): das Datum geht als `d.m.Y` an den Menschen.
     * `OffenePunkte::fuer()` sagt bewusst ISO zu (das Portal sortiert
     * danach) — die Umformung fuer ein lesbares Handy gehoert hierher, nicht
     * in die Datenquelle. Ein leerer Eingabewert bleibt leer (der
     * Platzhalter-Check oben entscheidet dann ueber "unbefuellbar"); ein
     * NICHT im `Y-m-d`-Format vorliegender Wert faellt auf sich selbst
     * zurueck, statt den Versand mit einer Ausnahme abzubrechen — das kann
     * nach heutigem Vertrag von `OffenePunkte` nicht vorkommen, ist aber
     * billiger als ein Fehlschlag mitten im Format.
     */
    private function menschlichesDatum(string $iso): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $iso)->format('d.m.Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * Die genehmigte Meta-Vorlage zu diesem Namen — gebraucht wird nur ihr
     * Aufbau (`components`), um den dynamischen URL-Knopf zu finden.
     *
     * NAME UND SPRACHE, UND EINDEUTIG: gibt es zu einem Namen mehr als eine
     * genehmigte Vorlage (zwei WABAs mit gleichnamigen Vorlagen), wird NICHT
     * geraten — dann koennte der Knopf an der falschen Position sitzen und
     * der Link ins Leere fuehren. Dieselbe Strenge wie bei
     * `buildTokenComponents()`, das bei zwei dynamischen Knoepfen ebenfalls
     * abbricht statt zu raten.
     *
     * Der Name kommt aus der Konfiguration (env), nicht aus einer
     * Team-Einstellung wie bei `ProofReminderSender` — das ist der Stand aus
     * Aufgabe 9 und bleibt so; nachgeschlagen wird hier nur der AUFBAU.
     *
     * ET-29: der GRUND kommt mit zurueck, nicht nur ein null. Die vier Wege
     * hierher sind vier verschiedene Handgriffe (siehe Aufrufstelle).
     *
     * @return array{vorlage: ?IntegrationsWhatsAppTemplate, grund: ?string}
     */
    private function vorlageNachschlagen(string $name, string $sprache): array
    {
        $fehlt = fn (string $grund) => ['vorlage' => null, 'grund' => $grund];

        if (!class_exists(IntegrationsWhatsAppTemplate::class)) {
            return $fehlt('vorlage_fehlt_ganz');
        }

        // EINMAL ueber den Namen, dann in PHP einengen: so kann jede Stufe
        // sagen, WORAN es lag. Mit drei where() auf der Abfrage waere am
        // Ende nur bekannt, dass nichts passt.
        $mitNamen = IntegrationsWhatsAppTemplate::query()
            ->where('name', $name)
            ->orderBy('id')
            ->get();

        if ($mitNamen->isEmpty()) {
            return $fehlt('vorlage_fehlt_ganz');
        }

        $inSprache = $mitNamen->where('language', $sprache);
        if ($inSprache->isEmpty()) {
            return $fehlt('vorlage_falsche_sprache');
        }

        $genehmigt = $inSprache->where('status', 'APPROVED')->values();
        if ($genehmigt->isEmpty()) {
            return $fehlt('vorlage_nicht_genehmigt');
        }

        if ($genehmigt->count() > 1) {
            return $fehlt('vorlage_nicht_eindeutig');
        }

        return ['vorlage' => $genehmigt->first(), 'grund' => null];
    }

    /**
     * Der WhatsApp-Kanal des Teams — ueber RecruitingChannelResolver, also
     * ueber dasselbe Kanal-Set wie der Rest der Kommunikation (Muster
     * EinmalcodeSender::kanal()).
     */
    private function kanal(int $teamId): ?CommsChannel
    {
        $ids = RecruitingChannelResolver::channelIds($teamId);
        if ($ids === []) {
            return null;
        }

        return CommsChannel::query()->find($ids[0]);
    }

    /** Die letzten vier Stellen — NIE die volle Nummer ins Log. */
    private function endungVon(string $nummer): string
    {
        return substr(PhoneE164::suffix($nummer), -4);
    }
}
