<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-Zuschlag, von HR eingetragen (Spec Lohn MA 2026-10-19 §5): fuer
 * RG-Anstellungen OHNE Bewerbung (ZAS-Bestand) kennen wir den Zuschlag
 * nicht, ZAS liefert ihn nicht zurueck. Ohne ihn kann der MA-Zuschlag der
 * Person nicht angeglichen werden und bleibt im Export leer. Rein additiv,
 * nullable, Bestand unveraendert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rec_employees', 'zuschlag_manuell')) {
            return;
        }
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->decimal('zuschlag_manuell', 5, 2)->nullable()->after('tax_class');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rec_employees', 'zuschlag_manuell')) {
            return;
        }
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('zuschlag_manuell');
        });
    }
};
