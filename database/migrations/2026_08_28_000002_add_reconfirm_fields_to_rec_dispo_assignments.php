<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runde 4 (#2): Snapshot der Zeiten zum Bestaetigungszeitpunkt + Marker
    // "Zeit geaendert, erneut bestaetigen". Bestand: bereits bestaetigte
    // Einbuchungen bekommen die aktuellen Zeiten als Snapshot, damit die
    // naechste Lieferung gegen etwas vergleichen kann.
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->date('confirmed_datum')->nullable()->after('confirmed_at');
            $table->string('confirmed_von', 8)->nullable()->after('confirmed_datum');
            $table->string('confirmed_bis', 8)->nullable()->after('confirmed_von');
            $table->timestamp('reconfirm_required_at')->nullable()->after('confirmed_bis');
            $table->json('reconfirm_previous')->nullable()->after('reconfirm_required_at');
        });

        DB::table('rec_dispo_assignments')->whereNotNull('confirmed_at')->update([
            'confirmed_datum' => DB::raw('datum'),
            'confirmed_von'   => DB::raw('von'),
            'confirmed_bis'   => DB::raw('bis'),
        ]);
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['confirmed_datum', 'confirmed_von', 'confirmed_bis', 'reconfirm_required_at', 'reconfirm_previous']);
        });
    }
};
