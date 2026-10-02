<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine an HR weitergeleitete Dispo-Nachricht (oder mehrere). Offen, solange
 * done_at leer ist. target_thread_id ist der HR-Thread — leer, bis HR die
 * Erstnachricht geschickt oder einen offenen Chat uebernommen hat.
 */
class RecConversationForward extends Model
{
    protected $table = 'rec_conversation_forwards';

    protected $fillable = [
        'team_id', 'source', 'target', 'source_thread_id', 'rec_employee_id', 'phone', 'display_name',
        'messages', 'comment', 'forwarded_by_user_id', 'forwarded_by_name', 'forwarded_at',
        'target_thread_id', 'first_contact_at', 'first_contact_by_user_id', 'last_error',
        'done_at', 'done_by_user_id',
    ];

    protected $casts = [
        'messages' => 'array',
        'forwarded_at' => 'datetime',
        'first_contact_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    // In-Memory-Defaults: Eine neu erstellte Instanz hat immer diese Werte,
    // bis fresh() aufgerufen wird. Sonst wären source/target null, bis DB gelesen wird.
    protected $attributes = [
        'source' => 'dispo',
        'target' => 'hr',
    ];

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    /** Offene Weiterleitungen EINES Ziels — jede Ansicht filtert auf ihr Ziel. */
    public function scopeOpenForTeam(Builder $query, int $teamId, string $target = 'hr'): Builder
    {
        return $query->where('team_id', $teamId)->where('target', $target)->whereNull('done_at');
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }
}
