<?php
// database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Zustellung je Anstellung (Spec 2026-10-08, §2.2). rec_employee_id ohne
 * DB-Fremdschluessel wie rec_contracts.rec_employee_id. person_key ist eine
 * Kopie zum Entdoppeln und fuer die Portal-Sicht ueber beide Anstellungen.
 *
 * Status wird aus den Zeitstempeln abgeleitet (DokumentStatus), es gibt keine
 * Status-Spalte. Zurueckziehen ist ein Zeitstempel, kein Loeschen: die Zeile
 * bleibt im Ueberblick als "zurueckgezogen" sichtbar. Die uuid ist die
 * oeffentliche Kennung des Portal-Downloads (eine Zustellung, ein Mensch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_document_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('rec_document_id')->index();
            $table->unsignedBigInteger('rec_employee_id')->index();
            $table->string('person_key', 64)->nullable()->index();
            $table->dateTime('notified_at')->nullable();
            $table->string('notify_error', 120)->nullable();
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['rec_document_id', 'rec_employee_id'], 'rec_doc_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_document_recipients');
    }
};
