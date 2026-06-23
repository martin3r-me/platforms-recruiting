<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Aggregiertes, reines DTO für die Kommunikations-Übersicht.
 * Berechnet pro Zeile den Eskalations-Zustand (ConversationEscalation),
 * zählt Kennzahlen und sortiert: verpasst zuerst (am längsten zu oben),
 * dann offene nach Fenster-Ablauf aufsteigend, beantwortete zuletzt.
 *
 * Reines DTO ohne Laravel/vendor-Abhängigkeit → unit-testbar.
 */
final class ConversationInboxReport
{
    /** @param ConversationInboxRow[] $rows */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalCount,
        public readonly int $unreadCount,
        public readonly int $greenCount,
        public readonly int $yellowCount,
        public readonly int $redCount,
        public readonly int $missedCount,
        public readonly int $escalationCount,
    ) {}

    /** Sortier-Priorität je Level (kleiner = weiter oben). */
    private const LEVEL_ORDER = [
        ConversationEscalation::LEVEL_MISSED => 0,
        ConversationEscalation::LEVEL_RED => 1,
        ConversationEscalation::LEVEL_YELLOW => 2,
        ConversationEscalation::LEVEL_GREEN => 3,
        ConversationEscalation::LEVEL_NONE => 4,
    ];

    /**
     * @param array<int, array{
     *     thread_id: int|string,
     *     subject_type?: string,
     *     subject_id?: ?int,
     *     url?: ?string,
     *     contact_name?: ?string,
     *     preview?: ?string,
     *     phone?: ?string,
     *     owner_user_id?: ?int,
     *     is_unread?: bool,
     *     last_inbound_at?: ?int,
     *     last_outbound_at?: ?int,
     * }> $rows
     */
    public static function fromRows(
        array $rows,
        int $now,
        float $yellowHoursLeft = 12.0,
        float $redHoursLeft = 3.0,
    ): self {
        $built = [];
        $unread = $green = $yellow = $red = $missed = 0;

        foreach ($rows as $row) {
            $escalation = ConversationEscalation::compute(
                $row['last_inbound_at'] ?? null,
                $row['last_outbound_at'] ?? null,
                $now,
                $yellowHoursLeft,
                $redHoursLeft,
            );

            $isUnread = (bool) ($row['is_unread'] ?? false);
            if ($isUnread) {
                $unread++;
            }

            switch ($escalation->level) {
                case ConversationEscalation::LEVEL_GREEN: $green++; break;
                case ConversationEscalation::LEVEL_YELLOW: $yellow++; break;
                case ConversationEscalation::LEVEL_RED: $red++; break;
                case ConversationEscalation::LEVEL_MISSED: $missed++; break;
            }

            $built[] = new ConversationInboxRow(
                threadId: $row['thread_id'],
                subjectType: (string) ($row['subject_type'] ?? 'applicant'),
                subjectId: $row['subject_id'] ?? null,
                url: $row['url'] ?? null,
                contactName: (string) ($row['contact_name'] ?? 'Unbekannt'),
                firstName: $row['first_name'] ?? null,
                preview: $row['preview'] ?? null,
                phone: $row['phone'] ?? null,
                ownerUserId: $row['owner_user_id'] ?? null,
                isUnread: $isUnread,
                escalation: $escalation,
            );
        }

        usort($built, static function (ConversationInboxRow $a, ConversationInboxRow $b): int {
            $orderA = self::LEVEL_ORDER[$a->escalation->level] ?? 9;
            $orderB = self::LEVEL_ORDER[$b->escalation->level] ?? 9;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            // Innerhalb gleicher Stufe: nach Fenster-Ablauf aufsteigend
            // (verpasst → am längsten zu oben; offen → läuft als nächstes ab oben).
            $expA = $a->escalation->windowExpiresAt ?? PHP_INT_MAX;
            $expB = $b->escalation->windowExpiresAt ?? PHP_INT_MAX;
            return $expA <=> $expB;
        });

        return new self(
            rows: $built,
            totalCount: count($built),
            unreadCount: $unread,
            greenCount: $green,
            yellowCount: $yellow,
            redCount: $red,
            missedCount: $missed,
            escalationCount: $red + $missed,
        );
    }
}
