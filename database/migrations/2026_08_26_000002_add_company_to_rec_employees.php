<?php

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Recruiting\Support\ZasPersonnelNumber;

return new class extends Migration
{
    /**
     * Firmenzugehoerigkeit am Mitarbeiter (`RG` / `MA`).
     *
     * ZAS bedient zwei Firmen. Bisher liess sich die Zugehoerigkeit nur aus dem
     * Praefix der Personalnummer ableiten — das reicht nicht: bei unseren
     * eigenen Neuanlagen gibt es noch keine Nummer, und genau dafuer braucht
     * ZAS die Firma im Rueck-Export (Michel 2026-08-26: "am Anfang haben wir ja
     * noch keine PNr"). Ausserdem soll die HR-Uebersicht danach filtern koennen.
     *
     * Backfill aus dem Praefix, wo eine Nummer da ist; sonst die Vorgabe. Das
     * ist heute korrekt, weil unser Recruiting ausschliesslich Rheingedeck
     * bedient — alles ohne Nummer ist also RG.
     *
     * Die Kostenstelle taugt als Unterscheidung ausdruecklich NICHT: 100, 200,
     * 300 und 400 existieren jeweils fuer beide Firmen (Screenshot ZAS,
     * 2026-08-26).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('company', 8)->nullable()->after('personnel_number');
            $table->index('company', 'idx_rec_employees_company');
        });

        $default = self::defaultPrefix();

        DB::table('rec_employees')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($default): void {
                foreach ($rows as $row) {
                    $company = ZasPersonnelNumber::prefixOf($row->personnel_number) ?? $default;
                    if ($company === '') {
                        continue;
                    }
                    DB::table('rec_employees')->where('id', $row->id)->update(['company' => $company]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropIndex('idx_rec_employees_company');
            $table->dropColumn('company');
        });
    }

    /**
     * Einige Integrationstests fahren die Migrationen ohne gebundene Config
     * hoch — dort greift die Vorgabe direkt.
     */
    private static function defaultPrefix(): string
    {
        return Container::getInstance()->bound('config')
            ? (string) config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX)
            : ZasPersonnelNumber::DEFAULT_PREFIX;
    }
};
