<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weiterleitung HR -> Dispo (Spec 16.10.2026): das Ziel ist ein bestimmter
 * Dispo-KANAL (eine Filiale), nicht "die Dispo". Leer bei Zielen ohne Kanal
 * (HR). Kein Fremdschluessel ueber die Modulgrenze (comms_channels liegt im CRM).
 *
 * Nummer 100001 statt 000001: feat/ma-konto belegt 2026_10_16_000001..000003.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_conversation_forwards', function (Blueprint $table) {
            $table->unsignedBigInteger('target_channel_id')->nullable()->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('rec_conversation_forwards', function (Blueprint $table) {
            $table->dropColumn('target_channel_id');
        });
    }
};
