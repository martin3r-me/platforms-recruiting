<?php

namespace Platform\Recruiting\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verlauf je Dokument (Sebastian 16.10.2026: "sicherstellen, dass alles
 * geloggt wird — wer was wie gesendet hat, von welchem User"). Eine Zeile je
 * HR-Handlung bzw. Automatik in rec_document_events, nie geaendert.
 *
 * Was NICHT hier steht: gesehen/bestaetigt/unterschrieben im Portal — das
 * tragen die Zeitstempel der Zustellung, doppelt waere es nur ein zweiter
 * Ort, der abweichen kann.
 *
 * Query Builder, nie Eloquent-Hooks. Ein Fehler beim Schreiben darf die
 * Handlung nicht kippen (der Mensch hat das Dokument dann trotzdem bzw. es
 * ist trotzdem zurueckgezogen) — darum try/catch mit Log::warning.
 */
final class DokumentVerlauf
{
    public const BEREITGESTELLT    = 'bereitgestellt';
    public const VERSAND_GESTARTET = 'versand_gestartet';
    public const VERSAND_ANGESTOSSEN = 'versand_angestossen';
    public const WHATSAPP_JETZT    = 'whatsapp_jetzt';
    public const ERNEUT_GESENDET   = 'erneut_gesendet';
    public const ZURUECKGEZOGEN    = 'zurueckgezogen';
    public const AUTOMATIK_BEENDET = 'automatik_beendet';
    public const AUTO_ZUGESTELLT   = 'auto_zugestellt';
    public const NACHGETRAGEN      = 'nachgetragen';

    private const LABELS = [
        self::BEREITGESTELLT    => 'bereitgestellt',
        self::VERSAND_GESTARTET => 'WhatsApp-Versand',
        self::VERSAND_ANGESTOSSEN => 'Versand angestoßen',
        self::WHATSAPP_JETZT    => 'WhatsApp jetzt senden',
        self::ERNEUT_GESENDET   => 'erneut gesendet',
        self::ZURUECKGEZOGEN    => 'zurückgezogen',
        self::AUTOMATIK_BEENDET => 'Automatik beendet',
        self::AUTO_ZUGESTELLT   => 'automatisch zugestellt',
        self::NACHGETRAGEN      => 'Empfänger nachgetragen',
    ];

    private const EINSTIEGE = [
        'dokumente'     => 'Dokumente',
        'akte'          => 'Akte',
        'veranstaltung' => 'Veranstaltung',
    ];

    public static function label(string $aktion): string
    {
        return self::LABELS[$aktion] ?? $aktion;
    }

    /** Lesbarer Name des Einstiegs; unbekannte Werte fallen auf "Dokumente" (Default beim Bereitstellen). */
    public static function einstieg(?string $einstieg): string
    {
        return self::EINSTIEGE[$einstieg ?? ''] ?? self::EINSTIEGE['dokumente'];
    }

    public static function schreibe(int $teamId, int $documentId, ?int $recipientId, string $aktion, ?int $userId, array $details = []): void
    {
        try {
            DB::table('rec_document_events')->insert([
                'team_id'                   => $teamId,
                'rec_document_id'           => $documentId,
                'rec_document_recipient_id' => $recipientId,
                'aktion'                    => mb_substr($aktion, 0, 40),
                'user_id'                   => $userId,
                'details'                   => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at'                => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('recruiting.dokumente.verlauf_fehlgeschlagen', [
                'document_id'  => $documentId,
                'recipient_id' => $recipientId,
                'aktion'       => $aktion,
                'user_id'      => $userId,
                'fehler'       => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Verlauf eines Dokuments, neueste oben. Team ist Pflicht (Review K1):
     * ohne Team-Filter laese ein Aufrufer mit fremder Dokument-ID mit. Benutzername per Join auf users
     * (Muster HrMitarbeiterListen::gestoppte()); ohne Benutzer (Job,
     * Automatik) oder mit verschwundenem Benutzer heisst es "System".
     *
     * @return list<array{zeit:string, aktion:string, aktion_label:string, user:string, details:array, recipient_id:?int, empfaenger:?string, text:string}>
     */
    public static function fuer(int $teamId, int $documentId): array
    {
        $zeilen = DB::table('rec_document_events as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->leftJoin('rec_document_recipients as r', 'r.id', '=', 'v.rec_document_recipient_id')
            ->leftJoin('rec_employees as e', 'e.id', '=', 'r.rec_employee_id')
            ->where('v.team_id', $teamId)
            ->where('v.rec_document_id', $documentId)
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->get(['v.aktion', 'v.details', 'v.created_at', 'v.rec_document_recipient_id', 'u.name as benutzer',
                'e.first_name', 'e.last_name', 'r.rec_employee_id']);

        $out = [];
        foreach ($zeilen as $z) {
            $details = is_string($z->details) ? (json_decode($z->details, true) ?? []) : [];
            $empfaenger = null;
            if ($z->rec_document_recipient_id !== null) {
                $name = trim(((string) $z->first_name) . ' ' . ((string) $z->last_name));
                $empfaenger = $name !== '' ? $name : ($z->rec_employee_id !== null ? 'Mitarbeiter #' . $z->rec_employee_id : 'Zustellung #' . $z->rec_document_recipient_id);
            }
            $benutzer = trim((string) ($z->benutzer ?? ''));
            $out[] = [
                'zeit'         => Carbon::parse((string) $z->created_at)->format('d.m.Y H:i'),
                'aktion'       => (string) $z->aktion,
                'aktion_label' => self::label((string) $z->aktion),
                'user'         => $benutzer !== '' ? $benutzer : 'System',
                'details'      => $details,
                'recipient_id' => $z->rec_document_recipient_id !== null ? (int) $z->rec_document_recipient_id : null,
                'empfaenger'   => $empfaenger,
                'text'         => self::text((string) $z->aktion, $details, $empfaenger),
            ];
        }

        return $out;
    }

    /** Ein Satz je Eintrag, wie ihn HR liest. */
    private static function text(string $aktion, array $d, ?string $empfaenger): string
    {
        $fuer = $empfaenger !== null ? ' für ' . $empfaenger : '';

        return match ($aktion) {
            self::BEREITGESTELLT => 'bereitgestellt an ' . (int) ($d['empfaenger'] ?? 0) . ' Empfänger ('
                . (($d['art'] ?? 'pdf') === 'info' ? 'Info' : 'PDF')
                . ', WhatsApp ' . (($d['notify_mode'] ?? 'whatsapp') === 'none' ? 'nein' : 'ja')
                . (!empty($d['auto_neue_mitarbeiter']) ? ', automatisch an Neue' : '')
                . ', von: ' . self::einstieg($d['einstieg'] ?? null) . ')',
            self::VERSAND_GESTARTET => 'WhatsApp-Versand: ' . (int) ($d['benachrichtigt'] ?? 0) . ' von ' . (int) ($d['versucht'] ?? 0) . ' benachrichtigt'
                . self::fehlerText($d['fehler'] ?? []),
            self::WHATSAPP_JETZT  => 'WhatsApp nachträglich angestoßen',
            self::VERSAND_ANGESTOSSEN => 'Versand angestoßen (von: ' . self::einstieg($d['quelle'] ?? null) . ')',
            self::ERNEUT_GESENDET => 'erneut gesendet' . $fuer . ' (' . ($d['status'] ?? '?') . ')',
            self::ZURUECKGEZOGEN  => $empfaenger !== null
                ? 'zurückgezogen' . $fuer
                : 'ganz zurückgezogen (' . (int) ($d['anzahl'] ?? 0) . ' Zustellungen, '
                    . (!empty($d['geloescht']) ? 'Dokument entfernt' : 'Dokument bleibt als Nachweis') . ')',
            self::AUTOMATIK_BEENDET => 'Automatik „an neue Mitarbeiter“ beendet',
            self::AUTO_ZUGESTELLT   => 'automatisch zugestellt' . ($empfaenger !== null ? ' an ' . $empfaenger : ''),
            self::NACHGETRAGEN      => (int) ($d['neu'] ?? 0) . ' Empfänger nachgetragen'
                . (!empty($d['schon']) ? ' (' . (int) $d['schon'] . ' hatten es schon)' : ''),
            default                 => self::label($aktion),
        };
    }

    private static function fehlerText(mixed $fehler): string
    {
        if (!is_array($fehler) || $fehler === []) {
            return '';
        }
        $teile = [];
        foreach ($fehler as $status => $anzahl) {
            $teile[] = $status . ' ' . (int) $anzahl;
        }

        return ', nicht erreicht: ' . implode(', ', $teile);
    }
}
