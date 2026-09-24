<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\ProofReminderSender;
use Platform\Recruiting\Support\ProofReminderPlanner;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Taeglicher Fristenlauf: findet faellige Nachweise (ProofReminderPlanner)
 * und verschickt je Mitarbeiter GENAU EINE WhatsApp (ProofReminderSender).
 *
 * DUENNE HUELLE, Muster PersonPairAudit: die Regeln liegen in reinen,
 * getesteten Klassen (Planner, Sender), dieses Kommando liest, ruft auf,
 * schreibt.
 *
 * ZWEI GUARDS, DIE DEN BESTAND NICHT WIEDERHOLEN:
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
 *
 * GENAU EINE WHATSAPP JE PERSON UND LAUF: plan() sortiert global nach
 * Dringlichkeit (faelligstes zuerst). Wer zwei faellige Nachweise hat (z.B.
 * Ausweis UND Aufenthaltstitel), bekommt in diesem Lauf nur die dringendere
 * Erinnerung — der zweite Nachweis bleibt unerinnert und erscheint im
 * naechsten Lauf erneut (kein Grund, ihn zu ignorieren: er ist ja noch nicht
 * `reminded_at`). --limit zaehlt Personen, nicht Nachweis-Zeilen.
 *
 * ALTES PORTAL: standardmaessig uebersprungen (--auch-altes-portal hebt das
 * auf). Wer `portal_v2_since` nicht gesetzt hat, kann im alten Portal keinen
 * Nachweis hochladen — die Erinnerung waere eine Sackgasse. Solange die
 * Zielgruppe nicht umgestellt ist, laeuft dieses Kommando im Alltag praktisch
 * leer, und das ist Absicht (siehe Task-Notiz: "geht erst scharf, wenn die
 * Zielgruppe umgestellt ist").
 *
 * STICHTAG: ohne --stichtag ist die Bremse gegen die Altbestands-Welle AUS —
 * ProofReminderPlanner::plan() erinnert dann an JEDEN faelligen Nachweis,
 * auch die rund 540 bereits abgelaufenen. Der Stichtag ist eine
 * Kundenentscheidung (Markus, offen) und deshalb ein Kommandozeilen-Parameter,
 * kein Default im Code.
 */
class SendProofReminders extends Command
{
    protected $signature = 'recruiting:nachweise-erinnern
        {--team= : Nur Mitarbeiter dieses Teams}
        {--stichtag= : Fristen VOR diesem Datum (Y-m-d) bleiben stumm — Bremse gegen die Altbestands-Welle. Ohne Angabe: keine Bremse.}
        {--dry-run : Nichts senden, nichts schreiben — nur anzeigen, was fällig wäre}
        {--limit= : Höchstens so viele Personen erinnern (dringendste zuerst)}
        {--auch-altes-portal : Auch Mitarbeiter ohne neues Portal (portal_v2_since) erinnern. Standard: überspringen, sie können die Aufgabe dort nicht erledigen.}';

    protected $description = 'Täglicher Fristenlauf: fällige Nachweise finden und je Person genau eine WhatsApp-Erinnerung senden';

    public function handle(): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $stichtag = $this->option('stichtag') !== null ? (string) $this->option('stichtag') : null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $auchAltesPortal = (bool) $this->option('auch-altes-portal');

        $nachweise = $this->offeneNachweise($teamId, $auchAltesPortal);
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

        $sender = app(ProofReminderSender::class);
        $gesendet = 0;
        $uebersprungen = 0;

        foreach ($jeEmployee as $eintrag) {
            $employee = RecEmployee::find($eintrag['rec_employee_id']);
            if ($employee === null) {
                $uebersprungen++;
                $this->warn("MA #{$eintrag['rec_employee_id']}: nicht gefunden, übersprungen.");
                continue;
            }

            $result = $sender->send($employee, $eintrag);

            if ($result['status'] === ProofReminderSender::STATUS_SENT) {
                // Query Builder, nicht Eloquent — siehe Klassen-Docblock.
                DB::table('rec_employee_proofs')
                    ->where('id', $eintrag['proof_id'])
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
        }

        $this->info(sprintf('%d Erinnerung(en) gesendet, %d übersprungen/fehlgeschlagen.', $gesendet, $uebersprungen));

        return self::SUCCESS;
    }

    /**
     * Aktuelle (nicht abgeloeste) Nachweise mit Ablaufdatum, gescoped auf
     * aktive Mitarbeiter und — sofern nicht --auch-altes-portal gesetzt ist —
     * auf das neue Portal. Reine Lesequery, Query Builder.
     *
     * @return list<array{id:int, rec_employee_id:int, proof_type_code:string,
     *                     valid_until:?string, reminded_at:?string, superseded_at:?string}>
     */
    private function offeneNachweise(?int $teamId, bool $auchAltesPortal): array
    {
        $query = DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->whereNull('p.superseded_at')
            ->where('e.is_active', true);

        if ($teamId !== null) {
            $query->where('e.team_id', $teamId);
        }

        if (!$auchAltesPortal) {
            $query->whereNotNull('e.portal_v2_since');
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
