<?php

namespace Platform\Recruiting\Support;

/**
 * Ordnet einem Bewerber Mitarbeiter-Datensaetze zu, ohne sich auf
 * rec_employees.rec_applicant_id zu verlassen.
 *
 * WARUM NICHT EINFACH DER LINK: der ZAS-Inbound (ZasInboundEmployeeImporter)
 * matcht eingehende Zeilen ausschliesslich ueber die Personalnummer und legt
 * bei Nicht-Treffer einen MA mit `rec_applicant_id => null` an. Aus ZAS
 * zurueckgekommene Mitarbeiter haengen deshalb unverknuepft neben ihrem
 * Bewerber. Stand 08.09.2026: von 289 Bewerbern mit signiertem Arbeitsvertrag
 * hatten 164 einen Link, aber 84 der 125 Unverlinkten waren nachweislich MA.
 * Wer „ist das ein Mitarbeiter?" ueber den Link beantwortet, irrt sich bei
 * jedem zweiten Fall.
 *
 * Paesse, absteigend nach Beweiskraft:
 *  - PASS_LINK      rec_applicant_id zeigt auf den Bewerber          (stark)
 *  - PASS_NAME      normalisierter Voll-Name, auch Vor/Nach gedreht  (stark)
 *  - PASS_SURNAME   Nachname gleich + Vornamens-Anfang gleich        (schwach)
 *  - PASS_CONTAINS  MA-Nachname steckt im Bewerber-Namen            (schwach)
 *  - PASS_BIRTHDATE gleiches Geburtsdatum                            (schwach)
 *
 * PASS_CONTAINS existiert wegen der verstuemmelten Extra-Feld-Namen des
 * Altbestands: „Ochir Ocirzumaev" (Bewerber) vs. „Ochir Zumaev" (MA),
 * „Dario Dhalabarec" vs. „Dario Halabarec". Solche Faelle sind ueber den
 * Voll-Namen unfindbar.
 *
 * Die schwachen Paesse sind Hinweise, kein Beweis: drei verschiedene Menschen
 * teilen im Bestand das Geburtsdatum 2006-10-16. Deshalb taugt nur
 * PASS_NAME mit zusaetzlich passendem Geburtsdatum als Grundlage fuer einen
 * automatischen Link-Backfill (isLinkable()).
 *
 * FALLE, die dieser Klasse ihren Test verdankt: den Vor-/Nachnamen-Dreher
 * als `mitarbeiterName IN (bewerberName, gedreht(mitarbeiterName))` zu
 * pruefen vergleicht den MA mit SICH SELBST. Fuer jeden MA, dessen Vor- und
 * Nachname gleich sind („Ali Ali") oder dessen eine Namenshaelfte leer ist,
 * ist die Bedingung immer wahr — er matcht dann JEDEN Bewerber. Verglichen
 * wird hier ausschliesslich gegen die Namen des Bewerbers.
 *
 * Pure PHP, keine Laravel-Abhaengigkeit: Daten werden hereingereicht.
 */
final class EmployeeMatchResolver
{
    public const PASS_LINK = 'link';
    public const PASS_NAME = 'name';
    public const PASS_SURNAME = 'surname';
    public const PASS_CONTAINS = 'contains';
    public const PASS_BIRTHDATE = 'birthdate';

    public const STRONG = 'stark';
    public const WEAK = 'schwach';

    public const VERDICT_LINKED = 'verlinkt';
    public const VERDICT_UNLINKED = 'ma_ohne_link';
    public const VERDICT_CHECK = 'pruefen';
    public const VERDICT_NONE = 'kein_ma';

    /**
     * Kuerzester normalisierte Voll-Name, der noch verglichen wird. Schuetzt
     * davor, dass zwei Datensaetze mit leeren Namensfeldern („" === „")
     * aufeinander matchen.
     */
    private const MIN_NAME_LENGTH = 4;

