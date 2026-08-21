<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verweis vom ersetzten auf den ersetzenden Vertrag.
     *
     * Gesetzt wird die Spalte ausschliesslich von ReissueContractService:
     * wenn HR einen unterschriebenen Arbeitsvertrag neu ausstellt (typisch
     * ein falsch angesetzter Zuschlag), bleibt der alte Vertrag als
     * `completed` mit Unterschrift und PDF stehen — er IST passiert und ist
     * der Archivbeleg. Diese Spalte sagt, welcher Vertrag ihn abgeloest hat.
     *
     * Bewusst kein FK-constrained(): der Nachfolger gehoert demselben
     * Bewerber, cascadeOnDelete auf rec_contracts wuerde beim Loeschen des
     * Nachfolgers den Vorgaenger mitnehmen — also genau das Archivstueck,
     * das hier geschuetzt werden soll. nullOnDelete waere korrekt, kostet
     * aber einen Constraint fuer eine Beziehung, die nur gelesen wird.
     */
    public function up(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('superseded_by_contract_id')
                ->nullable()
                ->after('notes');
            $table->index('superseded_by_contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->dropIndex(['superseded_by_contract_id']);
            $table->dropColumn('superseded_by_contract_id');
        });
    }
};
