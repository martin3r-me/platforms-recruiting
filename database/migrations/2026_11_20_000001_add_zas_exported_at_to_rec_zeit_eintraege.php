<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zeiten an ZAS (Sebastian/Olaf 20.11.2026): jeder erledigte Zeiteintrag geht
 * genau einmal raus. Der Abruf beansprucht die Zeile ueber diesen Marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rec_zeit_eintraege') || Schema::hasColumn('rec_zeit_eintraege', 'zas_exported_at')) {
            return;
        }
        Schema::table('rec_zeit_eintraege', function (Blueprint $table) {
            $table->dateTime('zas_exported_at')->nullable()->after('gesperrt_grund');
            $table->index('zas_exported_at', 'rec_zeit_eintraege_zas_exported_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rec_zeit_eintraege', 'zas_exported_at')) {
            return;
        }
        Schema::table('rec_zeit_eintraege', function (Blueprint $table) {
            $table->dropIndex('rec_zeit_eintraege_zas_exported_idx');
            $table->dropColumn('zas_exported_at');
        });
    }
};
