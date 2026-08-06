<?php

namespace Platform\Recruiting\Support;

/**
 * Whitelist der RecEmployee-Dokumentspalten, die ueber den
 * EmployeeFileController (HR-Backend, "Dokument anzeigen") ausgeliefert
 * werden duerfen. Schuetzt davor, dass ueber den slot-Parameter beliebige
 * Spalten des Modells angefragt werden.
 *
 * Muss synchron bleiben mit dem File-Renderer in employees/show
 * (FILE_UPLOAD_MAP) — der Unit-Test prueft die Liste explizit.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
class EmployeeFileSlots
{
    public const COLUMNS = [
        'identity_card_front_file_id',
        'identity_card_back_file_id',
        'selfie_file_id',
        'health_insurance_card_file_id',
        'nationalpass_file_id',
        'aufenthaltstitel_front_file_id',
        'aufenthaltstitel_back_file_id',
        'visumsblatt_file_id',
        'zusatzblatt_file_id',
        'zusatzblatt_back_file_id',
        'immatrikulation_file_id',
        'schulbescheinigung_file_id',
        'fiktionsbescheinigung_front_file_id',
        'fiktionsbescheinigung_back_file_id',
        'erstbescheinigung_file_id',
    ];

    public static function isAllowed(string $slot): bool
    {
        return in_array($slot, self::COLUMNS, true);
    }
}
