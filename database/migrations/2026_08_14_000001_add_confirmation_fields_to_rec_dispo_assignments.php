<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bestaetigungs-Flow (Step 2 der Dispo-Reihe, Spec 2026-08-14):
     *
     *  reminder_sent_at       Bestaetigungs-WhatsApp raus (Dedup pro Person+VA:
     *                         Stempel auf ALLE Status-1-Einsaetze der VA)
     *  reminder_message_id    CommsWhatsAppMessage-ID — Zustell-Status-Anzeige.
     *                         BEWUSST ohne FK (Fremd-Modul-Tabelle, Leitplanke).
     *  confirmed_at           MA hat auf der Einsatz-Seite bestaetigt
     *  deletion_marked_at     4h-Regel (Runde 2) — zur Loeschung gemeldet
     *  deletion_confirmed_at  ZAS hat verarbeitet (Runde 2, via Folgeexport)
     */
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('missing_since');
            $table->unsignedBigInteger('reminder_message_id')->nullable()->after('reminder_sent_at');
            $table->timestamp('confirmed_at')->nullable()->after('reminder_message_id');
            $table->timestamp('deletion_marked_at')->nullable()->after('confirmed_at');
            $table->timestamp('deletion_confirmed_at')->nullable()->after('deletion_marked_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_sent_at', 'reminder_message_id', 'confirmed_at',
                'deletion_marked_at', 'deletion_confirmed_at',
            ]);
        });
    }
};
