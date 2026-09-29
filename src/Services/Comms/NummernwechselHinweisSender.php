<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Der Hinweis AN DIE ALTE NUMMER, nachdem die Handynummer eines Kontos
 * gewechselt wurde — Begleitregel aus Spec §5 (Canvas 1789):
 *
 *   "Die alte Nummer bekommt einmalig einen Hinweis, dass die Nummer
 *    geaendert wurde, sofern noch zustellbar."
 *
 * WOZU ER DA IST, und das ist keine Hoeflichkeit: er ist die einzige Warnung,
 * die ein Mensch bekommt, dem jemand das Konto umgehaengt hat. Alle fuenf
 * Wege aus Spec §5 enden damit, dass der Einmalcode kuenftig auf ein ANDERES
 * Geraet geht — wer das nicht mitbekommt, merkt den Verlust erst, wenn er
 * sich das naechste Mal anmelden will. Deshalb geht der Hinweis auf das
 * Geraet, das der rechtmaessige Inhaber noch in der Hand haelt.
 *
 * "SOFERN NOCH ZUSTELLBAR" laesst sich vorher nicht feststellen. Ob die alte
 * SIM noch lebt, weiss weder diese Anwendung noch Meta vor dem Versuch —
 * deshalb wird schlicht versucht und das Ergebnis protokolliert. Ein
 * Fehlschlag ist der Normalfall bei einer verlorenen Karte und KEIN Grund,
 * irgendetwas zurueckzudrehen: der Wechsel ist zu diesem Zeitpunkt vollzogen.
 *
 * "EINMALIG" LIEGT BEIM AUFRUFER. Diese Klasse fuehrt keinen Zaehler; sie
 * wird je vollzogenem Wechsel genau einmal gerufen (Seite: nach
 * loeseCodeEin(); Kommando: nach dem Anwenden beziehungsweise nach dem
 * HR-Eintrag). Ein eigener Zaehler hier waere eine zweite Wahrheit ueber
 * "wurde gewechselt?" neben der Datenbank.
 *
 * DIE ALTE NUMMER WIRD HEREINGEREICHT, NICHT NACHGESCHLAGEN. Nach dem
 * Wechsel steht sie nirgends mehr — PersonLinker::setzeNummer() ueberschreibt
 * rec_persons.phone UND rec_employees.phone. Wer sie hier nachschlagen
 * wollte, faende die NEUE und schickte den Hinweis an genau das Geraet, das
 * ihn nicht braucht. Der Aufrufer merkt sie sich deshalb vorher
 * (KontoWriter::aktuelleNummer()).
 *
 * WARUM DAS NICHT IM EinmalcodeSender MITLAEUFT, obwohl Vorlagen-Aufloesung,
 * Kanal und Statuspruefung dort sehr aehnlich aussehen — drei Gruende, jeder
 * fuer sich ausreichend:
 *  1. DIE DROSSEL. Der Einmalcode-Sender bremst je Nummer und je Person
 *     (drei je Stunde). Der Hinweis faellt ZWANGSLAEUFIG in denselben
 *     Augenblick wie der dritte Code eines Vorgangs — er wuerde also gerade
 *     dann verschluckt, wenn am meisten passiert ist. Eine Warnung, die die
 *     Kostenbremse fuer Geheimnisse mitbremst, ist keine Warnung.
 *  2. DAS GEHEIMNIS. Der andere Sender traegt einen Code und schwaerzt ihn
 *     deshalb aus jeder fremden Meldung (ohneCode()). Diese Vorlage traegt
 *     keines; sie braucht die Schwaerzung nicht und darf ihre Abwesenheit
 *     nicht als Versehen aussehen lassen.
 *  3. DIE ZIELNUMMER. Dort geht die Nachricht an eine Nummer, die der Person
 *     GEHOERT; hier an eine, die ihr gerade genommen wurde. Das ist derselbe
 *     Kanal, aber nicht derselbe Vorgang.
 * Der Preis ist die Aehnlichkeit der beiden Klassen, und er ist bewusst
 * bezahlt. WER SIE JE ZUSAMMENLEGT, nehme die drei Punkte mit.
 *
 * OBSERVER-FREI: diese Klasse schreibt nichts. Sie liest den Vornamen aus
 * rec_employees und sonst gar nichts — ein Hinweis darf zas_changed_at nicht
 * setzen, sonst spuelt er den Menschen in die naechste ZAS-Update-Datei.
 */
