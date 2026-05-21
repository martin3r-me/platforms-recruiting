<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iteration 3 — vollstaendiges HR-Field-Set.
     *
     * rec_employees bekommt 12 neue Spalten die im MA-Portal sichtbar
     * sind (MA kann sie selbst nachpflegen).
     *
     * rec_employee_hr_data bekommt 5 neue HR-only-Spalten (Vertrag-
     * Snapshots fuer ZAS-Export + Status + rechtliche Anstellungsart).
     *
     * Alle Spalten nullable bis auf Defaults (export_status='GO',
     * employment_classification='kurzfristig').
     */
    public function up(): void
    {
        // --- rec_employees: MA-sichtbare Felder ---
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'tax_class')) {
                $table->string('tax_class', 1)->nullable()->comment('Lohnsteuerklasse 1-6');
            }
            if (!Schema::hasColumn('rec_employees', 'number_of_children')) {
                $table->unsignedSmallInteger('number_of_children')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'account_holder')) {
                $table->string('account_holder', 120)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'religion')) {
                $table->string('religion', 32)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'school_certificate_valid_until')) {
                $table->date('school_certificate_valid_until')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'has_infection_protection_certificate')) {
                $table->boolean('has_infection_protection_certificate')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'infection_protection_first_issued_at')) {
                $table->date('infection_protection_first_issued_at')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'shirt_size')) {
                $table->string('shirt_size', 8)->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'pants_size')) {
                $table->unsignedSmallInteger('pants_size')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'shoe_size')) {
                $table->unsignedSmallInteger('shoe_size')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'residence_permit_valid_until')) {
                $table->date('residence_permit_valid_until')->nullable()->comment('Aufenthaltserlaubnis bis (non-EU)');
            }
            if (!Schema::hasColumn('rec_employees', 'work_permit_valid_until')) {
                $table->date('work_permit_valid_until')->nullable()->comment('Arbeitsgenehmigung bis (non-EU)');
            }
        });

        // --- rec_employee_hr_data: HR-only-Felder ---
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'contract_signed_at')) {
                $table->date('contract_signed_at')->nullable()->comment('Vertrag zurueck am');
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'contract_sent_date')) {
                $table->date('contract_sent_date')->nullable()->comment('Vertrags-Datum');
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'contract_end_date')) {
                $table->date('contract_end_date')->nullable()->comment('Befristet bis');
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'export_status')) {
                $table->string('export_status', 8)->default('GO')->comment('Status — immer GO solange MA');
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'employment_classification')) {
                $table->string('employment_classification', 32)->default('kurzfristig')->comment('Anstellungsart');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $cols = [
                'tax_class', 'number_of_children', 'account_holder', 'religion',
                'school_certificate_valid_until', 'has_infection_protection_certificate',
                'infection_protection_first_issued_at',
                'shirt_size', 'pants_size', 'shoe_size',
                'residence_permit_valid_until', 'work_permit_valid_until',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('rec_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $cols = ['contract_signed_at', 'contract_sent_date', 'contract_end_date', 'export_status', 'employment_classification'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('rec_employee_hr_data', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
