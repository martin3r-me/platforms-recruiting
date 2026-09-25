<?php

namespace Platform\Recruiting\Support;

/**
 * Die Arbeitgeber-Erklaerung aus dem Unterschriften-Schritt des
 * Arbeitsvertrags (Markus 24.09.2026).
 *
 * Sie wird beim Signieren zusammen mit §15/§16 in rec_contracts.pre_signing_data
 * abgelegt und von dort auf den Mitarbeiter uebernommen — direkt, wenn es ihn
 * schon gibt, sonst bei seiner Anlage.
 *
 * BEWUSST NICHT im Vertragsdokument (Entscheidung 25.09.2026). Markus hat eine
 * verbindliche Auswahl verlangt, keine Aenderung am Vertragstext. Damit bleibt
 * dieser Umbau komplett aus der Vertrags-Darstellung heraus — dort gibt es die
 * offene Altlast, dass "Felder -> Speichern" an unterschriebenen Vertraegen
 * neu rendert. RecContract::embedPreSigningData liest ausschliesslich die
 * Schluessel par15_has_previous, par15_entries, par16_was_jobseeking und
 * par16_entries — die hier hinzugefuegten ignoriert es.
 *
 * Reine Logik (kein Framework/DB) -> pure-unit-testbar.
 */
final class EmployerDeclaration
{
    public const ROLE_MAIN      = 'haupt';
    public const ROLE_SECONDARY = 'neben';

    /** Schluessel in pre_signing_data. */
    public const KEY_ROLE  = 'arbeitgeber_rolle';
    public const KEY_OTHER = 'anderer_arbeitgeber';

    /**
     * Vorauswahl im Unterschriften-Schritt aus dem Feld "Ich bin".
     *
     * Markus: "Wenn der Mitarbeiter Schueler oder Student ist, ist
     * RheinGedeck in diesem Prozess automatisch als Hauptarbeitgeber
     * auszuwaehlen." Umgesetzt als Vorbelegung, nicht als Sperre
     * (Entscheidung 25.09.2026): ein Werkstudent oder dualer Student mit
     * festem Hauptjob bekaeme sonst die falsche Steuerklasse und koennte es
     * nicht korrigieren.
     *
     * `student_erwerbstaetig` ist bewusst NICHT dabei — dieser Wert sagt
     * ausdruecklich, dass die Person nebenher arbeitet.
     */
    public static function defaultRoleFor(?string $employmentType): ?string
    {
        return match ($employmentType) {
            'schueler', 'student' => self::ROLE_MAIN,
            default               => null,
        };
    }

    /**
     * Validierungsregeln fuer den Unterschriften-Schritt.
     *
     * Stehen hier statt in der Livewire-Komponente, weil sie zur Erklaerung
     * gehoeren und nicht zur Maske — und weil sie so ohne Livewire-Kontext
     * pruefbar sind. Die Schluessel sind die Property-Namen der Komponente.
     *
     * max:128 ist kein Schoenheitswert: rec_employees.other_employer ist
     * string(128). Ohne Grenze im Formular kaeme eine laengere Eingabe erst
     * beim Uebertrag auf den Mitarbeiter als 22001 zurueck — derselbe Abbruch,
     * der am 25.08.2026 die MA-Anlage gekillt hat.
     */
    public static function rules(): array
    {
        return [
            'employerRole'  => 'required|in:' . self::ROLE_MAIN . ',' . self::ROLE_SECONDARY,
            'employerOther' => 'nullable|required_if:employerRole,' . self::ROLE_SECONDARY . '|string|max:128',
        ];
    }

    /** Fehlermeldungen in der Anrede des Teams (use_informal_address). */
    public static function messages(bool $duzen): array
    {
        return [
            'employerRole.required' => $duzen
                ? 'Bitte waehle aus, wie du bei uns angemeldet wirst.'
                : 'Bitte waehlen Sie aus, wie Sie bei uns angemeldet werden.',
            'employerRole.in' => $duzen
                ? 'Bitte waehle eine der beiden Moeglichkeiten.'
                : 'Bitte waehlen Sie eine der beiden Moeglichkeiten.',
            'employerOther.required_if' => $duzen
                ? 'Bitte trag ein, wer dein Hauptarbeitgeber ist.'
                : 'Bitte tragen Sie ein, wer Ihr Hauptarbeitgeber ist.',
            'employerOther.max' => $duzen
                ? 'Der Name ist zu lang — bitte auf 128 Zeichen kuerzen.'
                : 'Der Name ist zu lang — bitte auf 128 Zeichen kuerzen.',
        ];
    }

    /**
     * pre_signing_data -> RecEmployee-Spalten.
     *
     * Leeres Array, wenn keine Erklaerung enthalten ist: §15/§16-Daten aus
     * der Zeit vor dieser Erweiterung duerfen am Mitarbeiter nichts anfassen.
     *
     * `other_employer` wird auch dann geliefert, wenn es leer ist — und zwar
     * als null. Die Erklaerung ist der Stand vom Unterschriftstag und besagt
     * dann ausdruecklich "kein weiterer Arbeitgeber"; ein alter Wert am
     * Mitarbeiter muss also weichen, nicht stehenbleiben.
     *
     * @return array{is_main_employer?: bool, other_employer?: ?string}
     */
    public static function toEmployeeAttributes(array $preSigningData): array
    {
        $role = $preSigningData[self::KEY_ROLE] ?? null;

        if (!in_array($role, [self::ROLE_MAIN, self::ROLE_SECONDARY], true)) {
            return [];
        }

        $other = trim((string) ($preSigningData[self::KEY_OTHER] ?? ''));

        return [
            'is_main_employer' => $role === self::ROLE_MAIN,
            'other_employer'   => $other === '' ? null : $other,
        ];
    }
}