final class NummernwechselHinweisSender
{
    /** Der Konfigurationspfad der Vorlage. */
    public const KONFIG = 'recruiting.konto.hinweis_vorlage';

    /** Die Nachricht ist bei Meta angenommen. */
    public const STATUS_SENT = 'sent';

    /**
     * Alles andere: fehlende Einstellung, unbekannter Platzhalter, kein
     * Kanal, unlesbare alte Nummer, von Meta abgelehnt (die alte Karte lebt
     * nicht mehr — der Normalfall). Absichtlich EIN Wert; die Unterscheidung
     * steht im Log.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Die Platzhalternamen, die als VORNAME befuellt werden — dieselben wie
     * im Einmalcode-Sender, damit eine Vorlage mit {{name}} hier dasselbe
     * bekommt wie ueberall sonst im Modul.
     *
     * @var list<string>
     */
    private const NAME_PLATZHALTER = ['name', 'vorname'];

    /**
     * @param  string  $alteNummer  die Nummer VOR dem Wechsel
     * @return string  STATUS_SENT | STATUS_FAILED
     */
    public function sende(int $personId, string $alteNummer): string
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first();
        if ($person === null) {
            return $this->fertig($personId, null, self::STATUS_FAILED, "Person {$personId} existiert nicht.");
        }

        // Normalisiert, wie ueberall an der Grenze: die gemerkte Nummer kommt
        // aus rec_persons.phone und steht dort zwar in E.164 — aber ein
        // Kommando kann sie auch von Hand hereinreichen.
        $nummer = PhoneE164::normalize($alteNummer);
        if ($nummer === null) {
            return $this->fertig($personId, null, self::STATUS_FAILED, 'Die alte Nummer ist nicht lesbar.');
        }

        // Die NEUE Nummer bekommt den Hinweis nicht: sie hat den
        // Bestaetigungscode schon bekommen, und eine zweite Nachricht an
        // dasselbe Geraet sagt niemandem etwas Neues. Dieser Fall tritt ein,
        // wenn jemand seine eigene Nummer erneut eintraegt.
        if ($person->phone !== null && PhoneE164::normalize((string) $person->phone) === $nummer) {
            return $this->fertig($personId, $nummer, self::STATUS_FAILED, 'Alte und neue Nummer sind dieselbe.');
        }

        $vorlage = $this->vorlage();
        if ($vorlage['fehler'] !== null) {
            return $this->fertig($personId, $nummer, self::STATUS_FAILED, $vorlage['fehler'], 'error');
        }

        $kanal = $this->kanal((int) $person->team_id);
        if ($kanal === null) {
            return $this->fertig($personId, $nummer, self::STATUS_FAILED, 'Kein aktiver WhatsApp-Kanal fuer das Team.', 'error');
        }

        $parameter = [];
        foreach ($vorlage['platzhalter'] as $name) {
            // Jeder befuellbare Platzhalter IST der Vorname — etwas anderes
            // laesst vorlage() gar nicht erst durch.
            $parameter[] = ['type' => 'text', 'text' => $this->vorname($personId), 'parameter_name' => strtolower($name)];
        }

