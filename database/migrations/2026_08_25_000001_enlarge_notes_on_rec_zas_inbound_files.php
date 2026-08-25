<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `notes` haelt den kompletten Verarbeitungsbericht einer Lieferung als
     * JSON (created / updated / skipped / failed / warnings / suspected). Als
     * TEXT sind das 65.535 Bytes — und die reichen nicht weit:
     *
     *   100 Zeilen  ~  18 KB   (der abgesprochene Paketschnitt, unkritisch)
     *   300 Zeilen  ~  53 KB   (Bruchkante)
     *   900 Zeilen  ~ 159 KB   (selbst ohne eine einzige Warnung schon 72 KB)
     *
     * Da MySQL hier mit strict-Mode laeuft, wirft ein Ueberlauf statt zu
     * kappen — und zwar NACH dem Import: die Mitarbeiter waeren angelegt, der
     * Bericht verloren, processed_at null und ZAS bekaeme einen 500er. Damit
     * haengt die Belastbarkeit der Schnittstelle an der Paketgroesse, die
     * bisher nur eine Absprache war.
     *
     * MEDIUMTEXT (16 MB) nimmt jede realistische Lieferung auf. Der zusaetzliche
     * Zeilen-Waechter (ZasInboundSizeGuard) bleibt trotzdem sinnvoll — der
     * schuetzt vor dem Request-Timeout, nicht vor der Spaltenbreite.
     */
    public function up(): void
    {
        Schema::table('rec_zas_inbound_files', function (Blueprint $table) {
            $table->mediumText('notes')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Achtung: bestehende Berichte ueber 64 KB werden dabei abgeschnitten
        // bzw. abgewiesen. Nur zurueckrollen, wenn das bewusst gewollt ist.
        Schema::table('rec_zas_inbound_files', function (Blueprint $table) {
            $table->text('notes')->nullable()->change();
        });
    }
};
