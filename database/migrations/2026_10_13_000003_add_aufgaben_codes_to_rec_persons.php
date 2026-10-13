<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WELCHE Punkte zuletzt gemeldet wurden — nicht nur ein Hash darueber.
 *
 * Kundenzusage Markus (13.10.2026, Go-live-Folie 8): „Eine neue Nachricht
 * kommt nur, wenn ein neuer Punkt dazukommt." Mit `aufgaben_signatur`
 * (sha256 ueber die sortierten Codes) laesst sich nur „gleich / anders"
 * feststellen — eine KLEINERE Liste war auch „anders" und loeste nach der
 * Pause erneut aus. Fuer die Teilmengen-Pruefung braucht es die Codes selbst.
 *
 * WARUM EINE NEUE SPALTE und nicht die Signatur-Spalte umwidmen: die ist ein
 * string(64), und die Codes sind nicht auf 13 feste Nachweise begrenzt —
 * OffenePunkte liefert auch `dokument:<id>` und `vertrag:<id>`, die Liste
 * hat also keine feste Hoechstlaenge. Ausserdem bleibt die Signatur so der
 * unveraenderte Marker „es wurde gemeldet" (Zielkreis ET-16, HR-Liste
 * „Erinnert"), und der Altbestand ist eindeutig erkennbar: Signatur ohne
 * Codes = alter Stand, Hash-Vergleich wie bisher.
 *
 * Inhalt: JSON-Liste sortierter Codes, z. B. ["ausweis","selfie"]. TEXT,
 * nullable. Geschrieben nur per Query Builder (Einsatz-Pruefung,
 * HR „Erneut erinnern"), nie per Eloquent — Grund wie bei den uebrigen
 * Trigger-Spalten (ZAS-Export-Marker, Vorfall 02.09.2026).
 *
 * hasColumn-Wache in up() und down() (Muster 2026_10_01_000003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'aufgaben_codes')) {
                $table->text('aufgaben_codes')->nullable()->after('aufgaben_signatur');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasColumn('rec_persons', 'aufgaben_codes')) {
                $table->dropColumn('aufgaben_codes');
            }
        });
    }
};
