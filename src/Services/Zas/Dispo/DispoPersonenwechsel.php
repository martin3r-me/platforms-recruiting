<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDeclineCheck;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zeit\DispoZeitBezug;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitAkteur;
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
 * Kein Wechsel, aber eine andere Anstellung DERSELBEN Person (RG -> MA): die
 * Zeile laeuft weiter, es gibt keine Ablage. Damit der Zeitdatensatz nicht an
 * der alten Anstellung haengen bleibt, meldet planMitAnstellung() diese Zeilen
 * und zeitPersonUmschreiben() zieht den Datensatz auf die neue Anstellung.
 *
 * plan() liest nur; archivieren() und zeitPersonUmschreiben() schreiben und
 * laufen in der Import-Transaktion.
 */
class DispoPersonenwechsel
{
    /** Alles, was an der Zeile der PERSON gehoert, nicht dem Einsatz. */
    public const RESET = [
        'reminder_sent_at'              => null,
        'reminder_message_id'           => null,
        'reminder_sent_to'              => null,
        // Erinnerungs-Stempel der Einsatz-Pruefung (Zweig feat/ma-konto, ET-18)
        // gehoert der ALTEN Person — die neue ist nicht erinnert.
        'aufgaben_erinnert_at'          => null,
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
        return $this->planMitAnstellung($incomingPnrByDsRef, $matcher)['wechsel'];
    }

