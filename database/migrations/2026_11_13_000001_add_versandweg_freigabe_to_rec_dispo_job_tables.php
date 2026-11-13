<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs anbieten — Versandweg (WhatsApp oder Portal), Freigabe, Kanal je Angebot (Markus 13.11.2026).
 * Defaults halten den Bestand auf WhatsApp ohne Freigabe: Verhalten wie bisher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_requests', function (Blueprint $t) {
            $t->string('versandweg', 16)->default('whatsapp')->after('anlass');
            $t->boolean('freigabe_pflicht')->default(false)->after('versandweg');
            $t->timestamp('freigabe_beantragt_at')->nullable()->after('freigabe_pflicht');
            $t->unsignedBigInteger('freigabe_beantragt_by_user_id')->nullable()->after('freigabe_beantragt_at');
            $t->timestamp('freigegeben_at')->nullable()->after('freigabe_beantragt_by_user_id');
            $t->unsignedBigInteger('freigegeben_by_user_id')->nullable()->after('freigegeben_at');
            $t->timestamp('freigabe_abgelehnt_at')->nullable()->after('freigegeben_by_user_id');
            $t->unsignedBigInteger('freigabe_abgelehnt_by_user_id')->nullable()->after('freigabe_abgelehnt_at');
            $t->string('freigabe_ablehnungsgrund', 255)->nullable()->after('freigabe_abgelehnt_by_user_id');
        });
        Schema::table('rec_dispo_job_offers', function (Blueprint $t) {
            $t->string('kanal', 16)->default('whatsapp')->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_offers', fn (Blueprint $t) => $t->dropColumn('kanal'));
        Schema::table('rec_dispo_job_requests', fn (Blueprint $t) => $t->dropColumn([
            'versandweg', 'freigabe_pflicht', 'freigabe_beantragt_at', 'freigabe_beantragt_by_user_id',
            'freigegeben_at', 'freigegeben_by_user_id', 'freigabe_abgelehnt_at', 'freigabe_abgelehnt_by_user_id',
            'freigabe_ablehnungsgrund',
        ]));
    }
};
