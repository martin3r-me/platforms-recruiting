<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Herkunfts-/Provenienz-Marker: aus welcher ZAS-Inbound-Lieferung wurde
     * dieser MA angelegt. NULL = nicht aus ZAS importiert (z.B. Recruiting-Anlage).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'rec_zas_inbound_file_id')) {
                $table->unsignedBigInteger('rec_zas_inbound_file_id')->nullable()->after('zas_id');
                $table->index('rec_zas_inbound_file_id', 'idx_rec_employees_zas_inbound_file');
                $table->foreign('rec_zas_inbound_file_id')
                    ->references('id')->on('rec_zas_inbound_files')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'rec_zas_inbound_file_id')) {
                $table->dropForeign('rec_employees_rec_zas_inbound_file_id_foreign');
                $table->dropIndex('idx_rec_employees_zas_inbound_file');
                $table->dropColumn('rec_zas_inbound_file_id');
            }
        });
    }
};
