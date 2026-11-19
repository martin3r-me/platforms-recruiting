<?php

namespace Platform\Recruiting\Services\Statistics\Mitarbeiter;

use Platform\Recruiting\Livewire\Statistics\Mitarbeiter;

/**
 * Täglicher Personalreport (Markus 19.11.2026: „Übersicht täglich geht an alle … jeden Morgen um
 * 08 Uhr“, Montag bis Freitag). Je Kostenstelle Soll, Aktiv, Produktiv, Abweichung — dieselben
 * Zahlen wie der Reiter „Mitarbeiter“ mit Stichtag heute. Die Warnung bei Unterdeckung kommt
 * bewusst NICHT (Markus: „erst im zweiten Schritt“).
 *
 * Hier wird nur der Inhalt gebaut; versendet wird im Kommando recruiting:personalreport.
 */
class Personalreport
{
    public const SETTING_AKTIV = 'personalreport_aktiv';
    public const SETTING_EMPFAENGER = 'personalreport_empfaenger';
    public const SETTING_LETZTER_VERSAND = 'personalreport_letzter_versand';

    /**
     * @param array{zeilen: list<array<string,mixed>>, gesamt: array<string,mixed>} $auswertung
     * @return array{betreff:string, html:string, text:string}
     */
    public static function inhalt(array $auswertung, string $heuteYmd, ?string $link): array
    {
        $datum = (new \DateTimeImmutable($heuteYmd))->format('d.m.Y');
        $betreff = 'Personalreport ' . $datum;

        $fmt = fn (?int $n) => $n === null ? '–' : (string) $n;
        $fmtAbw = fn (?int $n) => $n === null ? '–' : ($n > 0 ? '+' . $n : ($n < 0 ? '−' . abs($n) : '0'));

        $zeilen = $auswertung['zeilen'];
        $gesamt = $auswertung['gesamt'];

        $th = 'style="text-align:left;padding:6px 10px;border-bottom:1px solid #ccc;font-weight:bold"';
        $thr = 'style="text-align:right;padding:6px 10px;border-bottom:1px solid #ccc;font-weight:bold"';
        $tdr = 'style="text-align:right;padding:6px 10px;border-bottom:1px solid #eee"';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#000">'
            . '<p>Guten Morgen,</p>'
            . '<p>hier die Personalübersicht vom ' . e($datum) . '.</p>'
            . '<table style="border-collapse:collapse;font-size:14px">'
            . '<tr><th ' . $th . '>Kostenstelle</th><th ' . $thr . '>Soll</th><th ' . $thr . '>Aktiv</th><th ' . $thr . '>Produktiv</th><th ' . $thr . '>Abweichung</th></tr>';
        $text = "Personalübersicht vom {$datum}\n\n"
            . sprintf("%-28s %6s %6s %10s %11s\n", 'Kostenstelle', 'Soll', 'Aktiv', 'Produktiv', 'Abweichung');

        $alle = $zeilen;
        $alle[] = ['kostenstelle' => 'gesamt'] + $gesamt;
        foreach ($alle as $z) {
            $name = $z['kostenstelle'] === 'gesamt' ? 'Gesamt' : Mitarbeiter::kostenstellenName($z['kostenstelle']);
            $istGesamt = $z['kostenstelle'] === 'gesamt';
            $abw = $z['abweichung'];
            $farbe = $abw !== null && $abw < 0 ? 'color:#b91c1c;' : ($abw !== null && $abw > 0 ? 'color:#15803d;' : '');
            $b = $istGesamt ? 'font-weight:bold;' : '';
            $html .= '<tr>'
                . '<td style="text-align:left;padding:6px 10px;border-bottom:1px solid #eee;' . $b . '">' . e($name) . '</td>'
                . '<td ' . $tdr . '>' . $fmt($z['soll']) . '</td>'
                . '<td ' . $tdr . '>' . $z['aktiv'] . '</td>'
                . '<td ' . $tdr . '>' . $z['produktiv'] . '</td>'
                . '<td style="text-align:right;padding:6px 10px;border-bottom:1px solid #eee;' . $b . $farbe . '">' . $fmtAbw($abw) . '</td>'
                . '</tr>';
            $text .= sprintf("%-28s %6s %6d %10d %11s\n", mb_substr($name, 0, 28), $fmt($z['soll']), $z['aktiv'], $z['produktiv'], $fmtAbw($abw));
        }

        $erklaerung = 'Aktiv = aktive Mitarbeiter im HCM, jede Person einmal. Produktiv = aktiv und mindestens ein '
            . 'disponierter Einsatz in den letzten 60 Tagen oder ein schon disponierter kommender Einsatz. '
            . 'Abweichung = Produktiv minus Soll.';
        $html .= '</table>'
            . '<p style="color:#555;font-size:12px">' . e($erklaerung) . '</p>'
            . ($link !== null ? '<p><a href="' . e($link) . '">Zur Mitarbeiter-Statistik im HCM</a></p>' : '')
            . '</div>';
        $text .= "\n" . $erklaerung . "\n" . ($link !== null ? "\nZur Mitarbeiter-Statistik: {$link}\n" : '');

        return ['betreff' => $betreff, 'html' => $html, 'text' => $text];
    }
}
