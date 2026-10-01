<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein HR-Fall auch fuer den, der nie Bewerber war.
 *
 * rec_hr_desk_cases hing bisher ausschliesslich an rec_applicant_id, und die
 * Spalte war NOT NULL. Wer ueber ZAS kam und nie durch den Bewerberprozess
 * lief — das ist der Grossteil des Bestands — konnte deshalb gar keinen Fall
 * bekommen. Die harte Sperre des Einsatz-Triggers braucht aber genau fuer
 * diese Menschen einen. Ein Fall haengt danach an EINEM von beiden.
 *
 * KEIN FOREIGN-KEY-CONSTRAINT auf rec_employees, wie schon bei
 * rec_dispo_assignments.rec_employee_id: dieselbe Sollbruchstelle fuer den
 * spaeteren Personen-Verweis. Der Index bleibt, weil die Abfrage des
 * Triggers die offenen Faelle EINES Mitarbeiters sucht.
 *
 * Eigene Datei statt Aenderung an der Erzeuger-Migration: eine Migration,
 * die irgendwo schon gelaufen ist, laeuft dort nie wieder an. hasColumn-Wache
 * in up() und down() (Muster:
 * 2026_09_29_000001_add_konto_felder_to_rec_persons.php).
 *
 * ZU DEN after()-ANKERN: SQLite ignoriert after() stillschweigend. Die
 * erzeugte MySQL-DDL prueft HrDeskCaseFuerMitarbeiterTest trotzdem, indem es
 * diese Migration zusaetzlich gegen die MySQL-Grammatik faehrt (pretend, ohne
 * Server); die Spaltenreihenfolge selbst bleibt ungemessen.
 *
 * WAS DIE SUITE NICHT HERSTELLEN KANN: das change() auf rec_applicant_id
 * unter MySQL. In pretend liest istPflichtfeld() eine leere Spaltenliste und
 * ueberspringt den Zweig, und SQLite baut die Tabelle auf einem anderen Weg
 * um als MySQL. Dass das ALTER auf einer Spalte mit FK-Constraint durchlaeuft,
 * ist zulaessig, aber von dieser Suite nicht belegt — vor dem Deploy auf einer
 * MySQL-Kopie fahren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_hr_desk_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_hr_desk_cases', 'rec_employee_id')) {
                $table->unsignedBigInteger('rec_employee_id')->nullable()->index()->after('rec_applicant_id');
            }
        });

        // Nur anfassen, wenn die Spalte heute wirklich NOT NULL ist — ein
        // change() auf eine Spalte, die schon passt, baut die Tabelle
        // trotzdem um und kostet auf der Produktion Sperrzeit fuer nichts.
        if ($this->istPflichtfeld('rec_applicant_id')) {
            Schema::table('rec_hr_desk_cases', function (Blueprint $table) {
                $table->unsignedBigInteger('rec_applicant_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // ERST DER INDEX, DANN DIE SPALTE — gemessen: SQLite raeumt den Index
        // beim Spaltenabwurf NICHT mit ab und bricht danach bei jedem
        // weiteren Schema-Zugriff mit "error in index ... after drop column".
        // MySQL verzeiht die Reihenfolge, SQLite nicht; der Testlauf haengt
        // an SQLite. Der Name kommt aus der Datenbank und nicht aus der
        // Laravel-Konvention, damit der Rueckbau auch an einem von Hand
        // umbenannten Index nicht haengen bleibt.
        $indexName = $this->indexUeber('rec_employee_id');

        if ($indexName !== null) {
            Schema::table('rec_hr_desk_cases', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        Schema::table('rec_hr_desk_cases', function (Blueprint $table) {
            if (Schema::hasColumn('rec_hr_desk_cases', 'rec_employee_id')) {
                $table->dropColumn('rec_employee_id');
            }
        });

        // Zurueck auf Pflicht nur, wenn keine Zeile ohne Bewerber dasteht.
        // Sonst scheitert das ALTER mitten im Rueckbau und laesst die
        // Tabelle halb umgebaut zurueck — die Faelle der ZAS-Menschen sind
        // dann schon da und waeren genau die Zeilen, die es kippt.
        $ohneBewerber = DB::table('rec_hr_desk_cases')->whereNull('rec_applicant_id')->count();

        if ($ohneBewerber === 0 && !$this->istPflichtfeld('rec_applicant_id')) {
            Schema::table('rec_hr_desk_cases', function (Blueprint $table) {
                $table->unsignedBigInteger('rec_applicant_id')->nullable(false)->change();
            });
        }
    }

    /** Der Name des Index, der genau ueber dieser einen Spalte liegt. */
    private function indexUeber(string $spalte): ?string
    {
        foreach (Schema::getIndexes('rec_hr_desk_cases') as $index) {
            if ($index['columns'] === [$spalte]) {
                return $index['name'];
            }
        }

        return null;
    }

    /** Traegt die Spalte heute eine NOT-NULL-Pflicht? */
    private function istPflichtfeld(string $spalte): bool
    {
        foreach (Schema::getColumns('rec_hr_desk_cases') as $beschreibung) {
            if ($beschreibung['name'] === $spalte) {
                return $beschreibung['nullable'] === false;
            }
        }

        return false;
    }
};
