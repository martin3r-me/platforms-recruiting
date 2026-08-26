<?php

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Support\ZasPersonnelNumber;

return new class extends Migration
{
    /**
     * Setzt den eigenen Firmen-Praefix vor alle bestehenden ZAS-Personalnummern.
     *
     * ZAS bedient zwei Firmen und vergibt in beiden dieselben Ziffernfolgen —
     * 276, 322, 325 und 353 existieren als RG UND als MA. Unsere
     * `personnel_number` ist gleichzeitig Dubletten-Schluessel beim Import und
     * Zuordnungsschluessel in der Disposition; ohne Praefix ist sie also nicht
     * eindeutig. Konkret hingen dadurch 13 MA-Einbuchungen an drei
     * RG-Mitarbeitern (Befund 2026-08-26).
     *
     * Der Zeitpunkt ist bewusst jetzt: solange ausschliesslich RG-Mitarbeiter
     * im Bestand sind, ist die Firmenzugehoerigkeit jeder Nummer zweifelsfrei.
     * Sobald MA-Leute ohne Praefix mitimportiert waeren, liesse sie sich nicht
     * mehr rekonstruieren.
     *
     * Nur blanke Nummern werden angefasst — ein bereits vorhandener Praefix
     * bleibt unberuehrt (dieselbe Regel wie beim Import, siehe
     * ZasPersonnelNumber). Die Migration ist damit wiederholbar.
     *
     * Bewusst per DB::table statt Eloquent: kein Observer, kein Export-Marker,
     * kein Lohn-Tracking. `personnel_number` ist ohnehin nicht export-relevant.
     *
     * NICHT angefasst wird `recruited_by_personnel_number` ("geworben von").
     * Auf dem Feld matcht nichts, es dient nur der Anzeige und dem Rueckexport;
     * kuenftige Lieferungen bringen es praefixt mit.
     */
    public function up(): void
    {
        $prefix = self::prefix();
        if ($prefix === '') {
            return;
        }

        DB::transaction(function () use ($prefix): void {
            DB::table('rec_employees')
                ->whereNotNull('personnel_number')
                ->where('personnel_number', '<>', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($prefix): void {
                    foreach ($rows as $row) {
                        $normalized = ZasPersonnelNumber::normalize($row->personnel_number, $prefix);
                        if ($normalized === null || $normalized === $row->personnel_number) {
                            continue;
                        }
                        DB::table('rec_employees')
                            ->where('id', $row->id)
                            ->update(['personnel_number' => $normalized]);
                    }
                });
        });
    }

    /**
     * Entfernt den eigenen Praefix wieder. Fremde Praefixe bleiben stehen —
     * eine MA-Nummer duerfte auch beim Rollback nicht zu einer blanken werden.
     */
    public function down(): void
    {
        $prefix = self::prefix();
        if ($prefix === '') {
            return;
        }

        DB::transaction(function () use ($prefix): void {
            DB::table('rec_employees')
                ->where('personnel_number', 'like', $prefix . '%')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($prefix): void {
                    foreach ($rows as $row) {
                        $rest = substr((string) $row->personnel_number, strlen($prefix));
                        if ($rest === '') {
                            continue;
                        }
                        DB::table('rec_employees')
                            ->where('id', $row->id)
                            ->update(['personnel_number' => $rest]);
                    }
                });
        });
    }

    /**
     * Einige Integrationstests fahren die Migrationen gegen ein handgebautes
     * SQLite hoch, ohne eine Config zu binden. Dort greift die Vorgabe direkt.
     */
    private static function prefix(): string
    {
        return Container::getInstance()->bound('config')
            ? (string) config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX)
            : ZasPersonnelNumber::DEFAULT_PREFIX;
    }
};
