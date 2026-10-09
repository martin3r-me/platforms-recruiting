<?php

namespace Platform\Recruiting\Support;

/**
 * Anzeigename eines Vertrags aus dem Vorlagen-Code — EINE Stelle fuer
 * Portal (PortalShell), VertragLeser und spaeter die MA-Akte (Spec Vertrag
 * aus der Akte §2.5). AV-* → "Arbeitsvertrag", IFSG → "Infektionsschutzgesetz",
 * AT-* → "Zusatzvereinbarung", sonst der Vorlagenname.
 */
final class VertragsAnzeige
{
    public static function name(?string $code, ?string $name): string
    {
        return match (true) {
            $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag',
            $code === 'IFSG'                                => 'Infektionsschutzgesetz',
            $code !== null && str_starts_with($code, 'AT-') => 'Zusatzvereinbarung',
            default                                         => $name ?? 'Vertrag',
        };
    }
}
