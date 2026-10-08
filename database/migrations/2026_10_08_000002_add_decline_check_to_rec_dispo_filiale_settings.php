<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Absage-Erkennung (Kunde 08.10.): gesetzt = an. Der Zeitpunkt ist zugleich
    // die Untergrenze — geprueft wird nur, was NACH dem Einschalten eingeht.
    public function up(): void
    {
        Schema::table('rec_dispo_filiale_settings', function (Blueprint $table) {
            $table->timestamp('decline_check_enabled_at')->nullable()->after('duty_phone');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_filiale_settings', function (Blueprint $table) {
            $table->dropColumn('decline_check_enabled_at');
        });
    }
};
