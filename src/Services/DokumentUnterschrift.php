<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Die drei Handlungen des Mitarbeiters (Spec §5.3): oeffnen, bestaetigen,
 * unterschreiben. Alle Zeitstempel ueber den Query Builder, erster Zeitpunkt
 * gewinnt (die WHERE-Bedingung "IS NULL" ist die Idempotenz, nicht ein
 * vorheriges Lesen). Die Pruefsumme der gespeicherten Datei wird VOR der
 * Unterschrift gegen file_sha256 gehalten — der Wachhund ueber der
 * Unveraenderlichkeit.
 */
class DokumentUnterschrift
{
    public const SIGNATUR_PRAEFIX = 'data:image/png;base64,';

    /** Nullable mit faulem Default: app(DokumentUnterschrift::class) baut ihn ohne Argument, Tests geben den Speicher mit. */
    public function __construct(private ?DokumentSpeicher $speicher = null)
    {
    }

    private function speicher(): DokumentSpeicher
    {
        return $this->speicher ??= DokumentSpeicher::default();
    }

    public function oeffnen(RecDocumentRecipient $z): void
    {
        DB::table('rec_document_recipients')
            ->where('id', $z->id)
            ->whereNull('first_viewed_at')
            ->update(['first_viewed_at' => now(), 'updated_at' => now()]);
    }

    public function bestaetigen(RecDocumentRecipient $z, bool $gelesen, bool $duzen): ?string
    {
        $z = $z->fresh();
        if ($fehler = $this->vorbedingungen($z, $gelesen, $duzen, DokumentKategorie::AKTION_ACKNOWLEDGE)) {
            return $fehler;
        }
        DB::table('rec_document_recipients')
            ->where('id', $z->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'updated_at' => now()]);

        return null;
    }

    public function unterschreiben(RecDocumentRecipient $z, bool $gelesen, string $signatureData, bool $duzen): ?string
    {
        $z = $z->fresh();
        if ($fehler = $this->vorbedingungen($z, $gelesen, $duzen, DokumentKategorie::AKTION_SIGN)) {
            return $fehler;
        }
        if (!self::istUnterschriftsbild($signatureData)) {
            return $duzen ? 'Bitte unterschreibe im Feld.' : 'Bitte unterschreiben Sie im Feld.';
        }

        $dokument = $z->document;
        $aktuell = $this->speicher()->pruefsumme((string) $dokument->stored_path);
        if ($aktuell === null || !hash_equals((string) $dokument->file_sha256, $aktuell)) {
            Log::error('recruiting.dokumente.pruefsumme_abweichung', [
                'document_id' => $dokument->id, 'recipient_id' => $z->id,
                'erwartet' => $dokument->file_sha256, 'ist' => $aktuell,
            ]);

            return $duzen
                ? 'Das Dokument kann gerade nicht unterschrieben werden. Bitte melde dich bei uns.'
                : 'Das Dokument kann gerade nicht unterschrieben werden. Bitte melden Sie sich bei uns.';
        }

        DB::transaction(function () use ($z, $signatureData) {
            DB::table('rec_document_recipients')
                ->where('id', $z->id)
                ->whereNull('signed_at')
                ->update(['signed_at' => now(), 'signature_data' => $signatureData, 'updated_at' => now()]);
            DB::table('rec_document_recipients')
                ->where('id', $z->id)
                ->whereNull('acknowledged_at')
                ->update(['acknowledged_at' => now()]);
        });

        return null;
    }

    public static function istUnterschriftsbild(string $signatureData): bool
    {
        if (!str_starts_with($signatureData, self::SIGNATUR_PRAEFIX)) {
            return false;
        }
        $rumpf = substr($signatureData, strlen(self::SIGNATUR_PRAEFIX));
        $bytes = base64_decode($rumpf, true);

        return $bytes !== false && str_starts_with($bytes, "\x89PNG");
    }

    private function vorbedingungen(?RecDocumentRecipient $z, bool $gelesen, bool $duzen, string $erwarteteAktion): ?string
    {
        if ($z === null || $z->withdrawn_at !== null || $z->document === null) {
            return 'Dieses Dokument wurde zurückgezogen.';
        }
        // Die Aktion gehoert dem Dokument, nicht dem Aufrufer: wer ein 'none'- oder
        // 'acknowledge'-Dokument unterschriebe, haette signed_at gesetzt und HR
        // koennte es nicht mehr zurueckziehen.
        if ((string) $z->document->action !== $erwarteteAktion) {
            return $erwarteteAktion === DokumentKategorie::AKTION_SIGN
                ? 'Dieses Dokument braucht keine Unterschrift.'
                : 'Dieses Dokument braucht keine Bestätigung.';
        }
        if ($z->first_viewed_at === null) {
            return 'Bitte zuerst das Dokument öffnen.';
        }
        if (!$gelesen) {
            return $duzen
                ? 'Bitte bestätige, dass du das Dokument gelesen hast.'
                : 'Bitte bestätigen Sie, dass Sie das Dokument gelesen haben.';
        }

        return null;
    }
}
