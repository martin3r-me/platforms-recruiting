<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;
use Symfony\Component\Uid\UuidV7;

/**
 * Personenwechsel in einer ZAS-Zeile (Markus, Mail 13.10.2026): Die Dispo
 * tauscht in ZAS nur den Namen, die DS-ID bleibt. Ohne diesen Baustein erbte
 * die neue Person alles, was an der Zeile hing — die Absage der alten, ihre
 * Bestaetigung, den Versand-Stempel, die Eskalation, die persoenliche Notiz.
 *
 * Erkannt wird ein Wechsel an der Personalnummer: Sie weicht ab UND gehoert
 * nicht derselben Person (gleicher Datensatz oder dieselbe Identitaetsgruppe,
 * also RG- und MA-Nummer desselben Menschen sind KEIN Wechsel).
 *
 * Folge eines Wechsels:
 *  - Hing an der Zeile schon etwas (Versand, Bestaetigung, Absage, Notiz …),
 *    wird die alte Besetzung als eigene Zeile abgelegt: DS-ID mit Anhang
 *    "~alt1", missing_since gesetzt (= in ZAS verschwunden). So bleiben die
 *    Absage in der HR-Liste, die Portal-Sperre und die Historie in der
 *    MA-Akte bei der alten Person. Die Bestaetigung wird an der Ablage
 *    geleert, sonst meldete der Bestaetigungs-Export eine erfundene DS-ID an
 *    ZAS zurueck; der Zeitpunkt steht in source_meta.
 *  - Die Zeile selbst startet fuer die neue Person frisch (RESET).
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
     * @return array<string, array{alt_pnr:string, neu_pnr:string, archivieren:bool}>
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
        $gruppen = $ids === [] ? [] : $this->identity->groupsFor($ids);

        $wechsel = [];
        foreach ($kandidaten as $dsRef => $a) {
            [$alt, $neu] = $paare[$dsRef];
            if ($alt !== null && $neu !== null
                && ($alt === $neu || in_array($neu, $gruppen[$alt] ?? [$alt], true))) {
                continue; // dieselbe Person, nur eine andere Nummernform
            }
            $wechsel[$dsRef] = [
                'alt_pnr'     => (string) $a->pnr_raw,
                'neu_pnr'     => trim((string) $incomingPnrByDsRef[$dsRef]),
                'archivieren' => self::hatSpuren($a),
            ];
        }

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
        $kopie->missing_since = now();
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
