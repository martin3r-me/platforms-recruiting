<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

class RecSourcePlatform extends Model
{
    protected $table = 'rec_source_platforms';

    protected $fillable = [
        'uuid', 'team_id', 'name', 'url', 'match_pattern', 'is_active', 'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function applicants()
    {
        return $this->hasMany(RecApplicant::class, 'source_platform_id');
    }

    /**
     * Match the given sender address against active source platforms for the
     * team. Longer (more specific) patterns win first; priority is the
     * tiebreaker for equally long patterns.
     *
     * Pattern matching is a simple case-insensitive substring check on the
     * sender — works for both domain-only patterns ("@indeedemail.com") and
     * full addresses ("website@mitarbeiter.rheingedeck.de").
     */
    public static function detectFromSender(?string $senderAddress, int $teamId): ?self
    {
        $needle = mb_strtolower(trim((string) $senderAddress));
        if ($needle === '') {
            return null;
        }

        return static::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderByRaw('LENGTH(match_pattern) DESC')
            ->orderBy('priority')
            ->get()
            ->first(fn (self $source) => str_contains($needle, mb_strtolower($source->match_pattern)));
    }
}
