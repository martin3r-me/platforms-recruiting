<?php

namespace Platform\Recruiting\Support;

/**
 * Die Felder, die dem MENSCHEN gehoeren und deshalb auf allen Akten (RG, MA)
 * gleich sein muessen (Spec 2026-10-09 §2.1). EINE Liste — Spiegel und
 * Pruefkommando lesen nur hier.
 *
 * Kundenentscheidung Markus 12.10.2026: Steuerklasse, Haupt-/Nebenarbeitgeber
 * und Krankenkasse sind keine Personenfelder mehr (siehe JE_GESELLSCHAFT).
 */
final class PersonenFelder
{
    public const SPIEGELN = [
        'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place', 'gender',
        'email',
        'street', 'house_number', 'zip', 'city', 'country_code', 'birth_country', 'nationality', 'is_eu_citizen',
        'identity_card_number', 'identity_card_valid_until', 'drivers_license_class',
        'marital_status', 'number_of_children', 'religion', 'employment_type',
        'iban', 'bic', 'bank_institute', 'account_holder',
        'steuer_id', 'sozialversicherungsnummer',
        // Spec 2026-10-22 §4 (Sebastian 21.10.): Ersthelfer gehoert dem
        // Menschen, nicht der Gesellschaft. Der Schein laeuft als Nachweis
        // (ProofWriter spiegelt die Altspalte schon selbst).
        'is_first_aider', 'first_aider_valid_until',
    ];

    public const DATUM = ['birth_date', 'identity_card_valid_until', 'first_aider_valid_until'];
    public const BOOL  = ['is_main_employer', 'is_eu_citizen', 'is_first_aider'];

    /**
     * Felder, die an der ANSTELLUNG haengen, nicht am Menschen: sie werden
     * nie gespiegelt und im Personendaten-Abgleich nie als Abweichung
     * gemeldet.
     *
     * Historie: bis 12.10.2026 standen tax_class, is_main_employer und
     * other_employer in SPIEGELN und liessen sich nur ueber den Team-Schalter
     * tax_class_per_company herausnehmen (Review I1, Ruling 09.10.).
     *
     * Kundenentscheidung Markus 12.10.2026 (E7/E8): Steuerklasse und
     * Haupt-/Nebenarbeitgeber sind je Gesellschaft verschieden — „wenn 1
     * vergeben, dann die zweite Karte 6", „nicht den gleichen Status 2 x
     * vergeben". Die Krankenkasse ebenso (Generalprobe 13.10.: der
     * MA-Minijob laeuft ueber die Knappschaft/Minijob-Zentrale). Der Schalter
     * ist damit entfallen; was zwischen den Anstellungen gilt, regelt
     * AnstellungsRegeln (eine Stelle), nicht der Spiegel.
     */
    public const JE_GESELLSCHAFT = ['tax_class', 'is_main_employer', 'other_employer', 'health_insurance'];

    /**
     * Felder, die bei offenem Export-Marker eine ZAS-Lieferung nicht
     * ueberschreiben darf (ZasInboundEmployeeImporter, Review C1). Die
     * Gesellschaftsfelder gehoeren weiter dazu: eine eigene Aenderung (auch
     * die Folgeaenderung aus AnstellungsRegeln) ist auf dem Weg zu ZAS, die
     * Lieferung traegt den alten Stand. Seit 12.10.2026 als eigene Liste,
     * damit das Herausnehmen aus SPIEGELN diesen Schutz nicht still abbaut.
     */
    public const MARKER_GESCHUETZT = [
        ...self::SPIEGELN,
        ...self::JE_GESELLSCHAFT,
    ];

    /**
     * Personenfelder fuer ein Team. Seit 12.10.2026 (Kundenentscheidung
     * Markus, E7/E8) ohne Team-Abhaengigkeit: der Schalter
     * tax_class_per_company ist entfallen, die Gesellschaftsfelder stehen gar
     * nicht mehr in SPIEGELN. Signatur bleibt, damit die Aufrufer (Spiegel,
     * Abgleich) unveraendert lesen; es wird keine Einstellung mehr gelesen.
     *
     * @param  list<string>|null $nurFuer
     * @return list<string>
     */
    public static function fuerTeam(?int $teamId, ?array $nurFuer = null): array
    {
        return $nurFuer === null ? self::SPIEGELN : array_values(array_intersect(self::SPIEGELN, $nurFuer));
    }

    /** Vergleichsform: leer = null, Datum ohne Uhrzeit, Bool als '1'/'0'. */
    public static function normalisiere(string $feld, mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }
        if (in_array($feld, self::BOOL, true)) {
            return ((bool) (is_string($wert) ? trim($wert) : $wert)) ? '1' : '0';
        }
        $text = trim((string) $wert);
        if ($text === '') {
            return null;
        }

        return in_array($feld, self::DATUM, true) ? substr($text, 0, 10) : $text;
    }
}
