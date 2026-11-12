<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundMarkerReparatur as R;

/** Vorfall 06.11.–12.11.2026: Datenzeilen ohne Zeilenende-Marker — ergaenzen nur, wenn NUR der Marker fehlt. */
class ZasInboundMarkerReparaturTest extends TestCase
{
    private const KOPF = "Name;Vorname;ZasPersonalNr;ArbeitstageRest;|;";

    public function test_fehlender_marker_wird_in_jeder_zeile_ergaenzt(): void
    {
        $r = R::pruefen(self::KOPF . "\r\nMuster;Anna;RG18711;70;\r\nBeispiel;Bernd;MA18745;;\r\n");

        $this->assertSame(R::ERGAENZT, $r['stand']);
        $this->assertSame(2, $r['ergaenzt']);
        $this->assertSame(2, $r['zeilen']);
        $this->assertSame(self::KOPF . "\nMuster;Anna;RG18711;70;|;\nBeispiel;Bernd;MA18745;;|;\n", $r['content']);
    }

    public function test_intakte_datei_bleibt_byteweise_unveraendert(): void
    {
        $content = "\xEF\xBB\xBF" . self::KOPF . "\r\nMuster;Anna;RG18711;70;|;\r\n";

        $r = R::pruefen($content);

        $this->assertSame(R::INTAKT, $r['stand']);
        $this->assertSame($content, $r['content']);
        $this->assertSame(0, $r['ergaenzt']);
    }

    /** ZAS repariert vielleicht mitten in einer Lieferung — jede Zeile wird einzeln beurteilt. */
    public function test_gemischte_datei_ergaenzt_nur_die_zeilen_ohne_marker(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;RG1;70;|;\nBeispiel;Bernd;RG2;5;\n");

        $this->assertSame(R::ERGAENZT, $r['stand']);
        $this->assertSame(1, $r['ergaenzt']);
        $this->assertStringContainsString("\nBeispiel;Bernd;RG2;5;|;\n", $r['content']);
        $this->assertStringContainsString("\nMuster;Anna;RG1;70;|;\n", $r['content']);
    }

    /** Fehlt ausser dem Marker noch ein Feld, ist die Zeile verrutscht — dann nichts anfassen. */
    public function test_zeile_mit_zwei_fehlenden_feldern_lehnt_die_datei_ab(): void
    {
        $content = self::KOPF . "\nMuster;Anna;RG1;70;\nBeispiel;Bernd;RG2;\n";

        $r = R::pruefen($content);

        $this->assertSame(R::ABGELEHNT, $r['stand']);
        $this->assertSame($content, $r['content']);
        $this->assertStringContainsString('Zeile 2', $r['grund']);
    }

    /** Ein Feld mitten in der Zeile fehlt, der Marker ist noch da (nur eine Stelle zu frueh): kein „nur der Marker fehlt“. */
    public function test_verrutschter_marker_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;RG1;70;|;\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    public function test_zu_viele_felder_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;Zusatz;RG1;70;|;\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    /** Ohne abschliessendes Trennzeichen fehlt mehr als der Marker (das letzte Feld waere dann ein Wert). */
    public function test_zeile_ohne_abschliessendes_semikolon_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;RG1;70;x\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    public function test_kopf_ohne_marker_lehnt_ab(): void
    {
        $r = R::pruefen("Name;Vorname;ZasPersonalNr\nMuster;Anna;RG1\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
        $this->assertStringContainsString('Kopfzeile', $r['grund']);
    }

    /** Semikolon in Anfuehrungszeichen ist EIN Feld — gezaehlt wird wie im Parser. */
    public function test_semikolon_im_geschuetzten_wert_zaehlt_als_ein_feld(): void
    {
        $r = R::pruefen(self::KOPF . "\n\"Muster; geb. Alt\";Anna;RG1;70;\n");

        $this->assertSame(R::ERGAENZT, $r['stand']);
        $this->assertStringContainsString("\"Muster; geb. Alt\";Anna;RG1;70;|;\n", $r['content']);
    }

    /** Klassischer Versatz: Semikolon im ungeschuetzten Wert, Marker fehlt — die Zeile hat dann so viele Felder wie der Kopf. */
    public function test_zeile_ohne_marker_mit_voller_feldzahl_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;geb. Alt;Anna;RG1;70;\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    /** Letzter Wert leer, aber die Zeile endet nicht mit dem Trennzeichen: Anhaengen ergaebe `""|;`. */
    public function test_zeile_die_mit_leeren_anfuehrungszeichen_endet_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;RG1;70;\"\"\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    public function test_wert_der_selbst_der_marker_ist_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;|;RG1;70;\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    /** Kopf braucht beides: die Marker-Spalte UND das leere Feld dahinter. */
    public function test_kopf_mit_nur_halbem_zeilenende_lehnt_ab(): void
    {
        $this->assertSame(R::ABGELEHNT, R::pruefen("Name;Vorname;ZasPersonalNr;|\nMuster;Anna;RG1\n")['stand']);
        $this->assertSame(R::ABGELEHNT, R::pruefen("Name;Vorname;ZasPersonalNr;\nMuster;Anna;RG1\n")['stand']);
    }

    /** Intakte Zeile heisst: Marker UND leeres Feld dahinter — `…;|;x` ist es nicht. */
    public function test_zeile_mit_marker_aber_wert_dahinter_lehnt_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;RG1;70;|;x\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
    }

    /**
     * Anker (Review 12.11.2026): ein Feld fehlt VOR der Personalnummer und hinten haengt ein leeres zu viel —
     * die Feldzahl stimmt, aber in der Spalte ZasPersonalNr steht dann keine Nummer.
     */
    public function test_versatz_vor_der_personalnummer_faellt_am_anker_auf(): void
    {
        // Wie in der echten Datei: hinter der Personalnummer steht ein Textfeld (Ersthelfer Ja/Nein).
        $r = R::pruefen("Name;Vorname;ZasPersonalNr;Ersthelfer;|;\nMuster;RG1;Nein;;\n");

        $this->assertSame(R::ABGELEHNT, $r['stand']);
        $this->assertStringContainsString('ZasPersonalNr', $r['grund']);
    }

    /** Leere Personalnummer ist Sache des Importers (er weist die Zeile ab) — die Datei laeuft trotzdem. */
    public function test_leere_personalnummer_lehnt_die_datei_nicht_ab(): void
    {
        $r = R::pruefen(self::KOPF . "\nMuster;Anna;;70;\nBeispiel;Bernd;MA1000000878;5;\n");

        $this->assertSame(R::ERGAENZT, $r['stand']);
        $this->assertSame(2, $r['ergaenzt']);
    }

    public function test_leere_datei_lehnt_ab(): void
    {
        $this->assertSame(R::ABGELEHNT, R::pruefen("\n\n")['stand']);
    }
}
