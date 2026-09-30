<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein Waeschepaket: Name (intern) + Kleidungstext (das, was der Mitarbeiter
 * auf seiner Einsatz-Seite liest).
 *
 * Deaktivierte Pakete verschwinden nur aus der Auswahl — bestehende
 * Zuordnungen und festgeschriebene Einbuchungen zeigen sie weiter.
 */
class RecDispoDressPackage extends Model
{
    protected $table = 'rec_dispo_dress_packages';

    protected $fillable = ['uuid', 'team_id', 'name', 'items_text', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
