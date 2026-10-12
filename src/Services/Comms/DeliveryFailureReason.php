<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Meta-Fehlercode einer nicht zugestellten WhatsApp-Nachricht -> Klartext
 * fuer HR, plus WER etwas tun muss: 'person' (Nummer/Konto des Empfaengers —
 * anrufen), 'setup' (unsere Vorlage/unser Konto — an die Technik), 'other'.
 *
 * Wo der Fehler steht (platform-crm WhatsAppMetaService):
 *  - Webhook meldet failed      -> meta_payload['errors'][] (Liste)
 *  - API lehnt sofort ab         -> meta_payload['error']   (einzelnes Objekt)
 * Spaetere Webhooks ueberschreiben meta_payload['errors'] nicht — deshalb
 * findet sich der Code auch an Altfaellen, deren Status vor dem CRM-Fix #924
 * von einem verspaeteten 'sent' ueberschrieben wurde.
 */
final class DeliveryFailureReason
{
    public const WHO_PERSON = 'person';
    public const WHO_SETUP = 'setup';
    public const WHO_OTHER = 'other';

    /** code => [Klartext, wer] */
    private const KNOWN = [
        131026 => ['Nummer nicht per WhatsApp erreichbar (kein WhatsApp, alte App-Version oder gesperrtes Konto)', self::WHO_PERSON],
        133010 => ['Nummer ist nicht bei WhatsApp registriert', self::WHO_PERSON],
        131049 => ['Meta hat die Zustellung zurückgehalten (zu viele Vorlagen an diese Person)', self::WHO_PERSON],
        131050 => ['Person hat Nachrichten dieses Absenders abbestellt', self::WHO_PERSON],
        130472 => ['Nummer nimmt an einem Meta-Experiment teil, Zustellung gesperrt', self::WHO_PERSON],
        131047 => ['24-Stunden-Fenster war zu — freier Text ging nicht raus', self::WHO_OTHER],
        131056 => ['Zu viele Nachrichten kurz hintereinander an diese Nummer', self::WHO_OTHER],
        130429 => ['Versandlimit erreicht', self::WHO_SETUP],
        131048 => ['Versandlimit wegen Spam-Einstufung erreicht', self::WHO_SETUP],
        131008 => ['Vorlage: Pflichtangabe fehlt', self::WHO_SETUP],
        131009 => ['Vorlage: ungültige Angabe', self::WHO_SETUP],
        132000 => ['Vorlage: Anzahl der Platzhalter passt nicht', self::WHO_SETUP],
        132001 => ['Vorlage existiert nicht (Name oder Sprache)', self::WHO_SETUP],
        132005 => ['Vorlage: Text zu lang', self::WHO_SETUP],
        132007 => ['Vorlage verstößt gegen Meta-Richtlinien', self::WHO_SETUP],
        132012 => ['Vorlage: Platzhalter im falschen Format', self::WHO_SETUP],
        132015 => ['Vorlage ist von Meta pausiert', self::WHO_SETUP],
        132016 => ['Vorlage ist von Meta deaktiviert', self::WHO_SETUP],
        131031 => ['WhatsApp-Konto gesperrt', self::WHO_SETUP],
        131042 => ['Zahlungsproblem im WhatsApp-Konto', self::WHO_SETUP],
        131000 => ['Unbekannter Fehler bei Meta', self::WHO_OTHER],
        131016 => ['WhatsApp war vorübergehend nicht erreichbar', self::WHO_OTHER],
    ];

    private function __construct(
        public readonly ?int $code,
        public readonly string $text,
        public readonly string $who,
    ) {}

    /**
     * Fehler aus meta_payload lesen. null = kein Fehler im Payload.
     *
     * @param array<string, mixed>|null $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        $payload ??= [];
        $error = null;
        if (!empty($payload['errors']) && is_array($payload['errors'])) {
            $first = reset($payload['errors']);
            $error = is_array($first) ? $first : null;
        } elseif (!empty($payload['error']) && is_array($payload['error'])) {
            $error = $payload['error'];
        }

        if ($error === null) {
            return null;
        }

        $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;

        return self::forCode($code, (string) ($error['title'] ?? $error['message'] ?? ''));
    }

    /** Status failed, aber kein Code im Payload. */
    public static function unknown(): self
    {
        return new self(null, 'Nicht zugestellt (kein Grund von Meta übermittelt)', self::WHO_OTHER);
    }

    public static function forCode(?int $code, string $metaText = ''): self
    {
        if ($code !== null && isset(self::KNOWN[$code])) {
            [$text, $who] = self::KNOWN[$code];

            return new self($code, $text, $who);
        }

        $text = trim($metaText) !== '' ? 'Meta: ' . trim($metaText) : 'Nicht zugestellt';

        return new self($code, $text, self::WHO_OTHER);
    }

    /** Was HR jetzt tun soll. */
    public function hint(): string
    {
        return match ($this->who) {
            self::WHO_PERSON => 'Bitte anrufen oder per E-Mail melden.',
            self::WHO_SETUP => 'Liegt an unserer Vorlage oder unserem Konto — bitte an die Technik geben.',
            default => 'Später erneut versuchen oder anrufen.',
        };
    }
}
