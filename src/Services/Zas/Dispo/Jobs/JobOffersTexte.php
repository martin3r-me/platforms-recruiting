<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Carbon\CarbonInterface;

/**
 * Texte der Jobseite je Anlass (Folien 1–3, Markus 23.10.2026) und die
 * Zeile „Bitte antworte bis …“ (= naechste automatische Welle). Rein.
 */
final class JobOffersTexte
{
    private const VORRANG = ['dringend' => 3, 'naechste_woche' => 2, 'vier_wochen' => 1];

    private const TEXTE = [
        'dringend' => [
            'kicker'   => 'Wir zählen auf dich!',
            'satz'     => 'Für diese Veranstaltung brauchen wir dringend Unterstützung.',
            'fussnote' => null,
        ],
        'naechste_woche' => [
            'kicker'   => 'Hier brauchen wir deine Unterstützung!',
            'satz'     => 'Melde dich für alle Tage, an denen du dabei sein kannst.',
            'fussnote' => 'Eine Meldung ist noch keine bestätigte Einteilung.',
        ],
        'vier_wochen' => [
            'kicker'   => 'Sichere dir frühzeitig deine Einsätze!',
            'satz'     => 'Wer zuerst reagiert, hat bessere Chancen auf passende Schichten.',
            'fussnote' => 'Keine Garantie auf eine Einteilung.',
        ],
    ];

    private const STANDARD = ['kicker' => 'Wir suchen Verstärkung', 'satz' => null, 'fussnote' => null];

    /** Bei gebuendelten Schichten mehrerer Anfragen gewinnt Dringend > Woche > Vierwochen. */
    public static function anlassVorrang(array $anlaesse): ?string
    {
        $best = null;
        foreach ($anlaesse as $a) {
            if ($a !== null && isset(self::VORRANG[$a]) && ($best === null || self::VORRANG[$a] > self::VORRANG[$best])) {
                $best = $a;
            }
        }

        return $best;
    }

    /** @return array{kicker:string, satz:?string, fussnote:?string} */
    public static function fuer(?string $anlass): array
    {
        return self::TEXTE[$anlass] ?? self::STANDARD;
    }

    /** „heute 12:30 Uhr“, „morgen 12:30 Uhr“, sonst „Do 30.10., 12:30 Uhr“. */
    public static function antwortenBis(CarbonInterface $zeit, CarbonInterface $jetzt): string
    {
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $uhr = $zeit->format('H:i') . ' Uhr';
        if ($zeit->isSameDay($jetzt)) {
            return 'heute ' . $uhr;
        }
        if ($zeit->isSameDay($jetzt->copy()->addDay())) {
            return 'morgen ' . $uhr;
        }

        return $wd[$zeit->dayOfWeek] . ' ' . $zeit->format('d.m.') . ', ' . $uhr;
    }
}
