<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der BEANTRAGTE Nummernwechsel — Weg 4 aus Spec §5 (Canvas 1789):
 *
 *   "Nummer weg UND Passwort vergessen: Geburtsdatum + Ausweisziffern, neue
 *    Nummer eintragen, Code an die neue. HR bekommt eine Meldung und kann
 *    innerhalb von 24 Stunden stoppen."
 *
 * WARUM DAFUER UEBERHAUPT ETWAS GEBRAUCHT WIRD. Alle anderen Wege wirken
 * sofort: loeseCodeEin() ruft PersonLinker::setzeNummer(), und die Sache ist
 * erledigt. Weg 4 darf das NICHT — zwischen dem Beweis und der Wirkung
 * liegen 24 Stunden, in denen HR eingreifen kann. Dieser Zwischenzustand hat
 * heute keine Ablage: code_neue_nummer traegt die Nummer nur, solange ein
 * Code laeuft (zehn Minuten), und loeseCodeEin() raeumt sie beim Einloesen
 * ab. Danach waere sie weg.
 *
 * WARUM SPALTEN AN rec_persons UND KEINE EIGENE TABELLE. Drei Gruende, und
 * der erste ist der tragende:
 *
 *  1. GENAU EIN OFFENER ANTRAG JE MENSCH ist die richtige Eigenschaft, und
 *     Spalten erzwingen sie. Ein zweiter Antrag ueberschreibt den ersten —
 *     dieselbe Semantik wie bei erzeugeCode(), wo ein neuer Code den
 *     laufenden ersetzt. Eine Tabelle liesse zwei konkurrierende offene
 *     Zeilen zu und braeuchte eine Regel, welche gewinnt; genau solche
 *     Regeln driften, und hier hiesse Drift: die Nummer wandert an das
 *     falsche Geraet.
 *  2. EIN SCHREIBER. Die Kontofelder stehen bewusst nicht in
 *     RecPerson::$fillable, und KontoWriter ist ihre einzige Schreibstelle.
 *     Eine eigene Tabelle braeuchte entweder ein Modell (und damit einen
 *     zweiten Weg) oder dieselben DB::table()-Aufrufe an einem zweiten Ort.
 *  3. DER VERLAUF STEHT IM PROTOKOLL, nicht in der Tabelle. "Jeder Wechsel
 *     wird protokolliert" ist die Begleitregel aus Spec §5, und sie wird von
 *     Log-Zeilen erfuellt (recruiting.konto.nummernwechsel_*) — so wie es
 *     der ganze Konto-Zweig haelt: Einladung, Code-Versuche und der
 *     Anmeldestempel hinterlassen ihre Spur ebenfalls im Log und nicht in
 *     einer Historientabelle.
 *
 * WAS DIESE ENTSCHEIDUNG KOSTET, damit es niemand spaeter entdeckt: ein
 * gestoppter oder angewendeter Antrag verschwindet aus der Zeile. Wer
 * spaeter fragt "wie oft hat dieser Mensch Weg 4 benutzt?", muss ins
 * Protokoll sehen und nicht in die Datenbank. Wird das je eine
 * Auswertungsfrage, gehoert eine eigene Tabelle her — dann aber als
 * ZUSAETZLICHE Historie neben diesen Spalten, nicht an ihrer Stelle: die
 * Eigenschaft aus Punkt 1 darf dabei nicht verloren gehen.
 *
 * wechsel_quelle haelt fest, AUS WELCHEM Weg der Antrag stammt. Heute gibt
 * es nur einen ('notfall'); die Spalte steht trotzdem da, weil ein zweiter
 * Weg sonst denselben Schlitz mit anderer Bedeutung belegte und niemand es
 * dem Inhalt ansaehe.
 *
 * WARUM EINE DRITTE MIGRATION und keine Ergaenzung der ersten: die
 * Migrationen aus Aufgabe 1 und 7 koennen auf der Demo schon gelaufen sein,
 * und eine nachtraeglich geaenderte Migration laeuft dort nie wieder an —
 * die Ergaenzung waere auf der Demo unsichtbar geblieben.
 *
 * Spalten einzeln mit hasColumn-Wache (Muster:
 * 2026_09_29_000001_add_konto_felder_to_rec_persons.php), down() als echte
 * Umkehrung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'wechsel_neue_nummer')) {
                $table->string('wechsel_neue_nummer', 32)->nullable()->after('letzte_anmeldung_at');
            }
            if (!Schema::hasColumn('rec_persons', 'wechsel_beantragt_at')) {
                $table->timestamp('wechsel_beantragt_at')->nullable()->after('wechsel_neue_nummer');
            }
            if (!Schema::hasColumn('rec_persons', 'wechsel_wirksam_ab')) {
                $table->timestamp('wechsel_wirksam_ab')->nullable()->after('wechsel_beantragt_at');
            }
            if (!Schema::hasColumn('rec_persons', 'wechsel_quelle')) {
                $table->string('wechsel_quelle', 20)->nullable()->after('wechsel_wirksam_ab');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            foreach ([
                'wechsel_neue_nummer', 'wechsel_beantragt_at', 'wechsel_wirksam_ab', 'wechsel_quelle',
            ] as $spalte) {
                if (Schema::hasColumn('rec_persons', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
