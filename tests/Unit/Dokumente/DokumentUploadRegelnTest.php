<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentUploadRegeln;

final class DokumentUploadRegelnTest extends TestCase
{
    public function test_echte_pdf_geht_durch(): void
    {
        $this->assertNull(DokumentUploadRegeln::pruefe("%PDF-1.7\n%âãÏÓ\n1 0 obj", 'Verschwiegenheit.pdf'));
    }

    public function test_umbenanntes_jpg_wird_abgelehnt(): void
    {
        $jpg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 100);
        $this->assertSame('Die Datei ist keine PDF.', DokumentUploadRegeln::pruefe($jpg, 'foto.pdf'));
    }

    public function test_falsche_endung_wird_abgelehnt(): void
    {
        $this->assertSame('Nur PDF-Dateien sind erlaubt.', DokumentUploadRegeln::pruefe("%PDF-1.4", 'vertrag.docx'));
    }

    public function test_leere_datei_wird_abgelehnt(): void
    {
        $this->assertSame('Die Datei ist leer.', DokumentUploadRegeln::pruefe('', 'leer.pdf'));
    }

    public function test_zu_gross_wird_abgelehnt(): void
    {
        $inhalt = '%PDF-' . str_repeat('x', DokumentUploadRegeln::MAX_BYTES);
        $this->assertSame('Die Datei ist größer als 20 MB.', DokumentUploadRegeln::pruefe($inhalt, 'gross.pdf'));
    }

    public function test_endung_ist_nicht_case_sensitiv(): void
    {
        $this->assertNull(DokumentUploadRegeln::pruefe("%PDF-1.4", 'SCAN.PDF'));
    }
}
