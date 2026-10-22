<?php

namespace Platform\Recruiting\Support\Comms;

/**
 * Entscheidung ohne DB (S-Themen §2.1): loest dieser Eingang die Themen-Frage
 * aus? null = ja, sonst der Grund (fuer Log und Tests). Die Ruhezeit zaehlt die
 * JUENGSTE Aktivitaet im Thread in beide Richtungen, auch Auto-Antworten —
 * wer auf unsere Nachricht antwortet, hat kein neues Anliegen.
 *
 * `pilot`: true = Absender ist in der Pilotgruppe (PilotKreis) ODER die
 * Team-Sperre comms_topic_nur_pilot ist aus. Neue Features nur fuer den
 * Pilot (Sebastian 19.10.2026) — wer noch am alten Portal haengt, bekommt
 * die Frage nicht.
 *
 * @param array{enabled:bool, hr_kanal:bool, aktiver_ma:bool, pilot:bool, quick_reply:bool, message_type:string,
 *              letzte_aktivitaet_ts:?int, jetzt_ts:int, ruhe_stunden:int, fremder_kontext:bool} $k
 */
final class ThemenAusloeser
{
    public static function qualifiziert(array $k): ?string
    {
        if (!$k['enabled']) {
            return 'schalter_aus';
        }
        if (!$k['hr_kanal']) {
            return 'kein_hr_kanal';
        }
        if ($k['fremder_kontext']) {
            return 'fremder_kontext';
        }
        if (!$k['aktiver_ma']) {
            return 'kein_aktiver_ma';
        }
        if (!$k['pilot']) {
            return 'nicht_im_pilot';
        }
        if ($k['quick_reply']) {
            return 'button_klick';
        }
        if ($k['message_type'] === 'audio') {
            return 'sprachnachricht';
        }
        $letzte = $k['letzte_aktivitaet_ts'];
        if ($letzte !== null && $k['ruhe_stunden'] > 0 && ($k['jetzt_ts'] - $letzte) <= $k['ruhe_stunden'] * 3600) {
            return 'ruhezeit';
        }

        return null;
    }
}
