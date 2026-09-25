<?php

namespace Platform\Recruiting\Support;

/**
 * "Tage erlaubt" aus der unterschriebenen §15-Erklaerung — der Startwert,
 * ab dem ZAS das Tageskonto herunterzaehlt (Markus 24.09.2026).
 *
 * Gewinner ist der juengste Arbeitsvertrag, der wirklich eine §15-Erklaerung
 * traegt; welche Vertraege ueberhaupt zaehlen, entscheidet
 * SignedContractDeclarations.
 *
 * Gerechnet wird in ShortTermDayBudget. null heisst "keine Grundlage" und ist
 * etwas anderes als 0 ("Grenze ausgeschoepft") — ZAS zaehlt von diesem Wert
 * herunter, eine erfundene Zahl waere schlimmer als ein leeres Feld.
 */
final class SignedDayBudget
{
    public static function forApplicant(?int $applicantId, int $limit): ?int
    {
        return self::fromDeclarations(SignedContractDeclarations::preSigningDataFor($applicantId), $limit);
    }

    /**
     * Variante fuer Aufrufer, die die Erklaerungen schon gelesen haben —
     * siehe SignedEmployerDeclaration::fromDeclarations.
     *
     * @param list<array<string,mixed>> $declarations juengste zuerst
     */
    public static function fromDeclarations(array $declarations, int $limit): ?int
    {
        foreach ($declarations as $data) {
            $allowed = ShortTermDayBudget::allowedFrom($data, $limit);
            if ($allowed !== null) {
                return $allowed;
            }
        }

        return null;
    }
}
