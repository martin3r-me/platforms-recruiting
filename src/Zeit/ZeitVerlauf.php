<?php

namespace Platform\Recruiting\Zeit;

use Illuminate\Database\Eloquent\Model;

/** Verlaufszeile: nur anfuegen, kein updated_at. */
class ZeitVerlauf extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rec_zeit_verlauf';

    protected $guarded = [];
}
