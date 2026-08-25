<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * Dublettenverdacht vor einer Neuanlage aus dem ZAS-Inbound.
 *
 * Anlass (Massenimport 2026-08-25): vier eigene MA wurden ein zweites Mal
 * angelegt. Die Match-Kaskade des Importers ist UUID → Personalnummer → neu,
 * und bei diesen vier war beides nicht verfuegbar (ZAS lieferte unsere UUID
 * nicht mit, unser PNr-Feld war leer). Der Importer leitet aus dem FEHLEN
 * eines Schluessels ab, dass die Person neu ist — die Annahme war falsch.
 *
 * Diese Klasse ersetzt "kein Schluessel heisst neu" durch "kein Schluessel
 * heisst nachsehen". Sie fuehrt NICHT automatisch zusammen: zwei Personen
 * koennen sich Telefon oder Konto legitim teilen (in derselben Lieferung lagen
 * drei Paare mit gemeinsamer IBAN, sehr wahrscheinlich Familie). Eine falsche
 * Verschmelzung waere deutlich schaedlicher als eine Dublette — deshalb wird
 * nur GEMELDET, und die Zeile laeuft normal weiter.
 */
final class ZasInboundDuplicateFinder
{
    /** Mehr als eine Handvoll Treffer pro Feld hilft im Bericht nicht. */
    private const MAX_HITS_PER_FIELD = 3;

    /**
     * Nationale Rufnummer als Vergleichsschluessel.
     *
     * Bewusst NICHT die E164-Form und bewusst kein naives Ziffernfiltern: der
     * gespeicherte Wert "0176 1234567" und der gelieferte "+49 176 1234567"
     * sind dieselbe Person, unterscheiden sich aber in fuehrender 0 gegen 49.
     * Die nationale Rufnummer ist bei beiden gleich und laesst sich als Suffix
     * gegen beliebige Schreibweisen vergleichen.
     *
     * Unparsebar oder ungueltig => null, also kein Vergleich. Kein Raten.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($raw, 'DE');
        } catch (NumberParseException) {
            return null;
        }
        if (!PhoneNumberUtil::getInstance()->isValidNumber($parsed)) {
            return null;
        }

        return (string) $parsed->getNationalNumber();
    }

    public static function normalizeEmail(?string $raw): ?string
    {
        $email = mb_strtolower(trim((string) $raw));

        return $email === '' ? null : $email;
    }

    public static function normalizeIban(?string $raw): ?string
    {
        $iban = mb_strtoupper(str_replace(' ', '', trim((string) $raw)));

        return $iban === '' ? null : $iban;
    }

    /**
     * Sucht bestehende MA, die zur anzulegenden Zeile passen koennten.
     *
     * @param  array<string,mixed> $employeeFields Mapper-Ausgabe fuer rec_employees
     * @return list<array{field:string, value:string, employee_id:int, confidence:string}>
     */
    public function suspicions(array $employeeFields, ?int $teamId): array
    {
        $hits = [];

        $email = self::normalizeEmail($employeeFields['email'] ?? null);
        if ($email !== null) {
            foreach ($this->query($teamId)->whereRaw('LOWER(email) = ?', [$email])
                ->limit(self::MAX_HITS_PER_FIELD)->pluck('id') as $id) {
                $hits[] = ['field' => 'email', 'value' => $email, 'employee_id' => (int) $id, 'confidence' => 'stark'];
            }
        }

        $phone = self::normalizePhone($employeeFields['phone'] ?? null);
        if ($phone !== null) {
            $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '/', ''), '(', ''), ')', '')";
            foreach ($this->query($teamId)->whereRaw("{$stripped} LIKE ?", ['%' . $phone])
                ->limit(self::MAX_HITS_PER_FIELD)->pluck('id') as $id) {
                $hits[] = ['field' => 'phone', 'value' => $phone, 'employee_id' => (int) $id, 'confidence' => 'stark'];
            }
        }

        $iban = self::normalizeIban($employeeFields['iban'] ?? null);
        if ($iban !== null) {
            // Schwaches Signal: Angehoerige teilen sich Konten. Trotzdem
            // melden, aber im Bericht als solches gekennzeichnet.
            foreach ($this->query($teamId)->whereRaw("UPPER(REPLACE(iban, ' ', '')) = ?", [$iban])
                ->limit(self::MAX_HITS_PER_FIELD)->pluck('id') as $id) {
                $hits[] = ['field' => 'iban', 'value' => $iban, 'employee_id' => (int) $id, 'confidence' => 'schwach'];
            }
        }

        return $hits;
    }

    private function query(?int $teamId): \Illuminate\Database\Query\Builder
    {
        return DB::table('rec_employees')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId));
    }
}
