<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Empfaenger-Auswahl fuer den Bestaetigungs-Versand einer VA (pure).
 *
 * Regeln (Spec): nur Status 1 (Auftrag); missing/zur-Loeschung-gemeldet nie;
 * bestaetigte nie erneut; bereits Angeschriebene nur mit Reminder-Flag.
 * Dedup pro Person: EINE Nachricht, assignment_ids buendeln, first_datum =
 * fruehester Einsatztag. Nichts wird still uebersprungen — alles gezaehlt.
 */
class DispoRecipientPlanner
{
    private const STATUS_AUFTRAG = 1;

    /**
     * @param list<array<string, mixed>> $assignments
     * @param array<int, ?string> $phones employee_id => Telefonnummer
     * @return array{recipients: list<array{employee_id:int, phone:string, assignment_ids:list<int>, first_datum:string, is_reminder:bool}>, skipped: array<string,int>}
     */
    public function plan(array $assignments, array $phones, bool $includeReminders): array
    {
        $skipped = [
            'wrong_status' => 0, 'missing' => 0, 'deletion_marked' => 0,
            'not_matched' => 0, 'no_phone' => 0, 'confirmed' => 0, 'already_sent' => 0,
        ];

        $byEmployee = [];
        foreach ($assignments as $a) {
            if ((int) $a['status_id'] !== self::STATUS_AUFTRAG) {
                $skipped['wrong_status']++;
                continue;
            }
            if (!empty($a['missing_since'])) {
                $skipped['missing']++;
                continue;
            }
            if (!empty($a['deletion_marked_at'])) {
                $skipped['deletion_marked']++;
                continue;
            }
            if (empty($a['employee_id'])) {
                $skipped['not_matched']++;
                continue;
            }
            $employeeId = (int) $a['employee_id'];
            if (!isset($phones[$employeeId]) || $phones[$employeeId] === null || $phones[$employeeId] === '') {
                $skipped['no_phone']++;
                continue;
            }
            if (!empty($a['confirmed_at'])) {
                $skipped['confirmed']++;
                continue;
            }
            $alreadySent = !empty($a['reminder_sent_at']);
            if ($alreadySent && !$includeReminders) {
                $skipped['already_sent']++;
                continue;
            }

            $byEmployee[$employeeId]['phone'] = $phones[$employeeId];
            $byEmployee[$employeeId]['items'][] = ['id' => (int) $a['id'], 'datum' => (string) $a['datum'], 'sent' => $alreadySent];
        }

        $recipients = [];
        foreach ($byEmployee as $employeeId => $data) {
            $ids = array_map(fn ($i) => $i['id'], $data['items']);
            $dates = array_map(fn ($i) => $i['datum'], $data['items']);
            $allSent = !in_array(false, array_map(fn ($i) => $i['sent'], $data['items']), true);

            $recipients[] = [
                'employee_id'    => $employeeId,
                'phone'          => $data['phone'],
                'assignment_ids' => $ids,
                'first_datum'    => min($dates),
                'is_reminder'    => $allSent,
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped];
    }
}
