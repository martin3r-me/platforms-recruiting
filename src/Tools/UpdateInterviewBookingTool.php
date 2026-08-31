<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateInterviewBookingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interview_bookings.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/interview-bookings/{id} - Aktualisiert eine Interview-Buchung (Status, Notizen). Parameter: booking_id (required). Status-Workflow: registered → confirmed → attended/cancelled/no_show.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
                'booking_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Buchung (ERFORDERLICH).',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Neuer Status (registered/confirmed/attended/cancelled/rejected_on_site/no_show).',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen.',
                ],
            ],
            'required' => ['booking_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $found = $this->validateAndFindModel($arguments, $context, 'booking_id', RecInterviewBooking::class, 'NOT_FOUND', 'Buchung nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $booking = $found['model'];

            if ((int)$booking->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf diese Buchung.');
            }

            if (isset($arguments['status'])) {
                $validStatuses = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show', 'rejected_on_site'];
                if (!in_array($arguments['status'], $validStatuses)) {
                    return ToolResult::error('VALIDATION_ERROR', 'Ungültiger Status. Erlaubt: ' . implode(', ', $validStatuses));
                }

                // Standby-Buchung wird manuell hochgestuft = bewusste HR-Uebersteuerung
                // (kein Kapazitaetsblock, aber nachvollziehbar im AutoPilot-Log).
                if ($booking->is_standby && !in_array($arguments['status'], ['booked', 'cancelled'], true)) {
                    try {
                        RecAutoPilotLog::create([
                            'rec_applicant_id' => $booking->rec_applicant_id,
                            'type' => 'seat_reclaimed_override',
                            'summary' => "Standby-Buchung #{$booking->id} manuell auf '{$arguments['status']}' gesetzt — Platz bewusst konsumiert (HR).",
                            'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'status' => $arguments['status']],
                        ]);
                    } catch (\Throwable) {}
                }

                $booking->status = $arguments['status'];
            }

            if (array_key_exists('notes', $arguments)) {
                $booking->notes = $arguments['notes'] === '' ? null : $arguments['notes'];
            }

            $booking->save();

            return ToolResult::success([
                'id' => $booking->id,
                'uuid' => $booking->uuid,
                'status' => $booking->status,
                'message' => 'Buchung erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Buchung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'interview_bookings', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
