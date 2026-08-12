<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausgestellte Schulungszertifikate. Bewusst KEINE rec_contracts-Zeile:
 * die wuerde hasAnyContractSent() wahr machen (Versand-Guards des
 * Nicht-EU-Umbaus) und in Portal-, Employees-Show- und ZAS-Vertragslisten
 * auftauchen.
 *
 * KEINE Vorlagen-Zeile, und das ist der Zuschnitt dieses Pakets: der
 * Zertifikat-Inhalt steht als festes HTML in TrainingCertificateHtml, nicht als
 * Vorlage in rec_contract_templates. Das Dokument hat drei variable Werte
 * (Name, Schulungsdatum, Schulungsleiter), eine Schulungsart, und einen Text,
 * der sich praktisch nie aendert. Der Preis ist benannt: HR kann den Text nicht
 * selbst aendern, eine zweite Schulungsart braucht ein Deploy. Der Gewinn ist,
 * dass keine Zeile in rec_contract_templates landet — damit entfallen 22 Guards
 * in fremden Queries und die Kopplung an den erzwungenen 'ZERT-'-Praefix, die
 * fuer 12 dieser Guards die einzige Garantie war. Siehe Spec, Abschnitt
 * "Aufgegeben mit dem Zuschnitt", und docs/zertifikat/guard-landkarte-511451c.md
 * fuer die nicht ausgefuehrte Analyse.
 *
 * DESHALB: kind statt rec_contract_template_id.
 *
 * `kind` ist die Dedup-Dimension, die vorher die Vorlagen-ID war: ein
 * Zertifikat pro Person pro Schulungsart. Auf unique(rec_applicant_id) allein
 * runterzugehen waere der naheliegende Reflex und wuerde die zweite
 * Schulungsart verbauen — sie braucht dann nur ein Deploy mit einem zweiten
 * HTML-Block, keinen Schemawechsel. NOT NULL ohne Default, damit niemand
 * versehentlich eine Zeile ohne Art anlegt; der Wert kommt aus einer Konstante
 * am Model, nicht aus einem Formular.
 *
 * personalized_content ist ein Snapshot — und er bleibt, obwohl der Text jetzt
 * fest ist. Grund: er haelt die drei variablen Werte zum Zeitpunkt der
 * Ausstellung fest. Ohne ihn wuerde bei jedem Download neu aufgeloest, und ein
 * Zertifikat, das im August ausgestellt wurde, zeigte im Dezember ein anderes
 * Ausstellungsdatum und womoeglich einen anderen Schulungsleiter (die Buchung
 * kann sich aendern, Interviewer koennen nachgetragen werden). Er ist NICHT
 * redundant, auch wenn er beim naechsten Aufraeumen so aussieht.
 * Die Huelle (Layout, Assets) steckt NICHT darin, sondern wird beim Rendern
 * aufgeloest — Muster wie beim Firmenstempel in Vertraegen. Sonst lagen ~550 KB
 * Base64 pro ausgestelltem Zertifikat in der Spalte.
 *
 * Constraint-Namen: alle automatisch generierten liegen unter der
 * MySQL-Grenze von 64 Zeichen, durchgerechnet —
 *   rec_training_certificates_rec_applicant_id_kind_unique   54
 *   rec_training_certificates_team_id_issued_at_index        49
 *   rec_training_certificates_rec_applicant_id_foreign       50
 *   rec_training_certificates_issued_by_user_id_foreign      51
 * Der explizite Name der Vorgaenger-Fassung war nur noetig, weil
 * ...rec_applicant_id_rec_contract_template_id_unique auf 74 Zeichen kam. Mit
 * `kind` faellt er weg, und damit ein handgepflegter String weniger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_training_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->longText('personalized_content')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('wa_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['rec_applicant_id', 'kind']);
            $table->index(['team_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_training_certificates');
    }
};
