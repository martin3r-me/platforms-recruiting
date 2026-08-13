<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schalter "manuelles Einbuchen erlaubt" pro Phase. HR darf Bewerber aus Phasen
 * mit diesem Flag von Hand in Schulungstermine buchen und umbuchen. Vorher hing
 * der Buchungs-Dialog an auto_pilot_completed_at — das war die alte
 * 2-Phasen-Logik, in der Phase 2 die LETZTE Phase war und ihr Abschluss
 * "fertig fuer die Schulung" bedeutete. Bei den heutigen 4-Phasen-Stellen
 * bedeutet derselbe Wert "Vertraege sind raus", also genau die Bewerber, die
 * man NICHT mehr einbuchen will.
 *
 * Bewusst eine echte Spalte und kein Key in auto_pilot_settings/completion_config:
 * der Dialog filtert per DB-Query ueber rec_phases. Ein JSON-Pfad-Vergleich waere
 * dort nicht indexierbar und bei Checkbox-Werten (true vs. "1") fehleranfaellig.
 *
 * NOT NULL mit Default false: ein dritter Zustand "unbekannt" muesste in jeder
 * Query mitgedacht werden. Der Bestand wird durch den Default korrekt zu false;
 * die fuenf Live-Stellen schaltet recruiting:enable-manual-booking scharf.
 *
 * Idempotenz ueber Live-Guards: pro DDL-Operation ein eigener hasColumn-Check
 * (Muster 2026_08_12_000001_add_type_to_rec_contract_templates.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_phases', 'allow_manual_booking')) {
                $table->boolean('allow_manual_booking')->default(false)->after('auto_advance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            if (Schema::hasColumn('rec_phases', 'allow_manual_booking')) {
                $table->dropColumn('allow_manual_booking');
            }
        });
    }
};
