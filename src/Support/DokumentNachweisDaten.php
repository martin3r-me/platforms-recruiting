<?php

namespace Platform\Recruiting\Support;

/**
 * Die Werte des Nachweisblatts (Spec §5.4). Rein: Eingaben sind Arrays, die
 * Datumsangaben werden hier formatiert, der Abstand zwischen Oeffnen und
 * Unterschrift ist das Indiz gegen "nach null Sekunden bestaetigt".
 */
final class DokumentNachweisDaten
{
    public static function fuer(array $dokument, array $zustellung, array $mitarbeiter): array
    {
        $name = trim((string) ($mitarbeiter['first_name'] ?? '') . ' ' . (string) ($mitarbeiter['last_name'] ?? ''));
        $geoeffnet = $zustellung['first_viewed_at'] ?? null;
        $unterschrieben = $zustellung['signed_at'] ?? null;

        return [
            'name'             => $name !== '' ? $name : '—',
            'personalnummer'   => self::text($mitarbeiter['personnel_number'] ?? null),
            'firma'            => self::text($mitarbeiter['company'] ?? null),
            'titel'            => (string) ($dokument['title'] ?? ''),
            'dateiname'        => (string) ($dokument['original_filename'] ?? ''),
            'pruefsumme'       => (string) ($dokument['file_sha256'] ?? ''),
            'bereitgestellt'   => self::datum($zustellung['created_at'] ?? ($dokument['created_at'] ?? null)),
            'geoeffnet'        => self::datum($geoeffnet),
            'bestaetigt'       => self::datum($zustellung['acknowledged_at'] ?? null),
            'unterschrieben'   => self::datum($unterschrieben),
            'abstand_sekunden' => ($geoeffnet && $unterschrieben) ? max(0, strtotime((string) $unterschrieben) - strtotime((string) $geoeffnet)) : null,
            'unterschrift'     => ($zustellung['signature_data'] ?? null) ?: null,
        ];
    }

    private static function datum(?string $wert): string
    {
        $wert = trim((string) $wert);
        if ($wert === '') {
            return '—';
        }
        $ts = strtotime($wert);

        return $ts === false ? $wert : date('d.m.Y H:i:s', $ts);
    }

    private static function text(?string $wert): string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? '—' : $wert;
    }
}
