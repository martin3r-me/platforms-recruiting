<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDeclineCheck;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Symfony\Component\Uid\UuidV7;

/**
 * Personenwechsel in einer ZAS-Zeile (Markus, Mail 13.10.2026): Die Dispo
 * tauscht in ZAS nur den Namen, die DS-ID bleibt. Ohne diesen Baustein erbte
 * die neue Person alles, was an der Zeile hing — die Absage der alten, ihre
 * Bestaetigung, den Versand-Stempel, die Eskalation, die persoenliche Notiz.
 *
 * Erkannt wird ein Wechsel an der Personalnummer: Sie weicht ab UND gehoert
 * nicht derselben Person. Dieselbe Person = gleicher Datensatz, gleicher
 * person_key oder gemeinsamer CRM-Kontakt — auch wenn einer der Datensaetze
 * inzwischen inaktiv ist (RG- und MA-Nummer desselben Menschen sind KEIN
 * Wechsel; Muster MA-Akte/EmployeeSenderResolver).
 *
 * Folge eines Wechsels:
 *  - Hing an der Zeile schon etwas (Versand, Bestaetigung, Absage, Notiz …),
 *    wird die alte Besetzung als eigene Zeile abgelegt: DS-ID mit Anhang
 *    "~alt1", missing_since gesetzt (= in ZAS verschwunden). So bleiben die
 *    Absage in der HR-Liste, die Portal-Sperre und die Historie in der
 *    MA-Akte bei der alten Person. Die Bestaetigung wird an der Ablage
 *    geleert, sonst meldete der Bestaetigungs-Export eine erfundene DS-ID an
 *    ZAS zurueck; der Zeitpunkt steht in source_meta.
 *    Was per Zeilen-ID auf die Einbuchung zeigt (KI-Absage-Pruefungen,
 *    angenommene Jobangebote), zieht mit in die Ablage um.
 *  - Die Zeile selbst startet fuer die neue Person frisch (RESET).
 *  - Zurueck-Tausch (A -> B -> A) startet ebenfalls frisch; A wird neu
 *    angeschrieben. Bewusst: eine alte Absage von A waere hier falsch.
 *
 * plan() liest nur; archivieren() schreibt und laeuft in der Import-Transaktion.
 */
class DispoPersonenwechsel
{
    /** Alles, was an der Zeile der PERSON gehoert, nicht dem Einsatz. */
    public const RESET = [
        'reminder_sent_at'              => null,
        'reminder_message_id'           => null,
        'reminder_sent_to'              => null,
        'confirmed_at'                  => null,
        'confirmed_datum'               => null,
        'confirmed_von'                 => null,
        'confirmed_bis'                 => null,
        'reconfirm_required_at'         => null,
        'reconfirm_previous'            => null,
        'manually_confirmed_by_user_id' => null,
        'deletion_marked_at'            => null,
        'deletion_confirmed_at'         => null,
        'declined_at'                   => null,
        'declined_reason'               => null,
        'declined_note'                 => null,
        'declined_by_user_id'           => null,
        'declined_portal_locked'        => false,
        'declined_hr_at'                => null,
        'declined_hr_done_at'           => null,
        'declined_hr_done_by_user_id'   => null,
        'zas_removed_at'                => null,
        'zas_removed_by_user_id'        => null,
        'escalation_due_1_at'           => null,
        'escalation_due_2_at'           => null,
        'escalation_due_3_at'           => null,
        'escalation_1_at'               => null,
        'escalation_2_at'               => null,
        'escalation_1_message_id'       => null,
        'escalation_2_message_id'       => null,
        'late_marked_at'                => null,
        'late_marked_by_user_id'        => null,
        'individual_note'               => null,
        'individual_note_updated_at'    => null,
        // Eingefrorenes Waeschepaket = was die ALTE Person bestaetigt hat.
        'rec_dispo_dress_package_id'    => null,
        'dress_frozen_at'               => null,
        'dress_items_text'              => null,
    ];

    public function __construct(private ?DispoIdentityResolver $identity = null)
    {
        $this->identity ??= new DispoIdentityResolver();
    }

