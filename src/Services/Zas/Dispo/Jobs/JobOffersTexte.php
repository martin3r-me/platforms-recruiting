<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Carbon\CarbonInterface;

/**
 * Texte der Jobseite je Anlass — Wortlaut 1:1 der Portal-Spalte von Markus'
 * Folien 1–3 („RheinGedeck_Dispo_Templates_Prozess_6_Folien_kommentiert_MA_SH_MA II.pptx“,
 * 28.10.2026) — und die Zeile „Bitte antworte bis …“ (= naechste automatische
 * Welle). Rein.
 */
final class JobOffersTexte
{
    private const VORRANG = ['dringend' => 3, 'naechste_woche' => 2, 'vier_wochen' => 1];

    /** {vorname} = „, Vorname“ oder leer; {frist} = Zeitpunkt der naechsten Welle. */
    private const TEXTE = [
        'dringend' => [
            'kicker'   => 'Hey{vorname}, wir zählen auf dich!',
            'satz'     => 'Für diese Veranstaltung brauchen wir dringend Unterstützung.',
            'antwort'  => 'Bitte antworte bis {frist}, ob du dabei bist. Wenn nicht, gib uns bitte Bescheid.',
            'fussnote' => null,
        ],
        'naechste_woche' => [
            'kicker'   => 'Hier brauchen wir deine Unterstützung!',
            'satz'     => 'Schau dir die offenen Einsätze für nächste Woche an und melde dich für alle Tage, an denen du dabei sein kannst.',
            'antwort'  => 'Bitte antworte bis {frist}',
            'fussnote' => 'Eine Meldung ist noch keine bestätigte Einteilung.',
        ],
        'vier_wochen' => [
            'kicker'   => 'Sichere dir frühzeitig deine Einsätze!',
            'satz'     => 'Wer zuerst reagiert, hat bessere Chancen auf passende Schichten – und damit auf die Stunden und den Verdienst, die du dir wünschst. Schau jetzt rein und melde dich für deine Wunschtermine.',
            'antwort'  => 'Bitte antworte bis {frist}',
            'fussnote' => 'Keine Garantie auf eine Einteilung.',
        ],
    ];

    private const STANDARD = ['kicker' => 'Wir suchen Verstärkung', 'satz' => null, 'antwort' => 'Bitte antworte bis {frist}', 'fussnote' => null];

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

    /**
     * @param string|null $frist Text aus antwortenBis(); null = keine Welle geplant → keine Antwort-Zeile.
     * @return array{kicker:string, satz:?string, antwort:?string, fussnote:?string}
     */
    public static function fuer(?string $anlass, string $vorname = '', ?string $frist = null): array
    {
        $t = self::TEXTE[$anlass] ?? self::STANDARD;
        $vorname = trim($vorname);

        return [
            'kicker'   => str_replace('{vorname}', $vorname !== '' ? ' ' . $vorname : '', $t['kicker']),
            'satz'     => $t['satz'],
            'antwort'  => $frist !== null && $frist !== '' ? str_replace('{frist}', $frist, $t['antwort']) : null,
            'fussnote' => $t['fussnote'],
        ];
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
