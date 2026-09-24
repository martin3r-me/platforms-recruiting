<?php

namespace Platform\Recruiting\Support;

/**
 * Katalog der Nachweisarten — die einzige Stelle, die weiss, welche es gibt,
 * welche ein Ablaufdatum haben und auf welche der 16 Altspalten sie zeigen.
 *
 * Bewusst PHP und keine Tabelle: Die Zuordnung auf die Altspalten ist fest mit
 * dem Code verdrahtet (Doppelschreiben, Umzug, ZAS-Export), eine Tabelle koennte
 * davon wegdriften. Gleiches Muster wie EmployeeFileSlots, NonEuDocumentMapping
 * und SchoolCertificateFields. Vorlaufzeiten koennen spaeter in die
 * Team-Einstellungen wandern, falls der Kunde sie ohne Deploy aendern will.
 *
 * DREI Arten haben ein Ablaufdatum OHNE Altspalte — Nationalpass, Visumsblatt
 * und Fiktionsbescheinigung. Das ist Absicht: Fuer sie gibt es im ZAS-Export
 * keine Spalte, ihr Datum bleibt bei uns und dient nur unseren Erinnerungen.
 * Die Fiktionsbescheinigung ist dabei der wichtigste Fall — sie gilt nur wenige
 * Monate und entscheidet ueber die Arbeitserlaubnis, und heute sieht niemand,
 * wann sie ablaeuft.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class ProofTypes
{
    /**
     * dateien       Altspalten (Vorderseite, optional Rueckseite)
     * ablauf        hat die Art ein Gueltig-bis?
     * ablauf_spalte Altspalte dafuer — null heisst: nur bei uns, nicht in ZAS
     * vorlauf       Tage vor Ablauf, ab denen erinnert wird (Kundenvorgabe 22.09.:
     *               Aufenthaltstitel und Arbeitsgenehmigung 60, alles andere 30)
     */
    private const TYPES = [
        'ausweis' => [
            'label' => 'Personalausweis oder Reisepass', 'gruppe' => 'immer',
            'dateien' => ['identity_card_front_file_id', 'identity_card_back_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'identity_card_valid_until', 'vorlauf' => 30,
        ],
        'selfie' => [
            'label' => 'Foto von dir', 'gruppe' => 'immer',
            'dateien' => ['selfie_file_id'],
            'ablauf' => false, 'ablauf_spalte' => null, 'vorlauf' => null,
        ],
        'krankenkasse' => [
            'label' => 'Krankenkassenkarte', 'gruppe' => 'immer',
            'dateien' => ['health_insurance_card_file_id'],
            'ablauf' => false, 'ablauf_spalte' => null, 'vorlauf' => null,
        ],
        'iban_nachweis' => [
            'label' => 'Nachweis deiner Bankverbindung', 'gruppe' => 'immer',
            'dateien' => [],
            'ablauf' => false, 'ablauf_spalte' => null, 'vorlauf' => null,
        ],

        'nationalpass' => [
            'label' => 'Nationalpass', 'gruppe' => 'nicht_eu',
            'dateien' => ['nationalpass_file_id'],
            'ablauf' => true, 'ablauf_spalte' => null, 'vorlauf' => 30,
        ],
        'aufenthaltstitel' => [
            'label' => 'Aufenthaltstitel', 'gruppe' => 'nicht_eu',
            'dateien' => ['aufenthaltstitel_front_file_id', 'aufenthaltstitel_back_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'residence_permit_valid_until', 'vorlauf' => 60,
        ],
        'visum' => [
            'label' => 'Visumsblatt', 'gruppe' => 'nicht_eu',
            'dateien' => ['visumsblatt_file_id'],
            'ablauf' => true, 'ablauf_spalte' => null, 'vorlauf' => 30,
        ],
        'arbeitsgenehmigung' => [
            'label' => 'Zusatzblatt Arbeitsgenehmigung', 'gruppe' => 'nicht_eu',
            'dateien' => ['zusatzblatt_file_id', 'zusatzblatt_back_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'work_permit_valid_until', 'vorlauf' => 60,
        ],
        'fiktionsbescheinigung' => [
            'label' => 'Fiktionsbescheinigung', 'gruppe' => 'nicht_eu',
            'dateien' => ['fiktionsbescheinigung_front_file_id', 'fiktionsbescheinigung_back_file_id'],
            'ablauf' => true, 'ablauf_spalte' => null, 'vorlauf' => 30,
        ],

        'schulbescheinigung' => [
            'label' => 'Schulbescheinigung', 'gruppe' => 'beschaeftigungsart',
            'dateien' => ['schulbescheinigung_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'school_certificate_valid_until', 'vorlauf' => 30,
        ],
        'immatrikulation' => [
            'label' => 'Immatrikulationsbescheinigung', 'gruppe' => 'beschaeftigungsart',
            'dateien' => ['immatrikulation_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'school_certificate_valid_until', 'vorlauf' => 30,
        ],

        'erstbescheinigung' => [
            'label' => 'Erstbescheinigung nach Infektionsschutzgesetz', 'gruppe' => 'taetigkeit',
            'dateien' => ['erstbescheinigung_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'infection_protection_valid_until', 'vorlauf' => 30,
        ],
        'ersthelfer' => [
            'label' => 'Ersthelferschein', 'gruppe' => 'taetigkeit',
            'dateien' => ['first_aider_certificate_file_id'],
            'ablauf' => true, 'ablauf_spalte' => 'first_aider_valid_until', 'vorlauf' => 30,
        ],
    ];

    /**
     * Die zwei Arten, die HR "zur Kenntnis" auf einer eigenen Liste sieht.
     *
     * WICHTIG (Korrektur K3, 24.09.2026): das ist KEINE Bestaetigung mit
     * Wirkung mehr — der urspruengliche Plan (HR bestaetigt das Datum, weil
     * daran eine harte Einsatzsperre haengt) war ein Denkfehler. Die Sperre
     * gibt es nur fuer BEWERBER (LegalStatusGate blockiert Vertrag und
     * Erinnerung), nicht fuer Mitarbeiter; residence_permit_valid_until und
     * work_permit_valid_until werden nirgends im Modul fuer eine Sperre
     * gelesen. Der Bestaetigen-Knopf in ProofInbox ist deshalb entfernt.
     *
     * @var list<string>
     */
    private const HR_BESTAETIGUNG_PFLICHT = ['aufenthaltstitel', 'arbeitsgenehmigung'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $code): bool
    {
        return isset(self::TYPES[$code]);
    }

    public static function label(string $code): string
    {
        return self::TYPES[$code]['label'] ?? '';
    }

    public static function group(string $code): string
    {
        return self::TYPES[$code]['gruppe'] ?? '';
    }

    public static function hasExpiry(string $code): bool
    {
        return (bool) (self::TYPES[$code]['ablauf'] ?? false);
    }

    public static function leadDays(string $code): ?int
    {
        return self::TYPES[$code]['vorlauf'] ?? null;
    }

    /**
     * Gehoert diese Art auf die "zur Kenntnis"-Liste in ProofInbox? Nur
     * Aufenthaltstitel und Arbeitsgenehmigung — siehe HR_BESTAETIGUNG_PFLICHT.
     * Alles andere gilt mit dem Upload sofort als erledigt (Kundenvorgabe
     * 22.09.2026).
     *
     * BEWUSST NICHT AUFRAEUMEN, auch wenn kein Bestaetigen-Knopf mehr daran
     * haengt (Korrektur K3, 24.09.2026): die Spalten confirmed_by_user_id und
     * confirmed_at bleiben in rec_employee_proofs, und diese Methode bleibt
     * die einzige Quelle dafuer, welche Arten in der "zur Kenntnis"-Liste
     * auftauchen (ProofInbox::wartetAufBestaetigung()) — falls der Kunde eine
     * Bestaetigung MIT Wirkung doch will, kommen Knopf und Wirkung zusammen
     * zurueck, nicht diese Methode allein.
     */
    public static function needsHrConfirmation(string $code): bool
    {
        return in_array($code, self::HR_BESTAETIGUNG_PFLICHT, true);
    }

    /** @return list<string> Altspalten dieser Art — leer bei neuen Arten ohne Altbestand. */
    public static function legacyFileColumns(string $code): array
    {
        return self::TYPES[$code]['dateien'] ?? [];
    }

    /** Altspalte des Ablaufdatums, oder null wenn es sie nur bei uns gibt (nicht in ZAS). */
    public static function legacyExpiryColumn(string $code): ?string
    {
        return self::TYPES[$code]['ablauf_spalte'] ?? null;
    }

    /**
     * Welche Nachweise braucht dieser Mensch?
     *
     * Die Regeln kommen NICHT neu — sie spiegeln, was NonEuDocumentMapping und
     * SchoolCertificateFields heute schon entscheiden.
     *
     * Zurueckhaltend bei unbekanntem EU-Status: `is_eu_citizen` ist im Bestand
     * zu 83 % leer. Wer NULL hat, wird NICHT nach Aufenthaltstitel gefragt —
     * lieber keine Aufgabe als eine falsche. Sobald der Status aus der
     * ZAS-Lieferung nachgezogen ist, greift die Regel von selbst.
     *
     * @param array{is_eu_citizen: ?bool, employment_type: ?string, is_first_aider: ?bool} $mitarbeiter
     * @return list<string>
     */
    public static function requiredFor(array $mitarbeiter): array
    {
        $pflicht = ['ausweis'];

        if (($mitarbeiter['is_eu_citizen'] ?? null) === false) {
            $pflicht[] = 'nationalpass';
            $pflicht[] = 'aufenthaltstitel';
            $pflicht[] = 'arbeitsgenehmigung';
        }

        $pflicht = array_merge($pflicht, match ($mitarbeiter['employment_type'] ?? null) {
            'schueler' => ['schulbescheinigung'],
            'student'  => ['immatrikulation'],
            default    => [],
        });

        if (($mitarbeiter['is_first_aider'] ?? null) === true) {
            $pflicht[] = 'ersthelfer';
        }

        return array_values(array_unique($pflicht));
    }
}
