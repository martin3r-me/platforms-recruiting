<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\ProofReminderSender;
use Platform\Recruiting\Support\ProofReminderPlanner;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Fristenlauf: findet faellige Nachweise (ProofReminderPlanner) und verschickt
 * je Mitarbeiter GENAU EINE WhatsApp (ProofReminderSender).
 *
 * DUENNE HUELLE, Muster PersonPairAudit: die Regeln liegen in reinen,
 * getesteten Klassen (Planner, Sender), dieses Kommando liest, ruft auf,
 * schreibt.
 *
 * GUARDS, DIE DEN BESTAND NICHT WIEDERHOLEN:
 *
 *  1. `reminded_at` wird NUR nach `ProofReminderSender::STATUS_SENT`
 *     geschrieben — ein von Meta abgelehnter Versand bleibt unerinnert und
 *     wird morgen automatisch erneut versucht (kein stiller Erfolg wie bei
 *     RecEmployee::sendPortalNotification).
 *  2. Schreibvorgaenge auf rec_employee_proofs laufen ueber den Query
 *     Builder, nicht ueber Eloquent — sonst koennte ein spaeter
 *     hinzugefuegter Observer auf rec_employees mitgeschleift werden
 *     (Muster ProofWriter, Vorfall 02.09.2026: 505 Bestands-Mitarbeiter in
 *     der Export-Datei durch einen Eloquent-Massenupdate).
 *  3. Eine Laufsperre (Cache::lock) haelt einen zweiten, parallel gestarteten
 *     Lauf ab (Fixrunde 2, Befund I3) — das Kommando wird von Hand gefahren,
 *     bei ~540 Einzel-Aufrufen an Meta ist ein zweiter Versuch im zweiten
 *     Terminal realistisch, und ohne Sperre bekaeme jeder Mensch zwei
 *     Nachrichten.
 *
 * GENAU EINE WHATSAPP JE PERSON UND LAUF: plan() sortiert global nach
 * Dringlichkeit (faelligstes zuerst). Wer zwei faellige Nachweise hat (z.B.
 * Ausweis UND Aufenthaltstitel), bekommt in diesem Lauf nur die dringendere
 * Erinnerung — der zweite Nachweis bleibt unerinnert und erscheint im
 * naechsten Lauf erneut (kein Grund, ihn zu ignorieren: er ist ja noch nicht
 * `reminded_at`). --limit zaehlt Personen, nicht Nachweis-Zeilen.
 *
 * ALTES PORTAL: IMMER uebersprungen, ohne Ausnahme-Option (Fixrunde 2, Ruling
 * C2 — eine urspruenglich vorgesehene --auch-altes-portal-Flagge wurde
 * ersatzlos entfernt). Wer `portal_v2_since` nicht gesetzt hat, kann im alten
 * Portal keinen Nachweis hochladen: der Knopf fuehrt auf eine 404-Seite, UND
 * der Mensch gilt danach DAUERHAFT als erinnert (reminded_at wird ja trotzdem
 * gesetzt) — bekommt also auch nach der Umstellung nie wieder eine Erinnerung.
 * Es gibt keinen legitimen Grund, das zu wollen: wer jemanden auf dem alten
 * Portal erinnern will, stellt ihn vorher um (recruiting:portal-umstellen).
 * Damit ist der Zielgruppenfilter unten die VOLLSTAENDIGE Antwort auf die
 * Frage "welches Portal oeffnet der Knopf" — keine Ternary noetig, die
 * zwischen alter und neuer Route unterscheidet.
 *
 * STICHTAG: ohne --stichtag ist die Bremse gegen die Altbestands-Welle AUS —
 * ProofReminderPlanner::plan() erinnert dann an JEDEN faelligen Nachweis,
 * auch die rund 540 bereits abgelaufenen. Der Stichtag ist eine
 * Kundenentscheidung (Markus, offen) und deshalb ein Kommandozeilen-Parameter,
 * kein Default im Code. Ein unlesbarer Wert (Fixrunde 2, Befund C1 — z.B. die
 * deutsche Schreibweise 24.09.2026 oder ein fehlendes fuehrendes Null wie
 * 2026-9-24) bricht den Lauf VOR jeder Aktion ab, statt die Bremse still
 * auszuschalten: ProofReminderPlanner::alsTag() liefert fuer sowas null, und
 * null heisst im Planer "kein Stichtag" — also KEINE Bremse. Genau das waere
 * der Schaden, gegen den der Parameter erfunden wurde.
 *
 * KEIN ZEITPLAN-EINTRAG (Ruling Fixrunde 1): dieses Kommando wird bis auf
 * Weiteres VON HAND gefahren, zuerst mit --dry-run. Ein Eintrag in
 * RecruitingServiceProvider::registerSchedule(), der dauerhaft auf --dry-run
 * stuende, waere eine Falle — der Naechste, der ihn sieht, haelt das fuer ein
 * Versehen und entfernt es, und dann gehen ohne Stichtag ueber 500 WhatsApps
 * auf einen Schlag raus. Erst automatisieren, wenn Markus den Stichtag
 * entschieden UND die Meta-Vorlage genehmigt ist.
 */