    /** Zeichen, die der Vornamens-Anfang beim Nachnamen-Pass teilen muss. */
    private const FIRST_NAME_PREFIX = 3;

    /**
     * @param array{id:int,names:array<int,array{first?:?string,last?:?string}>,birth_date?:mixed} $candidate
     * @param array<int,array{id:int,personnel_number?:?string,first_name?:?string,last_name?:?string,birth_date?:mixed,rec_applicant_id?:?int}> $employees
     * @return array<int,array{employee_id:int,label:string,pass:string,strength:string,birth_match:bool}>
     */
    public static function match(array $candidate, array $employees): array
    {
        [$candidateNames, $candidatePairs] = self::candidateNames($candidate['names'] ?? []);
        $candidateBirth = self::normalizeDate($candidate['birth_date'] ?? null);
        $candidateId = (int) ($candidate['id'] ?? 0);

        $hits = [];

        foreach ($employees as $employee) {
            $first = self::normalize($employee['first_name'] ?? null);
            $last = self::normalize($employee['last_name'] ?? null);
            $full = $first . $last;
            $reversed = $last . $first;
            $birth = self::normalizeDate($employee['birth_date'] ?? null);
            $birthMatch = $candidateBirth !== null && $birth !== null && $birth === $candidateBirth;

            $linkedId = $employee['rec_applicant_id'] ?? null;
            if ($linkedId !== null && (int) $linkedId === $candidateId) {
                $hits[] = self::hit($employee, self::PASS_LINK, self::STRONG, $birthMatch);
                continue;
            }

            if (strlen($full) >= self::MIN_NAME_LENGTH
                && (isset($candidateNames[$full]) || isset($candidateNames[$reversed]))) {
                $hits[] = self::hit($employee, self::PASS_NAME, self::STRONG, $birthMatch);
                continue;
            }

            $pass = self::weakPass($first, $last, $candidatePairs, $candidateNames);
            if ($pass !== null) {
                $hits[] = self::hit($employee, $pass, self::WEAK, $birthMatch);
                continue;
            }

            if ($birthMatch) {
                $hits[] = self::hit($employee, self::PASS_BIRTHDATE, self::WEAK, true);
            }
        }

        return $hits;
    }

    /**
     * Gesamturteil ueber alle Treffer eines Bewerbers.
     *
     * @param array<int,array{pass:string,strength:string}> $hits
     */
    public static function verdict(array $hits): string
    {
        $verdict = self::VERDICT_NONE;

        foreach ($hits as $hit) {
            if ($hit['pass'] === self::PASS_LINK) {
                return self::VERDICT_LINKED;
            }
            if ($hit['strength'] === self::STRONG) {
                $verdict = self::VERDICT_UNLINKED;
                continue;
            }
            if ($verdict === self::VERDICT_NONE) {
                $verdict = self::VERDICT_CHECK;
            }
        }

        return $verdict;
    }

