<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertrag an der Anstellung (Spec 2026-10-07, §3.1).
 *
 * rec_contracts.rec_employee_id: die Anstellung (rec_employees), zu der der
 * Vertrag gehoert. Nullbar — im Bewerberstadium gibt es noch keine. BEWUSST
 * KEIN Fremdschluessel: eine rec_employees-Zeile kann aus dem ZAS-Bestand neu
 * entstehen oder ersetzt werden, der Vertrag ueberlebt das; nullOnDelete
 * wiederholte das change()/MySQL-Thema von 2026_10_01_000002 auf feat/ma-konto.
 * Verwaiste Verweise nennt recruiting:vertraege-an-anstellung.
 *
 * rec_contract_templates.company: fuer welche GmbH ein Vertrag aus dieser
 * Vorlage gilt — Werte wie rec_employees.company (RG/MA). Default 'RG' fuellt
 * den Bestand (Kunde 05.10.: alle vorhandenen Vertraege sind RG-Vertraege).
 * rec_contract_templates.taetigkeit: freier Schluessel ('eventmitarbeiter'),
 * NULL bei Belehrung/Zusatzvereinbarung. Schritt 0 des Backfills setzt ihn.
 *
 * Guards pro DDL-Operation wie in 2026_08_12_000001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contracts', 'rec_employee_id')) {
                $table->unsignedBigInteger('rec_employee_id')->nullable()->after('rec_applicant_id');
            }
            if (!Schema::hasIndex('rec_contracts', 'rec_contracts_rec_employee_id_index')) {
                $table->index('rec_employee_id');
            }
        });

        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contract_templates', 'company')) {
                $table->string('company', 10)->default('RG')->after('type');
            }
            if (!Schema::hasColumn('rec_contract_templates', 'taetigkeit')) {
                $table->string('taetigkeit', 50)->nullable()->after('company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            if (Schema::hasIndex('rec_contracts', 'rec_contracts_rec_employee_id_index')) {
                $table->dropIndex('rec_contracts_rec_employee_id_index');
            }
            if (Schema::hasColumn('rec_contracts', 'rec_employee_id')) {
                $table->dropColumn('rec_employee_id');
            }
        });
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            foreach (['taetigkeit', 'company'] as $column) {
                if (Schema::hasColumn('rec_contract_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
