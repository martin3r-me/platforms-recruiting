<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecServiceHours extends Model
{
    protected $table = 'rec_service_hours';

    protected $fillable = [
        'uuid',
        'rec_applicant_settings_id',
        'name',
        'description',
        'is_active',
        'service_hours',
        'auto_message_inside',
        'auto_message_outside',
        'use_auto_messages',
        'order',
    ];

    protected $casts = [
        'uuid' => 'string',
        'is_active' => 'boolean',
        'service_hours' => 'array',
        'use_auto_messages' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $uuid;
        });
    }

    public function applicantSettings(): BelongsTo
    {
        return $this->belongsTo(RecApplicantSettings::class, 'rec_applicant_settings_id');
    }

    /**
     * Prüft, ob aktuell Service Hours aktiv sind
     */
    public function isCurrentlyActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->service_hours || empty($this->service_hours)) {
            return true;
        }

        $now = now();
        $dayOfWeek = $now->dayOfWeek;
        $time = $now->format('H:i');

        foreach ($this->service_hours as $schedule) {
            if ($schedule['day'] == $dayOfWeek) {
                if ($time >= $schedule['start'] && $time <= $schedule['end']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gibt die passende Auto-Nachricht zurück
     */
    public function getAutoMessage(): ?string
    {
        if (!$this->use_auto_messages) {
            return null;
        }

        if ($this->isCurrentlyActive()) {
            return $this->auto_message_inside;
        } else {
            return $this->auto_message_outside;
        }
    }

    /**
     * Erstellt Standard-Service-Hours für Mo-Fr 9-17 Uhr
     */
    public static function getDefaultServiceHours(): array
    {
        return [
            [
                'day' => 1,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => true
            ],
            [
                'day' => 2,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => true
            ],
            [
                'day' => 3,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => true
            ],
            [
                'day' => 4,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => true
            ],
            [
                'day' => 5,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => true
            ],
            [
                'day' => 6,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => false
            ],
            [
                'day' => 0,
                'start' => '09:00',
                'end' => '17:00',
                'enabled' => false
            ]
        ];
    }

    /**
     * Gibt die Wochentage als Array zurück
     */
    public static function getWeekDays(): array
    {
        return [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            0 => 'Sonntag'
        ];
    }

    /**
     * Formatiert die Service Hours für die Anzeige
     */
    public function getFormattedSchedule(): string
    {
        if (!$this->service_hours || empty($this->service_hours)) {
            return '24/7 verfügbar';
        }

        $enabledDays = collect($this->service_hours)->filter(fn($day) => $day['enabled'] ?? false);

        if ($enabledDays->isEmpty()) {
            return 'Nicht verfügbar';
        }

        $weekDays = self::getWeekDays();
        $formatted = [];

        foreach ($enabledDays as $day) {
            $dayName = $weekDays[$day['day']] ?? 'Unbekannt';
            $time = $day['start'] . ' - ' . $day['end'];
            $formatted[] = "$dayName: $time";
        }

        return implode(', ', $formatted);
    }
}
