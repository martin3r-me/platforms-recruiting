<?php

namespace Platform\Recruiting\Support;

/**
 * Die fuenf Sperrgruende der Liste "Gesperrt" und des Konto-Kastens (Spec
 * HR-Bereich §3.2) als Chips mit Aktion. Rein: die Eingaben sammelt
 * HrMitarbeiterListen. code_versuche, IP-Bremse, Token-404 und
 * Arbeitserlaubnis sind bewusst KEINE Gruende.
 */
final class SperrGruende
{
    public const DISPO      = 'dispo';
    public const KONTO      = 'konto';
    public const BREMSE     = 'bremse';
    public const NOTFALL    = 'notfall';
    public const MEHRDEUTIG = 'mehrdeutig';

    /** Aktion in HrKontoAktionen / den Livewire-Komponenten; MEHRDEUTIG hat keine (Plattform-Support). */
    public const AKTION = [
        self::DISPO      => 'portal_entsperren',
        self::KONTO      => 'konto_entsperren',
        self::BREMSE     => 'bremse_loesen',
        self::NOTFALL    => 'notfallsperre_aufheben',
        self::MEHRDEUTIG => null,
    ];

    public const KNOPF = [
        self::DISPO      => 'Portal entsperren',
        self::KONTO      => 'Konto entsperren',
        self::BREMSE     => 'Bremse lösen',
        self::NOTFALL    => 'Notfallsperre aufheben',
        self::MEHRDEUTIG => null,
    ];

    /** Die Drossel endet nach 15 Minuten von selbst und zaehlt nicht fuers Abzeichen (Spec §3). */
    public static function zaehltFuersAbzeichen(string $code): bool
    {
        return $code !== self::BREMSE;
    }

    /**
     * @param  array{dispo?: ?array{seit:string, grund:?string, employee_id:int}, konto_gesperrt?: bool, bremse?: ?array{frei_ab:?string}, notfall_bis?: ?string, mehrdeutig?: bool}  $e
     * @return list<array{code:string, text:string, aktion:?string, knopf:?string, employee_id:?int}>
     */
    public static function fuer(array $e, string $jetzt): array
    {
        $gruende = [];

        if (($e['dispo'] ?? null) !== null) {
            $grund = trim((string) ($e['dispo']['grund'] ?? ''));
            $gruende[] = self::chip(
                self::DISPO,
                'Portal gesperrt seit ' . date('d.m.Y, H:i', (int) strtotime((string) $e['dispo']['seit'])) . ($grund !== '' ? ' — ' . $grund : ''),
                (int) $e['dispo']['employee_id'],
            );
        }
        if (($e['konto_gesperrt'] ?? false) === true) {
            $gruende[] = self::chip(self::KONTO, 'Konto gesperrt');
        }
        if (($e['bremse'] ?? null) !== null) {
            $frei = $e['bremse']['frei_ab'] ?? null;
            $gruende[] = self::chip(self::BREMSE, $frei !== null
                ? '5 Fehlversuche, frei ab ' . date('H:i', (int) strtotime((string) $frei))
                : '5 Fehlversuche, frei spätestens nach 15 Minuten');
        }
        $bis = $e['notfall_bis'] ?? null;
        if ($bis !== null && strtotime((string) $bis) > strtotime($jetzt)) {
            $gruende[] = self::chip(self::NOTFALL, 'Notfallweg gesperrt bis ' . date('d.m.Y', (int) strtotime((string) $bis)));
        }
        if (($e['mehrdeutig'] ?? false) === true) {
            $gruende[] = self::chip(self::MEHRDEUTIG, 'Nummer ist auch in einem anderen Team vergeben — Anmeldung nicht möglich');
        }

        return $gruende;
    }

    /** @return array{code:string, text:string, aktion:?string, knopf:?string, employee_id:?int} */
    private static function chip(string $code, string $text, ?int $employeeId = null): array
    {
        return ['code' => $code, 'text' => $text, 'aktion' => self::AKTION[$code], 'knopf' => self::KNOPF[$code], 'employee_id' => $employeeId];
    }
}
