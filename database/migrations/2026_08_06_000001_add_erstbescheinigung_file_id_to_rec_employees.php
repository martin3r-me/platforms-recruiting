<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upload-Slot fuer die IFSG-Erstbescheinigung (Gesundheitsamt/Arzt).
     * Bisher gab es nur das Bestaetigungs-Bool + Datum
     * (infection_protection_first_issued_at) — die Datei selbst konnte
     * nirgends hinterlegt werden. Sichtbar im MA-Portal und in der
     * HR-Ansicht; fuer den ZAS-Export bewusst NICHT relevant
     * (RelevantFields-Listen unveraendert).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('erstbescheinigung_file_id')
                ->nullable()
                ->after('infection_protection_first_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('erstbescheinigung_file_id');
        });
    }
};
