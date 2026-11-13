<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-Push-Abos der Mitarbeiter (Markus 13.11.2026). Ein Geraet = ein Endpunkt;
 * der Hash macht den langen Endpunkt eindeutig pruefbar. Keine Fremdschluessel ueber Modulgrenzen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_push_abos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('rec_employee_id')->index();
            $t->text('endpoint');
            $t->char('endpoint_hash', 64)->unique();
            $t->string('p256dh', 255);
            $t->string('auth', 255);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_push_abos');
    }
};
