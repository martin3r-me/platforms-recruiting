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
            $t->text('field_mappings')->nullable();
            $t->boolean('requires_signature')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    /** Wie die Migration aus Task 2, inklusive Unique-Constraint. */
    public static function trainingCertificates(Builder $schema): void
    {
        if ($schema->hasTable('rec_training_certificates')) {
            return;
        }

        $schema->create('rec_training_certificates', function ($t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('rec_applicant_id');
            $t->unsignedBigInteger('rec_contract_template_id');
            $t->longText('personalized_content')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->unsignedBigInteger('issued_by_user_id')->nullable();
            $t->timestamp('wa_sent_at')->nullable();
            $t->timestamps();
            // Der Constraint ist Teil der Invariante "ein Zertifikat pro
            // Bewerber pro Vorlage" und muss im Test genauso greifen.
            $t->unique(
                ['rec_applicant_id', 'rec_contract_template_id'],
                'rec_training_cert_applicant_tpl_unique'
            );
        });
    }
}