    /**
     * Treffer, die einen automatischen Link-Backfill tragen: Voll-Namen-Pass
     * UND passendes Geburtsdatum, und der MA hat noch keinen Link. Alles
     * andere (Nachname, Teil-Name, nur Geburtsdatum) bleibt Handarbeit — ein
     * falsch gesetzter Link ist schlimmer als ein fehlender.
     *
     * @param array<int,array{employee_id:int,pass:string,birth_match:bool}> $hits
     * @param array<int,array{id:int,rec_applicant_id?:?int}> $employeesById
     * @return array<int,int> Employee-IDs
     */
    public static function linkableEmployeeIds(array $hits, array $employeesById): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            if ($hit['pass'] !== self::PASS_NAME || !$hit['birth_match']) {
                continue;
            }
            $employee = $employeesById[$hit['employee_id']] ?? null;
            if ($employee === null || ($employee['rec_applicant_id'] ?? null) !== null) {
                continue;
            }
            $ids[] = (int) $hit['employee_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,array{first?:?string,last?:?string}> $names
     * @return array{0:array<string,true>,1:array<int,array{0:string,1:string}>}
     */
    private static function candidateNames(array $names): array
    {
        $full = [];
        $pairs = [];

        foreach ($names as $name) {
            $first = self::normalize($name['first'] ?? null);
            $last = self::normalize($name['last'] ?? null);
            $joined = $first . $last;

            if (strlen($joined) >= self::MIN_NAME_LENGTH) {
                $full[$joined] = true;
            }
            if ($first !== '' && $last !== '') {
                $full[$last . $first] = true;
                $pairs[] = [$first, $last];
            }
        }

        return [$full, $pairs];
    }

    /**
     * @param array<int,array{0:string,1:string}> $candidatePairs
     * @param array<string,true> $candidateNames
     */
    private static function weakPass(string $first, string $last, array $candidatePairs, array $candidateNames): ?string
    {
        if ($last === '' || strlen($last) < self::MIN_NAME_LENGTH) {
            return null;
        }

        foreach ($candidatePairs as [$candidateFirst, $candidateLast]) {
            if ($last === $candidateLast
                && $first !== ''
                && strlen($first) >= self::FIRST_NAME_PREFIX
                && substr($first, 0, self::FIRST_NAME_PREFIX) === substr($candidateFirst, 0, self::FIRST_NAME_PREFIX)) {
                return self::PASS_SURNAME;
            }
        }

        // Verstuemmelter Bewerber-Name: MA-Nachname steckt im Bewerber-Namen
        // („ochirocirzumaev" enthaelt „zumaev"), Vornamens-Anfang muss passen.
        if ($first === '' || strlen($first) < self::FIRST_NAME_PREFIX) {
            return null;
        }
        $prefix = substr($first, 0, self::FIRST_NAME_PREFIX);
        foreach (array_keys($candidateNames) as $candidateFull) {
            if (str_starts_with($candidateFull, $prefix) && str_contains($candidateFull, $last)) {
                return self::PASS_CONTAINS;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $employee
     * @return array{employee_id:int,label:string,pass:string,strength:string,birth_match:bool}
     */
    private static function hit(array $employee, string $pass, string $strength, bool $birthMatch): array
    {
        $number = $employee['personnel_number'] ?? null;
        $name = trim(((string) ($employee['first_name'] ?? '')) . ' ' . ((string) ($employee['last_name'] ?? '')));

        return [
            'employee_id' => (int) $employee['id'],
            'label' => (($number !== null && $number !== '') ? (string) $number : '#' . (int) $employee['id'])
                . ($name !== '' ? ' = ' . $name : ' = (ohne Namen)'),
            'pass' => $pass,
            'strength' => $strength,
            'birth_match' => $birthMatch,
        ];
    }

    /**
     * Umlaute/Diakritika falten, alles ausser a-z0-9 verwerfen. „Böyükbas"
     * und „Boyukbas" muessen denselben Schluessel ergeben, „zur Nieden" und
     * „zurNieden" ebenfalls.
     */
    private static function normalize(?string $value): string
    {
        $value = mb_strtolower((string) $value, 'UTF-8');

        $value = strtr($value, [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n',
            'ş' => 's', 'š' => 's', 'ś' => 's', 'ğ' => 'g', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'đ' => 'd', 'ł' => 'l', 'ý' => 'y', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /** Vereinheitlicht Datumswerte (DateTime, „Y-m-d", „d.m.Y") auf Y-m-d. */
    private static function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'Y-m-d H:i:s', 'd/m/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($parsed instanceof \DateTimeImmutable) {
                $year = (int) $parsed->format('Y');
                // Kaputte Datumswerte (Bestand hat applied_at „0002-06-15")
                // duerfen keinen Treffer tragen.
                return ($year < 1900 || $year > 2100) ? null : $parsed->format('Y-m-d');
            }
        }

        return null;
    }
}
