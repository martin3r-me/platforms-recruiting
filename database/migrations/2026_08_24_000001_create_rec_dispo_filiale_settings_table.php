<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-Filiale-Konfiguration fuer Runde 2: Versand-Kanal + Diensthandy je Filialnummer.
    public function up(): void
    {
        Schema::create('rec_dispo_filiale_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedInteger('filial_nr');
            $table->unsignedBigInteger('comms_channel_id')->nullable(); // Filial-Kanal; leer -> Fallback Default
            $table->string('duty_phone')->nullable();                   // Ziel 16-Uhr-Alarm
            $table->timestamps();
            $table->unique(['team_id', 'filial_nr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_filiale_settings');
    }
};
