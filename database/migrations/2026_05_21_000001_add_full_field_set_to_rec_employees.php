<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iteration 2 — vollstaendiges Field-Set am Mitarbeiter.
     *
     * Ergaenzt das MVP-Schema (2026_05_20_000001) um:
     *  - Stammdaten-Ergaenzungen (Pflichtfelder aus P3 die im MVP fehlten)
     *  - Optionale Felder die aus P3 ins MA-Portal gewandert sind
     *
     * Alle Spalten nullable damit bestehende RecEmployee-Rows nicht
     * brechen. Lookup-Werte werden als String (Lookup-Code) gespeichert
     * — konsistent mit dem existing Pattern fuer beschaftigungsort.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            // --- Stammdaten-Ergaenzung (Pflichtfelder aus P3) ---
            if (!Schema::hasColumn('rec_employees', 'house_number')) {
                $table->string('house_number', 16)->nullable()->after('street');
            }
            if (!Schema::hasColumn('rec_employees', 'birth_name')) {
                $table->string('birth_name', 120)->nullable()->after('last_name');
            }
            if (!Schema::hasColumn('rec_employees', 'birth_place')) {
                $table->string('birth_place', 120)->nullable()->after('birth_date');
            }
            if (!Schema::hasColumn('rec_employees', 'identity_card_valid_until')) {
                $table->date('identity_card_valid_until')->nullable()->after('identity_card_number');
            }
            if (!Schema::hasColumn('rec_employees', 'identity_card_front_file_id')) {
                $table->unsignedBigInteger('identity_card_front_file_id')->nullable()->after('identity_card_valid_until');
            }
            if (!Schema::hasColumn('rec_employees', 'identity_card_back_file_id')) {
                $table->unsignedBigInteger('identity_card_back_file_id')->nullable()->after('identity_card_front_file_id');
            }
            if (!Schema::hasColumn('rec_employees', 'selfie_file_id')) {
                $table->unsignedBigInteger('selfie_file_id')->nullable()->after('identity_card_back_file_id');
            }

            // --- Optionale Felder (aus P3 ins MA-Portal gewandert) ---
            if (!Schema::hasColumn('rec_employees', 'gender')) {
                $table->string('gender', 32)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'marital_status')) {
                $table->string('marital_status', 32)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'health_insurance')) {
                $table->string('health_insurance', 64)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'birth_country')) {
                $table->string('birth_country', 64)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'employment_type')) {
                $table->string('employment_type', 64)->nullable()->comment('ich_bin: werkstudent / minijob / etc.');
            }
            if (!Schema::hasColumn('rec_employees', 'health_insurance_card_file_id')) {
                $table->unsignedBigInteger('health_insurance_card_file_id')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'bank_institute')) {
                $table->string('bank_institute', 120)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'drivers_license_class')) {
                $table->string('drivers_license_class', 32)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'has_car')) {
                $table->boolean('has_car')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'recruited_by_personnel_number')) {
                $table->string('recruited_by_personnel_number', 64)->nullable()->comment('geworben_von: Personalnummer des werbenden MA');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $cols = [
                'house_number', 'birth_name', 'birth_place',
                'identity_card_valid_until', 'identity_card_front_file_id',
                'identity_card_back_file_id', 'selfie_file_id',
                'gender', 'marital_status', 'health_insurance', 'birth_country',
                'employment_type', 'health_insurance_card_file_id',
                'bank_institute', 'drivers_license_class', 'has_car',
                'recruited_by_personnel_number',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('rec_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