    /**
     * Wie plan(), dazu die Zeilen, an denen DIESELBE Person mit einer anderen
     * Anstellung weiterlaeuft (kein Wechsel, keine Ablage): bisher ein Treffer,
     * jetzt ein anderer Treffer, beide nach dem Kriterium oben derselbe Mensch.
     * Liest nur.
     *
     * @param array<string, string> $incomingPnrByDsRef ds_ref => pnr_raw der Lieferung
     * @return array{
     *   wechsel: array<string, array{alt_pnr:string, alt_employee_id:?int, neu_pnr:string, archivieren:bool}>,
     *   anstellung: array<string, array{assignment_id:int, von:int, nach:int}>
     * }
     */
    public function planMitAnstellung(array $incomingPnrByDsRef, ZasDispoMatcher $matcher): array
    {
        $leer = ['wechsel' => [], 'anstellung' => []];
        if ($incomingPnrByDsRef === []) {
            return $leer;
        }

        $kandidaten = [];
        // Gleiche Nummer, aber der Stamm loest sie heute auf einen anderen Datensatz auf:
        // fuer den Wechsel unerheblich (wie bisher), fuer die Zeiterfassung nicht.
        $gleicheNummer = [];
        foreach (array_chunk(array_keys($incomingPnrByDsRef), 1000) as $chunk) {
            RecDispoAssignment::query()
                ->whereIn('ds_ref', array_map('strval', $chunk))
                ->get()
                ->each(function (RecDispoAssignment $a) use ($incomingPnrByDsRef, $matcher, &$kandidaten, &$gleicheNummer) {
                    $neu = trim((string) $incomingPnrByDsRef[(string) $a->ds_ref]);
                    if (self::norm((string) $a->pnr_raw) !== self::norm($neu)) {
                        $kandidaten[(string) $a->ds_ref] = $a;
                    } elseif ($a->rec_employee_id !== null) {
                        $treffer = $matcher->match($neu)['employee_id'];
                        if ($treffer !== null && (int) $treffer !== (int) $a->rec_employee_id) {
                            $gleicheNummer[(string) $a->ds_ref] = ['assignment_id' => (int) $a->id, 'von' => (int) $a->rec_employee_id, 'nach' => (int) $treffer];
                        }
                    }
                });
        }
        $anstellung = $this->nurDieselbePerson($gleicheNummer);
        if ($kandidaten === []) {
            return ['wechsel' => [], 'anstellung' => $anstellung];
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
                // Dieselbe Person, nur eine andere Nummer. Haengt die Zeile dabei
                // an einer anderen Anstellung, muss die Zeiterfassung mitziehen.
                if ($a->rec_employee_id !== null && $neu !== null && (int) $a->rec_employee_id !== (int) $neu) {
                    $anstellung[$dsRef] = ['assignment_id' => (int) $a->id, 'von' => (int) $a->rec_employee_id, 'nach' => (int) $neu];
                }
                continue;
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
        $personen = [];
        foreach (array_keys($wechsel) as $dsRef) {
            if ($kandidaten[$dsRef]->rec_employee_id !== null) {
                $personen[(int) $kandidaten[$dsRef]->id] = (int) $kandidaten[$dsRef]->rec_employee_id;
            }
        }
        $verwiesen += $this->zeitIds($personen);
        foreach ($wechsel as $dsRef => &$w) {
            $a = $kandidaten[$dsRef];
            $w['archivieren'] = self::hatSpuren($a) || isset($verwiesen[(int) $a->id]);
        }
        unset($w);

        return ['wechsel' => $wechsel, 'anstellung' => $anstellung];
    }

    /**
     * Behaelt nur die Zeilen, deren alte und neue Anstellung derselbe Mensch sind.
     *
     * @param array<string, array{assignment_id:int, von:int, nach:int}> $zeilen
     * @return array<string, array{assignment_id:int, von:int, nach:int}>
     */
    private function nurDieselbePerson(array $zeilen): array
    {
        if ($zeilen === []) {
            return [];
        }
        $ids = [];
        foreach ($zeilen as $z) {
            $ids[] = $z['von'];
            $ids[] = $z['nach'];
        }
        $ids = array_values(array_unique($ids));
        $kontakte = $this->identity->contactIdsByEmployee($ids);
        $personKeys = DB::table('rec_employees')->whereIn('id', $ids)
            ->whereNotNull('person_key')->where('person_key', '!=', '')
            ->pluck('person_key', 'id')->map(fn ($v) => (string) $v)->all();

        return array_filter($zeilen, fn (array $z) => self::dieselbePerson($z['von'], $z['nach'], $kontakte, $personKeys));
    }

    /**
     * Zeiterfassung (Review 16.11., W1/W3): Die Zeile laeuft fuer dieselbe
     * Person mit einer anderen Anstellung weiter — der Zeitdatensatz zieht auf
     * die neue Anstellung mit, sonst zeigte die Disposition „nicht eingecheckt“
     * und ein zweiter Datensatz verdraengte den echten Stempel. Hat die neue
     * Anstellung dort schon einen Datensatz, bleibt alles stehen (Protokoll).
     * Fehler brechen den Import nicht ab — ausser Nebenlaeufigkeitsfehlern
     * (siehe zeitFehler()).
     *
     * @return string ok | kein_datensatz | ziel_existiert | ohne_tabelle | fehler
     */
    public function zeitPersonUmschreiben(int $assignmentId, int $vonEmployeeId, int $nachEmployeeId): string
    {
        if ($vonEmployeeId === $nachEmployeeId || !Schema::hasTable('rec_zeit_eintraege')) {
            return 'ohne_tabelle';
        }
        $kontext = ['assignment_id' => $assignmentId, 'von_person_id' => $vonEmployeeId, 'nach_person_id' => $nachEmployeeId];
        try {
            $status = app(Stempeluhr::class)->personUmschreiben(
                DispoZeitBezug::BEZUG_TYP,
                $assignmentId,
                DispoZeitBezug::PERSON_TYP,
                $vonEmployeeId,
                $nachEmployeeId,
                new ZeitAkteur('system', null, 'ZAS-Import'),
            );
        } catch (\Throwable $e) {
            $this->zeitFehler($e, 'zeit_person_umschreiben_fehlgeschlagen', $kontext);

            return 'fehler';
        }
        if ($status === 'ziel_existiert') {
            Log::info('zeit_person_umschreiben_ziel_existiert', $kontext);
        }

        return $status;
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

    /**
     * Live-Zeilen, an denen ein Zeitdatensatz der bisherigen Person haengt
     * (Review 16.11., W2): Auch eine Zeile, an der NUR gestempelt wurde, hat
     * eine Spur — ohne Ablage bliebe der Datensatz der alten Person an der
     * Live-Zeile haengen und waere nirgends mehr zu finden (Spec §7.5).
     *
     * @param array<int, int> $personByAssignment Zeilen-ID => bisherige rec_employee_id
     * @return array<int, true>
     */
    private function zeitIds(array $personByAssignment): array
    {
        if ($personByAssignment === [] || !Schema::hasTable('rec_zeit_eintraege')) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_keys($personByAssignment), 1000) as $chunk) {
            $zeilen = DB::table('rec_zeit_eintraege')
                ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
                ->where('person_typ', DispoZeitBezug::PERSON_TYP)
                ->whereIn('bezug_id', $chunk)
                ->get(['bezug_id', 'person_id']);
            foreach ($zeilen as $z) {
                if (($personByAssignment[(int) $z->bezug_id] ?? null) === (int) $z->person_id) {
                    $out[(int) $z->bezug_id] = true;
                }
            }
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
        $this->zeitUmziehen($alt, $liveId, $ablageId);
    }

    /**
     * Zeiterfassung (Spec 2026-11-16 §2.2): Der Zeitdatensatz der ALTEN Person
     * zieht in die Ablage mit — auch ein gesperrter („erledigt“), es aendert
     * sich ja kein Zeitwert. umhaengen() ist ein einzelnes UPDATE (ohne
     * Wiederholung) und darf deshalb in der Import-Transaktion laufen. Ein
     * Fehler hier bricht den Import NICHT ab: die Zeit-Zuordnung ist weniger
     * wichtig als die Lieferung. Ausnahme: Nebenlaeufigkeitsfehler (zeitFehler()).
     */
    private function zeitUmziehen(RecDispoAssignment $alt, int $liveId, int $ablageId): void
    {
        if ($alt->rec_employee_id === null || !Schema::hasTable('rec_zeit_eintraege')) {
            return;
        }
        try {
            app(Stempeluhr::class)->umhaengen(
                DispoZeitBezug::BEZUG_TYP,
                $liveId,
                $ablageId,
                DispoZeitBezug::PERSON_TYP,
                (int) $alt->rec_employee_id,
            );
        } catch (\Throwable $e) {
            $this->zeitFehler($e, 'zeit_umhaengen_fehlgeschlagen', [
                'live_id'  => $liveId,
                'ablage_id' => $ablageId,
                'person_id' => (int) $alt->rec_employee_id,
            ]);
        }
    }

    /**
     * Fehler der Zeiterfassung im Import: protokollieren und weiter. NICHT bei
     * Nebenlaeufigkeitsfehlern (Deadlock, Lock-Timeout): die Datenbank hat dann
     * die GANZE Import-Transaktion schon zurueckgerollt — verschluckt, liefe der
     * Rest der Lieferung ohne Transaktion weiter (halb angewendet). Also
     * weiterwerfen; der Import scheitert sauber und wird wiederholt.
     *
     * @param array<string, mixed> $kontext
     */
    private function zeitFehler(\Throwable $e, string $meldung, array $kontext): void
    {
        if ($e instanceof DeadlockException || (new ConcurrencyErrorDetector())->causedByConcurrencyError($e)) {
            throw $e;
        }
        Log::warning($meldung, $kontext + ['fehler' => $e->getMessage()]);
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
