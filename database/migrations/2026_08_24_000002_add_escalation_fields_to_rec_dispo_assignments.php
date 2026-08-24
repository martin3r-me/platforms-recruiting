<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stufen-Status + verknuepfte Nachrichten fuer die Eskalation (idempotent + Fehler-Anzeige).
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('escalation_1_at')->nullable()->after('reminder_message_id');
            $table->timestamp('escalation_2_at')->nullable()->after('escalation_1_at');
            $table->unsignedBigInteger('escalation_1_message_id')->nullable()->after('escalation_2_at');
            $table->unsignedBigInteger('escalation_2_message_id')->nullable()->after('escalation_1_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['escalation_1_at', 'escalation_2_at', 'escalation_1_message_id', 'escalation_2_message_id']);
        });
    }
};
