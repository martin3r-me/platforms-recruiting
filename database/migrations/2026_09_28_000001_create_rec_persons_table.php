<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Mensch als eigene Zeile — bisher gab es ihn nur als Stempel.
 *
 * `rec_employees.person_key` markiert seit dem 09.09.2026, welche Datensaetze
 * derselbe Mensch sind. Gesetzt wird er aber NUR beim Paaren: wer eine
 * Anstellung hat, traegt NULL — das ist die Mehrheit. Als Anker fuer ein
 * Konto taugt er deshalb nicht (Spec 2026-09-28, Paragraph 4.3).
 *
 * Diese Zeile ist fuer JEDEN gedacht, auch ohne Konto und auch mit nur
 * einer Anstellung. Stand heute gilt das aber noch nicht fuer jeden
 * Entstehungsweg:
 *
 *  - Der Backfill (recruiting:personen-anlegen) stellt die Klammer fuer den
 *    Bestand her, wie er zum ZEITPUNKT DES LAUFS aussieht.
 *  - CreateEmployeeFromApplicantService (Mitarbeiter-Anlage aus der
 *    Bewerbung — der Hauptweg dieses Moduls) ruft PersonLinker seit Commit
 *    c318c15 unmittelbar mit (linkPerson()). HIER STAND BIS ZUR
 *    SCHLUSSRUNDE DAS GEGENTEIL: der Absatz ist aelter als der Haken, und
 *    wer die erste Haelfte nachprueft und falsch vorfindet, glaubt der
 *    zweiten nicht mehr — und die zweite ist die teure.
 *  - ZasInboundEmployeeImporter (Neuanlage aus der ZAS-Lieferung) ruft ihn
 *    WEITERHIN NICHT. Er erreicht PersonLinker nur ueber die Paarung
 *    (pairIfExact -> stamp -> verbindePerson), also NUR beim
 *    doppelt-exakten Treffer; ein neu angelegter Einzelfall bleibt ohne
 *    Zeile. DAS IST DIE VERBLIEBENE LUECKE, und jede ZAS-Lieferung nach dem
 *    Backfill erzeugt wieder Anstellungen ohne rec_person_id.
 *
 * WAS DIE LUECKE IN STUFE 2 KOSTET: ein so angelegter Mensch kann KEIN Konto
 * bekommen und sich nie anmelden. Im Bericht von recruiting:konto-einladen
 * tauchte er lange gar nicht auf, auch nicht unter "nicht erreichbar" — die
 * Abfrage startet an rec_persons, und wer dort keine Zeile hat, kommt in
 * keiner Spalte vor. Seit der Schlussrunde nennt der Bericht wenigstens
 * ihre ANZAHL in einer eigenen Zeile, mit dem Hinweis auf
 * recruiting:personen-anlegen. Den Haken in den ZAS-Import einzubauen ist
 * eine eigene Aufgabe mit eigener Pruefung.
 *
 * Folge, die nicht uebersehen werden darf: der Uebergangs-Zweig in
 * PersonScopeResolver (person_key + Telefon zur Laufzeit) darf NICHT
 * entfernt werden, solange dieser Haken fehlt — auch dann nicht, wenn der
 * Backfill auf der Produktion gelaufen ist. Ohne ihn saehe jeder nach dem
 * Backfill neu angelegte Mensch seine zweite Anstellung nicht mehr.
 *
 * Die Anmeldespalten (phone, password_hash, ...) entstehen hier mit, werden
 * in Stufe 1 aber von nichts gelesen. Sie jetzt wegzulassen hiesse, spaeter
 * ein zweites Mal an einer dann befuellten Tabelle zu wandern.
 *
 * phone ist der spaetere Benutzername und deshalb je Team eindeutig — eine
 * Nummer darf nur an EINEM Konto haengen (Canvas 68, Eintrag 1740). NULL ist
 * erlaubt und mehrfach moeglich: wer keine Nummer hat, bekommt trotzdem eine
 * Personen-Zeile, er bekommt nur kein Konto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_persons')) {
            return;
        }

        Schema::create('rec_persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('phone', 32)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('email')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Gesetzt, wenn diese Zeile beim Zusammenlegen verloren hat. Sie
            // bleibt stehen und ihre Nummer bleibt gesperrt, damit eine neu
            // vergebene Handynummer nicht an alte Daten fuehrt (Canvas 1793).
            $table->unsignedBigInteger('merged_into_person_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_persons');
    }
};