class SendProofReminders extends Command
{
    private const LOCK_KEY = 'recruiting:nachweise-erinnern:lock';

    /** Grosszuegig: ~540 Einzel-HTTP-Aufrufe an Meta koennen dauern. */
    private const LOCK_SECONDS = 1800;

    protected $signature = 'recruiting:nachweise-erinnern
        {--team= : Nur Mitarbeiter dieses Teams}
        {--stichtag= : Fristen VOR diesem Datum (Y-m-d) bleiben stumm — Bremse gegen die Altbestands-Welle. Ohne Angabe: keine Bremse. Unlesbarer Wert bricht den Lauf ab.}
        {--dry-run : Nichts senden, nichts schreiben — nur anzeigen, was fällig wäre}
        {--limit= : Höchstens so viele Personen erinnern (dringendste zuerst)}
        {--zuruecksetzen= : Statt zu erinnern: reminded_at bei diesen Nachweis-IDs leeren (komma-getrennt) — Reparatur, falls Meta erst per Webhook meldet, dass eine als "sent" geltende Nachricht nie ankam.}';

    protected $description = 'Fristenlauf Nachweise: fällige Nachweise finden und je Person genau eine WhatsApp-Erinnerung senden — läuft ohne Zeitplan, bis auf Weiteres von Hand (Stichtag + Meta-Vorlage noch offen)';

    public function handle(): int
    {
        if ($this->option('zuruecksetzen') !== null) {
            return $this->zuruecksetzen((string) $this->option('zuruecksetzen'));
        }

        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $stichtagRoh = $this->option('stichtag') !== null ? (string) $this->option('stichtag') : null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // C1: unlesbarer Stichtag bricht ab, statt die Bremse still
        // auszuschalten (siehe Klassen-Docblock).
        if ($stichtagRoh !== null && !$this->istGueltigesDatum($stichtagRoh)) {
            $this->error("Unlesbarer --stichtag '{$stichtagRoh}' — erwartet wird Y-m-d, z.B. 2026-09-24. Lauf abgebrochen, es wurde nichts gesendet.");

            return self::FAILURE;
        }
        $stichtag = $stichtagRoh;

        // Wirksame Parameter IMMER im Klartext, auch im Trockenlauf — wer das
        // liest, sieht sofort, ob die Bremse greift (C1).
        $this->info(sprintf(
            'Fristenlauf — Team: %s · Stichtag: %s · Limit: %s · Zielgruppe: nur Mitarbeiter mit neuem Portal (portal_v2_since gesetzt)%s',
            $teamId !== null ? (string) $teamId : 'alle',
            $stichtag ?? 'KEINE BREMSE — erinnert an ALLE fälligen Nachweise, auch den Altbestand',
            $limit !== null ? (string) $limit : 'kein Limit',
            $dryRun ? ' · TROCKENLAUF' : ''
        ));

        $nachweise = $this->offeneNachweise($teamId);
        $plan = ProofReminderPlanner::plan($nachweise, now()->toDateString(), $stichtag);

        // Je Person nur der dringendste Eintrag dieses Laufs — unique()
        // behaelt bei Collections das ERSTE Vorkommen, und plan() liefert die
        // Liste bereits nach Dringlichkeit sortiert.
        $jeEmployee = collect($plan)->unique('rec_employee_id')->values();
        if ($limit !== null) {
            $jeEmployee = $jeEmployee->take(max(0, $limit));
        }

        if ($jeEmployee->isEmpty()) {
            $this->info('Nichts zu erinnern.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Trockenlauf — %d Erinnerung(en) wären fällig, es wird nichts gesendet und nichts geschrieben.', $jeEmployee->count()));
            $this->table(
                ['MA-ID', 'Nachweis', 'Fällig am'],
                $jeEmployee->map(fn (array $e) => [
                    $e['rec_employee_id'],
                    ProofTypes::label($e['code']),
                    $e['valid_until'],
                ])->all()
            );

            return self::SUCCESS;
        }

