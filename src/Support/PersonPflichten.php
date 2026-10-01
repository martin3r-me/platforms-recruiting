<?php

namespace Platform\Recruiting\Support;

/**
 * Die Pflichtnachweise eines MENSCHEN, nicht einer Anstellung.
 *
 * ProofReader::current() liest die vorhandenen Nachweise laengst ueber die
 * ganze Person (PersonScopeResolver). Die PFLICHTEN kamen bisher aus der
 * einen Anstellung, mit der jemand gerade zu tun hatte — wer bei RG als
 * Student und bei MA als Aushilfe gefuehrt wird, sah je nach Anstellung eine
 * andere Liste.
 *
 * Vereinigt wird, nicht geschnitten: ein Dokument, das EINE Anstellung
 * verlangt, braucht der Mensch. Widersprechen sich die Datensaetze bei der
 * Staatsangehoerigkeit, gilt damit automatisch die strengere Lesart — das ist
 * gewollt, denn die Arbeitsberechtigung ist die teurere Richtung zum Irren.
 */
final class PersonPflichten
{
    /**
     * @param list<array{is_eu_citizen?:bool|null, employment_type?:string|null, is_first_aider?:bool|null}> $anstellungen
     * @return list<string>
     */
    public static function vereinige(array $anstellungen): array
    {
        $alle = [];

        foreach ($anstellungen as $anstellung) {
            foreach (ProofTypes::requiredFor($anstellung) as $code) {
                $alle[] = $code;
            }
        }

        return array_values(array_unique($alle));
    }
}
