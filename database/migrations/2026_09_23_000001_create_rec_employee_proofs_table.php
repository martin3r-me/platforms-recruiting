<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nachweise als eigene Objekte statt als 16 feste Spalten an rec_employees.
 *
 * Zwei Schluessel mit Absicht:
 *  - rec_employee_id  WO hochgeladen wurde (Anstellung, fuer Herkunft und HR)
 *  - person_key       WEM der Nachweis gehoert — hierueber wird gelesen
 *
 * Damit gilt ein Ausweis fuer beide Anstellungen, ohne dass es eine
 * Personen-Tabelle gibt. Canvas 68 ersetzt den Marker spaeter durch einen
 * echten Fremdschluessel; die Lesestellen bleiben, wie sie sind.
 *
 * Fassungen: Eine neue Datei ersetzt die alte fachlich, loescht sie aber nie.
 * Die aktuelle Fassung ist die mit superseded_at IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_employee_proofs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->unsignedBigInteger('rec_employee_id');
            $table->string('person_key', 64)->nullable();
            $table->string('proof_type_code', 40);

            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('file_back_id')->nullable();

            // Vom Mitarbeiter beim Hochladen eingetragen — aus der PDF ist es
            // nicht ableitbar. Null bei Arten ohne Ablauf.
            $table->date('valid_until')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamp('superseded_at')->nullable();

            // Verhindert, dass der taegliche Fristenlauf dieselbe Erinnerung
            // wiederholt. Eine Erinnerung je Nachweis, danach steht es als
            // Aufgabe im Portal.
            $table->timestamp('reminded_at')->nullable();

            // Nur fuer Aufenthaltstitel und Arbeitsgenehmigung befuellt. KEINE
            // Einsatzsperre haengt daran (die gibt es fuer Mitarbeiter nicht,
            // siehe ProofTypes::needsHrConfirmation()) — Spalten bleiben fuer
            // eine spaetere Bestaetigung MIT Wirkung, aktuell nur Altbestand.
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            // employee | hr | import — woher die Fassung stammt
            $table->string('uploaded_via', 20)->default('employee');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();

            $table->timestamps();

            $table->index(['rec_employee_id', 'proof_type_code'], 'rec_proofs_employee_type_idx');
            $table->index(['person_key', 'proof_type_code'], 'rec_proofs_person_type_idx');
            // Fristenlauf: alles was bald ablaeuft, ueber alle Personen
            $table->index(['valid_until', 'superseded_at'], 'rec_proofs_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_employee_proofs');
    }
};
