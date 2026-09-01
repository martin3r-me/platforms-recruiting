<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upload-Slot fuer den Ersthelfer-Schein (Kundenwunsch 2026-09-01).
     * Bisher gab es nur das Bool + das Bis-Datum aus dem Arbeitsschutz-Paket
     * (2026_07_17_000001) — die Bescheinigung selbst konnte nirgends
     * hinterlegt werden. Sichtbar und im MA-Portal Pflicht (sobald
     * Ersthelfer=Ja), in der HR-Ansicht nur sichtbar. Fuer den ZAS-Export
     * bewusst NICHT relevant (RelevantFields-Listen unveraendert).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'first_aider_certificate_file_id')) {
                $table->unsignedBigInteger('first_aider_certificate_file_id')
                    ->nullable()
                    ->after('first_aider_valid_until')
                    ->comment('Ersthelfer-Schein (ContextFile-Id, nicht ZAS-relevant)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'first_aider_certificate_file_id')) {
                $table->dropColumn('first_aider_certificate_file_id');
            }
        });
    }
};
