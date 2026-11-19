<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Versendet Bestaetigungs-Templates ueber den Dispo-Kanal.
 *
 * Kanal-Aufloesung ueber DispoChannelResolver::resolveForEvent() (Filial-Kanal
 * der Veranstaltung, sonst Default-Dispo-Kanal) — identisch zur Eskalation,
 * damit Erstversand und Erinnerungen/Alarm von derselben Nummer kommen.
 * Components werden hier selbst gebaut — bewusst NICHT sendManualTemplate
 * (bekannter Form-Token-Bug).
 *
 * Body-Variablen-Vertrag des genehmigten Meta-Templates (Namenskonvention
 * `dispo_einsatz_bestaetigung`), Reihenfolge ist bindend, alle Werte MUESSEN
 * nicht-leere Strings sein (Meta lehnt leere Parameter ab):
 * {{1}} Vorname (contact['first_name'], Fallback contact['name'] falls leer — Meta lehnt leere Parameter ab)
 * {{2}} erster Einsatztag (d.m.Y)
 * {{3}} VA-Name (event->name ?? event->einsatz_ref)
 * {{4}} Vorlauf-Minuten (event->vorlauf_minuten, Modal-Pflichtfeld; ?? 0 nur Belt-and-Braces)
 * {{5}} Schichtzeit des ERSTEN Einsatztags, siehe firstShiftLabel()
 * URL-Button (index 0): portal_token
 */
class DispoConfirmationSender
{
    public function __construct(
        private DispoEmployeeGateway $gateway,
        // Default, damit Controller/Command/Tests unveraendert bleiben.
        private DispoDressResolver $dress = new DispoDressResolver(),
        // Pause vor dem zweiten Versuch (Sekunden); Tests setzen eine Attrappe.
        private ?\Closure $pause = null,
    ) {}

    /** Pause vor dem zweiten Versuch bei einer voruebergehenden Meta-Stoerung. */
    public const PAUSE_SEKUNDEN = 2;

    /**
     * Meta-Fehlercodes, die „gleich nochmal“ bedeuten (Vorfall 19.11.2026: #2
     * „Service temporarily unavailable“ und #131000 „Something went wrong“ mitten
     * im Versand eines Spieltags). Dazu alles, was Meta selbst als is_transient meldet.
     */
    private const VORUEBERGEHEND = [2, 131000, 131016];

