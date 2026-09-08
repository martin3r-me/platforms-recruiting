<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ZasImageContent;

/**
 * Inhaltspruefung fuer den Datei-Eingang von ZAS.
 *
 * Geprueft wird der INHALT, nicht die Dateiendung: in der Testlieferung vom
 * 03.09. stand `PlanHalle18.jpg` im Selfie-Feld — ein Hallenplan mit
 * Bild-Endung. Eine Endungspruefung haette ihn als Gesicht ins Crew-Kaertchen
 * gelassen. Umgekehrt darf eine harmlos aussehende `.jpg` auch etwas voellig
 * anderes sein; deshalb entscheidet allein die Datei-Signatur.
 */
class ZasImageContentTest extends TestCase
{
    /** 1x1-PNG, echte Bytes. */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
    }

    /** 1x1-JPEG, echte Bytes. */
    private function jpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBk'
            . 'ZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSww'
            . 'MTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
            . 'MjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//E'
            . 'ALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkq'
            . 'NDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1'
            . 'tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgME'
            . 'BQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDTh'
            . 'JfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKj'
            . 'pKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6K'
            . 'KKAP/9k='
        );
    }

    public function test_recognises_jpeg_and_png(): void
    {
        $this->assertSame('image/jpeg', ZasImageContent::mimeOf($this->jpeg()));
        $this->assertSame('image/png', ZasImageContent::mimeOf($this->png()));
    }

    public function test_rejects_content_that_is_not_an_image(): void
    {
        // Ein Word-Dokument, ein PDF und schlichter Text — alles Dinge, die in
        // seiner Dokumente-Tabelle vorkommen.
        $this->assertNull(ZasImageContent::mimeOf('%PDF-1.4 irgendwas'));
        $this->assertNull(ZasImageContent::mimeOf("PK\x03\x04sonstwas"));
        $this->assertNull(ZasImageContent::mimeOf('Selfie-IMG_0623.jpeg'));
        $this->assertNull(ZasImageContent::mimeOf(''));
    }

    public function test_rejects_image_formats_we_do_not_want(): void
    {
        // GIF ist ein gueltiges Bild, aber kein Format, das wir als
        // Personenfoto annehmen wollen — die Whitelist entscheidet, nicht
        // getimagesize.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        $this->assertNotFalse(@getimagesizefromstring($gif), 'Fixture ist kein gueltiges GIF');
        $this->assertNull(ZasImageContent::mimeOf($gif));
    }

    public function test_size_limit_is_ten_megabytes(): void
    {
        $this->assertSame(10 * 1024 * 1024, ZasImageContent::MAX_BYTES);
        $this->assertFalse(ZasImageContent::exceedsLimit(str_repeat('x', 1024)));
        $this->assertTrue(ZasImageContent::exceedsLimit(str_repeat('x', ZasImageContent::MAX_BYTES + 1)));
    }
}
