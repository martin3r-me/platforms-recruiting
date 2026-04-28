<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the mapping column that connects a position to a `beschaftigungsort`
 * lookup value (e.g. position "Düsseldorf" → 'duesseldorf').
 *
 * The column is nullable: a null value means the position is NOT part of the
 * multi-standort pool (used e.g. for Düsseldorf-Messe and the sandbox).
 *
 * Existing positions get NULL by default, so the multi-standort filter
 * remains inactive until an admin explicitly maps a position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->string('beschaftigungsort_lookup_value', 60)
                ->nullable()
                ->after('location');
            $table->index(['beschaftigungsort_lookup_value', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->dropIndex(['beschaftigungsort_lookup_value', 'team_id']);
            $table->dropColumn('beschaftigungsort_lookup_value');
        });
    }
};
