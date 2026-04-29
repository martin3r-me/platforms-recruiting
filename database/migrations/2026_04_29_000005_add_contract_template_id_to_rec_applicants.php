<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add contract_template_id FK to rec_applicants. The training leader
     * (Schulungsleiter) selects this in the Schulungsnachbereitung — it
     * decides which Arbeitsvertrag-Variante (e.g. AV-060 = 0,60€ Zuschlag)
     * is generated when "Verträge versenden" is triggered.
     *
     * IFSG is auto-attached at contract-creation time, no setting needed.
     */
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('contract_template_id')
                ->nullable()
                ->after('source_platform_id')
                ->constrained('rec_contract_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropForeign(['contract_template_id']);
            $table->dropColumn('contract_template_id');
        });
    }
};
