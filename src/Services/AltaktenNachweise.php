<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Nachweise, die in der Papierakte der Bestands-MA liegen, aber nicht als
 * Datei im HCM (Pilot-Feedback Markus 29.10.2026: "sonst nerven wir die
 * Mitarbeiter und sie muessen es doppelt einsenden").
 *
 * Belegt wird nur, was ZAS geliefert hat (isZasOwned) — eine Angabe aus der
 * Bewerbung ist eine Selbstauskunft, kein Aktenstueck:
 *  - ausweis           <- AusweisBis (identity_card_valid_until), MIT Datum:
 *                         laeuft er ab, fragt das Portal wie gewohnt nach
 *  - erstbescheinigung <- InfekGueltigBis gesetzt (Belehrung setzt die
 *                         Erstbescheinigung voraus), laeuft nicht ab
 *  - krankenkasse      <- Krankenkasse eingetragen (die Karte selbst liefert
 *                         ZAS nicht; fuer die Abrechnung reicht die Kasse)
 *
 * Geschrieben wird eine Nachweis-Zeile OHNE Datei, uploaded_via=altakte, und
 * nur, wo fuer die Person weder ein aktueller Nachweis noch eine Altspalten-
 * Datei liegt. Bewusst NICHT ueber ProofWriter: der spiegelt die Datei in die
 * Altspalten und wuerde dort mit null ueberschreiben.
 */
final class AltaktenNachweise
{
    public const QUELLE = 'altakte';

    private const CODES = ['ausweis', 'erstbescheinigung', 'krankenkasse'];

    /**
     * Reine Entscheidung (ohne DB).
     *
     * @param  list<array{zas:bool, identity_card_valid_until:?string, infection_protection_valid_until:?string, health_insurance:?string}> $mitglieder
     *         alle Anstellungen der Person
     * @param  list<string> $belegt  Codes mit aktuellem Nachweis oder Altspalten-Datei
     * @return array<string, ?string> code => valid_until (Y-m-d) bzw. null
     */
    public static function vorschlag(array $mitglieder, array $belegt): array
    {
        $ausweisBis = null;
        $infek = false;
        $kasse = false;
        foreach ($mitglieder as $m) {
            if (!($m['zas'] ?? false)) {
                continue;
            }
            $bis = self::datum($m['identity_card_valid_until'] ?? null);
            if ($bis !== null && ($ausweisBis === null || $bis > $ausweisBis)) {
                $ausweisBis = $bis;
            }
            $infek = $infek || self::datum($m['infection_protection_valid_until'] ?? null) !== null;
            $kasse = $kasse || trim((string) ($m['health_insurance'] ?? '')) !== '';
        }

        $out = [];
        if ($ausweisBis !== null) {
            $out['ausweis'] = $ausweisBis;
        }
        if ($infek) {
            $out['erstbescheinigung'] = null;
        }
        if ($kasse) {
            $out['krankenkasse'] = null;
        }

        return array_diff_key($out, array_flip($belegt));
    }

    /**
     * Ganzes Team abgleichen (idempotent).
     *
     * @return array{personen: int, angelegt: array<string, int>}
     */
    public function abgleichen(int $teamId, bool $schreiben): array
    {
        $anstellungen = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $scope = new PersonScopeResolver();
        $erledigt = [];
        $out = ['personen' => 0, 'angelegt' => []];

        foreach ($anstellungen as $employee) {
            if (isset($erledigt[(int) $employee->id])) {
                continue;
            }
            $ids = $scope->forEmployee($employee)['ids'];
            foreach ($ids as $id) {
                $erledigt[(int) $id] = true;
            }

            $neu = $this->fuerPerson($employee, $ids, $schreiben);
            if ($neu !== []) {
                $out['personen']++;
                foreach (array_keys($neu) as $code) {
                    $out['angelegt'][$code] = ($out['angelegt'][$code] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<int> $ids Personen-Umfang (PersonScopeResolver)
     * @return array<string, ?string>
     */
    public function fuerPerson(RecEmployee $employee, array $ids, bool $schreiben): array
    {
        $codes = self::CODES;

        $mitglieder = RecEmployee::query()->whereIn('id', $ids)->get();
        $aktuell = RecEmployeeProof::query()->aktuell()->whereIn('rec_employee_id', $ids)
            ->whereIn('proof_type_code', $codes)->get()->keyBy('proof_type_code');

        // Belegt = echter Nachweis oder Altspalten-Datei. Ein eigener
        // altakte-Vermerk ist NICHT belegt: liefert ZAS spaeter ein neueres
        // AusweisBis, wird er erneuert (Review 30.10. — sonst hiesse es im
        // Portal "abgelaufen", obwohl ZAS den neuen Ausweis kennt).
        $belegt = $aktuell->reject(fn ($p) => $p->uploaded_via === self::QUELLE)->keys()->all();
        foreach ($codes as $code) {
            foreach (ProofTypes::legacyFileColumns($code) as $spalte) {
                if ($mitglieder->contains(fn ($m) => $m->getAttribute($spalte) !== null)) {
                    $belegt[] = $code;
                }
            }
        }

        $vorschlag = self::vorschlag($mitglieder->map(fn (RecEmployee $m) => [
            'zas' => $m->isZasOwned(),
            'identity_card_valid_until' => $m->identity_card_valid_until?->format('Y-m-d'),
            'infection_protection_valid_until' => $m->infection_protection_valid_until?->format('Y-m-d'),
            'health_insurance' => $m->health_insurance,
        ])->all(), array_values(array_unique($belegt)));

        // Vorhandene Vermerke: nur ein spaeteres Ausweis-Datum ersetzt sie.
        $vorschlag = array_filter($vorschlag, function (?string $bis, string $code) use ($aktuell) {
            $alt = $aktuell->get($code);
            if ($alt === null) {
                return true;
            }

            return $code === 'ausweis' && $bis !== null
                && ($alt->valid_until === null || $bis > $alt->valid_until->format('Y-m-d'));
        }, ARRAY_FILTER_USE_BOTH);

        if ($schreiben && $vorschlag !== []) {
            // Getragen von einer AKTIVEN Anstellung im Team, bevorzugt im neuen
            // Portal — Erinnerungen und HR-Listen fragen nur solche ab
            // (Review 30.10.: die kleinste id war oft eine beendete).
            $traeger = $mitglieder
                ->filter(fn ($m) => $m->is_active && (int) $m->team_id === (int) $employee->team_id)
                ->sortBy(fn ($m) => [$m->portal_v2_since === null ? 1 : 0, (int) $m->id])
                ->first() ?? $employee;
            DB::transaction(function () use ($vorschlag, $traeger, $ids, $aktuell) {
                foreach ($vorschlag as $code => $bis) {
                    $alt = $aktuell->get($code);
                    if ($alt !== null) {
                        RecEmployeeProof::query()->whereKey($alt->id)->update(['superseded_at' => now()]);
                    }
                    // Abgeloeste Fassungen zaehlen mit — die Version laeuft je Person und Art weiter.
                    $version = (int) RecEmployeeProof::query()->whereIn('rec_employee_id', $ids)
                        ->where('proof_type_code', $code)->max('version');
                    RecEmployeeProof::create([
                        'team_id'         => $traeger->team_id,
                        'rec_employee_id' => $traeger->id,
                        'person_key'      => $traeger->person_key,
                        'proof_type_code' => $code,
                        'valid_until'     => $bis,
                        'version'         => $version + 1,
                        'uploaded_via'    => self::QUELLE,
                    ]);
                }
            });
        }

        return $vorschlag;
    }

    private static function datum(?string $wert): ?string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? null : substr($wert, 0, 10);
    }
}
