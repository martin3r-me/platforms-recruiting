<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Login-Nummer der Person nach einer Aenderung der Handynummer in der Akte
 * (Sebastian 22.10.2026).
 *
 * Bis hierhin zog eine Aktenkorrektur nur den CRM-Kontakt mit
 * (RecEmployeePhoneSyncObserver), die Login-Nummer an rec_persons blieb alt —
 * die Einladung ging dann an die alte Nummer.
 *
 *  - OHNE KONTO (kein Passwort): die Login-Nummer wandert mit.
 *  - MIT KONTO: bleibt. Die Nummer ist dann der Zugang; der Mensch aendert
 *    sie selbst im Portal und bestaetigt sie per Code (Nummernwechsel). Der
 *    Konto-Kasten zeigt die Abweichung.
 *  - VERGEBEN: gehoert die neue Nummer im Team schon einer anderen Person
 *    (Eindeutigkeit team_id + phone), bleibt sie stehen; der Kasten nennt es.
 *
 * Bewusst NUR rec_persons — nicht PersonLinker::setzeNummer(), das die Nummer
 * auch in alle Anstellungen schreibt (ohne Export-Marker). Die Schwester-Akte
 * aendert sich hier nicht still mit.
 */
final class LoginNummer
{
    public const GESETZT      = 'gesetzt';
    public const UNVERAENDERT = 'unveraendert';
    public const MIT_KONTO    = 'mit_konto';
    public const BELEGT       = 'belegt';
    public const OHNE         = 'ohne';

    public static function nachAktenAenderung(RecEmployee $employee): string
    {
        $neu = PhoneE164::normalize($employee->phone);
        if ($employee->rec_person_id === null || $neu === null) {
            return self::OHNE;
        }
        $person = DB::table('rec_persons')->where('id', (int) $employee->rec_person_id)
            ->first(['id', 'team_id', 'phone', 'password_hash', 'merged_into_person_id']);
        if ($person === null || $person->merged_into_person_id !== null) {
            return self::OHNE;
        }
        if ((string) $person->phone === $neu) {
            return self::UNVERAENDERT;
        }
        $kontext = ['person_id' => (int) $person->id, 'employee_id' => (int) $employee->id];
        if ($person->password_hash !== null) {
            Log::notice('recruiting.konto.login_nummer_abweichend', $kontext);

            return self::MIT_KONTO;
        }
        if (self::nummerVergeben($person->team_id !== null ? (int) $person->team_id : null, $neu, (int) $person->id)) {
            Log::notice('recruiting.konto.login_nummer_vergeben', $kontext);

            return self::BELEGT;
        }

        DB::table('rec_persons')->where('id', (int) $person->id)->update(['phone' => $neu, 'updated_at' => now()]);
        Log::info('recruiting.konto.login_nummer_nachgezogen', $kontext);

        return self::GESETZT;
    }

    /** Gehoert die Nummer im Team schon einer anderen Personen-Zeile? */
    public static function nummerVergeben(?int $teamId, string $nummer, int $ausser): bool
    {
        $q = DB::table('rec_persons')->where('phone', $nummer)->where('id', '!=', $ausser);
        $teamId === null ? $q->whereNull('team_id') : $q->where('team_id', $teamId);

        return $q->exists();
    }
}
