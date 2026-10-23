<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hotfix 23.10.2026: Unterschrift scheitert am Handy mit 500.
 *
 * signature_data war TEXT — in MySQL hoechstens 65.535 Bytes. Die Unterschrift
 * ist ein PNG als Data-URL; am Handy zeichnet das Feld in Geraete-Aufloesung
 * (devicePixelRatio 3), eine grosse Unterschrift wird groesser als 64 KB.
 * MySQL laeuft STRICT und lehnt ab ("Data too long") — der Bewerber sieht 500
 * (Befund Prod: groesste gespeicherte 65.514, 7 knapp an der Grenze,
 * Durchschnitt 40.112). MEDIUMTEXT fasst 16 MB.
 *
 * Beide Spalten: Vertraege (rec_contracts) und Dokument-Unterschriften im
 * neuen Portal (rec_document_recipients).
 */
return new class extends Migration
{
    private const TABELLEN = ['rec_contracts', 'rec_document_recipients'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (!Schema::hasColumn($tabelle, 'signature_data')) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->mediumText('signature_data')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (!Schema::hasColumn($tabelle, 'signature_data')) {
                continue;
            }
            // Zurueck auf TEXT nur, solange keine Unterschrift ueber 64 KB liegt —
            // sonst schnitte MySQL sie ab oder bricht ab.
            $max = (int) DB::table($tabelle)->max(DB::raw('LENGTH(signature_data)'));
            if ($max > 65535) {
                throw new RuntimeException("{$tabelle}.signature_data hat Werte ueber 65.535 Bytes — zurueck auf TEXT wuerde Unterschriften zerstoeren.");
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->text('signature_data')->nullable()->change();
            });
        }
    }
};