        // Eine Vorlage ohne Platzhalter bekommt auch keinen leeren
        // body-Abschnitt: Meta lehnt "parameters": [] ab.
        $components = $parameter === [] ? [] : [['type' => 'body', 'parameters' => $parameter]];

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $kanal,
                to:           $nummer,
                templateName: $vorlage['name'],
                components:   $components,
                languageCode: $vorlage['sprache'],
            );
        } catch (\Throwable $e) {
            return $this->fertig($personId, $nummer, self::STATUS_FAILED, $e->getMessage(), 'warning');
        }

        // DIESELBE ZEILE GEGEN DEN BEKANNTEN FEHLER wie im Einmalcode-Sender:
        // RecEmployee::sendPortalNotification() meldet ok:true direkt nach
        // sendTemplate(), ohne $nachricht->status zu pruefen. Hier ist ein
        // abgelehnter Versand sogar der erwartete Ausgang (die alte Karte ist
        // tot) — und genau deshalb darf er nicht als Erfolg im Protokoll
        // stehen.
        if (($nachricht->status ?? null) === 'failed') {
            $meldung = (string) ($nachricht->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.');

            return $this->fertig($personId, $nummer, self::STATUS_FAILED, $meldung, 'warning');
        }

        return $this->fertig($personId, $nummer, self::STATUS_SENT, null);
    }

    // ------------------------------------------------------------- Vorlage

    /**
     * Vorlagenname, Sprache und Platzhalter — aus der Konfiguration, aus
     * demselben Grund wie beim Einmalcode-Sender: die Meta-Vorlagen sind noch
     * nicht genehmigt.
     *
     * @return array{fehler: ?string, name: string, sprache: string, platzhalter: list<string>}
     */
    private function vorlage(): array
    {
        $eintrag = config(self::KONFIG);

        $fehler = fn (string $meldung): array => ['fehler' => $meldung, 'name' => '', 'sprache' => 'de', 'platzhalter' => []];

        if (!is_array($eintrag)) {
            return $fehler('Kein Eintrag unter ' . self::KONFIG . ' — ohne Vorlage kein Hinweis.');
        }

        $name = trim((string) ($eintrag['name'] ?? ''));
        if ($name === '') {
            return $fehler(
                'Kein Meta-Vorlagenname unter ' . self::KONFIG . '.name — bis zur Freigabe bei Meta geht '
                .'der Hinweis an die alte Nummer nicht raus.',
            );
        }

        $platzhalter = array_values(array_map('strval', (array) ($eintrag['platzhalter'] ?? [])));

        // Dieselbe Strenge wie beim Einmalcode-Sender, und aus demselben
        // Grund: HoldingTemplateComponents::build() setzt bei einem
        // unbekannten Platzhalter still den Vornamen ein, Meta naehme es an,
        // und der Versand gaelte als Erfolg. Hier kann dabei zwar kein
        // Geheimnis danebengehen — aber ein Hinweis, der an der falschen
        // Stelle den Vornamen traegt, ist trotzdem eine Nachricht, die
        // niemand versteht.
        $unbekannt = [];
        foreach ($platzhalter as $einzeln) {
            if (!in_array(strtolower($einzeln), self::NAME_PLATZHALTER, true) && !in_array($einzeln, $unbekannt, true)) {
                $unbekannt[] = $einzeln;
            }
        }

        if ($unbekannt !== []) {
            return $fehler(sprintf(
                'Die Vorlage "%s" erwartet den Platzhalter {{%s}}, den wir nicht befuellen koennen — diese '
                .'Nachricht kennt nur den Vornamen ({{%s}}). Die neue Nummer gehoert ausdruecklich NICHT '
                .'hinein.',
                $name,
                implode('}}, {{', $unbekannt),
                implode('}}, {{', self::NAME_PLATZHALTER),
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
     * Der Vorname — er haengt an der ANSTELLUNG, nicht an der Person. Nur
     * gelesen, nie geschrieben.
     */
    private function vorname(int $personId): string
    {
        $wert = DB::table('rec_employees')
            ->where('rec_person_id', $personId)
            ->whereNotNull('first_name')
            ->value('first_name');

        return trim((string) $wert);
    }

    /** Derselbe Kanal-Weg wie ueberall im Modul — keine zweite Aufloesungskette. */
    private function kanal(int $teamId): ?CommsChannel
    {
        $ids = RecruitingChannelResolver::channelIds($teamId);
        if ($ids === []) {
            return null;
        }

        return CommsChannel::query()->find($ids[0]);
    }

    /**
     * Letzte Station jedes Ausgangs: eine Log-Zeile, dann das Ergebnis.
     *
     * NIE DIE VOLLSTAENDIGE NUMMER, dieselbe Kuerzung auf vier Stellen wie in
     * KontoWriter und im Einmalcode-Sender.
     */
    private function fertig(int $personId, ?string $nummer, string $status, ?string $meldung, string $stufe = 'info'): string
    {
        $daten = [
            'person_id' => $personId,
            'status'    => $status,
        ];

        if ($nummer !== null) {
            $daten['alt_endet_auf'] = substr($nummer, -4);
        }
        if ($meldung !== null) {
            $daten['meldung'] = $meldung;
        }

        Log::{$stufe}('recruiting.konto.nummernwechsel_hinweis', $daten);

        return $status;
    }
}
