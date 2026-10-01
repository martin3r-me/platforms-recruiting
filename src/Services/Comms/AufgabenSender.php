<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;

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
 * DIE NACHRICHT NENNT DIE OFFENEN PUNKTE NICHT EINZELN (Spec §2.1: das
 * Portal traegt die Aufgaben) — sie nennt nur ihre ANZAHL und verweist aufs
 * Portal. Waere die Nachricht selbst der Traeger, muesste jede Aenderung an
 * den Punkten eine neue Nachricht erzeugen.
 *
 * KEINE VOLLE RUFNUMMER IM LOG (Datenschutz, gleiche Kuerzung wie in
 * `KontoWriter`/`EinmalcodeSender`): `PhoneE164::suffix()` liefert die
 * letzten neun Ziffern formatunabhaengig, geloggt werden davon nur die
 * letzten vier.
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
     * schiefgehen kann: unbefuellbarer Platzhalter, kein WhatsApp-Kanal, von
     * Meta abgelehnt, Ausnahme beim Senden.
     */
    public const STATUS_FAILED = 'failed';

    /** Kein Meta-Vorlagenname fuer diesen Anlass eingetragen — es wird nichts verschickt. */
    public const STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert';

    /**
     * @param  array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}  $stand  Rueckgabe von OffenePunkte::fuer()
     * @param  string  $anlass  'neu' oder 'erinnerung'
     */
    public function sende(RecEmployee $employee, array $stand, string $anlass): string
    {
        $vorlage = (array) config("recruiting.aufgaben.vorlagen.{$anlass}", []);
        $name = trim((string) ($vorlage['name'] ?? ''));

        if ($name === '') {
            // Kein stiller Fehlschlag: ohne Vorlage kann niemand erreicht
            // werden, und das ist bis zur Freigabe bei Meta der Normalfall.
            Log::error('recruiting.aufgaben.vorlage_fehlt', ['anlass' => $anlass]);

            return self::STATUS_NICHT_KONFIGURIERT;
        }

        $werte = [
            'anzahl' => (string) count($stand['punkte'] ?? []),
            'datum'  => (string) ($stand['einsatz']['datum'] ?? ''),
        ];

        $komponenten = [];
        foreach ((array) ($vorlage['platzhalter'] ?? []) as $platzhalter) {
            $platzhalter = (string) $platzhalter;
            $schluessel  = strtolower($platzhalter);

            // Strikter Leerstring-Vergleich, NICHT empty(): "0" offene Punkte
            // ist ein gueltiger, befuellter Wert und darf nicht wie ein
            // fehlender behandelt werden.
            if (!array_key_exists($schluessel, $werte) || $werte[$schluessel] === '') {
                // HoldingTemplateComponents::build() wuerde hier still den
                // Vornamen einsetzen — der Mensch bekaeme seinen Namen statt
                // der Zahl, und Meta naehme es an. Lieber gar nicht senden.
                Log::warning('recruiting.aufgaben.platzhalter_unbefuellbar', [
                    'anlass'      => $anlass,
                    'platzhalter' => $platzhalter,
                ]);

                return self::STATUS_FAILED;
            }

            $komponenten[] = ['type' => 'text', 'parameter_name' => $schluessel, 'text' => $werte[$schluessel]];
        }

        $kanal = $this->kanal((int) $employee->team_id);
        if ($kanal === null) {
            Log::error('recruiting.aufgaben.kein_kanal', ['anlass' => $anlass]);

            return self::STATUS_FAILED;
        }

        $nummer = PhoneE164::normalize($employee->phone) ?? (string) $employee->phone;
        $sprache = trim((string) ($vorlage['sprache'] ?? 'de')) ?: 'de';
        $components = [['type' => 'body', 'parameters' => $komponenten]];

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
                'nummer_endet_auf' => $this->endungVon($nummer),
            ]);

            return self::STATUS_FAILED;
        }

        return self::STATUS_SENT;
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