        // I3: Laufsperre um den ganzen Lauf — verhindert doppelte
        // Erinnerungen durch einen zweiten, parallel gestarteten Aufruf.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (!$lock->get()) {
            $this->error('Es läuft bereits ein Fristenlauf.');

            return self::FAILURE;
        }

        try {
            return $this->sendeErinnerungen($jeEmployee);
        } finally {
            $lock->release();
        }
    }

    /** @param \Illuminate\Support\Collection<int, array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}> $jeEmployee */
    private function sendeErinnerungen($jeEmployee): int
    {
        $sender = app(ProofReminderSender::class);
        $gesendet = 0;
        $uebersprungen = 0;
        $abgebrochen = 0;

        foreach ($jeEmployee as $eintrag) {
            // I4: ein kaputter Datensatz (DB zuckt, CRM-Kette krumm) darf
            // nicht die restlichen Personen mit runterreissen — 540
            // Einzelfaelle, jeder fuer sich.
            try {
                $employee = RecEmployee::find($eintrag['rec_employee_id']);
                if ($employee === null) {
                    $uebersprungen++;
                    $this->warn("MA #{$eintrag['rec_employee_id']}: nicht gefunden, übersprungen.");
                    Log::warning('[SendProofReminders] Mitarbeiter nicht gefunden', [
                        'rec_employee_id' => $eintrag['rec_employee_id'],
                        'proof_id' => $eintrag['proof_id'] ?? null,
                    ]);
                    continue;
                }

                $result = $sender->send($employee, $eintrag);

                if ($result['status'] === ProofReminderSender::STATUS_SENT) {
                    // Reihenfolge bleibt senden-dann-stempeln (Absicht, nicht
                    // vergessen): zweimal erinnert ist aergerlich, nie
                    // erinnert heisst, der Mensch darf irgendwann nicht mehr
                    // arbeiten. whereNull('reminded_at') zusaetzlich (I3):
                    // macht das Update wiederholbar, falls doch einmal zwei
                    // Laeufe denselben Nachweis treffen.
                    // Query Builder, nicht Eloquent — siehe Klassen-Docblock.
                    DB::table('rec_employee_proofs')
                        ->where('id', $eintrag['proof_id'])
                        ->whereNull('reminded_at')
                        ->update(['reminded_at' => now()]);
                    $gesendet++;
                } else {
                    $uebersprungen++;
                    $this->warn(sprintf(
                        'MA #%d (%s): %s — %s',
                        $eintrag['rec_employee_id'],
                        ProofTypes::label($eintrag['code']),
                        $result['status'],
                        $result['error'] ?? 'kein Grund gemeldet'
                    ));
                }
            } catch (\Throwable $e) {
                $abgebrochen++;
                $this->error("MA #{$eintrag['rec_employee_id']}: Abbruch — {$e->getMessage()}");
                Log::error('[SendProofReminders] Abbruch bei einer Person, Lauf geht weiter', [
                    'rec_employee_id' => $eintrag['rec_employee_id'],
                    'proof_id' => $eintrag['proof_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            '%d Erinnerung(en) gesendet, %d übersprungen/fehlgeschlagen, %d abgebrochen.',
            $gesendet,
            $uebersprungen,
            $abgebrochen
        ));

        return self::SUCCESS;
    }

    /**
     * I5: reminded_at ist sonst endgueltig — es gibt keinen automatischen
     * Weg zurueck. Meta kann eine Nachricht annehmen (status sent) und die
     * Nichtzustellung erst per Webhook nachmelden (Fall 131026, in diesem
     * Haus belegt); dann steht der Marker und der Mensch bekaeme nie wieder
     * eine Erinnerung. Diese Option macht genau das reversibel, ohne dass
     * jemand an die Datenbank muss. Query Builder, tut sonst nichts.
     */
    private function zuruecksetzen(string $raw): int
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ))));

        if ($ids === []) {
            $this->error('Bitte --zuruecksetzen=<proof-id[,proof-id,...]> mit mindestens einer gültigen Nachweis-ID angeben.');

            return self::FAILURE;
        }

        $gefunden = DB::table('rec_employee_proofs')->whereIn('id', $ids)->pluck('id')->all();

        DB::table('rec_employee_proofs')->whereIn('id', $ids)->update(['reminded_at' => null]);

        $this->info(sprintf(
            'reminded_at geleert bei %d Nachweis(en): %s',
            count($gefunden),
            $gefunden === [] ? '(keiner gefunden)' : implode(', ', $gefunden)
        ));

        $fehlend = array_diff($ids, $gefunden);
        if ($fehlend !== []) {
            $this->warn('Nicht gefunden, übersprungen: ' . implode(', ', $fehlend));
        }

        return self::SUCCESS;
    }

    /** Y-m-d, echtes Kalenderdatum — 2026-13-40 oder 2026-9-24 zaehlen NICHT. */
    private function istGueltigesDatum(string $wert): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Aktuelle (nicht abgeloeste) Nachweise mit Ablaufdatum, gescoped auf
     * aktive Mitarbeiter und — IMMER, ohne Ausnahme (Fixrunde 2, C2) — auf das
     * neue Portal. Reine Lesequery, Query Builder.
     *
     * @return list<array{id:int, rec_employee_id:int, proof_type_code:string,
     *                     valid_until:?string, reminded_at:?string, superseded_at:?string}>
     */
    private function offeneNachweise(?int $teamId): array
    {
        $query = DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->whereNull('p.superseded_at')
            // is_active: wer nicht mehr aktiv ist, braucht keine Erinnerung an
            // einen ablaufenden Ausweis — kein Zufall, sondern eigene
            // Ergaenzung ueber den Brief hinaus (Aufgabenpruefung, Zweifel 3,
            // bestaetigt in Fixrunde 2).
            ->where('e.is_active', true)
            // portal_v2_since: IMMER, ohne Ausnahme-Option (C2) — siehe
            // Klassen-Docblock "ALTES PORTAL".
            ->whereNotNull('e.portal_v2_since');

        if ($teamId !== null) {
            $query->where('e.team_id', $teamId);
        }

        return $query
            ->get(['p.id', 'p.rec_employee_id', 'p.proof_type_code', 'p.valid_until', 'p.reminded_at', 'p.superseded_at'])
            ->map(fn ($row) => [
                'id'              => (int) $row->id,
                'rec_employee_id' => (int) $row->rec_employee_id,
                'proof_type_code' => (string) $row->proof_type_code,
                'valid_until'     => $row->valid_until,
                'reminded_at'     => $row->reminded_at,
                'superseded_at'   => $row->superseded_at,
            ])
            ->all();
    }
}
