<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-phase completion configuration:
     *  - completion_type:   'fields' (default, current behaviour),
     *                       'booking' (advance when matching booking exists),
     *                       'manual' (HR has to advance manually)
     *  - completion_config: optional JSON for type-specific settings,
     *                       e.g. {"interview_type_code": "training"} for booking phases
     *
     * Existing phases get the default 'fields' value, so behaviour is unchanged.
     */
    public function up(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            $table->string('completion_type', 20)
                ->default('fields')
                ->after('auto_advance');
            $table->json('completion_config')
                ->nullable()
                ->after('completion_type');
        });
    }

    public function down(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            $table->dropColumn(['completion_type', 'completion_config']);
        });
    }
};