    /**
     * @param list<array{employee_id:int, phone:string, assignment_ids:list<int>, first_datum:string, is_reminder:bool}> $recipients
     * @return array{ok: bool, message: ?string, sent: int, failed: list<array{employee_id:int, error:string}>}
     */
    /** @param array{1:\DateTimeInterface,2:\DateTimeInterface,3:\DateTimeInterface}|null $escalationDue Eskalation pro Sendung: wird den Empfaengern mitgestempelt. */
    /**
     * $laufStart: Zeitpunkt, zu dem die Empfaengerliste geplant wurde (Hintergrund-Versand).
     * Damit erkennt der Riegel vor jeder Nachricht auch eine Erinnerung, die ein anderer
     * Lauf inzwischen schon verschickt hat. Rueckgabe zusaetzlich: skipped = so viele
     * Empfaenger waren beim Versand schon angeschrieben und wurden ausgelassen.
     */
    public function send(RecDispoEvent $event, array $recipients, int $templateId, ?array $escalationDue = null, ?\DateTimeInterface $laufStart = null): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)
            || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return ['ok' => false, 'message' => 'WhatsApp-Integrations-Modul nicht verfuegbar.', 'sent' => 0, 'failed' => [], 'skipped' => 0];
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Template nicht gefunden oder nicht genehmigt.', 'sent' => 0, 'failed' => [], 'skipped' => 0];
        }

        $channel = app(\Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver::class)->resolveForEvent($event);
        if (!$channel) {
            return ['ok' => false, 'message' => 'Kein aktiver Kanal fuer diese Veranstaltung.', 'sent' => 0, 'failed' => [], 'skipped' => 0];
        }

        $contacts = $this->gateway->contacts(array_column($recipients, 'employee_id'));
        $service  = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);

        $assignmentTimes = collect();
        if ($recipients !== []) {
            $assignmentTimes = RecDispoAssignment::query()
                ->whereIn('id', array_merge(...array_column($recipients, 'assignment_ids')))
                ->get(['id', 'datum', 'von', 'bis'])
                ->keyBy('id');
        }

        $sent = 0;
        $failed = [];
        $skipped = 0;

        foreach ($recipients as $recipient) {
            // Riegel direkt vor der Nachricht (Vorfall 19.11.2026): die Liste wurde
            // frueher geplant — hat ein anderer Lauf diese Person inzwischen
            // angeschrieben, bekommt sie keine zweite Nachricht.
            if ($this->inzwischenAngeschrieben($recipient, $laufStart)) {
                $skipped++;
                continue;
            }
            $contact = $contacts[$recipient['employee_id']] ?? null;
            // Die in der Vorschau bestimmte Nummer gewinnt (eingebuchter Datensatz
            // zuerst, Gruppen-Fallback) — contacts() nur als letzte Reserve.
            $phone = $recipient['phone'] ?? ($contact['phone'] ?? null);
            if ($contact === null || $phone === null) {
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => 'Kontaktdaten nicht mehr verfuegbar'];
                continue;
            }

            try {
                $components = [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => ($contact['first_name'] !== '' ? $contact['first_name'] : $contact['name'])],
                            ['type' => 'text', 'text' => \Carbon\Carbon::parse($recipient['first_datum'])->format('d.m.Y')],
                            ['type' => 'text', 'text' => (string) ($event->name ?? $event->einsatz_ref)],
                            ['type' => 'text', 'text' => (string) ($event->vorlauf_minuten ?? 0)],
                            ['type' => 'text', 'text' => $this->firstShiftLabel($recipient['assignment_ids'], $assignmentTimes)],
                        ],
                    ],
                    [
                        'type'       => 'button',
                        'sub_type'   => 'url',
                        'index'      => 0,
                        'parameters' => [['type' => 'text', 'text' => $contact['portal_token']]],
                    ],
                ];

                $senden = fn () => $service->sendTemplate(
                    channel:      $channel,
                    to:           $phone,
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                );
                $message = $senden();

                // Voruebergehende Stoerung bei Meta: kurz warten, EINMAL erneut.
                // Nur bei einer ausdruecklichen Ablehnung — nie nach einer Exception
                // (Zeitueberschreitung): dort kann Meta die Nachricht schon haben.
                if (($message->status ?? null) === 'failed' && self::istVoruebergehend($message)) {
                    Log::info('Dispo-Bestaetigung: Meta voruebergehend gestoert, zweiter Versuch', [
                        'event_id' => $event->id, 'employee_id' => $recipient['employee_id'],
                    ]);
                    $this->pausieren();
                    $message = $senden();
                }

                if (($message->status ?? null) === 'failed') {
                    $failed[] = [
                        'employee_id' => $recipient['employee_id'],
                        'error'       => (string) ($message->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.'),
                    ];
                    continue;
                }

                $stamp = [
                    'reminder_sent_at'    => now(),
                    'reminder_message_id' => $message->id ?? null,
                    // Angeschrieben-an-Protokoll (Vorfall RG19734): spaetere
                    // Nummernkorrektur macht die Zeile automatisch wieder zum
                    // Zustellproblem (wasSentToOutdatedPhone).
                    'reminder_sent_to'    => $phone,
                ];
                if ($escalationDue !== null) {
                    $stamp['escalation_due_1_at'] = $escalationDue[1];
                    $stamp['escalation_due_2_at'] = $escalationDue[2];
                    $stamp['escalation_due_3_at'] = $escalationDue[3];
                }
                RecDispoAssignment::query()
                    ->whereIn('id', $recipient['assignment_ids'])
                    ->update($stamp);

                // Waeschepaket festschreiben: was der Mitarbeiter jetzt
                // bestaetigt, darf sich durch spaetere Aenderungen am Paket
                // nicht rueckwirkend aendern. Je Einbuchung, nicht je
                // Empfaenger — die Taetigkeit kann pro Tag abweichen.
                //
                // EIGENER try/catch (Fix-Runde 3, Befund 5): die WhatsApp ist
                // an dieser Stelle raus und die Einbuchung gestempelt. Wuerde
                // ein Fehler hier in den grossen catch laufen, landete der
                // Empfaenger in $failed, obwohl er die Nachricht hat — ein
                // Nachversand doppelte sie an einen echten Menschen. Der
                // reale Ausloeser ist die fehlende Spalte nach einem Deploy,
                // dessen Skript im npm-Teil gerissen ist (Code live,
                // migrate nicht gelaufen). Kleidung fehlt dann auf der
                // Einsatz-Seite — das ist der kleinere Schaden und laesst
                // sich mit einem zweiten Versand oder
                // recruiting:dispo-unfreeze-dress nachziehen.
                try {
                    $this->dress->freeze($recipient['assignment_ids']);
                } catch (\Throwable $dressError) {
                    Log::warning('Dispo-Bestaetigung: Waeschepaket nicht festgeschrieben (Versand war erfolgreich)', [
                        'event_id'       => $event->id,
                        'employee_id'    => $recipient['employee_id'],
                        'assignment_ids' => $recipient['assignment_ids'],
                        'error'          => $dressError->getMessage(),
                    ]);
                }
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Dispo-Bestaetigung: Versand fehlgeschlagen', [
                    'event_id' => $event->id, 'employee_id' => $recipient['employee_id'], 'error' => $e->getMessage(),
                ]);
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Ist der Empfaenger seit dem Planen der Liste schon angeschrieben worden?
     *  - Erstversand (mindestens eine Einbuchung war beim Planen ungestempelt):
     *    ja, sobald ALLE seine Einbuchungen einen Stempel tragen.
     *  - Erinnerung (alle waren schon gestempelt): ja, wenn alle Stempel ab dem
     *    Planungszeitpunkt liegen. Ohne $laufStart (direkter Aufruf) nie —
     *    dort gibt es keinen frueheren Planungszeitpunkt.
     * Fehlt eine Einbuchung inzwischen ganz, wird gesendet wie bisher.
     *
     * @param array{assignment_ids:list<int>, is_reminder?:bool} $recipient
     */
    private function inzwischenAngeschrieben(array $recipient, ?\DateTimeInterface $laufStart): bool
    {
        $ids = array_values(array_map('intval', (array) ($recipient['assignment_ids'] ?? [])));
        if ($ids === []) {
            return false;
        }
        $erinnerung = !empty($recipient['is_reminder']);
        if ($erinnerung && $laufStart === null) {
            return false;
        }

        $stempel = RecDispoAssignment::query()->whereIn('id', $ids)->pluck('reminder_sent_at', 'id');
        if ($stempel->count() !== count(array_unique($ids))) {
            return false;
        }
        $grenze = $laufStart !== null ? \Illuminate\Support\Carbon::instance(\DateTime::createFromInterface($laufStart)) : null;
        foreach ($stempel as $wert) {
            if ($wert === null) {
                return false;
            }
            if ($erinnerung && \Illuminate\Support\Carbon::parse($wert)->lessThan($grenze)) {
                return false;
            }
        }

        return true;
    }

    /** Meldet Meta die Ablehnung als voruebergehend (is_transient oder bekannter Code)? */
    private static function istVoruebergehend(object $message): bool
    {
        $payload = $message->meta_payload ?? null;
        $fehler = is_array($payload) ? ($payload['error'] ?? null) : null;
        if (!is_array($fehler)) {
            return false;
        }

        return !empty($fehler['is_transient']) || in_array((int) ($fehler['code'] ?? 0), self::VORUEBERGEHEND, true);
    }

    private function pausieren(): void
    {
        if ($this->pause !== null) {
            ($this->pause)(self::PAUSE_SEKUNDEN);

            return;
        }
        sleep(self::PAUSE_SEKUNDEN);
    }

    /**
     * Schichtzeit-Label fuer {{5}}: der chronologisch ERSTE Einsatztag dieses
     * Empfaengers auf dieser VA. Sortierung [datum, von] aufsteigend, dabei
     * steht eine Zeile ohne von-Zeit innerhalb desselben Tages hinten.
     * "16:00 bis 22:00", nur-von -> "16:00", keine von-Zeit -> Fallback
     * "siehe Infoseite" (Meta akzeptiert keine leeren Parameter).
     *
     * @param list<int> $assignmentIds
     */
    private function firstShiftLabel(array $assignmentIds, \Illuminate\Support\Collection $times): string
    {
        $first = collect($assignmentIds)
            ->map(fn (int $id) => $times->get($id))
            ->filter()
            ->sort(function ($a, $b) {
                $byDatum = $a->datum <=> $b->datum;
                if ($byDatum !== 0) {
                    return $byDatum;
                }

                return ($a->von ?? '99:99') <=> ($b->von ?? '99:99');
            })
            ->first();

        if ($first === null || $first->von === null) {
            return 'siehe Infoseite';
        }

        return $first->bis !== null ? "{$first->von} bis {$first->bis}" : $first->von;
    }
}
