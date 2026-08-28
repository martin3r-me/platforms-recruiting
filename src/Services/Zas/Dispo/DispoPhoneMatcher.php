<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Telefonnummern-Matching Thread -> Mitarbeiter (pure).
 *
 * Normalisierung: nur Ziffern; fuehrendes 00 entfernt; danach fuehrende 0
 * durch 49 ersetzt (deutsche Inlandsschreibweise). Kollisionen (zwei
 * VERSCHIEDENE ids mit derselben normalisierten Nummer) matchen BEWUSST
 * nicht — nie raten (Ambiguitaets-Regel wie beim PNr-Matching). Dieselbe id
 * unter mehreren Nummern (oder zweimal unter derselben) ist KEINE
 * Mehrdeutigkeit — das ist die Dispo-Identitaetsgruppe einer Person mit
 * mehreren Mitarbeiter-Datensaetzen.
 */
class DispoPhoneMatcher
{
    /** @var array<string, int|null> normalisierte Nummer => employee_id, null = mehrdeutig */
    private array $byPhone = [];

    /** @param array<int, string|list<string>|null> $phonesById employee_id => Roh-Telefonnummer(n) */
    public function __construct(array $phonesById)
    {
        foreach ($phonesById as $employeeId => $phones) {
            foreach ((array) $phones as $phone) {
                $normalized = self::normalize($phone);
                if ($normalized === null) {
                    continue;
                }
                if (array_key_exists($normalized, $this->byPhone)) {
                    if ($this->byPhone[$normalized] !== (int) $employeeId) {
                        $this->byPhone[$normalized] = null;
                    }
                } else {
                    $this->byPhone[$normalized] = (int) $employeeId;
                }
            }
        }
    }

    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '49' . substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }

    public function match(?string $remotePhone): ?int
    {
        $normalized = self::normalize($remotePhone);
        if ($normalized === null || !array_key_exists($normalized, $this->byPhone)) {
            return null;
        }

        return $this->byPhone[$normalized];
    }
}
