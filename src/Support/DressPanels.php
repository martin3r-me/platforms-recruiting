<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet, welcher Kleidungs-Kasten auf der Einsatz-Seite steht.
 *
 * Reine Funktion ohne DB und ohne Blade — die Regel ist die fehleranfaellige
 * Stelle (ein Tag Service, ein Tag Logistik), nicht das Rendern.
 *
 * Ein Tag ohne eigenes Paket faellt bewusst auf den ZAS-Text zurueck: sonst
 * verloere er jede Kleidungsangabe, sobald irgendein anderer Tag ein Paket
 * bekommt.
 */
class DressPanels
{
    public const HEADING_PACKAGE = 'Deine Kleidung';
    public const HEADING_ZAS     = 'Kleidung / Infos';

    /**
     * @param  array<int|string, ?string> $days   tagKey => Paket-Text (null = kein Paket)
     * @return array{group: ?array{heading:string,text:string}, perDay: array<int|string, array{heading:string,text:string}>, hinweis: ?string}
     */
    public static function build(array $days, ?string $zasText, ?string $hinweis): array
    {
        $zas = self::clean($zasText);

        $panels = [];
        $anyPackage = false;
        $allPackages = $days !== [];

        foreach ($days as $key => $packageText) {
            $package = self::clean($packageText);
            if ($package !== null) {
                $anyPackage = true;
            } else {
                $allPackages = false;
            }

            $text = $package ?? $zas;
            if ($text === null) {
                continue;
            }
            $panels[$key] = [
                'heading' => $package !== null ? self::HEADING_PACKAGE : self::HEADING_ZAS,
                'text'    => $text,
            ];
        }

        $result = [
            'group'   => null,
            'perDay'  => [],
            'hinweis' => $anyPackage ? self::clean($hinweis) : null,
        ];

        if ($panels === []) {
            return $result;
        }

        $texts = array_column($panels, 'text');
        if (count(array_unique($texts)) === 1 && count($panels) === count($days)) {
            $result['group'] = [
                'heading' => $allPackages ? self::HEADING_PACKAGE : self::HEADING_ZAS,
                'text'    => $texts[0],
            ];

            return $result;
        }

        $result['perDay'] = $panels;

        return $result;
    }

    private static function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
