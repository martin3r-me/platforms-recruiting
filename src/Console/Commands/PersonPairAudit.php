<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\PersonPairLinker;
use Platform\Recruiting\Support\PersonPairAuditPlanner;

/**
 * Bestands-Audit der Personen-Paare (Chaieb-Befund 10.09.2026): findet
 * Mitarbeiter, die dieselbe Person in zwei Firmen sind (RG + MA, zwei
 * Personalnummern), aber noch keinen gemeinsamen person_key tragen.
 *
 * Zwei Etagen (Regeln in PersonPairAuditPlanner, pure + getestet):
 *  - SICHER: doppelt-exakt (voller Name + Geburtsdatum), genau zwei
 *    Datensaetze, hoechstens eine Bewerbung → mit --apply automatisch
 *    gestempelt (der unverlinkte erbt die Bewerbung).
 *  - PRUEFEN: alles andere — Tabelle mit Belegen und fertigem
 *    --pair-Befehl je Fall; ein Mensch entscheidet.
 *
 * Sortiert nach Dringlichkeit: Dispo-Einsaetze auf UNVERLINKTEN Datensaetzen
 * zuerst (die verfaelschen den Schulung→Einsatz-Abgleich der Statistik).
 *
 * Aufruf:
 *   php artisan recruiting:person-pair-audit                  (nur Bericht)
 *   php artisan recruiting:person-pair-audit --apply          (SICHER stempeln)
 *   php artisan recruiting:person-pair-audit --pair=819:847   (Hand-Bestaetigung)
 */
class PersonPairAudit extends Command
{
    protected $signature = 'recruiting:person-pair-audit
        {--team= : Nur Mitarbeiter dieses Teams}
        {--apply : SICHERE Paare stempeln (sonst nur Bericht)}
        {--pair= : Von Hand bestaetigte Paare stempeln — employeeId:employeeId, komma-getrennt}';

    protected $description = 'Personen-Paare (RG+MA derselben Person) finden und person_key stempeln';

    public function handle(): int
    {
        if ($this->option('pair')) {
            return $this->stampPairs((string) $this->option('pair'));
        }

        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;

        // Einsaetze je Mitarbeiter (dieselbe Zaehlregel wie der Statistik-
        // Abgleich: kein Storno, nichts aus ZAS Entferntes/Geloeschtes).
        $einsaetze = DB::table('rec_dispo_assignments')
            ->where('status_id', '!=', 3)
            ->whereNull('zas_removed_at')
            ->whereNull('deletion_confirmed_at')
            ->whereNotNull('rec_employee_id')
            ->groupBy('rec_employee_id')
            ->selectRaw('rec_employee_id, COUNT(*) as anzahl')
            ->pluck('anzahl', 'rec_employee_id');

        $employees = DB::table('rec_employees')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->get(['id', 'first_name', 'last_name', 'birth_date', 'personnel_number', 'rec_applicant_id', 'person_key'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'first_name' => $e->first_name,
                'last_name' => $e->last_name,
                'birth_date' => $e->birth_date !== null ? substr((string) $e->birth_date, 0, 10) : null,
                'personnel_number' => $e->personnel_number,
                'rec_applicant_id' => $e->rec_applicant_id !== null ? (int) $e->rec_applicant_id : null,
                'person_key' => $e->person_key,
                'einsaetze' => (int) ($einsaetze[$e->id] ?? 0),
            ])
            ->all();

        $plan = PersonPairAuditPlanner::plan($employees);

        $apply = (bool) $this->option('apply');
        $this->info(sprintf(
            '%d sichere Paare, %d Faelle fuer Menschen.%s',
            count($plan['sicher']),
            count($plan['pruefen']),
            $apply ? '' : ' (Bericht — stempeln mit --apply)',
        ));

        if ($plan['sicher'] !== []) {
            $rows = [];
            foreach ($plan['sicher'] as $paar) {
                $ergebnis = 'sicher';
                if ($apply) {
                    PersonPairLinker::stamp($paar['ids'], $paar['applicant_id']);
                    $ergebnis = 'gestempelt' . ($paar['applicant_id'] !== null ? ' + Bewerbung vererbt' : '');
                }
                $rows[] = [
                    implode(' + ', $paar['ids']),
                    $paar['name'],
                    $paar['birth_date'],
                    implode(' / ', $paar['nummern']),
                    $paar['applicant_id'] !== null ? '#' . $paar['applicant_id'] : '—',
                    $paar['einsaetze_unverlinkt'],
                    $ergebnis,
                ];
            }
            $this->table(['MA-IDs', 'Name', 'Geburtsdatum', 'Nummern', 'Bewerbung', 'Einsaetze unverlinkt', 'Ergebnis'], $rows);
        }

        if ($plan['pruefen'] !== []) {
            $this->warn('Fuer Menschen (Namensvariante fehlt hier bewusst — nur exakte Gruppen mit Mehrdeutigkeit):');
            $rows = [];
            foreach ($plan['pruefen'] as $fall) {
                $rows[] = [
                    implode(' + ', $fall['ids']),
                    $fall['name'],
                    $fall['birth_date'],
                    implode(' / ', $fall['nummern']),
                    $fall['einsaetze_unverlinkt'],
                    '--pair=' . implode(':', array_slice($fall['ids'], 0, 2)),
                ];
            }
            $this->table(['MA-IDs', 'Name', 'Geburtsdatum', 'Nummern', 'Einsaetze unverlinkt', 'Hand-Befehl'], $rows);
        }

        return self::SUCCESS;
    }

    private function stampPairs(string $raw): int
    {
        $fehler = 0;
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $paar) {
            if (!preg_match('/^(\d+)\s*:\s*(\d+)$/', $paar, $m)) {
                $this->error("Ungueltiges Paar '{$paar}' — erwartet employeeId:employeeId.");
                $fehler++;
                continue;
            }
            $ids = [(int) $m[1], (int) $m[2]];
            $gefunden = DB::table('rec_employees')->whereIn('id', $ids)
                ->get(['id', 'rec_applicant_id']);
            if ($gefunden->count() !== 2) {
                $this->error("Paar {$paar}: nicht beide Mitarbeiter gefunden.");
                $fehler++;
                continue;
            }
            $applicants = $gefunden->pluck('rec_applicant_id')->filter()->unique();
            if ($applicants->count() > 1) {
                $this->error("Paar {$paar}: haengt an ZWEI verschiedenen Bewerbungen (#" . $applicants->implode(', #') . ') — erst klaeren, nicht stempeln.');
                $fehler++;
                continue;
            }
            $key = PersonPairLinker::stamp($ids, $applicants->first() !== null ? (int) $applicants->first() : null);
            $this->info("Paar {$paar} gestempelt (person_key {$key}).");
        }

        return $fehler === 0 ? self::SUCCESS : self::FAILURE;
    }
}
