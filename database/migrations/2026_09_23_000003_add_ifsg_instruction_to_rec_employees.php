<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // IfSG-Belehrung am Mitarbeiter (Befund 23.09.2026). Bisher rechnete der
    // ZAS-Export FolgeBescheinigungAm/InfekGueltigBis aus dem unterschriebenen
    // IfSG-Vertrag der BEWERBUNG — ZAS-Bestandsmitarbeiter haben keine, ZAS
    // liefert die Werte aber zu 86 %. Reines HR-Feld, nicht im Portal.
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'infection_protection_instructed_at')) {
                $table->date('infection_protection_instructed_at')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'infection_protection_valid_until')) {
                $table->date('infection_protection_valid_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn(['infection_protection_instructed_at', 'infection_protection_valid_until']);
        });
    }
};
