<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            // Dauerabo-Zustand (nur Termin-Einträge): 1 = beim nächsten
            // freien Platz benachrichtigen. Wird beim Voll-Werden des
            // Termins gesetzt (Re-Arm-Hook) und beim Zustellen atomar
            // verbraucht. An Ort-Einträgen ungenutzt (Ort-Zweig läuft
            // unverändert über notified_at).
            $table->boolean('armed')->default(true)->after('notified_at');
        });

        // Backfill NUR auf der neuen Spalte (Query-Builder: keine Model-
        // Events, kein Observer, kein Dispatch — deploy-sicher):
        // V1-Termin-Einträge, die bereits benachrichtigt wurden, starten
        // entwaffnet — sie werden automatisch wieder scharf, sobald ihr
        // Termin das nächste Mal voll wird.
        DB::table('rec_interview_waitlist')
            ->whereNotNull('rec_interview_id')
            ->whereNotNull('notified_at')
            ->update(['armed' => false]);
    }

    public function down(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            $table->dropColumn('armed');
        });
    }
};
