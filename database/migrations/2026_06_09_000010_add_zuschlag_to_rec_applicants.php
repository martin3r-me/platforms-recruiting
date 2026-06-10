<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            // Zuschlag pro Bewerber (€/Std). Single Source of Truth; löst die
            // alte Kodierung über den AV-Template-Code (AV-NNN) ab.
            $table->decimal('zuschlag', 5, 2)->nullable()->after('contract_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn('zuschlag');
        });
    }
};
