<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infos im Mitarbeiterportal (Spec 2026-10-16 §2): ein Dokument darf statt
 * einer PDF einen Text tragen. Drei neue Spalten, fuenf Datei-Spalten werden
 * nullable. change() nur, wenn die Spalte heute wirklich NOT NULL ist
 * (Muster 2026_10_01_000002) — ein unnoetiges ALTER kostet auf der
 * Produktion Sperrzeit fuer nichts. Vor dem Deploy: migrate --pretend auf
 * MySQL lesen (SQLite baut Tabellen anders um).
 */
return new class extends Migration
{
    private const DATEI_SPALTEN = ['disk', 'stored_path', 'original_filename', 'file_sha256', 'file_size'];

    public function up(): void
    {
        Schema::table('rec_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_documents', 'content_markdown')) {
                $table->text('content_markdown')->nullable()->after('file_size');
            }
            if (!Schema::hasColumn('rec_documents', 'auto_neue_mitarbeiter')) {
                $table->boolean('auto_neue_mitarbeiter')->default(false)->after('content_markdown');
            }
            if (!Schema::hasColumn('rec_documents', 'published_at')) {
                $table->dateTime('published_at')->nullable()->index()->after('auto_neue_mitarbeiter');
            }
        });

        foreach (self::DATEI_SPALTEN as $spalte) {
            if (!$this->istPflichtfeld($spalte)) {
                continue;
            }
            Schema::table('rec_documents', function (Blueprint $table) use ($spalte) {
                match ($spalte) {
                    'disk'              => $table->string('disk', 50)->nullable()->change(),
                    'stored_path'       => $table->string('stored_path', 500)->nullable()->change(),
                    'original_filename' => $table->string('original_filename', 255)->nullable()->change(),
                    'file_sha256'       => $table->char('file_sha256', 64)->nullable()->change(),
                    'file_size'         => $table->unsignedBigInteger('file_size')->nullable()->change(),
                };
            });
        }

        // Bestand: published_at = created_at, damit die Automatik (Spec §4)
        // einen Stichtag hat, falls HR eine alte Zeile spaeter umwidmet.
        DB::table('rec_documents')->whereNull('published_at')->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // ERST DER INDEX, DANN DIE SPALTE: SQLite raeumt den Index beim
        // Spaltenabwurf nicht mit ab (Befund 2026_10_01_000002).
        foreach (Schema::getIndexes('rec_documents') as $index) {
            if ($index['columns'] === ['published_at']) {
                Schema::table('rec_documents', fn (Blueprint $t) => $t->dropIndex($index['name']));
            }
        }
        Schema::table('rec_documents', function (Blueprint $table) {
            foreach (['content_markdown', 'auto_neue_mitarbeiter', 'published_at'] as $spalte) {
                if (Schema::hasColumn('rec_documents', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
        // Datei-Spalten bleiben nullable: ein NOT NULL zurueck scheitert, sobald
        // eine Info existiert — bewusst kein Rueckbau (additiv, Spec §8).
    }

    /** Traegt die Spalte heute eine NOT-NULL-Pflicht? (Form wie 2026_10_01_000002 — getColumns() geht auch auf SQLite.) */
    private function istPflichtfeld(string $spalte): bool
    {
        foreach (Schema::getColumns('rec_documents') as $beschreibung) {
            if ($beschreibung['name'] === $spalte) {
                return $beschreibung['nullable'] === false;
            }
        }

        return false;
    }
};
