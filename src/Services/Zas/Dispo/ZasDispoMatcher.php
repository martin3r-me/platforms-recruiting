<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Zweistufiges PNr-Matching (Spec): (1) exakter String-Vergleich,
 * (2) ziffernbereinigt (RG14 -> 14). Mehrdeutige Ziffern-Treffer werden
 * BEWUSST nicht zugeordnet. Pure — bekommt die Map aus
 * DispoEmployeeDirectory::map() injiziert.
 */
class ZasDispoMatcher
{
    /** @var array<string, int> */
    private array $byPnr;

    /** @var array<string, int|null> digits => employee_id, null = mehrdeutig */
    private array $byDigits = [];

    /** @param array<string, int> $byPnr personnel_number => employee_id */
    public function __construct(array $byPnr)
    {
        $this->byPnr = $byPnr;

        foreach ($byPnr as $pnr => $id) {
            $digits = preg_replace('/\D+/', '', (string) $pnr) ?? '';
            if ($digits === '') {
                continue;
            }
            $this->byDigits[$digits] = array_key_exists($digits, $this->byDigits) ? null : $id;
        }
    }

    /** @return array{employee_id: ?int, reason: string} */
    public function match(?string $pnrRaw): array
    {
        $pnr = trim((string) $pnrRaw);
        if ($pnr === '') {
            return ['employee_id' => null, 'reason' => 'empty'];
        }

        if (array_key_exists($pnr, $this->byPnr)) {
            return ['employee_id' => $this->byPnr[$pnr], 'reason' => 'exact'];
        }

        $digits = preg_replace('/\D+/', '', $pnr) ?? '';
        if ($digits !== '' && array_key_exists($digits, $this->byDigits)) {
            return $this->byDigits[$digits] === null
                ? ['employee_id' => null, 'reason' => 'ambiguous']
                : ['employee_id' => $this->byDigits[$digits], 'reason' => 'digits'];
        }

        return ['employee_id' => null, 'reason' => 'none'];
    }
}
