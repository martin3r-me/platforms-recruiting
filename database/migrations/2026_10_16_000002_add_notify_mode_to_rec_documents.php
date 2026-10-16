<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haken "WhatsApp-Hinweis senden" (Sebastian 16.10.2026): je Dokument, ob es
 * zur Bereitstellung eine Portal-WhatsApp gibt. Werte whatsapp | none. Der
 * Default whatsapp laesst den Bestand unveraendert — bisher ging bei jedem
 * Dokument mit Handlung bzw. jeder Info eine WhatsApp raus. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rec_documents', 'notify_mode')) {
            return;
        }
        Schema::table('rec_documents', function (Blueprint $table) {
            $table->string('notify_mode', 20)->default('whatsapp')->after('action');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rec_documents', 'notify_mode')) {
            return;
        }
        Schema::table('rec_documents', function (Blueprint $table) {
            $table->dropColumn('notify_mode');
        });
    }
};
