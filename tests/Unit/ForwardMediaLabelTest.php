<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;

/** Deutsche Medien-Bezeichnungen fuer weitergeleitete Nachrichten. */
class ForwardMediaLabelTest extends TestCase
{
    public function test_bekannte_typen_sind_deutsch(): void
    {
        $this->assertSame('Bild', ConversationForwarder::mediaLabel('image'));
        $this->assertSame('Video', ConversationForwarder::mediaLabel('video'));
        $this->assertSame('Audio', ConversationForwarder::mediaLabel('audio'));
        $this->assertSame('Sprachnachricht', ConversationForwarder::mediaLabel('voice'));
        $this->assertSame('Dokument', ConversationForwarder::mediaLabel('document'));
        $this->assertSame('Sticker', ConversationForwarder::mediaLabel('sticker'));
    }

    public function test_unbekannter_typ_wird_gross_geschrieben_und_leer_bleibt_leer(): void
    {
        $this->assertSame('Location', ConversationForwarder::mediaLabel('location'));
        $this->assertSame('', ConversationForwarder::mediaLabel(null));
        $this->assertSame('', ConversationForwarder::mediaLabel(''));
    }
}