    /**
     * @param array<string, string> $incomingPnrByDsRef ds_ref => pnr_raw der Lieferung
     * @return array<string, array{alt_pnr:string, alt_employee_id:?int, neu_pnr:string, archivieren:bool}>
     */
    public function plan(array $incomingPnrByDsRef, ZasDispoMatcher $matcher): array
    {
        if ($incomingPnrByDsRef === []) {
            return [];
        }

        $kandidaten = [];
        foreach (array_chunk(array_keys($incomingPnrByDsRef), 1000) as $chunk) {
            RecDispoAssignment::query()
                ->whereIn('ds_ref', array_map('strval', $chunk))
                ->get()
                ->each(function (RecDispoAssignment $a) use ($incomingPnrByDsRef, &$kandidaten) {
                    $neu = trim((string) $incomingPnrByDsRef[(string) $a->ds_ref]);
                    if (self::norm((string) $a->pnr_raw) !== self::norm($neu)) {
                        $kandidaten[(string) $a->ds_ref] = $a;
                    }
                });
        }
        if ($kandidaten === []) {
            return [];
        }

        // Wer war vorher drin, wer kommt jetzt? Alte Zeilen ohne Treffer werden
        // mit dem heutigen Stamm nachgematcht — sonst gaelte eine Nummer, die
        // beim letzten Import noch nicht im Stamm war, als fremde Person.
        $paare = [];
        $ids = [];
        foreach ($kandidaten as $dsRef => $a) {
            $alt = $a->rec_employee_id !== null ? (int) $a->rec_employee_id : $matcher->match($a->pnr_raw)['employee_id'];
            $neu = $matcher->match($incomingPnrByDsRef[$dsRef])['employee_id'];
            $paare[$dsRef] = [$alt, $neu];
            foreach ([$alt, $neu] as $id) {
                if ($id !== null) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        $kontakte = $ids === [] ? [] : $this->identity->contactIdsByEmployee($ids);
        $personKeys = $ids === [] ? [] : DB::table('rec_employees')->whereIn('id', $ids)
            ->whereNotNull('person_key')->where('person_key', '!=', '')
            ->pluck('person_key', 'id')->map(fn ($v) => (string) $v)->all();

        $wechsel = [];
        foreach ($kandidaten as $dsRef => $a) {
            [$alt, $neu] = $paare[$dsRef];
            if (self::dieselbePerson($alt, $neu, $kontakte, $personKeys)) {
                continue; // dieselbe Person, nur eine andere Nummer
            }
            $wechsel[$dsRef] = [
                'alt_pnr'         => (string) $a->pnr_raw,
                'alt_employee_id' => $a->rec_employee_id !== null ? (int) $a->rec_employee_id : null,
                'neu_pnr'         => trim((string) $incomingPnrByDsRef[$dsRef]),
                'archivieren'     => false,
            ];
        }

        // Ablegen, sobald etwas an der Zeile haengt: eigene Spalten ODER
        // fremde Datensaetze, die per Zeilen-ID auf sie zeigen.
        $verwiesen = $this->verwieseneIds(array_map(fn ($dsRef) => (int) $kandidaten[$dsRef]->id, array_keys($wechsel)));
        foreach ($wechsel as $dsRef => &$w) {
            $a = $kandidaten[$dsRef];
            $w['archivieren'] = self::hatSpuren($a) || isset($verwiesen[(int) $a->id]);
        }
        unset($w);

        return $wechsel;
    }

    /** Legt die alte Besetzung als verschwundene Zeile ab. */
    public function archivieren(string $dsRef, string $neuPnr): void
    {
        $alt = RecDispoAssignment::query()->where('ds_ref', $dsRef)->first();
        if ($alt === null) {
            return;
        }

        $nr = 1;
        while (RecDispoAssignment::query()->where('ds_ref', $dsRef . '~alt' . $nr)->exists()) {
            $nr++;
        }

        $kopie = $alt->replicate();
        $kopie->uuid = (string) UuidV7::generate();
        $kopie->ds_ref = $dsRef . '~alt' . $nr;
        $kopie->missing_since = $alt->missing_since ?? now();
        $kopie->confirmed_at = null;
        $kopie->source_meta = array_merge((array) ($alt->source_meta ?? []), [
            'personenwechsel' => [
                'ersetzt_am'       => now()->toDateTimeString(),
                'original_ds_ref'  => $dsRef,
                'nachfolger_pnr'   => $neuPnr,
                'war_bestaetigt_am' => $alt->confirmed_at?->toDateTimeString(),
            ],
        ]);
        $kopie->save();

        $this->verweiseUmziehen($alt, (int) $kopie->id);
    }

    /**
     * @param array<int, list<int>> $kontakte employee_id => contact_ids
     * @param array<int, string> $personKeys employee_id => person_key
     */
    private static function dieselbePerson(?int $alt, ?int $neu, array $kontakte, array $personKeys): bool
    {
        if ($alt === null || $neu === null) {
            return false;
        }
        if ($alt === $neu) {
            return true;
        }
        if (isset($personKeys[$alt], $personKeys[$neu]) && $personKeys[$alt] === $personKeys[$neu]) {
            return true;
        }

        return array_intersect($kontakte[$alt] ?? [], $kontakte[$neu] ?? []) !== [];
    }

    /**
     * Zeilen-IDs, auf die eine KI-Absage-Pruefung oder ein Jobangebot zeigt.
     * @param list<int> $assignmentIds
     * @return array<int, true>
     */
    private function verwieseneIds(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }
        $out = [];
        if (Schema::hasTable('rec_dispo_job_offer_shifts')) {
            foreach (RecDispoJobOfferShift::query()->whereIn('zas_assignment_id', $assignmentIds)->pluck('zas_assignment_id') as $id) {
                $out[(int) $id] = true;
            }
        }
        if (Schema::hasTable('rec_dispo_decline_checks')) {
            $eventIds = RecDispoAssignment::query()->whereIn('id', $assignmentIds)->pluck('rec_dispo_event_id')->unique()->all();
            $gesucht = array_flip($assignmentIds);
            RecDispoDeclineCheck::query()->whereIn('rec_dispo_event_id', $eventIds)->get(['assignment_ids'])
                ->each(function ($c) use ($gesucht, &$out) {
                    foreach ((array) ($c->assignment_ids ?? []) as $id) {
                        if (isset($gesucht[(int) $id])) {
                            $out[(int) $id] = true;
                        }
                    }
                });
        }

        return $out;
    }

    /** Verweise auf die Live-Zeile zeigen danach auf die Ablage der alten Person. */
    private function verweiseUmziehen(RecDispoAssignment $alt, int $ablageId): void
    {
        $liveId = (int) $alt->id;
        if (Schema::hasTable('rec_dispo_job_offer_shifts')) {
            RecDispoJobOfferShift::query()->where('zas_assignment_id', $liveId)->update(['zas_assignment_id' => $ablageId]);
        }
        if (Schema::hasTable('rec_dispo_decline_checks')) {
            RecDispoDeclineCheck::query()->where('rec_dispo_event_id', $alt->rec_dispo_event_id)->get()
                ->each(function (RecDispoDeclineCheck $c) use ($liveId, $ablageId) {
                    $ids = array_map('intval', (array) ($c->assignment_ids ?? []));
                    if (!in_array($liveId, $ids, true)) {
                        return;
                    }
                    $c->assignment_ids = array_values(array_map(fn ($id) => $id === $liveId ? $ablageId : $id, $ids));
                    $c->save();
                });
        }
    }

    /** Hing an der Zeile schon etwas, das der Person gehoert? */
    public static function hatSpuren(RecDispoAssignment $a): bool
    {
        foreach (array_keys(self::RESET) as $spalte) {
            $wert = $a->getAttribute($spalte);
            if ($wert !== null && $wert !== false && $wert !== '' && $wert !== 0 && $wert !== []) {
                return true;
            }
        }

        return false;
    }

    private static function norm(string $pnr): string
    {
        return mb_strtoupper(trim($pnr));
    }
}
