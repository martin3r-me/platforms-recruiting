<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PersonProofScope;

/**
 * Welche Anstellungen gehoeren zu diesem Menschen?
 *
 * Eine Stelle fuer Lesen und Schreiben — wuerden Aufgabenliste und Upload
 * unterschiedlich aufloesen, saehe jemand einen Nachweis, den er nicht
 * hochladen kann, oder umgekehrt.
 */
final class PersonScopeResolver
{
    /** @return array{ids: list<int>, abweichend: list<int>} */
    public function forEmployee(RecEmployee $employee): array
    {
        $key = trim((string) $employee->person_key);
        if ($key === '') {
            return ['ids' => [(int) $employee->id], 'abweichend' => []];
        }

        $geschwister = DB::table('rec_employees')
            ->where('person_key', $key)
            ->where('id', '!=', $employee->id)
            ->get(['id', 'person_key', 'phone'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'person_key' => $r->person_key, 'phone' => $r->phone])
            ->all();

        return PersonProofScope::resolve(
            ['id' => (int) $employee->id, 'person_key' => $employee->person_key, 'phone' => $employee->phone],
            $geschwister,
        );
    }
}
