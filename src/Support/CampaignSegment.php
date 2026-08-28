<?php

namespace Platform\Recruiting\Support;

/**
 * Kampagne „Neue Termine" (Spec §5.2): entscheidet pro Bewerber, welches
 * Template rausgeht, ob er vorausgewaehlt ist und welche Badges HR sieht.
 *
 * Pure Entscheidungslogik ohne Framework (Muster SeatStandbyPolicy). Die
 * Eloquent-Seite (NewDatesCampaignRecipients) baut die Eingabe, diese Klasse
 * kennt weder Modelle noch MGL-Phasen-IDs — nur die Lage relativ zum
 * Buchungsschritt der Stelle. Damit gilt dieselbe Regel fuer jede Filiale.
 */
final class CampaignSegment
{
    /** Template A: Bewerbung vervollstaendigen (URL-Button → /form/{token}). */
    public const TEMPLATE_FORM = 'A';

    /** Template B: Terminauswahl (URL-Button → /recruiting/interviews/{token}). */
    public const TEMPLATE_BOOKING = 'B';

    /** Wer in diesem Fenster schon eine Kampagne bekam, ist default abgehakt. */
    public const RECENT_CAMPAIGN_DAYS = 14;

    /**
     * Ordnungszahl des Buchungsschritts der Stelle.
     *  - erste aktive Phase mit completion_type 'booking' → deren order
     *  - sonst erste aktive Phase mit completion_config.send_booking_notification_on_completion → order + 1
     *    (Legacy-Stellen: Buchungslink kommt am Ende dieser Phase)
     *  - sonst letzte aktive Phase + 1
     *  - keine Phasen → 1 (alles gilt als „vor dem Buchungsschritt" = Template A)
     *
     * @param list<array{order:int, completion_type:?string, completion_config:?array, is_active:bool}> $phases
     */
    public static function bookingOrder(array $phases): int
    {
        $active = array_values(array_filter($phases, fn (array $p) => ($p['is_active'] ?? true) === true));
        usort($active, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach ($active as $p) {
            if (($p['completion_type'] ?? null) === 'booking') {
                return (int) $p['order'];
            }
        }
        foreach ($active as $p) {
            if ((($p['completion_config'] ?? [])['send_booking_notification_on_completion'] ?? false) === true) {
                return (int) $p['order'] + 1;
            }
        }
        if ($active === []) {
            return 1;
        }

        return (int) end($active)['order'] + 1;
    }

    /**
     * @param array{
     *   phase_order:?int, booking_order:int, has_phone:bool, has_active_booking:bool,
     *   on_hr_desk:bool, last_campaign_at:?string, now:string,
     *   cancelled_bookings:list<array{cancelled_by:?string, cancelled_at:?string}>,
     *   waitlist:?array{enrolled_at:?string, notified_at:?string}
     * } $in
     * @return array{template:string, selectable:bool, checked:bool, badges:list<string>}
     */
    public static function classify(array $in): array
    {
        $order = (int) ($in['phase_order'] ?? 0);
        $bookingOrder = (int) $in['booking_order'];
        $badges = [];
        $checked = true;
        $selectable = true;

        if ($order < $bookingOrder) {
            $template = self::TEMPLATE_FORM;
            $badges[] = 'Bewerbung unvollständig';
        } else {
            $template = self::TEMPLATE_BOOKING;
            $storno = self::juengsterStorno($in['cancelled_bookings'] ?? []);
            if ($order === $bookingOrder + 1 && $storno !== null) {
                $badges[] = 'Storniert am ' . self::datum($storno['cancelled_at'])
                    . ' (' . self::akteur($storno['cancelled_by']) . ')';
            } elseif ($order >= $bookingOrder + 2) {
                // Zwei Phasen hinter dem Buchungsschritt (MGL: P4) — Daten
                // komplett, Buchung weg. Zu 73 % selbst storniert (Analyse
                // 28.08.), deshalb sichtbar, aber nicht vorausgewaehlt.
                $checked = false;
                if ($storno === null) {
                    $badges[] = 'Termin storniert';
                } elseif ($storno['cancelled_by'] === 'applicant') {
                    $badges[] = 'Termin selbst storniert am ' . self::datum($storno['cancelled_at']);
                } else {
                    $badges[] = 'HR-Storno am ' . self::datum($storno['cancelled_at']);
                }
            }
        }

        // Ueberlagerungen — Reihenfolge ist die Anzeige-Reihenfolge.
        if (($in['has_phone'] ?? false) !== true) {
            $selectable = false;
            $checked = false;
            $badges[] = 'kein Telefon';
        }
        if (($in['has_active_booking'] ?? false) === true) {
            $selectable = false;
            $checked = false;
            $badges[] = 'hat inzwischen gebucht';
        }
        if (($in['on_hr_desk'] ?? false) === true) {
            $checked = false;
            $badges[] = 'HR-Schreibtisch';
        }
        if (!empty($in['last_campaign_at'])) {
            $last = new \DateTimeImmutable($in['last_campaign_at']);
            $now = new \DateTimeImmutable($in['now']);
            if ($last > $now->modify('-' . self::RECENT_CAMPAIGN_DAYS . ' days')) {
                $checked = false;
            }
            $badges[] = 'angeschrieben am ' . self::datum($in['last_campaign_at']);
        }
        if (!empty($in['waitlist'])) {
            $text = 'Warteliste seit ' . self::datum($in['waitlist']['enrolled_at'] ?? null);
            if (!empty($in['waitlist']['notified_at'])) {
                $text .= ', benachrichtigt am ' . self::datum($in['waitlist']['notified_at']);
            }
            $badges[] = $text;
        }

        return [
            'template' => $template,
            'selectable' => $selectable,
            'checked' => $checked,
            'badges' => $badges,
        ];
    }

    /**
     * Schnitt aus Client-Auswahl, Kohorte und waehlbaren Zeilen. Der Client
     * darf nur ankreuzen, was das Modal zeigt UND was waehlbar ist — alles
     * andere wird still verworfen (Muster resolveIdsFromClient: Eingabe von
     * draussen heisst leere Menge, nicht Fehler).
     *
     * @param array<int|string,bool> $selection
     * @param list<int> $drillIds
     * @param list<int> $selectableIds
     * @return list<int>
     */
    public static function selectedIds(array $selection, array $drillIds, array $selectableIds): array
    {
        $allowed = array_intersect(array_map('intval', $drillIds), array_map('intval', $selectableIds));
        $out = [];
        foreach ($selection as $id => $on) {
            if ($on === true && in_array((int) $id, $allowed, true)) {
                $out[] = (int) $id;
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * A/B-Aufteilung der gewaehlten IDs nach Template — fuer die Button-Sperre
     * und den Zaehler im Statistik-Modal. IDs, die in $rows nicht vorkommen
     * (z. B. inzwischen aus der Kohorte gefallen), werden ignoriert — dieselbe
     * fail-closed-Regel wie selectedIds().
     *
     * @param array<int, array{template:string}> $rows applicant_id => Zeile (mind. 'template')
     * @param list<int> $selectedIds
     * @return array{A:int, B:int, total:int}
     */
    public static function countsByTemplate(array $rows, array $selectedIds): array
    {
        $a = 0;
        $b = 0;
        foreach ($selectedIds as $id) {
            if (!array_key_exists($id, $rows)) {
                continue;
            }
            if (($rows[$id]['template'] ?? '') === self::TEMPLATE_FORM) {
                $a++;
            } else {
                $b++;
            }
        }

        return ['A' => $a, 'B' => $b, 'total' => $a + $b];
    }

    /** @param list<array{cancelled_by:?string, cancelled_at:?string}> $stornos */
    private static function juengsterStorno(array $stornos): ?array
    {
        $best = null;
        foreach ($stornos as $s) {
            if ($best === null || (string) ($s['cancelled_at'] ?? '') > (string) ($best['cancelled_at'] ?? '')) {
                $best = $s;
            }
        }

        return $best;
    }

    private static function akteur(?string $cancelledBy): string
    {
        return match ($cancelledBy) {
            'applicant' => 'Bewerber',
            'hr' => 'HR',
            'system' => 'System',
            default => 'unbekannt',
        };
    }

    private static function datum(?string $ymdHis): string
    {
        if ($ymdHis === null || $ymdHis === '') {
            return '–';
        }

        return (new \DateTimeImmutable($ymdHis))->format('d.m.Y');
    }
}
