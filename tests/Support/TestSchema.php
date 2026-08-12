<?php

namespace Platform\Recruiting\Tests\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Die EINZIGE Quelle des handgebauten Testschemas fuer Zertifikat-Tests.
 *
 * Warum es das gibt: die Modul-Konvention ist reines PHPUnit ohne
 * Laravel-Bootstrap (tests/bootstrap.php ist ein reiner Autoloader,
 * orchestra/testbench ist nicht installiert). Integrationstests bauen
 * Container und Capsule von Hand und muessen das Schema selbst anlegen.
 *
 * Das dreimal zu tun hat schon einmal Drift erzeugt: in der ersten Fassung
 * des Umsetzungsplans hatte eine der drei Kopien die Spalte 'description',
 * die anderen zwei nicht. Ein Testschema, das von der Migration abweicht,
 * laesst Tests gruen werden und bestaetigt einen Zustand, den die Produktion
 * nicht hat.
 *
 * ACHTUNG: Diese Klasse ist die Testabbildung der Migrationen
 *   2026_08_12_000001_add_type_to_rec_contract_templates.php
 *   2026_08_12_000002_create_rec_training_certificates_table.php
 * und der Basis-Migration 2026_04_15_100000_create_rec_contract_tables.php.
 * Aendert sich dort etwas, gehoert es hier mit hinein. Sonst faellt es
 * niemandem auf.
 *
 * BEWUSSTE ABWEICHUNGEN VON DEN MIGRATIONEN:
 *
 * 1. Foreign Keys: Spalten wie team_id, rec_applicant_id, usw. sind
 *    unsignedBigInteger, NICHT foreignId()->constrained(). Grund: Das
 *    Test-Fixture hat keine teams-, users- oder rec_applicants-Tabellen.
 *    Mit constrained() müsste jeder Test diese Tabellen mit anlegen, nur
 *    damit ein Constraint hängt. In der bestehenden Capsule-Konfiguration
 *    der Integrationstests ist PRAGMA foreign_keys = 0 (gemessen 2026-08-12),
 *    FK-Constraints werden dort also nicht erzwungen. Die Abfrageergebnisse
 *    sind identisch, und Tests werden dadurch schneller. Wenn die
 *    Verbindungskonfiguration sich ändert, muss diese Entscheidung neu
 *    bewertet werden.
 *
 * 2. Indizes: Die echten Migrationen haben mehrere Indizes (z.B.
 *    index(['team_id','is_active']) auf rec_contract_templates), das
 *    Test-Schema hat keine. Grund: Indizes ändern kein Abfrageergebnis,
 *    nur die Laufzeit. Sie sind für Korrektheitstests nicht nötig.
 */
final class TestSchema
{
    /** Vollstaendig wie die Basis-Migration plus die type-Spalte aus Task 1. */
    public static function contractTemplates(Builder $schema): void
    {
        if ($schema->hasTable('rec_contract_templates')) {
            return;
        }

        $schema->create('rec_contract_templates', function ($t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->string('name');
            $t->string('code', 20)->nullable();
            // NOT NULL mit Default — wie die Migration. Nicht nullable machen:
            // ein dritter Zustand "unbekannt" wuerde die type-Filter aushebeln.
            $t->string('type', 20)->default('contract');
            $t->text('description')->nullable();
            $t->longText('content')->nullable();
            $t->json('field_mappings')->nullable();
            $t->boolean('requires_signature')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    // trainingCertificates() ist ENTFERNT, nicht vergessen.
    //
    // Die Methode war eine handgebaute Kopie der Migration
    // 2026_08_12_000002_create_rec_training_certificates_table.php. Ihr einziger
    // Konsument (IssueTrainingCertificateServiceTest) laedt inzwischen die
    // ECHTEN Migrationen — unter anderem, damit diese Migration im Testlauf
    // ueberhaupt einmal ausgefuehrt wird. Damit war die Kopie konsumentenlos.
    //
    // Ein Testschema ohne Konsument ist derselbe tote Schalter wie eine
    // Konfiguration, die nichts mehr steuert: es sieht nach Absicherung aus,
    // driftet aber unbemerkt von der Migration weg, und der naechste Leser
    // haelt es fuer die maszgebliche Definition. Deshalb weg statt liegenlassen.
    //
    // Wer ein Schema fuer rec_training_certificates braucht: die echten
    // Migrationen laden (Muster in IssueTrainingCertificateServiceTest), nicht
    // diese Kopie wiederbeleben.
}
